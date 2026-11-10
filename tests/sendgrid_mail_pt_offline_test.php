<?php
/**
 * Compat checks for sendgrid_mail_pt_api.php shim (country=pt default).
 * Full coverage lives in sendgrid_mail_offline_test.php.
 * Run: php tests/sendgrid_mail_pt_offline_test.php
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

echo "=== SendGridMail PT shim offline tests ===\n";

t_assert(is_file($root.DIRECTORY_SEPARATOR.'sendgrid_mail_pt_api.php'), 'shim exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'sendgrid_mail_api.php'), 'multi-country API exists');

$runner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_sgmp_shim_run.php';
@mkdir(dirname($runner), 0755, true);
$code = "<?php\n\$_GET = array('action'=>'overview');\ninclude ".var_export($root.DIRECTORY_SEPARATOR.'sendgrid_mail_pt_api.php', true).";\n";
file_put_contents($runner, $code);
$out = shell_exec('php '.escapeshellarg($runner).' 2>&1');
$data = json_decode((string)$out, true);
t_assert(is_array($data) && !empty($data['ok']), 'shim overview ok');
t_assert(isset($data['country']) && $data['country'] === 'pt', 'shim defaults country pt');
t_assert(isset($data['stats']['delivered_eligible']), 'uses delivered_eligible stats');
t_assert(isset($data['delivery_source']), 'exposes delivery_source');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'sendgrid_mail_api.php') !== false, 'UI points at multi-country API');

@unlink($runner);
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
