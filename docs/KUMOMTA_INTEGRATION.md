# KumoMTA / VPS SMTP integration

Third sending method alongside **Normal + SOCKS** and **Twilio SendGrid API**.

## What it does

- Mode key: `smtp_mode=kumomta` (UI: **VPS / KumoMTA**)
- Transport: PHPMailer SMTP **direct** to `smtp1.omnidigitalsuite.com` (no SOCKS)
- Queue lane: `kumomta` → `validmails_kumomta*.txt` / `mailssenddone_kumomta*.txt`
- Sender profiles: `mail` / `news` / `notify` (From/Reply-To; not forced to SMTP login)
- TLS: `verify_peer` / `verify_peer_name` **on** by default (`KUMOMTA_VERIFY_PEER=true`)

**SMTP acceptance ≠ inbox delivery.** The dashboard logs **ACCEPTED** when the server returns success; it does not claim the message was delivered to the mailbox.

## Configure

Copy placeholders from `.env.example` into local `.env` (never commit secrets):

```
KUMOMTA_ENABLED=true
KUMOMTA_HOST=smtp1.omnidigitalsuite.com
KUMOMTA_PORT=587
KUMOMTA_ENCRYPTION=tls
KUMOMTA_USERNAME=
KUMOMTA_PASSWORD=
KUMOMTA_TIMEOUT=30
KUMOMTA_VERIFY_PEER=true
KUMOMTA_DEFAULT_FROM=mail@omnidigitalsuite.com
KUMOMTA_DEFAULT_FROM_NAME=OmniDigitalSuite
KUMOMTA_DEFAULT_REPLY_TO=mail@omnidigitalsuite.com
```

Fill `KUMOMTA_USERNAME` and `KUMOMTA_PASSWORD`. Confirm with your VPS whether submission is **587 STARTTLS** (default) or **465 SMTPS** (`KUMOMTA_PORT=465` + `KUMOMTA_ENCRYPTION=ssl`).

## Files

| File | Role |
|------|------|
| `kumomta_mail.php` | Config, identity, send, diag helpers |
| `kumomta_status.php` | JSON status (no password) |
| `kumomta_diag.php` | Connect / TLS / AUTH probe (no campaign send) |
| `sender_profiles.php` | Profile load/defaults → `data/sender_profiles.json` |
| `queue_country.php` | Lane `kumomta` filenames |

## UI

1. Setup → select **VPS / KumoMTA**
2. Refresh status; optionally **Run SMTP diagnostic**
3. Choose sender profile
4. Recipients → **KumoMTA / VPS** list → Save
5. Compose → Send (test list or campaign)

Workers are forced to `1`; SOCKS is forced off for this mode.

## Offline / live checks

```bash
php tests/kumomta_offline_test.php
php tests/sendgrid_offline_test.php
```

Live diagnostic: open Setup → KumoMTA → **Run SMTP diagnostic**, or call `kumomta_diag.php?auth=1` after credentials are set. Do not mass-send until a single test recipient is accepted.

## Independence

- Does not modify SendGrid API send or analytics SQLite
- Does not share Normal SOCKS done-files
- Failures are **not** written to `mailssenddone_kumomta*` (retry next run)
- Stop flag is respected mid-batch
