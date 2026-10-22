<?php
/**
 * Dashboard upload/save for SOCKS lists.
 * Writes only socks5_proxies.txt or socks4_proxies.txt in this folder.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if($_SERVER['REQUEST_METHOD'] !== 'POST')
{
	echo json_encode(array('ok' => false, 'err' => 'POST required'));
	exit;
}

function proxy_list_path($kind)
{
	if($kind === 'socks5') return __DIR__.DIRECTORY_SEPARATOR.'socks5_proxies.txt';
	if($kind === 'socks4') return __DIR__.DIRECTORY_SEPARATOR.'socks4_proxies.txt';
	return '';
}

function proxy_normalize($raw)
{
	$out = array();
	$seen = array();
	foreach(preg_split("/\r\n|\r|\n/", (string)$raw) as $line)
	{
		$line = trim($line);
		if($line === '' || $line[0] === '#') continue;
		if(preg_match('#^(?:socks4a?|socks5h?)://#i', $line))
			$line = preg_replace('#^(?:socks4a?|socks5h?)://#i', '', $line);
		if(!preg_match('/^([^:\s\/]+):(\d{2,5})\b/', $line, $m)) continue;
		$key = $m[1].':'.$m[2];
		if(isset($seen[$key])) continue;
		$seen[$key] = true;
		$out[] = $key;
	}
	return $out;
}

function proxy_write($path, $lines)
{
	$fh = @fopen($path, 'c+');
	if(!$fh) return false;
	flock($fh, LOCK_EX);
	ftruncate($fh, 0);
	rewind($fh);
	foreach($lines as $line) fwrite($fh, $line."\n");
	fflush($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	return true;
}

$kind = isset($_POST['kind']) ? $_POST['kind'] : '';
$path = proxy_list_path($kind);
if($path === '')
{
	echo json_encode(array('ok' => false, 'err' => 'Unknown list'));
	exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : 'load';
$label = ($kind === 'socks4') ? 'SOCKS4' : 'SOCKS5';

if($action === 'load')
{
	$text = is_file($path) ? (string)@file_get_contents($path) : '';
	$lines = proxy_normalize($text);
	echo json_encode(array(
		'ok' => true,
		'count' => count($lines),
		'text' => implode("\n", $lines).(count($lines) ? "\n" : ''),
		'msg' => count($lines).' '.$label.' proxies'
	));
	exit;
}

$raw = '';
if($action === 'upload')
{
	if(empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name']))
	{
		echo json_encode(array('ok' => false, 'err' => 'No file'));
		exit;
	}
	$raw = (string)@file_get_contents($_FILES['file']['tmp_name']);
}
else
{
	$raw = isset($_POST['text']) ? (string)$_POST['text'] : '';
}

$lines = proxy_normalize($raw);
if(!proxy_write($path, $lines))
{
	echo json_encode(array('ok' => false, 'err' => 'Could not save the list on this computer'));
	exit;
}

echo json_encode(array(
	'ok' => true,
	'count' => count($lines),
	'text' => implode("\n", $lines).(count($lines) ? "\n" : ''),
	'msg' => 'Saved '.count($lines).' '.$label.' proxies on this computer'
));
