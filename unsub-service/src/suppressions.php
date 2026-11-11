<?php

function unsub_suppression_get($emailNorm)
{
	$st = unsub_db()->prepare('SELECT id, email_norm, reason, source, detail, created_at, updated_at, removed_at FROM suppression_global WHERE email_norm = ? LIMIT 1');
	$st->execute(array($emailNorm));
	$row = $st->fetch();
	if (!$row) return null;
	if (!empty($row['removed_at'])) return null;
	return $row;
}

function unsub_suppression_upsert($email, $reason, $source = '', $detail = '', $actor = 'system', $requestId = '')
{
	$emailNorm = unsub_normalize_email($email);
	$out = array('ok' => false, 'changed' => false, 'id' => 0, 'err' => '');
	if ($emailNorm === '' || strpos($emailNorm, '@') === false) {
		$out['err'] = 'invalid_email';
		return $out;
	}
	$reason = unsub_valid_reason($reason);
	$pdo = unsub_db();
	$prev = null;
	$stPrev = $pdo->prepare('SELECT id, reason, removed_at FROM suppression_global WHERE email_norm = ? LIMIT 1');
	$stPrev->execute(array($emailNorm));
	$prev = $stPrev->fetch();

	$st = $pdo->prepare('INSERT INTO suppression_global(email_norm, reason, source, detail, created_at, updated_at, removed_at)
		VALUES(?,?,?,?,NOW(),NOW(),NULL)
		ON DUPLICATE KEY UPDATE
			reason=VALUES(reason),
			source=VALUES(source),
			detail=VALUES(detail),
			updated_at=NOW(),
			removed_at=NULL');
	$st->execute(array($emailNorm, $reason, substr((string)$source, 0, 80), substr((string)$detail, 0, 500)));

	$row = unsub_suppression_get($emailNorm);
	$out['ok'] = true;
	$out['id'] = $row ? intval($row['id']) : 0;
	$changed = !$prev || !empty($prev['removed_at']) || (isset($prev['reason']) && $prev['reason'] !== $reason);
	$out['changed'] = (bool)$changed;
	if ($changed) {
		unsub_audit($emailNorm, $prev && empty($prev['removed_at']) ? 'update' : 'add', $reason, $source, $detail, $actor, $requestId);
	}
	return $out;
}

function unsub_suppression_soft_remove($email, $actor, $detail, $confirmEmail, $requestId = '')
{
	$emailNorm = unsub_normalize_email($email);
	$confirm = unsub_normalize_email($confirmEmail);
	$out = array('ok' => false, 'err' => '');
	if ($emailNorm === '' || $confirm === '' || !hash_equals($emailNorm, $confirm)) {
		$out['err'] = 'confirm_mismatch';
		return $out;
	}
	$row = unsub_suppression_get($emailNorm);
	if (!$row) {
		$out['ok'] = true;
		$out['err'] = 'not_found';
		return $out;
	}
	$st = unsub_db()->prepare('UPDATE suppression_global SET removed_at=NOW(), updated_at=NOW() WHERE email_norm=? AND removed_at IS NULL');
	$st->execute(array($emailNorm));
	unsub_audit($emailNorm, 'remove', isset($row['reason']) ? $row['reason'] : '', isset($row['source']) ? $row['source'] : '', $detail !== '' ? $detail : 'admin soft-remove', $actor, $requestId);
	$out['ok'] = true;
	return $out;
}

function unsub_suppressions_since($sinceId, $limit = 500)
{
	$sinceId = max(0, intval($sinceId));
	$limit = max(1, min(1000, intval($limit)));
	$st = unsub_db()->prepare('SELECT id, email_norm AS email, reason, source, detail, created_at, updated_at, removed_at
		FROM suppression_global WHERE id > ? ORDER BY id ASC LIMIT '.$limit);
	$st->execute(array($sinceId));
	$rows = $st->fetchAll();
	$next = $sinceId;
	foreach ($rows as $r) {
		if (intval($r['id']) > $next) $next = intval($r['id']);
	}
	return array('ok' => true, 'rows' => $rows, 'next_since_id' => $next, 'count' => count($rows));
}
