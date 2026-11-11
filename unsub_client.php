<?php
/**
 * HTTPS client for Hostinger AYB Unsubscribe + Suppression service.
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';

function unsub_client_config()
{
	ayb_env_load();
	return array(
		'enabled' => ayb_env_bool('UNSUB_SERVICE_ENABLED', false),
		'base_url' => rtrim(ayb_env('UNSUB_SERVICE_BASE_URL', ''), '/'),
		'api_key' => ayb_env('UNSUB_API_KEY', ''),
		'admin_api_key' => ayb_env('UNSUB_ADMIN_API_KEY', ''),
		'require_fresh' => ayb_env_bool('UNSUB_REQUIRE_FRESH', true),
		'cache_max_age' => max(60, intval(ayb_env('UNSUB_CACHE_MAX_AGE_SECONDS', '300'))),
		'timeout' => max(2, intval(ayb_env('UNSUB_HTTP_TIMEOUT_SECONDS', '8'))),
		'check_timeout' => max(1, intval(ayb_env('UNSUB_CHECK_TIMEOUT_SECONDS', '2'))),
		'consumer_id' => ayb_env('UNSUB_CONSUMER_ID', 'ayb-mailer-desktop'),
		'token_ttl' => max(7776000, intval(ayb_env('UNSUB_TOKEN_TTL_SECONDS', '63072000')))
	);
}

function unsub_client_meta_path()
{
	return __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'unsub_sync_meta.json';
}

function unsub_client_meta_get()
{
	$path = unsub_client_meta_path();
	$defaults = array(
		'last_pull_at' => 0,
		'last_pull_ok_at' => 0,
		'since_id' => 0,
		'last_error' => ''
	);
	if (!is_file($path)) return $defaults;
	$j = json_decode((string)@file_get_contents($path), true);
	if (!is_array($j)) return $defaults;
	return array_merge($defaults, $j);
}

function unsub_client_meta_set(array $patch)
{
	$cur = unsub_client_meta_get();
	$next = array_merge($cur, $patch);
	$dir = dirname(unsub_client_meta_path());
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
	@file_put_contents(unsub_client_meta_path(), json_encode($next, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function unsub_client_cache_age_seconds()
{
	$m = unsub_client_meta_get();
	$t = intval($m['last_pull_ok_at']);
	if ($t < 1) return PHP_INT_MAX;
	return max(0, time() - $t);
}

function unsub_client_ready()
{
	$cfg = unsub_client_config();
	return !empty($cfg['enabled']) && $cfg['base_url'] !== '' && $cfg['api_key'] !== '' && preg_match('#^https://#i', $cfg['base_url']);
}

/**
 * @return array{ok:bool,status:int,body:?array,err:string}
 */
function unsub_client_request($method, $path, $jsonBody = null, $timeout = null, $admin = false)
{
	$cfg = unsub_client_config();
	$out = array('ok' => false, 'status' => 0, 'body' => null, 'err' => '');
	if (!unsub_client_ready() && !$admin) {
		// admin remove may still need enabled+base
	}
	if ($cfg['base_url'] === '' || !preg_match('#^https://#i', $cfg['base_url'])) {
		$out['err'] = 'service_not_configured';
		return $out;
	}
	$key = $admin ? $cfg['admin_api_key'] : $cfg['api_key'];
	if ($key === '') {
		$out['err'] = $admin ? 'admin_key_missing' : 'api_key_missing';
		return $out;
	}
	if (!function_exists('curl_init')) {
		$out['err'] = 'curl_missing';
		return $out;
	}
	$url = $cfg['base_url'].$path;
	$ch = curl_init($url);
	if ($ch === false) {
		$out['err'] = 'curl_init_failed';
		return $out;
	}
	$headers = array(
		'Authorization: Bearer '.$key,
		'Accept: application/json'
	);
	$to = $timeout !== null ? intval($timeout) : intval($cfg['timeout']);
	$opts = array(
		CURLOPT_CUSTOMREQUEST => strtoupper((string)$method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => $to,
		CURLOPT_CONNECTTIMEOUT => min(5, $to),
		CURLOPT_SSL_VERIFYPEER => true
	);
	if ($jsonBody !== null) {
		$headers[] = 'Content-Type: application/json';
		$opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
	}
	if ($admin && is_array($jsonBody) && isset($jsonBody['confirm_email'])) {
		$headers[] = 'X-Unsub-Confirm: '.$jsonBody['confirm_email'];
	}
	$opts[CURLOPT_HTTPHEADER] = $headers;
	curl_setopt_array($ch, $opts);
	$raw = curl_exec($ch);
	$status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if ($raw === false) {
		$out['err'] = $cerr !== '' ? $cerr : 'curl_error';
		return $out;
	}
	$decoded = json_decode($raw, true);
	$out['status'] = $status;
	$out['body'] = is_array($decoded) ? $decoded : null;
	$out['ok'] = $status >= 200 && $status < 300;
	if (!$out['ok']) $out['err'] = 'http_'.$status;
	return $out;
}

function unsub_client_health()
{
	$cfg = unsub_client_config();
	if ($cfg['base_url'] === '') return array('ok' => false, 'err' => 'no_base');
	if (!function_exists('curl_init')) return array('ok' => false, 'err' => 'curl_missing');
	$url = $cfg['base_url'].'/api/v1/health';
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 5,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_SSL_VERIFYPEER => true
	));
	$raw = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	curl_close($ch);
	$body = is_string($raw) ? json_decode($raw, true) : null;
	return array('ok' => $code === 200 && is_array($body) && !empty($body['ok']), 'status' => $code, 'body' => $body);
}

function unsub_client_issue_token($email, array $opts = array())
{
	$cfg = unsub_client_config();
	$payload = array(
		'email' => $email,
		'ttl' => isset($opts['ttl']) ? intval($opts['ttl']) : $cfg['token_ttl'],
		'purpose' => isset($opts['purpose']) ? $opts['purpose'] : 'marketing',
		'provider' => isset($opts['provider']) ? $opts['provider'] : '',
		'issued_by' => isset($opts['issued_by']) ? $opts['issued_by'] : 'ayb-mailer'
	);
	return unsub_client_request('POST', '/api/v1/tokens', $payload);
}

function unsub_client_check($email)
{
	$cfg = unsub_client_config();
	$email = strtolower(trim((string)$email));
	$path = '/api/v1/suppressions/check?email='.rawurlencode($email);
	return unsub_client_request('GET', $path, null, $cfg['check_timeout']);
}

function unsub_client_push_suppression($email, $reason, $source = '', $detail = '', $actor = 'mailer')
{
	return unsub_client_request('POST', '/api/v1/suppressions', array(
		'email' => $email,
		'reason' => $reason,
		'source' => $source,
		'detail' => $detail,
		'actor' => $actor
	));
}

function unsub_client_admin_remove($email, $detail = 'UI remove', $actor = 'operator')
{
	$email = strtolower(trim((string)$email));
	$path = '/api/v1/suppressions?email='.rawurlencode($email);
	return unsub_client_request('DELETE', $path, array(
		'email' => $email,
		'confirm_email' => $email,
		'detail' => $detail,
		'actor' => $actor
	), null, true);
}

/**
 * Pull remote suppressions into local cache via callback.
 * @param callable $applyFn function(array $row): void  row has email, reason, source, detail, removed_at?
 * @return array{ok:bool,pulled:int,err:string}
 */
function unsub_client_pull(callable $applyFn)
{
	$out = array('ok' => false, 'pulled' => 0, 'err' => '');
	if (!unsub_client_ready()) {
		$out['err'] = 'service_disabled_or_incomplete';
		return $out;
	}
	$meta = unsub_client_meta_get();
	$since = intval($meta['since_id']);
	unsub_client_meta_set(array('last_pull_at' => time()));
	$guard = 0;
	while ($guard < 50) {
		$guard++;
		$r = unsub_client_request('GET', '/api/v1/suppressions?since_id='.$since.'&limit=500');
		if (empty($r['ok']) || !is_array($r['body'])) {
			$out['err'] = isset($r['err']) ? $r['err'] : 'pull_failed';
			unsub_client_meta_set(array('last_error' => $out['err']));
			return $out;
		}
		$rows = isset($r['body']['rows']) && is_array($r['body']['rows']) ? $r['body']['rows'] : array();
		foreach ($rows as $row) {
			$applyFn($row);
			$out['pulled']++;
		}
		$next = isset($r['body']['next_since_id']) ? intval($r['body']['next_since_id']) : $since;
		if ($next <= $since || count($rows) < 1) {
			$since = max($since, $next);
			break;
		}
		$since = $next;
	}
	unsub_client_meta_set(array(
		'since_id' => $since,
		'last_pull_ok_at' => time(),
		'last_error' => ''
	));
	$out['ok'] = true;
	return $out;
}
