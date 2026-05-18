# Splynx Ticket Events

Unified ticket event dispatcher. Polls Splynx once, detects new tickets and customer replies, then dispatches events to pluggable handlers.

Replaces the standalone `splynx-slack-poller` with an extensible architecture — add new features by dropping a handler file into `handlers/`.

## Architecture

```
poll.php                          ← single cron job (detects events)
  ↓
  ├── handlers/transcribe.php      ← Voicemail transcription
  ├── handlers/match_customer.php  ← Auto-assign customer from caller ID
  ├── handlers/slack.php           ← Slack notifications (with transcription + match)
  └── handlers/future.php          ← your next feature
```

Handlers run in config order. Each handler can enrich the event object so downstream handlers can use the data (e.g. transcription text flows into Slack, customer match flows into Slack).

## Events

| Event | Trigger |
|-------|---------|
| `new_ticket` | Customer created a new ticket |
| `customer_reply` | Customer replied to an existing ticket |

## How it works

1. Cron runs `poll.php` every minute
2. Fetches all open tickets, compares against `state.json`
3. Classifies changes as `new_ticket` or `customer_reply`
4. Filters out admin/API-created tickets and ignored senders
5. Dispatches each event to all enabled handlers (in order)
6. Retries any failed transcriptions from previous runs
7. Saves state for next run

## Handlers

### Transcribe (`handlers/transcribe.php`)

Detects audio/voicemail attachments (WAV, MP3, etc.) on new tickets, transcribes them via Google AI Studio (Gemini 2.5 Flash), and posts the transcription as an internal admin note.

- Events: `new_ticket`
- Retry: If transcription fails (e.g. API rate limit), queues for retry on next poll (up to 3 attempts). On successful retry, sends a follow-up Slack message.
- Enriches event with `$event['transcriptions']` for downstream handlers.

### Match Customer (`handlers/match_customer.php`)

For voicemail tickets that are unassigned or assigned to a generic account (e.g. "2Talk Limited"), attempts to identify and assign the real customer.

- Events: `new_ticket`
- Uses the `/dev/shm/splynx_active_services.json` data store (built by `splynx-service`) for fast lookups.
- Match priority:
  1. **Caller ID** from ticket subject (e.g. `from caller Name (063726897)`)
  2. **Phone numbers** mentioned in the transcription
  3. **Customer name** mentioned in the transcription or subject
  4. **Address** mentioned in the transcription
- Phone matching includes NZ format normalization (0xx ↔ +64xx ↔ 64xx).
- On match, assigns the customer via `PUT admin/support/tickets/{id}`.
- Enriches event with `$event['customer_matched']` for Slack.

### Slack (`handlers/slack.php`)

Sends notifications to Slack for new tickets and customer replies.

- Events: `new_ticket`, `customer_reply`
- If transcription is available, shows it in the notification with a 🎙️ icon.
- If customer was auto-matched, shows the matched name and method.
- Falls back to message preview for non-voicemail tickets.

## Requirements

- PHP 7.4+ with curl extension
- Splynx instance with API access
- `/dev/shm/splynx_active_services.json` (built by `splynx-service` cron) for customer matching
- Handler-specific: Slack webhook URL, Google AI Studio key (or OpenAI key)

## Setup

1. Copy `config.php.example` to `config.php`
2. Fill in Splynx API credentials
3. Configure handler settings:
   - Slack webhook URL
   - Google AI Studio key for transcription
   - Generic customer IDs to re-match (e.g. 2Talk Limited)
4. Create writable files and set permissions:

```bash
touch /var/www/html/splynx-ticket-events/events.log
touch /var/www/html/splynx-ticket-events/state.json
touch /var/www/html/splynx-ticket-events/pending_transcriptions.json
chown -R www-data:www-data /var/www/html/splynx-ticket-events/
```

5. Add to crontab (as www-data):

```
* * * * * /usr/bin/php /var/www/html/splynx-ticket-events/poll.php >> /var/www/html/splynx-ticket-events/events.log 2>&1
```

6. Disable the old `splynx-slack-poller` cron job (this replaces it)

## Adding a new handler

Create `handlers/yourfeature.php` that returns a callable:

```php
<?php
return function (array &$event, SplynxApiClient $api) {
    // $event['type'] is 'new_ticket' or 'customer_reply'
    // $event['ticket'] is the full ticket array from Splynx API
    // $event['transcriptions'] is set if transcribe handler ran successfully
    // $event['customer_matched'] is set if match_customer handler found a match

    if ($event['type'] !== 'new_ticket') return;

    $ticket = $event['ticket'];
    // ... do your thing

    // Optionally enrich the event for downstream handlers:
    $event['your_data'] = ['key' => 'value'];
};
```

Then add it to `config.php` (order matters — handlers run top to bottom):

```php
$handlers = [
    'transcribe'     => true,
    'match_customer' => true,
    'yourfeature'    => true,  // runs after match, before slack
    'slack'          => true,
];
```

## Files

| File | Purpose |
|------|---------|
| `poll.php` | Main dispatcher (run via cron) |
| `handlers/transcribe.php` | Voicemail transcription handler |
| `handlers/match_customer.php` | Auto-assign customer from caller ID/transcription |
| `handlers/slack.php` | Slack notification handler |
| `config.php` | Configuration (git-ignored) |
| `config.php.example` | Config template |
| `debug_ticket.php` | Inspect a ticket's data and attachments |
| `test_transcribe.php` | Test transcription without posting |
| `test_match.php` | Test customer matching without assigning |
| `SplynxApiClient.php` | Splynx API client |
| `state.json` | Runtime state (git-ignored, auto-created) |
| `pending_transcriptions.json` | Failed transcription retry queue (git-ignored) |
| `events.log` | Log output (git-ignored) |

## Debugging

Inspect a ticket's structure and attachments:

```bash
php /var/www/html/splynx-ticket-events/debug_ticket.php 11519
```

Test transcription without posting a note:

```bash
php /var/www/html/splynx-ticket-events/test_transcribe.php 11519
```

Test customer matching without assigning:

```bash
php /var/www/html/splynx-ticket-events/test_match.php 11519
```

Check recent activity:

```bash
tail -f /var/www/html/splynx-ticket-events/events.log
```

## Migration from splynx-slack-poller

This project fully replaces `splynx-slack-poller`. To migrate:

1. Set up this project with your existing Splynx + Slack credentials
2. Disable the `splynx-slack-poller` cron entry
3. Enable the new cron entry for this project
4. The Slack handler produces identical notifications (plus transcription and match info)

The old `splynx-slack-poller` can be removed once confirmed working.
