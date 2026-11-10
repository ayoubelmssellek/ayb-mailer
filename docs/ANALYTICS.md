# Analytics dashboard (Phase 5)

Extends the existing **Analytics** page without a visual redesign. There is **no** unified `message_events` database (Phase 2 was skipped). Each method keeps its own stores.

## Vocabulary

| Term | Meaning |
|------|---------|
| **Accepted** | SMTP/API accepted the message for processing |
| **Delivered** | SendGrid provider-confirmed delivery (when synced) |
| **OK (Normal/SOCKS)** | Local PHPMailer `Send()` success — not inbox delivery |

**Accepted ≠ Delivered.** Method comparison UI never equates SOCKS/Kumo accept with SendGrid delivered.

## Sections (Analytics page)

1. Sending methods status  
2. KumoMTA status + recent native log line  
3. Sender profiles  
4. Warm-up summary (full controls on **Warm-up** page)  
5. Campaign / queue lanes (valid / done / pending≈)  
6. Provider split (Kumo warm-up today)  
7. Method comparison (honest labels)  
8. Searchable provider logs (`kumomta.log` / `sendgrid.log`)  
9. Global suppressions (existing)  
10. Local Kumo throughput from warm-up series  

SendGrid sync card remains operational (mode-gated).

## API

`analytics_overview_api.php`

| Action | Purpose |
|--------|---------|
| `overview` | Aggregated dashboard JSON (`days`, `country`) |
| `logs` | Tail/search a provider log (`source=kumomta\|sendgrid`, `q`) |
| `queues` | Lane file counts |

## Offline test

```bash
php tests/analytics_offline_test.php
```
