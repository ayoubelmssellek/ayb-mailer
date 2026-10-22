<?php
/**
 * Filter status + start/stop
 * JSON:  filter_status.php?json=1
 * Start: filter_status.php?start=1
 * Stop:  filter_status.php?stop=1
 * HTML:  filter_status.php
 */
header('Cache-Control: no-store');
$dir = __DIR__;
$statusFile = $dir . '/socks5_filter_status.json';
$progressFile = $dir . '/socks5_filter_progress.txt';
$okFile = $dir . '/socks5_ok.txt';
$pidFile = $dir . '/socks5_filter.pid';
$script = $dir . '/filter_socks.php';
$logFile = $dir . '/socks5_filter_run.log';

function count_ok_file($okFile) {
	$n = 0;
	if (!is_file($okFile)) return 0;
	$seen = array();
	foreach (@file($okFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
		$line = preg_replace('#^socks5h?://#i', '', trim($line));
		if (preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m)) {
			$k = $m[1].':'.$m[2];
			if (!isset($seen[$k])) { $seen[$k] = 1; $n++; }
		}
	}
	return $n;
}

function pid_alive($pid) {
	$pid = intval($pid);
	if ($pid < 1) return false;
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$out = array();
		@exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL', $out);
		return (strpos(implode(' ', $out), (string)$pid) !== false);
	}
	return function_exists('posix_kill') ? @posix_kill($pid, 0) : file_exists("/proc/$pid");
}

function start_filter($script, $pidFile, $logFile, $scratch = false) {
	if (is_file($pidFile)) {
		$pid = intval(trim(@file_get_contents($pidFile)));
		if ($pid > 0 && pid_alive($pid)) return array('ok' => true, 'msg' => 'already', 'pid' => $pid);
	}
	$php = 'C:\\xampp\\php\\php.exe';
	if (!is_file($php)) $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
	$argList = escapeshellarg($script).($scratch ? ' scratch' : '');
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$out = escapeshellarg($logFile);
		$err = escapeshellarg($logFile.'.err');
		$ps = "Start-Process -FilePath ".escapeshellarg($php)." -ArgumentList $argList -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru | Select-Object -ExpandProperty Id";
		$pid = trim(@shell_exec('powershell -NoProfile -Command '.$ps));
		if ($pid !== '' && intval($pid) > 0) {
			@file_put_contents($pidFile, (string)intval($pid));
			return array('ok' => true, 'msg' => 'started', 'pid' => intval($pid));
		}
		pclose(popen('start /B "" '.escapeshellarg($php).' '.escapeshellarg($script).($scratch?' scratch':'').' >> '.escapeshellarg($logFile).' 2>&1', 'r'));
		@file_put_contents($pidFile, '1');
		return array('ok' => true, 'msg' => 'started', 'pid' => 0);
	}
	$cmd = 'nohup '.escapeshellarg($php).' '.escapeshellarg($script).($scratch?' scratch':'').' >> '.escapeshellarg($logFile).' 2>&1 & echo $!';
	$pid = trim(shell_exec($cmd));
	if ($pid !== '') @file_put_contents($pidFile, $pid);
	return array('ok' => true, 'msg' => 'started', 'pid' => intval($pid));
}

function stop_filter($pidFile, $statusFile) {
	$killed = false;
	$pid = 0;
	$workersPidFile = __DIR__ . '/socks5_filter_workers.pid';
	// Kill worker PIDs first
	if (is_file($workersPidFile)) {
		foreach (@file($workersPidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
			$wpid = intval(trim($line));
			if ($wpid < 1) continue;
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') @exec('taskkill /PID '.$wpid.' /F /T 2>NUL');
			else { @posix_kill($wpid, 9); }
		}
		@unlink($workersPidFile);
	}
	if (is_file($pidFile)) {
		$pid = intval(trim(@file_get_contents($pidFile)));
		if ($pid > 0) {
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				@exec('taskkill /PID '.$pid.' /F /T 2>NUL');
			} else {
				@posix_kill($pid, 15);
				usleep(200000);
				if (pid_alive($pid)) @posix_kill($pid, 9);
			}
			$killed = !pid_alive($pid);
		}
		@unlink($pidFile);
	}
	// Mark status stopped (keep progress so Run Test resumes)
	$status = is_file($statusFile) ? json_decode(@file_get_contents($statusFile), true) : array();
	if (!is_array($status)) $status = array();
	$status['state'] = 'stopped';
	$status['message'] = 'Stopped by user — progress saved, Run Test resumes';
	$status['updated_at'] = date('c');
	$status['updated_human'] = date('Y-m-d H:i:s');
	@file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
	return array('ok' => true, 'msg' => $killed || $pid > 0 ? 'stopped' : 'not_running', 'pid' => $pid);
}

if (isset($_GET['stop'])) {
	$r = stop_filter($pidFile, $statusFile);
	$data = array(
		'action' => $r['msg'],
		'pid' => $r['pid'],
		'ok_count' => count_ok_file($okFile),
		'running' => false,
		'state' => 'stopped',
		'message' => ($r['msg'] === 'not_running') ? 'Filter was not running' : 'Filter stopped — OK proxies kept in socks5_ok.txt'
	);
	if (isset($_GET['json'])) {
		header('Content-Type: application/json');
		echo json_encode($data);
		exit;
	}
	header('Location: filter_status.php');
	exit;
}

if (isset($_GET['start'])) {
	$scratch = isset($_GET['scratch']) || isset($_GET['from_scratch']) || isset($_GET['reset']);
	// Finished progress (e.g. 5754/5754) makes start a no-op unless we reset
	if ($scratch) {
		stop_filter($pidFile, $statusFile);
		@file_put_contents($progressFile, '0');
		@file_put_contents($statusFile, json_encode(array(
			'state' => 'running',
			'progress' => 0,
			'total' => 0,
			'ok_count' => count_ok_file($okFile),
			'percent' => 0,
			'workers' => 30,
			'message' => 'Starting full scan from scratch…',
			'last_proxy' => '',
			'updated_at' => date('c'),
			'updated_human' => date('Y-m-d H:i:s')
		), JSON_PRETTY_PRINT));
	} else {
		// Auto-scratch when previous run already finished / progress at end
		$st = is_file($statusFile) ? json_decode(@file_get_contents($statusFile), true) : null;
		$prog = is_file($progressFile) ? intval(trim(@file_get_contents($progressFile))) : 0;
		$total = is_array($st) && !empty($st['total']) ? intval($st['total']) : 0;
		$state = is_array($st) && !empty($st['state']) ? strtolower($st['state']) : '';
		if ($state === 'finished' || ($total > 0 && $prog >= $total)) {
			stop_filter($pidFile, $statusFile);
			@file_put_contents($progressFile, '0');
			$scratch = true;
		}
	}
	$r = start_filter($script, $pidFile, $logFile, true);
	$data = array(
		'action' => $r['msg'],
		'pid' => $r['pid'],
		'scratch' => $scratch ? 1 : 0,
		'ok_count' => count_ok_file($okFile),
		'message' => ($r['msg'] === 'already')
			? 'Filter already running'
			: ($scratch ? 'Full scan restarted from scratch — writing OK to socks5_ok.txt' : 'Filter started — writing OK proxies to socks5_ok.txt')
	);
	if (isset($_GET['json'])) {
		header('Content-Type: application/json');
		echo json_encode($data);
		exit;
	}
	header('Location: filter_status.php');
	exit;
}

$status = is_file($statusFile) ? json_decode(@file_get_contents($statusFile), true) : null;
if (!is_array($status)) $status = array();
$progress = is_file($progressFile) ? intval(trim(@file_get_contents($progressFile))) : 0;
$okCount = count_ok_file($okFile);
$running = false;
if (is_file($pidFile)) {
	$pid = intval(trim(@file_get_contents($pidFile)));
	$running = ($pid > 0 && pid_alive($pid));
}
if ($running) $status['state'] = 'running';
elseif (!empty($status['state']) && $status['state'] === 'running' && !$running) {
	$status['state'] = 'idle';
}
$status['ok_count'] = $okCount;
$status['progress'] = isset($status['progress']) ? $status['progress'] : $progress;
if (!isset($status['total'])) $status['total'] = 0;
$status['percent'] = !empty($status['total']) ? round(intval($status['progress']) * 100 / intval($status['total']), 1) : 0;
if (empty($status['message'])) $status['message'] = $running ? 'Running…' : 'Idle — click Run Test';
$status['running'] = $running;
// Live OK-list refresh during send (refresh_ok.php) — separate from full filter scan
$okRefreshPidFile = $dir . '/socks5_ok_refresh.pid';
$okRefreshRunning = false;
if (is_file($okRefreshPidFile)) {
	$orPid = intval(trim(@file_get_contents($okRefreshPidFile)));
	$okRefreshRunning = ($orPid > 0 && pid_alive($orPid));
}
$status['ok_refresh_running'] = $okRefreshRunning;
$stLower = strtolower(isset($status['state']) ? $status['state'] : '');
if (!$running && ($stLower === 'finished' || $stLower === 'idle' || $stLower === 'stopped')) {
	if ($okRefreshRunning) {
		$status['message'] = 'Full scan finished. Live OK refresh is re-testing socks5_ok.txt (count can change).';
	} elseif ($stLower === 'finished') {
		$status['message'] = 'Full scan finished. OK count still changes during send when dead proxies are dropped.';
	}
}

if (isset($_GET['json'])) {
	header('Content-Type: application/json');
	echo json_encode($status);
	exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>SOCKS filter</title>
<style>
body{font-family:Verdana,sans-serif;background:#0d131a;color:#e8eef5;padding:24px;margin:0}
a{color:#5eb3ff}
.box{background:#16202a;border:1px solid #2a3848;border-radius:12px;padding:20px;max-width:640px}
h2{margin:0 0 6px;font-size:18px}
.sub{color:#8b9bb0;font-size:12px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}
.kv{background:#101820;border-radius:10px;padding:12px}
.kv b{display:block;font-size:22px;margin-bottom:4px}
.kv span{font-size:10px;color:#8b9bb0;text-transform:uppercase;letter-spacing:.4px}
.msg{color:#8b9bb0;font-size:13px;min-height:20px;margin:8px 0 14px}
.btns{display:flex;flex-wrap:wrap;gap:8px}
button.btn{padding:10px 18px;background:#1a9f6a;color:#fff;border:0;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px}
button.btn.stop{background:#c0392b}
button.btn:disabled{opacity:.6;cursor:wait}
.bar{height:8px;background:#243040;border-radius:99px;overflow:hidden;margin-top:8px}
.bar>i{display:block;height:100%;width:0;background:linear-gradient(90deg,#1a6fbf,#5eb3ff);transition:width .3s}
</style></head>
<body>
<div class="box">
<h2>SOCKS filter → socks5_ok.txt</h2>
<div class="sub">Live scan status — Run / Stop without leaving this page</div>
<div class="grid">
  <div class="kv"><b id="st-state"><?php echo htmlspecialchars(strtoupper($status['state'] ?? 'idle')); ?></b><span>State</span></div>
  <div class="kv"><b id="st-ok" style="color:#2ee59d"><?php echo intval($okCount); ?></b><span>OK proxies</span></div>
  <div class="kv"><b id="st-prog"><?php echo intval($status['progress']); ?> / <?php echo intval($status['total'] ?: 0); ?></b><span>Scanned</span></div>
  <div class="kv"><b id="st-pct"><?php echo htmlspecialchars((string)$status['percent']); ?>%</b><span>Progress</span></div>
</div>
<div class="bar"><i id="st-bar" style="width:<?php echo floatval($status['percent']); ?>%"></i></div>
<div class="msg" id="st-msg"><?php echo htmlspecialchars($status['message'] ?? ''); ?></div>
<div class="msg" id="st-last">Last: <?php echo htmlspecialchars($status['last_proxy'] ?? '—'); ?></div>
<div class="btns">
  <button type="button" class="btn" id="btn-run">Run Test</button>
  <button type="button" class="btn stop" id="btn-stop">Stop Test</button>
</div>
</div>
<script>
(function(){
  function set(id,v){var e=document.getElementById(id); if(e) e.textContent=v;}
  function refresh(){
    fetch('filter_status.php?json=1&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      if(!d) return;
      var st=(d.state||(d.running?'running':'idle')).toUpperCase();
      set('st-state', st);
      set('st-ok', String(d.ok_count!=null?d.ok_count:0));
      set('st-prog', (d.progress||0)+' / '+(d.total||0));
      set('st-pct', (d.percent!=null?d.percent:0)+'%');
      set('st-msg', d.message||'');
      set('st-last', 'Last: '+(d.last_proxy||'—'));
      var bar=document.getElementById('st-bar');
      if(bar) bar.style.width=(d.percent!=null?d.percent:0)+'%';
    }).catch(function(){});
  }
  var run=document.getElementById('btn-run');
  var stop=document.getElementById('btn-stop');
  if(run) run.onclick=function(){
    run.disabled=true; run.textContent='Starting…';
    fetch('filter_status.php?start=1&json=1&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      run.disabled=false; run.textContent='Run Test';
      if(d) set('st-msg', d.message||'Started');
      refresh();
    }).catch(function(){ run.disabled=false; run.textContent='Run Test'; });
  };
  if(stop) stop.onclick=function(){
    stop.disabled=true; stop.textContent='Stopping…';
    fetch('filter_status.php?stop=1&json=1&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      stop.disabled=false; stop.textContent='Stop Test';
      if(d) set('st-msg', d.message||'Stopped');
      set('st-state','STOPPED');
      refresh();
    }).catch(function(){ stop.disabled=false; stop.textContent='Stop Test'; });
  };
  refresh();
  setInterval(refresh, 2000);
})();
</script>
</body></html>
