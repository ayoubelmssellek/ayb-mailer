<?php
/////error_reporting(0);
set_time_limit(0);
// Require autoload
require_once 'vendor/autoload.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_status.php';
// init faker 
$faker = Faker\Factory::create('fr_FR');
//INIT VARS
$message_base="";$action="";$message="";$emaillist="";$from="";$reconnect="0";$epriority="";$my_smtp="";$ssl_port="587";$encodety="";$replyto="";$subject="";$realname="";$subject_base="";$realname_base="";$contenttype="";$isbcc="";$nbcc="100";$default_system="";$from_base="";$debg="0";$pause=1;$pemails=1;$nm=0;$nopose=true;$nrotat=50;$curentsmtp=0;$canrotat=false;$allsmtps=array();$lase="";$reading=false;$repaslog=false;$uploadfile="";$smtp_sent=array();$use_socks=false;$socks_proxies=array();$socks_full=array();$socks_ok=array();$socks_index=0;$socks_proxy="";$socks_dead=array();$socks_pool='ok';$send_workers=5;$smtp_mode='normal';$brevo_smtp='';$brevo_api_keys='';$brevo_api_accounts=array();$brevo_api_sent=array();$brevo_api_cur=0;$brevo_api_credits=array();$brevo_api_exhausted=array();$smtp_accounts=array();$smtp_account_of=array();$account_sent=array();$send_list_mode='campaign';$mail_queue_campaign=true;$content_variants=array();$content_variant_buckets=array();$content_variant_cursor=array();$content_variants_json='';

$cli_mode = (php_sapi_name() === 'cli');
$cli_campaign = '';
$cli_worker_id = 0;
$cli_num_workers = 1;
if($cli_mode && !empty($argv))
{
	foreach($argv as $a)
	{
		if(preg_match('/^--campaign=(.+)$/', $a, $m)) $cli_campaign = $m[1];
		if(preg_match('/^--worker=(\d+)$/', $a, $m)) $cli_worker_id = intval($m[1]);
		if(preg_match('/^--workers=(\d+)$/', $a, $m)) $cli_num_workers = max(1, intval($m[1]));
	}
	if($cli_campaign !== '') $action = 'cli_worker';
}

if(!empty($_POST))
{	  
	if(isset($_POST['send_workers']) && $_POST['send_workers'] !== '')
	$send_workers = max(1, min(15, intval($_POST['send_workers'])));
	$debg=lrtrim($_POST['dbg']);
	if(!empty($_POST['from']))
	{
	$from=lrtrim($_POST['from']);
	$from_base =$from;
	}
	$action=lrtrim($_POST['action']);
	$message=lrtrim($_POST['message']);
	$message_base=lrtrim($_POST['message']);
	$emaillist=isset($_POST['emaillist']) ? lrtrim($_POST['emaillist']) : '';
	$send_list_mode = (!empty($_POST['send_list_mode']) && $_POST['send_list_mode'] === 'test') ? 'test' : 'campaign';
	$mail_queue_campaign = ($send_list_mode === 'campaign');
	$reconnect=lrtrim($_POST['reconnect']);
	$epriority=lrtrim($_POST['epriority']);
	$my_smtp=lrtrim($_POST['my_smtp']);
	$subject=lrtrim($_POST['subject']);
	$realname=lrtrim($_POST['realname']);
	$subject_base=lrtrim($_POST['subject']);
	$realname_base=lrtrim($_POST['realname']);
	$content_variants_json = isset($_POST['content_variants_json']) ? (string)$_POST['content_variants_json'] : '';
	$content_variants = content_variants_from_post($content_variants_json, $subject_base, $message_base);
	if(!empty($content_variants[0]['subject'])) $subject_base = $content_variants[0]['subject'];
	if(!empty($content_variants[0]['message'])) $message_base = $content_variants[0]['message'];
	$subject = $subject_base;
	$message = $message_base;
	$contenttype=lrtrim($_POST['contenttype']);
	$encodety=$_POST['encodety'];
	if(isset($_POST['pause']) && $_POST['pause'] !== '')
	$pause=$_POST['pause'];
	if(!empty($_POST['replyto']))
	$replyto=lrtrim($_POST['replyto']);
	if(!empty($_POST['nrotat']))
	$nrotat=max(1, intval($_POST['nrotat']));
	if(isset($_POST['pemails']) && $_POST['pemails'] !== '')
	$pemails=$_POST['pemails'];
	if(!empty($_POST['lase']))
	$lase=true;
	if(!empty($_POST['nbcc']))
	$nbcc=lrtrim($_POST['nbcc']);
	if(!empty($_POST['readingconf']))
	$reading=true;
	if(!empty($_POST['repaslog']))
	$repaslog=true;
	if(!empty($_POST['use_socks']) || (isset($_POST['use_socks_persist']) && $_POST['use_socks_persist'] === '1'))
	$use_socks=true;
	if(!empty($_POST['smtp_mode']) && $_POST['smtp_mode'] === 'brevo_api')
	$smtp_mode = 'brevo_api';
	else if(!empty($_POST['smtp_mode']) && $_POST['smtp_mode'] === 'brevo')
	$smtp_mode = 'brevo';
	else
	$smtp_mode = 'normal';
	if(!empty($_POST['brevo_smtp']))
	$brevo_smtp = lrtrim($_POST['brevo_smtp']);
	if(!empty($_POST['brevo_api_keys']))
	$brevo_api_keys = lrtrim($_POST['brevo_api_keys']);
	else if(!empty($_POST['brevo_api_key']))
	$brevo_api_keys = lrtrim($_POST['brevo_api_key']); // old single-field compat
	// Brevo SMTP / API: no SOCKS
	if($smtp_mode === 'brevo')
	{
	$use_socks = false;
	$send_workers = 1;
	$parsed = parse_brevo_smtp_blocks($brevo_smtp);
	$allsmtps = $parsed['allsmtps'];
	$smtp_accounts = $parsed['accounts'];
	$smtp_account_of = $parsed['account_of'];
	$account_sent = array();
	}
	else if($smtp_mode === 'brevo_api')
	{
	$use_socks = false;
	$send_workers = 1;
	$allsmtps = array();
	$brevo_api_accounts = parse_brevo_api_blocks($brevo_api_keys);
	$brevo_api_sent = array();
	$brevo_api_cur = 0;
	}
	else
	{
	$allsmtps = preg_split("/\\r\\n|\\r|\\n/", $my_smtp);
	$allsmtps = array_values(array_filter(array_map('trim', $allsmtps), 'strlen'));
	// Forgot Add SMTP? Pull host:port:user:pass lines from Bulk box
	if(empty($allsmtps) && !empty($_POST['bulk_smtp']))
	{
		$bulkLines = preg_split("/\\r\\n|\\r|\\n/", lrtrim($_POST['bulk_smtp']));
		foreach($bulkLines as $bl)
		{
			$bl = trim($bl);
			if($bl === '' || $bl[0] === '[' || $bl[0] === '{') continue;
			$parts = (strpos($bl, '|') !== false) ? explode('|', $bl) : explode(':', $bl);
			if(count($parts) >= 4 && strpos($parts[0], '@') === false && ctype_digit(trim($parts[1])))
				$allsmtps[] = $bl;
		}
		if(!empty($allsmtps))
			$my_smtp = implode("\n", $allsmtps);
	}
	}
	// Method 1 (Normal SMTP) uses its own list. Brevo API / Brevo SMTP keep validmails.txt.
	$mail_queue_lane = ($smtp_mode === 'normal') ? 'smtp' : 'api';
	@file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'send_queue_lane.txt', $mail_queue_lane);
}
if(!$cli_mode){
?>
<html>

<head>
<title>AYB Mailer</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<script
	  src="https://code.jquery.com/jquery-3.1.1.min.js"
	  integrity="sha256-hVVnYaiADRTO2PzUGmuLJr8BLUSjGIZsDYGmIJLv2b8="
	  crossorigin="anonymous"></script>
<style type="text/css">
.td1 { width:150px; }
.style1 { font-size: 11px; color: var(--muted); }
:root{--bg:#0b0f14;--panel:#121821;--card:#18212c;--line:#273242;--text:#e8eef5;--muted:#8b9bb0;--ok:#2ee59d;--fail:#ff5d6c;--info:#5eb3ff;--warn:#ffc14d;--accent:#2ee59d;--sand:#c4a574}
body:not(.send-mode){--s-bg:#f0f3f7;--s-card:#ffffff;--s-line:#d5dde8;--s-text:#1a2330;--s-muted:#5c6b7e;--s-hint:#7a8a9c;--s-input:#f7f9fc;--s-ok:#0d8f5f;--s-sand:#9a6b2f;margin:0;min-height:100vh;background:radial-gradient(1000px 480px at 8% -5%,#dce8f5 0%,transparent 55%),radial-gradient(800px 420px at 100% 0%,#dff0e6 0%,transparent 50%),var(--s-bg);color:var(--s-text);font-family:"DM Sans",system-ui,sans-serif}
.setup-wrap{max-width:1120px;margin:0 auto;padding:28px 20px 48px}
/* App shell: sidebar + pages (setup only; send-mode hides this via body class) */
body:not(.send-mode) .app-shell{display:flex;min-height:100vh;align-items:stretch}
body.send-mode .app-shell{display:none!important}
.app-sidebar{width:240px;flex-shrink:0;background:#121821;color:#e8eef5;border-right:1px solid #273242;padding:20px 14px;position:sticky;top:0;height:100vh;box-sizing:border-box;display:flex;flex-direction:column;gap:18px;z-index:40}
.app-sidebar .sb-brand{font-family:"Instrument Serif",Georgia,serif;font-size:26px;line-height:1.1;padding:0 8px;color:#fff}
.app-sidebar .sb-brand em{font-style:italic;color:#c4a574}
.app-sidebar .sb-nav{display:flex;flex-direction:column;gap:4px;flex:1}
.app-sidebar .sb-link{display:block;text-align:left;width:100%;border:0;background:transparent;color:#8b9bb0;font:inherit;font-size:13px;font-weight:600;padding:10px 12px;border-radius:10px;cursor:pointer}
.app-sidebar .sb-link:hover{background:rgba(255,255,255,.04);color:#e8eef5}
.app-sidebar .sb-link.on{background:rgba(46,229,157,.12);color:#2ee59d}
.app-sidebar .sb-foot{font-size:11px;color:#667788;padding:0 8px}
.app-main{flex:1;min-width:0;display:flex;flex-direction:column}
.app-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 22px;background:rgba(255,255,255,.72);backdrop-filter:blur(8px);border-bottom:1px solid var(--s-line);position:sticky;top:0;z-index:30}
.app-topbar h2{margin:0;font-size:16px;font-weight:700;color:var(--s-text)}
.app-topbar h2 small{display:block;font-size:12px;font-weight:500;color:var(--s-muted);margin-top:2px}
.app-topbar .tb-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.app-content{padding:20px 22px 48px;max-width:1120px;width:100%;box-sizing:border-box}
.nav-page{display:none}
.nav-page.on{display:block}
.variant-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 0}
.variant-tabs button{border:1px solid var(--s-line);background:var(--s-input);color:var(--s-text);border-radius:10px;padding:7px 12px;font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.variant-tabs button.on{border-color:var(--s-ok);color:var(--s-ok);background:rgba(13,143,95,.08)}
.variant-meta{font-size:12px;color:var(--s-muted);margin:6px 0 0}
.sb-toggle{display:none;border:1px solid var(--s-line);background:#fff;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
@media(max-width:900px){
  body:not(.send-mode) .app-shell{flex-direction:column}
  .app-sidebar{position:fixed;left:0;top:0;transform:translateX(-105%);transition:transform .2s ease;height:100vh;box-shadow:8px 0 28px rgba(0,0,0,.2)}
  .app-sidebar.open{transform:translateX(0)}
  .sb-toggle{display:inline-block}
  .app-content{padding:16px}
}
.setup-hero{margin-bottom:22px}
.setup-hero .brand{font-family:"Instrument Serif",Georgia,serif;font-size:42px;line-height:1;letter-spacing:-.02em;margin:0;color:var(--s-text)}
.setup-hero .brand em{font-style:italic;color:var(--s-sand)}
.setup-hero p{margin:8px 0 0;color:var(--s-muted);font-size:14px;max-width:52ch}
.setup-grid{display:grid;gap:16px}
.setup-card{background:var(--s-card);border:1px solid var(--s-line);border-radius:16px;padding:18px 18px 16px;box-shadow:0 8px 28px rgba(26,35,48,.06)}
.setup-card h3{margin:0 0 14px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--s-muted)}
.setup-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.setup-row.one{grid-template-columns:1fr}
.setup-field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.setup-field label{font-size:12px;color:var(--s-muted);font-weight:500}
.setup-field .hint{font-size:11px;color:var(--s-hint);margin-top:2px}
.setup-field input[type=text],.setup-field input[type=password],.setup-field input[type=number],.setup-field select,.setup-field textarea,.setup-inline input[type=text],.setup-inline input[type=number]{width:100%;box-sizing:border-box;background:var(--s-input);border:1px solid var(--s-line);border-radius:10px;color:var(--s-text);padding:10px 12px;font:inherit;font-size:13px}
.setup-field textarea{min-height:88px;resize:vertical;font-family:ui-monospace,Consolas,monospace;font-size:12px}
.setup-field textarea.tall{min-height:160px}
.setup-inline{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;font-size:13px;color:var(--s-muted)}
.setup-inline label{display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:var(--s-text)}
.setup-inline input[type=text],.setup-inline input[type=number]{width:auto}
.setup-seg{display:flex;flex-wrap:wrap;gap:8px}
.setup-seg label{background:var(--s-input);border:1px solid var(--s-line);border-radius:999px;padding:8px 14px;font-size:13px;cursor:pointer;color:var(--s-text)}
.setup-seg input{accent-color:var(--s-ok)}
.setup-seg label:has(input:checked){border-color:var(--s-ok);color:var(--s-ok);background:rgba(13,143,95,.08)}
.rc-switch{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rc-switch label{position:relative;display:block;margin:0;padding:14px 16px;border:1px solid var(--s-line);border-radius:14px;background:#fff;cursor:pointer}
.rc-switch input{position:absolute;opacity:0;pointer-events:none}
.rc-switch strong{display:block;font-size:14px;color:var(--s-text)}
.rc-switch em{display:block;margin-top:4px;font-style:normal;font-size:12px;line-height:1.4;color:var(--s-muted)}
.rc-switch label:has(input:checked){border-color:var(--s-ok);background:linear-gradient(180deg,rgba(13,143,95,.1),#fff);box-shadow:0 0 0 3px rgba(13,143,95,.12)}
.rc-switch label:has(input:checked) strong{color:#0d8f5f}
.rc-test{margin-top:14px;padding:14px;border-radius:14px;background:var(--s-input);border:1px dashed var(--s-line)}
.rc-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}
.rc-list{background:#fff;border:1px solid var(--s-line);border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(26,35,48,.05)}
.rc-list.api{border-top:3px solid #3b82f6}
.rc-list.smtp{border-top:3px solid #c4a574}
.rc-hd{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;padding:14px 16px 10px}
.rc-hd b{display:block;font-size:15px;color:var(--s-text)}
.rc-hd span{display:block;margin-top:3px;font-size:12px;line-height:1.4;color:var(--s-muted);font-weight:500}
.rc-pill{flex-shrink:0;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 8px;border-radius:999px;background:var(--s-input);color:var(--s-muted)}
.rc-list.api .rc-pill{color:#1d4ed8;background:#eff6ff}
.rc-list.smtp .rc-pill{color:#9a6b2f;background:#fbf6ee}
.rc-stat{margin:0 16px 10px;padding:8px 10px;border-radius:10px;background:var(--s-input);font-size:12px;font-weight:600;color:var(--s-text);line-height:1.4}
.rc-test textarea{width:100%;box-sizing:border-box;min-height:120px;border:1px solid var(--s-line);border-radius:12px;background:#fff;padding:10px 12px;font:12px/1.45 ui-monospace,Consolas,monospace;resize:vertical}
.rc-body{padding:0 16px 14px}
.rc-body textarea{width:100%;box-sizing:border-box;min-height:180px;border:1px solid var(--s-line);border-radius:12px;background:var(--s-input);padding:10px 12px;font:12px/1.45 ui-monospace,Consolas,monospace;resize:vertical}
.rc-tools{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.rc-tools .btn{padding:8px 12px}
#page-recipients.on{display:flex;flex-direction:column;gap:16px}
.rc-switch label:has(input:checked)::after{content:"In use";display:inline-block;margin-top:8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0d8f5f}
.rc-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:0 16px 10px}
.rc-metrics div{background:var(--s-input);border-radius:12px;padding:10px 10px 8px}
.rc-metrics b{display:block;font-size:18px;font-variant-numeric:tabular-nums;line-height:1.1;color:var(--s-text)}
.rc-metrics span{font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:var(--s-muted)}
.rc-next{margin:0 16px 12px;font-size:11px;line-height:1.4;color:var(--s-muted);word-break:break-all}
.rc-list.is-live{box-shadow:0 12px 32px rgba(26,35,48,.1)}
.rc-list.is-live .rc-pill::before{content:"Active · "}
#page-recipients.test-on .rc-grid{opacity:.42;filter:grayscale(.35)}
#page-recipients.test-on .rc-list.is-live .rc-pill::before{content:none}
@media(max-width:860px){.rc-switch,.rc-grid{grid-template-columns:1fr}}
.setup-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:8px}
.btn{border:0;border-radius:10px;padding:11px 18px;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit}
.btn-primary{background:linear-gradient(180deg,#1db978,#0d8f5f);color:#fff}
.btn-ghost{background:#fff;color:var(--s-text);border:1px solid var(--s-line)}
.btn-danger{background:#c0392b;color:#fff}
.btn-ok{background:#0d8f5f;color:#fff}
.setup-proxy{display:grid;grid-template-columns:1.2fr auto;gap:14px;align-items:center}
.setup-proxy-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px}
.setup-proxy-stats div{background:var(--s-input);border:1px solid var(--s-line);border-radius:10px;padding:10px 12px}
.setup-proxy-stats b{display:block;font-size:18px;font-variant-numeric:tabular-nums;color:var(--s-text)}
.setup-proxy-stats span{font-size:10px;color:var(--s-muted);text-transform:uppercase;letter-spacing:.4px}
#pt-ok{color:var(--s-ok)!important}
#pt-msg{color:var(--s-muted)!important}
@media(max-width:860px){.setup-row,.setup-proxy,.setup-proxy-stats{grid-template-columns:1fr}}
/* Brevo-style analytics dashboard */
.stats-dash{margin-top:14px;background:linear-gradient(180deg,#0f141c 0%,#121821 100%);border:1px solid #273242;border-radius:16px;padding:16px;color:#e8eef5}
.stats-dash *{box-sizing:border-box}
.stats-dash .sd-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.stats-dash .sd-hd h4{margin:0;font-size:15px;font-weight:700;letter-spacing:.2px}
.stats-dash .sd-hd h4 small{display:block;font-weight:500;color:#8b9bb0;font-size:11px;margin-top:3px}
.stats-dash .sd-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.stats-dash .sd-controls select,.stats-dash .sd-controls input[type=date]{background:#18212c;border:1px solid #273242;color:#e8eef5;border-radius:10px;padding:8px 12px;font:inherit;font-size:13px}
.stats-dash .sd-controls input[type=date]{min-width:148px}
.stats-dash .sd-controls input[type=date]::-webkit-calendar-picker-indicator{filter:invert(.85);cursor:pointer}
.stats-dash .sd-btn{background:#1b2838;color:#e6edf5;border:1px solid #3d4f63;border-radius:10px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit}
.stats-dash .sd-btn:hover{border-color:#2ee59d}
.stats-dash .sd-btn:disabled{opacity:.55;cursor:wait}
.stats-dash .sd-overview{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.stats-dash .sd-kpi{background:#18212c;border:1px solid #273242;border-radius:12px;padding:12px 14px}
.stats-dash .sd-kpi b{display:block;font-size:22px;font-variant-numeric:tabular-nums;line-height:1.1;color:#fff}
.stats-dash .sd-kpi span{font-size:10px;color:#8b9bb0;text-transform:uppercase;letter-spacing:.45px}
.stats-dash .sd-kpi.ok b{color:#2ee59d}
.stats-dash .sd-kpi.fail b{color:#ff5d6c}
.stats-dash .sd-kpi.info b{color:#5eb3ff}
.stats-dash .sd-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.stats-dash .sd-tab{background:#18212c;border:1px solid #273242;color:#8b9bb0;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:600;cursor:pointer}
.stats-dash .sd-tab.on{border-color:#2ee59d;color:#2ee59d;background:rgba(46,229,157,.08)}
.stats-dash .sd-card{background:#18212c;border:1px solid #273242;border-radius:14px;padding:14px 16px}
.stats-dash .sd-card-hd{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;align-items:baseline}
.stats-dash .sd-card-hd b{font-size:14px}
.stats-dash .sd-card-hd span{font-size:12px;color:#8b9bb0}
.stats-dash .sd-sent{font-size:13px;color:#8b9bb0;margin-bottom:10px}
.stats-dash .sd-sent strong{color:#fff;font-size:18px;font-variant-numeric:tabular-nums}
.stats-dash .sd-row{display:grid;grid-template-columns:140px 64px 58px 1fr;gap:10px;align-items:center;margin:7px 0;font-size:13px}
.stats-dash .sd-row .lab{color:#c5d0dc}
.stats-dash .sd-row .num{text-align:right;font-variant-numeric:tabular-nums;color:#e8eef5;font-weight:700}
.stats-dash .sd-row .pct{text-align:right;font-variant-numeric:tabular-nums;color:#8b9bb0;font-weight:600;font-size:12px}
.stats-dash .sd-bar{height:8px;background:#243040;border-radius:99px;overflow:hidden}
.stats-dash .sd-bar>i{display:block;height:100%;width:0;border-radius:99px;transition:width .35s ease}
.stats-dash .sd-bar.blue>i{background:#3b82f6}
.stats-dash .sd-bar.red>i{background:#ef4444}
.stats-dash .sd-bar.green>i{background:#22c55e}
.stats-dash .sd-bar.orange>i{background:#f59e0b}
.stats-dash .sd-bar.gray>i{background:#64748b}
.stats-dash .sd-local{margin-top:12px;padding-top:12px;border-top:1px solid #273242}
.stats-dash .sd-note{font-size:12px;color:#8b9bb0;line-height:1.45;margin:0 0 12px;padding:10px 12px;background:#121821;border:1px solid #273242;border-radius:10px}
.stats-dash .sd-note b{color:#e8eef5}
.stats-dash .sd-acct-table{width:100%;border-collapse:collapse;font-size:12px;margin:0 0 14px}
.stats-dash .sd-acct-table th,.stats-dash .sd-acct-table td{padding:8px 8px;border-bottom:1px solid #243040;text-align:right;font-variant-numeric:tabular-nums}
.stats-dash .sd-acct-table th:first-child,.stats-dash .sd-acct-table td:first-child{text-align:left}
.stats-dash .sd-acct-table th{color:#8b9bb0;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.35px}
.stats-dash .sd-acct-table td{color:#e8eef5}
.stats-dash .sd-acct-table tr.total td{font-weight:700;border-bottom:0;padding-top:10px}
.stats-dash .sd-domains{margin:10px 0 14px;padding:12px;background:#121821;border:1px solid #273242;border-radius:12px}
.stats-dash .sd-domains h5{margin:0 0 8px;font-size:12px;color:#8b9bb0;text-transform:uppercase;letter-spacing:.4px}
.stats-dash .sd-dom{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #243040;font-size:13px}
.stats-dash .sd-dom:last-child{border-bottom:0}
.stats-dash .sd-dom b{color:#fff;font-weight:600}
.stats-dash .sd-pill{display:inline-block;border-radius:999px;padding:3px 9px;font-size:11px;font-weight:700}
.stats-dash .sd-pill.ok{background:rgba(46,229,157,.15);color:#2ee59d}
.stats-dash .sd-pill.bad{background:rgba(255,93,108,.15);color:#ff5d6c}
.stats-dash .sd-pill.mute{background:rgba(139,155,176,.12);color:#8b9bb0}
.stats-dash .sd-msg{font-size:12px;color:#8b9bb0;margin-top:10px;min-height:16px}
.stats-dash .sd-err{color:#ff5d6c}
@media(max-width:900px){.stats-dash .sd-overview{grid-template-columns:1fr 1fr}.stats-dash .sd-row{grid-template-columns:1fr 56px;grid-template-rows:auto auto}.stats-dash .sd-bar{grid-column:1/-1}}
body.send-mode{margin:0;background:var(--bg);color:var(--text);font-family:"DM Sans",system-ui,sans-serif}
.send-dash{position:sticky;top:0;z-index:9999;background:linear-gradient(180deg,#101820 0%,#0d131a 100%);color:var(--text);border-bottom:1px solid var(--line);box-shadow:0 12px 40px rgba(0,0,0,.45)}
.send-dash *{box-sizing:border-box}
.send-dash .hd{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-bottom:1px solid var(--line)}
.send-dash .hd-left{display:flex;align-items:center;gap:12px}
.send-dash .badge{width:10px;height:10px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 4px rgba(46,229,157,.15);animation:pulse 1.6s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(46,229,157,.35)}70%{box-shadow:0 0 0 10px rgba(46,229,157,0)}100%{box-shadow:0 0 0 0 rgba(46,229,157,0)}}
.send-dash .hd h2{margin:0;font-size:15px;font-weight:700;letter-spacing:.4px}
.send-dash .hd h2 small{display:block;font-weight:500;color:var(--muted);font-size:11px;margin-top:2px}
.send-dash .hd .status{font-size:12px;color:var(--muted);background:var(--card);border:1px solid var(--line);padding:6px 10px;border-radius:999px}
.send-dash .grid{display:grid;grid-template-columns:1.4fr 1fr;gap:14px;padding:14px 20px}
.send-dash .panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.send-dash .panel .pt{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line);font-size:12px;font-weight:600;letter-spacing:.3px;color:var(--muted);text-transform:uppercase}
.send-dash .panel .pt em{font-style:normal;color:var(--ok);font-weight:700}
.send-dash .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:12px}
.send-dash .stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px}
.send-dash .stat b{display:block;font-size:22px;line-height:1.15;color:#fff;font-variant-numeric:tabular-nums}
.send-dash .stat span{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.send-dash .stat.ok b{color:var(--ok)}
.send-dash .stat.fail b{color:var(--fail)}
.send-dash .stat.info b{color:var(--info);font-size:13px;word-break:break-all}
.send-dash .barwrap{padding:0 12px 12px}
.send-dash .barlab{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:6px}
.send-dash .bar{height:8px;background:#243040;border-radius:99px;overflow:hidden}
.send-dash .bar>i{display:block;height:100%;width:0;background:linear-gradient(90deg,#1a9f6a,var(--ok));transition:width .25s ease}
.send-dash .bar.filt>i{background:linear-gradient(90deg,#1a6fbf,var(--info))}
.send-dash .meta{padding:0 14px 12px;font-size:12px;color:var(--muted);min-height:18px}
.send-dash .filt-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:12px}
.send-dash .kv{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px}
.send-dash .kv b{display:block;font-size:18px;color:#fff}
.send-dash .kv span{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.45px}
.send-dash .filt-msg{padding:0 14px 12px;font-size:12px;color:var(--muted)}
.send-dash .api-credits{padding:12px;display:grid;gap:8px}
.send-dash .api-credits .api-total{display:flex;justify-content:space-between;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.send-dash .api-credits .api-total b{font-size:22px;color:var(--ok);font-variant-numeric:tabular-nums}
.send-dash .api-credits .api-total span{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.send-dash .api-credits .api-active{background:rgba(46,229,157,.08);border:1px solid rgba(46,229,157,.35);border-radius:12px;padding:12px 14px}
.send-dash .api-credits .api-active b{display:block;font-size:28px;color:var(--ok);font-variant-numeric:tabular-nums;line-height:1.1}
.send-dash .api-credits .api-active span{font-size:11px;color:var(--muted)}
.send-dash .api-credits .api-row{display:flex;justify-content:space-between;align-items:center;gap:10px;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13px}
.send-dash .api-credits .api-row.on{border-color:var(--ok);box-shadow:0 0 0 1px rgba(46,229,157,.25)}
.send-dash .api-credits .api-row.dead{opacity:.55}
.send-dash .api-credits .api-row b{font-variant-numeric:tabular-nums;color:#fff}
.send-dash .api-credits .api-row .muted{color:var(--muted);font-size:11px}
.send-dash .dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px;background:var(--warn)}
.send-dash .dot.on{background:var(--ok)}
.send-dash .dot.off{background:var(--fail)}
.send-dash .log-wrap{padding:0 20px 16px}
.send-dash .log-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.send-dash .log-col .pt{padding:0 0 8px;border:0;text-transform:uppercase;letter-spacing:.3px;color:#8b9bb0;font-size:12px;font-weight:600}
.send-dash .log{height:280px;overflow:auto;background:#0a0e13;border:1px solid var(--line);border-radius:14px;padding:12px 14px;font-family:Consolas,"Courier New",monospace;font-size:12px;line-height:1.5}
.send-dash .log .row{margin:0 0 5px;padding:6px 10px;border-radius:8px;border-left:3px solid #334;background:rgba(255,255,255,.02)}
.send-dash .log .row.ok{border-color:var(--ok);background:rgba(46,229,157,.06)}
.send-dash .log .row.fail{border-color:var(--fail);background:rgba(255,93,108,.06)}
.send-dash .log .row.info{border-color:var(--info);background:rgba(94,179,255,.05)}
.send-dash .log .row.warn{border-color:var(--warn);background:rgba(255,193,77,.06)}
.send-dash .log .t{color:#667788;margin-right:8px}
@media(max-width:980px){.send-dash .grid{grid-template-columns:1fr}.send-dash .stats{grid-template-columns:repeat(2,1fr)}.send-dash .log-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php if(empty($action)){ ?>
<script>
$(document).ready(function(){
	var i = 1;
	$("#addfiles").click(function(){
	event.preventDefault();
	i++;
	$("#mfiles").append("<div id='" + i +"'>" + i + "<input name='userfile[]' type='file'> [ <a href='#' class='remfile' id='" + i +"'>-</a> ]</div>");
	});
	$('#mfiles').on('click', 'a.remfile', function(e){
	e.preventDefault();
	   //alert(e.target.id);
	   $("#" + e.target.id).remove();
	});


	$("#patb").click(function(){
	if($("#az").prop( "checked" ) || $("#AZ").prop( "checked" ) || $("#09").prop( "checked" ) && $("#len").val() != "")
	{
	$("#patval").val("");
	$("#patval").val($("#patval").val() + "##");
	if($("#az").prop( "checked" ))
	{
	$("#patval").val($("#patval").val() + "az-");
	}
	 if($("#AZ").prop( "checked" ))
	{
	$("#patval").val($("#patval").val() + "AZ-");
	}
	 if($("#09").prop( "checked" ))
	{
	$("#patval").val($("#patval").val() + "09-");
	}
	if($("#len").val() != "")
	{
	$("#patval").val($("#patval").val() + "{"+$("#len").val()+ "}");
	}
	$("#patval").val($("#patval").val() + "##");
	
	}
	else $("#patval").val("");
	});
	function smtpEnc(){
		var enc = $('input[name=SSLTLS]:checked').val();
		return enc ? enc : 'TLS';
	}
	function smtpBcc(){
		return $('input[name=isbcc]').prop('checked') ? 'BCC' : 'NOBCC';
	}
	function appendSmtpLine(line){
		line = $.trim(line);
		if(!line) return;
		var cur = $('#my_smtp').val();
		$('#my_smtp').val(cur + (cur && cur.slice(-1) !== '\n' ? '\n' : '') + line + '\n');
	}
	function parseLoginPass(raw){
		raw = $.trim(raw).replace(/^["']|["']$/g, '');
		if(!raw || raw.indexOf(':') === -1) return null;
		var idx = raw.indexOf(':');
		var user = $.trim(raw.substring(0, idx));
		var pass = raw.substring(idx + 1);
		if(!user || pass === '') return null;
		return {user: user, pass: pass};
	}
	function buildSmtpFromLogin(user, pass){
		var host = $.trim($('#ip').val());
		var port = $.trim($('#ssl_port').val()) || '587';
		var enc = smtpEnc();
		if((port === '587' || /mail\.com|gmx\.|outlook\.|office365/i.test(host)) && enc === 'NON') enc = 'TLS';
		if(port === '465' && enc === 'NON') enc = 'SSL';
		if(!host) return null;
		return host+':'+port+':'+user+':'+pass+':'+enc+':'+smtpBcc();
	}
	function parseSmtpLine(raw){
		raw = $.trim(raw);
		if(!raw) return null;
		var usePipe = raw.indexOf('|') !== -1;
		var parts = usePipe ? raw.split('|') : raw.split(':');
		if(parts.length >= 4 && !/@/.test(parts[0])){
			var host = $.trim(parts[0]);
			var port = $.trim(parts[1]);
			var user = $.trim(parts[2]);
			var pass, enc, bcc, last, prev;
			last = $.trim(parts[parts.length-1]).toUpperCase();
			prev = parts.length > 4 ? $.trim(parts[parts.length-2]).toUpperCase() : '';
			if((last === 'BCC' || last === 'NOBCC') && (prev === 'SSL' || prev === 'TLS' || prev === 'NON')){
				enc = prev;
				bcc = last;
				pass = parts.slice(3, parts.length-2).join(usePipe ? '|' : ':');
			} else if(last === 'SSL' || last === 'TLS' || last === 'NON'){
				enc = last;
				bcc = smtpBcc();
				pass = parts.slice(3, parts.length-1).join(usePipe ? '|' : ':');
			} else {
				enc = smtpEnc();
				bcc = smtpBcc();
				pass = parts.slice(3).join(usePipe ? '|' : ':');
			}
			pass = $.trim(pass);
			if(!host || !port || !user) return null;
			return host+':'+port+':'+user+':'+pass+':'+enc+':'+bcc;
		}
		var lp = parseLoginPass(raw);
		if(!lp) return null;
		return buildSmtpFromLogin(lp.user, lp.pass);
	}
	function extractBulkEntries(bulk){
		var entries = [];
		var t = $.trim(bulk);
		if(t.charAt(0) === '['){
			try {
				var arr = JSON.parse(t);
				if($.isArray(arr)){
					for(var i=0;i<arr.length;i++){
						if(typeof arr[i] === 'string') entries.push(arr[i]);
						else if(arr[i] && arr[i].email && arr[i].pass) entries.push(arr[i].email+':'+arr[i].pass);
						else if(arr[i] && arr[i].user && arr[i].pass) entries.push(arr[i].user+':'+arr[i].pass);
						else if(arr[i] && arr[i].login && arr[i].password) entries.push(arr[i].login+':'+arr[i].password);
					}
					return entries;
				}
			} catch(e){}
		}
		var lines = t.split(/\r\n|\r|\n/);
		for(var j=0;j<lines.length;j++){
			var line = $.trim(lines[j]).replace(/^,|,$/g, '');
			if(line) entries.push(line);
		}
		return entries;
	}
	$("#add").click(function(){
		var bulk = $.trim($('#bulk_smtp').val());
		var added = 0;
		if(bulk){
			var entries = extractBulkEntries(bulk);
			var needHost = false;
			for(var i=0;i<entries.length;i++){
				var parsed = parseSmtpLine(entries[i]);
				if(parsed){ appendSmtpLine(parsed); added++; }
				else if(parseLoginPass(entries[i]) && !$.trim($('#ip').val())) needHost = true;
			}
			if(added){
				$('#bulk_smtp').val('');
				alert(added + ' SMTP(s) added.');
			} else if(needHost){
				alert('JSON has login:pass only.\nFill SMTP IP + Port first, then click add smtp.');
			} else {
				alert('No valid entries found.\nJSON: ["email:pass","email2:pass2"]\nor: host:port:user:pass');
			}
			return;
		}
		var host = $.trim($('#ip').val());
		var port = $.trim($('#ssl_port').val());
		var user = $.trim($('#user').val());
		var pass = $('#pass').val();
		if(!host || !user){
			alert('Fill SMTP IP + LOGIN, or paste JSON / SMTPs in the bulk box.');
			return;
		}
		appendSmtpLine(host+':'+port+':'+user+':'+pass+':'+smtpEnc()+':'+smtpBcc());
		$('#user').val('');
		$('#pass').val('');
	});
	$("#reset").click(function(){
		$('#my_smtp').val('');
		$('#bulk_smtp').val('');
	});
	$("input[name=lase]").click(function(){
	if($('input[name=lase]').prop('checked'))
	{
	$('input[name=from]').attr('disabled','disabled');
	$('input[name=from]').val('');
	}
	else
	$('input[name=from]').removeAttr('disabled');
	});
	$("input[name=repaslog]").click(function(){
	if($('input[name=repaslog]').prop('checked'))
	{
	$('input[name=replyto]').attr('disabled','disabled');
	$('input[name=replyto]').val('');
	}
	else
	$('input[name=replyto]').removeAttr('disabled');
	});
});
</script>
<div class="app-shell" id="app-shell">
<aside class="app-sidebar" id="app-sidebar">
  <div class="sb-brand">AYB <em>Mailer</em></div>
  <nav class="sb-nav" id="app-nav">
    <button type="button" class="sb-link on" data-page="setup">Setup</button>
    <button type="button" class="sb-link" data-page="recipients">Recipients</button>
    <button type="button" class="sb-link" data-page="compose">Compose</button>
    <button type="button" class="sb-link" data-page="analytics" id="nav-analytics">Analytics</button>
  </nav>
  <div class="sb-foot">Setup → Recipients → Compose</div>
</aside>
<div class="app-main">
  <div class="app-topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button type="button" class="sb-toggle" id="sb-toggle" aria-label="Menu">Menu</button>
      <h2 id="app-page-title">Setup<small id="setup-hero-sub">Mode, accounts, and SOCKS — everything to connect.</small></h2>
    </div>
    <div class="tb-actions">
      <button type="button" class="btn btn-ghost" id="btn-goto-compose">Compose</button>
      <button type="submit" form="form1" class="btn btn-primary" id="btn-top-send">Send Message</button>
    </div>
  </div>
  <div class="app-content">
  <div id="pending-banner" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:10px;background:#e8f7ef;border:1px solid #b7e4c7;color:#0d5c3a;font-size:13px;font-weight:600"></div>
<!-- proxy panel moved into Connections page; keep marker -->
<div id="proxy-test-panel-holder" style="display:none"></div>
<div id="proxy-test-panel" class="setup-card mode-only-normal" style="margin-bottom:16px;<?php if($smtp_mode==='brevo'||$smtp_mode==='brevo_api') echo 'display:none'; ?>">
  <div class="setup-proxy">
    <div>
      <h3 style="margin-bottom:6px">SOCKS Run Test</h3>
      <div style="color:var(--s-muted);font-size:13px">Upload the proxy lists here and Save. Run Test keeps the ones that work. Send uses that saved OK list.</div>
    </div>
    <div class="setup-actions" style="margin:0;justify-content:flex-end">
      <button type="button" id="btn-run-test" class="btn btn-ok">Run Test</button>
      <button type="button" id="btn-stop-test" class="btn btn-danger">Stop Test</button>
    </div>
  </div>
  <div class="setup-proxy-stats">
    <div><b id="pt-state">—</b><span>State</span></div>
    <div><b id="pt-prog">—</b><span>Scanned</span></div>
    <div><b id="pt-ok">0</b><span>OK proxies</span></div>
    <div><b id="pt-pct">0%</b><span>Progress</span></div>
  </div>
  <div id="pt-msg" style="margin-top:10px;font-size:12px">Click Run Test before Send (or while sending — the OK list keeps growing).</div>
  <div class="setup-row" style="margin-top:14px">
    <div class="setup-field">
      <label>SOCKS5 list</label>
      <textarea id="socks5-editor" rows="5" placeholder="One proxy per line: ip:port"></textarea>
      <input type="file" id="socks5-file" accept=".txt,.csv,.list,text/plain" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden">
      <div class="rc-tools">
        <button type="button" id="btn-socks5-upload" class="btn btn-ghost">Upload</button>
        <button type="button" id="btn-socks5-save" class="btn btn-primary">Save</button>
      </div>
      <div id="socks5-msg" class="hint" style="margin:8px 0 0"></div>
    </div>
    <div class="setup-field">
      <label>SOCKS4 list</label>
      <textarea id="socks4-editor" rows="5" placeholder="One proxy per line: ip:port"></textarea>
      <input type="file" id="socks4-file" accept=".txt,.csv,.list,text/plain" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden">
      <div class="rc-tools">
        <button type="button" id="btn-socks4-upload" class="btn btn-ghost">Upload</button>
        <button type="button" id="btn-socks4-save" class="btn btn-primary">Save</button>
      </div>
      <div id="socks4-msg" class="hint" style="margin:8px 0 0"></div>
    </div>
  </div>
</div>
<script>
(function(){
  function set(id,v){var e=document.getElementById(id); if(e) e.textContent=v;}
  function refresh(){
    fetch('filter_status.php?json=1&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      if(!d) return;
      set('pt-state', (d.state|| (d.running?'running':'idle')).toUpperCase());
      set('pt-prog', (d.progress||0)+' / '+(d.total||'?'));
      set('pt-ok', String(d.ok_count!=null?d.ok_count:0));
      set('pt-pct', (d.percent!=null?d.percent:0)+'%');
      set('pt-msg', d.message||'');
    }).catch(function(){});
  }
  var btn=document.getElementById('btn-run-test');
  var btnStop=document.getElementById('btn-stop-test');
  if(btn) btn.onclick=function(){
    btn.disabled=true; btn.textContent='Starting…';
    // scratch=1 always — otherwise finished 5754/5754 exits immediately saying "finished"
    fetch('filter_status.php?start=1&scratch=1&json=1&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      btn.disabled=false; btn.textContent='Run Test';
      if(d) set('pt-msg', d.message||'Started');
      refresh();
    }).catch(function(){ btn.disabled=false; btn.textContent='Run Test'; });
  };
  if(btnStop) btnStop.onclick=function(){
    btnStop.disabled=true; btnStop.textContent='Stopping…';
    fetch('filter_status.php?stop=1&json=1&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      btnStop.disabled=false; btnStop.textContent='Stop Test';
      if(d) set('pt-msg', d.message||'Stopped');
      refresh();
    }).catch(function(){ btnStop.disabled=false; btnStop.textContent='Stop Test'; });
  };
  refresh();
  setInterval(refresh, 3000);
  function proxyMsg(kind, t, ok){
    var m=document.getElementById(kind+'-msg');
    if(!m) return;
    m.textContent=t||'';
    m.style.color=ok===false?'#c0392b':(ok===true?'var(--s-ok)':'');
  }
  function proxyPost(kind, action, text, file){
    var fd=new FormData();
    fd.append('kind', kind);
    fd.append('action', action);
    if(text!=null) fd.append('text', text);
    if(file) fd.append('file', file);
    return fetch('proxy_lists.php',{method:'POST',body:fd,cache:'no-store'}).then(function(r){return r.json();});
  }
  function bindProxy(kind){
    var ta=document.getElementById(kind+'-editor');
    var file=document.getElementById(kind+'-file');
    var up=document.getElementById('btn-'+kind+'-upload');
    var save=document.getElementById('btn-'+kind+'-save');
    if(up && file) up.onclick=function(){ file.click(); };
    if(file) file.onchange=function(){
      if(!this.files || !this.files[0]) return;
      var f=this.files[0];
      this.value='';
      proxyMsg(kind, 'Uploading '+f.name+'…', null);
      proxyPost(kind, 'upload', null, f).then(function(d){
        if(!d||!d.ok){ proxyMsg(kind, (d&&d.err)||'Upload failed', false); return; }
        if(ta) ta.value=d.text||'';
        proxyMsg(kind, d.msg||('Saved '+d.count), true);
      }).catch(function(){ proxyMsg(kind, 'Upload failed', false); });
    };
    if(save && ta) save.onclick=function(){
      proxyMsg(kind, 'Saving…', null);
      proxyPost(kind, 'save', ta.value||'', null).then(function(d){
        if(!d||!d.ok){ proxyMsg(kind, (d&&d.err)||'Save failed', false); return; }
        if(typeof d.text==='string') ta.value=d.text;
        proxyMsg(kind, d.msg||('Saved '+d.count), true);
      }).catch(function(){ proxyMsg(kind, 'Save failed', false); });
    };
    proxyPost(kind, 'load', '', null).then(function(d){
      if(!d||!d.ok) return;
      if(ta && !ta.value) ta.value=d.text||'';
      if(d.count) proxyMsg(kind, d.count+' proxies on this computer', true);
    }).catch(function(){});
  }
  bindProxy('socks5');
  bindProxy('socks4');
})();
function syncSmtpMode(){
  var brevo = document.getElementById('mode_brevo') && document.getElementById('mode_brevo').checked;
  var brevoApi = document.getElementById('mode_brevo_api') && document.getElementById('mode_brevo_api').checked;
  var normal = !brevo && !brevoApi;
  var noSocks = brevo || brevoApi;
  function show(el, on){
    if(!el) return;
    el.style.display = on ? '' : 'none';
  }
  function showAll(sel, on){
    var list=document.querySelectorAll(sel);
    for(var i=0;i<list.length;i++) show(list[i], on);
  }
  showAll('.mode-only-normal', normal);
  showAll('.mode-only-brevo', brevo);
  showAll('.mode-only-brevo-api', brevoApi);
  showAll('.mode-hide-api', !brevoApi);
  showAll('.mode-smtp-any', normal || brevo);

  var socks = document.querySelector('input[name=use_socks]');
  var workers = document.querySelector('input[name=send_workers]');
  var hid = document.getElementById('use_socks_persist');
  if(socks){
    if(noSocks){ socks.checked = false; socks.disabled = true; }
    else { socks.disabled = false; }
  }
  if(hid) hid.value = (normal && socks && socks.checked) ? '1' : '0';
  if(workers){
    if(noSocks){
      if(!workers.getAttribute('data-prev')) workers.setAttribute('data-prev', workers.value || '5');
      workers.value = 1;
      workers.disabled = true;
    } else {
      workers.disabled = false;
      var prev = workers.getAttribute('data-prev');
      if(prev && String(workers.value) === '1') workers.value = prev;
    }
  }
  var lase=document.getElementById('lase_cb')||document.querySelector('input[name=lase]');
  var fromInput=document.querySelector('input[name=from]');
  if(brevoApi && lase && fromInput){
    lase.checked=false;
    fromInput.disabled=false;
  }
  var hero=document.getElementById('setup-hero-sub');
  if(hero && document.getElementById('page-setup') && document.getElementById('page-setup').classList.contains('on')){
    if(brevoApi) hero.textContent='Brevo API — paste API accounts, From, message, list. No SMTP / SOCKS.';
    else if(brevo) hero.textContent='Brevo SMTP — paste #ACCOUNT blocks. No SOCKS.';
    else hero.textContent='Normal mode — SMTP list + optional SOCKS Run Test, then send.';
  }
  var apiCard=document.querySelector('.rc-list.api');
  var smtpCard=document.querySelector('.rc-list.smtp');
  if(apiCard) apiCard.classList.toggle('is-live', !normal);
  if(smtpCard) smtpCard.classList.toggle('is-live', !!normal);
}
document.addEventListener('DOMContentLoaded', function(){
  var a=document.getElementById('mode_normal'), b=document.getElementById('mode_brevo'), c=document.getElementById('mode_brevo_api');
  if(a) a.onchange = syncSmtpMode;
  if(b) b.onchange = syncSmtpMode;
  if(c) c.onchange = syncSmtpMode;
  // Move SOCKS test panel into Connections page (keeps IDs / scripts working)
  var proxyPanel=document.getElementById('proxy-test-panel');
  var proxySlot=document.getElementById('connections-proxy-slot');
  if(proxyPanel && proxySlot) proxySlot.appendChild(proxyPanel);
  syncSmtpMode();

  // —— Sidebar page navigation (views only; form stays one POST) ——
  var PAGE_KEY='bayanv_page_v1';
  var PAGE_META={
    setup:{title:'Setup', sub:'Mode, accounts, and SOCKS — everything to connect'},
    recipients:{title:'Recipients', sub:'The highlighted list is the one Send uses'},
    compose:{title:'Compose', sub:'Write the email, set pace, then send'},
    analytics:{title:'Analytics', sub:'Brevo stats and domain authentication'}
  };
  // Map old empty pages → dense ones
  var PAGE_ALIAS={mode:'setup', connections:'setup', message:'compose', send:'compose'};
  function showPage(name){
    if(PAGE_ALIAS[name]) name=PAGE_ALIAS[name];
    if(!PAGE_META[name]) name='setup';
    var pages=document.querySelectorAll('.nav-page');
    for(var i=0;i<pages.length;i++) pages[i].classList.toggle('on', pages[i].id==='page-'+name);
    var links=document.querySelectorAll('.sb-link');
    for(var j=0;j<links.length;j++) links[j].classList.toggle('on', links[j].getAttribute('data-page')===name);
    var meta=PAGE_META[name];
    var titleEl=document.getElementById('app-page-title');
    if(titleEl){
      titleEl.innerHTML=meta.title+'<small id="setup-hero-sub"></small>';
      var sub=document.getElementById('setup-hero-sub');
      if(sub){
        if(name==='setup' && typeof syncSmtpMode==='function') syncSmtpMode();
        else sub.textContent=meta.sub;
      }
    }
    try{ localStorage.setItem(PAGE_KEY, name); }catch(e){}
    if(location.hash !== '#'+name){
      try{ history.replaceState(null,'','#'+name); }catch(e2){ location.hash=name; }
    }
    var side=document.getElementById('app-sidebar');
    if(side) side.classList.remove('open');
  }
  window.showAppPage=showPage;
  var nav=document.getElementById('app-nav');
  if(nav) nav.addEventListener('click', function(ev){
    var t=ev.target;
    if(!t || !t.getAttribute) return;
    var p=t.getAttribute('data-page');
    if(p) showPage(p);
  });
  var btnGoto=document.getElementById('btn-goto-compose');
  if(btnGoto) btnGoto.onclick=function(){ showPage('compose'); };
  var sbToggle=document.getElementById('sb-toggle');
  if(sbToggle) sbToggle.onclick=function(){
    var side=document.getElementById('app-sidebar');
    if(side) side.classList.toggle('open');
  };
  var startPage=(location.hash||'').replace(/^#/,'') || '';
  if(PAGE_ALIAS[startPage]) startPage=PAGE_ALIAS[startPage];
  try{ if(!PAGE_META[startPage]){ startPage=localStorage.getItem(PAGE_KEY)||'setup'; if(PAGE_ALIAS[startPage]) startPage=PAGE_ALIAS[startPage]; } }catch(e){ startPage='setup'; }
  if(!PAGE_META[startPage]) startPage='setup';
  showPage(startPage);
  window.addEventListener('hashchange', function(){
    var h=(location.hash||'').replace(/^#/,'');
    if(PAGE_ALIAS[h]) h=PAGE_ALIAS[h];
    if(PAGE_META[h]) showPage(h);
  });

  function brevoParseLocalAccounts(text){
    var accounts=[], cur=null, lines=(text||'').split(/\r\n|\r|\n/);
    function flush(){
      if(cur && cur.key){ if(!cur.limit) cur.limit=300; accounts.push(cur); }
      cur=null;
    }
    for(var i=0;i<lines.length;i++){
      var line=lines[i].replace(/^\s+|\s+$/g,'');
      if(!line) continue;
      var m=line.match(/^#\s*ACCOUNT\s+(\d+)/i);
      if(m){ flush(); cur={limit:Math.max(1,parseInt(m[1],10)||300), key:'', from:'', problem:false}; continue; }
      if(line.charAt(0)==='#') continue;
      var fm=line.match(/^from\s*[:=]\s*(.+)$/i);
      if(fm){ if(!cur) cur={limit:300,key:'',from:'',problem:false}; cur.from=fm[1].replace(/^\s+|\s+$/g,''); continue; }
      var pm=line.match(/^problem\s*[:=]\s*(.+)$/i);
      if(pm){ if(!cur) cur={limit:300,key:'',from:'',problem:false}; cur.problem=/^(1|yes|true|on)$/i.test(pm[1].replace(/^\s+|\s+$/g,'')); continue; }
      if(/^name\s*[:=]/i.test(line)) continue;
      if(/^xkeysib-/i.test(line) || (line.length>40 && line.indexOf(' ')<0 && line.indexOf('@')<0)){
        if(!cur) cur={limit:300,key:'',from:'',problem:false};
        if(cur.key){ flush(); cur={limit:300,key:'',from:'',problem:false}; }
        cur.key=line;
      }
    }
    flush();
    return accounts;
  }
  function brevoAccountsToText(list){
    var chunks=[];
    (list||[]).forEach(function(a){
      var lim=a.limit||300;
      var block='#ACCOUNT '+lim+'\n'+(a.key||'');
      if(a.from) block+='\nfrom: '+a.from;
      if(a.problem) block+='\nproblem: 1';
      chunks.push(block);
    });
    return chunks.join('\n\n');
  }
  function brevoSyncTextarea(list){
    var ta=document.getElementById('brevo_api_keys');
    if(ta) ta.value=brevoAccountsToText(list);
    if(typeof save==='function') try{ save(); }catch(e){}
  }
  function brevoGetAccountsMerged(){
    var ta=document.getElementById('brevo_api_keys');
    var locals=brevoParseLocalAccounts(ta ? ta.value : '');
    return locals.map(function(a,i){
      var o={index:i+1, limit:a.limit, from:a.from, key:a.key, problem:!!a.problem, key_preview:(a.key||'').slice(0,14)+'…'};
      var prev=window.__brevoCreditState && window.__brevoCreditState[i];
      if(prev && prev.key_preview===(a.key||'').slice(0,14)+'…'){
        o.ok=prev.ok; o.credits=prev.credits; o.plan_type=prev.plan_type; o.end_date=prev.end_date; o.email=prev.email; o.err=prev.err; o.key_preview=prev.key_preview||o.key_preview; o.status_text=prev.status_text||''; o.blocking=!!prev.blocking;
      } else if(prev && prev._key===a.key){
        o.ok=prev.ok; o.credits=prev.credits; o.plan_type=prev.plan_type; o.end_date=prev.end_date; o.email=prev.email; o.err=prev.err; o.status_text=prev.status_text||''; o.blocking=!!prev.blocking;
      }
      return o;
    });
  }
  function brevoSaveCreditState(rows){
    window.__brevoCreditState=(rows||[]).map(function(x){
      return {ok:x.ok,credits:x.credits,plan_type:x.plan_type,end_date:x.end_date,email:x.email,err:x.err,key_preview:x.key_preview,status_text:x.status_text||'',blocking:!!x.blocking,_key:x.key};
    });
  }
  function brevoRenderCreditCards(list, totalOverride){
    var wrap=document.getElementById('brevo-credits-cards');
    var totEl=document.getElementById('brevo-credits-total');
    if(!wrap) return;
    wrap.innerHTML='';
    var total=0, known=0;
    if(!(list||[]).length){
      wrap.innerHTML='<div class="hint" style="margin:0">No accounts yet — fill API key + From email, then Add account.</div>';
      if(totEl) totEl.textContent='Total left today: —';
      return;
    }
    (list||[]).forEach(function(a, idx){
      var n=a.index|| (idx+1);
      var card=document.createElement('div');
      card.style.cssText='background:var(--s-input);border:1px solid '+(a.problem||a.blocking?'#c0392b':'var(--s-line)')+';border-radius:10px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap';
      var left=document.createElement('div');
      left.style.flex='1';
      var status='Not checked yet';
      if(a.ok){
        var leftN=Math.floor(a.credits);
        total+=leftN; known++;
        status='<b style="color:var(--s-ok);font-size:16px">'+leftN+' left</b> on Brevo';
        if(a.plan_type) status+=' <span style="color:var(--s-muted)">('+a.plan_type+')</span>';
        if(a.end_date) status+=' <span style="color:var(--s-muted)">until '+String(a.end_date).slice(0,10)+'</span>';
      } else if(a.err && !a.status_text){
        status='<span style="color:#c0392b">'+brevoEsc(a.err)+'</span>';
      } else if(a.pending){
        status='Checking…';
      }
      if(a.problem) status+='<div style="margin-top:4px;color:#c0392b;font-weight:700">Marked problem — send skips this account</div>';
      if(a.status_text) status+='<div style="margin-top:4px;color:'+(a.blocking?'#c0392b':'#9a6b2f')+';font-weight:600">'+brevoEsc(a.status_text)+(a.blocking?' — send skips this account':'')+'</div>';
      left.innerHTML='<div style="font-weight:600;margin-bottom:4px">Account '+n+(a.limit?' · limit '+a.limit+'/day':'')+'</div>'
        +'<div style="font-size:13px;margin-bottom:6px">From: <b>'+((a.from||a.email||'(none)').replace(/</g,'&lt;'))+'</b></div>'
        +'<div style="font-size:13px">'+status+'</div>'
        +'<div style="font-size:11px;color:var(--s-muted);margin-top:2px">'+(a.key_preview||'')+'</div>';
      var actions=document.createElement('div');
      actions.style.cssText='display:flex;gap:6px;flex-wrap:wrap';
      var btn=document.createElement('button');
      btn.type='button'; btn.className='btn btn-ghost'; btn.textContent='Check';
      btn.onclick=function(){ brevoCheckOne(a.key, n, a.from); };
      var btnEdit=document.createElement('button');
      btnEdit.type='button'; btnEdit.className='btn btn-ghost'; btnEdit.textContent='Edit';
      btnEdit.onclick=function(){ brevoStartEdit(n-1); };
      var btnProb=document.createElement('button');
      btnProb.type='button'; btnProb.className='btn btn-ghost'; btnProb.textContent=a.problem?'Use again':'Mark problem';
      btnProb.onclick=function(){ brevoToggleProblem(n-1); };
      var btnRm=document.createElement('button');
      btnRm.type='button'; btnRm.className='btn btn-ghost'; btnRm.textContent='Remove';
      btnRm.onclick=function(){ brevoRemoveAccount(n-1); };
      actions.appendChild(btn);
      actions.appendChild(btnEdit);
      actions.appendChild(btnProb);
      actions.appendChild(btnRm);
      card.appendChild(left);
      card.appendChild(actions);
      wrap.appendChild(card);
    });
    if(totEl){
      if(typeof totalOverride==='number') totEl.textContent='Total left today (all accounts): '+Math.floor(totalOverride);
      else if(known>0) totEl.textContent='Total left today (all accounts): '+total;
      else totEl.textContent='Total left today (all accounts): —';
    }
  }
  function brevoEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function brevoToggleProblem(idx){
    var ta=document.getElementById('brevo_api_keys');
    var locals=brevoParseLocalAccounts(ta ? ta.value : '');
    if(idx<0 || idx>=locals.length) return;
    locals[idx].problem=!locals[idx].problem;
    brevoSyncTextarea(locals);
    brevoRenderCreditCards(brevoGetAccountsMerged());
  }
  function brevoRemoveAccount(idx){
    var ta=document.getElementById('brevo_api_keys');
    var locals=brevoParseLocalAccounts(ta ? ta.value : '');
    if(idx<0 || idx>=locals.length) return;
    locals.splice(idx,1);
    if(window.__brevoCreditState) window.__brevoCreditState.splice(idx,1);
    brevoSyncTextarea(locals);
    brevoRenderCreditCards(brevoGetAccountsMerged());
  }
  function brevoCancelEdit(){
    window.__brevoKeyEdit=null;
    var btnAdd=document.getElementById('btn-brevo-add');
    if(btnAdd) btnAdd.textContent='Add account';
    var keyEl=document.getElementById('brevo_add_key');
    if(keyEl){ keyEl.type='password'; keyEl.value=''; }
    var note=document.getElementById('brevo-key-edit-note');
    if(note) note.textContent='';
  }
  function brevoStartEdit(idx){
    var ta=document.getElementById('brevo_api_keys');
    var locals=brevoParseLocalAccounts(ta ? ta.value : '');
    var acc=locals[idx];
    if(!acc) return;
    window.__brevoKeyEdit=idx;
    var btnAdd=document.getElementById('btn-brevo-add');
    if(btnAdd) btnAdd.textContent='Save';
    var keyEl=document.getElementById('brevo_add_key');
    if(keyEl){ keyEl.type='text'; keyEl.value=acc.key||''; keyEl.focus(); }
    var fromEl=document.getElementById('brevo_add_from');
    if(fromEl) fromEl.value=acc.from||'';
    var limEl=document.getElementById('brevo_add_limit');
    if(limEl) limEl.value=String(acc.limit||300);
    var note=document.getElementById('brevo-key-edit-note');
    if(note) note.textContent='Editing account '+(idx+1)+'. The current key and From email are in the boxes. Change what you need, then Save.';
  }
  function brevoAddAccount(){
    var keyEl=document.getElementById('brevo_add_key');
    var fromEl=document.getElementById('brevo_add_from');
    var limEl=document.getElementById('brevo_add_limit');
    var key=(keyEl && keyEl.value || '').replace(/^\s+|\s+$/g,'');
    var from=(fromEl && fromEl.value || '').replace(/^\s+|\s+$/g,'');
    var limit=limEl ? (parseInt(limEl.value,10)||300) : 300;
    if(!key){ alert('Paste the Brevo API key (xkeysib-…)'); return; }
    var taEdit=document.getElementById('brevo_api_keys');
    var localsEdit=brevoParseLocalAccounts(taEdit ? taEdit.value : '');
    if(typeof window.__brevoKeyEdit==='number'){
      var idx=window.__brevoKeyEdit;
      if(idx<0 || idx>=localsEdit.length){ brevoCancelEdit(); return; }
      if(!from || from.indexOf('@')<0){ alert('Enter a valid From email'); return; }
      var keyChanged=localsEdit[idx].key!==key;
      localsEdit[idx].key=key;
      localsEdit[idx].from=from;
      if(limEl && String(limEl.value).replace(/^\s+|\s+$/g,'')!=='') localsEdit[idx].limit=limit;
      if(keyChanged && window.__brevoCreditState) window.__brevoCreditState[idx]=null;
      brevoSyncTextarea(localsEdit);
      if(fromEl) fromEl.value='';
      if(limEl) limEl.value='300';
      brevoCancelEdit();
      brevoRenderCreditCards(brevoGetAccountsMerged());
      return;
    }
    if(!from || from.indexOf('@')<0){ alert('Enter the From email for this account (verified in Brevo)'); return; }
    var ta=document.getElementById('brevo_api_keys');
    var locals=brevoParseLocalAccounts(ta ? ta.value : '');
    locals.push({limit:limit, key:key, from:from});
    brevoSyncTextarea(locals);
    if(keyEl) keyEl.value='';
    if(fromEl) fromEl.value='';
    if(limEl) limEl.value='300';
    brevoRenderCreditCards(brevoGetAccountsMerged());
  }
  function brevoCheckOne(key, index, from){
    if(!key){ alert('No API key for this account'); return; }
    fetch('brevo_credits.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({key:key, from:from||''}),
      cache:'no-store'
    }).then(function(r){return r.json();}).then(function(d){
      var row=(d && d.accounts && d.accounts[0]) ? d.accounts[0] : null;
      if(!row){ alert((d && d.err) || 'Check failed'); return; }
      var merged=brevoGetAccountsMerged().map(function(a,i){
        if(i+1===index){
          a.ok=row.ok; a.credits=row.credits; a.plan_type=row.plan_type; a.end_date=row.end_date; a.email=row.email; a.err=row.err; a.key_preview=row.key_preview; a.status_text=row.status_text||''; a.blocking=!!row.blocking;
        }
        return a;
      });
      brevoSaveCreditState(merged);
      var sum=0, n=0;
      merged.forEach(function(x){ if(x.ok){ sum+=Math.floor(x.credits||0); n++; } });
      brevoRenderCreditCards(merged, n>0 ? sum : undefined);
    }).catch(function(){ alert('Request failed'); });
  }
  function brevoCheckAll(){
    var ta=document.getElementById('brevo_api_keys');
    var text=ta ? (ta.value||'') : '';
    var btnCred=document.getElementById('btn-brevo-credits');
    var totEl=document.getElementById('brevo-credits-total');
    var locals=brevoParseLocalAccounts(text);
    if(!locals.length){ if(totEl) totEl.textContent='Add accounts first'; return; }
    brevoRenderCreditCards(locals.map(function(a,i){ return {index:i+1,limit:a.limit,from:a.from,key:a.key,key_preview:(a.key||'').slice(0,14)+'…',pending:true}; }));
    if(btnCred){ btnCred.disabled=true; btnCred.textContent='Checking…'; }
    fetch('brevo_credits.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({text:text}),
      cache:'no-store'
    }).then(function(r){return r.json();}).then(function(d){
      if(btnCred){ btnCred.disabled=false; btnCred.textContent='Check all left'; }
      if(!d || !d.ok){
        if(totEl) totEl.textContent=(d && d.err) ? d.err : 'Failed';
        return;
      }
      var rows=(d.accounts||[]).map(function(a,i){
        var loc=locals[i]||{};
        return {
          index:a.index|| (i+1),
          limit:a.limit||loc.limit||300,
          from:a.from||loc.from||'',
          key:loc.key||'',
          key_preview:a.key_preview,
          ok:a.ok, credits:a.credits, plan_type:a.plan_type, end_date:a.end_date, email:a.email, err:a.err, status_text:a.status_text||'', blocking:!!a.blocking
        };
      });
      brevoSaveCreditState(rows);
      brevoRenderCreditCards(rows, d.total_left);
    }).catch(function(){
      if(btnCred){ btnCred.disabled=false; btnCred.textContent='Check all left'; }
      if(totEl) totEl.textContent='Request failed';
    });
  }
  var btnCred=document.getElementById('btn-brevo-credits');
  if(btnCred) btnCred.onclick=brevoCheckAll;
  var btnAdd=document.getElementById('btn-brevo-add');
  if(btnAdd) btnAdd.onclick=brevoAddAccount;
  // Expose for localStorage load() in the other script
  window.brevoGetAccountsMerged=brevoGetAccountsMerged;
  window.brevoRenderCreditCards=brevoRenderCreditCards;

  // —— Brevo analytics dashboard (pro metrics) ——
  window.__brevoStats=null;
  window.__brevoStatsTab='all';
  function sdEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  function sdMetricRows(rates, metrics){
    rates=rates||{};
    metrics=metrics||{};
    var n=function(v){ var x=parseInt(v,10); return isNaN(x)?0:x; };
    var rows=[
      {lab:'Delivered', pct:rates.delivered_pct, count:n(metrics.delivered), cls:'blue'},
      {lab:'Bounced', pct:rates.bounced_pct, count:n(metrics.bounces!=null?metrics.bounces:(n(metrics.hardBounces)+n(metrics.softBounces))), cls:'red'},
      {lab:'Estimated openers', pct:rates.estimated_openers_pct, count:n(metrics.uniqueOpens), cls:'green'},
      {lab:'Trackable openers', pct:rates.trackable_openers_pct, count:n(metrics.uniqueOpens), cls:'green'},
      {lab:'Unique clickers', pct:rates.unique_clickers_pct, count:n(metrics.uniqueClicks), cls:'green'},
      {lab:'Complaint', pct:rates.complaint_pct, count:n(metrics.spamReports), cls:'gray'},
      {lab:'Hard bounce', pct:rates.hard_bounce_pct, count:n(metrics.hardBounces), cls:'red'},
      {lab:'Soft bounce', pct:rates.soft_bounce_pct, count:n(metrics.softBounces), cls:'orange'},
      {lab:'Blocked', pct:rates.blocked_pct, count:n(metrics.blocked), cls:'gray'},
      {lab:'Invalid', pct:null, count:n(metrics.invalid), cls:'gray'},
      {lab:'Unsubscribed', pct:null, count:n(metrics.unsubscribed), cls:'gray'},
      {lab:'Opens (total)', pct:null, count:n(metrics.opens), cls:'green'},
      {lab:'Clicks (total)', pct:null, count:n(metrics.clicks), cls:'green'}
    ];
    var html='';
    for(var i=0;i<rows.length;i++){
      var r=rows[i];
      var p=(r.pct==null||r.pct==='')?null:Math.max(0, Math.min(100, parseFloat(r.pct)||0));
      var pctTxt=p==null?'—':(p.toFixed(2)+'%');
      var barW=p==null?0:p;
      html+='<div class="sd-row"><span class="lab">'+sdEsc(r.lab)+'</span><span class="num">'+r.count+'</span><span class="pct">'+pctTxt+'</span><div class="sd-bar '+r.cls+'"><i style="width:'+barW+'%"></i></div></div>';
    }
    return html;
  }
  function sdRenderLocal(lc){
    var el=document.getElementById('brevo-stats-local');
    if(!el||!lc){ if(el) el.style.display='none'; return; }
    el.style.display='block';
    var p=Math.max(0, Math.min(100, parseFloat(lc.progress_pct)||0));
    el.innerHTML='<div class="sd-card-hd"><b>'+sdEsc(lc.name||'Local campaign')+'</b><span>all-time queue — not today’s Brevo count</span></div>'+
      '<div class="sd-note">This is <b>mailssenddone.txt</b> total (every OK ever logged here). It is <b>not</b> “emails sent today”. Brevo numbers above are from Brevo’s API for the selected day/period, summed across accounts.</div>'+
      '<div class="sd-sent">Sent OK (all time) · <strong>'+sdEsc(lc.done)+'</strong> · pending <strong>'+sdEsc(lc.pending)+'</strong> · list <strong>'+sdEsc(lc.valid)+'</strong></div>'+
      '<div class="sd-row"><span class="lab">Campaign progress</span><span class="num">'+sdEsc(lc.done)+'</span><span class="pct">'+p.toFixed(2)+'%</span><div class="sd-bar green"><i style="width:'+p+'%"></i></div></div>';
  }
  function sdDailyBreakdown(daily){
    daily=daily||[];
    if(!daily.length) return '';
    var html='<div class="sd-domains"><h5>Brevo by calendar day (API /reports — not local files)</h5>';
    html+='<table class="sd-acct-table"><thead><tr><th>Date</th><th>Sent</th><th>Delivered</th><th>Bounced</th><th>Blocked</th></tr></thead><tbody>';
    daily.forEach(function(r){
      var mark=r.is_selected?' style="color:#2ee59d"':'';
      html+='<tr'+mark+'><td><b>'+sdEsc(r.date)+'</b>'+(r.is_selected?' · selected':'')+'</td><td>'+r.requests+'</td><td>'+r.delivered+'</td><td>'+r.bounces+'</td><td>'+r.blocked+'</td></tr>';
    });
    html+='</tbody></table>';
    html+='<div class="sd-note">Compare <b>yesterday</b> vs <b>today</b> here. If today looks huge, check whether yesterday also has volume — KPIs are <b>only from Brevo</b>, then summed across your API accounts.</div></div>';
    return html;
  }
  function sdAccountBreakdown(accounts, periodLabel){
    accounts=accounts||[];
    if(!accounts.length) return '';
    var html='<div class="sd-domains"><h5>Per account · '+sdEsc(periodLabel||'period')+' · Brevo API</h5>';
    html+='<table class="sd-acct-table"><thead><tr><th>Account</th><th>Sent</th><th>Delivered</th><th>Bounced</th><th>Blocked</th></tr></thead><tbody>';
    var ts=0, td=0, tb=0, tblk=0;
    accounts.forEach(function(a){
      var m=a.metrics||{};
      var s=parseInt(m.requests,10)||0;
      var d=parseInt(m.delivered,10)||0;
      var b=parseInt(m.bounces!=null?m.bounces:((m.hardBounces||0)+(m.softBounces||0)),10)||0;
      var blk=parseInt(m.blocked,10)||0;
      ts+=s; td+=d; tb+=b; tblk+=blk;
      var from=a.from||a.email||('Acct '+a.index);
      var err=a.ok?'':(' · '+(a.err||'fail'));
      html+='<tr><td><b>Acct '+a.index+'</b> · '+sdEsc(from)+sdEsc(err)+'</td><td>'+s+'</td><td>'+d+'</td><td>'+b+'</td><td>'+blk+'</td></tr>';
    });
    html+='<tr class="total"><td>Total (sum of accounts)</td><td>'+ts+'</td><td>'+td+'</td><td>'+tb+'</td><td>'+tblk+'</td></tr>';
    html+='</tbody></table>';
    html+='<div class="sd-note">Top KPIs = this total. Each row is one Brevo API key. Your “I sent 1200” may be one run — Brevo still counts all 4 keys + any other traffic on those keys.</div></div>';
    return html;
  }
  function sdDomainsHtml(accOrList, isAll){
    var html='<div class="sd-domains"><h5>Sender domains</h5>';
    if(isAll){
      var accounts=accOrList||[];
      if(!accounts.length) return html+'<div class="sd-dom"><span>No accounts</span></div></div>';
      accounts.forEach(function(a){
        var auth=a.domain_authenticated_count||0;
        var tot=a.domain_total||0;
        var pill=(!a.domains_ok)?'<span class="sd-pill mute">n/a</span>':(auth>0?'<span class="sd-pill ok">'+auth+' authenticated</span>':'<span class="sd-pill bad">not authenticated</span>');
        var from=a.from||a.email||('Account '+a.index);
        html+='<div class="sd-dom"><span><b>Acct '+a.index+'</b> · '+sdEsc(from)+(tot?(' · '+tot+' domain(s)'):'')+'</span>'+pill+'</div>';
      });
      html+='</div>';
      return html;
    }
    var a=accOrList;
    if(!a) return '';
    if(!a.domains_ok){
      html+='<div class="sd-dom"><span>'+sdEsc(a.domains_err||'Could not load domains')+'</span><span class="sd-pill mute">n/a</span></div></div>';
      return html;
    }
    var list=a.domains||[];
    if(!list.length){
      html+='<div class="sd-dom"><span>No domains on this account</span><span class="sd-pill bad">none</span></div></div>';
      return html;
    }
    list.forEach(function(d){
      var pills='';
      pills+=d.authenticated?'<span class="sd-pill ok">authenticated</span>':'<span class="sd-pill bad">not authenticated</span>';
      pills+=' ';
      pills+=d.verified?'<span class="sd-pill ok">verified</span>':'<span class="sd-pill mute">not verified</span>';
      html+='<div class="sd-dom"><b>'+sdEsc(d.domain)+'</b><span>'+pills+'</span></div>';
    });
    html+='</div>';
    return html;
  }
  function sdShowTab(key){
    window.__brevoStatsTab=key;
    var data=window.__brevoStats;
    if(!data) return;
    var tabs=document.getElementById('brevo-stats-tabs');
    if(tabs){
      var btns=tabs.querySelectorAll('.sd-tab');
      for(var i=0;i<btns.length;i++) btns[i].classList.toggle('on', btns[i].getAttribute('data-tab')===key);
    }
    var title='All accounts';
    var range=data.period || (data.totals && data.totals.range ? data.totals.range : ('Last '+data.days+' day(s)'));
    var metrics=data.totals||{};
    var rates=data.total_rates||{};
    var domHtml='';
    var breakdown='';
    if(key!=='all'){
      var idx=parseInt(key,10);
      var acc=(data.accounts||[]).filter(function(a){ return a.index===idx; })[0];
      if(acc){
        title='Account '+acc.index+(acc.from?(' · '+acc.from):(acc.email?(' · '+acc.email):''));
        if(acc.credits!=null) title+=' · '+Math.floor(acc.credits)+' left';
        metrics=acc.metrics||{};
        rates=acc.rates||{};
        range=acc.ok ? (metrics.range||data.period||range) : (acc.err||'Failed');
        domHtml=sdDomainsHtml(acc, false);
      }
    } else {
      breakdown=sdDailyBreakdown(data.daily||[])+sdAccountBreakdown(data.accounts||[], data.period||range);
      domHtml=sdDomainsHtml(data.accounts||[], true);
    }
    var hd=document.querySelector('#brevo-stats-panel .sd-card-hd b');
    if(hd) hd.textContent=title;
    var rg=document.getElementById('brevo-stats-range');
    if(rg) rg.textContent=(data.source==='brevo_api'?'Brevo API · ':'')+(range||'');
    var sent=document.getElementById('sk-panel-sent');
    if(sent) sent.textContent=metrics.requests!=null?metrics.requests:'—';
    var box=document.getElementById('brevo-stats-metrics');
    var srcNote='<div class="sd-note"><b>Source: Brevo API</b> (account + stats + domains). Not from mailssenddone / local queue. Local block at the bottom is separate and all-time.</div>';
    if(box) box.innerHTML=srcNote+breakdown+domHtml+sdMetricRows(rates, metrics);
    sdRenderLocal(data.local_campaign);
  }
  function sdBuildTabs(data){
    var tabs=document.getElementById('brevo-stats-tabs');
    if(!tabs) return;
    var html='<button type="button" class="sd-tab on" data-tab="all">All accounts</button>';
    (data.accounts||[]).forEach(function(a){
      var mark=a.ok?'':' ✕';
      if(a.domains_ok && a.domain_total>0 && !a.domain_authenticated_count) mark=' ⚠';
      else if(a.domains_ok && a.domain_authenticated_count>0) mark=' ✓';
      var label='Acct '+a.index+mark;
      html+='<button type="button" class="sd-tab" data-tab="'+a.index+'">'+sdEsc(label)+'</button>';
    });
    tabs.innerHTML=html;
    tabs.onclick=function(ev){
      var t=ev.target;
      if(!t || !t.getAttribute || !t.getAttribute('data-tab')) return;
      sdShowTab(t.getAttribute('data-tab'));
    };
  }
  function brevoTodayYmd(){
    var d=new Date();
    var m=String(d.getMonth()+1); if(m.length<2) m='0'+m;
    var day=String(d.getDate()); if(day.length<2) day='0'+day;
    return d.getFullYear()+'-'+m+'-'+day;
  }
  function brevoLoadStats(){
    var ta=document.getElementById('brevo_api_keys');
    var text=ta?(ta.value||''):'';
    var daysEl=document.getElementById('brevo-stats-days');
    var days=daysEl?parseInt(daysEl.value,10):7;
    var dateEl=document.getElementById('brevo-stats-date');
    var day=(dateEl && dateEl.value) ? dateEl.value : '';
    // "Today" → real calendar date (not Brevo rolling days=1)
    if(!day && days===1) day=brevoTodayYmd();
    var btn=document.getElementById('btn-brevo-stats');
    var msg=document.getElementById('brevo-stats-msg');
    if(!text.trim()){ if(msg){ msg.className='sd-msg sd-err'; msg.textContent='Add at least one API account first.'; } return; }
    if(btn){ btn.disabled=true; btn.textContent='Loading…'; }
    if(msg){
      msg.className='sd-msg';
      msg.textContent=day ? ('Fetching Brevo API stats for calendar day '+day+'…') : ('Fetching Brevo API stats · last '+days+' day(s)…');
    }
    var statsUrl=(function(){ var p=location.pathname||''; var i=p.lastIndexOf('/'); return (i>=0?p.slice(0,i+1):'/')+'brevo_stats.php'; })();
    var payload={text:text};
    if(day){ payload.startDate=day; payload.endDate=day; }
    else { payload.days=days; }
    fetch(statsUrl,{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload),
      cache:'no-store'
    }).then(function(r){
      return r.text().then(function(t){
        var d=null;
        try{ d=t?JSON.parse(t):null; }catch(e){ d=null; }
        return {http:r.status, okHttp:r.ok, raw:t, data:d};
      });
    }).then(function(pack){
      if(btn){ btn.disabled=false; btn.textContent='Load stats'; }
      var d=pack.data;
      if(!d||!d.ok){
        var err=(d&&d.err)||('HTTP '+pack.http+(pack.raw?(' — '+String(pack.raw).replace(/<[^>]+>/g,' ').slice(0,140)):' — empty response'));
        if(msg){ msg.className='sd-msg sd-err'; msg.textContent=err; }
        return;
      }
      window.__brevoStats=d;
      try{
        var t=d.totals||{};
        var set=function(id,v){ var e=document.getElementById(id); if(e) e.textContent=v; };
        set('sk-requests', t.requests!=null?t.requests:'—');
        set('sk-delivered', t.delivered!=null?t.delivered:'—');
        set('sk-bounced', (t.bounces!=null?t.bounces:(t.hardBounces||0)+(t.softBounces||0)));
        set('sk-credits', d.total_credits!=null?Math.floor(d.total_credits):'—');
        sdBuildTabs(d);
        sdShowTab('all');
        var okN=(d.accounts||[]).filter(function(a){return a.ok;}).length;
        var failN=(d.accounts||[]).length-okN;
        if(msg){
          msg.className=failN?'sd-msg sd-err':'sd-msg';
          msg.textContent='Brevo API · '+okN+'/'+d.account_count+' accounts OK'+(failN?(' · '+failN+' failed'):'')+' · '+(d.period||('last '+d.days+' day(s)'));
        }
      }catch(err){
        if(msg){ msg.className='sd-msg sd-err'; msg.textContent='Stats loaded but display error — '+(err&&err.message?err.message:err); }
      }
    }).catch(function(e){
      if(btn){ btn.disabled=false; btn.textContent='Load stats'; }
      if(msg){ msg.className='sd-msg sd-err'; msg.textContent='Request failed'+(e&&e.message?(' — '+e.message):'')+' · hard-refresh page (Ctrl+F5) and try again'; }
    });
  }
  var btnStats=document.getElementById('btn-brevo-stats');
  if(btnStats) btnStats.onclick=brevoLoadStats;
  var daysSel=document.getElementById('brevo-stats-days');
  if(daysSel) daysSel.onchange=function(){
    var dateEl=document.getElementById('brevo-stats-date');
    if(dateEl) dateEl.value='';
    if(window.__brevoStats) brevoLoadStats();
  };
  var dateSel=document.getElementById('brevo-stats-date');
  if(dateSel) dateSel.onchange=function(){ if(this.value) brevoLoadStats(); };
  var btnClearDate=document.getElementById('btn-brevo-stats-clear-date');
  if(btnClearDate) btnClearDate.onclick=function(){
    var dateEl=document.getElementById('brevo-stats-date');
    if(dateEl) dateEl.value='';
    brevoLoadStats();
  };

  function mqMsg(t, ok, lane){
    var m=document.getElementById(lane==='smtp'?'mail-queue-msg-smtp':'mail-queue-msg');
    if(!m) return;
    m.textContent=t||'';
    m.style.color=ok===false?'var(--s-fail)':(ok===true?'var(--s-ok)':'');
  }
  function refreshMailQueueStats(lane){
    lane = lane==='smtp' ? 'smtp' : 'api';
    var el=document.getElementById(lane==='smtp'?'mail-queue-stats-smtp':'mail-queue-stats');
    if(!el) return;
    fetch('mail_queue_status.php?lane='+lane+'&_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){
      if(!d||!d.ok){ el.removeAttribute('data-pending'); el.removeAttribute('data-valid'); el.textContent='Queue: could not read files'; return; }
      el.setAttribute('data-pending', String(d.pending));
      el.setAttribute('data-valid', String(d.valid));
      var bits=[];
      if(d.valid_raw && d.valid_raw>d.valid) bits.push(d.valid_duplicates+' duplicates ignored');
      if(d.valid_raw) bits.push(d.valid_raw+' lines in file');
      if(d.resume_line) bits.push('resume @ line '+d.resume_line);
      if(d.next_email) bits.push('next '+d.next_email);
      function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
      el.innerHTML='<div class="rc-metrics"><div><b>'+esc(d.pending)+'</b><span>Pending</span></div><div><b>'+esc(d.done)+'</b><span>Sent OK</span></div><div><b>'+esc(d.valid)+'</b><span>Unique</span></div></div>'+(bits.length?'<div class="rc-next">'+esc(bits.join(' · '))+'</div>':'');
    }).catch(function(){ el.removeAttribute('data-pending'); el.removeAttribute('data-valid'); el.textContent='Queue: could not read files'; });
  }
  function mqPost(action, extra, file, lane){
    var fd=new FormData();
    fd.append('action', action);
    fd.append('lane', lane==='smtp'?'smtp':'api');
    if(extra){ for(var k in extra) if(extra.hasOwnProperty(k)) fd.append(k, extra[k]); }
    if(file) fd.append('file', file);
    return fetch('mail_queue_save.php',{method:'POST',body:fd,cache:'no-store'}).then(function(r){return r.ok?r.json():null;});
  }
  function loadValidmailsEditor(lane){
    lane = lane==='smtp' ? 'smtp' : 'api';
    var ta=document.getElementById(lane==='smtp'?'validmails-smtp-editor':'validmails-editor');
    if(!ta) return;
    mqMsg('Loading…', null, lane);
    mqPost('load_valid', null, null, lane).then(function(d){
      if(!d||!d.ok){ mqMsg('Could not load list', false, lane); return; }
      ta.value=d.text||'';
      mqMsg(d.msg || ('Loaded '+d.count+' emails'), true, lane);
      refreshMailQueueStats(lane);
    }).catch(function(){ mqMsg('Could not load list', false, lane); });
  }
  function saveValidmailsEditor(lane){
    lane = lane==='smtp' ? 'smtp' : 'api';
    var ta=document.getElementById(lane==='smtp'?'validmails-smtp-editor':'validmails-editor');
    if(!ta) return;
    mqMsg('Saving…', null, lane);
    mqPost('save_valid',{text:ta.value||''}, null, lane).then(function(d){
      if(!d||!d.ok){ mqMsg((d&&d.err)||'Save failed', false, lane); return; }
      if(typeof d.text==='string') ta.value=d.text;
      mqMsg(d.msg+' · pending '+d.pending+(d.resume_line?' · resume @ line '+d.resume_line:''), true, lane);
      refreshMailQueueStats(lane);
    }).catch(function(){ mqMsg('Save failed', false, lane); });
  }
  function uploadValidmailsFile(file, lane){
    if(!file) return;
    lane = lane==='smtp' ? 'smtp' : 'api';
    var ta=document.getElementById(lane==='smtp'?'validmails-smtp-editor':'validmails-editor');
    mqMsg('Uploading '+file.name+'…', null, lane);
    mqPost('upload_valid',{},file, lane).then(function(d){
      if(!d||!d.ok){ mqMsg((d&&d.err)||'Upload failed', false, lane); return; }
      if(ta && typeof d.text==='string') ta.value=d.text;
      mqMsg(d.msg+' · pending '+d.pending+(d.resume_line?' · resume @ line '+d.resume_line:''), true, lane);
      refreshMailQueueStats(lane);
    }).catch(function(){ mqMsg('Upload failed', false, lane); });
  }
  function clearDoneList(lane){
    lane = lane==='smtp' ? 'smtp' : 'api';
    var which = lane==='smtp' ? 'Normal SMTP progress only' : 'Brevo campaign progress only';
    if(!confirm('Clear sent progress ('+which+')? Next Send starts again from the top of that list.')) return;
    mqPost('clear_done', null, null, lane).then(function(d){
      if(!d||!d.ok){ mqMsg('Could not clear sent list', false, lane); return; }
      mqMsg('Sent progress cleared — resume from top', true, lane);
      refreshMailQueueStats(lane);
    }).catch(function(){ mqMsg('Could not clear sent list', false, lane); });
  }
  function syncListModePanels(){
    var camp=document.getElementById('list_mode_campaign');
    var isCamp=camp ? camp.checked : true;
    var pt=document.getElementById('panel-test');
    if(pt) pt.style.display=isCamp?'none':'block';
    var page=document.getElementById('page-recipients');
    if(page) page.classList.toggle('test-on', !isCamp);
  }
  var btnQ=document.getElementById('btn-refresh-queue');
  if(btnQ) btnQ.onclick=function(){ refreshMailQueueStats('api'); };
  var btnLoadV=document.getElementById('btn-validmails-load');
  if(btnLoadV) btnLoadV.onclick=function(){ loadValidmailsEditor('api'); };
  var btnSaveV=document.getElementById('btn-validmails-save');
  if(btnSaveV) btnSaveV.onclick=function(){ saveValidmailsEditor('api'); };
  var btnClearD=document.getElementById('btn-done-clear');
  if(btnClearD) btnClearD.onclick=function(){ clearDoneList('api'); };
  var fileInp=document.getElementById('validmails-file');
  var btnUp=document.getElementById('btn-validmails-upload');
  if(btnUp && fileInp) btnUp.onclick=function(){ fileInp.click(); };
  if(fileInp) fileInp.onchange=function(){
    if(this.files && this.files[0]) uploadValidmailsFile(this.files[0], 'api');
    this.value='';
  };
  var btnSq=document.getElementById('btn-smtp-refresh');
  if(btnSq) btnSq.onclick=function(){ refreshMailQueueStats('smtp'); };
  var btnSload=document.getElementById('btn-smtp-load');
  if(btnSload) btnSload.onclick=function(){ loadValidmailsEditor('smtp'); };
  var btnSsave=document.getElementById('btn-smtp-save');
  if(btnSsave) btnSsave.onclick=function(){ saveValidmailsEditor('smtp'); };
  var btnSclear=document.getElementById('btn-smtp-clear');
  if(btnSclear) btnSclear.onclick=function(){ clearDoneList('smtp'); };
  var fileSmtp=document.getElementById('validmails-smtp-file');
  var btnSup=document.getElementById('btn-smtp-upload');
  if(btnSup && fileSmtp) btnSup.onclick=function(){ fileSmtp.click(); };
  if(fileSmtp) fileSmtp.onchange=function(){
    if(this.files && this.files[0]) uploadValidmailsFile(this.files[0], 'smtp');
    this.value='';
  };
  var lmC=document.getElementById('list_mode_campaign');
  var lmT=document.getElementById('list_mode_test');
  if(lmC) lmC.onchange=syncListModePanels;
  if(lmT) lmT.onchange=syncListModePanels;
  window.syncListModePanels=syncListModePanels;
  syncListModePanels();
  setTimeout(function(){
    brevoRenderCreditCards(brevoGetAccountsMerged());
    refreshMailQueueStats('api');
    refreshMailQueueStats('smtp');
    loadValidmailsEditor('api');
    loadValidmailsEditor('smtp');
  }, 50);
});
</script>
<form name="form1" id="form1" method="post" action="" enctype="multipart/form-data" class="setup-grid">

  <div class="nav-page on" id="page-setup">
  <section class="setup-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
      <h3 style="margin:0">How you send</h3>
      <div class="setup-field" style="margin:0;min-width:120px">
        <label>Debug</label>
        <select name="dbg">
          <option value="0" <?php echo ($debg == "0") ? 'selected=selected':''; ?>>OFF</option>
          <option value="1" <?php echo ($debg == "1") ? 'selected=selected':''; ?>>1</option>
          <option value="2" <?php echo ($debg == "2") ? 'selected=selected':''; ?>>2</option>
          <option value="3" <?php echo ($debg == "3") ? 'selected=selected':''; ?>>3</option>
          <option value="4" <?php echo ($debg == "4") ? 'selected=selected':''; ?>>4</option>
        </select>
      </div>
    </div>
    <div class="setup-seg" style="flex-wrap:wrap;margin-bottom:4px">
      <label><input type="radio" name="smtp_mode" value="normal" <?php if($smtp_mode!=='brevo' && $smtp_mode!=='brevo_api') echo 'checked'; ?> id="mode_normal"> Normal + SOCKS</label>
      <label><input type="radio" name="smtp_mode" value="brevo" <?php if($smtp_mode==='brevo') echo 'checked'; ?> id="mode_brevo"> Brevo SMTP</label>
      <label><input type="radio" name="smtp_mode" value="brevo_api" <?php if($smtp_mode==='brevo_api') echo 'checked'; ?> id="mode_brevo_api"> Brevo API</label>
    </div>
  </section>
  <div id="connections-proxy-slot"></div>
  <section class="setup-card">
    <h3>Accounts</h3>

    <div class="setup-field mode-only-brevo-api" id="brevo-api-box" style="margin-bottom:14px;<?php if($smtp_mode!=='brevo_api') echo 'display:none'; ?>">
      <label>Brevo API accounts</label>
      <div class="setup-row" style="margin-bottom:8px">
        <div class="setup-field" style="margin:0">
          <label>API key</label>
          <input type="password" id="brevo_add_key" value="" placeholder="xkeysib-…" autocomplete="off">
        </div>
        <div class="setup-field" style="margin:0">
          <label>From email</label>
          <input type="text" id="brevo_add_from" value="" placeholder="you@yourdomain.com">
        </div>
      </div>
      <div class="setup-inline" style="gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:10px">
        <div class="setup-field" style="margin:0">
          <label>Limit / day</label>
          <input type="number" id="brevo_add_limit" value="300" min="1" style="width:88px">
        </div>
        <button type="button" id="btn-brevo-add" class="btn btn-primary">Add account</button>
        <button type="button" id="btn-brevo-credits" class="btn btn-ghost">Check all left</button>
        <span id="brevo-credits-total" style="font-weight:700;font-size:15px;color:var(--s-ok)">Total left today: —</span>
      </div>
      <div id="brevo-key-edit-note" class="hint" style="margin:0 0 8px"></div>
      <textarea name="brevo_api_keys" id="brevo_api_keys" rows="2" style="display:none"><?php echo htmlspecialchars($brevo_api_keys); ?></textarea>
      <div id="brevo-credits-cards" style="display:grid;gap:8px"></div>
      <div class="hint">Check reads Brevo: credits, under verification, sender, and domain. Mark problem to skip an account on the next send.</div>
    </div>

    <div class="mode-only-normal" style="<?php if($smtp_mode==='brevo'||$smtp_mode==='brevo_api') echo 'display:none'; ?>">
    <div class="setup-row">
      <div>
        <div class="setup-field">
          <label>SMTP IP / host</label>
          <input type="text" id="ip" name="ip" value="" placeholder="smtp.example.com">
        </div>
        <div class="setup-field">
          <label>SMTP login (single)</label>
          <input type="text" id="user" name="user" value="" placeholder="user">
          <input type="password" id="pass" name="pass" value="" placeholder="pass" style="margin-top:8px">
        </div>
        <div class="setup-row" style="grid-template-columns:1fr auto;align-items:end">
          <div class="setup-field" style="margin:0">
            <label>Port</label>
            <input type="text" name="ssl_port" id="ssl_port" value="<?php echo $ssl_port;?>">
          </div>
          <button type="button" name="add" id="add" class="btn btn-ghost" style="margin-bottom:12px">Add SMTP</button>
        </div>
      </div>
      <div>
        <div class="setup-field">
          <label>Active SMTP list</label>
          <textarea id="my_smtp" name="my_smtp" rows="5" placeholder="host:port:user:pass:TLS (one per line) — or use Bulk + Add SMTP"><?php echo htmlspecialchars($my_smtp);?></textarea>
          <div class="setup-inline" style="margin-top:8px">
            <span>Limit</span>
            <input name="nrotat" type="text" value="<?php echo htmlspecialchars($nrotat);?>" style="width:64px">
            <span>/ SMTP / day</span>
            <button type="button" name="reset" id="reset" class="btn btn-ghost">Reset list</button>
          </div>
          <div class="hint">Round-robin across Active SMTP list.</div>
        </div>
      </div>
    </div>

    <div class="setup-field">
      <label>Bulk SMTP</label>
      <textarea id="bulk_smtp" name="bulk_smtp" rows="3" placeholder='["email:pass","email2:pass2"] or host:port:user:pass'></textarea>
      <div class="hint">For JSON email:pass, set SMTP IP + Port + SSL/TLS first, then Add SMTP.</div>
    </div>

    <div class="setup-row">
      <div class="setup-field">
        <label>Encryption</label>
        <div class="setup-inline">
          <label><input type="radio" name="SSLTLS" value="SSL"> SSL / 465</label>
          <label><input type="radio" name="SSLTLS" value="TLS" checked> TLS / 587</label>
          <label><input type="radio" name="SSLTLS" value="NON"> NON</label>
        </div>
      </div>
      <div class="setup-field">
        <label>Send options</label>
        <div class="setup-inline">
          <label><input type="checkbox" name="use_socks" id="use_socks" value="1" <?php if(!isset($_POST['action']) || !empty($use_socks)) echo "checked"; ?>> Use SOCKS5</label>
          <input type="hidden" name="use_socks_persist" id="use_socks_persist" value="1">
          <span>Workers</span>
          <input type="number" name="send_workers" value="<?php echo intval($send_workers); ?>" min="1" max="15" style="width:64px" title="Parallel send processes (1=normal)">
          <span class="hint" style="margin:0">(1–15)</span>
        </div>
        <div class="hint">Send uses the proxies that passed Run Test.</div>
        <div class="setup-inline" style="margin-top:8px">
          <label><input type="checkbox" name="sticky_proxy" id="sticky_proxy" value="1" checked> Sticky proxy (reuse the one that worked)</label>
        </div>
      </div>
    </div>
    </div>

    <div class="setup-field mode-only-brevo" style="<?php if($smtp_mode!=='brevo') echo 'display:none'; ?>">
      <label>Brevo SMTP accounts</label>
      <textarea id="brevo_smtp" name="brevo_smtp" rows="5" placeholder="#ACCOUNT 500&#10;smtp-relay.brevo.com:587:login1:pass:TLS"><?php echo htmlspecialchars($brevo_smtp); ?></textarea>
      <div class="hint">One #ACCOUNT 500 block per Brevo login. Up to 3 SMTPs share that 500/day. No SOCKS.</div>
    </div>
  </section>
  </div>

  <div class="nav-page" id="page-recipients">
  <section class="setup-card">
    <h3>Send to</h3>
    <div class="rc-switch">
      <label><input type="radio" name="send_list_mode" id="list_mode_campaign" value="campaign" checked><strong>Campaign lists</strong><em>Brevo uses the blue list. Normal SMTP uses the sand list.</em></label>
      <label><input type="radio" name="send_list_mode" id="list_mode_test" value="test"><strong>Test my emails</strong><em>Only the addresses you type. Both saved lists stay untouched.</em></label>
    </div>
    <div id="panel-test" class="rc-test" style="display:none">
      <textarea name="emaillist" id="emaillist" rows="5" placeholder="Paste only the addresses you want to test, one per line"><?php echo htmlspecialchars($emaillist);?></textarea>
    </div>
  </section>
  <div class="rc-grid" id="recipients-panel">
    <section class="rc-list api">
      <div class="rc-hd"><div><b>Brevo</b><span>API and Brevo SMTP. Saved sends stay in mailssenddone.txt.</span></div><div class="rc-pill">Method 3</div></div>
      <div id="mail-queue-stats" class="rc-stat">Queue: loading…</div>
      <div class="rc-body" id="panel-campaign">
        <textarea id="validmails-editor" rows="10" placeholder="One email per line"></textarea>
        <input type="file" id="validmails-file" accept=".txt,.csv,.list,.log,.email,.emails,text/plain" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden">
        <div class="rc-tools">
          <button type="button" id="btn-validmails-upload" class="btn btn-ghost">Upload</button>
          <button type="button" id="btn-validmails-load" class="btn btn-ghost">Load</button>
          <button type="button" id="btn-validmails-save" class="btn btn-primary">Save</button>
          <button type="button" id="btn-refresh-queue" class="btn btn-ghost">Refresh</button>
          <button type="button" id="btn-done-clear" class="btn btn-ghost" title="Reset Brevo progress">Clear sent</button>
        </div>
        <div id="mail-queue-msg" class="hint" style="margin:8px 0 0"></div>
      </div>
    </section>
    <section class="rc-list smtp">
      <div class="rc-hd"><div><b>Normal SMTP</b><span>SOCKS or direct SMTP. Saved sends stay in mailssenddone_smtp.txt.</span></div><div class="rc-pill">Method 1</div></div>
      <div id="mail-queue-stats-smtp" class="rc-stat">Normal queue: loading…</div>
      <div class="rc-body">
        <textarea id="validmails-smtp-editor" rows="10" placeholder="One email per line"></textarea>
        <input type="file" id="validmails-smtp-file" accept=".txt,.csv,.list,.log,.email,.emails,text/plain" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden">
        <div class="rc-tools">
          <button type="button" id="btn-smtp-upload" class="btn btn-ghost">Upload</button>
          <button type="button" id="btn-smtp-load" class="btn btn-ghost">Load</button>
          <button type="button" id="btn-smtp-save" class="btn btn-primary">Save</button>
          <button type="button" id="btn-smtp-refresh" class="btn btn-ghost">Refresh</button>
          <button type="button" id="btn-smtp-clear" class="btn btn-ghost" title="Reset Normal progress">Clear sent</button>
        </div>
        <div id="mail-queue-msg-smtp" class="hint" style="margin:8px 0 0"></div>
      </div>
    </section>
  </div>
  </div>

  <div class="nav-page" id="page-compose">
  <section class="setup-card">
    <h3>Message</h3>
    <div class="setup-row">
      <div class="setup-field">
        <label>From email</label>
        <input type="text" name="from" value="<?php if(!$lase) echo $from_base;?>" <?php if($lase) echo "disabled"?> >
        <div class="setup-inline mode-smtp-any" style="margin-top:6px">
          <label><input type="checkbox" name="lase" value="true" id="lase_cb" <?php if($lase || (!isset($_POST['action']) && $smtp_mode!=='brevo_api')) echo 'checked'; ?>> Email as login</label>
          <label><input type="checkbox" name="rndmail" value="true" <?php if(!empty($_POST['rndmail'])) echo "checked"; ?>> Random mail</label>
        </div>
        <div class="hint mode-only-brevo-api" style="margin-top:6px">From must be a verified sender in Brevo (or set <code>from:</code> per API account).</div>
      </div>
      <div class="setup-field">
        <label>From name</label>
        <input type="text" name="realname" value="<?php echo $realname_base;?>">
        <div class="setup-inline" style="margin-top:6px">
          <label><input type="checkbox" name="rndname" value="true" <?php if(!empty($_POST['rndname'])) echo "checked"; ?>> Random sender name</label>
        </div>
      </div>
    </div>

    <div class="setup-row">
      <div class="setup-field">
        <label>Reply-To</label>
        <input type="text" name="replyto" value="<?php if(!$repaslog) echo $replyto;?>" <?php if($repaslog) echo "disabled";?>>
        <div class="setup-inline mode-smtp-any" style="margin-top:6px">
          <label><input type="checkbox" name="repaslog" value="true" <?php if($repaslog) echo "checked"; ?>> Reply as login</label>
          <label><input type="checkbox" name="readingconf" value="true" <?php if($reading) echo "checked"; ?>> Confirm reading</label>
        </div>
      </div>
      <div class="setup-field">
        <label>Priority</label>
        <select name="epriority" id="listMethod" onchange="showHideListConfig()">
          <option value="" <?php if(strlen($epriority)< 1){print "selected";} ?>>No priority</option>
          <option value="1" <?php if($epriority == "1"){print "selected";} ?>>High</option>
          <option value="3" <?php if($epriority == "3"){print "selected";} ?>>Normal</option>
          <option value="5" <?php if($epriority == "5"){print "selected";} ?>>Low</option>
        </select>
      </div>
    </div>

    <div class="setup-field" id="content-variants-box">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <label style="margin:0">HTML variants</label>
        <div class="setup-inline" style="gap:8px">
          <button type="button" id="btn-variant-add" class="btn btn-ghost">+ Add HTML</button>
          <button type="button" id="btn-variant-del" class="btn btn-ghost">Remove</button>
        </div>
      </div>
      <div class="hint" style="margin:6px 0 0;line-height:1.45">
        Add several subject + HTML versions (e.g. 4). On send they are split across your accounts:
        2 HTML → 4 accounts share them; 5 HTML → distributed on those 4 (extra ones rotate).
      </div>
      <div class="variant-tabs" id="variant-tabs"></div>
      <div class="variant-meta" id="variant-meta"></div>
      <div class="setup-field" style="margin-top:12px">
        <label>Subject</label>
        <input type="text" name="subject" id="variant_subject" value="<?php echo htmlspecialchars($subject_base, ENT_QUOTES, 'UTF-8');?>">
      </div>
      <div class="setup-field">
        <label>HTML / message body</label>
        <textarea name="message" id="variant_message" class="tall" rows="12"><?php echo htmlspecialchars($message_base, ENT_QUOTES, 'UTF-8');?></textarea>
        <div class="setup-inline" style="margin-top:8px">
          <label><input type="radio" name="contenttype" value="plain"> TEXT</label>
          <label><input type="radio" name="contenttype" value="html" checked> HTML</label>
          <span class="hint" style="margin:0">#name# in the HTML is the recipient’s name from their email (madalena_dordio@… → Madalena Dordio). From name stays the name you type.</span>
        </div>
      </div>
      <input type="hidden" name="content_variants_json" id="content_variants_json" value="<?php echo htmlspecialchars($content_variants_json !== '' ? $content_variants_json : '', ENT_QUOTES, 'UTF-8');?>">
    </div>

    <div class="setup-row">
      <div class="setup-field">
        <label>Encoding</label>
        <select size="1" name="encodety">
          <option value="no" <?php if($encodety == "no"){print "selected";} ?>>NO</option>
          <option value="8bit" <?php if($encodety == "8bit"){print "selected";} ?>>8bit</option>
          <option value="7bit" <?php if($encodety == "7bit"){print "selected";} ?>>7bit</option>
          <option value="binary" <?php if($encodety == "binary"){print "selected";} ?>>binary</option>
          <option value="base64" <?php if($encodety == "base64"){print "selected";} ?>>base64</option>
          <option value="quoted-printable" <?php if($encodety == "quoted-printable"){print "selected";} ?>>quoted-printable</option>
        </select>
      </div>
      <div class="setup-field">
        <label>Charset</label>
        <select name="charset_pick" id="charsetPick">
          <option value="" selected="">No charset</option>
          <option value="us-ascii">Unicode → us-ascii</option>
          <option value="utf-7">Unicode → utf-7</option>
          <option value="utf-8">Unicode → utf-8</option>
          <option value="iso-10646-ucs-2">Unicode → iso-10646-ucs-2</option>
          <option value="iso-8859-6">Arabic → iso-8859-6</option>
          <option value="x-mac-arabic">Arabic → x-mac-arabic</option>
          <option value="windows-1256">Arabic → windows-1256</option>
          <option value="iso-8859-4">Baltic → iso-8859-4</option>
          <option value="windows-1257">Baltic → windows-1257</option>
          <option value="iso-8859-2">Central European → iso-8859-2</option>
          <option value="x-mac-ce">Central European → x-mac-ce</option>
          <option value="windows-1250">Central European → windows-1250</option>
          <option value="euc-cn">Chinese → euc-cn</option>
          <option value="gb2312">Chinese → gb2312</option>
          <option value="hz-gb-2312">Chinese → hz-gb-2312</option>
          <option value="x-mac-chinesesimp">Chinese → x-mac-chinesesimp</option>
          <option value="cp-936">Chinese → cp-936</option>
          <option value="big5">Chinese → big5</option>
          <option value="x-mac-chinesetrad">Chinese → x-mac-chinesetrad</option>
          <option value="cp-950">Chinese → cp-950</option>
          <option value="cp-932">Chinese → cp-932</option>
          <option value="euc-tw">Chinese → euc-tw</option>
        </select>
      </div>
    </div>

    <div class="setup-row">
      <div class="setup-field">
        <label>Pattern generator</label>
        <div class="setup-inline">
          <label><input type="checkbox" name="az" id="az"> a-z</label>
          <label><input type="checkbox" name="AZ" id="AZ"> A-Z</label>
          <label><input type="checkbox" name="09" id="09"> 0-9</label>
          <input type="text" maxlength="2" name="len" id="len" placeholder="len" style="width:48px">
          <input type="text" name="patval" id="patval" placeholder="#pattern" style="flex:1;min-width:120px">
          <button type="button" id="patb" name="patb" class="btn btn-ghost">Get pattern</button>
        </div>
      </div>
      <div class="setup-field">
        <label>Message attachments (optional) <a href="#" id="addfiles" style="color:var(--s-ok);text-decoration:none">+</a></label>
        <input name="userfile[]" type="file">
        <div class="hint" style="margin:4px 0 0">Files attached to each email — not your recipient list.</div>
        <div id="mfiles"></div>
      </div>
    </div>

  </section>
  <section class="setup-card">
    <h3>Pace &amp; launch</h3>
    <div class="setup-row">
      <div class="setup-field">
        <label>Send pause</label>
        <div class="setup-inline">
          <input type="text" name="pause" value="<?php echo $pause;?>" style="width:64px">
          <span>sec every</span>
          <input type="text" name="pemails" value="<?php echo $pemails;?>" style="width:64px">
          <span>emails</span>
        </div>
      </div>
      <div class="setup-field mode-smtp-any">
        <label>Reconnect after</label>
        <div class="setup-inline">
          <input type="text" name="reconnect" value="<?php echo $reconnect;?>" style="width:80px">
          <span>emails</span>
        </div>
      </div>
    </div>
    <div class="setup-row mode-smtp-any">
      <div class="setup-field">
        <label>BCC</label>
        <div class="setup-inline">
          <label><input type="checkbox" name="isbcc" value="true" <?php if($isbcc=="true") echo "checked"; ?>> Enable BCC</label>
          <input type="text" name="nbcc" value="<?php echo $nbcc;?>" style="width:64px">
          <span>per group</span>
        </div>
      </div>
      <div class="setup-field mode-only-normal">
        <label>&nbsp;</label>
        <div class="hint">Accounts &amp; SOCKS are on <b>Setup</b>. List is on <b>Recipients</b>.</div>
      </div>
    </div>
    <input type="hidden" name="action" value="send">
    <div class="setup-actions">
      <input type="submit" class="btn btn-primary" value="Send Message">
      <button type="button" id="btn-load-pending" class="btn btn-ghost">Load pending emails</button>
    </div>
  </section>
  </div>

  <div class="nav-page" id="page-analytics">
  <section class="setup-card mode-only-brevo-api" style="<?php if($smtp_mode!=='brevo_api') echo 'display:none'; ?>">
    <h3>Brevo performance</h3>
    <div class="stats-dash" id="brevo-stats-dash">
      <div class="sd-hd">
        <h4>Transactional<small>Per account + domain auth</small></h4>
        <div class="sd-controls">
          <select id="brevo-stats-days" title="Period">
            <option value="1">Today (calendar)</option>
            <option value="7" selected>Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="90">Last 90 days</option>
          </select>
          <input type="date" id="brevo-stats-date" title="Specific day" max="<?php echo date('Y-m-d'); ?>">
          <button type="button" id="btn-brevo-stats-clear-date" class="sd-btn" title="Use period instead of calendar">Clear day</button>
          <button type="button" id="btn-brevo-stats" class="sd-btn">Load stats</button>
        </div>
      </div>
      <div class="sd-overview" id="brevo-stats-kpis">
        <div class="sd-kpi info"><b id="sk-requests">—</b><span>Emails sent</span></div>
        <div class="sd-kpi ok"><b id="sk-delivered">—</b><span>Delivered</span></div>
        <div class="sd-kpi fail"><b id="sk-bounced">—</b><span>Bounced</span></div>
        <div class="sd-kpi"><b id="sk-credits">—</b><span>Credits left</span></div>
      </div>
      <div class="sd-tabs" id="brevo-stats-tabs"></div>
      <div class="sd-card" id="brevo-stats-panel">
        <div class="sd-card-hd"><b>All accounts</b><span id="brevo-stats-range">Pick a period → Load stats</span></div>
        <div class="sd-sent">Emails sent · <strong id="sk-panel-sent">—</strong></div>
        <div id="brevo-stats-metrics"></div>
        <div class="sd-local" id="brevo-stats-local" style="display:none"></div>
      </div>
      <div class="sd-msg" id="brevo-stats-msg"></div>
    </div>
  </section>
  <section class="setup-card mode-hide-api" style="<?php if($smtp_mode==='brevo_api') echo 'display:none'; ?>">
    <h3>Brevo performance</h3>
    <div class="hint" style="margin:0">Switch send mode to <b>Brevo API</b> on Setup to load per-account stats and domain authentication.</div>
  </section>
  </div>

</form>
  </div><!-- .app-content -->
</div><!-- .app-main -->
</div><!-- .app-shell -->
<script>
(function(){
  // HTML variants (subject + body) — synced into content_variants_json + subject/message
  var variants = [];
  var variantIdx = 0;
  var subjEl = document.getElementById('variant_subject');
  var msgEl = document.getElementById('variant_message');
  var jsonEl = document.getElementById('content_variants_json');
  var tabsEl = document.getElementById('variant-tabs');
  var metaEl = document.getElementById('variant-meta');
  function flushActive(){
    if(!subjEl || !msgEl) return;
    if(!variants.length) variants = [{subject:'', message:''}];
    if(variantIdx < 0 || variantIdx >= variants.length) variantIdx = 0;
    variants[variantIdx] = {subject: subjEl.value || '', message: msgEl.value || ''};
  }
  function renderTabs(){
    if(!tabsEl) return;
    tabsEl.innerHTML = '';
    for(var i=0;i<variants.length;i++){
      (function(ix){
        var b=document.createElement('button');
        b.type='button';
        b.textContent='HTML '+(ix+1);
        if(ix===variantIdx) b.className='on';
        b.onclick=function(){ selectVariant(ix); };
        tabsEl.appendChild(b);
      })(i);
    }
    if(metaEl){
      var n=variants.length;
      metaEl.textContent = n<=1
        ? 'One HTML — all accounts use it. Add more to split across accounts.'
        : (n+' HTML versions ready — send will split them across your accounts.');
    }
  }
  function selectVariant(ix){
    flushActive();
    variantIdx = ix;
    var v = variants[variantIdx] || {subject:'', message:''};
    if(subjEl) subjEl.value = v.subject || '';
    if(msgEl) msgEl.value = v.message || '';
    renderTabs();
    syncJson();
  }
  function syncJson(){
    flushActive();
    var clean=[];
    for(var i=0;i<variants.length;i++){
      var s=(variants[i].subject||'').replace(/^\s+|\s+$/g,'');
      var m=(variants[i].message||'').replace(/^\s+|\s+$/g,'');
      if(s||m) clean.push({subject:variants[i].subject||'', message:variants[i].message||''});
    }
    if(!clean.length && subjEl && msgEl)
      clean=[{subject:subjEl.value||'', message:msgEl.value||''}];
    if(jsonEl) jsonEl.value = JSON.stringify(clean);
    // Keep POST subject/message = first variant (fallback for older paths)
    if(clean.length && subjEl && msgEl && variantIdx===0){ /* already in fields */ }
    else if(clean.length && document.form1){
      // ensure first variant is what name=subject/message post if user is on another tab
    }
    return clean;
  }
  function ensurePrimaryFields(){
    var clean = syncJson();
    if(!clean.length || !subjEl || !msgEl) return;
    // On submit, temporarily show first variant in named fields by swapping if needed
    flushActive();
    variants = clean.length ? clean.slice() : variants;
    if(variantIdx !== 0){
      // write active back already done; put v0 into named fields for POST by switching
      var keep = variantIdx;
      variantIdx = 0;
      if(subjEl) subjEl.value = variants[0].subject || '';
      if(msgEl) msgEl.value = variants[0].message || '';
      // JSON already has all; PHP reads JSON for real split. subject/message = first.
      variantIdx = keep;
    }
    if(jsonEl) jsonEl.value = JSON.stringify(variants);
  }
  function loadVariantsFromJson(raw, fallbackS, fallbackM){
    var list=[];
    try{
      var j=JSON.parse(raw||'[]');
      if(Array.isArray(j)){
        for(var i=0;i<j.length;i++){
          if(!j[i]) continue;
          list.push({subject:j[i].subject||'', message:j[i].message||''});
        }
      }
    }catch(e){}
    if(!list.length) list=[{subject:fallbackS||'', message:fallbackM||''}];
    variants=list;
    variantIdx=0;
    if(subjEl) subjEl.value=variants[0].subject||'';
    if(msgEl) msgEl.value=variants[0].message||'';
    renderTabs();
    syncJson();
  }
  window.contentVariantsFlush = function(){ ensurePrimaryFields(); syncJson(); };
  window.contentVariantsSync = function(){ flushActive(); return syncJson(); };
  window.contentVariantsLoad = loadVariantsFromJson;
  if(tabsEl){
    var seedJson = jsonEl ? jsonEl.value : '';
    loadVariantsFromJson(seedJson, subjEl?subjEl.value:'', msgEl?msgEl.value:'');
    var addBtn=document.getElementById('btn-variant-add');
    var delBtn=document.getElementById('btn-variant-del');
    if(addBtn) addBtn.onclick=function(){
      flushActive();
      if(variants.length>=12){ alert('Max 12 HTML variants'); return; }
      variants.push({subject:'', message:''});
      selectVariant(variants.length-1);
    };
    if(delBtn) delBtn.onclick=function(){
      if(variants.length<=1){ alert('Keep at least one HTML'); return; }
      flushActive();
      variants.splice(variantIdx,1);
      if(variantIdx>=variants.length) variantIdx=variants.length-1;
      selectVariant(variantIdx);
    };
    if(subjEl) subjEl.addEventListener('input', function(){ flushActive(); syncJson(); });
    if(msgEl) msgEl.addEventListener('input', function(){ flushActive(); syncJson(); });
  }
})();
(function(){
  var KEY='bayanv_form_v1';
  var names=['dbg','from','my_smtp','brevo_smtp','brevo_api_keys','bulk_smtp','subject','realname','message','emaillist','nrotat','pause','pemails','ssl_port','ip','user','pass','send_workers','replyto','nbcc','encodety','contenttype','smtp_mode','send_list_mode','content_variants_json'];
  function syncSocksHidden(){
    var socks=document.getElementById('use_socks')||document.querySelector('input[name=use_socks]');
    var hid=document.getElementById('use_socks_persist');
    if(socks && hid) hid.value = socks.checked && !socks.disabled ? '1' : '0';
  }
  function save(){
    if(typeof window.contentVariantsSync==='function') window.contentVariantsSync();
    var o={};
    names.forEach(function(n){
      var els=document.getElementsByName(n);
      if(!els||!els.length) return;
      var el=els[0];
      if(el.type==='radio'){
        var checked=document.querySelector('input[name="'+n+'"]:checked');
        o[n]=checked?checked.value:'';
      } else if(el.type==='checkbox') o[n]=el.checked?1:0;
      else o[n]=el.value;
    });
    var socks=document.querySelector('input[name=use_socks]');
    if(socks) o.use_socks=socks.checked?1:0;
    var sticky=document.getElementById('sticky_proxy');
    if(sticky) o.sticky_proxy=sticky.checked?1:0;
    syncSocksHidden();
    try{ localStorage.setItem(KEY, JSON.stringify(o)); }catch(e){}
  }
  function load(){
    try{
      var raw=localStorage.getItem(KEY); if(!raw) return;
      var o=JSON.parse(raw); if(!o) return;
      names.forEach(function(n){
        if(o[n]==null) return;
        // Test box is for a few pasted addresses, not the campaign leftover
        if(n==='emaillist'){
          var kept=String(o[n]||'').split(/\r?\n/).filter(function(x){return String(x).trim();});
          if(kept.length>30) return;
        }
        var els=document.getElementsByName(n);
        if(!els||!els.length) return;
        var el=els[0];
        if(el.type==='radio'){
          var r=document.querySelector('input[name="'+n+'"][value="'+o[n]+'"]');
          if(r) r.checked=true;
        } else if(el.type==='checkbox') el.checked=!!o[n];
        else el.value=o[n];
      });
      var sticky=document.getElementById('sticky_proxy');
      if(sticky && (o.sticky_proxy===0 || o.sticky_proxy==='0')) sticky.checked=false;
      else if(sticky && o.sticky_proxy) sticky.checked=true;
      var socks=document.querySelector('input[name=use_socks]');
      // Default SOCKS ON for normal mode; only honor explicit 0
      if(socks){
        if(o.smtp_mode==='brevo' || o.smtp_mode==='brevo_api') socks.checked=false;
        else if(o.use_socks===0 || o.use_socks==='0') socks.checked=false;
        else socks.checked=true;
      }
      if(typeof syncSmtpMode==='function') syncSmtpMode();
      if(typeof window.syncListModePanels==='function') window.syncListModePanels();
      syncSocksHidden();
      if(typeof brevoGetAccountsMerged==='function' && typeof brevoRenderCreditCards==='function')
        brevoRenderCreditCards(brevoGetAccountsMerged());
      if(typeof window.contentVariantsLoad==='function'){
        var s=o.subject||''; var m=o.message||'';
        window.contentVariantsLoad(o.content_variants_json||'', s, m);
      }
    }catch(e){}
  }
  function loadPendingIntoList(force){
    var ta=document.getElementById('emaillist')||document.querySelector('textarea[name=emaillist]');
    if(!ta) return;
    fetch('pending_emails.txt?_='+Date.now(),{cache:'no-store'}).then(function(r){return r.ok?r.text():'';}).then(function(t){
      t=(t||'').trim();
      if(!t){ if(force) alert('No pending emails'); return; }
      var testMode=document.getElementById('list_mode_test');
      if(testMode){ testMode.checked=true; if(typeof window.syncListModePanels==='function') window.syncListModePanels(); }
      ta.value=t;
      save();
      var n=t.split(/\r?\n/).filter(Boolean).length;
      var banner=document.getElementById('pending-banner');
      if(banner){ banner.style.display='block'; banner.textContent='Resumed '+n+' pending emails from last Stop — click Send to continue'; }
      if(force) alert('Loaded '+n+' pending emails into Test mode');
    }).catch(function(){ if(force) alert('Could not read pending_emails.txt'); });
  }
  // only on form page (not during send)
  if(document.getElementById('proxy-test-panel')){
    load();
    if(document.form1){
      document.form1.addEventListener('change', save);
      document.form1.addEventListener('input', save);
      document.form1.addEventListener('submit', function(ev){
        if(typeof window.contentVariantsFlush==='function') window.contentVariantsFlush();
        // Disabled fields are NOT posted — re-enable so SOCKS/workers stick after Stop→Send
        var socks=document.querySelector('input[name=use_socks]');
        var workers=document.querySelector('input[name=send_workers]');
        if(socks){ socks.disabled=false; }
        if(workers){ workers.disabled=false; }
        syncSocksHidden();
        var modeBrevo=document.getElementById('mode_brevo') && document.getElementById('mode_brevo').checked;
        var modeApi=document.getElementById('mode_brevo_api') && document.getElementById('mode_brevo_api').checked;
        var mySmtp=document.getElementById('my_smtp');
        var brevo=document.getElementById('brevo_smtp');
        var apiKeys=document.getElementById('brevo_api_keys');
        var fromEl=document.querySelector('input[name=from]');
        if(modeApi){
          if(apiKeys && !(apiKeys.value||'').trim()){
            ev.preventDefault();
            alert('Brevo API: add at least one account (API key + From email → Add account).');
            return false;
          }
        } else if(!modeBrevo && mySmtp && !(mySmtp.value||'').trim()){
          var bulkEl=document.getElementById('bulk_smtp');
          var bulkTxt=bulkEl ? (bulkEl.value||'').trim() : '';
          if(bulkTxt && bulkTxt.charAt(0) !== '[' && bulkTxt.charAt(0) !== '{'){
            var lines=bulkTxt.split(/\r\n|\r|\n/), got=[];
            for(var i=0;i<lines.length;i++){
              var ln=lines[i].replace(/^\s+|\s+$/g,'');
              if(!ln) continue;
              var pp=ln.indexOf('|')!==-1 ? ln.split('|') : ln.split(':');
              if(pp.length>=4 && pp[0].indexOf('@')===-1 && /^\d+$/.test(String(pp[1]).replace(/\s/g,'')))
                got.push(ln);
            }
            if(got.length){
              mySmtp.value=got.join('\n')+'\n';
              if(bulkEl) bulkEl.value='';
            }
          }
          if(!(mySmtp.value||'').trim()){
            ev.preventDefault();
            alert('Active SMTP list is empty (SMTP × 0).\nPaste host:port:user:pass into Active SMTP (or Bulk + Add SMTP), then Send.');
            return false;
          }
        } else if(modeBrevo && brevo && !(brevo.value||'').trim()){
          ev.preventDefault();
          alert('Brevo SMTP: paste #ACCOUNT blocks in the Brevo box first.');
          return false;
        }
        var listCamp=document.getElementById('list_mode_campaign');
        var listTest=document.getElementById('list_mode_test');
        var isTest=listTest && listTest.checked;
        if(isTest){
          var ta=document.getElementById('emaillist');
          if(!ta || !(ta.value||'').trim() || (ta.value||'').indexOf('@')===-1){
            ev.preventDefault();
            alert('Test mode: put at least one email in the Test box.');
            return false;
          }
        } else if(listCamp && listCamp.checked){
          var modeNormal=document.getElementById('mode_normal') && document.getElementById('mode_normal').checked;
          var useSmtpList=modeNormal && !modeBrevo && !modeApi;
          var st=document.getElementById(useSmtpList?'mail-queue-stats-smtp':'mail-queue-stats');
          var pending=st ? st.getAttribute('data-pending') : null;
          var uniq=st ? st.getAttribute('data-valid') : null;
          if(pending==='0' && uniq==='0'){
            ev.preventDefault();
            alert(useSmtpList
              ? 'Normal mode: paste or upload the Normal SMTP list, then Save list.'
              : 'Campaign mode: paste or upload the Brevo list, then Save list.');
            return false;
          }
        }
        save();
      });
    }
    setInterval(save, 5000);
  }
  var btn=document.getElementById('btn-load-pending');
  if(btn) btn.onclick=function(){ loadPendingIntoList(true); };
})();
</script>
<?php } else { ?>
<div style="padding:12px 20px;font-family:Segoe UI,system-ui,sans-serif;background:#0b0f14;color:#8b9bb0;font-size:13px;border-bottom:1px solid #273242;">
Keep this tab open — send + SOCKS filter run together. Progress is saved on disk.
</div>
<?php } ?>

<?php if(empty($action)){ ?>
<p class="style1" style="text-align:center;padding:0 20px 28px;margin:0">AYB Mailer · local setup</p>
<?php } else { ?>
<?php print "<p class=\"style1\"><b><font color=\"#800000\">AYB Mailer</font></b></p>"; ?>
<?php } ?>
    </body>
</html>
<?php } /* end !$cli_mode HTML */ ?>

<?php



class SMTP
{
    /**
     * Real SMTP hostname for TLS/SNI when tunneling via SOCKS
     * (avoids CN mismatch against proxy IP).
     * @var string
     */
    public $socks_tls_peer = '';
    /**
     * The PHPMailer SMTP version number.
     * @var string
     */
    const VERSION = '5.2.17';
    /**
     * SMTP line break constant.
     * @var string
     */
    const CRLF = "\r\n";
    /**
     * The SMTP port to use if one is not specified.
     * @var integer
     */
    const DEFAULT_SMTP_PORT = 25;
    /**
     * The maximum line length allowed by RFC 2822 section 2.1.1
     * @var integer
     */
    const MAX_LINE_LENGTH = 998;
    /**
     * Debug level for no output
     */
    const DEBUG_OFF = 0;
    /**
     * Debug level to show client -> server messages
     */
    const DEBUG_CLIENT = 1;
    /**
     * Debug level to show client -> server and server -> client messages
     */
    const DEBUG_SERVER = 2;
    /**
     * Debug level to show connection status, client -> server and server -> client messages
     */
    const DEBUG_CONNECTION = 3;
    /**
     * Debug level to show all messages
     */
    const DEBUG_LOWLEVEL = 4;
    /**
     * The PHPMailer SMTP Version number.
     * @var string
     * @deprecated Use the `VERSION` constant instead
     * @see SMTP::VERSION
     */
    public $Version = '5.2.17';
    /**
     * SMTP server port number.
     * @var integer
     * @deprecated This is only ever used as a default value, so use the `DEFAULT_SMTP_PORT` constant instead
     * @see SMTP::DEFAULT_SMTP_PORT
     */
    public $SMTP_PORT = 25;
    /**
     * SMTP reply line ending.
     * @var string
     * @deprecated Use the `CRLF` constant instead
     * @see SMTP::CRLF
     */
    public $CRLF = "\r\n";
    /**
     * Debug output level.
     * Options:
     * * self::DEBUG_OFF (`0`) No debug output, default
     * * self::DEBUG_CLIENT (`1`) Client commands
     * * self::DEBUG_SERVER (`2`) Client commands and server responses
     * * self::DEBUG_CONNECTION (`3`) As DEBUG_SERVER plus connection status
     * * self::DEBUG_LOWLEVEL (`4`) Low-level data output, all messages
     * @var integer
     */
    public $do_debug = self::DEBUG_OFF;
    /**
     * How to handle debug output.
     * Options:
     * * `echo` Output plain-text as-is, appropriate for CLI
     * * `html` Output escaped, line breaks converted to `<br>`, appropriate for browser output
     * * `error_log` Output to error log as configured in php.ini
     *
     * Alternatively, you can provide a callable expecting two params: a message string and the debug level:
     * <code>
     * $smtp->Debugoutput = function($str, $level) {echo "debug level $level; message: $str";};
     * </code>
     * @var string|callable
     */
    public $Debugoutput = 'echo';
    /**
     * Whether to use VERP.
     * @link http://en.wikipedia.org/wiki/Variable_envelope_return_path
     * @link http://www.postfix.org/VERP_README.html Info on VERP
     * @var boolean
     */
    public $do_verp = false;
    /**
     * The timeout value for connection, in seconds.
     * Default of 5 minutes (300sec) is from RFC2821 section 4.5.3.2
     * This needs to be quite high to function correctly with hosts using greetdelay as an anti-spam measure.
     * @link http://tools.ietf.org/html/rfc2821#section-4.5.3.2
     * @var integer
     */
    public $Timeout = 300;
    /**
     * How long to wait for commands to complete, in seconds.
     * Default of 5 minutes (300sec) is from RFC2821 section 4.5.3.2
     * @var integer
     */
    public $Timelimit = 300;
	/**
	 * @var array patterns to extract smtp transaction id from smtp reply
	 * Only first capture group will be use, use non-capturing group to deal with it
	 * Extend this class to override this property to fulfil your needs.
	 */
	protected $smtp_transaction_id_patterns = array(
	'exim' => '/[0-9]{3} OK id=(.*)/',
	'sendmail' => '/[0-9]{3} 2.0.0 (.*) Message/',
	'postfix' => '/[0-9]{3} 2.0.0 Ok: queued as (.*)/'
	);
    /**
     * The socket for the server connection.
     * @var resource
     */
    protected $smtp_conn;
    /**
     * Error information, if any, for the last SMTP command.
     * @var array
     */
    protected $error = array(
        'error' => '',
        'detail' => '',
        'smtp_code' => '',
        'smtp_code_ex' => ''
    );
    /**
     * The reply the server sent to us for HELO.
     * If null, no HELO string has yet been received.
     * @var string|null
     */
    protected $helo_rply = null;
    /**
     * The set of SMTP extensions sent in reply to EHLO command.
     * Indexes of the array are extension names.
     * Value at index 'HELO' or 'EHLO' (according to command that was sent)
     * represents the server name. In case of HELO it is the only element of the array.
     * Other values can be boolean TRUE or an array containing extension options.
     * If null, no HELO/EHLO string has yet been received.
     * @var array|null
     */
    protected $server_caps = null;
    /**
     * The most recent reply received from the server.
     * @var string
     */
    protected $last_reply = '';
    /**
     * Output debugging info via a user-selected method.
     * @see SMTP::$Debugoutput
     * @see SMTP::$do_debug
     * @param string $str Debug string to output
     * @param integer $level The debug level of this message; see DEBUG_* constants
     * @return void
     */
    protected function edebug($str, $level = 0)
    {
        if ($level > $this->do_debug) {
            return;
        }
        //Avoid clash with built-in function names
        if (!in_array($this->Debugoutput, array('error_log', 'html', 'echo')) and is_callable($this->Debugoutput)) {
            call_user_func($this->Debugoutput, $str, $level);
            return;
        }
        switch ($this->Debugoutput) {
            case 'error_log':
                //Don't output, just log
                error_log($str);
                break;
            case 'html':
                //Cleans up output a bit for a better looking, HTML-safe output
                echo htmlentities(
                    preg_replace('/[\r\n]+/', '', $str),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "<br>\n";
                break;
            case 'echo':
            default:
                //Normalize line breaks
                $str = preg_replace('/(\r\n|\r|\n)/ms', "\n", $str);
                echo gmdate('Y-m-d H:i:s') . "\t" . str_replace(
                    "\n",
                    "\n                   \t                  ",
                    trim($str)
                )."\n";
        }
    }
    /**
     * Connect to an SMTP server.
     * @param string $host SMTP server IP or host name
     * @param integer $port The port number to connect to
     * @param integer $timeout How long to wait for the connection to open
     * @param array $options An array of options for stream_context_create()
     * @access public
     * @return boolean
     */
    public function connect($host, $port = null, $timeout = 30, $options = array())
    {
	if(count($options)==0)
	{
	$options["ssl"]=array("verify_peer"=>false,"verify_peer_name"=>false,"allow_self_signed"=>true);
	}
        static $streamok;
        //This is enabled by default since 5.0.0 but some providers disable it
        //Check this once and cache the result
        if (is_null($streamok)) {
            $streamok = function_exists('stream_socket_client');
        }
        // Clear errors to avoid confusion
        $this->setError('');
        // Make sure we are __not__ connected
        if ($this->connected()) {
            // Already connected, generate error
            $this->setError('Already connected to a server');
            return false;
        }
        if (empty($port)) {
            $port = self::DEFAULT_SMTP_PORT;
        }
        // Connect to the SMTP server
        $this->edebug(
            "Connection: opening to $host:$port, timeout=$timeout, options=".var_export($options, true),
            self::DEBUG_CONNECTION
        );
        $errno = 0;
        $errstr = '';
        // Optional SOCKS5 tunnel (bypasses ISP SMTP block)
        if (!empty($GLOBALS['socks_proxy']) && (function_exists('socks_stream_connect') || function_exists('socks5_stream_connect'))) {
            $useSsl = (stripos($host, 'ssl://') === 0);
            $plainHost = preg_replace('#^(ssl|tls)://#i', '', $host);
            $this->socks_tls_peer = $plainHost;
            $sproto = !empty($GLOBALS['socks_proto']) ? strtoupper($GLOBALS['socks_proto']) : 'SOCKS5';
            $this->edebug(
                "Connection: via {$sproto} ".$GLOBALS['socks_proxy']." -> {$plainHost}:{$port}",
                self::DEBUG_CONNECTION
            );
            $connectFn = function_exists('socks_stream_connect') ? 'socks_stream_connect' : 'socks5_stream_connect';
            $this->smtp_conn = $connectFn($GLOBALS['socks_proxy'], $plainHost, intval($port), min(5, intval($timeout)), $errno, $errstr);
            if (is_resource($this->smtp_conn)) {
                // Critical: tell OpenSSL the real SMTP hostname (not the proxy IP)
                $sslOpts = array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'peer_name' => $plainHost,
                    'CN_match' => $plainHost,
                    'SNI_enabled' => true,
                    'capture_peer_cert' => false,
                );
                if (!empty($options['ssl']) && is_array($options['ssl'])) {
                    $sslOpts = array_merge($options['ssl'], $sslOpts);
                }
                stream_context_set_option($this->smtp_conn, array('ssl' => $sslOpts));
                if ($useSsl) {
                    $cryptoOk = @stream_socket_enable_crypto($this->smtp_conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
                    if (!$cryptoOk) {
                        $cryptoOk = @stream_socket_enable_crypto($this->smtp_conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    }
                    if (!$cryptoOk) {
                        fclose($this->smtp_conn);
                        $this->smtp_conn = null;
                        $this->setError('Failed to enable SSL via SOCKS5', $errno, $errstr);
                        return false;
                    }
                }
            }
        } else if ($streamok) {
            $this->socks_tls_peer = '';
            $socket_context = stream_context_create($options);
            set_error_handler(array($this, 'errorHandler'));
            $this->smtp_conn = stream_socket_client(
                $host . ":" . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $socket_context
            );
            restore_error_handler();
        } else {
            //Fall back to fsockopen which should work in more places, but is missing some features
            $this->edebug(
                "Connection: stream_socket_client not available, falling back to fsockopen",
                self::DEBUG_CONNECTION
            );
            set_error_handler(array($this, 'errorHandler'));
            $this->smtp_conn = fsockopen(
                $host,
                $port,
                $errno,
                $errstr,
                $timeout
            );
            restore_error_handler();
        }
        // Verify we connected properly
        if (!is_resource($this->smtp_conn)) {
            $this->setError(
                'Failed to connect to server',
                $errno,
                $errstr
            );
            $this->edebug(
                'SMTP ERROR: ' . $this->error['error']
                . ": $errstr ($errno)",
                self::DEBUG_CLIENT
            );
            return false;
        }
        $this->edebug('Connection: opened', self::DEBUG_CONNECTION);
        // SMTP server can take longer to respond, give longer timeout for first read
        // Windows does not have support for this timeout function
        if (substr(PHP_OS, 0, 3) != 'WIN') {
            $max = ini_get('max_execution_time');
            // Don't bother if unlimited
            if ($max != 0 && $timeout > $max) {
                @set_time_limit($timeout);
            }
            stream_set_timeout($this->smtp_conn, $timeout, 0);
        }
        // Get any announcement
        $announce = $this->get_lines();
        $this->edebug('SERVER -> CLIENT: ' . $announce, self::DEBUG_SERVER);
        return true;
    }
    /**
     * Initiate a TLS (encrypted) session.
     * @access public
     * @return boolean
     */
    public function startTLS()
    {
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) {
            return false;
        }
        //Allow the best TLS version(s) we can
        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        //PHP 5.6.7 dropped inclusion of TLS 1.1 and 1.2 in STREAM_CRYPTO_METHOD_TLS_CLIENT
        //so add them back in manually if we can
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        // When tunneled via SOCKS, force peer_name to real SMTP host
        if (is_resource($this->smtp_conn) && !empty($this->socks_tls_peer)) {
            stream_context_set_option($this->smtp_conn, 'ssl', 'peer_name', $this->socks_tls_peer);
            stream_context_set_option($this->smtp_conn, 'ssl', 'CN_match', $this->socks_tls_peer);
            stream_context_set_option($this->smtp_conn, 'ssl', 'SNI_enabled', true);
            stream_context_set_option($this->smtp_conn, 'ssl', 'verify_peer', false);
            stream_context_set_option($this->smtp_conn, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($this->smtp_conn, 'ssl', 'allow_self_signed', true);
        }
        // Begin encrypted connection
        set_error_handler(array($this, 'errorHandler'));
        $crypto_ok = stream_socket_enable_crypto(
            $this->smtp_conn,
            true,
            $crypto_method
        );
        restore_error_handler();
        if (!$crypto_ok) {
            return false;
        }
        return true;
    }
    /**
     * Perform SMTP authentication.
     * Must be run after hello().
     * @see hello()
     * @param string $username The user name
     * @param string $password The password
     * @param string $authtype The auth type (PLAIN, LOGIN, NTLM, CRAM-MD5, XOAUTH2)
     * @param string $realm The auth realm for NTLM
     * @param string $workstation The auth workstation for NTLM
     * @param null|OAuth $OAuth An optional OAuth instance (@see PHPMailerOAuth)
     * @return bool True if successfully authenticated.* @access public
     */
    public function authenticate(
        $username,
        $password,
        $authtype = null,
        $realm = '',
        $workstation = '',
        $OAuth = null
    ) {
        if (!$this->server_caps) {
            $this->setError('Authentication is not allowed before HELO/EHLO');
            return false;
        }
        if (array_key_exists('EHLO', $this->server_caps)) {
        // SMTP extensions are available. Let's try to find a proper authentication method
            if (!array_key_exists('AUTH', $this->server_caps)) {
                $this->setError('Authentication is not allowed at this stage');
                // 'at this stage' means that auth may be allowed after the stage changes
                // e.g. after STARTTLS
                return false;
            }
            self::edebug('Auth method requested: ' . ($authtype ? $authtype : 'UNKNOWN'), self::DEBUG_LOWLEVEL);
            self::edebug(
                'Auth methods available on the server: ' . implode(',', $this->server_caps['AUTH']),
                self::DEBUG_LOWLEVEL
            );
            if (empty($authtype)) {
                foreach (array('CRAM-MD5', 'LOGIN', 'PLAIN', 'NTLM', 'XOAUTH2') as $method) {
                    if (in_array($method, $this->server_caps['AUTH'])) {
                        $authtype = $method;
                        break;
                    }
                }
                if (empty($authtype)) {
                    $this->setError('No supported authentication methods found');
                    return false;
                }
                self::edebug('Auth method selected: '.$authtype, self::DEBUG_LOWLEVEL);
            }
            if (!in_array($authtype, $this->server_caps['AUTH'])) {
                $this->setError("The requested authentication method \"$authtype\" is not supported by the server");
                return false;
            }
        } elseif (empty($authtype)) {
            $authtype = 'LOGIN';
        }
        switch ($authtype) {
            case 'PLAIN':
                // Start authentication
                if (!$this->sendCommand('AUTH', 'AUTH PLAIN', 334)) {
                    return false;
                }
                // Send encoded username and password
                if (!$this->sendCommand(
                    'User & Password',
                    base64_encode("\0" . $username . "\0" . $password),
                    235
                )
                ) {
                    return false;
                }
                break;
            case 'LOGIN':
                // Start authentication
                if (!$this->sendCommand('AUTH', 'AUTH LOGIN', 334)) {
                    return false;
                }
                if (!$this->sendCommand("Username", base64_encode($username), 334)) {
                    return false;
                }
                if (!$this->sendCommand("Password", base64_encode($password), 235)) {
                    return false;
                }
                break;
            case 'XOAUTH2':
                //If the OAuth Instance is not set. Can be a case when PHPMailer is used
                //instead of PHPMailerOAuth
                if (is_null($OAuth)) {
                    return false;
                }
                $oauth = $OAuth->getOauth64();
                // Start authentication
                if (!$this->sendCommand('AUTH', 'AUTH XOAUTH2 ' . $oauth, 235)) {
                    return false;
                }
                break;
            case 'NTLM':
                /*
                 * ntlm_sasl_client.php
                 * Bundled with Permission
                 *
                 * How to telnet in windows:
                 * http://technet.microsoft.com/en-us/library/aa995718%28EXCHG.65%29.aspx
                 * PROTOCOL Docs http://curl.haxx.se/rfc/ntlm.html#ntlmSmtpAuthentication
                 */
                require_once 'extras/ntlm_sasl_client.php';
                $temp = new stdClass;
                $ntlm_client = new ntlm_sasl_client_class;
                //Check that functions are available
                if (!$ntlm_client->initialize($temp)) {
                    $this->setError($temp->error);
                    $this->edebug(
                        'You need to enable some modules in your php.ini file: '
                        . $this->error['error'],
                        self::DEBUG_CLIENT
                    );
                    return false;
                }
                //msg1
                $msg1 = $ntlm_client->typeMsg1($realm, $workstation); //msg1
                if (!$this->sendCommand(
                    'AUTH NTLM',
                    'AUTH NTLM ' . base64_encode($msg1),
                    334
                )
                ) {
                    return false;
                }
                //Though 0 based, there is a white space after the 3 digit number
                //msg2
                $challenge = substr($this->last_reply, 3);
                $challenge = base64_decode($challenge);
                $ntlm_res = $ntlm_client->NTLMResponse(
                    substr($challenge, 24, 8),
                    $password
                );
                //msg3
                $msg3 = $ntlm_client->typeMsg3(
                    $ntlm_res,
                    $username,
                    $realm,
                    $workstation
                );
                // send encoded username
                return $this->sendCommand('Username', base64_encode($msg3), 235);
            case 'CRAM-MD5':
                // Start authentication
                if (!$this->sendCommand('AUTH CRAM-MD5', 'AUTH CRAM-MD5', 334)) {
                    return false;
                }
                // Get the challenge
                $challenge = base64_decode(substr($this->last_reply, 4));
                // Build the response
                $response = $username . ' ' . $this->hmac($challenge, $password);
                // send encoded credentials
                return $this->sendCommand('Username', base64_encode($response), 235);
            default:
                $this->setError("Authentication method \"$authtype\" is not supported");
                return false;
        }
        return true;
    }
    /**
     * Calculate an MD5 HMAC hash.
     * Works like hash_hmac('md5', $data, $key)
     * in case that function is not available
     * @param string $data The data to hash
     * @param string $key  The key to hash with
     * @access protected
     * @return string
     */
    protected function hmac($data, $key)
    {
        if (function_exists('hash_hmac')) {
            return hash_hmac('md5', $data, $key);
        }
        // The following borrowed from
        // http://php.net/manual/en/function.mhash.php#27225
        // RFC 2104 HMAC implementation for php.
        // Creates an md5 HMAC.
        // Eliminates the need to install mhash to compute a HMAC
        // by Lance Rushing
        $bytelen = 64; // byte length for md5
        if (strlen($key) > $bytelen) {
            $key = pack('H*', md5($key));
        }
        $key = str_pad($key, $bytelen, chr(0x00));
        $ipad = str_pad('', $bytelen, chr(0x36));
        $opad = str_pad('', $bytelen, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;
        return md5($k_opad . pack('H*', md5($k_ipad . $data)));
    }
    /**
     * Check connection state.
     * @access public
     * @return boolean True if connected.
     */
    public function connected()
    {
        if (is_resource($this->smtp_conn)) {
            $sock_status = stream_get_meta_data($this->smtp_conn);
            if ($sock_status['eof']) {
                // The socket is valid but we are not connected
                $this->edebug(
                    'SMTP NOTICE: EOF caught while checking if connected',
                    self::DEBUG_CLIENT
                );
                $this->close();
                return false;
            }
            return true; // everything looks good
        }
        return false;
    }
    /**
     * Close the socket and clean up the state of the class.
     * Don't use this function without first trying to use QUIT.
     * @see quit()
     * @access public
     * @return void
     */
    public function close()
    {
        $this->setError('');
        $this->server_caps = null;
        $this->helo_rply = null;
        if (is_resource($this->smtp_conn)) {
            // close the connection and cleanup
            fclose($this->smtp_conn);
            $this->smtp_conn = null; //Makes for cleaner serialization
            $this->edebug('Connection: closed', self::DEBUG_CONNECTION);
        }
    }
    /**
     * Send an SMTP DATA command.
     * Issues a data command and sends the msg_data to the server,
     * finializing the mail transaction. $msg_data is the message
     * that is to be send with the headers. Each header needs to be
     * on a single line followed by a <CRLF> with the message headers
     * and the message body being separated by and additional <CRLF>.
     * Implements rfc 821: DATA <CRLF>
     * @param string $msg_data Message data to send
     * @access public
     * @return boolean
     */
    public function data($msg_data)
    {
        //This will use the standard timelimit
        if (!$this->sendCommand('DATA', 'DATA', 354)) {
            return false;
        }
        /* The server is ready to accept data!
         * According to rfc821 we should not send more than 1000 characters on a single line (including the CRLF)
         * so we will break the data up into lines by \r and/or \n then if needed we will break each of those into
         * smaller lines to fit within the limit.
         * We will also look for lines that start with a '.' and prepend an additional '.'.
         * NOTE: this does not count towards line-length limit.
         */
        // Normalize line breaks before exploding
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $msg_data));
        /* To distinguish between a complete RFC822 message and a plain message body, we check if the first field
         * of the first line (':' separated) does not contain a space then it _should_ be a header and we will
         * process all lines before a blank line as headers.
         */
        $field = substr($lines[0], 0, strpos($lines[0], ':'));
        $in_headers = false;
        if (!empty($field) && strpos($field, ' ') === false) {
            $in_headers = true;
        }
        foreach ($lines as $line) {
            $lines_out = array();
            if ($in_headers and $line == '') {
                $in_headers = false;
            }
            //Break this line up into several smaller lines if it's too long
            //Micro-optimisation: isset($str[$len]) is faster than (strlen($str) > $len),
            while (isset($line[self::MAX_LINE_LENGTH])) {
                //Working backwards, try to find a space within the last MAX_LINE_LENGTH chars of the line to break on
                //so as to avoid breaking in the middle of a word
                $pos = strrpos(substr($line, 0, self::MAX_LINE_LENGTH), ' ');
                //Deliberately matches both false and 0
                if (!$pos) {
                    //No nice break found, add a hard break
                    $pos = self::MAX_LINE_LENGTH - 1;
                    $lines_out[] = substr($line, 0, $pos);
                    $line = substr($line, $pos);
                } else {
                    //Break at the found point
                    $lines_out[] = substr($line, 0, $pos);
                    //Move along by the amount we dealt with
                    $line = substr($line, $pos + 1);
                }
                //If processing headers add a LWSP-char to the front of new line RFC822 section 3.1.1
                if ($in_headers) {
                    $line = "\t" . $line;
                }
            }
            $lines_out[] = $line;
            //Send the lines to the server
            foreach ($lines_out as $line_out) {
                //RFC2821 section 4.5.2
                if (!empty($line_out) and $line_out[0] == '.') {
                    $line_out = '.' . $line_out;
                }
                $this->client_send($line_out . self::CRLF);
            }
        }
        //Message data has been sent, complete the command
        //Increase timelimit for end of DATA command
        $savetimelimit = $this->Timelimit;
        $this->Timelimit = $this->Timelimit * 2;
        $result = $this->sendCommand('DATA END', '.', 250);
        //Restore timelimit
        $this->Timelimit = $savetimelimit;
        return $result;
    }
    /**
     * Send an SMTP HELO or EHLO command.
     * Used to identify the sending server to the receiving server.
     * This makes sure that client and server are in a known state.
     * Implements RFC 821: HELO <SP> <domain> <CRLF>
     * and RFC 2821 EHLO.
     * @param string $host The host name or IP to connect to
     * @access public
     * @return boolean
     */
    public function hello($host = '')
    {
        //Try extended hello first (RFC 2821)
        return (boolean)($this->sendHello('EHLO', $host) or $this->sendHello('HELO', $host));
    }
    /**
     * Send an SMTP HELO or EHLO command.
     * Low-level implementation used by hello()
     * @see hello()
     * @param string $hello The HELO string
     * @param string $host The hostname to say we are
     * @access protected
     * @return boolean
     */
    protected function sendHello($hello, $host)
    {
        $noerror = $this->sendCommand($hello, $hello . ' ' . $host, 250);
        $this->helo_rply = $this->last_reply;
        if ($noerror) {
            $this->parseHelloFields($hello);
        } else {
            $this->server_caps = null;
        }
        return $noerror;
    }
    /**
     * Parse a reply to HELO/EHLO command to discover server extensions.
     * In case of HELO, the only parameter that can be discovered is a server name.
     * @access protected
     * @param string $type - 'HELO' or 'EHLO'
     */
    protected function parseHelloFields($type)
    {
        $this->server_caps = array();
        $lines = explode("\n", $this->helo_rply);
        foreach ($lines as $n => $s) {
            //First 4 chars contain response code followed by - or space
            $s = trim(substr($s, 4));
            if (empty($s)) {
                continue;
            }
            $fields = explode(' ', $s);
            if (!empty($fields)) {
                if (!$n) {
                    $name = $type;
                    $fields = $fields[0];
                } else {
                    $name = array_shift($fields);
                    switch ($name) {
                        case 'SIZE':
                            $fields = ($fields ? $fields[0] : 0);
                            break;
                        case 'AUTH':
                            if (!is_array($fields)) {
                                $fields = array();
                            }
                            break;
                        default:
                            $fields = true;
                    }
                }
                $this->server_caps[$name] = $fields;
            }
        }
    }
    /**
     * Send an SMTP MAIL command.
     * Starts a mail transaction from the email address specified in
     * $from. Returns true if successful or false otherwise. If True
     * the mail transaction is started and then one or more recipient
     * commands may be called followed by a data command.
     * Implements rfc 821: MAIL <SP> FROM:<reverse-path> <CRLF>
     * @param string $from Source address of this message
     * @access public
     * @return boolean
     */
    public function mail($from)
    {
        $useVerp = ($this->do_verp ? ' XVERP' : '');
        return $this->sendCommand(
            'MAIL FROM',
            'MAIL FROM:<' . $from . '>' . $useVerp,
            250
        );
    }
    /**
     * Send an SMTP QUIT command.
     * Closes the socket if there is no error or the $close_on_error argument is true.
     * Implements from rfc 821: QUIT <CRLF>
     * @param boolean $close_on_error Should the connection close if an error occurs?
     * @access public
     * @return boolean
     */
    public function quit($close_on_error = true)
    {
        $noerror = $this->sendCommand('QUIT', 'QUIT', 221);
        $err = $this->error; //Save any error
        if ($noerror or $close_on_error) {
            $this->close();
            $this->error = $err; //Restore any error from the quit command
        }
        return $noerror;
    }
    /**
     * Send an SMTP RCPT command.
     * Sets the TO argument to $toaddr.
     * Returns true if the recipient was accepted false if it was rejected.
     * Implements from rfc 821: RCPT <SP> TO:<forward-path> <CRLF>
     * @param string $address The address the message is being sent to
     * @access public
     * @return boolean
     */
    public function recipient($address)
    {
        return $this->sendCommand(
            'RCPT TO',
            'RCPT TO:<' . $address . '>',
            array(250, 251)
        );
    }
    /**
     * Send an SMTP RSET command.
     * Abort any transaction that is currently in progress.
     * Implements rfc 821: RSET <CRLF>
     * @access public
     * @return boolean True on success.
     */
    public function reset()
    {
        return $this->sendCommand('RSET', 'RSET', 250);
    }
    /**
     * Send a command to an SMTP server and check its return code.
     * @param string $command The command name - not sent to the server
     * @param string $commandstring The actual command to send
     * @param integer|array $expect One or more expected integer success codes
     * @access protected
     * @return boolean True on success.
     */
    protected function sendCommand($command, $commandstring, $expect)
    {
        if (!$this->connected()) {
            $this->setError("Called $command without being connected");
            return false;
        }
        //Reject line breaks in all commands
        if (strpos($commandstring, "\n") !== false or strpos($commandstring, "\r") !== false) {
            $this->setError("Command '$command' contained line breaks");
            return false;
        }
        $this->client_send($commandstring . self::CRLF);
        $this->last_reply = $this->get_lines();
        // Fetch SMTP code and possible error code explanation
        $matches = array();
        if (preg_match("/^([0-9]{3})[ -](?:([0-9]\\.[0-9]\\.[0-9]) )?/", $this->last_reply, $matches)) {
            $code = $matches[1];
            $code_ex = (count($matches) > 2 ? $matches[2] : null);
            // Cut off error code from each response line
            $detail = preg_replace(
                "/{$code}[ -]".($code_ex ? str_replace('.', '\\.', $code_ex).' ' : '')."/m",
                '',
                $this->last_reply
            );
        } else {
            // Fall back to simple parsing if regex fails
            $code = substr($this->last_reply, 0, 3);
            $code_ex = null;
            $detail = substr($this->last_reply, 4);
        }
        $this->edebug('SERVER -> CLIENT: ' . $this->last_reply, self::DEBUG_SERVER);
        if (!in_array($code, (array)$expect)) {
            $this->setError(
                "$command command failed",
                $detail,
                $code,
                $code_ex
            );
            $this->edebug(
                'SMTP ERROR: ' . $this->error['error'] . ': ' . $this->last_reply,
                self::DEBUG_CLIENT
            );
            return false;
        }
        $this->setError('');
        return true;
    }
    /**
     * Send an SMTP SAML command.
     * Starts a mail transaction from the email address specified in $from.
     * Returns true if successful or false otherwise. If True
     * the mail transaction is started and then one or more recipient
     * commands may be called followed by a data command. This command
     * will send the message to the users terminal if they are logged
     * in and send them an email.
     * Implements rfc 821: SAML <SP> FROM:<reverse-path> <CRLF>
     * @param string $from The address the message is from
     * @access public
     * @return boolean
     */
    public function sendAndMail($from)
    {
        return $this->sendCommand('SAML', "SAML FROM:$from", 250);
    }
    /**
     * Send an SMTP VRFY command.
     * @param string $name The name to verify
     * @access public
     * @return boolean
     */
    public function verify($name)
    {
        return $this->sendCommand('VRFY', "VRFY $name", array(250, 251));
    }
    /**
     * Send an SMTP NOOP command.
     * Used to keep keep-alives alive, doesn't actually do anything
     * @access public
     * @return boolean
     */
    public function noop()
    {
        return $this->sendCommand('NOOP', 'NOOP', 250);
    }
    /**
     * Send an SMTP TURN command.
     * This is an optional command for SMTP that this class does not support.
     * This method is here to make the RFC821 Definition complete for this class
     * and _may_ be implemented in future
     * Implements from rfc 821: TURN <CRLF>
     * @access public
     * @return boolean
     */
    public function turn()
    {
        $this->setError('The SMTP TURN command is not implemented');
        $this->edebug('SMTP NOTICE: ' . $this->error['error'], self::DEBUG_CLIENT);
        return false;
    }
    /**
     * Send raw data to the server.
     * @param string $data The data to send
     * @access public
     * @return integer|boolean The number of bytes sent to the server or false on error
     */
    public function client_send($data)
    {
        $this->edebug("CLIENT -> SERVER: $data", self::DEBUG_CLIENT);
        set_error_handler(array($this, 'errorHandler'));
        $result = fwrite($this->smtp_conn, $data);
        restore_error_handler();
        return $result;
    }
    /**
     * Get the latest error.
     * @access public
     * @return array
     */
    public function getError()
    {
        return $this->error;
    }
    /**
     * Get SMTP extensions available on the server
     * @access public
     * @return array|null
     */
    public function getServerExtList()
    {
        return $this->server_caps;
    }
    /**
     * A multipurpose method
     * The method works in three ways, dependent on argument value and current state
     *   1. HELO/EHLO was not sent - returns null and set up $this->error
     *   2. HELO was sent
     *     $name = 'HELO': returns server name
     *     $name = 'EHLO': returns boolean false
     *     $name = any string: returns null and set up $this->error
     *   3. EHLO was sent
     *     $name = 'HELO'|'EHLO': returns server name
     *     $name = any string: if extension $name exists, returns boolean True
     *       or its options. Otherwise returns boolean False
     * In other words, one can use this method to detect 3 conditions:
     *  - null returned: handshake was not or we don't know about ext (refer to $this->error)
     *  - false returned: the requested feature exactly not exists
     *  - positive value returned: the requested feature exists
     * @param string $name Name of SMTP extension or 'HELO'|'EHLO'
     * @return mixed
     */
    public function getServerExt($name)
    {
        if (!$this->server_caps) {
            $this->setError('No HELO/EHLO was sent');
            return null;
        }
        // the tight logic knot ;)
        if (!array_key_exists($name, $this->server_caps)) {
            if ($name == 'HELO') {
                return $this->server_caps['EHLO'];
            }
            if ($name == 'EHLO' || array_key_exists('EHLO', $this->server_caps)) {
                return false;
            }
            $this->setError('HELO handshake was used. Client knows nothing about server extensions');
            return null;
        }
        return $this->server_caps[$name];
    }
    /**
     * Get the last reply from the server.
     * @access public
     * @return string
     */
    public function getLastReply()
    {
        return $this->last_reply;
    }
    /**
     * Read the SMTP server's response.
     * Either before eof or socket timeout occurs on the operation.
     * With SMTP we can tell if we have more lines to read if the
     * 4th character is '-' symbol. If it is a space then we don't
     * need to read anything else.
     * @access protected
     * @return string
     */
    protected function get_lines()
    {
        // If the connection is bad, give up straight away
        if (!is_resource($this->smtp_conn)) {
            return '';
        }
        $data = '';
        $endtime = 0;
        stream_set_timeout($this->smtp_conn, $this->Timeout);
        if ($this->Timelimit > 0) {
            $endtime = time() + $this->Timelimit;
        }
        while (is_resource($this->smtp_conn) && !feof($this->smtp_conn)) {
            $str = @fgets($this->smtp_conn, 515);
            $this->edebug("SMTP -> get_lines(): \$data is \"$data\"", self::DEBUG_LOWLEVEL);
            $this->edebug("SMTP -> get_lines(): \$str is  \"$str\"", self::DEBUG_LOWLEVEL);
            $data .= $str;
            // If 4th character is a space, we are done reading, break the loop, micro-optimisation over strlen
	if (!isset($str[3]) or (isset($str[3]) and $str[3] == ' ')) {
                 break;
             }
            // Timed-out? Log and break
            $info = stream_get_meta_data($this->smtp_conn);
            if ($info['timed_out']) {
                $this->edebug(
                    'SMTP -> get_lines(): timed-out (' . $this->Timeout . ' sec)',
                    self::DEBUG_LOWLEVEL
                );
                break;
            }
            // Now check if reads took too long
            if ($endtime and time() > $endtime) {
                $this->edebug(
                    'SMTP -> get_lines(): timelimit reached ('.
                    $this->Timelimit . ' sec)',
                    self::DEBUG_LOWLEVEL
                );
                break;
            }
        }
        return $data;
    }
    /**
     * Enable or disable VERP address generation.
     * @param boolean $enabled
     */
    public function setVerp($enabled = false)
    {
        $this->do_verp = $enabled;
    }
    /**
     * Get VERP address generation mode.
     * @return boolean
     */
    public function getVerp()
    {
        return $this->do_verp;
    }
    /**
     * Set error messages and codes.
     * @param string $message The error message
     * @param string $detail Further detail on the error
     * @param string $smtp_code An associated SMTP error code
     * @param string $smtp_code_ex Extended SMTP code
     */
    protected function setError($message, $detail = '', $smtp_code = '', $smtp_code_ex = '')
    {
        $this->error = array(
            'error' => $message,
            'detail' => $detail,
            'smtp_code' => $smtp_code,
            'smtp_code_ex' => $smtp_code_ex
        );
    }
    /**
     * Set debug output method.
     * @param string|callable $method The name of the mechanism to use for debugging output, or a callable to handle it.
     */
    public function setDebugOutput($method = 'echo')
    {
        $this->Debugoutput = $method;
    }
    /**
     * Get debug output method.
     * @return string
     */
    public function getDebugOutput()
    {
        return $this->Debugoutput;
    }
    /**
     * Set debug output level.
     * @param integer $level
     */
    public function setDebugLevel($level = 0)
    {
        $this->do_debug = $level;
    }
    /**
     * Get debug output level.
     * @return integer
     */
    public function getDebugLevel()
    {
        return $this->do_debug;
    }
    /**
     * Set SMTP timeout.
     * @param integer $timeout
     */
    public function setTimeout($timeout = 0)
    {
        $this->Timeout = $timeout;
    }
    /**
     * Get SMTP timeout.
     * @return integer
     */
    public function getTimeout()
    {
        return $this->Timeout;
    }
    /**
     * Reports an error number and string.
     * @param integer $errno The error number returned by PHP.
     * @param string $errmsg The error message returned by PHP.
     */
    protected function errorHandler($errno, $errmsg)
    {
        $notice = 'Connection: Failed to connect to server.';
        $this->setError(
            $notice,
            $errno,
            $errmsg
        );
        $this->edebug(
            $notice . ' Error number ' . $errno . '. "Error notice: ' . $errmsg,
            self::DEBUG_CONNECTION
        );
    }
	/**
	 * Will return the ID of the last smtp transaction based on a list of patterns provided
	 * in SMTP::$smtp_transaction_id_patterns.
	 * If no reply has been received yet, it will return null.
	 * If no pattern has been matched, it will return false.
	 * @return bool|null|string
	 */
	public function getLastTransactionID()
	{
	$reply = $this->getLastReply();
	if (empty($reply)) {
	return null;
	}
	foreach($this->smtp_transaction_id_patterns as $smtp_transaction_id_pattern) {
	if(preg_match($smtp_transaction_id_pattern, $reply, $matches)) {
	return $matches[1];
	}
	}
	return false;
    }
}
class PHPMailer
{
    /**
     * The PHPMailer Version number.
     * @var string
     */
    public $Version = '5.2.17';
    /**
     * Email priority.
     * Options: null (default), 1 = High, 3 = Normal, 5 = low.
     * When null, the header is not set at all.
     * @var integer
     */
    public $Priority = null;
    /**
     * The character set of the message.
     * @var string
     */
    public $CharSet = 'iso-8859-1';
    /**
     * The MIME Content-type of the message.
     * @var string
     */
    public $ContentType = 'text/plain';
    /**
     * The message encoding.
     * Options: "8bit", "7bit", "binary", "base64", and "quoted-printable".
     * @var string
     */
    public $Encoding = '8bit';
    /**
     * Holds the most recent mailer error message.
     * @var string
     */
    public $ErrorInfo = '';
    /**
     * The From email address for the message.
     * @var string
     */
    public $From = 'root@localhost';
    /**
     * The From name of the message.
     * @var string
     */
    public $FromName = 'Root User';
    /**
     * The Sender email (Return-Path) of the message.
     * If not empty, will be sent via -f to sendmail or as 'MAIL FROM' in smtp mode.
     * @var string
     */
    public $Sender = '';
    /**
     * The Return-Path of the message.
     * If empty, it will be set to either From or Sender.
     * @var string
     * @deprecated Email senders should never set a return-path header;
     * it's the receiver's job (RFC5321 section 4.4), so this no longer does anything.
     * @link https://tools.ietf.org/html/rfc5321#section-4.4 RFC5321 reference
     */
    public $ReturnPath = '';
    /**
     * The Subject of the message.
     * @var string
     */
    public $Subject = '';
    /**
     * An HTML or plain text message body.
     * If HTML then call isHTML(true).
     * @var string
     */
    public $Body = '';
    /**
     * The plain-text message body.
     * This body can be read by mail clients that do not have HTML email
     * capability such as mutt & Eudora.
     * Clients that can read HTML will view the normal Body.
     * @var string
     */
    public $AltBody = '';
    /**
     * An iCal message part body.
     * Only supported in simple alt or alt_inline message types
     * To generate iCal events, use the bundled extras/EasyPeasyICS.php class or iCalcreator
     * @link http://sprain.ch/blog/downloads/php-class-easypeasyics-create-ical-files-with-php/
     * @link http://kigkonsult.se/iCalcreator/
     * @var string
     */
    public $Ical = '';
    /**
     * The complete compiled MIME message body.
     * @access protected
     * @var string
     */
    protected $MIMEBody = '';
    /**
     * The complete compiled MIME message headers.
     * @var string
     * @access protected
     */
    protected $MIMEHeader = '';
    /**
     * Extra headers that createHeader() doesn't fold in.
     * @var string
     * @access protected
     */
    protected $mailHeader = '';
    /**
     * Word-wrap the message body to this number of chars.
     * Set to 0 to not wrap. A useful value here is 78, for RFC2822 section 2.1.1 compliance.
     * @var integer
     */
    public $WordWrap = 0;
    /**
     * Which method to use to send mail.
     * Options: "mail", "sendmail", or "smtp".
     * @var string
     */
    public $Mailer = 'mail';
    /**
     * The path to the sendmail program.
     * @var string
     */
    public $Sendmail = '/usr/sbin/sendmail';
    /**
     * Whether mail() uses a fully sendmail-compatible MTA.
     * One which supports sendmail's "-oi -f" options.
     * @var boolean
     */
    public $UseSendmailOptions = true;
    /**
     * Path to PHPMailer plugins.
     * Useful if the SMTP class is not in the PHP include path.
     * @var string
     * @deprecated Should not be needed now there is an autoloader.
     */
    public $PluginDir = '';
    /**
     * The email address that a reading confirmation should be sent to, also known as read receipt.
     * @var string
     */
    public $ConfirmReadingTo = '';
    /**
     * The hostname to use in the Message-ID header and as default HELO string.
     * If empty, PHPMailer attempts to find one with, in order,
     * $_SERVER['SERVER_NAME'], gethostname(), php_uname('n'), or the value
     * 'localhost.localdomain'.
     * @var string
     */
    public $Hostname = '';
    /**
     * An ID to be used in the Message-ID header.
     * If empty, a unique id will be generated.
     * You can set your own, but it must be in the format "<id@domain>",
     * as defined in RFC5322 section 3.6.4 or it will be ignored.
     * @see https://tools.ietf.org/html/rfc5322#section-3.6.4
     * @var string
     */
    public $MessageID = '';
    /**
     * The message Date to be used in the Date header.
     * If empty, the current date will be added.
     * @var string
     */
    public $MessageDate = '';
    /**
     * SMTP hosts.
     * Either a single hostname or multiple semicolon-delimited hostnames.
     * You can also specify a different port
     * for each host by using this format: [hostname:port]
     * (e.g. "smtp1.example.com:25;smtp2.example.com").
     * You can also specify encryption type, for example:
     * (e.g. "tls://smtp1.example.com:587;ssl://smtp2.example.com:465").
     * Hosts will be tried in order.
     * @var string
     */
    public $Host = 'localhost';
    /**
     * The default SMTP server port.
     * @var integer
     * @TODO Why is this needed when the SMTP class takes care of it?
     */
    public $Port = 25;
    /**
     * The SMTP HELO of the message.
     * Default is $Hostname. If $Hostname is empty, PHPMailer attempts to find
     * one with the same method described above for $Hostname.
     * @var string
     * @see PHPMailer::$Hostname
     */
    public $Helo = '';
    /**
     * What kind of encryption to use on the SMTP connection.
     * Options: '', 'ssl' or 'tls'
     * @var string
     */
    public $SMTPSecure = '';
    /**
     * Whether to enable TLS encryption automatically if a server supports it,
     * even if `SMTPSecure` is not set to 'tls'.
     * Be aware that in PHP >= 5.6 this requires that the server's certificates are valid.
     * @var boolean
     */
    public $SMTPAutoTLS = true;
    /**
     * Whether to use SMTP authentication.
     * Uses the Username and Password properties.
     * @var boolean
     * @see PHPMailer::$Username
     * @see PHPMailer::$Password
     */
    public $SMTPAuth = false;
    /**
     * Options array passed to stream_context_create when connecting via SMTP.
     * @var array
     */
    public $SMTPOptions = array();
    /**
     * SMTP username.
     * @var string
     */
    public $Username = '';
    /**
     * SMTP password.
     * @var string
     */
    public $Password = '';
    /**
     * SMTP auth type.
     * Options are CRAM-MD5, LOGIN, PLAIN, NTLM, XOAUTH2, attempted in that order if not specified
     * @var string
     */
    public $AuthType = '';
    /**
     * SMTP realm.
     * Used for NTLM auth
     * @var string
     */
    public $Realm = '';
    /**
     * SMTP workstation.
     * Used for NTLM auth
     * @var string
     */
    public $Workstation = '';
    /**
     * The SMTP server timeout in seconds.
     * Default of 5 minutes (300sec) is from RFC2821 section 4.5.3.2
     * @var integer
     */
    public $Timeout = 300;
    /**
     * SMTP class debug output mode.
     * Debug output level.
     * Options:
     * * `0` No output
     * * `1` Commands
     * * `2` Data and commands
     * * `3` As 2 plus connection status
     * * `4` Low-level data output
     * @var integer
     * @see SMTP::$do_debug
     */
    public $SMTPDebug = 0;
    /**
     * How to handle debug output.
     * Options:
     * * `echo` Output plain-text as-is, appropriate for CLI
     * * `html` Output escaped, line breaks converted to `<br>`, appropriate for browser output
     * * `error_log` Output to error log as configured in php.ini
     *
     * Alternatively, you can provide a callable expecting two params: a message string and the debug level:
     * <code>
     * $mail->Debugoutput = function($str, $level) {echo "debug level $level; message: $str";};
     * </code>
     * @var string|callable
     * @see SMTP::$Debugoutput
     */
    public $Debugoutput = 'echo';
    /**
     * Whether to keep SMTP connection open after each message.
     * If this is set to true then to close the connection
     * requires an explicit call to smtpClose().
     * @var boolean
     */
    public $SMTPKeepAlive = false;
    /**
     * Whether to split multiple to addresses into multiple messages
     * or send them all in one message.
     * Only supported in `mail` and `sendmail` transports, not in SMTP.
     * @var boolean
     */
    public $SingleTo = false;
    /**
     * Storage for addresses when SingleTo is enabled.
     * @var array
     * @TODO This should really not be public
     */
    public $SingleToArray = array();
    /**
     * Whether to generate VERP addresses on send.
     * Only applicable when sending via SMTP.
     * @link https://en.wikipedia.org/wiki/Variable_envelope_return_path
     * @link http://www.postfix.org/VERP_README.html Postfix VERP info
     * @var boolean
     */
    public $do_verp = false;
    /**
     * Whether to allow sending messages with an empty body.
     * @var boolean
     */
    public $AllowEmpty = false;
    /**
     * The default line ending.
     * @note The default remains "\n". We force CRLF where we know
     *        it must be used via self::CRLF.
     * @var string
     */
    public $LE = "\n";
    /**
     * DKIM selector.
     * @var string
     */
    public $DKIM_selector = '';
    /**
     * DKIM Identity.
     * Usually the email address used as the source of the email.
     * @var string
     */
    public $DKIM_identity = '';
    /**
     * DKIM passphrase.
     * Used if your key is encrypted.
     * @var string
     */
    public $DKIM_passphrase = '';
    /**
     * DKIM signing domain name.
     * @example 'example.com'
     * @var string
     */
    public $DKIM_domain = '';
    /**
     * DKIM private key file path.
     * @var string
     */
    public $DKIM_private = '';
    /**
     * DKIM private key string.
     * If set, takes precedence over `$DKIM_private`.
     * @var string
     */
    public $DKIM_private_string = '';
    /**
     * Callback Action function name.
     *
     * The function that handles the result of the send email action.
     * It is called out by send() for each email sent.
     *
     * Value can be any php callable: http://www.php.net/is_callable
     *
     * Parameters:
     *   boolean $result        result of the send action
     *   string  $to            email address of the recipient
     *   string  $cc            cc email addresses
     *   string  $bcc           bcc email addresses
     *   string  $subject       the subject
     *   string  $body          the email body
     *   string  $from          email address of sender
     * @var string
     */
    public $action_function = '';
    /**
     * What to put in the X-Mailer header.
     * Options: An empty string for PHPMailer default, whitespace for none, or a string to use
     * @var string
     */
    public $XMailer = '';
    /**
     * Which validator to use by default when validating email addresses.
     * May be a callable to inject your own validator, but there are several built-in validators.
     * @see PHPMailer::validateAddress()
     * @var string|callable
     * @static
     */
    public static $validator = 'auto';
    /**
     * An instance of the SMTP sender class.
     * @var SMTP
     * @access protected
     */
    protected $smtp = null;
    /**
     * The array of 'to' names and addresses.
     * @var array
     * @access protected
     */
    protected $to = array();
    /**
     * The array of 'cc' names and addresses.
     * @var array
     * @access protected
     */
    protected $cc = array();
    /**
     * The array of 'bcc' names and addresses.
     * @var array
     * @access protected
     */
    protected $bcc = array();
    /**
     * The array of reply-to names and addresses.
     * @var array
     * @access protected
     */
    protected $ReplyTo = array();
    /**
     * An array of all kinds of addresses.
     * Includes all of $to, $cc, $bcc
     * @var array
     * @access protected
     * @see PHPMailer::$to @see PHPMailer::$cc @see PHPMailer::$bcc
     */
    protected $all_recipients = array();
    /**
     * An array of names and addresses queued for validation.
     * In send(), valid and non duplicate entries are moved to $all_recipients
     * and one of $to, $cc, or $bcc.
     * This array is used only for addresses with IDN.
     * @var array
     * @access protected
     * @see PHPMailer::$to @see PHPMailer::$cc @see PHPMailer::$bcc
     * @see PHPMailer::$all_recipients
     */
    protected $RecipientsQueue = array();
    /**
     * An array of reply-to names and addresses queued for validation.
     * In send(), valid and non duplicate entries are moved to $ReplyTo.
     * This array is used only for addresses with IDN.
     * @var array
     * @access protected
     * @see PHPMailer::$ReplyTo
     */
    protected $ReplyToQueue = array();
    /**
     * The array of attachments.
     * @var array
     * @access protected
     */
    protected $attachment = array();
    /**
     * The array of custom headers.
     * @var array
     * @access protected
     */
    protected $CustomHeader = array();
    /**
     * The most recent Message-ID (including angular brackets).
     * @var string
     * @access protected
     */
    protected $lastMessageID = '';
    /**
     * The message's MIME type.
     * @var string
     * @access protected
     */
    protected $message_type = '';
    /**
     * The array of MIME boundary strings.
     * @var array
     * @access protected
     */
    protected $boundary = array();
    /**
     * The array of available languages.
     * @var array
     * @access protected
     */
    protected $language = array();
    /**
     * The number of errors encountered.
     * @var integer
     * @access protected
     */
    protected $error_count = 0;
    /**
     * The S/MIME certificate file path.
     * @var string
     * @access protected
     */
    protected $sign_cert_file = '';
    /**
     * The S/MIME key file path.
     * @var string
     * @access protected
     */
    protected $sign_key_file = '';
    /**
     * The optional S/MIME extra certificates ("CA Chain") file path.
     * @var string
     * @access protected
     */
    protected $sign_extracerts_file = '';
    /**
     * The S/MIME password for the key.
     * Used only if the key is encrypted.
     * @var string
     * @access protected
     */
    protected $sign_key_pass = '';
    /**
     * Whether to throw exceptions for errors.
     * @var boolean
     * @access protected
     */
    protected $exceptions = false;
    /**
     * Unique ID used for message ID and boundaries.
     * @var string
     * @access protected
     */
    protected $uniqueid = '';
    /**
     * Error severity: message only, continue processing.
     */
    const STOP_MESSAGE = 0;
    /**
     * Error severity: message, likely ok to continue processing.
     */
    const STOP_CONTINUE = 1;
    /**
     * Error severity: message, plus full stop, critical error reached.
     */
    const STOP_CRITICAL = 2;
    /**
     * SMTP RFC standard line ending.
     */
    const CRLF = "\r\n";
    /**
     * The maximum line length allowed by RFC 2822 section 2.1.1
     * @var integer
     */
    const MAX_LINE_LENGTH = 998;
    /**
     * Constructor.
     * @param boolean $exceptions Should we throw external exceptions?
     */
    public function __construct($exceptions = null)
    {
        if ($exceptions !== null) {
            $this->exceptions = (boolean)$exceptions;
        }
    }
    /**
     * Destructor.
     */
    public function __destruct()
    {
        //Close any open SMTP connection nicely
        $this->smtpClose();
    }
    /**
     * Call mail() in a safe_mode-aware fashion.
     * Also, unless sendmail_path points to sendmail (or something that
     * claims to be sendmail), don't pass params (not a perfect fix,
     * but it will do)
     * @param string $to To
     * @param string $subject Subject
     * @param string $body Message Body
     * @param string $header Additional Header(s)
     * @param string $params Params
     * @access private
     * @return boolean
     */
    private function mailPassthru($to, $subject, $body, $header, $params)
    {
        //Check overloading of mail function to avoid double-encoding
        if (ini_get('mbstring.func_overload') & 1) {
            $subject = $this->secureHeader($subject);
        } else {
            $subject = $this->encodeHeader($this->secureHeader($subject));
        }
        //Can't use additional_parameters in safe_mode
        //@link http://php.net/manual/en/function.mail.php
        if (ini_get('safe_mode') or !$this->UseSendmailOptions or is_null($params)) {
            $result = @mail($to, $subject, $body, $header);
        } else {
            $result = @mail($to, $subject, $body, $header, $params);
        }
        return $result;
    }
    /**
     * Output debugging info via user-defined method.
     * Only generates output if SMTP debug output is enabled (@see SMTP::$do_debug).
     * @see PHPMailer::$Debugoutput
     * @see PHPMailer::$SMTPDebug
     * @param string $str
     */
    protected function edebug($str)
    {
        if ($this->SMTPDebug <= 0) {
            return;
        }
        //Avoid clash with built-in function names
        if (!in_array($this->Debugoutput, array('error_log', 'html', 'echo')) and is_callable($this->Debugoutput)) {
            call_user_func($this->Debugoutput, $str, $this->SMTPDebug);
            return;
        }
        switch ($this->Debugoutput) {
            case 'error_log':
                //Don't output, just log
                error_log($str);
                break;
            case 'html':
                //Cleans up output a bit for a better looking, HTML-safe output
                echo htmlentities(
                    preg_replace('/[\r\n]+/', '', $str),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "<br>\n";
                break;
            case 'echo':
            default:
                //Normalize line breaks
                $str = preg_replace('/\r\n?/ms', "\n", $str);
                echo gmdate('Y-m-d H:i:s') . "\t" . str_replace(
                    "\n",
                    "\n                   \t                  ",
                    trim($str)
                ) . "\n";
        }
    }
    /**
     * Sets message type to HTML or plain.
     * @param boolean $isHtml True for HTML mode.
     * @return void
     */
    public function isHTML($isHtml = true)
    {
        if ($isHtml) {
            $this->ContentType = 'text/html';
        } else {
            $this->ContentType = 'text/plain';
        }
    }
    /**
     * Send messages using SMTP.
     * @return void
     */
    public function isSMTP()
    {
        $this->Mailer = 'smtp';
    }
    /**
     * Send messages using PHP's mail() function.
     * @return void
     */
    public function isMail()
    {
        $this->Mailer = 'mail';
    }
    /**
     * Send messages using $Sendmail.
     * @return void
     */
    public function isSendmail()
    {
        $ini_sendmail_path = ini_get('sendmail_path');
        if (!stristr($ini_sendmail_path, 'sendmail')) {
            $this->Sendmail = '/usr/sbin/sendmail';
        } else {
            $this->Sendmail = $ini_sendmail_path;
        }
        $this->Mailer = 'sendmail';
    }
    /**
     * Send messages using qmail.
     * @return void
     */
    public function isQmail()
    {
        $ini_sendmail_path = ini_get('sendmail_path');
        if (!stristr($ini_sendmail_path, 'qmail')) {
            $this->Sendmail = '/var/qmail/bin/qmail-inject';
        } else {
            $this->Sendmail = $ini_sendmail_path;
        }
        $this->Mailer = 'qmail';
    }
    /**
     * Add a "To" address.
     * @param string $address The email address to send to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addAddress($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('to', $address, $name);
    }
    /**
     * Add a "CC" address.
     * @note: This function works with the SMTP mailer on win32, not with the "mail" mailer.
     * @param string $address The email address to send to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('cc', $address, $name);
    }
    /**
     * Add a "BCC" address.
     * @note: This function works with the SMTP mailer on win32, not with the "mail" mailer.
     * @param string $address The email address to send to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addBCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('bcc', $address, $name);
    }
    /**
     * Add a "Reply-To" address.
     * @param string $address The email address to reply to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addReplyTo($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('Reply-To', $address, $name);
    }
    /**
     * Add an address to one of the recipient arrays or to the ReplyTo array. Because PHPMailer
     * can't validate addresses with an IDN without knowing the PHPMailer::$CharSet (that can still
     * be modified after calling this function), addition of such addresses is delayed until send().
     * Addresses that have been added already return false, but do not throw exceptions.
     * @param string $kind One of 'to', 'cc', 'bcc', or 'ReplyTo'
     * @param string $address The email address to send, resp. to reply to
     * @param string $name
     * @throws phpmailerException
     * @return boolean true on success, false if address already used or invalid in some way
     * @access protected
     */
    protected function addOrEnqueueAnAddress($kind, $address, $name)
    {
        $address = trim($address);
        $name = trim(preg_replace('/[\r\n]+/', '', $name)); //Strip breaks and trim
        if (($pos = strrpos($address, '@')) === false) {
            // At-sign is misssing.
            $error_message = $this->lang('invalid_address') . " (addAnAddress $kind): $address";
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        $params = array($kind, $address, $name);
        // Enqueue addresses with IDN until we know the PHPMailer::$CharSet.
        if ($this->has8bitChars(substr($address, ++$pos)) and $this->idnSupported()) {
            if ($kind != 'Reply-To') {
                if (!array_key_exists($address, $this->RecipientsQueue)) {
                    $this->RecipientsQueue[$address] = $params;
                    return true;
                }
            } else {
                if (!array_key_exists($address, $this->ReplyToQueue)) {
                    $this->ReplyToQueue[$address] = $params;
                    return true;
                }
            }
            return false;
        }
        // Immediately add standard addresses without IDN.
        return call_user_func_array(array($this, 'addAnAddress'), $params);
    }
    /**
     * Add an address to one of the recipient arrays or to the ReplyTo array.
     * Addresses that have been added already return false, but do not throw exceptions.
     * @param string $kind One of 'to', 'cc', 'bcc', or 'ReplyTo'
     * @param string $address The email address to send, resp. to reply to
     * @param string $name
     * @throws phpmailerException
     * @return boolean true on success, false if address already used or invalid in some way
     * @access protected
     */
    protected function addAnAddress($kind, $address, $name = '')
    {
        if (!in_array($kind, array('to', 'cc', 'bcc', 'Reply-To'))) {
            $error_message = $this->lang('Invalid recipient kind: ') . $kind;
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        if (!$this->validateAddress($address)) {
            $error_message = $this->lang('invalid_address') . " (addAnAddress $kind): $address";
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        if ($kind != 'Reply-To') {
            if (!array_key_exists(strtolower($address), $this->all_recipients)) {
                array_push($this->$kind, array($address, $name));
                $this->all_recipients[strtolower($address)] = true;
                return true;
            }
        } else {
            if (!array_key_exists(strtolower($address), $this->ReplyTo)) {
                $this->ReplyTo[strtolower($address)] = array($address, $name);
                return true;
            }
        }
        return false;
    }
    /**
     * Parse and validate a string containing one or more RFC822-style comma-separated email addresses
     * of the form "display name <address>" into an array of name/address pairs.
     * Uses the imap_rfc822_parse_adrlist function if the IMAP extension is available.
     * Note that quotes in the name part are removed.
     * @param string $addrstr The address list string
     * @param bool $useimap Whether to use the IMAP extension to parse the list
     * @return array
     * @link http://www.andrew.cmu.edu/user/agreen1/testing/mrbs/web/Mail/RFC822.php A more careful implementation
     */
    public function parseAddresses($addrstr, $useimap = true)
    {
        $addresses = array();
        if ($useimap and function_exists('imap_rfc822_parse_adrlist')) {
            //Use this built-in parser if it's available
            $list = imap_rfc822_parse_adrlist($addrstr, '');
            foreach ($list as $address) {
                if ($address->host != '.SYNTAX-ERROR.') {
                    if ($this->validateAddress($address->mailbox . '@' . $address->host)) {
                        $addresses[] = array(
                            'name' => (property_exists($address, 'personal') ? $address->personal : ''),
                            'address' => $address->mailbox . '@' . $address->host
                        );
                    }
                }
            }
        } else {
            //Use this simpler parser
            $list = explode(',', $addrstr);
            foreach ($list as $address) {
                $address = trim($address);
                //Is there a separate name part?
                if (strpos($address, '<') === false) {
                    //No separate name, just use the whole thing
                    if ($this->validateAddress($address)) {
                        $addresses[] = array(
                            'name' => '',
                            'address' => $address
                        );
                    }
                } else {
                    list($name, $email) = explode('<', $address);
                    $email = trim(str_replace('>', '', $email));
                    if ($this->validateAddress($email)) {
                        $addresses[] = array(
                            'name' => trim(str_replace(array('"', "'"), '', $name)),
                            'address' => $email
                        );
                    }
                }
            }
        }
        return $addresses;
    }
    /**
     * Set the From and FromName properties.
     * @param string $address
     * @param string $name
     * @param boolean $auto Whether to also set the Sender address, defaults to true
     * @throws phpmailerException
     * @return boolean
     */
    public function setFrom($address, $name = '', $auto = true)
    {
        $address = trim($address);
        $name = trim(preg_replace('/[\r\n]+/', '', $name)); //Strip breaks and trim
        // Don't validate now addresses with IDN. Will be done in send().
        if (($pos = strrpos($address, '@')) === false or
            (!$this->has8bitChars(substr($address, ++$pos)) or !$this->idnSupported()) and
            !$this->validateAddress($address)) {
            $error_message = $this->lang('invalid_address') . " (setFrom) $address";
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        $this->From = $address;
        $this->FromName = $name;
        if ($auto) {
            if (empty($this->Sender)) {
                $this->Sender = $address;
            }
        }
        return true;
    }
    /**
     * Return the Message-ID header of the last email.
     * Technically this is the value from the last time the headers were created,
     * but it's also the message ID of the last sent message except in
     * pathological cases.
     * @return string
     */
    public function getLastMessageID()
    {
        return $this->lastMessageID;
    }
    /**
     * Check that a string looks like an email address.
     * @param string $address The email address to check
     * @param string|callable $patternselect A selector for the validation pattern to use :
     * * `auto` Pick best pattern automatically;
     * * `pcre8` Use the squiloople.com pattern, requires PCRE > 8.0, PHP >= 5.3.2, 5.2.14;
     * * `pcre` Use old PCRE implementation;
     * * `php` Use PHP built-in FILTER_VALIDATE_EMAIL;
     * * `html5` Use the pattern given by the HTML5 spec for 'email' type form input elements.
     * * `noregex` Don't use a regex: super fast, really dumb.
     * Alternatively you may pass in a callable to inject your own validator, for example:
     * PHPMailer::validateAddress('user@example.com', function($address) {
     *     return (strpos($address, '@') !== false);
     * });
     * You can also set the PHPMailer::$validator static to a callable, allowing built-in methods to use your validator.
     * @return boolean
     * @static
     * @access public
     */
    public static function validateAddress($address, $patternselect = null)
    {
        if (is_null($patternselect)) {
            $patternselect = self::$validator;
        }
        if (is_callable($patternselect)) {
            return call_user_func($patternselect, $address);
        }
        //Reject line breaks in addresses; it's valid RFC5322, but not RFC5321
        if (strpos($address, "\n") !== false or strpos($address, "\r") !== false) {
            return false;
        }
        if (!$patternselect or $patternselect == 'auto') {
            //Check this constant first so it works when extension_loaded() is disabled by safe mode
            //Constant was added in PHP 5.2.4
            if (defined('PCRE_VERSION')) {
                //This pattern can get stuck in a recursive loop in PCRE <= 8.0.2
                if (version_compare(PCRE_VERSION, '8.0.3') >= 0) {
                    $patternselect = 'pcre8';
                } else {
                    $patternselect = 'pcre';
                }
            } elseif (function_exists('extension_loaded') and extension_loaded('pcre')) {
                //Fall back to older PCRE
                $patternselect = 'pcre';
            } else {
                //Filter_var appeared in PHP 5.2.0 and does not require the PCRE extension
                if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
                    $patternselect = 'php';
                } else {
                    $patternselect = 'noregex';
                }
            }
        }
        switch ($patternselect) {
            case 'pcre8':
                /**
                 * Uses the same RFC5322 regex on which FILTER_VALIDATE_EMAIL is based, but allows dotless domains.
                 * @link http://squiloople.com/2009/12/20/email-address-validation/
                 * @copyright 2009-2010 Michael Rushton
                 * Feel free to use and redistribute this code. But please keep this copyright notice.
                 */
                return (boolean)preg_match(
                    '/^(?!(?>(?1)"?(?>\\\[ -~]|[^"])"?(?1)){255,})(?!(?>(?1)"?(?>\\\[ -~]|[^"])"?(?1)){65,}@)' .
                    '((?>(?>(?>((?>(?>(?>\x0D\x0A)?[\t ])+|(?>[\t ]*\x0D\x0A)?[\t ]+)?)(\((?>(?2)' .
                    '(?>[\x01-\x08\x0B\x0C\x0E-\'*-\[\]-\x7F]|\\\[\x00-\x7F]|(?3)))*(?2)\)))+(?2))|(?2))?)' .
                    '([!#-\'*+\/-9=?^-~-]+|"(?>(?2)(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\x7F]))*' .
                    '(?2)")(?>(?1)\.(?1)(?4))*(?1)@(?!(?1)[a-z0-9-]{64,})(?1)(?>([a-z0-9](?>[a-z0-9-]*[a-z0-9])?)' .
                    '(?>(?1)\.(?!(?1)[a-z0-9-]{64,})(?1)(?5)){0,126}|\[(?:(?>IPv6:(?>([a-f0-9]{1,4})(?>:(?6)){7}' .
                    '|(?!(?:.*[a-f0-9][:\]]){8,})((?6)(?>:(?6)){0,6})?::(?7)?))|(?>(?>IPv6:(?>(?6)(?>:(?6)){5}:' .
                    '|(?!(?:.*[a-f0-9]:){6,})(?8)?::(?>((?6)(?>:(?6)){0,4}):)?))?(25[0-5]|2[0-4][0-9]|1[0-9]{2}' .
                    '|[1-9]?[0-9])(?>\.(?9)){3}))\])(?1)$/isD',
                    $address
                );
            case 'pcre':
                //An older regex that doesn't need a recent PCRE
                return (boolean)preg_match(
                    '/^(?!(?>"?(?>\\\[ -~]|[^"])"?){255,})(?!(?>"?(?>\\\[ -~]|[^"])"?){65,}@)(?>' .
                    '[!#-\'*+\/-9=?^-~-]+|"(?>(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\xFF]))*")' .
                    '(?>\.(?>[!#-\'*+\/-9=?^-~-]+|"(?>(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\xFF]))*"))*' .
                    '@(?>(?![a-z0-9-]{64,})(?>[a-z0-9](?>[a-z0-9-]*[a-z0-9])?)(?>\.(?![a-z0-9-]{64,})' .
                    '(?>[a-z0-9](?>[a-z0-9-]*[a-z0-9])?)){0,126}|\[(?:(?>IPv6:(?>(?>[a-f0-9]{1,4})(?>:' .
                    '[a-f0-9]{1,4}){7}|(?!(?:.*[a-f0-9][:\]]){8,})(?>[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,6})?' .
                    '::(?>[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,6})?))|(?>(?>IPv6:(?>[a-f0-9]{1,4}(?>:' .
                    '[a-f0-9]{1,4}){5}:|(?!(?:.*[a-f0-9]:){6,})(?>[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,4})?' .
                    '::(?>(?:[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,4}):)?))?(?>25[0-5]|2[0-4][0-9]|1[0-9]{2}' .
                    '|[1-9]?[0-9])(?>\.(?>25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}))\])$/isD',
                    $address
                );
            case 'html5':
                /**
                 * This is the pattern used in the HTML5 spec for validation of 'email' type form input elements.
                 * @link http://www.whatwg.org/specs/web-apps/current-work/#e-mail-state-(type=email)
                 */
                return (boolean)preg_match(
                    '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}' .
                    '[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD',
                    $address
                );
            case 'noregex':
                //No PCRE! Do something _very_ approximate!
                //Check the address is 3 chars or longer and contains an @ that's not the first or last char
                return (strlen($address) >= 3
                    and strpos($address, '@') >= 1
                    and strpos($address, '@') != strlen($address) - 1);
            case 'php':
            default:
                return (boolean)filter_var($address, FILTER_VALIDATE_EMAIL);
        }
    }
    /**
     * Tells whether IDNs (Internationalized Domain Names) are supported or not. This requires the
     * "intl" and "mbstring" PHP extensions.
     * @return bool "true" if required functions for IDN support are present
     */
    public function idnSupported()
    {
        // @TODO: Write our own "idn_to_ascii" function for PHP <= 5.2.
        return function_exists('idn_to_ascii') and function_exists('mb_convert_encoding');
    }
    /**
     * Converts IDN in given email address to its ASCII form, also known as punycode, if possible.
     * Important: Address must be passed in same encoding as currently set in PHPMailer::$CharSet.
     * This function silently returns unmodified address if:
     * - No conversion is necessary (i.e. domain name is not an IDN, or is already in ASCII form)
     * - Conversion to punycode is impossible (e.g. required PHP functions are not available)
     *   or fails for any reason (e.g. domain has characters not allowed in an IDN)
     * @see PHPMailer::$CharSet
     * @param string $address The email address to convert
     * @return string The encoded address in ASCII form
     */
    public function punyencodeAddress($address)
    {
        // Verify we have required functions, CharSet, and at-sign.
        if ($this->idnSupported() and
            !empty($this->CharSet) and
            ($pos = strrpos($address, '@')) !== false) {
            $domain = substr($address, ++$pos);
            // Verify CharSet string is a valid one, and domain properly encoded in this CharSet.
            if ($this->has8bitChars($domain) and @mb_check_encoding($domain, $this->CharSet)) {
                $domain = mb_convert_encoding($domain, 'UTF-8', $this->CharSet);
                if (($punycode = defined('INTL_IDNA_VARIANT_UTS46') ?
                    idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) :
                    idn_to_ascii($domain)) !== false) {
                    return substr($address, 0, $pos) . $punycode;
                }
            }
        }
        return $address;
    }
    /**
     * Create a message and send it.
     * Uses the sending method specified by $Mailer.
     * @throws phpmailerException
     * @return boolean false on error - See the ErrorInfo property for details of the error.
     */
    public function send()
    {
        try {
            if (!$this->preSend()) {
                return false;
            }
            return $this->postSend();
        } catch (phpmailerException $exc) {
            $this->mailHeader = '';
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
    }
    /**
     * Prepare a message for sending.
     * @throws phpmailerException
     * @return boolean
     */
    public function preSend()
    {
        try {
            $this->error_count = 0; // Reset errors
            $this->mailHeader = '';
            // Dequeue recipient and Reply-To addresses with IDN
            foreach (array_merge($this->RecipientsQueue, $this->ReplyToQueue) as $params) {
                $params[1] = $this->punyencodeAddress($params[1]);
                call_user_func_array(array($this, 'addAnAddress'), $params);
            }
            if ((count($this->to) + count($this->cc) + count($this->bcc)) < 1) {
                throw new phpmailerException($this->lang('provide_address'), self::STOP_CRITICAL);
            }
            // Validate From, Sender, and ConfirmReadingTo addresses
            foreach (array('From', 'Sender', 'ConfirmReadingTo') as $address_kind) {
                $this->$address_kind = trim($this->$address_kind);
                if (empty($this->$address_kind)) {
                    continue;
                }
                $this->$address_kind = $this->punyencodeAddress($this->$address_kind);
                if (!$this->validateAddress($this->$address_kind)) {
                    $error_message = $this->lang('invalid_address') . ' (punyEncode) ' . $this->$address_kind;
                    $this->setError($error_message);
                    $this->edebug($error_message);
                    if ($this->exceptions) {
                        throw new phpmailerException($error_message);
                    }
                    return false;
                }
            }
            // Set whether the message is multipart/alternative
            if ($this->alternativeExists()) {
                $this->ContentType = 'multipart/alternative';
            }
            $this->setMessageType();
            // Refuse to send an empty message unless we are specifically allowing it
            if (!$this->AllowEmpty and empty($this->Body)) {
                throw new phpmailerException($this->lang('empty_message'), self::STOP_CRITICAL);
            }
            // Create body before headers in case body makes changes to headers (e.g. altering transfer encoding)
            $this->MIMEHeader = '';
            $this->MIMEBody = $this->createBody();
            // createBody may have added some headers, so retain them
            $tempheaders = $this->MIMEHeader;
            $this->MIMEHeader = $this->createHeader();
            $this->MIMEHeader .= $tempheaders;
            // To capture the complete message when using mail(), create
            // an extra header list which createHeader() doesn't fold in
            if ($this->Mailer == 'mail') {
                if (count($this->to) > 0) {
                    $this->mailHeader .= $this->addrAppend('To', $this->to);
                } else {
                    $this->mailHeader .= $this->headerLine('To', 'undisclosed-recipients:;');
                }
                $this->mailHeader .= $this->headerLine(
                    'Subject',
                    $this->encodeHeader($this->secureHeader(trim($this->Subject)))
                );
            }
            // Sign with DKIM if enabled
            if (!empty($this->DKIM_domain)
                && !empty($this->DKIM_selector)
                && (!empty($this->DKIM_private_string)
                   || (!empty($this->DKIM_private) && file_exists($this->DKIM_private))
                )
            ) {
                $header_dkim = $this->DKIM_Add(
                    $this->MIMEHeader . $this->mailHeader,
                    $this->encodeHeader($this->secureHeader($this->Subject)),
                    $this->MIMEBody
                );
                $this->MIMEHeader = rtrim($this->MIMEHeader, "\r\n ") . self::CRLF .
                    str_replace("\r\n", "\n", $header_dkim) . self::CRLF;
            }
            return true;
        } catch (phpmailerException $exc) {
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
    }
    /**
     * Actually send a message.
     * Send the email via the selected mechanism
     * @throws phpmailerException
     * @return boolean
     */
    public function postSend()
    {
        try {
            // Choose the mailer and send through it
            switch ($this->Mailer) {
                case 'sendmail':
                case 'qmail':
                    return $this->sendmailSend($this->MIMEHeader, $this->MIMEBody);
                case 'smtp':
                    return $this->smtpSend($this->MIMEHeader, $this->MIMEBody);
                case 'mail':
                    return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
                default:
                    $sendMethod = $this->Mailer.'Send';
                    if (method_exists($this, $sendMethod)) {
                        return $this->$sendMethod($this->MIMEHeader, $this->MIMEBody);
                    }
                    return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
            }
        } catch (phpmailerException $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
        }
        return false;
    }
    /**
     * Send mail using the $Sendmail program.
     * @param string $header The message headers
     * @param string $body The message body
     * @see PHPMailer::$Sendmail
     * @throws phpmailerException
     * @access protected
     * @return boolean
     */
    protected function sendmailSend($header, $body)
    {
        if ($this->Sender != '') {
            if ($this->Mailer == 'qmail') {
                $sendmail = sprintf('%s -f%s', escapeshellcmd($this->Sendmail), escapeshellarg($this->Sender));
            } else {
                $sendmail = sprintf('%s -oi -f%s -t', escapeshellcmd($this->Sendmail), escapeshellarg($this->Sender));
            }
        } else {
            if ($this->Mailer == 'qmail') {
                $sendmail = sprintf('%s', escapeshellcmd($this->Sendmail));
            } else {
                $sendmail = sprintf('%s -oi -t', escapeshellcmd($this->Sendmail));
            }
        }
        if ($this->SingleTo) {
            foreach ($this->SingleToArray as $toAddr) {
                if (!@$mail = popen($sendmail, 'w')) {
                    throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
                }
                fputs($mail, 'To: ' . $toAddr . "\n");
                fputs($mail, $header);
                fputs($mail, $body);
                $result = pclose($mail);
                $this->doCallback(
                    ($result == 0),
                    array($toAddr),
                    $this->cc,
                    $this->bcc,
                    $this->Subject,
                    $body,
                    $this->From
                );
                if ($result != 0) {
                    throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
                }
            }
        } else {
            if (!@$mail = popen($sendmail, 'w')) {
                throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
            }
            fputs($mail, $header);
            fputs($mail, $body);
            $result = pclose($mail);
            $this->doCallback(
                ($result == 0),
                $this->to,
                $this->cc,
                $this->bcc,
                $this->Subject,
                $body,
                $this->From
            );
            if ($result != 0) {
                throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
            }
        }
        return true;
    }
    /**
     * Send mail using the PHP mail() function.
     * @param string $header The message headers
     * @param string $body The message body
     * @link http://www.php.net/manual/en/book.mail.php
     * @throws phpmailerException
     * @access protected
     * @return boolean
     */
    protected function mailSend($header, $body)
    {
        $toArr = array();
        foreach ($this->to as $toaddr) {
            $toArr[] = $this->addrFormat($toaddr);
        }
        $to = implode(', ', $toArr);
        $params = null;
        //This sets the SMTP envelope sender which gets turned into a return-path header by the receiver
        if (!empty($this->Sender)) {
            $params = sprintf('-f%s', $this->Sender);
        }
        if ($this->Sender != '' and !ini_get('safe_mode')) {
            $old_from = ini_get('sendmail_from');
            ini_set('sendmail_from', $this->Sender);
        }
        $result = false;
        if ($this->SingleTo and count($toArr) > 1) {
            foreach ($toArr as $toAddr) {
                $result = $this->mailPassthru($toAddr, $this->Subject, $body, $header, $params);
                $this->doCallback($result, array($toAddr), $this->cc, $this->bcc, $this->Subject, $body, $this->From);
            }
        } else {
            $result = $this->mailPassthru($to, $this->Subject, $body, $header, $params);
            $this->doCallback($result, $this->to, $this->cc, $this->bcc, $this->Subject, $body, $this->From);
        }
        if (isset($old_from)) {
            ini_set('sendmail_from', $old_from);
        }
        if (!$result) {
            throw new phpmailerException($this->lang('instantiate'), self::STOP_CRITICAL);
        }
        return true;
    }
    /**
     * Get an instance to use for SMTP operations.
     * Override this function to load your own SMTP implementation
     * @return SMTP
     */
    public function getSMTPInstance()
    {
        if (!is_object($this->smtp)) {
            $this->smtp = new SMTP;
        }
        return $this->smtp;
    }
    /**
     * Send mail via SMTP.
     * Returns false if there is a bad MAIL FROM, RCPT, or DATA input.
     * Uses the PHPMailerSMTP class by default.
     * @see PHPMailer::getSMTPInstance() to use a different class.
     * @param string $header The message headers
     * @param string $body The message body
     * @throws phpmailerException
     * @uses SMTP
     * @access protected
     * @return boolean
     */
    protected function smtpSend($header, $body)
    {
        $bad_rcpt = array();
        if (!$this->smtpConnect($this->SMTPOptions)) {
            throw new phpmailerException($this->lang('smtp_connect_failed'), self::STOP_CRITICAL);
        }
        if ('' == $this->Sender) {
            $smtp_from = $this->From;
        } else {
            $smtp_from = $this->Sender;
        }
        if (!$this->smtp->mail($smtp_from)) {
            $this->setError($this->lang('from_failed') . $smtp_from . ' : ' . implode(',', $this->smtp->getError()));
            throw new phpmailerException($this->ErrorInfo, self::STOP_CRITICAL);
        }
        // Attempt to send to all recipients
        foreach (array($this->to, $this->cc, $this->bcc) as $togroup) {
            foreach ($togroup as $to) {
                if (!$this->smtp->recipient($to[0])) {
                    $error = $this->smtp->getError();
                    $bad_rcpt[] = array('to' => $to[0], 'error' => $error['detail']);
                    $isSent = false;
                } else {
                    $isSent = true;
                }
                $this->doCallback($isSent, array($to[0]), array(), array(), $this->Subject, $body, $this->From);
            }
        }
        // Only send the DATA command if we have viable recipients
        if ((count($this->all_recipients) > count($bad_rcpt)) and !$this->smtp->data($header . $body)) {
            throw new phpmailerException($this->lang('data_not_accepted'), self::STOP_CRITICAL);
        }
        if ($this->SMTPKeepAlive) {
            $this->smtp->reset();
        } else {
            $this->smtp->quit();
            $this->smtp->close();
        }
        //Create error message for any bad addresses
        if (count($bad_rcpt) > 0) {
            $errstr = '';
            foreach ($bad_rcpt as $bad) {
                $errstr .= $bad['to'] . ': ' . $bad['error'];
            }
            throw new phpmailerException(
                $this->lang('recipients_failed') . $errstr,
                self::STOP_CONTINUE
            );
        }
        return true;
    }
    /**
     * Initiate a connection to an SMTP server.
     * Returns false if the operation failed.
     * @param array $options An array of options compatible with stream_context_create()
     * @uses SMTP
     * @access public
     * @throws phpmailerException
     * @return boolean
     */
    public function smtpConnect($options = null)
    {
        if (is_null($this->smtp)) {
            $this->smtp = $this->getSMTPInstance();
        }
        //If no options are provided, use whatever is set in the instance
        if (is_null($options)) {
            $options = $this->SMTPOptions;
        }
        // Already connected?
        if ($this->smtp->connected()) {
            return true;
        }
        $this->smtp->setTimeout($this->Timeout);
        $this->smtp->setDebugLevel($this->SMTPDebug);
        $this->smtp->setDebugOutput($this->Debugoutput);
        $this->smtp->setVerp($this->do_verp);
        $hosts = explode(';', $this->Host);
        $lastexception = null;
        foreach ($hosts as $hostentry) {
            $hostinfo = array();
            if (!preg_match('/^((ssl|tls):\/\/)*([a-zA-Z0-9:\[\]\.-]*):?([0-9]*)$/', trim($hostentry), $hostinfo)){
                // Not a valid host entry
                continue;
            }
            // $hostinfo[2]: optional ssl or tls prefix
            // $hostinfo[3]: the hostname
            // $hostinfo[4]: optional port number
            // The host string prefix can temporarily override the current setting for SMTPSecure
            // If it's not specified, the default value is used
            $prefix = '';
            $secure = $this->SMTPSecure;
            $tls = ($this->SMTPSecure == 'tls');
            if ('ssl' == $hostinfo[2] or ('' == $hostinfo[2] and 'ssl' == $this->SMTPSecure)) {
                $prefix = 'ssl://';
                $tls = false; // Can't have SSL and TLS at the same time
                $secure = 'ssl';
            } elseif ($hostinfo[2] == 'tls') {
                $tls = true;
                // tls doesn't use a prefix
                $secure = 'tls';
            }
            //Do we need the OpenSSL extension?
            $sslext = defined('OPENSSL_ALGO_SHA1');
            if ('tls' === $secure or 'ssl' === $secure) {
                //Check for an OpenSSL constant rather than using extension_loaded, which is sometimes disabled
                if (!$sslext) {
                    throw new phpmailerException($this->lang('extension_missing').'openssl', self::STOP_CRITICAL);
                }
            }
            $host = $hostinfo[3];
            $port = $this->Port;
            $tport = (integer)$hostinfo[4];
            if ($tport > 0 and $tport < 65536) {
                $port = $tport;
            }
            if ($this->smtp->connect($prefix . $host, $port, $this->Timeout, $options)) {
                try {
                    if ($this->Helo) {
                        $hello = $this->Helo;
                    } else {
                        $hello = $this->serverHostname();
                    }
                    $this->smtp->hello($hello);
                    //Automatically enable TLS encryption if:
                    // * it's not disabled
                    // * we have openssl extension
                    // * we are not already using SSL
                    // * the server offers STARTTLS
                    if ($this->SMTPAutoTLS and $sslext and $secure != 'ssl' and $this->smtp->getServerExt('STARTTLS')) {
                        $tls = true;
                    }
                    if ($tls) {
                        if (!$this->smtp->startTLS()) {
                            throw new phpmailerException($this->lang('connect_host'));
                        }
                        // We must resend EHLO after TLS negotiation
                        $this->smtp->hello($hello);
                    }
                    if ($this->SMTPAuth) {
                        if (!$this->smtp->authenticate(
                            $this->Username,
                            $this->Password,
                            $this->AuthType,
                            $this->Realm,
                            $this->Workstation
                        )
                        ) {
                            throw new phpmailerException($this->lang('authenticate'));
                        }
                    }
                    return true;
                } catch (phpmailerException $exc) {
                    $lastexception = $exc;
                    $this->edebug($exc->getMessage());
                    // We must have connected, but then failed TLS or Auth, so close connection nicely
                    $this->smtp->quit();
                }
            }
        }
        // If we get here, all connection attempts have failed, so close connection hard
        $this->smtp->close();
        // As we've caught all exceptions, just report whatever the last one was
        if ($this->exceptions and !is_null($lastexception)) {
            throw $lastexception;
        }
        return false;
    }
    /**
     * Close the active SMTP session if one exists.
     * @return void
     */
    public function smtpClose()
    {
        if (is_a($this->smtp, 'SMTP')) {
            if ($this->smtp->connected()) {
                $this->smtp->quit();
                $this->smtp->close();
            }
        }
    }
    /**
     * Set the language for error messages.
     * Returns false if it cannot load the language file.
     * The default language is English.
     * @param string $langcode ISO 639-1 2-character language code (e.g. French is "fr")
     * @param string $lang_path Path to the language file directory, with trailing separator (slash)
     * @return boolean
     * @access public
     */
    public function setLanguage($langcode = 'en', $lang_path = '')
    {
        // Backwards compatibility for renamed language codes
        $renamed_langcodes = array(
            'br' => 'pt_br',
            'cz' => 'cs',
            'dk' => 'da',
            'no' => 'nb',
            'se' => 'sv',
        );
        if (isset($renamed_langcodes[$langcode])) {
            $langcode = $renamed_langcodes[$langcode];
        }
        // Define full set of translatable strings in English
        $PHPMAILER_LANG = array(
            'authenticate' => 'SMTP Error: Could not authenticate.',
            'connect_host' => 'SMTP Error: Could not connect to SMTP host.',
            'data_not_accepted' => 'SMTP Error: data not accepted.',
            'empty_message' => 'Message body empty',
            'encoding' => 'Unknown encoding: ',
            'execute' => 'Could not execute: ',
            'file_access' => 'Could not access file: ',
            'file_open' => 'File Error: Could not open file: ',
            'from_failed' => 'The following From address failed: ',
            'instantiate' => 'Could not instantiate mail function.',
            'invalid_address' => 'Invalid address: ',
            'mailer_not_supported' => ' mailer is not supported.',
            'provide_address' => 'You must provide at least one recipient email address.',
            'recipients_failed' => 'SMTP Error: The following recipients failed: ',
            'signing' => 'Signing Error: ',
            'smtp_connect_failed' => 'SMTP connect() failed.',
            'smtp_error' => 'SMTP server error: ',
            'variable_set' => 'Cannot set or reset variable: ',
            'extension_missing' => 'Extension missing: '
        );
        if (empty($lang_path)) {
            // Calculate an absolute path so it can work if CWD is not here
            $lang_path = dirname(__FILE__). DIRECTORY_SEPARATOR . 'language'. DIRECTORY_SEPARATOR;
        }
        //Validate $langcode
        if (!preg_match('/^[a-z]{2}(?:_[a-zA-Z]{2})?$/', $langcode)) {
            $langcode = 'en';
        }
        $foundlang = true;
        $lang_file = $lang_path . 'phpmailer.lang-' . $langcode . '.php';
        // There is no English translation file
        if ($langcode != 'en') {
            // Make sure language file path is readable
            if (!is_readable($lang_file)) {
                $foundlang = false;
            } else {
                // Overwrite language-specific strings.
                // This way we'll never have missing translation keys.
                $foundlang = include $lang_file;
            }
        }
        $this->language = $PHPMAILER_LANG;
        return (boolean)$foundlang; // Returns false if language not found
    }
    /**
     * Get the array of strings for the current language.
     * @return array
     */
    public function getTranslations()
    {
        return $this->language;
    }
    /**
     * Create recipient headers.
     * @access public
     * @param string $type
     * @param array $addr An array of recipient,
     * where each recipient is a 2-element indexed array with element 0 containing an address
     * and element 1 containing a name, like:
     * array(array('joe@example.com', 'Joe User'), array('zoe@example.com', 'Zoe User'))
     * @return string
     */
    public function addrAppend($type, $addr)
    {
        $addresses = array();
        foreach ($addr as $address) {
            $addresses[] = $this->addrFormat($address);
        }
        return $type . ': ' . implode(', ', $addresses) . $this->LE;
    }
    /**
     * Format an address for use in a message header.
     * @access public
     * @param array $addr A 2-element indexed array, element 0 containing an address, element 1 containing a name
     *      like array('joe@example.com', 'Joe User')
     * @return string
     */
    public function addrFormat($addr)
    {
        if (empty($addr[1])) { // No name provided
            return $this->secureHeader($addr[0]);
        } else {
            return $this->encodeHeader($this->secureHeader($addr[1]), 'phrase') . ' <' . $this->secureHeader(
                $addr[0]
            ) . '>';
        }
    }
    /**
     * Word-wrap message.
     * For use with mailers that do not automatically perform wrapping
     * and for quoted-printable encoded messages.
     * Original written by philippe.
     * @param string $message The message to wrap
     * @param integer $length The line length to wrap to
     * @param boolean $qp_mode Whether to run in Quoted-Printable mode
     * @access public
     * @return string
     */
    public function wrapText($message, $length, $qp_mode = false)
    {
        if ($qp_mode) {
            $soft_break = sprintf(' =%s', $this->LE);
        } else {
            $soft_break = $this->LE;
        }
        // If utf-8 encoding is used, we will need to make sure we don't
        // split multibyte characters when we wrap
        $is_utf8 = (strtolower($this->CharSet) == 'utf-8');
        $lelen = strlen($this->LE);
        $crlflen = strlen(self::CRLF);
        $message = $this->fixEOL($message);
        //Remove a trailing line break
        if (substr($message, -$lelen) == $this->LE) {
            $message = substr($message, 0, -$lelen);
        }
        //Split message into lines
        $lines = explode($this->LE, $message);
        //Message will be rebuilt in here
        $message = '';
        foreach ($lines as $line) {
            $words = explode(' ', $line);
            $buf = '';
            $firstword = true;
            foreach ($words as $word) {
                if ($qp_mode and (strlen($word) > $length)) {
                    $space_left = $length - strlen($buf) - $crlflen;
                    if (!$firstword) {
                        if ($space_left > 20) {
                            $len = $space_left;
                            if ($is_utf8) {
                                $len = $this->utf8CharBoundary($word, $len);
                            } elseif (substr($word, $len - 1, 1) == '=') {
                                $len--;
                            } elseif (substr($word, $len - 2, 1) == '=') {
                                $len -= 2;
                            }
                            $part = substr($word, 0, $len);
                            $word = substr($word, $len);
                            $buf .= ' ' . $part;
                            $message .= $buf . sprintf('=%s', self::CRLF);
                        } else {
                            $message .= $buf . $soft_break;
                        }
                        $buf = '';
                    }
                    while (strlen($word) > 0) {
                        if ($length <= 0) {
                            break;
                        }
                        $len = $length;
                        if ($is_utf8) {
                            $len = $this->utf8CharBoundary($word, $len);
                        } elseif (substr($word, $len - 1, 1) == '=') {
                            $len--;
                        } elseif (substr($word, $len - 2, 1) == '=') {
                            $len -= 2;
                        }
                        $part = substr($word, 0, $len);
                        $word = substr($word, $len);
                        if (strlen($word) > 0) {
                            $message .= $part . sprintf('=%s', self::CRLF);
                        } else {
                            $buf = $part;
                        }
                    }
                } else {
                    $buf_o = $buf;
                    if (!$firstword) {
                        $buf .= ' ';
                    }
                    $buf .= $word;
                    if (strlen($buf) > $length and $buf_o != '') {
                        $message .= $buf_o . $soft_break;
                        $buf = $word;
                    }
                }
                $firstword = false;
            }
            $message .= $buf . self::CRLF;
        }
        return $message;
    }
    /**
     * Find the last character boundary prior to $maxLength in a utf-8
     * quoted-printable encoded string.
     * Original written by Colin Brown.
     * @access public
     * @param string $encodedText utf-8 QP text
     * @param integer $maxLength Find the last character boundary prior to this length
     * @return integer
     */
    public function utf8CharBoundary($encodedText, $maxLength)
    {
        $foundSplitPos = false;
        $lookBack = 3;
        while (!$foundSplitPos) {
            $lastChunk = substr($encodedText, $maxLength - $lookBack, $lookBack);
            $encodedCharPos = strpos($lastChunk, '=');
            if (false !== $encodedCharPos) {
                // Found start of encoded character byte within $lookBack block.
                // Check the encoded byte value (the 2 chars after the '=')
                $hex = substr($encodedText, $maxLength - $lookBack + $encodedCharPos + 1, 2);
                $dec = hexdec($hex);
                if ($dec < 128) {
                    // Single byte character.
                    // If the encoded char was found at pos 0, it will fit
                    // otherwise reduce maxLength to start of the encoded char
                    if ($encodedCharPos > 0) {
                        $maxLength = $maxLength - ($lookBack - $encodedCharPos);
                    }
                    $foundSplitPos = true;
                } elseif ($dec >= 192) {
                    // First byte of a multi byte character
                    // Reduce maxLength to split at start of character
                    $maxLength = $maxLength - ($lookBack - $encodedCharPos);
                    $foundSplitPos = true;
                } elseif ($dec < 192) {
                    // Middle byte of a multi byte character, look further back
                    $lookBack += 3;
                }
            } else {
                // No encoded character found
                $foundSplitPos = true;
            }
        }
        return $maxLength;
    }
    /**
     * Apply word wrapping to the message body.
     * Wraps the message body to the number of chars set in the WordWrap property.
     * You should only do this to plain-text bodies as wrapping HTML tags may break them.
     * This is called automatically by createBody(), so you don't need to call it yourself.
     * @access public
     * @return void
     */
    public function setWordWrap()
    {
        if ($this->WordWrap < 1) {
            return;
        }
        switch ($this->message_type) {
            case 'alt':
            case 'alt_inline':
            case 'alt_attach':
            case 'alt_inline_attach':
                $this->AltBody = $this->wrapText($this->AltBody, $this->WordWrap);
                break;
            default:
                $this->Body = $this->wrapText($this->Body, $this->WordWrap);
                break;
        }
    }
    /**
     * Assemble message headers.
     * @access public
     * @return string The assembled headers
     */
    public function createHeader()
    {
        $result = '';
        if ($this->MessageDate == '') {
            $this->MessageDate = self::rfcDate();
        }
        $result .= $this->headerLine('Date', $this->MessageDate == '' ? self::rfcDate() : $this->MessageDate);
        // To be created automatically by mail()
        if ($this->SingleTo) {
            if ($this->Mailer != 'mail') {
                foreach ($this->to as $toaddr) {
                    $this->SingleToArray[] = $this->addrFormat($toaddr);
                }
            }
        } else {
            if (count($this->to) > 0) {
                if ($this->Mailer != 'mail') {
                    $result .= $this->addrAppend('To', $this->to);
                }
            } elseif (count($this->cc) == 0) {
                $result .= $this->headerLine('To', 'undisclosed-recipients:;');
            }
        }
        $result .= $this->addrAppend('From', array(array(trim($this->From), $this->FromName)));
        // sendmail and mail() extract Cc from the header before sending
        if (count($this->cc) > 0) {
            $result .= $this->addrAppend('Cc', $this->cc);
        }
        // sendmail and mail() extract Bcc from the header before sending
        if ((
                $this->Mailer == 'sendmail' or $this->Mailer == 'qmail' or $this->Mailer == 'mail'
            )
            and count($this->bcc) > 0
        ) {
            $result .= $this->addrAppend('Bcc', $this->bcc);
        }
        if (count($this->ReplyTo) > 0) {
            $result .= $this->addrAppend('Reply-To', $this->ReplyTo);
        }
        // mail() sets the subject itself
        if ($this->Mailer != 'mail') {
            $result .= $this->headerLine('Subject', $this->encodeHeader($this->secureHeader($this->Subject)));
        }
        // Only allow a custom message ID if it conforms to RFC 5322 section 3.6.4
        // https://tools.ietf.org/html/rfc5322#section-3.6.4
        if ('' != $this->MessageID and preg_match('/^<.*@.*>$/', $this->MessageID)) {
            $this->lastMessageID = $this->MessageID;
        } else {
            //$this->lastMessageID = sprintf('<%s@%s>', $this->uniqueid, $this->serverHostname());
	$fromDomain = 'localhost';
	if (!empty($this->From) && strpos($this->From, '@') !== false) {
		$fromParts = explode('@', $this->From, 2);
		if (!empty($fromParts[1])) $fromDomain = $fromParts[1];
	}
	$this->lastMessageID = '<'.md5($this->uniqueid).'@'.$fromDomain.'>';
        }
        $result .= $this->headerLine('Message-ID', $this->lastMessageID);
        if (!is_null($this->Priority)) {
            $result .= $this->headerLine('X-Priority', $this->Priority);
        }
        /* if ($this->XMailer == '') {
            $result .= $this->headerLine(
                'X-Mailer',
                //'PHPMailer ' . $this->Version . '(https://github.com/PHPMailer/PHPMailer)'
	'PHPMailer ' . $this->uniqueid
            );
        } else {
            $myXmailer = trim($this->XMailer);
            if ($myXmailer) {
                //$result .= $this->headerLine('X-Mailer', $myXmailer);
	$result .= $this->headerLine('X-Mailer', $myXmailer.$this->uniqueid);
            }
        }*/
        if ($this->ConfirmReadingTo != '') {
            $result .= $this->headerLine('Disposition-Notification-To', '<' . $this->ConfirmReadingTo . '>');
        }
        // Add custom headers
        foreach ($this->CustomHeader as $header) {
            $result .= $this->headerLine(
                trim($header[0]),
                $this->encodeHeader(trim($header[1]))
            );
        }
        if (!$this->sign_key_file) {
            $result .= $this->headerLine('MIME-Version', '1.0');
            $result .= $this->getMailMIME();
        }
        return $result;
    }
    /**
     * Get the message MIME type headers.
     * @access public
     * @return string
     */
    public function getMailMIME()
    {
        $result = '';
        $ismultipart = true;
        switch ($this->message_type) {
            case 'inline':
                $result .= $this->headerLine('Content-Type', 'multipart/related;');
                $result .= $this->textLine("\tboundary=\"" . $this->boundary[1] . '"');
                break;
            case 'attach':
            case 'inline_attach':
            case 'alt_attach':
            case 'alt_inline_attach':
                $result .= $this->headerLine('Content-Type', 'multipart/mixed;');
                $result .= $this->textLine("\tboundary=\"" . $this->boundary[1] . '"');
                break;
            case 'alt':
            case 'alt_inline':
                $result .= $this->headerLine('Content-Type', 'multipart/alternative;');
                $result .= $this->textLine("\tboundary=\"" . $this->boundary[1] . '"');
                break;
            default:
                // Catches case 'plain': and case '':
                $result .= $this->textLine('Content-Type: ' . $this->ContentType . '; charset=' . $this->CharSet);
                $ismultipart = false;
                break;
        }
        // RFC1341 part 5 says 7bit is assumed if not specified
        if ($this->Encoding != '7bit') {
            // RFC 2045 section 6.4 says multipart MIME parts may only use 7bit, 8bit or binary CTE
            if ($ismultipart) {
                if ($this->Encoding == '8bit') {
                    $result .= $this->headerLine('Content-Transfer-Encoding', '8bit');
                }
                // The only remaining alternatives are quoted-printable and base64, which are both 7bit compatible
            } else {
                $result .= $this->headerLine('Content-Transfer-Encoding', $this->Encoding);
            }
        }
        if ($this->Mailer != 'mail') {
            $result .= $this->LE;
        }
        return $result;
    }
    /**
     * Returns the whole MIME message.
     * Includes complete headers and body.
     * Only valid post preSend().
     * @see PHPMailer::preSend()
     * @access public
     * @return string
     */
    public function getSentMIMEMessage()
    {
        return rtrim($this->MIMEHeader . $this->mailHeader, "\n\r") . self::CRLF . self::CRLF . $this->MIMEBody;
    }
    /**
     * Create unique ID
     * @return string
     */
    protected function generateId() {
        return md5(uniqid(time()));
    }
    /**
     * Assemble the message body.
     * Returns an empty string on failure.
     * @access public
     * @throws phpmailerException
     * @return string The assembled message body
     */
    public function createBody()
    {
        $body = '';
        //Create unique IDs and preset boundaries
        $this->uniqueid = $this->generateId();
        $this->boundary[1] = '1' . $this->uniqueid;
        $this->boundary[2] = '2' . $this->uniqueid;
        $this->boundary[3] = '3' . $this->uniqueid;
        if ($this->sign_key_file) {
            $body .= $this->getMailMIME() . $this->LE;
        }
        $this->setWordWrap();
        $bodyEncoding = $this->Encoding;
        $bodyCharSet = $this->CharSet;
        //Can we do a 7-bit downgrade?
        if ($bodyEncoding == '8bit' and !$this->has8bitChars($this->Body)) {
            $bodyEncoding = '7bit';
            //All ISO 8859, Windows codepage and UTF-8 charsets are ascii compatible up to 7-bit
            $bodyCharSet = 'us-ascii';
        }
        //If lines are too long, and we're not already using an encoding that will shorten them,
        //change to quoted-printable transfer encoding for the body part only
        if ('base64' != $this->Encoding and self::hasLineLongerThanMax($this->Body)) {
            $bodyEncoding = 'quoted-printable';
        }
        $altBodyEncoding = $this->Encoding;
        $altBodyCharSet = $this->CharSet;
        //Can we do a 7-bit downgrade?
        if ($altBodyEncoding == '8bit' and !$this->has8bitChars($this->AltBody)) {
            $altBodyEncoding = '7bit';
            //All ISO 8859, Windows codepage and UTF-8 charsets are ascii compatible up to 7-bit
            $altBodyCharSet = 'us-ascii';
        }
        //If lines are too long, and we're not already using an encoding that will shorten them,
        //change to quoted-printable transfer encoding for the alt body part only
        if ('base64' != $altBodyEncoding and self::hasLineLongerThanMax($this->AltBody)) {
            $altBodyEncoding = 'quoted-printable';
        }
        //Use this as a preamble in all multipart message types
        $mimepre = "This is a multi-part message in MIME format." . $this->LE . $this->LE;
        switch ($this->message_type) {
            case 'inline':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[1]);
                break;
            case 'attach':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'inline_attach':
                $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/related;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'alt':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                if (!empty($this->Ical)) {
                    $body .= $this->getBoundary($this->boundary[1], '', 'text/calendar; method=REQUEST', '');
                    $body .= $this->encodeString($this->Ical, $this->Encoding);
                    $body .= $this->LE . $this->LE;
                }
                $body .= $this->endBoundary($this->boundary[1]);
                break;
            case 'alt_inline':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/related;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->endBoundary($this->boundary[1]);
                break;
            case 'alt_attach':
                $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/alternative;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->endBoundary($this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'alt_inline_attach':
                $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/alternative;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->textLine('--' . $this->boundary[2]);
                $body .= $this->headerLine('Content-Type', 'multipart/related;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[3] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[3], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[3]);
                $body .= $this->LE;
                $body .= $this->endBoundary($this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            default:
                // Catch case 'plain' and case '', applies to simple `text/plain` and `text/html` body content types
                //Reset the `Encoding` property in case we changed it for line length reasons
                $this->Encoding = $bodyEncoding;
                $body .= $this->encodeString($this->Body, $this->Encoding);
                break;
        }
        if ($this->isError()) {
            $body = '';
        } elseif ($this->sign_key_file) {
            try {
                if (!defined('PKCS7_TEXT')) {
                    throw new phpmailerException($this->lang('extension_missing') . 'openssl');
                }
                // @TODO would be nice to use php://temp streams here, but need to wrap for PHP < 5.1
                $file = tempnam(sys_get_temp_dir(), 'mail');
                if (false === file_put_contents($file, $body)) {
                    throw new phpmailerException($this->lang('signing') . ' Could not write temp file');
                }
                $signed = tempnam(sys_get_temp_dir(), 'signed');
                //Workaround for PHP bug https://bugs.php.net/bug.php?id=69197
                if (empty($this->sign_extracerts_file)) {
                    $sign = @openssl_pkcs7_sign(
                        $file,
                        $signed,
                        'file://' . realpath($this->sign_cert_file),
                        array('file://' . realpath($this->sign_key_file), $this->sign_key_pass),
                        null
                    );
                } else {
                    $sign = @openssl_pkcs7_sign(
                        $file,
                        $signed,
                        'file://' . realpath($this->sign_cert_file),
                        array('file://' . realpath($this->sign_key_file), $this->sign_key_pass),
                        null,
                        PKCS7_DETACHED,
                        $this->sign_extracerts_file
                    );
                }
                if ($sign) {
                    @unlink($file);
                    $body = file_get_contents($signed);
                    @unlink($signed);
                    //The message returned by openssl contains both headers and body, so need to split them up
                    $parts = explode("\n\n", $body, 2);
                    $this->MIMEHeader .= $parts[0] . $this->LE . $this->LE;
                    $body = $parts[1];
                } else {
                    @unlink($file);
                    @unlink($signed);
                    throw new phpmailerException($this->lang('signing') . openssl_error_string());
                }
            } catch (phpmailerException $exc) {
                $body = '';
                if ($this->exceptions) {
                    throw $exc;
                }
            }
        }
        return $body;
    }
    /**
     * Return the start of a message boundary.
     * @access protected
     * @param string $boundary
     * @param string $charSet
     * @param string $contentType
     * @param string $encoding
     * @return string
     */
    protected function getBoundary($boundary, $charSet, $contentType, $encoding)
    {
        $result = '';
        if ($charSet == '') {
            $charSet = $this->CharSet;
        }
        if ($contentType == '') {
            $contentType = $this->ContentType;
        }
        if ($encoding == '') {
            $encoding = $this->Encoding;
        }
        $result .= $this->textLine('--' . $boundary);
        $result .= sprintf('Content-Type: %s; charset=%s', $contentType, $charSet);
        $result .= $this->LE;
        // RFC1341 part 5 says 7bit is assumed if not specified
        if ($encoding != '7bit') {
            $result .= $this->headerLine('Content-Transfer-Encoding', $encoding);
        }
        $result .= $this->LE;
        return $result;
    }
    /**
     * Return the end of a message boundary.
     * @access protected
     * @param string $boundary
     * @return string
     */
    protected function endBoundary($boundary)
    {
        return $this->LE . '--' . $boundary . '--' . $this->LE;
    }
    /**
     * Set the message type.
     * PHPMailer only supports some preset message types, not arbitrary MIME structures.
     * @access protected
     * @return void
     */
    protected function setMessageType()
    {
        $type = array();
        if ($this->alternativeExists()) {
            $type[] = 'alt';
        }
        if ($this->inlineImageExists()) {
            $type[] = 'inline';
        }
        if ($this->attachmentExists()) {
            $type[] = 'attach';
        }
        $this->message_type = implode('_', $type);
        if ($this->message_type == '') {
            //The 'plain' message_type refers to the message having a single body element, not that it is plain-text
            $this->message_type = 'plain';
        }
    }
    /**
     * Format a header line.
     * @access public
     * @param string $name
     * @param string $value
     * @return string
     */
    public function headerLine($name, $value)
    {
        return $name . ': ' . $value . $this->LE;
    }
    /**
     * Return a formatted mail line.
     * @access public
     * @param string $value
     * @return string
     */
    public function textLine($value)
    {
        return $value . $this->LE;
    }
    /**
     * Add an attachment from a path on the filesystem.
     * Returns false if the file could not be found or read.
     * @param string $path Path to the attachment.
     * @param string $name Overrides the attachment name.
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type File extension (MIME) type.
     * @param string $disposition Disposition to use
     * @throws phpmailerException
     * @return boolean
     */
    public function addAttachment($path, $name = '', $encoding = 'base64', $type = '', $disposition = 'attachment')
    {
        try {
            if (!@is_file($path)) {
                throw new phpmailerException($this->lang('file_access') . $path, self::STOP_CONTINUE);
            }
            // If a MIME type is not specified, try to work it out from the file name
            if ($type == '') {
                $type = self::filenameToType($path);
            }
            $filename = basename($path);
            if ($name == '') {
                $name = $filename;
            }
            $this->attachment[] = array(
                0 => $path,
                1 => $filename,
                2 => $name,
                3 => $encoding,
                4 => $type,
                5 => false, // isStringAttachment
                6 => $disposition,
                7 => 0
            );
        } catch (phpmailerException $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
        return true;
    }
    /**
     * Return the array of attachments.
     * @return array
     */
    public function getAttachments()
    {
        return $this->attachment;
    }
    /**
     * Attach all file, string, and binary attachments to the message.
     * Returns an empty string on failure.
     * @access protected
     * @param string $disposition_type
     * @param string $boundary
     * @return string
     */
    protected function attachAll($disposition_type, $boundary)
    {
        // Return text of body
        $mime = array();
        $cidUniq = array();
        $incl = array();
        // Add all attachments
        foreach ($this->attachment as $attachment) {
            // Check if it is a valid disposition_filter
            if ($attachment[6] == $disposition_type) {
                // Check for string attachment
                $string = '';
                $path = '';
                $bString = $attachment[5];
                if ($bString) {
                    $string = $attachment[0];
                } else {
                    $path = $attachment[0];
                }
                $inclhash = md5(serialize($attachment));
                if (in_array($inclhash, $incl)) {
                    continue;
                }
                $incl[] = $inclhash;
                $name = $attachment[2];
                $encoding = $attachment[3];
                $type = $attachment[4];
                $disposition = $attachment[6];
                $cid = $attachment[7];
                if ($disposition == 'inline' && array_key_exists($cid, $cidUniq)) {
                    continue;
                }
                $cidUniq[$cid] = true;
                $mime[] = sprintf('--%s%s', $boundary, $this->LE);
                //Only include a filename property if we have one
                if (!empty($name)) {
                    $mime[] = sprintf(
                        'Content-Type: %s; name="%s"%s',
                        $type,
                        $this->encodeHeader($this->secureHeader($name)),
                        $this->LE
                    );
                } else {
                    $mime[] = sprintf(
                        'Content-Type: %s%s',
                        $type,
                        $this->LE
                    );
                }
                // RFC1341 part 5 says 7bit is assumed if not specified
                if ($encoding != '7bit') {
                    $mime[] = sprintf('Content-Transfer-Encoding: %s%s', $encoding, $this->LE);
                }
                if ($disposition == 'inline') {
                    $mime[] = sprintf('Content-ID: <%s>%s', $cid, $this->LE);
                }
                // If a filename contains any of these chars, it should be quoted,
                // but not otherwise: RFC2183 & RFC2045 5.1
                // Fixes a warning in IETF's msglint MIME checker
                // Allow for bypassing the Content-Disposition header totally
                if (!(empty($disposition))) {
                    $encoded_name = $this->encodeHeader($this->secureHeader($name));
                    if (preg_match('/[ \(\)<>@,;:\\"\/\[\]\?=]/', $encoded_name)) {
                        $mime[] = sprintf(
                            'Content-Disposition: %s; filename="%s"%s',
                            $disposition,
                            $encoded_name,
                            $this->LE . $this->LE
                        );
                    } else {
                        if (!empty($encoded_name)) {
                            $mime[] = sprintf(
                                'Content-Disposition: %s; filename=%s%s',
                                $disposition,
                                $encoded_name,
                                $this->LE . $this->LE
                            );
                        } else {
                            $mime[] = sprintf(
                                'Content-Disposition: %s%s',
                                $disposition,
                                $this->LE . $this->LE
                            );
                        }
                    }
                } else {
                    $mime[] = $this->LE;
                }
                // Encode as string attachment
                if ($bString) {
                    $mime[] = $this->encodeString($string, $encoding);
                    if ($this->isError()) {
                        return '';
                    }
                    $mime[] = $this->LE . $this->LE;
                } else {
                    $mime[] = $this->encodeFile($path, $encoding);
                    if ($this->isError()) {
                        return '';
                    }
                    $mime[] = $this->LE . $this->LE;
                }
            }
        }
        $mime[] = sprintf('--%s--%s', $boundary, $this->LE);
        return implode('', $mime);
    }
    /**
     * Encode a file attachment in requested format.
     * Returns an empty string on failure.
     * @param string $path The full path to the file
     * @param string $encoding The encoding to use; one of 'base64', '7bit', '8bit', 'binary', 'quoted-printable'
     * @throws phpmailerException
     * @access protected
     * @return string
     */
    protected function encodeFile($path, $encoding = 'base64')
    {
        try {
            if (!is_readable($path)) {
                throw new phpmailerException($this->lang('file_open') . $path, self::STOP_CONTINUE);
            }
            $magic_quotes = get_magic_quotes_runtime();
            if ($magic_quotes) {
                if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                    set_magic_quotes_runtime(false);
                } else {
                    //Doesn't exist in PHP 5.4, but we don't need to check because
                    //get_magic_quotes_runtime always returns false in 5.4+
                    //so it will never get here
                    ini_set('magic_quotes_runtime', false);
                }
            }
            $file_buffer = file_get_contents($path);
            $file_buffer = $this->encodeString($file_buffer, $encoding);
            if ($magic_quotes) {
                if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                    set_magic_quotes_runtime($magic_quotes);
                } else {
                    ini_set('magic_quotes_runtime', $magic_quotes);
                }
            }
            return $file_buffer;
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            return '';
        }
    }
    /**
     * Encode a string in requested format.
     * Returns an empty string on failure.
     * @param string $str The text to encode
     * @param string $encoding The encoding to use; one of 'base64', '7bit', '8bit', 'binary', 'quoted-printable'
     * @access public
     * @return string
     */
    public function encodeString($str, $encoding = 'base64')
    {
        $encoded = '';
        switch (strtolower($encoding)) {
            case 'base64':
                $encoded = chunk_split(base64_encode($str), 76, $this->LE);
                break;
            case '7bit':
            case '8bit':
                $encoded = $this->fixEOL($str);
                // Make sure it ends with a line break
                if (substr($encoded, -(strlen($this->LE))) != $this->LE) {
                    $encoded .= $this->LE;
                }
                break;
            case 'binary':
                $encoded = $str;
                break;
            case 'quoted-printable':
                $encoded = $this->encodeQP($str);
                break;
            default:
                $this->setError($this->lang('encoding') . $encoding);
                break;
        }
        return $encoded;
    }
    /**
     * Encode a header string optimally.
     * Picks shortest of Q, B, quoted-printable or none.
     * @access public
     * @param string $str
     * @param string $position
     * @return string
     */
    public function encodeHeader($str, $position = 'text')
    {
        $matchcount = 0;
        switch (strtolower($position)) {
            case 'phrase':
                if (!preg_match('/[\200-\377]/', $str)) {
                    // Can't use addslashes as we don't know the value of magic_quotes_sybase
                    $encoded = addcslashes($str, "\0..\37\177\\\"");
                    if (($str == $encoded) && !preg_match('/[^A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]/', $str)) {
                        return ($encoded);
                    } else {
                        return ("\"$encoded\"");
                    }
                }
                $matchcount = preg_match_all('/[^\040\041\043-\133\135-\176]/', $str, $matches);
                break;
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'comment':
                $matchcount = preg_match_all('/[()"]/', $str, $matches);
                // Intentional fall-through
            case 'text':
            default:
                $matchcount += preg_match_all('/[\000-\010\013\014\016-\037\177-\377]/', $str, $matches);
                break;
        }
        //There are no chars that need encoding
        if ($matchcount == 0) {
            return ($str);
        }
        $maxlen = 75 - 7 - strlen($this->CharSet);
        // Try to select the encoding which should produce the shortest output
        if ($matchcount > strlen($str) / 3) {
            // More than a third of the content will need encoding, so B encoding will be most efficient
            $encoding = 'B';
            if (function_exists('mb_strlen') && $this->hasMultiBytes($str)) {
                // Use a custom function which correctly encodes and wraps long
                // multibyte strings without breaking lines within a character
                $encoded = $this->base64EncodeWrapMB($str, "\n");
            } else {
                $encoded = base64_encode($str);
                $maxlen -= $maxlen % 4;
                $encoded = trim(chunk_split($encoded, $maxlen, "\n"));
            }
        } else {
            $encoding = 'Q';
            $encoded = $this->encodeQ($str, $position);
            $encoded = $this->wrapText($encoded, $maxlen, true);
            $encoded = str_replace('=' . self::CRLF, "\n", trim($encoded));
        }
        $encoded = preg_replace('/^(.*)$/m', ' =?' . $this->CharSet . "?$encoding?\\1?=", $encoded);
        $encoded = trim(str_replace("\n", $this->LE, $encoded));
        return $encoded;
    }
    /**
     * Check if a string contains multi-byte characters.
     * @access public
     * @param string $str multi-byte text to wrap encode
     * @return boolean
     */
    public function hasMultiBytes($str)
    {
        if (function_exists('mb_strlen')) {
            return (strlen($str) > mb_strlen($str, $this->CharSet));
        } else { // Assume no multibytes (we can't handle without mbstring functions anyway)
            return false;
        }
    }
    /**
     * Does a string contain any 8-bit chars (in any charset)?
     * @param string $text
     * @return boolean
     */
    public function has8bitChars($text)
    {
        return (boolean)preg_match('/[\x80-\xFF]/', $text);
    }
    /**
     * Encode and wrap long multibyte strings for mail headers
     * without breaking lines within a character.
     * Adapted from a function by paravoid
     * @link http://www.php.net/manual/en/function.mb-encode-mimeheader.php#60283
     * @access public
     * @param string $str multi-byte text to wrap encode
     * @param string $linebreak string to use as linefeed/end-of-line
     * @return string
     */
    public function base64EncodeWrapMB($str, $linebreak = null)
    {
        $start = '=?' . $this->CharSet . '?B?';
        $end = '?=';
        $encoded = '';
        if ($linebreak === null) {
            $linebreak = $this->LE;
        }
        $mb_length = mb_strlen($str, $this->CharSet);
        // Each line must have length <= 75, including $start and $end
        $length = 75 - strlen($start) - strlen($end);
        // Average multi-byte ratio
        $ratio = $mb_length / strlen($str);
        // Base64 has a 4:3 ratio
        $avgLength = floor($length * $ratio * .75);
        for ($i = 0; $i < $mb_length; $i += $offset) {
            $lookBack = 0;
            do {
                $offset = $avgLength - $lookBack;
                $chunk = mb_substr($str, $i, $offset, $this->CharSet);
                $chunk = base64_encode($chunk);
                $lookBack++;
            } while (strlen($chunk) > $length);
            $encoded .= $chunk . $linebreak;
        }
        // Chomp the last linefeed
        $encoded = substr($encoded, 0, -strlen($linebreak));
        return $encoded;
    }
    /**
     * Encode a string in quoted-printable format.
     * According to RFC2045 section 6.7.
     * @access public
     * @param string $string The text to encode
     * @param integer $line_max Number of chars allowed on a line before wrapping
     * @return string
     * @link http://www.php.net/manual/en/function.quoted-printable-decode.php#89417 Adapted from this comment
     */
    public function encodeQP($string, $line_max = 76)
    {
        // Use native function if it's available (>= PHP5.3)
        if (function_exists('quoted_printable_encode')) {
            return quoted_printable_encode($string);
        }
        // Fall back to a pure PHP implementation
        $string = str_replace(
            array('%20', '%0D%0A.', '%0D%0A', '%'),
            array(' ', "\r\n=2E", "\r\n", '='),
            rawurlencode($string)
        );
        return preg_replace('/[^\r\n]{' . ($line_max - 3) . '}[^=\r\n]{2}/', "$0=\r\n", $string);
    }
    /**
     * Backward compatibility wrapper for an old QP encoding function that was removed.
     * @see PHPMailer::encodeQP()
     * @access public
     * @param string $string
     * @param integer $line_max
     * @param boolean $space_conv
     * @return string
     * @deprecated Use encodeQP instead.
     */
    public function encodeQPphp(
        $string,
        $line_max = 76,
        /** @noinspection PhpUnusedParameterInspection */ $space_conv = false
    ) {
        return $this->encodeQP($string, $line_max);
    }
    /**
     * Encode a string using Q encoding.
     * @link http://tools.ietf.org/html/rfc2047
     * @param string $str the text to encode
     * @param string $position Where the text is going to be used, see the RFC for what that means
     * @access public
     * @return string
     */
    public function encodeQ($str, $position = 'text')
    {
        // There should not be any EOL in the string
        $pattern = '';
        $encoded = str_replace(array("\r", "\n"), '', $str);
        switch (strtolower($position)) {
            case 'phrase':
                // RFC 2047 section 5.3
                $pattern = '^A-Za-z0-9!*+\/ -';
                break;
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'comment':
                // RFC 2047 section 5.2
                $pattern = '\(\)"';
                // intentional fall-through
                // for this reason we build the $pattern without including delimiters and []
            case 'text':
            default:
                // RFC 2047 section 5.1
                // Replace every high ascii, control, =, ? and _ characters
                $pattern = '\000-\011\013\014\016-\037\075\077\137\177-\377' . $pattern;
                break;
        }
        $matches = array();
        if (preg_match_all("/[{$pattern}]/", $encoded, $matches)) {
            // If the string contains an '=', make sure it's the first thing we replace
            // so as to avoid double-encoding
            $eqkey = array_search('=', $matches[0]);
            if (false !== $eqkey) {
                unset($matches[0][$eqkey]);
                array_unshift($matches[0], '=');
            }
            foreach (array_unique($matches[0]) as $char) {
                $encoded = str_replace($char, '=' . sprintf('%02X', ord($char)), $encoded);
            }
        }
        // Replace every spaces to _ (more readable than =20)
        return str_replace(' ', '_', $encoded);
    }
    /**
     * Add a string or binary attachment (non-filesystem).
     * This method can be used to attach ascii or binary data,
     * such as a BLOB record from a database.
     * @param string $string String attachment data.
     * @param string $filename Name of the attachment.
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type File extension (MIME) type.
     * @param string $disposition Disposition to use
     * @return void
     */
    public function addStringAttachment(
        $string,
        $filename,
        $encoding = 'base64',
        $type = '',
        $disposition = 'attachment'
    ) {
        // If a MIME type is not specified, try to work it out from the file name
        if ($type == '') {
            $type = self::filenameToType($filename);
        }
        // Append to $attachment array
        $this->attachment[] = array(
            0 => $string,
            1 => $filename,
            2 => basename($filename),
            3 => $encoding,
            4 => $type,
            5 => true, // isStringAttachment
            6 => $disposition,
            7 => 0
        );
    }
    /**
     * Add an embedded (inline) attachment from a file.
     * This can include images, sounds, and just about any other document type.
     * These differ from 'regular' attachments in that they are intended to be
     * displayed inline with the message, not just attached for download.
     * This is used in HTML messages that embed the images
     * the HTML refers to using the $cid value.
     * @param string $path Path to the attachment.
     * @param string $cid Content ID of the attachment; Use this to reference
     *        the content when using an embedded image in HTML.
     * @param string $name Overrides the attachment name.
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type File MIME type.
     * @param string $disposition Disposition to use
     * @return boolean True on successfully adding an attachment
     */
    public function addEmbeddedImage($path, $cid, $name = '', $encoding = 'base64', $type = '', $disposition = 'inline')
    {
        if (!@is_file($path)) {
            $this->setError($this->lang('file_access') . $path);
            return false;
        }
        // If a MIME type is not specified, try to work it out from the file name
        if ($type == '') {
            $type = self::filenameToType($path);
        }
        $filename = basename($path);
        if ($name == '') {
            $name = $filename;
        }
        // Append to $attachment array
        $this->attachment[] = array(
            0 => $path,
            1 => $filename,
            2 => $name,
            3 => $encoding,
            4 => $type,
            5 => false, // isStringAttachment
            6 => $disposition,
            7 => $cid
        );
        return true;
    }
    /**
     * Add an embedded stringified attachment.
     * This can include images, sounds, and just about any other document type.
     * Be sure to set the $type to an image type for images:
     * JPEG images use 'image/jpeg', GIF uses 'image/gif', PNG uses 'image/png'.
     * @param string $string The attachment binary data.
     * @param string $cid Content ID of the attachment; Use this to reference
     *        the content when using an embedded image in HTML.
     * @param string $name
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type MIME type.
     * @param string $disposition Disposition to use
     * @return boolean True on successfully adding an attachment
     */
    public function addStringEmbeddedImage(
        $string,
        $cid,
        $name = '',
        $encoding = 'base64',
        $type = '',
        $disposition = 'inline'
    ) {
        // If a MIME type is not specified, try to work it out from the name
        if ($type == '' and !empty($name)) {
            $type = self::filenameToType($name);
        }
        // Append to $attachment array
        $this->attachment[] = array(
            0 => $string,
            1 => $name,
            2 => $name,
            3 => $encoding,
            4 => $type,
            5 => true, // isStringAttachment
            6 => $disposition,
            7 => $cid
        );
        return true;
    }
    /**
     * Check if an inline attachment is present.
     * @access public
     * @return boolean
     */
    public function inlineImageExists()
    {
        foreach ($this->attachment as $attachment) {
            if ($attachment[6] == 'inline') {
                return true;
            }
        }
        return false;
    }
    /**
     * Check if an attachment (non-inline) is present.
     * @return boolean
     */
    public function attachmentExists()
    {
        foreach ($this->attachment as $attachment) {
            if ($attachment[6] == 'attachment') {
                return true;
            }
        }
        return false;
    }
    /**
     * Check if this message has an alternative body set.
     * @return boolean
     */
    public function alternativeExists()
    {
        return !empty($this->AltBody);
    }
    /**
     * Clear queued addresses of given kind.
     * @access protected
     * @param string $kind 'to', 'cc', or 'bcc'
     * @return void
     */
    public function clearQueuedAddresses($kind)
    {
        $RecipientsQueue = $this->RecipientsQueue;
        foreach ($RecipientsQueue as $address => $params) {
            if ($params[0] == $kind) {
                unset($this->RecipientsQueue[$address]);
            }
        }
    }
    /**
     * Clear all To recipients.
     * @return void
     */
    public function clearAddresses()
    {
        foreach ($this->to as $to) {
            unset($this->all_recipients[strtolower($to[0])]);
        }
        $this->to = array();
        $this->clearQueuedAddresses('to');
    }
    /**
     * Clear all CC recipients.
     * @return void
     */
    public function clearCCs()
    {
        foreach ($this->cc as $cc) {
            unset($this->all_recipients[strtolower($cc[0])]);
        }
        $this->cc = array();
        $this->clearQueuedAddresses('cc');
    }
    /**
     * Clear all BCC recipients.
     * @return void
     */
    public function clearBCCs()
    {
        foreach ($this->bcc as $bcc) {
            unset($this->all_recipients[strtolower($bcc[0])]);
        }
        $this->bcc = array();
        $this->clearQueuedAddresses('bcc');
    }
    /**
     * Clear all ReplyTo recipients.
     * @return void
     */
    public function clearReplyTos()
    {
        $this->ReplyTo = array();
        $this->ReplyToQueue = array();
    }
    /**
     * Clear all recipient types.
     * @return void
     */
    public function clearAllRecipients()
    {
        $this->to = array();
        $this->cc = array();
        $this->bcc = array();
        $this->all_recipients = array();
        $this->RecipientsQueue = array();
    }
    /**
     * Clear all filesystem, string, and binary attachments.
     * @return void
     */
    public function clearAttachments()
    {
        $this->attachment = array();
    }
    /**
     * Clear all custom headers.
     * @return void
     */
    public function clearCustomHeaders()
    {
        $this->CustomHeader = array();
    }
    /**
     * Add an error message to the error container.
     * @access protected
     * @param string $msg
     * @return void
     */
    protected function setError($msg)
    {
        $this->error_count++;
        if ($this->Mailer == 'smtp' and !is_null($this->smtp)) {
            $lasterror = $this->smtp->getError();
            if (!empty($lasterror['error'])) {
                $msg .= $this->lang('smtp_error') . $lasterror['error'];
                if (!empty($lasterror['detail'])) {
                    $msg .= ' Detail: '. $lasterror['detail'];
                }
                if (!empty($lasterror['smtp_code'])) {
                    $msg .= ' SMTP code: ' . $lasterror['smtp_code'];
                }
                if (!empty($lasterror['smtp_code_ex'])) {
                    $msg .= ' Additional SMTP info: ' . $lasterror['smtp_code_ex'];
                }
            }
        }
        $this->ErrorInfo = $msg;
    }
    /**
     * Return an RFC 822 formatted date.
     * @access public
     * @return string
     * @static
     */
    public static function rfcDate()
    {
        // Set the time zone to whatever the default is to avoid 500 errors
        // Will default to UTC if it's not set properly in php.ini
        date_default_timezone_set(@date_default_timezone_get());
        return date('D, j M Y H:i:s O');
    }
    /**
     * Get the server hostname.
     * Returns 'localhost.localdomain' if unknown.
     * @access protected
     * @return string
     */
    protected function serverHostname()
    {
        $result = 'localhost.localdomain';
        if (!empty($this->Hostname)) {
            $result = $this->Hostname;
        } elseif (isset($_SERVER) and array_key_exists('SERVER_NAME', $_SERVER) and !empty($_SERVER['SERVER_NAME'])) {
            $result = $_SERVER['SERVER_NAME'];
        } elseif (function_exists('gethostname') && gethostname() !== false) {
            $result = gethostname();
        } elseif (php_uname('n') !== false) {
            $result = php_uname('n');
        }
        return $result;
    }
    /**
     * Get an error message in the current language.
     * @access protected
     * @param string $key
     * @return string
     */
    protected function lang($key)
    {
        if (count($this->language) < 1) {
            $this->setLanguage('en'); // set the default language
        }
        if (array_key_exists($key, $this->language)) {
            if ($key == 'smtp_connect_failed') {
                //Include a link to troubleshooting docs on SMTP connection failure
                //this is by far the biggest cause of support questions
                //but it's usually not PHPMailer's fault.
                return $this->language[$key] . ' https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting';
            }
            return $this->language[$key];
        } else {
            //Return the key as a fallback
            return $key;
        }
    }
    /**
     * Check if an error occurred.
     * @access public
     * @return boolean True if an error did occur.
     */
    public function isError()
    {
        return ($this->error_count > 0);
    }
    /**
     * Ensure consistent line endings in a string.
     * Changes every end of line from CRLF, CR or LF to $this->LE.
     * @access public
     * @param string $str String to fixEOL
     * @return string
     */
    public function fixEOL($str)
    {
        // Normalise to \n
        $nstr = str_replace(array("\r\n", "\r"), "\n", $str);
        // Now convert LE as needed
        if ($this->LE !== "\n") {
            $nstr = str_replace("\n", $this->LE, $nstr);
        }
        return $nstr;
    }
    /**
     * Add a custom header.
     * $name value can be overloaded to contain
     * both header name and value (name:value)
     * @access public
     * @param string $name Custom header name
     * @param string $value Header value
     * @return void
     */
    public function addCustomHeader($name, $value = null,$overwrite = false)
    {
        if ($value === null) 
	{
	$header = explode(':', $name, 2);
            $name = $header[0];
        }
        if ($overwrite) 
	{
            $this->CustomHeader[$name] = isset($header) ? $header : array($name, $value);
        }
	else 
	{
            $this->CustomHeader[] = isset($header) ? $header : array($name, $value);
        }
    }
    /**
     * Returns all custom headers.
     * @return array
     */
    public function getCustomHeaders()
    {
        return array_values($this->CustomHeader);
    }
    /**
     * Create a message body from an HTML string.
     * Automatically inlines images and creates a plain-text version by converting the HTML,
     * overwriting any existing values in Body and AltBody.
     * $basedir is used when handling relative image paths, e.g. <img src="images/a.png">
     * will look for an image file in $basedir/images/a.png and convert it to inline.
     * If you don't want to apply these transformations to your HTML, just set Body and AltBody yourself.
     * @access public
     * @param string $message HTML message string
     * @param string $basedir base directory for relative paths to images
     * @param boolean|callable $advanced Whether to use the internal HTML to text converter
     *    or your own custom converter @see PHPMailer::html2text()
     * @return string $message The transformed message Body
     */
    public function msgHTML($message, $basedir = '', $advanced = false)
    {
        preg_match_all('/(src|background)=["\'](.*)["\']/Ui', $message, $images);
        if (array_key_exists(2, $images)) {
            foreach ($images[2] as $imgindex => $url) {
                // Convert data URIs into embedded images
                if (preg_match('#^data:(image[^;,]*)(;base64)?,#', $url, $match)) {
                    $data = substr($url, strpos($url, ','));
                    if ($match[2]) {
                        $data = base64_decode($data);
                    } else {
                        $data = rawurldecode($data);
                    }
                    $cid = md5($url) . '@phpmailer.0'; // RFC2392 S 2
                    if ($this->addStringEmbeddedImage($data, $cid, 'embed' . $imgindex, 'base64', $match[1])) {
                        $message = str_replace(
                            $images[0][$imgindex],
                            $images[1][$imgindex] . '="cid:' . $cid . '"',
                            $message
                        );
                    }
                } elseif (substr($url, 0, 4) !== 'cid:' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                    // Do not change urls for absolute images (thanks to corvuscorax)
                    // Do not change urls that are already inline images
                    $filename = basename($url);
                    $directory = dirname($url);
                    if ($directory == '.') {
                        $directory = '';
                    }
                    $cid = md5($url) . '@phpmailer.0'; // RFC2392 S 2
                    if (strlen($basedir) > 1 && substr($basedir, -1) != '/') {
                        $basedir .= '/';
                    }
                    if (strlen($directory) > 1 && substr($directory, -1) != '/') {
                        $directory .= '/';
                    }
                    if ($this->addEmbeddedImage(
                        $basedir . $directory . $filename,
                        $cid,
                        $filename,
                        'base64',
                        self::_mime_types((string)self::mb_pathinfo($filename, PATHINFO_EXTENSION))
                    )
                    ) {
                        $message = preg_replace(
                            '/' . $images[1][$imgindex] . '=["\']' . preg_quote($url, '/') . '["\']/Ui',
                            $images[1][$imgindex] . '="cid:' . $cid . '"',
                            $message
                        );
                    }
                }
            }
        }
        $this->isHTML(true);
        // Convert all message body line breaks to CRLF, makes quoted-printable encoding work much better
        $this->Body = $this->normalizeBreaks($message);
        $this->AltBody = $this->normalizeBreaks($this->html2text($message, $advanced));
        if (!$this->alternativeExists()) {
            $this->AltBody = 'To view this email message, open it in a program that understands HTML!' .
                self::CRLF . self::CRLF;
        }
        return $this->Body;
    }
    /**
     * Convert an HTML string into plain text.
     * This is used by msgHTML().
     * Note - older versions of this function used a bundled advanced converter
     * which was been removed for license reasons in #232.
     * Example usage:
     * <code>
     * // Use default conversion
     * $plain = $mail->html2text($html);
     * // Use your own custom converter
     * $plain = $mail->html2text($html, function($html) {
     *     $converter = new MyHtml2text($html);
     *     return $converter->get_text();
     * });
     * </code>
     * @param string $html The HTML text to convert
     * @param boolean|callable $advanced Any boolean value to use the internal converter,
     *   or provide your own callable for custom conversion.
     * @return string
     */
    public function html2text($html, $advanced = false)
    {
        if (is_callable($advanced)) {
            return call_user_func($advanced, $html);
        }
        return html_entity_decode(
            trim(strip_tags(preg_replace('/<(head|title|style|script)[^>]*>.*?<\/\\1>/si', '', $html))),
            ENT_QUOTES,
            $this->CharSet
        );
    }
    /**
     * Get the MIME type for a file extension.
     * @param string $ext File extension
     * @access public
     * @return string MIME type of file.
     * @static
     */
    public static function _mime_types($ext = '')
    {
        $mimes = array(
            'xl'    => 'application/excel',
            'js'    => 'application/javascript',
            'hqx'   => 'application/mac-binhex40',
            'cpt'   => 'application/mac-compactpro',
            'bin'   => 'application/macbinary',
            'doc'   => 'application/msword',
            'word'  => 'application/msword',
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xltx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'potx'  => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'ppsx'  => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'sldx'  => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
            'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dotx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'xlam'  => 'application/vnd.ms-excel.addin.macroEnabled.12',
            'xlsb'  => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'class' => 'application/octet-stream',
            'dll'   => 'application/octet-stream',
            'dms'   => 'application/octet-stream',
            'exe'   => 'application/octet-stream',
            'lha'   => 'application/octet-stream',
            'lzh'   => 'application/octet-stream',
            'psd'   => 'application/octet-stream',
            'sea'   => 'application/octet-stream',
            'so'    => 'application/octet-stream',
            'oda'   => 'application/oda',
            'pdf'   => 'application/pdf',
            'ai'    => 'application/postscript',
            'eps'   => 'application/postscript',
            'ps'    => 'application/postscript',
            'smi'   => 'application/smil',
            'smil'  => 'application/smil',
            'mif'   => 'application/vnd.mif',
            'xls'   => 'application/vnd.ms-excel',
            'ppt'   => 'application/vnd.ms-powerpoint',
            'wbxml' => 'application/vnd.wap.wbxml',
            'wmlc'  => 'application/vnd.wap.wmlc',
            'dcr'   => 'application/x-director',
            'dir'   => 'application/x-director',
            'dxr'   => 'application/x-director',
            'dvi'   => 'application/x-dvi',
            'gtar'  => 'application/x-gtar',
            'php3'  => 'application/x-httpd-php',
            'php4'  => 'application/x-httpd-php',
            'php'   => 'application/x-httpd-php',
            'phtml' => 'application/x-httpd-php',
            'phps'  => 'application/x-httpd-php-source',
            'swf'   => 'application/x-shockwave-flash',
            'sit'   => 'application/x-stuffit',
            'tar'   => 'application/x-tar',
            'tgz'   => 'application/x-tar',
            'xht'   => 'application/xhtml+xml',
            'xhtml' => 'application/xhtml+xml',
            'zip'   => 'application/zip',
            'mid'   => 'audio/midi',
            'midi'  => 'audio/midi',
            'mp2'   => 'audio/mpeg',
            'mp3'   => 'audio/mpeg',
            'mpga'  => 'audio/mpeg',
            'aif'   => 'audio/x-aiff',
            'aifc'  => 'audio/x-aiff',
            'aiff'  => 'audio/x-aiff',
            'ram'   => 'audio/x-pn-realaudio',
            'rm'    => 'audio/x-pn-realaudio',
            'rpm'   => 'audio/x-pn-realaudio-plugin',
            'ra'    => 'audio/x-realaudio',
            'wav'   => 'audio/x-wav',
            'bmp'   => 'image/bmp',
            'gif'   => 'image/gif',
            'jpeg'  => 'image/jpeg',
            'jpe'   => 'image/jpeg',
            'jpg'   => 'image/jpeg',
            'png'   => 'image/png',
            'tiff'  => 'image/tiff',
            'tif'   => 'image/tiff',
            'eml'   => 'message/rfc822',
            'css'   => 'text/css',
            'html'  => 'text/html',
            'htm'   => 'text/html',
            'shtml' => 'text/html',
            'log'   => 'text/plain',
            'text'  => 'text/plain',
            'txt'   => 'text/plain',
            'rtx'   => 'text/richtext',
            'rtf'   => 'text/rtf',
            'vcf'   => 'text/vcard',
            'vcard' => 'text/vcard',
            'xml'   => 'text/xml',
            'xsl'   => 'text/xml',
            'mpeg'  => 'video/mpeg',
            'mpe'   => 'video/mpeg',
            'mpg'   => 'video/mpeg',
            'mov'   => 'video/quicktime',
            'qt'    => 'video/quicktime',
            'rv'    => 'video/vnd.rn-realvideo',
            'avi'   => 'video/x-msvideo',
            'movie' => 'video/x-sgi-movie'
        );
        if (array_key_exists(strtolower($ext), $mimes)) {
            return $mimes[strtolower($ext)];
        }
        return 'application/octet-stream';
    }
    /**
     * Map a file name to a MIME type.
     * Defaults to 'application/octet-stream', i.e.. arbitrary binary data.
     * @param string $filename A file name or full path, does not need to exist as a file
     * @return string
     * @static
     */
    public static function filenameToType($filename)
    {
        // In case the path is a URL, strip any query string before getting extension
        $qpos = strpos($filename, '?');
        if (false !== $qpos) {
            $filename = substr($filename, 0, $qpos);
        }
        $pathinfo = self::mb_pathinfo($filename);
        return self::_mime_types($pathinfo['extension']);
    }
    /**
     * Multi-byte-safe pathinfo replacement.
     * Drop-in replacement for pathinfo(), but multibyte-safe, cross-platform-safe, old-version-safe.
     * Works similarly to the one in PHP >= 5.2.0
     * @link http://www.php.net/manual/en/function.pathinfo.php#107461
     * @param string $path A filename or path, does not need to exist as a file
     * @param integer|string $options Either a PATHINFO_* constant,
     *      or a string name to return only the specified piece, allows 'filename' to work on PHP < 5.2
     * @return string|array
     * @static
     */
    public static function mb_pathinfo($path, $options = null)
    {
        $ret = array('dirname' => '', 'basename' => '', 'extension' => '', 'filename' => '');
        $pathinfo = array();
        if (preg_match('%^(.*?)[\\\\/]*(([^/\\\\]*?)(\.([^\.\\\\/]+?)|))[\\\\/\.]*$%im', $path, $pathinfo)) {
            if (array_key_exists(1, $pathinfo)) {
                $ret['dirname'] = $pathinfo[1];
            }
            if (array_key_exists(2, $pathinfo)) {
                $ret['basename'] = $pathinfo[2];
            }
            if (array_key_exists(5, $pathinfo)) {
                $ret['extension'] = $pathinfo[5];
            }
            if (array_key_exists(3, $pathinfo)) {
                $ret['filename'] = $pathinfo[3];
            }
        }
        switch ($options) {
            case PATHINFO_DIRNAME:
            case 'dirname':
                return $ret['dirname'];
            case PATHINFO_BASENAME:
            case 'basename':
                return $ret['basename'];
            case PATHINFO_EXTENSION:
            case 'extension':
                return $ret['extension'];
            case PATHINFO_FILENAME:
            case 'filename':
                return $ret['filename'];
            default:
                return $ret;
        }
    }
    /**
     * Set or reset instance properties.
     * You should avoid this function - it's more verbose, less efficient, more error-prone and
     * harder to debug than setting properties directly.
     * Usage Example:
     * `$mail->set('SMTPSecure', 'tls');`
     *   is the same as:
     * `$mail->SMTPSecure = 'tls';`
     * @access public
     * @param string $name The property name to set
     * @param mixed $value The value to set the property to
     * @return boolean
     * @TODO Should this not be using the __set() magic function?
     */
    public function set($name, $value = '')
    {
        if (property_exists($this, $name)) {
            $this->$name = $value;
            return true;
        } else {
            $this->setError($this->lang('variable_set') . $name);
            return false;
        }
    }
    /**
     * Strip newlines to prevent header injection.
     * @access public
     * @param string $str
     * @return string
     */
    public function secureHeader($str)
    {
        return trim(str_replace(array("\r", "\n"), '', $str));
    }
    /**
     * Normalize line breaks in a string.
     * Converts UNIX LF, Mac CR and Windows CRLF line breaks into a single line break format.
     * Defaults to CRLF (for message bodies) and preserves consecutive breaks.
     * @param string $text
     * @param string $breaktype What kind of line break to use, defaults to CRLF
     * @return string
     * @access public
     * @static
     */
    public static function normalizeBreaks($text, $breaktype = "\r\n")
    {
        return preg_replace('/(\r\n|\r|\n)/ms', $breaktype, $text);
    }
    /**
     * Set the public and private key files and password for S/MIME signing.
     * @access public
     * @param string $cert_filename
     * @param string $key_filename
     * @param string $key_pass Password for private key
     * @param string $extracerts_filename Optional path to chain certificate
     */
    public function sign($cert_filename, $key_filename, $key_pass, $extracerts_filename = '')
    {
        $this->sign_cert_file = $cert_filename;
        $this->sign_key_file = $key_filename;
        $this->sign_key_pass = $key_pass;
        $this->sign_extracerts_file = $extracerts_filename;
    }
    /**
     * Quoted-Printable-encode a DKIM header.
     * @access public
     * @param string $txt
     * @return string
     */
    public function DKIM_QP($txt)
    {
        $line = '';
        for ($i = 0; $i < strlen($txt); $i++) {
            $ord = ord($txt[$i]);
            if (((0x21 <= $ord) && ($ord <= 0x3A)) || $ord == 0x3C || ((0x3E <= $ord) && ($ord <= 0x7E))) {
                $line .= $txt[$i];
            } else {
                $line .= '=' . sprintf('%02X', $ord);
            }
        }
        return $line;
    }
    /**
     * Generate a DKIM signature.
     * @access public
     * @param string $signHeader
     * @throws phpmailerException
     * @return string The DKIM signature value
     */
    public function DKIM_Sign($signHeader)
    {
        if (!defined('PKCS7_TEXT')) {
            if ($this->exceptions) {
                throw new phpmailerException($this->lang('extension_missing') . 'openssl');
            }
            return '';
        }
        $privKeyStr = !empty($this->DKIM_private_string) ? $this->DKIM_private_string : file_get_contents($this->DKIM_private);
        if ('' != $this->DKIM_passphrase) {
            $privKey = openssl_pkey_get_private($privKeyStr, $this->DKIM_passphrase);
        } else {
            $privKey = openssl_pkey_get_private($privKeyStr);
        }
        //Workaround for missing digest algorithms in old PHP & OpenSSL versions
        //@link http://stackoverflow.com/a/11117338/333340
        if (version_compare(PHP_VERSION, '5.3.0') >= 0 and
            in_array('sha256WithRSAEncryption', openssl_get_md_methods(true))) {
            if (openssl_sign($signHeader, $signature, $privKey, 'sha256WithRSAEncryption')) {
                openssl_pkey_free($privKey);
                return base64_encode($signature);
            }
        } else {
            $pinfo = openssl_pkey_get_details($privKey);
            $hash = hash('sha256', $signHeader);
            //'Magic' constant for SHA256 from RFC3447
            //@link https://tools.ietf.org/html/rfc3447#page-43
            $t = '3031300d060960864801650304020105000420' . $hash;
            $pslen = $pinfo['bits'] / 8 - (strlen($t) / 2 + 3);
            $eb = pack('H*', '0001' . str_repeat('FF', $pslen) . '00' . $t);
            if (openssl_private_encrypt($eb, $signature, $privKey, OPENSSL_NO_PADDING)) {
                openssl_pkey_free($privKey);
                return base64_encode($signature);
            }
        }
        openssl_pkey_free($privKey);
        return '';
    }
    /**
     * Generate a DKIM canonicalization header.
     * @access public
     * @param string $signHeader Header
     * @return string
     */
    public function DKIM_HeaderC($signHeader)
    {
        $signHeader = preg_replace('/\r\n\s+/', ' ', $signHeader);
        $lines = explode("\r\n", $signHeader);
        foreach ($lines as $key => $line) {
            list($heading, $value) = explode(':', $line, 2);
            $heading = strtolower($heading);
            $value = preg_replace('/\s{2,}/', ' ', $value); // Compress useless spaces
            $lines[$key] = $heading . ':' . trim($value); // Don't forget to remove WSP around the value
        }
        $signHeader = implode("\r\n", $lines);
        return $signHeader;
    }
    /**
     * Generate a DKIM canonicalization body.
     * @access public
     * @param string $body Message Body
     * @return string
     */
    public function DKIM_BodyC($body)
    {
        if ($body == '') {
            return "\r\n";
        }
        // stabilize line endings
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        // END stabilize line endings
        while (substr($body, strlen($body) - 4, 4) == "\r\n\r\n") {
            $body = substr($body, 0, strlen($body) - 2);
        }
        return $body;
    }
    /**
     * Create the DKIM header and body in a new message header.
     * @access public
     * @param string $headers_line Header lines
     * @param string $subject Subject
     * @param string $body Body
     * @return string
     */
    public function DKIM_Add($headers_line, $subject, $body)
    {
        $DKIMsignatureType = 'rsa-sha256'; // Signature & hash algorithms
        $DKIMcanonicalization = 'relaxed/simple'; // Canonicalization of header/body
        $DKIMquery = 'dns/txt'; // Query method
        $DKIMtime = time(); // Signature Timestamp = seconds since 00:00:00 - Jan 1, 1970 (UTC time zone)
        $subject_header = "Subject: $subject";
        $headers = explode($this->LE, $headers_line);
        $from_header = '';
        $to_header = '';
        $date_header = '';
        $current = '';
        foreach ($headers as $header) {
            if (strpos($header, 'From:') === 0) {
                $from_header = $header;
                $current = 'from_header';
            } elseif (strpos($header, 'To:') === 0) {
                $to_header = $header;
                $current = 'to_header';
            } elseif (strpos($header, 'Date:') === 0) {
                $date_header = $header;
                $current = 'date_header';
            } else {
                if (!empty($$current) && strpos($header, ' =?') === 0) {
                    $$current .= $header;
                } else {
                    $current = '';
                }
            }
        }
        $from = str_replace('|', '=7C', $this->DKIM_QP($from_header));
        $to = str_replace('|', '=7C', $this->DKIM_QP($to_header));
        $date = str_replace('|', '=7C', $this->DKIM_QP($date_header));
        $subject = str_replace(
            '|',
            '=7C',
            $this->DKIM_QP($subject_header)
        ); // Copied header fields (dkim-quoted-printable)
        $body = $this->DKIM_BodyC($body);
        $DKIMlen = strlen($body); // Length of body
        $DKIMb64 = base64_encode(pack('H*', hash('sha256', $body))); // Base64 of packed binary SHA-256 hash of body
        if ('' == $this->DKIM_identity) {
            $ident = '';
        } else {
            $ident = ' i=' . $this->DKIM_identity . ';';
        }
        $dkimhdrs = 'DKIM-Signature: v=1; a=' .
            $DKIMsignatureType . '; q=' .
            $DKIMquery . '; l=' .
            $DKIMlen . '; s=' .
            $this->DKIM_selector .
            ";\r\n" .
            "\tt=" . $DKIMtime . '; c=' . $DKIMcanonicalization . ";\r\n" .
            "\th=From:To:Date:Subject;\r\n" .
            "\td=" . $this->DKIM_domain . ';' . $ident . "\r\n" .
            "\tz=$from\r\n" .
            "\t|$to\r\n" .
            "\t|$date\r\n" .
            "\t|$subject;\r\n" .
            "\tbh=" . $DKIMb64 . ";\r\n" .
            "\tb=";
        $toSign = $this->DKIM_HeaderC(
            $from_header . "\r\n" .
            $to_header . "\r\n" .
            $date_header . "\r\n" .
            $subject_header . "\r\n" .
            $dkimhdrs
        );
        $signed = $this->DKIM_Sign($toSign);
        return $dkimhdrs . $signed . "\r\n";
    }
    /**
     * Detect if a string contains a line longer than the maximum line length allowed.
     * @param string $str
     * @return boolean
     * @static
     */
    public static function hasLineLongerThanMax($str)
    {
        //+2 to include CRLF line break for a 1000 total
        return (boolean)preg_match('/^(.{'.(self::MAX_LINE_LENGTH + 2).',})/m', $str);
    }
    /**
     * Allows for public read access to 'to' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getToAddresses()
    {
        return $this->to;
    }
    /**
     * Allows for public read access to 'cc' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getCcAddresses()
    {
        return $this->cc;
    }
    /**
     * Allows for public read access to 'bcc' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getBccAddresses()
    {
        return $this->bcc;
    }
    /**
     * Allows for public read access to 'ReplyTo' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getReplyToAddresses()
    {
        return $this->ReplyTo;
    }
    /**
     * Allows for public read access to 'all_recipients' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getAllRecipientAddresses()
    {
        return $this->all_recipients;
    }
    /**
     * Perform a callback.
     * @param boolean $isSent
     * @param array $to
     * @param array $cc
     * @param array $bcc
     * @param string $subject
     * @param string $body
     * @param string $from
     */
    protected function doCallback($isSent, $to, $cc, $bcc, $subject, $body, $from)
    {
        if (!empty($this->action_function) && is_callable($this->action_function)) {
            $params = array($isSent, $to, $cc, $bcc, $subject, $body, $from);
            call_user_func_array($this->action_function, $params);
        }
    }
}
/**
 * PHPMailer exception handler
 * @package PHPMailer
 */
class phpmailerException extends Exception
{
    /**
     * Prettify error message output
     * @return string
     */
    public function errorMessage()
    {
        $errorMsg = '<strong>' . $this->getMessage() . "</strong><br />\n";
        return $errorMsg;
    }
}


$mail = new PHPMailer();
// XAMPP / Windows often fails TLS without this
$mail->SMTPOptions = array(
	'ssl' => array(
		'verify_peer' => false,
		'verify_peer_name' => false,
		'allow_self_signed' => true
	)
);

// ---- SOCKS5 helpers: Run Test → socks5_ok.txt · Send uses OK · fails → failed_emails.txt ----
function socks_ok_path() { return __DIR__ . DIRECTORY_SEPARATOR . 'socks5_ok.txt'; }
function socks_failed_path() { return __DIR__ . DIRECTORY_SEPARATOR . 'failed_emails.txt'; }

function parse_socks_file($file, $skip_http_ports = false)
{
	$list = array();
	if(!is_file($file)) return $list;
	$skip_ports = array(80=>1, 8080=>1, 3128=>1, 8000=>1, 8888=>1, 8081=>1);
	$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if(!is_array($lines)) return $list;
	$seen = array();
	foreach($lines as $line)
	{
		$line = trim($line);
		if($line === '' || $line[0] === '#') continue;
		$proto = 'socks5';
		if(preg_match('#^(socks4a?|socks5h?)://#i', $line, $pm))
		{
			$p = strtolower($pm[1]);
			$proto = (strpos($p, '4') !== false) ? 'socks4' : 'socks5';
			$line = preg_replace('#^(socks4a?|socks5h?)://#i', '', $line);
		}
		if(!preg_match('/^([^:\s\/]+):(\d+)(?::([^:]*):?(.*))?$/', $line, $m)) continue;
		$port = intval($m[2]);
		if($skip_http_ports && isset($skip_ports[$port])) continue;
		$label = $m[1].':'.$m[2];
		$key = $proto.'|'.$label;
		if(isset($seen[$key])) continue;
		$seen[$key] = 1;
		$list[] = array(
			'host' => $m[1],
			'port' => $port,
			'user' => isset($m[3]) ? $m[3] : '',
			'pass' => isset($m[4]) ? $m[4] : '',
			'label' => $label,
			'proto' => $proto
		);
	}
	return $list;
}

function load_socks_ok($shuffle = true)
{
	global $socks_ok, $socks_proxies, $socks_pool, $socks_index;
	$socks_ok = parse_socks_file(socks_ok_path(), false);
	if($shuffle) shuffle($socks_ok);
	$socks_pool = 'ok';
	$socks_proxies = $socks_ok;
	$socks_index = 0;
	return $socks_ok;
}

/** Drop a dead proxy from socks5_ok.txt so send stays fresh */
function socks_remove_from_ok($label)
{
	$label = trim(preg_replace('#^socks[45]a?h?://#i', '', (string)$label));
	if($label === '') return;
	$file = socks_ok_path();
	if(!is_file($file)) return;
	$lk = @fopen(__DIR__.DIRECTORY_SEPARATOR.'socks5_filter.lock', 'c+');
	if($lk) flock($lk, LOCK_EX);
	$keep = array();
	foreach(@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
	{
		$raw = trim($line);
		$proto = (stripos($raw, 'socks4') === 0) ? 'socks4' : 'socks5';
		$l = preg_replace('#^socks[45]a?h?://#i', '', $raw);
		if($l === '' || $l === $label) continue;
		if(preg_match('/^([^:\s\/]+):(\d+)$/', $l, $m))
			$keep[$m[1].':'.$m[2]] = $proto.'://'.$m[1].':'.$m[2];
	}
	@file_put_contents($file, $keep ? (implode("\n", array_values($keep))."\n") : '');
	if($lk) { flock($lk, LOCK_UN); fclose($lk); }
}

function socks_start_ok_refresh()
{
	$script = __DIR__.DIRECTORY_SEPARATOR.'refresh_ok.php';
	$pidFile = __DIR__.DIRECTORY_SEPARATOR.'socks5_ok_refresh.pid';
	$log = __DIR__.DIRECTORY_SEPARATOR.'socks5_ok_refresh.log';
	if(!is_file($script)) return 'no_script';
	if(is_file($pidFile))
	{
		$pid = intval(trim(@file_get_contents($pidFile)));
		if($pid > 0 && socks_pid_alive($pid)) return 'already';
	}
	$php = 'C:\\xampp\\php\\php.exe';
	if(!is_file($php)) $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
	if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
	{
		$ps = "Start-Process -FilePath ".escapeshellarg($php)." -ArgumentList ".escapeshellarg($script)." -WindowStyle Hidden -RedirectStandardOutput ".escapeshellarg($log)." -RedirectStandardError ".escapeshellarg($log.'.err')." -PassThru | Select-Object -ExpandProperty Id";
		$pid = trim(@shell_exec('powershell -NoProfile -Command '.$ps));
		if($pid !== '' && intval($pid) > 0) @file_put_contents($pidFile, (string)intval($pid));
		return 'started';
	}
	$cmd = 'nohup '.escapeshellarg($php).' '.escapeshellarg($script).' >> '.escapeshellarg($log).' 2>&1 & echo $!';
	$pid = trim(shell_exec($cmd));
	if($pid !== '') @file_put_contents($pidFile, $pid);
	return 'started';
}

function send_stop_requested($campaignDir = '')
{
	$f1 = __DIR__.DIRECTORY_SEPARATOR.'stop_send.flag';
	clearstatcache(true, $f1);
	if(is_file($f1)) return true;
	if($campaignDir !== '')
	{
		$f2 = rtrim($campaignDir, '/\\').DIRECTORY_SEPARATOR.'stop.flag';
		clearstatcache(true, $f2);
		if(is_file($f2)) return true;
	}
	return false;
}

function send_running_flag_path()
{
	return __DIR__.DIRECTORY_SEPARATOR.'send_running.flag';
}

function send_mark_running()
{
	$data = array('pid' => getmypid(), 'sapi' => php_sapi_name(), 't' => time());
	@file_put_contents(send_running_flag_path(), json_encode($data));
	@file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'send_master.pid', (string)getmypid());
	register_shutdown_function('send_mark_stopped');
}

function send_mark_stopped()
{
	@unlink(send_running_flag_path());
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'send_master.pid');
}

function send_is_running()
{
	$f = send_running_flag_path();
	clearstatcache(true, $f);
	return is_file($f);
}

function send_save_pending($emails)
{
	$emails = array_values(array_unique(array_filter(array_map('trim', $emails), 'strlen')));
	@file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'pending_emails.txt', $emails ? (implode("\n", $emails)."\n") : '');
	return count($emails);
}

/** Reload socks5_ok.txt from disk (filter may have added new ones while sending) */
function socks_reload_ok_from_disk()
{
	global $socks_ok, $socks_proxies, $socks_index, $socks_dead;
	$fresh = parse_socks_file(socks_ok_path(), false);
	$have = array();
	foreach($socks_proxies as $p) $have[$p['label']] = 1;
	$added = 0;
	foreach($fresh as $p)
	{
		if(!empty($have[$p['label']])) continue;
		$socks_proxies[] = $p;
		$socks_ok[] = $p;
		$have[$p['label']] = 1;
		$added++;
	}
	return $added;
}

function socks_mark_dead($label)
{
	global $socks_dead;
	if($label) $socks_dead[$label] = true;
	if(!empty($GLOBALS['socks_sticky_proxy']) && $GLOBALS['socks_sticky_proxy'] === $label)
		$GLOBALS['socks_sticky_proxy'] = '';
}

function socks_next_proxy()
{
	global $socks_proxies, $socks_index, $socks_proxy, $socks_dead;
	if(empty($socks_proxies))
	{
		$socks_proxy = '';
		$GLOBALS['socks_proxy'] = '';
		return false;
	}
	// Prefer the proxy that already authenticated + sent OK this campaign
	$stickyOn = !isset($_POST['sticky_proxy']) || !empty($_POST['sticky_proxy']) || !empty($GLOBALS['sticky_proxy']);
	$sticky = (!empty($GLOBALS['socks_sticky_proxy']) && $stickyOn) ? $GLOBALS['socks_sticky_proxy'] : '';
	if($sticky !== '' && empty($socks_dead[$sticky]))
	{
		foreach($socks_proxies as $p)
		{
			if($p['label'] !== $sticky) continue;
			$socks_proxy = $p['label'];
			$GLOBALS['socks_proxy'] = $p['label'];
			$GLOBALS['socks_proto'] = !empty($p['proto']) ? $p['proto'] : 'socks5';
			$GLOBALS['socks_proxy_auth'] = array('user' => $p['user'], 'pass' => $p['pass']);
			return $p;
		}
	}
	$total = count($socks_proxies);
	for($i = 0; $i < $total; $i++)
	{
		$p = $socks_proxies[$socks_index % $total];
		$socks_index++;
		if(!empty($socks_dead[$p['label']])) continue;
		$socks_proxy = $p['label'];
		$GLOBALS['socks_proxy'] = $p['label'];
		$GLOBALS['socks_proto'] = !empty($p['proto']) ? $p['proto'] : 'socks5';
		$GLOBALS['socks_proxy_auth'] = array('user' => $p['user'], 'pass' => $p['pass']);
		return $p;
	}
	$socks_proxy = '';
	$GLOBALS['socks_proxy'] = '';
	$GLOBALS['socks_proto'] = 'socks5';
	return false;
}

function socks_queue_failed_email($email)
{
	$email = trim($email);
	if($email === '' || strpos($email, '@') === false) return;
	$fh = @fopen(socks_failed_path(), 'a');
	if($fh) { fwrite($fh, $email."\n"); fclose($fh); }
}

function socks_load_failed_emails()
{
	$file = socks_failed_path();
	if(!is_file($file)) return array();
	$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if(!is_array($lines)) return array();
	$out = array();
	$seen = array();
	foreach($lines as $line)
	{
		$e = trim($line);
		if($e === '' || strpos($e, '@') === false) continue;
		$k = strtolower($e);
		if(isset($seen[$k])) continue;
		$seen[$k] = 1;
		$out[] = $e;
	}
	return $out;
}

function socks_clear_failed_emails()
{
	@file_put_contents(socks_failed_path(), '');
}

function socks_pid_alive($pid)
{
	$pid = intval($pid);
	if($pid < 1) return false;
	if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
	{
		$out = array();
		@exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL', $out);
		return (strpos(implode(' ', $out), (string)$pid) !== false);
	}
	return function_exists('posix_kill') ? @posix_kill($pid, 0) : file_exists("/proc/$pid");
}

function socks_count_ok_on_disk()
{
	$file = socks_ok_path();
	if(!is_file($file)) return 0;
	$n = 0; $seen = array();
	foreach(@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
	{
		$line = preg_replace('#^socks5h?://#i', '', trim($line));
		if(preg_match('/^([^:\s\/]+):(\d+)$/', $line, $m))
		{
			$k = $m[1].':'.$m[2];
			if(!isset($seen[$k])) { $seen[$k] = 1; $n++; }
		}
	}
	return $n;
}

function socks_filter_is_running()
{
	$pidFile = __DIR__.DIRECTORY_SEPARATOR.'socks5_filter.pid';
	if(!is_file($pidFile)) return false;
	$pid = intval(trim(@file_get_contents($pidFile)));
	return ($pid > 0 && socks_pid_alive($pid));
}

function socks_stop_background_filter()
{
	$dir = __DIR__;
	$workersPidFile = $dir.DIRECTORY_SEPARATOR.'socks5_filter_workers.pid';
	$pidFile = $dir.DIRECTORY_SEPARATOR.'socks5_filter.pid';
	if(is_file($workersPidFile))
	{
		foreach(@file($workersPidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
		{
			$wpid = intval(trim($line));
			if($wpid < 1) continue;
			if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') @exec('taskkill /PID '.$wpid.' /F /T 2>NUL');
			else @posix_kill($wpid, 9);
		}
		@unlink($workersPidFile);
	}
	if(is_file($pidFile))
	{
		$pid = intval(trim(@file_get_contents($pidFile)));
		if($pid > 0)
		{
			if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') @exec('taskkill /PID '.$pid.' /F /T 2>NUL');
			else { @posix_kill($pid, 15); usleep(150000); if(socks_pid_alive($pid)) @posix_kill($pid, 9); }
		}
		@unlink($pidFile);
	}
}

/** When OK file is empty: reset progress to 0 and start 30-worker full scan so send can pick fresh OK as they appear */
function socks_restart_full_filter_from_scratch($reason = '')
{
	$dir = __DIR__;
	$cooldownFile = $dir.DIRECTORY_SEPARATOR.'socks5_filter_scratch_at.txt';
	$last = is_file($cooldownFile) ? intval(trim(@file_get_contents($cooldownFile))) : 0;
	if(socks_count_ok_on_disk() > 0) return 'has_ok';
	if(socks_filter_is_running()) return 'already';
	$force = (stripos($reason, 'send start') !== false || stripos($reason, 'OK empty') !== false);
	if(!$force && $last > 0 && (time() - $last) < 60) return 'cooldown';

	socks_stop_background_filter();
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'socks5_filter_progress.txt', '0');
	$status = array(
		'state' => 'running',
		'progress' => 0,
		'total' => 0,
		'ok_count' => 0,
		'percent' => 0,
		'workers' => 30,
		'message' => 'Auto full re-scan from scratch'.($reason !== '' ? ' ('.$reason.')' : '').' — OK file was empty',
		'last_proxy' => '',
		'updated_at' => date('c'),
		'updated_human' => date('Y-m-d H:i:s')
	);
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'socks5_filter_status.json', json_encode($status, JSON_PRETTY_PRINT));
	@file_put_contents($cooldownFile, (string)time());
	$r = socks_start_background_filter(true);
	return $r === 'already' ? 'started' : $r;
}

/** Poll socks5_ok.txt until fresh proxies appear (filter appends while running) */
function socks_wait_for_ok_proxies($maxSec = 120)
{
	global $socks_proxies, $socks_dead, $socks_index, $socks_ok;
	$deadline = time() + max(10, intval($maxSec));
	$tick = 0;
	while(time() < $deadline)
	{
		clearstatcache(true, __DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
		if(function_exists('send_stop_requested') && send_stop_requested()) return 0;
		// If scan falsely finished / errored with 0 OK, force another scratch start
		$stFile = __DIR__.DIRECTORY_SEPARATOR.'socks5_filter_status.json';
		$st = is_file($stFile) ? json_decode(@file_get_contents($stFile), true) : null;
		$stName = is_array($st) && !empty($st['state']) ? strtolower($st['state']) : '';
		if(socks_count_ok_on_disk() < 1 && !(function_exists('send_stop_requested') && send_stop_requested()))
		{
			if(!socks_filter_is_running() && ($stName === 'finished' || $stName === 'error' || $stName === 'idle' || $stName === ''))
			{
				@unlink(__DIR__.DIRECTORY_SEPARATOR.'socks5_filter_scratch_at.txt');
				socks_restart_full_filter_from_scratch('OK empty');
			}
			else if(!socks_filter_is_running())
				socks_restart_full_filter_from_scratch('waiting for OK');
		}
		if(function_exists('send_stop_requested') && send_stop_requested()) return 0;
		$fresh = parse_socks_file(socks_ok_path(), false);
		if(!empty($fresh))
		{
			$socks_ok = $fresh;
			$socks_proxies = $fresh;
			$socks_dead = array();
			$socks_index = 0;
			dash_js("dashProxy('".count($fresh)." OK'); dashMeta('OK proxies ready — starting send');");
			return count($fresh);
		}
		$tick++;
		$left = max(0, $deadline - time());
		$prog = is_array($st) ? intval(isset($st['progress'])?$st['progress']:0) : 0;
		$total = is_array($st) ? intval(isset($st['total'])?$st['total']:0) : 0;
		dash_js("dashMeta('Waiting for OK… ".$left."s · scan ".$prog."/".$total." · ".($stName?:'?')."'); dashProxy('scanning…');");
		if($tick % 5 === 1) dash_log('info', 'Still waiting for socks5_ok.txt ('.$left.'s, filter '.$stName.' '.$prog.'/'.$total.')…');
		// Short sleep so Stop is noticed quickly
		usleep(400000);
	}
	return 0;
}

function socks_start_background_filter($scratch = false)
{
	$dir = __DIR__;
	$script = $dir . DIRECTORY_SEPARATOR . 'filter_socks.php';
	$pidFile = $dir . DIRECTORY_SEPARATOR . 'socks5_filter.pid';
	$logFile = $dir . DIRECTORY_SEPARATOR . 'socks5_filter_run.log';
	if(!is_file($script)) return 'no_script';
	if(is_file($pidFile))
	{
		$pid = intval(trim(@file_get_contents($pidFile)));
		if($pid > 0 && socks_pid_alive($pid)) return 'already';
	}
	$php = 'C:\\xampp\\php\\php.exe';
	if(!is_file($php)) $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
	$args = escapeshellarg($script).($scratch ? ' scratch' : '');
	if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
	{
		$out = escapeshellarg($logFile);
		$err = escapeshellarg($logFile.'.err');
		$ps = "Start-Process -FilePath ".escapeshellarg($php)." -ArgumentList $args -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru | Select-Object -ExpandProperty Id";
		$pid = trim(@shell_exec('powershell -NoProfile -Command '.$ps));
		if($pid !== '' && intval($pid) > 0)
			@file_put_contents($pidFile, (string)intval($pid));
		else
		{
			pclose(popen('start /B "" '.escapeshellarg($php).' '.escapeshellarg($script).($scratch?' scratch':'').' >> '.escapeshellarg($logFile).' 2>&1', 'r'));
			@file_put_contents($pidFile, '1');
		}
		return 'started';
	}
	$cmd = 'nohup '.escapeshellarg($php).' '.escapeshellarg($script).($scratch?' scratch':'').' >> '.escapeshellarg($logFile).' 2>&1 & echo $!';
	$pid = trim(shell_exec($cmd));
	if($pid !== '') @file_put_contents($pidFile, $pid);
	return 'started';
}

/** If OK pool is empty: start full filter from scratch and wait for first fresh OK proxies */
function socks_refill_ok_or_wait($toLabel = '')
{
	$n = socks_count_ok_on_disk();
	if($n > 0)
	{
		load_socks_ok(true);
		return count($GLOBALS['socks_proxies']);
	}
	$r = socks_restart_full_filter_from_scratch($toLabel !== '' ? 'send '.$toLabel : 'OK empty');
	dash_log('warn', 'OK file empty → full parallel filter from scratch ('.$r.') — waiting for fresh proxies…');
	$got = socks_wait_for_ok_proxies(120);
	if($got > 0)
	{
		dash_log('ok', 'Fresh OK proxies: '.$got.' — resume send');
		dash_js("dashProxy('waiting→".$got." OK');");
		return $got;
	}
	return 0;
}

/**
 * Try send $to using current $mail + socks5_ok.txt.
 * Returns: ok | queued (wait file) | fail
 */
function mailer_socks_send_attempt($to)
{
	global $mail, $use_socks, $socks_proxies, $socks_proxy, $socks_index, $socks_dead, $default_system;

	$result = array('ok' => false, 'queued' => false, 'fail' => false, 'err' => '');
	$proxy_tries = 0;

	if($use_socks)
	{
		$added = socks_reload_ok_from_disk();
		if($added > 0) dash_log('ok', 'Filter added '.$added.' new OK proxy(ies) — now '.count($socks_proxies));
		$socks_dead = array();
		$socks_index = 0;
		if(empty($socks_proxies) || socks_count_ok_on_disk() < 1)
		{
			if(socks_refill_ok_or_wait($to) < 1)
			{
				dash_log('warn', 'WAIT '.$to.' — still no OK after re-scan → failed_emails.txt');
				socks_queue_failed_email($to);
				$result['queued'] = true;
				return $result;
			}
		}
		$max_proxy_tries = max(1, count($socks_proxies) + 5);
		socks_next_proxy();
		if(empty($socks_proxy))
		{
			if(socks_refill_ok_or_wait($to) < 1 || !socks_next_proxy())
			{
				dash_log('warn', 'WAIT '.$to.' — no OK proxies → failed_emails.txt');
				socks_queue_failed_email($to);
				$result['queued'] = true;
				return $result;
			}
		}
		dash_log('info', 'OK list '.count($socks_proxies).' — test then send');
	}
	else
	{
		$max_proxy_tries = 1;
	}

	$old_debug = $mail->SMTPDebug;
	$smtp_test_host = $mail->Host;
	$smtp_test_port = intval($mail->Port);

	while($proxy_tries < $max_proxy_tries)
	{
		if(function_exists('send_stop_requested') && send_stop_requested())
		{
			// Real stop — queue for later resume (not a hard FAIL)
			socks_queue_failed_email($to);
			$result['queued'] = true;
			$result['err'] = 'stopped';
			$mail->SMTPDebug = $old_debug;
			return $result;
		}
		$proxy_tries++;
		if($use_socks && $proxy_tries > 1) $mail->SMTPDebug = 0;

		if($use_socks && !empty($socks_proxy))
		{
			dash_js("dashProxy('".str_replace("'","\\'",$socks_proxy)."');");
			if(!socks_pretest($socks_proxy, $smtp_test_host, $smtp_test_port, 5))
			{
				dash_log('warn', 'Test fail '.$socks_proxy.' try '.$proxy_tries.' — next');
				// Memory skip only — keep socks5_ok.txt for other workers
				socks_mark_dead($socks_proxy);
				if(!socks_next_proxy())
				{
					socks_reload_ok_from_disk();
					$socks_dead = array();
					$socks_index = 0;
					if(!socks_next_proxy())
					{
						if(socks_count_ok_on_disk() < 1 && (socks_refill_ok_or_wait($to) < 1 || !socks_next_proxy()))
						{
							dash_log('warn', 'WAIT '.$to.' — OK list exhausted → failed_emails.txt');
							socks_queue_failed_email($to);
							$result['queued'] = true;
							$mail->SMTPDebug = $old_debug;
							return $result;
						}
						if(empty($socks_proxy))
						{
							dash_log('warn', 'WAIT '.$to.' — OK list exhausted → failed_emails.txt');
							socks_queue_failed_email($to);
							$result['queued'] = true;
							$mail->SMTPDebug = $old_debug;
							return $result;
						}
					}
				}
				continue;
			}
			dash_log('info', 'Test OK '.$socks_proxy.' — sending…');
		}

		if($mail->Send())
		{
			$mail->SMTPDebug = $old_debug;
			$result['ok'] = true;
			if(method_exists($mail, 'getLastMessageID'))
				$result['msgid'] = $mail->getLastMessageID();
			if(method_exists($mail, 'getSMTPInstance'))
			{
				$smtpObj = $mail->getSMTPInstance();
				if($smtpObj && method_exists($smtpObj, 'getLastReply'))
					$result['smtp_reply'] = trim($smtpObj->getLastReply());
			}
			if($use_socks && !empty($socks_proxy))
				$GLOBALS['socks_sticky_proxy'] = $socks_proxy;
			return $result;
		}

		$mail->SMTPDebug = $old_debug;
		$smtp_err = $mail->ErrorInfo;
		$is_connect_fail = (stripos($smtp_err, 'connect') !== false || stripos($smtp_err, 'Failed to connect') !== false || stripos($smtp_err, 'SOCKS') !== false || stripos($smtp_err, 'timed out') !== false || stripos($smtp_err, 'timeout') !== false);
		if($is_connect_fail && method_exists($mail, 'getSMTPInstance'))
		{
			$smtpObj = $mail->getSMTPInstance();
			if($smtpObj)
			{
				$le = $smtpObj->getError();
				if(!empty($le['error']) || !empty($le['detail']))
					$smtp_err .= ' | ' . trim(($le['error'] ?? '') . ' ' . ($le['detail'] ?? ''));
			}
		}
		if($is_connect_fail && $use_socks)
		{
			$cert_issue = (stripos($smtp_err, 'certificate') !== false || stripos($smtp_err, 'CN=') !== false || stripos($smtp_err, 'enable_crypto') !== false);
			dash_log('warn', ($cert_issue ? 'TLS/proxy ' : 'Send fail ').$socks_proxy.' try '.$proxy_tries);
			// Always skip this proxy in-memory; do NOT wipe shared OK file (10 workers were killing the pool)
			socks_mark_dead($socks_proxy);
			socks_reload_ok_from_disk();
			try { $mail->SmtpClose(); } catch(Exception $e) {}
			if(!socks_next_proxy())
			{
				$socks_dead = array();
				$socks_index = 0;
				socks_reload_ok_from_disk();
				if(!socks_next_proxy())
				{
					if(socks_count_ok_on_disk() < 1)
					{
						if(socks_refill_ok_or_wait($to) < 1 || !socks_next_proxy())
						{
							dash_log('warn', 'WAIT '.$to.' — OK list exhausted → failed_emails.txt');
							socks_queue_failed_email($to);
							$result['queued'] = true;
							return $result;
						}
					}
					else
					{
						dash_log('warn', 'WAIT '.$to.' — OK list exhausted → failed_emails.txt');
						socks_queue_failed_email($to);
						$result['queued'] = true;
						return $result;
					}
				}
			}
			dash_js("dashProxy('".str_replace("'","\\'",$socks_proxy)."');");
			continue;
		}
		if($is_connect_fail && !$use_socks)
			$smtp_err .= ' | Enable USE SOCKS5 or unblock ports 25/465/587';

		if($default_system != "1")
		{
			$result['fail'] = true;
			$result['err'] = $smtp_err ? $smtp_err : 'SMTP send failed';
			return $result;
		}
		$mail->IsMail();
		if($mail->Send())
		{
			$result['ok'] = true;
			return $result;
		}
		$result['fail'] = true;
		$result['err'] = $mail->ErrorInfo ? $mail->ErrorInfo : 'mail() failed';
		return $result;
	}

	// Exhausted proxy tries — queue for retry (never silent "not sent")
	if(!$result['ok'] && !$result['queued'] && !$result['fail'])
	{
		if($use_socks)
		{
			dash_log('warn', 'WAIT '.$to.' — proxy tries exhausted → failed_emails.txt');
			socks_queue_failed_email($to);
			$result['queued'] = true;
			$result['err'] = 'proxy tries exhausted';
		}
		else
		{
			$result['fail'] = true;
			$result['err'] = 'not sent';
		}
	}
	return $result;
}

/** SOCKS4a CONNECT (domain supported). SOCKS4 alone cannot resolve hostnames. */
function socks4_stream_connect($proxyLabel, $destHost, $destPort, $timeout = 4, &$errno = 0, &$errstr = '')
{
	$proxyHost = $proxyLabel;
	$proxyPort = 1080;
	if(strpos($proxyLabel, ':') !== false)
	{
		$parts = explode(':', $proxyLabel);
		$proxyHost = $parts[0];
		$proxyPort = intval($parts[1]);
	}
	if($timeout > 5) $timeout = 5;
	$fp = @stream_socket_client('tcp://'.$proxyHost.':'.$proxyPort, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
	if(!is_resource($fp)) return false;
	stream_set_timeout($fp, $timeout);

	$userId = '';
	if(!empty($GLOBALS['socks_proxy_auth']['user'])) $userId = (string)$GLOBALS['socks_proxy_auth']['user'];
	// SOCKS4a: DSTIP = 0.0.0.x (x!=0), then userid\0, then domain\0
	$req = "\x04\x01".pack('n', intval($destPort))."\x00\x00\x00\x01".$userId."\x00".$destHost."\x00";
	fwrite($fp, $req);
	$resp = @fread($fp, 8);
	if($resp === false || strlen($resp) < 8 || ord($resp[0]) !== 0x00 || ord($resp[1]) !== 0x5a)
	{
		$code = ($resp !== false && strlen($resp) > 1) ? ord($resp[1]) : 0;
		fclose($fp);
		$errstr = 'SOCKS4 CONNECT rejected (code '.$code.')';
		$errno = $code;
		return false;
	}
	stream_set_blocking($fp, true);
	return $fp;
}

function socks5_stream_connect($proxyLabel, $destHost, $destPort, $timeout = 4, &$errno = 0, &$errstr = '')
{
	$proxyHost = $proxyLabel;
	$proxyPort = 1080;
	if(strpos($proxyLabel, ':') !== false)
	{
		$parts = explode(':', $proxyLabel);
		$proxyHost = $parts[0];
		$proxyPort = intval($parts[1]);
	}
	// Fast fail on dead free proxies (don't wait 20s)
	if($timeout > 5) $timeout = 5;
	$fp = @stream_socket_client(
		'tcp://'.$proxyHost.':'.$proxyPort,
		$errno,
		$errstr,
		$timeout,
		STREAM_CLIENT_CONNECT
	);
	if(!is_resource($fp)) return false;
	stream_set_timeout($fp, $timeout);

	$auth = !empty($GLOBALS['socks_proxy_auth']['user']);
	if($auth)
	{
		// methods: no-auth + username/password
		fwrite($fp, "\x05\x02\x00\x02");
	}
	else
	{
		fwrite($fp, "\x05\x01\x00");
	}
	$resp = @fread($fp, 2);
	if($resp === false || strlen($resp) < 2 || $resp[0] !== "\x05")
	{
		fclose($fp);
		$errstr = 'SOCKS5 greeting failed';
		return false;
	}
	$method = ord($resp[1]);
	if($method === 0x02)
	{
		$user = $GLOBALS['socks_proxy_auth']['user'];
		$pass = $GLOBALS['socks_proxy_auth']['pass'];
		$authPkt = "\x01".chr(strlen($user)).$user.chr(strlen($pass)).$pass;
		fwrite($fp, $authPkt);
		$aresp = @fread($fp, 2);
		if($aresp === false || strlen($aresp) < 2 || ord($aresp[1]) !== 0)
		{
			fclose($fp);
			$errstr = 'SOCKS5 auth failed';
			return false;
		}
	}
	else if($method !== 0x00)
	{
		fclose($fp);
		$errstr = 'SOCKS5 method not accepted';
		return false;
	}

	// CONNECT to destination (domain name)
	$req = "\x05\x01\x00\x03".chr(strlen($destHost)).$destHost.pack('n', intval($destPort));
	fwrite($fp, $req);
	$hdr = @fread($fp, 4);
	if($hdr === false || strlen($hdr) < 4)
	{
		fclose($fp);
		$errstr = 'SOCKS5 connect truncated';
		return false;
	}
	if(ord($hdr[1]) !== 0x00)
	{
		$code = ord($hdr[1]);
		fclose($fp);
		$errstr = 'SOCKS5 CONNECT rejected (code '.$code.') — proxy may block SMTP';
		$errno = $code;
		return false;
	}
	$atyp = ord($hdr[3]);
	if($atyp === 1) @fread($fp, 6);          // IPv4 + port
	else if($atyp === 4) @fread($fp, 18);    // IPv6 + port
	else if($atyp === 3)
	{
		$len = ord(@fread($fp, 1));
		@fread($fp, $len + 2);
	}
	stream_set_blocking($fp, true);
	return $fp;
}

/** Dispatch SOCKS4 or SOCKS5 using $GLOBALS['socks_proto'] (default socks5). */
function socks_stream_connect($proxyLabel, $destHost, $destPort, $timeout = 4, &$errno = 0, &$errstr = '')
{
	$proto = !empty($GLOBALS['socks_proto']) ? strtolower($GLOBALS['socks_proto']) : 'socks5';
	if($proto === 'socks4' || $proto === 'socks4a')
		return socks4_stream_connect($proxyLabel, $destHost, $destPort, $timeout, $errno, $errstr);
	return socks5_stream_connect($proxyLabel, $destHost, $destPort, $timeout, $errno, $errstr);
}

/** Quick filter-style test: SOCKS4/5 → SMTP host:port → must get 220 banner. Does not send mail. */
function socks_pretest($proxyLabel, $destHost, $destPort, $timeout = 5)
{
	$errno = 0; $errstr = '';
	$fp = socks_stream_connect($proxyLabel, $destHost, intval($destPort), $timeout, $errno, $errstr);
	if(!is_resource($fp))
	{
		// If proto unknown, try the other one once
		$was = !empty($GLOBALS['socks_proto']) ? $GLOBALS['socks_proto'] : 'socks5';
		$GLOBALS['socks_proto'] = ($was === 'socks4') ? 'socks5' : 'socks4';
		$fp = socks_stream_connect($proxyLabel, $destHost, intval($destPort), $timeout, $errno, $errstr);
		if(!is_resource($fp))
		{
			$GLOBALS['socks_proto'] = $was;
			return false;
		}
	}
	$banner = @fgets($fp, 512);
	fclose($fp);
	return ($banner && strpos($banner, '220') === 0);
}

// REMOVE ALL HTML TAG WILL BE USED IN ALTBODY
function strip_tags_content($text, $tags = '', $invert = FALSE) { 

  /*preg_match_all('/<(.+?)[\s]*\/?[\s]*>/si', trim($tags), $tags); 
  $tags = array_unique($tags[1]); 
    
  if(is_array($tags) AND count($tags) > 0) { 
    if($invert == FALSE) { 
      return preg_replace('@<(?!(?:'. implode('|', $tags) .')\b)(\w+)\b.*?>.*?</\1>@si', '', $text); 
    } 
    else { 
      return preg_replace('@<('. implode('|', $tags) .')\b.*?>.*?</\1>@si', '', $text); 
    } 
  } 
  elseif($invert == FALSE) { 
    return preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $text); 
  } */
  return strip_tags($text); 
} 

// TRIM LEFT AND RIGHT
function lrtrim($string)
{
	return stripslashes(ltrim(rtrim($string)));
}
// REPLACE LAST OCCURENCE OF STRING
function str_lreplace($search, $replace, $subject)
{
    $pos = strrpos($subject, $search);

    if($pos !== false)
    {
        $subject = substr_replace($subject, $replace, $pos, strlen($search));
    }

    return $subject;
}
// RANDOM STRING FUNCTION
function generateRandomString($matches) 
{
	$length = intval(ltrim(rtrim($matches[3])));
	$PATTERN = str_lreplace('-','',$matches[2]);
	
    $characters = '';
	if(strpos($PATTERN, '09') !== false)
	$characters .= '0123456789';
	if(strpos($PATTERN, 'az') !== false)
	$characters .= 'abcdefghijklmnopqrstuvwxyz';
	if(strpos($PATTERN, 'AZ') !== false)
	$characters .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}
// Recipient greeting name from the mailbox (madalena_dordio@… → Madalena Dordio). Sender name stays what you type.
function mail_name_from_email($email)
{
	$email = trim((string)$email);
	if(preg_match('/<([^>]+)>/', $email, $m)) $email = trim($m[1]);
	$at = strpos($email, '@');
	$local = ($at === false) ? $email : substr($email, 0, $at);
	$plus = strpos($local, '+');
	if($plus !== false) $local = substr($local, 0, $plus);
	$local = str_replace(array('.', '_', '-'), ' ', $local);
	$local = trim(preg_replace('/\s+/', ' ', preg_replace('/[0-9]+/', ' ', $local)));
	if($local === '') return '';
	$small = array('da'=>1,'de'=>1,'do'=>1,'dos'=>1,'das'=>1,'e'=>1);
	$parts = preg_split('/\s+/', $local);
	$out = array();
	foreach($parts as $i => $p)
	{
		if($p === '') continue;
		if(function_exists('mb_strtolower'))
		{
			$p = mb_strtolower($p, 'UTF-8');
			if($i > 0 && isset($small[$p])) { $out[] = $p; continue; }
			$out[] = mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($p, 1, null, 'UTF-8');
		}
		else
		{
			$p = strtolower($p);
			if($i > 0 && isset($small[$p])) { $out[] = $p; continue; }
			$out[] = strtoupper(substr($p, 0, 1)).substr($p, 1);
		}
	}
	return implode(' ', $out);
}
// WAITING FUNCTION
function pause($pause,$mail)
{
	$sec=doubleval($pause);
	$mail->SmtpClose();
	dash_log('warn', 'Pause '.$sec.'s (anti-block)...');
	dash_js("dashMeta('Paused {$sec}s...');");
	sleep($sec);
}
// SMTP SWITCH
function switch_smtp()
{
	global $allsmtps;
	global $curentsmtp;
	global $mail;
	global $isbcc;
	global $from;
	global $from_base;
	global $lase;
	global $replyto;
	global $reading;
	global $repaslog;
	if(count($allsmtps) > $curentsmtp)
	{
	$line = $allsmtps[$curentsmtp];
	$smtprot = explode(':', $line);
	if(count($smtprot) >= 4)
	{
	$host = trim($smtprot[0]);
	$port = trim($smtprot[1]);
	$user = trim($smtprot[2]);
	$last = strtoupper(trim($smtprot[count($smtprot)-1]));
	$prev = count($smtprot) > 4 ? strtoupper(trim($smtprot[count($smtprot)-2])) : '';
	if(($last === 'BCC' || $last === 'NOBCC') && ($prev === 'SSL' || $prev === 'TLS' || $prev === 'NON'))
	{
	$enc = $prev;
	$bccflag = $last;
	$pass = implode(':', array_slice($smtprot, 3, count($smtprot)-5));
	}
	else if($last === 'SSL' || $last === 'TLS' || $last === 'NON')
	{
	$enc = $last;
	$bccflag = 'NOBCC';
	$pass = implode(':', array_slice($smtprot, 3, count($smtprot)-4));
	}
	else
	{
	$enc = 'TLS';
	$bccflag = 'NOBCC';
	$pass = implode(':', array_slice($smtprot, 3));
	}

	// Auto-fix common provider settings (mail.com needs 587+TLS or 465+SSL)
	$host_l = strtolower($host);
	if($port === '465' || $enc === 'SSL')
	{
	$port = '465';
	$enc = 'SSL';
	}
	else if($port === '587' || $enc === 'TLS' || preg_match('/mail\.com|gmx\.|outlook\.|office365|hotmail|yahoo|aol\./', $host_l))
	{
	if($port === '25' || $port === '' || $enc === 'NON')
	$port = '587';
	$enc = 'TLS';
	}

	$mail->Host = $host;
	$mail->Port = intval($port);
	$mail->Username = $user;
	$mail->Password = $pass;
	$mail->SMTPAuth = true;
	$mail->Timeout = 30;
	$mail->SMTPAutoTLS = true;
	$mail->SMTPOptions = array(
		'ssl' => array(
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
			'peer_name' => $host,
			'CN_match' => $host,
			'SNI_enabled' => true
		)
	);
	if($reading && $repaslog)
	$replyto = $user;
	// mail.com requires MAIL FROM = SMTP login (else 550 Sender address is not allowed)
	$from = $user;
	$from_base = $user;
	$mail->From = $user;
	$mail->Sender = $user;

	if($enc =="SSL")
	$mail->SMTPSecure  = "ssl";
	else if($enc =="TLS")
	$mail->SMTPSecure  = "tls";
	else
	$mail->SMTPSecure  = "";
	if($bccflag =="BCC")
	$isbcc = true;
	else $isbcc = false;
	
	}
	}
}

function mail_queue_is_smtp_lane()
{
	global $mail_queue_lane;
	return (isset($mail_queue_lane) && $mail_queue_lane === 'smtp');
}

function mail_queue_valid_path()
{
	$name = mail_queue_is_smtp_lane() ? 'validmails_smtp.txt' : 'validmails.txt';
	return __DIR__.DIRECTORY_SEPARATOR.$name;
}

function mail_queue_done_path()
{
	$name = mail_queue_is_smtp_lane() ? 'mailssenddone_smtp.txt' : 'mailssenddone.txt';
	return __DIR__.DIRECTORY_SEPARATOR.$name;
}

function mail_queue_progress_path()
{
	$name = mail_queue_is_smtp_lane() ? 'mail_queue_progress_smtp.json' : 'mail_queue_progress.json';
	return __DIR__.DIRECTORY_SEPARATOR.$name;
}

/**
 * Load emails from a file.
 * Keeps first-seen order; unique by lowercase.
 * validmails.txt is NEVER modified by the mailer — you replace the full list anytime.
 */
function mail_queue_load_file($path)
{
	if(!is_file($path)) return array();
	$raw = @file($path, FILE_IGNORE_NEW_LINES);
	if(!is_array($raw)) return array();
	$out = array();
	$seen = array();
	foreach($raw as $line)
	{
		$e = strtolower(trim($line));
		if($e === '' || strpos($e, '@') === false) continue;
		if(isset($seen[$e])) continue;
		$seen[$e] = true;
		$out[] = $e;
	}
	return $out;
}

/** Emails already sent OK (lowercase set) — resume cursor = skip these in validmails order. */
function mail_queue_done_set()
{
	$set = array();
	foreach(mail_queue_load_file(mail_queue_done_path()) as $e) $set[$e] = true;
	return $set;
}

/**
 * Next addresses to send: walk validmails.txt top→bottom, skip anything in mailssenddone.txt.
 * validmails is left untouched.
 */
function mail_queue_pending()
{
	$done = mail_queue_done_set();
	$pending = array();
	$firstIndex = null;
	$i = 0;
	foreach(mail_queue_load_file(mail_queue_valid_path()) as $e)
	{
		$i++;
		if(isset($done[$e])) continue;
		if($firstIndex === null) $firstIndex = $i;
		$pending[] = $e;
	}
	return array('emails' => $pending, 'resume_line' => $firstIndex);
}

function mail_queue_counts()
{
	$valid = mail_queue_load_file(mail_queue_valid_path());
	$done = mail_queue_done_set();
	$pending = 0;
	$resume = null;
	$line = 0;
	foreach($valid as $e)
	{
		$line++;
		if(isset($done[$e])) continue;
		$pending++;
		if($resume === null) $resume = $line;
	}
	return array(
		'valid' => count($valid),
		'done' => count($done),
		'pending' => $pending,
		'resume_line' => $resume
	);
}

/** Append one OK recipient to mailssenddone.txt (never deletes from validmails). */
function mail_queue_mark_done($email)
{
	$email = strtolower(trim($email));
	if($email === '' || strpos($email, '@') === false) return false;
	// Avoid duplicate lines if somehow retried
	static $mem = null;
	if($mem === null) $mem = mail_queue_done_set();
	if(isset($mem[$email])) return true;
	$path = mail_queue_done_path();
	$fh = @fopen($path, 'a');
	if(!$fh) return false;
	flock($fh, LOCK_EX);
	fwrite($fh, $email."\n");
	flock($fh, LOCK_UN);
	fclose($fh);
	$mem[$email] = true;
	// Lightweight live counters for dashboard Refresh (never stops send)
	static $okN = 0;
	$okN++;
	if($okN === 1 || $okN % 10 === 0)
	{
		$c = mail_queue_counts();
		if(function_exists('dash_live_state_write'))
			dash_live_state_write(array(
				'session_ok_ticks' => $okN,
				'queue_pending' => $c['pending'],
				'queue_done' => $c['done'],
				'queue_valid' => $c['valid'],
				'resume_line' => $c['resume_line']
			));
	}
	return true;
}

/**
 * Professional progress snapshot (does not touch validmails.txt).
 * Lets you see where the queue resumes next run.
 */
function mail_queue_save_progress($extra = array())
{
	$counts = mail_queue_counts();
	$row = array_merge(array(
		'updated_at' => date('c'),
		'valid_file' => mail_queue_is_smtp_lane() ? 'validmails_smtp.txt' : 'validmails.txt',
		'done_file' => mail_queue_is_smtp_lane() ? 'mailssenddone_smtp.txt' : 'mailssenddone.txt',
		'valid_total' => $counts['valid'],
		'done_total' => $counts['done'],
		'pending_total' => $counts['pending'],
		'resume_line_in_validmails' => $counts['resume_line'],
		'note' => 'validmails.txt is never modified. Progress = mailssenddone.txt. Next Send skips done addresses in list order.'
	), $extra);
	@file_put_contents(mail_queue_progress_path(), json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	return $row;
}

/** Total send room across all Brevo API accounts (mailer limit ∩ credits). */
function brevo_api_total_room()
{
	global $brevo_api_accounts;
	$sum = 0;
	foreach($brevo_api_accounts as $id => $acc)
		$sum += brevo_api_room_left($id);
	return $sum;
}

/**
 * Multi HTML/subject variants — split across accounts at send time.
 * POST field: content_variants_json = [{"subject":"...","message":"..."}, ...]
 */
function content_variants_from_post($json, $fallbackSubject, $fallbackMessage)
{
	$out = array();
	$decoded = json_decode((string)$json, true);
	if(is_array($decoded))
	{
		foreach($decoded as $row)
		{
			if(!is_array($row)) continue;
			$s = isset($row['subject']) ? lrtrim((string)$row['subject']) : '';
			$m = isset($row['message']) ? lrtrim((string)$row['message']) : '';
			if($s === '' && $m === '') continue;
			$out[] = array('subject' => $s, 'message' => $m);
		}
	}
	if(empty($out))
	{
		$fs = lrtrim((string)$fallbackSubject);
		$fm = lrtrim((string)$fallbackMessage);
		if($fs !== '' || $fm !== '')
			$out[] = array('subject' => $fs, 'message' => $fm);
	}
	return $out;
}

/** Assign variants onto N account slots (2→4 share; 5→4 distribute + rotate). */
function content_variants_prepare($nSlots)
{
	global $content_variants, $content_variant_buckets, $content_variant_cursor, $subject_base, $message_base;
	$nSlots = max(1, intval($nSlots));
	if(empty($content_variants))
		$content_variants = array(array('subject' => (string)$subject_base, 'message' => (string)$message_base));
	$n = count($content_variants);
	$content_variant_buckets = array();
	$content_variant_cursor = array();
	for($i = 0; $i < $nSlots; $i++)
	{
		$content_variant_buckets[$i] = array();
		$content_variant_cursor[$i] = 0;
	}
	if($n <= $nSlots)
	{
		for($i = 0; $i < $nSlots; $i++)
			$content_variant_buckets[$i][] = $content_variants[$i % $n];
	}
	else
	{
		for($vi = 0; $vi < $n; $vi++)
			$content_variant_buckets[$vi % $nSlots][] = $content_variants[$vi];
	}
}

function content_variant_for_slot($slot, $advance = true)
{
	global $content_variant_buckets, $content_variant_cursor, $subject_base, $message_base;
	$slot = intval($slot);
	if(empty($content_variant_buckets[$slot]))
		return array('subject' => (string)$subject_base, 'message' => (string)$message_base, 'index' => 0);
	$list = $content_variant_buckets[$slot];
	$c = isset($content_variant_cursor[$slot]) ? intval($content_variant_cursor[$slot]) : 0;
	$pick = $c % count($list);
	if($advance && count($list) > 1)
		$content_variant_cursor[$slot] = $c + 1;
	else if($advance && count($list) === 1)
		$content_variant_cursor[$slot] = $c; // stable: one HTML per account
	$v = $list[$pick];
	return array(
		'subject' => isset($v['subject']) ? (string)$v['subject'] : (string)$subject_base,
		'message' => isset($v['message']) ? (string)$v['message'] : (string)$message_base,
		'index' => $pick
	);
}

function content_variant_smtp_slot()
{
	global $smtp_mode, $smtp_accounts, $smtp_account_of, $curentsmtp, $allsmtps;
	if($smtp_mode === 'brevo' && !empty($smtp_accounts) && isset($smtp_account_of[$curentsmtp]))
		return intval($smtp_account_of[$curentsmtp]);
	return max(0, intval($curentsmtp));
}

/**
 * Parse Brevo API accounts:
 * #ACCOUNT 300
 * xkeysib-...
 * from: you@domain.com
 * name: From Name
 */
function parse_brevo_api_blocks($text)
{
	$accounts = array();
	$lines = preg_split("/\\r\\n|\\r|\\n/", (string)$text);
	$cur = null;
	$flush = function() use (&$accounts, &$cur) {
		if($cur !== null && !empty($cur['key']))
		{
			if(empty($cur['limit'])) $cur['limit'] = 300;
			$accounts[] = $cur;
		}
		$cur = null;
	};
	foreach($lines as $line)
	{
		$line = trim($line);
		if($line === '') continue;
		if(preg_match('/^#\s*ACCOUNT\s+(\d+)/i', $line, $m))
		{
			$flush();
			$cur = array('limit' => max(1, intval($m[1])), 'key' => '', 'from' => '', 'name' => '', 'problem' => false);
			continue;
		}
		if($line[0] === '#') continue;
		if(preg_match('/^from\s*[:=]\s*(.+)$/i', $line, $m))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '', 'problem' => false);
			$cur['from'] = trim($m[1]);
			continue;
		}
		if(preg_match('/^name\s*[:=]\s*(.+)$/i', $line, $m))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '', 'problem' => false);
			$cur['name'] = trim($m[1]);
			continue;
		}
		if(preg_match('/^problem\s*[:=]\s*(1|yes|true|on)\s*$/i', $line))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '', 'problem' => false);
			$cur['problem'] = true;
			continue;
		}
		// API key line (xkeysib-… or any long token)
		if(preg_match('/^xkeysib-/i', $line) || (strlen($line) > 40 && strpos($line, ' ') === false && strpos($line, '@') === false))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '', 'problem' => false);
			// New bare key without #ACCOUNT after a filled account → start next at 300
			if($cur['key'] !== '')
			{
				$flush();
				$cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '', 'problem' => false);
			}
			$cur['key'] = $line;
			continue;
		}
	}
	$flush();
	return $accounts;
}

/** Effective send room left for one API account (mailer limit ∩ Brevo credits). */
function brevo_api_room_left($id)
{
	global $brevo_api_accounts, $brevo_api_sent, $brevo_api_credits, $brevo_api_exhausted;
	$id = intval($id);
	if(!empty($brevo_api_exhausted[$id])) return 0;
	if(empty($brevo_api_accounts[$id])) return 0;
	$used = isset($brevo_api_sent[$id]) ? intval($brevo_api_sent[$id]) : 0;
	$lim = isset($brevo_api_accounts[$id]['limit']) ? intval($brevo_api_accounts[$id]['limit']) : 300;
	$mailerLeft = max(0, $lim - $used);
	if(isset($brevo_api_credits[$id]) && $brevo_api_credits[$id] !== null)
	{
		$credLeft = max(0, intval(floor($brevo_api_credits[$id])));
		return min($mailerLeft, $credLeft);
	}
	return $mailerLeft;
}

function dash_live_state_write($extra = array())
{
	global $brevo_api_accounts, $brevo_api_sent, $brevo_api_credits, $brevo_api_exhausted, $brevo_api_cur, $smtp_mode, $mail_queue_campaign;
	$rows = array();
	if(isset($smtp_mode) && $smtp_mode === 'brevo_api' && !empty($brevo_api_accounts))
	{
		foreach($brevo_api_accounts as $id => $acc)
		{
			$left = null;
			if(isset($brevo_api_credits[$id]) && $brevo_api_credits[$id] !== null)
				$left = intval(floor($brevo_api_credits[$id]));
			if(!empty($brevo_api_exhausted[$id])) $left = 0;
			$rows[] = array(
				'id' => intval($id),
				'from' => !empty($acc['from']) ? $acc['from'] : '',
				'lim' => isset($acc['limit']) ? intval($acc['limit']) : 300,
				'left' => $left,
				'used' => isset($brevo_api_sent[$id]) ? intval($brevo_api_sent[$id]) : 0,
				'exhausted' => !empty($brevo_api_exhausted[$id])
			);
		}
	}
	$row = array_merge(array(
		'updated_at' => date('c'),
		'mode' => isset($smtp_mode) ? $smtp_mode : '',
		'campaign' => !empty($mail_queue_campaign),
		'api_accounts' => $rows,
		'api_active' => isset($brevo_api_cur) ? intval($brevo_api_cur) : -1
	), $extra);
	@file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'dash_live_state.json', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function brevo_api_dash_snapshot()
{
	global $brevo_api_accounts, $brevo_api_sent, $brevo_api_credits, $brevo_api_exhausted, $brevo_api_cur;
	$rows = array();
	foreach($brevo_api_accounts as $id => $acc)
	{
		$left = null;
		if(isset($brevo_api_credits[$id]) && $brevo_api_credits[$id] !== null)
			$left = intval(floor($brevo_api_credits[$id]));
		if(!empty($brevo_api_exhausted[$id])) $left = 0;
		$rows[] = array(
			'id' => intval($id),
			'from' => !empty($acc['from']) ? $acc['from'] : '',
			'lim' => isset($acc['limit']) ? intval($acc['limit']) : 300,
			'left' => $left,
			'used' => isset($brevo_api_sent[$id]) ? intval($brevo_api_sent[$id]) : 0,
			'exhausted' => !empty($brevo_api_exhausted[$id])
		);
	}
	$active = isset($brevo_api_cur) ? intval($brevo_api_cur) : -1;
	dash_live_state_write(array('api_accounts' => $rows, 'api_active' => $active));
	dash_js('dashApiSetAccounts('.json_encode($rows, JSON_UNESCAPED_UNICODE).','.$active.');');
}

function brevo_api_mark_exhausted($id, $reason = '')
{
	global $brevo_api_exhausted, $brevo_api_credits;
	$id = intval($id);
	if(!empty($brevo_api_exhausted[$id])) return;
	$brevo_api_exhausted[$id] = true;
	$brevo_api_credits[$id] = 0;
	dash_log('warn', 'Brevo API account '.($id + 1).' empty — skip to next'.($reason !== '' ? ' ('.$reason.')' : ''));
	brevo_api_dash_snapshot();
}

/** Use credits fetched once at Send start only — never re-calls Brevo API. */
function brevo_api_ensure_credits($id)
{
	global $brevo_api_accounts, $brevo_api_credits, $brevo_api_exhausted;
	$id = intval($id);
	if(!empty($brevo_api_exhausted[$id])) return false;
	if(empty($brevo_api_accounts[$id]['key'])) return false;
	if(isset($brevo_api_credits[$id]) && $brevo_api_credits[$id] !== null && floatval($brevo_api_credits[$id]) < 1)
	{
		brevo_api_mark_exhausted($id, '0 Brevo credits');
		return false;
	}
	return brevo_api_room_left($id) >= 1;
}

function brevo_api_pick_next()
{
	global $brevo_api_accounts, $brevo_api_cur;
	if(empty($brevo_api_accounts)) return false;
	$prev = isset($brevo_api_cur) ? intval($brevo_api_cur) : -1;
	foreach($brevo_api_accounts as $id => $acc)
	{
		if(!brevo_api_ensure_credits($id)) continue;
		if(brevo_api_room_left($id) < 1) continue;
		$switched = ($prev >= 0 && $prev !== intval($id));
		if($switched)
			dash_log('info', 'Switching → API account '.($id + 1).' (account '.($prev + 1).' empty or full)');
		$brevo_api_cur = $id;
		// UI update only when account changes (no Brevo API call)
		if($switched || $prev < 0)
			brevo_api_dash_snapshot();
		return $acc;
	}
	return false;
}

function brevo_api_note_sent_ok()
{
	global $brevo_api_cur, $brevo_api_sent, $brevo_api_accounts, $brevo_api_credits;
	$id = intval($brevo_api_cur);
	if(!isset($brevo_api_sent[$id])) $brevo_api_sent[$id] = 0;
	$brevo_api_sent[$id]++;
	if(isset($brevo_api_credits[$id]) && $brevo_api_credits[$id] !== null)
		$brevo_api_credits[$id] = max(0, floatval($brevo_api_credits[$id]) - 1);
	$lim = isset($brevo_api_accounts[$id]['limit']) ? intval($brevo_api_accounts[$id]['limit']) : 300;
	$credTxt = isset($brevo_api_credits[$id]) && $brevo_api_credits[$id] !== null
		? (' · Brevo left ~'.intval(floor($brevo_api_credits[$id])))
		: '';
	dash_log('info', 'Brevo API account '.($id + 1).' quota '.$brevo_api_sent[$id].'/'.$lim.$credTxt);
	if(brevo_api_room_left($id) < 1)
		brevo_api_mark_exhausted($id, 'mailer limit or Brevo credits hit 0 — next mail uses next account');
	else
		brevo_api_dash_snapshot();
}

function brevo_api_err_is_credits($err)
{
	$e = strtolower((string)$err);
	if(strpos($e, 'http 402') !== false || preg_match('/\b402\b/', $e)) return true;
	return (strpos($e, 'credit') !== false || strpos($e, 'quota') !== false
		|| strpos($e, 'not enough') !== false || strpos($e, 'exceed') !== false
		|| (strpos($e, 'plan') !== false && (strpos($e, 'limit') !== false || strpos($e, 'over') !== false)));
}

/** Remaining email credits from Brevo GET /v3/account (plan sendLimit). */
function brevo_api_fetch_credits_left($apiKey)
{
	$out = array('ok' => false, 'credits' => null, 'plan_type' => '', 'end_date' => '', 'err' => '');
	if(!function_exists('curl_init') || trim((string)$apiKey) === '')
	{
		$out['err'] = 'curl/key missing';
		return $out;
	}
	$ch = curl_init('https://api.brevo.com/v3/account');
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array('accept: application/json', 'api-key: '.trim($apiKey)),
		CURLOPT_TIMEOUT => 20,
		CURLOPT_CONNECTTIMEOUT => 10
	));
	$body = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if($body === false || $cerr !== '')
	{
		$out['err'] = $cerr !== '' ? $cerr : 'curl failed';
		return $out;
	}
	$json = json_decode($body, true);
	if($code < 200 || $code >= 300 || !is_array($json))
	{
		$out['err'] = 'HTTP '.$code;
		return $out;
	}
	$best = null;
	if(!empty($json['plan']) && is_array($json['plan']))
	{
		foreach($json['plan'] as $p)
		{
			if(!is_array($p) || (isset($p['creditsType']) && $p['creditsType'] !== 'sendLimit')) continue;
			if(isset($p['type']) && $p['type'] === 'sms') continue;
			$cand = array(
				'credits' => isset($p['credits']) ? floatval($p['credits']) : 0,
				'type' => isset($p['type']) ? $p['type'] : '',
				'end' => !empty($p['endDate']) ? $p['endDate'] : ''
			);
			if($best === null || $cand['type'] === 'free' || $cand['type'] === 'subscription') $best = $cand;
		}
	}
	if($best === null)
	{
		$out['err'] = 'no sendLimit';
		return $out;
	}
	$out['ok'] = true;
	$out['credits'] = $best['credits'];
	$out['plan_type'] = $best['type'];
	$out['end_date'] = $best['end'];
	return $out;
}

/**
 * Send one email via Brevo Transactional API (HTTP).
 * @return array{ok:bool, err:string, id:string}
 */
function brevo_api_send_email($apiKey, $toEmail, $fromEmail, $fromName, $subject, $htmlOrText, $isHtml, $replyTo = '')
{
	$out = array('ok' => false, 'err' => '', 'id' => '');
	$apiKey = trim((string)$apiKey);
	$toEmail = trim((string)$toEmail);
	$fromEmail = trim((string)$fromEmail);
	if($apiKey === '' || $toEmail === '' || $fromEmail === '' || strpos($fromEmail, '@') === false)
	{
		$out['err'] = 'Missing API key, From email, or To';
		return $out;
	}
	if(!function_exists('curl_init'))
	{
		$out['err'] = 'PHP curl extension required for Brevo API';
		return $out;
	}
	$payload = array(
		'sender' => array(
			'name' => $fromName !== '' ? $fromName : $fromEmail,
			'email' => $fromEmail
		),
		'to' => array(array('email' => $toEmail)),
		'subject' => (string)$subject
	);
	if($isHtml)
		$payload['htmlContent'] = (string)$htmlOrText;
	else
		$payload['textContent'] = (string)$htmlOrText;
	if($replyTo !== '' && strpos($replyTo, '@') !== false)
		$payload['replyTo'] = array('email' => $replyTo);

	$ch = curl_init('https://api.brevo.com/v3/smtp/email');
	curl_setopt_array($ch, array(
		CURLOPT_POST => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array(
			'accept: application/json',
			'content-type: application/json',
			'api-key: '.$apiKey
		),
		CURLOPT_POSTFIELDS => json_encode($payload),
		CURLOPT_TIMEOUT => 60,
		CURLOPT_CONNECTTIMEOUT => 20
	));
	$body = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if($body === false || $cerr !== '')
	{
		$out['err'] = $cerr !== '' ? $cerr : 'curl failed';
		return $out;
	}
	$json = json_decode($body, true);
	if($code >= 200 && $code < 300)
	{
		$out['ok'] = true;
		if(is_array($json) && !empty($json['messageId'])) $out['id'] = $json['messageId'];
		return $out;
	}
	$msg = '';
	if(is_array($json))
	{
		if(!empty($json['message'])) $msg = $json['message'];
		else if(!empty($json['error'])) $msg = is_string($json['error']) ? $json['error'] : json_encode($json['error']);
	}
	if($msg === '') $msg = substr($body, 0, 200);
	$out['err'] = 'HTTP '.$code.($msg !== '' ? ' — '.$msg : '');
	$out['http'] = $code;
	return $out;
}

/**
 * Parse Brevo blocks:
 * #ACCOUNT 500
 * host:port:user:pass:TLS
 * host:port:user:pass:TLS
 */
function parse_brevo_smtp_blocks($text)
{
	$allsmtps = array();
	$accounts = array();
	$account_of = array();
	$lines = preg_split("/\\r\\n|\\r|\\n/", (string)$text);
	$cur = null;
	foreach($lines as $line)
	{
		$line = trim($line);
		if($line === '') continue;
		if(preg_match('/^#\s*ACCOUNT\s+(\d+)/i', $line, $m))
		{
			if($cur !== null && !empty($cur['indices']))
			$accounts[] = $cur;
			$cur = array('limit' => max(1, intval($m[1])), 'indices' => array());
			continue;
		}
		if($line[0] === '#') continue;
		if($cur === null)
		$cur = array('limit' => 500, 'indices' => array());
		$idx = count($allsmtps);
		$allsmtps[] = $line;
		$account_of[$idx] = count($accounts);
		$cur['indices'][] = $idx;
	}
	if($cur !== null && !empty($cur['indices']))
	$accounts[] = $cur;
	// fix account_of ids (were provisional while building)
	$account_of = array();
	foreach($accounts as $aid => $acc)
	{
		foreach($acc['indices'] as $idx)
		$account_of[$idx] = $aid;
	}
	return array('allsmtps' => $allsmtps, 'accounts' => $accounts, 'account_of' => $account_of);
}

// Round-robin: normal = per-SMTP limit; Brevo = finish account A then B (shared limit)
function smtp_pick_next($after_idx = null)
{
	global $allsmtps, $curentsmtp, $nrotat, $smtp_sent, $smtp_mode, $smtp_accounts, $smtp_account_of, $account_sent;
	$total = count($allsmtps);
	if($total < 1) return false;

	if($smtp_mode === 'brevo' && !empty($smtp_accounts))
	{
		// Find first account that still has daily room
		$aid = null;
		foreach($smtp_accounts as $id => $acc)
		{
			$used = isset($account_sent[$id]) ? intval($account_sent[$id]) : 0;
			if($used < intval($acc['limit'])) { $aid = $id; break; }
		}
		if($aid === null) return false;

		$indices = $smtp_accounts[$aid]['indices'];
		if(empty($indices)) return false;

		// Round-robin inside this account only (splits the 500 across its SMTPs)
		$pos = 0;
		if($after_idx !== null && in_array($after_idx, $indices, true))
		{
			$found = array_search($after_idx, $indices, true);
			$pos = ($found === false) ? 0 : (($found + 1) % count($indices));
		}
		else if($after_idx === null && in_array($curentsmtp, $indices, true))
		{
			$found = array_search($curentsmtp, $indices, true);
			$pos = ($found === false) ? 0 : (($found + 1) % count($indices));
		}
		$curentsmtp = $indices[$pos];
		return true;
	}

	$start = ($after_idx === null) ? $curentsmtp : (($after_idx + 1) % $total);
	for($i = 0; $i < $total; $i++)
	{
	$idx = ($start + $i) % $total;
	$sent = isset($smtp_sent[$idx]) ? intval($smtp_sent[$idx]) : 0;
	if($sent < intval($nrotat))
	{
	$curentsmtp = $idx;
	return true;
	}
	}
	return false;
}

function smtp_note_sent_ok()
{
	global $curentsmtp, $smtp_sent, $smtp_mode, $smtp_account_of, $account_sent, $smtp_accounts, $nrotat;
	if(!isset($smtp_sent[$curentsmtp])) $smtp_sent[$curentsmtp] = 0;
	$smtp_sent[$curentsmtp]++;
	if($smtp_mode === 'brevo' && isset($smtp_account_of[$curentsmtp]))
	{
		$aid = $smtp_account_of[$curentsmtp];
		if(!isset($account_sent[$aid])) $account_sent[$aid] = 0;
		$account_sent[$aid]++;
		$lim = isset($smtp_accounts[$aid]['limit']) ? intval($smtp_accounts[$aid]['limit']) : 500;
		dash_log('info', 'Brevo account '.($aid+1).' quota '.$account_sent[$aid].'/'.$lim.' (SMTP '.($curentsmtp+1).')');
		return;
	}
	dash_log('info', 'SMTP '.($curentsmtp+1).' quota '.$smtp_sent[$curentsmtp].'/'.$nrotat);
}

function dash_flush()
{
	if(function_exists('ob_get_level'))
	{
		while(ob_get_level() > 0) { @ob_end_flush(); }
	}
	@ob_implicit_flush(true);
	@flush();
}

function dash_boot($total, $smtpCount, $proxyCount = 0)
{
	global $smtp_mode, $use_socks;
	$total = intval($total);
	$smtpCount = intval($smtpCount);
	$proxyCount = intval($proxyCount);
	$isApi = (isset($smtp_mode) && $smtp_mode === 'brevo_api');
	$isBrevo = (isset($smtp_mode) && ($smtp_mode === 'brevo' || $smtp_mode === 'brevo_api'));
	$showSocks = !$isBrevo && !empty($use_socks);
	$twoCol = $showSocks || $isApi;
	$sub = $isApi ? 'Brevo API · Stop saves pending for later' : ($isBrevo ? 'Brevo SMTP · Stop saves pending for later' : 'Send via socks5_ok.txt · Stop saves pending for later');
	echo '<script>document.body.classList.add("send-mode");</script>';
	echo '<div id="send-dash" class="send-dash">';
	echo '<div class="hd"><div class="hd-left"><span class="badge" id="live-badge"></span><h2>Campaign Control<small>'.htmlspecialchars($sub).'</small></h2></div><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button type="button" id="btn-dash-refresh" style="background:#1b2838;color:#e6edf5;border:1px solid #3d4f63;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer" title="Reload counters from disk — does not stop send">Refresh</button><button type="button" id="btn-stop-send" style="background:#c0392b;color:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer">Stop send</button><a id="btn-back-setup" href="index.php" style="display:none;background:#2d6a4f;color:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;text-decoration:none">Back to setup</a><div class="status" id="dash-status">Starting…</div></div></div>';

	echo '<div class="grid"'.($twoCol?'':' style="grid-template-columns:1fr"').'>';
	echo '<div class="panel"><div class="pt">Send pipeline <em id="d-send-state">RUNNING</em></div>';
	echo '<div class="stats">';
	echo '<div class="stat"><b id="d-total">'.$total.'</b><span>Targets</span></div>';
	echo '<div class="stat"><b id="d-done">0</b><span>Processed</span></div>';
	echo '<div class="stat ok"><b id="d-ok">0</b><span>Sent OK</span></div>';
	echo '<div class="stat fail"><b id="d-fail">0</b><span>Failed</span></div>';
	echo '<div class="stat fail"><b id="d-queue">0</b><span>Queued (retry file)</span></div>';
	echo '<div class="stat info"><b id="d-smtp">-</b><span>'.($isApi?'API accounts × '.$smtpCount:'SMTP × '.$smtpCount).'</span></div>';
	if($showSocks)
	echo '<div class="stat info"><b id="d-proxy">'.($proxyCount>0?$proxyCount.' OK':'off').'</b><span>OK proxies</span></div>';
	else
	echo '<div class="stat info"><b id="d-proxy">'.($isApi?'Brevo API':'direct').'</b><span>Transport</span></div>';
	echo '</div>';
	echo '<div class="barwrap"><div class="barlab"><span>Send progress</span><span id="d-send-pct">0%</span></div><div class="bar"><i id="d-bar"></i></div></div>';
	echo '<div class="meta" id="d-meta">Preparing campaign…</div></div>';

	if($isApi)
	{
	echo '<div class="panel" id="panel-api-credits"><div class="pt">Brevo credits <em id="api-cred-state">LIVE</em></div>';
	echo '<div class="api-credits">';
	echo '<div class="api-total"><div><span>Total left (all accounts)</span><b id="api-total-left">—</b></div><div style="text-align:right"><span>Accounts</span><b id="api-acct-count" style="font-size:18px;color:var(--info)">'.$smtpCount.'</b></div></div>';
	echo '<div class="api-active"><span id="api-active-label">Active account — left now</span><b id="api-active-left">—</b></div>';
	echo '<div id="api-acct-list"></div>';
	echo '</div></div>';
	}
	else if($showSocks)
	{
	echo '<div class="panel" id="panel-proxy-scan"><div class="pt">Full proxy scan <em id="f-state">—</em></div>';
	echo '<div class="filt-stats">';
	echo '<div class="kv"><b id="f-prog">—</b><span>Scanned</span></div>';
	echo '<div class="kv"><b id="f-pct">0%</b><span>Scan %</span></div>';
	echo '<div class="kv"><b id="f-work">'.$proxyCount.'</b><span>Live OK file</span></div>';
	echo '<div class="kv"><b id="f-upd">—</b><span>Last update</span></div>';
	echo '</div>';
	echo '<div class="barwrap"><div class="barlab"><span><span class="dot" id="f-dot"></span>Full list filter</span><span id="f-last">…</span></div><div class="bar filt"><i id="f-bar"></i></div></div>';
	echo '<div class="filt-msg" id="f-msg">FINISHED = full scan done. Live OK count still updates during send.</div></div>';
	}
	echo '</div>';

	echo '<div class="log-wrap"><div class="log-grid"'.($showSocks?'':' style="grid-template-columns:1fr"').'>';
	echo '<div class="log-col"><div class="pt">Send / errors (OK · FAIL · retry)</div><div class="log" id="d-log"></div></div>';
	if($showSocks)
	echo '<div class="log-col"><div class="pt">Send proxy tests (not full scan)</div><div class="log" id="d-log-proxy"></div></div>';
	else
	echo '<div class="log-col" style="display:none"><div class="log" id="d-log-proxy"></div></div>';
	echo '</div></div>';
	echo '</div>';

	$pollFilterJs = $showSocks ? 'true' : 'false';
	echo '<script>
window.__dash={ok:0,fail:0,queue:0,done:0,total:'.$total.',filterPoll:null,pollFilter:'.$pollFilterJs.',apiAccounts:[],apiActive:-1};
function dashApiRender(){
  var list=window.__dash.apiAccounts||[];
  var active=window.__dash.apiActive;
  var total=0, known=0;
  list.forEach(function(a){
    if(a.exhausted) return;
    if(a.left!=null && a.left!==""){ total+=Math.max(0,Math.floor(a.left)); known++; }
  });
  dashSet("api-total-left", known? String(Math.floor(total)) : "—");
  dashSet("api-acct-count", String(list.length));
  var act=null;
  for(var i=0;i<list.length;i++){ if(list[i].id===active){ act=list[i]; break; } }
  if(act){
    dashSet("api-active-label", "Account "+(act.id+1)+(act.from?(" · "+act.from):"")+(act.exhausted?" (empty)":" — left now"));
    dashSet("api-active-left", act.exhausted? "0" : (act.left!=null? String(Math.floor(act.left)) : "—"));
  } else {
    dashSet("api-active-label", "Active account — left now");
    dashSet("api-active-left", "—");
  }
  var wrap=document.getElementById("api-acct-list");
  if(!wrap) return;
  wrap.innerHTML="";
  list.forEach(function(a){
    var row=document.createElement("div");
    row.className="api-row"+(a.id===active?" on":"")+(a.exhausted?" dead":"");
    var leftTxt=a.exhausted? "0 left" : (a.left!=null? (Math.floor(a.left)+" left") : "? left");
    var usedTxt=(a.used!=null?a.used:0)+"/"+(a.lim||300);
    row.innerHTML="<div><b>Account "+(a.id+1)+"</b><div class=\\"muted\\">"+__esc(a.from||"")+(a.id===active?" · SENDING":"")+(a.exhausted?" · EMPTY":"")+"</div></div><div style=\\"text-align:right\\"><b>"+leftTxt+"</b><div class=\\"muted\\">sent "+usedTxt+"</div></div>";
    wrap.appendChild(row);
  });
}
function dashApiSetAccounts(list, activeId){
  window.__dash.apiAccounts=list||[];
  if(typeof activeId==="number") window.__dash.apiActive=activeId;
  dashApiRender();
}
function dashApiUpdate(id, left, used, exhausted){
  var list=window.__dash.apiAccounts||[];
  for(var i=0;i<list.length;i++){
    if(list[i].id===id){
      if(left!=null) list[i].left=left;
      if(used!=null) list[i].used=used;
      if(exhausted) list[i].exhausted=true;
      break;
    }
  }
  dashApiRender();
}
function dashApiActive(id){ window.__dash.apiActive=id; dashApiRender(); }
function __esc(s){return String(s).replace(/[&<>"\']/g,function(c){return({"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#39;"})[c];});}
function dashLog(type,msg){
  var proxyNoise = (type==="warn") || /^Test /i.test(msg) || /^Send fail /i.test(msg) || /^TLS\\/proxy /i.test(msg) || /^OK list /i.test(msg);
  var log=document.getElementById(proxyNoise ? "d-log-proxy" : "d-log");
  if(!log) log=document.getElementById("d-log");
  if(!log) return;
  var d=document.createElement("div"); d.className="row "+type;
  var t=new Date().toLocaleTimeString();
  d.innerHTML="<span class=\\"t\\">"+t+"</span>"+__esc(msg);
  log.appendChild(d); log.scrollTop=log.scrollHeight;
}
function dashSet(id,val){var e=document.getElementById(id); if(e) e.textContent=String(val==null?"":val);}
function dashRepaintCounters(){
  var d=window.__dash||{};
  dashSet("d-ok", d.ok||0);
  dashSet("d-fail", d.fail||0);
  dashSet("d-queue", d.queue||0);
  dashSet("d-done", d.done||0);
  dashSet("d-total", d.total||0);
  var pct=d.total? Math.min(100, Math.round((d.done||0)*100/d.total)) : 0;
  var bar=document.getElementById("d-bar"); if(bar) bar.style.width=pct+"%";
  dashSet("d-send-pct", pct+"%");
  if(typeof dashApiRender==="function") dashApiRender();
}
function dashProgress(done){
  window.__dash.done=done;
  dashSet("d-done",done);
  var pct=window.__dash.total? Math.min(100, Math.round(done*100/window.__dash.total)) : 0;
  var bar=document.getElementById("d-bar"); if(bar) bar.style.width=pct+"%";
  dashSet("d-send-pct", pct+"%");
  dashSet("dash-status", "Send "+pct+"% · "+done+"/"+window.__dash.total);
}
function dashOk(){window.__dash.ok++; dashSet("d-ok",window.__dash.ok);}
function dashFail(){window.__dash.fail++; dashSet("d-fail",window.__dash.fail);}
function dashQueue(){window.__dash.queue++; dashSet("d-queue",window.__dash.queue); window.__dash.fail++; dashSet("d-fail",window.__dash.fail);}
function dashSmtp(v){dashSet("d-smtp",v);}
function dashProxy(v){dashSet("d-proxy",v||"off");}
function dashMeta(v){dashSet("d-meta",v);}
function dashRefreshDisplay(){
  var btn=document.getElementById("btn-dash-refresh");
  if(btn){ btn.disabled=true; btn.textContent="Refreshing…"; }
  dashRepaintCounters();
  fetch("dash_live.php?_="+Date.now(),{cache:"no-store"})
    .then(function(r){ return r.ok?r.json():null; })
    .then(function(d){
      if(!d||!d.ok){ dashLog("warn","Refresh failed — send still running"); return; }
      var q=d.queue||{};
      var bits=[];
      bits.push("Pending "+(q.pending!=null?q.pending:"—"));
      bits.push("Sent OK file "+(q.done!=null?q.done:"—"));
      bits.push("Unique list "+(q.valid!=null?q.valid:"—"));
      if(q.resume_line) bits.push("resume @ line "+q.resume_line);
      if(q.next_email) bits.push("next "+q.next_email);
      if(d.running) bits.push("SENDING");
      else bits.push("idle");
      dashSet("d-meta", bits.join(" · "));
      if(d.snapshot && d.snapshot.api_accounts && d.snapshot.api_accounts.length){
        dashApiSetAccounts(d.snapshot.api_accounts, typeof d.snapshot.api_active==="number"?d.snapshot.api_active:-1);
      }
      if(d.filter && window.__dash.pollFilter) updateFilterUI(d.filter);
      if(typeof d.socks_ok==="number" && document.getElementById("d-proxy") && window.__dash.pollFilter)
        dashProxy(d.socks_ok+" OK");
      dashRepaintCounters();
      var st=document.getElementById("d-send-state");
      var stTxt=st?String(st.textContent||""):"";
      if(d.running && stTxt!=="DONE" && stTxt!=="STOPPED") dashSet("d-send-state","RUNNING");
      dashLog("info","Display refreshed (send not interrupted)");
    })
    .catch(function(){ dashLog("warn","Refresh failed — send still running"); })
    .then(function(){ if(btn){ btn.disabled=false; btn.textContent="Refresh"; } });
}
function dashDone(msg){
  var finalMsg=msg||("Finished — OK: "+window.__dash.ok+" · FAIL: "+window.__dash.fail);
  dashSet("dash-status", finalMsg);
  dashSet("d-send-state","DONE");
  var b=document.getElementById("live-badge"); if(b) b.style.animation="none";
  dashLog("info", finalMsg);
  if(window.__dash.filterPoll){ clearInterval(window.__dash.filterPoll); window.__dash.filterPoll=null; }
  var btn=document.getElementById("btn-stop-send");
  if(btn){
    btn.style.display="none";
    btn.disabled=true;
    btn.onclick=null;
  }
  var back=document.getElementById("btn-back-setup");
  if(back) back.style.display="inline-block";
}
function updateFilterUI(d){
  if(!d) return;
  var st=(d.state||(d.running?"running":"idle")).toUpperCase();
  dashSet("f-state", st);
  var prog=parseInt(d.progress||0,10), total=parseInt(d.total||0,10), work=parseInt(d.ok_count||0,10);
  var pct=total? Math.min(100, Math.round(prog*100/total)) : parseFloat(d.percent||0);
  dashSet("f-prog", prog+" / "+(total||"?"));
  dashSet("f-pct", pct+"%");
  dashSet("f-work", String(work));
  dashSet("f-upd", d.updated_human||"—");
  var msg=d.message||"";
  if(st==="FINISHED" || st==="IDLE" || st==="STOPPED"){
    if(d.ok_refresh_running) msg="Full scan done · live OK refresh running (file count changes)";
    else if(!msg || /Filter finished/i.test(msg)) msg="Full scan done. Live OK count still drops when send finds dead proxies.";
  }
  dashSet("f-msg", msg);
  var lastBit=d.last_proxy? ("last: "+d.last_proxy) : "";
  if(d.ok_refresh_running) lastBit=(lastBit?lastBit+" · ":"")+"OK refresh active";
  dashSet("f-last", lastBit||"…");
  var bar=document.getElementById("f-bar"); if(bar) bar.style.width=pct+"%";
  var dot=document.getElementById("f-dot");
  if(dot){ dot.className="dot "+(st==="RUNNING"||d.ok_refresh_running?"on":(st==="FINISHED"?"off":"")); }
}
function pollFilter(){
  fetch("filter_status.php?json=1&_="+Date.now(), {cache:"no-store"})
    .then(function(r){ return r.ok?r.json():null; })
    .then(function(d){ if(d) updateFilterUI(d); })
    .catch(function(){});
}
if(window.__dash.pollFilter){
  pollFilter();
  window.__dash.filterPoll=setInterval(pollFilter, 3000);
}
var btnRefresh=document.getElementById("btn-dash-refresh");
if(btnRefresh) btnRefresh.onclick=function(){ dashRefreshDisplay(); };
var btnStop=document.getElementById("btn-stop-send");
if(btnStop) btnStop.onclick=function(){
  if(!confirm("Stop send now? Remaining emails are saved to pending_emails.txt")) return;
  btnStop.disabled=true; btnStop.textContent="Stopping…";
  dashSet("dash-status","Stopping — killing workers…");
  dashSet("d-send-state","STOPPING");
  function finishStop(d){
    var n=(d&&d.pending!=null)?parseInt(d.pending,10):0;
    dashLog("warn", (d&&d.message)?d.message:("Stopped — "+n+" pending saved"));
    dashSet("d-send-state","STOPPED");
    dashDone("Stopped — "+n+" pending saved. Go back and Send to continue.");
    btnStop.textContent="Stopped";
    var b=document.getElementById("live-badge"); if(b) b.style.animation="none";
  }
  function pollUntilDead(tries){
    fetch("stop_send.php?check=1&_="+Date.now(),{cache:"no-store"}).then(function(r){return r.ok?r.json():null;}).then(function(s){
      if(s && !s.running){ finishStop(s); return; }
      if(tries<=0){
        // One more kill pass then accept
        fetch("stop_send.php?_="+Date.now(),{cache:"no-store"}).then(function(r){return r.ok?r.json():null;}).then(function(d2){
          finishStop(d2||s||{pending:0,message:"Stopped (timeout)"});
        }).catch(function(){ finishStop(s||{pending:0}); });
        return;
      }
      dashSet("dash-status","Stopping — waiting for send to exit ("+tries+")…");
      setTimeout(function(){ pollUntilDead(tries-1); }, 400);
    }).catch(function(){ if(tries>0) setTimeout(function(){ pollUntilDead(tries-1); }, 400); else finishStop({pending:0}); });
  }
  fetch("stop_send.php?_="+Date.now(),{cache:"no-store"}).then(function(r){return r.ok?r.json():null;}).then(function(d){
    dashLog("warn", (d&&d.message)?d.message:"Stop signal sent");
    if(d && d.running===false) finishStop(d);
    else pollUntilDead(25); // ~10s max — only shows Stopped when send_running.flag is gone
  }).catch(function(){ btnStop.disabled=false; btnStop.textContent="Stop send"; });
};
</script>';
	dash_flush();
}

function dash_js($code)
{
	if(php_sapi_name() === 'cli') return;
	echo "<script>{$code}</script>\n";
	dash_flush();
}

function dash_log($type, $msg)
{
	if(php_sapi_name() === 'cli')
	{
		fwrite(STDOUT, '['.$type.'] '.$msg.PHP_EOL);
		// Parallel workers: push proxy-test lines to campaign events so the browser Proxy panel fills
		global $cli_campaign;
		if(!empty($cli_campaign))
		{
			$proxyNoise = ($type === 'warn')
				|| (stripos($msg, 'Test fail') === 0)
				|| (stripos($msg, 'Test OK') === 0)
				|| (stripos($msg, 'OK list') === 0)
				|| (stripos($msg, 'Send fail') === 0)
				|| (stripos($msg, 'TLS/proxy') === 0);
			if($proxyNoise)
			{
				static $proxy_event_n = 0;
				$proxy_event_n++;
				$keep = (stripos($msg, 'Test OK') === 0)
					|| (stripos($msg, 'OK list') === 0)
					|| (stripos($msg, 'WAIT') !== false)
					|| ($proxy_event_n % 3 === 0);
				if($keep) campaign_event($cli_campaign, 'proxy', $msg);
			}
		}
		return;
	}
	$msg = str_replace(array("\\", "'", "\r", "\n"), array("\\\\", "\\'", " ", " "), $msg);
	dash_js("dashLog('{$type}','{$msg}');");
}

function smtp_apply_current($show = true)
{
	global $mail, $curentsmtp, $nq, $smtp_sent, $nrotat, $use_socks, $socks_proxy;
	$nq = 0;
	if(method_exists($mail, 'getSMTPInstance'))
	{
	try {
		$smtp = $mail->getSMTPInstance();
		if($smtp && $smtp->connected()) $mail->SmtpClose();
	} catch(Exception $e) {}
	}
	if($use_socks) socks_next_proxy();
	else { $GLOBALS['socks_proxy'] = ''; $socks_proxy = ''; }
	switch_smtp();
	if($show)
	{
	$sent = isset($smtp_sent[$curentsmtp]) ? $smtp_sent[$curentsmtp] : 0;
	$sec = strtoupper($mail->SMTPSecure ? $mail->SMTPSecure : 'none');
	$px = ($use_socks && $socks_proxy) ? $socks_proxy : '';
	$label = ($curentsmtp+1).' '.$mail->Username;
	dash_js("dashSmtp('".str_replace("'","\\'",$label)."'); dashProxy('".str_replace("'","\\'",($px?$px:'off'))."'); dashMeta('SMTP ".($curentsmtp+1)." · {$mail->Host}:{$mail->Port}/{$sec} · used {$sent}/{$nrotat}');");
	dash_log('info', 'Switch SMTP '.($curentsmtp+1).' | '.$mail->Host.':'.$mail->Port.'/'.$sec.' | '.$mail->Username.($px?' | proxy '.$px:'').' | '.$sent.'/'.$nrotat);
	}
}

function campaign_event($campaignDir, $type, $msg, $extra = array())
{
	$file = rtrim($campaignDir, '/\\') . DIRECTORY_SEPARATOR . 'events.jsonl';
	$row = array_merge(array('t' => time(), 'type' => $type, 'msg' => $msg), $extra);
	$fh = @fopen($file, 'a');
	if($fh)
	{
		flock($fh, LOCK_EX);
		fwrite($fh, json_encode($row)."\n");
		flock($fh, LOCK_UN);
		fclose($fh);
	}
}

function campaign_bump($campaignDir, $key, $by = 1)
{
	$file = rtrim($campaignDir, '/\\') . DIRECTORY_SEPARATOR . 'stats.json';
	$lk = @fopen(rtrim($campaignDir, '/\\') . DIRECTORY_SEPARATOR . 'stats.lock', 'c+');
	if($lk) flock($lk, LOCK_EX);
	$stats = is_file($file) ? json_decode(@file_get_contents($file), true) : array();
	if(!is_array($stats)) $stats = array();
	if(!isset($stats[$key])) $stats[$key] = 0;
	$stats[$key] += $by;
	$stats['updated'] = time();
	@file_put_contents($file, json_encode($stats));
	if($lk) { flock($lk, LOCK_UN); fclose($lk); }
	return $stats;
}

function campaign_parallel_master($emails, $cfg, $numWorkers)
{
	global $mail;
	$numWorkers = max(1, min(15, intval($numWorkers)));
	$dir = __DIR__ . DIRECTORY_SEPARATOR . 'campaigns' . DIRECTORY_SEPARATOR . 'run_'.date('Ymd_His').'_'.getmypid();
	@mkdir($dir, 0777, true);
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'emails.txt', implode("\n", $emails)."\n");
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'config.json', json_encode($cfg));
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'stats.json', json_encode(array('ok'=>0,'fail'=>0,'queue'=>0,'done'=>0)));
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'events.jsonl', '');

	$php = 'C:\\xampp\\php\\php.exe';
	if(!is_file($php)) $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
	$script = __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
	$procs = array();
	$pids = array();
	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('file', $dir.DIRECTORY_SEPARATOR.'worker_out.log', 'a'),
		2 => array('file', $dir.DIRECTORY_SEPARATOR.'worker_err.log', 'a')
	);
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	@file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'active_campaign.txt', $dir);
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'done.txt', '');
	dash_log('info', 'Parallel SEND: '.$numWorkers.' workers · '.count($emails).' targets');
	dash_log('info', 'Campaign folder: '.$dir);
	dash_log('info', 'Click Stop send anytime — remaining emails → pending_emails.txt');
	for($w = 0; $w < $numWorkers; $w++)
	{
		$cmd = escapeshellarg($php).' '.escapeshellarg($script).' --campaign='.escapeshellarg($dir).' --worker='.$w.' --workers='.$numWorkers;
		$pipes = array();
		$proc = @proc_open($cmd, $descriptors, $pipes, __DIR__);
		if(is_resource($proc))
		{
			if(isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
			$info = proc_get_status($proc);
			if(!empty($info['pid'])) $pids[] = intval($info['pid']);
			$procs[] = $proc;
		}
	}
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'workers.pid', implode("\n", $pids));

	$eventPos = 0;
	$total = count($emails);
	while(true)
	{
		$alive = 0;
		foreach($procs as $i => $proc)
		{
			if(!is_resource($proc)) continue;
			$st = proc_get_status($proc);
			if(!empty($st['running'])) $alive++;
			else { @proc_close($proc); unset($procs[$i]); }
		}
		$evFile = $dir.DIRECTORY_SEPARATOR.'events.jsonl';
		if(is_file($evFile))
		{
			$raw = @file($evFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			if(is_array($raw))
			{
				while($eventPos < count($raw))
				{
					$row = json_decode($raw[$eventPos], true);
					$eventPos++;
					if(!is_array($row)) continue;
					$t = isset($row['type']) ? $row['type'] : 'info';
					$msg = isset($row['msg']) ? $row['msg'] : '';
					if($t === 'ok') { dash_log('ok', $msg); dash_js('dashOk();'); }
					else if($t === 'fail') { dash_log('fail', $msg); dash_js('dashFail();'); }
					else if($t === 'queue') { dash_log('fail', $msg); dash_js('dashQueue();'); }
					else if($t === 'proxy' || $t === 'warn') dash_log('warn', $msg);
					else dash_log('info', $msg);
					if(isset($row['done'])) dash_js('dashProgress('.intval($row['done']).');');
				}
			}
		}
		$stats = is_file($dir.DIRECTORY_SEPARATOR.'stats.json') ? json_decode(@file_get_contents($dir.DIRECTORY_SEPARATOR.'stats.json'), true) : null;
		if(is_array($stats))
		{
			$done = intval(isset($stats['done']) ? $stats['done'] : 0);
			dash_js('dashProgress('.$done.'); dashMeta("Parallel workers alive: '.$alive.'/'.$numWorkers.' · done '.$done.'/'.$total.'");');
		}
		clearstatcache(true, __DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
		if(send_stop_requested($dir))
		{
			dash_log('warn', 'STOP requested — killing workers and saving pending…');
			foreach($procs as $proc) { if(is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); } }
			$wpid = $dir.DIRECTORY_SEPARATOR.'workers.pid';
			if(is_file($wpid))
			{
				foreach(@file($wpid, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
				{
					$pid = intval(trim($line));
					if($pid > 0 && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') @exec('taskkill /PID '.$pid.' /F /T 2>NUL');
				}
			}
			break;
		}
		if($alive < 1) break;
		usleep(250000);
	}

	// Build pending = emails not in done.txt (+ failed queue)
	$doneMap = array();
	if(is_file($dir.DIRECTORY_SEPARATOR.'done.txt'))
	{
		foreach(@file($dir.DIRECTORY_SEPARATOR.'done.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
			$doneMap[strtolower(trim($line))] = 1;
	}
	$pending = array();
	foreach($emails as $e)
	{
		if(empty($doneMap[strtolower($e)])) $pending[] = $e;
	}
	foreach(socks_load_failed_emails() as $e)
	{
		if(empty($doneMap[strtolower($e)])) $pending[] = $e;
	}
	$pending = array_values(array_unique($pending));
	if(send_stop_requested($dir) || !empty($pending))
	{
		$n = send_save_pending($pending);
		if(send_stop_requested($dir))
		{
			dash_log('warn', 'Stopped. Saved '.$n.' emails to pending_emails.txt — use Load pending later');
			dash_js("dashDone('Stopped — ".intval($n)." pending saved');");
			@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
			@unlink($dir.DIRECTORY_SEPARATOR.'stop.flag');
			send_mark_stopped();
			return;
		}
	}

	// Retry pass for failed_emails.txt (same as sequential)
	if(!empty($cfg['use_socks']))
	{
		$retry = socks_load_failed_emails();
		if(!empty($retry))
		{
			dash_log('info', '=== RETRY PASS: '.count($retry).' queued ===');
			load_socks_ok(true);
			socks_clear_failed_emails();
			$still = array();
			global $allsmtps, $curentsmtp, $smtp_sent, $nrotat, $default_system, $use_socks, $pause;
			$use_socks = !empty($cfg['use_socks']);
			foreach($retry as $ri => $to)
			{
				$mail->clearAllRecipients();
				if(!empty($allsmtps) && $default_system != '1')
				{
					if(!smtp_pick_next($curentsmtp)) { $still[] = $to; continue; }
					smtp_apply_current(false);
				}
				if($default_system != '1' && !empty($mail->Username))
				{
					$mail->From = $mail->Username;
					$mail->Sender = $mail->Username;
				}
				$mail->AddAddress($to);
				$mail->Subject = isset($cfg['subject']) ? $cfg['subject'] : $mail->Subject;
				$mail->Body = isset($cfg['message']) ? $cfg['message'] : $mail->Body;
				$attempt = mailer_socks_send_attempt($to);
				if(!empty($attempt['ok'])) { dash_log('ok', 'OK (retry) '.$to); dash_js('dashOk();'); }
				else if(!empty($attempt['queued'])) { $still[] = $to; dash_log('fail', 'Still QUEUED '.$to); }
				else { $still[] = $to; dash_log('fail', 'FAIL (retry) '.$to); dash_js('dashFail();'); }
			}
			foreach($still as $e) socks_queue_failed_email($e);
		}
	}
	dash_js("dashDone('Finished parallel send');");
	dash_log('info', 'Parallel campaign finished.');
}

	if ($action)
	{
	// ---- CLI parallel send worker (no browser) ----
	if($action === 'cli_worker' && $cli_mode && $cli_campaign !== '')
	{
	$cfgFile = $cli_campaign.DIRECTORY_SEPARATOR.'config.json';
	$emailsFile = $cli_campaign.DIRECTORY_SEPARATOR.'emails.txt';
	if(!is_file($cfgFile) || !is_file($emailsFile)) exit(1);
	$cfg = json_decode(@file_get_contents($cfgFile), true);
	if(!is_array($cfg)) exit(1);
	$allemails = array_values(array_filter(array_map('trim', @file($emailsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 'strlen'));
	$use_socks = !empty($cfg['use_socks']);
	$allsmtps = isset($cfg['allsmtps']) && is_array($cfg['allsmtps']) ? $cfg['allsmtps'] : array();
	$nrotat = !empty($cfg['nrotat']) ? intval($cfg['nrotat']) : 50;
	$pause = isset($cfg['pause']) ? $cfg['pause'] : 1;
	$contenttype = isset($cfg['contenttype']) ? $cfg['contenttype'] : 'html';
	$subject = isset($cfg['subject']) ? $cfg['subject'] : '';
	$realname = isset($cfg['realname']) ? $cfg['realname'] : '';
	$message = isset($cfg['message']) ? $cfg['message'] : '';
	$subject_base = $subject;
	$message_base = $message;
	$content_variants = (isset($cfg['content_variants']) && is_array($cfg['content_variants'])) ? $cfg['content_variants'] : array();
	if(empty($content_variants))
		$content_variants = array(array('subject' => $subject, 'message' => $message));
	$from = isset($cfg['from']) ? $cfg['from'] : '';
	$encodety = isset($cfg['encodety']) ? $cfg['encodety'] : 'no';
	$mail_queue_lane = (isset($cfg['mail_queue_lane']) && $cfg['mail_queue_lane'] === 'smtp') ? 'smtp' : 'api';
	$default_system = '';
	$smtp_sent = array();
	$curentsmtp = $cli_worker_id % max(1, count($allsmtps));
	content_variants_prepare(max(1, count($allsmtps)));
	if($use_socks) load_socks_ok(true);
	$mail = new PHPMailer();
	$mail->CharSet = 'UTF-8';
	if($contenttype == 'html') $mail->IsHtml(true); else $mail->IsHtml(false);
	if(empty($allsmtps)) { @file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'send_worker_'.$cli_worker_id.'.log', "No SMTPs — abort\n", FILE_APPEND); exit(1); }
	$mail->IsSMTP();
	$mail->SMTPKeepAlive = false;
	$mail->SMTPAuth = true;
	$mail->Timeout = $use_socks ? 8 : 30;
	$mail->SMTPDebug = 0;
	if(!smtp_pick_next($curentsmtp - 1 < 0 ? -1 : $curentsmtp - 1)) exit(1);
	smtp_apply_current(false);
	$mail->FromName = $realname;
	$totalE = count($allemails);
	for($i = $cli_worker_id; $i < $totalE; $i += $cli_num_workers)
	{
		if(send_stop_requested($cli_campaign)) break;
		$to = $allemails[$i];
		$mail->clearAllRecipients();
		if(!empty($allsmtps) && $default_system != '1')
		{
			// Prefer SMTP slot matching this worker to reduce collisions
			$want = $cli_worker_id % count($allsmtps);
			$curentsmtp = $want;
			if(!smtp_pick_next($want - 1))
			{
				campaign_event($cli_campaign, 'fail', 'FAIL '.$to.' — SMTP limit', array('done' => $i+1));
				campaign_bump($cli_campaign, 'fail');
				campaign_bump($cli_campaign, 'done');
				socks_queue_failed_email($to);
				continue;
			}
			smtp_apply_current(false);
		}
		if($default_system != '1' && !empty($mail->Username))
		{
			$mail->From = $mail->Username;
			$mail->Sender = $mail->Username;
		}
		else
		{
			$mail->From = $from;
			$mail->Sender = $from;
		}
		$cv = content_variant_for_slot(content_variant_smtp_slot());
		$subjOne = ($cv['subject'] !== '') ? $cv['subject'] : $subject;
		$bodyOne = ($cv['message'] !== '') ? $cv['message'] : $message;
		$who = mail_name_from_email($to);
		$subjOne = str_replace('#name#', $who, $subjOne);
		$bodyOne = str_replace('#name#', $who, $bodyOne);
		$bodyOne = preg_replace('/!!EMAIL!!/', $to, $bodyOne);
		$subjOne = preg_replace('/!!EMAIL!!/', $to, $subjOne);
		$bodyOne = preg_replace('/!!DATE!!/', date('d/m/Y'), $bodyOne);
		$subjOne = preg_replace('/!!DATE!!/', date('d/m/Y'), $subjOne);
		$bodyOne = preg_replace('/!!TIME!!/', date('H:i:s'), $bodyOne);
		$subjOne = preg_replace('/!!TIME!!/', date('H:i:s'), $subjOne);
		$mail->Subject = $subjOne;
		$mail->Body = $bodyOne;
		$mail->AltBody = strip_tags_content($mail->Body);
		$mail->AddAddress($to);
		$attempt = mailer_socks_send_attempt($to);
		campaign_bump($cli_campaign, 'done');
		if(!empty($attempt['ok']))
		{
			campaign_event($cli_campaign, 'ok', 'OK  '.$to.' [w'.$cli_worker_id.']', array('done' => $i+1));
			campaign_bump($cli_campaign, 'ok');
			$fh = @fopen($cli_campaign.DIRECTORY_SEPARATOR.'done.txt', 'a');
			if($fh) { flock($fh, LOCK_EX); fwrite($fh, $to."\n"); flock($fh, LOCK_UN); fclose($fh); }
			if(!empty($cfg['mail_queue_campaign'])) mail_queue_mark_done($to);
			if(!empty($allsmtps) && $default_system != '1')
				smtp_note_sent_ok();
			// Keep OK list fresh: reload after each OK send
			if($use_socks) socks_reload_ok_from_disk();
		}
		else if(!empty($attempt['queued']))
		{
			campaign_event($cli_campaign, 'queue', 'QUEUED '.$to.' [w'.$cli_worker_id.']', array('done' => $i+1));
			campaign_bump($cli_campaign, 'queue');
		}
		else
		{
			campaign_event($cli_campaign, 'fail', 'FAIL '.$to.' — '.(isset($attempt['err'])?$attempt['err']:'').' [w'.$cli_worker_id.']', array('done' => $i+1));
			campaign_bump($cli_campaign, 'fail');
		}
		$p = isset($cfg['pause']) ? doubleval($cfg['pause']) : 0;
		if($p > 0) usleep((int)($p * 500000)); // half pause in parallel
	}
	exit(0);
	}

	$apiMode = (isset($smtp_mode) && $smtp_mode === 'brevo_api');
	$mail_queue_campaign = isset($mail_queue_campaign) ? $mail_queue_campaign : true;
	if (!$subject && !$message)
	{
	print "<script>alert('Fill Subject and Message before sending.'); </script>";
	die();
	}
	else
	{
	@ini_set('output_buffering', 'off');
	@ini_set('zlib.output_compression', 0);
	@ini_set('implicit_flush', 1);
	dash_flush();
	send_mark_running();

	// Campaign = pending from saved list; Test = emails in Test box
	$allemails = array();
	if(!empty($mail_queue_campaign))
	{
		$queuePack0 = mail_queue_pending();
		$allemails = isset($queuePack0['emails']) ? $queuePack0['emails'] : array();
		if(empty($allemails))
		{
			dash_boot(0, 0, 0);
			dash_log('fail', 'Campaign: no pending emails. Paste/upload a list and Save, or Clear sent, or use Test mode.');
			dash_js("dashDone('no pending recipients');");
			send_mark_stopped();
			die();
		}
	}
	else
	{
		$seenT = array();
		foreach(preg_split("/\\r\\n|\\r|\\n/", isset($emaillist) ? $emaillist : '') as $line)
		{
			$e = strtolower(trim($line));
			if($e === '' || strpos($e, '@') === false) continue;
			if(isset($seenT[$e])) continue;
			$seenT[$e] = true;
			$allemails[] = $e;
		}
		if(empty($allemails))
		{
			dash_boot(0, 0, 0);
			dash_log('fail', 'Test mode: put at least one email in the Test box.');
			dash_js("dashDone('no test emails');");
			send_mark_stopped();
			die();
		}
	}
	$numemails = count($allemails);
	$nq=0;
	$qx=0;
	$smtp_sent = array();
	if(empty($nrotat) || intval($nrotat) <= 0) $nrotat = 50;
	if(empty($send_workers) || intval($send_workers) <= 0) $send_workers = 1;

	// -------- Brevo API --------
	if($smtp_mode === 'brevo_api')
	{
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	if(empty($brevo_api_accounts))
	{
		dash_boot(0, 0, 0);
		dash_log('fail', 'Brevo API: add at least one API account first.');
		dash_js("dashDone('No API accounts');");
		send_mark_stopped();
		die();
	}
	$formFrom = trim($from_base !== '' ? $from_base : $from);
	$needFormFrom = false;
	foreach($brevo_api_accounts as $acc)
	{
		if(empty($acc['from']) || strpos($acc['from'], '@') === false) { $needFormFrom = true; break; }
	}
	if($needFormFrom && ($formFrom === '' || strpos($formFrom, '@') === false))
	{
		dash_boot(0, 0, 0);
		dash_log('fail', 'Brevo API: set From email on each account (Edit).');
		dash_js("dashDone('No From email');");
		send_mark_stopped();
		die();
	}

	$isTestSendEarly = empty($mail_queue_campaign);
	$qCounts = mail_queue_counts();
	dash_boot(max(1, $numemails), count($brevo_api_accounts), 0);
	if($isTestSendEarly)
	{
		dash_log('info', 'MODE: Brevo API — TEST ('.$numemails.') · campaign list / mailssenddone untouched');
	}
	else
	{
		dash_log('info', 'MODE: Brevo API — campaign queue · OK → mailssenddone.txt');
		$resumeMsg = !empty($qCounts['resume_line']) ? (' · resume @ line '.$qCounts['resume_line']) : '';
		dash_log('info', 'Queue: pending '.$qCounts['pending'].' · sent OK '.$qCounts['done'].' · in list '.$qCounts['valid'].$resumeMsg);
		mail_queue_save_progress(array('phase' => 'start', 'credits_room' => null));
	}
	dash_js("dashSet('api-cred-state','FETCHING'); dashMeta('Fetching Brevo credits (once)…');");

	$brevo_api_credits = array();
	$brevo_api_exhausted = array();
	$anyRoom = false;
	foreach($brevo_api_accounts as $aid => $acc)
	{
		$fkey = !empty($acc['key']) ? (substr($acc['key'], 0, 12).'…') : '(no key)';
		$ff = !empty($acc['from']) ? $acc['from'] : ($formFrom !== '' ? $formFrom.' (form)' : '(missing)');
		if(!empty($acc['problem']))
		{
			$brevo_api_exhausted[$aid] = true;
			$brevo_api_credits[$aid] = 0;
			dash_log('warn', '  Account '.($aid + 1).': SKIP · marked problem · from '.$ff);
			continue;
		}
		$cred = brevo_account_health($acc['key'], !empty($acc['from']) ? $acc['from'] : $formFrom);
		if(!empty($cred['blocking']))
		{
			$brevo_api_exhausted[$aid] = true;
			$brevo_api_credits[$aid] = 0;
			dash_log('warn', '  Account '.($aid + 1).': SKIP · '.$cred['status_text'].' · from '.$ff);
			continue;
		}
		if(!empty($cred['ok']))
		{
			$brevo_api_credits[$aid] = floatval($cred['credits']);
			$leftN = intval(floor($cred['credits']));
			$room = min(intval($acc['limit']), $leftN);
			$extra = !empty($cred['status_text']) ? (' · '.$cred['status_text']) : '';
			if($leftN < 1)
			{
				$brevo_api_exhausted[$aid] = true;
				dash_log('warn', '  Account '.($aid + 1).': SKIP · 0 left · from '.$ff.$extra);
			}
			else
			{
				$anyRoom = true;
				dash_log('info', '  Account '.($aid + 1).': limit '.$acc['limit'].' · Brevo '.$leftN.' left · can send '.$room.' · from '.$ff.$extra);
			}
		}
		else
		{
			$brevo_api_credits[$aid] = null;
			$anyRoom = true;
			dash_log('warn', '  Account '.($aid + 1).': credits fetch failed ('.$cred['err'].') — mailer limit only · from '.$ff);
		}
	}
	brevo_api_dash_snapshot();
	dash_js("dashSet('api-cred-state','READY');");

	$totalRoom = brevo_api_total_room();
	if(!$anyRoom || $totalRoom < 1 || brevo_api_pick_next() === false)
	{
		brevo_api_dash_snapshot();
		dash_log('fail', 'No credits left today. Come back tomorrow (or add another account).');
		dash_js("dashDone('0 credits today — try tomorrow');");
		send_mark_stopped();
		die();
	}

	$isTestSend = empty($mail_queue_campaign);
	$pendingAll = $allemails; // already resolved above (campaign pending or test box)
	$resumeLine = null;
	if(!$isTestSend)
	{
		$qPack = mail_queue_pending();
		$resumeLine = isset($qPack['resume_line']) ? $qPack['resume_line'] : null;
	}
	else
	{
		dash_log('info', 'TEST list: '.count($pendingAll).' (campaign list untouched)');
	}

	// Batch size = today's credits only.
	$allemails = array_slice($pendingAll, 0, $totalRoom);
	$numemails = count($allemails);
	$leftForLater = count($pendingAll) - $numemails;
	dash_js('dashSet("d-total",'.intval($numemails).'); window.__dash.total='.intval($numemails).';');
	if($isTestSend)
		dash_log('info', 'Test batch: '.$numemails.' (credits room '.$totalRoom.')');
	else
		dash_log('info', 'Today batch: '.$numemails.' (credits '.$totalRoom.') · still pending after today: '.$leftForLater.($resumeLine ? ' · starting @ validmails line '.$resumeLine : ''));
	if(!$isTestSend)
	{
		mail_queue_save_progress(array(
			'phase' => 'sending',
			'credits_room' => $totalRoom,
			'today_batch' => $numemails,
			'pending_after_batch' => $leftForLater,
			'started_at_resume_line' => $resumeLine
		));
	}
	content_variants_prepare(count($brevo_api_accounts));
	dash_js("dashProxy('Brevo API'); dashSmtp('API × ".count($brevo_api_accounts)."');");
	dash_log('info', 'Content: '.count($content_variants).' HTML variant(s) split across '.count($brevo_api_accounts).' API account(s)');
	$isHtmlApi = ($contenttype == 'html');
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'pending_emails.txt');
	$sessionOk = array();
	$sessionOkMap = array();

	for($x = 1; $x <= $numemails; $x++)
	{
		if(send_stop_requested())
		{
			$left = count($pendingAll) - count($sessionOk);
			if(!$isTestSend)
			{
				mail_queue_save_progress(array(
					'phase' => 'stopped',
					'session_ok' => count($sessionOk),
					'pending_left' => $left
				));
			}
			dash_log('warn', 'Stopped. OK this run: '.count($sessionOk).($isTestSend ? ' (test — not written to mailssenddone)' : (' → mailssenddone · pending left: '.$left)));
			dash_js("dashDone('Stopped');");
			@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
			send_mark_stopped();
			die();
		}
		if(brevo_api_total_room() < 1)
		{
			$left = count($pendingAll) - count($sessionOk);
			if(!$isTestSend)
			{
				mail_queue_save_progress(array(
					'phase' => 'credits_exhausted',
					'session_ok' => count($sessionOk),
					'pending_left' => $left
				));
			}
			dash_log('warn', 'Credits finished. OK: '.count($sessionOk).($isTestSend ? '' : (' · pending left: '.$left)));
			dash_js("dashDone('Credits done');");
			send_mark_stopped();
			die();
		}
		$to = preg_replace('/\s+/', '', $allemails[$x - 1]);
		$sentThis = false;
		$lastErr = '';
		for($tryAcc = 0; $tryAcc < count($brevo_api_accounts); $tryAcc++)
		{
			$acc = brevo_api_pick_next();
			if($acc === false) break;
			if(brevo_api_room_left($brevo_api_cur) < 1)
			{
				brevo_api_mark_exhausted($brevo_api_cur, '0 left before send');
				continue;
			}
			$realname = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', 'generateRandomString', $realname_base);
			if(isset($_POST['rndname'])) $realname = $faker->name;
			if(!empty($acc['name'])) $realname = $acc['name'];
			$cv = content_variant_for_slot($brevo_api_cur, false);
			$msgSrc = ($cv['message'] !== '') ? $cv['message'] : $message_base;
			$subjSrc = ($cv['subject'] !== '') ? $cv['subject'] : $subject_base;
			$message = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', 'generateRandomString', $msgSrc);
			$subject = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', 'generateRandomString', $subjSrc);
			$apiFrom = (!empty($acc['from']) && strpos($acc['from'], '@') !== false) ? $acc['from'] : $formFrom;
			$from = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', 'generateRandomString', $apiFrom);
			$who = mail_name_from_email($to);
			$message = str_replace('#name#', $who, $message);
			$subject = str_replace('#name#', $who, $subject);
			$message = preg_replace('/!!EMAIL!!/', $to, $message);
			$subject = preg_replace('/!!EMAIL!!/', $to, $subject);
			$message = preg_replace('/!!DATE!!/', date('d/m/Y'), $message);
			$subject = preg_replace('/!!DATE!!/', date('d/m/Y'), $subject);
			$message = preg_replace('/!!TIME!!/', date('H:i:s'), $message);
			$subject = preg_replace('/!!TIME!!/', date('H:i:s'), $subject);
			$message = stripslashes($message);
			$subject = stripslashes($subject);
			$vNum = ($brevo_api_cur % max(1, count($content_variants))) + 1;
			if(!empty($content_variant_buckets[$brevo_api_cur]) && count($content_variant_buckets[$brevo_api_cur]) > 1)
				$vNum = intval($cv['index']) + 1;
			dash_log('info', ($isTestSend ? 'Test' : 'Batch').' '.$x.'/'.$numemails.' → '.$to.' · API account '.($brevo_api_cur + 1).' · HTML '.$vNum.' (room '.brevo_api_room_left($brevo_api_cur).')');
			dash_js("dashMeta('API ".$x."/".$numemails." · acct ".($brevo_api_cur+1)." → ".str_replace("'","\\'",$to)."'); dashProgress(".$x."); dashSmtp('API ".($brevo_api_cur+1)."');");
			dash_log('info', 'From: '.$from.' → To: '.$to);
			$r = brevo_api_send_email($acc['key'], $to, $from, $realname, $subject, $message, $isHtmlApi, $replyto);
			if(!empty($r['ok']))
			{
				content_variant_for_slot($brevo_api_cur, true); // rotate multi-HTML bucket only after OK
				dash_log('ok', 'OK  '.$to.(!empty($r['id']) ? ' · id '.$r['id'] : ''));
				dash_js('dashOk();');
				brevo_api_note_sent_ok();
				if(!$isTestSend) mail_queue_mark_done($to);
				$sessionOk[] = $to;
				$sessionOkMap[$to] = true;
				if(!$isTestSend && count($sessionOk) % 25 === 0)
				{
					mail_queue_save_progress(array(
						'phase' => 'sending',
						'session_ok' => count($sessionOk),
						'batch_pos' => $x,
						'batch_total' => $numemails
					));
				}
				$sentThis = true;
				break;
			}
			$lastErr = isset($r['err']) ? $r['err'] : 'not sent';
			$httpCode = isset($r['http']) ? intval($r['http']) : 0;
			if($httpCode === 402 || brevo_api_err_is_credits($lastErr))
			{
				brevo_api_mark_exhausted($brevo_api_cur, $lastErr);
				dash_log('warn', 'Account '.($brevo_api_cur + 1).' out of credits — retry '.$to.' on next account');
				continue;
			}
			dash_log('fail', 'FAIL '.$to.' — '.$lastErr.($isTestSend ? '' : ' (not marked done — will retry next run)'));
			dash_js('dashFail();');
			$sentThis = true;
			break;
		}
		if(!$sentThis)
		{
			if(brevo_api_pick_next() === false)
			{
				$left = count($pendingAll) - count($sessionOk);
				if(!$isTestSend)
				{
					mail_queue_save_progress(array(
						'phase' => 'credits_exhausted',
						'session_ok' => count($sessionOk),
						'pending_left' => $left
					));
				}
				dash_log('fail', 'Credits finished. OK: '.count($sessionOk).($isTestSend ? '' : (' · pending left: '.$left)));
				dash_js('dashFail();');
				dash_js("dashDone('Credits done');");
				send_mark_stopped();
				die();
			}
			dash_log('fail', 'FAIL '.$to.' — '.($lastErr !== '' ? $lastErr : 'no account'));
			dash_js('dashFail();');
		}
		flush();
		if(!empty($pause) && doubleval($pause) > 0 && $x < $numemails && !empty($pemails) && ($x % intval($pemails) == 0))
			usleep((int)(doubleval($pause) * 1000000));
	}
	if($isTestSend)
	{
		dash_log('info', 'Test finished. OK: '.count($sessionOk).' / '.$numemails.' (validmails + mailssenddone untouched)');
	}
	else
	{
		$finalCounts = mail_queue_counts();
		mail_queue_save_progress(array(
			'phase' => 'finished',
			'session_ok' => count($sessionOk),
			'pending_left' => $finalCounts['pending'],
			'resume_line_in_validmails' => $finalCounts['resume_line']
		));
		dash_log('info', 'Campaign finished. OK this run → mailssenddone: '.count($sessionOk).' · pending left: '.$finalCounts['pending'].($finalCounts['resume_line'] ? ' · next resume @ line '.$finalCounts['resume_line'] : '').' · validmails untouched');
	}
	dash_js("dashDone();");
	send_mark_stopped();
	die();
	}

	// SOCKS5: send uses socks5_ok.txt; if empty → auto full parallel filter from scratch
	$proxyCount = 0;
	$filt = '';
	$dash_booted = false;
	if($use_socks)
	{
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	load_socks_ok(true);
	$socks_dead = array();
	if(empty($socks_ok) || socks_count_ok_on_disk() < 1)
	{
	dash_boot(max(1, isset($numemails)?$numemails:0), !empty($allsmtps)?count($allsmtps):0, 0);
	$dash_booted = true;
	dash_js("dashMeta('OK empty — restarting full filter from scratch…'); dashProxy('scanning…');");
	dash_log('warn', 'socks5_ok.txt empty — starting full parallel filter from scratch…');
	$filt = socks_restart_full_filter_from_scratch('send start');
	dash_log('info', 'Filter: '.$filt.' — waiting for first OK proxies (send continues when ready)');
	$got = socks_wait_for_ok_proxies(180);
	if(send_stop_requested())
	{
	$n = send_save_pending(isset($allemails)?$allemails:array());
	dash_log('warn', 'Stopped while waiting for OK. Saved '.$n.' to pending_emails.txt');
	dash_js("dashDone('Stopped — ".intval($n)." pending saved');");
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	send_mark_stopped();
	die();
	}
	if($got < 1)
	{
	$n = send_save_pending(isset($allemails)?$allemails:array());
	dash_log('fail', 'No OK proxies after waiting. Saved '.$n.' pending. Check filter / Run Test.');
	dash_js("dashDone('No OK proxies — ".intval($n)." pending saved');");
	die();
	}
	load_socks_ok(true);
	}
	else
	{
	// Keep incomplete filter going; if finished leave it. Soft OK refresh once.
	$filt = socks_start_background_filter();
	socks_start_ok_refresh();
	}
	$proxyCount = count($socks_ok);
	socks_clear_failed_emails();
	dash_log('info', 'Using '.$proxyCount.' OK proxies — filter auto-restarts from scratch if OK hits 0');
	}
	else
	{
	$GLOBALS['socks_proxy'] = '';
	}

	$smtpCount = !empty($allsmtps) ? count($allsmtps) : 0;
	if($smtp_mode === 'brevo' && empty($allsmtps))
	{
	if(!$dash_booted) dash_boot(0, 0, 0);
	dash_log('fail', 'Brevo SMTP ON but no SMTPs parsed. Use #ACCOUNT 500 blocks in the Brevo box.');
	die();
	}
	if($smtp_mode === 'normal' && empty($allsmtps))
	{
	if(!$dash_booted) dash_boot(max(1, isset($numemails)?$numemails:0), 0, isset($proxyCount)?$proxyCount:0);
	dash_log('fail', 'No SMTPs in Active SMTP list (SMTP × 0). Paste host:port:user:pass and click Add SMTP, then Send again.');
	dash_log('warn', 'Without SMTPs the mailer falls back to PHP mail()/sendmail — that fails on XAMPP ("Could not instantiate mail function").');
	die();
	}
	if(!$dash_booted) dash_boot($numemails, $smtpCount, $proxyCount);
	else { dash_js('dashSet("d-total",'.intval($numemails).'); window.__dash.total='.intval($numemails).';'); dash_js("dashProxy('".$proxyCount." OK');"); }
	if($smtp_mode === 'brevo')
	{
	dash_log('info', 'MODE: Brevo — no SOCKS · '.count($smtp_accounts).' account(s) · '.$smtpCount.' SMTP line(s)');
	foreach($smtp_accounts as $aid => $acc)
	dash_log('info', '  Account '.($aid+1).': '.count($acc['indices']).' SMTP(s), shared limit '.$acc['limit'].'/day — finish this account before next');
	}
	else if($use_socks)
	{
	dash_log('info', 'MODE: Normal + SOCKS5 — socks5_ok.txt ('.$proxyCount.' OK proxies)');
	dash_log('info', 'If OK hits 0 → full parallel filter restarts from scratch; send picks fresh OK as they appear');
	dash_log('info', 'Unsent addresses go to failed_emails.txt then retry after the list');
	}
	else
	dash_log('warn', 'MODE: Normal without SOCKS — if connect fails, enable USE SOCKS5.');
	dash_log('info', (!empty($mail_queue_campaign) ? 'Recipients: CAMPAIGN queue' : 'Recipients: TEST box').' · '.$numemails.' | SMTPs: '.$smtpCount.' | Limit: '.($smtp_mode==='brevo'?'per Brevo account':($nrotat.'/SMTP')).' | Workers: '.$send_workers);
	if(!empty($mail_queue_campaign)) dash_log('info', 'OK → mailssenddone.txt (campaign list never deleted)');
	
	if(!empty($epriority))
	$mail->Priority = "$epriority";
	if($contenttype == "html")
	$mail->IsHtml(true);
	else
	$mail->IsHtml(false);
	if(empty($reconnect))
	$reconnect=0;
	if(!empty($replyto))
	$mail->AddReplyTo("$replyto");
	if(empty($my_smtp) || empty($allsmtps))
	{
	dash_log('fail', 'No SMTPs — refusing PHP mail()/sendmail fallback.');
	die();
	}
	$mail->IsSMTP();
	// Fresh connect each mail when rotating SMTPs (avoids connect() failed)
	$mail->SMTPKeepAlive = (count($allsmtps) <= 1);
	$mail->SMTPAuth = true;
	$mail->Timeout = $use_socks ? 8 : 30;
	$curentsmtp = 0;
	if(!smtp_pick_next(-1))
	{
	dash_log('fail', 'No SMTP available.');
	die();
	}
	smtp_apply_current(true);
	// Random From with SMTP auth gets blocked — keep SMTP login as From
	if(!empty($_POST['rndmail']) && empty($my_smtp)){
		$from = $faker->email; 
	}
	$mail->From = $from;
	#$mail->addCustomHeader('List-Unsubscribe',preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', "generateRandomString", 'mailto:bounce##09-{3}##-##az-AZ-09-{15}##@'.explode('@',$from)[1].'?subject=list-unsubscribe'));
	// SET DEBUG LVL
	// Keep dashboard clean (set DEBUG LVL > 0 only if you need raw SMTP transcript)
	$mail->SMTPDebug = intval($debg);
	if($mail->SMTPDebug > 0)
	dash_log('warn', 'DEBUG LVL '.$mail->SMTPDebug.' ON — raw SMTP lines may appear below the log.');
	// SET CHARSET
	$mail->CharSet = "UTF-8";
	// READING CONFIRMATION
	if($reading)
	$mail->ConfirmReadingTo = $replyto;
	else $mail->ConfirmReadingTo = '';
	// SET ENCODING TYPE
	if($encodety !="no") $mail->Encoding = $encodety;
	// ADD ATTACH
	//print_r($_FILES['userfile']);
	//echo "jjjj";
	if(isset($_FILES['userfile']) && is_array($_FILES['userfile']['tmp_name'])){

		foreach($_FILES['userfile']['tmp_name'] as $key => $tmp_name)
		{
		    if(empty($tmp_name) || !is_uploaded_file($tmp_name)) continue;
		    $file_name = $_FILES['userfile']['name'][$key];
		    $file_tmp =$_FILES['userfile']['tmp_name'][$key];

		    $uploadfile = tempnam(sys_get_temp_dir(), sha1($file_name));

		    if(move_uploaded_file($file_tmp,$uploadfile)){
		    	$mail->addAttachment($uploadfile,$file_name);
		    }
		}
	}
	/*foreach($_FILES as $file){
	if (array_key_exists('userfile', $file)) 
	{
	// First handle the upload
	// Don't trust provided filename - same goes for MIME types
	// See http://php.net/manual/en/features.file-upload.php#114004 for more thorough upload validation
	$uploadfile = tempnam(sys_get_temp_dir(), sha1($file['userfile']['name']));
	echo "----";
	move_uploaded_file($file['userfile']['tmp_name'], $uploadfile);
	if (move_uploaded_file($file['userfile']['tmp_name'], $uploadfile)) 
	{
	$mail->addAttachment($uploadfile,$file['userfile']['name']);
	echo "yop";
	}
	}
	}*/

	// Parallel send (multiple PHP workers) — skip BCC mode for now
	if(intval($send_workers) > 1 && empty($isbcc))
	{
	$cfg = array(
		'use_socks' => !empty($use_socks),
		'allsmtps' => $allsmtps,
		'nrotat' => $nrotat,
		'pause' => $pause,
		'contenttype' => $contenttype,
		'subject' => $subject_base,
		'realname' => $realname_base,
		'message' => $message_base,
		'content_variants' => $content_variants,
		'from' => $from_base,
		'encodety' => $encodety,
		'mail_queue_campaign' => !empty($mail_queue_campaign),
		'mail_queue_lane' => (isset($mail_queue_lane) && $mail_queue_lane === 'smtp') ? 'smtp' : 'api'
	);
	campaign_parallel_master($allemails, $cfg, $send_workers);
	send_mark_stopped();
	}
	else
	{
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	$cvSlots = ($smtp_mode === 'brevo' && !empty($smtp_accounts)) ? count($smtp_accounts) : max(1, count($allsmtps));
	content_variants_prepare($cvSlots);
	dash_log('info', 'Content: '.count($content_variants).' HTML variant(s) split across '.$cvSlots.' account/SMTP slot(s)');
	$seq_done = array();
	for($x=1; $x<=$numemails; $x++)
	{
	if(send_stop_requested())
	{
	$left = array();
	for($z=$x; $z<=$numemails; $z++) $left[] = $allemails[$z-1];
	foreach(socks_load_failed_emails() as $e) $left[] = $e;
	$n = send_save_pending($left);
	dash_log('warn', 'Stopped. Saved '.$n.' to pending_emails.txt');
	dash_js("dashDone('Stopped — ".intval($n)." pending saved');");
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	send_mark_stopped();
	break;
	}
	// BCC EMAIL COUNT
	if($isbcc && $x % intval($nbcc) == 0)
	{
	$nm += 1;
	$nopose=false;
	$canrotat=true;
	}
	else
	{
	$nopose=true;
	$canrotat=false;
	}
	//END//
	
	$send=false;
	$v=$x-1;
	$to = $allemails[$v];
	$to = preg_replace("/ /", "", $to);
	
	// ADD ADRESSES TO QUEUE
	if($isbcc && !empty($nbcc) )
	{

	if($x % intval($nbcc)  != 0 && $x <= $numemails)
	{
	$mail->addBCC("$to");
	dash_log('info', 'Line '.$x.' queued BCC → '.$to);
	if($x % intval($nbcc) != 0 && $x == $numemails)
	$send=true;
	}
	else
	{
	$mail->addBCC("$to");
	dash_log('info', 'Line '.$x.' queued BCC → '.$to);
	$send = true;
	}
	$qx=$x;
	}
	else 
	{
	$mail->clearAddresses();
	$mail->AddAddress("$to");
	$send=true;
	dash_log('info', 'Line '.$x.' → '.$to);
	dash_js("dashMeta('Sending ".$x."/".$numemails." → ".str_replace("'","\\'",$to)."'); dashProgress(".$x.");");
	$qx=$x;
	}
	//END//
	if($send)
	{	
	
	$realname = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', "generateRandomString", $realname_base);
	if(isset($_POST['rndname']))
	{
		$realname = $faker->name;
	}
	$cv = content_variant_for_slot(content_variant_smtp_slot());
	$msgSrc = ($cv['message'] !== '') ? $cv['message'] : $message_base;
	$subjSrc = ($cv['subject'] !== '') ? $cv['subject'] : $subject_base;
	$message = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', "generateRandomString", $msgSrc);
	$subject = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', "generateRandomString", $subjSrc);
	$from = preg_replace_callback('/(##([a-zA-Z0-9\-]+)\{([0-9]+)\}##)/', "generateRandomString", $from_base);
	if(!$isbcc)
	{
	$who = mail_name_from_email($to);
	$message = str_replace('#name#', $who, $message);
	$subject = str_replace('#name#', $who, $subject);
	$message = preg_replace("/!!EMAIL!!/", $to, $message);
	$subject = preg_replace("/!!EMAIL!!/", $to, $subject);
	}
	$message = preg_replace("/!!DATE!!/", date("d/m/Y"), $message);
	$subject = preg_replace("/!!DATE!!/", date("d/m/Y"), $subject);
	$message = preg_replace("/!!TIME!!/", date("H:i:s"), $message);
	$subject = preg_replace("/!!TIME!!/", date("H:i:s"), $subject);
	$message = urlencode($message);
	$message = preg_replace("/%5C%22/", "%22", $message);
	$message = urldecode($message);
	$message = stripslashes($message);
	$subject = stripslashes($subject);

	if ($encodety != "no") 
	{
	$subject = "=?UTF-8?B?".base64_encode($subject)."?=";
	$realname = "=?UTF-8?B?".base64_encode($realname)."?=";
	}

	// Always MAIL FROM = SMTP login when using SMTP (fixes 550 Sender address is not allowed)
	if($default_system != "1" && !empty($mail->Username))
	{
	$from = $mail->Username;
	$from_base = $mail->Username;
	}
	$mail->From = "$from";
	$mail->Sender = "$from";
	$mail->FromName = "$realname";
	$mail->Subject = "$subject";
	$mail->Body = "$message";
	$mail->AltBody = strip_tags_content($message);
	dash_log('info', 'From: '.$from.' → To: '.$to);
	$attempt = mailer_socks_send_attempt($to);
	$send_ok = !empty($attempt['ok']);
	if($send_ok)
	{
	dash_log('ok', 'OK  '.$to.($isbcc ? ' (BCC batch #'.$nm.')' : ''));
	dash_js('dashOk();');
	if(!empty($mail_queue_campaign)) mail_queue_mark_done($to);
	if($use_socks) socks_reload_ok_from_disk();
	}
	else if(!empty($attempt['queued']))
	{
	dash_log('fail', 'QUEUED '.$to.' → failed_emails.txt');
	dash_js('dashQueue();');
	}
	else
	{
	dash_log('fail', 'FAIL '.$to.' — '.(isset($attempt['err']) ? $attempt['err'] : 'not sent'));
	dash_js('dashFail();');
	}

	// Count only successful sends toward daily limit (per SMTP or Brevo account)
	if($send_ok && !empty($allsmtps) && $default_system != "1")
	smtp_note_sent_ok();

	if($reconnect >0 && $reconnect==$nq && count($allsmtps) <= 1)
	{
	$mail->SmtpClose();
	echo "<p><b>########################### SMTP CLOSED AND ATTEMPTS TO RECONNECT NEW CONNECTION SEASON ########################### </b></p>";
	$nq=0;
	}
	$nq=$nq+1;
	flush();
	if($isbcc)
	$mail->clearBCCs();
	}
	//END//
	
	// ROUND-ROBIN: next mail uses next SMTP that still has limit left
	if(!empty($allsmtps) && $default_system != "1" && $x < $numemails)
	{
	$do_rotate = !($isbcc && !$canrotat);
	if($do_rotate)
	{
	$prev_smtp = $curentsmtp;
	if(!smtp_pick_next($curentsmtp))
	{
	dash_log('fail', $smtp_mode==='brevo' ? 'All Brevo accounts reached daily limit. Stopping.' : ('All SMTPs reached daily limit ('.$nrotat.' each). Stopping.'));
	dash_js("dashDone('Stopped — daily limit reached');");
	break;
	}
	if($curentsmtp !== $prev_smtp)
	smtp_apply_current(true);
	}
	}
	//END//
	
	// WAITING PAUSE TIME
	if(!empty($pause) && !empty($pemails) && $x < $numemails)
	{
	if(doubleval($pause) > 0)
	{
	if(!$isbcc)
	{
	if($x % intval($pemails) == 0)
	{
	pause($pause,$mail);
	}
	}
	else if($nm > 0 && $nm % intval($pemails) == 0 && !$nopose)
	{
	pause($pause,$mail);
	}
	}
	}
	//END//
	}

	// RETRY PASS: emails that waited in failed_emails.txt (more OK proxies may exist now)
	if($use_socks && empty($isbcc))
	{
	$retry_list = socks_load_failed_emails();
	if(!empty($retry_list))
	{
	socks_clear_failed_emails();
	load_socks_ok(true);
	dash_log('info', '=== RETRY PASS: '.count($retry_list).' emails · OK proxies now: '.count($socks_ok).' ===');
	dash_js("dashMeta('Retry pass — ".count($retry_list)." queued');");
	$still_fail = array();
	foreach($retry_list as $rxi => $to)
	{
	$mail->clearAllRecipients();
	if(!empty($allsmtps) && $default_system != "1")
	{
	$prev_smtp = $curentsmtp;
	if(!smtp_pick_next($curentsmtp))
	{
	dash_log('fail', 'Retry stopped — SMTP daily limit');
	$still_fail[] = $to;
	for($k = $rxi + 1; $k < count($retry_list); $k++) $still_fail[] = $retry_list[$k];
	break;
	}
	if($curentsmtp !== $prev_smtp) smtp_apply_current(true);
	}
	if($default_system != "1" && !empty($mail->Username))
	{
	$from = $mail->Username;
	$mail->From = $from;
	$mail->Sender = $from;
	}
	$mail->AddAddress($to);
	dash_log('info', 'Retry '.($rxi+1).'/'.count($retry_list).' → '.$to);
	$attempt = mailer_socks_send_attempt($to);
	if(!empty($attempt['ok']))
	{
	dash_log('ok', 'OK (retry) '.$to);
	dash_js('dashOk();');
	if(!empty($mail_queue_campaign)) mail_queue_mark_done($to);
	if(!empty($allsmtps) && $default_system != "1")
	smtp_note_sent_ok();
	}
	else if(!empty($attempt['queued']))
	{
	$still_fail[] = $to;
	dash_log('fail', 'Still QUEUED '.$to.' → failed_emails.txt');
	}
	else
	{
	$still_fail[] = $to;
	dash_log('fail', 'FAIL (retry) '.$to.' — '.(isset($attempt['err']) ? $attempt['err'] : ''));
	dash_js('dashFail();');
	}
	if(!empty($pause) && doubleval($pause) > 0) pause($pause, $mail);
	}
	foreach($still_fail as $e) socks_queue_failed_email($e);
	if(!empty($still_fail))
	dash_log('warn', count($still_fail).' still in failed_emails.txt — run Send again later or keep filter running');
	else
	dash_log('ok', 'Retry pass complete — failed_emails.txt empty');
	}
	socks_start_background_filter();
	}

	dash_log('info', 'Campaign finished.');
	dash_js("dashDone();");
	send_mark_stopped();
	} // end sequential (non-parallel) send
	}
	}
?>