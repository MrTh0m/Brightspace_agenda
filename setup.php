<?php
/**
 * setup.php — Configuration initiale EMMGO Dashboard
 * À supprimer ou protéger par .htaccess après utilisation.
 */
session_name('emmgo_session');
session_start();

define('DATA_DIR',    __DIR__ . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');

function readJson($f, $d=[]) { if(!file_exists($f))return $d; $r=@json_decode(file_get_contents($f),true); return is_array($r)?$r:$d; }
function writeJson($f,$d)    { return file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) !== false; }

// Créer data/ si nécessaire
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
    @file_put_contents(DATA_DIR.'/.htaccess', "Require all denied\n");
    @file_put_contents(DATA_DIR.'/index.html', '');
}

$config      = readJson(CONFIG_FILE, ['password_hash'=>'','share_token'=>'']);
$configured  = !empty($config['password_hash']);
$error       = '';
$success     = '';
$currentUrl  = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']);

// ── POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Première configuration ──────────────────────────────────
    if ($action === 'setup' && !$configured) {
        $pwd1 = $_POST['password']  ?? '';
        $pwd2 = $_POST['password2'] ?? '';
        if (strlen($pwd1) < 6)      $error = 'Le mot de passe doit faire au moins 6 caractères.';
        elseif ($pwd1 !== $pwd2)    $error = 'Les deux mots de passe ne correspondent pas.';
        else {
            if (!is_writable(DATA_DIR)) {
                $error = 'Impossible d\'écrire dans data/ — vérifie les permissions du dossier.';
            } else {
                $config['password_hash'] = password_hash($pwd1, PASSWORD_DEFAULT);
                $config['share_token']   = bin2hex(random_bytes(16));
                if (writeJson(CONFIG_FILE, $config)) {
                    $configured = true;
                    $success = 'Configuration enregistrée !';
                    $config  = readJson(CONFIG_FILE);
                } else {
                    $error = 'Impossible d\'écrire config.json.';
                }
            }
        }
    }

    // ── Réinitialiser (reconfigurer) — nécessite l'ancien mot de passe ──
    if ($action === 'reconfigure' && $configured) {
        $oldPwd = $_POST['old_password'] ?? '';
        $pwd1   = $_POST['password']     ?? '';
        $pwd2   = $_POST['password2']    ?? '';
        if (!password_verify($oldPwd, $config['password_hash'])) $error = 'Ancien mot de passe incorrect.';
        elseif (strlen($pwd1) < 6)  $error = 'Nouveau mot de passe trop court (min. 6 caractères).';
        elseif ($pwd1 !== $pwd2)    $error = 'Les deux mots de passe ne correspondent pas.';
        else {
            $config['password_hash'] = password_hash($pwd1, PASSWORD_DEFAULT);
            writeJson(CONFIG_FILE, $config);
            $success = 'Mot de passe mis à jour.';
        }
    }

    // ── Régénérer le token ──────────────────────────────────────
    if ($action === 'regen_token' && $configured) {
        $pwd = $_POST['password'] ?? '';
        if (!password_verify($pwd, $config['password_hash'])) $error = 'Mot de passe incorrect.';
        else {
            $config['share_token'] = bin2hex(random_bytes(16));
            writeJson(CONFIG_FILE, $config);
            $success = 'Token de partage régénéré.';
        }
    }
}
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EMMGO Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#faf9f6;color:#1c1b18;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border:1px solid #e2e0d8;border-radius:14px;padding:32px;max-width:480px;width:100%;box-shadow:0 2px 16px rgba(0,0,0,.06)}
h1{font-size:20px;font-weight:700;margin-bottom:6px}
.sub{font-size:13px;color:#706e66;margin-bottom:24px}
h2{font-size:14px;font-weight:600;margin:20px 0 10px;color:#444}
label{display:block;font-size:13px;font-weight:500;margin-bottom:5px;color:#444}
input[type=password],input[type=text]{width:100%;padding:9px 12px;border:1px solid #ccc9be;border-radius:8px;font-size:14px;margin-bottom:12px}
input:focus{outline:none;border-color:#1a7fc1}
button{width:100%;padding:10px;border-radius:8px;border:none;background:#1c1b18;color:#fff;font-size:14px;font-weight:600;cursor:pointer;margin-top:4px}
button:hover{opacity:.85}
.btn-sec{background:transparent;border:1px solid #ccc9be;color:#1c1b18;margin-top:8px}
.alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.alert.ok {background:#e0f4ec;color:#0d6e4f;border:1px solid #a5d6be}
.alert.err{background:#fce8e8;color:#c0302e;border:1px solid #e8a5a4}
.info-box{background:#f2f1ed;border-radius:8px;padding:12px 14px;font-size:12px;color:#706e66;margin:12px 0;line-height:1.7}
.info-box strong{color:#1c1b18}
.share-url{word-break:break-all;background:#fff;border:1px solid #e2e0d8;border-radius:6px;padding:8px 10px;font-family:monospace;font-size:11px;margin-top:6px;color:#4b44b0}
.sep{border:none;border-top:1px solid #e2e0d8;margin:24px 0}
details summary{cursor:pointer;font-size:13px;font-weight:600;color:#706e66;user-select:none;margin-bottom:8px}
</style></head><body>
<div class="card">
  <h1>📚 EMMGO Setup</h1>
  <p class="sub">Configuration du mode connecté</p>

  <?php if($error): ?><div class="alert err"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <?php if($success): ?><div class="alert ok">✓ <?=htmlspecialchars($success)?></div><?php endif; ?>

  <?php if(!$configured): ?>
  <!-- ── Première configuration ── -->
  <p style="font-size:13px;color:#706e66;margin-bottom:16px">
    Définis un mot de passe pour activer le mode connecté (1 compte, persistance des données côté serveur).
  </p>
  <form method="post">
    <input type="hidden" name="action" value="setup">
    <label>Mot de passe <span style="color:#999">(min. 6 caractères)</span></label>
    <input type="password" name="password" required minlength="6" placeholder="Ton mot de passe">
    <label>Confirmer le mot de passe</label>
    <input type="password" name="password2" required minlength="6" placeholder="Confirmer">
    <?php if(!is_writable(DATA_DIR)): ?>
      <div class="alert err">⚠ Le dossier data/ n'est pas inscriptible.<br>
      Lance : <code>chmod 755 data/</code> ou crée-le manuellement.</div>
    <?php endif; ?>
    <button type="submit">Configurer</button>
  </form>

  <?php else: ?>
  <!-- ── Déjà configuré ── -->
  <div class="info-box">
    <strong>Statut :</strong> ✓ Configuré<br>
    <strong>URL du dashboard :</strong> <a href="<?=$currentUrl?>/index.html"><?=$currentUrl?>/index.html</a><br>
    <?php if(!empty($config['share_token'])): ?>
    <strong>URL de partage :</strong>
    <div class="share-url"><?=$currentUrl?>/index.html?share=<?=htmlspecialchars($config['share_token'])?></div>
    <?php else: ?>
    <strong>Partage :</strong> Désactivé
    <?php endif; ?>
  </div>

  <details>
    <summary>Changer le mot de passe</summary>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="action" value="reconfigure">
      <label>Mot de passe actuel</label>
      <input type="password" name="old_password" required placeholder="Mot de passe actuel">
      <label>Nouveau mot de passe</label>
      <input type="password" name="password" required minlength="6" placeholder="Nouveau mot de passe">
      <label>Confirmer</label>
      <input type="password" name="password2" required minlength="6" placeholder="Confirmer">
      <button type="submit">Mettre à jour</button>
    </form>
  </details>

  <details style="margin-top:8px">
    <summary>Régénérer l'URL de partage</summary>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="action" value="regen_token">
      <label>Mot de passe (confirmation)</label>
      <input type="password" name="password" required placeholder="Ton mot de passe">
      <button type="submit">Générer un nouveau token</button>
    </form>
    <p style="font-size:11px;color:#999;margin-top:6px">⚠ L'ancien lien de partage sera immédiatement invalide.</p>
  </details>

  <hr class="sep">
  <div class="info-box">
    <strong>Pour supprimer ce fichier après configuration :</strong><br>
    <code>rm setup.php</code><br>
    Ou ajoute dans <code>.htaccess</code> :<br>
    <code>&lt;Files "setup.php"&gt; Require all denied &lt;/Files&gt;</code>
  </div>
  <?php endif; ?>

</div>
</body></html>
