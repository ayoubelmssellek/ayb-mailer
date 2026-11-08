<?php
/**
 * Lightweight .env loader for AYB Mailer.
 * Does not override existing environment variables.
 */
function ayb_env_load($path = null)
{
	static $loaded = false;
	if ($loaded) return;
	$loaded = true;
	if ($path === null) $path = __DIR__.DIRECTORY_SEPARATOR.'.env';
	if (!is_file($path) || !is_readable($path)) return;
	$lines = file($path, FILE_IGNORE_NEW_LINES);
	if ($lines === false) return;
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') continue;
		if (strpos($line, '=') === false) continue;
		list($key, $val) = explode('=', $line, 2);
		$key = trim($key);
		$val = trim($val);
		if ($key === '') continue;
		if (strlen($val) >= 2) {
			$q = $val[0];
			if (($q === '"' || $q === "'") && substr($val, -1) === $q) {
				$val = substr($val, 1, -1);
			}
		}
		if (getenv($key) === false && !isset($_ENV[$key])) {
			putenv($key.'='.$val);
			$_ENV[$key] = $val;
			$_SERVER[$key] = $val;
		}
	}
}

function ayb_env($key, $default = '')
{
	ayb_env_load();
	$v = getenv($key);
	if ($v === false || $v === null || $v === '') {
		if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
		return $default;
	}
	return (string)$v;
}

function ayb_env_bool($key, $default = false)
{
	$v = strtolower(trim(ayb_env($key, $default ? 'true' : 'false')));
	return in_array($v, array('1', 'true', 'yes', 'on'), true);
}
