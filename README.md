# Magento Installer

Installer web per Magento Open Source oppure [Mage-OS](https://mage-os.org), con moduli opzionali di Mage-OS, nello stile di quello delle prime versioni di Magento 2: si apre dal browser, controlla i requisiti del server e produce un'installazione funzionante.

## Cosa fa
1. Sceglie la piattaforma (Magento Open Source o Mage-OS), la versione e la distro. Le versioni di Magento (2.4.6 e successive) sono lette dal mirror `mirror.mage-os.org`, che serve i pacchetti ufficiali senza chiavi Marketplace; quelle di Mage-OS da `repo.mage-os.org`.
2. Tema del negozio: Luma o Hyvä (`catalog/frontend.json`). Con Hyvä si incolla il blocco `composer config` copiato dall'area licenze di hyva.io (se ne ricavano chiave e nome del progetto) e Luma e Blank vengono esclusi con `replace`. Checkout a scelta: Loki Checkout (open source, con Luma e Hyvä) oppure Hyvä Checkout (stesse chiavi, serve la licenza Checkout).
3. Facoltativo: moduli Mage-OS (blog, resi RMA, SEO…), dati di esempio e regole IVA italiane.
4. Verifica i requisiti per quella versione: PHP (web e CLI), estensioni, php.ini, Composer, spazio su disco, rete.
5. Testa database (versioni supportate da Magento) e motore di ricerca (OpenSearch / Elasticsearch).
6. Raccoglie dati del negozio (URL, lingua, valuta, fuso orario) e dell'utente admin.
7. Installa in background (`composer create-project`, `composer update`, `setup:install`, configurazione, reindex) con log in diretta.

## Distro
Sono file JSON in `distros/`:

| Distro | Contenuto |
|---|---|
| `light` | La piattaforma scelta senza i pacchetti non necessari (MSI, GraphQL superflui, DHL, FedEx, New Relic, analytics…; con Magento anche Adobe IMS, Stock e Braintree) tramite `replace` (130 su Magento, 117 su Mage-OS: elenchi `replace` e `replace_mageos`) |
| `normal` | La piattaforma scelta, base e senza extra |
| `italiana` | Come `normal` + lingua it_IT, EUR, Europe/Rome, modulo Resi (RMA) di Mage-OS, IVA italiana preselezionata e moduli Threecommerce a scelta (richiede credenziali di `repo.threecommerce.it`) |

## Moduli Mage-OS e IVA italiana
- `catalog/mageos.json` elenca i moduli Mage-OS selezionabili con qualsiasi distro (da Packagist). `incompatible_with` esclude un modulo da certe distro (es. SEO e FAQ richiedono MSI, quindi non vanno con `light`); `min_magento` fa comparire un avviso se la versione scelta è troppo vecchia.
- L'opzione «regole IVA italiane» imposta prezzi IVA inclusa e crea le regole 22% (classe Taxable Goods), 10% e 4% (classi «IVA ridotta»). Il calcolo segue l'indirizzo di spedizione: per altri paesi UE servono le rispettive aliquote.

## Uso
Metti la cartella in una directory servita dal web server (es. `installer-mage-os.test` con Valet) e aprila nel browser. Serve PHP con `exec`/`proc_open` abilitati e `ext-curl`, `ext-pdo_mysql`.

- Da localhost o rete privata l'accesso è libero; da rete pubblica serve il token scritto nel file indicato dalla pagina.
- Job, log e credenziali stanno nella cartella temporanea di sistema, mai nella document root.
- A installazione finita con successo l'installer si cancella da solo (la pagina lo richiede appena mostra il riepilogo, quindi serve che il browser resti aperto fino ad allora). Se la cancellazione non riesce, o chiudi la pagina prima, resta il file `.installed` che lo blocca: **rimuovilo dal server** a mano.
- La document root del sito installato deve puntare a `pub/`.

## Note
- Magento 2.4.x non supporta MySQL 9.x: nel passo del database c'è l'opzione "Procedi comunque" (solo sviluppo) che aggiunge la versione a `app/etc/di.xml`.
