<?php
declare(strict_types=1);

/**
 * Modalità demo: l'installer si può percorrere da cima a fondo ma non tocca nulla.
 * Nessun controllo reale sul server, nessuna connessione a database o motori di ricerca,
 * nessun job, nessun processo lanciato, nessun file scritto (a parte la sessione PHP).
 */

/** Measurement ID di Google Analytics 4: vuoto = GA spento. Si carica solo dopo il consenso (assets/consent.js). */
const DEMO_GA4_ID = 'G-QEN3D7KEVZ';

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
            $ok('php_web', t('PHP del web server (fpm-fcgi)'), t('Installato {v}', ['v'=>'8.3.14'])),
            $ok('php_cli', t('PHP da riga di comando'), '/usr/bin/php · 8.3.14'),
            $ok('exec', t('Esecuzione comandi (exec / proc_open)'), t('Disponibile')),
            $ok('memory', 'memory_limit (web)', '2G'),
            $ok('max_exec', 'max_execution_time (web)', '300 s'),
            $ok('opcache_comments', 'opcache.save_comments', t('OPcache attivo, save_comments=1')),
            $ok('allow_url_fopen', 'allow_url_fopen', t('Attivo')),
        ]],
        ['title' => t('Estensioni PHP richieste da {p} {v}', ['p' => $platform['name'], 'v' => $version['version'] ?? '']),
            'checks' => array_map(fn($e) => $ok('ext_' . $e, 'ext-' . $e, t('Caricata')), $exts)],
        ['title' => t('Strumenti'), 'checks' => [
            $ok('composer', 'Composer', '2.8.4 · /usr/local/bin/composer'),
            $ok('unzip', 'unzip', '/usr/bin/unzip'),
        ]],
        ['title' => t('File system'), 'checks' => [
            $ok('target_empty', t('Cartella di destinazione'), t('{d} (verrà creata)', ['d' => trim((string)($p['target'] ?? ''))])),
            $ok('disk', t('Spazio su disco'), t('{n} GB liberi', ['n' => '48.2'])),
            $ok('installer_dir', t('Cartella dell\'installer'), '/var/www/installer'),
            $ok('tmp', t('Cartella di lavoro dell\'installer'), '/tmp/magento-installer'),
        ]],
        ['title' => t('Rete'), 'checks' => [
            $ok('net_repo', t('Repository {p}', ['p' => $platform['name']]), t('Raggiungibile (HTTP 200)')),
            $ok('net_packagist', 'Packagist', t('Raggiungibile (HTTP 200)')),
        ]],
    ];
    return ['groups' => $groups, 'blocking' => 0, 'warnings' => 0, 'version' => $version];
}

function demo_test_db(array $p): array
{
    $name = trim((string)($p['name'] ?? ''));
    if (trim((string)($p['host'] ?? '')) === '' || $name === '' || trim((string)($p['user'] ?? '')) === '') {
        return ['status' => 'fail', 'message' => t('Compila host, nome database e utente.')];
    }
    return ['status' => 'ok', 'server' => 'MariaDB', 'version' => '10.11.6', 'raw' => '10.11.6-MariaDB', 'exists' => false, 'tables' => 0,
        'message' => t('MariaDB 10.11.6 · il database "{n}" non esiste e verrà creato.', ['n' => $name])];
}

function demo_test_search(array $p): array
{
    if (trim((string)($p['host'] ?? '')) === '') {
        return ['status' => 'fail', 'message' => t('Host o porta non validi.')];
    }
    return ['status' => 'ok', 'version' => '2.17.1', 'engine' => 'opensearch', 'scheme' => 'http', 'message' => t('OpenSearch 2.17.1 raggiungibile.')];
}

const DEMO_DURATION = 24;

/** Piano della simulazione: [id, etichetta, secondo d'inizio, secondo di fine, righe di log]. */
function demo_plan(string $platformName, string $version): array
{
    return [
        ['preflight', t('Verifica ambiente e database'), 0, 3, [t('Controllo di PHP, estensioni e permessi…'), t('✔ Ambiente idoneo'), t('✔ Connessione al database riuscita')]],
        ['download', t('Download di {p} {v}', ['p' => $platformName, 'v' => $version]), 3, 9, ['$ composer create-project --no-install …', t('Scaricamento dei metadati del prodotto…'), t('✔ Prodotto scaricato')]],
        ['packages', t('Installazione dei pacchetti (composer)'), 9, 15, ['$ composer install --no-dev', t('Installazione di {n} pacchetti…', ['n' => 412]), t('✔ Pacchetti installati')]],
        ['install', t('Installazione di {p} (setup:install)', ['p' => $platformName]), 15, 20, ['$ bin/magento setup:install …', t('Creazione delle tabelle del database…'), t('✔ Schema e dati installati')]],
        ['configure', t('Configurazione del negozio'), 20, 22, [t('Lingua, valuta e fuso orario impostati'), t('✔ Negozio configurato')]],
        ['finalize', t('Indicizzazione e rifinitura'), 22, 24, ['$ bin/magento indexer:reindex', t('✔ Indici aggiornati')]],
    ];
}

/** Avvia la simulazione: solo un orario di inizio nella sessione. */
function demo_start(array $in): array
{
    $id = bin2hex(random_bytes(8));
    $_SESSION['demo_job'] = [
        'id' => $id, 'start' => time(), 'lang' => current_lang(),
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
        return ['error' => t('Job inesistente.')];
    }
    set_lang((string)($j['lang'] ?? 'it')); // il log di una simulazione resta nella lingua con cui è partita
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
    $log = ltrim($log, "\n") . ($done ? "\n[" . date('H:i:s', $j['start'] + DEMO_DURATION) . '] ' . t('Simulazione completata: in questa demo non è stato installato nulla.') . "\n" : '');
    $chunk = substr($log, min($since, strlen($log)));
    return ['status' => ['state' => $done ? 'demo' : 'running', 'steps' => $steps], 'log' => $chunk, 'offset' => strlen($log), 'size' => strlen($log)];
}
