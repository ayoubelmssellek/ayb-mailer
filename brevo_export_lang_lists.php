<?php
/**
 * Export unique delivered recipient emails by subject language (PT / ES).
 * Reads existing SQLite only — does not modify analytics tables.
 *
 * Usage: php brevo_export_lang_lists.php
 * Output: data/exports/PT.txt , data/exports/ES.txt , data/exports/lang_export_report.json
 */
@ini_set('display_errors', '1');
@error_reporting(E_ALL);
@set_time_limit(0);

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

function lang_norm_email($email)
{
	return strtolower(trim((string)$email));
}

function lang_is_email($email)
{
	return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email);
}

/**
 * Classify subject language for PT vs ES.
 * Returns: pt | es | ambiguous | empty | other
 */
function lang_classify_subject($subject)
{
	$s = trim((string)$subject);
	if ($s === '' || strcasecmp($s, '(unavailable)') === 0) return 'empty';

	// Strip common marketing noise / random suffixes for scoring
	$plain = $s;
	$plain = preg_replace('/\[[^\]]*\]/u', ' ', $plain);
	$plain = preg_replace('/https?:\/\/\S+/iu', ' ', $plain);
	$plain = preg_replace('/\s+/u', ' ', $plain);
	$plain = trim($plain);
	$lower = mb_strtolower($plain, 'UTF-8');

	// Strong Portuguese markers (orthography / function words / common campaign phrases)
	$ptMarkers = array(
		// distinctive orthography / words rarely Spanish
		'sua ', 'suas ', 'seu ', 'seus ', 'você', 'voce', 'vocês', 'voces',
		'subscrição', 'subscricao', 'assinatura', 'notificação', 'notificacao',
		'informação', 'informacao', 'informações', 'informacoes',
		'conteúdos', 'conteudos', 'conteúdo', 'conteudo',
		'detalhes e acesso', 'plano de conteúdos', 'plano de conteudos',
		'lista completa', 'canais de notícias', 'canais de noticias',
		'estreias de cinema', 'futebol', 'importante', 'importantes',
		'sobre a sua', 'sobre o seu', 'aos seus', 'às suas', 'as suas',
		'actualização', 'atualização', 'atualizacao', 'actualizacao',
		'obrigado', 'obrigada', 'bem-vindo', 'bem vindo', 'disponível', 'disponivel',
		'aqui está', 'aqui esta', 'clique aqui', 'aceda', 'acesso ao',
		'portugal', 'português', 'portugues',
		// Portuguese-only characters / endings often decisive when paired
		'ção', 'ções', 'são ', 'não ', 'também', 'tambem'
	);

	// Strong Spanish markers
	$esMarkers = array(
		'su lista', 'su plan', 'su suscripción', 'su suscripcion',
		'suscripción', 'suscripcion', 'notificación', 'notificacion',
		'información', 'informacion', 'detalles y acceso', 'acceso a su',
		'contenido', 'contenidos', 'novedades', 'actualización', 'actualizacion',
		'bienvenido', 'bienvenida', 'gracias', 'haga clic', 'pulse aquí', 'pulse aqui',
		'usted', 'ustedes', 'español', 'espanol', 'españa', 'espana',
		'canales de', 'fútbol', 'futbol', 'estrenos',
		// Spanish orthography cues
		'ción', 'ciones', 'también', 'tambien', 'más ', 'más.', 'más,',
		'¿', '¡'
	);

	$ptScore = 0;
	$esScore = 0;

	foreach ($ptMarkers as $m) {
		if (mb_strpos($lower, mb_strtolower($m, 'UTF-8'), 0, 'UTF-8') !== false) $ptScore += 2;
	}
	foreach ($esMarkers as $m) {
		if (mb_strpos($lower, mb_strtolower($m, 'UTF-8'), 0, 'UTF-8') !== false) $esScore += 2;
	}

	// Character / diacritic cues
	if (preg_match('/[ãõ]/u', $lower)) $ptScore += 4;          // strongly Portuguese
	if (preg_match('/\b(não|são|você|vocês)\b/u', $lower)) $ptScore += 4;
	if (preg_match('/[ñ¿¡]/u', $lower)) $esScore += 4;         // strongly Spanish
	if (preg_match('/\b(usted|ustedes|gracias|bienvenido)\b/u', $lower)) $esScore += 3;

	// Shared Romance words that alone are weak — only tip if already leaning
	if (preg_match('/\b(informação|informações|subscrição|conteúdos?)\b/u', $lower)) $ptScore += 3;
	if (preg_match('/\b(información|suscripción|contenidos?)\b/u', $lower)) $esScore += 3;

	// English / other clear non-PT/ES
	if (preg_match('/\b(your|update|from|newsletter|special offer|welcome|account|password|invoice)\b/iu', $lower)
		&& $ptScore === 0 && $esScore === 0) {
		return 'other';
	}

	if ($ptScore === 0 && $esScore === 0) return 'ambiguous';
	if ($ptScore > 0 && $esScore > 0) {
		// require clear margin; otherwise ambiguous
		if ($ptScore >= $esScore + 2) return 'pt';
		if ($esScore >= $ptScore + 2) return 'es';
		return 'ambiguous';
	}
	if ($ptScore > 0) return 'pt';
	if ($esScore > 0) return 'es';
	return 'ambiguous';
}

function lang_write_unique_file($path, array $emails)
{
	sort($emails, SORT_STRING);
	$fh = fopen($path, 'wb');
	if (!$fh) throw new RuntimeException('Cannot write '.$path);
	foreach ($emails as $e) fwrite($fh, $e."\n");
	fclose($fh);
}

// ---------- Account 2 accessibility check (optional; does not require keys file) ----------
$acct2Status = 'unknown';
$acct2Note = 'No messages for account_index=2 in SQLite (likely prior IP allowlist failure).';
$pdo = ba_db();
$acct2Count = intval($pdo->query("SELECT COUNT(*) FROM ba_messages WHERE account_index=2")->fetchColumn());
if ($acct2Count > 0) {
	$acct2Status = 'present_in_db';
	$acct2Note = 'Account 2 has '.$acct2Count.' messages in SQLite.';
} else {
	$acct2Status = 'missing_in_db';
}

$accountsPresent = $pdo->query("SELECT DISTINCT account_index FROM ba_messages WHERE account_index > 0 ORDER BY account_index")->fetchAll(PDO::FETCH_COLUMN);
$accountsPresent = array_map('intval', $accountsPresent);

// ---------- Scan delivered messages ----------
$sql = "SELECT recipient_email, subject, current_status, account_index,
	substr(COALESCE(NULLIF(send_date,''), event_date),1,10) AS d
	FROM ba_messages
	WHERE current_status = 'delivered'";
$st = $pdo->query($sql);

$ptEmails = array(); // email => true
$esEmails = array();
$stats = array(
	'delivered_scanned' => 0,
	'delivered_pt_messages' => 0,
	'delivered_es_messages' => 0,
	'pt_subjects' => array(),
	'es_subjects' => array(),
	'ambiguous_subjects' => array(),
	'other_subjects' => array(),
	'empty_subjects' => 0,
	'invalid_emails_skipped' => 0,
	'date_min' => null,
	'date_max' => null,
	'accounts_seen' => array()
);

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
	$stats['delivered_scanned']++;
	$email = lang_norm_email($row['recipient_email']);
	$subj = (string)$row['subject'];
	$ai = intval($row['account_index']);
	if ($ai > 0) $stats['accounts_seen'][$ai] = true;
	$d = $row['d'];
	if ($d !== null && $d !== '') {
		if ($stats['date_min'] === null || strcmp($d, $stats['date_min']) < 0) $stats['date_min'] = $d;
		if ($stats['date_max'] === null || strcmp($d, $stats['date_max']) > 0) $stats['date_max'] = $d;
	}

	if (!lang_is_email($email)) {
		$stats['invalid_emails_skipped']++;
		continue;
	}

	$lang = lang_classify_subject($subj);
	if ($lang === 'empty') {
		$stats['empty_subjects']++;
		continue;
	}
	if ($lang === 'ambiguous') {
		$stats['ambiguous_subjects'][$subj] = isset($stats['ambiguous_subjects'][$subj]) ? $stats['ambiguous_subjects'][$subj] + 1 : 1;
		continue;
	}
	if ($lang === 'other') {
		$stats['other_subjects'][$subj] = isset($stats['other_subjects'][$subj]) ? $stats['other_subjects'][$subj] + 1 : 1;
		continue;
	}
	if ($lang === 'pt') {
		$stats['delivered_pt_messages']++;
		$stats['pt_subjects'][$subj] = isset($stats['pt_subjects'][$subj]) ? $stats['pt_subjects'][$subj] + 1 : 1;
		$ptEmails[$email] = true;
	} else if ($lang === 'es') {
		$stats['delivered_es_messages']++;
		$stats['es_subjects'][$subj] = isset($stats['es_subjects'][$subj]) ? $stats['es_subjects'][$subj] + 1 : 1;
		$esEmails[$email] = true;
	}
}

$ptList = array_keys($ptEmails);
$esList = array_keys($esEmails);

$outDir = BA_DATA_DIR.DIRECTORY_SEPARATOR.'exports';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$ptPath = $outDir.DIRECTORY_SEPARATOR.'PT.txt';
$esPath = $outDir.DIRECTORY_SEPARATOR.'ES.txt';
lang_write_unique_file($ptPath, $ptList);
lang_write_unique_file($esPath, $esList);

// ---------- Validation ----------
function lang_validate_file($path)
{
	$lines = file($path, FILE_IGNORE_NEW_LINES);
	if ($lines === false) return array('ok' => false, 'err' => 'unreadable');
	$seen = array();
	$bad = 0;
	$dup = 0;
	foreach ($lines as $line) {
		$e = strtolower(trim($line));
		if ($e === '') continue;
		if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $e)) $bad++;
		if (isset($seen[$e])) $dup++;
		$seen[$e] = true;
	}
	return array('ok' => ($bad === 0 && $dup === 0), 'lines' => count($lines), 'unique' => count($seen), 'bad' => $bad, 'dup' => $dup);
}

// Ensure no bounced-only contamination: every exported email must have >=1 delivered PT/ES message (already enforced by query)

$ptVal = lang_validate_file($ptPath);
$esVal = lang_validate_file($esPath);

// Integrity: exports are built only from current_status='delivered' rows, so bounced-only
// recipients cannot appear. Spot-check a few emails for safety without N+1 over the full set.
$noDel = 0;
$spot = array_slice(array_unique(array_merge($ptList, $esList)), 0, 200);
$st2 = $pdo->prepare("SELECT 1 FROM ba_messages WHERE recipient_email = ? AND current_status = 'delivered' LIMIT 1");
foreach ($spot as $e) {
	$st2->execute(array($e));
	if (!$st2->fetchColumn()) $noDel++;
}

arsort($stats['pt_subjects']);
arsort($stats['es_subjects']);
arsort($stats['ambiguous_subjects']);
arsort($stats['other_subjects']);

$report = array(
	'ok' => ($ptVal['ok'] && $esVal['ok'] && $noDel === 0),
	'generated_at' => gmdate('c'),
	'files' => array(
		'PT' => str_replace('\\', '/', $ptPath),
		'ES' => str_replace('\\', '/', $esPath)
	),
	'delivered_portuguese_messages' => $stats['delivered_pt_messages'],
	'unique_portuguese_emails' => count($ptList),
	'delivered_spanish_messages' => $stats['delivered_es_messages'],
	'unique_spanish_emails' => count($esList),
	'portuguese_subject_variants' => count($stats['pt_subjects']),
	'spanish_subject_variants' => count($stats['es_subjects']),
	'ambiguous_subject_variants' => count($stats['ambiguous_subjects']),
	'other_subject_variants' => count($stats['other_subjects']),
	'empty_subjects' => $stats['empty_subjects'],
	'ambiguous_message_count' => array_sum($stats['ambiguous_subjects']),
	'other_message_count' => array_sum($stats['other_subjects']),
	'delivered_scanned' => $stats['delivered_scanned'],
	'invalid_emails_skipped' => $stats['invalid_emails_skipped'],
	'accounts_with_data' => array_values($accountsPresent),
	'accounts_successfully_represented' => count($accountsPresent),
	'account_2_status' => $acct2Status,
	'account_2_note' => $acct2Note,
	'accounts_failed_or_missing' => (in_array(2, $accountsPresent, true) ? array() : array(2)),
	'date_coverage' => array('min' => $stats['date_min'], 'max' => $stats['date_max']),
	'validation' => array(
		'PT' => $ptVal,
		'ES' => $esVal,
		'exported_emails_without_delivered_row' => $noDel
	),
	'top_portuguese_subjects' => array_slice($stats['pt_subjects'], 0, 15, true),
	'top_spanish_subjects' => array_slice($stats['es_subjects'], 0, 15, true),
	'top_ambiguous_subjects' => array_slice($stats['ambiguous_subjects'], 0, 15, true),
	'top_other_subjects' => array_slice($stats['other_subjects'], 0, 15, true),
	'db_untouched' => true
);

$reportPath = $outDir.DIRECTORY_SEPARATOR.'lang_export_report.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "PT.txt: ".count($ptList)." unique emails → $ptPath\n";
echo "ES.txt: ".count($esList)." unique emails → $esPath\n";
echo "PT messages: {$stats['delivered_pt_messages']}\n";
echo "ES messages: {$stats['delivered_es_messages']}\n";
echo "Ambiguous subjects: ".count($stats['ambiguous_subjects'])." variants / ".array_sum($stats['ambiguous_subjects'])." messages\n";
echo "Other subjects: ".count($stats['other_subjects'])." variants / ".array_sum($stats['other_subjects'])." messages\n";
echo "Accounts with data: ".implode(',', $accountsPresent)."\n";
echo "Account 2: $acct2Status — $acct2Note\n";
echo "Date coverage: {$stats['date_min']} → {$stats['date_max']}\n";
echo "Validation PT ok=".($ptVal['ok']?'yes':'no')." ES ok=".($esVal['ok']?'yes':'no')." noDel=$noDel\n";
echo "Report: $reportPath\n";
