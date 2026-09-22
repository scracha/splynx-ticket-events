# Splynx Ticket Events

Unified ticket event dispatcher. Polls Splynx once, detects new tickets and customer replies, then dispatches events to pluggable handlers.

Replaces the standalone `splynx-slack-poller` with an extensible architecture — add new features by dropping a handler file into `handlers/`.

## Architecture

```
poll.php                          ← single cron job (detects events)
  ↓
  ├── handlers/transcribe.php      ← Voicemail transcription
  ├── handlers/match_customer.php  ← Auto-assign customer from caller ID / transcription
  ├── handlers/slack.php           ← Slack notifications (with transcription + match / suggestions)
  └── handlers/future.php          ← your next feature
```

Handlers run in config order. Each handler can enrich the event object so downstream handlers can use the data (e.g. transcription text flows into customer matching, customer match details flow into Slack).

## Events

| Event | Trigger |
|---|---|
| `new_ticket` | Customer created a new ticket |
| `customer_reply` | Customer replied to an existing ticket |

## How it works

1. Cron runs `poll.php` every minute.
2. Fetches all open tickets, compares against `state.json`.
3. Classifies changes as `new_ticket` or `customer_reply`.
4. Filters out admin/API-created tickets and ignored senders.
5. Dispatches each event to all enabled handlers (in order).
6. Retries any failed transcriptions from previous runs.
7. Saves state for next run.

## Handlers

### Transcribe (`handlers/transcribe.php`)

Detects audio/voicemail attachments (WAV, MP3, etc.) on new tickets, transcribes them via Google AI Studio (Gemini 2.5 Flash), and posts the transcription as an internal admin note.

- **Events:** `new_ticket`
- **Retry:** If transcription fails (e.g. API rate limit), queues for retry on next poll (up to 3 attempts). On successful retry, sends a follow-up Slack message.
- Enriches event with `$event['transcriptions']` for downstream handlers.

### Match Customer (`handlers/match_customer.php`)

For voicemail tickets that are unassigned or assigned to a generic account (e.g. "2Talk Limited"), evaluates candidates using a multi-signal scoring engine (0–100% confidence) with fuzzy and phonetic matching.

- **Events:** `new_ticket` (runs after transcription)
- **Data store:** Uses `/dev/shm/splynx_active_services.json` (built by `splynx-service`), aggregating duplicate IPs/services per customer.
- **Signals & Evaluation Priority:**
  1. **Caller ID (100%):** Extracted from 2Talk message body (`From: <number>`) or subject line (`from [caller] <number>`). Matches against primary `Phones` or `Contact 2 Phone` with full NZ normalization (`0xx` ↔ `+64xx` ↔ `64xx`).
  2. **Transcribed Phone Numbers (95%):** Spoken phone numbers extracted from audio transcription matched against customer and contact 2 phones.
  3. **Physical Address (80–85%):** Spoken street addresses evaluated against `service_address` and billing address. Includes street suffix normalization and phonetic / Levenshtein matching (`metaphone` handles speech-to-text mishearings like *Longbish* → *Longbush*).
  4. **Full Name (75–80%):** Exact or phonetic first + last name matching against customer full name or contact 2 name (e.g. *Smyth* ↔ *Smith*).
  5. **Compound Disambiguation (90–100%):** If multiple customers share an address, matching a spoken name (even a first name like *"Jessica"*) acts as a compound booster to disambiguate the exact account.
  6. **Unusual First Names (65–70%):** First names with $\le 3$ occurrences across the entire customer database are flagged as **suggested matches**. Common first names ($> 3$ occurrences) are rejected standalone to prevent false positives.

- **Decision Logic:**
  - **$\ge 80\%$ confidence & clear winner:** Automatically assigns the customer via `PUT admin/support/tickets/{id}` and posts an internal ticket note detailing the customer link, confidence score, and match reason.
  - **1 to 3 viable candidates (65–79% confidence or tied):** Left **unassigned**. Posts an internal ticket note and Slack warning listing up to 3 suggested matches with direct links for manual review.
  - **More than 3 candidates / below 65%:** Left unassigned as **unknown** to prevent clutter.

### Slack (`handlers/slack.php`)

Sends notifications to Slack for new tickets and customer replies.

- **Events:** `new_ticket`, `customer_reply`
- If transcription is available, displays it with a :studio_microphone: block.
- If customer was auto-matched, shows matched customer name and confidence reason.
- If ambiguous matches / suggestions were found, displays a :bulb: warning with candidate names and scores.
- Falls back to clean message previews for standard non-voicemail tickets.

## Requirements

- PHP 7.4+ with `curl` extension
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

5. Add to crontab (as `www-data`):

```cron
* * * * * /usr/bin/php /var/www/html/splynx-ticket-events/poll.php >> /var/www/html/splynx-ticket-events/events.log 2>&1
```

6. Disable the old `splynx-slack-poller` cron job (this replaces it).

## Testing & Debugging

Inspect a ticket's structure and attachments:
```bash
php debug_ticket.php 12710
```

Test transcription without posting a note:
```bash
php test_transcribe.php 12710
```

Test customer matching scoring, candidates, and decision logic without making changes:
```bash
php test_match.php 12710
```

Check recent activity logs:
```bash
tail -f events.log
```
