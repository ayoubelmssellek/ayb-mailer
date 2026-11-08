<?php
/**
 * Offline self-test for recipient analytics (no live Brevo send).
 * Run: php brevo_analytics_selftest.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

// Use an isolated temp DB so we don't pollute real data
define('BA_ROOT', __DIR__);
define('BA_DATA_DIR', __DIR__.DIRECTORY_SEPARATOR.'data');
define('BA_DB_PATH', BA_DATA_DIR.DIRECTORY_SEPARATOR.'brevo_analytics_selftest.sqlite');
define('BA_CONFIG_PATH', BA_DATA_DIR.DIRECTORY_SEPARATOR.'brevo_analytics_selftest_config.json');

@unlink(BA_DB_PATH);
@unlink(BA_DB_PATH.'-wal');
@unlink(BA_DB_PATH.'-shm');
@unlink(BA_CONFIG_PATH);

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

$pass = 0;
$fail = 0;
function t($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name".($detail !== '' ? " — $detail" : '')."\n"; $fail++; }
}

$pdo = ba_db();
t('DB opens + migrates', $pdo instanceof PDO);

// Local send record
ba_record_local_send('xkeysib-testkey-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user@example.com', 'November Special Offer', '<msg-1@brevo.test>', 'from@example.com');
ba_record_local_send('xkeysib-testkey-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user@example.com', 'New Product Announcement', '<msg-2@brevo.test>', 'from@example.com');
ba_record_local_send('xkeysib-testkey-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'other@example.com', 'November Special Offer', '<msg-3@brevo.test>', 'from@example.com');

$list = ba_query_messages(array('status' => 'all'), 1, 50);
t('Multiple messages same recipient kept separate', $list['total'] === 3, 'total='.$list['total']);
t('Unique emails counted', $list['unique_emails'] === 2, 'unique='.$list['unique_emails']);

// Delivered + hard bounce events
$h = ba_key_hash('xkeysib-testkey-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$r = ba_import_event($pdo, array(
	'account_key_hash' => $h,
	'email' => 'user@example.com',
	'event' => 'delivered',
	'date' => '2026-11-01T10:00:00Z',
	'messageId' => '<msg-1@brevo.test>',
	'subject' => 'November Special Offer',
	'event_source' => 'test',
	'raw_payload' => '{}'
));
t('Delivered event inserted', $r === 'inserted', $r);

$r = ba_import_event($pdo, array(
	'account_key_hash' => $h,
	'email' => 'user@example.com',
	'event' => 'hardBounce',
	'date' => '2026-11-04T10:00:00Z',
	'messageId' => '<msg-2@brevo.test>',
	'subject' => 'New Product Announcement',
	'reason' => 'mailbox unavailable',
	'event_source' => 'test',
	'raw_payload' => '{}'
));
t('Hard bounce event inserted', $r === 'inserted', $r);

// Duplicate protection
$r = ba_import_event($pdo, array(
	'account_key_hash' => $h,
	'email' => 'user@example.com',
	'event' => 'hardBounce',
	'date' => '2026-11-04T10:00:00Z',
	'messageId' => '<msg-2@brevo.test>',
	'subject' => 'New Product Announcement',
	'reason' => 'mailbox unavailable',
	'event_source' => 'test',
	'raw_payload' => '{}'
));
t('Duplicate event skipped', $r === 'skipped', $r);

$del = ba_query_messages(array('status' => 'delivered'), 1, 50);
t('Filter delivered', $del['total'] >= 1, 'total='.$del['total']);

$hb = ba_query_messages(array('status' => 'hardBounce'), 1, 50);
t('Filter hardBounce', $hb['total'] === 1, 'total='.$hb['total']);

$subj = ba_query_messages(array('status' => 'delivered', 'subject' => 'November Special Offer'), 1, 50);
t('Filter delivered + subject', $subj['total'] >= 1, 'total='.$subj['total']);

$hist = ba_recipient_history('user@example.com');
t('Recipient history has 2 messages', count($hist) === 2, 'count='.count($hist));
t('History preserves distinct subjects', $hist[0]['subject'] !== $hist[1]['subject']);

$counts = ba_status_counts(array());
t('KPI hardBounce count', intval($counts['hardBounce']) === 1, json_encode($counts));

// CSV export
$tmp = BA_DATA_DIR.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'selftest.csv';
@mkdir(dirname($tmp), 0755, true);
$fp = fopen($tmp, 'wb');
$n = ba_export_csv(array('status' => 'bounced'), $fp);
fclose($fp);
$csv = file_get_contents($tmp);
t('CSV export rows', $n >= 1, 'n='.$n);
t('CSV has UTF-8 BOM', substr($csv, 0, 3) === "\xEF\xBB\xBF");
t('CSV contains bounce reason', strpos($csv, 'mailbox unavailable') !== false);
t('CSV contains headers', strpos($csv, 'Email,Subject,Status') !== false);

// Event name normalization across API vs webhook
t('Normalize hardBounces→hardBounce', ba_normalize_event('hardBounces') === 'hardBounce');
t('Normalize request→sent', ba_normalize_event('request') === 'sent');
t('Normalize click→clicked', ba_normalize_event('click') === 'clicked');

// Webhook-shaped import (subject present)
$r = ba_import_event($pdo, array(
	'account_key_hash' => $h,
	'email' => 'webhook@example.com',
	'event' => 'delivered',
	'ts_event' => 1730000000,
	'message-id' => 'wh-msg-9@relay.test',
	'subject' => 'Webhook Subject',
	'event_source' => 'webhook',
	'raw_payload' => '{"event":"delivered"}'
));
t('Webhook payload import', $r === 'inserted', $r);
$wh = ba_query_messages(array('email_exact' => 'webhook@example.com'), 1, 10);
t('Webhook subject stored', !empty($wh['rows'][0]['subject']) && $wh['rows'][0]['subject'] === 'Webhook Subject', isset($wh['rows'][0]['subject']) ? $wh['rows'][0]['subject'] : 'none');

// Config token
$cfg = ba_config_load();
t('Webhook token generated', strlen($cfg['webhook_token']) >= 32);

echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
