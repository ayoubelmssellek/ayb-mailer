<?php
/**
 * Compatibility shim — Portugal callers redirect to multi-country API with country=pt.
 * Prefer sendgrid_mail_api.php?country=XX going forward.
 */
if (!isset($_GET['country']) || trim((string)$_GET['country']) === '') {
	$_GET['country'] = 'pt';
}
if (!isset($_GET['action']) || trim((string)$_GET['action']) === '') {
	$_GET['action'] = 'overview';
}
require __DIR__.DIRECTORY_SEPARATOR.'sendgrid_mail_api.php';
