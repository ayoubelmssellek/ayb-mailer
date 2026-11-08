# Brevo → Twilio SendGrid Migration

AYB Mailer cloud email delivery now uses **Twilio SendGrid Web API v3** only (`POST https://api.sendgrid.com/v3/mail/send`, Bearer API key). Brevo API/SMTP send paths are disabled. Normal PHPMailer SMTP (non-Brevo) remains available as **Normal + SOCKS**.

## What was removed (Brevo send path)

- Active **Brevo API** send mode (`brevo_api`) — mapped to / replaced by **SendGrid**.
- Active **Brevo SMTP** mode (`brevo` / `smtp-relay.brevo.com`) — POST is rejected with a clear error.
- `brevo_api_send_email()` — stubbed; does not call `api.brevo.com`.
- UI radio for Brevo SMTP / multi-key Brevo account picker for sending (hidden/disabled).
- Requirement to paste Brevo `xkeysib-` keys into the browser for sending.

**Not removed (historical / analytics only):** `brevo_*.php` recipient analytics, SQLite exports, and stats helpers. They do **not** send mail. Live sending does not use them.

## What was added (SendGrid)

| File | Role |
|------|------|
| `env_load.php` | Loads `.env` into process env |
| `sendgrid_mail.php` | Central `sendEmail()` / `sendgrid_api_send_email()` |
| `sendgrid_status.php` | Safe config status JSON (no full API key) |
| `sendgrid_sync.php` | Local API polling sync (stats/activity/suppressions) |
| `sendgrid_analytics_lib.php` | Local SQLite analytics + SendGrid GET helpers |
| `sendgrid_test.php` | Optional live test (explicit confirm) |
| `.env.example` | Placeholder env vars |
| `docs/SENDGRID_MIGRATION.md` | This document |
| `tests/sendgrid_offline_test.php` | Offline automated checks |

Composer dependency: `sendgrid/sendgrid` (^8.1).

## Environment variables

Copy `.env.example` → `.env` (already gitignored).

```
SENDGRID_API_KEY=
SENDGRID_FROM_EMAIL=notify@omnidigitalsuite.com
SENDGRID_FROM_NAME=OmniDigitalSuite
SENDGRID_REPLY_TO=notify@omnidigitalsuite.com
SENDGRID_API_URL=https://api.sendgrid.com/v3/mail/send
SENDGRID_ENABLED=true
SENDGRID_TEST_TO=
```

### How to configure the API key

1. In SendGrid: create an API key with **Restricted Access** → **Mail Send: Full Access**.
2. Paste it only into local `.env` as `SENDGRID_API_KEY=SG....`
3. Never commit `.env`, never paste the key into chat, never put it in frontend JS.

## Sender configuration

| Setting | Value |
|---------|-------|
| From email | `notify@omnidigitalsuite.com` |
| From name | `OmniDigitalSuite` |
| Reply-To | `notify@omnidigitalsuite.com` |
| Authenticated domain | `em283.notify.omnidigitalsuite.com` |
| Link branding | `url6512.notify.omnidigitalsuite.com` |

HTTP **202** from SendGrid means **accepted for processing**, not inbox delivery.

## Email service architecture

1. UI / POST selects `smtp_mode=sendgrid`.
2. `index.php` validates config via `sendgrid_config_status()`.
3. Each recipient is sent through `sendgrid_api_send_email()` → `sendEmail()`.
4. Official SDK (`SendGrid\Mail\Mail`) is preferred; curl REST fallback uses the same endpoint.
5. Results normalize to: `accepted` | `failed` | `configuration_error`.

## Workflows using SendGrid

- Campaign list sends (API lane / blue list)
- Test-box sends in SendGrid mode
- Stop → pending resume (same queue files as before)

Normal SMTP + SOCKS is unchanged and does not use SendGrid.

## Analytics (API polling — no webhook)

See [SENDGRID_API_SYNC.md](SENDGRID_API_SYNC.md). Delivery/open/click/bounce statistics are synced locally via SendGrid GET APIs into SQLite. This is **not** real-time.

## How to run tests

```bash
cd ayb-mailer
php -l sendgrid_mail.php
php -l sendgrid_sync.php
php -l sendgrid_analytics_lib.php
php -l index.php
composer validate --no-check-publish
composer dump-autoload -o
php tests/sendgrid_offline_test.php
php tests/sendgrid_api_probe.php
```

### Live test email (optional)

1. Set `SENDGRID_API_KEY` and `SENDGRID_TEST_TO` in `.env`
2. Run: `php sendgrid_test.php --confirm`  
   or open `sendgrid_test.php?confirm=1`

Do not run without both env vars and `--confirm` / `confirm=1`.

## Database

SendGrid analytics uses a **new local SQLite file** (`data/sendgrid_analytics.sqlite`) with additive schema only. Historical Brevo SQLite data is not modified. There is still **no MySQL** in this project.

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| configuration_error / missing key | Empty `SENDGRID_API_KEY` in `.env` |
| HTTP 401 | Wrong/revoked API key |
| HTTP 403 | Sender not verified / insufficient key scopes |
| HTTP 400 | Invalid payload (bad address/content) |
| HTTP 429 | Rate limit — back off |
| HTTP 5xx | SendGrid outage — retry later manually |
| Email Activity empty / capped | ~30-day retention; max 1000 rows/query |

## Official documentation

1. [SendGrid PHP Quickstart](https://www.twilio.com/docs/sendgrid/for-developers/sending-email/quickstart-php)  
2. [Mail Send API](https://www.twilio.com/docs/sendgrid/api-reference/mail-send)  
3. [API Getting Started](https://www.twilio.com/docs/sendgrid/for-developers/sending-email/api-getting-started)  
4. [sendgrid-php SDK](https://github.com/sendgrid/sendgrid-php)  
5. [Global stats API](https://www.twilio.com/docs/sendgrid/api-reference/stats/retrieve-global-email-statistics)  
6. [Email Activity API](https://www.twilio.com/docs/sendgrid/api-reference/email-activity)

## Known limitations

- HTTP 202 ≠ delivered; use API sync (`/v3/stats`, `/v3/messages`, suppressions) for delivery/engagement signals.
- Sync is polling, not real-time.
- Live delivery cannot be verified until you set `SENDGRID_API_KEY` (and optionally `SENDGRID_TEST_TO`).
