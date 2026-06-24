<?php
/**
 * api.php — Backend EMMGO Dashboard
 */

// ── Session persistante (30 jours) avec flags sécurisés ─────────
$sessionLifetime = 30 * 24 * 3600; // 30 jours
ini_set('session.gc_maxlifetime', $sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => true,   // HTTPS uniquement
    'httponly' => true,   // Inaccessible au JS
    'samesite' => 'Lax',  // Compatible PWA + reverse proxy
]);
session_name('emmgo_session');
session_start();

define('DATA_DIR',    __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('STATE_FILE',  DATA_DIR . '/state.json');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

$ALLOWED_HOSTS = ['emlyon.brightspace.com', 'brightspace.com', 'em-lyon.com'];

function readJson($file, $default = []) {
    if (!file_exists($file)) return $default;
    $d = @json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}
function writeJson($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function isLoggedIn()         { return !empty($_SESSION['emmgo_auth']); }
function requireAuth()        { if (!isLoggedIn()) respond(['error' => 'Non authentifié'], 401); }
function getConfig()          { return readJson(CONFIG_FILE, ['password_hash'=>'','share_token'=>'','ics_url'=>'']); }
function isValidShare($token) {
    if (empty($token)) return false;
    $cfg = getConfig();
    return !empty($cfg['share_token']) && hash_equals($cfg['share_token'], $token);
}
function curlFetch($url) {
    if (function_exists('curl_init')) {
        foreach ([true, false] as $ssl) {
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
                CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>$ssl,CURLOPT_USERAGENT=>'EMMGO-Dashboard/1.0']);
            $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($body && $status === 200) return $body;
        }
    }
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http'=>['timeout'=>15,'user_agent'=>'EMMGO-Dashboard/1.0','ignore_errors'=>true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body) return $body;
    }
    return false;
}

// Ensure data dir
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
    @file_put_contents(DATA_DIR.'/.htaccess', "Require all denied\n");
    @file_put_contents(DATA_DIR.'/index.html', '');
}
if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
    respond(['error'=>'data/ non accessible en écriture','code'=>'NO_WRITE'], 503);
}

$config = getConfig();
$action = $_GET['action'] ?? '';
if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

if (empty($config['password_hash']) && $action !== 'ping') {
    respond(['error'=>'Non configuré — visite setup.php','code'=>'NOT_CONFIGURED'], 503);
}

switch ($action) {

    case 'ping':
        respond(['ok'=>true, 'configured'=>!empty($config['password_hash'])]);

    case 'get_config':
        $data = ['ok'=>true, 'logged_in'=>isLoggedIn(), 'share_enabled'=>!empty($config['share_token']),
                 'share_token'=> isLoggedIn() ? ($config['share_token']??'') : null];
        if (isLoggedIn()) {
            $data['ics_url']             = $config['ics_url']             ?? '';
            $data['private_ics_url']     = $config['private_ics_url']     ?? '';
            $data['dashboard_name']      = $config['dashboard_name']      ?? '';
        }
        respond($data);

    case 'login':
        $pwd = $input['password'] ?? '';
        if (empty($pwd)) respond(['error'=>'Mot de passe manquant'], 400);
        if (!password_verify($pwd, $config['password_hash'])) { sleep(1); respond(['error'=>'Mot de passe incorrect'], 401); }
        $_SESSION['emmgo_auth'] = true;
        $_SESSION['login_time'] = time();
        session_regenerate_id(true);
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessionLifetime,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        respond(['ok'=>true, 'logged_in'=>true,
            'ics_url'         => $config['ics_url']         ?? '',
            'private_ics_url' => $config['private_ics_url'] ?? '',
            'dashboard_name'  => $config['dashboard_name']  ?? '']);

    // Sauvegarder le nom personnalisé du dashboard
    case 'save_dashboard_name':
        requireAuth();
        $name = trim($input['name'] ?? '');
        if (strlen($name) > 80) respond(['error'=>'Nom trop long (max 80 car.)'], 400);
        $config['dashboard_name'] = $name;
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    // Sauvegarder l'URL ICS privée (groupe de travail) — pas de restriction de domaine
    case 'save_private_ics_url':
        requireAuth();
        $url = trim($input['private_ics_url'] ?? '');
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) respond(['error'=>'URL invalide'], 400);
        if (!empty($url) && parse_url($url, PHP_URL_SCHEME) !== 'https') respond(['error'=>'HTTPS requis'], 400);
        $config['private_ics_url'] = $url;
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    // Fetcher l'ICS privé (groupe) — auth OU share token valide
    // Pas de restriction de domaine : l'URL est saisie par l'utilisateur authentifié
    case 'fetch_private_ics':
        $shareToken = $_GET['share'] ?? ($input['share'] ?? '');
        if (!isLoggedIn() && !isValidShare($shareToken)) respond(['error'=>'Non autorisé'], 401);
        $picsUrl = $config['private_ics_url'] ?? '';
        if (empty($picsUrl)) respond(['error'=>'Aucune URL ICS privée configurée'], 404);
        $body = curlFetch($picsUrl);
        if (!$body) respond(['error'=>'Impossible de récupérer le calendrier privé'], 502);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
        exit;

    case 'logout':
        $_SESSION = []; session_destroy();
        respond(['ok'=>true, 'logged_in'=>false]);

    // Sauvegarder l'URL ICS (connecté uniquement)
    case 'save_ics_url':
        requireAuth();
        $url = trim($input['ics_url'] ?? '');
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) respond(['error'=>'URL invalide'], 400);
        $host = parse_url($url, PHP_URL_HOST);
        $ok = false;
        foreach ($ALLOWED_HOSTS as $ah) { if ($host===$ah||substr($host,-strlen($ah)-1)==='.'.$ah){$ok=true;break;} }
        if (!$ok) respond(['error'=>"Domaine non autorisé : $host"], 403);
        $config['ics_url'] = $url;
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    // Fetcher l'ICS côté serveur — URL Brightspace jamais exposée au navigateur
    case 'fetch_ics':
        $shareToken = $_GET['share'] ?? ($input['share'] ?? '');
        if (!isLoggedIn() && !isValidShare($shareToken)) respond(['error'=>'Non autorisé'], 401);
        $icsUrl = $config['ics_url'] ?? '';
        if (empty($icsUrl)) respond(['error'=>'Aucune URL ICS configurée — connecte-toi et saisis l\'URL Brightspace dans les paramètres'], 404);
        $body = curlFetch($icsUrl);
        if (!$body) respond(['error'=>'Impossible de récupérer le calendrier Brightspace'], 502);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
        exit;

    case 'get_state':
        $shareToken = $_GET['share'] ?? ($input['share'] ?? '');
        $readOnly   = !isLoggedIn();
        if ($readOnly && !isValidShare($shareToken)) respond(['error'=>'Non autorisé'], 401);
        $state = readJson(STATE_FILE, ['rendus'=>new stdClass()]);
        respond(['ok'=>true, 'state'=>$state, 'read_only'=>$readOnly,
                 'dashboard_name'=>$config['dashboard_name']??'']);

    case 'set_state':
        requireAuth();
        if (!isset($input['state'])) respond(['error'=>'Champ state manquant'], 400);
        writeJson(STATE_FILE, $input['state']) ? respond(['ok'=>true]) : respond(['error'=>'Erreur écriture state.json'], 500);

    case 'regen_token':
        requireAuth();
        $config['share_token'] = bin2hex(random_bytes(16));
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true, 'share_token'=>$config['share_token']]);

    case 'disable_share':
        requireAuth();
        $config['share_token'] = '';
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    case 'change_password':
        requireAuth();
        $old = $input['old_password'] ?? ''; $new = $input['new_password'] ?? '';
        if (strlen($new) < 6) respond(['error'=>'Mot de passe trop court (min. 6 car.)'], 400);
        if (!password_verify($old, $config['password_hash'])) respond(['error'=>'Ancien mot de passe incorrect'], 401);
        $config['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    case 'upcoming':
        $shareToken = $_GET['token'] ?? ($_GET['share'] ?? ($input['token'] ?? ''));
        if (!isLoggedIn() && !isValidShare($shareToken))
            respond(['error' => 'Non autorisé'], 401);

        $limit  = max(1, min(20, (int)($_GET['limit'] ?? 5)));
        $days   = max(1, min(60, (int)($_GET['days']  ?? 14)));
        $now    = time();
        $cutoff = $now + $days * 86400;

        // ICS URLs à parcourir
        $icsUrls = array_filter([
            $config['ics_url']         ?? '',
            $config['private_ics_url'] ?? '',
        ]);

        // groupTagsCache depuis state.json pour typage et filtre ignored
        $state      = readJson(STATE_FILE, []);
        $groupTags  = $state['groupTagsCache'] ?? [];
        $ignoredMap = [];
        foreach ($groupTags as $uid => $tag) {
            if (!empty($tag['ignored'])) $ignoredMap[$uid] = true;
        }

        $events = [];

        foreach ($icsUrls as $icsUrl) {
            $raw = curlFetch($icsUrl);
            if (!$raw) continue;

            $lines   = preg_split('/\r?\n/', $raw);
            $inEvent = false;
            $ev      = [];

            foreach ($lines as $line) {
                if ($line === 'BEGIN:VEVENT') { $inEvent = true; $ev = []; continue; }
                if ($line === 'END:VEVENT') {
                    $inEvent = false;
                    $uid     = $ev['UID']     ?? '';
                    $start   = $ev['DTSTART'] ?? 0;
                    $end     = $ev['DTEND']   ?? $start;
                    $summary = $ev['SUMMARY'] ?? '';

                    // Filtres
                    if (!$start || $start < $now || $start > $cutoff) continue;
                    if (isset($ignoredMap[$uid])) continue;

                    // Typage
                    $tag = $groupTags[$uid] ?? null;
                    // Typage aligné sur la logique de l'app
                    $loc  = $ev['LOCATION']    ?? '';
                    $desc = $ev['DESCRIPTION'] ?? '';
                    $sl   = strtolower($summary);
                    $ll   = strtolower($loc);
                    $dl   = strtolower($desc);
                    $sessionTitleRe = '/^\d{4}_[A-Z0-9]+_\d{4}-\d{2}\s+.+$/i';

                    $isDevoir = str_contains($sl,'assessment') || str_contains($sl,'co-construction')
                             || str_contains($dl,'assessment') || str_contains($dl,'co-construction')
                             || str_ends_with($sl,'à échéance');

                    $isSession = str_contains($sl,'live session') || str_contains($sl,'cours distanciel')
                              || str_contains($ll,'live session') || str_contains($ll,'cours distanciel')
                              || str_contains($ll,'teams.microsoft') || str_contains($ll,'teams.live.com')
                              || str_contains($ll,'virtual-room.em-lyon.com')
                              || preg_match($sessionTitleRe, $summary);

                    if ($isDevoir) {
                        // co-construction = collectif, sinon individuel → tous deadline ici
                        $type = 'deadline';
                    } elseif ($isSession) {
                        $type = 'session';
                    } elseif ($tag && !empty($tag['devoirUid']) && $tag['devoirUid'] !== '__subgroup__') {
                        $type = 'workshop';
                    } else {
                        $type = 'event';
                    }

                    $daysUntil = (int)(($start - $now) / 86400);
                    $events[]  = [
                        'uid'        => $uid,
                        'summary'    => $summary,
                        'type'       => $type,
                        'start'      => $start,
                        'end'        => $end,
                        'start_iso'  => date('c', $start),
                        'end_iso'    => date('c', $end),
                        'days_until' => $daysUntil,
                        'subject'    => $tag['subjectName'] ?? ($tag['subject'] ?? null),
                    ];
                    continue;
                }
                if (!$inEvent) continue;

                // Parse key[:params]:value, unfold multi-line
                if (preg_match('/^([\w-]+)(?:;[^:]+)?:(.*)$/', $line, $m)) {
                    $key = strtoupper(explode(';', $m[1])[0]);
                    $val = $m[2];
                    if (in_array($key, ['DTSTART', 'DTEND'])) {
                        $d = preg_replace('/[^\d]/', '', substr($val, 0, 15));
                        $val = strlen($d) >= 14
                            ? mktime((int)substr($d,8,2),(int)substr($d,10,2),(int)substr($d,12,2),
                                     (int)substr($d,4,2),(int)substr($d,6,2),(int)substr($d,0,4))
                            : mktime(0,0,0,(int)substr($d,4,2),(int)substr($d,6,2),(int)substr($d,0,4));
                    }
                    $ev[$key] = $val;
                }
            }
        }

        usort($events, fn($a, $b) => $a['start'] <=> $b['start']);
        // Métriques agrégées (calculées avant le slice)
        $stats = [
            'a_venir'     => count($events),
            'individuels' => count(array_filter($events, fn($e) => $e['type'] === 'deadline')),
            'collectifs'  => count(array_filter($events, fn($e) => $e['type'] === 'workshop')),
            'urgents'     => count(array_filter($events, fn($e) =>
                                $e['type'] === 'deadline' && $e['days_until'] <= 7)),
        ];

        $events = array_values(array_slice($events, 0, $limit));
        respond(['ok' => true, 'count' => count($events), 'events' => $events,
                 'stats' => $stats, 'generated_at' => date('c')]);

    default:
        respond(['error'=>"Action inconnue : $action"], 400);
}
