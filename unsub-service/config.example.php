<?php
/**
 * Copy to config.php on Hostinger (outside web root if possible).
 * Never commit real secrets.
 */
return array(
	'db_dsn' => 'mysql:host=localhost;dbname=YOUR_DB;charset=utf8mb4',
	'db_user' => 'YOUR_DB_USER',
	'db_pass' => 'YOUR_DB_PASSWORD',
	// Public site origin (no trailing slash), e.g. https://mail.omnidigitalsuite.com
	'public_base_url' => 'https://mail.omnidigitalsuite.com',
	// Mailer client key (Bearer) — issue/check/pull/add only
	'api_key' => 'CHANGE_ME_MAILER_API_KEY_64_HEX',
	// Admin key — DELETE / uns suppress only (separate from api_key)
	'admin_api_key' => 'CHANGE_ME_ADMIN_API_KEY_64_HEX',
	'token_ttl_seconds' => 63072000, // 730 days
	'mailto_unsub' => 'unsub@omnidigitalsuite.com',
);
