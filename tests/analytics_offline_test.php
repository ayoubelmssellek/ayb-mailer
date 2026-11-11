<?php
/**
 * Offline Phase 5 analytics overview tests.
 * Run: php tests/analytics_offline_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function t_assert($cond, $name)
{
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name\n"; $fail++; }
}

function ao_run($root, $runner, array $get)
{
	$code = "<?php\n\$_GET = ".var_export($get, true).";\ninclude ".var_export($root.DIRECTORY_SEPARATOR.'analytics_overview_api.php', true).";\n";
	file_put_contents($runner, $code);
	$out = shell_exec('php '.escapeshellarg($runner).' 2>&1');
	return json_decode((string)$out, true);
}

echo "=== Analytics overview offline tests ===\n";

t_assert(is_file($root.DIRECTORY_SEPARATOR.'analytics_overview_api.php'), 'analytics_overview_api.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'ANALYTICS.md'), 'ANALYTICS.md exists');

$runner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_ao_run.php';
@mkdir(dirname($runner), 0755, true);

$data = ao_run($root, $runner, array('action' => 'overview', 'days' => '7'));
t_assert(is_array($data) && !empty($data['ok']), 'overview returns ok JSON');
t_assert(isset($data['vocabulary']['accepted']) && isset($data['vocabulary']['delivered']), 'vocabulary present');
t_assert(isset($data['methods_status']['sendgrid']) && isset($data['methods_status']['kumomta']), 'methods status keys');
t_assert(isset($data['comparison']['disclaimer']), 'comparison disclaimer');
t_assert(isset($data['comparison']['methods']) && count($data['comparison']['methods']) === 3, 'three methods compared');
$labels = array();
if (is_array($data) && isset($data['comparison']['methods'])) {
	foreach ($data['comparison']['methods'] as $m) {
		$labels[$m['method']] = $m['primary_metric'];
	}
}
t_assert(isset($labels['sendgrid']) && $labels['sendgrid'] === 'delivered', 'SendGrid primary is delivered');
t_assert(isset($labels['kumomta']) && $labels['kumomta'] === 'accepted', 'Kumo primary is accepted');
t_assert(isset($labels['normal_smtp']), 'Normal SMTP present in comparison');
t_assert(is_array($data) && isset($data['comparison']['disclaimer']) && strpos($data['comparison']['disclaimer'], 'Do not') !== false, 'disclaimer warns against equating metrics');
t_assert(isset($data['queues']['api']) && isset($data['queues']['smtp']) && isset($data['queues']['kumomta']), 'queue lanes present');
t_assert(isset($data['throughput']), 'throughput block present');
t_assert(isset($data['suppressions']['counts']), 'suppressions counts present');

$log = ao_run($root, $runner, array('action' => 'logs', 'source' => 'kumomta', 'q' => '', 'limit' => '5'));
t_assert(is_array($log) && !empty($log['ok']), 'logs action ok');
t_assert(isset($log['note']) && strpos($log['note'], 'Phase 2') !== false, 'logs note mentions no unified ledger');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'analytics-overview-card') !== false, 'index has overview card');
t_assert(strpos($idx, 'Accepted ≠ Delivered') !== false, 'index states Accepted ≠ Delivered');
t_assert(strpos($idx, 'warmup:{title:') !== false, 'PAGE_META includes warmup');
t_assert(strpos($idx, 'logs:{title:') !== false, 'PAGE_META includes logs');
t_assert(strpos($idx, 'loadAnalyticsOverview') !== false, 'overview JS wired');
t_assert(strpos($idx, 'ao-kpi-delivered') !== false, 'analytics KPI delivered present');
t_assert(strpos($idx, '9. Global suppressions') !== false, 'suppressions numbered as section 9');

@unlink($runner);

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
