/* Condor #1439: consentimiento local, fail-closed y sin dependencias externas. */
(() => {
  'use strict';
  const banner = document.getElementById('cookie-consent-banner');
  const accept = document.getElementById('cookie-consent-accept');
  const reject = document.getElementById('cookie-consent-reject');
  const close = document.getElementById('cookie-consent-close');
  const settings = document.getElementById('cookie-consent-settings');
  if (!banner || !accept || !reject || !close || !settings) return;

  const name = 'condor_cookie_choice';
  const maxAge = 60 * 60 * 24 * 180;
  function readChoice() {
    const value = document.cookie.split(';').map((part) => part.trim())
      .find((part) => part.startsWith(name + '='));
    if (!value) return null;
    const choice = value.slice(name.length + 1);
    return choice === 'accepted' || choice === 'rejected' ? choice : null;
  }
  function writeChoice(choice) {
    // Una cookie propia de preferencia: no se comparten datos con terceros.
    document.cookie = name + '=' + choice + '; Max-Age=' + maxAge +
      '; Path=/; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
  }
  function loadOptionalScripts() {
    // type=text/plain impide cualquier descarga/ejecución antes del clic Aceptar.
    document.querySelectorAll('script[type="text/plain"][data-cookie-src]').forEach((source) => {
      if (source.dataset.cookieActivated === 'true') return;
      let url;
      try { url = new URL(source.dataset.cookieSrc, window.location.href); }
      catch (_) { return; }
      // Solo HTTPS remoto o el mismo origen: nunca data:, javascript: ni HTTP externo.
      if (url.protocol !== 'https:' && url.origin !== window.location.origin) return;
      if (url.protocol !== 'https:' && url.protocol !== 'http:') return;
      const script = document.createElement('script');
      script.src = url.href;
      script.async = true;
      document.head.appendChild(script);
      source.dataset.cookieActivated = 'true';
    });
  }
  function hide() {
    banner.hidden = true;
    settings.focus();
  }
  function choose(choice) {
    const previous = readChoice();
    writeChoice(choice);
    hide();
    if (choice === 'accepted' && readChoice() === 'accepted') loadOptionalScripts();
    // Si ya se habían iniciado proveedores opcionales, recargar para detenerlos.
    if (choice === 'rejected' && previous === 'accepted') window.location.reload();
  }
  function show() {
    banner.hidden = false;
    reject.focus();
  }
  accept.addEventListener('click', () => choose('accepted'));
  reject.addEventListener('click', () => choose('rejected'));
  close.addEventListener('click', () => choose('rejected'));
  settings.addEventListener('click', show);
  banner.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { event.preventDefault(); choose('rejected'); }
  });

  const current = readChoice();
  if (current === 'accepted') loadOptionalScripts();
  if (current === null) show(); // sin elección = rechazo funcional de todo lo opcional
})();
