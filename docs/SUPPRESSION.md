# Global suppressions and centralized unsubscribe

## Architecture

- **System of record:** Hostinger Unsubscribe Service (`unsub-service/`) on MariaDB  
  Public: `https://mail.omnidigitalsuite.com/u/{token}`  
  API: `https://mail.omnidigitalsuite.com/api/v1/*`
- **Desktop cache:** `data/suppressions.sqlite` (pulled from central)
- **Providers:** SendGrid, KumoMTA, Normal+SOCKS all use `suppression_is_blocked()` before send

When `UNSUB_SERVICE_ENABLED=true`, marketing sends:

1. Fail-closed if the local cache is stale and central sync is unavailable  
2. Issue an **opaque** HTTPS unsubscribe URL (no email in the link)  
3. Add HTML/text footer + `List-Unsubscribe` / `List-Unsubscribe-Post` (RFC 8058)

## Env

```
UNSUB_SERVICE_ENABLED=false
UNSUB_SERVICE_BASE_URL=https://mail.omnidigitalsuite.com
UNSUB_API_KEY=
UNSUB_ADMIN_API_KEY=
UNSUB_REQUIRE_FRESH=true
UNSUB_CACHE_MAX_AGE_SECONDS=300
UNSUB_TOKEN_TTL_SECONDS=63072000
UNSUBSCRIBE_MAILTO=unsub@omnidigitalsuite.com
SUPPRESSION_ENABLED=true
```

Keep `UNSUB_SERVICE_ENABLED=false` until the Hostinger service is deployed and `/api/v1/health` returns ok.

## Soft bounce policy

```
SUPPRESSION_SOFT_BOUNCE_MAX=3
SUPPRESSION_SOFT_BOUNCE_DAYS=14
```

## Removal

Desktop UI remove requires the **admin** API key on the central service (`UNSUB_ADMIN_API_KEY`) plus email confirmation. The mailer API key cannot delete suppressions.

## Deploy Hostinger

See `unsub-service/README.md` and `unsub-service/sql/schema.sql`.

## Offline test

```bash
php tests/suppression_offline_test.php
```

## Feature flags

```
SUPPRESSION_ENABLED=false          # opens pre-send gate
UNSUB_SERVICE_ENABLED=false        # local/legacy mode only
```
