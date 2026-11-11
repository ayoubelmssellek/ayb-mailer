<?php
/**
 * Offline checks for Phase 2 dashboard UI presentation helpers.
 * Does not send email or touch VPS.
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root.DIRECTORY_SEPARATOR.'log_redact.php';
require_once $root.DIRECTORY_SEPARATOR.'warmup_lib.php';

function t_assert($cond, $msg)
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: ".$msg."\n");
		exit(1);
	}
	echo "OK: ".$msg."\n";
}

// Redaction
$s = ayb_log_redact_text('Authorization: Bearer abcdef123456 password=secret123 api_key=XYZ SENDGRID_API_KEY=SG.aaaa.bbbb');
t_assert(strpos($s, 'abcdef123456') === false, 'Bearer token redacted');
t_assert(strpos($s, 'secret123') === false, 'password redacted');
t_assert(strpos($s, 'XYZ') === false, 'api_key redacted');
t_assert(strpos($s, '[REDACTED]') !== false, 'redaction marker present');

$row = ayb_log_redact_row(array('details' => 'smtp_pass=hunter2 token=abc', 'ok' => true));
t_assert(strpos($row['details'], 'hunter2') === false, 'row redaction');

// Warm-up update presentation flags (no enforcement change)
putenv('WARMUP_ENABLED=true');
$_ENV['WARMUP_ENABLED'] = 'true';
$plan = warmup_get_plan('kumomta');
$daily = intval($plan['current_daily_cap']);
$hourly = intval($plan['hourly_cap']);
$same = warmup_update_plan('kumomta', array(
	'current_daily_cap' => $daily,
	'hourly_cap' => $hourly,
	'reason' => 'ui offline unchanged'
), 'tester');
t_assert(!empty($same['ok']), 'update ok');
t_assert(!empty($same['unchanged']), 'unchanged flag when caps identical');
t_assert(empty($same['pending']), 'not pending when unchanged');
t_assert(empty($same['applied']), 'not applied when unchanged');

// Display math (mirror UI formula)
$min = 180;
$pacing = 3600 / $min;
$eff = min(50, $pacing);
t_assert(abs($eff - 20.0) < 0.001, 'effective throughput min(50, 20)=20');

t_assert(is_file($root.DIRECTORY_SEPARATOR.'dashboard_logs_api.php'), 'dashboard_logs_api.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'log_redact.php'), 'log_redact.php exists');

// Subprocess smoke for logs API sources
$runner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_dlog_run.php';
@mkdir(dirname($runner), 0755, true);
file_put_contents($runner, "<?php\n\$_GET = array('action' => 'sources');\ninclude ".var_export($root.DIRECTORY_SEPARATOR.'dashboard_logs_api.php', true).";\n");
$out = shell_exec('php '.escapeshellarg($runner).' 2>&1');
@unlink($runner);
t_assert(is_string($out) && (strpos($out, '"ok":true') !== false || strpos($out, '"ok": true') !== false), 'logs API sources ok json');

$listRunner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_dlog_list.php';
file_put_contents($listRunner, "<?php\n\$_GET = array('action' => 'list', 'source' => 'warmup', 'page' => '1', 'per_page' => '5');\ninclude ".var_export($root.DIRECTORY_SEPARATOR.'dashboard_logs_api.php', true).";\n");
$listOut = shell_exec('php '.escapeshellarg($listRunner).' 2>&1');
@unlink($listRunner);
$listJson = json_decode((string)$listOut, true);
t_assert(is_array($listJson) && !empty($listJson['ok']), 'logs API list ok');
t_assert(isset($listJson['rows']) && is_array($listJson['rows']), 'logs API list rows');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'data-page="logs"') !== false, 'nav has Logs');
t_assert(strpos($idx, 'page-logs') !== false, 'logs page exists');
t_assert(strpos($idx, 'wu-eff-throughput') !== false, 'warm-up effective throughput KPI');
t_assert(strpos($idx, 'btn-wu-apply-hourly') !== false, 'apply hourly button');
t_assert(strpos($idx, 'ao-kpi-delivered') !== false, 'analytics delivered KPI');
t_assert(strpos($idx, 'Pending approval') !== false || strpos($idx, 'd.pending') !== false, 'pending save UX wired');

echo "ALL DASHBOARD UI OFFLINE CHECKS PASSED\n";
