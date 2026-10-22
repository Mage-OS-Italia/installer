<?php
declare(strict_types=1);

const APP_NAME = 'Magento Installer';
const APP_VERSION = '0.2.0';
/** Mirror pubblico dei pacchetti Magento Open Source (stessi pacchetti di repo.magento.com, nessuna chiave Adobe). */
const MAGENTO_REPO = 'https://mirror.mage-os.org/';
const MAGEOS_REPO = 'https://repo.mage-os.org/';

define('APP_ROOT', dirname(__DIR__));

/** Cartella temporanea privata dell'installer (job, log, cache, token): fuori dalla document root. */
function app_tmp(string $sub = ''): string
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/magento-installer-' . substr(md5(APP_ROOT), 0, 10);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
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

/** Le funzioni di esecuzione comandi servono all'installer: se sono disabilitate lo diciamo chiaramente. */
function exec_available(): bool
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    foreach (['exec', 'proc_open'] as $fn) {
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
    return array_merge([
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
    foreach (array_unique($cands) as $c) {
        if ($c && is_executable($c) && sh(escapeshellarg($c) . ' -r ' . escapeshellarg('echo PHP_SAPI;')) === 'cli') {
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

function http_get(string $url, int $timeout = 8, array $headers = [], bool $headOnly = false): array
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
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
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

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            rrmdir($dir . '/' . $f);
        }
    }
    @rmdir($dir);
}
