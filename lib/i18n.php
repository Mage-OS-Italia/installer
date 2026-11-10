<?php
declare(strict_types=1);

/**
 * Traduzioni lato server. Il testo italiano è la chiave: t('Frase in italiano') restituisce l'inglese
 * se la lingua attiva è "en" e la frase è nei dizionari lib/lang/en.*.php, altrimenti l'italiano stesso.
 * I segnaposto sono {nome}: t('Trovati {n} file', ['n' => 3]).
 */
const LANGS = ['it', 'en'];

function set_lang(string $l): void
{
    $GLOBALS['__lang'] = in_array($l, LANGS, true) ? $l : 'it';
}

/** Lingua attiva: quella impostata (worker) o il cookie "lang"; in mancanza, quella del browser (it se italiano, altrimenti en). */
function current_lang(): string
{
    if (!isset($GLOBALS['__lang'])) {
        $c = (string)($_COOKIE['lang'] ?? '');
        if (in_array($c, LANGS, true)) {
            $GLOBALS['__lang'] = $c;
        } else {
            $GLOBALS['__lang'] = str_starts_with(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'it')), 'it') ? 'it' : 'en';
        }
    }
    return $GLOBALS['__lang'];
}

function lang_dict(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        foreach (glob(__DIR__ . '/lang/en.*.php') ?: [] as $f) {
            $d = array_merge($d, (array)require $f);
        }
    }
    return $d;
}

function t(string $it, array $vars = []): string
{
    $s = current_lang() === 'en' ? (lang_dict()[$it] ?? $it) : $it;
    if ($vars) {
        $r = [];
        foreach ($vars as $k => $v) {
            $r['{' . $k . '}'] = (string)$v;
        }
        $s = strtr($s, $r);
    }
    return $s;
}

/** Traduce in profondità i campi di testo indicati (per i cataloghi JSON, scritti in italiano). */
function t_fields(array $data, array $keys): array
{
    foreach ($data as $k => $v) {
        if (is_array($v) && in_array($k, $keys, true) && $v === array_values($v) && array_filter($v, 'is_string') === $v) {
            $data[$k] = array_map('t', $v); // elenco di frasi (es. highlights)
        } elseif (is_array($v)) {
            $data[$k] = t_fields($v, $keys);
        } elseif (is_string($v) && in_array($k, $keys, true)) {
            $data[$k] = t($v);
        }
    }
    return $data;
}
