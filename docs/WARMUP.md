# Warm-up engine (Phase 4)

App-controlled warm-up for **KumoMTA** (primary). No blind volume schedule and no fake engagement. Cap increases that raise the daily limit require **manual approval**.

## Storage

`data/warmup.sqlite` — plans, daily/hourly counters, provider counters, decisions, pending increases.

## Feature flag

```
WARMUP_ENABLED=true
WARMUP_DEFAULT_DAILY_CAP=50
WARMUP_DEFAULT_HOURLY_CAP=20
WARMUP_METRIC_MIN_SAMPLE=20
WARMUP_MAX_HARD_BOUNCE_RATE=0.05
WARMUP_MAX_COMPLAINT_RATE=0.002
WARMUP_MAX_FAIL_RATE=0.25
```

Set `WARMUP_ENABLED=false` to disable the governor (SendGrid / Normal / Kumo still work).

## Governor (campaign Kumo sends)

Before each campaign send:

1. Emergency `stop_send.flag` → halt  
2. Plan state `paused|hold|cooldown|stopped` → halt  
3. Metric gates (after min sample) → auto **cooldown** with stored reason  
4. Daily / hourly accepted caps → halt when exhausted  
5. Optional per-provider caps → skip that recipient (campaign continues)

**Test list sends skip the governor** so connectivity checks do not burn warm-up quota.

Accepted SMTP submissions count toward caps (accept ≠ inbox delivery).

## States

| State | Meaning |
|-------|---------|
| active | Sending allowed under caps |
| paused | Operator pause |
| hold | Temporary hold |
| cooldown | Auto after bad metrics |
| stopped | Emergency / hard stop |

## Increases

If `require_manual_increase=1` (default), raising `current_daily_cap` creates a **pending** request. Caps do not rise until **Approve**. Approve is refused while metrics are failing or state is `cooldown`/`stopped`. Decreases apply immediately.

## API

`warmup_api.php` — `status`, `series`, `set_state`, `update`, `request_increase`, `approve_increase`, `reject_increase`, `emergency_stop`, `clear_emergency`.

## Offline test

```bash
php tests/warmup_offline_test.php
```
