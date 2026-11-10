<?php
/**
 * KumoMTA configuration status for the dashboard (never returns the password).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.DIRECTORY_SEPARATOR.'kumomta_mail.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'sender_profiles.php';

$st = kumomta_config_status();
$profiles = sender_profile_public_list();
echo json_encode(array(
	'ok' => !empty($st['ok']),
	'provider' => 'kumomta',
	'transport' => 'smtp',
	'socks' => false,
	'host' => $st['host'],
	'port' => $st['port'],
	'encryption' => $st['encryption'],
	'verify_peer' => !empty($st['verify_peer']),
	'enabled' => !empty($st['enabled']),
	'has_username' => !empty($st['has_username']),
	'has_password' => !empty($st['has_password']),
	'username_preview' => isset($st['username_preview']) ? $st['username_preview'] : '',
	'default_from' => $st['default_from'],
	'default_from_name' => $st['default_from_name'],
	'default_reply_to' => $st['default_reply_to'],
	'timeout' => $st['timeout'],
	'status' => $st['status'],
	'err' => $st['err'],
	'note' => 'SMTP acceptance is not inbox delivery',
	'profiles' => isset($profiles['profiles']) ? $profiles['profiles'] : array(),
	'default_profile_id' => isset($profiles['default_id']) ? $profiles['default_id'] : '',
	'diag' => 'kumomta_diag.php'
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
