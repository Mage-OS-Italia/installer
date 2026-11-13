<?php
declare(strict_types=1);

/**
 * Demo: privacy policy e cookie policy (?p=privacy, ?p=cookie), in italiano e in inglese.
 * Titolare, ID di GA4 e date stanno qui e in DEMO_GA4_ID (lib/demo.php).
 */

const POLICY_OWNER = ['name' => 'Gianluca Porta', 'email' => 'gporta@gianlucaporta.it'];
const POLICY_UPDATED = ['it' => '13 novembre 2026', 'en' => '13 November 2026'];

function policy_body(string $page, string $lang): array
{
    $o = POLICY_OWNER;
    $mail = '<a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a>';
    $site = h($_SERVER['HTTP_HOST'] ?? 'mage-os-installer.threecommerce.it');
    $cookieRows = $lang === 'en'
        ? [
            ['magento_installer', 'Technical', $site, 'Keeps the demo session (form security, simulated installation progress).', 'End of the browser session'],
            ['lang', 'Technical', $site, 'Remembers the interface language you chose.', '12 months'],
            ['cookie_consent', 'Technical', $site, 'Remembers whether you accepted or rejected analytics cookies.', '6 months'],
            ['_ga', 'Analytics (only with consent)', 'Google Analytics', 'Distinguishes visitors for aggregate statistics.', '2 years'],
            ['_ga_' . str_replace('G-', '', DEMO_GA4_ID ?: 'XXXX'), 'Analytics (only with consent)', 'Google Analytics', 'Keeps the state of the analytics session.', '2 years'],
        ]
        : [
            ['magento_installer', 'Tecnico', $site, 'Mantiene la sessione della demo (sicurezza dei form, avanzamento dell\'installazione simulata).', 'Fine della sessione del browser'],
            ['lang', 'Tecnico', $site, 'Ricorda la lingua dell\'interfaccia che hai scelto.', '12 mesi'],
            ['cookie_consent', 'Tecnico', $site, 'Ricorda se hai accettato o rifiutato i cookie analitici.', '6 mesi'],
            ['_ga', 'Analitico (solo con consenso)', 'Google Analytics', 'Distingue i visitatori per statistiche aggregate.', '2 anni'],
            ['_ga_' . str_replace('G-', '', DEMO_GA4_ID ?: 'XXXX'), 'Analitico (solo con consenso)', 'Google Analytics', 'Mantiene lo stato della sessione di analisi.', '2 anni'],
        ];
    $table = '<div class="policy-table"><table><thead><tr><th>' . ($lang === 'en' ? 'Name</th><th>Type</th><th>Set by</th><th>Purpose</th><th>Duration' : 'Nome</th><th>Tipo</th><th>Impostato da</th><th>Finalità</th><th>Durata') . '</th></tr></thead><tbody>';
    foreach ($cookieRows as $r) {
        $table .= '<tr><td><code>' . h($r[0]) . '</code></td><td>' . h($r[1]) . '</td><td>' . h($r[2]) . '</td><td>' . h($r[3]) . '</td><td>' . h($r[4]) . '</td></tr>';
    }
    $table .= '</tbody></table></div>';
    $prefs = '<p><button type="button" class="btn primary" data-act="cookie-prefs">' . ($lang === 'en' ? 'Change cookie preferences' : 'Modifica le preferenze sui cookie') . '</button></p>';

    if ($page === 'cookie') {
        return $lang === 'en' ? ['Cookie policy', <<<HTML
<p>This page explains which cookies are used by the demo of the Magento / Mage-OS installer published at <b>{$site}</b>. The data controller is <b>{$o['name']}</b> ({$mail}). For everything else, read the <a href="?p=privacy">privacy policy</a>.</p>
<h2>What cookies are</h2>
<p>Cookies are small text files that a site stores in your browser and reads on later visits. Technical cookies are needed for the site to work and do not require consent. Analytics cookies, when they come from a third party and are not anonymised, are set only if you accept them.</p>
<h2>Cookies used</h2>
{$table}
<p>Before you choose, no analytics cookie is set and no request is made to Google: the Google Analytics script is loaded only after you click <b>Accept</b>. If you click <b>Reject</b>, or close the page without choosing, only technical cookies are used. Your choice is asked again after 6 months.</p>
<h2>Google Analytics 4</h2>
<p>With your consent we use Google Analytics 4, provided by Google Ireland Limited, to count visits, the pages viewed and the clicks on “Download the installer”. Google signals and advertising personalisation are disabled, and GA4 does not store IP addresses. Google may process data in the United States: the transfer is based on the EU-U.S. Data Privacy Framework, to which Google LLC adheres, and on the Standard Contractual Clauses. More information: <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google privacy policy</a>, <a href="https://business.safety.google/privacy/" target="_blank" rel="noopener">how Google uses data</a>.</p>
<h2>Changing or withdrawing consent</h2>
<p>You can change your choice at any time with the button below, or from the “Cookie preferences” link at the bottom of the side column. Withdrawing consent deletes the Google Analytics cookies from this site. You can also delete or block cookies from your browser settings.</p>
{$prefs}
<h2>External links</h2>
<p>The “Download the installer” link and the links to GitHub, mage-os.org and Adobe take you to third-party sites, which have their own cookie and privacy policies.</p>
HTML] : ['Cookie policy', <<<HTML
<p>Questa pagina spiega quali cookie usa la demo dell'installer Magento / Mage-OS pubblicata su <b>{$site}</b>. Il titolare del trattamento è <b>{$o['name']}</b> ({$mail}). Per tutto il resto leggi la <a href="?p=privacy">privacy policy</a>.</p>
<h2>Cosa sono i cookie</h2>
<p>I cookie sono piccoli file di testo che un sito salva nel browser e rilegge alle visite successive. I cookie tecnici servono al funzionamento del sito e non richiedono consenso. I cookie analitici, quando sono di terze parti e non anonimizzati, vengono installati solo se li accetti.</p>
<h2>Cookie utilizzati</h2>
{$table}
<p>Prima della tua scelta non viene installato nessun cookie analitico e non parte nessuna richiesta verso Google: lo script di Google Analytics si carica solo dopo il clic su <b>Accetta</b>. Se scegli <b>Rifiuta</b>, o chiudi la pagina senza scegliere, vengono usati solo i cookie tecnici. La scelta ti viene richiesta di nuovo dopo 6 mesi.</p>
<h2>Google Analytics 4</h2>
<p>Con il tuo consenso usiamo Google Analytics 4, fornito da Google Ireland Limited, per contare le visite, le pagine viste e i clic su “Scarica l'installer”. Google Signals e la personalizzazione pubblicitaria sono disattivati, e GA4 non memorizza gli indirizzi IP. Google può trattare i dati negli Stati Uniti: il trasferimento si basa sull'EU-U.S. Data Privacy Framework, a cui Google LLC aderisce, e sulle Clausole Contrattuali Standard. Maggiori informazioni: <a href="https://policies.google.com/privacy?hl=it" target="_blank" rel="noopener">privacy policy di Google</a>, <a href="https://business.safety.google/privacy/" target="_blank" rel="noopener">come Google usa i dati</a>.</p>
<h2>Modificare o revocare il consenso</h2>
<p>Puoi cambiare la tua scelta in qualunque momento con il pulsante qui sotto, oppure dal link “Preferenze cookie” in fondo alla colonna laterale. Revocando il consenso i cookie di Google Analytics vengono cancellati da questo sito. Puoi anche cancellare o bloccare i cookie dalle impostazioni del browser.</p>
{$prefs}
<h2>Link esterni</h2>
<p>Il link “Scarica l'installer” e i link a GitHub, mage-os.org e Adobe portano su siti di terze parti, che hanno le proprie cookie e privacy policy.</p>
HTML];
    }

    return $lang === 'en' ? ['Privacy policy', <<<HTML
<p>This policy describes how personal data is processed when you use the demo of the Magento / Mage-OS installer published at <b>{$site}</b>, pursuant to Articles 13 and 14 of Regulation (EU) 2016/679 (GDPR).</p>
<h2>Data controller</h2>
<p><b>{$o['name']}</b> · email {$mail}</p>
<h2>What the demo is</h2>
<p>The demo lets you go through the installer's wizard without installing anything: the fields are pre-filled with sample data, the checks are simulated and no connection to databases or search engines is made. <b>Do not enter real data</b> (passwords, real emails, database credentials): what you type stays only in your browser and in the temporary demo session, it is not saved in any database or log and it is not sent to third parties.</p>
<h2>Data processed</h2>
<ul>
<li><b>Navigation data.</b> The web server (hosting provider) records, as in any site, technical logs with IP address, date and time, requested page, browser and response code. They are used only for security and to make the service work.</li>
<li><b>Technical cookies.</b> The session cookie and the language and cookie-choice preferences, described in the <a href="?p=cookie">cookie policy</a>.</li>
<li><b>Usage statistics (only with consent).</b> If you accept analytics cookies, Google Analytics 4 collects pseudonymous data about the visit (pages, approximate country and city, device, browser, clicks on “Download the installer”) and shows it to us only in aggregate form.</li>
</ul>
<h2>Purposes and legal bases</h2>
<ul>
<li>Providing the demo and protecting the site: legitimate interest of the controller (Art. 6(1)(f) GDPR).</li>
<li>Usage statistics and counting downloads: your consent (Art. 6(1)(a) GDPR), which you can withdraw at any time without affecting the processing carried out before.</li>
</ul>
<h2>Recipients</h2>
<p>The data is processed by the controller and by the providers that help run the site, as processors: the hosting provider of the server and, only with consent, Google Ireland Limited for Google Analytics. Google may transfer data to the United States, on the basis of the EU-U.S. Data Privacy Framework and the Standard Contractual Clauses. The data is not sold or disseminated.</p>
<h2>Retention</h2>
<p>Server logs are kept for the time set by the hosting provider, generally no more than a few weeks. The demo session ends when you close the browser. Google Analytics data is kept for 14 months, then deleted automatically.</p>
<h2>Your rights</h2>
<p>You can ask the controller for access to your data, rectification, erasure, restriction of processing, portability, and object to processing (Articles 15–22 GDPR), by writing to {$mail}. You can withdraw consent to analytics cookies from the <a href="?p=cookie">cookie policy</a>. You also have the right to lodge a complaint with the supervisory authority: in Italy, the <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">Garante per la protezione dei dati personali</a>.</p>
<h2>The installer you download</h2>
<p>The installer you download from GitHub runs on your own server and sends nothing to the controller: this policy applies only to the online demo.</p>
HTML] : ['Privacy policy', <<<HTML
<p>Questa informativa descrive come vengono trattati i dati personali quando usi la demo dell'installer Magento / Mage-OS pubblicata su <b>{$site}</b>, ai sensi degli articoli 13 e 14 del Regolamento (UE) 2016/679 (GDPR).</p>
<h2>Titolare del trattamento</h2>
<p><b>{$o['name']}</b> · email {$mail}</p>
<h2>Cos'è la demo</h2>
<p>La demo permette di percorrere il wizard dell'installer senza installare nulla: i campi sono precompilati con dati di esempio, i controlli sono simulati e non avviene nessuna connessione a database o motori di ricerca. <b>Non inserire dati reali</b> (password, email vere, credenziali di database): quello che scrivi resta solo nel tuo browser e nella sessione temporanea della demo, non viene salvato in nessun database o log e non viene inviato a terzi.</p>
<h2>Dati trattati</h2>
<ul>
<li><b>Dati di navigazione.</b> Il server web (fornitore di hosting) registra, come in qualunque sito, log tecnici con indirizzo IP, data e ora, pagina richiesta, browser e codice di risposta. Servono solo per la sicurezza e il funzionamento del servizio.</li>
<li><b>Cookie tecnici.</b> Il cookie di sessione e le preferenze di lingua e di scelta sui cookie, descritti nella <a href="?p=cookie">cookie policy</a>.</li>
<li><b>Statistiche d'uso (solo con consenso).</b> Se accetti i cookie analitici, Google Analytics 4 raccoglie dati pseudonimi sulla visita (pagine, paese e città approssimativi, dispositivo, browser, clic su “Scarica l'installer”) e ce li mostra solo in forma aggregata.</li>
</ul>
<h2>Finalità e basi giuridiche</h2>
<ul>
<li>Erogare la demo e proteggere il sito: legittimo interesse del titolare (art. 6, par. 1, lett. f GDPR).</li>
<li>Statistiche d'uso e conteggio dei download: il tuo consenso (art. 6, par. 1, lett. a GDPR), revocabile in qualunque momento senza effetti sui trattamenti già fatti.</li>
</ul>
<h2>Destinatari</h2>
<p>I dati sono trattati dal titolare e dai fornitori che aiutano a gestire il sito, come responsabili del trattamento: il fornitore di hosting del server e, solo con consenso, Google Ireland Limited per Google Analytics. Google può trasferire i dati negli Stati Uniti, sulla base dell'EU-U.S. Data Privacy Framework e delle Clausole Contrattuali Standard. I dati non vengono venduti né diffusi.</p>
<h2>Conservazione</h2>
<p>I log del server sono conservati per il tempo stabilito dal fornitore di hosting, di norma non oltre qualche settimana. La sessione della demo termina alla chiusura del browser. I dati di Google Analytics sono conservati per 14 mesi, poi cancellati automaticamente.</p>
<h2>I tuoi diritti</h2>
<p>Puoi chiedere al titolare l'accesso ai tuoi dati, la rettifica, la cancellazione, la limitazione del trattamento, la portabilità e opporti al trattamento (articoli 15–22 GDPR), scrivendo a {$mail}. Puoi revocare il consenso ai cookie analitici dalla <a href="?p=cookie">cookie policy</a>. Hai anche il diritto di proporre reclamo all'autorità di controllo: in Italia il <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">Garante per la protezione dei dati personali</a>.</p>
<h2>L'installer che scarichi</h2>
<p>L'installer che scarichi da GitHub gira sul tuo server e non invia nulla al titolare: questa informativa vale solo per la demo online.</p>
HTML];
}

/** Pagina completa della policy richiesta. */
function render_policy(string $page, string $nonce, callable $asset): void
{
    $lang = current_lang();
    [$title, $body] = policy_body($page, $lang);
    $other = $lang === 'en' ? 'it' : 'en';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Magento Installer Demo</title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($asset('app.css')) ?>">
</head>
<body class="policy-page">
<div class="bg-glow" aria-hidden="true"></div>
<main class="policy">
  <nav class="policy-nav">
    <a href="./">← <?= $lang === 'en' ? 'Back to the demo' : 'Torna alla demo' ?></a>
    <span><a href="?p=privacy"<?= $page === 'privacy' ? ' aria-current="page"' : '' ?>>Privacy</a> · <a href="?p=cookie"<?= $page === 'cookie' ? ' aria-current="page"' : '' ?>>Cookie</a> · <a href="#" data-lang="<?= $other ?>"><?= strtoupper($other) ?></a></span>
  </nav>
  <article class="card policy-card">
    <h1><?= h($title) ?></h1>
    <p class="muted"><?= $lang === 'en' ? 'Last updated' : 'Ultimo aggiornamento' ?>: <?= h(POLICY_UPDATED[$lang]) ?></p>
    <?= $body ?>
  </article>
</main>
<script src="<?= h($asset('consent.js')) ?>" nonce="<?= h($nonce) ?>" data-ga="<?= h(DEMO_GA4_ID) ?>"></script>
<script nonce="<?= h($nonce) ?>">
document.addEventListener('click', (e) => {
  const p = e.target.closest('[data-act="cookie-prefs"]');
  if (p) { e.preventDefault(); window.demoConsent && window.demoConsent.open(); return; }
  const l = e.target.closest('[data-lang]');
  if (l) { e.preventDefault(); document.cookie = 'lang=' + l.dataset.lang + '; path=/; max-age=31536000; SameSite=Lax'; location.reload(); }
});
</script>
</body>
</html>
<?php
    exit;
}
