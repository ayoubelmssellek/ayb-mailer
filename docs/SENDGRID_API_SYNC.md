# SendGrid API sync (no Event Webhook)

OmniDigitalSuite Mailer retrieves delivery statistics and related data **exclusively via SendGrid REST APIs**, then stores results in a **local SQLite** file. There is **no Event Webhook**, no public URL, no ngrok/tunnel.

> Note: This project does **not** use MySQL. Analytics storage is `data/sendgrid_analytics.sqlite`.

## Removed webhook components

- `sendgrid_webhook.php` (deleted)
- `SENDGRID_WEBHOOK_PUBLIC_KEY` env var
- Signed Event Webhook verification (`sendgrid_verify_event_signature`)
- Dashboard text that pointed delivery events at a webhook URL

## API endpoints used

| Endpoint | Purpose | Observed on this account (probe) |
|----------|---------|-----------------------------------|
| `GET /v3/stats` | Daily aggregate metrics (processed, delivered, deferred, bounces, opens, clicks, spam, unsubscribes, drops, …) | Available (HTTP 200) |
| `GET /v3/messages` | Email Activity / individual message history | Available (HTTP 200); empty until mail is sent |
| `GET /v3/suppression/bounces` | Bounce suppressions | Available |
| `GET /v3/suppression/spam_reports` | Spam reports | Available |
| `GET /v3/suppression/blocks` | Blocks | Available |
| `GET /v3/suppression/invalid_emails` | Invalid emails | Available |
| `GET /v3/suppression/unsubscribes` | Global unsubscribes | Available |
| `GET /v3/asm/groups` | ASM group definitions | Available (may be empty) |
| `GET /v3/user/credits` | Remaining credits | Available |
| `POST /v3/mail/send` | Sending only (unchanged) | Existing mailer path |

## Event / metric coverage

**From `/v3/stats` (aggregates, not per-recipient events):**
processed, requests, delivered, deferred, bounces, bounce_drops, blocks, opens, unique_opens, clicks, unique_clicks, spam_reports, spam_report_drops, unsubscribes, unsubscribe_drops, invalid_emails

**From Email Activity `/v3/messages` (when available):**
per-message status, opens_count, clicks_count, subject, to/from, last_event_time

**From suppression APIs:**
bounce, spam report, block, invalid, unsubscribe lists

**Not fully available via polling:**
- Continuous per-event streams equivalent to webhook `group_unsubscribe` / `group_resubscribe`
- Real-time push delivery
- Unlimited historical Email Activity (approx. **30-day** retention; **max 1000** messages per query; ~**6 req/min**)

The UI distinguishes **unavailable** endpoints from **zero activity** after a successful sync.

## Synchronization behavior

1. User clicks **Sync from SendGrid** (or POSTs `sendgrid_sync.php` with `action:"sync"`).
2. App authenticates with `SENDGRID_API_KEY` from `.env`.
3. Pulls stats, messages, suppressions, ASM groups, credits.
4. Upserts into local SQLite with uniqueness constraints (no duplicate daily stats / msg_id / suppression rows).
5. Records `last_sync_at` / status / date range.
6. Dashboard **Load local** reads only the local DB (no network).

This is **on-demand polling**, not real-time synchronization.

## How to sync

Dashboard → Analytics → **Sync from SendGrid**

Or:

```bash
php -r "require 'sendgrid_analytics_lib.php'; print_r(sg_sync_all(gmdate('Y-m-d',strtotime('-7 days')), gmdate('Y-m-d')));"
```

## Security

- API key stays in `.env` (gitignored).
- Never returned by `sendgrid_status.php` / sync JSON (only a short preview on status).
- Sync logs never write Authorization headers.

## Official docs

- [Global stats](https://www.twilio.com/docs/sendgrid/api-reference/stats/retrieve-global-email-statistics)
- [Email Activity](https://www.twilio.com/docs/sendgrid/api-reference/email-activity)
- [Filter messages](https://www.twilio.com/docs/sendgrid/api-reference/email-activity/filter-all-messages)
