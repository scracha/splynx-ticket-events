#!/usr/bin/env php
<?php
/**
 * Splynx Ticket Event Dispatcher
 *
 * Single poller that detects new tickets and customer replies,
 * then dispatches events to registered handlers.
 *
 * Run via cron every 1-2 minutes:
 *   * * * * * www-data php /var/www/html/splynx-ticket-events/poll.php >> /var/www/html/splynx-ticket-events/events.log 2>&1
 *
 * Events dispatched:
 *   - new_ticket: A customer created a new ticket
 *   - customer_reply: A customer replied to an existing ticket
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SplynxApiClient.php';

$api = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// Load registered handlers
$activeHandlers = loadHandlers($handlers);

if (empty($activeHandlers)) {
    logMsg("WARNING: No handlers enabled, nothing to do");
    exit(0);
}

// Load state
$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

// Determine lookback window
$lastRunTime = $state['_last_run'] ?? null;
if ($lastRunTime) {
    $lookbackSeconds = (time() - $lastRunTime) + 120;
} else {
    $lookbackSeconds = ($pollLookbackMinutes * 60) + 120;
}
$lookbackSeconds = min($lookbackSeconds, 3600);  // Cap at 1 hour

// Fetch all open tickets (paginated)
$tickets = [];
$offset = 0;
$pageSize = 200;
while (true) {
    $page = $api->get('admin/support/tickets', [
        'limit'  => $pageSize,
        'offset' => $offset,
        'main_attributes' => [
            'closed' => 0,
            'trash'  => 0,
        ],
    ]);
    if (!is_array($page) || isset($page['error']) || empty($page)) break;
    $tickets = array_merge($tickets, $page);
    if (count($page) < $pageSize) break;
    $offset += $pageSize;
}

if (empty($tickets)) {
    logMsg("ERROR: Failed to fetch tickets from Splynx");
    exit(1);
}

$events = [];

foreach ($tickets as $ticket) {
    $id = $ticket['id'] ?? null;
    if (!$id) continue;

    $updatedAt = $ticket['updated_at'] ?? '';
    $createdAt = $ticket['created_at'] ?? '';
    $unreadByAdmin = (int)($ticket['unread_by_admin'] ?? 0);
    $stateKey = "ticket_{$id}";
    $prevState = $state[$stateKey] ?? null;

    // --- New ticket (never seen before) ---
    if ($prevState === null) {
        $createdTime = strtotime($createdAt);
        if ($createdTime && (time() - $createdTime) < $lookbackSeconds) {
            // Skip admin/API-created and ignored incoming customers
            if (!isIgnoredTicket($ticket)) {
                $events[] = [
                    'type'   => 'new_ticket',
                    'ticket' => $ticket,
                ];
            }
        }
        $state[$stateKey] = [
            'updated_at'      => $updatedAt,
            'unread_by_admin' => $unreadByAdmin,
        ];
        continue;
    }

    // --- Customer reply (existing ticket updated with new unread messages) ---
    $prevUnread = $prevState['unread_by_admin'] ?? 0;
    if ($updatedAt !== ($prevState['updated_at'] ?? '') && $unreadByAdmin > $prevUnread) {
        $events[] = [
            'type'   => 'customer_reply',
            'ticket' => $ticket,
        ];
    }

    $state[$stateKey] = [
        'updated_at'      => $updatedAt,
        'unread_by_admin' => $unreadByAdmin,
    ];
}

// --- Dispatch events to handlers ---
// Handlers receive $event by reference so earlier handlers can enrich it
// (e.g. transcribe adds transcription text, slack includes it in notification)
$dispatched = 0;
foreach ($events as &$event) {
    $ticketId = $event['ticket']['id'];
    $type = $event['type'];

    foreach ($activeHandlers as $name => $handler) {
        try {
            $handler($event, $api);
        } catch (Exception $e) {
            logMsg("ERROR: Handler '{$name}' failed on ticket #{$ticketId} ({$type}): " . $e->getMessage());
        }
    }
    $dispatched++;
}
unset($event);

// Clean up old closed tickets from state
$activeIds = array_map(fn($t) => "ticket_" . ($t['id'] ?? ''), $tickets);
foreach (array_keys($state) as $key) {
    if ($key === '_last_run') continue;
    if (!in_array($key, $activeIds)) {
        unset($state[$key]);
    }
}

// Save state
$state['_last_run'] = time();
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

if ($dispatched > 0) {
    logMsg("Dispatched {$dispatched} event(s) to " . count($activeHandlers) . " handler(s)");
}

// --- Process pending transcription retries ---
if (isset($activeHandlers['transcribe']) && function_exists('processRetries')) {
    processRetries($api);
}

// ============================================================
// Core Functions
// ============================================================

/**
 * Load enabled handler files from the handlers/ directory.
 * Each handler file must return a callable.
 */
function loadHandlers(array $handlerConfig): array
{
    $loaded = [];
    $handlerDir = __DIR__ . '/handlers';

    foreach ($handlerConfig as $name => $enabled) {
        if (!$enabled) continue;

        $file = $handlerDir . '/' . $name . '.php';
        if (!file_exists($file)) {
            logMsg("WARNING: Handler file not found: {$file}");
            continue;
        }

        $handler = require $file;
        if (!is_callable($handler)) {
            logMsg("WARNING: Handler '{$name}' did not return a callable");
            continue;
        }

        $loaded[$name] = $handler;
    }

    return $loaded;
}

function isIgnoredTicket(array $ticket): bool
{
    global $ignoreAdminCreators, $ignoreApiCreators, $ignoreIncomingCustomerIds;

    $reporterType = $ticket['reporter_type'] ?? '';
    $reporterId = (int)($ticket['reporter_id'] ?? 0);

    // Admin-created
    if ($reporterType === 'admin') {
        if (!empty($ignoreAdminCreators)) {
            return $reporterId > 0 && in_array($reporterId, $ignoreAdminCreators);
        }
        return true;
    }

    // API-created
    if ($reporterType === 'api') {
        if (!empty($ignoreApiCreators)) {
            return $reporterId > 0 && in_array($reporterId, $ignoreApiCreators);
        }
        return true;
    }

    // Ignored incoming customers
    if (!empty($ignoreIncomingCustomerIds)) {
        $incomingId = (int)($ticket['incoming_customer_id'] ?? 0);
        if ($incomingId > 0 && in_array($incomingId, $ignoreIncomingCustomerIds)) {
            return true;
        }
    }

    return false;
}

function logMsg(string $msg): void
{
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}
