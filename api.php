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
define('TOKENS_FILE',  DATA_DIR . '/tokens.json');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

// ── CORS : nécessaire pour l'app packagée (APK), qui appelle un
// serveur Brightspace Agenda distant en cross-origin. Sans danger pour
// la version web : same-origin n'envoie jamais d'en-tête Origin entrant
// en conflit, et l'auth sensible (login) reste protégée par le token
// opaque généré côté serveur, jamais par la seule origine déclarée.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

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
function isLoggedIn()         { return !empty($_SESSION['emmgo_auth']) || isValidAuthToken(); }
function requireAuth()        { if (!isLoggedIn()) respond(['error' => 'Non authentifié'], 401); }
function getConfig()          { return readJson(CONFIG_FILE, ['password_hash'=>'','share_token'=>'','ics_url'=>'']); }
function isValidShare($token) {
    if (empty($token)) return false;
    $cfg = getConfig();
    return !empty($cfg['share_token']) && hash_equals($cfg['share_token'], $token);
}

// ── Auth par token (APK Capacitor) ───────────────────────────────
// Utilisé en plus du cookie de session : nécessaire car les WebViews
// mobiles (Capacitor/Cordova) appliquent SameSite de façon stricte sur
// les requêtes fetch cross-origin, ce qui empêche le cookie de session
// classique de fonctionner depuis une app packagée.
function getBearerToken() {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($hdr) && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) return trim($m[1]);
    return null;
}
function hashToken($token) { return hash('sha256', $token); }
function readTokens() { return readJson(TOKENS_FILE, []); }
function writeTokens($tokens) { return writeJson(TOKENS_FILE, $tokens); }
function pruneExpiredTokens($tokens) {
    $now = time();
    foreach ($tokens as $h => $exp) if ($exp < $now) unset($tokens[$h]);
    return $tokens;
}
function isValidAuthToken() {
    $token = getBearerToken();
    if (!$token) return false;
    $tokens = readTokens();
    $h = hashToken($token);
    return isset($tokens[$h]) && $tokens[$h] > time();
}
function issueAuthToken($lifetime) {
    $token = bin2hex(random_bytes(32));
    $tokens = pruneExpiredTokens(readTokens());
    $tokens[hashToken($token)] = time() + $lifetime;
    writeTokens($tokens);
    return $token;
}
function revokeAuthToken() {
    $token = getBearerToken();
    if (!$token) return;
    $tokens = readTokens();
    unset($tokens[hashToken($token)]);
    writeTokens($tokens);
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
        // Token d'auth (en plus du cookie) : nécessaire pour l'app packagée
        // (APK Capacitor), où le cookie de session ne peut pas être envoyé
        // de façon fiable sur des requêtes cross-origin.
        $authToken = issueAuthToken($sessionLifetime);
        respond(['ok'=>true, 'logged_in'=>true, 'auth_token'=>$authToken,
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
        revokeAuthToken();
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

    default:
        respond(['error'=>"Action inconnue : $action"], 400);
}
