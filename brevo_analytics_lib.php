<?php
/**
 * Brevo recipient-level analytics — shared library.
 *
 * Detected send path for this app: TRANSACTIONAL API
 *   POST https://api.brevo.com/v3/smtp/email
 * Aggregate stats already use:
 *   GET /v3/smtp/statistics/aggregatedReport
 *   GET /v3/smtp/statistics/reports
 *
 * Marketing Campaign APIs are not used for sending here.
 */

@ini_set('display_errors', '0');

if (!defined('BA_ROOT')) {
	define('BA_ROOT', __DIR__);
}
if (!defined('BA_DATA_DIR')) {
	define('BA_DATA_DIR', BA_ROOT.DIRECTORY_SEPARATOR.'data');
}
if (!defined('BA_DB_PATH')) {
	define('BA_DB_PATH', BA_DATA_DIR.DIRECTORY_SEPARATOR.'brevo_analytics.sqlite');
}
if (!defined('BA_CONFIG_PATH')) {
	define('BA_CONFIG_PATH', BA_DATA_DIR.DIRECTORY_SEPARATOR.'brevo_analytics_config.json');
}

/** Canonical event labels used in the UI / DB */
function ba_event_aliases()
{
	return array(
		'request' => 'sent',
		'requests' => 'sent',
		'sent' => 'sent',
		'delivered' => 'delivered',
		'hardbounce' => 'hardBounce',
		'hardbounces' => 'hardBounce',
		'hard_bounce' => 'hardBounce',
		'softbounce' => 'softBounce',
		'softbounces' => 'softBounce',
		'soft_bounce' => 'softBounce',
		'bounces' => 'bounced',
		'deferred' => 'deferred',
		'opened' => 'opened',
		'uniqueopened' => 'uniqueOpened',
		'unique_opened' => 'uniqueOpened',
		'click' => 'clicked',
		'clicks' => 'clicked',
		'clicked' => 'clicked',
		'spam' => 'spam',
		'invalid' => 'invalid',
		'blocked' => 'blocked',
		'unsubscribed' => 'unsubscribed',
		'unsubscribe' => 'unsubscribed',
		'error' => 'error',
		'loadedbyproxy' => 'loadedByProxy',
		'proxy_open' => 'loadedByProxy'
	);
}

function ba_normalize_event($event)
{
	$e = preg_replace('/\s+/', '', strtolower(trim((string)$event)));
	$map = ba_event_aliases();
	return isset($map[$e]) ? $map[$e] : trim((string)$event);
}

/** Higher = more definitive for current_status */
function ba_event_priority($event)
{
	$order = array(
		'hardBounce' => 100,
		'invalid' => 95,
		'blocked' => 90,
		'error' => 85,
		'softBounce' => 80,
		'spam' => 70,
		'unsubscribed' => 60,
		'clicked' => 50,
		'opened' => 45,
		'uniqueOpened' => 44,
		'loadedByProxy' => 40,
		'delivered' => 30,
		'deferred' => 20,
		'sent' => 10,
		'bounced' => 75
	);
	$e = ba_normalize_event($event);
	return isset($order[$e]) ? $order[$e] : 1;
}

function ba_status_filters_supported()
{
	// Transactional API / webhooks support these
	return array(
		'all' => 'All recipients',
		'delivered' => 'Delivered',
		'hardBounce' => 'Hard Bounced',
		'softBounce' => 'Soft Bounced',
		'bounced' => 'All Bounced',
		'sent' => 'Sent',
		'deferred' => 'Deferred',
		'opened' => 'Opened',
		'clicked' => 'Clicked',
		'spam' => 'Spam',
		'invalid' => 'Invalid Email',
		'blocked' => 'Blocked',
		'unsubscribed' => 'Unsubscribed',
		'error' => 'Error'
	);
}

function ba_ensure_dirs()
{
	foreach (array(BA_DATA_DIR, BA_DATA_DIR.DIRECTORY_SEPARATOR.'exports', BA_DATA_DIR.DIRECTORY_SEPARATOR.'tmp') as $d) {
		if (!is_dir($d)) @mkdir($d, 0755, true);
	}
}

function ba_config_load()
{
	ba_ensure_dirs();
	$cfg = array(
		'webhook_token' => '',
		'webhook_url' => '',
		'webhook_id' => null,
		'webhook_events' => array(),
		'public_base_url' => '',
		'last_sync' => null,
		'account_key_hashes' => array()
	);
	if (is_file(BA_CONFIG_PATH)) {
		$raw = @file_get_contents(BA_CONFIG_PATH);
		$j = json_decode((string)$raw, true);
		if (is_array($j)) $cfg = array_merge($cfg, $j);
	}
	if ($cfg['webhook_token'] === '') {
		$cfg['webhook_token'] = bin2hex(random_bytes(24));
		ba_config_save($cfg);
	}
	return $cfg;
}

function ba_config_save(array $cfg)
{
	ba_ensure_dirs();
	@file_put_contents(BA_CONFIG_PATH, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function ba_key_hash($apiKey)
{
	return substr(hash('sha256', trim((string)$apiKey)), 0, 16);
}

function ba_key_preview($apiKey)
{
	$k = trim((string)$apiKey);
	return $k === '' ? '' : (substr($k, 0, 14).'…');
}

/**
 * Parse #ACCOUNT blocks — same format as brevo_stats / index.php
 */
function ba_parse_accounts($text)
{
	$accounts = array();
	$lines = preg_split("/\\r\\n|\\r|\\n/", (string)$text);
	$cur = null;
	$flush = function() use (&$accounts, &$cur) {
		if ($cur !== null && !empty($cur['key'])) {
			if (empty($cur['limit'])) $cur['limit'] = 300;
			$accounts[] = $cur;
		}
		$cur = null;
	};
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '') continue;
		if (preg_match('/^#\s*ACCOUNT\s+(\d+)/i', $line, $m)) {
			$flush();
			$cur = array('limit' => max(1, intval($m[1])), 'key' => '', 'from' => '', 'name' => '');
			continue;
		}
		if (isset($line[0]) && $line[0] === '#') continue;
		if (preg_match('/^from\s*[:=]\s*(.+)$/i', $line, $m)) {
			if ($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			$cur['from'] = trim($m[1]);
			continue;
		}
		if (preg_match('/^name\s*[:=]\s*(.+)$/i', $line, $m)) {
			if ($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			$cur['name'] = trim($m[1]);
			continue;
		}
		if (preg_match('/^xkeysib-/i', $line) || (strlen($line) > 40 && strpos($line, ' ') === false && strpos($line, '@') === false)) {
			if ($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			if ($cur['key'] !== '') {
				$flush();
				$cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			}
			$cur['key'] = $line;
		}
	}
	$flush();
	return $accounts;
}

function ba_db()
{
	static $pdo = null;
	if ($pdo instanceof PDO) return $pdo;
	ba_ensure_dirs();
	$pdo = new PDO('sqlite:'.BA_DB_PATH, null, null, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
	));
	$pdo->exec('PRAGMA foreign_keys = ON');
	$pdo->exec('PRAGMA journal_mode = WAL');
	ba_db_migrate($pdo);
	return $pdo;
}

function ba_db_migrate(PDO $pdo)
{
	$pdo->exec("CREATE TABLE IF NOT EXISTS ba_messages (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		account_key_hash TEXT NOT NULL DEFAULT '',
		recipient_email TEXT NOT NULL,
		subject TEXT NOT NULL DEFAULT '',
		subject_available INTEGER NOT NULL DEFAULT 0,
		campaign_id TEXT NOT NULL DEFAULT '',
		campaign_name TEXT NOT NULL DEFAULT '',
		brevo_message_id TEXT NOT NULL DEFAULT '',
		template_id TEXT NOT NULL DEFAULT '',
		tags TEXT NOT NULL DEFAULT '',
		send_date TEXT NOT NULL DEFAULT '',
		provider_source TEXT NOT NULL DEFAULT 'transactional',
		current_status TEXT NOT NULL DEFAULT 'sent',
		bounce_reason TEXT NOT NULL DEFAULT '',
		event_date TEXT NOT NULL DEFAULT '',
		uuid TEXT NOT NULL DEFAULT '',
		from_email TEXT NOT NULL DEFAULT '',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS ba_events (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		message_row_id INTEGER,
		account_key_hash TEXT NOT NULL DEFAULT '',
		recipient_email TEXT NOT NULL DEFAULT '',
		brevo_message_id TEXT NOT NULL DEFAULT '',
		event_type TEXT NOT NULL,
		event_ts TEXT NOT NULL DEFAULT '',
		bounce_reason TEXT NOT NULL DEFAULT '',
		subject TEXT NOT NULL DEFAULT '',
		template_id TEXT NOT NULL DEFAULT '',
		tags TEXT NOT NULL DEFAULT '',
		link TEXT NOT NULL DEFAULT '',
		event_source TEXT NOT NULL DEFAULT '',
		event_uid TEXT NOT NULL UNIQUE,
		raw_payload TEXT NOT NULL DEFAULT '',
		imported_at TEXT NOT NULL DEFAULT (datetime('now')),
		FOREIGN KEY(message_row_id) REFERENCES ba_messages(id) ON DELETE SET NULL
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS ba_sync_runs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		account_key_hash TEXT NOT NULL DEFAULT '',
		sync_type TEXT NOT NULL,
		status TEXT NOT NULL DEFAULT 'running',
		start_date TEXT NOT NULL DEFAULT '',
		end_date TEXT NOT NULL DEFAULT '',
		total_retrieved INTEGER NOT NULL DEFAULT 0,
		new_imported INTEGER NOT NULL DEFAULT 0,
		existing_skipped INTEGER NOT NULL DEFAULT 0,
		failed INTEGER NOT NULL DEFAULT 0,
		resume_offset INTEGER NOT NULL DEFAULT 0,
		message TEXT NOT NULL DEFAULT '',
		started_at TEXT NOT NULL DEFAULT (datetime('now')),
		finished_at TEXT NOT NULL DEFAULT ''
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS ba_export_jobs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		account_key_hash TEXT NOT NULL DEFAULT '',
		process_id TEXT NOT NULL,
		export_type TEXT NOT NULL DEFAULT 'transactional',
		status TEXT NOT NULL DEFAULT 'queued',
		notify_url TEXT NOT NULL DEFAULT '',
		export_url TEXT NOT NULL DEFAULT '',
		imported_count INTEGER NOT NULL DEFAULT 0,
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now')),
		UNIQUE(account_key_hash, process_id)
	)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS ba_webhooks (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		account_key_hash TEXT NOT NULL DEFAULT '',
		brevo_webhook_id TEXT NOT NULL DEFAULT '',
		url TEXT NOT NULL DEFAULT '',
		webhook_type TEXT NOT NULL DEFAULT 'transactional',
		events_json TEXT NOT NULL DEFAULT '[]',
		status TEXT NOT NULL DEFAULT 'unknown',
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");

	$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ba_msg_uniq ON ba_messages(account_key_hash, brevo_message_id, recipient_email) WHERE brevo_message_id != ''");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_msg_email ON ba_messages(recipient_email)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_msg_status ON ba_messages(current_status)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_msg_subject ON ba_messages(subject)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_msg_send ON ba_messages(send_date)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_evt_msg ON ba_events(brevo_message_id)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_evt_email ON ba_events(recipient_email)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS ba_evt_type ON ba_events(event_type)");
}

function ba_http($method, $url, $apiKey, $body = null, $timeout = 45, $retries = 3)
{
	$attempt = 0;
	$last = array('ok' => false, 'err' => 'no attempt', 'http' => 0, 'data' => null, 'raw' => '');
	while ($attempt < $retries) {
		$attempt++;
		if (!function_exists('curl_init')) {
			return array('ok' => false, 'err' => 'PHP curl required', 'http' => 0, 'data' => null, 'raw' => '');
		}
		$ch = curl_init($url);
		$headers = array('accept: application/json', 'api-key: '.$apiKey);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => 12,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0
		);
		if ($body !== null) {
			$json = is_string($body) ? $body : json_encode($body);
			$headers[] = 'content-type: application/json';
			$opts[CURLOPT_HTTPHEADER] = $headers;
			$opts[CURLOPT_POSTFIELDS] = $json;
		}
		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$cerr = curl_error($ch);
		curl_close($ch);
		if ($raw === false || $cerr !== '') {
			$last = array('ok' => false, 'err' => $cerr !== '' ? $cerr : 'curl failed', 'http' => $code, 'data' => null, 'raw' => '');
			usleep((int)(pow(2, $attempt) * 200000));
			continue;
		}
		$data = json_decode($raw, true);
		if ($code === 429 || ($code >= 500 && $code < 600)) {
			$last = array('ok' => false, 'err' => 'HTTP '.$code, 'http' => $code, 'data' => is_array($data) ? $data : null, 'raw' => $raw);
			usleep((int)(pow(2, $attempt) * 300000));
			continue;
		}
		if ($code < 200 || $code >= 300) {
			$msg = is_array($data) && !empty($data['message']) ? $data['message'] : substr((string)$raw, 0, 200);
			return array('ok' => false, 'err' => 'HTTP '.$code.($msg ? ' — '.$msg : ''), 'http' => $code, 'data' => is_array($data) ? $data : null, 'raw' => $raw);
		}
		return array('ok' => true, 'err' => '', 'http' => $code, 'data' => is_array($data) ? $data : null, 'raw' => $raw);
	}
	return $last;
}

function ba_http_download($url, $destPath, $timeout = 120)
{
	$ch = curl_init($url);
	$fp = fopen($destPath, 'wb');
	if (!$fp) return array('ok' => false, 'err' => 'Cannot write '.$destPath);
	curl_setopt_array($ch, array(
		CURLOPT_FILE => $fp,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => $timeout,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0
	));
	$ok = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$err = curl_error($ch);
	curl_close($ch);
	fclose($fp);
	if ($ok === false || $err !== '' || $code < 200 || $code >= 300) {
		@unlink($destPath);
		return array('ok' => false, 'err' => $err !== '' ? $err : ('HTTP '.$code));
	}
	return array('ok' => true, 'err' => '');
}

function ba_tags_to_string($tags)
{
	if (is_array($tags)) return json_encode(array_values($tags), JSON_UNESCAPED_UNICODE);
	$s = trim((string)$tags);
	if ($s === '') return '';
	if ($s[0] === '[') return $s;
	return json_encode(array($s), JSON_UNESCAPED_UNICODE);
}

function ba_normalize_message_id($id)
{
	$id = trim((string)$id);
	if ($id === '') return '';
	// Webhooks often omit surrounding < >; API events usually include them
	if ($id[0] !== '<' && strpos($id, '@') !== false) $id = '<'.$id.'>';
	if (substr($id, -1) !== '>' && strpos($id, '@') !== false && $id[0] === '<') $id .= '>';
	return $id;
}

function ba_event_uid($accountHash, $messageId, $email, $eventType, $eventTs, $extra = '')
{
	$base = strtolower($accountHash.'|'.ba_normalize_message_id($messageId).'|'.strtolower(trim($email)).'|'.ba_normalize_event($eventType).'|'.$eventTs.'|'.$extra);
	return hash('sha256', $base);
}

function ba_utc_now()
{
	return gmdate('Y-m-d\TH:i:s\Z');
}

function ba_parse_ts($value)
{
	$value = trim((string)$value);
	if ($value === '') return '';
	if (ctype_digit($value)) {
		$n = intval($value);
		if ($n > 20000000000) $n = (int)floor($n / 1000); // ms
		return gmdate('Y-m-d\TH:i:s\Z', $n);
	}
	$ts = strtotime($value);
	if ($ts === false) return $value;
	return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function ba_upsert_message(PDO $pdo, array $msg)
{
	$hash = isset($msg['account_key_hash']) ? $msg['account_key_hash'] : '';
	$email = strtolower(trim(isset($msg['recipient_email']) ? $msg['recipient_email'] : ''));
	$mid = ba_normalize_message_id(isset($msg['brevo_message_id']) ? $msg['brevo_message_id'] : '');
	if ($email === '') return 0;

	$row = null;
	if ($mid !== '') {
		$st = $pdo->prepare('SELECT * FROM ba_messages WHERE account_key_hash = ? AND brevo_message_id = ? AND recipient_email = ? LIMIT 1');
		$st->execute(array($hash, $mid, $email));
		$row = $st->fetch();
	}
	if (!$row && $mid !== '') {
		// Match without account hash (cross-import)
		$st = $pdo->prepare('SELECT * FROM ba_messages WHERE brevo_message_id = ? AND recipient_email = ? LIMIT 1');
		$st->execute(array($mid, $email));
		$row = $st->fetch();
	}

	$subject = isset($msg['subject']) ? (string)$msg['subject'] : '';
	$subjectAvail = !empty($msg['subject_available']) || ($subject !== '' && strcasecmp($subject, '(unavailable)') !== 0) ? 1 : 0;
	if ($subject === '' && empty($msg['force_empty_subject'])) {
		$subject = '';
		$subjectAvail = 0;
	}

	$fields = array(
		'account_key_hash' => $hash,
		'recipient_email' => $email,
		'subject' => $subject,
		'subject_available' => $subjectAvail,
		'campaign_id' => isset($msg['campaign_id']) ? (string)$msg['campaign_id'] : '',
		'campaign_name' => isset($msg['campaign_name']) ? (string)$msg['campaign_name'] : '',
		'brevo_message_id' => $mid,
		'template_id' => isset($msg['template_id']) ? (string)$msg['template_id'] : '',
		'tags' => ba_tags_to_string(isset($msg['tags']) ? $msg['tags'] : ''),
		'send_date' => isset($msg['send_date']) ? ba_parse_ts($msg['send_date']) : '',
		'provider_source' => isset($msg['provider_source']) ? $msg['provider_source'] : 'transactional',
		'current_status' => isset($msg['current_status']) ? ba_normalize_event($msg['current_status']) : 'sent',
		'bounce_reason' => isset($msg['bounce_reason']) ? (string)$msg['bounce_reason'] : '',
		'event_date' => isset($msg['event_date']) ? ba_parse_ts($msg['event_date']) : '',
		'uuid' => isset($msg['uuid']) ? (string)$msg['uuid'] : '',
		'from_email' => isset($msg['from_email']) ? (string)$msg['from_email'] : ''
	);

	if ($row) {
		$id = intval($row['id']);
		// Never wipe a known subject with empty/unavailable
		if ($fields['subject'] === '' && $row['subject'] !== '') {
			$fields['subject'] = $row['subject'];
			$fields['subject_available'] = intval($row['subject_available']);
		} else if ($fields['subject'] !== '' && intval($row['subject_available']) === 0) {
			$fields['subject_available'] = 1;
		}
		if ($fields['send_date'] === '' && $row['send_date'] !== '') $fields['send_date'] = $row['send_date'];
		if ($fields['template_id'] === '' && $row['template_id'] !== '') $fields['template_id'] = $row['template_id'];
		if ($fields['tags'] === '' && $row['tags'] !== '') $fields['tags'] = $row['tags'];
		if ($fields['from_email'] === '' && $row['from_email'] !== '') $fields['from_email'] = $row['from_email'];
		if ($fields['campaign_id'] === '' && $row['campaign_id'] !== '') $fields['campaign_id'] = $row['campaign_id'];
		if ($fields['campaign_name'] === '' && $row['campaign_name'] !== '') $fields['campaign_name'] = $row['campaign_name'];
		if ($fields['uuid'] === '' && $row['uuid'] !== '') $fields['uuid'] = $row['uuid'];
		if ($fields['account_key_hash'] === '' && $row['account_key_hash'] !== '') $fields['account_key_hash'] = $row['account_key_hash'];

		// Preserve stronger current_status unless caller forces
		if (empty($msg['force_status'])) {
			if (ba_event_priority($row['current_status']) > ba_event_priority($fields['current_status'])) {
				$fields['current_status'] = $row['current_status'];
				if ($row['bounce_reason'] !== '' && $fields['bounce_reason'] === '') $fields['bounce_reason'] = $row['bounce_reason'];
				if ($row['event_date'] !== '' && $fields['event_date'] === '') $fields['event_date'] = $row['event_date'];
			}
		}

		$st = $pdo->prepare("UPDATE ba_messages SET
			account_key_hash=?, recipient_email=?, subject=?, subject_available=?,
			campaign_id=?, campaign_name=?, brevo_message_id=?, template_id=?, tags=?,
			send_date=?, provider_source=?, current_status=?, bounce_reason=?, event_date=?,
			uuid=?, from_email=?, updated_at=datetime('now')
			WHERE id=?");
		$st->execute(array(
			$fields['account_key_hash'], $fields['recipient_email'], $fields['subject'], $fields['subject_available'],
			$fields['campaign_id'], $fields['campaign_name'], $fields['brevo_message_id'], $fields['template_id'], $fields['tags'],
			$fields['send_date'], $fields['provider_source'], $fields['current_status'], $fields['bounce_reason'], $fields['event_date'],
			$fields['uuid'], $fields['from_email'], $id
		));
		return $id;
	}

	if ($fields['subject'] === '' && empty($msg['allow_blank_subject'])) {
		$fields['subject'] = '(unavailable)';
		$fields['subject_available'] = 0;
	} else if ($fields['subject'] !== '' && $fields['subject'] !== '(unavailable)') {
		$fields['subject_available'] = 1;
	}

	$st = $pdo->prepare("INSERT INTO ba_messages (
		account_key_hash, recipient_email, subject, subject_available, campaign_id, campaign_name,
		brevo_message_id, template_id, tags, send_date, provider_source, current_status,
		bounce_reason, event_date, uuid, from_email
	) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
	$st->execute(array(
		$fields['account_key_hash'], $fields['recipient_email'], $fields['subject'], $fields['subject_available'],
		$fields['campaign_id'], $fields['campaign_name'], $fields['brevo_message_id'], $fields['template_id'], $fields['tags'],
		$fields['send_date'], $fields['provider_source'], $fields['current_status'], $fields['bounce_reason'],
		$fields['event_date'], $fields['uuid'], $fields['from_email']
	));
	return intval($pdo->lastInsertId());
}

function ba_recompute_message_status(PDO $pdo, $messageRowId)
{
	$messageRowId = intval($messageRowId);
	if ($messageRowId < 1) return;
	$st = $pdo->prepare('SELECT event_type, event_ts, bounce_reason FROM ba_events WHERE message_row_id = ? ORDER BY event_ts ASC, id ASC');
	$st->execute(array($messageRowId));
	$best = null;
	$bestP = -1;
	$reason = '';
	$eventDate = '';
	while ($ev = $st->fetch()) {
		$p = ba_event_priority($ev['event_type']);
		if ($p >= $bestP) {
			$bestP = $p;
			$best = ba_normalize_event($ev['event_type']);
			$eventDate = $ev['event_ts'];
			if ($ev['bounce_reason'] !== '') $reason = $ev['bounce_reason'];
		}
	}
	if ($best === null) return;
	$up = $pdo->prepare("UPDATE ba_messages SET current_status=?, bounce_reason=?, event_date=?, updated_at=datetime('now') WHERE id=?");
	$up->execute(array($best, $reason, $eventDate, $messageRowId));
}

/**
 * Insert one event idempotently. Returns: inserted|skipped|failed
 */
function ba_import_event(PDO $pdo, array $ev)
{
	$hash = isset($ev['account_key_hash']) ? $ev['account_key_hash'] : '';
	$email = strtolower(trim(isset($ev['recipient_email']) ? $ev['recipient_email'] : (isset($ev['email']) ? $ev['email'] : '')));
	$mid = ba_normalize_message_id(isset($ev['brevo_message_id']) ? $ev['brevo_message_id'] : (isset($ev['messageId']) ? $ev['messageId'] : (isset($ev['message-id']) ? $ev['message-id'] : '')));
	$etype = ba_normalize_event(isset($ev['event_type']) ? $ev['event_type'] : (isset($ev['event']) ? $ev['event'] : ''));
	$ets = ba_parse_ts(isset($ev['event_ts']) ? $ev['event_ts'] : (isset($ev['date']) ? $ev['date'] : (isset($ev['ts_event']) ? $ev['ts_event'] : '')));
	$reason = isset($ev['bounce_reason']) ? (string)$ev['bounce_reason'] : (isset($ev['reason']) ? (string)$ev['reason'] : '');
	$subject = isset($ev['subject']) ? (string)$ev['subject'] : '';
	$templateId = isset($ev['template_id']) ? (string)$ev['template_id'] : (isset($ev['templateId']) ? (string)$ev['templateId'] : '');
	$tags = isset($ev['tags']) ? $ev['tags'] : (isset($ev['tag']) ? $ev['tag'] : '');
	$link = isset($ev['link']) ? (string)$ev['link'] : '';
	$source = isset($ev['event_source']) ? (string)$ev['event_source'] : 'api';
	$raw = isset($ev['raw_payload']) ? (is_string($ev['raw_payload']) ? $ev['raw_payload'] : json_encode($ev['raw_payload'])) : '';
	$from = isset($ev['from_email']) ? (string)$ev['from_email'] : (isset($ev['from']) ? (string)$ev['from'] : '');
	$sendDate = isset($ev['send_date']) ? ba_parse_ts($ev['send_date']) : '';

	if ($etype === '' || ($email === '' && $mid === '')) {
		return 'failed';
	}

	$uid = isset($ev['event_uid']) ? $ev['event_uid'] : ba_event_uid($hash, $mid, $email, $etype, $ets, $link.$reason);
	$chk = $pdo->prepare('SELECT id FROM ba_events WHERE event_uid = ? LIMIT 1');
	$chk->execute(array($uid));
	if ($chk->fetch()) return 'skipped';

	$msgId = ba_upsert_message($pdo, array(
		'account_key_hash' => $hash,
		'recipient_email' => $email,
		'subject' => $subject,
		'subject_available' => ($subject !== '') ? 1 : 0,
		'brevo_message_id' => $mid,
		'template_id' => $templateId,
		'tags' => $tags,
		'send_date' => $sendDate !== '' ? $sendDate : $ets,
		'provider_source' => 'transactional',
		'current_status' => $etype,
		'bounce_reason' => $reason,
		'event_date' => $ets,
		'from_email' => $from,
		'campaign_id' => isset($ev['campaign_id']) ? (string)$ev['campaign_id'] : '',
		'campaign_name' => isset($ev['campaign_name']) ? (string)$ev['campaign_name'] : '',
		'force_status' => false,
		'allow_blank_subject' => true
	));

	try {
		$ins = $pdo->prepare("INSERT INTO ba_events (
			message_row_id, account_key_hash, recipient_email, brevo_message_id, event_type, event_ts,
			bounce_reason, subject, template_id, tags, link, event_source, event_uid, raw_payload
		) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
		$ins->execute(array(
			$msgId, $hash, $email, $mid, $etype, $ets, $reason, $subject, $templateId,
			ba_tags_to_string($tags), $link, $source, $uid, $raw
		));
	} catch (PDOException $ex) {
		if (strpos($ex->getMessage(), 'UNIQUE') !== false) return 'skipped';
		return 'failed';
	}

	ba_recompute_message_status($pdo, $msgId);
	if ($subject !== '') {
		$pdo->prepare("UPDATE ba_messages SET subject=?, subject_available=1, updated_at=datetime('now') WHERE id=? AND (subject_available=0 OR subject='' OR subject='(unavailable)')")
			->execute(array($subject, $msgId));
	}
	return 'inserted';
}

/** Record a local successful transactional send (subject known). */
function ba_record_local_send($apiKey, $toEmail, $subject, $messageId, $fromEmail = '', $templateId = '', $tags = '')
{
	try {
		$pdo = ba_db();
		ba_upsert_message($pdo, array(
			'account_key_hash' => ba_key_hash($apiKey),
			'recipient_email' => $toEmail,
			'subject' => $subject,
			'subject_available' => 1,
			'brevo_message_id' => $messageId,
			'template_id' => $templateId,
			'tags' => $tags,
			'send_date' => ba_utc_now(),
			'provider_source' => 'transactional',
			'current_status' => 'sent',
			'event_date' => ba_utc_now(),
			'from_email' => $fromEmail,
			'force_status' => false,
			'allow_blank_subject' => false
		));
		ba_import_event($pdo, array(
			'account_key_hash' => ba_key_hash($apiKey),
			'recipient_email' => $toEmail,
			'brevo_message_id' => $messageId,
			'event_type' => 'sent',
			'event_ts' => ba_utc_now(),
			'subject' => $subject,
			'template_id' => $templateId,
			'tags' => $tags,
			'from_email' => $fromEmail,
			'event_source' => 'local_send',
			'raw_payload' => json_encode(array('source' => 'local_send', 'messageId' => $messageId))
		));
	} catch (Exception $e) {
		// never break sending
	}
}

function ba_fetch_events_page($apiKey, array $query)
{
	$q = http_build_query($query);
	return ba_http('GET', 'https://api.brevo.com/v3/smtp/statistics/events?'.$q, $apiKey, null, 60);
}

function ba_fetch_email_log($apiKey, array $query)
{
	$q = http_build_query($query);
	return ba_http('GET', 'https://api.brevo.com/v3/smtp/emails?'.$q, $apiKey, null, 45);
}

/**
 * Pull transactional events for up to 90 days with pagination.
 */
function ba_sync_events_for_account($apiKey, $startDate, $endDate, $syncRunId = 0)
{
	$pdo = ba_db();
	$hash = ba_key_hash($apiKey);
	$stats = array('retrieved' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'err' => '');
	$limit = 2500;
	$offset = 0;
	$maxPages = 200;

	// Chunk by 90-day max (caller should already clamp)
	for ($page = 0; $page < $maxPages; $page++) {
		$res = ba_fetch_events_page($apiKey, array(
			'limit' => $limit,
			'offset' => $offset,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'sort' => 'desc'
		));
		if (!$res['ok']) {
			$stats['err'] = $res['err'];
			break;
		}
		$events = array();
		if (!empty($res['data']['events']) && is_array($res['data']['events'])) $events = $res['data']['events'];
		$n = count($events);
		if ($n < 1) break;

		foreach ($events as $e) {
			if (!is_array($e)) { $stats['failed']++; continue; }
			$stats['retrieved']++;
			$result = ba_import_event($pdo, array(
				'account_key_hash' => $hash,
				'email' => isset($e['email']) ? $e['email'] : '',
				'event' => isset($e['event']) ? $e['event'] : '',
				'date' => isset($e['date']) ? $e['date'] : '',
				'messageId' => isset($e['messageId']) ? $e['messageId'] : '',
				'reason' => isset($e['reason']) ? $e['reason'] : '',
				'subject' => isset($e['subject']) ? $e['subject'] : '',
				'tag' => isset($e['tag']) ? $e['tag'] : '',
				'templateId' => isset($e['templateId']) ? $e['templateId'] : '',
				'from' => isset($e['from']) ? $e['from'] : '',
				'link' => isset($e['link']) ? $e['link'] : '',
				'event_source' => 'smtp_statistics_events',
				'raw_payload' => json_encode($e)
			));
			if ($result === 'inserted') $stats['imported']++;
			else if ($result === 'skipped') $stats['skipped']++;
			else $stats['failed']++;
		}

		if ($syncRunId > 0) {
			$pdo->prepare('UPDATE ba_sync_runs SET total_retrieved=?, new_imported=?, existing_skipped=?, failed=?, resume_offset=? WHERE id=?')
				->execute(array($stats['retrieved'], $stats['imported'], $stats['skipped'], $stats['failed'], $offset + $n, $syncRunId));
		}

		$offset += $n;
		if ($n < $limit) break;
		usleep(150000); // gentle rate limit
	}

	// Enrich subjects for messages still missing subject via /smtp/emails when we have messageId
	ba_enrich_subjects($apiKey, 200);

	return $stats;
}

function ba_enrich_subjects($apiKey, $limit = 100)
{
	$pdo = ba_db();
	$hash = ba_key_hash($apiKey);
	$st = $pdo->prepare("SELECT id, brevo_message_id, recipient_email FROM ba_messages
		WHERE (subject_available=0 OR subject='' OR subject='(unavailable)')
		AND brevo_message_id != ''
		AND (account_key_hash = ? OR account_key_hash = '')
		ORDER BY id DESC LIMIT ?");
	$st->execute(array($hash, intval($limit)));
	$rows = $st->fetchAll();
	$filled = 0;
	foreach ($rows as $row) {
		$res = ba_fetch_email_log($apiKey, array(
			'messageId' => $row['brevo_message_id'],
			'limit' => 1
		));
		if (!$res['ok']) {
			// try by email (less precise)
			continue;
		}
		$list = array();
		if (!empty($res['data']['transactionalEmails']) && is_array($res['data']['transactionalEmails'])) {
			$list = $res['data']['transactionalEmails'];
		}
		foreach ($list as $te) {
			if (!is_array($te)) continue;
			$subj = isset($te['subject']) ? (string)$te['subject'] : '';
			if ($subj === '') continue;
			$pdo->prepare("UPDATE ba_messages SET subject=?, subject_available=1, template_id=COALESCE(NULLIF(template_id,''), ?),
				uuid=COALESCE(NULLIF(uuid,''), ?), send_date=COALESCE(NULLIF(send_date,''), ?), updated_at=datetime('now') WHERE id=?")
				->execute(array(
					$subj,
					isset($te['templateId']) ? (string)$te['templateId'] : '',
					isset($te['uuid']) ? (string)$te['uuid'] : '',
					isset($te['date']) ? ba_parse_ts($te['date']) : '',
					$row['id']
				));
			$filled++;
			break;
		}
		usleep(80000);
	}
	return $filled;
}

function ba_create_weekly_export($apiKey, $notifyURL)
{
	return ba_http('POST', 'https://api.brevo.com/v3/webhooks/export', $apiKey, array(
		'event' => 'allEvents',
		'type' => 'transactional',
		'days' => 7,
		'notifyURL' => $notifyURL
	), 45);
}

function ba_get_process($apiKey, $processId)
{
	return ba_http('GET', 'https://api.brevo.com/v3/processes/'.rawurlencode((string)$processId), $apiKey, null, 30);
}

function ba_list_webhooks($apiKey, $type = 'transactional')
{
	return ba_http('GET', 'https://api.brevo.com/v3/webhooks?type='.rawurlencode($type), $apiKey, null, 30);
}

function ba_create_webhook($apiKey, $url, array $events, $type = 'transactional', $description = 'ayb-mailer recipient analytics')
{
	return ba_http('POST', 'https://api.brevo.com/v3/webhooks', $apiKey, array(
		'url' => $url,
		'description' => $description,
		'events' => array_values($events),
		'type' => $type
	), 30);
}

function ba_default_webhook_events()
{
	return array(
		'delivered', 'hardBounce', 'softBounce', 'request', 'deferred',
		'opened', 'uniqueOpened', 'click', 'spam', 'invalid', 'blocked',
		'unsubscribed', 'error'
	);
}

function ba_import_export_file($apiKeyOrHash, $filePath, $isKey = true)
{
	$pdo = ba_db();
	$hash = $isKey ? ba_key_hash($apiKeyOrHash) : (string)$apiKeyOrHash;
	$stats = array('retrieved' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0);
	$paths = array();

	$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
	if ($ext === 'zip' && class_exists('ZipArchive')) {
		$zip = new ZipArchive();
		if ($zip->open($filePath) === true) {
			$tmp = BA_DATA_DIR.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'exp_'.uniqid();
			@mkdir($tmp, 0755, true);
			$zip->extractTo($tmp);
			$zip->close();
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp));
			foreach ($it as $f) {
				if ($f->isFile() && preg_match('/\.csv$/i', $f->getFilename())) $paths[] = $f->getPathname();
			}
		}
	} else {
		$paths[] = $filePath;
	}

	foreach ($paths as $csvPath) {
		$fh = fopen($csvPath, 'rb');
		if (!$fh) continue;
		$header = fgetcsv($fh);
		if (!is_array($header)) { fclose($fh); continue; }
		$header = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);
		$map = array();
		foreach ($header as $i => $h) $map[$h] = $i;
		while (($row = fgetcsv($fh)) !== false) {
			$get = function($keys) use ($map, $row) {
				foreach ((array)$keys as $k) {
					$k = strtolower($k);
					if (isset($map[$k]) && isset($row[$map[$k]])) return $row[$map[$k]];
				}
				return '';
			};
			$email = $get(array('email'));
			$event = $get(array('event'));
			$mid = $get(array('message-id', 'messageid', 'message_id'));
			if ($email === '' && $mid === '') { $stats['failed']++; continue; }
			$stats['retrieved']++;
			$payload = array();
			foreach ($header as $i => $h) $payload[$h] = isset($row[$i]) ? $row[$i] : '';
			$r = ba_import_event($pdo, array(
				'account_key_hash' => $hash,
				'email' => $email,
				'event' => $event,
				'date' => $get(array('date')),
				'ts_event' => $get(array('ts_event', 'ts')),
				'message-id' => $mid,
				'reason' => $get(array('reason')),
				'subject' => $get(array('subject')),
				'tag' => $get(array('tag')),
				'tags' => $get(array('tags')),
				'template_id' => $get(array('template_id', 'templateid')),
				'link' => $get(array('link')),
				'event_source' => 'weekly_export',
				'raw_payload' => json_encode($payload)
			));
			if ($r === 'inserted') $stats['imported']++;
			else if ($r === 'skipped') $stats['skipped']++;
			else $stats['failed']++;
		}
		fclose($fh);
	}
	return $stats;
}

function ba_build_message_filters(array $in)
{
	$where = array('1=1');
	$params = array();

	$status = isset($in['status']) ? trim((string)$in['status']) : 'all';
	if ($status !== '' && $status !== 'all') {
		if ($status === 'bounced') {
			$where[] = "current_status IN ('hardBounce','softBounce','bounced')";
		} else if ($status === 'opened') {
			$where[] = "(current_status IN ('opened','uniqueOpened','loadedByProxy') OR id IN (SELECT message_row_id FROM ba_events WHERE event_type IN ('opened','uniqueOpened','loadedByProxy') AND message_row_id IS NOT NULL))";
		} else if ($status === 'clicked') {
			$where[] = "(current_status = 'clicked' OR id IN (SELECT message_row_id FROM ba_events WHERE event_type = 'clicked' AND message_row_id IS NOT NULL))";
		} else {
			$where[] = 'current_status = ?';
			$params[] = ba_normalize_event($status);
		}
	}

	if (!empty($in['email'])) {
		$where[] = 'recipient_email LIKE ?';
		$params[] = '%'.strtolower(trim($in['email'])).'%';
	}
	if (!empty($in['email_exact'])) {
		$where[] = 'recipient_email = ?';
		$params[] = strtolower(trim($in['email_exact']));
	}
	if (!empty($in['subject'])) {
		$where[] = 'subject = ?';
		$params[] = (string)$in['subject'];
	}
	if (!empty($in['subject_q'])) {
		$where[] = 'subject LIKE ?';
		$params[] = '%'.$in['subject_q'].'%';
	}
	if (!empty($in['campaign_id'])) {
		$where[] = 'campaign_id = ?';
		$params[] = (string)$in['campaign_id'];
	}
	if (!empty($in['campaign_name'])) {
		$where[] = 'campaign_name = ?';
		$params[] = (string)$in['campaign_name'];
	}
	if (!empty($in['start_date'])) {
		$where[] = "(date(substr(COALESCE(NULLIF(send_date,''), event_date),1,10)) >= date(?))";
		$params[] = $in['start_date'];
	}
	if (!empty($in['end_date'])) {
		$where[] = "(date(substr(COALESCE(NULLIF(send_date,''), event_date),1,10)) <= date(?))";
		$params[] = $in['end_date'];
	}
	if (!empty($in['account_key_hash'])) {
		$where[] = 'account_key_hash = ?';
		$params[] = $in['account_key_hash'];
	}

	return array(implode(' AND ', $where), $params);
}

function ba_query_messages(array $in, $page = 1, $perPage = 50)
{
	$pdo = ba_db();
	list($where, $params) = ba_build_message_filters($in);
	$page = max(1, intval($page));
	$perPage = max(1, min(500, intval($perPage)));
	$offset = ($page - 1) * $perPage;

	$cst = $pdo->prepare("SELECT COUNT(*) FROM ba_messages WHERE $where");
	$cst->execute($params);
	$total = intval($cst->fetchColumn());

	$ust = $pdo->prepare("SELECT COUNT(DISTINCT recipient_email) FROM ba_messages WHERE $where");
	$ust->execute($params);
	$unique = intval($ust->fetchColumn());

	$sql = "SELECT * FROM ba_messages WHERE $where ORDER BY COALESCE(NULLIF(event_date,''), send_date, updated_at) DESC, id DESC LIMIT $perPage OFFSET $offset";
	$st = $pdo->prepare($sql);
	$st->execute($params);
	$rows = $st->fetchAll();

	return array(
		'total' => $total,
		'unique_emails' => $unique,
		'page' => $page,
		'per_page' => $perPage,
		'pages' => $perPage > 0 ? intval(ceil($total / $perPage)) : 0,
		'rows' => $rows
	);
}

function ba_status_counts(array $in = array())
{
	$pdo = ba_db();
	// Base filters without status
	$in2 = $in;
	unset($in2['status']);
	list($where, $params) = ba_build_message_filters($in2);

	$out = array(
		'total_messages' => 0,
		'unique_emails' => 0,
		'delivered' => 0,
		'hardBounce' => 0,
		'softBounce' => 0,
		'bounced' => 0,
		'sent' => 0,
		'deferred' => 0,
		'opened' => 0,
		'clicked' => 0,
		'spam' => 0,
		'invalid' => 0,
		'blocked' => 0,
		'unsubscribed' => 0,
		'error' => 0
	);

	$st = $pdo->prepare("SELECT current_status, COUNT(*) AS c FROM ba_messages WHERE $where GROUP BY current_status");
	$st->execute($params);
	while ($r = $st->fetch()) {
		$s = ba_normalize_event($r['current_status']);
		$c = intval($r['c']);
		$out['total_messages'] += $c;
		if (isset($out[$s])) $out[$s] += $c;
		if ($s === 'hardBounce' || $s === 'softBounce' || $s === 'bounced') $out['bounced'] += $c;
	}

	$ust = $pdo->prepare("SELECT COUNT(DISTINCT recipient_email) FROM ba_messages WHERE $where");
	$ust->execute($params);
	$out['unique_emails'] = intval($ust->fetchColumn());

	// Opened / clicked include engagement even if current_status is delivered
	$ost = $pdo->prepare("SELECT COUNT(DISTINCT m.id) FROM ba_messages m
		INNER JOIN ba_events e ON e.message_row_id = m.id
		WHERE ($where) AND e.event_type IN ('opened','uniqueOpened','loadedByProxy')");
	$ost->execute($params);
	$out['opened'] = intval($ost->fetchColumn());

	$cst = $pdo->prepare("SELECT COUNT(DISTINCT m.id) FROM ba_messages m
		INNER JOIN ba_events e ON e.message_row_id = m.id
		WHERE ($where) AND e.event_type = 'clicked'");
	$cst->execute($params);
	$out['clicked'] = intval($cst->fetchColumn());

	return $out;
}

function ba_recipient_history($email, $limit = 200)
{
	$pdo = ba_db();
	$email = strtolower(trim($email));
	$st = $pdo->prepare("SELECT * FROM ba_messages WHERE recipient_email = ? ORDER BY COALESCE(NULLIF(send_date,''), event_date, created_at) DESC LIMIT ?");
	$st->execute(array($email, intval($limit)));
	$messages = $st->fetchAll();
	foreach ($messages as &$m) {
		$es = $pdo->prepare('SELECT event_type, event_ts, bounce_reason, event_source FROM ba_events WHERE message_row_id = ? ORDER BY event_ts ASC, id ASC');
		$es->execute(array($m['id']));
		$m['events'] = $es->fetchAll();
	}
	return $messages;
}

function ba_list_subjects($q = '', $limit = 200)
{
	$pdo = ba_db();
	if ($q !== '') {
		$st = $pdo->prepare("SELECT subject, COUNT(*) AS c FROM ba_messages WHERE subject LIKE ? AND subject != '' GROUP BY subject ORDER BY c DESC LIMIT ?");
		$st->execute(array('%'.$q.'%', intval($limit)));
	} else {
		$st = $pdo->prepare("SELECT subject, COUNT(*) AS c FROM ba_messages WHERE subject != '' GROUP BY subject ORDER BY c DESC LIMIT ?");
		$st->execute(array(intval($limit)));
	}
	return $st->fetchAll();
}

function ba_list_campaigns($limit = 200)
{
	$pdo = ba_db();
	$st = $pdo->prepare("SELECT campaign_id, campaign_name, COUNT(*) AS c FROM ba_messages
		WHERE campaign_id != '' OR campaign_name != ''
		GROUP BY campaign_id, campaign_name ORDER BY c DESC LIMIT ?");
	$st->execute(array(intval($limit)));
	return $st->fetchAll();
}

function ba_last_sync_info()
{
	$pdo = ba_db();
	$st = $pdo->query("SELECT * FROM ba_sync_runs ORDER BY id DESC LIMIT 1");
	$row = $st ? $st->fetch() : false;
	$cfg = ba_config_load();
	return array('last_run' => $row ?: null, 'config_last_sync' => $cfg['last_sync']);
}

function ba_csv_escape($v)
{
	$v = (string)$v;
	if (preg_match('/[",\r\n]/', $v)) return '"'.str_replace('"', '""', $v).'"';
	return $v;
}

function ba_export_csv(array $filters, $outStream = null)
{
	$pdo = ba_db();
	list($where, $params) = ba_build_message_filters($filters);
	$st = $pdo->prepare("SELECT * FROM ba_messages WHERE $where ORDER BY COALESCE(NULLIF(event_date,''), send_date, updated_at) DESC, id DESC");
	$st->execute($params);

	$close = false;
	if ($outStream === null) {
		$outStream = fopen('php://output', 'w');
		$close = true;
	}
	// UTF-8 BOM for Excel
	fwrite($outStream, "\xEF\xBB\xBF");
	$headers = array('Email','Subject','Status','Campaign Name','Campaign ID','Sent Date','Event Date','Bounce Reason','Message ID','Template ID','Tags');
	fwrite($outStream, implode(',', $headers)."\r\n");
	$count = 0;
	while ($r = $st->fetch()) {
		$line = array(
			ba_csv_escape($r['recipient_email']),
			ba_csv_escape($r['subject_available'] ? $r['subject'] : '(unavailable)'),
			ba_csv_escape($r['current_status']),
			ba_csv_escape($r['campaign_name']),
			ba_csv_escape($r['campaign_id']),
			ba_csv_escape($r['send_date']),
			ba_csv_escape($r['event_date']),
			ba_csv_escape($r['bounce_reason']),
			ba_csv_escape($r['brevo_message_id']),
			ba_csv_escape($r['template_id']),
			ba_csv_escape($r['tags'])
		);
		fwrite($outStream, implode(',', $line)."\r\n");
		$count++;
	}
	if ($close) fclose($outStream);
	return $count;
}

function ba_json_input()
{
	$raw = file_get_contents('php://input');
	$in = json_decode($raw, true);
	if (!is_array($in)) $in = array();
	if (empty($in) && !empty($_POST)) $in = $_POST;
	return $in;
}

function ba_require_post_json()
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => false, 'err' => 'POST only'));
		exit;
	}
	return ba_json_input();
}
