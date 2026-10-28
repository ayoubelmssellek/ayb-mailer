<?php
/**
 * Read-only live snapshot for the send dashboard Refresh button.
 * Never stops send, never writes flags — display only.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__;

function dash_live_load_emails($path)
{
	if(!is_file($path)) return array('unique' => 0, 'raw' => 0);
	$raw = @file($path, FILE_IGNORE_NEW_LINES);
	if(!is_array($raw)) return array('unique' => 0, 'raw' => 0);
	$seen = array();
	$nRaw = 0;
	foreach($raw as $line)
	{
		$e = strtolower(trim($line));
		if($e === '' || strpos($e, '@') === false) continue;
		$nRaw++;
		$seen[$e] = true;
	}
	return array('unique' => count($seen), 'raw' => $nRaw);
}

$running = is_file($dir.DIRECTORY_SEPARATOR.'send_running.flag');
$stopFlag = is_file($dir.DIRECTORY_SEPARATOR.'stop_send.flag');

require_once $dir.DIRECTORY_SEPARATOR.'queue_country.php';
$laneFile = $dir.DIRECTORY_SEPARATOR.'send_queue_lane.txt';
$lane = (is_file($laneFile) && trim((string)@file_get_contents($laneFile)) === 'smtp') ? 'smtp' : 'api';
$countryFile = $dir.DIRECTORY_SEPARATOR.'send_country.txt';
$country = queue_country_code(is_file($countryFile) ? (string)@file_get_contents($countryFile) : 'pt');
$names = queue_country_files($lane, $country);
$validName = $names['valid'];
$doneName = $names['done'];

$valid = dash_live_load_emails($dir.DIRECTORY_SEPARATOR.$validName);
$done = dash_live_load_emails($dir.DIRECTORY_SEPARATOR.$doneName);

$doneSet = array();
if(is_file($dir.DIRECTORY_SEPARATOR.$doneName))
{
	foreach(@file($dir.DIRECTORY_SEPARATOR.$doneName, FILE_IGNORE_NEW_LINES) ?: array() as $line)
	{
		$e = strtolower(trim($line));
		if($e !== '' && strpos($e, '@') !== false) $doneSet[$e] = true;
	}
}
$pending = 0;
$resume = null;
$line = 0;
$next = null;
if(is_file($dir.DIRECTORY_SEPARATOR.$validName))
{
	$seenV = array();
	foreach(@file($dir.DIRECTORY_SEPARATOR.$validName, FILE_IGNORE_NEW_LINES) ?: array() as $row)
	{
		$e = strtolower(trim($row));
		if($e === '' || strpos($e, '@') === false) continue;
		if(isset($seenV[$e])) continue;
		$seenV[$e] = true;
		$line++;
		if(isset($doneSet[$e])) continue;
		$pending++;
		if($resume === null) { $resume = $line; $next = $e; }
	}
}

$snapshot = null;
$snapPath = $dir.DIRECTORY_SEPARATOR.'dash_live_state.json';
if(is_file($snapPath))
{
	$snapshot = json_decode(@file_get_contents($snapPath), true);
	if(!is_array($snapshot)) $snapshot = null;
}

$filter = null;
$filtPath = $dir.DIRECTORY_SEPARATOR.'socks5_filter_status.json';
if(is_file($filtPath))
{
	$filter = json_decode(@file_get_contents($filtPath), true);
	if(!is_array($filter)) $filter = null;
}

$socksOk = 0;
$okPath = $dir.DIRECTORY_SEPARATOR.'socks5_ok.txt';
if(is_file($okPath))
{
	$socksOk = count(array_filter(array_map('trim', @file($okPath, FILE_IGNORE_NEW_LINES) ?: array()), 'strlen'));
}

echo json_encode(array(
	'ok' => true,
	'ts' => date('c'),
	'running' => $running,
	'stop_flag' => $stopFlag,
	'queue' => array(
		'valid' => $valid['unique'],
		'valid_raw' => $valid['raw'],
		'done' => $done['unique'],
		'pending' => $pending,
		'resume_line' => $resume,
		'next_email' => $next
	),
	'socks_ok' => $socksOk,
	'filter' => $filter,
	'snapshot' => $snapshot
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
