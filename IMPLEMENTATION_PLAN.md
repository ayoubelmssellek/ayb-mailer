# AYB Mailer — Implementation Plan

**Status:** Phase 0 done · Phase 1 done · Phase 2 SKIPPED · **Phase 3 done (2026-11-10)** · awaiting explicit approval before Phase 4+  
**Created:** 2026-11-10  
**Based on:** Analysis-only audit of `c:\Users\dell\Desktop\Mailer\ayb-mailer`  
**Scope of this document:** Technical plan, phases, steps, files, tests, risks, Brevo removal inventory  

**This document does not authorize implementation.** Do not modify application code, delete data, change configuration, install packages, run migrations, send email, or touch the VPS until the owner explicitly approves a phase.

**Owner override (2026-11-10):** Do **not** implement Phase 2 (unified message logging / cross-provider event DB). Keep SendGrid, Normal+SOCKS, and KumoMTA independent. Priority: **VPS/KumoMTA monitoring, diagnostics, performance analysis, and native server logs**. Phase 3 uses dedicated `data/suppressions.sqlite` (not a unified message-events ledger).

---

## 0. Goals and non-negotiables

### Goals

1. Remove **all Brevo** functionality and unnecessary Brevo references from the application.
2. Preserve **Twilio SendGrid API** and **Normal SMTP + SOCKS4/SOCKS5** exactly as they work today.
3. Add **KumoMTA / VPS SMTP** as a **third independent** sending method.
4. Add unified message-event tracking, suppression, warm-up, and deliverability analytics.
5. Progress toward **20,000–25,000 legitimate, permission-based** marketing emails/day after measured warm-up — never by blind schedule.

### Non-negotiables

| Rule | Detail |
|------|--------|
| Preserve SendGrid | Keep `sendgrid_mail.php`, SendGrid queue lane (`api`), `.env` SendGrid keys, `data/sendgrid_analytics.sqlite`, SendGrid UI sync |
| Preserve SOCKS/Normal | Keep PHPMailer+SOCKS path, `filter_*.php`, proxy lists, `smtp` queue lane, rotation/`nrotat` |
| No false delivery | SMTP/API acceptance ≠ inbox delivery; dashboards must label statuses correctly |
| No fake engagement | No artificial opens/clicks/replies |
| Local app | Do not block mailer work on a broad security redesign; still use env credentials + TLS verify for KumoMTA |
| Data deletion | Historical Brevo DBs/exports require **explicit owner approval** before delete |
| Credentials | Never commit secrets; never log passwords/API keys |

### Verified current architecture (baseline)

| Item | Fact |
|------|------|
| App root | `ayb-mailer/` |
| Engine / UI | Monolithic `index.php` (~11.5k lines) including embedded PHPMailer/SMTP |
| Live modes | `smtp_mode=sendgrid` · `smtp_mode=normal` (+ optional `use_socks`) |
| Brevo send | Already rejected / remapped; analytics endpoints and UI remnants remain |
| Storage | Text queues + SQLite; **no MySQL** |
| PHP | Composer `>=7.4`; local CLI observed `8.2.12` |
| VPS SMTP | `smtp1.omnidigitalsuite.com` (external; not yet in app code) |

---

## 1. Target end-state architecture

```
Dashboard (existing shell)
  ├─ Setup: mode = Normal+SOCKS | SendGrid API | KumoMTA
  ├─ Sender Profiles (mail@ / news@ / notify@ …)
  ├─ Recipients (api / smtp / kumomta lanes)
  ├─ Compose + Send
  ├─ Warm-up controls
  ├─ Analytics (campaign / provider / method / historical)
  ├─ Message logs (searchable)
  └─ Suppressions / unsubscribes

Sending adapters (logical)
  ├─ SendGridMethod  → existing sendgrid_mail.php (unchanged contract)
  ├─ SocksSmtpMethod → existing PHPMailer + SOCKS path (unchanged behavior)
  └─ KumoMtaMethod   → NEW PHPMailer direct to smtp1… (no SOCKS)

Shared services (new)
  ├─ message_events store
  ├─ suppression_global store
  ├─ sender_profiles store
  └─ warmup engine
```

### Sending method matrix

| Method | Transport | SOCKS | Queue lane | Analytics source |
|--------|-----------|-------|------------|------------------|
| SendGrid | REST `POST /v3/mail/send` | Never | `api` (`validmails*.txt`) | Existing SendGrid poll + unified events |
| Normal SMTP | PHPMailer | Optional | `smtp` (`validmails_smtp*.txt`) | Unified events (accept/fail); delivery only if later events exist |
| KumoMTA | PHPMailer direct | Never (default) | **new** `kumomta` | Unified events + Kumo hooks when available |

---

## 2. Phase overview

| Phase | Name | Primary outcome | Status |
|-------|------|-----------------|--------|
| **0** | Remove Brevo | No active Brevo code/UI/endpoints; SendGrid+SOCKS intact | Done |
| **1** | KumoMTA third method | Independent mode, env config, profiles, diagnostics, queue lane | Done |
| **2** | Unified message logging | Per-message event ledger for all three methods | **SKIPPED** — do not implement |
| **3** | Bounce / suppression / unsub | Global pre-send gates + policies | Done (no Phase 2 dependency) |
| **4** | Warm-up engine | Data-driven limits, pause/hold/stop, manual increase approval | Awaiting approval |
| **5** | Analytics dashboard | Campaign / provider / method / historical views | Awaiting approval |
| **6** | Performance & scaling | Validate reliability; progressive scale toward 20–25k/day | Awaiting approval |

**Recommended execution order (updated):** 0 → 1 → ~~2~~ → 3 → 4 → 5 → 6  
Phase 2 is waived. Prefer **KumoMTA/VPS-native** monitoring and logs over a cross-provider SQLite ledger. Do not start Phase 3 until the owner explicitly approves it.

---

## PHASE 0 — Remove Brevo functionality and obsolete references

### Objective

Eliminate every Brevo send path remnant, analytics integration, UI surface, endpoint, and unnecessary textual reference — **without** damaging SendGrid analytics or Normal/SOCKS sending.

### Brevo inventory (verified)

#### A. Standalone PHP files — **safe to remove from app (code)** after backup

| File | Role |
|------|------|
| `brevo_analytics_lib.php` | SQLite schema + Brevo transactional sync helpers |
| `brevo_analytics_selftest.php` | Self-test harness |
| `brevo_credits.php` | Credit check API for Brevo keys |
| `brevo_export_callback.php` | Export callback |
| `brevo_export_lang_lists.php` | Language list export |
| `brevo_recipients.php` | Recipient analytics API |
| `brevo_recipients_export.php` | Recipient export |
| `brevo_recipients_sync.php` | Event sync from Brevo |
| `brevo_stats.php` | Aggregate Brevo stats API |
| `brevo_status.php` | Brevo status helper |
| `brevo_webhook.php` | Webhook receiver |
| `brevo_webhook_setup.php` | Webhook registration helper |

#### B. Data / databases — **require explicit owner approval before deletion**

| Path | Approx size / notes | Why approval needed |
|------|---------------------|---------------------|
| `data/brevo_analytics.sqlite` | ~84 MB historical analytics | Irreversible loss of Brevo history |
| `data/brevo_analytics.sqlite-*` | WAL/SHM if present | Companion DB files |
| `data/brevo_analytics_config.json` | Config + webhook token + last sync meta | May contain secrets/tokens |
| `data/brevo_analytics_selftest.sqlite` | Self-test DB | Low value; still confirm |
| `data/brevo_analytics_selftest_config.json` | Self-test config | Confirm |
| `data/exports/ES.txt`, `data/exports/PT.txt`, `data/exports/lang_export_report.json` | Export artifacts (likely Brevo-era) | Confirm not needed |
| `data/tmp/live_export_*.csv`, `live_sync_*.php`, `live_sync_out.txt`, `inspect_export_ready.php`, `selftest.csv`, `live_counts.php` | Temp/live sync artifacts | Confirm before delete |

**Do not delete these in Phase 0 implementation until the owner replies with an explicit delete list.**

#### C. `index.php` Brevo surfaces (code cleanup — preserve SendGrid/Recipients pages)

**Mode / POST handling**

- Remap/reject of `brevo`, `brevo_smtp`, `brevo_api` (~lines 80–88)
- Globals: `$brevo_smtp`, `$brevo_api_keys`, `$brevo_api_accounts`, `$brevo_api_sent`, `$brevo_api_cur`, `$brevo_api_credits`, `$brevo_api_exhausted`

**UI / JS (remove Brevo-specific; rename classes used for SendGrid)**

- Hidden legacy Brevo account editor (`brevo_add_*`, `brevo_api_keys`, credits cards) inside SendGrid setup box
- `brevo_smtp` textarea / `#ACCOUNT` SMTP blocks UI
- JS: `brevoBlank`, `brevoParseLocalAccounts`, `brevoAccountsToText`, `brevoSyncTextarea`, `brevoGetAccountsMerged`, `brevoSaveCreditState`, `brevoRenderCreditCards`, `brevoEsc`, `brevoToggleProblem`, `brevoRemoveAccount`, `brevoCancelEdit`, `brevoStartEdit`, `brevoAddAccount`, `brevoCheckOne`, `brevoCheckAll`, `brevoTodayYmd`, `brevoLoadStats`, `brevoMergeBlocks`, `brevoPoolGet`, `brevoPoolSet`, `brevoEnsurePool`, `brevoApplyPool`
- Analytics panels calling `brevo_stats.php`, `brevo_recipients.php`, `brevo_recipients_sync.php`, `brevo_recipients_export.php`, `brevo_credits.php`
- Country-pack persistence fields `brevo_api_keys` / `brevo_smtp`
- CSS comment `/* Brevo-style analytics dashboard */`
- CSS/JS class names `.mode-only-brevo` / `.mode-only-brevo-api` — **rename** to `.mode-only-sendgrid` (these currently gate **SendGrid** UI; do not delete the SendGrid panels)

**PHP helper functions (dead send/analytics support)**

- `brevo_api_total_room`, `parse_brevo_api_blocks`, `brevo_api_room_left`, `brevo_api_dash_snapshot`, `brevo_api_mark_exhausted`, `brevo_api_ensure_credits`, `brevo_api_pick_next`, `brevo_api_note_sent_ok`, `brevo_api_err_is_credits`, `brevo_api_fetch_credits_left`, `brevo_api_send_email`, `parse_brevo_smtp_blocks`
- Remaining `smtp_mode === 'brevo'` / `'brevo_api'` branches in send/rotation/dash snapshot code

**Keep (not Brevo product)**

- Recipients page structure for `validmails` / `validmails_smtp` queues
- SendGrid analytics page (`sendgrid_sync.php` UI)
- PHPMailer “recipients” internal property names

#### D. Docs / gitignore / README

| Path | Action |
|------|--------|
| `docs/SENDGRID_MIGRATION.md` | Rewrite title/body: remove “keep Brevo analytics” language; state Brevo fully removed |
| `docs/SENDGRID_API_SYNC.md` | Remove “older Brevo SQLite left untouched” note (or mark historical archive only if data retained offline) |
| `README.md` | Ensure no Brevo send/analytics instructions |
| `.gitignore` | After approved data deletion, remove obsolete Brevo ignore lines **or** keep them harmlessly |

### Exact implementation steps (when approved)

1. **Backup** entire `ayb-mailer/` (or at least `data/brevo_*`, `brevo_*.php`, and `index.php`).
2. Owner confirms which **data files** may be deleted vs archived offline.
3. Delete or quarantine approved data files only.
4. Delete standalone `brevo_*.php` files listed in §A.
5. Edit `index.php`:
   - Remove Brevo UI/JS/PHP helpers.
   - Rename `.mode-only-brevo-api` → `.mode-only-sendgrid` and update `syncSmtpMode()`.
   - Keep SendGrid radio + status + analytics.
   - Keep Normal+SOCKS.
   - Replace Brevo reject messages with simple ignore/unknown-mode handling (optional: map legacy `brevo_api` POST → `sendgrid` one release, then remove).
6. Update docs/README.
7. Grep project for `brevo|Brevo|xkeysib|api.brevo|smtp-relay.brevo|ba_` and resolve every remaining **active** hit (allow only historical changelog notes if desired).
8. Regression: SendGrid offline test + Normal mode UI load + SOCKS panel load.

### Existing files to modify

- `index.php` (primary)
- `docs/SENDGRID_MIGRATION.md`
- `docs/SENDGRID_API_SYNC.md`
- `README.md`
- `.gitignore` (optional cleanup after data decision)

### New files to create

- None required (optional: `docs/BREVO_REMOVAL_NOTES.md` archive of what was removed — only if requested)

### Dependencies

- None new
- Must not touch `sendgrid_*` logic except docs clarifying Brevo is gone

### Database / storage changes

- **Code** stops reading Brevo SQLite.
- **Physical delete** of `data/brevo_analytics.sqlite` (~84MB) and related files **only with owner approval**.

### Tests

| Test | Expected |
|------|----------|
| `rg -i "brevo\|xkeysib\|api\.brevo" ayb-mailer --glob '!IMPLEMENTATION_PLAN.md' --glob '!vendor/**'` | Zero active code hits (plan/docs archive may remain if explicitly kept) |
| Open Setup | Only Normal+SOCKS and SendGrid modes; no Brevo forms |
| Open Analytics | SendGrid sync UI works; no Brevo stats/recipients sync |
| `php tests/sendgrid_offline_test.php` | Pass |
| `php -l index.php` and remaining PHP | No syntax errors |
| Normal mode + SOCKS panel | Load/save proxy lists still works |
| Hitting deleted `brevo_*.php` URL | 404 (acceptable) |

### Acceptance criteria

- [ ] No Brevo endpoints callable from UI
- [ ] No Brevo API keys collected in UI
- [ ] No Brevo SQLite access from code
- [ ] SendGrid send + analytics still work
- [ ] Normal SMTP + SOCKS still work
- [ ] Owner decision recorded for each Brevo data file (deleted / archived / retained offline)

### Risks and rollback

| Risk | Mitigation |
|------|------------|
| Accidental removal of SendGrid UI gated by `.mode-only-brevo-api` | Rename class; do not delete SendGrid box |
| Losing 84MB historical Brevo analytics | Require written approval; archive zip before delete |
| Broken country-pack JS expecting `brevo_*` fields | Remove pool helpers carefully; keep country queue logic |
| Rollback | Restore from backup zip / git |

---

## PHASE 1 — Add KumoMTA as the third sending method

### Objective

Introduce an independent `smtp_mode=kumomta` (label: **VPS / KumoMTA**) that submits mail via PHPMailer **directly** to `smtp1.omnidigitalsuite.com`, with env-based credentials, TLS verification, dedicated queue lane, sender profiles, diagnostics, and response capture — **without** changing SendGrid or SOCKS engines.

### Design decisions (locked by this plan unless owner overrides)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Mode key | `kumomta` | Distinct from `normal` and `sendgrid` |
| SOCKS | Disabled for this mode | Direct VPS path; SOCKS remains Normal-only |
| Queue lane | New `kumomta` | Avoid mixing with Normal SOCKS campaign progress |
| From handling | Sender profile From/Reply-To | Do **not** force From = SMTP login (unlike Normal `switch_smtp()`) |
| TLS | `verify_peer` / `verify_peer_name` **true** for Kumo path | Use system CA / provided chain; differ from Normal’s relaxed options |
| Workers | Start with `send_workers=1`; allow later increase | Safer for first integration |
| PHPMailer | Reuse embedded classes in `index.php` initially | Avoid Composer PHPMailer churn; optional later extract |

### Environment variables (planned; placeholders only)

```
KUMOMTA_ENABLED=true
KUMOMTA_HOST=smtp1.omnidigitalsuite.com
KUMOMTA_PORT=587
KUMOMTA_ENCRYPTION=tls
KUMOMTA_USERNAME=
KUMOMTA_PASSWORD=
KUMOMTA_TIMEOUT=30
KUMOMTA_DEFAULT_FROM=mail@omnidigitalsuite.com
KUMOMTA_DEFAULT_FROM_NAME=OmniDigitalSuite
KUMOMTA_DEFAULT_REPLY_TO=mail@omnidigitalsuite.com
```

**Missing until owner provides (do not invent):** SMTP username/password, confirmation of port 587 vs 465, whether the PHP app runs on the same VPS or remote Windows.

### Sender profiles (initial set)

| Profile ID | From | Reply-To default | Notes |
|------------|------|------------------|-------|
| `mail` | `mail@omnidigitalsuite.com` | same or configurable | Primary |
| `news` | `news@omnidigitalsuite.com` | configurable | |
| `notify` | `notify@omnidigitalsuite.com` | configurable | May overlap SendGrid identity — method still independent |

Store in `data/sender_profiles.json` (or SQLite table later). Profiles bind to method `kumomta` (and optionally reusable metadata for SendGrid later). Changing From does **not** reset IP reputation; DMARC alignment still required on `omnidigitalsuite.com`.

### Exact implementation steps

1. Extend `queue_country.php` with lane `kumomta` → filenames:
   - `validmails_kumomta{,_CC}.txt`
   - `mailssenddone_kumomta{,_CC}.txt`
   - `mail_queue_progress_kumomta{,_CC}.json`
2. Create `kumomta_mail.php`:
   - `kumomta_config()` / `kumomta_config_status()` (never return password)
   - `kumomta_send_email(...)` wrapping PHPMailer or shared mailer factory
   - Capture Message-ID + last SMTP reply text
   - Log to `data/kumomta.log` without credentials
3. Create `kumomta_status.php` + `kumomta_diag.php` (single connection EHLO/AUTH/MAIL probe — **no campaign send**).
4. Create sender profile load/save helper `sender_profiles.php`.
5. Edit `index.php`:
   - Add third radio: Normal+SOCKS | SendGrid | KumoMTA
   - `syncSmtpMode()` shows Kumo panels; forces SOCKS off
   - New send branch `if ($smtp_mode === 'kumomta')` parallel to SendGrid branch (~10973), using campaign/test list rules already used for queues
   - Do not enter Normal SOCKS filter logic for this mode
6. Update `.env.example` with Kumo placeholders (not real secrets).
7. Wire Recipients UI third list editor (reuse `mail_queue_save.php` / `mail_queue_status.php` with `lane=kumomta`).
8. Document: SMTP accept ≠ delivered.

### Existing files to modify

- `index.php`
- `queue_country.php`
- `mail_queue_save.php` / `mail_queue_status.php` (accept `lane=kumomta`)
- `dash_live.php` (optional lane awareness)
- `.env.example`
- `README.md` (brief)

### New files to create

- `kumomta_mail.php`
- `kumomta_status.php`
- `kumomta_diag.php`
- `sender_profiles.php`
- `data/sender_profiles.json` (initial template, no secrets)
- `docs/KUMOMTA_INTEGRATION.md`
- `tests/kumomta_offline_test.php` (config/status only; live diag gated)

### Dependencies

- Existing embedded PHPMailer/SMTP in `index.php` (or thin require from shared bootstrap)
- OpenSSL PHP extension for TLS
- Owner-supplied SMTP credentials in local `.env`
- Network reachability to `smtp1.omnidigitalsuite.com:587` (or chosen port)

### Database / storage changes

- New text queue files for `kumomta` lane
- `data/sender_profiles.json`
- `data/kumomta.log`
- No change to `data/sendgrid_analytics.sqlite`

### Tests

| Test | Expected |
|------|----------|
| Offline config status without password | `configuration_error` clear message |
| Diag with credentials (owner-approved) | TLS + AUTH OK; no mass send |
| Test-box single message | Accepted by Kumo; Message-ID/reply captured; **not** claimed “delivered” |
| SendGrid regression | Unchanged |
| Normal+SOCKS regression | Unchanged; SOCKS still optional |
| Lane isolation | Kumo done-file does not mark SendGrid/Normal done files |

### Acceptance criteria

- [ ] Three modes coexist in UI
- [ ] Kumo sends without SOCKS
- [ ] TLS verification enabled on Kumo path
- [ ] Multiple From profiles selectable
- [ ] Dedicated queue lane works with pause/resume via done-file pattern
- [ ] Failures do not mark done; stop flag respected
- [ ] SendGrid + SOCKS untouched in behavior

### Risks and rollback

| Risk | Mitigation |
|------|------------|
| From=login logic leaking from Normal path | Isolated branch; never call `switch_smtp()` for Kumo |
| TLS failures on Windows CA store | Document CA install; optional pinned CA path later |
| Credential exposure in UI | Env-only password; status returns preview only |
| Rollback | Feature-flag `KUMOMTA_ENABLED=false`; remove radio; delete new files |

---

## PHASE 2 — Unified message logging and event tracking

> **SKIPPED (owner decision 2026-11-10).** Do not create `message_events.sqlite`, do not instrument SendGrid/Normal/Kumo into a shared ledger, and do not change existing queues/analytics/sending logic for “unified logging.”  
> Preserve: SendGrid untouched · Normal SMTP/SOCKS untouched · KumoMTA independent.  
> Near-term focus instead: VPS/KumoMTA monitoring, diagnostics, performance analysis, and **native server logs**.

### Objective *(historical — not to be implemented)*

Create one searchable message-event system covering SendGrid, Normal+SOCKS, and KumoMTA, clearly separating **accepted** from **delivered** and recording retries, SMTP codes, Message-IDs, and (when available) Kumo queue IDs.

### Event model (SQLite proposed: `data/message_events.sqlite`)

**Table `messages` (one row per logical send attempt chain)**

| Column | Purpose |
|--------|---------|
| `internal_id` | UUID / ULID |
| `campaign_id` | Campaign folder name or generated id |
| `method` | `sendgrid` \| `normal_smtp` \| `kumomta` |
| `sender_profile_id` | Profile key |
| `from_email` / `reply_to` | Envelope/header identity |
| `recipient` | Email |
| `provider` | Derived: gmail / google_workspace / outlook / hotmail / microsoft365 / yahoo / other |
| `message_id` | RFC Message-ID |
| `provider_msg_id` | SendGrid X-Message-Id or Kumo queue id |
| `status` | `queued` \| `accepted` \| `deferred` \| `rejected` \| `delivered` \| `hard_bounce` \| `soft_bounce` \| `complaint` \| `unsubscribed` \| `failed` \| `unknown` |
| `smtp_code` / `smtp_enhanced` / `smtp_response` | When applicable |
| `attempt_count` | Retries |
| `queued_at` / `accepted_at` / `delivered_at` / `last_event_at` | Timestamps |
| `socks_proxy` | Only for Normal+SOCKS |
| `raw_last` | Truncated last response (no secrets) |

**Table `message_event_log`**

Append-only: `internal_id`, `event_type`, `at`, `payload_json`.

**Indexes:** recipient, campaign_id, method, status, provider, message_id, provider_msg_id, accepted_at.

### Ingest sources

| Method | Acceptance signal | Delivery / bounce signal |
|--------|-------------------|--------------------------|
| SendGrid | HTTP 202 + X-Message-Id (already) | Existing poll (`/v3/messages`, suppressions) → map into unified store; optional later webhook |
| Normal+SOCKS | `$mail->Send()` success + reply | Usually **unknown** unless bounce mailbox/webhook added later |
| KumoMTA | SMTP 250 + Message-ID / queue id | KumoMTA log hook / webhook / typed log ingest (preferred) |

**Rule:** UI labels must say **Accepted** vs **Delivered (confirmed)**. Never promote acceptance to delivered without an event.

### Exact implementation steps

1. Create `message_events_lib.php` (PDO SQLite + migrate).
2. Instrument:
   - `sendgrid_api_send_email` / SendGrid loop → record accepted/failed
   - `mailer_socks_send_attempt` success/fail → record
   - `kumomta_send_email` → record
3. Add `message_events_api.php` for search (campaign, message id, recipient, method, provider, sender, date, status, smtp code).
4. Add Kumo event ingest endpoint `kumo_events_webhook.php` (token auth) — implement when VPS hook format is known.
5. Map SendGrid sync rows into unified statuses where msg ids match.
6. Retention policy config (e.g. 90 days events) — implement purge job later in Phase 6.

### Existing files to modify

- `index.php` (send loops — thin record calls only)
- `sendgrid_mail.php` or post-send hook site in `sendgrid_api_send_email`
- `sendgrid_analytics_lib.php` (optional bridge upsert)
- `kumomta_mail.php`

### New files to create

- `message_events_lib.php`
- `message_events_api.php`
- `kumo_events_webhook.php` (stub until hook format confirmed)
- `docs/MESSAGE_EVENTS.md`
- `data/message_events.sqlite` (created at runtime)

### Dependencies

- Phase 1 complete for Kumo fields
- Owner decision on Kumo hook format (JSON HTTP vs log tail)
- PDO SQLite

### Tests

- Unit: provider detection from domains
- Offline: insert/search events
- SendGrid test send creates `accepted` not `delivered`
- Sync can upgrade to delivered when API says so
- No credentials in `message_event_log`

### Acceptance criteria

- [ ] Every send attempt from all three methods creates an internal id
- [ ] Search API returns filtered rows
- [ ] Status vocabulary distinguishes accepted vs delivered
- [ ] SendGrid analytics DB remains functional

### Risks and rollback

- Write amplification under parallel SOCKS workers → use append + batched upserts; file lock discipline  
- Rollback: stop calling record helpers; drop/ignore new DB

---

## PHASE 3 — Bounce, unsubscribe, and suppression management

### Objective

Prevent sending to addresses that must not be mailed; support hard/soft bounce policy, complaints, unsubscribes, duplicates, and one-click unsubscribe headers for marketing sends.

### Design

**Table `suppression_global`:** email, reason (`hard_bounce|complaint|unsubscribe|manual|invalid`), source_method, detail, created_at, unique(email).

**Policies**

| Case | Policy |
|------|--------|
| Hard bounce | Immediate global suppress |
| Soft bounce | Retry with backoff; suppress after N soft bounces in window (configurable, e.g. 3/14d) |
| Complaint | Immediate global suppress |
| Unsubscribe | Immediate global suppress + honor List-Unsubscribe |
| Duplicate in queue | Already partially handled by normalize; enforce at pre-send |
| SendGrid suppressions | Import into global or method-scoped list; **pre-send check** |

**Pre-send:** all methods call `suppression_is_blocked($email)` before submit.

**One-click unsub:** enable `List-Unsubscribe` + `List-Unsubscribe-Post` for Kumo/marketing HTML; endpoint `unsubscribe.php` (signed token). Normal/SendGrid: SendGrid ASM where applicable; Kumo uses local endpoint.

### Exact implementation steps

1. `suppression_lib.php` + SQLite tables in **`data/suppressions.sqlite`** (not message_events — Phase 2 skipped).
2. Wire pre-send checks in all three send paths.
3. Import SendGrid suppressions during sync into local global list (additive).
4. ~~Ingest bounce/complaint events from Phase 2~~ — **waived**; soft/hard bounce inference from Kumo/Normal SMTP failures + manual/API add instead.
5. UI: suppression list search/add/remove (careful remove audit).
6. Campaign exclusion list file optional: `exclude_{lane}_{cc}.txt`.

### Files to modify

- Send loops in `index.php`
- `sendgrid_analytics_lib.php` (import bridge)
- `kumomta_mail.php` (headers)
- Compose UI for unsub footer note

### New files

- `suppression_lib.php`
- `suppression_api.php`
- `unsubscribe.php`
- `docs/SUPPRESSION.md`

### Tests

- Suppressed address skipped and logged as `rejected`/`excluded`
- Unsub token invalid → no change
- Soft-bounce threshold suppresses
- SendGrid + SOCKS still send to non-suppressed addresses

### Acceptance criteria

- [x] No send to globally suppressed emails
- [x] Hard bounce / complaint / unsub enforced
- [x] One-click path works for Kumo marketing messages
- [x] Audit log of suppress additions

### Risks / rollback

- Over-aggressive soft-bounce suppress → make thresholds configurable  
- Rollback: disable pre-send check via flag

---

## PHASE 4 — Warm-up engine and sending controls

### Objective

App-controlled warm-up for **KumoMTA** (primary) with optional per-method caps: daily/hourly/provider limits, manual approval for increases, pause/resume/hold/emergency stop, automatic cooldown on bad metrics — **no blind volume schedule**, **no fake engagement**.

### Design

**Tables:** `warmup_plans`, `warmup_days`, `warmup_decisions`

**Plan fields:** method (`kumomta`), start_date, current_daily_cap, hourly_cap, provider_caps JSON, state (`active|paused|hold|cooldown|stopped`), require_manual_increase=true.

**Governor (checked before each send / batch):**

1. Emergency stop flag → halt  
2. Plan state pause/hold/stopped → halt  
3. Daily/hourly/provider remaining → allow or wait  
4. Metric gates (from Phase 2–3): hard-bounce rate, complaint rate, deferral rate, reject rate  
5. If gates fail → auto **cooldown** or **reduce** + write decision reason  
6. Increases → create `pending_increase` requiring dashboard **Approve**

**Long-term target:** 20–25k/day after sustained healthy metrics — not a Phase 4 day-one cap.

### Exact implementation steps

1. `warmup_lib.php` + APIs `warmup_api.php`
2. Integrate governor into Kumo send loop (and optionally SendGrid/Normal read-only caps later)
3. Dashboard page: current caps, remaining, decisions log, approve increase, emergency stop (reuse `stop_send.php` pattern)
4. Charts data endpoints for Phase 5

### Files to modify

- `index.php` (Kumo loop + nav)
- `stop_send.php` (optional global emergency)

### New files

- `warmup_lib.php`, `warmup_api.php`, `docs/WARMUP.md`

### Tests

- Cap blocks send after N messages
- Failed metrics trigger cooldown with stored reason
- Increase without approval does not raise cap
- Emergency stop halts mid-run

### Acceptance criteria

- [ ] Warm-up controllable from dashboard
- [ ] Auto actions always store human-readable reasons
- [ ] Volume never auto-increases while metrics deteriorate
- [ ] Existing methods remain usable with warm-up disabled

### Risks / rollback

- False cooldown from sparse data → minimum sample size before gating  
- Rollback: `warmup_enabled=false`

---

## PHASE 5 — Analytics and dashboard integration

### Objective

Extend the existing dashboard (Setup / Recipients / Compose / Analytics) without a visual redesign. Add sections for methods, Kumo status, profiles, warm-up, campaign/provider/method analytics, searchable logs, suppressions, and MTA health.

### Planned Analytics page sections

1. Sending methods status (SendGrid / Normal+SOCKS / KumoMTA)  
2. KumoMTA connection status + last diag  
3. Sender profiles  
4. Warm-up management  
5. Campaign analytics (queued, attempted, accepted, delivered, deferred, rejected, bounces, complaints, unsubs, opens/clicks when available, unknown, duration, msg/min)  
6. Provider analytics (Gmail, Google Workspace, Outlook, Hotmail, M365, Yahoo, Other)  
7. Method comparison (only comparable metrics; label acceptance vs delivery)  
8. Searchable message logs  
9. Bounce / suppression management  
10. VPS/MTA performance (from Kumo metrics if exposed; otherwise local throughput)

**Time ranges:** hourly / daily / weekly / monthly from `message_events` (+ SendGrid daily stats where applicable).

### Exact implementation steps

1. Add nav entries / `nav-page` sections in `index.php` (or split includes if file size becomes unmanageable — optional refactor, not required).
2. Wire fetch APIs from Phases 2–4.
3. Keep SendGrid sync card operational.
4. Explicit UI copy: “Accepted ≠ Delivered”.

### Files to modify

- `index.php` (UI)
- Possibly small CSS blocks in same file

### New files

- Optional `partials/` includes if splitting UI (only if needed)
- Chart helpers as vanilla JS (no new framework required)

### Tests

- Each section loads empty state without errors
- Seeded SQLite fixtures render correct rates
- SendGrid sync still works

### Acceptance criteria

- [ ] All listed sections reachable
- [ ] Status vocabulary consistent
- [ ] Method comparison does not equate SOCKS “OK” with SendGrid “delivered”

### Risks / rollback

- `index.php` size/merge conflict → isolate new HTML in includes  
- Rollback: hide new nav pages via flag

---

## PHASE 6 — Performance testing and progressive scaling

### Objective

Validate reliability of the three-method stack and increase Kumo volume only when metrics and warm-up approvals support it, toward 20–25k legitimate emails/day.

### Steps

1. Define baseline caps (e.g. start far below target).  
2. Run controlled campaigns to permissioned lists.  
3. Monitor accept/defer/bounce/complaint/provider splits.  
4. Optional: inbox placement tests via external seed service (not built as fake engagement).  
5. Tune PHP workers, Kumo concurrency, SQLite retention, log rotation.  
6. Document VPS capacity findings (CPU/RAM/disk/network) — **requires owner SSH metrics**; not assumed.

### Tests / acceptance

- Sustained send day at approved cap without data corruption  
- Emergency stop works under load  
- Event DB growth within retention policy  
- Regression: SendGrid + SOCKS still healthy  

### Risks

- IP/domain reputation damage from premature scale → hard governor  
- SQLite lock contention → batch writes / WAL already used elsewhere  

---

## 3. Cross-cutting: files summary

### Must not break

| Path | Why |
|------|-----|
| `sendgrid_mail.php` | Core SendGrid send |
| `sendgrid_analytics_lib.php` / `sendgrid_sync.php` / `sendgrid_status.php` | SendGrid analytics |
| `data/sendgrid_analytics.sqlite` | SendGrid local cache |
| `filter_socks.php` / `filter_worker.php` / `filter_status.php` / `proxy_lists.php` / `refresh_ok.php` | SOCKS pipeline |
| Embedded PHPMailer/SMTP + `mailer_socks_send_attempt()` | Normal+SOCKS engine |
| `validmails*.txt` / `mailssenddone*.txt` lane conventions | Queue resume behavior |

### Phase 0 removable code files

All root `brevo_*.php` listed in Phase 0 §A.

### Phase 0 data — approval gate

All `data/brevo_*` and likely Brevo-era `data/exports/*`, `data/tmp/live_*` listed in Phase 0 §B.

---

## 4. Testing strategy (global)

### Per-phase gates

1. Syntax: `php -l` on touched PHP  
2. Offline unit/selftests  
3. Regression: SendGrid offline test; SOCKS list load; mode switching UI  
4. Live tests **only** with owner approval and tiny recipient sets  

### Final regression checklist

- [ ] SendGrid campaign + test send  
- [ ] Normal SMTP without SOCKS (if network allows)  
- [ ] Normal SMTP with SOCKS  
- [ ] KumoMTA test send  
- [ ] Stop send / pending resume  
- [ ] Queue lane isolation  
- [ ] Suppression pre-check  
- [ ] Warm-up cap enforcement  
- [ ] Analytics empty + populated states  
- [ ] Grep clean of active Brevo references  

---

## 5. Unresolved decisions / missing information

| # | Item | Blocks |
|---|------|--------|
| 1 | Owner approval list for deleting vs archiving Brevo SQLite/exports/tmp | Phase 0 data deletion |
| 2 | KumoMTA SMTP username/password (and whether auth is PLAIN/LOGIN) | Phase 1 live send |
| 3 | Confirm submission port **587 STARTTLS** vs **465 SMTPS** | Phase 1 config defaults |
| 4 | App runtime location (local XAMPP Windows vs same VPS) | Firewall/TLS/diag |
| 5 | KumoMTA event/hook format for delivery/bounce | Phase 2 complete delivery correlation |
| 6 | Public base URL for one-click unsubscribe (even if LAN/VPN) | Phase 3 |
| 7 | Initial warm-up starting daily cap and provider caps | Phase 4 |
| 8 | Whether legacy POST `brevo_api` should keep mapping to SendGrid for one release | Phase 0 cleanup strictness |
| 9 | VPS CPU/RAM/disk quotas | Phase 6 capacity claims |

---

## 6. Explicit non-actions (until phase approval)

Until the owner approves a specific phase, the implementer must **not**:

- Modify application code  
- Delete files or databases  
- Change `.env` / VPS / KumoMTA  
- Install Composer packages  
- Run migrations  
- Send emails  

**Exception for this task only:** creation of this file `IMPLEMENTATION_PLAN.md`.

---

## 7. Approval protocol

1. Owner reviews this plan.  
2. Owner resolves Phase 0 data-deletion list.  
3. Owner says e.g. **“Approve Phase 0”** (then later Phase 1, etc.).  
4. Implementer executes only the approved phase, then stops for re-review.

---

## 8. Expected results after full roadmap

- Zero active Brevo functionality or UI.  
- Three independent sending methods: SendGrid, Normal+SOCKS, KumoMTA.  
- ~~Unified message events~~ — **waived**; use provider-native / VPS logs for KumoMTA visibility.  
- Global suppression + unsub protections (Phase 3+, if approved).  
- Dashboard warm-up with manual increase approval and metric-based cooldown.  
- Analytics covering campaign, provider, and method views (without requiring Phase 2 ledger).  
- Measured path toward 20–25k legitimate emails/day — not a guaranteed timeline.

---

*End of implementation plan.*
