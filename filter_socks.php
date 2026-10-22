<?php
/**
 * Master: spawn parallel workers to scan socks5_proxies.txt → socks5_ok.txt
 * Scratch/resume via socks5_filter_progress.txt (UI counter only).
 * Workers always scan their full modulo slice (skip only proxies already in OK file).
 *
 * Run:  C:\xampp\php\php.exe filter_socks.php
 */
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

$dir = __DIR__;
$src = $dir . '/socks5_proxies.txt';
$src4 = $dir . '/socks4_proxies.txt';
$dst = $dir . '/socks5_ok.txt';
$progress_file = $dir . '/socks5_filter_progress.txt';
$status_file = $dir . '/socks5_filter_status.json';
$pid_file = $dir . '/socks5_filter.pid';
$workers_pid_file = $dir . '/socks5_filter_workers.pid';
$worker_script = $dir . '/filter_worker.php';
$lock_file = $dir . '/socks5_filter.lock';

$NUM_WORKERS = 30;

if (!is_file($src) && !is_file($src4)) {
	fwrite(STDERR, "Missing socks5_proxies.txt and socks4_proxies.txt\n");
	exit(1);
}
if (!is_file($worker_script)) {
	fwrite(STDERR, "Missing $worker_script\n");
	exit(1);
}

@file_put_contents($pid_file, (string)getmypid());
register_shutdown_function(function() use ($pid_file, $workers_pid_file) {
	if (is_file($pid_file) && trim(@file_get_contents($pid_file)) === (string)getmypid()) {
		@unlink($pid_file);
	}
	@unlink($workers_pid_file);
});

function count_ok($dst) {
	$n = 0; $seen = array();
	if (!is_file($dst)) return 0;
	foreach (@file($dst, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
		$line = preg_replace('#^socks5h?://#i', '', trim($line));
		if (preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m)) {
			$k = $m[1].':'.$m[2];
			if (!isset($seen[$k])) { $seen[$k] = 1; $n++; }
		}
	}
	return $n;
}

function save_status($status_file, $data) {
	$data['updated_at'] = date('c');
	$data['updated_human'] = date('Y-m-d H:i:s');
	@file_put_contents($status_file, json_encode($data, JSON_PRETTY_PRINT));
}

function pid_alive_local($pid) {
	$pid = intval($pid);
	if ($pid < 1) return false;
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$out = array();
		@exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL', $out);
		return (strpos(implode(' ', $out), (string)$pid) !== false);
	}
	return function_exists('posix_kill') ? @posix_kill($pid, 0) : file_exists("/proc/$pid");
}

$skip_ports = array(80=>1,8080=>1,3128=>1,8000=>1,8888=>1,8081=>1);
$proxies = array();
$seen = array();
foreach (array($src => 'socks5', $src4 => 'socks4') as $path => $defProto) {
	if (!is_file($path)) continue;
	foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') continue;
		$proto = $defProto;
		if (preg_match('#^(socks4a?|socks5h?)://#i', $line, $pm)) {
			$p = strtolower($pm[1]);
			$proto = (strpos($p, '4') !== false) ? 'socks4' : 'socks5';
			$line = preg_replace('#^(socks4a?|socks5h?)://#i', '', $line);
		}
		if (!preg_match('/^([^:\s]+):(\d+)/', $line, $m)) continue;
		if (isset($skip_ports[intval($m[2])])) continue;
		$k = $proto.'|'.$m[1].':'.$m[2];
		if (isset($seen[$k])) continue;
		$seen[$k] = 1;
		$proxies[] = $k;
	}
}
$total = count($proxies);

$start = is_file($progress_file) ? max(0, intval(trim(@file_get_contents($progress_file)))) : 0;
$ok = count_ok($dst);

// Only treat as finished if we actually completed a real scan (progress at end AND not a fake instant finish)
// Allow forced re-run when OK is empty even if progress says done
$forceArg = isset($argv[1]) && ($argv[1] === 'scratch' || $argv[1] === '--scratch');
if ($forceArg || ($ok < 1 && $start >= $total)) {
	$start = 0;
	@file_put_contents($progress_file, '0');
}

if ($start >= $total && $ok > 0) {
	save_status($status_file, array(
		'state' => 'finished',
		'progress' => $total,
		'total' => $total,
		'ok_count' => $ok,
		'percent' => 100,
		'workers' => $NUM_WORKERS,
		'message' => 'Filter finished. OK proxies in socks5_ok.txt',
		'last_proxy' => ''
	));
	echo "Already finished $total / $total, ok=$ok\n";
	exit(0);
}

$php = 'C:\\xampp\\php\\php.exe';
if (!is_file($php)) $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';

save_status($status_file, array(
	'state' => 'running',
	'progress' => $start,
	'total' => $total,
	'ok_count' => $ok,
	'percent' => $total ? round($start * 100 / $total, 1) : 0,
	'workers' => $NUM_WORKERS,
	'message' => "Starting $NUM_WORKERS parallel workers…",
	'last_proxy' => ''
));

echo "Master PID ".getmypid()." — $NUM_WORKERS workers, resume $start / $total, ok=$ok\n";

$workerPids = array();
$procs = array();
$isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$nullOut = $isWin ? 'NUL' : '/dev/null';

// Separate NUL streams per worker (do NOT share one log file — that killed workers on Windows)
// No socks5_w*.out/err junk in the project folder
for ($w = 0; $w < $NUM_WORKERS; $w++) {
	$cmd = escapeshellarg($php).' '.escapeshellarg($worker_script).' '.$w.' '.$NUM_WORKERS;
	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('file', $nullOut, 'w'),
		2 => array('file', $nullOut, 'w')
	);
	$pipes = array();
	$proc = @proc_open($cmd, $descriptors, $pipes, $dir);
	if (is_resource($proc)) {
		if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
		$info = @proc_get_status($proc);
		if (!empty($info['pid'])) $workerPids[] = intval($info['pid']);
		$procs[$w] = $proc;
	} elseif ($isWin) {
		$bat = 'start /B "" '.$cmd.' > NUL 2> NUL';
		@pclose(@popen($bat, 'r'));
	}
}
@file_put_contents($workers_pid_file, implode("\n", $workerPids));
echo "Spawned ".count($procs)." proc_open + pids=".count($workerPids)." / $NUM_WORKERS\n";

if (count($procs) < 1 && count($workerPids) < 1) {
	save_status($status_file, array(
		'state' => 'error',
		'progress' => 0,
		'total' => $total,
		'ok_count' => $ok,
		'percent' => 0,
		'workers' => $NUM_WORKERS,
		'message' => 'ERROR: failed to spawn filter workers',
		'last_proxy' => ''
	));
	@file_put_contents($progress_file, '0');
	echo "ERROR: no workers spawned\n";
	exit(1);
}

$stallTicks = 0;
$lastProgress = $start;
while (true) {
	$alive = 0;
	if (!empty($procs)) {
		foreach ($procs as $i => $proc) {
			if (!is_resource($proc)) { unset($procs[$i]); continue; }
			$st = @proc_get_status($proc);
			if (!empty($st['running'])) $alive++;
			else {
				@proc_close($proc);
				unset($procs[$i]);
			}
		}
	} else {
		foreach ($workerPids as $wpid) {
			if (pid_alive_local($wpid)) $alive++;
		}
	}
	$progress = is_file($progress_file) ? intval(trim(@file_get_contents($progress_file))) : 0;
	$okNow = count_ok($dst);
	save_status($status_file, array(
		'state' => 'running',
		'progress' => min($progress, $total),
		'total' => $total,
		'ok_count' => $okNow,
		'percent' => $total ? round(min($progress, $total) * 100 / $total, 1) : 0,
		'workers' => $NUM_WORKERS,
		'message' => "Parallel scan: ~$alive workers · $progress / $total · OK $okNow",
		'last_proxy' => ''
	));

	if ($progress > $lastProgress) { $lastProgress = $progress; $stallTicks = 0; }
	else $stallTicks++;

	if (count($procs) < 1 && $alive < 1) break;
	if ($progress >= $total) break;
	if ($stallTicks > 150 && $progress > 0) break; // ~2 min stall after some progress
	if ($stallTicks > 20 && $progress < 1) break; // failed to start scanning
	usleep(800000);
}

$ok = count_ok($dst);
$progress = is_file($progress_file) ? intval(trim(@file_get_contents($progress_file))) : 0;

// Fake finish: workers died with almost no work — do NOT claim 100%
if ($progress < max(10, intval($total * 0.01)) && $ok < 1) {
	save_status($status_file, array(
		'state' => 'error',
		'progress' => $progress,
		'total' => $total,
		'ok_count' => $ok,
		'percent' => $total ? round($progress * 100 / $total, 1) : 0,
		'workers' => $NUM_WORKERS,
		'message' => 'ERROR: workers stopped early (progress '.$progress.'). Click Run Test again.',
		'last_proxy' => ''
	));
	echo "ERROR early exit. progress=$progress ok=$ok / $total\n";
	exit(1);
}

@file_put_contents($progress_file, (string)max($progress, $total));
save_status($status_file, array(
	'state' => 'finished',
	'progress' => $total,
	'total' => $total,
	'ok_count' => $ok,
	'percent' => 100,
	'workers' => $NUM_WORKERS,
	'message' => "Filter finished ($NUM_WORKERS workers). OK in socks5_ok.txt: $ok",
	'last_proxy' => ''
));
@unlink($workers_pid_file);
echo "DONE. ok=$ok / $total (workers=$NUM_WORKERS, progress=$progress)\n";
