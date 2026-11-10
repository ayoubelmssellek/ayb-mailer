<?php
/**
 * Offline KumoMTA / VPS tests (config, profiles, lanes). No live SMTP required.
 * Run: php tests/kumomta_offline_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root.DIRECTORY_SEPARATOR.'env_load.php';
require_once $root.DIRECTORY_SEPARATOR.'queue_country.php';
require_once $root.DIRECTORY_SEPARATOR.'sender_profiles.php';
require_once $root.DIRECTORY_SEPARATOR.'kumomta_mail.php';

$pass = 0;
$fail = 0;

function t_assert($cond, $name)
{
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name\n"; $fail++; }
}

echo "=== KumoMTA offline tests ===\n";

// Isolate env for missing-cred case
putenv('KUMOMTA_ENABLED=true');
putenv('KUMOMTA_HOST=smtp1.omnidigitalsuite.com');
putenv('KUMOMTA_PORT=587');
putenv('KUMOMTA_ENCRYPTION=tls');
putenv('KUMOMTA_USERNAME=');
putenv('KUMOMTA_PASSWORD=');
putenv('KUMOMTA_DEFAULT_FROM=mail@omnidigitalsuite.com');
$_ENV['KUMOMTA_ENABLED'] = 'true';
$_ENV['KUMOMTA_HOST'] = 'smtp1.omnidigitalsuite.com';
$_ENV['KUMOMTA_PORT'] = '587';
$_ENV['KUMOMTA_ENCRYPTION'] = 'tls';
$_ENV['KUMOMTA_USERNAME'] = '';
$_ENV['KUMOMTA_PASSWORD'] = '';
$_ENV['KUMOMTA_DEFAULT_FROM'] = 'mail@omnidigitalsuite.com';
$_SERVER['KUMOMTA_USERNAME'] = '';
$_SERVER['KUMOMTA_PASSWORD'] = '';

$st = kumomta_config_status();
t_assert(empty($st['ok']) && $st['status'] === 'configuration_error', 'missing password → configuration_error');
t_assert(strpos($st['err'], 'PASSWORD') !== false || strpos($st['err'], 'USERNAME') !== false, 'missing creds message names env keys');
t_assert(empty($st['has_password']), 'status never implies password present when empty');
t_assert(!isset($st['password']), 'status payload has no password field');

putenv('KUMOMTA_USERNAME=kumo_test_user');
putenv('KUMOMTA_PASSWORD=kumo_test_pass_not_real');
$_ENV['KUMOMTA_USERNAME'] = 'kumo_test_user';
$_ENV['KUMOMTA_PASSWORD'] = 'kumo_test_pass_not_real';
$_SERVER['KUMOMTA_USERNAME'] = 'kumo_test_user';
$_SERVER['KUMOMTA_PASSWORD'] = 'kumo_test_pass_not_real';

$st2 = kumomta_config_status();
t_assert(!empty($st2['ok']) && $st2['status'] === 'ready', 'with user/pass status is ready');
t_assert($st2['host'] === 'smtp1.omnidigitalsuite.com', 'default host is smtp1.omnidigitalsuite.com');
t_assert(intval($st2['port']) === 587, 'default port 587');
t_assert($st2['encryption'] === 'tls', 'default encryption tls');
t_assert(!empty($st2['verify_peer']), 'TLS verify_peer defaults on');
t_assert($st2['socks'] === false, 'Kumo path declares socks=false');
t_assert(strpos($st2['username_preview'], '…') !== false || strlen($st2['username_preview']) <= 6, 'username preview is truncated');

$ident = kumomta_resolve_identity(array('profile_id' => 'news'));
t_assert($ident['from_email'] === 'news@omnidigitalsuite.com', 'news profile From');
t_assert($ident['profile_id'] === 'news', 'news profile id resolved');

$identMail = kumomta_resolve_identity(array('profile_id' => 'mail'));
t_assert($identMail['from_email'] === 'mail@omnidigitalsuite.com', 'mail profile From');

$profiles = sender_profile_public_list();
t_assert(!empty($profiles['ok']) && count($profiles['profiles']) >= 3, 'at least 3 sender profiles');
t_assert($profiles['default_id'] === 'mail', 'default profile is mail');

$rBad = kumomta_send_email('not-an-email', 'subj', '<p>hi</p>', true, array('profile_id' => 'mail'));
t_assert(empty($rBad['ok']) && strpos($rBad['err'], 'Invalid recipient') !== false, 'invalid recipient rejected offline');

$rCfg = kumomta_send_email('ok@example.com', 'subj', '<p>hi</p>', true, array('profile_id' => 'mail'));
// Without PHPMailer class loaded, expect configuration_error; with it loaded from elsewhere, may try SMTP — either is fine if not claiming delivered
t_assert(empty($rCfg['ok']), 'send without live server does not report ok');
t_assert(!isset($rCfg['delivered']), 'result never claims delivered');

t_assert(queue_lane_normalize('kumomta') === 'kumomta', 'lane normalize kumomta');
t_assert(queue_lane_normalize('vps') === 'kumomta', 'lane alias vps → kumomta');
t_assert(queue_lane_normalize('kumo') === 'kumomta', 'lane alias kumo → kumomta');
$files = queue_country_files('kumomta', 'pt');
t_assert(strpos($files['valid'], 'validmails_kumomta') === 0, 'kumomta validmails filename');
t_assert(strpos($files['done'], 'mailssenddone_kumomta') === 0, 'kumomta done filename');
t_assert(strpos($files['prog'], 'mail_queue_progress_kumomta') === 0, 'kumomta progress filename');

$apiFiles = queue_country_files('api', 'pt');
$smtpFiles = queue_country_files('smtp', 'pt');
t_assert($files['done'] !== $apiFiles['done'] && $files['done'] !== $smtpFiles['done'], 'lane isolation: done files differ');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'value="kumomta"') !== false, 'index has KumoMTA radio');
t_assert(strpos($idx, "smtp_mode === 'kumomta'") !== false, 'index has KumoMTA send branch');
t_assert(strpos($idx, 'ACCEPTED') !== false && strpos($idx, 'accept ≠ delivered') !== false, 'index labels accept ≠ delivered');
$kumoPos = strpos($idx, "// -------- KumoMTA / VPS SMTP (direct, no SOCKS) --------");
$socksPos = $kumoPos !== false ? strpos($idx, '// SOCKS5: send uses socks5_ok.txt', $kumoPos) : false;
$kumoChunk = ($kumoPos !== false && $socksPos !== false) ? substr($idx, $kumoPos, $socksPos - $kumoPos) : '';
t_assert($kumoChunk !== '' && strpos($kumoChunk, 'switch_smtp(') === false, 'Kumo send branch does not call switch_smtp');
t_assert($kumoChunk !== '' && strpos($kumoChunk, 'kumomta_send_email(') !== false, 'Kumo send branch calls kumomta_send_email');
$envEx = file_get_contents($root.DIRECTORY_SEPARATOR.'.env.example');
t_assert(strpos($envEx, 'KUMOMTA_HOST=') !== false, '.env.example has KUMOMTA_HOST');
t_assert(strpos($envEx, 'KUMOMTA_USERNAME=') !== false, '.env.example has KUMOMTA_USERNAME placeholder');

t_assert(is_file($root.DIRECTORY_SEPARATOR.'kumomta_status.php'), 'kumomta_status.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'kumomta_diag.php'), 'kumomta_diag.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'KUMOMTA_INTEGRATION.md'), 'KUMOMTA_INTEGRATION.md exists');

putenv('KUMOMTA_USERNAME=');
putenv('KUMOMTA_PASSWORD=');
$_ENV['KUMOMTA_USERNAME'] = '';
$_ENV['KUMOMTA_PASSWORD'] = '';

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
