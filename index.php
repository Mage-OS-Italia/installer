<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/requirements.php';
require __DIR__ . '/lib/jobs.php';

session_name('magento_installer');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

/** Client locale o in rete privata: accesso libero. Altrimenti serve il token generato sul server. */
function is_trusted_client(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function token_file(): string
{
    return app_tmp() . '/access-token';
}

function access_token(): string
{
    $f = token_file();
    if (!is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(12)));
        chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
}

$authorized = !empty($_SESSION['authorized']) || is_trusted_client();
$locked = is_file(__DIR__ . '/.installed');
$action = (string)($_GET['a'] ?? '');

// ---- Sblocco con token (accesso da rete pubblica)
if ($action === 'auth' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $given = (string)(request_json()['token'] ?? '');
    if (hash_equals(access_token(), trim($given))) {
        $_SESSION['authorized'] = true;
        json_out(['ok' => true]);
    }
    usleep(500000);
    json_out(['ok' => false, 'error' => 'Token errato.'], 403);
}

// ---- API
if ($action !== '') {
    if (!$authorized) {
        json_out(['error' => 'Accesso negato.'], 403);
    }
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($post && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        json_out(['error' => 'Sessione scaduta: ricarica la pagina.'], 403);
    }
    if ($locked && $action !== 'remove' && $action !== 'status') {
        json_out(['error' => 'Installer bloccato: Magento risulta già installato.'], 423);
    }
    $in = $post ? request_json() : [];

    switch ($action) {
        case 'bootstrap':
            $platforms = [];
            foreach (PLATFORMS as $id => $pl) {
                $v = platform_versions($id);
                $platforms[$id] = ['id' => $id, 'name' => $pl['name'], 'versions' => $v['versions'], 'source' => $v['source']];
            }
            json_out([
                'platforms' => $platforms,
                'distros' => array_values(distros()),
                'mageos' => mageos_modules(),
                'frontends' => array_values(frontends()),
                'locale' => locale_options(),
                'server' => server_info(),
                'app' => ['name' => APP_NAME, 'version' => APP_VERSION],
            ]);
        case 'requirements':
            if (!$post) {
                json_out(['error' => 'Metodo non valido.'], 405);
            }
            $repos = [];
            $d = distros()[$in['distro'] ?? ''] ?? null;
            if ($d) {
                $needed = array_flip(array_map('strval', (array)($in['packages'] ?? [])));
                foreach ($d['packages'] ?? [] as $p) {
                    if ((!empty($p['required']) || isset($needed[$p['name']])) && !empty($p['repository'])) {
                        foreach ($d['repositories'] as $r) {
                            if ($r['id'] === $p['repository']) {
                                $repos[$r['url']] = $r['url'];
                            }
                        }
                    }
                }
            }
            json_out(run_requirements(['platform' => $in['platform'] ?? '', 'version' => $in['version'] ?? '', 'target' => $in['target'] ?? '', 'repos' => array_values($repos)]));
        case 'test_db':
            json_out(test_db($in));
        case 'test_search':
            json_out(test_search($in));
        case 'start':
            [$config, $errors] = validate_install($in);
            if ($errors) {
                json_out(['error' => 'Controlla i dati inseriti.', 'errors' => $errors], 422);
            }
            $r = start_install($config);
            json_out($r, isset($r['error']) ? 500 : 200);
        case 'status':
            json_out(job_status((string)($_GET['job'] ?? ''), max(0, (int)($_GET['since'] ?? 0))));
        case 'cancel':
            $id = (string)($in['job'] ?? '');
            if (valid_job_id($id) && is_dir(job_dir($id))) {
                touch(job_dir($id) . '/cancel');
            }
            json_out(['ok' => true]);
        case 'remove':
            if (!$post || !is_file(__DIR__ . '/lib/bootstrap.php')) {
                json_out(['error' => 'Operazione non consentita.'], 400);
            }
            $root = realpath(APP_ROOT);
            if (!$root || $root === '/' || $root === home_dir()) {
                json_out(['error' => 'Percorso non sicuro.'], 400);
            }
            rrmdir(app_tmp());
            rrmdir($root);
            json_out(['ok' => true]);
        default:
            json_out(['error' => 'Azione sconosciuta.'], 404);
    }
}

// ---- Pagina
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$asset = fn(string $f): string => 'assets/' . $f . '?v=' . filemtime(__DIR__ . '/assets/' . $f);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Magento Installer</title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($asset('app.css')) ?>">
</head>
<body>
<div class="bg-glow" aria-hidden="true"></div>
<?php if ($locked): ?>
<main class="gate">
  <div class="card gate-card">
    <img src="assets/logo.svg" alt="Magento Installer">
    <h1>Installer bloccato</h1>
    <p>Magento è già stato installato con questo installer. Per sicurezza non può essere rieseguito.</p>
    <p class="muted">Per ripartire elimina il file <code>.installed</code> nella cartella dell'installer, oppure rimuovi l'installer.</p>
    <button class="btn danger" id="remove-btn">Rimuovi installer</button>
  </div>
</main>
<script>
document.getElementById('remove-btn').onclick = async () => {
  if (!confirm("Eliminare definitivamente l'installer dal server?")) return;
  const r = await fetch('?a=remove', {method:'POST', headers:{'X-CSRF': <?= json_encode($_SESSION['csrf']) ?>, 'Content-Type':'application/json'}, body:'{}'});
  document.body.innerHTML = '<main class="gate"><div class="card gate-card"><h1>Installer rimosso</h1><p>Puoi chiudere questa pagina.</p></div></main>';
};
</script>
<?php elseif (!$authorized): ?>
<main class="gate">
  <div class="card gate-card">
    <img src="assets/logo.svg" alt="Magento Installer">
    <h1>Accesso protetto</h1>
    <p>Questo installer è raggiunto da una rete pubblica. Per continuare inserisci il token di accesso.</p>
    <p class="muted">Lo trovi sul server nel file:<br><code><?= h(token_file()) ?></code></p>
    <form id="auth-form">
      <input type="password" id="token" placeholder="Token di accesso" autocomplete="off" autofocus>
      <button class="btn primary" type="submit">Entra</button>
      <p class="err" id="auth-err"></p>
    </form>
  </div>
</main>
<script>
document.getElementById('auth-form').onsubmit = async (e) => {
  e.preventDefault();
  const r = await fetch('?a=auth', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({token: document.getElementById('token').value})});
  if (r.ok) location.reload(); else document.getElementById('auth-err').textContent = 'Token errato.';
};
</script>
<?php else: ?>
<div id="app" class="app" data-csrf="<?= h($_SESSION['csrf']) ?>">
  <div class="boot"><div class="spinner"></div><p>Caricamento…</p></div>
</div>
<script src="<?= h($asset('app.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
