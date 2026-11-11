<?php

function unsub_handle_api($method, $path)
{
	$method = strtoupper($method);
	$path = '/'.trim($path, '/');

	if ($path === '/api/v1/health' || $path === '/health') {
		try {
			unsub_db()->query('SELECT 1');
			unsub_json(array('ok' => true, 'service' => 'ayb-unsub', 'db' => true));
		} catch (Exception $e) {
			unsub_json(array('ok' => false, 'service' => 'ayb-unsub', 'db' => false), 500);
		}
	}

	// Admin delete — separate key + confirm header
	if ($path === '/api/v1/suppressions' && $method === 'DELETE') {
		unsub_require_admin_key();
		$q = isset($_GET['email']) ? $_GET['email'] : '';
		$body = unsub_read_json_body();
		if ($q === '' && isset($body['email'])) $q = $body['email'];
		$confirm = '';
		if (isset($_SERVER['HTTP_X_UNSUB_CONFIRM'])) $confirm = (string)$_SERVER['HTTP_X_UNSUB_CONFIRM'];
		else if (isset($body['confirm_email'])) $confirm = (string)$body['confirm_email'];
		$detail = isset($body['detail']) ? (string)$body['detail'] : 'admin remove';
		$actor = isset($body['actor']) ? (string)$body['actor'] : 'admin';
		$r = unsub_suppression_soft_remove($q, $actor, $detail, $confirm, unsub_request_id());
		$code = !empty($r['ok']) ? 200 : 400;
		unsub_json(array('ok' => !empty($r['ok']), 'err' => isset($r['err']) ? $r['err'] : ''), $code);
	}

	// All other API routes require mailer API key
	unsub_require_api_key();

	if ($path === '/api/v1/tokens' && $method === 'POST') {
		$body = unsub_read_json_body();
		$email = isset($body['email']) ? $body['email'] : '';
		$opts = array(
			'ttl' => isset($body['ttl']) ? $body['ttl'] : null,
			'purpose' => isset($body['purpose']) ? $body['purpose'] : 'marketing',
			'provider' => isset($body['provider']) ? $body['provider'] : '',
			'issued_by' => isset($body['issued_by']) ? $body['issued_by'] : ''
		);
		if ($opts['ttl'] === null) unset($opts['ttl']);
		$r = unsub_token_issue($email, $opts);
		if (empty($r['ok'])) unsub_json(array('ok' => false, 'err' => $r['err']), 400);
		unsub_json(array(
			'ok' => true,
			'url' => $r['url'],
			'expires_at' => $r['expires_at'],
			'token_id' => $r['token_id']
		));
	}

	if ($path === '/api/v1/suppressions/check' && $method === 'GET') {
		$email = isset($_GET['email']) ? unsub_normalize_email($_GET['email']) : '';
		$row = $email !== '' ? unsub_suppression_get($email) : null;
		unsub_json(array(
			'ok' => true,
			'email' => $email,
			'blocked' => $row ? true : false,
			'reason' => $row ? $row['reason'] : '',
			'source' => $row ? $row['source'] : '',
			'detail' => $row ? $row['detail'] : ''
		));
	}

	if ($path === '/api/v1/suppressions' && $method === 'GET') {
		$since = isset($_GET['since_id']) ? $_GET['since_id'] : 0;
		$limit = isset($_GET['limit']) ? $_GET['limit'] : 500;
		unsub_json(unsub_suppressions_since($since, $limit));
	}

	if ($path === '/api/v1/suppressions' && $method === 'POST') {
		$body = unsub_read_json_body();
		$email = isset($body['email']) ? $body['email'] : '';
		$reason = isset($body['reason']) ? $body['reason'] : 'manual';
		$source = isset($body['source']) ? $body['source'] : 'api';
		$detail = isset($body['detail']) ? $body['detail'] : '';
		$actor = isset($body['actor']) ? $body['actor'] : 'mailer';
		$r = unsub_suppression_upsert($email, $reason, $source, $detail, $actor, unsub_request_id());
		if (empty($r['ok'])) unsub_json(array('ok' => false, 'err' => $r['err']), 400);
		unsub_json(array('ok' => true, 'changed' => !empty($r['changed']), 'id' => $r['id']));
	}

	unsub_json(array('ok' => false, 'err' => 'not_found'), 404);
}
