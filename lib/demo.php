<?php
declare(strict_types=1);

/**
 * Modalità demo: l'installer si può percorrere da cima a fondo ma non tocca nulla.
 * Nessun controllo reale sul server, nessuna connessione a database o motori di ricerca,
 * nessun job, nessun processo lanciato, nessun file scritto (a parte la sessione PHP).
 */

/** Cartella e dati mostrati nel wizard: tutti di fantasia. */
const DEMO_SERVER = [
    'php_web' => '8.3.14', 'sapi' => 'fpm-fcgi', 'php_cli' => '8.3.14', 'os' => 'Linux 6.8.0',
    'software' => 'nginx', 'user' => 'demo', 'installer_dir' => '/var/www/installer', 'parent_dir' => '/var/www',
    'host' => 'demo', 'https' => true,
];

/** Valori precompilati del form (la UI li applica all'avvio). */
function demo_defaults(): array
{
    return [
        'target' => '/var/www/negozio-demo',
        'base_url' => 'https://negozio-demo.example.com/',
        'store_name' => 'Negozio Demo',
        'db_host' => 'localhost', 'db_name' => 'negozio_demo', 'db_user' => 'demo_user', 'db_password' => 'demo-password-1',
        'search_host' => 'localhost', 'search_port' => 9200, 'search_prefix' => 'negozio_demo',
        'admin_firstname' => 'Mario', 'admin_lastname' => 'Rossi', 'admin_email' => 'mario.rossi@example.com',
        'admin_user' => 'admin', 'admin_password' => 'DemoPassword1!',
    ];
}

function demo_requirements(array $p): array
{
    $platform = PLATFORMS[$p['platform'] ?? ''] ?? PLATFORMS['magento'];
    $version = find_version($p['platform'] ?? 'magento', (string)($p['version'] ?? ''));
    $ok = fn(string $id, string $label, string $detail): array => chk($id, $label, 'ok', $detail);
    $exts = $version['extensions'] ?? FALLBACK_EXTENSIONS;
    sort($exts);
    $groups = [
        ['title' => 'PHP', 'checks' => [
            $ok('php_web', 'PHP del web server (fpm-fcgi)', 'Installato 8.3.14'),
            $ok('php_cli', 'PHP da riga di comando', '/usr/bin/php · 8.3.14'),
            $ok('exec', 'Esecuzione comandi (exec / proc_open)', 'Disponibile'),
            $ok('memory', 'memory_limit (web)', '2G'),
            $ok('max_exec', 'max_execution_time (web)', '300 s'),
            $ok('opcache_comments', 'opcache.save_comments', 'OPcache attivo, save_comments=1'),
            $ok('allow_url_fopen', 'allow_url_fopen', 'Attivo'),
        ]],
        ['title' => 'Estensioni PHP richieste da ' . $platform['name'] . ' ' . ($version['version'] ?? ''),
            'checks' => array_map(fn($e) => $ok('ext_' . $e, 'ext-' . $e, 'Caricata'), $exts)],
        ['title' => 'Strumenti', 'checks' => [
            $ok('composer', 'Composer', '2.8.4 · /usr/local/bin/composer'),
            $ok('unzip', 'unzip', '/usr/bin/unzip'),
        ]],
        ['title' => 'File system', 'checks' => [
            $ok('target_empty', 'Cartella di destinazione', trim((string)($p['target'] ?? '')) . ' (verrà creata)'),
            $ok('disk', 'Spazio su disco', '48.2 GB liberi'),
            $ok('installer_dir', 'Cartella dell\'installer', '/var/www/installer'),
            $ok('tmp', 'Cartella di lavoro dell\'installer', '/tmp/magento-installer'),
        ]],
        ['title' => 'Rete', 'checks' => [
            $ok('net_repo', 'Repository ' . $platform['name'], 'Raggiungibile (HTTP 200)'),
            $ok('net_packagist', 'Packagist', 'Raggiungibile (HTTP 200)'),
        ]],
    ];
    return ['groups' => $groups, 'blocking' => 0, 'warnings' => 0, 'version' => $version];
}

function demo_test_db(array $p): array
{
    $name = trim((string)($p['name'] ?? ''));
    if (trim((string)($p['host'] ?? '')) === '' || $name === '' || trim((string)($p['user'] ?? '')) === '') {
        return ['status' => 'fail', 'message' => 'Compila host, nome database e utente.'];
    }
    return ['status' => 'ok', 'server' => 'MariaDB', 'version' => '10.11.6', 'raw' => '10.11.6-MariaDB', 'exists' => false, 'tables' => 0,
        'message' => "MariaDB 10.11.6 · il database \"$name\" non esiste e verrà creato."];
}

function demo_test_search(array $p): array
{
    if (trim((string)($p['host'] ?? '')) === '') {
        return ['status' => 'fail', 'message' => 'Host o porta non validi.'];
    }
    return ['status' => 'ok', 'version' => '2.17.1', 'engine' => 'opensearch', 'scheme' => 'http', 'message' => 'OpenSearch 2.17.1 raggiungibile.'];
}

const DEMO_DURATION = 24;

/** Piano della simulazione: [id, etichetta, secondo d'inizio, secondo di fine, righe di log]. */
function demo_plan(string $platformName, string $version): array
{
    return [
        ['preflight', 'Verifica ambiente e database', 0, 3, ['Controllo di PHP, estensioni e permessi…', '✔ Ambiente idoneo', '✔ Connessione al database riuscita']],
        ['download', "Download di $platformName $version", 3, 9, ['$ composer create-project --no-install …', 'Scaricamento dei metadati del prodotto…', '✔ Prodotto scaricato']],
        ['packages', 'Installazione dei pacchetti (composer)', 9, 15, ['$ composer install --no-dev', 'Installazione di 412 pacchetti…', '✔ Pacchetti installati']],
        ['install', "Installazione di $platformName (setup:install)", 15, 20, ['$ bin/magento setup:install …', 'Creazione delle tabelle del database…', '✔ Schema e dati installati']],
        ['configure', 'Configurazione del negozio', 20, 22, ['Lingua, valuta e fuso orario impostati', '✔ Negozio configurato']],
        ['finalize', 'Indicizzazione e rifinitura', 22, 24, ['$ bin/magento indexer:reindex', '✔ Indici aggiornati']],
    ];
}

/** Avvia la simulazione: solo un orario di inizio nella sessione. */
function demo_start(array $in): array
{
    $id = bin2hex(random_bytes(8));
    $_SESSION['demo_job'] = [
        'id' => $id, 'start' => time(),
        'platform' => PLATFORMS[$in['platform'] ?? '']['name'] ?? 'Magento Open Source',
        'version' => (string)($in['version'] ?? ''),
    ];
    return ['job' => $id];
}

/** Stato calcolato dal tempo trascorso, stesso formato di job_status(). */
function demo_status(string $id, int $since): array
{
    $j = $_SESSION['demo_job'] ?? null;
    if (!$j || $j['id'] !== $id) {
        return ['error' => 'Job inesistente.'];
    }
    $el = time() - $j['start'];
    $steps = [];
    $log = '';
    foreach (demo_plan($j['platform'], $j['version']) as [$sid, $label, $from, $to, $lines]) {
        $state = $el >= $to ? 'ok' : ($el >= $from ? 'running' : 'pending');
        $steps[] = ['id' => $sid, 'label' => $label, 'state' => $state, 'detail' => $state === 'ok' ? ($to - $from) . 's' : '',
            'progress' => $state === 'running' ? ($el - $from) / ($to - $from) : ($state === 'ok' ? 1.0 : null)];
        if ($state !== 'pending') {
            $log .= sprintf("\n[%s] ▶ %s\n", date('H:i:s', $j['start'] + $from), $label);
            foreach ($lines as $i => $line) {
                if ($el >= $from + ($to - $from) * ($i + 1) / count($lines) - 0.5) {
                    $log .= sprintf("[%s] %s\n", date('H:i:s', $j['start'] + $from + (int)(($to - $from) * ($i + 1) / count($lines))), $line);
                }
            }
        }
    }
    $done = $el >= DEMO_DURATION;
    $log = ltrim($log, "\n") . ($done ? "\n[" . date('H:i:s', $j['start'] + DEMO_DURATION) . "] Simulazione completata: in questa demo non è stato installato nulla.\n" : '');
    $chunk = substr($log, min($since, strlen($log)));
    return ['status' => ['state' => $done ? 'demo' : 'running', 'steps' => $steps], 'log' => $chunk, 'offset' => strlen($log), 'size' => strlen($log)];
}
