<?php
/**
 * Offline suppression / unsubscribe tests. No live SMTP/SendGrid required.
 * Run: php tests/suppression_offline_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
// Use an isolated DB for tests
define('AYB_SUPPRESSIONS_DB', $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'suppressions_test.sqlite');
@mkdir(dirname(AYB_SUPPRESSIONS_DB), 0755, true);
@unlink(AYB_SUPPRESSIONS_DB);
@unlink(AYB_SUPPRESSIONS_DB.'-wal');
@unlink(AYB_SUPPRESSIONS_DB.'-shm');

require_once $root.DIRECTORY_SEPARATOR.'env_load.php';
require_once $root.DIRECTORY_SEPARATOR.'suppression_lib.php';
require_once $root.DIRECTORY_SEPARATOR.'sendgrid_mail.php';

$pass = 0;
$fail = 0;
function t_assert($cond, $name)
{
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name\n"; $fail++; }
}

echo "=== Suppression offline tests ===\n";

putenv('SUPPRESSION_ENABLED=true');
$_ENV['SUPPRESSION_ENABLED'] = 'true';
putenv('SUPPRESSION_SOFT_BOUNCE_MAX=3');
$_ENV['SUPPRESSION_SOFT_BOUNCE_MAX'] = '3';
putenv('SUPPRESSION_SOFT_BOUNCE_DAYS=14');
$_ENV['SUPPRESSION_SOFT_BOUNCE_DAYS'] = '14';
putenv('UNSUBSCRIBE_SECRET=test-secret-phase3');
$_ENV['UNSUBSCRIBE_SECRET'] = 'test-secret-phase3';
putenv('UNSUBSCRIBE_BASE_URL=https://example.test/ayb-mailer/unsubscribe.php');
$_ENV['UNSUBSCRIBE_BASE_URL'] = 'https://example.test/ayb-mailer/unsubscribe.php';

t_assert(empty(suppression_is_blocked('ok@example.com')['blocked']), 'clean address not blocked');

$add = suppression_add('bad@example.com', 'hard_bounce', 'test', 'unit test');
t_assert(!empty($add['ok']) && !empty($add['changed']), 'hard bounce add ok');
$b = suppression_is_blocked('bad@example.com');
t_assert(!empty($b['blocked']) && $b['reason'] === 'hard_bounce', 'hard bounce blocks pre-send');

$add2 = suppression_add('bad@example.com', 'hard_bounce', 'test', 'unit test');
t_assert(!empty($add2['ok']) && empty($add2['changed']), 'idempotent add does not re-audit as change');

$token = suppression_unsub_token('leave@example.com');
$parsed = suppression_unsub_parse_token($token);
t_assert(!empty($parsed['ok']) && $parsed['email'] === 'leave@example.com', 'unsub token round-trip');
$badTok = suppression_unsub_parse_token('not-a-token');
t_assert(empty($badTok['ok']), 'invalid unsub token rejected');

// Tamper token
$tampered = substr($token, 0, -4).'xxxx';
t_assert(empty(suppression_unsub_parse_token($tampered)['ok']), 'tampered token rejected');

$u = suppression_add('leave@example.com', 'unsubscribe', 'unsubscribe', 'one-click', 'recipient');
t_assert(!empty($u['ok']), 'unsubscribe add ok');
t_assert(!empty(suppression_is_blocked('leave@example.com')['blocked']), 'unsubscribed address blocked');

$rm = suppression_remove('leave@example.com', 'tester', 'cleanup');
t_assert(!empty($rm['ok']), 'remove audited');
t_assert(empty(suppression_is_blocked('leave@example.com')['blocked']), 'removed address allowed again');

$softEmail = 'soft@example.com';
$s1 = suppression_record_soft_bounce($softEmail, 'kumomta', '421 try later');
$s2 = suppression_record_soft_bounce($softEmail, 'kumomta', '421 try later');
$s3 = suppression_record_soft_bounce($softEmail, 'kumomta', '421 try later');
t_assert(!empty($s3['ok']) && !empty($s3['suppressed']) && $s3['count'] >= 3, 'soft bounce threshold suppresses');
t_assert(!empty(suppression_is_blocked($softEmail)['blocked']), 'soft-suppressed address blocked');

$hdrs = suppression_list_unsubscribe_headers('news@example.com');
t_assert(strpos($hdrs['list_unsubscribe'], 'https://example.test/') !== false, 'List-Unsubscribe has absolute URL');
t_assert($hdrs['list_unsubscribe_post'] === 'List-Unsubscribe=One-Click', 'List-Unsubscribe-Post set for https');

// SendGrid helper respects suppression without live API
putenv('SENDGRID_API_KEY=SG.test_offline_key_not_real');
$_ENV['SENDGRID_API_KEY'] = 'SG.test_offline_key_not_real';
$_SERVER['SENDGRID_API_KEY'] = 'SG.test_offline_key_not_real';
$r = sendEmail('bad@example.com', 'subj', '<p>hi</p>', null);
t_assert(empty($r['ok']) && $r['status'] === 'excluded', 'SendGrid sendEmail excludes suppressed');

$rOkShape = sendEmail('not-an-email', 'subj', '<p>hi</p>', null);
t_assert($rOkShape['status'] === 'failed', 'invalid recipient still failed (not excluded)');

$audit = suppression_audit_query(array('email' => 'bad@example.com', 'per_page' => 10));
t_assert(!empty($audit['ok']) && $audit['total'] >= 1, 'audit log has entries');

$counts = suppression_counts();
t_assert(isset($counts['total']) && $counts['total'] >= 2, 'counts populated');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'suppression_lib.php') !== false, 'index requires suppression_lib');
t_assert(strpos($idx, 'EXCLUDED') !== false, 'index logs EXCLUDED skips');
t_assert(strpos($idx, 'global-suppression-card') !== false, 'index has suppressions UI');

$envEx = file_get_contents($root.DIRECTORY_SEPARATOR.'.env.example');
t_assert(strpos($envEx, 'SUPPRESSION_ENABLED=') !== false, '.env.example has SUPPRESSION_ENABLED');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'unsubscribe.php'), 'unsubscribe.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'SUPPRESSION.md'), 'SUPPRESSION.md exists');

// Feature flag off
putenv('SUPPRESSION_ENABLED=false');
$_ENV['SUPPRESSION_ENABLED'] = 'false';
t_assert(empty(suppression_is_blocked('bad@example.com')['blocked']), 'SUPPRESSION_ENABLED=false opens gate');

putenv('SUPPRESSION_ENABLED=true');
$_ENV['SUPPRESSION_ENABLED'] = 'true';

@unlink(AYB_SUPPRESSIONS_DB);
@unlink(AYB_SUPPRESSIONS_DB.'-wal');
@unlink(AYB_SUPPRESSIONS_DB.'-shm');

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
