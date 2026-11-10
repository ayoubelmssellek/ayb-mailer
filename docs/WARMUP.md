# Warm-up engine (Phase 4 + 20/hr controller)

App-controlled warm-up for **KumoMTA** (primary). No blind volume schedule and no fake engagement. Cap increases that raise the daily limit require **manual approval**.

## 20 emails/hour profile

| Control | Default |
|---------|---------|
| State after boot | **paused** (must Resume on Dashboard) |
| Hourly limit | **20 attempts / rolling 60 minutes** (server-side) |
| Pacing | **180 seconds** between attempts (~1 / 3 min) |
| Daily limit | Independent (`WARMUP_DEFAULT_DAILY_CAP`, Dashboard) |
| Limit increases | Manual approval required |

Pause / Hold / Stop / Emergency stop **halt** further campaign dispatch. Pacing waits **sleep then retry** inside the Kumo send loop (does not require JS).

## Storage

`data/warmup.sqlite` — plans, daily/hourly counters, provider counters, decisions, pending increases, **`warmup_send_events`** (attempt ledger for rolling window + multi-worker lock).

## Feature flag

```
WARMUP_ENABLED=true
WARMUP_DEFAULT_DAILY_CAP=50
WARMUP_DEFAULT_HOURLY_CAP=20
WARMUP_MIN_INTERVAL_SECONDS=180
WARMUP_METRIC_MIN_SAMPLE=20
WARMUP_MAX_HARD_BOUNCE_RATE=0.05
WARMUP_MAX_COMPLAINT_RATE=0.002
WARMUP_MAX_FAIL_RATE=0.25
```

Set `WARMUP_ENABLED=false` to disable the governor (SendGrid / Normal / Kumo still work).

## Governor (campaign Kumo sends)

Before each campaign send (`BEGIN IMMEDIATE` lock):

1. Emergency `stop_send.flag` → halt  
2. Plan state `paused|hold|cooldown|stopped` → halt  
3. Metric gates (after min sample) → auto **cooldown** with stored reason  
4. Daily accepted cap → halt when exhausted  
5. Rolling 60-minute **attempt** cap → halt when exhausted  
6. Min interval pacing → wait / retry (not a hard stop)  
7. Optional per-provider caps → skip that recipient (campaign continues)  
8. Insert `attempt` into `warmup_send_events` (persists across restarts)

**Test list sends skip the governor** so connectivity checks do not burn warm-up quota.

Accepted SMTP submissions count toward daily accepted (accept ≠ inbox delivery). Rolling hourly uses **attempts**, not only accepts.

## States

| State | Meaning |
|-------|---------|
| active | Sending allowed under caps |
| paused | Operator pause (default boot) |
| hold | Temporary hold |
| cooldown | Auto after bad metrics |
| stopped | Emergency / hard stop |

## Increases

If `require_manual_increase=1` (default), raising `current_daily_cap` creates a **pending** request. Caps do not rise until **Approve**. Approve is refused while metrics are failing or state is `cooldown`/`stopped`. Decreases apply immediately.

## Dashboard

Warm-up page shows: daily/hourly remaining, attempts/accepted/bounced/failed, target rate, `next_send_at`, queue note, Start(Resume)/Pause/Hold/Stop/Emergency.

## API

`warmup_api.php` — `status`, `series`, `set_state`, `update`, `request_increase`, `approve_increase`, `reject_increase`, `emergency_stop`, `clear_emergency`.

## VPS readiness

DNS/TCP can be checked from the app host. Full Kumo container health, DKIM private keys, and queue depth require **working SSH**. If SSH is denied, status reports `vps_readiness.ssh_verified=false`.

## Offline test

```bash
php tests/warmup_offline_test.php
```
