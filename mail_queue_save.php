<?php
/**
 * Dashboard: manage validmails.txt (paste or upload). Never auto-deletes lines.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if($_SERVER['REQUEST_METHOD'] !== 'POST')
{
	echo json_encode(array('ok' => false, 'err' => 'POST required'));
	exit;
}

/**
 * Normalize emails. Returns unique list by default.
 * Pass $stats by-ref to get raw/unique/duplicate counts.
 */
function mq_normalize_list($raw, &$stats = null)
{
	if(!is_string($raw)) $raw = '';
	$out = array();
	$seen = array();
	$rawEmails = 0;
	$dups = 0;
	$bad = 0;
	foreach(preg_split("/\r\n|\r|\n/", $raw) as $line)
	{
		// CSV-ish: take first column if it looks like email
		$line = trim($line);
		if($line === '') continue;
		if(strpos($line, ',') !== false)
		{
			$parts = str_getcsv($line);
			$line = isset($parts[0]) ? trim($parts[0]) : $line;
		}
		$e = strtolower(trim($line, " \t\"'<>"));
		if($e === '' || strpos($e, '@') === false) { $bad++; continue; }
		$rawEmails++;
		if(isset($seen[$e])) { $dups++; continue; }
		$seen[$e] = true;
		$out[] = $e;
	}
	if(is_array($stats) || $stats === null)
	{
		$stats = array(
			'raw_emails' => $rawEmails,
			'unique' => count($out),
			'duplicates' => $dups,
			'invalid' => $bad
		);
	}
	return $out;
}

function mq_write_lines($path, $emails)
{
	$fh = @fopen($path, 'c+');
	if(!$fh) return false;
	flock($fh, LOCK_EX);
	ftruncate($fh, 0);
	rewind($fh);
	foreach($emails as $e) fwrite($fh, $e."\n");
	fflush($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	return true;
}

function mq_progress_snapshot($list, $donePath, $progPath, $phase, $validName, $doneName)
{
	$doneList = mq_normalize_list(is_file($donePath) ? (string)@file_get_contents($donePath) : '');
	$doneSet = array();
	foreach($doneList as $e) $doneSet[$e] = true;
	$pending = 0;
	$resume = null;
	$line = 0;
	foreach($list as $e)
	{
		$line++;
		if(isset($doneSet[$e])) continue;
		$pending++;
		if($resume === null) $resume = $line;
	}
	@file_put_contents($progPath, json_encode(array(
		'updated_at' => date('c'),
		'phase' => $phase,
		'valid_file' => $validName,
		'done_file' => $doneName,
		'valid_total' => count($list),
		'done_total' => count($doneList),
		'pending_total' => $pending,
		'resume_line_in_validmails' => $resume,
		'note' => $validName.' updated from dashboard. Mailer never auto-deletes from this file.'
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	return array(
		'valid' => count($list),
		'done' => count($doneList),
		'pending' => $pending,
		'resume_line' => $resume
	);
}

$lane = (isset($_POST['lane']) && $_POST['lane'] === 'smtp') ? 'smtp' : 'api';
if($lane === 'smtp')
{
	$validPath = __DIR__.DIRECTORY_SEPARATOR.'validmails_smtp.txt';
	$donePath = __DIR__.DIRECTORY_SEPARATOR.'mailssenddone_smtp.txt';
	$progPath = __DIR__.DIRECTORY_SEPARATOR.'mail_queue_progress_smtp.json';
	$validName = 'validmails_smtp.txt';
	$doneName = 'mailssenddone_smtp.txt';
	$listLabel = 'Normal SMTP list';
}
else
{
	$validPath = __DIR__.DIRECTORY_SEPARATOR.'validmails.txt';
	$donePath = __DIR__.DIRECTORY_SEPARATOR.'mailssenddone.txt';
	$progPath = __DIR__.DIRECTORY_SEPARATOR.'mail_queue_progress.json';
	$validName = 'validmails.txt';
	$doneName = 'mailssenddone.txt';
	$listLabel = 'Brevo campaign list';
}

$action = isset($_POST['action']) ? trim($_POST['action']) : 'save_valid';

if($action === 'load_valid')
{
	$text = is_file($validPath) ? (string)@file_get_contents($validPath) : '';
	$stats = array();
	$list = mq_normalize_list($text, $stats);
	$msg = 'Loaded '.$stats['unique'].' unique';
	if($stats['raw_emails'] > $stats['unique'])
		$msg .= ' from '.$stats['raw_emails'].' lines ('.$stats['duplicates'].' duplicates skipped)';
	echo json_encode(array(
		'ok' => true,
		'text' => implode("\n", $list).(count($list) ? "\n" : ''),
		'count' => count($list),
		'raw_emails' => $stats['raw_emails'],
		'duplicates' => $stats['duplicates'],
		'invalid' => $stats['invalid'],
		'msg' => $msg
	));
	exit;
}

if($action === 'load_done')
{
	$text = is_file($donePath) ? (string)@file_get_contents($donePath) : '';
	$list = mq_normalize_list($text);
	echo json_encode(array(
		'ok' => true,
		'text' => implode("\n", $list).(count($list) ? "\n" : ''),
		'count' => count($list)
	));
	exit;
}

if($action === 'clear_done')
{
	@file_put_contents($donePath, '');
	@file_put_contents($progPath, json_encode(array(
		'updated_at' => date('c'),
		'phase' => 'done_cleared',
		'note' => 'mailssenddone.txt cleared from dashboard — next Send starts from top of validmails.txt'
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	echo json_encode(array('ok' => true, 'done' => 0, 'msg' => $doneName.' cleared'));
	exit;
}

if($action === 'save_valid' || $action === 'upload_valid')
{
	$raw = isset($_POST['text']) ? $_POST['text'] : '';

	if(!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']))
	{
		$fn = isset($_FILES['file']['name']) ? strtolower($_FILES['file']['name']) : '';
		$okExt = preg_match('/\.(txt|csv|list|log|emails?)$/i', $fn) || $fn === '';
		if(!$okExt)
		{
			echo json_encode(array('ok' => false, 'err' => 'Use a .txt / .csv / .list file'));
			exit;
		}
		if(!empty($_FILES['file']['error']))
		{
			echo json_encode(array('ok' => false, 'err' => 'Upload error code '.intval($_FILES['file']['error'])));
			exit;
		}
		$size = isset($_FILES['file']['size']) ? intval($_FILES['file']['size']) : 0;
		if($size > 20 * 1024 * 1024)
		{
			echo json_encode(array('ok' => false, 'err' => 'File too large (max 20MB)'));
			exit;
		}
		$fileText = @file_get_contents($_FILES['file']['tmp_name']);
		if($fileText === false)
		{
			echo json_encode(array('ok' => false, 'err' => 'Could not read uploaded file'));
			exit;
		}
		// If BOM
		if(substr($fileText, 0, 3) === "\xEF\xBB\xBF") $fileText = substr($fileText, 3);
		$raw = $fileText;
	}

	$list = mq_normalize_list($raw);
	if(!mq_write_lines($validPath, $list))
	{
		echo json_encode(array('ok' => false, 'err' => 'Could not write '.$validName));
		exit;
	}

	$stats = mq_progress_snapshot($list, $donePath, $progPath, $action === 'upload_valid' ? 'valid_uploaded' : 'valid_saved', $validName, $doneName);
	$src = ($action === 'upload_valid' || !empty($_FILES['file'])) ? 'upload' : 'paste';
	echo json_encode(array(
		'ok' => true,
		'valid' => $stats['valid'],
		'done' => $stats['done'],
		'pending' => $stats['pending'],
		'resume_line' => $stats['resume_line'],
		'text' => implode("\n", $list).(count($list) ? "\n" : ''),
		'msg' => 'Saved '.count($list).' emails to '.$listLabel.' ('.$src.')'
	));
	exit;
}

echo json_encode(array('ok' => false, 'err' => 'Unknown action'));
