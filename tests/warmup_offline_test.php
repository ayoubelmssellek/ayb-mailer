<?php
/**
 * Offline warm-up governor tests. No live SMTP required.
 * Run: php tests/warmup_offline_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
define('AYB_WARMUP_DB', $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'warmup_test.sqlite');
@mkdir(dirname(AYB_WARMUP_DB), 0755, true);
foreach (array(AYB_WARMUP_DB, AYB_WARMUP_DB.'-wal', AYB_WARMUP_DB.'-shm') as $f) @unlink($f);

require_once $root.DIRECTORY_SEPARATOR.'env_load.php';
require_once $root.DIRECTORY_SEPARATOR.'warmup_lib.php';

$pass = 0;
$fail = 0;
function t_assert($cond, $name)
{
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name\n"; $fail++; }
}

echo "=== Warm-up offline tests ===\n";

putenv('WARMUP_ENABLED=true');
$_ENV['WARMUP_ENABLED'] = 'true';
putenv('WARMUP_DEFAULT_DAILY_CAP=3');
$_ENV['WARMUP_DEFAULT_DAILY_CAP'] = '3';
putenv('WARMUP_DEFAULT_HOURLY_CAP=10');
$_ENV['WARMUP_DEFAULT_HOURLY_CAP'] = '10';
putenv('WARMUP_METRIC_MIN_SAMPLE=5');
$_ENV['WARMUP_METRIC_MIN_SAMPLE'] = '5';
putenv('WARMUP_MAX_HARD_BOUNCE_RATE=0.2');
$_ENV['WARMUP_MAX_HARD_BOUNCE_RATE'] = '0.2';
putenv('WARMUP_MAX_COMPLAINT_RATE=0.1');
$_ENV['WARMUP_MAX_COMPLAINT_RATE'] = '0.1';
putenv('WARMUP_MAX_FAIL_RATE=0.5');
$_ENV['WARMUP_MAX_FAIL_RATE'] = '0.5';

@unlink($root.DIRECTORY_SEPARATOR.'stop_send.flag');

$plan = warmup_get_plan('kumomta');
t_assert(!empty($plan['id']) && intval($plan['current_daily_cap']) === 3, 'default plan daily cap 3');

$a1 = warmup_allow_send('kumomta', 'a@example.com');
t_assert(!empty($a1['ok']), 'allow when under cap');

warmup_record_outcome('kumomta', 'a@example.com', 'accepted');
warmup_record_outcome('kumomta', 'b@example.com', 'accepted');
warmup_record_outcome('kumomta', 'c@example.com', 'accepted');
$a2 = warmup_allow_send('kumomta', 'd@example.com');
t_assert(empty($a2['ok']) && !empty($a2['halt']), 'daily cap blocks after 3 accepted');
t_assert(strpos($a2['err'], 'Daily') !== false, 'daily cap message');

// Increase without approval must not raise cap
$req = warmup_request_increase('kumomta', 100, 20, 'want more', 'tester');
t_assert(!empty($req['ok']) && !empty($req['pending']) && empty($req['applied']), 'increase creates pending');
$plan2 = warmup_get_plan('kumomta');
t_assert(intval($plan2['current_daily_cap']) === 3, 'cap unchanged without approval');

// Approve should still fail while at cooldown/metrics - first clear day by using fresh plan state
// Cap still 3 and already at 3 — approve raises cap
$pendId = intval($req['pending_id']);
$ap = warmup_approve_increase($pendId, 'tester');
t_assert(!empty($ap['ok']) && !empty($ap['applied']), 'approve raises cap');
$plan3 = warmup_get_plan('kumomta');
t_assert(intval($plan3['current_daily_cap']) === 100, 'daily cap now 100');

$a3 = warmup_allow_send('kumomta', 'e@example.com');
t_assert(!empty($a3['ok']), 'allow after approved increase');

// Metrics cooldown: record many hard bounces relative to accepted
// Reset counters by using new day simulation — bump hard_bounce high
$planId = intval($plan3['id']);
for ($i = 0; $i < 6; $i++) {
	warmup_record_outcome('kumomta', 'hb'.$i.'@example.com', 'hard_bounce', array('smtp_code' => 550));
}
// Need enough accepted for sample — already have 3; add 3 more accepted then more hard bounces
for ($i = 0; $i < 3; $i++) warmup_record_outcome('kumomta', 'ok'.$i.'@example.com', 'accepted');
// hard_bounce count is high; accepted ~6; set low max hard rate
putenv('WARMUP_MAX_HARD_BOUNCE_RATE=0.05');
$_ENV['WARMUP_MAX_HARD_BOUNCE_RATE'] = '0.05';
putenv('WARMUP_METRIC_MIN_SAMPLE=5');
$_ENV['WARMUP_METRIC_MIN_SAMPLE'] = '5';

$a4 = warmup_allow_send('kumomta', 'z@example.com');
t_assert(empty($a4['ok']) && !empty($a4['halt']), 'bad metrics halt send');
$plan4 = warmup_get_plan('kumomta');
t_assert($plan4['state'] === 'cooldown', 'state flipped to cooldown with reason stored');

$st = warmup_status('kumomta');
t_assert(!empty($st['decisions']) && count($st['decisions']) >= 1, 'decisions log populated');
$hasCooldown = false;
foreach ($st['decisions'] as $d) {
	if (strpos($d['action'], 'cooldown') !== false || strpos($d['reason'], 'cooldown') !== false || strpos($d['reason'], 'Hard-bounce') !== false) $hasCooldown = true;
}
t_assert($hasCooldown, 'cooldown reason human-readable in decisions');

// Increase while cooldown refused
$req2 = warmup_request_increase('kumomta', 200, 30, 'more while bad', 'tester');
t_assert(!empty($req2['pending_id']), 'pending created even in cooldown');
$ap2 = warmup_approve_increase(intval($req2['pending_id']), 'tester');
t_assert(empty($ap2['ok']), 'approve refused during cooldown');
$plan5 = warmup_get_plan('kumomta');
t_assert(intval($plan5['current_daily_cap']) === 100, 'cap not raised while metrics bad');

// Emergency stop
@file_put_contents($root.DIRECTORY_SEPARATOR.'stop_send.flag', '1');
warmup_set_state($planId, 'active', 'reset for emergency test', 'tester');
$a5 = warmup_allow_send('kumomta', 'x@example.com');
t_assert(empty($a5['ok']) && !empty($a5['halt']) && strpos($a5['err'], 'Emergency') !== false, 'emergency stop halts');
@unlink($root.DIRECTORY_SEPARATOR.'stop_send.flag');

// Disabled flag opens gate regardless of state
putenv('WARMUP_ENABLED=false');
$_ENV['WARMUP_ENABLED'] = 'false';
warmup_set_state($planId, 'stopped', 'test', 'tester');
$a6 = warmup_allow_send('kumomta', 'y@example.com');
t_assert(!empty($a6['ok']), 'WARMUP_ENABLED=false bypasses governor');

putenv('WARMUP_ENABLED=true');
$_ENV['WARMUP_ENABLED'] = 'true';

t_assert(warmup_provider_from_email('a@gmail.com') === 'gmail', 'provider gmail');
t_assert(warmup_provider_from_email('a@outlook.com') === 'microsoft', 'provider microsoft');

$series = warmup_series('kumomta', 7);
t_assert(!empty($series['ok']) && is_array($series['days']), 'series endpoint data shape');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'warmup_lib.php') !== false, 'index requires warmup_lib');
t_assert(strpos($idx, 'page-warmup') !== false || strpos($idx, 'Warm-up') !== false, 'index has warm-up UI');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'WARMUP.md'), 'WARMUP.md exists');
$envEx = file_get_contents($root.DIRECTORY_SEPARATOR.'.env.example');
t_assert(strpos($envEx, 'WARMUP_ENABLED=') !== false, '.env.example has WARMUP_ENABLED');

foreach (array(AYB_WARMUP_DB, AYB_WARMUP_DB.'-wal', AYB_WARMUP_DB.'-shm') as $f) @unlink($f);

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
