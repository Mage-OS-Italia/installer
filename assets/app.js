(() => {
  'use strict';

  const root = document.getElementById('app');
  const CSRF = root.dataset.csrf;
  const STEPS = ['Versione e distro', 'Requisiti', 'Database e ricerca', 'Negozio e admin', 'Installazione'];

  const S = {
    step: 0,
    boot: null,
    f: {},
    touched: {},
    req: null,
    reqLoading: false,
    db: null,
    search: null,
    errors: [],
    job: null,
  };

  // ------------------------------------------------------------------ utils
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (sel, el = root) => el.querySelector(sel);
  const $$ = (sel, el = root) => [...el.querySelectorAll(sel)];

  async function api(action, body, query = '') {
    const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(body) };
    const r = await fetch('?a=' + action + query, opts);
    let data = {};
    try { data = await r.json(); } catch (e) { data = { error: 'Risposta non valida dal server (HTTP ' + r.status + ').' }; }
    if (!r.ok && !data.error) data.error = 'Errore HTTP ' + r.status;
    data._status = r.status;
    return data;
  }

  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    const t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
    return Promise.resolve();
  }

  const basename = (p) => (p || '').replace(/\/+$/, '').split('/').pop() || '';
  const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

  function genPassword() {
    const a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ', d = '23456789', all = a + d + '!@#%*-_';
    const pick = (set) => set[crypto.getRandomValues(new Uint32Array(1))[0] % set.length];
    let p = pick(a) + pick(a.toUpperCase()) + pick(d) + pick('!@#%*-_');
    while (p.length < 16) p += pick(all);
    return p.split('').sort(() => crypto.getRandomValues(new Uint8Array(1))[0] - 128).join('');
  }

  const version = () => S.boot.versions.find((v) => v.version === S.f.version);
  const distro = () => S.boot.distros.find((d) => d.id === S.f.distro);
  const phpHuman = (c) => (c || 'n/d').replace(/\|\|/g, ' · ').replace(/~?(\d+\.\d+)\.0/g, '$1.x');

  /** Pacchetti scelti (richiesti + opzionali) e se servono credenziali. */
  function selectedPackages() {
    const d = distro();
    return (d?.packages || []).filter((p) => p.required || S.f.packages.includes(p.name));
  }
  function neededRepos() {
    const d = distro();
    const ids = new Set(selectedPackages().map((p) => p.repository).filter(Boolean));
    return (d?.repositories || []).filter((r) => ids.has(r.id));
  }

  function applyDistroDefaults() {
    const d = distro();
    if (!d) return;
    S.f.packages = (d.packages || []).filter((p) => p.default && !p.required).map((p) => p.name);
    const df = d.defaults || {};
    if (!S.touched.language && df.language) S.f.language = df.language;
    if (!S.touched.currency && df.currency) S.f.currency = df.currency;
    if (!S.touched.timezone && df.timezone) S.f.timezone = df.timezone;
  }

  function syncDerived() {
    const name = basename(S.f.target);
    if (!S.touched.base_url && name) {
      const h = location.hostname;
      const dev = /\.(test|local|localhost)$/.test(h) || h === 'localhost';
      S.f.base_url = dev ? `http://${slug(name)}.${h.endsWith('.local') ? 'local' : 'test'}/` : location.origin + '/';
    }
    if (!S.touched.db_name && name) S.f.db_name = name.replace(/[^A-Za-z0-9_]+/g, '_').toLowerCase();
  }

  // ------------------------------------------------------------------ boot
  async function init() {
    const b = await api('bootstrap');
    if (b.error) {
      root.innerHTML = `<div class="boot"><p class="err">${esc(b.error)}</p></div>`;
      return;
    }
    S.boot = b;
    const latest = b.versions[0];
    const parent = (b.server.parent_dir || '').replace(/\/+$/, '');
    S.f = {
      version: latest.version, distro: (b.distros.find((d) => d.default) || b.distros[0])?.id || 'normal', packages: [], sample_data: false,
      target: parent + '/mage-os-store', base_url: '', store_name: 'Mage-OS Store', backend_frontname: 'admin',
      language: 'it_IT', currency: 'EUR', timezone: 'Europe/Rome',
      db_host: 'localhost', db_name: '', db_user: 'root', db_password: '', db_prefix: '', create_db: true, cleanup_db: false,
      search_engine: 'opensearch', search_host: 'localhost', search_port: 9200, search_prefix: 'magento2', search_user: '', search_password: '',
      admin_firstname: '', admin_lastname: '', admin_email: '', admin_user: 'admin', admin_password: genPassword(),
      deploy_mode: 'developer', allow_unsupported_db: false, disable_2fa: false, install_cron: false, save_auth: true, credentials: {},
    };
    applyDistroDefaults();
    syncDerived();
    render();
  }

  // ----------------------------------------------------------------- layout
  function render() {
    root.innerHTML = `
      <aside class="side">
        <div class="brand"><img src="assets/logo.svg" alt=""><div><h1>Mage-OS<br>Installer</h1><small>v${esc(S.boot.app.version)}</small></div></div>
        <ol class="stepper card">${STEPS.map((t, i) => `
          <li class="${i === S.step ? 'active' : i < S.step ? 'done' : ''}"><span class="num">${i < S.step ? '✓' : i + 1}</span><span>${esc(t)}</span></li>`).join('')}
        </ol>
        <div class="info card" id="side-info"></div>
      </aside>
      <main class="main"><div class="card" id="panel"></div></main>`;
    renderSide();
    renderStep();
  }

  function renderSide() {
    const v = version();
    const s = S.boot.server;
    $('#side-info').innerHTML = `
      <h3>Cos'è Mage-OS</h3>
      <p>Distribuzione community-driven di Magento Open Source: stesso ecosistema, aggiornamenti rilasciati dalla community e pacchetti su <code>repo.mage-os.org</code>.</p>
      <div class="links"><a href="https://mage-os.org" target="_blank" rel="noopener">mage-os.org</a><a href="https://github.com/mage-os" target="_blank" rel="noopener">GitHub</a></div>
      <h3 style="margin-top:16px">Release selezionata</h3>
      <dl><dt>Versione</dt><dd>${esc(v?.version)}</dd><dt>PHP</dt><dd>${esc(phpHuman(v?.php))}</dd>${v?.time ? `<dt>Rilascio</dt><dd>${esc(v.time)}</dd>` : ''}</dl>
      <h3>Questo server</h3>
      <dl><dt>PHP web</dt><dd>${esc(s.php_web)} (${esc(s.sapi)})</dd><dt>PHP CLI</dt><dd>${esc(s.php_cli || 'non trovato')}</dd><dt>Sistema</dt><dd>${esc(s.os)}</dd><dt>Utente</dt><dd>${esc(s.user)}</dd></dl>`;
  }

  function renderStep() {
    const panel = $('#panel');
    const fn = [step0, step1, step2, step3, step4][S.step];
    panel.innerHTML = fn();
    bind(panel);
    if (S.step === 1 && !S.req && !S.reqLoading) runRequirements();
    if (S.step === 2 && !S.search) testSearch(true);
    if (S.step === 4 && S.job) resumeJobView();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  const actions = (back, next, nextLabel = 'Avanti →', nextDisabled = false) => `
    <div class="actions">
      ${back ? '<button class="btn" data-nav="back">← Indietro</button>' : '<span></span>'}
      <div class="right">${next ? `<button class="btn primary" data-nav="next" ${nextDisabled ? 'disabled' : ''}>${nextLabel}</button>` : ''}</div>
    </div>`;

  const field = (label, key, o = {}) => `
    <label class="field ${o.full ? 'full' : ''}"><span>${label}</span>
      <input type="${o.type || 'text'}" data-k="${key}" value="${esc(S.f[key])}" placeholder="${esc(o.ph || '')}" autocomplete="off" ${o.attrs || ''}>
      ${o.hint ? `<small>${o.hint}</small>` : ''}</label>`;

  const select = (label, key, options, o = {}) => `
    <label class="field ${o.full ? 'full' : ''}"><span>${label}</span>
      <select data-k="${key}">${options.map(([v, t]) => `<option value="${esc(v)}" ${String(S.f[key]) === String(v) ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select>
      ${o.hint ? `<small>${o.hint}</small>` : ''}</label>`;

  const checkbox = (key, title, desc) => `
    <label class="check"><input type="checkbox" data-k="${key}" ${S.f[key] ? 'checked' : ''}><span><b>${title}</b><small>${desc}</small></span></label>`;

  // ------------------------------------------------------------ step 0
  function step0() {
    const v = version();
    const d = distro();
    const opts = S.boot.versions.map((x, i) => [x.version, x.version + (i === 0 ? ' — ultima' : '')]);
    const optional = (d?.packages || []).filter((p) => !p.required);
    const required = (d?.packages || []).filter((p) => p.required);
    return `
      <h2>Benvenuto 👋</h2>
      <p class="lead">Scegli la versione di Mage-OS e la distro: l'installer scarica tutto, configura il database e crea il negozio.</p>
      ${!['live', 'cache'].includes(S.boot.versions_source) ? `<div class="notice" style="margin:0 0 18px">Elenco versioni ${S.boot.versions_source === 'fallback' ? 'minimo (repo.mage-os.org non raggiungibile)' : 'dall\'ultima copia salvata (repo.mage-os.org non raggiungibile)'}.</div>` : ''}
      <div class="grid">
        ${select('Versione di Mage-OS', 'version', opts)}
        <div class="field"><span>Requisiti di questa versione</span><div class="chips" style="margin-top:4px"><span class="chip accent">PHP ${esc(phpHuman(v.php))}</span>${v.time ? `<span class="chip">rilasciata ${esc(v.time)}</span>` : ''}</div></div>
      </div>

      <h3>Distro</h3>
      <div class="distros">${S.boot.distros.map((x) => `
        <label class="distro ${x.id === S.f.distro ? 'selected' : ''}">
          <input type="radio" name="distro" value="${esc(x.id)}" ${x.id === S.f.distro ? 'checked' : ''}>
          <h4>${esc(x.name)}</h4><div class="tag">${esc(x.tagline)}</div><p>${esc(x.description)}</p>
          <ul>${(x.highlights || []).map((h) => `<li>${esc(h)}</li>`).join('')}</ul>
        </label>`).join('')}</div>

      ${(required.length || optional.length) ? `<h3>Moduli inclusi nella distro</h3><div class="pkg-list">
        ${required.map((p) => `<label class="check" style="opacity:.75"><input type="checkbox" checked disabled><span><b>${esc(p.label)}</b> <span class="chip">sempre incluso</span><small>${esc(p.description || '')} · <code>${esc(p.name)}</code></small></span></label>`).join('')}
        ${optional.map((p) => `<label class="check"><input type="checkbox" data-pkg="${esc(p.name)}" ${S.f.packages.includes(p.name) ? 'checked' : ''}><span><b>${esc(p.label)}</b><small>${esc(p.description || '')} · <code>${esc(p.name)}</code></small></span></label>`).join('')}
      </div>` : ''}

      <h3>Opzioni</h3>
      ${checkbox('sample_data', 'Installa i dati di esempio', 'Catalogo demo (prodotti, categorie, CMS, regole). Utile per provare il negozio; aggiunge circa 20 pacchetti e alcune centinaia di MB.')}

      <h3>Dove installare</h3>
      ${field('Cartella di destinazione', 'target', { full: true, ph: '/var/www/mio-negozio', hint: 'Percorso assoluto sul server. Deve essere vuota o non esistere: verrà creata. La document root del sito dovrà puntare a <code>pub/</code>.' })}
      ${actions(false, true)}`;
  }

  // ------------------------------------------------------------ step 1
  async function runRequirements() {
    S.reqLoading = true;
    S.req = null;
    renderStep();
    const r = await api('requirements', { version: S.f.version, distro: S.f.distro, packages: S.f.packages, target: S.f.target });
    S.reqLoading = false;
    S.req = r;
    if (S.step === 1) renderStep();
  }

  function step1() {
    if (S.reqLoading || !S.req) {
      return `<h2>Requisiti del server</h2><p class="lead">Controllo in corso per Mage-OS ${esc(S.f.version)}…</p><div class="boot" style="padding:40px"><div class="spinner"></div></div>${actions(true, false)}`;
    }
    const r = S.req;
    if (r.error) return `<h2>Requisiti del server</h2><p class="err">${esc(r.error)}</p>${actions(true, false)}`;
    const cls = r.blocking ? 'fail' : r.warnings ? 'warn' : 'ok';
    const msg = r.blocking
      ? `<b>${r.blocking} requisit${r.blocking === 1 ? 'o non soddisfatto' : 'i non soddisfatti'}.</b> Risolvi i punti in rosso e ricontrolla.`
      : r.warnings ? `<b>Tutto a posto per procedere</b>, con ${r.warnings} avvis${r.warnings === 1 ? 'o' : 'i'} da valutare.` : '<b>Il server soddisfa tutti i requisiti.</b>';
    return `
      <h2>Requisiti del server</h2>
      <p class="lead">Verifica per Mage-OS ${esc(S.f.version)} — PHP ${esc(phpHuman(r.version?.php))}.</p>
      <div class="summary-bar ${cls}"><span style="font-size:22px">${r.blocking ? '⛔' : r.warnings ? '⚠️' : '✅'}</span><div style="flex:1">${msg}</div><button class="btn small" data-act="recheck">Ricontrolla</button></div>
      ${r.groups.map((g) => `<div class="group"><h3>${esc(g.title)}</h3><div class="group-box">${g.checks.map((c) => `
        <div class="req ${c.status}"><span class="ico">${c.status === 'ok' ? '✓' : c.status === 'warn' ? '!' : '✕'}</span>
        <div><b>${esc(c.label)}</b> <span class="d">— ${esc(c.detail)}</span>${c.status !== 'ok' && c.hint ? `<div class="hint">${esc(c.hint)}</div>` : ''}</div></div>`).join('')}</div></div>`).join('')}
      <p class="muted" style="font-size:13px">Versioni minime di database e motore di ricerca vengono verificate al passo successivo, quando inserisci i dati di connessione.</p>
      ${actions(true, true, 'Avanti →', !!r.blocking)}`;
  }

  // ------------------------------------------------------------ step 2
  async function testDb() {
    setBox('#db-test', 'warn', '<div class="spinner"></div> Connessione in corso…');
    S.db = await api('test_db', { host: S.f.db_host, name: S.f.db_name, user: S.f.db_user, password: S.f.db_password, version: S.f.version });
    setBox('#db-test', S.db.status || 'fail', esc(S.db.message || S.db.error));
    const ub = $('#unsupported-box');
    if (ub) { ub.innerHTML = unsupportedBox(); bind(ub); }
    updateNext();
  }
  async function testSearch(silent) {
    if (!silent) setBox('#search-test', 'warn', '<div class="spinner"></div> Connessione in corso…');
    S.search = await api('test_search', { host: S.f.search_host, port: S.f.search_port, user: S.f.search_user, password: S.f.search_password, version: S.f.version });
    if (S.search.status === 'ok' && S.search.engine && !S.touched.search_engine) {
      S.f.search_engine = S.search.engine;
      const sel = $('[data-k=search_engine]');
      if (sel) sel.value = S.search.engine;
    }
    if (S.step === 2) setBox('#search-test', S.search.status || 'fail', esc(S.search.message || S.search.error));
    updateNext();
  }
  function setBox(sel, status, html) {
    const b = $(sel);
    if (b) { b.className = 'testbox ' + status; b.innerHTML = html; b.hidden = false; }
  }
  const testBoxHtml = (t, id) => t ? `<div class="testbox ${t.status || 'fail'}" id="${id}">${esc(t.message || t.error)}</div>` : `<div class="testbox" id="${id}" hidden></div>`;

  function step2() {
    return `
      <h2>Database e ricerca</h2>
      <p class="lead">Mage-OS usa MySQL o MariaDB per i dati e OpenSearch o Elasticsearch per catalogo e ricerca.</p>
      <h3 style="margin-top:0">Database</h3>
      <div class="grid">
        ${field('Host', 'db_host', { ph: 'localhost', hint: 'Anche host:porta o percorso del socket.' })}
        ${field('Nome database', 'db_name', { ph: 'mage_os' })}
        ${field('Utente', 'db_user')}
        ${field('Password', 'db_password', { type: 'password' })}
        ${field('Prefisso tabelle (opzionale)', 'db_prefix', { ph: 'es. mg_' })}
      </div>
      <div class="checks" style="margin-top:14px">
        ${checkbox('create_db', 'Crea il database se manca', "Richiede che l'utente abbia il permesso CREATE.")}
        ${checkbox('cleanup_db', 'Svuota il database se non è vuoto', 'Elimina le tabelle esistenti prima di installare. Usalo solo su database di prova.')}
      </div>
      <div style="margin-top:14px"><button class="btn small" data-act="test-db">Testa connessione al database</button></div>
      ${testBoxHtml(S.db, 'db-test')}
      <div id="unsupported-box">${unsupportedBox()}</div>

      <h3>Motore di ricerca</h3>
      <div class="grid">
        ${select('Motore', 'search_engine', [['opensearch', 'OpenSearch'], ['elasticsearch8', 'Elasticsearch 8'], ['elasticsearch7', 'Elasticsearch 7']])}
        ${field('Prefisso indice', 'search_prefix')}
        ${field('Host', 'search_host')}
        ${field('Porta', 'search_port', { type: 'number' })}
        ${field('Utente (se protetto)', 'search_user')}
        ${field('Password', 'search_password', { type: 'password' })}
      </div>
      <div style="margin-top:14px"><button class="btn small" data-act="test-search">Testa connessione alla ricerca</button></div>
      ${testBoxHtml(S.search, 'search-test')}
      ${actions(true, true, 'Avanti →', !step2Ok())}`;
  }
  function unsupportedBox() {
    if (!S.db?.unsupported) return '';
    return `<div style="margin-top:12px"><label class="check"><input type="checkbox" data-k="allow_unsupported_db" ${S.f.allow_unsupported_db ? 'checked' : ''}><span><b>Procedi comunque (solo sviluppo)</b><small>Aggiunge questa versione del database a <code>app/etc/di.xml</code>. Non è una configurazione supportata: da non usare in produzione.</small></span></label></div>`;
  }
  function step2Ok() {
    const dbOk = S.db && (S.db.status === 'ok' || (S.db.status === 'warn' && S.f.cleanup_db) || (S.db.unsupported && S.f.allow_unsupported_db));
    return !!(dbOk && S.search && S.search.status === 'ok');
  }

  // ------------------------------------------------------------ step 3
  function step3() {
    const L = S.boot.locale;
    const repos = neededRepos();
    return `
      <h2>Negozio e amministratore</h2>
      <p class="lead">Lingua, valuta e fuso orario vengono impostati all'installazione. Le proposte dipendono dalla distro scelta.</p>
      <div class="grid">
        ${field('Nome del negozio', 'store_name')}
        ${field('Base URL', 'base_url', { ph: 'http://mio-negozio.test/', hint: 'Indirizzo con cui il sito sarà raggiungibile.' })}
        ${select('Lingua', 'language', Object.entries(L.languages).map(([k, v]) => [k, `${v} (${k})`]))}
        ${select('Valuta', 'currency', Object.entries(L.currencies).map(([k, v]) => [k, `${k} — ${v}`]))}
        ${select('Fuso orario', 'timezone', L.timezones.map((t) => [t, t]))}
        ${field('Percorso admin', 'backend_frontname', { hint: 'Il pannello sarà su <code>/admin</code> o sul percorso scelto.' })}
      </div>

      <h3>Utente amministratore</h3>
      <div class="grid">
        ${field('Nome', 'admin_firstname')}
        ${field('Cognome', 'admin_lastname')}
        ${field('Email', 'admin_email', { type: 'email' })}
        ${field('Username', 'admin_user')}
        <div class="field full"><span>Password</span>
          <div class="input-row"><input type="password" data-k="admin_password" value="${esc(S.f.admin_password)}" autocomplete="new-password"><button class="btn small" data-act="toggle-pw" type="button">Mostra</button><button class="btn small" data-act="gen-pw" type="button">Genera</button></div>
          <small>Almeno 7 caratteri con lettere e numeri. Salvala: non verrà mostrata di nuovo.</small></div>
      </div>

      ${repos.length ? `<h3>Credenziali dei repository privati</h3><div class="grid">${repos.map((r) => `
        <div class="field full"><span>${esc(r.name)} <code>${esc(r.host)}</code></span></div>
        <label class="field"><span>Utente</span><input type="text" data-cred="${esc(r.host)}" data-ck="user" value="${esc(S.f.credentials[r.host]?.user || '')}" autocomplete="off"></label>
        <label class="field"><span>Password / token</span><input type="password" data-cred="${esc(r.host)}" data-ck="password" value="${esc(S.f.credentials[r.host]?.password || '')}" autocomplete="off"></label>`).join('')}</div>
        <div style="margin-top:12px">${checkbox('save_auth', 'Salva le credenziali in auth.json del progetto', "Comodo per i futuri composer update; il file viene creato con permessi 0600. Disattivalo se non vuoi lasciare credenziali sul server.")}</div>` : ''}

      <h3>Opzioni</h3>
      <div class="checks">
        ${select('Modalità di deploy', 'deploy_mode', [['developer', 'Developer (rapida, consigliata per iniziare)'], ['production', 'Production (compila DI e asset: richiede molti minuti)']])}
        <span></span>
        ${checkbox('disable_2fa', "Disattiva l'autenticazione a due fattori dell'admin", 'Solo per ambienti di sviluppo: evita la configurazione del 2FA al primo accesso.')}
        ${checkbox('install_cron', 'Installa il cron di Magento', 'Aggiunge le righe al crontab dell\'utente che esegue PHP.')}
      </div>
      ${S.errors.length ? `<div class="notice" style="margin-top:20px;border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08);color:#fecaca"><b>Da correggere:</b><ul style="margin:6px 0 0;padding-left:20px">${S.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul></div>` : ''}
      ${actions(true, true, 'Riepilogo →')}`;
  }

  function validateStep3() {
    const f = S.f, e = [];
    if (!f.store_name.trim()) e.push('Inserisci il nome del negozio.');
    if (!/^https?:\/\/[^\s/]+/i.test(f.base_url)) e.push('Il Base URL deve iniziare con http:// o https://');
    if (!/^[A-Za-z0-9_-]{2,}$/.test(f.backend_frontname)) e.push('Percorso admin non valido (lettere, numeri, - e _).');
    if (!f.admin_firstname.trim() || !f.admin_lastname.trim()) e.push("Nome e cognome dell'amministratore sono obbligatori.");
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.admin_email)) e.push('Email amministratore non valida.');
    if (!/^[A-Za-z0-9_.@-]{3,40}$/.test(f.admin_user)) e.push('Username non valido (almeno 3 caratteri).');
    if (f.admin_password.length < 7 || !/[A-Za-z]/.test(f.admin_password) || !/\d/.test(f.admin_password)) e.push('La password deve avere almeno 7 caratteri, con lettere e numeri.');
    for (const r of neededRepos()) {
      const c = f.credentials[r.host] || {};
      if (!c.user || !c.password) e.push('Inserisci utente e password per ' + r.host + '.');
    }
    return e;
  }

  // ------------------------------------------------------------ step 4
  function step4() {
    if (S.job) return jobShell();
    const f = S.f, d = distro(), v = version();
    const pk = selectedPackages();
    const row = (k, val) => `<dt>${k}</dt><dd>${val}</dd>`;
    return `
      <h2>Riepilogo</h2>
      <p class="lead">Controlla i dati: quando premi <b>Installa</b> parte il download e la configurazione. Puoi lasciare la pagina aperta e seguire il log in diretta.</p>
      <div class="recap">
        <section><h4>Mage-OS</h4><dl>${row('Versione', esc(v.version))}${row('Distro', esc(d.name))}${row('Moduli extra', pk.length)}${row('Dati di esempio', f.sample_data ? 'sì' : 'no')}${row('Modalità', esc(f.deploy_mode))}</dl></section>
        <section><h4>Destinazione</h4><dl>${row('Cartella', esc(f.target))}${row('Base URL', esc(f.base_url))}${row('Admin', esc('/' + f.backend_frontname))}</dl></section>
        <section><h4>Database</h4><dl>${row('Server', esc((S.db?.server || '') + ' ' + (S.db?.version || '')))}${row('Host', esc(f.db_host))}${row('Database', esc(f.db_name))}${row('Utente', esc(f.db_user))}${row('Svuota', f.cleanup_db ? 'sì' : 'no')}</dl></section>
        <section><h4>Ricerca</h4><dl>${row('Motore', esc(f.search_engine))}${row('Host', esc(f.search_host + ':' + f.search_port))}${row('Prefisso', esc(f.search_prefix))}</dl></section>
        <section><h4>Negozio</h4><dl>${row('Nome', esc(f.store_name))}${row('Lingua', esc(f.language))}${row('Valuta', esc(f.currency))}${row('Fuso orario', esc(f.timezone))}</dl></section>
        <section><h4>Amministratore</h4><dl>${row('Nome', esc(f.admin_firstname + ' ' + f.admin_lastname))}${row('Email', esc(f.admin_email))}${row('Username', esc(f.admin_user))}${row('Password', '••••••••')}</dl></section>
      </div>
      ${pk.length ? `<h3>Pacchetti che verranno installati</h3><div class="chips">${pk.map((p) => `<span class="chip">${esc(p.name)}</span>`).join('')}</div>` : ''}
      <div id="start-err"></div>
      <div class="actions"><button class="btn" data-nav="back">← Indietro</button><div class="right"><button class="btn primary" data-act="start" style="padding:13px 26px;font-size:16px">🚀 Installa Mage-OS</button></div></div>`;
  }

  async function startInstall(btn) {
    btn.disabled = true;
    btn.textContent = 'Avvio…';
    const f = S.f;
    const payload = { ...f, packages: f.packages };
    const r = await api('start', payload);
    if (r.error) {
      btn.disabled = false;
      btn.textContent = '🚀 Installa Mage-OS';
      $('#start-err').innerHTML = `<div class="notice" style="margin-top:20px;border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08);color:#fecaca"><b>${esc(r.error)}</b>${(r.errors || []).length ? `<ul style="margin:6px 0 0;padding-left:20px">${r.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul>` : ''}</div>`;
      return;
    }
    S.job = { id: r.job, offset: 0, state: 'running', steps: [], logEl: null, timer: null };
    renderStep();
  }

  function jobShell() {
    return `
      <h2>Installazione in corso</h2>
      <p class="lead" id="job-lead">Mage-OS ${esc(S.f.version)} · ${esc(S.f.target)}</p>
      <div class="progress-top"><div class="bar"><i id="job-bar"></i></div><b id="job-pct">0%</b></div>
      <div class="tasks" id="job-tasks"></div>
      <div id="job-result"></div>
      <details open id="job-log-wrap"><summary class="label" style="cursor:pointer;margin:6px 0 10px">Log in diretta</summary><div class="term" id="job-log"></div></details>
      <div class="actions" id="job-actions"><span></span><div class="right"><button class="btn danger small" data-act="cancel">Annulla installazione</button></div></div>`;
  }

  function resumeJobView() {
    const j = S.job;
    j.logEl = $('#job-log');
    if (j.logText) j.logEl.innerHTML = j.logText;
    drawTasks();
    if (!j.timer && j.state === 'running') j.timer = setInterval(pollJob, 1200);
    if (j.state !== 'running') finishView();
    pollJob();
  }

  const WEIGHTS = { preflight: 5, download: 35, packages: 15, install: 30, configure: 8, finalize: 7 };

  function drawTasks() {
    const j = S.job;
    const box = $('#job-tasks');
    if (!box) return;
    box.innerHTML = j.steps.map((s) => {
      const st = s.state === 'ok' ? '✓' : s.state === 'failed' ? '✕' : s.state === 'running' ? '<div class="spinner" style="width:16px;height:16px;border-width:2px"></div>' : s.state === 'skipped' ? '–' : '';
      return `<div class="task ${s.state}"><span class="st">${st}</span><span>${esc(s.label)}</span><span class="det">${s.state === 'skipped' ? 'non necessario' : esc(s.detail || '')}</span></div>`;
    }).join('');
    let tot = 0, got = 0;
    for (const s of j.steps) {
      if (s.state === 'skipped') continue;
      const w = WEIGHTS[s.id] || 5;
      tot += w;
      got += w * (s.state === 'ok' ? 1 : s.state === 'running' ? (s.progress ?? 0.15) : 0);
    }
    const pct = j.state === 'done' ? 100 : Math.min(99, Math.round(tot ? (got / tot) * 100 : 0));
    $('#job-bar').style.width = pct + '%';
    $('#job-pct').textContent = pct + '%';
  }

  function appendLog(text) {
    const j = S.job;
    if (!text || !j.logEl) return;
    const stick = j.logEl.scrollTop + j.logEl.clientHeight >= j.logEl.scrollHeight - 30;
    let html = '';
    for (const line of text.split('\n')) {
      if (line === '') { html += '\n'; continue; }
      let cls = '';
      if (/^\[\d\d:\d\d:\d\d\] ▶/.test(line)) cls = 'step';
      else if (/✖|⚠|\[ERROR\]|Exception|error/i.test(line) && !/^\[\d\d:\d\d:\d\d\] \$/.test(line)) cls = 'bad';
      else if (/✔/.test(line)) cls = 'good';
      else if (/^\[\d\d:\d\d:\d\d\] \$/.test(line)) cls = 'cmd';
      html += `<span class="${cls}">${esc(line)}</span>\n`;
    }
    j.logText = (j.logText || '') + html;
    j.logEl.insertAdjacentHTML('beforeend', html);
    if (stick) j.logEl.scrollTop = j.logEl.scrollHeight;
  }

  let polling = false;
  async function pollJob() {
    const j = S.job;
    if (!j || polling) return;
    polling = true;
    try {
      const r = await api('status', undefined, `&job=${j.id}&since=${j.offset}`);
      if (r.error) return;
      j.offset = r.offset;
      appendLog(r.log);
      j.steps = r.status.steps || [];
      j.status = r.status;
      drawTasks();
      if (['done', 'failed', 'cancelled'].includes(r.status.state)) {
        j.state = r.status.state;
        clearInterval(j.timer); j.timer = null;
        drawTasks();
        finishView();
      }
    } finally { polling = false; }
  }

  function finishView() {
    const j = S.job, st = j.status || {};
    const out = $('#job-result');
    if (!out || out.dataset.done) return;
    out.dataset.done = '1';
    const act = $('#job-actions');
    if (j.state === 'done') {
      const r = st.result;
      $('#job-lead').textContent = 'Fatto!';
      out.innerHTML = `
        <div class="success"><div class="big">🎉</div><h2 style="margin:0 0 4px">Mage-OS ${esc(r.version)} è installato</h2><p class="muted">Tempo totale: ${Math.floor(r.duration / 60)} min ${r.duration % 60} s · modalità ${esc(r.mode)}</p></div>
        <div class="links-grid">
          <div class="link-card"><small>Negozio</small><div class="v"><a href="${esc(r.storefront)}" target="_blank" rel="noopener">${esc(r.storefront)}</a></div></div>
          <div class="link-card"><small>Pannello admin</small><div class="v"><a href="${esc(r.admin)}" target="_blank" rel="noopener">${esc(r.admin)}</a></div></div>
          <div class="link-card"><small>Utente admin</small><div class="v">${esc(r.admin_user)}</div></div>
          <div class="link-card"><small>Password admin</small><div class="v"><span id="pw-show">••••••••••••</span> <button class="btn small" data-act="reveal-pw" style="margin-left:8px">Mostra</button> <button class="btn small" data-act="copy-pw">Copia</button></div></div>
          <div class="link-card" style="grid-column:1/-1"><small>Cartella</small><div class="v">${esc(r.target)}</div></div>
        </div>
        ${(r.warnings || []).map((w) => `<div class="notice">${esc(w)}</div>`).join('')}
        <div class="notice">Per sicurezza l'installer è stato bloccato. Eliminalo dal server quando non ti serve più.</div>`;
      act.innerHTML = `<button class="btn danger" data-act="remove">Rimuovi installer</button><div class="right"><a class="btn primary" href="${esc(r.storefront)}" target="_blank" rel="noopener" style="text-decoration:none">Apri il negozio →</a></div>`;
      $('#job-log-wrap').open = false;
    } else {
      $('#job-lead').textContent = j.state === 'cancelled' ? 'Installazione annullata' : 'Installazione non riuscita';
      out.innerHTML = `<div class="notice" style="border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08);color:#fecaca;margin-bottom:16px"><b>${esc(st.error || 'Errore sconosciuto')}</b><br>Controlla il log qui sotto per il dettaglio. La cartella <code>${esc(S.f.target)}</code> può contenere file parziali: svuotala prima di riprovare.</div>`;
      act.innerHTML = `<button class="btn" data-act="copy-log">Copia il log</button><div class="right"><button class="btn primary" data-act="retry">← Torna ai dati</button></div>`;
    }
  }

  // ----------------------------------------------------------------- wiring
  function updateNext() {
    const btn = $('[data-nav=next]');
    if (!btn) return;
    if (S.step === 2) btn.disabled = !step2Ok();
  }

  function bind(scope) {
    $$('[data-k]', scope).forEach((el) => {
      const k = el.dataset.k;
      const handler = () => {
        const val = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? Number(el.value) : el.value);
        S.f[k] = val;
        S.touched[k] = true;
        if (k === 'target') { S.req = null; syncDerived(); }
        if (k === 'version') { S.req = null; S.db = null; S.search = null; renderSide(); renderStep(); return; }
        if (['db_host', 'db_name', 'db_user', 'db_password'].includes(k)) { S.db = null; const b = $('#db-test'); if (b) b.hidden = true; const ub = $('#unsupported-box'); if (ub) ub.innerHTML = ''; }
        if (k.startsWith('search_') && k !== 'search_engine') { S.search = null; const b = $('#search-test'); if (b) b.hidden = true; }
        if (k === 'cleanup_db' || k === 'allow_unsupported_db') updateNext();
        if (['db_host', 'db_name', 'db_user', 'db_password'].includes(k) || k.startsWith('search_')) updateNext();
        if (k === 'sample_data') S.req = null;
      };
      el.addEventListener(el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', handler);
    });
    $$('[data-pkg]', scope).forEach((el) => el.addEventListener('change', () => {
      const n = el.dataset.pkg;
      S.f.packages = el.checked ? [...new Set([...S.f.packages, n])] : S.f.packages.filter((x) => x !== n);
      S.req = null;
    }));
    $$('input[name=distro]', scope).forEach((el) => el.addEventListener('change', () => {
      S.f.distro = el.value;
      S.req = null;
      applyDistroDefaults();
      renderSide();
      renderStep();
    }));
    $$('[data-cred]', scope).forEach((el) => el.addEventListener('input', () => {
      const h = el.dataset.cred;
      S.f.credentials[h] = { ...(S.f.credentials[h] || {}), [el.dataset.ck]: el.value };
    }));
  }

  root.addEventListener('click', async (e) => {
    const nav = e.target.closest('[data-nav]');
    const act = e.target.closest('[data-act]');
    if (nav) {
      if (nav.dataset.nav === 'back') { S.step = Math.max(0, S.step - 1); render(); return; }
      if (S.step === 0) {
        if (!S.f.target.startsWith('/')) { alert('Inserisci un percorso assoluto per la cartella di destinazione.'); return; }
        S.req = null;
      }
      if (S.step === 3) {
        S.errors = validateStep3();
        if (S.errors.length) { renderStep(); return; }
      }
      S.step = Math.min(4, S.step + 1);
      render();
      return;
    }
    if (!act) return;
    switch (act.dataset.act) {
      case 'recheck': S.req = null; runRequirements(); break;
      case 'test-db': testDb(); break;
      case 'test-search': S.touched.search_engine = true; testSearch(false); break;
      case 'gen-pw': { S.f.admin_password = genPassword(); const i = $('[data-k=admin_password]'); i.value = S.f.admin_password; i.type = 'text'; $('[data-act=toggle-pw]').textContent = 'Nascondi'; break; }
      case 'toggle-pw': { const i = $('[data-k=admin_password]'); i.type = i.type === 'password' ? 'text' : 'password'; act.textContent = i.type === 'password' ? 'Mostra' : 'Nascondi'; break; }
      case 'start': startInstall(act); break;
      case 'cancel': if (confirm("Annullare l'installazione in corso?")) { act.disabled = true; await api('cancel', { job: S.job.id }); } break;
      case 'retry': S.job = null; S.step = 3; render(); break;
      case 'copy-log': copy($('#job-log').innerText); act.textContent = 'Copiato ✓'; break;
      case 'reveal-pw': { const s = $('#pw-show'); const hidden = s.textContent.startsWith('•'); s.textContent = hidden ? S.f.admin_password : '••••••••••••'; act.textContent = hidden ? 'Nascondi' : 'Mostra'; break; }
      case 'copy-pw': copy(S.f.admin_password); act.textContent = 'Copiata ✓'; break;
      case 'remove':
        if (confirm("Eliminare definitivamente l'installer dal server?")) {
          const r = await api('remove', {});
          if (r.ok) root.innerHTML = '<div class="boot"><p>Installer rimosso. Puoi chiudere questa pagina.</p></div>';
        }
        break;
    }
  });

  init();
})();
