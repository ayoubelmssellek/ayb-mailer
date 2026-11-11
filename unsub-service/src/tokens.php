<?php

function unsub_token_raw_generate()
{
	// 32 bytes → base64url (~43 chars), opaque, no email
	$bin = random_bytes(32);
	return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function unsub_token_hash($raw)
{
	return hash('sha256', (string)$raw);
}

function unsub_token_normalize_raw($raw)
{
	$raw = trim((string)$raw);
	// allow base64url charset only
	if ($raw === '' || !preg_match('#^[A-Za-z0-9\-_]+$#', $raw)) return '';
	if (strlen($raw) < 20 || strlen($raw) > 128) return '';
	return $raw;
}

function unsub_token_issue($email, array $opts = array())
{
	$out = array('ok' => false, 'url' => '', 'expires_at' => '', 'token_id' => 0, 'err' => '');
	$emailNorm = unsub_normalize_email($email);
	if ($emailNorm === '' || strpos($emailNorm, '@') === false || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
		$out['err'] = 'invalid_email';
		return $out;
	}
	$cfg = unsub_cfg();
	$ttl = isset($opts['ttl']) ? intval($opts['ttl']) : intval($cfg['token_ttl_seconds']);
	if ($ttl < 7776000) $ttl = 7776000; // min 90d
	if ($ttl > 157680000) $ttl = 157680000; // max 5y
	$purpose = isset($opts['purpose']) ? substr((string)$opts['purpose'], 0, 40) : 'marketing';
	$provider = isset($opts['provider']) ? substr((string)$opts['provider'], 0, 40) : '';
	$issuedBy = isset($opts['issued_by']) ? substr((string)$opts['issued_by'], 0, 120) : '';

	$raw = unsub_token_raw_generate();
	$hash = unsub_token_hash($raw);
	$expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);

	$st = unsub_db()->prepare('INSERT INTO unsubscribe_tokens(token_hash, email_norm, purpose, provider, issued_by, expires_at) VALUES(?,?,?,?,?,?)');
	$st->execute(array($hash, $emailNorm, $purpose, $provider, $issuedBy, $expiresAt));
	$id = intval(unsub_db()->lastInsertId());

	$url = unsub_public_base().'/u/'.rawurlencode($raw);
	$out['ok'] = true;
	$out['url'] = $url;
	$out['expires_at'] = $expiresAt.'Z';
	$out['token_id'] = $id;
	return $out;
}

/**
 * Lookup token row by raw token (does not consume).
 * @return array{ok:bool,error:?string,row:?array}
 */
function unsub_token_lookup($raw)
{
	$raw = unsub_token_normalize_raw($raw);
	if ($raw === '') {
		return array('ok' => false, 'error' => 'invalid_token', 'row' => null);
	}
	$hash = unsub_token_hash($raw);
	$st = unsub_db()->prepare('SELECT * FROM unsubscribe_tokens WHERE token_hash = ? LIMIT 1');
	$st->execute(array($hash));
	$row = $st->fetch();
	if (!$row) return array('ok' => false, 'error' => 'invalid_token', 'row' => null);
	if (!empty($row['revoked_at'])) return array('ok' => false, 'error' => 'token_revoked', 'row' => $row);
	if (!empty($row['used_at'])) return array('ok' => false, 'error' => 'token_used', 'row' => $row);
	$exp = strtotime($row['expires_at'].' UTC');
	if ($exp !== false && $exp < time()) return array('ok' => false, 'error' => 'token_expired', 'row' => $row);
	return array('ok' => true, 'error' => null, 'row' => $row);
}

/**
 * Consume token + upsert unsubscribe suppression (transaction).
 */
function unsub_token_consume($raw, $detail = 'One-click or form unsubscribe', $actor = 'recipient')
{
	$out = array('ok' => false, 'error' => null, 'email' => '', 'already' => false);
	$lookup = unsub_token_lookup($raw);
	$requestId = unsub_request_id();

	if (!$lookup['ok']) {
		// Idempotent paths
		if ($lookup['error'] === 'token_used' && !empty($lookup['row']['email_norm'])) {
			$email = $lookup['row']['email_norm'];
			$supp = unsub_suppression_get($email);
			$out['email'] = $email;
			$out['already'] = true;
			$out['ok'] = true;
			$out['error'] = null;
			if (!$supp) {
				// used but missing suppression — repair
				unsub_suppression_upsert($email, 'unsubscribe', 'unsubscribe', 'Repair after used token', $actor, $requestId);
			}
			return $out;
		}
		if ($lookup['error'] === 'token_expired' && !empty($lookup['row']['email_norm'])) {
			$email = $lookup['row']['email_norm'];
			if (unsub_suppression_get($email)) {
				$out['ok'] = true;
				$out['already'] = true;
				$out['email'] = $email;
				return $out;
			}
		}
		$out['error'] = $lookup['error'] ? $lookup['error'] : 'invalid_token';
		return $out;
	}

	$row = $lookup['row'];
	$email = $row['email_norm'];
	$pdo = unsub_db();
	try {
		$pdo->beginTransaction();
		$upd = $pdo->prepare('UPDATE unsubscribe_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL AND revoked_at IS NULL');
		$upd->execute(array(intval($row['id'])));
		if ($upd->rowCount() < 1) {
			$pdo->rollBack();
			$out['ok'] = true;
			$out['already'] = true;
			$out['email'] = $email;
			return $out;
		}
		// commit token use then upsert (upsert does its own writes)
		$pdo->commit();
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		$out['error'] = 'server_error';
		return $out;
	}

	$add = unsub_suppression_upsert($email, 'unsubscribe', 'unsubscribe', $detail, $actor, $requestId);
	if (empty($add['ok'])) {
		$out['error'] = 'suppress_failed';
		return $out;
	}
	$out['ok'] = true;
	$out['email'] = $email;
	$out['already'] = empty($add['changed']);
	return $out;
}
