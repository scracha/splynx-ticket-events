#!/usr/bin/env php
<?php
/**
 * Test customer matching on a specific ticket without making changes.
 *
 * Usage: php test_match.php <ticket_id>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SplynxApiClient.php';

// Load the match_customer handler functions (but don't execute the handler itself)
// We need to include it in a way that loads the functions without running the closure
$handlerFile = __DIR__ . '/handlers/match_customer.php';
require_once $handlerFile;

// Also load transcribe handler for findAudioAttachments/transcribeAudioFile
require_once __DIR__ . '/handlers/transcribe.php';

$ticketId = $argv[1] ?? null;
if (!$ticketId) {
    die("Usage: php test_match.php <ticket_id>\n");
}

$api = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// Fetch ticket
$ticket = $api->get("admin/support/tickets/{$ticketId}");
if (!$ticket) {
    die("Ticket #{$ticketId} not found\n");
}

$subject = $ticket['subject'] ?? '';
$customerId = (int)($ticket['customer_id'] ?? 0);

echo "=== Customer Match Test — Ticket #{$ticketId} ===\n\n";
echo "Subject: {$subject}\n";
echo "Current customer_id: {$customerId}\n\n";

// Check if this ticket would be processed
if ($customerId !== 0 && !in_array($customerId, $matchGenericCustomerIds ?? [])) {
    echo "⚠ Ticket already assigned to customer #{$customerId} (not in generic list)\n";
    echo "  Would NOT be processed by match_customer handler.\n";
    echo "  Generic customer IDs: " . implode(', ', $matchGenericCustomerIds ?? []) . "\n\n";
}

$pattern = $matchSubjectPattern ?? '/voicemail|voice message/i';
if (!preg_match($pattern, $subject)) {
    echo "⚠ Subject does not match pattern: {$pattern}\n";
    echo "  Would NOT be processed by match_customer handler.\n\n";
}

// Extract caller ID
echo "--- Caller ID Extraction ---\n";
$callerPhone = extractCallerIdFromSubject($subject);
echo "  Extracted caller phone: " . ($callerPhone ?: '(none)') . "\n\n";

// Check if data store is available
if (!file_exists(MATCH_DATA_STORE)) {
    die("ERROR: Data store not found at " . MATCH_DATA_STORE . "\n");
}
$dataStore = json_decode(file_get_contents(MATCH_DATA_STORE), true);
echo "Data store loaded: " . count($dataStore) . " entries\n\n";

// Try caller ID match
echo "--- Phone Match (caller ID) ---\n";
if ($callerPhone) {
    $match = searchByPhone($dataStore, $callerPhone);
    if ($match) {
        echo "  ✓ MATCH: {$match['customer_name']} (ID: {$match['customer_id']})\n";
        echo "    Phone: {$match['customer_phone']}\n";
    } else {
        echo "  ✗ No match for {$callerPhone}\n";
    }
} else {
    echo "  (no caller ID to search)\n";
}

// Try transcription if available
echo "\n--- Transcription Phone Numbers ---\n";
$transcription = '';

// Check if there's already a transcription we can use
$audioFiles = findAudioAttachments($api, (int)$ticketId);
if (!empty($audioFiles)) {
    echo "  Audio file found: {$audioFiles[0]['filename']}\n";
    echo "  Attempting transcription for phone/name extraction...\n";

    $url = $audioFiles[0]['url'];
    $fileContent = $api->downloadFile($url);
    if ($fileContent) {
        $tmpFile = sys_get_temp_dir() . '/test_match_' . basename($audioFiles[0]['filename']);
        file_put_contents($tmpFile, $fileContent);
        $transcription = transcribeAudioFile($tmpFile, $audioFiles[0]['filename']);
        @unlink($tmpFile);
    }
}

if ($transcription) {
    echo "  Transcription: {$transcription}\n\n";

    $phones = extractPhoneNumbers($transcription);
    echo "  Extracted phones: " . (empty($phones) ? '(none)' : implode(', ', $phones)) . "\n";
    foreach ($phones as $phone) {
        $match = searchByPhone($dataStore, $phone);
        if ($match) {
            echo "    ✓ MATCH for {$phone}: {$match['customer_name']} (ID: {$match['customer_id']})\n";
        } else {
            echo "    ✗ No match for {$phone}\n";
        }
    }

    echo "\n--- Name Match ---\n";
    $names = extractNames($transcription, $subject);
    echo "  Extracted names: " . (empty($names) ? '(none)' : implode(', ', $names)) . "\n";
    foreach ($names as $name) {
        $match = searchByName($dataStore, $name);
        if ($match) {
            echo "    ✓ MATCH for '{$name}': {$match['customer_name']} (ID: {$match['customer_id']})\n";
        } else {
            echo "    ✗ No match for '{$name}'\n";
        }
    }

    echo "\n--- Address Match ---\n";
    $addresses = extractAddresses($transcription);
    echo "  Extracted addresses: " . (empty($addresses) ? '(none)' : implode(', ', $addresses)) . "\n";
    foreach ($addresses as $addr) {
        $match = searchByAddress($dataStore, $addr);
        if ($match) {
            echo "    ✓ MATCH for '{$addr}': {$match['customer_name']} (ID: {$match['customer_id']})\n";
        } else {
            echo "    ✗ No match for '{$addr}'\n";
        }
    }
} else {
    echo "  (no transcription available)\n";
}

echo "\nDone. (No changes were made to the ticket)\n";

function logMsg(string $msg): void
{
    echo "[LOG] {$msg}\n";
}
