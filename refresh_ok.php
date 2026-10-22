<?php
/**
 * Fast re-test of socks5_ok.txt only — drop dead, keep live.
 * Supports socks4:// and socks5:// lines.
 */
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

$dir = __DIR__;
$okFile = $dir . '/socks5_ok.txt';
$host = 'smtp.mail.com';
$port = 587;
$timeout = 2;
$pidFile = $dir . '/socks5_ok_refresh.pid';

@file_put_contents($pidFile, (string)getmypid());
register_shutdown_function(function() use ($pidFile) {
	if (is_file($pidFile) && trim(@file_get_contents($pidFile)) === (string)getmypid())
		@unlink($pidFile);
});

function try_socks5($label, $host, $port, $timeout) {
	$parts = explode(':', $label);
	if (count($parts) < 2) return false;
	$fp = @stream_socket_client('tcp://'.$parts[0].':'.intval($parts[1]), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
	if (!is_resource($fp)) return false;
	stream_set_timeout($fp, $timeout);
	fwrite($fp, "\x05\x01\x00");
	$resp = @fread($fp, 2);
	if ($resp === false || strlen($resp) < 2 || $resp[0] !== "\x05" || $resp[1] !== "\x00") { fclose($fp); return false; }
	$req = "\x05\x01\x00\x03".chr(strlen($host)).$host.pack('n', intval($port));
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

function try_socks4($label, $host, $port, $timeout) {
	$parts = explode(':', $label);
	if (count($parts) < 2) return false;
	$fp = @stream_socket_client('tcp://'.$parts[0].':'.intval($parts[1]), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
	if (!is_resource($fp)) return false;
	stream_set_timeout($fp, $timeout);
	$req = "\x04\x01".pack('n', intval($port))."\x00\x00\x00\x01"."\x00".$host."\x00";
	fwrite($fp, $req);
	$resp = @fread($fp, 8);
	if ($resp === false || strlen($resp) < 8 || ord($resp[0]) !== 0x00 || ord($resp[1]) !== 0x5a) { fclose($fp); return false; }
	$banner = @fgets($fp, 512);
	fclose($fp);
	return ($banner && strpos($banner, '220') === 0);
}

if (!is_file($okFile)) exit(0);
$lines = @file($okFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
$entries = array();
foreach ($lines as $line) {
	$raw = trim($line);
	$proto = (stripos($raw, 'socks4') === 0) ? 'socks4' : 'socks5';
	$line = preg_replace('#^socks[45]a?h?://#i', '', $raw);
	if (preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m)) {
		$k = $m[1].':'.$m[2];
		$entries[$k] = $proto;
	}
}
$live = array();
foreach ($entries as $p => $proto) {
	$ok = false;
	if ($proto === 'socks4') $ok = try_socks4($p, $host, $port, $timeout);
	else $ok = try_socks5($p, $host, $port, $timeout);
	if (!$ok) {
		// try other protocol once
		$ok = ($proto === 'socks4') ? try_socks5($p, $host, $port, $timeout) : try_socks4($p, $host, $port, $timeout);
		if ($ok) $proto = ($proto === 'socks4') ? 'socks5' : 'socks4';
	}
	if ($ok) $live[] = $proto.'://'.$p;
}
if (empty($live)) {
	echo 'OK refresh: 0 / '.count($entries)." live — kept previous socks5_ok.txt (no wipe)\n";
	exit(0);
}
$tmp = $okFile . '.tmp';
@file_put_contents($tmp, implode("\n", $live)."\n");
@rename($tmp, $okFile);
echo 'OK refresh: '.count($live).' / '.count($entries)." live\n";
