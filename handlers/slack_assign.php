<?php
/**
 * Slack Personal Ticket Assignment DM Handler
 *
 * Sends a direct message via Slack Bot API to an agent when a ticket is assigned or reassigned to them.
 * Events handled: ticket_assigned
 */

// Fallback logMsg definition if required in isolation outside poll.php
if (!function_exists('logMsg')) {
    function logMsg(string $msg): void {
        echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
    }
}

return function (array &$event, SplynxApiClient $api) {
    if ($event['type'] !== 'ticket_assigned') {
        return;
    }

    global $slackBotToken, $slackAgentUsers, $splynxAdminUrl;

    if (empty($slackBotToken) || empty($slackAgentUsers) || !is_array($slackAgentUsers)) {
        return;
    }

    $assignee = trim((string)($event['assignee'] ?? ''));
    if (empty($assignee)) {
        return;
    }

    // Match agent key in $slackAgentUsers (supports numeric admin ID or name)
    $slackUserId = $slackAgentUsers[$assignee] ?? null;
    if (!$slackUserId) {
        foreach ($slackAgentUsers as $nameKey => $uid) {
            if (strcasecmp((string)$nameKey, $assignee) === 0) {
                $slackUserId = $uid;
                break;
            }
        }
    }

    if (!$slackUserId) {
        // Agent not configured in $slackAgentUsers map; silently ignore
        return;
    }

    $ticket   = $event['ticket'];
    $ticketId = (int)$ticket['id'];

    // --- Check Self-Assignment: Skip if assigned_by == assigned_to ---
    if (is_numeric($assignee) && (int)$assignee > 0) {
        $recentChanges = $api->get("admin/support/ticket-messages", [
            'main_attributes' => [
                'ticket_id'    => $ticketId,
                'message_type' => 'change_ticket',
            ],
            'limit' => 50,
        ]);

        if (is_array($recentChanges) && !empty($recentChanges)) {
            // Helper to extract timestamp (Splynx uses 'date' + 'time' or 'created_at')
            $getMsgTime = function($msg) {
                if (!empty($msg['created_at'])) {
                    $ts = strtotime($msg['created_at']);
                    if ($ts) return $ts;
                }
                if (!empty($msg['date'])) {
                    $dStr = trim($msg['date'] . ' ' . ($msg['time'] ?? '00:00:00'));
                    $ts = strtotime($dStr);
                    if ($ts) return $ts;
                }
                return (int)($msg['id'] ?? 0);
            };

            // Sort newest-first so index [0] is guaranteed to be the most recent action
            usort($recentChanges, function ($a, $b) use ($getMsgTime) {
                return $getMsgTime($b) <=> $getMsgTime($a);
            });

            $lastChange      = $recentChanges[0];
            $actionByAdminId = (int)($lastChange['admin_id'] ?? 0);
            $changeMsg       = $lastChange['message'] ?? '';
            $changeTime      = $getMsgTime($lastChange);
            $ticketUpdated   = strtotime($ticket['updated_at'] ?? 0);

            // Guard against stale self-assignments: the assignment action must be current
            // (within 180s of the ticket's updated_at timestamp)
            $isCurrentChange = ($changeTime && $ticketUpdated && abs($ticketUpdated - $changeTime) <= 180);

            if ($isCurrentChange
                && stripos($changeMsg, 'Assigned ticket') !== false
                && $actionByAdminId === (int)$assignee) {
                logMsg("Slack Assign: Ticket #{$ticketId} was self-assigned by admin #{$actionByAdminId}; skipping DM.");
                return;
            }
        }
    }

    $subject   = $ticket['subject'] ?? 'No subject';
    $priority  = strtoupper($ticket['priority'] ?? 'NORMAL');
    $adminUrl  = rtrim($splynxAdminUrl ?? '', '/');
    $ticketUrl = $adminUrl ? "{$adminUrl}/admin/tickets/opened--view?id={$ticketId}" : "#";

    // Resolve customer name
    $customerId   = $ticket['customer_id'] ?? null;
    $customerName = 'Unknown Customer';
    if ($customerId && $customerId != 0) {
        $customerName = "Customer #{$customerId}";
        $cust = $api->get("admin/customers/customer/{$customerId}");
        if ($cust && isset($cust['name'])) {
            $customerName = trim($cust['name'] . ' ' . ($cust['surname'] ?? ''));
        }
    }

    $title = preg_match('/^#\d+\b/', $subject) ? $subject : "#{$ticketId} {$subject}";

    // Priority color indicators:
    // Red (Urgent), Amber (High), Grey/White (Normal), Green (Low)
    $priorityEmoji = [
        'URGENT' => '🔴',
        'HIGH'   => '🟠',
        'NORMAL' => '⚪',
        'MEDIUM' => '⚪',
        'LOW'    => '🟢',
    ];
    $icon = $priorityEmoji[$priority] ?? '⚪';

    // Header context: check for reassignment vs initial assignment
    $prevAssignee = trim((string)($event['prev_assignee'] ?? ''));
    $hasPrev = (!empty($prevAssignee) && $prevAssignee !== 'Unassigned' && $prevAssignee !== '0');

    if ($hasPrev) {
        // Only show "(from Name)" if the previous assignee is an actual name string, not a raw numeric ID
        $fromLabel = !is_numeric($prevAssignee) ? " _(from {$prevAssignee})_" : '';
        $headerText = "🎫 *Reassigned to you*{$fromLabel} {$icon}";
    } else {
        $headerText = "🎫 *Assigned to you* {$icon}";
    }

    // Compact layout:
    // Line 1: 🎫 Assigned to you 🔴
    // Line 2: *<URL|#5665 Subject>*
    // Line 3: 👤 Customer Name
    $bodyText = "{$headerText}\n*<{$ticketUrl}|{$title}>*\n👤 {$customerName}";

    $payload = [
        'channel' => $slackUserId,
        'text'    => "🎫 Ticket assigned to you: {$title} — {$customerName} ({$priority})",
        'blocks'  => [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $bodyText,
                ]
            ]
        ],
    ];

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $slackBotToken,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $respData = json_decode($resp, true);
    if ($code === 200 && ($respData['ok'] ?? false)) {
        logMsg("Slack Assign: Sent DM to {$assignee} ({$slackUserId}) for ticket #{$ticketId}");
    } else {
        $err = $respData['error'] ?? "HTTP {$code}";
        logMsg("Slack Assign: Failed to send DM to {$assignee} ({$slackUserId}) for ticket #{$ticketId}: {$err}");
    }
};
