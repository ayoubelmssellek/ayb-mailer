<?php
/**
 * Remote Delivery Analytics (KumoMTA post-submission outcomes).
 * Storage: data/remote_delivery.sqlite
 *
 * Reception (Desktop→Kumo) is NOT stored here.
 * Only remote lifecycle events: Delivery, Bounce, TransientFailure, Expiration.
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';

if (!defined('AYB_REMOTE_DELIVERY_DB')) {
	define('AYB_REMOTE_DELIVERY_DB', __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'remote_delivery.sqlite');
}

function rd_config()
{
	ayb_env_load();
	$token = trim((string)ayb_env('KUMO_EVENTS_WEBHOOK_TOKEN', ''));
	return array(
		'token' => $token,
		'ingest_enabled' => ($token !== ''),
		'max_raw_bytes' => 8192
	);
}

function rd_db()
{
	static $pdo = null;
	if ($pdo instanceof PDO) return $pdo;
	$dir = dirname(AYB_REMOTE_DELIVERY_DB);
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
	$pdo = new PDO('sqlite:'.AYB_REMOTE_DELIVERY_DB);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	rd_db_migrate($pdo);
	return $pdo;
}

function rd_db_migrate(PDO $pdo)
{
	$pdo->exec("CREATE TABLE IF NOT EXISTS remote_delivery_events (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		dedupe_key TEXT NOT NULL UNIQUE,
		event_type TEXT NOT NULL,
		spool_id TEXT NOT NULL DEFAULT '',
		message_id TEXT,
		ayb_send_id TEXT,
		recipient TEXT NOT NULL DEFAULT '',
		sender TEXT NOT NULL DEFAULT '',
		remote_peer TEXT NOT NULL DEFAULT '',
		site TEXT NOT NULL DEFAULT '',
		smtp_code INTEGER,
		enhanced_code TEXT,
		response_text TEXT NOT NULL DEFAULT '',
		num_attempts INTEGER NOT NULL DEFAULT 0,
		event_timestamp INTEGER NOT NULL DEFAULT 0,
		received_at TEXT NOT NULL,
		raw_event TEXT
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_rd_event_ts ON remote_delivery_events(event_timestamp)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_rd_type_ts ON remote_delivery_events(event_type, event_timestamp)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_rd_spool ON remote_delivery_events(spool_id)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_rd_msgid ON remote_delivery_events(message_id)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_rd_ayb ON remote_delivery_events(ayb_send_id)");
	$pdo->exec("CREATE TABLE IF NOT EXISTS remote_delivery_meta (
		key TEXT PRIMARY KEY,
		value TEXT NOT NULL DEFAULT ''
	)");
}

function rd_meta_get($key, $default = '')
{
	$st = rd_db()->prepare('SELECT value FROM remote_delivery_meta WHERE key=?');
	$st->execute(array((string)$key));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	return $row ? (string)$row['value'] : $default;
}

function rd_meta_set($key, $value)
{
	rd_db()->prepare('INSERT INTO remote_delivery_meta(key,value) VALUES(?,?)
		ON CONFLICT(key) DO UPDATE SET value=excluded.value')
		->execute(array((string)$key, (string)$value));
}

function rd_allowed_event_types()
{
	return array('Delivery', 'Bounce', 'TransientFailure', 'Expiration');
}

function rd_normalize_event_type($type)
{
	$t = trim((string)$type);
	$map = array(
		'delivery' => 'Delivery',
		'bounce' => 'Bounce',
		'transientfailure' => 'TransientFailure',
		'transient_failure' => 'TransientFailure',
		'expiration' => 'Expiration',
		'expired' => 'Expiration'
	);
	$k = strtolower($t);
	if (isset($map[$k])) return $map[$k];
	foreach (rd_allowed_event_types() as $ok) {
		if (strcasecmp($ok, $t) === 0) return $ok;
	}
	return '';
}

function rd_mask_recipient($email)
{
	$email = strtolower(trim((string)$email));
	if ($email === '' || strpos($email, '@') === false) return '—';
	list($local, $dom) = explode('@', $email, 2);
	if ($local === '') return '***@'.$dom;
	$keep = substr($local, 0, 1);
	return $keep.'***@'.$dom;
}

function rd_redact_raw($json)
{
	$s = (string)$json;
	if ($s === '') return '';
	$s = preg_replace('/(password\s*[:=]\s*)([^\s&,;"]+)/i', '$1[REDACTED]', $s);
	$s = preg_replace('/(Authorization\s*[:=]\s*)(\S.+)$/im', '$1[REDACTED]', $s);
	$s = preg_replace('/(Bearer\s+)[A-Za-z0-9\-_\.=]+/i', 'Bearer [REDACTED]', $s);
	$max = rd_config()['max_raw_bytes'];
	if (strlen($s) > $max) $s = substr($s, 0, $max).'…';
	return $s;
}

function rd_extract_headers_map($headers)
{
	$out = array();
	if (!is_array($headers)) return $out;
	foreach ($headers as $k => $v) {
		$out[strtolower((string)$k)] = is_array($v) ? (isset($v[0]) ? (string)$v[0] : '') : (string)$v;
	}
	return $out;
}

/**
 * Normalize a KumoMTA log record (or flat payload) into insertable fields.
 * @return array{ok:bool,err?:string,skip?:bool,row?:array}
 */
function rd_normalize_payload($in)
{
	if (!is_array($in)) return array('ok' => false, 'err' => 'Event must be an object');

	// Nested log_record meta (webhook message body sometimes wraps)
	if (isset($in['log_record']) && is_array($in['log_record'])) $in = $in['log_record'];

	$type = rd_normalize_event_type(isset($in['type']) ? $in['type'] : (isset($in['event_type']) ? $in['event_type'] : ''));
	if ($type === '') {
		$rawType = isset($in['type']) ? (string)$in['type'] : '';
		// Explicitly ignore submission-side noise
		if (in_array(strtolower($rawType), array('reception', 'rejection', 'feedback', 'adminbounce'), true)) {
			return array('ok' => true, 'skip' => true, 'err' => 'Ignored event type '.$rawType);
		}
		return array('ok' => false, 'err' => 'Unsupported or missing event type');
	}

	$spool = isset($in['id']) ? trim((string)$in['id']) : (isset($in['spool_id']) ? trim((string)$in['spool_id']) : '');
	$recipient = isset($in['recipient']) ? $in['recipient'] : '';
	if (is_array($recipient)) $recipient = isset($recipient[0]) ? $recipient[0] : '';
	$recipient = strtolower(trim((string)$recipient));

	$sender = isset($in['sender']) ? strtolower(trim((string)$in['sender'])) : '';
	$peer = '';
	if (isset($in['peer_address']) && is_array($in['peer_address'])) {
		$name = isset($in['peer_address']['name']) ? (string)$in['peer_address']['name'] : '';
		$addr = isset($in['peer_address']['addr']) ? (string)$in['peer_address']['addr'] : '';
		$peer = trim($name !== '' ? $name : $addr);
	} else if (isset($in['remote_peer'])) {
		$peer = trim((string)$in['remote_peer']);
	}
	$site = isset($in['site']) ? trim((string)$in['site']) : '';

	$smtpCode = null;
	$enhanced = '';
	$responseText = '';
	if (isset($in['response']) && is_array($in['response'])) {
		if (isset($in['response']['code']) && $in['response']['code'] !== '' && $in['response']['code'] !== null) {
			$smtpCode = intval($in['response']['code']);
		}
		if (isset($in['response']['enhanced_code']) && is_array($in['response']['enhanced_code'])) {
			$ec = $in['response']['enhanced_code'];
			$enhanced = intval(isset($ec['class']) ? $ec['class'] : 0).'.'
				.intval(isset($ec['subject']) ? $ec['subject'] : 0).'.'
				.intval(isset($ec['detail']) ? $ec['detail'] : 0);
		}
		$responseText = isset($in['response']['content']) ? trim((string)$in['response']['content']) : '';
	}
	if ($smtpCode === null && isset($in['smtp_code'])) $smtpCode = intval($in['smtp_code']);
	if ($responseText === '' && isset($in['response_text'])) $responseText = trim((string)$in['response_text']);
	if ($enhanced === '' && isset($in['enhanced_code'])) $enhanced = trim((string)$in['enhanced_code']);

	$ts = 0;
	if (isset($in['timestamp'])) $ts = intval($in['timestamp']);
	if ($ts < 1 && isset($in['event_timestamp'])) $ts = intval($in['event_timestamp']);
	if ($ts < 1 && isset($in['event_time'])) {
		$parsed = strtotime((string)$in['event_time']);
		if ($parsed) $ts = intval($parsed);
	}
	if ($ts < 1) $ts = time();

	$attempts = isset($in['num_attempts']) ? max(0, intval($in['num_attempts'])) : 0;

	$headers = rd_extract_headers_map(isset($in['headers']) ? $in['headers'] : array());
	$messageId = isset($headers['message-id']) ? trim($headers['message-id']) : '';
	if ($messageId === '' && isset($in['message_id'])) $messageId = trim((string)$in['message_id']);
	$aybSendId = '';
	foreach (array('x-ayb-send-id', 'x-ayb_send_id') as $hk) {
		if (!empty($headers[$hk])) { $aybSendId = trim($headers[$hk]); break; }
	}
	if ($aybSendId === '' && isset($in['ayb_send_id'])) $aybSendId = trim((string)$in['ayb_send_id']);

	// Idempotency: type + spool + ts + recipient (+ smtp code when present)
	$dedupe = strtolower($type).'|'.$spool.'|'.$ts.'|'.$recipient.'|'.($smtpCode !== null ? (string)$smtpCode : '');
	if ($spool === '' && $recipient === '') {
		return array('ok' => false, 'err' => 'Event needs spool id or recipient');
	}

	$row = array(
		'dedupe_key' => substr(hash('sha256', $dedupe), 0, 64),
		'event_type' => $type,
		'spool_id' => substr($spool, 0, 80),
		'message_id' => $messageId !== '' ? substr($messageId, 0, 255) : null,
		'ayb_send_id' => $aybSendId !== '' ? substr($aybSendId, 0, 120) : null,
		'recipient' => substr($recipient, 0, 320),
		'sender' => substr($sender, 0, 320),
		'remote_peer' => substr($peer, 0, 255),
		'site' => substr($site, 0, 255),
		'smtp_code' => $smtpCode,
		'enhanced_code' => $enhanced !== '' ? substr($enhanced, 0, 32) : null,
		'response_text' => substr($responseText, 0, 500),
		'num_attempts' => $attempts,
		'event_timestamp' => $ts,
		'received_at' => gmdate('c'),
		'raw_event' => rd_redact_raw(json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
	);
	return array('ok' => true, 'skip' => false, 'row' => $row);
}

/**
 * Insert one normalized event. Idempotent via dedupe_key UNIQUE.
 * @return array{ok:bool,inserted?:bool,duplicate?:bool,skipped?:bool,err?:string,id?:int}
 */
function rd_ingest_event($payload)
{
	$n = rd_normalize_payload($payload);
	if (empty($n['ok'])) return array('ok' => false, 'err' => isset($n['err']) ? $n['err'] : 'invalid');
	if (!empty($n['skip'])) return array('ok' => true, 'skipped' => true, 'inserted' => false, 'duplicate' => false);

	$row = $n['row'];
	$pdo = rd_db();
	try {
		$st = $pdo->prepare('INSERT INTO remote_delivery_events(
			dedupe_key, event_type, spool_id, message_id, ayb_send_id, recipient, sender,
			remote_peer, site, smtp_code, enhanced_code, response_text, num_attempts,
			event_timestamp, received_at, raw_event
		) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
		$st->execute(array(
			$row['dedupe_key'], $row['event_type'], $row['spool_id'], $row['message_id'], $row['ayb_send_id'],
			$row['recipient'], $row['sender'], $row['remote_peer'], $row['site'], $row['smtp_code'],
			$row['enhanced_code'], $row['response_text'], $row['num_attempts'], $row['event_timestamp'],
			$row['received_at'], $row['raw_event']
		));
		rd_meta_set('last_ingest_at', gmdate('c'));
		return array('ok' => true, 'inserted' => true, 'duplicate' => false, 'id' => intval($pdo->lastInsertId()));
	} catch (PDOException $e) {
		$msg = $e->getMessage();
		if (stripos($msg, 'UNIQUE') !== false || stripos($msg, 'constraint') !== false) {
			return array('ok' => true, 'inserted' => false, 'duplicate' => true);
		}
		return array('ok' => false, 'err' => 'DB insert failed');
	}
}

function rd_ingest_batch($items)
{
	if (!is_array($items)) return array('ok' => false, 'err' => 'Batch must be an array');
	$inserted = 0;
	$dup = 0;
	$skipped = 0;
	$errors = 0;
	foreach ($items as $item) {
		$r = rd_ingest_event($item);
		if (empty($r['ok'])) { $errors++; continue; }
		if (!empty($r['skipped'])) { $skipped++; continue; }
		if (!empty($r['duplicate'])) { $dup++; continue; }
		if (!empty($r['inserted'])) $inserted++;
	}
	return array(
		'ok' => ($errors === 0),
		'inserted' => $inserted,
		'duplicates' => $dup,
		'skipped' => $skipped,
		'errors' => $errors
	);
}

function rd_range_bounds($days)
{
	$days = max(1, min(90, intval($days)));
	$end = time();
	$start = $end - (($days) * 86400);
	// For "today" style, still use rolling window for consistency with overview
	return array('days' => $days, 'start_ts' => $start, 'end_ts' => $end);
}

function rd_count_types($startTs, $endTs)
{
	$st = rd_db()->prepare('SELECT event_type, COUNT(*) AS c FROM remote_delivery_events
		WHERE event_timestamp >= ? AND event_timestamp <= ? GROUP BY event_type');
	$st->execute(array(intval($startTs), intval($endTs)));
	$out = array('Delivery' => 0, 'Bounce' => 0, 'TransientFailure' => 0, 'Expiration' => 0);
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$t = $row['event_type'];
		if (isset($out[$t])) $out[$t] = intval($row['c']);
	}
	return $out;
}

/**
 * Remote acceptance rate = Delivery / (Delivery + Bounce + Expiration).
 * TransientFailure excluded (non-terminal). Reception/Rejection never counted.
 */
function rd_acceptance_rate($counts)
{
	$d = intval(isset($counts['Delivery']) ? $counts['Delivery'] : 0);
	$b = intval(isset($counts['Bounce']) ? $counts['Bounce'] : 0);
	$e = intval(isset($counts['Expiration']) ? $counts['Expiration'] : 0);
	$den = $d + $b + $e;
	if ($den < 1) return null;
	return round(($d / $den) * 100, 2);
}

function rd_series_daily($startTs, $endTs)
{
	$st = rd_db()->prepare('SELECT date(event_timestamp, \'unixepoch\') AS day, event_type, COUNT(*) AS c
		FROM remote_delivery_events
		WHERE event_timestamp >= ? AND event_timestamp <= ?
		GROUP BY day, event_type ORDER BY day ASC');
	$st->execute(array(intval($startTs), intval($endTs)));
	$byDay = array();
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$day = $row['day'];
		if (!isset($byDay[$day])) {
			$byDay[$day] = array('day' => $day, 'Delivery' => 0, 'Bounce' => 0, 'TransientFailure' => 0, 'Expiration' => 0);
		}
		$t = $row['event_type'];
		if (isset($byDay[$day][$t])) $byDay[$day][$t] = intval($row['c']);
	}
	return array_values($byDay);
}

function rd_smtp_code_breakdown($startTs, $endTs)
{
	$st = rd_db()->prepare('SELECT smtp_code, COUNT(*) AS c FROM remote_delivery_events
		WHERE event_timestamp >= ? AND event_timestamp <= ? GROUP BY smtp_code');
	$st->execute(array(intval($startTs), intval($endTs)));
	$buckets = array('2xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0, 'unknown' => 0);
	$detail = array();
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$code = $row['smtp_code'];
		$c = intval($row['c']);
		if ($code === null || $code === '') {
			$buckets['unknown'] += $c;
			continue;
		}
		$code = intval($code);
		$detail[] = array('smtp_code' => $code, 'count' => $c);
		if ($code >= 200 && $code < 300) $buckets['2xx'] += $c;
		else if ($code >= 400 && $code < 500) $buckets['4xx'] += $c;
		else if ($code >= 500 && $code < 600) $buckets['5xx'] += $c;
		else $buckets['other'] += $c;
	}
	usort($detail, function ($a, $b) { return $b['count'] - $a['count']; });
	return array('buckets' => $buckets, 'codes' => array_slice($detail, 0, 40));
}

function rd_mx_breakdown($startTs, $endTs, $limit = 25)
{
	$limit = max(1, min(100, intval($limit)));
	$st = rd_db()->prepare('SELECT
			CASE WHEN remote_peer IS NULL OR remote_peer=\'\' THEN \'(unknown)\' ELSE remote_peer END AS mx,
			SUM(CASE WHEN event_type=\'Delivery\' THEN 1 ELSE 0 END) AS accepted,
			SUM(CASE WHEN event_type=\'Bounce\' THEN 1 ELSE 0 END) AS bounce,
			SUM(CASE WHEN event_type=\'TransientFailure\' THEN 1 ELSE 0 END) AS deferred,
			SUM(CASE WHEN event_type=\'Expiration\' THEN 1 ELSE 0 END) AS expired
		FROM remote_delivery_events
		WHERE event_timestamp >= ? AND event_timestamp <= ?
		GROUP BY mx
		ORDER BY (accepted + bounce + deferred + expired) DESC
		LIMIT '.$limit);
	$st->execute(array(intval($startTs), intval($endTs)));
	$rows = array();
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$a = intval($row['accepted']);
		$b = intval($row['bounce']);
		$e = intval($row['expired']);
		$den = $a + $b + $e;
		$rows[] = array(
			'mx' => $row['mx'],
			'accepted' => $a,
			'bounce' => intval($row['bounce']),
			'deferred' => intval($row['deferred']),
			'expired' => $e,
			'acceptance_rate' => $den > 0 ? round(($a / $den) * 100, 2) : null
		);
	}
	return $rows;
}

function rd_failure_analysis($startTs, $endTs, $limit = 30)
{
	$limit = max(1, min(100, intval($limit)));
	$st = rd_db()->prepare('SELECT smtp_code, enhanced_code, response_text, COUNT(*) AS c
		FROM remote_delivery_events
		WHERE event_timestamp >= ? AND event_timestamp <= ?
			AND event_type IN (\'Bounce\',\'TransientFailure\',\'Expiration\')
		GROUP BY smtp_code, enhanced_code, response_text
		ORDER BY c DESC
		LIMIT '.$limit);
	$st->execute(array(intval($startTs), intval($endTs)));
	$out = array();
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$out[] = array(
			'smtp_code' => $row['smtp_code'] !== null ? intval($row['smtp_code']) : null,
			'enhanced_code' => $row['enhanced_code'] !== null ? $row['enhanced_code'] : '—',
			'response_text' => $row['response_text'] !== '' ? $row['response_text'] : '—',
			'count' => intval($row['c'])
		);
	}
	return $out;
}

function rd_recent_events($startTs, $endTs, $limit = 50, $offset = 0)
{
	$limit = max(1, min(100, intval($limit)));
	$offset = max(0, intval($offset));
	$st = rd_db()->prepare('SELECT id, event_type, spool_id, message_id, ayb_send_id, recipient, sender,
			remote_peer, site, smtp_code, enhanced_code, response_text, num_attempts, event_timestamp, received_at
		FROM remote_delivery_events
		WHERE event_timestamp >= ? AND event_timestamp <= ?
		ORDER BY event_timestamp DESC, id DESC
		LIMIT '.$limit.' OFFSET '.$offset);
	$st->execute(array(intval($startTs), intval($endTs)));
	$rows = array();
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$rows[] = array(
			'id' => intval($row['id']),
			'ts' => gmdate('Y-m-d H:i:s', intval($row['event_timestamp'])),
			'event_timestamp' => intval($row['event_timestamp']),
			'event_type' => $row['event_type'],
			'recipient_masked' => rd_mask_recipient($row['recipient']),
			'remote_peer' => $row['remote_peer'] !== '' ? $row['remote_peer'] : '—',
			'smtp_code' => $row['smtp_code'] !== null ? intval($row['smtp_code']) : null,
			'enhanced_code' => $row['enhanced_code'] !== null ? $row['enhanced_code'] : '—',
			'response_text' => $row['response_text'] !== '' ? $row['response_text'] : '—',
			'num_attempts' => intval($row['num_attempts']),
			'spool_id' => $row['spool_id'],
			'message_id' => $row['message_id'],
			'ayb_send_id' => $row['ayb_send_id']
		);
	}
	$cst = rd_db()->prepare('SELECT COUNT(*) FROM remote_delivery_events WHERE event_timestamp >= ? AND event_timestamp <= ?');
	$cst->execute(array(intval($startTs), intval($endTs)));
	$total = intval($cst->fetchColumn());
	return array('rows' => $rows, 'total' => $total);
}

function rd_overview($days = 7)
{
	$cfg = rd_config();
	$bounds = rd_range_bounds($days);
	$counts = rd_count_types($bounds['start_ts'], $bounds['end_ts']);
	$totalRemote = $counts['Delivery'] + $counts['Bounce'] + $counts['TransientFailure'] + $counts['Expiration'];
	$rate = rd_acceptance_rate($counts);
	$waiting = ($totalRemote < 1);

	return array(
		'ok' => true,
		'waiting_for_events' => $waiting,
		'ingest_enabled' => !empty($cfg['ingest_enabled']),
		'ingest_note' => empty($cfg['ingest_enabled'])
			? 'Ingest inactive: set KUMO_EVENTS_WEBHOOK_TOKEN in .env and configure KumoMTA webhook (Ubuntu VPS) to POST events.'
			: 'Ingest active: authenticated webhook endpoint ready (KumoMTA VPS must still be configured to send events).',
		'vocabulary' => array(
			'remote_accepted' => 'Remote MX accepted the message (KumoMTA Delivery) — not inbox placement',
			'bounce' => 'Permanent remote delivery failure (KumoMTA Bounce)',
			'deferred' => 'Temporary remote failure / deferral (KumoMTA TransientFailure)',
			'expired' => 'Message expired in queue without successful delivery (KumoMTA Expiration)',
			'acceptance_rate' => 'Delivery / (Delivery + Bounce + Expiration). TransientFailure excluded. Reception/Rejection excluded.'
		),
		'days' => $bounds['days'],
		'range' => array('start_ts' => $bounds['start_ts'], 'end_ts' => $bounds['end_ts']),
		'kpis' => array(
			'remote_accepted' => $counts['Delivery'],
			'permanent_bounces' => $counts['Bounce'],
			'deferred' => $counts['TransientFailure'],
			'expired' => $counts['Expiration'],
			'remote_acceptance_rate' => $rate,
			'remote_acceptance_rate_label' => $rate === null ? 'Unavailable' : ($rate.'%')
		),
		'series' => rd_series_daily($bounds['start_ts'], $bounds['end_ts']),
		'smtp_codes' => rd_smtp_code_breakdown($bounds['start_ts'], $bounds['end_ts']),
		'mx' => rd_mx_breakdown($bounds['start_ts'], $bounds['end_ts']),
		'failures' => rd_failure_analysis($bounds['start_ts'], $bounds['end_ts']),
		'recent' => rd_recent_events($bounds['start_ts'], $bounds['end_ts'], 40, 0),
		'last_ingest_at' => rd_meta_get('last_ingest_at', ''),
		'empty_message' => 'Waiting for KumoMTA delivery events'
	);
}
