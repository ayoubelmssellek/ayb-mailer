<?php
/**
 * Live Hostinger unsubscribe service verification.
 * Uses .env keys; never prints secret values.
 * Run: php tests/unsub_live_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root.DIRECTORY_SEPARATOR.'env_load.php';
ayb_env_load($root.DIRECTORY_SEPARATOR.'.env');

$base = rtrim(ayb_env('UNSUB_SERVICE_BASE_URL', 'https://mail.omnidigitalsuite.com'), '/');
$apiKey = ayb_env('UNSUB_API_KEY', '');
$adminKey = ayb_env('UNSUB_ADMIN_API_KEY', '');
$enabled = ayb_env_bool('UNSUB_SERVICE_ENABLED', false);

$pass = 0;
$fail = 0;
function check($name, $cond, $detail = '')
{
	global $pass, $fail;
	if ($cond) {
		echo "[PASS] $name\n";
		$pass++;
	} else {
		echo "[FAIL] $name".($detail !== '' ? " — $detail" : '')."\n";
		$fail++;
	}
}

function http_req($method, $url, $headers = array(), $body = null)
{
	$ch = curl_init($url);
	$hdrs = array('Accept: application/json');
	foreach ($headers as $h) $hdrs[] = $h;
	$opts = array(
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_TIMEOUT => 20,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_HTTPHEADER => $hdrs,
	);
	if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
	curl_setopt_array($ch, $opts);
	$raw = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$err = curl_error($ch);
	curl_close($ch);
	if ($raw === false) {
		return array('status' => 0, 'body' => '', 'err' => $err);
	}
	return array(
		'status' => $code,
		'body' => substr($raw, $hs),
		'err' => ''
	);
}

function body_snip($body, $n = 100)
{
	$s = preg_replace('/\s+/', ' ', (string) $body);
	return substr($s, 0, $n);
}

echo "=== ENV presence (no values) ===\n";
check('UNSUB_SERVICE_BASE_URL is https', (bool) preg_match('#^https://#i', $base), $base);
check('UNSUB_API_KEY is 64 hex', (bool) preg_match('/^[a-f0-9]{64}$/', $apiKey), 'len='.strlen($apiKey));
check('UNSUB_ADMIN_API_KEY is 64 hex', (bool) preg_match('/^[a-f0-9]{64}$/', $adminKey), 'len='.strlen($adminKey));
check('API and ADMIN keys differ', $apiKey !== '' && $apiKey !== $adminKey);
check('UNSUB_SERVICE_ENABLED=true', $enabled === true, $enabled ? 'true' : 'false');

echo "\n=== Public endpoints ===\n";
$r = http_req('GET', $base.'/');
check('GET / -> 200', $r['status'] === 200, 'status='.$r['status'].' body='.body_snip($r['body'], 40));
check('GET / body is ayb-unsub', trim($r['body']) === 'ayb-unsub', 'body='.body_snip($r['body'], 40));

$r = http_req('GET', $base.'/api/v1/health');
$j = json_decode($r['body'], true);
check('GET /api/v1/health -> 200', $r['status'] === 200, 'status='.$r['status'].' body='.body_snip($r['body']));
check('health ok and db true', is_array($j) && !empty($j['ok']) && !empty($j['db']), is_array($j) ? json_encode($j) : 'nojson');
check('health has no secrets', !preg_match('/api_key|admin_api_key|db_pass|Bearer\s+[a-f0-9]{16}/i', $r['body']));

$r = http_req('GET', $base.'/u/invalidtoken');
check('GET /u/invalidtoken -> 200', $r['status'] === 200, 'status='.$r['status']);
check('GET /u/invalidtoken shows Invalid link', stripos($r['body'], 'Invalid link') !== false);

$r = http_req('POST', $base.'/u/invalidtoken', array('Content-Type: application/x-www-form-urlencoded'), 'List-Unsubscribe=One-Click');
check('POST /u/invalidtoken one-click -> 200', $r['status'] === 200, 'status='.$r['status']);

echo "\n=== Exposure paths (must not be 200 for config/src) ===\n";
foreach (array('/config.php', '/src/bootstrap.php') as $p) {
	$r = http_req('GET', $base.$p);
	check($p.' not HTTP 200', $r['status'] !== 200, 'status='.$r['status']);
}

echo "\n=== API auth ===\n";
$r = http_req('GET', $base.'/api/v1/suppressions/check?email='.rawurlencode('authprobe@example.test'));
check('check without key -> 401', $r['status'] === 401, 'status='.$r['status']);

$r = http_req(
	'GET',
	$base.'/api/v1/suppressions/check?email='.rawurlencode('authprobe@example.test'),
	array('Authorization: Bearer '.$apiKey)
);
$j = json_decode($r['body'], true);
check('check with API_KEY -> 200', $r['status'] === 200, 'status='.$r['status'].' body='.body_snip($r['body']));
check('check JSON ok', is_array($j) && !empty($j['ok']));

$r = http_req(
	'GET',
	$base.'/api/v1/suppressions/check?email='.rawurlencode('authprobe@example.test'),
	array('Authorization: Bearer '.$adminKey)
);
check('check with ADMIN key -> 401', $r['status'] === 401, 'status='.$r['status']);

$r = http_req(
	'DELETE',
	$base.'/api/v1/suppressions?email='.rawurlencode('nosuch-delete@example.test'),
	array(
		'Authorization: Bearer '.$apiKey,
		'Content-Type: application/json',
		'X-Unsub-Confirm: nosuch-delete@example.test'
	),
	json_encode(array(
		'email' => 'nosuch-delete@example.test',
		'confirm_email' => 'nosuch-delete@example.test'
	))
);
check('DELETE with API_KEY -> 401', $r['status'] === 401, 'status='.$r['status']);

$r = http_req(
	'DELETE',
	$base.'/api/v1/suppressions?email='.rawurlencode('nosuch-delete@example.test'),
	array(
		'Authorization: Bearer '.$adminKey,
		'Content-Type: application/json',
		'X-Unsub-Confirm: nosuch-delete@example.test'
	),
	json_encode(array(
		'email' => 'nosuch-delete@example.test',
		'confirm_email' => 'nosuch-delete@example.test',
		'detail' => 'probe'
	))
);
check('DELETE with ADMIN_KEY allowed (200/400)', in_array($r['status'], array(200, 400), true), 'status='.$r['status'].' body='.body_snip($r['body']));

echo "\n=== Token issue + one-click consume ===\n";
$testEmail = 'ayb.unsub.probe.'.bin2hex(random_bytes(4)).'@example.test';
$r = http_req(
	'POST',
	$base.'/api/v1/tokens',
	array(
		'Authorization: Bearer '.$apiKey,
		'Content-Type: application/json'
	),
	json_encode(array(
		'email' => $testEmail,
		'provider' => 'probe',
		'issued_by' => 'live_test'
	))
);
$j = json_decode($r['body'], true);
check('POST /tokens -> 200', $r['status'] === 200, 'status='.$r['status'].' body='.body_snip($r['body']));
check(
	'token URL is https /u/ opaque',
	is_array($j) && !empty($j['url']) && (bool) preg_match('#^https://mail\.omnidigitalsuite\.com/u/[A-Za-z0-9\-_]+$#', $j['url']),
	is_array($j) && isset($j['url']) ? ('url_host_ok') : 'missing'
);
check('token response omits email', is_array($j) && strpos($r['body'], $testEmail) === false);

$tokenUrl = (is_array($j) && isset($j['url'])) ? $j['url'] : '';
if ($tokenUrl !== '') {
	$r = http_req('GET', $tokenUrl);
	check('GET token confirm page', $r['status'] === 200 && stripos($r['body'], 'Confirm') !== false, 'status='.$r['status']);

	$r = http_req('POST', $tokenUrl, array('Content-Type: application/x-www-form-urlencoded'), 'List-Unsubscribe=One-Click');
	check(
		'POST one-click unsubscribed',
		$r['status'] === 200 && (stripos($r['body'], 'unsubscribed') !== false || stripos($r['body'], 'Already') !== false),
		'status='.$r['status']
	);

	$r = http_req(
		'GET',
		$base.'/api/v1/suppressions/check?email='.rawurlencode($testEmail),
		array('Authorization: Bearer '.$apiKey)
	);
	$j = json_decode($r['body'], true);
	check(
		'probe email blocked after unsub',
		is_array($j) && !empty($j['blocked']) && isset($j['reason']) && $j['reason'] === 'unsubscribe',
		is_array($j) ? json_encode($j) : ''
	);

	$r = http_req(
		'DELETE',
		$base.'/api/v1/suppressions?email='.rawurlencode($testEmail),
		array(
			'Authorization: Bearer '.$adminKey,
			'Content-Type: application/json',
			'X-Unsub-Confirm: '.$testEmail
		),
		json_encode(array(
			'email' => $testEmail,
			'confirm_email' => $testEmail,
			'detail' => 'live_test cleanup',
			'actor' => 'probe'
		))
	);
	check('admin cleanup remove -> 200', $r['status'] === 200, 'status='.$r['status'].' body='.body_snip($r['body']));
}

echo "\n=== Desktop client ===\n";
require_once $root.DIRECTORY_SEPARATOR.'unsub_client.php';
require_once $root.DIRECTORY_SEPARATOR.'suppression_lib.php';
check('unsub_client_ready()', unsub_client_ready());
$h = unsub_client_health();
check('unsub_client_health()', !empty($h['ok']), isset($h['status']) ? ('status='.$h['status']) : '');
$pull = suppression_sync_from_service();
check('suppression_sync_from_service()', !empty($pull['ok']), isset($pull['err']) ? $pull['err'] : '');
$sync = suppression_sync_allow_marketing();
check('suppression_sync_allow_marketing()', !empty($sync['ok']), isset($sync['err']) ? $sync['err'] : '');

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
