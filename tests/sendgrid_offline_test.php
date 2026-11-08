<?php
/**
 * Offline SendGrid tests (mail + analytics helpers). No live send required.
 * Run: php tests/sendgrid_offline_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
require_once $root.DIRECTORY_SEPARATOR.'env_load.php';
require_once $root.DIRECTORY_SEPARATOR.'sendgrid_mail.php';
require_once $root.DIRECTORY_SEPARATOR.'sendgrid_analytics_lib.php';

$pass = 0;
$fail = 0;

function t_assert($cond, $name)
{
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name\n"; $fail++; }
}

echo "=== SendGrid offline tests ===\n";

t_assert(class_exists('SendGrid'), 'SendGrid SDK class loaded');
t_assert(class_exists('SendGrid\\Mail\\Mail'), 'SendGrid\\Mail\\Mail loaded');
t_assert(!function_exists('sendgrid_verify_event_signature'), 'webhook signature helper removed');
t_assert(!is_file($root.DIRECTORY_SEPARATOR.'sendgrid_webhook.php'), 'sendgrid_webhook.php deleted');

$cfg = sendgrid_config();
t_assert(!array_key_exists('webhook_public_key', $cfg) || $cfg['webhook_public_key'] === null || !isset($cfg['webhook_public_key']), 'config has no webhook public key field');
t_assert($cfg['api_url'] === 'https://api.sendgrid.com/v3/mail/send', 'API URL is Mail Send v3');

// Missing key validation
putenv('SENDGRID_API_KEY=');
$_ENV['SENDGRID_API_KEY'] = '';
$_SERVER['SENDGRID_API_KEY'] = '';
$r = sendEmail('nobody@example.com', 'test', '<p>x</p>', 'x');
t_assert(empty($r['ok']) && ($r['status'] === 'configuration_error' || $r['err'] !== ''), 'missing key yields config error');

putenv('SENDGRID_API_KEY=SG.test_offline_key_not_real');
$_ENV['SENDGRID_API_KEY'] = 'SG.test_offline_key_not_real';
$_SERVER['SENDGRID_API_KEY'] = 'SG.test_offline_key_not_real';
$r2 = sendEmail('not-an-email', 'subj', '<p>hi</p>', null);
t_assert($r2['status'] === 'failed' && strpos($r2['err'], 'Invalid recipient') !== false, 'invalid recipient rejected');

$base = array('ok' => false, 'status' => 'failed', 'http' => 0, 'id' => 'mid', 'err' => '');
t_assert(sendgrid_normalize_http_result($base, 202, '')['status'] === 'accepted', 'HTTP 202 → accepted');
t_assert(sendgrid_normalize_http_result($base, 401, '')['status'] === 'configuration_error', 'HTTP 401 → configuration_error');

// Local analytics DB (SQLite) — additive, no MySQL
$pdo = sg_db();
t_assert($pdo instanceof PDO, 'sg_db opens SQLite');
sg_upsert_stats_day('2099-01-01', array('requests' => 3, 'delivered' => 2, 'bounces' => 1, 'processed' => 3));
sg_upsert_stats_day('2099-01-01', array('requests' => 5, 'delivered' => 4, 'bounces' => 1, 'processed' => 5)); // dedupe upsert
$sum = sg_stats_sum('2099-01-01', '2099-01-01');
t_assert(intval($sum['requests']) === 5, 'stats upsert dedupes by date');
sg_upsert_message(array('msg_id' => 'test-msg-1', 'to_email' => 'a@b.com', 'status' => 'delivered', 'subject' => 'Hi'));
sg_upsert_message(array('msg_id' => 'test-msg-1', 'to_email' => 'a@b.com', 'status' => 'delivered', 'subject' => 'Hi2'));
$q = sg_query_messages(array('email' => 'a@b.com'));
t_assert(intval($q['total']) === 1, 'message upsert dedupes by msg_id');
sg_upsert_suppression('bounce', array('email' => 'x@y.com', 'created' => '1', 'reason' => 'test'));
sg_upsert_suppression('bounce', array('email' => 'x@y.com', 'created' => '1', 'reason' => 'test'));
$sc = sg_suppression_counts();
t_assert(isset($sc['bounce']) && intval($sc['bounce']) >= 1, 'suppression stored');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'sendgrid_webhook.php') === false, 'index has no webhook URL');
t_assert(strpos($idx, 'sendgrid_sync.php') !== false, 'index uses sendgrid_sync.php');
t_assert(strpos($idx, 'SENDGRID_WEBHOOK_PUBLIC_KEY') === false, 'index has no webhook public key');

$envEx = file_get_contents($root.DIRECTORY_SEPARATOR.'.env.example');
t_assert(strpos($envEx, 'SENDGRID_WEBHOOK_PUBLIC_KEY') === false, '.env.example has no webhook key');

putenv('SENDGRID_API_KEY=');
$_ENV['SENDGRID_API_KEY'] = '';

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
