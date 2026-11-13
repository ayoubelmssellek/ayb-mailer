<?php
/**
 * Offline tests for Remote Delivery Analytics (no network, no Kumo VPS, no sends).
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);
$testDb = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'remote_delivery_test.sqlite';
@mkdir(dirname($testDb), 0755, true);
@unlink($testDb);
@unlink($testDb.'-wal');
@unlink($testDb.'-shm');
define('AYB_REMOTE_DELIVERY_DB', $testDb);
putenv('KUMO_EVENTS_WEBHOOK_TOKEN=');
$_ENV['KUMO_EVENTS_WEBHOOK_TOKEN'] = '';

require_once $root.DIRECTORY_SEPARATOR.'remote_delivery_lib.php';

function t_assert($cond, $msg)
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: ".$msg."\n");
		exit(1);
	}
	echo "OK: ".$msg."\n";
}

$cfg = rd_config();
t_assert(empty($cfg['ingest_enabled']), 'ingest disabled without token');

$ov0 = rd_overview(7);
t_assert(!empty($ov0['ok']), 'empty overview ok');
t_assert(!empty($ov0['waiting_for_events']), 'waiting when no events');
t_assert($ov0['kpis']['remote_acceptance_rate'] === null, 'rate null when empty');
t_assert($ov0['empty_message'] === 'Waiting for KumoMTA delivery events', 'empty message text');

$base = array(
	'id' => 'spool-aaa',
	'sender' => 'mail@example.com',
	'recipient' => 'user@gmail.com',
	'peer_address' => array('name' => 'gmail-smtp-in.l.google.com.', 'addr' => '142.251.2.27'),
	'site' => 'gmail',
	'num_attempts' => 1,
	'timestamp' => time() - 100,
	'headers' => array('Message-ID' => '<test@ayb>', 'X-AYB-Send-Id' => 'send-1'),
	'response' => array(
		'code' => 250,
		'enhanced_code' => array('class' => 2, 'subject' => 0, 'detail' => 0),
		'content' => 'OK'
	)
);

$d1 = $base;
$d1['type'] = 'Delivery';
$r1 = rd_ingest_event($d1);
t_assert(!empty($r1['ok']) && !empty($r1['inserted']), 'insert Delivery');

$rDup = rd_ingest_event($d1);
t_assert(!empty($rDup['ok']) && !empty($rDup['duplicate']), 'duplicate Delivery protected');

$b1 = $base;
$b1['type'] = 'Bounce';
$b1['id'] = 'spool-bbb';
$b1['recipient'] = 'bad@outlook.com';
$b1['timestamp'] = time() - 90;
$b1['response']['code'] = 550;
$b1['response']['content'] = 'User unknown';
$b1['peer_address'] = array('name' => 'outlook-com.olc.protection.outlook.com', 'addr' => '1.2.3.4');
t_assert(!empty(rd_ingest_event($b1)['inserted']), 'insert Bounce');

$t1 = $base;
$t1['type'] = 'TransientFailure';
$t1['id'] = 'spool-ccc';
$t1['timestamp'] = time() - 80;
$t1['response']['code'] = 421;
$t1['response']['content'] = 'Try later';
t_assert(!empty(rd_ingest_event($t1)['inserted']), 'insert TransientFailure');

$e1 = $base;
$e1['type'] = 'Expiration';
$e1['id'] = 'spool-ddd';
$e1['timestamp'] = time() - 70;
$e1['response'] = array('code' => null, 'content' => 'expired');
t_assert(!empty(rd_ingest_event($e1)['inserted']), 'insert Expiration');

$skip = rd_ingest_event(array('type' => 'Reception', 'id' => 'x', 'recipient' => 'a@b.com', 'timestamp' => time()));
t_assert(!empty($skip['ok']) && !empty($skip['skipped']), 'Reception skipped');

$ov = rd_overview(7);
t_assert(empty($ov['waiting_for_events']), 'not waiting after events');
t_assert($ov['kpis']['remote_accepted'] === 1, 'kpi Delivery=1');
t_assert($ov['kpis']['permanent_bounces'] === 1, 'kpi Bounce=1');
t_assert($ov['kpis']['deferred'] === 1, 'kpi TransientFailure=1');
t_assert($ov['kpis']['expired'] === 1, 'kpi Expiration=1');
// rate = 1/(1+1+1)=33.33 — TransientFailure excluded
t_assert($ov['kpis']['remote_acceptance_rate'] === 33.33, 'acceptance rate 33.33');
t_assert(!empty($ov['mx']), 'mx breakdown present');
t_assert(!empty($ov['smtp_codes']['buckets']['2xx']), '2xx bucket');
t_assert(!empty($ov['failures']), 'failure analysis present');
t_assert(!empty($ov['recent']['rows']), 'recent events present');
$masked = $ov['recent']['rows'][0]['recipient_masked'];
t_assert(strpos($masked, '***@') !== false, 'recipient masked in API');

// Webhook inactive without token
$runner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_rd_wh.php';
file_put_contents($runner, "<?php\nputenv('KUMO_EVENTS_WEBHOOK_TOKEN=');\n\$_ENV['KUMO_EVENTS_WEBHOOK_TOKEN']='';\ndefine('AYB_REMOTE_DELIVERY_DB', ".var_export($testDb, true).");\n\$_SERVER['REQUEST_METHOD']='POST';\nfile_put_contents('php://memory',''); // noop\n");
// Direct library auth gate already tested; call GET status via include simulation
putenv('KUMO_EVENTS_WEBHOOK_TOKEN=test-secret-token');
$_ENV['KUMO_EVENTS_WEBHOOK_TOKEN'] = 'test-secret-token';
// Re-read config: ayb_env may have cached empty — force by putenv before new process
$whRunner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_rd_wh_get.php';
file_put_contents($whRunner, "<?php\nputenv('KUMO_EVENTS_WEBHOOK_TOKEN=test-secret-token');\n\$_ENV['KUMO_EVENTS_WEBHOOK_TOKEN']='test-secret-token';\ndefine('AYB_REMOTE_DELIVERY_DB', ".var_export($testDb, true).");\n\$_SERVER['REQUEST_METHOD']='GET';\ninclude ".var_export($root.DIRECTORY_SEPARATOR.'kumo_events_webhook.php', true).";\n");
$out = shell_exec('php '.escapeshellarg($whRunner).' 2>&1');
@unlink($whRunner);
@unlink($runner);
$j = json_decode((string)$out, true);
t_assert(is_array($j) && !empty($j['ok']) && !empty($j['ingest_enabled']), 'webhook GET enabled with token');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'remote-delivery-card') !== false, 'dashboard has Remote Delivery section');
t_assert(strpos($idx, 'Waiting for KumoMTA delivery events') !== false, 'empty state copy in UI');
t_assert(strpos($idx, 'loadRemoteDelivery') !== false, 'JS loader wired');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'remote_delivery_api.php'), 'API exists');

echo "ALL REMOTE DELIVERY OFFLINE CHECKS PASSED\n";
