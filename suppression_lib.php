<?php
/**
 * Global suppression / bounce / unsubscribe helpers.
 * Independent of SendGrid analytics SQLite and of Phase 2 message events (skipped).
 * Storage: data/suppressions.sqlite
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'unsub_client.php';

if (!defined('AYB_SUPPRESSIONS_DB')) {
	define('AYB_SUPPRESSIONS_DB', __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'suppressions.sqlite');
}

function suppression_config()
{
	ayb_env_load();
	$svc = unsub_client_config();
	return array(
		'enabled' => ayb_env_bool('SUPPRESSION_ENABLED', true),
		'soft_max' => max(1, intval(ayb_env('SUPPRESSION_SOFT_BOUNCE_MAX', '3'))),
		'soft_days' => max(1, intval(ayb_env('SUPPRESSION_SOFT_BOUNCE_DAYS', '14'))),
		// Legacy local HMAC base (dev only when service disabled)
		'unsub_base_url' => rtrim(ayb_env('UNSUBSCRIBE_BASE_URL', ''), '/'),
		'unsub_secret' => ayb_env('UNSUBSCRIBE_SECRET', ''),
		'mailto_unsub' => ayb_env('UNSUBSCRIBE_MAILTO', ''),
		'service_enabled' => !empty($svc['enabled']),
		'service_base' => $svc['base_url'],
		'require_fresh' => !empty($svc['require_fresh']),
		'cache_max_age' => intval($svc['cache_max_age'])
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
	// Push to central SoT when service enabled (best-effort; local cache remains authoritative for this process)
	if ($changed && unsub_client_ready() && $actor !== 'unsub_sync') {
		$push = unsub_client_push_suppression($email, $reason, $sourceMethod, $detail, $actor);
		$out['remote_ok'] = !empty($push['ok']);
		if (empty($push['ok'])) $out['remote_err'] = isset($push['err']) ? $push['err'] : 'push_failed';
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
	// Central SoT: admin-only remote remove first when service enabled
	if (unsub_client_ready()) {
		$remote = unsub_client_admin_remove($email, $detail !== '' ? $detail : 'manual remove', $actor);
		if (empty($remote['ok'])) {
			$out['err'] = 'remote_remove_denied_or_failed';
			if (!empty($remote['err'])) $out['err'] .= ':'.$remote['err'];
			return $out;
		}
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
 * Pull central suppressions into local SQLite cache.
 */
function suppression_sync_from_service()
{
	$out = array('ok' => false, 'pulled' => 0, 'err' => '');
	if (!unsub_client_ready()) {
		$out['err'] = 'service_disabled';
		return $out;
	}
	$pull = unsub_client_pull(function ($row) {
		$email = isset($row['email']) ? $row['email'] : (isset($row['email_norm']) ? $row['email_norm'] : '');
		$email = suppression_normalize_email($email);
		if ($email === '') return;
		if (!empty($row['removed_at'])) {
			$prev = suppression_get($email);
			if ($prev) {
				$st = suppression_db()->prepare('DELETE FROM suppression_global WHERE email = ? COLLATE NOCASE');
				$st->execute(array($email));
				suppression_audit($email, 'remove', isset($prev['reason']) ? $prev['reason'] : '', 'unsub_sync', 'Remote soft-remove sync', 'unsub_sync');
			}
			return;
		}
		$reason = isset($row['reason']) ? $row['reason'] : 'manual';
		$source = isset($row['source']) ? $row['source'] : 'unsub_sync';
		$detail = isset($row['detail']) ? $row['detail'] : 'synced from central';
		// Local write without re-push
		$emailN = $email;
		$reason = suppression_valid_reason($reason);
		$pdo = suppression_db();
		$prev = suppression_get($emailN);
		$st = $pdo->prepare('INSERT INTO suppression_global(email, reason, source_method, detail, created_at, updated_at)
			VALUES(?,?,?,?,datetime(\'now\'),datetime(\'now\'))
			ON CONFLICT(email) DO UPDATE SET
				reason=excluded.reason,
				source_method=excluded.source_method,
				detail=excluded.detail,
				updated_at=datetime(\'now\')');
		$st->execute(array($emailN, $reason, substr((string)$source, 0, 40), substr((string)$detail, 0, 500)));
		$changed = !$prev || (isset($prev['reason']) && $prev['reason'] !== $reason);
		if ($changed) suppression_audit($emailN, $prev ? 'update' : 'add', $reason, $source, $detail, 'unsub_sync');
	});
	return $pull;
}

/**
 * Whether marketing sends may proceed under fail-closed sync policy.
 * @return array{ok:bool,err:string,cache_age:int}
 */
function suppression_sync_allow_marketing()
{
	$cfg = suppression_config();
	$out = array('ok' => true, 'err' => '', 'cache_age' => 0);
	if (empty($cfg['service_enabled'])) return $out;
	if (!unsub_client_ready()) {
		$out['ok'] = false;
		$out['err'] = 'Unsubscribe service enabled but not fully configured (HTTPS base + API key required)';
		return $out;
	}
	$age = unsub_client_cache_age_seconds();
	$out['cache_age'] = $age;
	// Opportunistic pull when stale-ish
	if ($age > min(60, intval($cfg['cache_max_age']))) {
		suppression_sync_from_service();
		$age = unsub_client_cache_age_seconds();
		$out['cache_age'] = $age;
	}
	if (!empty($cfg['require_fresh']) && $age > intval($cfg['cache_max_age'])) {
		$out['ok'] = false;
		$out['err'] = 'Unsubscribe cache stale ('.$age.'s > '.$cfg['cache_max_age'].'s) and central sync unavailable — marketing fail-closed';
		return $out;
	}
	return $out;
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

	// Live check on cache miss when central service is ready
	if (unsub_client_ready() && empty($opts['skip_remote_check'])) {
		$chk = unsub_client_check($email);
		if (!empty($chk['ok']) && is_array($chk['body']) && !empty($chk['body']['blocked'])) {
			$reason = isset($chk['body']['reason']) ? $chk['body']['reason'] : 'unsubscribe';
			$source = isset($chk['body']['source']) ? $chk['body']['source'] : 'remote_check';
			$detail = isset($chk['body']['detail']) ? $chk['body']['detail'] : 'Central suppression hit';
			suppression_add($email, $reason, $source, $detail, 'unsub_sync');
			$out['blocked'] = true;
			$out['reason'] = suppression_valid_reason($reason);
			$out['source'] = $source;
			$out['detail'] = $detail;
			return $out;
		}
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

/** Legacy HMAC secret (dev/local only when central service disabled). */
function suppression_unsub_secret()
{
	$cfg = suppression_config();
	if ($cfg['unsub_secret'] !== '') return $cfg['unsub_secret'];
	return hash('sha256', 'ayb-unsub|'.__DIR__.'|'.AYB_SUPPRESSIONS_DB);
}

function suppression_unsub_token($email, $ttlSeconds = 31536000)
{
	$email = suppression_normalize_email($email);
	$exp = time() + max(3600, intval($ttlSeconds));
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

/**
 * Issue marketing unsubscribe URL.
 * Prefers central opaque Hostinger token when UNSUB_SERVICE_ENABLED.
 * @return array{ok:bool,url:string,err:string}
 */
function suppression_unsub_issue($email, array $opts = array())
{
	$out = array('ok' => false, 'url' => '', 'err' => '');
	$email = suppression_normalize_email($email);
	if ($email === '' || strpos($email, '@') === false) {
		$out['err'] = 'invalid_email';
		return $out;
	}
	if (unsub_client_ready()) {
		$r = unsub_client_issue_token($email, $opts);
		if (!empty($r['ok']) && is_array($r['body']) && !empty($r['body']['url'])) {
			$url = (string)$r['body']['url'];
			if (stripos($url, 'https://') !== 0) {
				$out['err'] = 'non_https_url';
				return $out;
			}
			$out['ok'] = true;
			$out['url'] = $url;
			return $out;
		}
		$out['err'] = isset($r['err']) ? $r['err'] : 'token_issue_failed';
		return $out;
	}
	// Legacy local HMAC (not for production one-click)
	$out['ok'] = true;
	$out['url'] = suppression_unsub_url_legacy($email);
	return $out;
}

function suppression_unsub_url_legacy($email)
{
	$cfg = suppression_config();
	$base = $cfg['unsub_base_url'];
	if ($base === '') {
		$base = 'unsubscribe.php';
		return $base.'?token='.rawurlencode(suppression_unsub_token($email));
	}
	$token = suppression_unsub_token($email);
	$sep = (strpos($base, '?') !== false) ? '&' : '?';
	if (stripos($base, 'unsubscribe.php') === false) {
		$base = rtrim($base, '/').'/unsubscribe.php';
	}
	return $base.$sep.'token='.rawurlencode($token);
}

/** @deprecated use suppression_unsub_issue */
function suppression_unsub_url($email)
{
	$issued = suppression_unsub_issue($email);
	return !empty($issued['ok']) ? $issued['url'] : '';
}

/**
 * Append unsubscribe footer for marketing bodies.
 * @return array{html:string,text:string,url:string,ok:bool,err:string}
 */
function suppression_append_unsubscribe_footer($html, $text, $email, array $opts = array())
{
	$out = array('html' => (string)$html, 'text' => (string)$text, 'url' => '', 'ok' => false, 'err' => '');
	$issued = suppression_unsub_issue($email, $opts);
	if (empty($issued['ok']) || $issued['url'] === '') {
		$out['err'] = isset($issued['err']) ? $issued['err'] : 'no_url';
		return $out;
	}
	$url = $issued['url'];
	$out['url'] = $url;
	$safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$footerHtml = "\n<p style=\"margin-top:2em;font-size:12px;font-family:Arial,sans-serif;color:#444;\">"
		.'If you no longer wish to receive these emails, '
		.'<a href="'.$safe.'">unsubscribe here</a>.</p>';
	$footerText = "\n\n---\nIf you no longer wish to receive these emails, unsubscribe here:\n".$url."\n";
	if (trim($out['html']) !== '') $out['html'] .= $footerHtml;
	if (trim($out['text']) !== '') $out['text'] .= $footerText;
	else if (trim($out['html']) !== '') $out['text'] = trim(strip_tags($out['html']))."\n".$footerText;
	$out['ok'] = true;
	return $out;
}

/**
 * Headers for marketing List-Unsubscribe (RFC 8058 one-click when HTTPS URL available).
 * @return array{list_unsubscribe:string,list_unsubscribe_post:string,url:string,ok:bool,err:string}
 */
function suppression_list_unsubscribe_headers($email, array $opts = array())
{
	$cfg = suppression_config();
	$out = array('list_unsubscribe' => '', 'list_unsubscribe_post' => '', 'url' => '', 'ok' => false, 'err' => '');
	$issued = suppression_unsub_issue($email, $opts);
	if (empty($issued['ok']) || $issued['url'] === '') {
		$out['err'] = isset($issued['err']) ? $issued['err'] : 'no_url';
		return $out;
	}
	$url = $issued['url'];
	$out['url'] = $url;
	$parts = array();
	if ($cfg['mailto_unsub'] !== '' && strpos($cfg['mailto_unsub'], '@') !== false) {
		$parts[] = '<mailto:'.$cfg['mailto_unsub'].'?subject=unsubscribe>';
	}
	$parts[] = '<'.$url.'>';
	$out['list_unsubscribe'] = implode(', ', $parts);
	if (stripos($url, 'https://') === 0) {
		$out['list_unsubscribe_post'] = 'List-Unsubscribe=One-Click';
		$out['ok'] = true;
	} else {
		$out['err'] = 'url_not_https';
		$out['ok'] = false;
	}
	return $out;
}
