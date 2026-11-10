# Magento Installer

🇮🇹 Italiano · 🇬🇧 [English](#english)

Installer web per Magento Open Source oppure [Mage-OS](https://mage-os.org), con moduli opzionali di Mage-OS, nello stile di quello delle prime versioni di Magento 2: si apre dal browser, controlla i requisiti del server e produce un'installazione funzionante.

## Cosa fa
1. Sceglie la piattaforma (Magento Open Source o Mage-OS), la versione e la distro. Le versioni di Magento (2.4.6 e successive) sono lette dal mirror `mirror.mage-os.org`, che serve i pacchetti ufficiali senza chiavi Marketplace; quelle di Mage-OS da `repo.mage-os.org`.
2. Tema del negozio: Luma o Hyvä (`catalog/frontend.json`). Con Hyvä si incolla il blocco `composer config` copiato dall'area licenze di hyva.io (se ne ricavano chiave e nome del progetto) e Luma e Blank vengono esclusi con `replace`. Checkout a scelta: Loki Checkout (open source, con Luma e Hyvä) oppure Hyvä Checkout (stesse chiavi, serve la licenza Checkout).
3. Catalogo: si indica il numero di prodotti previsti. Fino a 10.000 (soglia in `catalog/search.json`) la ricerca usa MySQL con il modulo Swissup `module-search-mysql-legacy` (engine `lmysql`) e i moduli Elasticsearch/OpenSearch sono esclusi con `replace`, come su threecommerce; oltre, o su richiesta, si configura OpenSearch/Elasticsearch.
4. Facoltativo: moduli Mage-OS (blog, resi RMA, SEO…), dati di esempio e regole IVA italiane.
5. Verifica i requisiti per quella versione: PHP (web e CLI), estensioni, php.ini, Composer, spazio su disco, rete.
6. Testa database (versioni supportate da Magento) e motore di ricerca (OpenSearch / Elasticsearch).
7. Raccoglie dati del negozio (URL, lingua, valuta, fuso orario) e dell'utente admin.
8. Installa in background (`composer create-project`, `composer update`, `setup:install`, configurazione, reindex) con log in diretta.

## Distro
Sono file JSON in `distros/`. Un repository può essere `composer` (default, con credenziali) oppure `vcs` con `"public": true` (GitHub pubblico, nessuna credenziale):

| Distro | Contenuto |
|---|---|
| `light` | La piattaforma scelta senza i pacchetti non necessari (MSI, GraphQL superflui, DHL, FedEx, New Relic, analytics…; con Magento anche Adobe IMS, Stock e Braintree) tramite `replace` (129 su Magento, 116 su Mage-OS: elenchi `replace` e `replace_mageos`) |
| `normal` | La piattaforma scelta, base e senza extra |
| `italiana` | Come `normal` + lingua it_IT (pacchetto `mage-os-italia/module-language-it_it` da Packagist, nessuna credenziale), EUR, Europe/Rome, modulo Resi (RMA) di Mage-OS, IVA italiana preselezionata e moduli Threecommerce a scelta (le credenziali di `repo.threecommerce.it` servono solo se se ne seleziona qualcuno) |

## Moduli Mage-OS e IVA italiana
- `catalog/mageos.json` elenca i moduli Mage-OS selezionabili con qualsiasi distro (da Packagist). `incompatible_with` esclude un modulo da certe distro; `needs_msi` (SEO, FAQ) lo rende selezionabile solo con MSI attivo; `min_php` fa comparire un avviso e rifiuta l'avvio se il PHP CLI è troppo vecchio (`min_magento` è solo un avviso nella pagina, il server non lo controlla); `needs_2fa` impedisce di disattivare il 2FA. I moduli che il prodotto Mage-OS richiede già a versione fissa non vengono riaggiunti (e compaiono come «già inclusi»).
- L'opzione «Uso più magazzini (MSI)» (`multi_stock`, spenta di default) decide se tenere l'inventario multi-sorgente: se è spenta, i pacchetti elencati in `catalog/msi.json` (74 su Magento, 75 su Mage-OS) vengono esclusi con `replace` e resta lo stock classico. La distro `light` ha `"msi": false`: lo esclude sempre e la domanda non compare. I moduli con `needs_msi` (SEO, FAQ) richiedono la casella attiva.
- L'opzione «regole IVA italiane» imposta l'Italia come paese predefinito, prezzi IVA inclusa e crea le regole 22% (classe Taxable Goods), 10% e 4% (classi «IVA ridotta»). Il calcolo segue l'indirizzo di spedizione: per altri paesi UE servono le rispettive aliquote. Con `cross_border_trade_enabled` attivo, le vendite fuori UE registrano IVA 0 sul prezzo lordo: controlla la configurazione fiscale prima di vendere fuori dall'Italia. Senza questa opzione (distro Light o Normal) il negozio resta con gli Stati Uniti come paese predefinito e senza IVA.

## Uso
Metti la cartella in una directory servita dal web server (es. `installer-mage-os.test` con Valet) e aprila nel browser. Serve PHP con `proc_open` abilitato e `ext-curl`, `ext-pdo_mysql`.

- Nessun token di accesso: chiunque raggiunga l'URL può usare l'installer. Tienilo solo in locale o dietro VPN/limite per IP, e rimuovilo a lavoro finito.
- Usa HTTPS se l'installer è raggiungibile da una rete pubblica: su HTTP le password del database e le chiavi Composer viaggiano in chiaro. In locale (`.test`, `localhost`) non serve. Meglio ancora: tienilo dietro VPN o limita l'accesso per IP, e rimuovilo a lavoro finito.
- Le pagine hanno una Content-Security-Policy restrittiva (script solo dal sito stesso, nessuna risorsa esterna, nessun frame): se la modifichi, ricorda che l'interfaccia usa solo `assets/app.js` più due piccoli script con nonce in `index.php`.
- Job, log e credenziali stanno nella cartella temporanea di sistema (`0700`, di proprietà dell'utente PHP: se è di un altro utente o è un link l'installer si rifiuta di usarla), mai nella document root. Le password di installazione non compaiono nella riga di comando dei processi né nei log.
- Il processo PHP deve poter scrivere nella cartella dell'installer (serve per il blocco finale e per la rimozione): se non può, l'installazione non parte.
- A installazione finita con successo l'installer si cancella da solo (la pagina lo richiede appena mostra il riepilogo, quindi serve che il browser resti aperto fino ad allora). Elimina **solo i file che distribuisce** (`index.php`, `worker.php`, i file di `lib/`, `assets/`, `catalog/` e `distros/`, `README.md`, `LICENSE`, `.gitignore`, `.installed`), mai il resto della cartella (nemmeno altri file nelle stesse sottocartelle), e se qualcosa non si riesce a cancellare lo dice. In quel caso, o se chiudi la pagina prima, resta il file `.installed` che lo blocca: **rimuovilo dal server** a mano.
- Composer 2.9+ non carica di default le versioni con advisory di sicurezza (`audit.block-insecure`): senza questa deroga 2.4.6, 2.4.7 e Mage-OS 1.x non si installerebbero. L'installer imposta `COMPOSER_NO_SECURITY_BLOCKING=1` per i suoi processi e lo scrive nel log: le release vecchie dipendono da librerie con advisory noti, quindi scegli la patch più recente disponibile.
- La document root del sito installato deve puntare a `pub/`.

## Note
- Magento 2.4.x non supporta MySQL 9.x: nel passo del database c'è l'opzione "Procedi comunque" (solo sviluppo) che aggiunge la versione a `app/etc/di.xml`. Le versioni di database ammesse cambiano da una release (e da una patch) all'altra: il controllo definitivo avviene dopo `composer update`, leggendo i `supportedVersionPatterns` del `di.xml` appena installato, prima di `setup:install` (se non va bene cartella e database creati vengono ripuliti). Il file viene sovrascritto da un successivo `composer update`.
- Elasticsearch 7 è proposto solo dove il modulo esiste (fino a Magento 2.4.7 e Mage-OS 1.x). OpenSearch con HTTPS (sicurezza di serie dalla 2.12) viene riconosciuto in automatico; il certificato autofirmato, e l'HTTP con utente e password, sono accettati solo se OpenSearch è su una rete locale (`localhost`, `127.x`, IP privati `10.x`/`172.16-31.x`/`192.168.x`, nomi senza punti come un servizio Docker); su un host remoto serve un certificato valido.
- In modalità `production` `composer update` gira con `--no-dev`.

## Lingua dell'interfaccia
L'installer è disponibile in italiano e in inglese. Le bandiere IT / EN nella colonna a sinistra cambiano lingua senza perdere i dati inseriti; la scelta è salvata nel cookie `lang`. Alla prima visita si usa la lingua del browser (italiano se è italiano, altrimenti inglese). L'installazione in corso resta nella lingua con cui è partita.

Come funzionano le traduzioni: il testo italiano è la chiave e l'inglese sta nei dizionari.
- **Client** (`assets/app.js`): `t('Testo italiano {x}', { x })`, dizionario in `assets/lang-en.js`.
- **Server** (`lib/*.php`, `worker.php`, `lib/demo.php` nella demo): `t('Testo italiano {x}', ['x' => $x])`, dizionari in `lib/lang/en.*.php` (`en.server.php`, `en.worker.php`, `en.catalog.php`, `en.demo.php`).
- **Cataloghi** (`catalog/*.json`, `distros/*.json`): restano scritti in italiano; i campi di testo (`name`, `label`, `description`, `tagline`, `group`, `highlights`) vengono tradotti al caricamento con `t_fields()`, usando `lib/lang/en.catalog.php`.

Per aggiungere o correggere una frase: scrivila in italiano dentro `t()` (una frase = una chiave, con segnaposto `{nome}` invece di concatenare pezzi) e aggiungi la riga inglese nel dizionario giusto. Una chiave senza traduzione viene mostrata in italiano.

## Licenza
MIT, vedi `LICENSE`.

---

# English

Web installer for Magento Open Source or [Mage-OS](https://mage-os.org), with optional Mage-OS modules, in the style of the early Magento 2 installers: you open it in the browser, it checks the server requirements and produces a working installation.

## What it does
1. Picks the platform (Magento Open Source or Mage-OS), the version and the distro. Magento versions (2.4.6 and later) are read from the `mirror.mage-os.org` mirror, which serves the official packages without Marketplace keys; Mage-OS versions come from `repo.mage-os.org`.
2. Store theme: Luma or Hyvä (`catalog/frontend.json`). With Hyvä you paste the `composer config` block copied from the licenses area of hyva.io (the key and project name are extracted from it) and Luma and Blank are excluded with `replace`. Checkout of your choice: Loki Checkout (open source, with Luma and Hyvä) or Hyvä Checkout (same keys, requires the Checkout license).
3. Catalog: you enter the expected number of products. Up to 10,000 (threshold in `catalog/search.json`) search uses MySQL with the Swissup `module-search-mysql-legacy` module (engine `lmysql`) and the Elasticsearch/OpenSearch modules are excluded with `replace`, as on threecommerce; above that, or on request, OpenSearch/Elasticsearch is configured.
4. Optional: Mage-OS modules (blog, RMA returns, SEO…), sample data and Italian VAT rules.
5. Checks the requirements for that version: PHP (web and CLI), extensions, php.ini, Composer, disk space, network.
6. Tests the database (versions supported by Magento) and the search engine (OpenSearch / Elasticsearch).
7. Collects the store details (URL, language, currency, time zone) and the admin user.
8. Installs in the background (`composer create-project`, `composer update`, `setup:install`, configuration, reindex) with a live log.

## Distros
They are JSON files in `distros/`. A repository can be `composer` (default, with credentials) or `vcs` with `"public": true` (public GitHub, no credentials):

| Distro | Contents |
|---|---|
| `light` | The chosen platform without the unneeded packages (MSI, superfluous GraphQL, DHL, FedEx, New Relic, analytics…; with Magento also Adobe IMS, Stock and Braintree) via `replace` (129 on Magento, 116 on Mage-OS: the `replace` and `replace_mageos` lists) |
| `normal` | The chosen platform, plain and without extras |
| `italiana` | Like `normal` + it_IT language (package `mage-os-italia/module-language-it_it` from Packagist, no credentials), EUR, Europe/Rome, the Mage-OS Returns (RMA) module, Italian VAT preselected and Threecommerce modules of your choice (the `repo.threecommerce.it` credentials are only needed if you select any) |

## Mage-OS modules and Italian VAT
- `catalog/mageos.json` lists the Mage-OS modules that can be selected with any distro (from Packagist). `incompatible_with` excludes a module from certain distros; `needs_msi` (SEO, FAQ) makes it selectable only with MSI enabled; `min_php` shows a warning and refuses to start if the CLI PHP is too old (`min_magento` is only a warning on the page, the server does not check it); `needs_2fa` prevents disabling 2FA. Modules that the Mage-OS product already requires at a fixed version are not added again (they appear as "already included").
- The option "I use multiple warehouses (MSI)" (`multi_stock`, off by default) decides whether to keep multi-source inventory: when off, the packages listed in `catalog/msi.json` (74 on Magento, 75 on Mage-OS) are excluded with `replace` and the classic stock is kept. The `light` distro has `"msi": false`: it always excludes it and the question does not appear. Modules with `needs_msi` (SEO, FAQ) need the box checked.
- The "Italian VAT rules" option sets Italy as the default country, prices including VAT, and creates the 22% (Taxable Goods class), 10% and 4% ("Reduced VAT" classes) rules. Tax is calculated on the shipping address: other EU countries need their own rates. With `cross_border_trade_enabled` on, sales outside the EU record 0 VAT on the gross price: check the tax configuration before selling outside Italy. Without this option (Light or Normal distro) the store keeps the United States as default country and no VAT.

## Interface language
The installer is available in Italian and English. The IT / EN flags in the left column switch language without losing the data you entered; the choice is saved in the `lang` cookie. On the first visit the browser language is used (Italian if it is Italian, otherwise English). An installation already in progress keeps the language it was started with.

How translations work: the Italian text is the key and the English lives in dictionaries.
- **Client** (`assets/app.js`): `t('Italian text {x}', { x })`, dictionary in `assets/lang-en.js`.
- **Server** (`lib/*.php`, `worker.php`, `lib/demo.php` in the demo): `t('Italian text {x}', ['x' => $x])`, dictionaries in `lib/lang/en.*.php` (`en.server.php`, `en.worker.php`, `en.catalog.php`, `en.demo.php`).
- **Catalogs** (`catalog/*.json`, `distros/*.json`): they stay written in Italian; the text fields (`name`, `label`, `description`, `tagline`, `group`, `highlights`) are translated on load with `t_fields()`, using `lib/lang/en.catalog.php`.

To add or fix a sentence: write it in Italian inside `t()` (one sentence = one key, with `{name}` placeholders instead of concatenating pieces) and add the English line to the right dictionary. A key without a translation is shown in Italian.

## Usage
Put the folder in a directory served by the web server (e.g. `installer-mage-os.test` with Valet) and open it in the browser. It needs PHP with `proc_open` enabled, plus `ext-curl` and `ext-pdo_mysql`.

- No access token: anyone who can reach the URL can use the installer. Keep it local or behind a VPN/IP restriction, and remove it when you are done.
- Use HTTPS if the installer is reachable from a public network: over HTTP the database passwords and Composer keys travel in clear text. Locally (`.test`, `localhost`) it is not needed. Even better: keep it behind a VPN or restrict access by IP, and remove it when you are done.
- The pages have a restrictive Content-Security-Policy (scripts only from the site itself, no external resources, no frames): if you change it, remember that the interface only uses `assets/lang-en.js` and `assets/app.js` plus two small scripts with a nonce in `index.php`.
- Jobs, logs and credentials live in the system temporary folder (`0700`, owned by the PHP user: if it belongs to another user or is a link the installer refuses to use it), never in the document root. Installation passwords do not appear in the processes' command line or in the logs.
- The PHP process must be able to write to the installer folder (needed for the final lock and for the removal): if it cannot, the installation does not start.
- After a successful installation the installer deletes itself (the page requests it as soon as it shows the summary, so the browser must stay open until then). It deletes **only the files it ships** (`index.php`, `worker.php`, the files in `lib/`, `assets/`, `catalog/` and `distros/`, `README.md`, `LICENSE`, `.gitignore`, `.installed`), never the rest of the folder (not even other files in the same subfolders), and it tells you if something could not be deleted. In that case, or if you close the page too early, the `.installed` file remains and locks it: **remove it from the server** by hand.
- Composer 2.9+ does not load versions with security advisories by default (`audit.block-insecure`): without this exemption 2.4.6, 2.4.7 and Mage-OS 1.x would not install. The installer sets `COMPOSER_NO_SECURITY_BLOCKING=1` for its processes and writes it in the log: old releases depend on libraries with known advisories, so choose the latest patch available.
- The document root of the installed site must point to `pub/`.

## Notes
- Magento 2.4.x does not support MySQL 9.x: in the database step there is the "Proceed anyway" option (development only) that adds the version to `app/etc/di.xml`. The allowed database versions change from one release (and patch) to another: the final check happens after `composer update`, reading the `supportedVersionPatterns` of the freshly installed `di.xml`, before `setup:install` (if it fails, the folder and the database created are cleaned up). The file is overwritten by a later `composer update`.
- Elasticsearch 7 is offered only where the module exists (up to Magento 2.4.7 and Mage-OS 1.x). OpenSearch with HTTPS (security on by default since 2.12) is detected automatically; a self-signed certificate, and HTTP with user and password, are accepted only if OpenSearch is on a local network (`localhost`, `127.x`, private IPs `10.x`/`172.16-31.x`/`192.168.x`, dotless names such as a Docker service); on a remote host a valid certificate is required.
- In `production` mode `composer update` runs with `--no-dev`.

## License
MIT, see `LICENSE`.
