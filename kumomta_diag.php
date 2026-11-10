<?php
/**
 * KumoMTA SMTP connection diagnostic (connect / TLS / AUTH).
 * Does not send a campaign message.
 * POST/GET auth=1 (default) to test AUTH LOGIN.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.DIRECTORY_SEPARATOR.'kumomta_mail.php';

$doAuth = true;
if (isset($_GET['auth']) && ($_GET['auth'] === '0' || $_GET['auth'] === 'false')) $doAuth = false;
if (isset($_POST['auth']) && ($_POST['auth'] === '0' || $_POST['auth'] === 'false')) $doAuth = false;

$st = kumomta_config_status();
if (empty($st['ok']) && $doAuth) {
	echo json_encode(array(
		'ok' => false,
		'err' => $st['err'],
		'status' => $st['status'],
		'steps' => array()
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

$r = kumomta_diag_connect($doAuth);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
