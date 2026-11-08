<?php
/**
 * Brevo transactional webhook receiver.
 * URL: brevo_webhook.php?token=YOUR_TOKEN[&akh=account_key_hash]
 *
 * Stores events immediately with subject when provided by Brevo.
 * Always returns 200 quickly on accepted payloads (Brevo retries on failure).
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(30);

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

header('Content-Type: text/plain; charset=utf-8');

$cfg = ba_config_load();
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '' || !hash_equals((string)$cfg['webhook_token'], $token)) {
	http_response_code(401);
	echo 'unauthorized';
	exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
	// Brevo sometimes probes with empty GET
	http_response_code(200);
	echo 'ok';
	exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
	http_response_code(400);
	echo 'invalid json';
	exit;
}

// Support single event or array of events
$events = array();
if (isset($data[0]) && is_array($data[0])) $events = $data;
else if (!empty($data['event']) || !empty($data['email']) || !empty($data['message-id'])) $events = array($data);
else {
	http_response_code(200);
	echo 'ignored';
	exit;
}

$akh = isset($_GET['akh']) ? (string)$_GET['akh'] : '';
$pdo = ba_db();
$okN = 0;
$skipN = 0;
$failN = 0;

foreach ($events as $e) {
	if (!is_array($e)) { $failN++; continue; }
	$etype = isset($e['event']) ? ba_normalize_event($e['event']) : '';
	$allowed = ba_status_filters_supported();
	// also allow uniqueOpened / loadedByProxy / request aliases
	$allowedTypes = array_keys($allowed);
	$allowedTypes[] = 'uniqueOpened';
	$allowedTypes[] = 'loadedByProxy';
	$allowedTypes[] = 'sent';
	if ($etype === '' || (!in_array($etype, $allowedTypes, true) && $etype !== 'bounced')) {
		// still store unknown email events if they look valid
		if ($etype === '') { $failN++; continue; }
	}

	$ts = '';
	if (!empty($e['ts_event'])) $ts = $e['ts_event'];
	else if (!empty($e['ts_epoch'])) $ts = $e['ts_epoch'];
	else if (!empty($e['ts'])) $ts = $e['ts'];
	else if (!empty($e['date'])) $ts = $e['date'];

	$r = ba_import_event($pdo, array(
		'account_key_hash' => $akh,
		'email' => isset($e['email']) ? $e['email'] : '',
		'event' => isset($e['event']) ? $e['event'] : '',
		'date' => $ts,
		'message-id' => isset($e['message-id']) ? $e['message-id'] : (isset($e['messageId']) ? $e['messageId'] : ''),
		'reason' => isset($e['reason']) ? $e['reason'] : '',
		'subject' => isset($e['subject']) ? $e['subject'] : '',
		'tags' => isset($e['tags']) ? $e['tags'] : (isset($e['tag']) ? $e['tag'] : ''),
		'template_id' => isset($e['template_id']) ? $e['template_id'] : (isset($e['templateId']) ? $e['templateId'] : ''),
		'link' => isset($e['link']) ? $e['link'] : '',
		'campaign_id' => isset($e['camp_id']) ? (string)$e['camp_id'] : '',
		'campaign_name' => isset($e['campaign_name']) ? (string)$e['campaign_name'] : '',
		'event_source' => 'webhook',
		'raw_payload' => json_encode($e)
	));
	if ($r === 'inserted') $okN++;
	else if ($r === 'skipped') $skipN++;
	else $failN++;
}

http_response_code(200);
echo 'ok '.$okN.'/'.count($events).' imported, '.$skipN.' skipped, '.$failN.' failed';
