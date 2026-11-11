# AYB Unsubscribe Service (Hostinger)

Central public unsubscribe + global suppression API for **ayb-mailer**.

- Public: `GET/POST /u/{token}` (RFC 8058 one-click on POST)
- Internal: `/api/v1/*` (Bearer API key)
- Admin delete: `DELETE /api/v1/suppressions` (admin key + email confirm)
- Database: **MariaDB/MySQL** (not SQLite)

## Deploy on Hostinger

**Locked Document Root (this project):** `.../public_html/mail`  
Use root `index.php` + root `.htaccess` so URLs work **without** `/public/` in the path.

1. Create a MySQL/MariaDB database in hPanel; import `sql/schema.sql`.
2. Extract the migration ZIP **into** `public_html/mail` (flat — you must see `mail/index.php`, not `mail/mail/...`).
3. Keep/merge your real `config.php` credentials (`db_*`, `api_key`, `admin_api_key`, `public_base_url`). Do not overwrite a filled live config with the placeholder.
4. Confirm structure: `mail/{index.php,.htaccess,config.php,src/,public/}`.
5. Verify:
   - `https://mail.omnidigitalsuite.com/` → `ayb-unsub`
   - `https://mail.omnidigitalsuite.com/api/v1/health`
   - `https://mail.omnidigitalsuite.com/u/invalidtoken`
   - `https://mail.omnidigitalsuite.com/config.php` → **403**
6. See `MIGRATE_HOSTINGER.txt` for Hostinger-side move order if files are still nested under `mail/public/`.

## Security

- Mailer key cannot delete suppressions.
- Admin delete requires `Authorization: Bearer <admin_key>` and `X-Unsub-Confirm: email@domain`.
- Tokens are opaque; only SHA-256 hashes are stored.
- Default token TTL: 730 days.

## Desktop client

See ayb-mailer `unsub_client.php` and `.env`:

```
UNSUB_SERVICE_ENABLED=true
UNSUB_SERVICE_BASE_URL=https://mail.omnidigitalsuite.com
UNSUB_API_KEY=...
UNSUB_ADMIN_API_KEY=...   # Desktop UI remove only
```
