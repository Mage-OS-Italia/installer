<?php
declare(strict_types=1);

/** Estensioni richieste comunque da Magento/Mage-OS, usate se il repository non è raggiungibile. */
const FALLBACK_EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'gd', 'hash', 'iconv', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'simplexml', 'soap', 'sodium', 'xsl', 'zip'];

/**
 * Piattaforme installabili. `min` è la prima versione proposta (le precedenti non girano sui PHP ancora
 * supportati), `regex` lascia passare solo release e patch ufficiali, niente alpha, beta, RC.
 */
const PLATFORMS = [
    'magento' => ['name' => 'Magento Open Source', 'repo' => MAGENTO_REPO, 'vendor' => 'magento', 'min' => '2.4.6',
        'regex' => '/^\d+\.\d+\.\d+(-p\d+)?$/', 'fallback' => '2.4.8-p5', 'fallback_php' => '~8.2.0||~8.3.0||~8.4.0'],
    'mageos' => ['name' => 'Mage-OS', 'repo' => MAGEOS_REPO, 'vendor' => 'mage-os', 'min' => '0.0.0',
        'regex' => '/^\d+\.\d+\.\d+$/', 'fallback' => '3.6.0', 'fallback_php' => '~8.3.0||~8.4.0||~8.5.0'],
];

function platform_of(string $id): ?array
{
    return PLATFORMS[$id] ?? null;
}

/**
 * Versioni stabili della piattaforma con i relativi requisiti PHP/estensioni, lette dai metadati di
 * <vendor>/product-community-edition (cache 1 ora). Se il repository non risponde si usa l'ultima copia
 * in cache o, in mancanza, un elenco minimo.
 */
function platform_versions(string $platform, bool $refresh = false): array
{
    $p = PLATFORMS[$platform];
    $pkg = $p['vendor'] . '/product-community-edition';
    $cache = app_tmp('cache') . "/versions-$platform-v3.json";
    if (!$refresh && is_file($cache) && filemtime($cache) > time() - 3600) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data) && $data) {
            return ['source' => 'cache', 'versions' => $data];
        }
    }

    $res = http_get($p['repo'] . "p2/$pkg.json", 10);
    $versions = [];
    if ($res['ok'] && $res['code'] === 200) {
        $json = json_decode($res['body'], true);
        $list = $json['packages'][$pkg] ?? [];
        $prev = [];
        foreach ($list as $entry) {
            // Metadati "minified": ogni voce eredita i campi della precedente.
            foreach ($entry as $k => $val) {
                if ($val === '__unset') {
                    unset($prev[$k]);
                } else {
                    $prev[$k] = $val;
                }
            }
            $v = (string)($prev['version'] ?? '');
            if (!preg_match($p['regex'], $v) || version_compare($v, $p['min'], '<')) {
                continue;
            }
            $req = $prev['require'] ?? [];
            $exts = [];
            foreach (array_keys($req) as $name) {
                if (str_starts_with($name, 'ext-')) {
                    $exts[] = substr($name, 4);
                }
            }
            // Versioni esatte richieste per pacchetti di terzi: servono a escluderli con "replace" senza
            // violare i "conflict" del prodotto (es. paypal/module-braintree nelle patch recenti di 2.4.7).
            $pins = [];
            foreach ($req as $name => $constraint) {
                if (!preg_match('#^(php|ext-|lib-|magento/|mage-os/)#', $name) && preg_match('/^\d[\w.\-]*$/', (string)$constraint)) {
                    $pins[$name] = $constraint;
                }
            }
            // `conflict` senza una versione fissata nei require (es. paypal/module-braintree >=4.7.0 su 2.4.7-p6…p9):
            // per escludere il pacchetto con "replace" basta limitarlo alle versioni che non confliggono.
            // Solo fino a 2.4.7: da 2.4.8 il metapacchetto del prodotto richiede Braintree ^4.8 e un replace "<4.7.0" non lo soddisfa.
            foreach (version_compare((string)preg_replace('/-p\d+$/', '', $v), '2.4.8', '<') ? (array)($prev['conflict'] ?? []) : [] as $name => $constraint) {
                if (!isset($pins[$name]) && !isset($req[$name]) && preg_match('/^>=\s*(\d[\w.\-]*)$/', trim((string)$constraint), $cm)) {
                    $pins[$name] = '<' . $cm[1];
                }
            }
            // Moduli Mage-OS che il prodotto richiede già (a versione fissa): il catalogo non deve riaggiungerli.
            $included = [];
            foreach ($req as $name => $constraint) {
                if (str_starts_with($name, 'mage-os/')) {
                    $included[] = $name;
                }
            }
            $versions[$v] = [
                'version' => $v,
                'pins' => $pins,
                'included' => $included,
                'es7' => es7_supported($platform, $v),
                'php' => (string)($req['php'] ?? ''),
                'extensions' => $exts,
                'time' => isset($prev['time']) ? substr((string)$prev['time'], 0, 10) : null,
            ];
        }
        uksort($versions, fn($a, $b) => version_compare($b, $a));
        $versions = array_values($versions);
        if ($versions) {
            @file_put_contents($cache, json_encode($versions));
            return ['source' => 'live', 'versions' => $versions];
        }
    }

    if (is_file($cache)) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data) && $data) {
            return ['source' => 'stale', 'versions' => $data];
        }
    }
    return ['source' => 'fallback', 'versions' => [[
        'version' => $p['fallback'], 'php' => $p['fallback_php'], 'extensions' => FALLBACK_EXTENSIONS, 'time' => null,
        'pins' => [], 'included' => [], 'es7' => es7_supported($platform, $p['fallback']),
    ]]];
}

/** Il modulo Elasticsearch7 c'è fino a Magento 2.4.7 (Mage-OS 1.x); dopo restano Elasticsearch 8 e OpenSearch. */
function es7_supported(string $platform, string $version): bool
{
    return $platform === 'mageos'
        ? version_compare($version, '2.0.0', '<')
        : version_compare((string)preg_replace('/-p\d+$/', '', $version), '2.4.8', '<');
}

function find_version(string $platform, string $v): ?array
{
    if (!isset(PLATFORMS[$platform])) {
        return null;
    }
    foreach (platform_versions($platform)['versions'] as $row) {
        if ($row['version'] === $v) {
            return $row;
        }
    }
    return null;
}

/** Minimi per database e motore di ricerca. Non sono nei metadati composer, quindi sono soglie prudenti. */
function service_minimums(string $version): array
{
    return [
        'mysql' => '8.0.0',
        'mariadb' => '10.6.0',
        'opensearch' => '2.0.0',
        'elasticsearch' => '7.17.0',
    ];
}

function distros(): array
{
    $out = [];
    foreach (glob(APP_ROOT . '/distros/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && !empty($d['id']) && preg_match('/^[a-z0-9_-]+$/', $d['id'])) {
            $d['order'] = $d['order'] ?? 100;
            $out[$d['id']] = $d;
        }
    }
    uasort($out, fn($a, $b) => $a['order'] <=> $b['order']);
    return $out;
}

/**
 * Pacchetti dei dati di esempio. Magento li numera a parte, quindi composer sceglie l'ultima compatibile;
 * Mage-OS li pubblica alla stessa versione della release.
 */
function sample_data_packages(string $platform, string $version): array
{
    $mods = ['bundle', 'catalog-rule', 'catalog', 'cms', 'configurable', 'customer', 'downloadable', 'grouped-product', 'msrp',
        'offline-shipping', 'product-links', 'review', 'sales-rule', 'sales', 'swatches', 'tax', 'theme', 'widget', 'wishlist'];
    $vendor = PLATFORMS[$platform]['vendor'];
    $constraint = $platform === 'mageos' ? $version : '*';
    $pkgs = [];
    foreach ($mods as $m) {
        $pkgs[] = "$vendor/module-$m-sample-data:$constraint";
    }
    $pkgs[] = "$vendor/sample-data-media:$constraint";
    return $pkgs;
}

/** Moduli Mage-OS opzionali (catalog/mageos.json), selezionabili con qualsiasi distro. */
function mageos_modules(): array
{
    $list = json_decode((string)@file_get_contents(APP_ROOT . '/catalog/mageos.json'), true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $m) {
        if (is_array($m) && preg_match('#^mage-os/[a-z0-9_.-]+$#', (string)($m['name'] ?? '')) && !empty($m['constraint'])) {
            $out[] = $m;
        }
    }
    return $out;
}

/** Temi del negozio e relativi checkout (catalog/frontend.json). */
function frontends(): array
{
    $list = json_decode((string)@file_get_contents(APP_ROOT . '/catalog/frontend.json'), true);
    return is_array($list) ? $list : [];
}

/**
 * Ricava progetto e chiave dal blocco incollato dall'area licenze di Hyvä: i comandi `composer config`,
 * l'URL del repository o un auth.json. Il resto del testo viene scartato.
 */
function parse_hyva_keys(string $text): ?array
{
    $host = 'hyva-themes\.repo\.packagist\.com';
    if (!preg_match('#' . $host . '/([A-Za-z0-9][A-Za-z0-9_.-]*)#', $text, $p)) {
        return null;
    }
    if (!preg_match('#' . $host . '[\'"]?\s+[\'"]?token[\'"]?\s+[\'"]?([A-Za-z0-9_-]{8,})#', $text, $k)
        && !preg_match('#"password"\s*:\s*"([A-Za-z0-9_-]{8,})"#', $text, $k)) {
        return null;
    }
    return ['project' => $p[1], 'key' => $k[1]];
}

/**
 * Ricerca con MySQL (modulo Swissup "legacy") al posto di OpenSearch, per i cataloghi piccoli:
 * è la scelta fatta su threecommerce. Sopra `max_products` serve OpenSearch/Elasticsearch.
 */
function mysql_search(): array
{
    $c = json_decode((string)@file_get_contents(APP_ROOT . '/catalog/search.json'), true);
    return is_array($c) ? $c : ['max_products' => 0, 'engine' => '', 'packages' => [], 'replace' => []];
}

/** Lingue, valute e fusi orari proposti dal form. */
function locale_options(): array
{
    return [
        'languages' => [
            'it_IT' => 'Italiano', 'en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'de_DE' => 'Deutsch', 'fr_FR' => 'Français',
            'es_ES' => 'Español', 'pt_PT' => 'Português', 'pt_BR' => 'Português (Brasil)', 'nl_NL' => 'Nederlands', 'pl_PL' => 'Polski',
            'sv_SE' => 'Svenska', 'da_DK' => 'Dansk', 'nb_NO' => 'Norsk', 'fi_FI' => 'Suomi', 'cs_CZ' => 'Čeština', 'ro_RO' => 'Română',
            'el_GR' => 'Ελληνικά', 'hu_HU' => 'Magyar', 'tr_TR' => 'Türkçe',
        ],
        'currencies' => [
            'EUR' => 'Euro', 'USD' => 'Dollaro USA', 'GBP' => 'Sterlina', 'CHF' => 'Franco svizzero', 'SEK' => 'Corona svedese',
            'DKK' => 'Corona danese', 'NOK' => 'Corona norvegese', 'PLN' => 'Złoty', 'CZK' => 'Corona ceca', 'HUF' => 'Fiorino',
            'RON' => 'Leu', 'BRL' => 'Real', 'CAD' => 'Dollaro canadese', 'AUD' => 'Dollaro australiano', 'TRY' => 'Lira turca',
        ],
        'timezones' => DateTimeZone::listIdentifiers(),
    ];
}
