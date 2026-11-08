<?php
/**
 * Webhook configuration / setup for Brevo transactional events.
 * POST JSON:
 *   action=status|save_url|create|list
 *   text / key — API accounts
 *   public_base_url — https://your-public-host/.../ayb-mailer
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

try {
	$in = ba_require_post_json();
	$action = isset($in['action']) ? (string)$in['action'] : 'status';
	$cfg = ba_config_load();
	$pdo = ba_db();

	if ($action === 'save_url') {
		$url = isset($in['public_base_url']) ? rtrim(trim((string)$in['public_base_url']), '/') : '';
		$cfg['public_base_url'] = $url;
		ba_config_save($cfg);
		$action = 'status';
	}

	$callbackUrl = '';
	if ($cfg['public_base_url'] !== '') {
		$callbackUrl = $cfg['public_base_url'].'/brevo_webhook.php?token='.rawurlencode($cfg['webhook_token']);
	}

	if ($action === 'status') {
		$rows = $pdo->query('SELECT * FROM ba_webhooks ORDER BY updated_at DESC')->fetchAll();
		echo json_encode(array(
			'ok' => true,
			'public_base_url' => $cfg['public_base_url'],
			'webhook_url' => $callbackUrl,
			'webhook_token_preview' => substr($cfg['webhook_token'], 0, 8).'…',
			'webhook_type' => 'transactional',
			'configured_events' => ba_default_webhook_events(),
			'can_create' => ($callbackUrl !== '' && preg_match('#^https://#i', $callbackUrl)),
			'note' => 'A real Brevo webhook is created only when a valid public HTTPS base URL is set.',
			'local_webhooks' => $rows,
			'export_callback_url' => ($cfg['public_base_url'] !== ''
				? $cfg['public_base_url'].'/brevo_export_callback.php?token='.rawurlencode($cfg['webhook_token'])
				: '')
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	$text = isset($in['text']) ? (string)$in['text'] : '';
	$accounts = array();
	if (!empty($in['key'])) $accounts[] = array('key' => trim((string)$in['key']));
	else $accounts = ba_parse_accounts($text);
	if (empty($accounts)) {
		echo json_encode(array('ok' => false, 'err' => 'No API keys found'));
		exit;
	}

	if ($action === 'list') {
		$out = array();
		foreach ($accounts as $i => $acc) {
			$res = ba_list_webhooks($acc['key'], 'transactional');
			$out[] = array(
				'index' => $i + 1,
				'key_preview' => ba_key_preview($acc['key']),
				'ok' => $res['ok'],
				'err' => $res['err'],
				'webhooks' => $res['ok'] ? (isset($res['data']['webhooks']) ? $res['data']['webhooks'] : $res['data']) : null
			);
		}
		echo json_encode(array('ok' => true, 'accounts' => $out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'create') {
		if (!empty($in['public_base_url'])) {
			$cfg['public_base_url'] = rtrim(trim((string)$in['public_base_url']), '/');
			ba_config_save($cfg);
			$callbackUrl = $cfg['public_base_url'].'/brevo_webhook.php?token='.rawurlencode($cfg['webhook_token']);
		}
		if ($callbackUrl === '' || !preg_match('#^https://#i', $callbackUrl)) {
			echo json_encode(array('ok' => false, 'err' => 'Set a valid public HTTPS base URL before creating Brevo webhooks'));
			exit;
		}
		$events = ba_default_webhook_events();
		$results = array();
		foreach ($accounts as $i => $acc) {
			$key = $acc['key'];
			$hash = ba_key_hash($key);
			$url = $callbackUrl.'&akh='.rawurlencode($hash);

			// Inspect existing webhooks — reuse if same URL
			$existing = ba_list_webhooks($key, 'transactional');
			$reused = null;
			if ($existing['ok']) {
				$list = array();
				if (!empty($existing['data']['webhooks']) && is_array($existing['data']['webhooks'])) $list = $existing['data']['webhooks'];
				else if (is_array($existing['data'])) $list = $existing['data'];
				foreach ($list as $wh) {
					if (!is_array($wh)) continue;
					$wurl = isset($wh['url']) ? (string)$wh['url'] : '';
					if ($wurl !== '' && strpos($wurl, 'brevo_webhook.php') !== false && strpos($wurl, $cfg['webhook_token']) !== false) {
						$reused = $wh;
						break;
					}
					if ($wurl === $url) { $reused = $wh; break; }
				}
			}

			if ($reused) {
				$wid = isset($reused['id']) ? (string)$reused['id'] : '';
				$pdo->prepare("INSERT INTO ba_webhooks (account_key_hash, brevo_webhook_id, url, webhook_type, events_json, status, updated_at)
					VALUES (?,?,?,?,?,?,datetime('now'))")->execute(array(
					$hash, $wid, isset($reused['url']) ? $reused['url'] : $url, 'transactional',
					json_encode(isset($reused['events']) ? $reused['events'] : $events), 'reused'
				));
				$results[] = array(
					'index' => $i + 1,
					'key_preview' => ba_key_preview($key),
					'ok' => true,
					'reused' => true,
					'webhook_id' => $wid,
					'url' => isset($reused['url']) ? $reused['url'] : $url,
					'events' => isset($reused['events']) ? $reused['events'] : $events,
					'status' => 'connected'
				);
				continue;
			}

			$created = ba_create_webhook($key, $url, $events, 'transactional');
			if ($created['ok']) {
				$wid = isset($created['data']['id']) ? (string)$created['data']['id'] : '';
				$pdo->prepare("INSERT INTO ba_webhooks (account_key_hash, brevo_webhook_id, url, webhook_type, events_json, status, updated_at)
					VALUES (?,?,?,?,?,?,datetime('now'))")->execute(array(
					$hash, $wid, $url, 'transactional', json_encode($events), 'created'
				));
				$results[] = array(
					'index' => $i + 1,
					'key_preview' => ba_key_preview($key),
					'ok' => true,
					'reused' => false,
					'webhook_id' => $wid,
					'url' => $url,
					'events' => $events,
					'status' => 'connected'
				);
			} else {
				$results[] = array(
					'index' => $i + 1,
					'key_preview' => ba_key_preview($key),
					'ok' => false,
					'err' => $created['err'],
					'status' => 'error'
				);
			}
		}
		echo json_encode(array(
			'ok' => true,
			'webhook_url' => $callbackUrl,
			'webhook_type' => 'transactional',
			'configured_events' => $events,
			'accounts' => $results
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	echo json_encode(array('ok' => false, 'err' => 'Unknown action'));
} catch (Exception $e) {
	echo json_encode(array('ok' => false, 'err' => $e->getMessage()));
}
