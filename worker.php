<?php
/**
 * Processo di installazione in background (solo CLI).
 * Lanciato da lib/jobs.php: legge il job da <tmp>/jobs/<id>/job.json, scrive stato e log accanto.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/requirements.php';
require __DIR__ . '/lib/jobs.php';

final class StepFailure extends RuntimeException
{
}

final class Worker
{
    private array $cfg;
    private string $dir;
    private array $status;
    private array $secrets = [];
    private string $php;
    private float $t0;
    private array $composerBase = [];
    private int $lastProgressLogged = -1;
    private string $dbVersion = '';
    private bool $dbIsMaria = false;
    private array $warnings = [];

    private const STEPS = [
        'preflight' => 'Verifica ambiente e database',
        'download' => 'Download di Magento Open Source',
        'packages' => 'Installazione dei pacchetti (composer)',
        'install' => 'Installazione di Magento (setup:install)',
        'configure' => 'Configurazione del negozio',
        'finalize' => 'Indicizzazione e rifinitura',
    ];

    public function __construct(string $id)
    {
        $this->dir = job_dir($id);
        $this->cfg = json_decode((string)file_get_contents($this->dir . '/job.json'), true);
        $this->php = find_php_cli() ?? PHP_BINARY;
        $this->t0 = microtime(true);
        foreach ([$this->cfg['db']['password'], $this->cfg['admin']['password'], $this->cfg['search']['password']] as $s) {
            $this->secrets[] = (string)$s;
        }
        foreach ($this->cfg['credentials'] as $c) {
            $this->secrets[] = $c['password'];
        }
        $this->secrets = array_values(array_filter(array_unique($this->secrets), fn($s) => strlen($s) >= 3));

        $steps = [];
        foreach (self::STEPS as $sid => $label) {
            if ($sid === 'download') {
                $label = 'Download di ' . platform_of($this->cfg['platform'])['name'] . ' ' . $this->cfg['version'];
            }
            $steps[] = ['id' => $sid, 'label' => $label, 'state' => 'pending', 'detail' => '', 'progress' => null];
        }
        $this->status = ['state' => 'running', 'pid' => getmypid(), 'started' => time(), 'steps' => $steps];
        $this->save();
    }

    public function main(): int
    {
        $this->log(APP_NAME . ' ' . APP_VERSION . ' · ' . platform_of($this->cfg['platform'])['name'] . ' ' . $this->cfg['version'] . ' · distro "' . $this->cfg['distro'] . '"');
        $this->log('Destinazione: ' . $this->cfg['target']);
        try {
            $this->step('preflight', fn() => $this->preflight());
            $this->step('download', fn() => $this->download());
            $this->step('packages', fn() => $this->packages());
            $this->step('install', fn() => $this->install());
            $this->step('configure', fn() => $this->configure());
            $this->step('finalize', fn() => $this->finalize());
        } catch (StepFailure $e) {
            $this->status['state'] = $e->getMessage() === 'cancelled' ? 'cancelled' : 'failed';
            $this->status['error'] = $e->getMessage() === 'cancelled' ? 'Installazione annullata.' : str_replace($this->secrets, '••••••', $e->getMessage());
            $this->log('✖ ' . $this->status['error']);
            $this->scrub();
            $this->save();
            return 1;
        } catch (Throwable $e) {
            $this->status['state'] = 'failed';
            $this->status['error'] = str_replace($this->secrets, '••••••', $e->getMessage());
            $this->log('✖ Errore: ' . $e->getMessage());
            $this->scrub();
            $this->save();
            return 1;
        }
        $this->status['state'] = 'done';
        $this->status['finished'] = time();
        $this->scrub();
        $this->save();
        return 0;
    }

    // ------------------------------------------------------------------ steps

    private function preflight(): void
    {
        $c = $this->cfg;
        if (is_dir($c['target']) && count(array_diff(scandir($c['target']) ?: [], ['.', '..'])) > 0) {
            throw new StepFailure('La cartella di destinazione non è più vuota: ' . $c['target']);
        }

        $this->log('Connessione al database ' . $c['db']['host'] . '…');
        try {
            $pdo = db_connect($c['db']['host'], $c['db']['user'], $c['db']['password']);
        } catch (Throwable $e) {
            throw new StepFailure('Connessione al database fallita: ' . $e->getMessage());
        }
        $raw = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $this->dbIsMaria = stripos($raw, 'mariadb') !== false;
        preg_match('/^(\d+\.\d+\.\d+)/', $raw, $vm);
        $this->dbVersion = $vm[1] ?? '';
        if (!db_is_supported($this->dbIsMaria, $this->dbVersion)) {
            if (!$c['allow_unsupported_db']) {
                throw new StepFailure(($this->dbIsMaria ? 'MariaDB ' : 'MySQL ') . $this->dbVersion . ' non è supportato da ' . platform_of($c['platform'])['name'] . '.');
            }
            $this->log('⚠ ' . ($this->dbIsMaria ? 'MariaDB ' : 'MySQL ') . $this->dbVersion . ' non è nell\'elenco ufficiale: procedo su richiesta (solo sviluppo).');
        }
        $name = $c['db']['name'];
        $exists = (bool)$pdo->query('SELECT 1 FROM information_schema.schemata WHERE schema_name = ' . $pdo->quote($name))->fetchColumn();
        if (!$exists) {
            if (!$c['create_db']) {
                throw new StepFailure("Il database \"$name\" non esiste.");
            }
            try {
                $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            } catch (Throwable $e) {
                throw new StepFailure("Impossibile creare il database \"$name\": " . $e->getMessage());
            }
            $this->log("Database \"$name\" creato.");
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
            $st->execute([$name]);
            $n = (int)$st->fetchColumn();
            if ($n > 0 && !$c['cleanup_db']) {
                throw new StepFailure("Il database \"$name\" contiene già $n tabelle. Svuotalo o abilita l'opzione di pulizia.");
            }
            $this->log($n > 0 ? "Il database contiene $n tabelle: verrà svuotato." : 'Database vuoto, ok.');
        }

        // Composer
        $composer = find_composer();
        if (!$composer) {
            $this->log('Composer non trovato: scarico composer.phar…');
            $r = http_get('https://getcomposer.org/download/latest-stable/composer.phar', 90);
            if (!$r['ok'] || $r['code'] !== 200 || strlen($r['body']) < 500000) {
                throw new StepFailure('Impossibile scaricare composer.phar. Installa Composer sul server.');
            }
            $composer = app_tmp() . '/composer.phar';
            file_put_contents($composer, $r['body']);
        }
        $home = $this->dir . '/composer-home';
        @mkdir($home, 0700, true);
        $auth = [];
        foreach ($c['credentials'] as $host => $cred) {
            $auth[$host] = $cred;
        }
        // Oggetti JSON anche quando vuoti: composer rifiuta gli array.
        file_put_contents($home . '/auth.json', json_encode(['http-basic' => (object)$auth], JSON_UNESCAPED_SLASHES));
        chmod($home . '/auth.json', 0600);
        $this->composerBase = [
            'cmd' => escapeshellarg($this->php) . ' -d memory_limit=-1 ' . escapeshellarg($composer),
            'env' => [
                'COMPOSER_HOME' => $home,
                'COMPOSER_CACHE_DIR' => app_tmp('composer-cache'),
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_MEMORY_LIMIT' => '-1',
                'COMPOSER_NO_SECURITY_BLOCKING' => '1',
                'COMPOSER_PROCESS_TIMEOUT' => '0',
            ],
        ];
        $v = sh($this->composerBase['cmd'] . ' --version --no-ansi 2>&1');
        $this->log($v !== '' ? $v : 'Composer: versione non rilevata');
        if (!preg_match('/version (\d+)\./', $v, $m) || (int)$m[1] < 2) {
            throw new StepFailure('Serve Composer 2.');
        }
    }

    private function download(): void
    {
        $c = $this->cfg;
        $pl = platform_of($c['platform']);
        $parent = dirname($c['target']);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true)) {
            throw new StepFailure("Impossibile creare $parent");
        }
        $cmd = $this->composerBase['cmd'] . ' create-project --repository-url=' . escapeshellarg($pl['repo'])
            . ' ' . $pl['vendor'] . '/project-community-edition ' . escapeshellarg($c['target']) . ' ' . escapeshellarg($c['version'])
            . ' --no-interaction --no-progress --no-ansi --prefer-dist --no-audit --no-install';
        $code = $this->run($cmd, $parent, $this->composerBase['env']);
        if ($code !== 0 || !is_file($c['target'] . '/composer.json')) {
            throw new StepFailure("composer create-project è fallito (codice $code).");
        }
        if ($c['save_auth'] && $c['credentials']) {
            $dest = $c['target'] . '/auth.json';
            copy($this->dir . '/composer-home/auth.json', $dest);
            chmod($dest, 0600);
            $this->log('Credenziali salvate in auth.json del progetto (0600).');
        }
    }

    /** Aggiunge la versione del DB agli elenchi ammessi in app/etc/di.xml (come il workaround MySQL 9.x). */
    private function allowDbVersion(): void
    {
        $file = $this->cfg['target'] . '/app/etc/di.xml';
        $xml = (string)@file_get_contents($file);
        [$maj, $min] = array_map('intval', explode('.', $this->dbVersion));
        $name = ($this->dbIsMaria ? 'MariaDB-' : 'MySQL-') . "$maj.$min-installer";
        $item = '                <item name="' . $name . '" xsi:type="string">^' . $maj . '\\.' . $min . '\\.</item>' . "\n";
        $needle = '<argument name="supportedVersionPatterns" xsi:type="array">' . "\n";
        $pos = strpos($xml, $needle);
        if ($pos === false) {
            throw new StepFailure('Impossibile aggiornare app/etc/di.xml: blocco supportedVersionPatterns non trovato.');
        }
        $pos += strlen($needle);
        file_put_contents($file, substr($xml, 0, $pos) . $item . substr($xml, $pos));
        $this->log("app/etc/di.xml: aggiunta la versione $maj.$min tra quelle ammesse (Composer può sovrascrivere il file ai prossimi aggiornamenti).");
    }

    /**
     * Prepara composer.json (repository, replace, pacchetti della distro, dati di esempio) e fa un solo
     * `composer update`: i pacchetti in `replace` non vengono mai scaricati.
     */
    private function packages(): void
    {
        $c = $this->cfg;
        $file = $c['target'] . '/composer.json';
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json)) {
            throw new StepFailure('composer.json del progetto non leggibile.');
        }
        foreach ($c['repositories'] as $r) {
            $json['repositories'][$r['id']] = ['type' => 'composer', 'url' => $r['url']];
        }
        foreach ($c['replace'] as $name) {
            $json['replace'][$name] = '*';
        }
        $require = [];
        foreach ($c['packages'] as $spec) {
            [$n, $v] = explode(':', $spec, 2);
            $require[$n] = $v;
        }
        if ($c['sample_data']) {
            foreach (sample_data_packages($c['platform'], $c['version']) as $spec) {
                [$n, $v] = explode(':', $spec, 2);
                $require[$n] = $v;
            }
        }
        foreach ($require as $n => $v) {
            $json['require'][$n] = $v;
        }
        if ($c['replace']) {
            $this->log(count($c['replace']) . ' pacchetti esclusi tramite "replace".');
        }
        if ($require) {
            $this->log('Pacchetti aggiunti: ' . implode(', ', array_keys($require)));
        }
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $total = 0;
        $done = 0;
        $problems = [];
        $code = $this->run($this->composerBase['cmd'] . ' update --no-interaction --no-progress --no-ansi --no-audit',
            $c['target'], $this->composerBase['env'],
            function (string $line) use (&$total, &$done, &$problems) {
                if (preg_match('/could not be found|not be found|403|401|Invalid credentials|authentication|Your requirements could not|Problem \d|conflict|does not match|no matching package|Could not (?:resolve|find)/i', $line) && count($problems) < 6) {
                    $problems[] = trim($line);
                }
                if (preg_match('/(?:Package|Lock file) operations: (\d+) installs?/', $line, $m)) {
                    $total = max($total, (int)$m[1]);
                }
                if (preg_match('/^\s*- Installing /', $line)) {
                    $done++;
                    $this->progress('packages', $done, $total, 'pacchetti');
                }
            });
        if ($code !== 0 || !is_file($c['target'] . '/bin/magento')) {
            $why = $problems ? "\n" . implode("\n", $problems) : '';
            if (($c['frontend']['id'] ?? '') === 'hyva') {
                if (preg_match('#hyva-themes/magento2-hyva-checkout.*could not be found#i', $why)) {
                    $why .= "\nIl repository Hyvä non offre Hyvä Checkout: la licenza di questo progetto probabilmente non lo include. Scegli Loki Checkout oppure usa le chiavi di una licenza con Hyvä Checkout.";
                } elseif (preg_match('/401|403|authentication|credentials|permissions|could not be found/i', $why)) {
                    $why .= "\nControlla chiave e nome del progetto Hyvä (area licenze su hyva.io): sembra che il repository privato rifiuti l'accesso.";
                }
            }
            throw new StepFailure("composer update è fallito (codice $code)." . ($why !== '' ? $why : ' Controlla credenziali e disponibilità dei pacchetti.'));
        }
    }

    private function install(): void
    {
        $c = $this->cfg;
        // Dopo composer: l'update può riscrivere app/etc/di.xml (magento-force: override).
        if ($c['allow_unsupported_db'] && !db_is_supported($this->dbIsMaria, $this->dbVersion)) {
            $this->allowDbVersion();
        }
        if ($c['sample_data']) {
            $this->fixSampleMedia();
        }
        $a = [
            '--base-url=' . $c['base_url'],
            '--db-host=' . $c['db']['host'],
            '--db-name=' . $c['db']['name'],
            '--db-user=' . $c['db']['user'],
            '--db-password=' . $c['db']['password'],
            '--backend-frontname=' . $c['backend_frontname'],
            '--admin-firstname=' . $c['admin']['firstname'],
            '--admin-lastname=' . $c['admin']['lastname'],
            '--admin-email=' . $c['admin']['email'],
            '--admin-user=' . $c['admin']['user'],
            '--admin-password=' . $c['admin']['password'],
            '--language=' . $c['language'],
            '--currency=' . $c['currency'],
            '--timezone=' . $c['timezone'],
            '--use-rewrites=1',
        ];
        if ($c['db']['prefix'] !== '') {
            $a[] = '--db-prefix=' . $c['db']['prefix'];
        }
        if ($c['cleanup_db']) {
            $a[] = '--cleanup-database';
        }
        // Con la ricerca MySQL il motore non si passa: setup:install non conosce ancora 'lmysql' e lo imposta configure().
        if ($c['search']['host'] !== '') {
            $a[] = '--search-engine=' . $c['search']['engine'];
            $se = $c['search']['engine'] === 'opensearch' ? 'opensearch' : 'elasticsearch';
            $a[] = "--$se-host=" . $c['search']['host'];
            $a[] = "--$se-port=" . $c['search']['port'];
            $a[] = "--$se-index-prefix=" . $c['search']['prefix'];
            if ($c['search']['user'] !== '') {
                $a[] = "--$se-enable-auth=1";
                $a[] = "--$se-username=" . $c['search']['user'];
                $a[] = "--$se-password=" . $c['search']['password'];
            }
        }
        if (str_starts_with($c['base_url'], 'https://')) {
            $a[] = '--use-secure=1';
            $a[] = '--base-url-secure=' . $c['base_url'];
            $a[] = '--use-secure-admin=1';
        }
        $cmd = escapeshellarg($this->php) . ' -d memory_limit=-1 bin/magento setup:install --no-interaction ' . implode(' ', array_map('escapeshellarg', $a));
        $flagged = [];
        $code = $this->run($cmd, $c['target'], [], function (string $line) use (&$flagged) {
            if (preg_match('/\[Progress: (\d+) \/ (\d+)\]/', $line, $m)) {
                $this->progress('install', (int)$m[1], (int)$m[2], 'operazioni');
            }
            // setup:install esce con 0 anche se questi passaggi falliscono: li segnaliamo noi.
            foreach (['Sample Data is installed with errors' => 'I dati di esempio sono stati installati con errori: controlla var/log/system.log e var/log/exception.log nel progetto.',
                      'Indexing Error' => 'La ricerca ha rifiutato la creazione degli indici durante l\'installazione: controlla il motore di ricerca.'] as $needle => $msg) {
                if (str_contains($line, $needle) && empty($flagged[$needle])) {
                    $flagged[$needle] = true;
                    $this->warn($msg);
                }
            }
        });
        if ($code !== 0 || !is_file($c['target'] . '/app/etc/env.php')) {
            throw new StepFailure("setup:install è fallito (codice $code). Controlla il log qui sopra.");
        }
    }

    private function configure(): void
    {
        $c = $this->cfg;
        $conf = ['general/store_information/name' => $c['store_name']] + $c['distro_config'];
        if ($c['search']['host'] === '') {
            $conf['catalog/search/engine'] = $c['search']['engine'];
        }
        foreach ($conf as $path => $value) {
            $this->mage('config:set ' . escapeshellarg((string)$path) . ' ' . escapeshellarg((string)$value), false);
        }
        $this->detail('configure', 'Configurazione salvata');

        $this->frontend();

        if ($c['italian_tax']) {
            $this->detail('configure', 'IVA italiana…');
            $this->italianTax();
        }

        if ($c['disable_2fa']) {
            $present = [];
            $modules = @include $c['target'] . '/app/etc/config.php';
            foreach (['Magento_AdminAdobeImsTwoFactorAuth', 'Magento_TwoFactorAuth'] as $m) {
                if (isset($modules['modules'][$m])) {
                    $present[] = $m;
                }
            }
            if ($present) {
                $this->mage('module:disable ' . implode(' ', $present));
            }
        }

        if ($c['install_cron']) {
            $this->mage('cron:install', false);
        }

        $this->detail('configure', 'Modalità ' . $c['deploy_mode'] . '…');
        $this->mage($c['deploy_mode'] === 'production' ? 'deploy:mode:set production' : 'deploy:mode:set developer --skip-compilation');
    }

    /**
     * Tema e checkout. Con Hyvä, Luma non c'è più e Magento non sa quale tema usare: lo attiviamo noi.
     * Il CSS Tailwind di Loki (e di Hyvä Checkout) non lo compiliamo: serve Node e il tema del progetto.
     */
    private function frontend(): void
    {
        $fe = $this->cfg['frontend'] ?? ['id' => 'luma', 'checkout' => '', 'theme_path' => ''];
        if ($fe['id'] === 'hyva') {
            $this->detail('configure', 'Tema Hyvä…');
            $c = $this->cfg;
            $pdo = db_connect($c['db']['host'], $c['db']['user'], $c['db']['password'], $c['db']['name']);
            $st = $pdo->prepare('SELECT theme_id FROM `' . str_replace('`', '', $c['db']['prefix'] . 'theme') . '` WHERE theme_path = ? AND area = ? LIMIT 1');
            $st->execute([$fe['theme_path'], 'frontend']);
            $id = $st->fetchColumn();
            if ($id === false) {
                throw new StepFailure('Tema ' . $fe['theme_path'] . ' non registrato: verifica che il pacchetto Hyvä sia stato installato.');
            }
            $this->mage('config:set design/theme/theme_id ' . (int)$id);
            $this->log('Tema attivo: ' . $fe['theme_path'] . '. Luma e Blank non sono installati.');
        }
        if ($fe['checkout'] === 'loki') {
            if ($fe['id'] === 'hyva') {
                $this->mage('hyva:config:generate', false);
            }
            $this->warn('Loki Checkout: compila il CSS Tailwind ' . ($fe['id'] === 'hyva' ? 'del tema Hyvä' : 'del modulo Luma') . ' (documentazione su loki-checkout.com), altrimenti il checkout non è stilizzato.');
        } elseif ($fe['checkout'] === 'hyva') {
            $this->mage('config:set dev/template/minify_html 0', false);
            $this->log('Minificazione HTML disattivata, come richiede Hyvä Checkout.');
            $this->warn('Hyvä Checkout: ricompila il CSS Tailwind del tema e attiva il checkout in Stores > Configuration > Hyvä Themes > Checkout.');
        }
    }

    /** Avvisi mostrati nel riepilogo finale. */
    private function warn(string $text): void
    {
        $this->warnings[] = $text;
        $this->log('⚠ ' . $text);
    }

    /**
     * Regole IVA italiane: prezzi di listino IVA inclusa, aliquota ordinaria 22% sulla classe "Taxable Goods"
     * e due classi per le aliquote ridotte (10% e 4%). Le regole stanno nel database: non c'è un comando CLI.
     * Il calcolo segue l'indirizzo di spedizione (default di Magento): per vendere ad altri paesi UE servono
     * le rispettive aliquote.
     */
    private function italianTax(): void
    {
        $c = $this->cfg;
        $conf = [
            'tax/calculation/price_includes_tax' => 1,
            'tax/calculation/shipping_includes_tax' => 1,
            'tax/calculation/apply_after_discount' => 1,
            'tax/calculation/discount_tax' => 1,
            'tax/calculation/cross_border_trade_enabled' => 1,
            'tax/display/type' => 2,
            'tax/display/shipping' => 2,
            'tax/cart_display/price' => 2,
            'tax/cart_display/subtotal' => 2,
            'tax/cart_display/shipping' => 2,
            'tax/sales_display/price' => 2,
            'tax/sales_display/subtotal' => 2,
            'tax/sales_display/shipping' => 2,
        ];
        foreach ($conf as $path => $value) {
            $this->mage('config:set ' . escapeshellarg($path) . ' ' . escapeshellarg((string)$value), false);
        }

        $pdo = db_connect($c['db']['host'], $c['db']['user'], $c['db']['password'], $c['db']['name']);
        $t = fn(string $table): string => '`' . str_replace('`', '', $c['db']['prefix'] . $table) . '`';
        $classId = function (string $name, string $type) use ($pdo, $t): int {
            $st = $pdo->prepare('SELECT class_id FROM ' . $t('tax_class') . ' WHERE class_name = ? AND class_type = ? LIMIT 1');
            $st->execute([$name, $type]);
            $id = $st->fetchColumn();
            if ($id === false) {
                $pdo->prepare('INSERT INTO ' . $t('tax_class') . ' (class_name, class_type) VALUES (?, ?)')->execute([$name, $type]);
                $id = $pdo->lastInsertId();
            }
            return (int)$id;
        };

        $customerClass = $classId('Retail Customer', 'CUSTOMER');
        $shippingClass = $classId('Taxable Goods', 'PRODUCT');
        $rules = [
            ['IVA 22%', 22.0, $shippingClass],
            ['IVA 10%', 10.0, $classId('IVA ridotta 10%', 'PRODUCT')],
            ['IVA 4%', 4.0, $classId('IVA ridotta 4%', 'PRODUCT')],
        ];
        $pdo->beginTransaction();
        try {
            foreach ($rules as [$label, $rate, $productClass]) {
                $code = 'IT-' . str_replace(' ', '-', $label);
                $pdo->prepare('INSERT INTO ' . $t('tax_calculation_rate') . ' (tax_country_id, tax_region_id, tax_postcode, code, rate) VALUES (?, 0, ?, ?, ?)')
                    ->execute(['IT', '*', $code, $rate]);
                $rateId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO ' . $t('tax_calculation_rule') . ' (code, priority, position, calculate_subtotal) VALUES (?, 0, 0, 0)')->execute([$label]);
                $ruleId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO ' . $t('tax_calculation') . ' (tax_calculation_rate_id, tax_calculation_rule_id, customer_tax_class_id, product_tax_class_id) VALUES (?, ?, ?, ?)')
                    ->execute([$rateId, $ruleId, $customerClass, $productClass]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new StepFailure('Impossibile creare le regole IVA: ' . $e->getMessage());
        }
        $this->mage('config:set tax/classes/shipping_tax_class ' . $shippingClass, false);
        $this->log('Regole IVA create: 22% (Taxable Goods), 10% e 4% (classi "IVA ridotta"), prezzi IVA inclusa.');
    }

    private function finalize(): void
    {
        $c = $this->cfg;
        $this->detail('finalize', 'Indicizzazione…');
        if (!$this->mage('indexer:reindex', false)) {
            $this->warn(($c['search']['host'] === '' ? 'Reindex non riuscito' : 'Reindex non riuscito: controlla che ' . ($c['search']['engine'] === 'opensearch' ? 'OpenSearch' : 'Elasticsearch') . ' accetti la creazione degli indici')
                . '. Rilancialo con bin/magento indexer:reindex (dettagli nel log).');
        }
        $this->mage('cache:flush');

        $dur = (int)round(microtime(true) - $this->t0);
        $warnings = $this->warnings;
        if (!is_file($c['target'] . '/pub/index.php')) {
            $warnings[] = 'pub/index.php non trovato: verifica la struttura del progetto.';
        }
        $warnings[] = 'La document root del virtual host deve puntare a ' . $c['target'] . '/pub (Valet e i driver Magento lo fanno da soli).';
        $this->status['result'] = [
            'storefront' => $c['base_url'],
            'admin' => $c['base_url'] . $c['backend_frontname'] . '/',
            'admin_user' => $c['admin']['user'],
            'version' => $c['version'],
            'target' => $c['target'],
            'duration' => $dur,
            'mode' => $c['deploy_mode'],
            'warnings' => $warnings,
        ];
        @file_put_contents(APP_ROOT . '/.installed', date('c') . "\n" . $c['target'] . "\n");
        $this->log('✔ Installazione completata in ' . gmdate('i\m s\s', $dur) . '.');
    }

    /**
     * `magento/sample-data-media` annida le immagini quando la cartella esiste già (pub/media/downloadable/downloadable…):
     * riporta tutto al suo posto. Va fatto prima di setup:install, perché i dati di esempio le leggono mentre si caricano.
     */
    private function fixSampleMedia(): void
    {
        $t = $this->cfg['target'];
        $media = $t . '/vendor/' . platform_of($this->cfg['platform'])['vendor'] . '/sample-data-media';
        if (!is_dir($media)) {
            return;
        }
        foreach (scandir($media) ?: [] as $d) {
            if ($d === '.' || $d === '..' || !is_dir("$media/$d") || $d[0] === '.') {
                continue;
            }
            $to = "$t/pub/media/$d";
            if (!is_dir($to)) {
                $this->copyTree("$media/$d", $to);
                $this->log("Immagini di esempio copiate in $to");
            } elseif (is_dir("$to/$d")) {
                $this->copyTree("$media/$d", $to);
                rrmdir("$to/$d");
                $this->log("Immagini di esempio riportate al loro posto in $to (cartella annidata rimossa)");
            }
        }
    }

    private function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? $this->copyTree("$from/$f", "$to/$f") : @copy("$from/$f", "$to/$f");
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Esegue bin/magento; se $fatal è true un errore interrompe l'installazione. */
    private function mage(string $args, bool $fatal = true): bool
    {
        $code = $this->run(escapeshellarg($this->php) . ' -d memory_limit=-1 bin/magento ' . $args . ' --no-interaction', $this->cfg['target'], $this->phpIniEnv());
        if ($code !== 0) {
            if ($fatal) {
                throw new StepFailure("bin/magento $args è fallito (codice $code).");
            }
            $this->log("⚠ bin/magento $args ha restituito $code, continuo.");
        }
        return $code === 0;
    }

    /**
     * `-d memory_limit=-1` vale solo per il processo diretto: deploy:mode:set production lancia
     * setup:di:compile e static-content-deploy come figli, che si fermerebbero al limite del php.ini.
     * Un ini aggiuntivo (via PHP_INI_SCAN_DIR) lo toglie anche a loro.
     */
    private function phpIniEnv(): array
    {
        $dir = $this->dir . '/php-ini';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            file_put_contents($dir . '/memory.ini', "memory_limit=-1\n");
        }
        $scan = [];
        if (preg_match('/Scan for additional \.ini files in: "?([^"\n]+)"?/', sh(escapeshellarg($this->php) . ' --ini 2>&1'), $m) && is_dir(trim($m[1]))) {
            $scan[] = trim($m[1]);
        }
        $scan[] = $dir;
        return ['PHP_INI_SCAN_DIR' => implode(':', $scan)];
    }

    private function step(string $id, callable $fn): void
    {
        $this->setStep($id, 'running');
        $this->log('');
        $this->log('▶ ' . $this->stepLabel($id));
        $t = microtime(true);
        try {
            $fn();
        } catch (Throwable $e) {
            $this->setStep($id, 'failed');
            throw $e;
        }
        $this->setStep($id, 'ok', null, sprintf('%.0fs', microtime(true) - $t));
    }

    private function stepLabel(string $id): string
    {
        foreach ($this->status['steps'] as $s) {
            if ($s['id'] === $id) {
                return $s['label'];
            }
        }
        return $id;
    }

    private function setStep(string $id, string $state, ?float $progress = null, ?string $detail = null): void
    {
        foreach ($this->status['steps'] as &$s) {
            if ($s['id'] === $id) {
                $s['state'] = $state;
                $s['progress'] = $state === 'ok' ? 1.0 : $progress;
                if ($detail !== null) {
                    $s['detail'] = $detail;
                }
            }
        }
        unset($s);
        $this->save();
    }

    private function detail(string $id, string $text): void
    {
        $this->setStep($id, 'running', null, $text);
    }

    private function progress(string $id, int $done, int $total, string $unit): void
    {
        if ($done === $this->lastProgressLogged) {
            return;
        }
        $this->lastProgressLogged = $done;
        if ($done % 5 === 0 || $done === $total) {
            $this->setStep($id, 'running', $total > 0 ? min(1, $done / $total) : null, $total > 0 ? "$done / $total $unit" : "$done $unit");
        }
    }

    private function log(string $line): void
    {
        $line = str_replace($this->secrets, '••••••', $line);
        file_put_contents($this->dir . '/log.txt', ($line === '' ? '' : '[' . date('H:i:s') . '] ') . $line . "\n", FILE_APPEND);
    }

    private function save(): void
    {
        $tmp = $this->dir . '/status.json.tmp';
        file_put_contents($tmp, json_encode($this->status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->dir . '/status.json');
    }

    /** A job concluso non servono più le password in chiaro. */
    private function scrub(): void
    {
        @unlink($this->dir . '/job.json');
        rrmdir($this->dir . '/composer-home');
    }

    private function cancelled(): bool
    {
        return file_exists($this->dir . '/cancel');
    }

    /** Esegue un comando in streaming: ogni riga va nel log, $onLine la riceve per estrarre l'avanzamento. */
    private function run(string $cmd, string $cwd, array $env = [], ?callable $onLine = null): int
    {
        $this->log('$ ' . preg_replace('/\s+/', ' ', mb_strimwidth($cmd, 0, 260, '…')));
        $p = proc_open('exec ' . $cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, shell_env($env));
        if (!is_resource($p)) {
            throw new StepFailure('Impossibile eseguire il comando.');
        }
        foreach ([1, 2] as $i) {
            stream_set_blocking($pipes[$i], false);
        }
        $buf = [1 => '', 2 => ''];
        $emit = function (string $line) use ($onLine) {
            $line = rtrim($line);
            if ($line === '') {
                return;
            }
            // Le righe di avanzamento di setup:install sono tante: le salto nel log (restano nello stato).
            $isProgress = (bool)preg_match('/^\[Progress: \d+ \/ \d+\]/', $line);
            if ($onLine) {
                $onLine($line);
            }
            if (!$isProgress) {
                $this->log($line);
            }
        };
        while (true) {
            $read = array_filter([$pipes[1] ?? null, $pipes[2] ?? null]);
            if ($read) {
                $w = $e = null;
                @stream_select($read, $w, $e, 1);
                foreach ($read as $s) {
                    $i = $s === $pipes[1] ? 1 : 2;
                    $chunk = fread($s, 65536);
                    if ($chunk !== false && $chunk !== '') {
                        $buf[$i] .= $chunk;
                        while (preg_match('/^(.*?)[\r\n]+(.*)$/s', $buf[$i], $m)) {
                            $emit($m[1]);
                            $buf[$i] = $m[2];
                        }
                    }
                }
            }
            if ($this->cancelled()) {
                proc_terminate($p, 15);
                sleep(1);
                proc_terminate($p, 9);
                proc_close($p);
                throw new StepFailure('cancelled');
            }
            $st = proc_get_status($p);
            if (!$st['running']) {
                foreach ([1, 2] as $i) {
                    $rest = (string)stream_get_contents($pipes[$i]);
                    $buf[$i] .= $rest;
                    foreach (preg_split('/[\r\n]+/', $buf[$i]) as $l) {
                        $emit($l);
                    }
                }
                $exit = $st['exitcode'];
                break;
            }
        }
        proc_close($p);
        return (int)$exit;
    }
}

$id = $argv[1] ?? '';
if (!valid_job_id($id) || !is_file(job_dir($id) . '/job.json')) {
    fwrite(STDERR, "Job non valido\n");
    exit(2);
}
exit((new Worker($id))->main());
