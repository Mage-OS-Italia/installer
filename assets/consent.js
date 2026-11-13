/*
 * Demo: consenso ai cookie e Google Analytics 4.
 * GA4 si carica solo dopo "Accetta": prima del consenso non parte nessuna richiesta verso Google.
 * La scelta resta nel cookie tecnico "cookie_consent" per 6 mesi, poi il banner ricompare.
 */
(() => {
  const me = document.currentScript;
  const GA_ID = (me && me.dataset.ga) || '';
  const KEY = 'cookie_consent';
  const MAX_AGE = 60 * 60 * 24 * 182;

  const TXT = {
    it: {
      text: 'Usiamo cookie tecnici, necessari al funzionamento, e, solo con il tuo consenso, cookie analitici di Google Analytics per contare visite e download in forma aggregata.',
      more: 'Cookie policy', accept: 'Accetta', reject: 'Rifiuta', label: 'Preferenze cookie',
    },
    en: {
      text: 'We use technical cookies, needed for the site to work, and, only with your consent, Google Analytics cookies to count visits and downloads in aggregate form.',
      more: 'Cookie policy', accept: 'Accept', reject: 'Reject', label: 'Cookie preferences',
    },
  };
  const tx = () => TXT[document.documentElement.lang === 'en' ? 'en' : 'it'];

  const read = () => {
    const m = document.cookie.match(/(?:^|;\s*)cookie_consent=(granted|denied)/);
    return m ? m[1] : null;
  };
  const write = (v) => {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${KEY}=${v}; path=/; max-age=${MAX_AGE}; SameSite=Lax${secure}`;
  };

  let loaded = false;
  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }

  function loadGa() {
    if (loaded || !GA_ID) return;
    loaded = true;
    gtag('consent', 'default', { analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    gtag('js', new Date());
    gtag('config', GA_ID, { allow_google_signals: false, allow_ad_personalization_signals: false });
    const s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
    document.head.appendChild(s);
  }

  /** Revoca: cancella i cookie _ga di questo dominio (e del dominio padre, dove GA li scrive di solito). */
  function clearGa() {
    const host = location.hostname.split('.');
    const domains = ['', location.hostname];
    for (let i = 1; i < host.length - 1; i++) domains.push('.' + host.slice(i).join('.'));
    for (const c of document.cookie.split(';')) {
      const name = c.split('=')[0].trim();
      if (!/^_ga(_|$)/.test(name)) continue;
      for (const d of domains) document.cookie = `${name}=; path=/; max-age=0${d ? '; domain=' + d : ''}`;
    }
  }

  let banner = null;
  function close() { if (banner) { banner.remove(); banner = null; } }

  function choose(v) {
    const before = read();
    write(v);
    close();
    if (v === 'granted') loadGa();
    else if (before === 'granted' || loaded) { clearGa(); location.reload(); }
  }

  function open() {
    close();
    const T = tx();
    banner = document.createElement('div');
    banner.className = 'cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', T.label);
    banner.innerHTML = `<p>${T.text} <a href="?p=cookie">${T.more}</a></p>
      <div class="cookie-actions"><button type="button" class="btn" data-c="denied">${T.reject}</button><button type="button" class="btn" data-c="granted">${T.accept}</button></div>`;
    banner.addEventListener('click', (e) => { const b = e.target.closest('[data-c]'); if (b) choose(b.dataset.c); });
    document.body.appendChild(banner);
  }

  window.demoConsent = {
    open,
    /** Evento GA4: parte solo se l'utente ha accettato. */
    track(name, params) { if (loaded) gtag('event', name, Object.assign({ transport_type: 'beacon' }, params || {})); },
  };

  const c = read();
  if (c === 'granted') loadGa();
  else if (c === null && !/[?&]p=(privacy|cookie)\b/.test(location.search)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', open); else open();
  }
})();
