<?php
/**
 * Global suppression / bounce / unsubscribe helpers.
 * Independent of SendGrid analytics SQLite and of Phase 2 message events (skipped).
 * Storage: data/suppressions.sqlite
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';

if (!defined('AYB_SUPPRESSIONS_DB')) {
	define('AYB_SUPPRESSIONS_DB', __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'suppressions.sqlite');
}

function suppression_config()
{
	ayb_env_load();
	return array(
		'enabled' => ayb_env_bool('SUPPRESSION_ENABLED', true),
		'soft_max' => max(1, intval(ayb_env('SUPPRESSION_SOFT_BOUNCE_MAX', '3'))),
		'soft_days' => max(1, intval(ayb_env('SUPPRESSION_SOFT_BOUNCE_DAYS', '14'))),
		'unsub_base_url' => rtrim(ayb_env('UNSUBSCRIBE_BASE_URL', ''), '/'),
		'unsub_secret' => ayb_env('UNSUBSCRIBE_SECRET', ''),
		'mailto_unsub' => ayb_env('UNSUBSCRIBE_MAILTO', '')
	);
}

function suppression_ensure_dirs()
{
	$dir = dirname(AYB_SUPPRESSIONS_DB);
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

function suppression_db()
{
	static $pdo = null;
	if ($pdo instanceof PDO) return $pdo;
	suppression_ensure_dirs();
	$pdo = new PDO('sqlite:'.AYB_SUPPRESSIONS_DB);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA journal_mode=WAL');
	suppression_db_migrate($pdo);
	return $pdo;
}

function suppression_db_migrate(PDO $pdo)
{
	$pdo->exec("CREATE TABLE IF NOT EXISTS suppression_global (
		email TEXT NOT NULL COLLATE NOCASE,
		reason TEXT NOT NULL,
		source_method TEXT NOT NULL DEFAULT '',
		detail TEXT NOT NULL DEFAULT '',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now')),
		PRIMARY KEY(email)
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_supp_reason ON suppression_global(reason)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_supp_created ON suppression_global(created_at)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS suppression_audit (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		email TEXT NOT NULL COLLATE NOCASE,
		action TEXT NOT NULL,
		reason TEXT NOT NULL DEFAULT '',
		source_method TEXT NOT NULL DEFAULT '',
		detail TEXT NOT NULL DEFAULT '',
		actor TEXT NOT NULL DEFAULT 'system',
		created_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_supp_audit_email ON suppression_audit(email)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_supp_audit_created ON suppression_audit(created_at)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS soft_bounce_events (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		email TEXT NOT NULL COLLATE NOCASE,
		source_method TEXT NOT NULL DEFAULT '',
		detail TEXT NOT NULL DEFAULT '',
		created_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_soft_email_created ON soft_bounce_events(email, created_at)");
}

function suppression_normalize_email($email)
{
	return strtolower(trim((string)$email));
}

function suppression_valid_reason($reason)
{
	$reason = strtolower(trim((string)$reason));
	$ok = array('hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe', 'manual', 'invalid', 'block', 'exclude');
	return in_array($reason, $ok, true) ? $reason : 'manual';
}

function suppression_audit($email, $action, $reason = '', $sourceMethod = '', $detail = '', $actor = 'system')
{
	$email = suppression_normalize_email($email);
	if ($email === '') return;
	$st = suppression_db()->prepare('INSERT INTO suppression_audit(email, action, reason, source_method, detail, actor) VALUES(?,?,?,?,?,?)');
	$st->execute(array(
		$email,
		substr((string)$action, 0, 40),
		substr((string)$reason, 0, 40),
		substr((string)$sourceMethod, 0, 40),
		substr((string)$detail, 0, 500),
		substr((string)$actor, 0, 80)
	));
}

/**
 * Add or update a global suppression. Idempotent on email.
 * @return array{ok:bool,changed:bool,err:string}
 */
function suppression_add($email, $reason, $sourceMethod = '', $detail = '', $actor = 'system')
{
	$out = array('ok' => false, 'changed' => false, 'err' => '');
	$email = suppression_normalize_email($email);
	if ($email === '' || strpos($email, '@') === false) {
		$out['err'] = 'Invalid email';
		return $out;
	}
	$reason = suppression_valid_reason($reason);
	$pdo = suppression_db();
	$prev = suppression_get($email);
	$st = $pdo->prepare('INSERT INTO suppression_global(email, reason, source_method, detail, created_at, updated_at)
		VALUES(?,?,?,?,datetime(\'now\'),datetime(\'now\'))
		ON CONFLICT(email) DO UPDATE SET
			reason=excluded.reason,
			source_method=excluded.source_method,
			detail=excluded.detail,
			updated_at=datetime(\'now\')');
	$st->execute(array($email, $reason, substr((string)$sourceMethod, 0, 40), substr((string)$detail, 0, 500)));
	$out['ok'] = true;
	$changed = !$prev || (isset($prev['reason']) && $prev['reason'] !== $reason) || (isset($prev['detail']) && $prev['detail'] !== $detail);
	$out['changed'] = (bool)$changed;
	if ($changed) {
		suppression_audit($email, $prev ? 'update' : 'add', $reason, $sourceMethod, $detail, $actor);
	}
	return $out;
}

function suppression_get($email)
{
	$email = suppression_normalize_email($email);
	if ($email === '') return null;
	$st = suppression_db()->prepare('SELECT email, reason, source_method, detail, created_at, updated_at FROM suppression_global WHERE email = ? COLLATE NOCASE');
	$st->execute(array($email));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	return $row ? $row : null;
}

/**
 * Remove from global list (audited). Soft-bounce history is left intact.
 */
function suppression_remove($email, $actor = 'operator', $detail = '')
{
	$out = array('ok' => false, 'err' => '');
	$email = suppression_normalize_email($email);
	if ($email === '') {
		$out['err'] = 'Invalid email';
		return $out;
	}
	$prev = suppression_get($email);
	if (!$prev) {
		$out['ok'] = true;
		$out['err'] = 'not found';
		return $out;
	}
	$st = suppression_db()->prepare('DELETE FROM suppression_global WHERE email = ? COLLATE NOCASE');
	$st->execute(array($email));
	suppression_audit($email, 'remove', isset($prev['reason']) ? $prev['reason'] : '', isset($prev['source_method']) ? $prev['source_method'] : '', $detail !== '' ? $detail : 'manual remove', $actor);
	$out['ok'] = true;
	return $out;
}

/**
 * Record a soft bounce; auto-suppress when threshold reached in window.
 * @return array{ok:bool,suppressed:bool,count:int,err:string}
 */
function suppression_record_soft_bounce($email, $sourceMethod = '', $detail = '')
{
	$out = array('ok' => false, 'suppressed' => false, 'count' => 0, 'err' => '');
	$email = suppression_normalize_email($email);
	if ($email === '' || strpos($email, '@') === false) {
		$out['err'] = 'Invalid email';
		return $out;
	}
	$cfg = suppression_config();
	$pdo = suppression_db();
	$ins = $pdo->prepare('INSERT INTO soft_bounce_events(email, source_method, detail) VALUES(?,?,?)');
	$ins->execute(array($email, substr((string)$sourceMethod, 0, 40), substr((string)$detail, 0, 500)));
	suppression_audit($email, 'soft_bounce', 'soft_bounce', $sourceMethod, $detail, 'system');

	$st = $pdo->prepare('SELECT COUNT(*) FROM soft_bounce_events WHERE email = ? COLLATE NOCASE AND created_at >= datetime(\'now\', ?)');
	$st->execute(array($email, '-'.intval($cfg['soft_days']).' days'));
	$count = intval($st->fetchColumn());
	$out['count'] = $count;
	$out['ok'] = true;
	if ($count >= intval($cfg['soft_max'])) {
		$add = suppression_add(
			$email,
			'soft_bounce',
			$sourceMethod,
			'Auto-suppress after '.$count.' soft bounces in '.$cfg['soft_days'].'d. '.$detail,
			'system'
		);
		$out['suppressed'] = !empty($add['ok']);
	}
	return $out;
}

/**
 * Optional per-lane exclude file: exclude_{lane}_{cc}.txt (one email per line).
 */
function suppression_exclude_file_path($lane = 'api', $country = 'pt')
{
	if (!function_exists('queue_lane_normalize')) {
		require_once __DIR__.DIRECTORY_SEPARATOR.'queue_country.php';
	}
	$lane = queue_lane_normalize($lane);
	$cc = function_exists('queue_country_code') ? queue_country_code($country) : preg_replace('/[^a-z]/i', '', strtolower((string)$country));
	if ($cc === '') $cc = 'pt';
	$name = 'exclude_'.$lane.'_'.$cc.'.txt';
	return __DIR__.DIRECTORY_SEPARATOR.$name;
}

function suppression_in_exclude_file($email, $lane = '', $country = 'pt')
{
	$email = suppression_normalize_email($email);
	if ($email === '') return false;
	$lanes = array();
	if ($lane !== '') $lanes[] = $lane;
	else $lanes = array('api', 'smtp', 'kumomta');
	foreach ($lanes as $ln) {
		$path = suppression_exclude_file_path($ln, $country);
		if (!is_file($path)) continue;
		$fh = @fopen($path, 'r');
		if (!$fh) continue;
		while (($line = fgets($fh)) !== false) {
			$e = suppression_normalize_email($line);
			if ($e !== '' && $e === $email) {
				fclose($fh);
				return true;
			}
		}
		fclose($fh);
	}
	return false;
}

/**
 * Send-helper shaped result when blocked, or null if allowed.
 * @return array|null
 */
function suppression_blocked_send_result($email, array $opts = array())
{
	$b = suppression_is_blocked($email, $opts);
	if (empty($b['blocked'])) return null;
	$reason = isset($b['reason']) ? $b['reason'] : 'manual';
	$detail = isset($b['detail']) ? $b['detail'] : '';
	$err = 'Suppressed ('.$reason.')';
	if ($detail !== '') $err .= ': '.$detail;
	return array(
		'ok' => false,
		'status' => 'excluded',
		'excluded' => true,
		'fail' => true,
		'queued' => false,
		'err' => $err,
		'reason' => $reason,
		'source' => isset($b['source']) ? $b['source'] : '',
		'detail' => $detail,
		'http' => 0,
		'id' => '',
		'msgid' => '',
		'smtp_reply' => '',
		'smtp_code' => '',
		'profile_id' => '',
		'from_email' => ''
	);
}

/**
 * Infer hard/soft bounce from SMTP failure (Kumo/Normal). Conservative.
 */
function suppression_maybe_from_smtp_failure($email, $smtpCode, $smtpReply, $sourceMethod = '')
{
	$email = suppression_normalize_email($email);
	if ($email === '') return array('ok' => false);
	$code = intval($smtpCode);
	$reply = strtolower(trim((string)$smtpReply));
	if ($code >= 400 && $code < 500) {
		return suppression_record_soft_bounce($email, $sourceMethod, 'SMTP '.$code.' '.$smtpReply);
	}
	if (in_array($code, array(550, 551, 552, 553, 554), true)) {
		if ($reply === '' || preg_match('/user unknown|does not exist|mailbox unavailable|no such user|invalid recipient|rejected|not exist|unknown user|address rejected/', $reply)) {
			return suppression_add($email, 'hard_bounce', $sourceMethod, 'SMTP '.$code.' '.$smtpReply, 'system');
		}
	}
	return array('ok' => true, 'changed' => false);
}

/**
 * Pre-send gate.
 * @return array{blocked:bool,reason:string,source:string,detail:string}
 */
function suppression_is_blocked($email, array $opts = array())
{
	$cfg = suppression_config();
	$out = array('blocked' => false, 'reason' => '', 'source' => '', 'detail' => '');
	if (empty($cfg['enabled'])) return $out;

	$email = suppression_normalize_email($email);
	if ($email === '') {
		$out['blocked'] = true;
		$out['reason'] = 'invalid';
		$out['detail'] = 'empty email';
		return $out;
	}

	$row = suppression_get($email);
	if ($row) {
		$out['blocked'] = true;
		$out['reason'] = isset($row['reason']) ? $row['reason'] : 'manual';
		$out['source'] = isset($row['source_method']) ? $row['source_method'] : '';
		$out['detail'] = isset($row['detail']) ? $row['detail'] : '';
		return $out;
	}

	$lane = isset($opts['lane']) ? (string)$opts['lane'] : '';
	$country = isset($opts['country']) ? (string)$opts['country'] : 'pt';
	if (suppression_in_exclude_file($email, $lane, $country)) {
		$out['blocked'] = true;
		$out['reason'] = 'exclude';
		$out['source'] = 'exclude_file';
		$out['detail'] = 'Listed in exclude file';
		return $out;
	}
	return $out;
}

function suppression_counts()
{
	$rows = suppression_db()->query('SELECT reason, COUNT(*) AS c FROM suppression_global GROUP BY reason')->fetchAll(PDO::FETCH_ASSOC);
	$out = array('total' => 0);
	foreach ($rows as $r) {
		$out[$r['reason']] = intval($r['c']);
		$out['total'] += intval($r['c']);
	}
	return $out;
}

function suppression_query(array $in)
{
	$q = isset($in['q']) ? suppression_normalize_email($in['q']) : '';
	$reason = isset($in['reason']) ? strtolower(trim((string)$in['reason'])) : '';
	$page = max(1, intval(isset($in['page']) ? $in['page'] : 1));
	$per = max(1, min(200, intval(isset($in['per_page']) ? $in['per_page'] : 50)));
	$offset = ($page - 1) * $per;

	$where = array('1=1');
	$args = array();
	if ($q !== '') {
		$where[] = 'email LIKE ?';
		$args[] = '%'.$q.'%';
	}
	if ($reason !== '' && $reason !== 'all') {
		$where[] = 'reason = ?';
		$args[] = suppression_valid_reason($reason);
	}
	$sqlWhere = implode(' AND ', $where);
	$pdo = suppression_db();
	$cst = $pdo->prepare('SELECT COUNT(*) FROM suppression_global WHERE '.$sqlWhere);
	$cst->execute($args);
	$total = intval($cst->fetchColumn());
	$st = $pdo->prepare('SELECT email, reason, source_method, detail, created_at, updated_at FROM suppression_global WHERE '.$sqlWhere.' ORDER BY updated_at DESC LIMIT '.$per.' OFFSET '.$offset);
	$st->execute($args);
	return array(
		'ok' => true,
		'total' => $total,
		'page' => $page,
		'per_page' => $per,
		'rows' => $st->fetchAll(PDO::FETCH_ASSOC),
		'counts' => suppression_counts()
	);
}

function suppression_audit_query(array $in)
{
	$email = isset($in['email']) ? suppression_normalize_email($in['email']) : '';
	$page = max(1, intval(isset($in['page']) ? $in['page'] : 1));
	$per = max(1, min(200, intval(isset($in['per_page']) ? $in['per_page'] : 50)));
	$offset = ($page - 1) * $per;
	$pdo = suppression_db();
	if ($email !== '') {
		$cst = $pdo->prepare('SELECT COUNT(*) FROM suppression_audit WHERE email = ? COLLATE NOCASE');
		$cst->execute(array($email));
		$total = intval($cst->fetchColumn());
		$st = $pdo->prepare('SELECT id, email, action, reason, source_method, detail, actor, created_at FROM suppression_audit WHERE email = ? COLLATE NOCASE ORDER BY id DESC LIMIT '.$per.' OFFSET '.$offset);
		$st->execute(array($email));
	} else {
		$total = intval($pdo->query('SELECT COUNT(*) FROM suppression_audit')->fetchColumn());
		$st = $pdo->query('SELECT id, email, action, reason, source_method, detail, actor, created_at FROM suppression_audit ORDER BY id DESC LIMIT '.$per.' OFFSET '.$offset);
	}
	return array('ok' => true, 'total' => $total, 'page' => $page, 'per_page' => $per, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Map SendGrid local suppression types → global reasons and import additively.
 */
function suppression_import_sendgrid_local()
{
	$out = array('ok' => false, 'imported' => 0, 'skipped' => 0, 'err' => '');
	$lib = __DIR__.DIRECTORY_SEPARATOR.'sendgrid_analytics_lib.php';
	if (!is_file($lib)) {
		$out['err'] = 'sendgrid_analytics_lib.php missing';
		return $out;
	}
	require_once $lib;
	if (!function_exists('sg_db')) {
		$out['err'] = 'sg_db unavailable';
		return $out;
	}
	$map = array(
		'bounce' => 'hard_bounce',
		'spamreport' => 'complaint',
		'block' => 'block',
		'invalid' => 'invalid',
		'unsubscribe' => 'unsubscribe'
	);
	try {
		$pdo = sg_db();
		$rows = $pdo->query('SELECT suppression_type, email, reason, created FROM sg_suppressions')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $r) {
			$type = isset($r['suppression_type']) ? $r['suppression_type'] : '';
			$email = isset($r['email']) ? $r['email'] : '';
			if (!isset($map[$type]) || $email === '') {
				$out['skipped']++;
				continue;
			}
			$detail = 'Imported from SendGrid '.$type;
			if (!empty($r['reason'])) $detail .= ': '.$r['reason'];
			if (!empty($r['created'])) $detail .= ' (sg created '.$r['created'].')';
			$add = suppression_add($email, $map[$type], 'sendgrid', $detail, 'sendgrid_sync');
			if (!empty($add['changed'])) $out['imported']++;
			else $out['skipped']++;
		}
		$out['ok'] = true;
	} catch (Exception $e) {
		$out['err'] = 'import failed';
	}
	return $out;
}

/** HMAC token for one-click unsubscribe (email|exp). */
function suppression_unsub_secret()
{
	$cfg = suppression_config();
	if ($cfg['unsub_secret'] !== '') return $cfg['unsub_secret'];
	// Derive a stable local secret from machine path when unset (dev/LAN only)
	return hash('sha256', 'ayb-unsub|'.__DIR__.'|'.AYB_SUPPRESSIONS_DB);
}

function suppression_unsub_token($email, $ttlSeconds = 31536000)
{
	$email = suppression_normalize_email($email);
	$exp = time() + max(3600, intval($ttlSeconds));
	// Use newlines so domain dots in email never collide with separators
	$payload = $email."\n".$exp;
	$sig = hash_hmac('sha256', $payload, suppression_unsub_secret());
	return rtrim(strtr(base64_encode($payload."\n".$sig), '+/', '-_'), '=');
}

function suppression_unsub_parse_token($token)
{
	$out = array('ok' => false, 'email' => '', 'exp' => 0, 'err' => '');
	$token = strtr((string)$token, '-_', '+/');
	$pad = strlen($token) % 4;
	if ($pad) $token .= str_repeat('=', 4 - $pad);
	$raw = base64_decode($token, true);
	if ($raw === false || strpos($raw, "\n") === false) {
		$out['err'] = 'Invalid token';
		return $out;
	}
	$parts = explode("\n", $raw);
	if (count($parts) !== 3) {
		$out['err'] = 'Bad payload';
		return $out;
	}
	$email = suppression_normalize_email($parts[0]);
	$exp = intval($parts[1]);
	$sig = $parts[2];
	$payload = $email."\n".$exp;
	$expect = hash_hmac('sha256', $payload, suppression_unsub_secret());
	if (!hash_equals($expect, $sig)) {
		$out['err'] = 'Invalid signature';
		return $out;
	}
	if ($email === '' || strpos($email, '@') === false) {
		$out['err'] = 'Bad email';
		return $out;
	}
	if ($exp < time()) {
		$out['err'] = 'Token expired';
		return $out;
	}
	$out['ok'] = true;
	$out['email'] = $email;
	$out['exp'] = $exp;
	return $out;
}

function suppression_unsub_url($email)
{
	$cfg = suppression_config();
	$base = $cfg['unsub_base_url'];
	if ($base === '') {
		// Relative fallback for same-host local app
		$base = 'unsubscribe.php';
		$token = suppression_unsub_token($email);
		return $base.'?token='.rawurlencode($token);
	}
	$token = suppression_unsub_token($email);
	$sep = (strpos($base, '?') !== false) ? '&' : '?';
	// If base already points to unsubscribe.php keep it; else append
	if (stripos($base, 'unsubscribe.php') === false) {
		$base = rtrim($base, '/').'/unsubscribe.php';
	}
	return $base.$sep.'token='.rawurlencode($token);
}

/**
 * Headers for marketing List-Unsubscribe (RFC 8058 one-click when HTTPS URL available).
 * @return array{list_unsubscribe:string,list_unsubscribe_post:string}
 */
function suppression_list_unsubscribe_headers($email)
{
	$cfg = suppression_config();
	$url = suppression_unsub_url($email);
	$parts = array();
	if ($cfg['mailto_unsub'] !== '' && strpos($cfg['mailto_unsub'], '@') !== false) {
		$parts[] = '<mailto:'.$cfg['mailto_unsub'].'?subject=unsubscribe>';
	}
	// Absolute http(s) preferred for List-Unsubscribe-Post
	$abs = $url;
	if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
		// Keep relative URL in angle brackets only if no absolute base configured
		$parts[] = '<'.$url.'>';
		return array(
			'list_unsubscribe' => implode(', ', $parts),
			'list_unsubscribe_post' => ''
		);
	}
	$parts[] = '<'.$abs.'>';
	return array(
		'list_unsubscribe' => implode(', ', $parts),
		'list_unsubscribe_post' => 'List-Unsubscribe=One-Click'
	);
}
