<?php
declare(strict_types=1);

const APP_NAME = 'Magento Installer';
const APP_VERSION = '0.2.0';
/** Mirror pubblico dei pacchetti Magento Open Source (stessi pacchetti di repo.magento.com, nessuna chiave Adobe). */
const MAGENTO_REPO = 'https://mirror.mage-os.org/';
const MAGEOS_REPO = 'https://repo.mage-os.org/';

define('APP_ROOT', dirname(__DIR__));

/**
 * Cartella temporanea privata dell'installer (job, log, cache, token): fuori dalla document root.
 * Il nome è prevedibile, quindi ogni volta si verifica che sia nostra (proprietario, non un link) e chiusa agli altri utenti.
 */
function app_tmp(string $sub = ''): string
{
    static $checked = [];
    $dir = rtrim(sys_get_temp_dir(), '/') . '/magento-installer-' . substr(md5(APP_ROOT), 0, 10);
    if (!isset($checked[$dir])) {
        if (is_link($dir)) {
            throw new RuntimeException("$dir è un link simbolico: cartella temporanea rifiutata. Eliminala e ricarica la pagina.");
        }
        if (!is_dir($dir)) {
            $old = umask(0077);
            $made = @mkdir($dir, 0700, true);
            umask($old);
            if (!$made && !is_dir($dir)) {
                throw new RuntimeException("Impossibile creare la cartella temporanea $dir.");
            }
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($uid !== null && fileowner($dir) !== $uid) {
            throw new RuntimeException("$dir appartiene a un altro utente: cartella temporanea rifiutata. Eliminala (o fai eliminare a chi l'ha creata) e ricarica la pagina.");
        }
        if ((fileperms($dir) & 0077) !== 0) {
            @chmod($dir, 0700);
        }
        $checked[$dir] = true;
    }
    if ($sub !== '') {
        $dir .= '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    return $dir;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** `proc_open` serve all'installer per lanciare composer e bin/magento: se è disabilitata lo diciamo chiaramente. */
function exec_available(): bool
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    foreach (['proc_open'] as $fn) {
        if (!function_exists($fn) || in_array($fn, $disabled, true)) {
            return false;
        }
    }
    return true;
}

/** Esegue un comando breve e ritorna lo stdout ('' se fallisce). */
function sh(string $cmd, int $timeout = 15): string
{
    if (!exec_available()) {
        return '';
    }
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, shell_env());
    if (!is_resource($p)) {
        return '';
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        $out .= (string)stream_get_contents($pipes[1]);
        $st = proc_get_status($p);
        if (!$st['running']) {
            $out .= (string)stream_get_contents($pipes[1]);
            break;
        }
        usleep(20000);
    }
    $st = proc_get_status($p);
    if ($st['running']) {
        proc_terminate($p);
    }
    foreach ($pipes as $pp) {
        @fclose($pp);
    }
    proc_close($p);
    return trim($out);
}

function home_dir(): string
{
    $home = getenv('HOME');
    if ($home) {
        return $home;
    }
    if (function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir'])) {
            return $pw['dir'];
        }
    }
    return sys_get_temp_dir();
}

/** Ambiente per i processi figli: PHP-FPM ha spesso un PATH minimo (niente Homebrew). */
function shell_env(array $extra = []): array
{
    $path = implode(':', array_unique(array_filter(array_merge(
        [PHP_BINDIR, '/opt/homebrew/bin', '/opt/homebrew/sbin', '/usr/local/bin', '/usr/local/sbin', '/usr/bin', '/bin', '/usr/sbin', '/sbin'],
        explode(':', (string)getenv('PATH'))
    ))));
    // L'ambiente è ricostruito da zero: si tengono solo proxy e certificati, senza i quali composer non esce in rete.
    $keep = [];
    // HTTP_PROXY maiuscolo è escluso apposta: sotto CGI/FPM un header `Proxy:` del client diventa quella variabile (httpoxy).
    foreach (['HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE', 'COMPOSER_AUTH'] as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') {
            $keep[$k] = $v;
        }
    }
    return array_merge($keep, [
        'PATH' => $path,
        'HOME' => home_dir(),
        'TMPDIR' => sys_get_temp_dir(),
        'LANG' => 'en_US.UTF-8',
    ], $extra);
}

/** Il binario PHP CLI (in FPM PHP_BINARY è php-fpm e non serve per eseguire script). */
function find_php_cli(): ?string
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $cands = [];
    if (PHP_SAPI === 'cli') {
        $cands[] = PHP_BINARY;
    }
    $cands[] = PHP_BINDIR . '/php';
    $which = sh('command -v php');
    if ($which !== '') {
        $cands[] = $which;
    }
    array_push($cands, '/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php');
    // Debian/Ubuntu e Remi installano anche php8.x: se `php` non va bene si provano le versioni più recenti per prime.
    foreach (['8.5', '8.4', '8.3', '8.2', '8.1'] as $v) {
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'] as $dir) {
            $cands[] = "$dir/php$v";
        }
    }
    foreach (array_unique($cands) as $c) {
        // Un warning di avvio su stdout (ini di Homebrew, estensione pecl vecchia) non deve far scartare il binario.
        if ($c && is_executable($c) && sh(escapeshellarg($c) . ' -d display_errors=stderr -d display_startup_errors=0 -r ' . escapeshellarg('echo PHP_SAPI;')) === 'cli') {
            return $cached = $c;
        }
    }
    return $cached = null;
}

/** Percorso di composer (phar o script) da eseguire con il php CLI. */
function find_composer(): ?string
{
    foreach (['/opt/homebrew/bin/composer', '/usr/local/bin/composer', '/usr/bin/composer', app_tmp() . '/composer.phar'] as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    $which = sh('command -v composer');
    return ($which !== '' && is_file($which)) ? $which : null;
}

function http_get(string $url, int $timeout = 8, array $headers = [], bool $headOnly = false, bool $verifyTls = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => APP_NAME . '/' . APP_VERSION,
        CURLOPT_NOBODY => $headOnly,
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    return ['ok' => $body !== false && $code > 0, 'code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (float)$v;
    return (int)match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

function human_bytes(float $b): string
{
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
        if ($b < 1024 || $u === 'TB') {
            return round($b, $u === 'B' ? 0 : 1) . ' ' . $u;
        }
        $b /= 1024;
    }
    return '';
}

/** Verifica una versione PHP contro un vincolo composer (~, ^, >=, <, ||). */
function php_satisfies(string $ver, string $constraint): bool
{
    $v = preg_replace('/[^0-9.].*$/', '', $ver);
    foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) as $or) {
        if ($or === '') {
            continue;
        }
        $ok = true;
        foreach (preg_split('/[\s,]+/', $or) as $c) {
            if (!constraint_part($v, $c)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return true;
        }
    }
    return false;
}

function constraint_part(string $v, string $c): bool
{
    if (!preg_match('/^(~|\^|>=|<=|>|<|=)?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\*)?$/', $c, $m)) {
        return true;
    }
    $op = $m[1] ?: '=';
    $maj = (int)$m[2];
    $hasMin = isset($m[3]) && $m[3] !== '';
    $hasPat = isset($m[4]) && $m[4] !== '';
    $min = $hasMin ? (int)$m[3] : 0;
    $pat = $hasPat ? (int)$m[4] : 0;
    $base = "$maj.$min.$pat";
    switch ($op) {
        case '~':
            $upper = $hasPat ? ($maj . '.' . ($min + 1) . '.0') : (($maj + 1) . '.0.0');
            return version_compare($v, $base, '>=') && version_compare($v, $upper, '<');
        case '^':
            return version_compare($v, $base, '>=') && version_compare($v, ($maj + 1) . '.0.0', '<');
        case '=':
            if (!$hasMin) {
                return version_compare($v, "$maj.0.0", '>=') && version_compare($v, ($maj + 1) . '.0.0', '<');
            }
            if (!$hasPat) {
                return version_compare($v, $base, '>=') && version_compare($v, "$maj." . ($min + 1) . '.0', '<');
            }
            return version_compare($v, $base, '==');
        default:
            return version_compare($v, $base, $op);
    }
}

/** Elimina un file o una cartella; ritorna false se qualcosa è rimasto. */
function rrmdir(string $dir): bool
{
    if (is_link($dir) || (!is_dir($dir) && file_exists($dir))) {
        return @unlink($dir);
    }
    if (!is_dir($dir)) {
        return true;
    }
    $ok = true;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            $ok = rrmdir($dir . '/' . $f) && $ok;
        }
    }
    return @rmdir($dir) && $ok;
}

/** File distribuiti con l'installer: la rimozione tocca solo questi (mai una cartella intera, che potrebbe contenere file del sito). */
const INSTALLER_FILES = [
    'assets/app.css', 'assets/app.js', 'assets/logo.svg',
    'catalog/frontend.json', 'catalog/mageos.json', 'catalog/msi.json', 'catalog/search.json',
    'distros/italiana.json', 'distros/light.json', 'distros/normal.json',
    'README.md', 'LICENSE', '.gitignore', 'lib/bootstrap.php', 'lib/catalog.php', 'lib/jobs.php', 'lib/magento-cli.php', 'lib/requirements.php',
    'worker.php', 'index.php',
];

/** Cartelle create dall'installer, tolte solo se restano vuote. */
const INSTALLER_DIRS = ['assets', 'catalog', 'distros', 'lib'];

/**
 * Rimuove l'installer. `.installed` (il blocco) si toglie solo se è sparito tutto il resto.
 * Ritorna ok, i file che non si sono potuti eliminare, ciò che nella cartella non è dell'installer e se il blocco è in piedi.
 */
function remove_installer(): array
{
    $root = realpath(APP_ROOT);
    if (!$root || $root === '/' || $root === home_dir()) {
        return ['ok' => false, 'error' => 'Percorso non sicuro.', 'failed' => [], 'left' => [], 'locked' => is_file(APP_ROOT . '/.installed')];
    }
    // Il blocco prima di tutto: se non si può scrivere non si cancella nulla, altrimenti resterebbe un installer svuotato e ancora usabile.
    if (!is_file("$root/.installed") && @file_put_contents("$root/.installed", date('c') . "\n") === false) {
        return ['ok' => false, 'error' => 'La cartella non è scrivibile: non ho eliminato nulla.', 'failed' => [], 'left' => [], 'locked' => false];
    }
    $failed = [];
    // Prima i file di contorno; codice (lib, worker, index) solo se è andato tutto bene, così un'installer rimasto a metà resta almeno in grado di mostrare il blocco.
    $isCode = static fn(string $f): bool => str_starts_with($f, 'lib/') || $f === 'worker.php' || $f === 'index.php';
    foreach ([false, true] as $code) {
        if ($failed) {
            break;
        }
        foreach (INSTALLER_FILES as $f) {
            if ($isCode($f) === $code && (is_file("$root/$f") || is_link("$root/$f")) && !@unlink("$root/$f")) {
                $failed[] = $f;
            }
        }
        if (!$code) {
            foreach (INSTALLER_DIRS as $d) {
                if ($d !== 'lib' && is_dir("$root/$d")) {
                    @rmdir("$root/$d");
                }
            }
        }
    }
    if (!$failed) {
        @rmdir("$root/lib");
        rrmdir("$root/.installed");
        rrmdir(app_tmp());
        @rmdir($root);
    }
    $left = is_dir($root) ? array_values(array_diff(scandir($root) ?: [], ['.', '..'])) : [];
    return ['ok' => !$failed, 'failed' => $failed, 'left' => $left, 'locked' => is_file("$root/.installed")];
}

/** Percorso assoluto senza `.`, `//` e link simbolici (anche se non esiste ancora): serve a confrontare cartelle. */
function normalize_path(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    $rest = [];
    while ($parts) {
        $real = realpath('/' . implode('/', $parts));
        if ($real !== false) {
            return rtrim($real, '/') . ($rest ? '/' . implode('/', $rest) : '');
        }
        array_unshift($rest, array_pop($parts));
    }
    return '/' . implode('/', $rest);
}

/** macOS e Windows ignorano le maiuscole nei percorsi: il confronto deve fare lo stesso. */
function path_key(string $path): string
{
    return in_array(PHP_OS_FAMILY, ['Darwin', 'Windows'], true) ? strtolower($path) : $path;
}

/** `$inner` è `$outer` o sta dentro (confronto su percorsi già normalizzati). */
function path_within(string $inner, string $outer): bool
{
    $i = rtrim(path_key($inner), '/') . '/';
    $o = rtrim(path_key($outer), '/') . '/';
    return str_starts_with($i, $o);
}
