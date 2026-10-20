<?php
declare(strict_types=1);

function chk(string $id, string $label, string $status, string $detail = '', string $hint = ''): array
{
    return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail, 'hint' => $hint];
}

/** Informazioni sul PHP CLI, che è quello che esegue composer e bin/magento. */
function php_cli_info(): ?array
{
    static $info = false;
    if ($info !== false) {
        return $info;
    }
    $php = find_php_cli();
    if (!$php) {
        return $info = null;
    }
    $code = 'echo json_encode(["bin"=>PHP_BINARY,"version"=>PHP_VERSION,"ext"=>array_map("strtolower",get_loaded_extensions()),'
        . '"ini"=>["memory_limit"=>ini_get("memory_limit"),"opcache"=>ini_get("opcache.enable_cli"),"save_comments"=>ini_get("opcache.save_comments"),'
        . '"timezone"=>ini_get("date.timezone")]]);';
    $out = sh(escapeshellarg($php) . ' -r ' . escapeshellarg($code));
    $data = json_decode($out, true);
    return $info = is_array($data) ? $data + ['path' => $php] : null;
}

function composer_info(): array
{
    $php = find_php_cli();
    $composer = find_composer();
    if (!$php || !$composer) {
        return ['found' => false];
    }
    $out = sh(escapeshellarg($php) . ' ' . escapeshellarg($composer) . ' --version --no-ansi 2>&1');
    preg_match('/Composer version ([\d.]+)/', $out, $m);
    return ['found' => true, 'path' => $composer, 'version' => $m[1] ?? null, 'raw' => $out];
}

/**
 * Controlli del server per la versione scelta.
 * $p: version, distro (opzionale), target (opzionale), sampledata (bool), repos (url aggiuntivi).
 */
function run_requirements(array $p): array
{
    $version = find_version((string)($p['version'] ?? ''));
    $groups = [];

    // --- PHP ---
    $php = [];
    $cli = php_cli_info();
    $constraint = $version['php'] ?? '';
    $phpHuman = $constraint !== '' ? preg_replace('/~?(\d+\.\d+)\.0/', '$1.x', str_replace('||', ' · ', $constraint)) : 'n/d';

    if ($constraint !== '') {
        $php[] = chk('php_web', 'PHP del web server (' . PHP_SAPI . ')', php_satisfies(PHP_VERSION, $constraint) ? 'ok' : 'fail',
            'Installato ' . PHP_VERSION . ' · richiesto ' . $phpHuman,
            'Usa una versione PHP supportata da Mage-OS ' . ($version['version'] ?? '') . '.');
    }
    if ($cli) {
        $php[] = chk('php_cli', 'PHP da riga di comando', $constraint === '' || php_satisfies($cli['version'], $constraint) ? 'ok' : 'fail',
            $cli['path'] . ' · ' . $cli['version'],
            'Composer e bin/magento girano con il PHP CLI: deve essere una versione supportata.');
    } else {
        $php[] = chk('php_cli', 'PHP da riga di comando', 'fail', 'Binario php CLI non trovato', 'Installa php-cli e rendilo raggiungibile nel PATH.');
    }

    $exec = exec_available();
    $php[] = chk('exec', 'Esecuzione comandi (exec / proc_open)', $exec ? 'ok' : 'fail',
        $exec ? 'Disponibile' : 'Disabilitata in disable_functions',
        'L\'installer lancia composer e bin/magento: rimuovi exec e proc_open da disable_functions.');

    $mem = ini_bytes((string)ini_get('memory_limit'));
    $php[] = chk('memory', 'memory_limit (web)', ($mem === -1 || $mem >= 756 * 1024 ** 2) ? 'ok' : 'warn',
        (string)ini_get('memory_limit'), 'Per l\'uso quotidiano di Magento si consigliano almeno 756M (2G per i processi di indicizzazione). Composer e setup usano memory_limit illimitato.');
    $met = (int)ini_get('max_execution_time');
    $php[] = chk('max_exec', 'max_execution_time (web)', ($met === 0 || $met >= 180) ? 'ok' : 'warn', $met === 0 ? 'Illimitato' : "$met s",
        'L\'installazione gira in background e non è soggetta a questo limite; per l\'admin si consigliano almeno 180 s.');
    $opcache = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL);
    $php[] = chk('opcache_comments', 'opcache.save_comments', (!$opcache || filter_var(ini_get('opcache.save_comments'), FILTER_VALIDATE_BOOL)) ? 'ok' : 'fail',
        $opcache ? 'OPcache attivo, save_comments=' . ini_get('opcache.save_comments') : 'OPcache non attivo',
        'Magento usa le annotazioni: imposta opcache.save_comments=1.');
    $php[] = chk('allow_url_fopen', 'allow_url_fopen', filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL) ? 'ok' : 'warn',
        ini_get('allow_url_fopen') ? 'Attivo' : 'Disattivo', 'Alcune funzioni di Magento scaricano file remoti.');
    $groups[] = ['title' => 'PHP', 'checks' => $php];

    // --- Estensioni ---
    $exts = $version['extensions'] ?? FALLBACK_EXTENSIONS;
    sort($exts);
    $ext = [];
    $cliExt = $cli['ext'] ?? [];
    foreach ($exts as $e) {
        $web = extension_loaded($e);
        $inCli = !$cli || in_array(strtolower($e), $cliExt, true);
        $status = ($web && $inCli) ? 'ok' : 'fail';
        $detail = $web && $inCli ? 'Caricata' : (!$web && !$inCli ? 'Mancante (web e CLI)' : (!$web ? 'Mancante nel PHP web' : 'Mancante nel PHP CLI'));
        $ext[] = chk('ext_' . $e, 'ext-' . $e, $status, $detail, $status === 'fail' ? "Installa e abilita l'estensione PHP $e." : '');
    }
    $groups[] = ['title' => 'Estensioni PHP richieste da Mage-OS ' . ($version['version'] ?? ''), 'checks' => $ext];

    // --- Strumenti ---
    $tools = [];
    $comp = composer_info();
    if (!$comp['found']) {
        $tools[] = chk('composer', 'Composer', 'warn', 'Non trovato', 'Verrà scaricato automaticamente (composer.phar) all\'avvio dell\'installazione.');
    } else {
        $okv = $comp['version'] && version_compare($comp['version'], '2.2', '>=');
        $tools[] = chk('composer', 'Composer', $okv ? 'ok' : 'fail', ($comp['version'] ?? 'versione sconosciuta') . ' · ' . $comp['path'],
            'Serve Composer 2.2 o superiore.');
    }
    $tools[] = chk('unzip', 'unzip', sh('command -v unzip') !== '' ? 'ok' : 'warn', sh('command -v unzip') ?: 'Non trovato',
        'Composer scompatta più velocemente con unzip.');
    $groups[] = ['title' => 'Strumenti', 'checks' => $tools];

    // --- Filesystem ---
    $fs = [];
    $target = trim((string)($p['target'] ?? ''));
    $probe = $target !== '' ? $target : dirname(APP_ROOT);
    $parent = $probe;
    while ($parent !== '/' && !is_dir($parent)) {
        $parent = dirname($parent);
    }
    if ($target !== '') {
        $exists = is_dir($target);
        $empty = $exists ? (count(array_diff(scandir($target) ?: [], ['.', '..'])) === 0) : true;
        if ($exists && !$empty) {
            $fs[] = chk('target_empty', 'Cartella di destinazione', 'fail', $target . ' non è vuota', 'Scegli una cartella vuota o non esistente.');
        } else {
            $fs[] = chk('target_empty', 'Cartella di destinazione', is_writable($parent) ? 'ok' : 'fail',
                $target . ($exists ? ' (vuota)' : ' (verrà creata)'), is_writable($parent) ? '' : "Il processo PHP non può scrivere in $parent.");
        }
    }
    $free = @disk_free_space($parent);
    if ($free !== false) {
        $fs[] = chk('disk', 'Spazio su disco', $free >= 6 * 1024 ** 3 ? 'ok' : ($free >= 3 * 1024 ** 3 ? 'warn' : 'fail'),
            human_bytes($free) . ' liberi', 'Servono circa 3 GB (6 GB consigliati, più i dati di esempio).');
    }
    $fs[] = chk('tmp', 'Cartella di lavoro dell\'installer', is_writable(app_tmp()) ? 'ok' : 'fail', app_tmp(), 'Deve essere scrivibile per job e log.');
    $groups[] = ['title' => 'File system', 'checks' => $fs];

    // --- Rete ---
    $net = [];
    $hosts = [MAGEOS_REPO . 'packages.json' => 'Repository Mage-OS', 'https://repo.packagist.org/packages.json' => 'Packagist'];
    foreach ((array)($p['repos'] ?? []) as $url) {
        if (is_string($url) && preg_match('#^https://[\w.\-]+(/|$)#', $url)) {
            $hosts[rtrim($url, '/') . '/packages.json'] = parse_url($url, PHP_URL_HOST);
        }
    }
    foreach ($hosts as $url => $name) {
        $r = http_get($url, 6, [], true);
        $reach = $r['ok'] && $r['code'] > 0 && $r['code'] < 500;
        $net[] = chk('net_' . md5($url), $name, $reach ? 'ok' : 'fail',
            $reach ? 'Raggiungibile (HTTP ' . $r['code'] . ')' : 'Non raggiungibile' . ($r['error'] ? ': ' . $r['error'] : ''),
            'Il server deve poter raggiungere ' . parse_url($url, PHP_URL_HOST) . ' in HTTPS.');
    }
    $groups[] = ['title' => 'Rete', 'checks' => $net];

    $fail = $warn = 0;
    foreach ($groups as $g) {
        foreach ($g['checks'] as $c) {
            $fail += $c['status'] === 'fail' ? 1 : 0;
            $warn += $c['status'] === 'warn' ? 1 : 0;
        }
    }
    return ['groups' => $groups, 'blocking' => $fail, 'warnings' => $warn, 'version' => $version];
}

/** Ambiente mostrato nel pannello laterale. */
function server_info(): array
{
    $cli = php_cli_info();
    return [
        'php_web' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'php_cli' => $cli['version'] ?? null,
        'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user(),
        'installer_dir' => APP_ROOT,
        'parent_dir' => dirname(APP_ROOT),
        'host' => $_SERVER['HTTP_HOST'] ?? '',
        'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ];
}

/** Versioni di database accettate da Magento/Mage-OS 3.x (SqlVersionProvider). */
const DB_SUPPORTED_HUMAN = [
    'mysql' => '8.0 – 8.4',
    'mariadb' => '10.2 – 10.6, 10.11, 11.4 – 11.8, 12.2 – 12.3',
];

function db_is_supported(bool $isMaria, string $num): bool
{
    $patterns = $isMaria
        ? ['/^10\.[2-6]\./', '/^10\.11\./', '/^11\.[4-8]\./', '/^12\.[2-3]\./']
        : ['/^8\.[0-4]\./'];
    foreach ($patterns as $re) {
        if (preg_match($re, $num . '.')) {
            return true;
        }
    }
    return false;
}

/** Test di connessione al database. */
function test_db(array $p): array
{
    $host = trim((string)($p['host'] ?? ''));
    $name = trim((string)($p['name'] ?? ''));
    $user = (string)($p['user'] ?? '');
    $pass = (string)($p['password'] ?? '');
    if ($host === '' || $name === '' || $user === '') {
        return ['status' => 'fail', 'message' => 'Compila host, nome database e utente.'];
    }
    if (!extension_loaded('pdo_mysql')) {
        return ['status' => 'fail', 'message' => 'Estensione pdo_mysql non disponibile.'];
    }
    try {
        $pdo = db_connect($host, $user, $pass);
    } catch (Throwable $e) {
        return ['status' => 'fail', 'message' => 'Connessione fallita: ' . $e->getMessage()];
    }
    $raw = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $isMaria = stripos($raw, 'mariadb') !== false;
    preg_match('/^(\d+\.\d+\.\d+)/', $raw, $m);
    $num = $m[1] ?? '0.0.0';
    $res = ['server' => $isMaria ? 'MariaDB' : 'MySQL', 'version' => $num, 'raw' => $raw];
    if (!db_is_supported($isMaria, $num)) {
        $list = $isMaria ? DB_SUPPORTED_HUMAN['mariadb'] : DB_SUPPORTED_HUMAN['mysql'];
        return $res + ['status' => 'fail', 'unsupported' => true,
            'message' => $res['server'] . " $num non è supportato da Mage-OS. Versioni supportate: $list."];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $st->execute([$name]);
    $tables = (int)$st->fetchColumn();
    $exists = (bool)$pdo->query('SELECT 1 FROM information_schema.schemata WHERE schema_name = ' . $pdo->quote($name))->fetchColumn();
    $res['exists'] = $exists;
    $res['tables'] = $tables;
    if (!$exists) {
        return $res + ['status' => 'ok', 'message' => $res['server'] . " $num · il database \"$name\" non esiste e verrà creato."];
    }
    if ($tables > 0) {
        return $res + ['status' => 'warn', 'message' => $res['server'] . " $num · il database contiene già $tables tabelle: attiva \"Svuota il database\" per reinstallare."];
    }
    return $res + ['status' => 'ok', 'message' => $res['server'] . " $num · connessione riuscita, database vuoto."];
}

function db_connect(string $host, string $user, string $pass, ?string $dbname = null): PDO
{
    $dsn = 'mysql:charset=utf8mb4';
    if (str_starts_with($host, '/')) {
        $dsn .= ';unix_socket=' . $host;
    } else {
        $port = null;
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            [$host, $port] = [$m[1], $m[2]];
        }
        $dsn .= ';host=' . $host . ($port ? ';port=' . $port : '');
    }
    if ($dbname !== null) {
        $dsn .= ';dbname=' . $dbname;
    }
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
}

/** Test del motore di ricerca (OpenSearch / Elasticsearch). */
function test_search(array $p): array
{
    $host = trim((string)($p['host'] ?? ''));
    $port = (int)($p['port'] ?? 9200);
    if (!preg_match('/^[\w.\-]+$/', $host) || $port < 1 || $port > 65535) {
        return ['status' => 'fail', 'message' => 'Host o porta non validi.'];
    }
    $headers = [];
    if (!empty($p['user'])) {
        $headers[] = 'Authorization: Basic ' . base64_encode($p['user'] . ':' . ($p['password'] ?? ''));
    }
    $r = http_get("http://$host:$port/", 5, $headers);
    if (!$r['ok']) {
        return ['status' => 'fail', 'message' => "Nessuna risposta da $host:$port" . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '.'];
    }
    $j = json_decode($r['body'], true);
    if ($r['code'] === 401) {
        return ['status' => 'fail', 'message' => 'Autenticazione richiesta: inserisci utente e password.'];
    }
    $num = $j['version']['number'] ?? null;
    if (!$num) {
        return ['status' => 'fail', 'message' => 'Il servizio risponde ma non sembra OpenSearch/Elasticsearch.'];
    }
    $isOs = ($j['version']['distribution'] ?? '') === 'opensearch';
    $min = service_minimums((string)($p['version'] ?? ''))[$isOs ? 'opensearch' : 'elasticsearch'];
    $major = (int)$num;
    $engine = $isOs ? 'opensearch' : ($major >= 8 ? 'elasticsearch8' : 'elasticsearch7');
    $label = ($isOs ? 'OpenSearch ' : 'Elasticsearch ') . $num;
    if (version_compare($num, $min, '<')) {
        return ['status' => 'fail', 'engine' => $engine, 'message' => "$label è troppo vecchio: serve almeno $min."];
    }
    return ['status' => 'ok', 'engine' => $engine, 'version' => $num, 'message' => "$label raggiungibile."];
}
