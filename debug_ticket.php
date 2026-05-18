#!/usr/bin/env php
<?php
/**
 * Debug: Inspect a ticket's messages, attachments, and event classification.
 *
 * Usage: php debug_ticket.php <ticket_id>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SplynxApiClient.php';

$ticketId = $argv[1] ?? null;
if (!$ticketId) {
    die("Usage: php debug_ticket.php <ticket_id>\n");
}

$api = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// Fetch ticket
$ticket = $api->get("admin/support/tickets/{$ticketId}");
if (!$ticket) {
    die("Ticket #{$ticketId} not found\n");
}

echo "=== Ticket #{$ticketId} ===\n";
echo "  Subject: " . ($ticket['subject'] ?? '') . "\n";
echo "  Status: " . ($ticket['status'] ?? '') . "\n";
echo "  Created: " . ($ticket['created_at'] ?? '') . "\n";
echo "  Updated: " . ($ticket['updated_at'] ?? '') . "\n";
echo "  Customer ID: " . ($ticket['customer_id'] ?? '') . "\n";
echo "  Reporter Type: " . ($ticket['reporter_type'] ?? '') . "\n";
echo "  Reporter ID: " . ($ticket['reporter_id'] ?? '') . "\n";
echo "  Source: " . ($ticket['source'] ?? '') . "\n";
echo "  Incoming Customer ID: " . ($ticket['incoming_customer_id'] ?? '') . "\n";
echo "  Unread by Admin: " . ($ticket['unread_by_admin'] ?? 0) . "\n";
echo "  Priority: " . ($ticket['priority'] ?? '') . "\n\n";

// Would this ticket be ignored?
$ignored = false;
$reporterType = $ticket['reporter_type'] ?? '';
if ($reporterType === 'admin') {
    $ignored = true;
    echo "  ⚠ Would be IGNORED (reporter_type = admin)\n";
} elseif ($reporterType === 'api') {
    $reporterId = (int)($ticket['reporter_id'] ?? 0);
    if (in_array($reporterId, $ignoreApiCreators)) {
        $ignored = true;
        echo "  ⚠ Would be IGNORED (API creator ID {$reporterId} in ignore list)\n";
    }
}
$incomingId = (int)($ticket['incoming_customer_id'] ?? 0);
if ($incomingId > 0 && in_array($incomingId, $ignoreIncomingCustomerIds)) {
    $ignored = true;
    echo "  ⚠ Would be IGNORED (incoming_customer_id {$incomingId} in ignore list)\n";
}
if (!$ignored) {
    echo "  ✓ Would be PROCESSED (not in any ignore list)\n";
}

echo "\n";

// Fetch messages
$messages = $api->get("admin/support/ticket-messages", [
    'main_attributes' => ['ticket_id' => $ticketId],
]);

if (!is_array($messages) || empty($messages)) {
    die("No messages found\n");
}

echo "=== Messages (" . count($messages) . ") ===\n\n";

$audioFound = false;
foreach ($messages as $msg) {
    echo "--- Message #{$msg['id']} (type: " . ($msg['message_type'] ?? '?') . ") ---\n";
    echo "  Created: " . ($msg['created_at'] ?? '') . "\n";

    // Show all keys for debugging
    $keys = array_keys($msg);
    $attachmentKeys = array_filter($keys, function($k) {
        return stripos($k, 'file') !== false || stripos($k, 'attach') !== false || stripos($k, 'media') !== false;
    });

    if (!empty($attachmentKeys)) {
        echo "  Attachment-related fields:\n";
        foreach ($attachmentKeys as $key) {
            $val = $msg[$key];
            if (is_array($val)) {
                echo "    {$key}: " . json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                echo "    {$key}: {$val}\n";
            }
        }
    }

    if (isset($msg['files']) && !empty($msg['files'])) {
        echo "  Files:\n";
        foreach ($msg['files'] as $j => $att) {
            echo "    [{$j}] " . json_encode($att, JSON_UNESCAPED_SLASHES) . "\n";
            $ext = strtolower(pathinfo($att['filename_original'] ?? '', PATHINFO_EXTENSION));
            if (in_array($ext, $audioExtensions)) {
                $audioFound = true;
                echo "         ^ AUDIO FILE DETECTED\n";
            }
        }
    }

    // Message preview
    $body = strip_tags($msg['message'] ?? '');
    $body = trim(preg_replace('/\s+/', ' ', $body));
    if (strlen($body) > 150) $body = substr($body, 0, 150) . '...';
    echo "  Body: {$body}\n\n";
}

echo "=== Summary ===\n";
echo "  Audio attachments found: " . ($audioFound ? 'YES' : 'No') . "\n";
echo "  Message keys available: " . implode(', ', array_keys($messages[0])) . "\n";
