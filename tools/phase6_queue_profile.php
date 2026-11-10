<?php
/**
 * Phase 6 — non-destructive queue scan profiler.
 * Times line/email scans for api/smtp/kumomta × country files. Sends nothing.
 *
 * Run: php tools/phase6_queue_profile.php [country]
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root.DIRECTORY_SEPARATOR.'queue_country.php';

$country = queue_country_code(isset($argv[1]) ? $argv[1] : 'pt');
echo "=== Phase 6 queue profile (country={$country}) — NO SEND ===\n";

function profile_file($path)
{
	$t0 = microtime(true);
	$mem0 = memory_get_usage(true);
	$raw = 0;
	$emails = 0;
	$unique = 0;
	$seen = array();
	$exists = is_file($path);
	if ($exists) {
		$fh = @fopen($path, 'r');
		if ($fh) {
			while (($line = fgets($fh)) !== false) {
				$raw++;
				$e = strtolower(trim($line));
				if ($e === '' || strpos($e, '@') === false) continue;
				$emails++;
				if (isset($seen[$e])) continue;
				$seen[$e] = true;
				$unique++;
			}
			fclose($fh);
		}
	}
	$ms = round((microtime(true) - $t0) * 1000, 2);
	$mem1 = memory_get_usage(true);
	return array(
		'file' => basename($path),
		'exists' => $exists,
		'raw_lines' => $raw,
		'emails' => $emails,
		'unique' => $unique,
		'ms' => $ms,
		'mem_delta_kb' => round(($mem1 - $mem0) / 1024, 1)
	);
}

foreach (array('api', 'smtp', 'kumomta') as $lane) {
	$names = queue_country_files($lane, $country);
	echo "\nLane {$lane}:\n";
	foreach (array('valid', 'done') as $kind) {
		$r = profile_file($root.DIRECTORY_SEPARATOR.$names[$kind]);
		$ex = $r['exists'] ? 'yes' : 'NO';
		echo sprintf(
			"  %-28s exists=%-3s raw=%d emails=%d unique=%d time=%sms memΔ=%sKB\n",
			$r['file'],
			$ex,
			$r['raw_lines'],
			$r['emails'],
			$r['unique'],
			$r['ms'],
			$r['mem_delta_kb']
		);
	}
}

echo "\nNotes:\n";
echo "- This is simulated scan cost only — not email delivery throughput.\n";
echo "- Missing files are reported; figures are not invented.\n";
echo "- SQLite was not touched (Phase 6 exclusion).\n";
echo "Done.\n";
