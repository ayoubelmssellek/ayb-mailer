<?php
/**
 * Notify URL for Brevo weekly transactional event export.
 * Expects: { "url": "...", "process_id": 123 }
 * Must respond with the string: success
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(300);

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

$cfg = ba_config_load();
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '' || !hash_equals((string)$cfg['webhook_token'], $token)) {
	http_response_code(401);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'unauthorized';
	exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) $data = $_POST;

$url = isset($data['url']) ? (string)$data['url'] : '';
$processId = isset($data['process_id']) ? (string)$data['process_id'] : (isset($data['processId']) ? (string)$data['processId'] : '');
$akh = isset($_GET['akh']) ? (string)$_GET['akh'] : '';

header('Content-Type: text/plain; charset=utf-8');

if ($url === '') {
	// Acknowledge empty probes
	echo 'success';
	exit;
}

try {
	$pdo = ba_db();
	if ($processId !== '') {
		$pdo->prepare("INSERT OR IGNORE INTO ba_export_jobs (account_key_hash, process_id, export_type, status, export_url)
			VALUES (?,?,?,?,?)")->execute(array($akh, $processId, 'transactional', 'completed', $url));
		$pdo->prepare("UPDATE ba_export_jobs SET status='completed', export_url=?, updated_at=datetime('now') WHERE process_id=?")
			->execute(array($url, $processId));
	}

	$dest = BA_DATA_DIR.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'cb_'.($processId !== '' ? $processId : uniqid()).'.bin';
	$dl = ba_http_download($url, $dest, 180);
	if ($dl['ok']) {
		$imp = ba_import_export_file($akh !== '' ? $akh : 'unknown', $dest, false);
		if ($processId !== '') {
			$pdo->prepare("UPDATE ba_export_jobs SET status='imported', imported_count=?, updated_at=datetime('now') WHERE process_id=?")
				->execute(array($imp['imported'], $processId));
		}
		@unlink($dest);
	} else if ($processId !== '') {
		$pdo->prepare("UPDATE ba_export_jobs SET status='download_failed', updated_at=datetime('now') WHERE process_id=?")
			->execute(array($processId));
	}
} catch (Exception $e) {
	// Still acknowledge so Brevo does not hammer retries endlessly
}

echo 'success';
