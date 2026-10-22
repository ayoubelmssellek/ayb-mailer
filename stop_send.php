<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
$dir = __DIR__;

function pid_alive_stop($pid) {
	$pid = intval($pid);
	if ($pid < 1) return false;
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$out = array();
		@exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL', $out);
		return (strpos(implode(' ', $out), (string)$pid) !== false);
	}
	return function_exists('posix_kill') ? @posix_kill($pid, 0) : file_exists("/proc/$pid");
}

function kill_pid_stop($pid) {
	$pid = intval($pid);
	if ($pid < 1) return false;
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		@exec('taskkill /PID '.$pid.' /F /T 2>NUL');
	} else {
		@posix_kill($pid, 9);
	}
	return true;
}

/** Kill CLI send workers (php index.php --campaign=...) — never touch httpd/Apache */
function kill_campaign_cli_workers() {
	$killed = 0;
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$lines = array();
		@exec('wmic process where "name=\'php.exe\'" get ProcessId,CommandLine /FORMAT:LIST 2>NUL', $lines);
		$cmd = '';
		$pid = 0;
		foreach ($lines as $line) {
			$line = trim($line);
			if (stripos($line, 'CommandLine=') === 0) {
				$cmd = substr($line, strlen('CommandLine='));
			} elseif (stripos($line, 'ProcessId=') === 0) {
				$pid = intval(substr($line, strlen('ProcessId=')));
				if ($pid > 0 && $cmd !== '' && stripos($cmd, '--campaign') !== false) {
					kill_pid_stop($pid);
					$killed++;
				}
				$cmd = '';
				$pid = 0;
			}
		}
	} else {
		@exec("pgrep -f 'index.php --campaign' 2>/dev/null", $pids);
		foreach ($pids ?: array() as $p) {
			kill_pid_stop(intval($p));
			$killed++;
		}
	}
	return $killed;
}

// Status check only — UI polls until send actually stopped
if (isset($_GET['check'])) {
	$runningFile = $dir . '/send_running.flag';
	clearstatcache(true, $runningFile);
	$running = is_file($runningFile);
	$pending = 0;
	if (is_file($dir . '/pending_emails.txt')) {
		$pending = count(array_filter(array_map('trim', @file($dir . '/pending_emails.txt', FILE_IGNORE_NEW_LINES) ?: array()), 'strlen'));
	}
	echo json_encode(array(
		'ok' => true,
		'running' => $running,
		'pending' => $pending,
		'stop_flag' => is_file($dir . '/stop_send.flag')
	));
	exit;
}

@file_put_contents($dir . '/stop_send.flag', '1');

$active = $dir . '/active_campaign.txt';
$campaign = is_file($active) ? trim(@file_get_contents($active)) : '';
$killed = 0;
$pendingSaved = 0;

if ($campaign !== '' && is_dir($campaign)) {
	@file_put_contents($campaign . DIRECTORY_SEPARATOR . 'stop.flag', '1');
	$wpid = $campaign . DIRECTORY_SEPARATOR . 'workers.pid';
	if (is_file($wpid)) {
		foreach (@file($wpid, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
			$pid = intval(trim($line));
			if ($pid < 1) continue;
			kill_pid_stop($pid);
			$killed++;
		}
	}

	// Save pending from campaign files
	$emailsFile = $campaign . DIRECTORY_SEPARATOR . 'emails.txt';
	$doneFile = $campaign . DIRECTORY_SEPARATOR . 'done.txt';
	if (is_file($emailsFile)) {
		$emails = @file($emailsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
		$doneMap = array();
		if (is_file($doneFile)) {
			foreach (@file($doneFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
				$doneMap[strtolower(trim($line))] = 1;
		}
		$pending = array();
		foreach ($emails as $e) {
			$e = trim($e);
			if ($e === '' || strpos($e, '@') === false) continue;
			if (empty($doneMap[strtolower($e)])) $pending[] = $e;
		}
		$pending = array_values(array_unique($pending));
		@file_put_contents($dir . '/pending_emails.txt', $pending ? (implode("\n", $pending)."\n") : '');
		$pendingSaved = count($pending);
	}
}

// Kill any leftover CLI campaign workers by command line
$killed += kill_campaign_cli_workers();

// If force=1 or still running after worker kill — do not kill Apache; just report
$runningFile = $dir . '/send_running.flag';
clearstatcache(true, $runningFile);
$stillRunning = is_file($runningFile);

echo json_encode(array(
	'ok' => true,
	'message' => 'Stop signal sent — CLI workers killed: '.$killed.'. Pending: '.$pendingSaved.($stillRunning ? ' — waiting for send loop to exit…' : ' — send stopped'),
	'campaign' => $campaign,
	'pending' => $pendingSaved,
	'killed' => $killed,
	'running' => $stillRunning
));
