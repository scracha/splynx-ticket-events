#!/usr/bin/env php
<?php
/**
 * Test transcription on a specific ticket without posting the note.
 * Downloads audio, transcribes it, and prints the result.
 *
 * Usage: php test_transcribe.php <ticket_id>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SplynxApiClient.php';

// Include transcription functions from the handler
require_once __DIR__ . '/handlers/transcribe.php';

$ticketId = $argv[1] ?? null;
if (!$ticketId) {
    die("Usage: php test_transcribe.php <ticket_id>\n");
}

$api = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

echo "=== Testing transcription for ticket #{$ticketId} ===\n\n";

// Find audio files
$audioFiles = findAudioAttachments($api, (int)$ticketId);

if (empty($audioFiles)) {
    die("No audio attachments found in ticket #{$ticketId}\n");
}

echo "Found " . count($audioFiles) . " audio file(s):\n\n";

foreach ($audioFiles as $i => $audio) {
    $filename = $audio['filename'];
    $url = $audio['url'];

    echo "--- [{$i}] {$filename} ---\n";
    echo "  Download URL: {$url}\n";

    // Download
    echo "  Downloading...\n";
    $fileContent = $api->downloadFile($url);
    if ($fileContent === false || empty($fileContent)) {
        echo "  ERROR: Failed to download\n\n";
        continue;
    }
    echo "  Downloaded: " . round(strlen($fileContent) / 1024, 1) . " KB\n";

    // Save to temp
    $tmpFile = sys_get_temp_dir() . '/test_transcribe_' . basename($filename);
    file_put_contents($tmpFile, $fileContent);

    // Transcribe
    echo "  Transcribing...\n";
    $transcription = transcribeAudioFile($tmpFile, $filename);
    @unlink($tmpFile);

    if ($transcription === false || empty($transcription)) {
        echo "  ERROR: Transcription failed\n\n";
        continue;
    }

    echo "\n  === TRANSCRIPTION ===\n";
    echo "  " . str_replace("\n", "\n  ", $transcription) . "\n";
    echo "  === END ===\n";
    echo "  Words: " . str_word_count($transcription) . "\n\n";
}

echo "Done. (No notes were posted to the ticket)\n";

function logMsg(string $msg): void
{
    echo "[LOG] {$msg}\n";
}
