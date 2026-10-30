# Magento Installer

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
- `catalog/mageos.json` elenca i moduli Mage-OS selezionabili con qualsiasi distro (da Packagist). `incompatible_with` esclude un modulo da certe distro (es. SEO e FAQ richiedono MSI, quindi non vanno con `light`: SEO 2.2 lo rende facoltativo ma va in conflitto con `meta-robots-tag` fissato da Mage-OS 3.6); `min_magento` e `min_php` fanno comparire un avviso (e rifiutano l'avvio) se la versione di Magento o il PHP CLI sono troppo vecchi; `needs_2fa` impedisce di disattivare il 2FA. I moduli che il prodotto Mage-OS richiede già a versione fissa non vengono riaggiunti (e compaiono come «già inclusi»).
- L'opzione «regole IVA italiane» imposta l'Italia come paese predefinito, prezzi IVA inclusa e crea le regole 22% (classe Taxable Goods), 10% e 4% (classi «IVA ridotta»). Il calcolo segue l'indirizzo di spedizione: per altri paesi UE servono le rispettive aliquote. Con `cross_border_trade_enabled` attivo, le vendite fuori UE registrano IVA 0 sul prezzo lordo: controlla la configurazione fiscale prima di vendere fuori dall'Italia. Senza questa opzione (distro Light o Normal) il negozio resta con gli Stati Uniti come paese predefinito e senza IVA.

## Uso
Metti la cartella in una directory servita dal web server (es. `installer-mage-os.test` con Valet) e aprila nel browser. Serve PHP con `proc_open` abilitato e `ext-curl`, `ext-pdo_mysql`.

- L'accesso è libero solo da `127.0.0.1` / `::1`, senza proxy davanti (nessun header `X-Forwarded-For`, `Forwarded`, `X-Real-IP`…) e con host `localhost`, `*.localhost`, `*.test` o un IP di loopback. Da qualsiasi altro indirizzo (anche privato: Docker, nginx davanti a PHP-FPM, tunnel, load balancer) o con un altro host (blocca il DNS rebinding) serve il token scritto nel file indicato dalla pagina.
- Job, log e credenziali stanno nella cartella temporanea di sistema (`0700`, di proprietà dell'utente PHP: se è di un altro utente o è un link l'installer si rifiuta di usarla), mai nella document root. Le password di installazione non compaiono nella riga di comando dei processi né nei log.
- Il processo PHP deve poter scrivere nella cartella dell'installer (serve per il blocco finale e per la rimozione): se non può, l'installazione non parte.
- A installazione finita con successo l'installer si cancella da solo (la pagina lo richiede appena mostra il riepilogo, quindi serve che il browser resti aperto fino ad allora). Elimina **solo i file che distribuisce** (`index.php`, `worker.php`, `lib/`, `assets/`, `catalog/`, `distros/`, `README.md`, `.gitignore`, `.installed`), mai il resto della cartella, e se qualcosa non si riesce a cancellare lo dice. In quel caso, o se chiudi la pagina prima, resta il file `.installed` che lo blocca: **rimuovilo dal server** a mano.
- Composer 2.9+ non carica di default le versioni con advisory di sicurezza (`audit.block-insecure`): senza questa deroga 2.4.6, 2.4.7 e Mage-OS 1.x non si installerebbero. L'installer imposta `COMPOSER_NO_SECURITY_BLOCKING=1` per i suoi processi e lo scrive nel log: le release vecchie dipendono da librerie con advisory noti, quindi scegli la patch più recente disponibile.
- La document root del sito installato deve puntare a `pub/`.

## Note
- Magento 2.4.x non supporta MySQL 9.x: nel passo del database c'è l'opzione "Procedi comunque" (solo sviluppo) che aggiunge la versione a `app/etc/di.xml`. Le versioni di database ammesse cambiano da una release (e da una patch) all'altra: il controllo definitivo avviene dopo `composer update`, leggendo i `supportedVersionPatterns` del `di.xml` appena installato, prima di `setup:install` (se non va bene cartella e database creati vengono ripuliti). Il file viene sovrascritto da un successivo `composer update`.
- Elasticsearch 7 è proposto solo dove il modulo esiste (fino a Magento 2.4.7 e Mage-OS 1.x). OpenSearch con HTTPS (sicurezza di serie dalla 2.12) viene riconosciuto in automatico, anche con certificato autofirmato.
- In modalità `production` `composer update` gira con `--no-dev`.
