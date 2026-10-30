<?php
/**
 * Lancia bin/magento con gli argomenti letti da un file (0600) invece che dalla riga di comando:
 * password di database, admin e ricerca non compaiono così in `ps`. Uso: php magento-cli.php <file-argomenti.json>
 * Il file contiene {"cwd": "<cartella del progetto>", "args": [...]} e viene eliminato appena letto.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(2);
}
$job = json_decode((string)@file_get_contents($argv[1]), true);
@unlink($argv[1]);
if (!is_array($job) || !is_dir($job['cwd'] ?? '')) {
    fwrite(STDERR, "Argomenti non leggibili\n");
    exit(2);
}
chdir($job['cwd']);
$argv = $_SERVER['argv'] = array_merge(['bin/magento'], array_map('strval', (array)$job['args']));
$argc = $_SERVER['argc'] = count($argv);
require $job['cwd'] . '/bin/magento';
