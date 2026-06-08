<?php
/**
 * api.php — Backend EMMGO Dashboard
 * Actions : get_config, login, logout, get_state, set_state, regen_token, disable_share
 */
session_name('emmgo_session');
session_start();

define('DATA_DIR',    __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('STATE_FILE',  DATA_DIR . '/state.json');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

// ── Helpers ──────────────────────────────────────────────────────
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
function isLoggedIn()          { return !empty($_SESSION['emmgo_auth']); }
function requireAuth()         { if (!isLoggedIn()) respond(['error' => 'Non authentifié'], 401); }
function getConfig()           { return readJson(CONFIG_FILE, ['password_hash' => '', 'share_token' => '']); }
function isValidShare($token)  {
    if (empty($token)) return false;
    $cfg = getConfig();
    return !empty($cfg['share_token']) && hash_equals($cfg['share_token'], $token);
}

// ── Ensure data dir exists and is protected ──────────────────────
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
    @file_put_contents(DATA_DIR . '/index.html', '');
}
if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
    respond(['error' => 'Répertoire data/ non accessible en écriture — vérifie les permissions', 'code' => 'NO_WRITE'], 503);
}

// ── Check configured ─────────────────────────────────────────────
$config = getConfig();
$action = $_GET['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($config['password_hash']) && $action !== 'ping') {
    respond(['error' => 'Non configuré — visite setup.php pour initialiser', 'code' => 'NOT_CONFIGURED'], 503);
}

// ── Router ───────────────────────────────────────────────────────
switch ($action) {

    // Ping : vérifie que l'API est dispo et si configurée
    case 'ping':
        respond([
            'ok'          => true,
            'configured'  => !empty($config['password_hash']),
        ]);

    // Config publique : logged_in, share disponible
    case 'get_config':
        respond([
            'ok'           => true,
            'logged_in'    => isLoggedIn(),
            'share_enabled'=> !empty($config['share_token']),
            // Token visible uniquement si connecté
            'share_token'  => isLoggedIn() ? ($config['share_token'] ?? '') : null,
        ]);

    // Login
    case 'login':
        $pwd = $input['password'] ?? '';
        if (empty($pwd)) respond(['error' => 'Mot de passe manquant'], 400);
        if (!password_verify($pwd, $config['password_hash'])) {
            // Délai anti-brute-force
            sleep(1);
            respond(['error' => 'Mot de passe incorrect'], 401);
        }
        $_SESSION['emmgo_auth'] = true;
        session_regenerate_id(true);
        respond(['ok' => true, 'logged_in' => true]);

    // Logout
    case 'logout':
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true, 'logged_in' => false]);

    // Lire l'état des rendus (connecté OU token de partage valide)
    case 'get_state':
        $shareToken = $_GET['share'] ?? ($input['share'] ?? '');
        $readOnly   = !isLoggedIn();
        if ($readOnly && !isValidShare($shareToken)) {
            respond(['error' => 'Non autorisé'], 401);
        }
        $state = readJson(STATE_FILE, ['rendus' => new stdClass()]);
        respond(['ok' => true, 'state' => $state, 'read_only' => $readOnly]);

    // Sauvegarder l'état des rendus (connecté uniquement)
    case 'set_state':
        requireAuth();
        if (!isset($input['state'])) respond(['error' => 'Champ state manquant'], 400);
        if (writeJson(STATE_FILE, $input['state'])) {
            respond(['ok' => true]);
        } else {
            respond(['error' => 'Impossible d\'écrire state.json'], 500);
        }

    // Générer un nouveau token de partage
    case 'regen_token':
        requireAuth();
        $config['share_token'] = bin2hex(random_bytes(16));
        writeJson(CONFIG_FILE, $config);
        respond(['ok' => true, 'share_token' => $config['share_token']]);

    // Désactiver le partage
    case 'disable_share':
        requireAuth();
        $config['share_token'] = '';
        writeJson(CONFIG_FILE, $config);
        respond(['ok' => true]);

    // Changer le mot de passe
    case 'change_password':
        requireAuth();
        $old = $input['old_password'] ?? '';
        $new = $input['new_password'] ?? '';
        if (empty($new) || strlen($new) < 6) respond(['error' => 'Nouveau mot de passe trop court (min. 6 caractères)'], 400);
        if (!password_verify($old, $config['password_hash'])) respond(['error' => 'Ancien mot de passe incorrect'], 401);
        $config['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        writeJson(CONFIG_FILE, $config);
        respond(['ok' => true]);

    default:
        respond(['error' => "Action inconnue : $action"], 400);
}
