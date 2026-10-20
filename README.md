# Mage-OS Installer

Installer web per [Mage-OS](https://mage-os.org), nello stile di quello delle prime versioni di Magento 2: si apre dal browser, controlla i requisiti del server e produce un'installazione funzionante.

## Cosa fa
1. Sceglie la versione di Mage-OS (elenco letto da `repo.mage-os.org`) e la distro.
2. Verifica i requisiti per quella versione: PHP (web e CLI), estensioni, php.ini, Composer, spazio su disco, rete.
3. Testa database (versioni supportate da Mage-OS) e motore di ricerca (OpenSearch / Elasticsearch).
4. Raccoglie dati del negozio (URL, lingua, valuta, fuso orario) e dell'utente admin.
5. Installa in background (`composer create-project`, `composer update`, `setup:install`, configurazione, reindex) con log in diretta.

## Distro
Sono file JSON in `distros/`:

| Distro | Contenuto |
|---|---|
| `light` | Mage-OS senza 117 pacchetti non necessari (MSI, GraphQL superflui, DHL, FedEx, New Relic, analytics…) tramite `replace` |
| `normal` | Mage-OS completo, senza extra |
| `italiana` | Come `normal` + lingua it_IT, EUR, Europe/Rome e moduli Threecommerce a scelta (richiede credenziali di `repo.threecommerce.it`) |

## Uso
Metti la cartella in una directory servita dal web server (es. `installer-mage-os.test` con Valet) e aprila nel browser. Serve PHP con `exec`/`proc_open` abilitati e `ext-curl`, `ext-pdo_mysql`.

- Da localhost o rete privata l'accesso è libero; da rete pubblica serve il token scritto nel file indicato dalla pagina.
- Job, log e credenziali stanno nella cartella temporanea di sistema, mai nella document root.
- A installazione finita viene creato `.installed` e l'installer si blocca. **Rimuovilo dal server** quando non serve più.
- La document root del sito installato deve puntare a `pub/`.

## Note
- Mage-OS 3.x non supporta MySQL 9.x: nel passo del database c'è l'opzione "Procedi comunque" (solo sviluppo) che aggiunge la versione a `app/etc/di.xml`.
