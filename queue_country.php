<?php
/** Country code for recipient files. pt keeps the original filenames. */
function queue_country_code($raw)
{
	$c = strtolower(preg_replace('/[^a-z]/', '', (string)$raw));
	if(strlen($c) !== 2) return 'pt';
	return $c;
}

function queue_country_files($lane, $country)
{
	$country = queue_country_code($country);
	$suffix = ($country === 'pt') ? '' : ('_'.$country);
	if($lane === 'smtp')
	{
		return array(
			'valid' => 'validmails_smtp'.$suffix.'.txt',
			'done' => 'mailssenddone_smtp'.$suffix.'.txt',
			'prog' => 'mail_queue_progress_smtp'.$suffix.'.json'
		);
	}
	return array(
		'valid' => 'validmails'.$suffix.'.txt',
		'done' => 'mailssenddone'.$suffix.'.txt',
		'prog' => 'mail_queue_progress'.$suffix.'.json'
	);
}
