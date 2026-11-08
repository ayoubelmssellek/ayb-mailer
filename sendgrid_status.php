<?php
/**
 * SendGrid configuration status for the dashboard (never returns the API key).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.DIRECTORY_SEPARATOR.'sendgrid_mail.php';
$st = sendgrid_config_status();
echo json_encode(array(
	'ok' => !empty($st['ok']),
	'provider' => 'sendgrid',
	'endpoint' => 'https://api.sendgrid.com/v3/mail/send',
	'auth' => 'Bearer API key',
	'enabled' => !empty($st['enabled']),
	'has_api_key' => !empty($st['has_api_key']),
	'key_preview' => isset($st['key_preview']) ? $st['key_preview'] : '',
	'from_email' => $st['from_email'],
	'from_name' => $st['from_name'],
	'reply_to' => $st['reply_to'],
	'status' => $st['status'],
	'err' => $st['err'],
	'sync' => 'sendgrid_sync.php',
	'analytics_mode' => 'api_polling',
	'realtime' => false,
	'sdk_loaded' => class_exists('SendGrid')
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
