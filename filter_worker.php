<?php
/**
 * One parallel worker: tests proxies where index % workers == workerId
 * Args: workerId numWorkers
 * Proxy lines may be: ip:port | socks5://ip:port | socks4://ip:port
 */
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

$workerId = isset($argv[1]) ? intval($argv[1]) : 0;
$numWorkers = isset($argv[2]) ? max(1, intval($argv[2])) : 1;

$dir = __DIR__;
$src = $dir . DIRECTORY_SEPARATOR . 'socks5_proxies.txt';
$src4 = $dir . DIRECTORY_SEPARATOR . 'socks4_proxies.txt';
$dst = $dir . DIRECTORY_SEPARATOR . 'socks5_ok.txt';
$progress_file = $dir . DIRECTORY_SEPARATOR . 'socks5_filter_progress.txt';
$status_file = $dir . DIRECTORY_SEPARATOR . 'socks5_filter_status.json';
$lock_file = $dir . DIRECTORY_SEPARATOR . 'socks5_filter.lock';
$host = 'smtp.mail.com';
$port = 587;
$timeout = 3;

function socks5_try($proxyLabel, $destHost, $destPort, $timeout = 3) {
	$parts = explode(':', $proxyLabel);
	$fp = @stream_socket_client('tcp://'.$parts[0].':'.intval($parts[1]), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
	if (!is_resource($fp)) return false;
	stream_set_timeout($fp, $timeout);
	fwrite($fp, "\x05\x01\x00");
	$resp = @fread($fp, 2);
	if ($resp === false || strlen($resp) < 2 || $resp[0] !== "\x05" || $resp[1] !== "\x00") { fclose($fp); return false; }
	$req = "\x05\x01\x00\x03".chr(strlen($destHost)).$destHost.pack('n', intval($destPort));
	fwrite($fp, $req);
	$hdr = @fread($fp, 4);
	if ($hdr === false || strlen($hdr) < 4 || ord($hdr[1]) !== 0x00) { fclose($fp); return false; }
	$atyp = ord($hdr[3]);
	if ($atyp === 1) @fread($fp, 6);
	elseif ($atyp === 4) @fread($fp, 18);
	elseif ($atyp === 3) { $l = ord(@fread($fp, 1)); @fread($fp, $l + 2); }
	$banner = @fgets($fp, 512);
	fclose($fp);
	return ($banner && strpos($banner, '220') === 0);
}

function socks4_try($proxyLabel, $destHost, $destPort, $timeout = 3) {
	$parts = explode(':', $proxyLabel);
	$fp = @stream_socket_client('tcp://'.$parts[0].':'.intval($parts[1]), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
	if (!is_resource($fp)) return false;
	stream_set_timeout($fp, $timeout);
	// SOCKS4a domain
	$req = "\x04\x01".pack('n', intval($destPort))."\x00\x00\x00\x01"."\x00".$destHost."\x00";
	fwrite($fp, $req);
	$resp = @fread($fp, 8);
	if ($resp === false || strlen($resp) < 8 || ord($resp[0]) !== 0x00 || ord($resp[1]) !== 0x5a) { fclose($fp); return false; }
	$banner = @fgets($fp, 512);
	fclose($fp);
	return ($banner && strpos($banner, '220') === 0);
}

function socks_try($entry, $destHost, $destPort, $timeout = 3) {
	$label = is_array($entry) ? $entry['label'] : $entry;
	$proto = is_array($entry) && !empty($entry['proto']) ? $entry['proto'] : 'socks5';
	if ($proto === 'socks4') {
		if (socks4_try($label, $destHost, $destPort, $timeout)) return 'socks4';
		return false;
	}
	if (socks5_try($label, $destHost, $destPort, $timeout)) return 'socks5';
	// fallback: some lists mislabel
	if (socks4_try($label, $destHost, $destPort, $timeout)) return 'socks4';
	return false;
}

function lock_open($lock_file) {
	$lk = @fopen($lock_file, 'c+');
	if ($lk) flock($lk, LOCK_EX);
	return $lk;
}

function lock_close($lk) {
	if (!$lk) return;
	flock($lk, LOCK_UN);
	fclose($lk);
}

function count_ok_file($okFile) {
	$ok = 0;
	$seen = array();
	if (!is_file($okFile)) return 0;
	$lines = @file($okFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!is_array($lines)) return 0;
	foreach ($lines as $line) {
		$line = preg_replace('#^socks[45]a?h?://#i', '', trim($line));
		if (preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m)) {
			$k = $m[1].':'.$m[2];
			if (!isset($seen[$k])) { $seen[$k] = 1; $ok++; }
		}
	}
	return $ok;
}

function bump_progress($progress_file, $lock_file) {
	$lk = lock_open($lock_file);
	$p = is_file($progress_file) ? intval(trim(@file_get_contents($progress_file))) : 0;
	$p++;
	@file_put_contents($progress_file, (string)$p);
	lock_close($lk);
	return $p;
}

function append_ok($dst, $label, $proto, $lock_file) {
	$lk = lock_open($lock_file);
	$known = array();
	if (is_file($dst)) {
		$lines = @file($dst, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (is_array($lines)) {
			foreach ($lines as $line) {
				$line = preg_replace('#^socks[45]a?h?://#i', '', trim($line));
				if (preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m)) $known[$m[1].':'.$m[2]] = 1;
			}
		}
	}
	if (!isset($known[$label])) {
		$prefix = ($proto === 'socks4') ? 'socks4://' : 'socks5://';
		$fh = @fopen($dst, 'a');
		if ($fh) { fwrite($fh, $prefix.$label."\n"); fclose($fh); }
	}
	lock_close($lk);
}

function touch_status($status_file, $lock_file, $okFile, $progress, $total, $last, $msg, $numWorkers) {
	$lk = lock_open($lock_file);
	$ok = count_ok_file($okFile);
	$data = array(
		'state' => 'running',
		'progress' => $progress,
		'total' => $total,
		'ok_count' => $ok,
		'percent' => $total ? round($progress * 100 / $total, 1) : 0,
		'message' => $msg,
		'last_proxy' => $last,
		'workers' => $numWorkers,
		'updated_at' => date('c'),
		'updated_human' => date('Y-m-d H:i:s')
	);
	@file_put_contents($status_file, json_encode($data, JSON_PRETTY_PRINT));
	lock_close($lk);
}

function load_proxy_list($path, $defaultProto, &$proxies, $skip_ports) {
	if (!is_file($path)) return;
	$lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!is_array($lines)) return;
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') continue;
		$proto = $defaultProto;
		if (preg_match('#^(socks4a?|socks5h?)://#i', $line, $pm)) {
			$p = strtolower($pm[1]);
			$proto = (strpos($p, '4') !== false) ? 'socks4' : 'socks5';
			$line = preg_replace('#^(socks4a?|socks5h?)://#i', '', $line);
		}
		if (!preg_match('/^([^:\s]+):(\d+)/', $line, $m)) continue;
		if (isset($skip_ports[intval($m[2])])) continue;
		$proxies[] = array('label' => $m[1].':'.$m[2], 'proto' => $proto);
	}
}

$skip_ports = array(80=>1,8080=>1,3128=>1,8000=>1,8888=>1,8081=>1);
$proxies = array();
load_proxy_list($src, 'socks5', $proxies, $skip_ports);
load_proxy_list($src4, 'socks4', $proxies, $skip_ports);

// unique by proto|label
$uniq = array();
$out = array();
foreach ($proxies as $p) {
	$k = $p['proto'].'|'.$p['label'];
	if (isset($uniq[$k])) continue;
	$uniq[$k] = 1;
	$out[] = $p;
}
$proxies = $out;
$total = count($proxies);

$known = array();
if (is_file($dst)) {
	$okLines = @file($dst, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (is_array($okLines)) {
		foreach ($okLines as $line) {
			$line = preg_replace('#^socks[45]a?h?://#i', '', trim($line));
			if (preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m)) $known[$m[1].':'.$m[2]] = 1;
		}
	}
}

$mine = 0;
for ($i = $workerId; $i < $total; $i += $numWorkers) $mine++;
echo 'Worker '.$workerId.'/'.$numWorkers.' slice~'.$mine.' total='.$total.PHP_EOL;

for ($i = $workerId; $i < $total; $i += $numWorkers) {
	$entry = $proxies[$i];
	$p = $entry['label'];

	if (isset($known[$p])) {
		$prog = bump_progress($progress_file, $lock_file);
		if ($prog % 40 === 0) {
			touch_status($status_file, $lock_file, $dst, $prog, $total, $p, 'Parallel scan ('.$numWorkers.' workers)', $numWorkers);
		}
		continue;
	}

	$goodProto = socks_try($entry, $host, $port, $timeout);
	$prog = bump_progress($progress_file, $lock_file);

	if ($goodProto) {
		append_ok($dst, $p, $goodProto, $lock_file);
		$known[$p] = 1;
		echo '[OK] w'.$workerId.' '.$goodProto.' '.$p.PHP_EOL;
		touch_status($status_file, $lock_file, $dst, $prog, $total, $p, 'New OK ('.$goodProto.'): '.$p, $numWorkers);
	} elseif (($prog % 20) === 0) {
		touch_status($status_file, $lock_file, $dst, $prog, $total, $p, 'Parallel scan ('.$numWorkers.' workers)', $numWorkers);
		echo 'w'.$workerId.' at '.$prog.' / '.$total.PHP_EOL;
	}
}

echo 'Worker '.$workerId.' done'.PHP_EOL;
