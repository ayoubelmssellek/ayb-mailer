# Phase 6 — Performance & scaling

Status: documentation + controlled-test guidance. **No uncontrolled production campaigns. No mass real sends without owner approval.**

## Scope (approved)

Included:

- Performance profiling / bottleneck identification (guidance)
- Worker concurrency and processing notes
- KumoMTA integration performance notes
- Queue processing improvements (application-level, non-destructive)
- Memory / CPU efficiency guidance
- Controlled load-test plan toward 20,000–25,000 emails/day
- Monitoring delivery success, failures, throughput under load
- VPS capacity planning template
- Optional external inbox-placement testing notes

**Explicitly excluded**

- SQLite schema/index/concurrency/journal/locking changes
- New database technology
- Phase 2 unified `message_events` database
- Invented VPS capacity numbers when SSH metrics are unavailable

## Architecture bottlenecks (observed / expected)

| Area | Observation | Risk under scale |
|------|-------------|------------------|
| Monolithic `index.php` send loop | Sequential PHP campaign loop with pacing | CPU bound on long runs; request timeout risk if run via web SAPI |
| Queue files (`validmails*.txt` / `mailssenddone*.txt`) | Append + full-file scan for resume | Linear scan cost grows with list size; keep lanes isolated |
| SendGrid REST | Per-message HTTP to Twilio API | Provider rate limits; network latency dominates |
| Normal SMTP + SOCKS | Proxy rotate + SMTP handshake | Highest latency/variance; SOCKS quality dominates |
| KumoMTA | Direct PHPMailer to VPS SMTP | Best path for volume if VPS/Kumo tuned; still gated by warm-up |
| Warm-up governor | Daily/hourly caps + emergency stop | Correct throttle — do not bypass for “performance” |
| Analytics SQLite | Poll sync, not webhook | Sync cost separate from send path; do not add indexes in Phase 6 |

## Simulated vs real delivery

| Mode | What it measures | Allowed without extra approval |
|------|------------------|--------------------------------|
| Offline / unit tests | Code correctness | Yes |
| Dry stats / queue math | Pending/Sent OK counts | Yes |
| Tiny live test sends (existing test-list path) | Transport smoke | Only if owner already uses that path; keep tiny |
| Controlled campaign at approved warm-up cap | Real delivery | **Owner must approve volume** |
| 20–25k real emails/day | Production scale | **Explicit owner approval required** |

Never treat SMTP/API **accept** as inbox **delivered**.

## Controlled load-test plan (toward 20–25k/day)

1. Confirm warm-up state is `active` (or equivalent) and daily/hourly remaining allow the trial.
2. Start far below target (example ladder: 500 → 1k → 2.5k → 5k → 10k → 15k → 20–25k) across separate days.
3. Prefer **KumoMTA** for volume trials; keep SendGrid and SOCKS regression checks small.
4. For each step record:
   - Accepted / failed / deferred (method-native)
   - Stop-send responsiveness
   - PHP process RSS / CPU if measurable locally
   - Provider splits (Kumo) when available
   - Complaint / bounce signals from suppressions import
5. Do **not** auto-increase warm-up caps.
6. Abort on emergency stop, elevated complaints, or unexplained failure spikes.

### Example metrics worksheet (fill with real numbers)

| Date | Method | Attempted | Accepted | Failed | Duration | Notes |
|------|--------|-----------|----------|--------|----------|-------|
| | kumomta | | | | | simulated / real |
| | sendgrid | | | | | |
| | normal | | | | | |

## Worker / concurrency guidance

- Keep one campaign runner per lane unless owner designs multi-worker locking.
- Do not parallelize appends to `mailssenddone*.txt` without an explicit locking design (out of scope here).
- Prefer pacing already in compose settings over spawning many PHP workers.
- For Kumo: tune **server-side** concurrency/injection rates on the VPS — app-side flood will only move the bottleneck.

## Queue processing improvements (safe)

Already aligned with current design:

- Resume skips `mailssenddone` without rewriting `validmails`
- Lane isolation (`api` / `smtp` / `kumomta`) prevents cross-method corruption
- Test sends must not write done files

Avoided in this phase:

- SQLite WAL/index “optimizations”
- Rewriting queue file formats

## Memory / CPU efficiency

- Stream/line-scan large lists where APIs already do (`mail_queue_status`, SendGridMail API).
- Avoid loading entire analytics DBs into UI; use pagination (SendGrid messages, SendGridMail, suppressions).
- Rotate `data/*.log` manually if files grow large (no automated destructive purge without approval).

## VPS capacity planning

**SSH metrics / VPS quotas were not available in this workspace.** Do not invent CPU/RAM/disk figures.

Collect on the VPS (owner) before claiming capacity:

```text
uptime
free -h
df -h
nproc
# during a controlled send window:
top / htop
# Kumo-native logs / queue depth (paths depend on Kumo install)
```

Capacity checklist:

- [ ] vCPU count
- [ ] RAM + swap
- [ ] Disk free on spool / logs
- [ ] NIC / bandwidth headroom
- [ ] KumoMTA version + configured injection concurrency
- [ ] TLS cert / SMTP auth health (`kumomta_diag.php`)

Until those are filled, treat 20–25k/day as a **target**, not a validated capacity.

## Monitoring under load

| Signal | Where |
|--------|-------|
| Campaign accept/fail | Live dash / method logs |
| SendGrid delivered/bounces | Analytics sync (`sendgrid_analytics.sqlite`) |
| Kumo accepts / provider split | Operations overview + `data/kumomta.log` |
| Suppressions growth | Global suppressions card |
| Warm-up remaining | Warm-up page |
| Emergency stop | Warm-up / stop_send |

## Optional inbox-placement testing

Use an **external seed/inbox-placement service** if desired. Do not fake opens/clicks inside this app. Placement tests are optional and separate from accept counts.

## Regression gates before any scale step

- [ ] SendGrid offline tests pass
- [ ] Kumo offline tests pass
- [ ] Warm-up offline tests pass
- [ ] Analytics offline tests pass
- [ ] SendGridMail Portugal offline tests pass
- [ ] Mode switching UI still isolates lanes
- [ ] No Brevo reintroduction

## Non-actions reminder

- No SQLite optimization work in Phase 6
- No Phase 2 unified events DB
- No Brevo restoration
- No 20–25k real send without explicit owner approval
