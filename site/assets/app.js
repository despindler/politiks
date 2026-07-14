(() => {
  'use strict';

  const state = { csrfToken: '', user: null, googleClientId: null, publicPage: 0, minePage: 0, editing: null };
  const themeMeta = {
    system: { label: 'System', icon: 'bi-circle-half' },
    light: { label: 'Hell', icon: 'bi-sun' },
    dark: { label: 'Dunkel', icon: 'bi-moon-stars' },
  };
  const visibilityMeta = {
    draft: { label: 'Entwurf', icon: 'bi-pencil', className: 'visibility-draft' },
    unlisted: { label: 'Nicht gelistet', icon: 'bi-link-45deg', className: 'visibility-unlisted' },
    public: { label: 'Öffentlich', icon: 'bi-globe2', className: 'visibility-public' },
  };

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body !== undefined) headers.set('Content-Type', 'application/json');
    if (options.method && options.method !== 'GET' && state.csrfToken) headers.set('X-CSRF-Token', state.csrfToken);
    const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
    let payload;
    try { payload = await response.json(); } catch (_) { throw new Error('Die Serverantwort konnte nicht gelesen werden.'); }
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.message || 'Die Anfrage ist fehlgeschlagen.');
      error.code = payload.error_code || '';
      throw error;
    }
    return payload;
  }

  function element(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function applyTheme(preference, persist = true) {
    if (!themeMeta[preference]) return;
    const resolved = preference === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : preference;
    document.documentElement.dataset.bsTheme = resolved;
    document.documentElement.dataset.themePreference = preference;
    if (persist) try { localStorage.setItem('politiks-theme', preference); } catch (_) { /* no-op */ }
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
    alert.classList.toggle('d-none', !message);
  }

  function setStatus(selector, message, icon = 'bi-info-circle') {
    const target = document.querySelector(selector);
    if (!target) return;
    target.replaceChildren();
    const symbol = element('i', `bi ${icon} me-2`);
    symbol.setAttribute('aria-hidden', 'true');
    target.append(symbol, document.createTextNode(message));
    target.classList.remove('d-none');
  }

  function badge(text) { return element('span', 'badge rounded-pill scope-badge', text); }

  function insightAccordion(insight, index) {
    const item = element('article', 'accordion-item');
    const heading = element('h3', 'accordion-header');
    const button = element('button', 'accordion-button collapsed');
    button.type = 'button';
    button.dataset.bsToggle = 'collapse';
    button.dataset.bsTarget = `#insight-${insight.public_id}`;
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', `insight-${insight.public_id}`);
    const wrapper = element('span', 'd-block w-100 pe-3');
    wrapper.append(element('span', 'd-block h5 mb-2', insight.title || 'Unbenannter Insight'));
    const meta = element('span', 'd-flex flex-wrap gap-2 small text-body-secondary');
    meta.append(document.createTextNode(`Von ${insight.author}`));
    if (insight.scope.party) meta.append(badge(insight.scope.party));
    if (insight.scope.chamber) meta.append(badge(insight.scope.chamber));
    wrapper.append(meta);
    button.append(wrapper);
    heading.append(button);
    const collapse = element('div', 'accordion-collapse collapse');
    collapse.id = `insight-${insight.public_id}`;
    collapse.dataset.bsParent = document.body.dataset.page === 'shared' ? '' : '#public-insights-list';
    const body = element('div', 'accordion-body');
    body.append(element('p', 'insight-claim mb-3', insight.claim_text || 'Noch keine Aussage erfasst.'));
    if (insight.explanatory_notes) body.append(element('p', 'text-body-secondary', insight.explanatory_notes));
    const facts = element('div', 'd-flex flex-wrap gap-2 mb-3');
    facts.append(badge(`${insight.member_count} Mitglieder`), badge(`${insight.evidence_count} Abstimmungen`));
    body.append(facts);
    if (insight.votes.length) {
      const title = element('h4', 'h6 mt-4', 'Parlamentarische Evidenz');
      body.append(title);
      insight.votes.forEach((vote) => {
        const evidence = element('div', 'vote-evidence my-3');
        evidence.append(element('div', 'fw-semibold', vote.title || vote.voting_identifier));
        evidence.append(element('div', 'small text-body-secondary', [vote.affair_identifier, vote.occurred_at, vote.vote_type].filter(Boolean).join(' · ')));
        if (vote.exact_question) evidence.append(element('div', 'small mt-1', vote.exact_question));
        body.append(evidence);
      });
    } else {
      body.append(element('p', 'small text-body-secondary mb-0', 'Dieser Insight enthält noch keine ausgewählte Abstimmung.'));
    }
    collapse.append(body);
    item.append(heading, collapse);
    if (index === 0 && document.body.dataset.page === 'shared') {
      button.classList.remove('collapsed');
      button.setAttribute('aria-expanded', 'true');
      collapse.classList.add('show');
    }
    return item;
  }

  async function loadPublic(append = false) {
    const list = document.querySelector('[data-public-list]');
    const more = document.querySelector('[data-public-more]');
    if (!list) return;
    const page = append ? state.publicPage + 1 : 1;
    setStatus('[data-public-status]', 'Insights werden geladen …', 'bi-hourglass-split');
    try {
      const payload = await api(`/api/insights/public?page=${page}&per_page=6`);
      if (!append) list.replaceChildren();
      payload.items.forEach((insight, index) => list.append(insightAccordion(insight, index)));
      state.publicPage = page;
      list.classList.toggle('d-none', list.children.length === 0);
      document.querySelector('[data-public-status]').classList.toggle('d-none', list.children.length > 0);
      if (!list.children.length) setStatus('[data-public-status]', 'Noch keine öffentlichen Insights. Schau bald wieder vorbei.', 'bi-lightbulb');
      more.classList.toggle('d-none', page >= payload.pagination.total_pages);
    } catch (error) {
      setStatus('[data-public-status]', error.message || 'Öffentliche Insights konnten nicht geladen werden.', 'bi-exclamation-triangle');
      more.classList.add('d-none');
    }
  }

  async function loadShared() {
    const token = location.pathname.split('/').pop();
    document.querySelector('[data-catalogue-eyebrow]').textContent = 'Persönlich geteilt';
    document.querySelector('[data-catalogue-title]').textContent = 'Geteilter Insight';
    document.querySelector('[data-public-more]').classList.add('d-none');
    try {
      const payload = await api(`/api/shared-insights/${encodeURIComponent(token)}`);
      const list = document.querySelector('[data-public-list]');
      list.replaceChildren(insightAccordion(payload.insight, 0));
      list.classList.remove('d-none');
      document.querySelector('[data-public-status]').classList.add('d-none');
    } catch (error) {
      setStatus('[data-public-status]', error.message || 'Der geteilte Insight konnte nicht geladen werden.', 'bi-exclamation-triangle');
    }
  }

  function ownerCard(insight) {
    const column = element('div', 'col-md-6 col-xl-4');
    const card = element('article', 'owner-card d-flex flex-column');
    const status = visibilityMeta[insight.visibility] || visibilityMeta.draft;
    const label = element('p', `small fw-semibold mb-2 ${status.className}`);
    const statusIcon = element('i', `bi ${status.icon} me-1`);
    statusIcon.setAttribute('aria-hidden', 'true');
    label.append(statusIcon, document.createTextNode(status.label));
    card.append(label, element('h3', 'h5', insight.title || 'Unbenannter Insight'));
    card.append(element('p', 'text-body-secondary small flex-grow-1', insight.claim_text || 'Noch keine Aussage erfasst.'));
    const summary = element('p', 'small text-body-secondary', `${insight.member_count} Mitglieder · ${insight.evidence_count} Abstimmungen`);
    const edit = element('a', 'btn btn-outline-secondary w-100', 'Im Assistenten bearbeiten');
    edit.href = `/insights/${insight.public_id}/bearbeiten`;
    card.append(summary, edit);
    column.append(card);
    return column;
  }

  async function loadMine(append = false) {
    const list = document.querySelector('[data-mine-list]');
    if (!list || !state.user) return;
    const page = append ? state.minePage + 1 : 1;
    setStatus('[data-mine-status]', 'Deine Insights werden geladen …', 'bi-hourglass-split');
    try {
      const payload = await api(`/api/insights/mine?page=${page}&per_page=8`);
      if (!append) list.replaceChildren();
      payload.items.forEach((insight) => list.append(ownerCard(insight)));
      state.minePage = page;
      list.classList.toggle('d-none', list.children.length === 0);
      document.querySelector('[data-mine-status]').classList.toggle('d-none', list.children.length > 0);
      if (!list.children.length) setStatus('[data-mine-status]', 'Du hast noch keinen Insight. Erstelle deinen ersten Entwurf.', 'bi-journal-plus');
      document.querySelector('[data-mine-more]').classList.toggle('d-none', page >= payload.pagination.total_pages);
    } catch (error) {
      setStatus('[data-mine-status]', error.message || 'Deine Insights konnten nicht geladen werden.', 'bi-exclamation-triangle');
    }
  }

  function editorModal() { return bootstrap.Modal.getOrCreateInstance(document.querySelector('#insight-editor')); }

  function openEditor(insight) {
    state.editing = insight;
    const form = document.querySelector('[data-insight-form]');
    form.elements.title.value = insight.title || '';
    form.elements.claim_text.value = insight.claim_text || '';
    form.elements.explanatory_notes.value = insight.explanatory_notes || '';
    form.elements.visibility.value = insight.visibility;
    document.querySelector('[data-editor-error]').classList.add('d-none');
    document.querySelector('[data-share-panel]').classList.add('d-none');
    editorModal().show();
  }

  async function createInsight() {
    const button = document.querySelector('[data-create-insight]');
    button.disabled = true;
    try {
      const payload = await api('/api/insights', { method: 'POST', body: '{}' });
      location.href = `/insights/${payload.insight.public_id}/bearbeiten`;
    } catch (error) { setStatus('[data-mine-status]', error.message, 'bi-exclamation-triangle'); }
    finally { button.disabled = false; }
  }

  async function saveInsight(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const errorBox = document.querySelector('[data-editor-error]');
    errorBox.classList.add('d-none');
    try {
      const payload = await api(`/api/insights/${state.editing.public_id}`, {
        method: 'PATCH',
        body: JSON.stringify({ title: form.elements.title.value, claim_text: form.elements.claim_text.value, explanatory_notes: form.elements.explanatory_notes.value, visibility: form.elements.visibility.value }),
      });
      state.editing = payload.insight;
      if (payload.insight.share_url) {
        document.querySelector('[data-share-url]').value = payload.insight.share_url;
        document.querySelector('[data-share-panel]').classList.remove('d-none');
      } else {
        editorModal().hide();
      }
      await Promise.all([loadMine(), loadPublic()]);
    } catch (error) {
      errorBox.textContent = error.message;
      errorBox.classList.remove('d-none');
      errorBox.focus();
    }
  }

  async function archiveInsight() {
    if (!state.editing || !window.confirm('Diesen Insight wirklich archivieren?')) return;
    try {
      await api(`/api/insights/${state.editing.public_id}`, { method: 'DELETE', body: '{}' });
      editorModal().hide();
      await Promise.all([loadMine(), loadPublic()]);
    } catch (error) {
      const box = document.querySelector('[data-editor-error]');
      box.textContent = error.message;
      box.classList.remove('d-none');
    }
  }

  function renderSession(user) {
    state.user = user;
    document.querySelectorAll('[data-authenticated]').forEach((node) => node.classList.toggle('d-none', !user));
    document.querySelectorAll('[data-signed-out]').forEach((node) => node.classList.toggle('d-none', Boolean(user)));
    if (user) {
      document.querySelectorAll('[data-user-name]').forEach((node) => { node.textContent = `Grüezi, ${user.display_name}`; });
      document.querySelectorAll('[data-user-email]').forEach((node) => { node.textContent = user.email; });
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
    if (!state.googleClientId) { if (disabled) disabled.hidden = false; return; }
    await loadGoogleLibrary();
    const container = document.querySelector('#google-login');
    if (!container || !window.google?.accounts?.id) return;
    window.google.accounts.id.initialize({ client_id: state.googleClientId, callback: async (response) => {
      showMessage();
      try {
        const result = await api('/api/google-login', { method: 'POST', body: JSON.stringify({ credential: response.credential }) });
        renderSession(result.user);
        await loadMine();
      } catch (error) { showMessage(error.message || 'Die Anmeldung ist fehlgeschlagen.'); }
    } });
    window.google.accounts.id.renderButton(container, { type: 'standard', theme: document.documentElement.dataset.bsTheme === 'dark' ? 'filled_black' : 'outline', size: 'large', shape: 'rectangular', text: 'continue_with', locale: 'de', width: Math.min(400, Math.max(240, Math.floor(container.getBoundingClientRect().width))) });
  }

  async function initialize() {
    applyTheme(document.documentElement.dataset.themePreference || 'system', false);
    document.querySelectorAll('[data-theme-value]').forEach((button) => button.addEventListener('click', () => applyTheme(button.dataset.themeValue)));
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (document.documentElement.dataset.themePreference === 'system') applyTheme('system', false); });
    document.querySelector('[data-public-more]')?.addEventListener('click', () => loadPublic(true));
    document.querySelector('[data-mine-more]')?.addEventListener('click', () => loadMine(true));
    document.querySelector('[data-create-insight]')?.addEventListener('click', createInsight);
    document.querySelector('[data-insight-form]')?.addEventListener('submit', saveInsight);
    document.querySelector('[data-archive-insight]')?.addEventListener('click', archiveInsight);
    document.querySelector('[data-copy-share]')?.addEventListener('click', async () => {
      await navigator.clipboard.writeText(document.querySelector('[data-share-url]').value);
      document.querySelector('[data-copy-share]').textContent = 'Kopiert';
    });
    document.querySelectorAll('[data-logout]').forEach((button) => button.addEventListener('click', async () => {
      showMessage();
      try {
        await api('/api/logout', { method: 'POST', body: '{}' });
        state.csrfToken = '';
        const session = await api('/api/session');
        state.csrfToken = session.csrf_token;
        renderSession(null);
        document.querySelector('[data-mine-list]')?.replaceChildren();
        await configureGoogleLogin();
      } catch (error) { showMessage(error.message || 'Die Abmeldung ist fehlgeschlagen.'); }
    }));

    if (document.body.dataset.page === 'shared') await loadShared(); else await loadPublic();
    try {
      const session = await api('/api/session');
      state.csrfToken = session.csrf_token;
      renderSession(session.user);
      if (session.authenticated) await loadMine(); else await configureGoogleLogin();
    } catch (error) { showMessage(error.message || 'Die Anwendung konnte nicht initialisiert werden.'); }
  }

  initialize();
})();
