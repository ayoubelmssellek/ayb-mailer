<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mq_load($path, &$stats = null)
{
	$stats = array('raw_emails' => 0, 'unique' => 0, 'duplicates' => 0, 'invalid' => 0);
	if(!is_file($path)) return array();
	$raw = @file($path, FILE_IGNORE_NEW_LINES);
	if(!is_array($raw)) return array();
	$out = array();
	$seen = array();
	foreach($raw as $line)
	{
		$e = strtolower(trim($line));
		if($e === '') continue;
		if(strpos($e, '@') === false) { $stats['invalid']++; continue; }
		$stats['raw_emails']++;
		if(isset($seen[$e])) { $stats['duplicates']++; continue; }
		$seen[$e] = true;
		$out[] = $e;
	}
	$stats['unique'] = count($out);
	return $out;
}

$lane = (isset($_GET['lane']) && $_GET['lane'] === 'smtp') ? 'smtp' : 'api';
if($lane === 'smtp')
{
	$validPath = __DIR__.DIRECTORY_SEPARATOR.'validmails_smtp.txt';
	$donePath = __DIR__.DIRECTORY_SEPARATOR.'mailssenddone_smtp.txt';
	$progPath = __DIR__.DIRECTORY_SEPARATOR.'mail_queue_progress_smtp.json';
	$validName = 'validmails_smtp.txt';
	$doneName = 'mailssenddone_smtp.txt';
}
else
{
	$validPath = __DIR__.DIRECTORY_SEPARATOR.'validmails.txt';
	$donePath = __DIR__.DIRECTORY_SEPARATOR.'mailssenddone.txt';
	$progPath = __DIR__.DIRECTORY_SEPARATOR.'mail_queue_progress.json';
	$validName = 'validmails.txt';
	$doneName = 'mailssenddone.txt';
}

$validStats = array();
$valid = mq_load($validPath, $validStats);
$doneStats = array();
$doneList = mq_load($donePath, $doneStats);
$done = array();
foreach($doneList as $e) $done[$e] = true;

$pending = 0;
$resume = null;
$line = 0;
$nextEmail = null;
foreach($valid as $e)
{
	$line++;
	if(isset($done[$e])) continue;
	$pending++;
	if($resume === null)
	{
		$resume = $line;
		$nextEmail = $e;
	}
}

$progress = null;
if(is_file($progPath))
{
	$progress = json_decode(@file_get_contents($progPath), true);
	if(!is_array($progress)) $progress = null;
}

echo json_encode(array(
	'ok' => true,
	'valid' => count($valid),
	'valid_raw' => $validStats['raw_emails'],
	'valid_duplicates' => $validStats['duplicates'],
	'done' => count($doneList),
	'pending' => $pending,
	'resume_line' => $resume,
	'next_email' => $nextEmail,
	'valid_file' => $validName,
	'done_file' => $doneName,
	'progress_file' => basename($progPath),
	'progress' => $progress
));
