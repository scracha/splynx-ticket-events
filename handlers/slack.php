<?php
/**
 * Slack Notification Handler
 *
 * Sends Slack notifications for new tickets and customer replies.
 * Replaces the standalone splynx-slack-poller.
 *
 * Events handled: new_ticket, customer_reply
 */

return function (array &$event, SplynxApiClient $api) {
    global $slackWebhookUrl, $splynxAdminUrl, $excludeStrings;

    if (empty($slackWebhookUrl) || strpos($slackWebhookUrl, 'YOUR') !== false) {
        return;
    }

    $ticket = $event['ticket'];
    $type = $event['type'];
    $id = $ticket['id'];
    $subject = $ticket['subject'] ?? 'No subject';
    $priority = $ticket['priority'] ?? 'normal';
    $customerId = $ticket['customer_id'] ?? null;
    $time = ($type === 'new_ticket') ? ($ticket['created_at'] ?? '') : ($ticket['updated_at'] ?? '');

    // Resolve customer name
    $customerName = ($customerId && $customerId != 0) ? 'Customer #' . $customerId : 'Unknown Customer';
    $resolved = getCustomerNameForSlack($api, $customerId);
    if ($resolved) $customerName = $resolved;

    // Get message preview
    $preview = getTicketPreviewForSlack($api, $id, 200, ($type === 'customer_reply'));

    // Include transcription if available (from transcribe handler)
    $transcription = '';
    if (!empty($event['transcriptions'])) {
        $parts = [];
        foreach ($event['transcriptions'] as $t) {
            $parts[] = $t['text'];
        }
        $transcription = implode("\n", $parts);
    }

    // Include customer match info if available (from match_customer handler)
    $customerMatch = '';
    if (!empty($event['customer_matched'])) {
        $cm = $event['customer_matched'];
        $customerName = $cm['customer_name']; // Override the generic name
        $customerMatch = "Matched via {$cm['method']}";
    }

    // Determine action label
    $action = ($type === 'new_ticket') ? 'New' : 'Customer Reply';

    // Send to Slack
    sendSlackNotification($action, $id, $subject, $customerName, $priority, $time, $splynxAdminUrl, $preview, $transcription, $customerMatch);

    logMsg("Slack: Sent '{$action}' notification for ticket #{$id}");

};

// ============================================================
// Slack Helper Functions
// ============================================================

function getCustomerNameForSlack(SplynxApiClient $api, $customerId): ?string
{
    if (!$customerId) return null;
    $cust = $api->get("admin/customers/customer/{$customerId}");
    if ($cust && isset($cust['name'])) {
        return trim($cust['name'] . ' ' . ($cust['surname'] ?? ''));
    }
    return null;
}

function getTicketPreviewForSlack(SplynxApiClient $api, int $ticketId, int $maxChars = 200, bool $lastMessage = false): string
{
    global $excludeStrings;

    $msgs = $api->get("admin/support/ticket-messages", [
        'main_attributes' => ['ticket_id' => $ticketId, 'message_type' => 'message'],
    ]);
    if (!is_array($msgs) || empty($msgs)) return '';

    $msg = $lastMessage ? end($msgs) : reset($msgs);
    $body = $msg['message'] ?? '';

    // Convert block-level HTML to newlines before stripping
    $body = preg_replace('/<br\s*\/?>/i', "\n", $body);
    $body = preg_replace('/<\/(div|p|li|tr|h[1-6])>/i', "\n", $body);
    $body = preg_replace('/<(div|p|li|tr|h[1-6])[^>]*>/i', '', $body);
    $body = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    $body = str_replace("\xC2\xA0", ' ', $body);
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    // Remove excluded strings
    if (!empty($excludeStrings)) {
        foreach ($excludeStrings as $exclude) {
            $body = str_replace($exclude, '', $body);
        }
    }

    // Split, trim, collapse
    $lines = array_filter(array_map(function($l) {
        return preg_replace('/\s+/', ' ', trim($l));
    }, explode("\n", $body)), fn($l) => $l !== '');

    // Join with punctuation logic
    $text = '';
    $prevLine = '';
    foreach ($lines as $line) {
        if ($text === '') {
            $text = $line;
        } else {
            $lastChar = substr($prevLine, -1);
            if (in_array($lastChar, ['.', ':', ';', '?', '!', ','])) {
                $text .= '  ' . $line;
            } else {
                $text .= '.  ' . $line;
            }
        }
        $prevLine = $line;
    }

    $text = preg_replace('/\s{3,}/', '  ', trim($text));

    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars) . '...';
    }

    return $text;
}

function sendSlackNotification(string $action, int $ticketId, string $subject, string $customer, string $priority, string $time, string $adminUrl, string $msgPreview = '', string $transcription = '', string $customerMatch = ''): bool
{
    global $slackWebhookUrl;

    $emoji = [
        'low' => ':large_blue_circle:', 'normal' => ':white_circle:',
        'medium' => ':large_yellow_circle:', 'high' => ':large_orange_circle:',
        'urgent' => ':red_circle:'
    ];
    $icon = $emoji[strtolower($priority)] ?? ':white_circle:';
    $ticketUrl = rtrim($adminUrl, '/') . "/admin/tickets/opened--view?id={$ticketId}";

    $text = "{$icon} *{$action}* — <{$ticketUrl}|#{$ticketId} {$subject}>\n*Customer:* {$customer}";
    if ($customerMatch) {
        $text .= " _(auto-assigned: {$customerMatch})_";
    }
    if ($transcription) {
        $text .= "\n:studio_microphone: *Voicemail Transcription:*\n>>> " . $transcription;
    } elseif ($msgPreview) {
        $text .= "\n>>> " . $msgPreview;
    }

    $payload = [
        'text' => strip_tags("{$action}: #{$ticketId} {$subject} — {$customer}"),
        'blocks' => [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => "_{$time}_"]]],
        ],
    ];

    $ch = curl_init($slackWebhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code === 200;
}
