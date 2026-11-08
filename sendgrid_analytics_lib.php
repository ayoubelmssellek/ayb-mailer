<?php
/**
 * SendGrid analytics — local SQLite store + API sync helpers.
 *
 * Storage: data/sendgrid_analytics.sqlite (local file DB; this project has no MySQL).
 * No Event Webhook. All data is pulled on demand via SendGrid REST APIs.
 *
 * Endpoints used (when permitted by the account):
 *   GET /v3/stats
 *   GET /v3/messages  (Email Activity Feed — retention ~30 days; max 1000/query; ~6 req/min)
 *   GET /v3/suppression/bounces|spam_reports|blocks|invalid_emails|unsubscribes
 *   GET /v3/asm/groups
 *   GET /v3/user/credits
 *
 * Not real-time: sync is local polling initiated by the app.
 */
@ini_set('display_errors', '0');

if (!defined('SG_ROOT')) define('SG_ROOT', __DIR__);
if (!defined('SG_DATA_DIR')) define('SG_DATA_DIR', SG_ROOT.DIRECTORY_SEPARATOR.'data');
if (!defined('SG_DB_PATH')) define('SG_DB_PATH', SG_DATA_DIR.DIRECTORY_SEPARATOR.'sendgrid_analytics.sqlite');

require_once __DIR__.DIRECTORY_SEPARATOR.'sendgrid_mail.php';

function sg_ensure_dirs()
{
	if (!is_dir(SG_DATA_DIR)) @mkdir(SG_DATA_DIR, 0755, true);
}

function sg_db()
{
	static $pdo = null;
	if ($pdo instanceof PDO) return $pdo;
	sg_ensure_dirs();
	$pdo = new PDO('sqlite:'.SG_DB_PATH, null, null, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
	));
	$pdo->exec('PRAGMA journal_mode=WAL');
	$pdo->exec('PRAGMA foreign_keys=ON');
	sg_db_migrate($pdo);
	return $pdo;
}

function sg_db_migrate(PDO $pdo)
{
	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_stats_daily (
		stat_date TEXT PRIMARY KEY,
		blocks INTEGER NOT NULL DEFAULT 0,
		bounce_drops INTEGER NOT NULL DEFAULT 0,
		bounces INTEGER NOT NULL DEFAULT 0,
		clicks INTEGER NOT NULL DEFAULT 0,
		deferred INTEGER NOT NULL DEFAULT 0,
		delivered INTEGER NOT NULL DEFAULT 0,
		invalid_emails INTEGER NOT NULL DEFAULT 0,
		opens INTEGER NOT NULL DEFAULT 0,
		processed INTEGER NOT NULL DEFAULT 0,
		requests INTEGER NOT NULL DEFAULT 0,
		spam_report_drops INTEGER NOT NULL DEFAULT 0,
		spam_reports INTEGER NOT NULL DEFAULT 0,
		unique_clicks INTEGER NOT NULL DEFAULT 0,
		unique_opens INTEGER NOT NULL DEFAULT 0,
		unsubscribe_drops INTEGER NOT NULL DEFAULT 0,
		unsubscribes INTEGER NOT NULL DEFAULT 0,
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_messages (
		msg_id TEXT PRIMARY KEY,
		to_email TEXT NOT NULL DEFAULT '',
		from_email TEXT NOT NULL DEFAULT '',
		subject TEXT NOT NULL DEFAULT '',
		status TEXT NOT NULL DEFAULT '',
		opens_count INTEGER NOT NULL DEFAULT 0,
		clicks_count INTEGER NOT NULL DEFAULT 0,
		last_event_time TEXT NOT NULL DEFAULT '',
		raw_json TEXT NOT NULL DEFAULT '',
		source TEXT NOT NULL DEFAULT 'api',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sg_messages_email ON sg_messages(to_email)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sg_messages_status ON sg_messages(status)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sg_messages_event ON sg_messages(last_event_time)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_suppressions (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		suppression_type TEXT NOT NULL,
		email TEXT NOT NULL,
		created TEXT NOT NULL DEFAULT '',
		reason TEXT NOT NULL DEFAULT '',
		status TEXT NOT NULL DEFAULT '',
		group_id TEXT NOT NULL DEFAULT '',
		group_name TEXT NOT NULL DEFAULT '',
		raw_json TEXT NOT NULL DEFAULT '',
		UNIQUE(suppression_type, email, created, group_id)
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sg_supp_type ON sg_suppressions(suppression_type)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sg_supp_email ON sg_suppressions(email)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_asm_groups (
		group_id TEXT PRIMARY KEY,
		name TEXT NOT NULL DEFAULT '',
		description TEXT NOT NULL DEFAULT '',
		is_default INTEGER NOT NULL DEFAULT 0,
		unsubscribes INTEGER NOT NULL DEFAULT 0,
		raw_json TEXT NOT NULL DEFAULT '',
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_capabilities (
		endpoint TEXT PRIMARY KEY,
		available INTEGER NOT NULL DEFAULT 0,
		http_code INTEGER NOT NULL DEFAULT 0,
		note TEXT NOT NULL DEFAULT '',
		checked_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_sync_meta (
		meta_key TEXT PRIMARY KEY,
		meta_value TEXT NOT NULL DEFAULT '',
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_sync_runs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		status TEXT NOT NULL DEFAULT 'running',
		start_date TEXT NOT NULL DEFAULT '',
		end_date TEXT NOT NULL DEFAULT '',
		stats_upserted INTEGER NOT NULL DEFAULT 0,
		messages_upserted INTEGER NOT NULL DEFAULT 0,
		suppressions_upserted INTEGER NOT NULL DEFAULT 0,
		message TEXT NOT NULL DEFAULT '',
		started_at TEXT NOT NULL DEFAULT (datetime('now')),
		finished_at TEXT NOT NULL DEFAULT ''
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS sg_local_sends (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		msg_id TEXT NOT NULL DEFAULT '',
		to_email TEXT NOT NULL,
		subject TEXT NOT NULL DEFAULT '',
		from_email TEXT NOT NULL DEFAULT '',
		http_status INTEGER NOT NULL DEFAULT 0,
		accepted_at TEXT NOT NULL DEFAULT (datetime('now')),
		UNIQUE(msg_id, to_email, accepted_at)
	)");
}

function sg_meta_get($key, $default = '')
{
	$st = sg_db()->prepare('SELECT meta_value FROM sg_sync_meta WHERE meta_key = ?');
	$st->execute(array($key));
	$row = $st->fetch();
	return $row ? (string)$row['meta_value'] : $default;
}

function sg_meta_set($key, $value)
{
	$st = sg_db()->prepare('INSERT INTO sg_sync_meta(meta_key, meta_value, updated_at) VALUES(?,?,datetime(\'now\'))
		ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value, updated_at=datetime(\'now\')');
	$st->execute(array($key, (string)$value));
}

function sg_capability_set($endpoint, $available, $httpCode, $note = '')
{
	$st = sg_db()->prepare('INSERT INTO sg_capabilities(endpoint, available, http_code, note, checked_at)
		VALUES(?,?,?,?,datetime(\'now\'))
		ON CONFLICT(endpoint) DO UPDATE SET available=excluded.available, http_code=excluded.http_code,
		note=excluded.note, checked_at=datetime(\'now\')');
	$st->execute(array($endpoint, $available ? 1 : 0, intval($httpCode), (string)$note));
}

function sg_capabilities()
{
	$rows = sg_db()->query('SELECT * FROM sg_capabilities ORDER BY endpoint')->fetchAll();
	$out = array();
	foreach ($rows as $r) {
		$out[$r['endpoint']] = array(
			'available' => !empty($r['available']),
			'http_code' => intval($r['http_code']),
			'note' => $r['note'],
			'checked_at' => $r['checked_at']
		);
	}
	return $out;
}

/**
 * Authenticated GET to SendGrid. Never logs the Authorization header.
 * Retries temporary 429/5xx with backoff. Does not retry permanent 4xx (except 429).
 */
function sg_api_get($path, array $query = array(), $timeout = 45, $retries = 3)
{
	$cfg = sendgrid_config();
	$out = array('ok' => false, 'http' => 0, 'body' => '', 'json' => null, 'err' => '', 'headers' => '');
	if ($cfg['api_key'] === '') {
		$out['err'] = 'Missing SENDGRID_API_KEY';
		return $out;
	}
	$url = 'https://api.sendgrid.com'.$path;
	if ($query) $url .= (strpos($url, '?') === false ? '?' : '&').http_build_query($query);

	$attempt = 0;
	while ($attempt <= $retries) {
		$attempt++;
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$cfg['api_key'],
				'Accept: application/json',
				'Content-Type: application/json'
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => true
		));
		$raw = curl_exec($ch);
		$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$hs = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
		$cerr = curl_error($ch);
		curl_close($ch);
		if ($raw === false || $cerr !== '') {
			$out['err'] = 'curl failed';
			sendgrid_log('error', 'analytics GET transport error', array('path' => $path, 'detail' => $cerr));
			if ($attempt <= $retries) { usleep(400000 * $attempt); continue; }
			return $out;
		}
		$out['http'] = $code;
		$out['headers'] = substr($raw, 0, $hs);
		$out['body'] = substr($raw, $hs);
		$out['json'] = json_decode($out['body'], true);
		if ($code >= 200 && $code < 300) {
			$out['ok'] = true;
			return $out;
		}
		if ($code === 429 || ($code >= 500 && $code < 600)) {
			sendgrid_log('warn', 'analytics GET retryable', array('path' => $path, 'http' => $code, 'attempt' => $attempt));
			if ($attempt <= $retries) { usleep(700000 * $attempt); continue; }
		}
		$msg = '';
		if (is_array($out['json'])) {
			if (!empty($out['json']['errors'][0]['message'])) $msg = $out['json']['errors'][0]['message'];
			else if (!empty($out['json']['message'])) $msg = $out['json']['message'];
		}
		$out['err'] = 'HTTP '.$code.($msg !== '' ? ' — '.$msg : '');
		sendgrid_log('error', 'analytics GET failed', array('path' => $path, 'http' => $code, 'err' => $out['err']));
		return $out;
	}
	return $out;
}

function sg_upsert_stats_day($date, array $m)
{
	$st = sg_db()->prepare('INSERT INTO sg_stats_daily(
		stat_date, blocks, bounce_drops, bounces, clicks, deferred, delivered, invalid_emails,
		opens, processed, requests, spam_report_drops, spam_reports, unique_clicks, unique_opens,
		unsubscribe_drops, unsubscribes, updated_at
	) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))
	ON CONFLICT(stat_date) DO UPDATE SET
		blocks=excluded.blocks, bounce_drops=excluded.bounce_drops, bounces=excluded.bounces,
		clicks=excluded.clicks, deferred=excluded.deferred, delivered=excluded.delivered,
		invalid_emails=excluded.invalid_emails, opens=excluded.opens, processed=excluded.processed,
		requests=excluded.requests, spam_report_drops=excluded.spam_report_drops,
		spam_reports=excluded.spam_reports, unique_clicks=excluded.unique_clicks,
		unique_opens=excluded.unique_opens, unsubscribe_drops=excluded.unsubscribe_drops,
		unsubscribes=excluded.unsubscribes, updated_at=datetime(\'now\')');
	$st->execute(array(
		$date,
		intval(isset($m['blocks']) ? $m['blocks'] : 0),
		intval(isset($m['bounce_drops']) ? $m['bounce_drops'] : 0),
		intval(isset($m['bounces']) ? $m['bounces'] : 0),
		intval(isset($m['clicks']) ? $m['clicks'] : 0),
		intval(isset($m['deferred']) ? $m['deferred'] : 0),
		intval(isset($m['delivered']) ? $m['delivered'] : 0),
		intval(isset($m['invalid_emails']) ? $m['invalid_emails'] : 0),
		intval(isset($m['opens']) ? $m['opens'] : 0),
		intval(isset($m['processed']) ? $m['processed'] : 0),
		intval(isset($m['requests']) ? $m['requests'] : 0),
		intval(isset($m['spam_report_drops']) ? $m['spam_report_drops'] : 0),
		intval(isset($m['spam_reports']) ? $m['spam_reports'] : 0),
		intval(isset($m['unique_clicks']) ? $m['unique_clicks'] : 0),
		intval(isset($m['unique_opens']) ? $m['unique_opens'] : 0),
		intval(isset($m['unsubscribe_drops']) ? $m['unsubscribe_drops'] : 0),
		intval(isset($m['unsubscribes']) ? $m['unsubscribes'] : 0)
	));
}

function sg_upsert_message(array $msg)
{
	$id = isset($msg['msg_id']) ? trim((string)$msg['msg_id']) : '';
	if ($id === '') return false;
	$st = sg_db()->prepare('INSERT INTO sg_messages(msg_id, to_email, from_email, subject, status, opens_count, clicks_count, last_event_time, raw_json, source, updated_at)
		VALUES(?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))
		ON CONFLICT(msg_id) DO UPDATE SET
			to_email=excluded.to_email, from_email=excluded.from_email, subject=excluded.subject,
			status=excluded.status, opens_count=excluded.opens_count, clicks_count=excluded.clicks_count,
			last_event_time=excluded.last_event_time, raw_json=excluded.raw_json, source=excluded.source,
			updated_at=datetime(\'now\')');
	$st->execute(array(
		$id,
		isset($msg['to_email']) ? $msg['to_email'] : '',
		isset($msg['from_email']) ? $msg['from_email'] : '',
		isset($msg['subject']) ? $msg['subject'] : '',
		isset($msg['status']) ? $msg['status'] : '',
		intval(isset($msg['opens_count']) ? $msg['opens_count'] : 0),
		intval(isset($msg['clicks_count']) ? $msg['clicks_count'] : 0),
		isset($msg['last_event_time']) ? $msg['last_event_time'] : '',
		isset($msg['raw_json']) ? $msg['raw_json'] : '',
		isset($msg['source']) ? $msg['source'] : 'api'
	));
	return true;
}

function sg_upsert_suppression($type, array $row)
{
	$email = isset($row['email']) ? strtolower(trim((string)$row['email'])) : '';
	if ($email === '') return false;
	$created = isset($row['created']) ? (string)$row['created'] : (isset($row['created_at']) ? (string)$row['created_at'] : '');
	$reason = isset($row['reason']) ? (string)$row['reason'] : '';
	$status = isset($row['status']) ? (string)$row['status'] : '';
	$gid = isset($row['group_id']) ? (string)$row['group_id'] : '';
	$gname = isset($row['group_name']) ? (string)$row['group_name'] : '';
	$raw = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$st = sg_db()->prepare('INSERT INTO sg_suppressions(suppression_type, email, created, reason, status, group_id, group_name, raw_json)
		VALUES(?,?,?,?,?,?,?,?)
		ON CONFLICT(suppression_type, email, created, group_id) DO UPDATE SET
			reason=excluded.reason, status=excluded.status, group_name=excluded.group_name, raw_json=excluded.raw_json');
	$st->execute(array($type, $email, $created, $reason, $status, $gid, $gname, $raw));
	return true;
}

function sg_record_local_send($toEmail, $subject, $msgId, $fromEmail = '', $http = 202)
{
	try {
		$st = sg_db()->prepare('INSERT OR IGNORE INTO sg_local_sends(msg_id, to_email, subject, from_email, http_status, accepted_at)
			VALUES(?,?,?,?,?,datetime(\'now\'))');
		$st->execute(array((string)$msgId, strtolower(trim((string)$toEmail)), (string)$subject, (string)$fromEmail, intval($http)));
		if ($msgId !== '') {
			sg_upsert_message(array(
				'msg_id' => $msgId,
				'to_email' => strtolower(trim((string)$toEmail)),
				'from_email' => $fromEmail,
				'subject' => $subject,
				'status' => 'processed',
				'last_event_time' => gmdate('c'),
				'source' => 'local_send',
				'raw_json' => ''
			));
		}
	} catch (Exception $e) {
		sendgrid_log('error', 'local send record failed', array('message' => $e->getMessage()));
	}
}

function sg_sync_stats($startDate, $endDate)
{
	$r = sg_api_get('/v3/stats', array(
		'start_date' => $startDate,
		'end_date' => $endDate,
		'aggregated_by' => 'day'
	));
	sg_capability_set('/v3/stats', !empty($r['ok']), $r['http'], $r['ok'] ? 'Global email statistics' : $r['err']);
	if (empty($r['ok']) || !is_array($r['json'])) return array('ok' => false, 'upserted' => 0, 'err' => $r['err']);
	$n = 0;
	foreach ($r['json'] as $day) {
		if (!is_array($day) || empty($day['date'])) continue;
		$metrics = array();
		if (!empty($day['stats'][0]['metrics']) && is_array($day['stats'][0]['metrics'])) {
			$metrics = $day['stats'][0]['metrics'];
		}
		sg_upsert_stats_day($day['date'], $metrics);
		$n++;
	}
	return array('ok' => true, 'upserted' => $n, 'err' => '');
}

function sg_sync_messages($startDate, $endDate)
{
	// Email Activity: ~30 day retention, max 1000 results, ~6 req/min, no true pagination.
	$query = 'last_event_time BETWEEN TIMESTAMP "'.$startDate.'T00:00:00Z" AND TIMESTAMP "'.$endDate.'T23:59:59Z"';
	$r = sg_api_get('/v3/messages', array('limit' => 1000, 'query' => $query));
	$note = 'Email Activity Feed (≤30 days retention, max 1000/query, ~6 req/min)';
	if (empty($r['ok'])) {
		sg_capability_set('/v3/messages', false, $r['http'], $r['err'] !== '' ? $r['err'] : $note.' — unavailable');
		return array(
			'ok' => false,
			'upserted' => 0,
			'truncated' => false,
			'unavailable' => true,
			'err' => $r['err'] !== '' ? $r['err'] : 'Email Activity API unavailable'
		);
	}
	sg_capability_set('/v3/messages', true, $r['http'], $note);
	$msgs = array();
	if (is_array($r['json']) && isset($r['json']['messages']) && is_array($r['json']['messages'])) {
		$msgs = $r['json']['messages'];
	}
	$n = 0;
	foreach ($msgs as $m) {
		if (!is_array($m)) continue;
		$id = isset($m['msg_id']) ? $m['msg_id'] : (isset($m['message_id']) ? $m['message_id'] : '');
		sg_upsert_message(array(
			'msg_id' => $id,
			'to_email' => isset($m['to_email']) ? $m['to_email'] : '',
			'from_email' => isset($m['from_email']) ? $m['from_email'] : '',
			'subject' => isset($m['subject']) ? $m['subject'] : '',
			'status' => isset($m['status']) ? $m['status'] : '',
			'opens_count' => isset($m['opens_count']) ? $m['opens_count'] : 0,
			'clicks_count' => isset($m['clicks_count']) ? $m['clicks_count'] : 0,
			'last_event_time' => isset($m['last_event_time']) ? $m['last_event_time'] : '',
			'raw_json' => json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'source' => 'api'
		));
		$n++;
	}
	$truncated = ($n >= 1000);
	if ($truncated) sg_meta_set('messages_truncated_warning', '1');
	else sg_meta_set('messages_truncated_warning', '0');
	return array('ok' => true, 'upserted' => $n, 'truncated' => $truncated, 'unavailable' => false, 'err' => '');
}

function sg_sync_suppression_list($type, $path)
{
	$offset = 0;
	$limit = 500;
	$total = 0;
	$pages = 0;
	while ($pages < 40) {
		$pages++;
		$r = sg_api_get($path, array('limit' => $limit, 'offset' => $offset));
		if (empty($r['ok'])) {
			sg_capability_set($path, false, $r['http'], $r['err']);
			return array('ok' => false, 'upserted' => $total, 'err' => $r['err']);
		}
		sg_capability_set($path, true, $r['http'], 'Suppression list');
		$list = is_array($r['json']) ? $r['json'] : array();
		if (!$list) break;
		foreach ($list as $row) {
			if (!is_array($row)) continue;
			if (sg_upsert_suppression($type, $row)) $total++;
		}
		if (count($list) < $limit) break;
		$offset += $limit;
		usleep(250000); // gentle pacing
	}
	return array('ok' => true, 'upserted' => $total, 'err' => '');
}

function sg_sync_asm_groups()
{
	$r = sg_api_get('/v3/asm/groups');
	sg_capability_set('/v3/asm/groups', !empty($r['ok']), $r['http'], $r['ok'] ? 'ASM unsubscribe groups' : $r['err']);
	if (empty($r['ok']) || !is_array($r['json'])) {
		return array('ok' => false, 'upserted' => 0, 'err' => $r['err'], 'note' => 'Group unsubscribe event streams are webhook-oriented; API exposes group definitions when permitted.');
	}
	$n = 0;
	$st = sg_db()->prepare('INSERT INTO sg_asm_groups(group_id, name, description, is_default, unsubscribes, raw_json, updated_at)
		VALUES(?,?,?,?,?,?,datetime(\'now\'))
		ON CONFLICT(group_id) DO UPDATE SET name=excluded.name, description=excluded.description,
		is_default=excluded.is_default, unsubscribes=excluded.unsubscribes, raw_json=excluded.raw_json, updated_at=datetime(\'now\')');
	foreach ($r['json'] as $g) {
		if (!is_array($g) || !isset($g['id'])) continue;
		$st->execute(array(
			(string)$g['id'],
			isset($g['name']) ? $g['name'] : '',
			isset($g['description']) ? $g['description'] : '',
			!empty($g['is_default']) ? 1 : 0,
			intval(isset($g['unsubscribes']) ? $g['unsubscribes'] : 0),
			json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		));
		$n++;
	}
	return array('ok' => true, 'upserted' => $n, 'err' => '', 'note' => 'Group definitions synced; per-event group unsubscribe/resubscribe history is not provided as a full pollable event feed.');
}

function sg_sync_credits()
{
	$r = sg_api_get('/v3/user/credits');
	sg_capability_set('/v3/user/credits', !empty($r['ok']), $r['http'], $r['ok'] ? 'Account credits' : $r['err']);
	if (!empty($r['ok']) && is_array($r['json'])) {
		sg_meta_set('credits_json', json_encode($r['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		return array('ok' => true, 'credits' => $r['json'], 'err' => '');
	}
	return array('ok' => false, 'credits' => null, 'err' => $r['err'], 'unavailable' => true);
}

/**
 * Full local sync (polling). Not real-time.
 */
function sg_sync_all($startDate, $endDate)
{
	$pdo = sg_db();
	$st = $pdo->prepare('INSERT INTO sg_sync_runs(status, start_date, end_date, message) VALUES(?,?,?,?)');
	$st->execute(array('running', $startDate, $endDate, 'started'));
	$runId = intval($pdo->lastInsertId());

	$result = array(
		'ok' => true,
		'realtime' => false,
		'start_date' => $startDate,
		'end_date' => $endDate,
		'stats' => null,
		'messages' => null,
		'suppressions' => array(),
		'asm_groups' => null,
		'credits' => null,
		'limitations' => array(
			'Email Activity (/v3/messages) retains roughly 30 days and returns at most 1000 messages per query with no full pagination.',
			'Stats (/v3/stats) are aggregates — not per-recipient event streams.',
			'Group unsubscribe/resubscribe event streams are not fully available via polling; ASM group definitions may sync.',
			'This sync is on-demand polling, not real-time webhook delivery.',
			'Storage is local SQLite (data/sendgrid_analytics.sqlite). This project has no MySQL connection.'
		)
	);

	$result['stats'] = sg_sync_stats($startDate, $endDate);
	$result['messages'] = sg_sync_messages($startDate, $endDate);
	usleep(200000);
	foreach (array(
		'bounce' => '/v3/suppression/bounces',
		'spamreport' => '/v3/suppression/spam_reports',
		'block' => '/v3/suppression/blocks',
		'invalid' => '/v3/suppression/invalid_emails',
		'unsubscribe' => '/v3/suppression/unsubscribes'
	) as $type => $path) {
		$result['suppressions'][$type] = sg_sync_suppression_list($type, $path);
		usleep(200000);
	}
	$result['asm_groups'] = sg_sync_asm_groups();
	$result['credits'] = sg_sync_credits();

	$statsN = isset($result['stats']['upserted']) ? intval($result['stats']['upserted']) : 0;
	$msgN = isset($result['messages']['upserted']) ? intval($result['messages']['upserted']) : 0;
	$supN = 0;
	foreach ($result['suppressions'] as $s) $supN += isset($s['upserted']) ? intval($s['upserted']) : 0;

	$ok = !empty($result['stats']['ok']); // stats are required baseline
	$msg = $ok ? 'sync complete (polling)' : ('stats sync failed: '.(isset($result['stats']['err']) ? $result['stats']['err'] : ''));
	$upd = $pdo->prepare('UPDATE sg_sync_runs SET status=?, stats_upserted=?, messages_upserted=?, suppressions_upserted=?, message=?, finished_at=datetime(\'now\') WHERE id=?');
	$upd->execute(array($ok ? 'ok' : 'failed', $statsN, $msgN, $supN, $msg, $runId));
	sg_meta_set('last_sync_at', gmdate('c'));
	sg_meta_set('last_sync_status', $ok ? 'ok' : 'failed');
	sg_meta_set('last_sync_range', $startDate.'..'.$endDate);
	$result['ok'] = $ok;
	$result['run_id'] = $runId;
	$result['last_sync_at'] = sg_meta_get('last_sync_at');
	return $result;
}

function sg_stats_sum($startDate, $endDate)
{
	$st = sg_db()->prepare('SELECT
		COALESCE(SUM(requests),0) AS requests,
		COALESCE(SUM(processed),0) AS processed,
		COALESCE(SUM(delivered),0) AS delivered,
		COALESCE(SUM(deferred),0) AS deferred,
		COALESCE(SUM(bounces),0) AS bounces,
		COALESCE(SUM(bounce_drops),0) AS bounce_drops,
		COALESCE(SUM(blocks),0) AS blocks,
		COALESCE(SUM(opens),0) AS opens,
		COALESCE(SUM(unique_opens),0) AS unique_opens,
		COALESCE(SUM(clicks),0) AS clicks,
		COALESCE(SUM(unique_clicks),0) AS unique_clicks,
		COALESCE(SUM(spam_reports),0) AS spam_reports,
		COALESCE(SUM(spam_report_drops),0) AS spam_report_drops,
		COALESCE(SUM(unsubscribes),0) AS unsubscribes,
		COALESCE(SUM(unsubscribe_drops),0) AS unsubscribe_drops,
		COALESCE(SUM(invalid_emails),0) AS invalid_emails,
		COUNT(*) AS days
		FROM sg_stats_daily WHERE stat_date >= ? AND stat_date <= ?');
	$st->execute(array($startDate, $endDate));
	$row = $st->fetch();
	return $row ? $row : array();
}

function sg_stats_series($startDate, $endDate)
{
	$st = sg_db()->prepare('SELECT * FROM sg_stats_daily WHERE stat_date >= ? AND stat_date <= ? ORDER BY stat_date');
	$st->execute(array($startDate, $endDate));
	return $st->fetchAll();
}

function sg_query_messages(array $in)
{
	$page = max(1, intval(isset($in['page']) ? $in['page'] : 1));
	$per = max(1, min(100, intval(isset($in['per_page']) ? $in['per_page'] : 50)));
	$where = array('1=1');
	$args = array();
	if (!empty($in['email'])) {
		$where[] = 'to_email LIKE ?';
		$args[] = '%'.strtolower(trim((string)$in['email'])).'%';
	}
	if (!empty($in['status']) && $in['status'] !== 'all') {
		$where[] = 'status = ?';
		$args[] = (string)$in['status'];
	}
	if (!empty($in['subject'])) {
		$where[] = 'subject LIKE ?';
		$args[] = '%'.(string)$in['subject'].'%';
	}
	$sqlWhere = implode(' AND ', $where);
	$cnt = sg_db()->prepare('SELECT COUNT(*) FROM sg_messages WHERE '.$sqlWhere);
	$cnt->execute($args);
	$total = intval($cnt->fetchColumn());
	$st = sg_db()->prepare('SELECT msg_id, to_email, from_email, subject, status, opens_count, clicks_count, last_event_time, source
		FROM sg_messages WHERE '.$sqlWhere.' ORDER BY last_event_time DESC LIMIT '.$per.' OFFSET '.(($page - 1) * $per));
	$st->execute($args);
	return array('total' => $total, 'page' => $page, 'per_page' => $per, 'rows' => $st->fetchAll());
}

function sg_suppression_counts()
{
	$rows = sg_db()->query('SELECT suppression_type, COUNT(*) AS c FROM sg_suppressions GROUP BY suppression_type')->fetchAll();
	$out = array();
	foreach ($rows as $r) $out[$r['suppression_type']] = intval($r['c']);
	return $out;
}

function sg_dashboard_payload($startDate, $endDate)
{
	$credits = null;
	$cj = sg_meta_get('credits_json', '');
	if ($cj !== '') $credits = json_decode($cj, true);
	$sum = sg_stats_sum($startDate, $endDate);
	$caps = sg_capabilities();
	$msgCap = isset($caps['/v3/messages']) ? $caps['/v3/messages'] : null;
	return array(
		'ok' => true,
		'provider' => 'sendgrid',
		'source' => 'local_sqlite_after_api_sync',
		'realtime' => false,
		'start_date' => $startDate,
		'end_date' => $endDate,
		'last_sync_at' => sg_meta_get('last_sync_at', ''),
		'last_sync_status' => sg_meta_get('last_sync_status', ''),
		'last_sync_range' => sg_meta_get('last_sync_range', ''),
		'metrics' => $sum,
		'series' => sg_stats_series($startDate, $endDate),
		'suppression_counts' => sg_suppression_counts(),
		'credits' => $credits,
		'capabilities' => $caps,
		'messages_available' => $msgCap ? !empty($msgCap['available']) : null,
		'messages_truncated' => sg_meta_get('messages_truncated_warning', '0') === '1',
		'storage' => 'sqlite:'.SG_DB_PATH,
		'note' => 'KPIs come from synced GET /v3/stats. Zero means no activity in range after a successful sync; unavailable endpoints are listed under capabilities.'
	);
}
