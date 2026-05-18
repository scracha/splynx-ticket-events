<?php
/**
 * Voicemail Transcription Handler
 *
 * Detects audio attachments on new tickets, transcribes them,
 * and posts the transcription as an admin note on the ticket.
 *
 * If transcription fails (e.g. API rate limit), queues for retry on next poll.
 * On successful retry, posts the note and sends a follow-up Slack message.
 *
 * Events handled: new_ticket only
 */

define('PENDING_FILE', __DIR__ . '/../pending_transcriptions.json');
define('MAX_RETRIES', 3);

return function (array &$event, SplynxApiClient $api) {
    // Only transcribe on new tickets (not replies, to avoid re-processing)
    if ($event['type'] !== 'new_ticket') return;

    global $audioExtensions, $maxAudioSize, $splynxBaseUrl, $notePrefix, $noteAuthorId;

    $ticket = $event['ticket'];
    $ticketId = $ticket['id'];

    // Find audio attachments
    $audioFiles = findAudioAttachments($api, $ticketId);
    if (empty($audioFiles)) return;

    logMsg("Transcribe: Ticket #{$ticketId} has " . count($audioFiles) . " audio attachment(s)");

    foreach ($audioFiles as $audio) {
        $transcription = attemptTranscription($api, $ticketId, $audio);

        if ($transcription !== false) {
            // Success — enrich event for Slack handler
            if (!isset($event['transcriptions'])) {
                $event['transcriptions'] = [];
            }
            $event['transcriptions'][] = [
                'filename' => $audio['filename'],
                'text'     => $transcription,
            ];
            postTranscriptionNote($api, $ticketId, $audio['filename'], $transcription);
        } else {
            // Failed — queue for retry
            queueForRetry($ticketId, $audio, $ticket['subject'] ?? '');
            logMsg("Transcribe: Ticket #{$ticketId} — queued {$audio['filename']} for retry");
        }
    }
};

/**
 * Attempt to download and transcribe an audio file.
 * Returns transcription text or false on failure.
 */
function attemptTranscription(SplynxApiClient $api, int $ticketId, array $audio)
{
    $filename = $audio['filename'];
    $url = $audio['url'];

    // Download
    $fileContent = $api->downloadFile($url);
    if ($fileContent === false || empty($fileContent)) {
        logMsg("Transcribe: Ticket #{$ticketId} — failed to download {$filename}");
        return false;
    }

    // Save to temp file
    $tmpFile = sys_get_temp_dir() . '/transcribe_' . getmypid() . '_' . basename($filename);
    file_put_contents($tmpFile, $fileContent);

    logMsg("Transcribe: Ticket #{$ticketId} — transcribing {$filename} (" . round(strlen($fileContent) / 1024, 1) . "KB)");

    // Transcribe
    $transcription = transcribeAudioFile($tmpFile, $filename);
    @unlink($tmpFile);

    if ($transcription === false || empty($transcription)) {
        logMsg("Transcribe: Ticket #{$ticketId} — transcription failed for {$filename}");
        return false;
    }

    logMsg("Transcribe: Ticket #{$ticketId} — success (" . str_word_count($transcription) . " words)");
    return $transcription;
}

/**
 * Post transcription as an admin note on the ticket.
 */
function postTranscriptionNote(SplynxApiClient $api, int $ticketId, string $filename, string $transcription)
{
    global $notePrefix, $noteAuthorId;

    $noteBody = "<p><strong>{$notePrefix}</strong> ({$filename}):</p>"
        . "<p>" . nl2br(htmlspecialchars($transcription)) . "</p>";

    $result = $api->post("admin/support/ticket-messages", [
        'ticket_id'    => $ticketId,
        'message'      => $noteBody,
        'message_type' => 'note',
        'admin_id'     => $noteAuthorId,
    ]);

    if ($result === null) {
        logMsg("Transcribe: Ticket #{$ticketId} — failed to post note");
    } else {
        logMsg("Transcribe: Ticket #{$ticketId} — posted transcription note");
    }
}

/**
 * Queue a failed transcription for retry on next poll run.
 */
function queueForRetry(int $ticketId, array $audio, string $subject)
{
    $pending = loadPending();

    $key = "{$ticketId}_{$audio['file_id']}";
    if (!isset($pending[$key])) {
        $pending[$key] = [
            'ticket_id' => $ticketId,
            'subject'   => $subject,
            'filename'  => $audio['filename'],
            'url'       => $audio['url'],
            'file_id'   => $audio['file_id'],
            'attempts'  => 0,
            'queued_at' => date('Y-m-d H:i:s'),
        ];
    }
    $pending[$key]['attempts']++;
    $pending[$key]['last_attempt'] = date('Y-m-d H:i:s');

    savePending($pending);
}

/**
 * Process pending retries. Called from poll.php after main event dispatch.
 */
function processRetries(SplynxApiClient $api)
{
    $pending = loadPending();
    if (empty($pending)) return;

    global $slackWebhookUrl, $splynxAdminUrl;

    $completed = [];

    foreach ($pending as $key => $item) {
        if ($item['attempts'] >= MAX_RETRIES) {
            logMsg("Transcribe: Giving up on ticket #{$item['ticket_id']} {$item['filename']} after " . MAX_RETRIES . " attempts");
            $completed[] = $key;
            continue;
        }

        logMsg("Transcribe: Retry #{$item['attempts']} for ticket #{$item['ticket_id']} {$item['filename']}");

        $audio = [
            'filename' => $item['filename'],
            'url'      => $item['url'],
            'file_id'  => $item['file_id'],
        ];

        $transcription = attemptTranscription($api, $item['ticket_id'], $audio);

        if ($transcription !== false) {
            // Success! Post note and send Slack follow-up
            postTranscriptionNote($api, $item['ticket_id'], $item['filename'], $transcription);
            sendTranscriptionSlack($item['ticket_id'], $item['subject'], $item['filename'], $transcription);
            $completed[] = $key;
        } else {
            // Still failing — increment attempt count
            $pending[$key]['attempts']++;
            $pending[$key]['last_attempt'] = date('Y-m-d H:i:s');
        }
    }

    // Remove completed/given-up items
    foreach ($completed as $key) {
        unset($pending[$key]);
    }

    savePending($pending);
}

/**
 * Send a follow-up Slack message with the transcription (for retried items).
 */
function sendTranscriptionSlack(int $ticketId, string $subject, string $filename, string $transcription)
{
    global $slackWebhookUrl, $splynxAdminUrl;

    if (empty($slackWebhookUrl) || strpos($slackWebhookUrl, 'YOUR') !== false) return;

    $ticketUrl = rtrim($splynxAdminUrl, '/') . "/admin/tickets/opened--view?id={$ticketId}";

    $text = ":studio_microphone: *Voicemail Transcription* — <{$ticketUrl}|#{$ticketId} {$subject}>"
        . "\n_{$filename}_"
        . "\n>>> {$transcription}";

    $payload = [
        'text' => "Transcription for #{$ticketId}",
        'blocks' => [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
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
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        logMsg("Transcribe: Sent follow-up Slack transcription for ticket #{$ticketId}");
    }
}

// ============================================================
// Pending file helpers
// ============================================================

function loadPending(): array
{
    if (!file_exists(PENDING_FILE)) return [];
    $data = json_decode(file_get_contents(PENDING_FILE), true);
    return is_array($data) ? $data : [];
}

function savePending(array $pending)
{
    file_put_contents(PENDING_FILE, json_encode($pending, JSON_PRETTY_PRINT));
}

// ============================================================
// Audio detection & transcription functions
// ============================================================

/**
 * Find audio attachments in a ticket's messages.
 * Splynx stores attachments in a 'files' array on each message with:
 *   - filename_original: the real filename
 *   - download_link: URL to download the file
 *   - id: file ID
 */
function findAudioAttachments(SplynxApiClient $api, int $ticketId): array
{
    global $audioExtensions, $maxAudioSize;

    $messages = $api->get("admin/support/ticket-messages", [
        'main_attributes' => ['ticket_id' => $ticketId],
    ]);

    if (!is_array($messages) || empty($messages)) return [];

    $audioFiles = [];

    foreach ($messages as $msg) {
        $files = $msg['files'] ?? [];
        if (!is_array($files) || empty($files)) continue;

        foreach ($files as $file) {
            $filename = $file['filename_original'] ?? '';
            if (empty($filename)) continue;

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, $audioExtensions)) continue;

            $downloadUrl = $file['download_link'] ?? null;
            if (empty($downloadUrl)) {
                logMsg("Transcribe: Ticket #{$ticketId} — no download_link for {$filename}, skipping");
                continue;
            }

            $audioFiles[] = [
                'filename'   => $filename,
                'url'        => $downloadUrl,
                'file_id'    => $file['id'] ?? null,
                'message_id' => $msg['id'] ?? null,
            ];
        }
    }

    return $audioFiles;
}

/**
 * Transcribe an audio file. Prefers Google AI Studio, falls back to OpenAI.
 */
function transcribeAudioFile(string $filePath, string $fileName)
{
    global $googleAIStudioKey, $openAIKey;

    if (!empty($googleAIStudioKey)) {
        $result = transcribeWithGoogleAI($filePath, $fileName, $googleAIStudioKey);
        if ($result !== false) return $result;
    }

    if (!empty($openAIKey)) {
        return transcribeWithOpenAI($filePath, $fileName, $openAIKey);
    }

    logMsg("Transcribe: No API key configured");
    return false;
}

/**
 * Transcribe using Google AI Studio (Gemini 2.5 Flash).
 */
function transcribeWithGoogleAI(string $filePath, string $fileName, string $apiKey)
{
    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) return false;

    $base64Audio = base64_encode($fileContent);
    $mimeType = mime_content_type($filePath) ?: 'audio/mpeg';

    $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . $apiKey;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => 'Please transcribe this audio file. Provide only the transcription text without any additional commentary or formatting. If the audio is unclear or empty, respond with "[inaudible]".'
                    ],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => $base64Audio
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logMsg("Transcribe: Google AI cURL error: $curlError");
        return false;
    }

    if ($httpCode !== 200) {
        logMsg("Transcribe: Google AI error (HTTP $httpCode): " . substr($response, 0, 200));
        return false;
    }

    $result = json_decode($response, true);
    $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!$text) {
        logMsg("Transcribe: Google AI returned no text");
        return false;
    }

    return trim($text);
}

/**
 * Transcribe using OpenAI Whisper API.
 */
function transcribeWithOpenAI(string $filePath, string $fileName, string $apiKey)
{
    $url = 'https://api.openai.com/v1/audio/transcriptions';
    $cFile = new CURLFile($filePath, mime_content_type($filePath) ?: 'audio/mpeg', $fileName);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $cFile, 'model' => 'whisper-1', 'response_format' => 'json'],
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logMsg("Transcribe: OpenAI error (HTTP $httpCode): " . substr($response, 0, 200));
        return false;
    }

    $result = json_decode($response, true);
    return $result['text'] ?? false;
}
