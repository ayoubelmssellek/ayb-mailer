<?php
/**
 * Offline SendGridMail multi-country delivered-export tests.
 * Run: php tests/sendgrid_mail_offline_test.php
 */
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function t_assert($cond, $name)
{
	global $pass, $fail;
	if ($cond) { echo "[PASS] $name\n"; $pass++; }
	else { echo "[FAIL] $name\n"; $fail++; }
}

echo "=== SendGridMail multi-country offline tests ===\n";

require_once $root.DIRECTORY_SEPARATOR.'sendgrid_mail_lib.php';
require_once $root.DIRECTORY_SEPARATOR.'suppression_lib.php';

t_assert(is_file($root.DIRECTORY_SEPARATOR.'sendgrid_mail_api.php'), 'sendgrid_mail_api.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'sendgrid_mail_lib.php'), 'sendgrid_mail_lib.php exists');
t_assert(is_file($root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'SENDGRID_MAIL.md'), 'SENDGRID_MAIL.md exists');

$tmp = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'sgm_test_'.getmypid();
@mkdir($tmp, 0755, true);

// Country queue fixtures (api lane only)
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails.txt',
	"ok.pt@example.com\nok.pt@example.com\nbounce.pt@example.com\nblock.pt@example.com\ndeferred.pt@example.com\nunknown.pt@example.com\ndoneonly.pt@example.com\nunsub.pt@example.com\nconflict.pt@example.com\nspam.pt@example.com\n");
file_put_contents($tmp.DIRECTORY_SEPARATOR.'mailssenddone.txt', "doneonly.pt@example.com\nok.pt@example.com\n");
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails_es.txt', "ok.es@example.com\nbounce.es@example.com\n");
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails_fr.txt', "ok.fr@example.com\n");
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails_de.txt', "ok.de@example.com\n");
// Decoy other lanes — must not mix
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails_smtp.txt', "smtp.only@example.com\n");
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails_kumomta.txt', "kumo.only@example.com\n");
file_put_contents($tmp.DIRECTORY_SEPARATOR.'validmails_smtp_es.txt', "smtp.es@example.com\n");

$evidence = array(
	'db_ok' => true,
	'truncated' => false,
	'err' => '',
	'messages' => array(
		'ok.pt@example.com' => array(array('status' => 'delivered', 'last_event_time' => '2026-11-01T10:00:00Z')),
		'bounce.pt@example.com' => array(array('status' => 'not_delivered', 'last_event_time' => '2026-11-01T11:00:00Z')),
		'block.pt@example.com' => array(array('status' => 'delivered', 'last_event_time' => '2026-11-01T09:00:00Z')),
		'deferred.pt@example.com' => array(array('status' => 'deferred', 'last_event_time' => '2026-11-01T12:00:00Z')),
		'unknown.pt@example.com' => array(array('status' => '', 'last_event_time' => '')),
		'doneonly.pt@example.com' => array(), // in mailssenddone only — NO delivered evidence
		'unsub.pt@example.com' => array(array('status' => 'delivered', 'last_event_time' => '2026-10-01T10:00:00Z')),
		'conflict.pt@example.com' => array(
			array('status' => 'delivered', 'last_event_time' => '2026-11-01T08:00:00Z'),
			array('status' => 'not_delivered', 'last_event_time' => '2026-11-01T18:00:00Z')
		),
		'spam.pt@example.com' => array(array('status' => 'delivered', 'last_event_time' => '2026-11-01T07:00:00Z')),
		'ok.es@example.com' => array(array('status' => 'delivered', 'last_event_time' => '2026-11-01T10:00:00Z')),
		'bounce.es@example.com' => array(array('status' => 'not_delivered', 'last_event_time' => '2026-11-01T10:00:00Z')),
		'ok.fr@example.com' => array(array('status' => 'delivered', 'last_event_time' => '2026-11-01T10:00:00Z')),
		'ok.de@example.com' => array(array('status' => 'processed', 'last_event_time' => '2026-11-01T10:00:00Z')), // not delivered
	),
	'suppressions' => array(
		'bounce.pt@example.com' => array('bounce'),
		'block.pt@example.com' => array('block'),
		'spam.pt@example.com' => array('spamreport'),
		'bounce.es@example.com' => array('bounce'),
	)
);

// Consent: global unsubscribe must override delivered
suppression_add('unsub.pt@example.com', 'unsubscribe', 'test', 'sgm offline', 'test');

$pt = sgm_build_country_dataset($tmp, 'pt', $evidence, array('check_global_suppression' => true, 'country' => 'pt'));
t_assert(!empty($pt['ok']), 'pt dataset ok');
t_assert(intval($pt['stats']['delivered_eligible']) === 1, 'pt delivered eligible = 1 (ok.pt only)');
t_assert(intval($pt['stats']['bounced']) >= 1, 'pt has bounced');
t_assert(intval($pt['stats']['blocked_dropped']) >= 1, 'pt has blocked/dropped');
t_assert(intval($pt['stats']['excluded_unknown']) >= 1, 'pt has excluded/unknown');

$byEmail = array();
foreach ($pt['rows'] as $r) $byEmail[$r['email']] = $r;

t_assert(!empty($byEmail['ok.pt@example.com']['eligible']), 'delivered recipient eligible');
t_assert(empty($byEmail['bounce.pt@example.com']['eligible']) && $byEmail['bounce.pt@example.com']['bucket'] === 'bounced', 'hard/soft bounce excluded as bounced');
t_assert(empty($byEmail['block.pt@example.com']['eligible']) && $byEmail['block.pt@example.com']['bucket'] === 'blocked_dropped', 'blocked excluded even if historical delivered');
t_assert(empty($byEmail['deferred.pt@example.com']['eligible']), 'deferred not eligible');
t_assert(empty($byEmail['unknown.pt@example.com']['eligible']), 'unknown status not eligible');
t_assert(empty($byEmail['doneonly.pt@example.com']['eligible']), 'mailssenddone-only not eligible without delivered');
t_assert(empty($byEmail['unsub.pt@example.com']['eligible']) && strpos($byEmail['unsub.pt@example.com']['reason'], 'consent_') === 0, 'unsubscribe overrides historical delivered');
t_assert(empty($byEmail['conflict.pt@example.com']['eligible']) && $byEmail['conflict.pt@example.com']['reason'] === 'conflicting_events', 'conflicting events excluded');
t_assert(empty($byEmail['spam.pt@example.com']['eligible']) && $byEmail['spam.pt@example.com']['reason'] === 'sg_spamreport', 'spam complaint overrides delivered');

$elig = sgm_eligible_emails($pt['rows']);
t_assert($elig === array('ok.pt@example.com'), 'eligible list unique and exact');
$txt = sgm_txt_body($elig);
t_assert($txt === "ok.pt@example.com\n", 'TXT body is email-only one per line');

// Country isolation
$es = sgm_build_country_dataset($tmp, 'es', $evidence);
$esEmails = sgm_eligible_emails($es['rows']);
t_assert($esEmails === array('ok.es@example.com'), 'ES eligible isolated');
t_assert(!in_array('ok.pt@example.com', $esEmails, true), 'PT email not mixed into ES');

$fr = sgm_build_country_dataset($tmp, 'fr', $evidence);
t_assert(sgm_eligible_emails($fr['rows']) === array('ok.fr@example.com'), 'FR delivered eligible');

$de = sgm_build_country_dataset($tmp, 'de', $evidence);
t_assert(sgm_eligible_emails($de['rows']) === array(), 'DE processed-only not eligible');
t_assert(intval($de['stats']['excluded_unknown']) === 1, 'DE counted as excluded/unknown');

$discovered = sgm_discover_countries($tmp, array('pt', 'es', 'fr', 'de', 'GER'));
$codes = array();
foreach ($discovered as $c) $codes[] = $c['code'];
t_assert(in_array('pt', $codes, true) && in_array('es', $codes, true) && in_array('fr', $codes, true) && in_array('de', $codes, true), 'discovers pt/es/fr/de');
t_assert(!in_array('ger', $codes, true), 'rejects GER three-letter code');
t_assert(!in_array('smtp', $codes, true), 'does not treat smtp lane as country');

// Duplicates already collapsed in membership
t_assert(intval($pt['stats']['membership_unique']) === 9, 'pt membership unique (dup ok.pt collapsed)');

// Dropped via invalid suppression
$dropEv = $evidence;
$dropEv['suppressions']['deferred.pt@example.com'] = array('invalid');
$clsDrop = sgm_classify_email('deferred.pt@example.com', $dropEv, array('check_global_suppression' => false));
t_assert($clsDrop['bucket'] === 'blocked_dropped' && empty($clsDrop['eligible']), 'invalid/dropped suppression buckets blocked_dropped');

// Failed/rejected style latest status
$failEv = array(
	'messages' => array('x@example.com' => array(array('status' => 'not_delivered', 'last_event_time' => '2026-11-01T10:00:00Z'))),
	'suppressions' => array()
);
$clsFail = sgm_classify_email('x@example.com', $failEv, array('check_global_suppression' => false));
t_assert($clsFail['bucket'] === 'bounced' && empty($clsFail['eligible']), 'not_delivered classified bounced/failed path');

// API smoke via runner
$runner = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'_sgm_api_run.php';
function sgm_api_run($root, $runner, array $get)
{
	$code = "<?php\n\$_GET = ".var_export($get, true).";\ninclude ".var_export($root.DIRECTORY_SEPARATOR.'sendgrid_mail_api.php', true).";\n";
	file_put_contents($runner, $code);
	$out = shell_exec('php '.escapeshellarg($runner).' 2>&1');
	return array($out, json_decode((string)$out, true));
}

list($rawCountries, $countriesJson) = sgm_api_run($root, $runner, array('action' => 'countries', 'known' => 'pt,es,fr,de'));
t_assert(is_array($countriesJson) && !empty($countriesJson['ok']), 'API countries ok');

list($rawBad, $badJson) = sgm_api_run($root, $runner, array('action' => 'overview', 'country' => 'GER'));
t_assert(is_array($badJson) && empty($badJson['ok']), 'API rejects GER');

list($rawOv, $ovJson) = sgm_api_run($root, $runner, array('action' => 'overview', 'country' => 'pt'));
t_assert(is_array($ovJson) && !empty($ovJson['ok']) && isset($ovJson['stats']['delivered_eligible']), 'API overview pt ok');
t_assert(isset($ovJson['delivery_source']) && strpos($ovJson['delivery_source'], 'delivered') !== false, 'API documents delivery source');

// Live workspace may have empty membership — stats may be zero; that is correct, not fabricated
t_assert(isset($ovJson['notes']) && is_array($ovJson['notes']), 'API returns notes/limitations');

$idx = file_get_contents($root.DIRECTORY_SEPARATOR.'index.php');
t_assert(strpos($idx, 'sendgrid_mail_api.php') !== false, 'index uses sendgrid_mail_api.php');
t_assert(strpos($idx, 'btn-sgm-export') !== false, 'export button present');
t_assert(strpos($idx, 'sgm-country') !== false, 'country selector present');
t_assert(strpos($idx, 'Delivered eligible') !== false, 'delivered KPI label present');

// Cleanup suppression fixture + temp files
suppression_remove('unsub.pt@example.com', 'test', 'sgm offline cleanup');
@unlink($runner);
foreach (glob($tmp.DIRECTORY_SEPARATOR.'*') ?: array() as $f) @unlink($f);
@rmdir($tmp);

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
