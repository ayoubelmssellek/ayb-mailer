# Global suppressions, bounces, and unsubscribe

Phase 3 — independent of SendGrid analytics DB and of skipped Phase 2 unified message events.

## Storage

- SQLite: `data/suppressions.sqlite`
- Tables: `suppression_global`, `suppression_audit`, `soft_bounce_events`
- Optional exclude files: `exclude_{lane}_{cc}.txt` (e.g. `exclude_kumomta_pt.txt`)

## Reasons

`hard_bounce` · `soft_bounce` · `complaint` · `unsubscribe` · `manual` · `invalid` · `block` · `exclude`

## Pre-send

All three methods call `suppression_is_blocked($email)` before submit when `SUPPRESSION_ENABLED=true` (default):

- SendGrid → inside `sendEmail()`
- KumoMTA → inside `kumomta_send_email()`
- Normal/SOCKS → inside `mailer_socks_send_attempt()`

Blocked recipients are **not** submitted. Campaign loops should treat status `excluded` as a permanent skip (mark done) so the address is not retried forever.

## Soft bounce policy

Configurable:

```
SUPPRESSION_SOFT_BOUNCE_MAX=3
SUPPRESSION_SOFT_BOUNCE_DAYS=14
```

After N soft-bounce events for the same address inside the window → auto global suppress (`soft_bounce`).

## SendGrid import

After SendGrid sync pulls provider suppressions into `sg_suppressions`, the sync also **additively** imports them into the global list (`suppression_import_sendgrid_local`). Mapping:

| SendGrid type | Global reason |
|---------------|---------------|
| bounce | hard_bounce |
| spamreport | complaint |
| block | block |
| invalid | invalid |
| unsubscribe | unsubscribe |

Manual re-import: `suppression_api.php?action=import_sendgrid`

## One-click unsubscribe (Kumo marketing)

1. Set a reachable base URL (LAN/VPN/public as appropriate):

```
UNSUBSCRIBE_BASE_URL=http://your-host/ayb-mailer/unsubscribe.php
UNSUBSCRIBE_SECRET=long-random-string
UNSUBSCRIBE_MAILTO=unsub@omnidigitalsuite.com
```

2. Kumo sends add `List-Unsubscribe` (+ `List-Unsubscribe-Post` when the URL is absolute `http(s)`).
3. `unsubscribe.php` validates the HMAC token and adds `unsubscribe` to the global list.

Invalid/expired tokens make **no** change.

## API

`suppression_api.php` actions: `list`, `add`, `remove`, `check`, `counts`, `audit`, `soft_bounce`, `import_sendgrid`.

## Offline test

```bash
php tests/suppression_offline_test.php
```

## Feature flag / rollback

```
SUPPRESSION_ENABLED=false
```

Disables the pre-send gate (lists and unsubscribe endpoint still work).
