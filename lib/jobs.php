<?php
declare(strict_types=1);

function job_dir(string $id): string
{
    return app_tmp('jobs') . '/' . $id;
}

function valid_job_id(string $id): bool
{
    return (bool)preg_match('/^[a-f0-9]{16}$/', $id);
}

/**
 * Valida il payload del form e lo normalizza. Ritorna [config, errori].
 * I pacchetti composer vengono sempre ricavati dalla definizione della distro: l'input
 * può solo scegliere tra quelli previsti, mai introdurne di nuovi.
 */
function validate_install(array $in): array
{
    $err = [];
    $s = fn(string $k): string => trim((string)($in[$k] ?? ''));

    $platform = platform_of($s('platform'));
    if (!$platform) {
        $err[] = 'Piattaforma non valida.';
    }
    $version = $platform ? find_version($s('platform'), $s('version')) : null;
    if (!$version) {
        $err[] = 'Versione non valida.';
    }
    if ($version && ($version['php'] ?? '') !== '') {
        $cli = php_cli_info();
        if (!$cli) {
            $err[] = 'PHP da riga di comando non trovato: servono composer e bin/magento.';
        } elseif (!php_satisfies($cli['version'], $version['php'])) {
            $err[] = 'Il PHP da riga di comando (' . $cli['version'] . ') non è supportato da ' . $platform['name'] . ' ' . $version['version']
                . ' (richiede ' . preg_replace('/~?(\d+\.\d+)\.0/', '$1.x', str_replace('||', ' · ', $version['php'])) . '). Scegli una versione più recente o un altro PHP.';
        }
    }
    $distros = distros();
    $distro = $distros[$s('distro')] ?? null;
    if (!$distro) {
        $err[] = 'Distro non valida.';
    }

    $target = rtrim($s('target'), '/');
    if ($target === '' || $target[0] !== '/' || str_contains($target, '..') || preg_match('/[\0\n\r]/', $target)) {
        $err[] = 'La cartella di destinazione deve essere un percorso assoluto.';
    } elseif ($target === '/' || $target === home_dir() || str_starts_with(APP_ROOT . '/', $target . '/')) {
        $err[] = 'La cartella di destinazione non può contenere l\'installer.';
    } elseif (str_starts_with($target . '/', rtrim(APP_ROOT, '/') . '/')) {
        $err[] = 'La cartella di destinazione non può stare dentro quella dell\'installer (verrebbe cancellata con lui).';
    } elseif (is_dir($target) && count(array_diff(scandir($target) ?: [], ['.', '..'])) > 0) {
        $err[] = 'La cartella di destinazione non è vuota.';
    } else {
        $parent = is_dir($target) ? $target : dirname($target);
        while ($parent !== '/' && !is_dir($parent)) {
            $parent = dirname($parent);
        }
        if (!is_writable($parent)) {
            $err[] = "Il processo PHP non può scrivere in $parent: scegli un'altra cartella o correggi i permessi.";
        }
    }

    $baseUrl = $s('base_url');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
        $err[] = 'Base URL non valido (es. http://mio-negozio.test/).';
    }
    $baseUrl = rtrim($baseUrl, '/') . '/';

    $front = $s('backend_frontname') ?: 'admin';
    if (!preg_match('/^[A-Za-z0-9_-]{2,}$/', $front)) {
        $err[] = 'Il percorso admin può contenere solo lettere, numeri, - e _.';
    }

    $loc = locale_options();
    $lang = $s('language');
    if (!isset($loc['languages'][$lang])) {
        $err[] = 'Lingua non valida.';
    }
    $cur = $s('currency');
    if (!isset($loc['currencies'][$cur])) {
        $err[] = 'Valuta non valida.';
    }
    $tz = $s('timezone');
    if (!in_array($tz, $loc['timezones'], true)) {
        $err[] = 'Fuso orario non valido.';
    }

    $db = ['host' => $s('db_host'), 'name' => $s('db_name'), 'user' => $s('db_user'), 'password' => (string)($in['db_password'] ?? ''), 'prefix' => $s('db_prefix')];
    if (!preg_match('#^[\w.\-:/]+$#', $db['host'])) {
        $err[] = 'Host del database non valido.';
    }
    if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $db['name'])) {
        $err[] = 'Nome database non valido (lettere, numeri, _ e $).';
    }
    if ($db['user'] === '') {
        $err[] = 'Utente database mancante.';
    }
    if ($db['prefix'] !== '' && !preg_match('/^[A-Za-z0-9_]{1,16}$/', $db['prefix'])) {
        $err[] = 'Prefisso tabelle non valido.';
    }

    // Catalogo piccolo: ricerca MySQL, senza OpenSearch (salvo richiesta esplicita di usarlo).
    $ms = mysql_search();
    $products = (int)($in['products'] ?? 0);
    if ($products < 1) {
        $err[] = 'Indica quanti prodotti prevedi di avere a catalogo.';
    }
    $useMysql = $products >= 1 && $products <= (int)$ms['max_products'] && empty($in['use_opensearch']);
    if ($useMysql) {
        $search = ['engine' => $ms['engine'], 'host' => '', 'port' => 0, 'prefix' => '', 'user' => '', 'password' => ''];
    } else {
        $engine = $s('search_engine');
        if (!in_array($engine, ['opensearch', 'elasticsearch8', 'elasticsearch7'], true)) {
            $err[] = 'Motore di ricerca non valido.';
        }
        $search = ['engine' => $engine, 'host' => $s('search_host'), 'port' => (int)($in['search_port'] ?? 9200),
            'prefix' => $s('search_prefix') ?: 'magento2', 'user' => $s('search_user'), 'password' => (string)($in['search_password'] ?? '')];
        if (!preg_match('/^[\w.\-]+$/', $search['host']) || $search['port'] < 1 || $search['port'] > 65535) {
            $err[] = 'Host o porta del motore di ricerca non validi.';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $search['prefix'])) {
            $err[] = 'Prefisso indice di ricerca non valido.';
        }
    }

    $admin = ['firstname' => $s('admin_firstname'), 'lastname' => $s('admin_lastname'), 'email' => $s('admin_email'),
        'user' => $s('admin_user'), 'password' => (string)($in['admin_password'] ?? '')];
    if ($admin['firstname'] === '' || $admin['lastname'] === '') {
        $err[] = 'Nome e cognome dell\'amministratore sono obbligatori.';
    }
    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Email amministratore non valida.';
    }
    if (!preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $admin['user'])) {
        $err[] = 'Username amministratore non valido (almeno 3 caratteri: lettere, numeri, . _ - @).';
    }
    if (strlen($admin['password']) < 7 || !preg_match('/[A-Za-z]/', $admin['password']) || !preg_match('/\d/', $admin['password'])) {
        $err[] = 'La password admin deve avere almeno 7 caratteri, con lettere e numeri.';
    }

    $mode = $s('deploy_mode') ?: 'developer';
    if (!in_array($mode, ['developer', 'production'], true)) {
        $err[] = 'Modalità di deploy non valida.';
    }

    // Pacchetti: solo quelli definiti dalla distro o nel catalogo dei moduli Mage-OS.
    $packages = [];
    $repos = [];
    $selected = array_flip(array_map('strval', (array)($in['packages'] ?? [])));
    $mageos = array_flip(array_map('strval', (array)($in['mageos'] ?? [])));
    if ($distro) {
        $byRepo = [];
        foreach ($distro['repositories'] ?? [] as $r) {
            $byRepo[$r['id']] = $r;
        }
        foreach ($distro['packages'] ?? [] as $pkg) {
            if (!empty($pkg['required']) || isset($selected[$pkg['name']])) {
                $packages[] = $pkg['name'] . ':' . $pkg['constraint'];
                if (!empty($pkg['repository']) && isset($byRepo[$pkg['repository']])) {
                    $repos[$pkg['repository']] = $byRepo[$pkg['repository']];
                }
            }
        }
        $known = array_column(mageos_modules(), 'name');
        foreach (array_keys($mageos) as $n) {
            if (!in_array((string)$n, $known, true)) {
                $err[] = 'Modulo Mage-OS sconosciuto: ' . $n . '.';
            }
        }
        foreach (mageos_modules() as $m) {
            if (!isset($mageos[$m['name']])) {
                continue;
            }
            if (in_array($distro['id'], (array)($m['incompatible_with'] ?? []), true)) {
                $err[] = $m['label'] . ' non è compatibile con la distro ' . $distro['name'] . '.';
                continue;
            }
            if ($useMysql && !empty($m['needs_opensearch'])) {
                $err[] = $m['label'] . ' richiede OpenSearch: con la ricerca MySQL non è installabile.';
                continue;
            }
            // Già incluso dalla distro (es. RMA in quella italiana): non lo duplichiamo.
            if (!preg_grep('#^' . preg_quote($m['name'], '#') . ':#', $packages)) {
                $packages[] = $m['name'] . ':' . $m['constraint'];
            }
        }
    }

    // Tema e checkout: pacchetti, repository e replace vengono sempre dal catalogo.
    $frontendId = $s('frontend') ?: 'luma';
    $fe = frontends()[$frontendId] ?? null;
    $replace = [];
    $feCreds = [];
    $checkoutId = '';
    if (!$fe) {
        $err[] = 'Tema non valido.';
    } else {
        $co = null;
        foreach ($fe['checkouts'] as $c) {
            if ($c['id'] === $s('checkout')) {
                $co = $c;
            }
        }
        if (!$co) {
            $err[] = 'Checkout non valido per il tema scelto.';
        } else {
            $checkoutId = $co['id'];
            foreach (($fe['packages'] ?? []) + $co['packages'] as $n => $v) {
                $packages[] = $n . ':' . $v;
            }
        }
        // I dati di esempio cercano il tema Magento/luma: con quelli Luma e Blank restano installati.
        $replace = !empty($in['sample_data']) ? [] : ($fe['replace'] ?? []);
        if (!empty($fe['repository'])) {
            $keys = parse_hyva_keys((string)($in['hyva_keys'] ?? ''));
            if (!$keys) {
                $err[] = 'Chiavi Hyvä non riconosciute: incolla il blocco copiato dall\'area licenze di Hyvä.';
            } else {
                $r = $fe['repository'];
                $repos[$r['id']] = ['id' => $r['id'], 'name' => 'Hyvä', 'url' => str_replace('{project}', $keys['project'], $r['url']), 'host' => $r['host']];
                $feCreds[$r['host']] = ['username' => 'token', 'password' => $keys['key']];
            }
        }
    }

    if ($useMysql && $platform) {
        foreach ($ms['packages'] as $n => $v) {
            $packages[] = $n . ':' . $v;
        }
        foreach ($ms['replace'] as $n) {
            $replace[] = str_replace('{vendor}', $platform['vendor'], $n);
        }
    }

    $credentials = $feCreds;
    foreach ($repos as $r) {
        if (isset($feCreds[$r['host']])) {
            continue;
        }
        $c = $in['credentials'][$r['host']] ?? [];
        $u = trim((string)($c['user'] ?? ''));
        $pw = (string)($c['password'] ?? '');
        if ($u === '' || $pw === '') {
            $err[] = 'Inserisci le credenziali per ' . $r['host'] . '.';
        }
        $credentials[$r['host']] = ['username' => $u, 'password' => $pw];
    }

    $config = [
        'platform' => $s('platform'),
        'version' => $version['version'] ?? '',
        'distro' => $distro['id'] ?? '',
        'target' => $target,
        'base_url' => $baseUrl,
        'backend_frontname' => $front,
        'store_name' => $s('store_name') ?: ($platform['name'] ?? 'Magento') . ' Store',
        'language' => $lang, 'currency' => $cur, 'timezone' => $tz,
        'db' => $db,
        'create_db' => !empty($in['create_db']),
        'cleanup_db' => !empty($in['cleanup_db']),
        'allow_unsupported_db' => !empty($in['allow_unsupported_db']),
        'search' => $search,
        'products' => $products,
        'admin' => $admin,
        'deploy_mode' => $mode,
        'sample_data' => !empty($in['sample_data']),
        'italian_tax' => !empty($in['italian_tax']),
        'disable_2fa' => !empty($in['disable_2fa']),
        'install_cron' => !empty($in['install_cron']),
        'save_auth' => !empty($in['save_auth']),
        'packages' => $packages,
        'repositories' => array_values($repos),
        'credentials' => $credentials,
        'frontend' => ['id' => $frontendId, 'checkout' => $checkoutId, 'theme_path' => $fe['theme_path'] ?? ''],
        'distro_config' => (array)($distro['config'] ?? []),
        'replace' => array_values(array_filter(array_merge($replace, (array)($distro[$s('platform') === 'mageos' ? 'replace_mageos' : 'replace'] ?? [])), fn($n) => is_string($n) && preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $n))),
    ];
    $config['replace_pins'] = $version ? array_intersect_key((array)($version['pins'] ?? []), array_flip($config['replace'])) : [];
    return [$config, $err];
}

/** Id di un'installazione ancora in corso (un worker morto non conta), o null. */
function running_job(): ?string
{
    foreach (glob(dirname(job_dir('x')) . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $id = basename($d);
        if (valid_job_id($id) && in_array(job_status($id, 0)['status']['state'] ?? '', ['running', 'queued'], true)) {
            return $id;
        }
    }
    return null;
}

function start_install(array $config): array
{
    if ($busy = running_job()) {
        return ['error' => 'Un\'installazione è già in corso: attendi che finisca o annullala.', 'job' => $busy, 'busy' => true];
    }
    $php = find_php_cli();
    if (!$php) {
        return ['error' => 'PHP CLI non trovato: impossibile avviare l\'installazione in background.'];
    }
    $id = bin2hex(random_bytes(8));
    $dir = job_dir($id);
    if (!@mkdir($dir, 0700, true)) {
        return ['error' => 'Impossibile creare la cartella del job in ' . $dir];
    }
    file_put_contents($dir . '/job.json', json_encode($config, JSON_UNESCAPED_SLASHES));
    chmod($dir . '/job.json', 0600);
    file_put_contents($dir . '/status.json', json_encode(['state' => 'queued', 'steps' => [], 'started' => time()]));
    file_put_contents($dir . '/log.txt', '');

    $cmd = 'nohup ' . escapeshellarg($php) . ' -d memory_limit=-1 ' . escapeshellarg(APP_ROOT . '/worker.php') . ' ' . escapeshellarg($id)
        . ' > ' . escapeshellarg($dir . '/worker.out') . ' 2>&1 &';
    $p = proc_open(['/bin/sh', '-c', $cmd], [], $pipes, null, shell_env());
    if (!is_resource($p)) {
        return ['error' => 'Impossibile avviare il processo di installazione.'];
    }
    proc_close($p);
    return ['job' => $id];
}

function job_status(string $id, int $since): array
{
    $dir = job_dir($id);
    if (!valid_job_id($id) || !is_dir($dir)) {
        return ['error' => 'Job inesistente.'];
    }
    $status = json_decode((string)@file_get_contents($dir . '/status.json'), true) ?: ['state' => 'unknown'];
    $log = '';
    $size = (int)@filesize($dir . '/log.txt');
    if ($size > $since) {
        $fh = fopen($dir . '/log.txt', 'rb');
        fseek($fh, $since);
        $log = (string)fread($fh, min($size - $since, 262144));
        fclose($fh);
    }
    // Se il worker è morto senza chiudere lo stato, lo segnaliamo.
    if (in_array($status['state'], ['running', 'queued'], true)) {
        $pid = (int)($status['pid'] ?? 0);
        $stale = $pid > 0 && function_exists('posix_kill') && !@posix_kill($pid, 0);
        $age = time() - (int)@filemtime($dir . '/status.json');
        if ($stale || ($status['state'] === 'queued' && $age > 20)) {
            $out = trim((string)@file_get_contents($dir . '/worker.out'));
            $status['state'] = 'failed';
            $status['error'] = 'Il processo di installazione si è interrotto inaspettatamente.' . ($out !== '' ? "\n" . substr($out, -800) : '');
        }
    }
    return ['status' => $status, 'log' => $log, 'offset' => $since + strlen($log), 'size' => $size];
}
