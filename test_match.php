#!/usr/bin/env php
<?php
/**
 * Test customer matching on a specific ticket without making changes.
 *
 * Usage:
 *   php test_match.php <ticket_id>           # Uses cached note if available, else API
 *   php test_match.php <ticket_id> --groq    # Prefers/forces Groq Whisper live
 *   php test_match.php <ticket_id> --both    # Transcribes with BOTH Google AI and Groq to compare
 *   php test_match.php <ticket_id> --force   # Bypasses cached Splynx note
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SplynxApiClient.php';

$handlerFile = __DIR__ . '/handlers/match_customer.php';
require_once $handlerFile;

require_once __DIR__ . '/handlers/transcribe.php';

$ticketId = null;
$preferGroq = false;
$showBoth = false;
$forceLive = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = strtolower($argv[$i]);
    if ($arg === '--groq' || $arg === '--prefer-groq') {
        $preferGroq = true;
        $forceLive = true;
    } elseif ($arg === '--both' || $arg === '--compare' || $arg === '--show-both') {
        $showBoth = true;
        $forceLive = true;
    } elseif ($arg === '--force') {
        $forceLive = true;
    } elseif (is_numeric($arg)) {
        $ticketId = (int)$arg;
    }
}

if (!$ticketId) {
    echo "Usage: php test_match.php <ticket_id> [options]\n\n";
    echo "Options:\n";
    echo "  --groq       Prefer/force Groq Whisper live (bypasses cached note)\n";
    echo "  --both       Transcribe with BOTH Google AI and Groq to compare\n";
    echo "  --force      Bypass cached Splynx note and force live re-transcription\n\n";
    exit(1);
}

$api = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

$ticket = $api->get("admin/support/tickets/{$ticketId}");
if (!$ticket) {
    die("Ticket #{$ticketId} not found\n");
}

$subject = $ticket['subject'] ?? '';
$customerId = (int)($ticket['customer_id'] ?? 0);

echo "=== Customer Match Test — Ticket #{$ticketId} ===\n\n";
echo "Subject: {$subject}\n";
echo "Current customer_id: {$customerId}\n\n";

if ($customerId !== 0 && !in_array($customerId, $matchGenericCustomerIds ?? [])) {
    echo "⚠ Ticket already assigned to customer #{$customerId} (not in generic list)\n";
    echo "  Generic customer IDs: " . implode(', ', $matchGenericCustomerIds ?? []) . "\n\n";
}

$pattern = $matchSubjectPattern ?? '/voicemail|voice message/i';
if (!preg_match($pattern, $subject)) {
    echo "⚠ Subject does not match pattern: {$pattern}\n\n";
}

if (!file_exists(MATCH_DATA_STORE)) {
    die("ERROR: Data store not found at " . MATCH_DATA_STORE . "\n");
}
$rawStore = json_decode(file_get_contents(MATCH_DATA_STORE), true);
$customers = aggregateCustomerStore($rawStore);
echo "Data store loaded: " . count($rawStore) . " entries aggregated into " . count($customers) . " unique customers\n\n";

$firstMessageBody = getFirstTicketMessageBody($api, (int)$ticketId);

$callerPhone = extractCallerId($subject, $firstMessageBody);
echo "--- Caller ID Extraction ---\n";
echo "  Extracted caller phone: " . ($callerPhone ?: '(none)') . "\n\n";

echo "--- Transcription Signals ---\n";
$transcription = '';
$transcriptionSource = '';

if (!$forceLive) {
    $existingNotes = $api->get("admin/support/ticket-messages", [
        'main_attributes' => ['ticket_id' => $ticketId, 'message_type' => 'note'],
    ]);

    if (is_array($existingNotes) && !empty($existingNotes)) {
        foreach ($existingNotes as $note) {
            $msg = $note['message'] ?? '';
            if (stripos($msg, 'Voicemail Transcription') !== false) {
                $cleanNote = html_entity_decode(strip_tags($msg), ENT_QUOTES, 'UTF-8');
                $cleanNote = preg_replace('/^.*Voicemail Transcription[^\n:]*:\s*/is', '', $cleanNote);
                $transcription = trim($cleanNote);
                if (!empty($transcription)) {
                    $transcriptionSource = 'Existing Splynx Note (use --force or --both to re-run APIs)';
                    echo "  Found existing transcription note on Ticket #{$ticketId} (saved API quota!)\n";
                    break;
                }
            }
        }
    }
}

if (empty($transcription) || $forceLive) {
    $audioFiles = findAudioAttachments($api, (int)$ticketId);
    if (empty($audioFiles)) {
        echo "  No audio attachments found on ticket.\n";
    } else {
        $audio = $audioFiles[0];
        $filename = $audio['filename'];
        echo "  Downloading {$filename}...\n";
        $fileContent = $api->downloadFile($audio['url']);

        if (!$fileContent) {
            echo "  ERROR: Failed to download audio file.\n";
        } else {
            $tmpFile = sys_get_temp_dir() . '/test_match_' . getmypid() . '_' . basename($filename);
            file_put_contents($tmpFile, $fileContent);

            if ($showBoth) {
                echo "\n  --- Model Comparison (--both) ---\n";
                $googleText = !empty($googleAIStudioKey) ? transcribeWithGoogleAI($tmpFile, $filename, $googleAIStudioKey) : false;
                $groqText = !empty($groqApiKey) ? transcribeWithGroq($tmpFile, $filename, $groqApiKey) : false;

                $googleDisplay = ($googleText !== false && $googleText !== '') ? $googleText : ($googleText === '' ? "(Empty / silent audio)" : "(Failed or quota exceeded)");
                $groqDisplay = ($groqText !== false && $groqText !== '') ? $groqText : ($groqText === '' ? "(Empty / silent audio)" : "(Failed or key missing)");

                echo "\n  [Google AI Studio (Gemini 2.5 Flash)]:\n";
                echo "  \"{$googleDisplay}\"\n";

                echo "\n  [Groq Cloud (Whisper Large v3)]:\n";
                echo "  \"{$groqDisplay}\"\n";

                if ($preferGroq && $groqText !== false) {
                    $transcription = $groqText;
                    $transcriptionSource = 'Groq Cloud (Whisper Large v3)';
                } elseif ($googleText !== false) {
                    $transcription = $googleText;
                    $transcriptionSource = 'Google AI Studio';
                } else {
                    $transcription = ($groqText !== false) ? $groqText : '';
                    $transcriptionSource = ($groqText !== false) ? 'Groq Cloud (Whisper Large v3)' : '';
                }
            } else {
                $providerPref = $preferGroq ? 'groq' : null;
                $transcription = transcribeAudioFile($tmpFile, $filename, $providerPref);
                $transcriptionSource = $preferGroq ? 'Groq Whisper' : 'Primary Provider';
            }

            @unlink($tmpFile);
        }
    }
}

if ($transcription) {
    echo "\nActive Transcription [Source: {$transcriptionSource}]:\n";
    echo "  \"{$transcription}\"\n\n";
} else {
    echo "  (no transcription available)\n\n";
}

$transcribedPhones = $transcription ? extractPhoneNumbers($transcription) : [];
$extractedAddresses = $transcription ? extractAddresses($transcription) : [];
$extractedNames = extractNames($transcription, $subject);

echo "Extracted Phones: " . (empty($transcribedPhones) ? '(none)' : implode(', ', $transcribedPhones)) . "\n";
echo "Extracted Addresses: " . (empty($extractedAddresses) ? '(none)' : implode(', ', $extractedAddresses)) . "\n";
$nameList = array_map(fn($n) => $n['name'] . ($n['is_full'] ? ' [Full]' : ' [First]'), $extractedNames);
echo "Extracted Names: " . (empty($nameList) ? '(none)' : implode(', ', $nameList)) . "\n\n";

echo "--- Scored Candidates ---\n";
$candidates = scoreCustomerCandidates(
    $customers,
    $callerPhone,
    $transcribedPhones,
    $extractedAddresses,
    $extractedNames
);

$viable = array_filter($candidates, fn($c) => $c['confidence'] >= 65);
usort($viable, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

$matchCount = count($viable);
$simulatedPhone = $callerPhone ?: (!empty($transcribedPhones) ? $transcribedPhones[0] : null);

if (empty($viable)) {
    echo "  No candidates met the minimum confidence threshold (>= 65%).\n";
    echo "  Result: UNKNOWN (Ticket remains unassigned).\n";
    $simulatedSubject = buildVoicemailSubject($ticketId, null, null, null, $simulatedPhone);
    echo "  Simulated Subject: \"{$simulatedSubject}\"\n";
} else {
    foreach (array_values($viable) as $i => $c) {
        $cName = !empty($c['customer_name']) ? $c['customer_name'] : "Customer #{$c['customer_id']}";
        echo "  [" . ($i + 1) . "] #{$c['customer_id']} {$cName} — {$c['confidence']}% confidence\n";
        echo "      Reason: {$c['reason']}\n";
    }

    echo "\n--- Decision Simulation ---\n";
    $top = $viable[0];
    $topScore = $top['confidence'];
    $secondScore = $viable[1]['confidence'] ?? 0;
    $isTied = ($topScore === $secondScore);

    $isClearWinner = (!$isTied && $topScore >= 80 && ($topScore - $secondScore >= 10 || ($topScore >= 95 && $topScore - $secondScore >= 5)));

    if ($isClearWinner) {
        $topName = !empty($top['customer_name']) ? $top['customer_name'] : "Customer #{$top['customer_id']}";
        $topTown = $top['city'] ?? ($customers[$top['customer_id']]['city'] ?? null);
        if (empty($topTown) || $topName === "Customer #{$top['customer_id']}") {
            $custDetails = $api->get("admin/customers/customer/{$top['customer_id']}");
            if (is_array($custDetails)) {
                if (empty($topTown)) {
                    $topTown = $custDetails['city'] ?? ($custDetails['billing_city'] ?? null);
                }
                if ($topName === "Customer #{$top['customer_id']}" && !empty($custDetails['name'])) {
                    $topName = trim($custDetails['name'] . ' ' . ($custDetails['surname'] ?? ''));
                }
            }
        }
        $simulatedSubject = buildVoicemailSubject($ticketId, $topName, $top['matched_address'] ?? null, $topTown, $simulatedPhone);
        echo "  ✓ AUTO-ASSIGN to {$topName} (#{$top['customer_id']})\n";
        echo "    Reason: {$topScore}% confidence via {$top['reason']}\n";
        echo "    Simulated Subject: \"{$simulatedSubject}\"\n";
    } elseif ($matchCount >= 1 && $matchCount <= 3) {
        $simulatedSubject = buildVoicemailSubject($ticketId, null, null, null, $simulatedPhone);
        echo "  ⚠ LEFT UNASSIGNED (Suggested Matches Note posted)\n";
        echo "    Up to 3 suggestions would be posted to the ticket for admin review.\n";
        echo "    Simulated Subject: \"{$simulatedSubject}\"\n";
    } else {
        $simulatedSubject = buildVoicemailSubject($ticketId, null, null, null, $simulatedPhone);
        echo "  ✗ LEFT UNASSIGNED as Unknown (> 3 matches: {$matchCount} candidates found)\n";
        echo "    Simulated Subject: \"{$simulatedSubject}\"\n";
    }
}

echo "\nDone. (No changes were made to the ticket)\n";
