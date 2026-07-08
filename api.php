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

// ── Export ICS — flux abonnables (complet vs partage sans liens) ──────
// Déplie les lignes RFC5545 (une propriété continuée sur la ligne suivante
// commence par un espace/tab).
function icsUnfoldLines($raw) {
    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    $out = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        if (($line[0] === ' ' || $line[0] === "\t") && count($out)) {
            $out[count($out) - 1] .= substr($line, 1);
        } else {
            $out[] = $line;
        }
    }
    return $out;
}

// Replie une ligne à 75 octets max, continuation précédée d'un espace.
function icsFoldLine($line) {
    if (strlen($line) <= 75) return $line;
    $out = ''; $rest = $line; $first = true;
    while (strlen($rest) > 0) {
        $chunkLen = $first ? 75 : 74;
        $out .= ($first ? '' : "\r\n ") . substr($rest, 0, $chunkLen);
        $rest = substr($rest, $chunkLen);
        $first = false;
    }
    return $out;
}

// Extrait les blocs VEVENT et VTIMEZONE (lignes dépliées) d'un flux ICS brut.
function icsExtractBlocks($raw) {
    $lines = icsUnfoldLines($raw);
    $events = []; $timezones = []; $cur = null; $curType = null;
    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT')    { $cur = [$line]; $curType = 'VEVENT';    continue; }
        if ($line === 'BEGIN:VTIMEZONE') { $cur = [$line]; $curType = 'VTIMEZONE'; continue; }
        if ($cur !== null) {
            $cur[] = $line;
            if ($line === 'END:VEVENT'    && $curType === 'VEVENT')    { $events[]    = $cur; $cur = null; }
            if ($line === 'END:VTIMEZONE' && $curType === 'VTIMEZONE') { $timezones[] = $cur; $cur = null; }
        }
    }
    return ['events' => $events, 'timezones' => $timezones];
}

// Lit la valeur d'une propriété (ex. UID) dans un bloc VEVENT déplié.
function icsGetProp(array $lines, $prop) {
    foreach ($lines as $line) {
        if (preg_match('/^' . preg_quote($prop, '/') . '(;[^:]*)?:(.*)$/i', $line, $m)) {
            return $m[2];
        }
    }
    return '';
}

// Préfixe l'UID d'un bloc VEVENT — évite les collisions quand on fusionne
// plusieurs calendriers sources dans un même flux exporté.
function icsPrefixUid(array $lines, $prefix) {
    foreach ($lines as &$line) {
        if (preg_match('/^UID(;[^:]*)?:(.*)$/', $line, $m)) {
            $line = 'UID' . $m[1] . ':' . $prefix . $m[2];
        }
    }
    return $lines;
}

// Classification devoir / live session — même logique que isDevoir()/isSession() côté client (index.html),
// portée en PHP pour pouvoir filtrer le flux public par type.
function icsIsDevoir(array $ev) {
    $s = mb_strtolower(icsGetProp($ev, 'SUMMARY'), 'UTF-8');
    $d = mb_strtolower(icsGetProp($ev, 'DESCRIPTION'), 'UTF-8');
    if (strpos($s, 'assessment') !== false || strpos($s, 'co-construction') !== false) return true;
    if (strpos($d, 'assessment') !== false || strpos($d, 'co-construction') !== false) return true;
    if (preg_match('/à\s?échéance\s*$/u', $s)) return true;
    return false;
}
function icsIsSession(array $ev) {
    $s = mb_strtolower(icsGetProp($ev, 'SUMMARY'), 'UTF-8');
    $l = mb_strtolower(icsGetProp($ev, 'LOCATION'), 'UTF-8');
    if (strpos($s, 'live session') !== false || strpos($s, 'cours distanciel') !== false) return true;
    if (strpos($l, 'live session') !== false || strpos($l, 'cours distanciel') !== false) return true;
    if (strpos($l, 'teams.microsoft') !== false || strpos($l, 'teams.live.com') !== false) return true;
    if (strpos($l, 'virtual-room.em-lyon.com') !== false) return true;
    if (preg_match('/^\d{4}_[A-Z0-9]+_\d{4}-\d{2}\s+.+/i', icsGetProp($ev, 'SUMMARY'))) return true;
    if (strpos(mb_strtolower(implode(' ', $ev), 'UTF-8'), 'virtual-room.em-lyon') !== false) return true;
    return false;
}

// Nettoie un bloc VEVENT pour la version "à partager" : retire tout lien
// (Teams/virtual-room, casier/event Brightspace) et les propriétés qui en
// contiennent nativement. La DESCRIPTION est entièrement vidée (le corps
// peut contenir des infos de connexion en texte brut — code, ID de réunion,
// etc. — qu'un simple retrait des URLs ne suffit pas à protéger).
function icsSanitizeVevent(array $lines) {
    $out = [];
    $urlRe = '#https?://[^\s"\'<>\)\]]+#i';
    foreach ($lines as $line) {
        if (preg_match('/^(URL|CONFERENCE)(;[^:]*)?:/i', $line)) continue;      // lien direct
        if (preg_match('/^X-ALT-DESC(;[^:]*)?:/i', $line))       continue;      // description HTML (liens intégrés)
        if (preg_match('/^DESCRIPTION(;[^:]*)?:/i', $line))      continue;      // corps vidé entièrement
        if (preg_match('/^LOCATION(;[^:]*)?:(.*)$/i', $line, $m)) {
            $val = preg_replace($urlRe, '', $m[2]);
            $val = preg_replace('/[ \t]{2,}/', ' ', $val);
            $val = rtrim($val, " \\,;");
            if (trim(str_replace('\\,', ',', $val)) === '') continue; // lieu devenu vide
            $out[] = 'LOCATION' . $m[1] . ':' . $val;
            continue;
        }
        $out[] = $line;
    }
    return $out;
}

// Assemble un VCALENDAR complet à partir de blocs VEVENT/VTIMEZONE bruts.
function icsBuildCalendar(array $eventBlocks, array $tzBlocks, $calName) {
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0',
        'PRODID:-//Brightspace Agenda//Export ICS//FR',
        'CALSCALE:GREGORIAN',
        'X-WR-CALNAME:' . $calName,
    ];
    $seen = [];
    foreach ($tzBlocks as $tz) {
        $key = implode('|', $tz);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        foreach ($tz as $l) $lines[] = $l;
    }
    foreach ($eventBlocks as $ev) foreach ($ev as $l) $lines[] = $l;
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('icsFoldLine', $lines)) . "\r\n";
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
            $data['export_public_devoirs']  = array_key_exists('export_public_devoirs',  $config) ? !empty($config['export_public_devoirs'])  : true;
            $data['export_public_sessions'] = array_key_exists('export_public_sessions', $config) ? !empty($config['export_public_sessions']) : true;
            $data['export_public_ateliers'] = array_key_exists('export_public_ateliers', $config) ? !empty($config['export_public_ateliers']) : true;
            $data['export_token_full']    = $config['export_token_full']  ?? '';
            $data['export_token_safe']    = $config['export_token_safe']  ?? '';
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
            'dashboard_name'  => $config['dashboard_name']  ?? '',
            'export_public_devoirs'  => array_key_exists('export_public_devoirs',  $config) ? !empty($config['export_public_devoirs'])  : true,
            'export_public_sessions' => array_key_exists('export_public_sessions', $config) ? !empty($config['export_public_sessions']) : true,
            'export_public_ateliers' => array_key_exists('export_public_ateliers', $config) ? !empty($config['export_public_ateliers']) : true,
            'export_token_full'    => $config['export_token_full'] ?? '',
            'export_token_safe'    => $config['export_token_safe'] ?? '']);

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

    // Préférences du lien public : quels types d'événements y sont visibles
    // (le lien privé, lui, inclut toujours tout — pas de réglage ici)
    case 'save_export_prefs':
        requireAuth();
        $config['export_public_devoirs']  = !empty($input['public_devoirs']);
        $config['export_public_sessions'] = !empty($input['public_sessions']);
        $config['export_public_ateliers'] = !empty($input['public_ateliers']);
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    // Génère (ou régénère) le token d'un flux ICS exporté : 'full' ou 'safe'
    case 'regen_export_token':
        requireAuth();
        $variant = $input['variant'] ?? '';
        if (!in_array($variant, ['full', 'safe'], true)) respond(['error'=>'Variante invalide'], 400);
        $config['export_token_' . $variant] = bin2hex(random_bytes(16));
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true, 'variant'=>$variant, 'token'=>$config['export_token_' . $variant]]);

    // Désactive un flux ICS exporté : 'full' ou 'safe'
    case 'disable_export_token':
        requireAuth();
        $variant = $input['variant'] ?? '';
        if (!in_array($variant, ['full', 'safe'], true)) respond(['error'=>'Variante invalide'], 400);
        $config['export_token_' . $variant] = '';
        writeJson(CONFIG_FILE, $config);
        respond(['ok'=>true]);

    // Flux ICS abonnable — public, authentifié uniquement par le token dans
    // l'URL (comme un lien de partage). Le token détermine lui-même la
    // variante ('full' = tout, y compris Teams ; 'safe' = sans aucun lien).
    // Régénéré à chaque requête à partir des calendriers sources : les
    // ajouts/suppressions/modifications suivent automatiquement au fil des
    // rafraîchissements périodiques de l'app calendrier abonnée.
    case 'export_ics':
        $token = $_GET['token'] ?? ($input['token'] ?? '');
        if (empty($token)) respond(['error'=>'Token manquant'], 400);
        $variant = null;
        if (!empty($config['export_token_full']) && hash_equals($config['export_token_full'], $token)) {
            $variant = 'full';
        } elseif (!empty($config['export_token_safe']) && hash_equals($config['export_token_safe'], $token)) {
            $variant = 'safe';
        }
        if (!$variant) respond(['error'=>'Lien invalide ou désactivé'], 401);

        // Le lien privé inclut toujours tout. Le lien public respecte les préférences
        // (par défaut tout inclus si jamais configuré, comportement rétro-compatible).
        $includeDevoirs  = $variant === 'full' ? true : ($config['export_public_devoirs']  ?? true);
        $includeSessions = $variant === 'full' ? true : ($config['export_public_sessions'] ?? true);
        $includeAteliers = $variant === 'full' ? true : ($config['export_public_ateliers'] ?? true);

        $sources = [];
        if (!empty($config['ics_url'])) $sources[] = ['url'=>$config['ics_url'], 'prefix'=>'', 'kind'=>'personal'];
        if ($includeAteliers && !empty($config['private_ics_url'])) {
            $sources[] = ['url'=>$config['private_ics_url'], 'prefix'=>'grp-', 'kind'=>'group'];
        }
        if (empty($sources)) respond(['error'=>'Aucun calendrier source configuré'], 404);

        // Événements masqués/ignorés par l'utilisateur (onglet Ateliers) — à exclure de l'export
        $state      = readJson(STATE_FILE, []);
        $groupTags  = $state['group_tags'] ?? [];
        $ignoredMap = [];
        foreach ($groupTags as $uid => $tag) {
            if (!empty($tag['ignored'])) $ignoredMap[$uid] = true;
        }

        $allEvents = []; $allTz = [];
        foreach ($sources as $src) {
            $raw = curlFetch($src['url']);
            if (!$raw) continue;
            $blocks = icsExtractBlocks($raw);
            foreach ($blocks['events'] as $ev) {
                $uid = icsGetProp($ev, 'UID');
                if ($uid !== '' && isset($ignoredMap[$uid])) continue; // événement masqué → exclu

                if ($src['kind'] === 'personal') {
                    if (!$includeDevoirs  && icsIsDevoir($ev))  continue;
                    if (!$includeSessions && icsIsSession($ev)) continue;
                }

                if ($src['prefix'] !== '') $ev = icsPrefixUid($ev, $src['prefix']);
                if ($variant === 'safe') $ev = icsSanitizeVevent($ev);
                $allEvents[] = $ev;
            }
            foreach ($blocks['timezones'] as $tz) $allTz[] = $tz;
        }

        $baseName = !empty($config['dashboard_name']) ? $config['dashboard_name'] : 'Brightspace Agenda';
        $calName  = $baseName . ($variant === 'full' ? ' - Complet' : ' - Partage');

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="brightspace-agenda-' . $variant . '.ics"');
        header('Cache-Control: public, max-age=900');
        echo icsBuildCalendar($allEvents, $allTz, $calName);
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

        // group_tags + rendus depuis state.json, pour typage/filtre ignored et statut rendu
        $state      = readJson(STATE_FILE, []);
        $groupTags  = $state['group_tags'] ?? [];
        $rendusMap  = $state['rendus']     ?? [];
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
                        'uid'         => $uid,
                        'summary'     => $summary,
                        'type'        => $type,
                        'start'       => $start,
                        'end'         => $end,
                        'start_iso'   => date('c', $start),
                        'end_iso'     => date('c', $end),
                        'days_until'  => $daysUntil,
                        'subject'     => $tag['subjectName'] ?? ($tag['subject'] ?? null),
                        'course_code' => $tag['subject'] ?? null,
                        'rendu'       => !empty($rendusMap[$uid]),
                    ];
                    continue;
                }
                if (!$inEvent) continue;

                // Parse key[:params]:value, unfold multi-line
                if (preg_match('/^([\w-]+)(?:;([^:]+))?:(.*)$/', $line, $m)) {
                    $key    = strtoupper(explode(';', $m[1])[0]);
                    $params = $m[2] ?? '';
                    $val    = $m[3];
                    if (in_array($key, ['DTSTART', 'DTEND'])) {
                        $raw = trim($val);
                        if (str_ends_with($raw, 'Z')) {
                            // Format UTC : 20260708T150000Z -> DateTime UTC
                            $dt  = DateTime::createFromFormat('Ymd\THis\Z', $raw, new DateTimeZone('UTC'));
                            $val = $dt ? $dt->getTimestamp() : 0;
                        } elseif (str_contains($params, 'TZID=') || preg_match('/[+-]\d{4}$/', $raw)) {
                            // Format avec timezone explicite ou offset
                            $dt  = new DateTime($raw);
                            $val = $dt ? $dt->getTimestamp() : 0;
                        } elseif (strlen($raw) === 8) {
                            // Date seule YYYYMMDD (journee entiere)
                            $dt  = DateTime::createFromFormat('Ymd', $raw, new DateTimeZone(date_default_timezone_get()));
                            $val = $dt ? $dt->setTime(0,0,0)->getTimestamp() : 0;
                        } else {
                            // Format local YYYYMMDDTHHMMSS sans Z
                            $dt  = DateTime::createFromFormat('Ymd\THis', $raw, new DateTimeZone(date_default_timezone_get()));
                            $val = $dt ? $dt->getTimestamp() : 0;
                        }
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
