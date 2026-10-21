<?php
declare(strict_types=1);

/** Estensioni richieste comunque da Magento, usate se il repository non è raggiungibile. */
const FALLBACK_EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'gd', 'hash', 'iconv', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'simplexml', 'soap', 'sodium', 'xsl', 'zip'];

/** Prima versione di Magento proposta: le precedenti non girano sui PHP ancora supportati. */
const MAGENTO_MIN_VERSION = '2.4.6';

/**
 * Versioni stabili di Magento Open Source (2.4.x e relative patch -pN) con i requisiti PHP/estensioni,
 * lette dai metadati di magento/product-community-edition (cache 1 ora). Se il repository non risponde
 * si usa l'ultima copia in cache o, in mancanza, un elenco minimo.
 */
function magento_versions(bool $refresh = false): array
{
    $cache = app_tmp('cache') . '/versions.json';
    if (!$refresh && is_file($cache) && filemtime($cache) > time() - 3600) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data) && $data) {
            return ['source' => 'cache', 'versions' => $data];
        }
    }

    $res = http_get(MAGENTO_REPO . 'p2/magento/product-community-edition.json', 10);
    $versions = [];
    if ($res['ok'] && $res['code'] === 200) {
        $json = json_decode($res['body'], true);
        $list = $json['packages']['magento/product-community-edition'] ?? [];
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
            // Solo release e patch ufficiali (2.4.8, 2.4.8-p3): niente alpha, beta, RC.
            if (!preg_match('/^\d+\.\d+\.\d+(-p\d+)?$/', $v) || version_compare($v, MAGENTO_MIN_VERSION, '<')) {
                continue;
            }
            $req = $prev['require'] ?? [];
            $exts = [];
            foreach (array_keys($req) as $name) {
                if (str_starts_with($name, 'ext-')) {
                    $exts[] = substr($name, 4);
                }
            }
            $versions[$v] = [
                'version' => $v,
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
        'version' => '2.4.8-p5', 'php' => '~8.2.0||~8.3.0||~8.4.0', 'extensions' => FALLBACK_EXTENSIONS, 'time' => null,
    ]]];
}

function find_version(string $v): ?array
{
    foreach (magento_versions()['versions'] as $row) {
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

/** Pacchetti dei dati di esempio: hanno una numerazione propria, quindi composer sceglie l'ultima compatibile. */
function sample_data_packages(): array
{
    $mods = ['bundle', 'catalog-rule', 'catalog', 'cms', 'configurable', 'customer', 'downloadable', 'grouped-product', 'msrp',
        'offline-shipping', 'product-links', 'review', 'sales-rule', 'sales', 'swatches', 'tax', 'theme', 'widget', 'wishlist'];
    $pkgs = [];
    foreach ($mods as $m) {
        $pkgs[] = "magento/module-$m-sample-data:*";
    }
    $pkgs[] = 'magento/sample-data-media:*';
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
