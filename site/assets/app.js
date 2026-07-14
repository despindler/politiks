(() => {
  'use strict';

  const state = { csrfToken: '', user: null, googleClientId: null };
  const themeMeta = {
    system: { label: 'System', icon: 'bi-circle-half' },
    light: { label: 'Hell', icon: 'bi-sun' },
    dark: { label: 'Dunkel', icon: 'bi-moon-stars' },
  };

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body !== undefined) headers.set('Content-Type', 'application/json');
    if (options.method && options.method !== 'GET' && state.csrfToken) {
      headers.set('X-CSRF-Token', state.csrfToken);
    }
    const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
    let payload;
    try {
      payload = await response.json();
    } catch (_) {
      throw new Error('Die Serverantwort konnte nicht gelesen werden.');
    }
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.message || 'Die Anfrage ist fehlgeschlagen.');
      error.code = payload.error_code || '';
      throw error;
    }
    return payload;
  }

  function applyTheme(preference, persist = true) {
    if (!themeMeta[preference]) return;
    const resolved = preference === 'system'
      ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      : preference;
    document.documentElement.dataset.bsTheme = resolved;
    document.documentElement.dataset.themePreference = preference;
    if (persist) {
      try { window.localStorage.setItem('politiks-theme', preference); } catch (_) { /* no-op */ }
    }
    const icon = document.querySelector('[data-theme-icon]');
    const label = document.querySelector('[data-theme-label]');
    if (icon) icon.className = `bi ${themeMeta[preference].icon} me-2`;
    if (label) label.textContent = themeMeta[preference].label;
    document.querySelectorAll('[data-theme-value]').forEach((button) => {
      button.classList.toggle('active', button.dataset.themeValue === preference);
      button.setAttribute('aria-pressed', String(button.dataset.themeValue === preference));
    });
  }

  function showMessage(message = '') {
    const alert = document.querySelector('[data-auth-message]');
    if (!alert) return;
    alert.textContent = message;
    alert.classList.toggle('d-none', message === '');
  }

  function renderSession(user) {
    state.user = user;
    const authenticated = Boolean(user);
    document.querySelectorAll('[data-authenticated]').forEach((element) => {
      element.classList.toggle('d-none', !authenticated);
    });
    document.querySelectorAll('[data-signed-out]').forEach((element) => {
      element.classList.toggle('d-none', authenticated);
    });
    if (user) {
      document.querySelectorAll('[data-user-name]').forEach((element) => {
        element.textContent = `Grüezi, ${user.display_name}`;
      });
      document.querySelectorAll('[data-user-email]').forEach((element) => {
        element.textContent = user.email;
      });
    }
  }

  function loadGoogleLibrary() {
    if (window.google?.accounts?.id) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://accounts.google.com/gsi/client';
      script.async = true;
      script.onload = resolve;
      script.onerror = () => reject(new Error('Google Sign-In konnte nicht geladen werden.'));
      document.head.appendChild(script);
    });
  }

  async function configureGoogleLogin() {
    const config = await api('/api/auth-config');
    state.googleClientId = config.google_client_id;
    const disabled = document.querySelector('[data-google-disabled]');
    if (!state.googleClientId) {
      if (disabled) disabled.hidden = false;
      return;
    }
    await loadGoogleLibrary();
    const container = document.querySelector('#google-login');
    if (!container || !window.google?.accounts?.id) return;
    window.google.accounts.id.initialize({
      client_id: state.googleClientId,
      callback: async (response) => {
        showMessage();
        try {
          const result = await api('/api/google-login', {
            method: 'POST',
            body: JSON.stringify({ credential: response.credential }),
          });
          renderSession(result.user);
        } catch (error) {
          showMessage(error.message || 'Die Anmeldung ist fehlgeschlagen.');
        }
      },
    });
    window.google.accounts.id.renderButton(container, {
      type: 'standard',
      theme: document.documentElement.dataset.bsTheme === 'dark' ? 'filled_black' : 'outline',
      size: 'large',
      shape: 'rectangular',
      text: 'continue_with',
      locale: 'de',
      width: Math.min(400, Math.max(240, Math.floor(container.getBoundingClientRect().width))),
    });
  }

  async function initialize() {
    applyTheme(document.documentElement.dataset.themePreference || 'system', false);
    document.querySelectorAll('[data-theme-value]').forEach((button) => {
      button.addEventListener('click', () => applyTheme(button.dataset.themeValue));
    });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (document.documentElement.dataset.themePreference === 'system') applyTheme('system', false);
    });
    document.querySelectorAll('[data-logout]').forEach((button) => {
      button.addEventListener('click', async () => {
        showMessage();
        try {
          await api('/api/logout', { method: 'POST', body: '{}' });
          state.csrfToken = '';
          const session = await api('/api/session');
          state.csrfToken = session.csrf_token;
          renderSession(null);
          await configureGoogleLogin();
        } catch (error) {
          showMessage(error.message || 'Die Abmeldung ist fehlgeschlagen.');
        }
      });
    });

    try {
      const session = await api('/api/session');
      state.csrfToken = session.csrf_token;
      renderSession(session.user);
      if (!session.authenticated) await configureGoogleLogin();
    } catch (error) {
      showMessage(error.message || 'Die Anwendung konnte nicht initialisiert werden.');
    }
  }

  initialize();
})();
