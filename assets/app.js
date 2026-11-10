(() => {
  'use strict';

  const root = document.getElementById('app');
  // Lingua dell'interfaccia: il testo italiano è la chiave, window.I18N_EN (assets/lang-en.js) l'inglese.
  let lang = document.documentElement.lang === 'en' ? 'en' : 'it';
  const t = (it, v) => {
    let s = (lang === 'en' && window.I18N_EN && window.I18N_EN[it]) || it;
    if (v) s = s.replace(/\{(\w+)\}/g, (m, k) => (k in v ? v[k] : m));
    return s;
  };
  const numLocale = () => (lang === 'en' ? 'en-GB' : 'it-IT');
  const FLAG_IT = '<svg viewBox="0 0 3 2" width="22" height="15" aria-hidden="true"><path fill="#009246" d="M0 0h1v2H0z"/><path fill="#fff" d="M1 0h1v2H1z"/><path fill="#ce2b37" d="M2 0h1v2H2z"/></svg>';
  const FLAG_EN = '<svg viewBox="0 0 60 40" width="22" height="15" aria-hidden="true"><clipPath id="uk"><path d="M0 0h60v40H0z"/></clipPath><g clip-path="url(#uk)"><path d="M0 0h60v40H0z" fill="#012169"/><path d="M0 0l60 40m0-40L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40m0-40L0 40" stroke="#c8102e" stroke-width="3"/><path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="13"/><path d="M30 0v40M0 20h60" stroke="#c8102e" stroke-width="7"/></g></svg>';
  const langSwitch = () => `<div class="lang-switch" role="group" aria-label="Language">${[['it', 'Italiano', FLAG_IT], ['en', 'English', FLAG_EN]].map(([c, n, f]) => `<button type="button" class="lang ${lang === c ? 'on' : ''}" data-act="lang" data-lang="${c}" title="${n}" aria-label="${n}" aria-pressed="${lang === c}">${f}<span>${c.toUpperCase()}</span></button>`).join('')}</div>`;
  const CSRF = root.dataset.csrf;
  const steps = () => [t('Versione e distro'), t('Requisiti'), t('Database e ricerca'), t('Negozio e admin'), t('Installazione')];

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
    open: {},
  };

  // ------------------------------------------------------------------ utils
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (sel, el = root) => el.querySelector(sel);
  const $$ = (sel, el = root) => [...el.querySelectorAll(sel)];

  async function api(action, body, query = '') {
    const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(body) };
    let r;
    try { r = await fetch('?a=' + action + query, opts); } catch (e) { return { error: t('Server non raggiungibile: controlla la connessione e riprova.'), _status: 0 }; }
    let data = {};
    try { data = await r.json(); } catch (e) { data = { error: t('Risposta non valida dal server (HTTP {s}).', { s: r.status }) }; }
    if (!r.ok && !data.error) data.error = t('Errore HTTP {s}', { s: r.status });
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

  /** Il job in corso sopravvive al ricaricamento della pagina (solo in questa scheda): id, dati da mostrare e password admin generata. */
  function saveJob(j) {
    try {
      if (!j) sessionStorage.removeItem('mi-job');
      else sessionStorage.setItem('mi-job', JSON.stringify({ id: j.id, pw: S.f.admin_password, f: { platform: S.f.platform, version: S.f.version, target: S.f.target, frontend: S.f.frontend, checkout: S.f.checkout } }));
    } catch (e) { /* storage bloccato: si perde solo la ripresa dopo un reload */ }
  }
  function loadJob() {
    try { return JSON.parse(sessionStorage.getItem('mi-job') || 'null'); } catch (e) { return null; }
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

  const platform = () => S.boot.platforms[S.f.platform];
  const otherPlatform = () => Object.values(S.boot.platforms).find((x) => x.id !== S.f.platform);
  const pname = () => platform().name;
  const isEol = (x) => !!x?.eol && x.eol <= new Date().toISOString().slice(0, 10);
  const eolText = (x) => (isEol(x) ? t('fine supporto {d}', { d: x.eol }) : t('supporto fino al {d}', { d: x.eol }));
  const version = () => platform().versions.find((v) => v.version === S.f.version);
  const defaultName = () => (S.f.platform === 'mageos' ? 'mage-os' : 'magento') + '-store';
  const distro = () => S.boot.distros.find((d) => d.id === S.f.distro);
  const phpHuman = (c) => (c || t('n/d')).replace(/\|\|/g, ' · ').replace(/~?(\d+\.\d+)\.0/g, '$1.x');

  const frontend = () => S.boot.frontends.find((x) => x.id === S.f.frontend);
  /** Stessa lettura del server (parse_hyva_keys): serve solo a dare un riscontro subito. */
  function parseHyva(text) {
    const host = 'hyva-themes\\.repo\\.packagist\\.com';
    const p = text.match(new RegExp(host + '/([A-Za-z0-9][A-Za-z0-9_.-]*)'));
    const k = text.match(new RegExp(host + '[\'"]?\\s+[\'"]?token[\'"]?\\s+[\'"]?([A-Za-z0-9_-]{8,})')) || text.match(/"password"\s*:\s*"([A-Za-z0-9_-]{8,})"/);
    return p && k ? { project: p[1], key: k[1] } : null;
  }
  /** Catalogo piccolo: ricerca MySQL senza OpenSearch, come su threecommerce. */
  const mysqlEligible = () => S.f.products >= 1 && S.f.products <= S.boot.mysql_search.max_products;
  const useMysql = () => mysqlEligible() && !S.f.use_opensearch;
  const hyvaOk = () => S.f.frontend !== 'hyva' || !!parseHyva(S.f.hyva_keys);

  /** Pacchetti scelti (richiesti + opzionali) e se servono credenziali. */
  function selectedPackages() {
    const d = distro();
    return (d?.packages || []).filter((p) => p.required || S.f.packages.includes(p.name));
  }
  function neededRepos() {
    const d = distro();
    const ids = new Set(selectedPackages().map((p) => p.repository).filter(Boolean));
    return (d?.repositories || []).filter((r) => ids.has(r.id) && !r.public);
  }

  /** Moduli Mage-OS scelti dall'elenco (esclusi quelli già inclusi dalla distro). */
  const distroPackageNames = () => (distro()?.packages || []).filter((p) => p.required).map((p) => p.name);
  /** Confronta versioni Magento tipo 2.4.7-p3 (la patch conta solo a parità di release). */
  const vcmp = (a, b) => {
    const p = (v) => { const [base, patch] = v.split('-p'); return [...base.split('.').map(Number), Number(patch || 0)]; };
    const x = p(a), y = p(b);
    for (let i = 0; i < Math.max(x.length, y.length); i++) if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) - (y[i] || 0);
    return 0;
  };
  const tooOld = (m) => S.f.platform === 'magento' && !!m.min_magento && vcmp(S.f.version, m.min_magento) < 0;
  /** MSI (multi-magazzino) resta solo se richiesto; la distro Light lo esclude sempre. */
  const noMsi = () => distro()?.msi === false || !S.f.multi_stock;
  const isIncompatible = (m) => (m.incompatible_with || []).includes(S.f.distro) || (!!m.needs_opensearch && useMysql()) || (!!m.needs_msi && noMsi());
  /** Moduli che il prodotto (Mage-OS) richiede già a versione fissa: non si aggiungono. */
  const inProduct = (m) => (version()?.included || []).includes(m.name);
  const selectedMageos = () => S.boot.mageos.filter((m) => S.f.mageos.includes(m.name) && !isIncompatible(m) && !distroPackageNames().includes(m.name) && !inProduct(m));
  const cliPhp = () => S.boot.server.php_cli || '';
  const tooOldPhp = (m) => !!m.min_php && !!cliPhp() && cliPhp().localeCompare(m.min_php, undefined, { numeric: true }) < 0;

  function applyDistroDefaults() {
    const d = distro();
    if (!d) return;
    S.f.packages = (d.packages || []).filter((p) => p.default && !p.required).map((p) => p.name);
    S.f.mageos = S.f.mageos.filter((n) => !isIncompatible(S.boot.mageos.find((m) => m.name === n) || {}));
    if (!S.touched.italian_tax) S.f.italian_tax = !!(d.defaults || {}).italian_tax;
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
    const latest = b.platforms.magento.versions[0];
    const parent = (b.server.parent_dir || '').replace(/\/+$/, '');
    S.f = {
      platform: 'magento', version: latest.version, distro: (b.distros.find((d) => d.default) || b.distros[0])?.id || 'normal', frontend: 'luma', checkout: 'loki', hyva_csp: false, hyva_keys: '', products: 1000, use_opensearch: false, packages: [], mageos: [], sample_data: false, italian_tax: false, multi_stock: false,
      target: parent + '/magento-store', base_url: '', store_name: 'Magento Store', backend_frontname: 'admin',
      language: 'it_IT', currency: 'EUR', timezone: 'Europe/Rome',
      db_host: 'localhost', db_name: '', db_user: 'root', db_password: '', db_prefix: '', create_db: true, cleanup_db: false,
      search_engine: 'opensearch', search_ssl: false, search_host: 'localhost', search_port: 9200, search_prefix: 'magento2', search_user: '', search_password: '',
      admin_firstname: '', admin_lastname: '', admin_email: '', admin_user: 'admin', admin_password: genPassword(),
      deploy_mode: 'developer', allow_unsupported_db: false, disable_2fa: false, install_cron: false, save_auth: true, credentials: {},
    };
    applyDistroDefaults();
    syncDerived();
    // Demo: dati di esempio già compilati, che il resto dell'interfaccia non deve sovrascrivere.
    Object.assign(S.f, b.demo || {});
    for (const k of ['target', 'base_url', 'db_name', 'store_name']) S.touched[k] = true;
    const saved = loadJob();
    if (saved && /^[a-f0-9]{16}$/.test(saved.id || '')) {
      const st = await api('status', undefined, `&job=${saved.id}&since=0`);
      if (st.status) {
        Object.assign(S.f, saved.f || {}, { admin_password: saved.pw || S.f.admin_password });
        S.job = { id: saved.id, offset: 0, state: 'running', steps: [], logEl: null, timer: null };
        S.step = 4;
      } else {
        saveJob(null);
      }
    }
    render();
  }

  /** Dal menu si torna ai passi già visitati; a installazione avviata il percorso è bloccato. */
  const canGo = (i) => !S.job && i !== S.step && i <= (S.maxStep || 0) && i < 4;

  // ----------------------------------------------------------------- layout
  const demoBanner = () => `<div class="demo-banner"><b>Demo</b> · ${t("Stai provando l'installer: i dati sono di esempio, nessun controllo è reale e alla fine non viene installato nulla.")} <a href="https://github.com/Mage-OS-Italia/installer/archive/refs/heads/main.zip">${t("Scarica l'installer")}</a></div>`;

  const sideHtml = () => `
        <button type="button" class="brand" data-act="switch-platform" title="${t('Passa a {p}', { p: esc(otherPlatform().name) })}"><img src="assets/logo.svg" alt=""><div><h1>${S.f.platform === 'mageos' ? 'Mage-OS' : 'Magento'}<br>Installer</h1><small>v${esc(S.boot.app.version)} · ${t('passa a {p}', { p: esc(otherPlatform().name) })} ⇄</small></div></button>
        ${langSwitch()}
        <ol class="stepper card">${steps().map((label, i) => `
          <li class="${i === S.step ? 'active' : i < S.step ? 'done' : ''} ${canGo(i) ? 'nav' : ''}" ${canGo(i) ? `data-act="goto" data-step="${i}" role="button" tabindex="0"` : ''}><span class="num">${i < S.step ? '✓' : i + 1}</span><span>${esc(label)}</span></li>`).join('')}
        </ol>
        <div class="info card" id="side-info"></div>
      `;

  function render() {
    root.innerHTML = `
      <aside class="side">${sideHtml()}</aside>
      <main class="main">${demoBanner()}<div class="card" id="panel"></div></main>`;
    renderSide();
    renderStep();
  }

  function renderSide() {
    const v = version();
    const s = S.boot.server;
    $('#side-info').innerHTML = `
      <h3>${t("Cos'è {p}", { p: esc(pname()) })}</h3>
      ${S.f.platform === 'mageos'
        ? `<p>${t('Distribuzione community-driven di Magento Open Source: stesso ecosistema, aggiornamenti rilasciati dalla community e pacchetti su <code>repo.mage-os.org</code>.')}</p>
      <div class="links"><a href="https://mage-os.org" target="_blank" rel="noopener">mage-os.org</a><a href="https://github.com/mage-os" target="_blank" rel="noopener">GitHub</a></div>`
        : `<p>${t('La piattaforma ecommerce open source di Adobe. L\'installer scarica i pacchetti ufficiali dal mirror pubblico <code>mirror.mage-os.org</code>: non servono chiavi Marketplace. I moduli opzionali arrivano da <a href="https://mage-os.org" target="_blank" rel="noopener">Mage-OS</a>.')}</p>
      <div class="links"><a href="https://developer.adobe.com/commerce/docs/" target="_blank" rel="noopener">${t('Documentazione')}</a><a href="https://github.com/magento/magento2" target="_blank" rel="noopener">GitHub</a></div>`}
      <h3 style="margin-top:16px">${t('Release selezionata')}</h3>
      <dl><dt>${t('Versione')}</dt><dd>${esc(v?.version)}</dd><dt>PHP</dt><dd>${esc(phpHuman(v?.php))}</dd>${v?.time ? `<dt>${t('Rilascio')}</dt><dd>${esc(v.time)}</dd>` : ''}${v?.eol ? `<dt>${t('Supporto')}</dt><dd>${isEol(v) ? t('terminato il {d}', { d: esc(v.eol) }) : t('fino al {d}', { d: esc(v.eol) })}</dd>` : ''}</dl>
      <h3>${t('Questo server')}</h3>
      <dl><dt>${t('PHP web')}</dt><dd>${esc(s.php_web)} (${esc(s.sapi)})</dd><dt>${t('PHP CLI')}</dt><dd>${esc(s.php_cli || t('non trovato'))}</dd><dt>${t('Sistema')}</dt><dd>${esc(s.os)}</dd><dt>${t('Utente')}</dt><dd>${esc(s.user)}</dd></dl>`;
  }

  function renderStep() {
    const panel = $('#panel');
    const fn = [step0, step1, step2, step3, step4][S.step];
    panel.innerHTML = fn();
    bind(panel);
    if (S.step === 1 && !S.req && !S.reqLoading) runRequirements();
    if (S.step === 2 && !S.search && !useMysql()) testSearch(true);
    if (S.step === 4 && S.job) resumeJobView();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  const actions = (back, next, nextLabel = t('Avanti →'), nextDisabled = false) => `
    <div class="actions">
      ${back ? '<button class="btn" data-nav="back">' + t('← Indietro') + '</button>' : '<span></span>'}
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
    const pl = platform();
    const opts = pl.versions.map((x, i) => [x.version, x.version + (i === 0 ? ' — ' + t('ultima') : '') + (x.eol ? ' — ' + eolText(x) : '')]);
    const optional = (d?.packages || []).filter((p) => !p.required);
    const required = (d?.packages || []).filter((p) => p.required);
    return `
      <h2>${t('Benvenuto 👋')}</h2>
      <p class="lead">${t("Scegli cosa installare, la versione e la distro: l'installer scarica tutto, configura il database e crea il negozio.")}</p>
      ${!['live', 'cache'].includes(pl.source) ? `<div class="notice" style="margin:0 0 18px">${pl.source === 'fallback' ? t('Elenco versioni minimo (il repository di {p} non è raggiungibile).', { p: esc(pl.name) }) : t("Elenco versioni dall'ultima copia salvata (il repository di {p} non è raggiungibile).", { p: esc(pl.name) })}</div>` : ''}
      <div class="grid">
        ${select(t('Cosa vuoi installare'), 'platform', Object.values(S.boot.platforms).map((x) => [x.id, x.name]), { hint: S.f.platform === 'mageos' ? t('Distribuzione community di Magento, con aggiornamenti della community.') : t('Il Magento Open Source ufficiale.') })}
        ${select(t('Versione di {p}', { p: esc(pname()) }), 'version', opts)}
        <div class="field"><span>${t('Requisiti di questa versione')}</span><div class="chips" style="margin-top:4px"><span class="chip accent">PHP ${esc(phpHuman(v.php))}</span>${v.time ? `<span class="chip">${t('rilasciata {d}', { d: esc(v.time) })}</span>` : ''}${v.eol ? `<span class="chip ${isEol(v) ? 'fail' : ''}">${esc(eolText(v))}</span>` : ''}</div>${isEol(v) ? '<small>' + t('Versione non più supportata: niente patch di sicurezza, meglio una più recente.') + '</small>' : ''}</div>
      </div>

      <h3>Distro</h3>
      <div class="distros">${S.boot.distros.map((x) => `
        <label class="distro ${x.id === S.f.distro ? 'selected' : ''}">
          <input type="radio" name="distro" value="${esc(x.id)}" ${x.id === S.f.distro ? 'checked' : ''}>
          <h4>${esc(x.name)}</h4><div class="tag">${esc(x.tagline)}</div><p>${esc(x.description)}</p>
          <ul>${(x.highlights || []).map((h) => `<li>${esc(h)}</li>`).join('')}</ul>
        </label>`).join('')}</div>

      ${frontendSection()}

      <h3>${t('Catalogo')}</h3>
      ${field(t('Quanti prodotti prevedi di avere?'), 'products', { type: 'number', attrs: 'min="1" step="1"', hint: t('Decide il motore di ricerca.') })}
      <div id="search-mode" style="margin-top:10px">${searchModeBox()}</div>

      ${(required.length || optional.length) ? fold('distro', t('Moduli inclusi nella distro'), distroCount(), `<div class="pkg-list">
        ${required.map((p) => `<label class="check" style="opacity:.75"><input type="checkbox" checked disabled><span><b>${esc(p.label)}</b> <span class="chip">${t('sempre incluso')}</span><small>${esc(p.description || '')} · <code>${esc(p.name)}</code></small></span></label>`).join('')}
        ${optional.map((p) => `<label class="check"><input type="checkbox" data-pkg="${esc(p.name)}" ${S.f.packages.includes(p.name) ? 'checked' : ''}><span><b>${esc(p.label)}</b><small>${esc(p.description || '')} · <code>${esc(p.name)}</code></small></span></label>`).join('')}
      </div>`) : ''}

      ${mageosList()}

      <h3>${t('Opzioni')}</h3>
      <div class="checks">
        ${checkbox('sample_data', t('Installa i dati di esempio'), t('Catalogo demo (prodotti, categorie, CMS, regole). Utile per provare il negozio; aggiunge circa 20 pacchetti e alcune centinaia di MB.'))}
        <div id="msi-box">${msiBox()}</div>
        ${checkbox('italian_tax', t('Imposta le regole IVA italiane'), t("Prezzi IVA inclusa, aliquota ordinaria 22% e classi per le aliquote ridotte 10% e 4%, Italia come paese predefinito. Il calcolo segue l'indirizzo di spedizione: per vendere ad altri paesi UE aggiungi le rispettive aliquote."))}
      </div>
      <div id="sample-note">${sampleNote()}</div>

      <h3>${t('Dove installare')}</h3>
      ${field(t('Cartella di destinazione'), 'target', { full: true, ph: t('/var/www/mio-negozio'), hint: t('Percorso assoluto sul server. Deve essere vuota o non esistere: verrà creata. La document root del sito dovrà puntare a <code>pub/</code>.') })}
      ${actions(false, true)}`;
  }

  /** Domanda sul multi-magazzino: con la distro Light MSI è già escluso, quindi non si chiede. */
  const msiBox = () => distro()?.msi === false ? ''
    : checkbox('multi_stock', t('Uso più magazzini (MSI)'), t('Inventario multi-sorgente: più magazzini, sorgenti e stock. Se il negozio ha un solo magazzino lascia la casella vuota: i moduli MSI (circa 75) non vengono scaricati e resta lo stock classico, più leggero. I moduli SEO e FAQ di Mage-OS richiedono MSI.'));

  /** Con Hyvä i dati di esempio richiedono Luma: non lo escludiamo. */
  const sampleNote = () => S.f.frontend === 'hyva' && S.f.sample_data
    ? '<div class="notice" style="margin:10px 0 0">' + t('Con Hyvä i dati di esempio richiedono Luma: Luma e Blank restano installati (il tema attivo sarà comunque Hyvä).') + '</div>' : '';

  function searchModeBox() {
    const max = S.boot.mysql_search.max_products.toLocaleString(numLocale());
    if (!mysqlEligible()) {
      return `<div class="notice" style="margin:0">${S.f.products >= 1 ? t('Sopra i {max} prodotti serve OpenSearch o Elasticsearch: li configuri al passo «Database e ricerca».', { max }) : t('Indica un numero di prodotti valido.')}</div>`;
    }
    return `<div class="notice" style="margin:0">${t('Fino a {max} prodotti basta la <b>ricerca MySQL</b> (modulo Swissup, come su threecommerce): OpenSearch non viene installato né richiesto e il server resta più leggero.', { max })}</div>
      <div style="margin-top:10px">${checkbox('use_opensearch', t('Usa comunque OpenSearch / Elasticsearch'), t('Scegli questa opzione se prevedi di crescere o se hai bisogno delle funzioni del motore dedicato.'))}</div>`;
  }

  /** Tema del negozio (Luma o Hyvä) e checkout. */
  function frontendSection() {
    const fe = frontend();
    const parsed = parseHyva(S.f.hyva_keys);
    return `<h3>${t('Tema del negozio')}</h3>
      <div class="distros">${S.boot.frontends.map((x) => `
        <label class="distro ${x.id === S.f.frontend ? 'selected' : ''}">
          <input type="radio" name="frontend" value="${esc(x.id)}" ${x.id === S.f.frontend ? 'checked' : ''}>
          <h4>${esc(x.name)}</h4><div class="tag">${esc(x.tagline)}</div><p>${esc(x.description)}</p>
          <ul>${(x.highlights || []).map((h) => `<li>${esc(h)}</li>`).join('')}</ul>
        </label>`).join('')}</div>
      ${fe.repository ? `<div class="field full" style="margin-top:18px"><span>${t('Chiavi Hyvä')}</span>
        <textarea data-k="hyva_keys" rows="5" spellcheck="false" autocomplete="off" placeholder="composer config --auth http-basic.hyva-themes.repo.packagist.com token …&#10;composer config repositories.private-packagist composer https://hyva-themes.repo.packagist.com/${t('nome-progetto')}/">${esc(S.f.hyva_keys)}</textarea>
        <small>${t('Accedi a <a href="https://www.hyva.io" target="_blank" rel="noopener">hyva.io</a>, apri l\'area licenze del tuo account e copia il blocco con i comandi <code>composer config</code> (chiave e nome del progetto). Incollalo qui così com\'è: l\'installer ne ricava solo chiave e progetto.')}</small>
        <div id="hyva-parsed">${hyvaParsed(parsed)}</div></div>` : ''}
      ${fe.csp ? `<div style="margin-top:18px">${checkbox('hyva_csp', esc(fe.csp.label), esc(fe.csp.description))}</div>` : ''}
      <div class="field full" style="margin-top:18px"><span>${t('Checkout')}</span>
        <div class="checks">${fe.checkouts.map((c) => `<label class="check"><input type="radio" name="checkout" value="${esc(c.id)}" ${c.id === S.f.checkout ? 'checked' : ''}><span><b>${esc(c.label)}</b><small>${esc(c.description)}</small></span></label>`).join('')}</div></div>`;
  }
  const hyvaParsed = (p) => S.f.hyva_keys.trim() === '' ? '' : p
    ? `<span class="chip ok">${t('Chiavi riconosciute · progetto {p} · chiave {k}…', { p: esc(p.project), k: esc(p.key.slice(0, 4)) })}</span>`
    : '<span class="chip fail">' + t("Chiavi non riconosciute: servono la chiave e l'URL del repository con il nome del progetto") + '</span>';

  /** Avviso se tra i moduli scelti ce n'è uno che non gira sulla versione di Magento selezionata. */
  function mageosWarning() {
    const phpOld = selectedMageos().filter(tooOldPhp);
    const phpMsg = phpOld.length ? `<div class="notice" style="margin:0 0 14px">${t('<b>PHP {php} troppo vecchio per {mods}.</b> Serve PHP {min} o successivo da riga di comando: deseleziona il modulo o aggiorna il PHP.', { php: esc(cliPhp()), mods: phpOld.map((m) => esc(m.label)).join(', '), min: esc(phpOld.reduce((a, m) => (m.min_php.localeCompare(a, undefined, { numeric: true }) > 0 ? m.min_php : a), '0')) })}</div>` : '';
    const old = selectedMageos().filter(tooOld);
    if (!old.length) return phpMsg;
    return `<div class="notice" style="margin:0 0 14px">${t(old.length === 1 ? '<b>Versione di Magento troppo vecchia per {mods}.</b> Richiede Magento {min} o successivo: con la versione {v} composer segnalerebbe un conflitto e l\'installazione si fermerebbe. Scegli una versione più recente o deseleziona il modulo.' : '<b>Versione di Magento troppo vecchia per {mods}.</b> Richiedono Magento {min} o successivo: con la versione {v} composer segnalerebbe un conflitto e l\'installazione si fermerebbe. Scegli una versione più recente o deseleziona il modulo.', { mods: old.map((m) => esc(m.label)).join(', '), min: esc(old.reduce((a, m) => (vcmp(m.min_magento, a) > 0 ? m.min_magento : a), '0')), v: esc(S.f.version) })}</div>`;
  }

  /** Elenco dei moduli Mage-OS opzionali, raggruppati per area. */
  function mageosList() {
    const own = distroPackageNames();
    const groups = [...new Set(S.boot.mageos.map((m) => m.group))];
    return `<div id="mageos-warn" style="margin-top:28px">${mageosWarning()}</div>
      ${fold('mageos', t('Moduli Mage-OS'), mageosCount(), `<p class="muted" style="margin:0 0 12px;font-size:13px">${t('Moduli opzionali della community Mage-OS, installabili con qualsiasi distro (da Packagist, senza credenziali).')}</p>
      ${groups.map((g) => `<div class="group"><h4 class="pkg-group">${esc(g)}</h4><div class="pkg-list">
        ${S.boot.mageos.filter((m) => m.group === g).map((m) => {
          const included = own.includes(m.name) || inProduct(m);
          const off = isIncompatible(m);
          return `<label class="check" ${off || included ? 'style="opacity:.6"' : ''}><input type="checkbox" data-mageos="${esc(m.name)}" ${included || S.f.mageos.includes(m.name) ? 'checked' : ''} ${off || included ? 'disabled' : ''}><span><b>${esc(m.label)}</b>${included ? ` <span class="chip">${own.includes(m.name) ? t('incluso nella distro') : t('già incluso in {p} {v}', { p: esc(pname()), v: esc(S.f.version) })}</span>` : ''}${off ? ` <span class="chip">${m.needs_opensearch && useMysql() ? t('richiede OpenSearch') : m.needs_msi && noMsi() && !(m.incompatible_with || []).includes(S.f.distro) ? (distro()?.msi === false ? t('richiede MSI: non disponibile con questa distro') : t('richiede MSI: attiva il multi-magazzino')) : t('non disponibile con questa distro')}</span>` : ''}${tooOld(m) ? ` <span class="chip">${t('richiede Magento {v}+', { v: esc(m.min_magento) })}</span>` : ''}${tooOldPhp(m) ? ` <span class="chip">${t('richiede PHP {v}+', { v: esc(m.min_php) })}</span>` : ''}<small>${esc(m.description)} · <code>${esc(m.name)}</code></small></span></label>`;
        }).join('')}
      </div></div>`).join('')}`)}`;
  }

  /** Sezione richiudibile: parte chiusa, con il numero di voci attive nel titolo. */
  const fold = (key, title, count, body) => `<details class="fold" data-fold="${key}" ${S.open[key] ? 'open' : ''}>
    <summary><span class="fold-title">${esc(title)}</span><span class="fold-count" data-count="${key}">${esc(count)}</span><span class="fold-cta"></span></summary>
    <div class="fold-body">${body}</div></details>`;
  const distroCount = () => {
    const ps = distro()?.packages || [];
    const on = ps.filter((p) => p.required || S.f.packages.includes(p.name)).length;
    return t('{on} di {n} attivi', { on, n: ps.length });
  };
  const mageosCount = () => {
    const n = selectedMageos().length;
    return n ? (n === 1 ? t('{n} selezionato', { n }) : t('{n} selezionati', { n })) : t('nessuno selezionato');
  };
  function updateCounts() {
    $$('[data-count=distro]').forEach((e) => { e.textContent = distroCount(); });
    $$('[data-count=mageos]').forEach((e) => { e.textContent = mageosCount(); });
  }

  // ------------------------------------------------------------ step 1
  async function runRequirements() {
    S.reqLoading = true;
    S.req = null;
    renderStep();
    const r = await api('requirements', { platform: S.f.platform, version: S.f.version, distro: S.f.distro, packages: S.f.packages, target: S.f.target });
    S.reqLoading = false;
    S.req = r;
    if (S.step === 1) renderStep();
  }

  function step1() {
    if (S.reqLoading || !S.req) {
      return `<h2>${t('Requisiti del server')}</h2><p class="lead">${t('Controllo in corso per {p} {v}…', { p: esc(pname()), v: esc(S.f.version) })}</p><div class="boot" style="padding:40px"><div class="spinner"></div></div>${actions(true, false)}`;
    }
    const r = S.req;
    if (r.error) return `<h2>${t('Requisiti del server')}</h2><p class="err">${esc(r.error)}</p>${actions(true, false)}`;
    const cls = r.blocking ? 'fail' : r.warnings ? 'warn' : 'ok';
    const msg = r.blocking
      ? (r.blocking === 1 ? t('<b>1 requisito non soddisfatto.</b> Risolvi i punti in rosso e ricontrolla.') : t('<b>{n} requisiti non soddisfatti.</b> Risolvi i punti in rosso e ricontrolla.', { n: r.blocking }))
      : r.warnings ? (r.warnings === 1 ? t('<b>Tutto a posto per procedere</b>, con 1 avviso da valutare.') : t('<b>Tutto a posto per procedere</b>, con {n} avvisi da valutare.', { n: r.warnings })) : t('<b>Il server soddisfa tutti i requisiti.</b>');
    return `
      <h2>${t('Requisiti del server')}</h2>
      <p class="lead">${t('Verifica per {p} {v} — PHP {php}.', { p: esc(pname()), v: esc(S.f.version), php: esc(phpHuman(r.version?.php)) })}</p>
      <div class="summary-bar ${cls}"><span style="font-size:22px">${r.blocking ? '⛔' : r.warnings ? '⚠️' : '✅'}</span><div style="flex:1">${msg}</div><button class="btn small" data-act="recheck">${t('Ricontrolla')}</button></div>
      ${r.groups.map((g) => `<div class="group"><h3>${esc(g.title)}</h3><div class="group-box">${g.checks.map((c) => `
        <div class="req ${c.status}"><span class="ico">${c.status === 'ok' ? '✓' : c.status === 'warn' ? '!' : '✕'}</span>
        <div><b>${esc(c.label)}</b> <span class="d">— ${esc(c.detail)}</span>${c.status !== 'ok' && c.hint ? `<div class="hint">${esc(c.hint)}</div>` : ''}</div></div>`).join('')}</div></div>`).join('')}
      <p class="muted" style="font-size:13px">${t('Versioni minime di database e motore di ricerca vengono verificate al passo successivo, quando inserisci i dati di connessione.')}</p>
      ${actions(true, true, t('Avanti →'), !!r.blocking)}`;
  }

  // ------------------------------------------------------------ step 2
  async function testDb() {
    setBox('#db-test', 'warn', '<div class="spinner"></div> ' + t('Connessione in corso…'));
    S.db = await api('test_db', { host: S.f.db_host, name: S.f.db_name, user: S.f.db_user, password: S.f.db_password, platform: S.f.platform, version: S.f.version });
    setBox('#db-test', S.db.status || 'fail', esc(S.db.message || S.db.error));
    const ub = $('#unsupported-box');
    if (ub) { ub.innerHTML = unsupportedBox(); bind(ub); }
    updateNext();
  }
  async function testSearch(silent) {
    if (!silent) setBox('#search-test', 'warn', '<div class="spinner"></div> ' + t('Connessione in corso…'));
    S.search = await api('test_search', { host: S.f.search_host, port: S.f.search_port, user: S.f.search_user, password: S.f.search_password, platform: S.f.platform, version: S.f.version });
    S.f.search_ssl = S.search.scheme === 'https';
    if (S.search.engine && !S.touched.search_engine) {
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
      <h2>${t('Database e ricerca')}</h2>
      <p class="lead">${useMysql() ? t('{p} usa MySQL o MariaDB per i dati e per la ricerca.', { p: esc(pname()) }) : t('{p} usa MySQL o MariaDB per i dati e OpenSearch o Elasticsearch per catalogo e ricerca.', { p: esc(pname()) })}</p>
      <h3 style="margin-top:0">${t('Database')}</h3>
      <div class="grid">
        ${field(t('Host'), 'db_host', { ph: 'localhost', hint: t('Anche host:porta o percorso del socket.') })}
        ${field(t('Nome database'), 'db_name', { ph: 'magento' })}
        ${field(t('Utente'), 'db_user')}
        ${field(t('Password'), 'db_password', { type: 'password' })}
        ${field(t('Prefisso tabelle (opzionale)'), 'db_prefix', { ph: t('es. mg_') })}
      </div>
      <div class="checks" style="margin-top:14px">
        ${checkbox('create_db', t('Crea il database se manca'), t("Richiede che l'utente abbia il permesso CREATE."))}
        ${checkbox('cleanup_db', t('Svuota il database se non è vuoto'), t('Elimina le tabelle esistenti prima di installare. Usalo solo su database di prova.'))}
      </div>
      <div style="margin-top:14px"><button class="btn small" data-act="test-db">${t('Testa connessione al database')}</button></div>
      ${testBoxHtml(S.db, 'db-test')}
      <div id="unsupported-box">${unsupportedBox()}</div>

      ${useMysql() ? `<h3>${t('Motore di ricerca')}</h3><div class="notice" style="margin:0">${t('Ricerca MySQL (catalogo fino a {n} prodotti): nessun motore da configurare. Per usare OpenSearch torna al primo passo.', { n: S.f.products.toLocaleString(numLocale()) })}</div>` : `<h3>${t('Motore di ricerca')}</h3>
      <div class="grid">
        ${select(t('Motore'), 'search_engine', [['opensearch', 'OpenSearch'], ['elasticsearch8', 'Elasticsearch 8'], ...(version()?.es7 ? [['elasticsearch7', 'Elasticsearch 7']] : [])])}
        ${field(t('Prefisso indice'), 'search_prefix')}
        ${field(t('Host'), 'search_host')}
        ${field(t('Porta'), 'search_port', { type: 'number' })}
        ${field(t('Utente (se protetto)'), 'search_user')}
        ${field(t('Password'), 'search_password', { type: 'password' })}
      </div>
      <div style="margin-top:14px"><button class="btn small" data-act="test-search">${t('Testa connessione alla ricerca')}</button></div>
      ${testBoxHtml(S.search, 'search-test')}`}
      <p class="muted" style="font-size:13px;margin:18px 0 0">${useMysql() ? t('Premendo <b>Avanti</b> la connessione al database viene verificata in automatico: il test manuale serve solo se vuoi controllare prima.') : t('Premendo <b>Avanti</b> la connessione a database e motore di ricerca viene verificata in automatico: il test manuale serve solo se vuoi controllare prima.')}</p>
      ${actions(true, true, t('Avanti →'))}`;
  }
  function unsupportedBox() {
    if (!S.db?.unsupported) return '';
    return `<div style="margin-top:12px"><label class="check"><input type="checkbox" data-k="allow_unsupported_db" ${S.f.allow_unsupported_db ? 'checked' : ''}><span><b>${t('Procedi comunque (solo sviluppo)')}</b><small>${t('Aggiunge questa versione del database a <code>app/etc/di.xml</code>. Non è una configurazione supportata: da non usare in produzione.')}</small></span></label></div>`;
  }
  function step2Ok() {
    const dbOk = S.db && (S.db.status === 'ok' || (S.db.status === 'warn' && S.f.cleanup_db) || (S.db.unsupported && S.f.allow_unsupported_db));
    return !!(dbOk && (useMysql() || (S.search && ['ok', 'warn'].includes(S.search.status))));
  }

  // ------------------------------------------------------------ step 3
  function step3() {
    const L = S.boot.locale;
    const repos = neededRepos();
    return `
      <h2>${t('Negozio e amministratore')}</h2>
      <p class="lead">${t("Lingua, valuta e fuso orario vengono impostati all'installazione. Le proposte dipendono dalla distro scelta.")}</p>
      <div class="grid">
        ${field(t('Nome del negozio'), 'store_name')}
        ${field(t('Base URL'), 'base_url', { ph: t('http://mio-negozio.test/'), hint: t('Indirizzo con cui il sito sarà raggiungibile.') })}
        ${select(t('Lingua'), 'language', Object.entries(L.languages).map(([k, v]) => [k, `${v} (${k})`]))}
        ${select(t('Valuta'), 'currency', Object.entries(L.currencies).map(([k, v]) => [k, `${k} — ${v}`]))}
        ${select(t('Fuso orario'), 'timezone', L.timezones.map((t) => [t, t]))}
        ${field(t('Percorso admin'), 'backend_frontname', { hint: t('Il pannello sarà su <code>/admin</code> o sul percorso scelto.') })}
      </div>

      <h3>${t('Utente amministratore')}</h3>
      <div class="grid">
        ${field(t('Nome'), 'admin_firstname')}
        ${field(t('Cognome'), 'admin_lastname')}
        ${field(t('Email'), 'admin_email', { type: 'email' })}
        ${field(t('Username'), 'admin_user')}
        <div class="field full"><span>${t('Password')}</span>
          <div class="input-row"><input type="password" data-k="admin_password" value="${esc(S.f.admin_password)}" autocomplete="new-password"><button class="btn small" data-act="gen-pw" type="button">${t('Genera')}</button></div>
          <small>${t('Almeno 7 caratteri con lettere e numeri. Salvala: non verrà mostrata di nuovo.')}</small></div>
      </div>

      ${repos.length ? `<h3>${t('Credenziali dei repository privati')}</h3><div class="grid">${repos.map((r) => `
        <div class="field full"><span>${esc(r.name)} <code>${esc(r.host)}</code></span></div>
        <label class="field"><span>${t('Utente')}</span><input type="text" data-cred="${esc(r.host)}" data-ck="user" value="${esc(S.f.credentials[r.host]?.user || '')}" autocomplete="off"></label>
        <label class="field"><span>${t('Password / token')}</span><input type="password" data-cred="${esc(r.host)}" data-ck="password" value="${esc(S.f.credentials[r.host]?.password || '')}" autocomplete="off"></label>`).join('')}</div>
        <div style="margin-top:12px">${checkbox('save_auth', t('Salva le credenziali in auth.json del progetto'), t('Comodo per i futuri composer update; il file viene creato con permessi 0600. Disattivalo se non vuoi lasciare credenziali sul server.'))}</div>` : ''}

      <h3>${t('Opzioni')}</h3>
      <div class="checks">
        ${select(t('Modalità di deploy'), 'deploy_mode', [['developer', t('Developer (rapida, consigliata per iniziare)')], ['production', t('Production (compila DI e asset: richiede molti minuti)')]])}
        <span></span>
        ${checkbox('disable_2fa', t("Disattiva l'autenticazione a due fattori dell'admin"), t('Solo per ambienti di sviluppo: evita la configurazione del 2FA al primo accesso.'))}
        ${checkbox('install_cron', t('Installa il cron di Magento'), t("Aggiunge le righe al crontab dell'utente che esegue PHP."))}
      </div>
      ${S.errors.length ? `<div class="notice" style="margin-top:20px;border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08);color:#fecaca"><b>${t('Da correggere:')}</b><ul style="margin:6px 0 0;padding-left:20px">${S.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul></div>` : ''}
      ${actions(true, true, t('Riepilogo →'))}`;
  }

  function validateStep3() {
    const f = S.f, e = [];
    if (!f.store_name.trim()) e.push(t('Inserisci il nome del negozio.'));
    if (!/^https?:\/\/[^\s/]+/i.test(f.base_url)) e.push(t('Il Base URL deve iniziare con http:// o https://'));
    if (!/^[A-Za-z0-9_-]{2,}$/.test(f.backend_frontname)) e.push(t('Percorso admin non valido (lettere, numeri, - e _).'));
    if (!f.admin_firstname.trim() || !f.admin_lastname.trim()) e.push(t("Nome e cognome dell'amministratore sono obbligatori."));
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.admin_email)) e.push(t('Email amministratore non valida.'));
    if (!/^[A-Za-z0-9_.@-]{3,40}$/.test(f.admin_user)) e.push(t('Username non valido (almeno 3 caratteri).'));
    if (f.admin_password.length < 7 || !/[A-Za-z]/.test(f.admin_password) || !/\d/.test(f.admin_password)) e.push(t('La password deve avere almeno 7 caratteri, con lettere e numeri.'));
    const need2fa = selectedMageos().filter((m) => m.needs_2fa);
    if (f.disable_2fa && need2fa.length) e.push(t(need2fa.length === 1 ? "{mods} dipende dall'autenticazione a due fattori: disattiva questa opzione o deseleziona il modulo." : "{mods} dipendono dall'autenticazione a due fattori: disattiva questa opzione o deseleziona il modulo.", { mods: need2fa.map((m) => m.label).join(', ') }));
    for (const r of neededRepos()) {
      const c = f.credentials[r.host] || {};
      if (!c.user || !c.password) e.push(t('Inserisci utente e password per {h}.', { h: r.host }));
    }
    return e;
  }

  // ------------------------------------------------------------ step 4
  function step4() {
    if (S.job) return jobShell();
    const f = S.f, d = distro(), v = version();
    const pk = [...selectedPackages(), ...selectedMageos()];
    const row = (k, val) => `<dt>${t(k)}</dt><dd>${val}</dd>`;
    const yn = (b) => (b ? t('sì') : t('no'));
    return `
      <h2>${t('Riepilogo')}</h2>
      <p class="lead">${t('Controlla i dati: quando premi <b>Installa</b> parte il download e la configurazione. Puoi lasciare la pagina aperta e seguire il log in diretta.')}</p>
      <div class="recap">
        <section><h4>${esc(pname())}</h4><dl>${row('Versione', esc(v.version))}${row('Distro', esc(d.name))}${row('Tema', esc(frontend().name + (f.hyva_csp && frontend().csp ? ' (CSP)' : '')))}${row('Checkout', esc(frontend().checkouts.find((c) => c.id === f.checkout)?.label || ''))}${row('Moduli extra', pk.length)}${row('Dati di esempio', yn(f.sample_data))}${row('IVA italiana', yn(f.italian_tax))}${row('Multi-magazzino (MSI)', yn(!noMsi()))}${row('Modalità', esc(f.deploy_mode))}</dl></section>
        <section><h4>${t('Destinazione')}</h4><dl>${row('Cartella', esc(f.target))}${row('Base URL', esc(f.base_url))}${row('Admin', esc('/' + f.backend_frontname))}</dl></section>
        <section><h4>${t('Database')}</h4><dl>${row('Server', esc((S.db?.server || '') + ' ' + (S.db?.version || '')))}${row('Host', esc(f.db_host))}${row('Database', esc(f.db_name))}${row('Utente', esc(f.db_user))}${row('Svuota', yn(f.cleanup_db))}</dl></section>
        <section><h4>${t('Ricerca')}</h4><dl>${useMysql() ? row('Motore', 'MySQL (Swissup)') + row('Prodotti previsti', esc(f.products)) : row('Motore', esc(f.search_engine)) + row('Host', esc(f.search_host + ':' + f.search_port)) + row('Prefisso', esc(f.search_prefix))}</dl></section>
        <section><h4>${t('Negozio')}</h4><dl>${row('Nome', esc(f.store_name))}${row('Lingua', esc(f.language))}${row('Valuta', esc(f.currency))}${row('Fuso orario', esc(f.timezone))}</dl></section>
        <section><h4>${t('Amministratore')}</h4><dl>${row('Nome', esc(f.admin_firstname + ' ' + f.admin_lastname))}${row('Email', esc(f.admin_email))}${row('Username', esc(f.admin_user))}${row('Password', '••••••••')}</dl></section>
      </div>
      ${pk.length ? `<h3>${t('Pacchetti che verranno installati')}</h3><div class="chips">${pk.map((p) => `<span class="chip">${esc(p.name)}</span>`).join('')}</div>` : ''}
      ${mageosWarning() ? `<div style="margin-top:20px">${mageosWarning()}</div>` : ''}
      <div id="start-err"></div>
      <div class="actions"><button class="btn" data-nav="back">${t('← Indietro')}</button><div class="right"><button class="btn primary" data-act="start" style="padding:13px 26px;font-size:16px">🚀 ${t('Installa {p}', { p: esc(pname()) })}</button></div></div>`;
  }

  async function startInstall(btn) {
    btn.disabled = true;
    btn.textContent = t('Avvio…');
    const f = S.f;
    const payload = { ...f, packages: f.packages, mageos: selectedMageos().map((m) => m.name) };
    const r = await api('start', payload);
    if (r.busy && r.job) {
      S.job = { id: r.job, offset: 0, state: 'running', steps: [], logEl: null, timer: null };
      saveJob(S.job);
      renderStep();
      return;
    }
    if (r.error) {
      btn.disabled = false;
      btn.textContent = '🚀 ' + t('Installa {p}', { p: pname() });
      $('#start-err').innerHTML = `<div class="notice" style="margin-top:20px;border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08);color:#fecaca"><b>${esc(r.error)}</b>${(r.errors || []).length ? `<ul style="margin:6px 0 0;padding-left:20px">${r.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul>` : ''}</div>`;
      return;
    }
    S.job = { id: r.job, offset: 0, state: 'running', steps: [], logEl: null, timer: null };
    saveJob(S.job);
    renderStep();
  }

  function jobShell() {
    return `
      <h2>${t('Installazione in corso')}</h2>
      <p class="lead" id="job-lead">${esc(pname())} ${esc(S.f.version)} · ${esc(S.f.target)}</p>
      <div class="progress-top"><div class="bar"><i id="job-bar"></i></div><b id="job-pct">0%</b></div>
      <div class="tasks" id="job-tasks"></div>
      <div id="job-result"></div>
      <details open id="job-log-wrap"><summary class="label" style="cursor:pointer;margin:6px 0 10px">${t('Log in diretta')}</summary><div class="term" id="job-log"></div></details>
      <div class="actions" id="job-actions"><span></span><div class="right"><button class="btn danger small" data-act="cancel">${t('Annulla installazione')}</button></div></div>`;
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
      return `<div class="task ${s.state}"><span class="st">${st}</span><span>${esc(s.label)}</span><span class="det">${s.state === 'skipped' ? t('non necessario') : esc(s.detail || '')}</span></div>`;
    }).join('');
    let tot = 0, got = 0;
    for (const s of j.steps) {
      if (s.state === 'skipped') continue;
      const w = WEIGHTS[s.id] || 5;
      tot += w;
      got += w * (s.state === 'ok' ? 1 : s.state === 'running' ? (s.progress ?? 0.15) : 0);
    }
    const pct = (j.state === 'done' || j.state === 'demo') ? 100 : Math.min(99, Math.round(tot ? (got / tot) * 100 : 0));
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
      const lead = $('#job-lead');
      if (r.error) {
        j.fails = (j.fails || 0) + 1;
        if (j.fails >= 4 && lead) {
          lead.textContent = (r._status === 403 ? t('Sessione scaduta: ricarica la pagina.') + ' ' : t('Non riesco a raggiungere il server: {e}', { e: r.error }) + ' ')
            + t("L'installazione prosegue in background.");
        }
        return;
      }
      if (j.fails >= 4 && lead) lead.textContent = `${pname()} ${S.f.version} · ${S.f.target}`;
      j.fails = 0;
      j.offset = r.offset;
      appendLog(r.log);
      j.steps = r.status.steps || [];
      j.status = r.status;
      drawTasks();
      if (['done', 'demo', 'failed', 'cancelled'].includes(r.status.state)) {
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
    if (j.state === 'demo') {
      $('#job-lead').textContent = t('Demo conclusa');
      const h = $('#panel h2'); if (h) h.textContent = t('Simulazione completata');
      out.innerHTML = `
        <div class="success"><div class="big">🙏</div><h2 style="margin:0 0 4px">${t('Grazie per aver provato la demo!')}</h2><p class="muted">${t("In questo ambiente l'installazione non viene completata: non è stato creato nessun file, database o negozio.")}</p></div>`;
      act.innerHTML = `<span></span><div class="right"><button class="btn primary" data-act="restart-demo">${t('↻ Riprova la demo')}</button></div>`;
      $('#job-log-wrap').open = false;
      saveJob(null);
    } else if (j.state === 'done') {
      const r = st.result;
      $('#job-lead').textContent = t('Fatto!');
      out.innerHTML = `
        <div class="success"><div class="big">🎉</div><h2 style="margin:0 0 4px">${t('{p} {v} è installato', { p: esc(pname()), v: esc(r.version) })}</h2><p class="muted">${t('Tempo totale: {m} min {s} s · modalità {mode}', { m: Math.floor(r.duration / 60), s: r.duration % 60, mode: esc(r.mode) })}</p></div>
        <div class="links-grid">
          <div class="link-card"><small>${t('Negozio')}</small><div class="v"><a href="${esc(r.storefront)}" target="_blank" rel="noopener">${esc(r.storefront)}</a></div></div>
          <div class="link-card"><small>${t('Pannello admin')}</small><div class="v"><a href="${esc(r.admin)}" target="_blank" rel="noopener">${esc(r.admin)}</a></div></div>
          <div class="link-card"><small>${t('Utente admin')}</small><div class="v">${esc(r.admin_user)}</div></div>
          <div class="link-card"><small>${t('Password admin')}</small><div class="v"><span id="pw-show">••••••••••••</span> <button class="btn small" data-act="reveal-pw" style="margin-left:8px">${t('Mostra')}</button> <button class="btn small" data-act="copy-pw">${t('Copia')}</button></div></div>
          <div class="link-card" style="grid-column:1/-1"><small>${t('Cartella')}</small><div class="v">${esc(r.target)}</div></div>
        </div>
        ${(r.warnings || []).map((w) => `<div class="notice">${esc(w)}</div>`).join('')}
        <div class="notice" id="self-remove">${t("Installazione riuscita: elimino l'installer dal server…")}</div>`;
      act.innerHTML = `<button class="btn danger" data-act="remove" hidden>${t('Rimuovi installer')}</button><div class="right"><a class="btn primary" href="${esc(r.storefront)}" target="_blank" rel="noopener" style="text-decoration:none">${t('Apri il negozio →')}</a></div>`;
      $('#job-log-wrap').open = false;
      selfRemove();
    } else {
      $('#job-lead').textContent = j.state === 'cancelled' ? t('Installazione annullata') : t('Installazione non riuscita');
      out.innerHTML = `<div class="notice" style="border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08);color:#fecaca;margin-bottom:16px"><b style="white-space:pre-line">${esc(st.error || t('Errore sconosciuto'))}</b><br>${t('Controlla il log qui sotto per il dettaglio.')} ${st.cleaned ? t('La cartella <code>{d}</code> è stata ripulita: puoi riprovare.', { d: esc(S.f.target) }) : t('La cartella <code>{d}</code> può contenere file parziali: svuotala prima di riprovare.', { d: esc(S.f.target) })}</div>`;
      act.innerHTML = `<button class="btn" data-act="copy-log">${t('Copia il log')}</button><div class="right"><button class="btn primary" data-act="retry">${t('← Torna ai dati')}</button></div>`;
    }
  }

  /** A installazione riuscita l'installer si cancella da solo; se non ci riesce, resta bloccato e il pulsante manuale. */
  async function selfRemove() {
    const note = $('#self-remove');
    let r = {};
    try { r = await api('remove', {}); } catch (e) { /* installer già sparito o rete caduta */ }
    if (!note) return;
    if (r.ok) {
      saveJob(null);
      note.textContent = t("L'installer è stato eliminato dal server: puoi chiudere questa pagina.") + ((r.left || []).length ? ' ' + t("Nella cartella sono rimasti altri file, non dell'installer: {l}.", { l: r.left.join(', ') }) : '');
    } else {
      note.textContent = (r.error ? r.error.replace(/\.$/, '') : t("Non sono riuscito a eliminare l'installer") + ((r.failed || []).length ? ' ' + t('(rimasti: {l})', { l: r.failed.join(', ') }) : '')) + (r.locked ? t(': è bloccato, cancellalo a mano dal server o riprova con il pulsante.') : t(': cancellalo a mano dal server o riprova con il pulsante.'));
      const b = $('[data-act=remove]');
      if (b) b.hidden = false;
    }
  }

  // ----------------------------------------------------------------- wiring
  function updateNext() {
    const btn = $('[data-nav=next]');
    if (!btn) return;
  }

  const EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  const EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';

  /** Mostra o nasconde il contenuto di un campo password e aggiorna l'occhio accanto. */
  function setPwVisible(input, visible) {
    input.type = visible ? 'text' : 'password';
    const b = input.parentElement.querySelector('.pw-eye');
    if (b) { b.innerHTML = visible ? EYE_OFF : EYE; b.title = b.ariaLabel = visible ? t('Nascondi la password') : t('Mostra la password'); }
  }

  /** Affianca a ogni campo password l'occhio per vederla. */
  function addPwEyes(scope) {
    $$('input[type=password]', scope).forEach((el) => {
      if (el.parentElement.classList.contains('pw-wrap')) return;
      const wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      el.replaceWith(wrap);
      wrap.append(el);
      wrap.insertAdjacentHTML('beforeend', '<button type="button" class="pw-eye" data-act="toggle-eye"></button>');
      setPwVisible(el, false);
    });
  }

  /** Cambia lingua senza perdere i dati inseriti: i testi del server si ricaricano, i controlli già fatti si rifanno. */
  async function setLang(l) {
    if (l === lang || !['it', 'en'].includes(l)) return;
    lang = l;
    document.documentElement.lang = l;
    try { document.cookie = 'lang=' + l + '; path=/; max-age=31536000; SameSite=Lax'; } catch (e) { /* cookie bloccati */ }
    const b = await api('bootstrap');
    if (!b.error) S.boot = b;
    S.req = null; S.db = null; S.search = null;
    if (S.job) {
      // Installazione in corso o conclusa: si aggiorna solo il contorno, il pannello (con la rimozione automatica) non si ridisegna.
      const side = $('.side');
      if (side) { side.innerHTML = sideHtml(); renderSide(); }
      return;
    }
    render();
  }

  function onPlatformChanged() {
    S.f.version = platform().versions[0].version;
    if (!S.touched.target) S.f.target = S.f.target.replace(/[^/]*$/, defaultName());
    if (!S.touched.store_name) S.f.store_name = pname() + ' Store';
    S.touched.platform = false;
    S.req = null; S.db = null; S.search = null; syncDerived(); render();
  }

  function bind(scope) {
    addPwEyes(scope);
    $$('[data-k]', scope).forEach((el) => {
      const k = el.dataset.k;
      const handler = () => {
        const val = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? Number(el.value) : el.value);
        S.f[k] = val;
        S.touched[k] = true;
        if (k === 'target') { S.req = null; syncDerived(); }
        if (k === 'version') { S.req = null; S.db = null; S.search = null; S.maxStep = S.step; renderSide(); renderStep(); return; }
        if (k === 'platform') { onPlatformChanged(); return; }
        if (['db_host', 'db_name', 'db_user', 'db_password'].includes(k)) { S.db = null; const b = $('#db-test'); if (b) b.hidden = true; const ub = $('#unsupported-box'); if (ub) ub.innerHTML = ''; }
        if (k.startsWith('search_') && k !== 'search_engine') { S.search = null; const b = $('#search-test'); if (b) b.hidden = true; }
        if (k === 'cleanup_db' || k === 'allow_unsupported_db') updateNext();
        if (['db_host', 'db_name', 'db_user', 'db_password'].includes(k) || k.startsWith('search_')) updateNext();
        if (k === 'multi_stock') {
          S.f.mageos = S.f.mageos.filter((n) => !isIncompatible(S.boot.mageos.find((m) => m.name === n) || {}));
          renderStep(); return; // i moduli che richiedono MSI cambiano stato
        }
        if (k === 'sample_data') { S.req = null; const n = $('#sample-note'); if (n) n.innerHTML = sampleNote(); }
        if (k === 'products' || k === 'use_opensearch') {
          const m = $('#search-mode');
          if (m) { m.innerHTML = searchModeBox(); bind(m); }
        }
      };
      el.addEventListener(el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', handler);
    });
    $$('details[data-fold]', scope).forEach((d) => d.addEventListener('toggle', () => { S.open[d.dataset.fold] = d.open; }));
    $$('[data-mageos]', scope).forEach((el) => el.addEventListener('change', () => {
      const n = el.dataset.mageos;
      S.f.mageos = el.checked ? [...new Set([...S.f.mageos, n])] : S.f.mageos.filter((x) => x !== n);
      const w = $('#mageos-warn'); if (w) w.innerHTML = mageosWarning();
      updateCounts();
    }));
    $$('[data-pkg]', scope).forEach((el) => el.addEventListener('change', () => {
      const n = el.dataset.pkg;
      S.f.packages = el.checked ? [...new Set([...S.f.packages, n])] : S.f.packages.filter((x) => x !== n);
      S.req = null;
      updateCounts();
    }));
    $$('input[name=frontend]', scope).forEach((el) => el.addEventListener('change', () => {
      S.f.frontend = el.value;
      S.f.hyva_csp = false;
      S.f.checkout = frontend().checkouts[0].id;
      renderStep();
    }));
    $$('input[name=checkout]', scope).forEach((el) => el.addEventListener('change', () => { S.f.checkout = el.value; }));
    const keys = $('[data-k=hyva_keys]', scope);
    if (keys) keys.addEventListener('input', () => { $('#hyva-parsed').innerHTML = hyvaParsed(parseHyva(keys.value)); });
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
        if (!S.f.target.startsWith('/')) { alert(t('Inserisci un percorso assoluto per la cartella di destinazione.')); return; }
        if (!(S.f.products >= 1)) { alert(t('Indica quanti prodotti prevedi di avere a catalogo.')); return; }
        if (!hyvaOk()) { alert(t("Incolla le chiavi Hyvä copiate dall'area licenze di hyva.io.")); return; }
        S.req = null;
      }
      if (S.step === 2) {
        // Il test di connessione parte da solo se non è già stato fatto con questi dati.
        const label = nav.textContent;
        nav.disabled = true;
        nav.textContent = t('Verifica in corso…');
        if (!S.db) await testDb();
        if (!useMysql() && !S.search) await testSearch(false);
        nav.disabled = false;
        nav.textContent = label;
        if (!step2Ok()) {
          const bad = $('#db-test.fail, #db-test.warn, #unsupported-box:not(:empty)') || $('#search-test');
          if (bad) bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
      }
      if (S.step === 3) {
        S.errors = validateStep3();
        if (S.errors.length) { renderStep(); return; }
      }
      S.step = Math.min(4, S.step + 1);
      S.maxStep = Math.max(S.maxStep || 0, S.step);
      render();
      return;
    }
    if (!act) return;
    switch (act.dataset.act) {
      case 'recheck': S.req = null; runRequirements(); break;
      case 'restart-demo': saveJob(null); location.reload(); break;
      case 'test-db': testDb(); break;
      case 'test-search': S.touched.search_engine = true; testSearch(false); break;
      case 'gen-pw': { S.f.admin_password = genPassword(); const i = $('[data-k=admin_password]'); i.value = S.f.admin_password; setPwVisible(i, true); break; }
      case 'toggle-eye': setPwVisible(act.parentElement.querySelector('input'), act.parentElement.querySelector('input').type === 'password'); break;
      case 'start': startInstall(act); break;
      case 'cancel': if (confirm(t("Annullare l'installazione in corso?"))) { act.disabled = true; await api('cancel', { job: S.job.id }); } break;
      case 'goto': if (canGo(+act.dataset.step)) { S.step = +act.dataset.step; render(); } break;
      case 'switch-platform':
        if (S.job) break; // installazione in corso o conclusa: la piattaforma non si cambia
        S.f.platform = otherPlatform().id; S.step = 0; S.maxStep = 0; onPlatformChanged(); break;
      case 'retry': S.job = null; saveJob(null); S.step = 3; render(); break;
      case 'copy-log': copy($('#job-log').innerText); act.textContent = t('Copiato ✓'); break;
      case 'reveal-pw': { const s = $('#pw-show'); const hidden = s.textContent.startsWith('•'); s.textContent = hidden ? S.f.admin_password : '••••••••••••'; act.textContent = hidden ? t('Nascondi') : t('Mostra'); break; }
      case 'copy-pw': copy(S.f.admin_password); act.textContent = t('Copiata ✓'); break;
      case 'lang': setLang(act.dataset.lang); break;
      case 'remove':
        if (confirm(t("Eliminare definitivamente l'installer dal server?"))) {
          const r = await api('remove', {});
          if (r.ok) { saveJob(null); root.innerHTML = '<div class="boot"><p>' + t('Installer rimosso. Puoi chiudere questa pagina.') + '</p></div>'; }
          else alert(((r.error || '').replace(/\.$/, '') || t('Rimozione non riuscita') + ((r.failed || []).length ? ' ' + t('(rimasti: {l})', { l: r.failed.join(', ') }) : '')) + t(': cancella a mano i file dal server.') + (r.locked ? ' ' + t("L'installer resta bloccato.") : ''));
        }
        break;
    }
  });

  init();
})();
