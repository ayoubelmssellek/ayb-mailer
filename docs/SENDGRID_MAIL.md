# SendGridMail — multi-country verified delivered export

Read-only dashboard + API to export **verified SendGrid-delivered** recipients per country for later VPS use.

## Eligibility (strict)

**Include only when all are true:**

1. Email is in the selected country’s **SendGrid api-lane** membership (`validmails[_cc].txt` ∪ `mailssenddone[_cc].txt`).
2. Local SendGrid analytics has `sg_messages.status = delivered`.
3. No consent / suppression veto (see below).
4. No conflicting later negative message status.

**Never sufficient alone:**

- Presence in `mailssenddone*.txt`
- `sg_local_sends` (HTTP 202 accept)
- Aggregate `/v3/stats` delivered counts
- Email domain suffixes (`.pt`, `.es`, …)

## Consent & suppressions (always win)

A historical `delivered` event is **never** enough if any of these apply:

| Source | Effect |
|--------|--------|
| Global `suppressions.sqlite` unsubscribe / complaint | Excluded (consent) |
| Global hard/soft bounce | Bounced bucket |
| Global block / invalid / manual / exclude | Excluded or blocked |
| SendGrid `unsubscribe` / `spamreport` suppressions | Excluded (consent) |
| SendGrid `bounce` | Bounced |
| SendGrid `block` / `invalid` | Blocked/dropped |
| Delivered then later `not_delivered` (by `last_event_time`) | Excluded (conflict) |
| Only `processed` / `deferred` / empty / missing | Excluded/unknown |

## Countries

- Dynamic **2-letter** codes (`pt`, `es`, `fr`, `de`, …).
- Discovered from api-lane queue filenames + UI `known` codes.
- Germany = **`de`** (not `GER`).
- Countries are never mixed unless you explicitly switch the selector.
- SMTP / KumoMTA queue files are ignored.

## API

`sendgrid_mail_api.php`

| Action | Result |
|--------|--------|
| `countries` | Discovered country list |
| `overview&country=XX` | KPI stats + notes |
| `recipients&country=XX` | Paginated rows (`q`, `bucket`, `page`) |
| `emails&country=XX` | Eligible emails only (JSON) |
| `export&country=XX` | TXT download + copy under `data/exports/sendgrid_delivered_XX.txt` |

Compat shim: `sendgrid_mail_pt_api.php` forces `country=pt`.

## UI

Sidebar → **SendGridMail**

- Country selector, delivered/bounced/blocked/excluded KPIs  
- Search, pagination, row select, select-all-eligible (page)  
- Copy selected / copy all eligible / export TXT  
- Output is **emails only**, one per line  

## Limitations

- SendGrid Email Activity: ~30-day retention, ≤1000 rows/query (truncation warning).
- No country field on SendGrid events — country requires local queue membership files.
- If queue files or sync data are missing, eligible counts are **0** with an explicit note (not invented).

## Safety

- Does not send email  
- Does not modify source queues  
- Does not change VPS / KumoMTA config  

## Tests

```bash
php tests/sendgrid_mail_offline_test.php
```
