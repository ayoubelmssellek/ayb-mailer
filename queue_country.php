<?php
/** Country code for recipient files. pt keeps the original filenames. */
function queue_country_code($raw)
{
	$c = strtolower(preg_replace('/[^a-z]/', '', (string)$raw));
	if(strlen($c) !== 2) return 'pt';
	return $c;
}

/**
 * Normalize queue lane.
 * api = SendGrid, smtp = Normal SMTP±SOCKS, kumomta = VPS/KumoMTA.
 */
function queue_lane_normalize($lane)
{
	$lane = strtolower(trim((string)$lane));
	if($lane === 'smtp') return 'smtp';
	if($lane === 'kumomta' || $lane === 'kumo' || $lane === 'vps') return 'kumomta';
	return 'api';
}

function queue_country_files($lane, $country)
{
	$country = queue_country_code($country);
	$suffix = ($country === 'pt') ? '' : ('_'.$country);
	$lane = queue_lane_normalize($lane);
	if($lane === 'smtp')
	{
		return array(
			'valid' => 'validmails_smtp'.$suffix.'.txt',
			'done' => 'mailssenddone_smtp'.$suffix.'.txt',
			'prog' => 'mail_queue_progress_smtp'.$suffix.'.json'
		);
	}
	if($lane === 'kumomta')
	{
		return array(
			'valid' => 'validmails_kumomta'.$suffix.'.txt',
			'done' => 'mailssenddone_kumomta'.$suffix.'.txt',
			'prog' => 'mail_queue_progress_kumomta'.$suffix.'.json'
		);
	}
	return array(
		'valid' => 'validmails'.$suffix.'.txt',
		'done' => 'mailssenddone'.$suffix.'.txt',
		'prog' => 'mail_queue_progress'.$suffix.'.json'
	);
}
