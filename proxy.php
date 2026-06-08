<?php
/**
 * proxy.php — Proxy serveur pour le calendrier ICS Brightspace
 * Mettre dans le même dossier que index.html.
 */

$ALLOWED_HOSTS = ['emlyon.brightspace.com', 'brightspace.com', 'em-lyon.com'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (!$url) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Parametre url manquant']);
    exit;
}

// Validation du domaine (compatible PHP 7.x)
$host    = parse_url($url, PHP_URL_HOST);
$allowed = false;
foreach ($ALLOWED_HOSTS as $ah) {
    if ($host === $ah || substr($host, -strlen($ah) - 1) === '.' . $ah) {
        $allowed = true; break;
    }
}
if (!$allowed) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => "Domaine non autorise : $host"]);
    exit;
}

$body   = false;
$status = 0;
$err    = '';

// --- Tentative 1 : cURL avec vérification SSL ---
if (function_exists('curl_init')) {
    list($body, $status, $err) = _curl_fetch($url, true);

    // --- Tentative 2 : cURL sans vérification SSL (si le CA manque sur le serveur) ---
    if ($body === false || $status !== 200) {
        list($body2, $status2, $err2) = _curl_fetch($url, false);
        if ($body2 !== false && $status2 === 200) {
            $body = $body2; $status = $status2; $err = '';
        }
    }
}

// --- Tentative 3 : file_get_contents ---
if (($body === false || $status !== 200) && ini_get('allow_url_fopen')) {
    $ctx  = stream_context_create(['http' => [
        'timeout'     => 15,
        'user_agent'  => 'EMMGO-Dashboard/1.0',
        'header'      => "Accept: text/calendar, */*\r\n",
        'ignore_errors' => true,
    ]]);
    $tmp = @file_get_contents($url, false, $ctx);
    if ($tmp !== false) { $body = $tmp; $status = 200; $err = ''; }
}

// --- Réponse ---
if ($body !== false && $status === 200) {
    header('Content-Type: text/calendar; charset=utf-8');
    echo $body;
} else {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'error'  => "Impossible de recuperer le calendrier",
        'detail' => $err ?: "HTTP $status",
        'hint'   => 'Visite test-proxy.php pour diagnostiquer le probleme',
    ]);
}

function _curl_fetch($url, $verifySSL) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => $verifySSL,
        CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
        CURLOPT_USERAGENT      => 'EMMGO-Dashboard/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/calendar, */*'],
        CURLOPT_ENCODING       => '',
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);
    return [$body === false ? false : $body, $status, $error];
}
