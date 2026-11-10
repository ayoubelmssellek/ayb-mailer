<?php
/**
 * Warm-up governor (Phase 4) — primary method: kumomta.
 * No blind auto-increase; no fake engagement.
 * Storage: data/warmup.sqlite
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';

if (!defined('AYB_WARMUP_DB')) {
	define('AYB_WARMUP_DB', __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'warmup.sqlite');
}

function warmup_config()
{
	ayb_env_load();
	return array(
		'enabled' => ayb_env_bool('WARMUP_ENABLED', true),
		'default_daily' => max(1, intval(ayb_env('WARMUP_DEFAULT_DAILY_CAP', '50'))),
		'default_hourly' => max(1, intval(ayb_env('WARMUP_DEFAULT_HOURLY_CAP', '20'))),
		// ~1 email / 3 minutes for 20/hour pacing (server-side, not JS-only)
		'min_interval_seconds' => max(0, intval(ayb_env('WARMUP_MIN_INTERVAL_SECONDS', '180'))),
		'default_state' => 'paused',
		'min_sample' => max(5, intval(ayb_env('WARMUP_METRIC_MIN_SAMPLE', '20'))),
		'max_hard_bounce_rate' => floatval(ayb_env('WARMUP_MAX_HARD_BOUNCE_RATE', '0.05')),
		'max_complaint_rate' => floatval(ayb_env('WARMUP_MAX_COMPLAINT_RATE', '0.002')),
		'max_fail_rate' => floatval(ayb_env('WARMUP_MAX_FAIL_RATE', '0.25')),
		'method' => 'kumomta'
	);
}

function warmup_ensure_dirs()
{
	$dir = dirname(AYB_WARMUP_DB);
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

function warmup_db()
{
	static $pdo = null;
	if ($pdo instanceof PDO) return $pdo;
	warmup_ensure_dirs();
	$pdo = new PDO('sqlite:'.AYB_WARMUP_DB);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA journal_mode=WAL');
	warmup_db_migrate($pdo);
	return $pdo;
}

function warmup_db_migrate(PDO $pdo)
{
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_plans (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		method TEXT NOT NULL UNIQUE,
		enabled INTEGER NOT NULL DEFAULT 1,
		start_date TEXT NOT NULL,
		current_daily_cap INTEGER NOT NULL,
		hourly_cap INTEGER NOT NULL,
		provider_caps TEXT NOT NULL DEFAULT '{}',
		state TEXT NOT NULL DEFAULT 'active',
		require_manual_increase INTEGER NOT NULL DEFAULT 1,
		notes TEXT NOT NULL DEFAULT '',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_days (
		plan_id INTEGER NOT NULL,
		day TEXT NOT NULL,
		attempted INTEGER NOT NULL DEFAULT 0,
		accepted INTEGER NOT NULL DEFAULT 0,
		excluded INTEGER NOT NULL DEFAULT 0,
		failed INTEGER NOT NULL DEFAULT 0,
		soft_bounce INTEGER NOT NULL DEFAULT 0,
		hard_bounce INTEGER NOT NULL DEFAULT 0,
		complaint INTEGER NOT NULL DEFAULT 0,
		PRIMARY KEY(plan_id, day)
	)");
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_hours (
		plan_id INTEGER NOT NULL,
		hour_key TEXT NOT NULL,
		accepted INTEGER NOT NULL DEFAULT 0,
		PRIMARY KEY(plan_id, hour_key)
	)");
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_provider_day (
		plan_id INTEGER NOT NULL,
		day TEXT NOT NULL,
		provider TEXT NOT NULL,
		accepted INTEGER NOT NULL DEFAULT 0,
		PRIMARY KEY(plan_id, day, provider)
	)");
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_decisions (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		plan_id INTEGER NOT NULL,
		action TEXT NOT NULL,
		reason TEXT NOT NULL,
		actor TEXT NOT NULL DEFAULT 'system',
		meta_json TEXT NOT NULL DEFAULT '{}',
		created_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_warmup_decisions_plan ON warmup_decisions(plan_id, id)");
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_pending_increases (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		plan_id INTEGER NOT NULL,
		requested_daily_cap INTEGER NOT NULL,
		requested_hourly_cap INTEGER NOT NULL DEFAULT 0,
		reason TEXT NOT NULL DEFAULT '',
		status TEXT NOT NULL DEFAULT 'pending',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		resolved_at TEXT,
		resolved_by TEXT NOT NULL DEFAULT ''
	)");
	// Append-only attempt ledger for rolling 60-minute caps + pacing + multi-worker safety.
	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_send_events (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		plan_id INTEGER NOT NULL,
		email TEXT NOT NULL DEFAULT '',
		event_type TEXT NOT NULL,
		created_at TEXT NOT NULL DEFAULT (datetime('now'))
	)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_warmup_events_plan_time ON warmup_send_events(plan_id, created_at)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_warmup_events_plan_type ON warmup_send_events(plan_id, event_type, created_at)");

	$pdo->exec("CREATE TABLE IF NOT EXISTS warmup_meta (
		meta_key TEXT PRIMARY KEY,
		meta_value TEXT NOT NULL DEFAULT ''
	)");
}

function warmup_meta_get($key, $default = '')
{
	$st = warmup_db()->prepare('SELECT meta_value FROM warmup_meta WHERE meta_key=?');
	$st->execute(array((string)$key));
	$v = $st->fetchColumn();
	return ($v === false || $v === null) ? $default : (string)$v;
}

function warmup_meta_set($key, $value)
{
	warmup_db()->prepare('INSERT OR REPLACE INTO warmup_meta(meta_key, meta_value) VALUES(?,?)')
		->execute(array((string)$key, (string)$value));
}

/**
 * Ensure 20/hr controller defaults: paused until Dashboard Resume; hourly cap 20.
 */
function warmup_bootstrap_v20hr()
{
	if (warmup_meta_get('paused_boot_v20hr', '') === '1') return;
	$cfg = warmup_config();
	$pdo = warmup_db();
	$hourly = intval($cfg['default_hourly']);
	$pdo->prepare("UPDATE warmup_plans SET state='paused', hourly_cap=?, updated_at=datetime('now') WHERE method='kumomta'")
		->execute(array($hourly));
	$row = $pdo->query("SELECT id FROM warmup_plans WHERE method='kumomta'")->fetch(PDO::FETCH_ASSOC);
	if ($row) {
		warmup_decision(intval($row['id']), 'state_paused', 'Boot: warm-up PAUSED for 20 emails/hour controller (Resume from Dashboard to start)', 'system', array(
			'hourly_cap' => $hourly,
			'min_interval_seconds' => intval($cfg['min_interval_seconds'])
		));
	}
	warmup_meta_set('paused_boot_v20hr', '1');
}

function warmup_today()
{
	return gmdate('Y-m-d');
}

function warmup_hour_key()
{
	return gmdate('Y-m-d-H');
}

function warmup_provider_from_email($email)
{
	$email = strtolower(trim((string)$email));
	$at = strrpos($email, '@');
	if ($at === false) return 'other';
	$dom = substr($email, $at + 1);
	if ($dom === 'gmail.com' || $dom === 'googlemail.com') return 'gmail';
	if (in_array($dom, array('outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'outlook.fr', 'hotmail.fr'), true)) return 'microsoft';
	if (strpos($dom, 'outlook.') === 0 || strpos($dom, 'hotmail.') === 0) return 'microsoft';
	if ($dom === 'yahoo.com' || $dom === 'ymail.com' || $dom === 'yahoo.fr' || strpos($dom, 'yahoo.') === 0) return 'yahoo';
	if ($dom === 'icloud.com' || $dom === 'me.com' || $dom === 'mac.com') return 'apple';
	return 'other';
}

function warmup_decision($planId, $action, $reason, $actor = 'system', array $meta = array())
{
	$st = warmup_db()->prepare('INSERT INTO warmup_decisions(plan_id, action, reason, actor, meta_json) VALUES(?,?,?,?,?)');
	$st->execute(array(
		intval($planId),
		substr((string)$action, 0, 40),
		substr((string)$reason, 0, 500),
		substr((string)$actor, 0, 80),
		json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
	));
}

function warmup_ensure_plan($method = 'kumomta')
{
	$method = strtolower(trim((string)$method));
	if ($method === '') $method = 'kumomta';
	$cfg = warmup_config();
	$pdo = warmup_db();
	warmup_bootstrap_v20hr();
	$st = $pdo->prepare('SELECT * FROM warmup_plans WHERE method = ?');
	$st->execute(array($method));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	if ($row) return $row;
	$ins = $pdo->prepare('INSERT INTO warmup_plans(method, enabled, start_date, current_daily_cap, hourly_cap, provider_caps, state, require_manual_increase, notes)
		VALUES(?,?,?,?,?,?,?,?,?)');
	$ins->execute(array(
		$method,
		1,
		warmup_today(),
		$cfg['default_daily'],
		$cfg['default_hourly'],
		'{}',
		'paused',
		1,
		'Auto-created KumoMTA warm-up plan (starts paused)'
	));
	$id = intval($pdo->lastInsertId());
	warmup_decision($id, 'create', 'Created warm-up plan paused (daily '.$cfg['default_daily'].', hourly '.$cfg['default_hourly'].', pace '.$cfg['min_interval_seconds'].'s)', 'system');
	$st->execute(array($method));
	return $st->fetch(PDO::FETCH_ASSOC);
}

function warmup_get_plan($method = 'kumomta')
{
	return warmup_ensure_plan($method);
}

function warmup_set_state($planId, $state, $reason, $actor = 'system')
{
	$state = strtolower(trim((string)$state));
	$allowed = array('active', 'paused', 'hold', 'cooldown', 'stopped');
	if (!in_array($state, $allowed, true)) $state = 'paused';
	$pdo = warmup_db();
	$st = $pdo->prepare('UPDATE warmup_plans SET state=?, updated_at=datetime(\'now\') WHERE id=?');
	$st->execute(array($state, intval($planId)));
	warmup_decision($planId, 'state_'.$state, $reason, $actor, array('state' => $state));
}

function warmup_ensure_day($planId, $day = null)
{
	if ($day === null) $day = warmup_today();
	$pdo = warmup_db();
	$pdo->prepare('INSERT OR IGNORE INTO warmup_days(plan_id, day) VALUES(?,?)')->execute(array(intval($planId), $day));
	$st = $pdo->prepare('SELECT * FROM warmup_days WHERE plan_id=? AND day=?');
	$st->execute(array(intval($planId), $day));
	return $st->fetch(PDO::FETCH_ASSOC);
}

function warmup_day_counts($planId, $day = null)
{
	$row = warmup_ensure_day($planId, $day);
	return $row ? $row : array(
		'attempted' => 0, 'accepted' => 0, 'excluded' => 0, 'failed' => 0,
		'soft_bounce' => 0, 'hard_bounce' => 0, 'complaint' => 0
	);
}

function warmup_hour_accepted($planId, $hourKey = null)
{
	if ($hourKey === null) $hourKey = warmup_hour_key();
	$st = warmup_db()->prepare('SELECT accepted FROM warmup_hours WHERE plan_id=? AND hour_key=?');
	$st->execute(array(intval($planId), $hourKey));
	$v = $st->fetchColumn();
	return $v === false ? 0 : intval($v);
}

function warmup_provider_accepted_today($planId, $provider, $day = null)
{
	if ($day === null) $day = warmup_today();
	$st = warmup_db()->prepare('SELECT accepted FROM warmup_provider_day WHERE plan_id=? AND day=? AND provider=?');
	$st->execute(array(intval($planId), $day, $provider));
	$v = $st->fetchColumn();
	return $v === false ? 0 : intval($v);
}

function warmup_provider_caps_map($plan)
{
	$raw = isset($plan['provider_caps']) ? $plan['provider_caps'] : '{}';
	$map = json_decode((string)$raw, true);
	return is_array($map) ? $map : array();
}

function warmup_emergency_stop_active()
{
	$f = __DIR__.DIRECTORY_SEPARATOR.'stop_send.flag';
	clearstatcache(true, $f);
	return is_file($f);
}

/**
 * Evaluate metric gates for today.
 * @param bool $applyCooldown when true, flip plan to cooldown on failure (send path only)
 * @return array{ok:bool,reason:string}
 */
function warmup_evaluate_metrics($plan, $applyCooldown = false)
{
	$cfg = warmup_config();
	$planId = intval($plan['id']);
	$day = warmup_day_counts($planId);
	$accepted = intval($day['accepted']);
	if ($accepted < intval($cfg['min_sample'])) {
		return array('ok' => true, 'reason' => 'sample below minimum ('.$accepted.'/'.$cfg['min_sample'].') — gates skipped');
	}
	$hard = intval($day['hard_bounce']);
	$comp = intval($day['complaint']);
	$fail = intval($day['failed']);
	$hardRate = $hard / max(1, $accepted);
	$compRate = $comp / max(1, $accepted);
	$failRate = $fail / max(1, $accepted + $fail);

	if ($hardRate > floatval($cfg['max_hard_bounce_rate'])) {
		$reason = 'Hard-bounce rate '.round($hardRate * 100, 2).'% exceeds max '.round($cfg['max_hard_bounce_rate'] * 100, 2).'% ('.$hard.'/'.$accepted.') — cooldown';
		if ($applyCooldown && $plan['state'] !== 'cooldown') warmup_set_state($planId, 'cooldown', $reason, 'system');
		return array('ok' => false, 'reason' => $reason);
	}
	if ($compRate > floatval($cfg['max_complaint_rate'])) {
		$reason = 'Complaint rate '.round($compRate * 100, 3).'% exceeds max '.round($cfg['max_complaint_rate'] * 100, 3).'% ('.$comp.'/'.$accepted.') — cooldown';
		if ($applyCooldown && $plan['state'] !== 'cooldown') warmup_set_state($planId, 'cooldown', $reason, 'system');
		return array('ok' => false, 'reason' => $reason);
	}
	if ($failRate > floatval($cfg['max_fail_rate'])) {
		$reason = 'Fail rate '.round($failRate * 100, 2).'% exceeds max '.round($cfg['max_fail_rate'] * 100, 2).'% ('.$fail.' fails / '.($accepted + $fail).' outcomes) — cooldown';
		if ($applyCooldown && $plan['state'] !== 'cooldown') warmup_set_state($planId, 'cooldown', $reason, 'system');
		return array('ok' => false, 'reason' => $reason);
	}
	return array('ok' => true, 'reason' => 'metrics healthy');
}

function warmup_rolling_attempt_count($planId, $windowSeconds = 3600)
{
	$planId = intval($planId);
	$windowSeconds = max(60, intval($windowSeconds));
	$st = warmup_db()->prepare("SELECT COUNT(*) FROM warmup_send_events
		WHERE plan_id=? AND event_type='attempt'
		AND created_at >= datetime('now', ?)");
	$st->execute(array($planId, '-'.$windowSeconds.' seconds'));
	return intval($st->fetchColumn());
}

function warmup_last_attempt_at($planId)
{
	$st = warmup_db()->prepare("SELECT created_at FROM warmup_send_events
		WHERE plan_id=? AND event_type='attempt' ORDER BY id DESC LIMIT 1");
	$st->execute(array(intval($planId)));
	$v = $st->fetchColumn();
	return $v === false ? '' : (string)$v;
}

function warmup_next_send_at($planId, $minIntervalSeconds)
{
	$minIntervalSeconds = max(0, intval($minIntervalSeconds));
	$last = warmup_last_attempt_at($planId);
	if ($last === '' || $minIntervalSeconds < 1) {
		return gmdate('c');
	}
	$ts = strtotime($last.' UTC');
	if ($ts === false) $ts = time();
	$next = $ts + $minIntervalSeconds;
	if ($next < time()) $next = time();
	return gmdate('c', $next);
}

function warmup_retry_after_seconds($planId, $minIntervalSeconds)
{
	$minIntervalSeconds = max(0, intval($minIntervalSeconds));
	if ($minIntervalSeconds < 1) return 0;
	$last = warmup_last_attempt_at($planId);
	if ($last === '') return 0;
	$ts = strtotime($last.' UTC');
	if ($ts === false) return 0;
	$left = ($ts + $minIntervalSeconds) - time();
	return $left > 0 ? $left : 0;
}

/**
 * Pre-send governor (server-side).
 * Uses SQLite BEGIN IMMEDIATE so parallel workers cannot bypass rolling/hourly/pacing limits.
 * Hourly = rolling 60-minute attempt window (not calendar hour only).
 *
 * @return array{ok:bool,halt:bool,wait:bool,err:string,reason:string,remaining:array,retry_after?:int,reservation_id?:int}
 */
function warmup_allow_send($method = 'kumomta', $email = '', array $opts = array())
{
	$cfg = warmup_config();
	$out = array(
		'ok' => true,
		'halt' => false,
		'wait' => false,
		'err' => '',
		'reason' => '',
		'remaining' => array(),
		'retry_after' => 0,
		'next_send_at' => '',
		'reservation_id' => 0
	);
	if (empty($cfg['enabled']) || !empty($opts['skip'])) {
		$out['reason'] = 'warm-up disabled or skipped';
		return $out;
	}

	$plan = warmup_get_plan($method);
	if (empty($plan['enabled'])) {
		$out['reason'] = 'plan disabled';
		return $out;
	}

	if (warmup_emergency_stop_active()) {
		$out['ok'] = false;
		$out['halt'] = true;
		$out['err'] = 'Emergency stop flag active';
		$out['reason'] = $out['err'];
		return $out;
	}

	$state = isset($plan['state']) ? $plan['state'] : 'paused';
	if (in_array($state, array('paused', 'hold', 'stopped', 'cooldown'), true)) {
		$out['ok'] = false;
		$out['halt'] = true;
		$out['err'] = 'Warm-up state is '.$state;
		$out['reason'] = $out['err'];
		return $out;
	}

	$metrics = warmup_evaluate_metrics($plan, true);
	if (empty($metrics['ok'])) {
		$out['ok'] = false;
		$out['halt'] = true;
		$out['err'] = $metrics['reason'];
		$out['reason'] = $metrics['reason'];
		return $out;
	}

	$planId = intval($plan['id']);
	$dailyCap = max(0, intval($plan['current_daily_cap']));
	$hourlyCap = max(0, intval($plan['hourly_cap']));
	$minInterval = intval($cfg['min_interval_seconds']);
	$provider = warmup_provider_from_email($email);
	$pcaps = warmup_provider_caps_map($plan);
	$providerCap = isset($pcaps[$provider]) ? intval($pcaps[$provider]) : null;

	$pdo = warmup_db();
	$locked = false;
	$unlock = function ($commit) use ($pdo, &$locked) {
		if (!$locked) return;
		try {
			$pdo->exec($commit ? 'COMMIT' : 'ROLLBACK');
		} catch (Exception $e) {
			try { $pdo->exec('ROLLBACK'); } catch (Exception $e2) {}
		}
		$locked = false;
	};
	try {
		$pdo->exec('BEGIN IMMEDIATE');
		$locked = true;
	} catch (Exception $e) {
		$out['ok'] = false;
		$out['halt'] = true;
		$out['err'] = 'Warm-up lock busy: '.$e->getMessage();
		$out['reason'] = $out['err'];
		return $out;
	}
	// Touch plan row under write lock so parallel PHP workers serialize.
	$pdo->exec('UPDATE warmup_plans SET updated_at=updated_at WHERE id='.$planId);

	$day = warmup_day_counts($planId);
	$acceptedToday = intval($day['accepted']);
	$rollingAttempts = warmup_rolling_attempt_count($planId, 3600);
	$calendarHourAccepted = warmup_hour_accepted($planId);
	$dailyLeft = max(0, $dailyCap - $acceptedToday);
	$hourlyLeft = max(0, $hourlyCap - $rollingAttempts);
	$providerUsed = warmup_provider_accepted_today($planId, $provider);
	$providerLeft = ($providerCap === null) ? null : max(0, $providerCap - $providerUsed);
	$retryAfter = warmup_retry_after_seconds($planId, $minInterval);
	$nextAt = warmup_next_send_at($planId, $minInterval);

	$out['remaining'] = array(
		'daily' => $dailyLeft,
		'hourly' => $hourlyLeft,
		'rolling_attempts_60m' => $rollingAttempts,
		'calendar_hour_accepted' => $calendarHourAccepted,
		'provider' => $provider,
		'provider_left' => $providerLeft,
		'daily_cap' => $dailyCap,
		'hourly_cap' => $hourlyCap,
		'min_interval_seconds' => $minInterval
	);
	$out['retry_after'] = $retryAfter;
	$out['next_send_at'] = $nextAt;

	if ($dailyLeft < 1) {
		$unlock(false);
		$out['ok'] = false;
		$out['wait'] = true;
		$out['halt'] = true;
		$out['err'] = 'Daily warm-up cap reached ('.$acceptedToday.'/'.$dailyCap.')';
		$out['reason'] = $out['err'];
		return $out;
	}
	if ($hourlyLeft < 1) {
		$unlock(false);
		$out['ok'] = false;
		$out['wait'] = true;
		$out['halt'] = true;
		$out['err'] = 'Rolling 60-minute attempt cap reached ('.$rollingAttempts.'/'.$hourlyCap.')';
		$out['reason'] = $out['err'];
		return $out;
	}
	if ($retryAfter > 0) {
		$unlock(false);
		$out['ok'] = false;
		$out['wait'] = true;
		$out['halt'] = false; // campaign may sleep then retry
		$out['pace_wait'] = true;
		$out['err'] = 'Warm-up pacing: wait '.$retryAfter.'s (min interval '.$minInterval.'s ≈ 1 / 3 min)';
		$out['reason'] = $out['err'];
		return $out;
	}
	if ($providerLeft !== null && $providerLeft < 1) {
		$unlock(false);
		$out['ok'] = false;
		$out['wait'] = true;
		$out['err'] = 'Provider cap reached for '.$provider.' ('.$providerUsed.'/'.$providerCap.')';
		$out['reason'] = $out['err'];
		$out['halt'] = false;
		$out['skip_recipient'] = true;
		return $out;
	}

	$emailNorm = strtolower(trim((string)$email));
	$ins = $pdo->prepare("INSERT INTO warmup_send_events(plan_id, email, event_type, created_at) VALUES(?,?, 'attempt', datetime('now'))");
	$ins->execute(array($planId, $emailNorm));
	$resId = intval($pdo->lastInsertId());
	$unlock(true);

	$out['reservation_id'] = $resId;
	$out['reason'] = 'allowed';
	$out['remaining']['rolling_attempts_60m'] = $rollingAttempts + 1;
	$out['remaining']['hourly'] = max(0, $hourlyCap - ($rollingAttempts + 1));
	$out['next_send_at'] = warmup_next_send_at($planId, $minInterval);
	return $out;
}

function warmup_bump($planId, $field, $email = '')
{
	$planId = intval($planId);
	$day = warmup_today();
	$pdo = warmup_db();
	warmup_ensure_day($planId, $day);
	$allowed = array('attempted', 'accepted', 'excluded', 'failed', 'soft_bounce', 'hard_bounce', 'complaint');
	if (!in_array($field, $allowed, true)) return;
	$pdo->exec('UPDATE warmup_days SET '.$field.' = '.$field.' + 1 WHERE plan_id='.$planId.' AND day='.$pdo->quote($day));
	if ($field === 'accepted') {
		$hk = warmup_hour_key();
		$pdo->prepare('INSERT INTO warmup_hours(plan_id, hour_key, accepted) VALUES(?,?,1)
			ON CONFLICT(plan_id, hour_key) DO UPDATE SET accepted = accepted + 1')->execute(array($planId, $hk));
		$prov = warmup_provider_from_email($email);
		$pdo->prepare('INSERT INTO warmup_provider_day(plan_id, day, provider, accepted) VALUES(?,?,?,1)
			ON CONFLICT(plan_id, day, provider) DO UPDATE SET accepted = accepted + 1')->execute(array($planId, $day, $prov));
	}
}

function warmup_record_outcome($method, $email, $status, array $extra = array())
{
	$cfg = warmup_config();
	if (empty($cfg['enabled'])) return;
	$plan = warmup_get_plan($method);
	if (empty($plan['enabled'])) return;
	$planId = intval($plan['id']);
	warmup_bump($planId, 'attempted', $email);
	$status = strtolower((string)$status);
	$eventType = 'failed';
	if ($status === 'accepted' || $status === 'ok') {
		warmup_bump($planId, 'accepted', $email);
		$eventType = 'accepted';
	} else if ($status === 'excluded') {
		warmup_bump($planId, 'excluded', $email);
		$eventType = 'excluded';
	} else if ($status === 'soft_bounce') {
		warmup_bump($planId, 'failed', $email);
		warmup_bump($planId, 'soft_bounce', $email);
		$eventType = 'soft_bounce';
	} else if ($status === 'hard_bounce') {
		warmup_bump($planId, 'failed', $email);
		warmup_bump($planId, 'hard_bounce', $email);
		$eventType = 'hard_bounce';
	} else if ($status === 'complaint') {
		warmup_bump($planId, 'complaint', $email);
		$eventType = 'complaint';
	} else {
		warmup_bump($planId, 'failed', $email);
		$code = isset($extra['smtp_code']) ? intval($extra['smtp_code']) : 0;
		if ($code >= 500) {
			warmup_bump($planId, 'hard_bounce', $email);
			$eventType = 'hard_bounce';
		} else if ($code >= 400) {
			warmup_bump($planId, 'soft_bounce', $email);
			$eventType = 'soft_bounce';
		}
	}
	try {
		warmup_db()->prepare("INSERT INTO warmup_send_events(plan_id, email, event_type, created_at) VALUES(?,?,?,datetime('now'))")
			->execute(array($planId, strtolower(trim((string)$email)), $eventType));
	} catch (Exception $e) {
		// ledger is additive; counters already updated
	}
}

function warmup_request_increase($method, $newDaily, $newHourly, $reason, $actor = 'operator')
{
	$plan = warmup_get_plan($method);
	$planId = intval($plan['id']);
	$newDaily = max(1, intval($newDaily));
	$newHourly = max(1, intval($newHourly));
	if (!empty($plan['require_manual_increase']) && $newDaily > intval($plan['current_daily_cap'])) {
		// pending only — do not raise yet
		$pdo = warmup_db();
		$pdo->prepare('UPDATE warmup_pending_increases SET status=\'rejected\', resolved_at=datetime(\'now\'), resolved_by=? WHERE plan_id=? AND status=\'pending\'')
			->execute(array('superseded', $planId));
		$pdo->prepare('INSERT INTO warmup_pending_increases(plan_id, requested_daily_cap, requested_hourly_cap, reason, status) VALUES(?,?,?,?,\'pending\')')
			->execute(array($planId, $newDaily, $newHourly, substr((string)$reason, 0, 500)));
		$id = intval($pdo->lastInsertId());
		warmup_decision($planId, 'increase_requested', 'Requested daily '.$newDaily.' / hourly '.$newHourly.': '.$reason, $actor, array('pending_id' => $id));
		return array('ok' => true, 'pending' => true, 'pending_id' => $id, 'applied' => false);
	}
	// decreases or manual_increase disabled can apply immediately
	return warmup_apply_caps($planId, $newDaily, $newHourly, $reason, $actor);
}

function warmup_apply_caps($planId, $daily, $hourly, $reason, $actor = 'operator')
{
	$planId = intval($planId);
	$daily = max(1, intval($daily));
	$hourly = max(1, intval($hourly));
	$pdo = warmup_db();
	$pdo->prepare('UPDATE warmup_plans SET current_daily_cap=?, hourly_cap=?, updated_at=datetime(\'now\') WHERE id=?')
		->execute(array($daily, $hourly, $planId));
	warmup_decision($planId, 'caps_set', $reason.' (daily='.$daily.', hourly='.$hourly.')', $actor, array('daily' => $daily, 'hourly' => $hourly));
	return array('ok' => true, 'pending' => false, 'applied' => true, 'daily' => $daily, 'hourly' => $hourly);
}

function warmup_approve_increase($pendingId, $actor = 'operator')
{
	$pdo = warmup_db();
	$st = $pdo->prepare('SELECT * FROM warmup_pending_increases WHERE id=?');
	$st->execute(array(intval($pendingId)));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	if (!$row || $row['status'] !== 'pending') {
		return array('ok' => false, 'err' => 'Pending increase not found');
	}
	$planId = intval($row['plan_id']);
	$plan = $pdo->query('SELECT * FROM warmup_plans WHERE id='.$planId)->fetch(PDO::FETCH_ASSOC);
	// Refuse auto-approve if metrics currently bad
	if ($plan) {
		$m = warmup_evaluate_metrics($plan);
		if (empty($m['ok'])) {
			return array('ok' => false, 'err' => 'Cannot approve while metrics gate failed: '.$m['reason']);
		}
		// Reload plan after possible cooldown flip
		$plan = $pdo->query('SELECT * FROM warmup_plans WHERE id='.$planId)->fetch(PDO::FETCH_ASSOC);
		if ($plan && in_array($plan['state'], array('cooldown', 'stopped'), true)) {
			return array('ok' => false, 'err' => 'Cannot approve increase while state is '.$plan['state']);
		}
	}
	$r = warmup_apply_caps($planId, $row['requested_daily_cap'], $row['requested_hourly_cap'], 'Approved pending increase #'.$pendingId.': '.$row['reason'], $actor);
	$pdo->prepare('UPDATE warmup_pending_increases SET status=\'approved\', resolved_at=datetime(\'now\'), resolved_by=? WHERE id=?')
		->execute(array($actor, intval($pendingId)));
	warmup_decision($planId, 'increase_approved', 'Approved pending #'.$pendingId, $actor);
	$r['pending_id'] = intval($pendingId);
	return $r;
}

function warmup_reject_increase($pendingId, $actor = 'operator', $detail = '')
{
	$pdo = warmup_db();
	$st = $pdo->prepare('SELECT * FROM warmup_pending_increases WHERE id=?');
	$st->execute(array(intval($pendingId)));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	if (!$row || $row['status'] !== 'pending') {
		return array('ok' => false, 'err' => 'Pending increase not found');
	}
	$pdo->prepare('UPDATE warmup_pending_increases SET status=\'rejected\', resolved_at=datetime(\'now\'), resolved_by=? WHERE id=?')
		->execute(array($actor, intval($pendingId)));
	warmup_decision(intval($row['plan_id']), 'increase_rejected', 'Rejected pending #'.$pendingId.($detail !== '' ? ': '.$detail : ''), $actor);
	return array('ok' => true);
}

function warmup_update_plan($method, array $fields, $actor = 'operator')
{
	$plan = warmup_get_plan($method);
	$planId = intval($plan['id']);
	$pdo = warmup_db();
	$set = array();
	$args = array();
	if (array_key_exists('enabled', $fields)) {
		$set[] = 'enabled=?';
		$args[] = !empty($fields['enabled']) ? 1 : 0;
	}
	if (array_key_exists('notes', $fields)) {
		$set[] = 'notes=?';
		$args[] = substr((string)$fields['notes'], 0, 500);
	}
	if (array_key_exists('provider_caps', $fields)) {
		$pc = $fields['provider_caps'];
		if (is_array($pc)) $pc = json_encode($pc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$set[] = 'provider_caps=?';
		$args[] = (string)$pc;
	}
	if (array_key_exists('require_manual_increase', $fields)) {
		$set[] = 'require_manual_increase=?';
		$args[] = !empty($fields['require_manual_increase']) ? 1 : 0;
	}
	// Direct daily/hourly set: if increasing and require_manual → pending path
	if (isset($fields['current_daily_cap']) || isset($fields['hourly_cap'])) {
		$daily = isset($fields['current_daily_cap']) ? intval($fields['current_daily_cap']) : intval($plan['current_daily_cap']);
		$hourly = isset($fields['hourly_cap']) ? intval($fields['hourly_cap']) : intval($plan['hourly_cap']);
		if (!empty($plan['require_manual_increase']) && $daily > intval($plan['current_daily_cap'])) {
			$req = warmup_request_increase($method, $daily, $hourly, isset($fields['reason']) ? $fields['reason'] : 'Dashboard increase request', $actor);
			// still apply other field updates
		} else {
			warmup_apply_caps($planId, $daily, $hourly, isset($fields['reason']) ? $fields['reason'] : 'Dashboard cap update', $actor);
		}
	}
	if (!empty($set)) {
		$set[] = 'updated_at=datetime(\'now\')';
		$args[] = $planId;
		$pdo->prepare('UPDATE warmup_plans SET '.implode(',', $set).' WHERE id=?')->execute($args);
		warmup_decision($planId, 'plan_update', 'Plan fields updated', $actor, $fields);
	}
	return array('ok' => true, 'plan' => warmup_get_plan($method));
}

function warmup_status($method = 'kumomta')
{
	$cfg = warmup_config();
	$plan = warmup_get_plan($method);
	$planId = intval($plan['id']);
	$day = warmup_day_counts($planId);
	$hourAcc = warmup_hour_accepted($planId);
	$pdo = warmup_db();
	$pending = $pdo->prepare('SELECT * FROM warmup_pending_increases WHERE plan_id=? AND status=\'pending\' ORDER BY id DESC');
	$pending->execute(array($planId));
	$pendingRows = $pending->fetchAll(PDO::FETCH_ASSOC);
	$dec = $pdo->prepare('SELECT id, action, reason, actor, created_at FROM warmup_decisions WHERE plan_id=? ORDER BY id DESC LIMIT 30');
	$dec->execute(array($planId));
	$prov = $pdo->prepare('SELECT provider, accepted FROM warmup_provider_day WHERE plan_id=? AND day=?');
	$prov->execute(array($planId, warmup_today()));
	$provRows = $prov->fetchAll(PDO::FETCH_ASSOC);
	$metrics = warmup_evaluate_metrics($plan);
	// reload plan if cooldown flipped
	$plan = warmup_get_plan($method);
	$rolling = warmup_rolling_attempt_count($planId, 3600);
	$minInterval = intval($cfg['min_interval_seconds']);
	$hourlyCap = intval($plan['hourly_cap']);
	$nextSend = warmup_next_send_at($planId, $minInterval);
	$retryAfter = warmup_retry_after_seconds($planId, $minInterval);
	$effectiveRate = ($minInterval > 0) ? round(3600 / $minInterval, 2) : null;
	return array(
		'ok' => true,
		'global_enabled' => !empty($cfg['enabled']),
		'emergency_stop' => warmup_emergency_stop_active(),
		'plan' => array(
			'id' => $planId,
			'method' => $plan['method'],
			'enabled' => !empty($plan['enabled']),
			'state' => $plan['state'],
			'start_date' => $plan['start_date'],
			'current_daily_cap' => intval($plan['current_daily_cap']),
			'hourly_cap' => $hourlyCap,
			'min_interval_seconds' => $minInterval,
			'provider_caps' => warmup_provider_caps_map($plan),
			'require_manual_increase' => !empty($plan['require_manual_increase']),
			'notes' => $plan['notes']
		),
		'today' => $day,
		'counters' => array(
			'sent_attempts_today' => intval($day['attempted']),
			'accepted_today' => intval($day['accepted']),
			'failed_today' => intval($day['failed']),
			'bounced_today' => intval($day['hard_bounce']) + intval($day['soft_bounce']),
			'hard_bounce_today' => intval($day['hard_bounce']),
			'soft_bounce_today' => intval($day['soft_bounce']),
			'complaint_today' => intval($day['complaint']),
			'excluded_today' => intval($day['excluded']),
			'delivered_note' => 'Inbox delivered is not tracked per-recipient for Kumo without VPS event hooks — Accepted ≠ Delivered'
		),
		'hour_accepted' => $hourAcc,
		'rolling_60m_attempts' => $rolling,
		'pacing' => array(
			'min_interval_seconds' => $minInterval,
			'target_per_hour' => $effectiveRate,
			'retry_after_seconds' => $retryAfter,
			'next_send_at' => $nextSend
		),
		'remaining' => array(
			'daily' => max(0, intval($plan['current_daily_cap']) - intval($day['accepted'])),
			'hourly' => max(0, $hourlyCap - $rolling)
		),
		'queue' => array(
			'lane' => 'kumomta',
			'note' => 'Use Recipients → KumoMTA list + mail_queue_status for pending/done counts'
		),
		'vps_readiness' => array(
			'ssh_verified' => false,
			'note' => 'SSH to VPS was unavailable (publickey denied). DNS/TCP checks are separate from container health.'
		),
		'providers_today' => $provRows,
		'pending_increases' => $pendingRows,
		'decisions' => $dec->fetchAll(PDO::FETCH_ASSOC),
		'metrics' => $metrics,
		'gates' => array(
			'min_sample' => $cfg['min_sample'],
			'max_hard_bounce_rate' => $cfg['max_hard_bounce_rate'],
			'max_complaint_rate' => $cfg['max_complaint_rate'],
			'max_fail_rate' => $cfg['max_fail_rate']
		)
	);
}

function warmup_series($method = 'kumomta', $days = 14)
{
	$plan = warmup_get_plan($method);
	$days = max(1, min(90, intval($days)));
	$st = warmup_db()->prepare('SELECT * FROM warmup_days WHERE plan_id=? ORDER BY day DESC LIMIT '.$days);
	$st->execute(array(intval($plan['id'])));
	$rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
	return array('ok' => true, 'method' => $method, 'days' => $rows);
}
