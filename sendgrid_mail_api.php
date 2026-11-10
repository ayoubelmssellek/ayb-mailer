<?php
/**
 * SendGridMail API — multi-country delivered export (read-only).
 *
 * Actions: countries | overview | recipients | emails | export
 * Does not send mail, does not modify queue source files or VPS/Kumo config.
 */
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'sendgrid_mail_lib.php';
ayb_env_load();

function sgm_api_out(array $payload, $code = 200)
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function sgm_api_country_param()
{
	$raw = isset($_GET['country']) ? $_GET['country'] : (isset($_POST['country']) ? $_POST['country'] : 'pt');
	$letters = strtolower(preg_replace('/[^a-z]/', '', (string)$raw));
	if (strlen($letters) !== 2) {
		sgm_api_out(array(
			'ok' => false,
			'err' => 'Country must be a 2-letter code (e.g. pt, es, fr, de). Codes like GER are invalid — use de for Germany.'
		), 400);
	}
	return $letters;
}

function sgm_api_extra_countries()
{
	$raw = isset($_GET['known']) ? $_GET['known'] : (isset($_POST['known']) ? $_POST['known'] : '');
	$parts = preg_split('/[\s,;]+/', (string)$raw);
	$out = array();
	foreach ($parts as $p) {
		$p = strtolower(preg_replace('/[^a-z]/', '', (string)$p));
		if (strlen($p) === 2) $out[] = $p;
	}
	return $out;
}

$action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : 'overview';
if ($action === '' && isset($_POST['action'])) $action = strtolower(trim((string)$_POST['action']));
if ($action === '') $action = 'overview';

$root = __DIR__;

if ($action === 'countries') {
	$list = sgm_discover_countries($root, sgm_api_extra_countries());
	sgm_api_out(array(
		'ok' => true,
		'feature' => 'SendGridMail',
		'countries' => $list,
		'note' => 'Countries discovered from api-lane queue files plus known UI codes. Always includes pt. Germany = de (not GER).'
	));
}

$country = sgm_api_country_param();
$dataset = sgm_build_country_dataset($root, $country);
$auth = array(
	'app_login' => false,
	'note' => 'No application login layer; same local-dashboard trust model as other APIs.'
);

if ($action === 'overview') {
	sgm_api_out(array(
		'ok' => true,
		'feature' => 'SendGridMail',
		'country' => $dataset['country'],
		'lane' => 'api',
		'method' => 'sendgrid',
		'files' => $dataset['files'],
		'stats' => $dataset['stats'],
		'reason_counts' => $dataset['reason_counts'],
		'delivery_source' => $dataset['delivery_source'],
		'evidence' => $dataset['evidence'],
		'auth' => $auth,
		'notes' => $dataset['notes'],
		'countries' => sgm_discover_countries($root, sgm_api_extra_countries())
	));
}

if ($action === 'recipients') {
	$q = isset($_GET['q']) ? $_GET['q'] : '';
	$bucket = isset($_GET['bucket']) ? $_GET['bucket'] : (isset($_GET['status']) ? $_GET['status'] : 'all');
	$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
	$per = intval(isset($_GET['per_page']) ? $_GET['per_page'] : 50);
	if ($per < 10) $per = 10;
	if ($per > 200) $per = 200;

	$filtered = sgm_filter_rows($dataset['rows'], $q, $bucket);
	$total = count($filtered);
	$pages = max(1, (int)ceil($total / $per));
	if ($page > $pages) $page = $pages;
	$slice = array_slice($filtered, ($page - 1) * $per, $per);

	sgm_api_out(array(
		'ok' => true,
		'feature' => 'SendGridMail',
		'country' => $country,
		'lane' => 'api',
		'stats' => $dataset['stats'],
		'reason_counts' => $dataset['reason_counts'],
		'delivery_source' => $dataset['delivery_source'],
		'evidence' => $dataset['evidence'],
		'files' => $dataset['files'],
		'notes' => $dataset['notes'],
		'auth' => $auth,
		'q' => strtolower(trim((string)$q)),
		'bucket' => strtolower(trim((string)$bucket)),
		'page' => $page,
		'per_page' => $per,
		'total' => $total,
		'pages' => $pages,
		'rows' => $slice
	));
}

if ($action === 'emails' || $action === 'copy') {
	$q = isset($_GET['q']) ? $_GET['q'] : (isset($_POST['q']) ? $_POST['q'] : '');
	// Eligible only — never return bounced/suppressed/unknown for copy/export helpers.
	$filtered = sgm_filter_rows($dataset['rows'], $q, 'delivered_eligible');
	$emails = sgm_eligible_emails($filtered);
	sgm_api_out(array(
		'ok' => true,
		'country' => $country,
		'lane' => 'api',
		'count' => count($emails),
		'emails' => $emails,
		'note' => 'Only verified delivered-eligible addresses. One unique email per entry. Consent/suppressions already applied.'
	));
}

if ($action === 'export') {
	$q = isset($_GET['q']) ? $_GET['q'] : '';
	$filtered = sgm_filter_rows($dataset['rows'], $q, 'delivered_eligible');
	$emails = sgm_eligible_emails($filtered);
	$body = sgm_txt_body($emails);
	$filename = 'sendgrid_delivered_'.$country.'.txt';

	// Optional on-disk copy under data/exports — never touches source queues.
	$exportDir = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'exports';
	if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);
	$diskPath = $exportDir.DIRECTORY_SEPARATOR.$filename;
	@file_put_contents($diskPath, $body);

	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('X-SendGridMail-Country: '.$country);
	header('X-SendGridMail-Count: '.count($emails));
	echo $body;
	exit;
}

sgm_api_out(array(
	'ok' => false,
	'err' => 'Unknown action. Use countries, overview, recipients, emails, or export.'
), 400);
