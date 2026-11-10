<?php
/**
 * Sender identity profiles (From / Reply-To / display name).
 * Profiles do not store SMTP passwords.
 */
if (!defined('AYB_SENDER_PROFILES_PATH')) {
	define('AYB_SENDER_PROFILES_PATH', __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'sender_profiles.json');
}

function sender_profiles_defaults()
{
	return array(
		'version' => 1,
		'default_id' => 'mail',
		'profiles' => array(
			array(
				'id' => 'mail',
				'from_email' => 'mail@omnidigitalsuite.com',
				'from_name' => 'OmniDigitalSuite',
				'reply_to' => 'mail@omnidigitalsuite.com',
				'methods' => array('kumomta'),
				'notes' => 'Primary marketing sender'
			),
			array(
				'id' => 'news',
				'from_email' => 'news@omnidigitalsuite.com',
				'from_name' => 'OmniDigitalSuite News',
				'reply_to' => 'news@omnidigitalsuite.com',
				'methods' => array('kumomta'),
				'notes' => 'Newsletter identity'
			),
			array(
				'id' => 'notify',
				'from_email' => 'notify@omnidigitalsuite.com',
				'from_name' => 'OmniDigitalSuite',
				'reply_to' => 'notify@omnidigitalsuite.com',
				'methods' => array('kumomta', 'sendgrid'),
				'notes' => 'Notifications; also used as SendGrid default From'
			)
		)
	);
}

function sender_profiles_ensure()
{
	$dir = dirname(AYB_SENDER_PROFILES_PATH);
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
	if (!is_file(AYB_SENDER_PROFILES_PATH)) {
		$defaults = sender_profiles_defaults();
		@file_put_contents(
			AYB_SENDER_PROFILES_PATH,
			json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		return $defaults;
	}
	$raw = @file_get_contents(AYB_SENDER_PROFILES_PATH);
	$data = json_decode((string)$raw, true);
	if (!is_array($data) || empty($data['profiles']) || !is_array($data['profiles'])) {
		$defaults = sender_profiles_defaults();
		@file_put_contents(
			AYB_SENDER_PROFILES_PATH,
			json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		return $defaults;
	}
	if (empty($data['default_id'])) $data['default_id'] = $data['profiles'][0]['id'];
	return $data;
}

function sender_profiles_all()
{
	$data = sender_profiles_ensure();
	return isset($data['profiles']) && is_array($data['profiles']) ? $data['profiles'] : array();
}

function sender_profile_get($id)
{
	$id = strtolower(trim((string)$id));
	$all = sender_profiles_all();
	foreach ($all as $p) {
		if (!is_array($p)) continue;
		if (strtolower((string)$p['id']) === $id) return $p;
	}
	$data = sender_profiles_ensure();
	$def = isset($data['default_id']) ? strtolower((string)$data['default_id']) : '';
	foreach ($all as $p) {
		if (is_array($p) && strtolower((string)$p['id']) === $def) return $p;
	}
	return !empty($all[0]) && is_array($all[0]) ? $all[0] : null;
}

function sender_profile_public_list()
{
	$out = array();
	foreach (sender_profiles_all() as $p) {
		if (!is_array($p)) continue;
		$out[] = array(
			'id' => isset($p['id']) ? (string)$p['id'] : '',
			'from_email' => isset($p['from_email']) ? (string)$p['from_email'] : '',
			'from_name' => isset($p['from_name']) ? (string)$p['from_name'] : '',
			'reply_to' => isset($p['reply_to']) ? (string)$p['reply_to'] : '',
			'methods' => isset($p['methods']) && is_array($p['methods']) ? $p['methods'] : array(),
			'notes' => isset($p['notes']) ? (string)$p['notes'] : ''
		);
	}
	$data = sender_profiles_ensure();
	return array(
		'ok' => true,
		'default_id' => isset($data['default_id']) ? (string)$data['default_id'] : '',
		'profiles' => $out
	);
}
