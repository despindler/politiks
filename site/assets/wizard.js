(() => {
  'use strict';

  const root = document.querySelector('[data-wizard]');
  const publicId = document.body.dataset.insightId;
  const state = {
    csrf: '', insight: null, options: null, eligible: [], selected: new Set(), baseline: new Set(),
    evidence: [], votes: [], voteMap: new Map(), query: '', direction: 'all', cohesion: 'all',
    voteType: 'all', topic: 'all', classification: 'all', memberFilter: 'all',
    currentStep: 0, mobileView: 'yes', memberSearch: '', cohortSearch: '', loadingVotes: false,
    contexts: [], transitionPromise: null, submitting: false,
  };
  const steps = Array.from(root.querySelectorAll('.wizard-step'));
  const tabs = Array.from(root.querySelectorAll('[data-step-target]'));
  let memberSaveTimer;
  let voteLoadTimer;
  let evidenceSaveTimer;
  let memberSavePromise = null;
  let pendingMemberIds = null;
  let evidenceSavePromise = null;
  let pendingEvidenceIds = null;
  let voteRequestSequence = 0;
  let voteAbortController = null;
  let voteBusyCleanup = null;
  let activitySequence = 0;
  let activeActivityToken = 0;

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body !== undefined && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    if (options.method && options.method !== 'GET') headers.set('X-CSRF-Token', state.csrf);
    const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
    const payload = await response.json().catch(() => ({ ok: false, message: 'Die Serverantwort konnte nicht gelesen werden.' }));
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.message || 'Die Anfrage ist fehlgeschlagen.');
      error.code = payload.error_code || '';
      throw error;
    }
    return payload;
  }

  function node(tag, className = '', text = '') {
    const result = document.createElement(tag);
    if (className) result.className = className;
    if (text) result.textContent = text;
    return result;
  }

  function beginActivity(text, slowText = 'Der Vorgang dauert etwas länger – bitte warten.', delay = 180) {
    const token = ++activitySequence;
    activeActivityToken = token;
    const activity = document.querySelector('[data-wizard-activity]');
    const activityText = document.querySelector('[data-wizard-activity-text]');
    const show = (message) => {
      if (activeActivityToken !== token) return;
      activityText.textContent = message;
      activity.classList.remove('d-none');
    };
    const showTimer = delay > 0 ? window.setTimeout(() => show(text), delay) : null;
    if (delay === 0) show(text);
    const slowTimer = window.setTimeout(() => show(slowText), 5000);
    return () => {
      if (showTimer !== null) window.clearTimeout(showTimer);
      window.clearTimeout(slowTimer);
      if (activeActivityToken === token) {
        activity.classList.add('d-none');
        activeActivityToken = 0;
      }
    };
  }

  function disableControls(controls) {
    const states = Array.from(new Set(controls.filter(Boolean))).map((control) => [control, control.disabled]);
    states.forEach(([control]) => { control.disabled = true; });
    return () => states.forEach(([control, disabled]) => { control.disabled = disabled; });
  }

  function makeButtonBusy(button, text) {
    if (!button || !text) return () => {};
    const original = { disabled: button.disabled, html: button.innerHTML };
    const spinner = node('span', 'spinner-border spinner-border-sm me-2');
    spinner.setAttribute('aria-hidden', 'true');
    button.replaceChildren(spinner, document.createTextNode(text));
    button.classList.add('wizard-busy-button');
    button.disabled = true;
    return () => {
      button.innerHTML = original.html;
      button.disabled = original.disabled;
      button.classList.remove('wizard-busy-button');
    };
  }

  function startBusy(options) {
    const controls = typeof options.controls === 'string'
      ? Array.from(document.querySelectorAll(options.controls))
      : (options.controls || []);
    const restoreControls = disableControls(controls);
    const restoreButton = makeButtonBusy(options.button, options.buttonText);
    const region = typeof options.region === 'string' ? document.querySelector(options.region) : options.region;
    if (region) { region.setAttribute('aria-busy', 'true'); region.classList.add('busy-region'); }
    if (options.formBusy) root.setAttribute('aria-busy', 'true');
    const finishActivity = options.activityText
      ? beginActivity(options.activityText, options.slowText, options.activityDelay ?? 180)
      : () => {};
    let finished = false;
    return () => {
      if (finished) return;
      finished = true;
      finishActivity();
      if (options.formBusy) root.setAttribute('aria-busy', 'false');
      if (region) { region.setAttribute('aria-busy', 'false'); region.classList.remove('busy-region'); }
      restoreButton();
      restoreControls();
    };
  }

  async function withBusy(options, work) {
    const finishBusy = startBusy(options);
    try {
      return await work();
    } finally {
      finishBusy();
    }
  }

  function formatDuration(startedAt) {
    const duration = performance.now() - startedAt;
    if (duration < 750) return '';
    return ` in ${(duration / 1000).toLocaleString('de-CH', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} Sekunden`;
  }

  function setSaveStatus(text, icon = 'bi-cloud-check') {
    const status = document.querySelector('[data-save-status]');
    status.replaceChildren();
    const symbol = node('i', `bi ${icon} me-1`);
    symbol.setAttribute('aria-hidden', 'true');
    status.append(symbol, document.createTextNode(text));
  }

  function applyTheme(preference) {
    const order = ['system', 'light', 'dark'];
    if (!order.includes(preference)) preference = 'system';
    const resolved = preference === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : preference;
    document.documentElement.dataset.bsTheme = resolved;
    document.documentElement.dataset.themePreference = preference;
    try { localStorage.setItem('politiks-theme', preference); } catch (_) { /* no-op */ }
  }

  function fillSelect(selector, items, value, placeholder = 'Bitte wählen') {
    const select = document.querySelector(selector);
    select.replaceChildren();
    const empty = node('option', '', placeholder);
    empty.value = '';
    select.append(empty);
    items.forEach((item) => {
      const option = node('option', '', item.name);
      option.value = String(item.id);
      select.append(option);
    });
    select.value = value ? String(value) : '';
  }

  function initializeScope() {
    const scope = state.insight.scope;
    const countries = state.options.countries;
    fillSelect('#scope-country', countries, scope.country_id || countries[0]?.id);
    const countryId = Number(document.querySelector('#scope-country').value);
    const legislatures = state.options.legislatures.filter((item) => item.parent_id === countryId);
    fillSelect('#scope-legislature', legislatures, scope.legislature_id || legislatures[0]?.id);
    const legislatureId = Number(document.querySelector('#scope-legislature').value);
    const chambers = state.options.chambers.filter((item) => item.parent_id === legislatureId);
    fillSelect('#scope-chamber', chambers, scope.chamber_id);
    const parties = state.options.parties.filter((item) => item.parent_id === countryId);
    fillSelect('#scope-party', parties, scope.party_id);
    document.querySelector('#scope-from').value = scope.period_from || state.options.date_range.date_from || '';
    document.querySelector('#scope-to').value = scope.period_to || state.options.date_range.date_to || '';
    document.querySelector('#scope-country').disabled = countries.length === 1;
  }

  function scopePayload() {
    return {
      country_id: Number(document.querySelector('#scope-country').value),
      legislature_id: Number(document.querySelector('#scope-legislature').value),
      chamber_id: Number(document.querySelector('#scope-chamber').value),
      party_id: Number(document.querySelector('#scope-party').value),
      period_from: document.querySelector('#scope-from').value,
      period_to: document.querySelector('#scope-to').value,
    };
  }

  function scopeComplete() {
    const scope = scopePayload();
    return scope.country_id && scope.legislature_id && scope.chamber_id && scope.party_id && scope.period_from && scope.period_to;
  }

  async function saveScope() {
    if (!scopeComplete()) throw new Error('Vervollständige zuerst Land, Parlament, Rat, Partei und Zeitraum.');
    const nextScope = scopePayload();
    const fields = ['country_id', 'legislature_id', 'chamber_id', 'party_id', 'period_from', 'period_to'];
    const scopeChanged = fields.some((field) => String(state.insight.scope[field] ?? '') !== String(nextScope[field] ?? ''));
    setSaveStatus('Rahmen wird gespeichert …', 'bi-cloud-arrow-up');
    const payload = await api(`/api/insights/${publicId}/scope`, { method: 'PUT', body: JSON.stringify(nextScope) });
    state.insight.scope = payload.scope;
    if (scopeChanged) {
      state.eligible = []; state.selected.clear(); state.baseline.clear(); state.evidence = [];
      state.votes = []; state.voteMap.clear();
      renderMembers(); renderVotes();
      document.querySelector('[data-vote-status]').textContent = 'Der geänderte Rahmen hat die bisherige Mitglieder- und Evidenzauswahl zurückgesetzt.';
    }
    setSaveStatus('Rahmen gespeichert');
  }

  function renderMemberSkeletons() {
    const list = document.querySelector('[data-member-list]');
    list.replaceChildren();
    for (let index = 0; index < 6; index += 1) {
      const skeleton = node('div', 'member-option member-skeleton');
      skeleton.setAttribute('aria-hidden', 'true');
      list.append(skeleton);
    }
    document.querySelector('[data-member-summary]').textContent = 'Mitglieder werden geladen …';
  }

  async function loadMembers(showBusy = true) {
    renderMemberSkeletons();
    const memberList = document.querySelector('[data-member-list]');
    memberList.setAttribute('aria-busy', 'true');
    memberList.classList.add('busy-region');
    const startedAt = performance.now();
    const work = async () => {
      try {
        const payload = await api(`/api/insights/${publicId}/members`);
        state.eligible = payload.items;
        const eligibleIds = new Set(state.eligible.map((member) => member.id));
        state.selected = new Set(Array.from(state.selected).filter((id) => eligibleIds.has(id)));
        if (!state.selected.size && state.eligible.length) state.selected = new Set(state.eligible.map((member) => member.id));
        renderMembers();
        setSaveStatus(`${state.eligible.length} Mitglieder geladen${formatDuration(startedAt)}`);
      } catch (error) {
        document.querySelector('[data-member-list]').replaceChildren();
        document.querySelector('[data-member-summary]').textContent = 'Mitglieder konnten nicht geladen werden.';
        throw error;
      } finally {
        memberList.setAttribute('aria-busy', 'false');
        memberList.classList.remove('busy-region');
      }
    };
    if (!showBusy) return work();
    return withBusy({
      activityText: 'Mitglieder werden geladen …',
      slowText: 'Das Laden der Mitglieder dauert etwas länger – bitte warten.',
      controls: '#member-search, [data-members-all], [data-members-none]',
    }, work);
  }

  function memberOption(member, compact = false) {
    const label = node('label', compact ? 'cohort-check' : 'member-option');
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'form-check-input';
    input.checked = state.selected.has(member.id);
    input.addEventListener('change', () => changeMember(member.id, input.checked));
    const copy = node('span');
    copy.append(node('span', 'd-block fw-semibold', member.name));
    if (!compact) copy.append(node('span', 'd-block small text-body-secondary', member.faction ? `Fraktion: ${member.faction}` : 'Keine Fraktionsangabe'));
    label.append(input, copy);
    return label;
  }

  function renderMembers() {
    const term = state.memberSearch.toLocaleLowerCase('de-CH');
    const list = document.querySelector('[data-member-list]');
    list.replaceChildren();
    state.eligible.filter((member) => !term || `${member.name} ${member.faction || ''}`.toLocaleLowerCase('de-CH').includes(term))
      .forEach((member) => list.append(memberOption(member)));
    document.querySelector('[data-member-summary]').textContent = `${state.selected.size} von ${state.eligible.length} wählbaren Mitgliedern ausgewählt`;
    renderCohort();
  }

  function renderCohort() {
    const term = state.cohortSearch.toLocaleLowerCase('de-CH');
    const list = document.querySelector('[data-cohort-list]');
    list.replaceChildren();
    state.eligible.filter((member) => !term || member.name.toLocaleLowerCase('de-CH').includes(term))
      .forEach((member) => list.append(memberOption(member, true)));
    document.querySelector('[data-cohort-basis]').textContent = state.selected.size === 1
      ? `Individuelles Stimmverhalten von ${state.eligible.find((member) => state.selected.has(member.id))?.name || 'einem Mitglied'}`
      : `Mehrheit von ${state.selected.size} ausgewählten Mitgliedern`;
  }

  function changeMember(id, checked) {
    if (checked) state.selected.add(id); else state.selected.delete(id);
    renderMembers();
    if (state.currentStep === 2) {
      clearTimeout(memberSaveTimer);
      clearTimeout(voteLoadTimer);
      memberSaveTimer = setTimeout(() => saveMembers().catch(showError), 250);
      voteLoadTimer = setTimeout(() => loadVotes().catch(showError), 350);
    }
  }

  async function saveMembers() {
    if (!state.selected.size) throw new Error('Wähle mindestens ein Mitglied aus.');
    pendingMemberIds = Array.from(state.selected);
    if (memberSavePromise) return memberSavePromise;
    setSaveStatus('Mitglieder werden gespeichert …', 'bi-cloud-arrow-up');
    memberSavePromise = (async () => {
      while (pendingMemberIds !== null) {
        const memberIds = pendingMemberIds;
        pendingMemberIds = null;
        await api(`/api/insights/${publicId}/members`, { method: 'PUT', body: JSON.stringify({ member_ids: memberIds }) });
      }
      setSaveStatus('Mitglieder gespeichert');
    })();
    try {
      return await memberSavePromise;
    } finally {
      memberSavePromise = null;
    }
  }

  async function loadVotes(showBusy = true) {
    const voteColumns = document.querySelector('[data-vote-columns]');
    if (!state.selected.size) {
      voteAbortController?.abort();
      voteRequestSequence += 1;
      if (voteBusyCleanup) { voteBusyCleanup(); voteBusyCleanup = null; }
      state.loadingVotes = false;
      state.votes = [];
      voteColumns.setAttribute('aria-busy', 'false');
      voteColumns.classList.remove('busy-region');
      renderVotes();
      document.querySelector('[data-vote-status]').textContent = 'Wähle mindestens ein Mitglied aus.';
      return;
    }
    const requestId = ++voteRequestSequence;
    voteAbortController?.abort();
    voteAbortController = new AbortController();
    if (voteBusyCleanup) { voteBusyCleanup(); voteBusyCleanup = null; }
    if (showBusy) {
      voteBusyCleanup = startBusy({
        button: document.querySelector('[data-vote-search]'),
        buttonText: 'Wird berechnet …',
        activityText: 'Abstimmungen werden für den aktuellen Mitgliederkreis berechnet …',
        slowText: 'Die Berechnung dauert etwas länger – bitte warten.',
        controls: '[data-vote-search], [data-cohort-reset]',
      });
    }
    const currentBusyCleanup = voteBusyCleanup;
    const startedAt = performance.now();
    state.loadingVotes = true;
    voteColumns.setAttribute('aria-busy', 'true');
    voteColumns.classList.add('busy-region');
    document.querySelector('[data-vote-status]').textContent = 'Abstimmungen werden für den aktuellen Mitgliederkreis neu berechnet …';
    try {
      const payload = await api(`/api/insights/${publicId}/votes`, {
        method: 'POST',
        body: JSON.stringify({ member_ids: Array.from(state.selected), query: state.query }),
        signal: voteAbortController.signal,
      });
      if (requestId !== voteRequestSequence) return;
      state.votes = payload.items;
      state.votes.forEach((vote) => state.voteMap.set(vote.id, vote));
      populateVoteFilters();
      const duration = formatDuration(startedAt);
      document.querySelector('[data-vote-status]').textContent = payload.total
        ? `${payload.total} Abstimmungen berechnet${duration}${payload.limited ? ' · Anzeige auf 100 Ergebnisse begrenzt' : ''}.`
        : `Keine Abstimmung entspricht der aktuellen Suche und dem gewählten Rahmen${duration}.`;
      renderVotes();
    } catch (error) {
      if (error.name === 'AbortError') return;
      if (requestId === voteRequestSequence) {
        document.querySelector('[data-vote-status]').textContent = 'Abstimmungen konnten nicht berechnet werden.';
      }
      throw error;
    } finally {
      if (requestId === voteRequestSequence) {
        state.loadingVotes = false;
        voteAbortController = null;
        voteColumns.setAttribute('aria-busy', 'false');
        voteColumns.classList.remove('busy-region');
        if (currentBusyCleanup) currentBusyCleanup();
        if (voteBusyCleanup === currentBusyCleanup) voteBusyCleanup = null;
      }
    }
  }

  function voteVisible(vote) {
    if (state.direction === 'yes' && vote.direction !== 'yes') return false;
    if (state.direction === 'no' && vote.direction !== 'no') return false;
    if (state.direction === 'neutral' && !['split', 'non_directional'].includes(vote.direction)) return false;
    if (state.cohesion === 'unanimous' && vote.cohesion !== 1) return false;
    if (state.cohesion === 'majority' && !(vote.cohesion >= .67 && vote.cohesion < 1)) return false;
    if (state.cohesion === 'close' && !(vote.cohesion !== null && vote.cohesion < .67)) return false;
    if (state.voteType !== 'all' && vote.vote_type !== state.voteType) return false;
    if (state.topic !== 'all' && !vote.official_topics.includes(state.topic)) return false;
    if (state.classification !== 'all' && !vote.reviewed_classifications.includes(state.classification)) return false;
    if (state.memberFilter !== 'all') {
      const member = vote.members.find((entry) => String(entry.id) === state.memberFilter);
      if (!member || !['yes', 'no'].includes(vote.direction) || !['yes', 'no'].includes(member.choice) || member.choice === vote.direction) return false;
    }
    return true;
  }

  function replaceFilterOptions(selector, values, allLabel, selected) {
    const select = document.querySelector(selector);
    select.replaceChildren();
    const all = node('option', '', allLabel); all.value = 'all'; select.append(all);
    values.forEach((value) => { const option = node('option', '', value.label); option.value = value.value; select.append(option); });
    select.value = values.some((value) => value.value === selected) ? selected : 'all';
    return select.value;
  }

  function populateVoteFilters() {
    const unique = (values) => Array.from(new Set(values.filter(Boolean))).sort((left, right) => left.localeCompare(right, 'de-CH'));
    state.voteType = replaceFilterOptions('[data-vote-type-filter]', unique(state.votes.map((vote) => vote.vote_type)).map((value) => ({ value, label: value })), 'Alle Typen', state.voteType);
    state.topic = replaceFilterOptions('[data-topic-filter]', unique(state.votes.flatMap((vote) => vote.official_topics)).map((value) => ({ value, label: value })), 'Alle Themen', state.topic);
    state.classification = replaceFilterOptions('[data-classification-filter]', unique(state.votes.flatMap((vote) => vote.reviewed_classifications)).map((value) => ({ value, label: value })), 'Alle Klassifikationen', state.classification);
    state.memberFilter = replaceFilterOptions('[data-member-filter]', state.eligible.filter((member) => state.selected.has(member.id)).map((member) => ({ value: String(member.id), label: member.name })), 'Alle Mitglieder', state.memberFilter);
  }

  function appendHighlightedText(parent, text, query) {
    if (!query) { parent.textContent = text; return; }
    const lower = text.toLocaleLowerCase('de-CH');
    const needle = query.toLocaleLowerCase('de-CH');
    let offset = 0; let position = lower.indexOf(needle);
    if (position < 0) { parent.textContent = text; return; }
    while (position >= 0) {
      parent.append(document.createTextNode(text.slice(offset, position)));
      parent.append(node('mark', '', text.slice(position, position + query.length)));
      offset = position + query.length;
      position = lower.indexOf(needle, offset);
    }
    parent.append(document.createTextNode(text.slice(offset)));
  }

  function choiceLabel(choice) {
    return { yes: 'Ja', no: 'Nein', abstain: 'Enthalten', other: 'Andere Stimme', not_participating: 'Nicht teilgenommen', no_mandate: 'Kein Mandat' }[choice] || choice;
  }

  function badge(text, className = 'text-bg-light border') {
    return node('span', `badge rounded-pill ${className}`, text);
  }

  function voteCard(vote) {
    const details = node('details', 'vote-card');
    const summary = node('summary');
    const heading = node('div', 'fw-semibold mb-2', vote.title);
    const meta = node('div', 'vote-meta mb-2', [vote.affair_identifier, vote.voting_identifier, vote.occurred_at?.slice(0, 10), vote.vote_type].filter(Boolean).join(' · '));
    const tally = node('div', 'vote-tally', `Ja ${vote.counts.yes} · Nein ${vote.counts.no} · Enthalten ${vote.counts.abstain} · Teilnahme ${vote.participating_count}/${vote.eligible_count}`);
    const badges = node('div', 'd-flex flex-wrap gap-1 mt-2');
    const cohesion = vote.cohesion === null ? 'nicht gerichtet' : `${Math.round(vote.cohesion * 100)} % Kohäsion`;
    badges.append(badge(cohesion));
    vote.official_topics.forEach((topic) => badges.append(badge(`Offiziell: ${topic}`)));
    vote.reviewed_classifications.forEach((classification) => badges.append(badge(`Geprüft: ${classification}`, 'text-bg-info')));
    const evidence = node('button', state.evidence.includes(vote.id) ? 'btn btn-primary w-100 mt-3' : 'btn btn-outline-secondary w-100 mt-3');
    evidence.type = 'button';
    evidence.textContent = state.evidence.includes(vote.id) ? 'Als Evidenz ausgewählt' : 'Als Evidenz auswählen';
    evidence.addEventListener('click', (event) => { event.preventDefault(); toggleEvidence(vote.id); });
    summary.append(heading, meta, tally, badges);
    if (vote.participation_warning) summary.append(node('div', 'alert alert-warning py-2 px-3 small mt-2 mb-0', 'Keine aufgezeichnete Teilnahme im aktuellen Mitgliederkreis – vor Veröffentlichung lösen.'));
    summary.append(evidence);
    const body = node('div', 'vote-details pt-3');
    body.append(node('p', 'small text-body-secondary mb-2', `Rat: ${vote.chamber || 'nicht ausgewiesen'} · Typ: ${vote.vote_type || 'nicht ausgewiesen'} · Berechtigte Mitglieder: ${vote.eligible_count} · Erfasste Teilnahme: ${vote.participating_count}`));
    if (vote.exact_question) body.append(node('p', '', vote.exact_question));
    const semantics = node('div', 'row g-2 small mb-3');
    const yes = node('div', 'col-12'); yes.append(node('strong', '', 'Bedeutung Ja: '), document.createTextNode(vote.meaning_yes || 'Nicht ausgewiesen'));
    const no = node('div', 'col-12'); no.append(node('strong', '', 'Bedeutung Nein: '), document.createTextNode(vote.meaning_no || 'Nicht ausgewiesen'));
    semantics.append(yes, no); body.append(semantics);
    body.append(node('p', 'small mb-2', `Gesamtergebnis des Rates: ${vote.overall_decision || 'Nicht ausgewiesen'}`));
    if (vote.match_context) {
      const context = node('p', 'search-context small border rounded p-2');
      context.append(node('strong', 'd-block mb-1', 'Treffer im Abstimmungstext'));
      const excerpt = node('span'); appendHighlightedText(excerpt, vote.match_context, state.query); context.append(excerpt); body.append(context);
    }
    body.append(node('p', 'small text-body-secondary', 'Die angezeigte Richtung wird nur aus Ja- und Nein-Stimmen des gewählten Mitgliederkreises berechnet. Enthaltungen, fehlende Teilnahme und fehlendes Mandat zählen nicht zur Richtungsmehrheit.'));
    const groups = {};
    vote.members.forEach((member) => { (groups[member.choice] ||= []).push(member.name); });
    Object.entries(groups).forEach(([choice, names]) => {
      body.append(node('p', 'small fw-semibold mb-1', choiceLabel(choice)));
      const group = node('div', 'choice-group mb-2');
      vote.members.filter((member) => member.choice === choice).forEach((member) => {
        const membership = [member.party, member.faction].filter(Boolean).join(' · ');
        const divergent = ['yes', 'no'].includes(vote.direction) && ['yes', 'no'].includes(member.choice) && member.choice !== vote.direction;
        const pill = badge(`${member.name} — ${membership || 'Zugehörigkeit nicht ausgewiesen'}${divergent ? ' · Abweichende Stimme' : ''}`);
        if (membership) pill.title = `Zugehörigkeit am Abstimmungstag: ${membership}`;
        pill.classList.add('text-wrap', 'text-start');
        group.append(pill);
      });
      body.append(group);
    });
    if (vote.provenance_url) {
      const source = node('a', 'btn btn-sm btn-outline-secondary w-100 mt-2', 'Offizielle Quelle öffnen');
      source.href = vote.provenance_url;
      source.target = '_blank'; source.rel = 'noopener noreferrer';
      body.append(source);
    }
    details.append(summary, body);
    return details;
  }

  function renderVotes() {
    const columns = { yes: document.querySelector('[data-votes-yes]'), no: document.querySelector('[data-votes-no]'), neutral: document.querySelector('[data-votes-neutral]') };
    Object.values(columns).forEach((column) => column.replaceChildren());
    const counts = { yes: 0, no: 0, neutral: 0 };
    state.votes.filter(voteVisible).forEach((vote) => {
      const group = ['yes', 'no'].includes(vote.direction) ? vote.direction : 'neutral';
      columns[group].append(voteCard(vote));
      counts[group] += 1;
    });
    Object.entries(counts).forEach(([group, count]) => document.querySelectorAll(`[data-count-${group}]`).forEach((target) => { target.textContent = String(count); }));
    Object.entries(columns).forEach(([group, column]) => {
      if (!column.children.length) column.append(node('p', 'small text-body-secondary p-2', 'Keine Ergebnisse in dieser Ansicht.'));
      document.querySelector(`[data-vote-column="${group}"]`).classList.toggle('mobile-active', group === state.mobileView);
    });
    renderEvidence();
    renderOutliers();
  }

  function toggleEvidence(id) {
    const index = state.evidence.indexOf(id);
    if (index >= 0) state.evidence.splice(index, 1); else state.evidence.push(id);
    clearTimeout(evidenceSaveTimer);
    evidenceSaveTimer = setTimeout(() => saveEvidence().catch(showError), 250);
    renderVotes();
  }

  async function saveEvidence() {
    pendingEvidenceIds = [...state.evidence];
    if (evidenceSavePromise) return evidenceSavePromise;
    setSaveStatus('Evidenz wird gespeichert …', 'bi-cloud-arrow-up');
    evidenceSavePromise = (async () => {
      while (pendingEvidenceIds !== null) {
        const evidenceIds = pendingEvidenceIds;
        pendingEvidenceIds = null;
        await api(`/api/insights/${publicId}/evidence`, { method: 'PUT', body: JSON.stringify({ evidence_ids: evidenceIds }) });
      }
      setSaveStatus('Evidenz gespeichert');
    })();
    try {
      return await evidenceSavePromise;
    } finally {
      evidenceSavePromise = null;
    }
  }

  function renderEvidence() {
    document.querySelector('[data-evidence-count]').textContent = String(state.evidence.length);
    const modalList = document.querySelector('[data-evidence-modal-list]');
    modalList.replaceChildren();
    if (!state.evidence.length) modalList.append(node('p', 'text-body-secondary', 'Noch keine Abstimmung ausgewählt.'));
    state.evidence.forEach((id, index) => {
      const vote = state.voteMap.get(id);
      const row = node('div', 'd-flex align-items-center gap-2 py-2 border-bottom');
      const copy = node('div', 'flex-grow-1');
      copy.append(node('div', 'fw-semibold', vote?.title || `Abstimmung ${id}`), node('div', 'small text-body-secondary', vote?.voting_identifier || 'Bleibt als Evidenz gespeichert'));
      const up = node('button', 'btn btn-sm btn-outline-secondary', '↑'); up.type = 'button'; up.disabled = index === 0;
      up.addEventListener('click', () => { [state.evidence[index - 1], state.evidence[index]] = [state.evidence[index], state.evidence[index - 1]]; saveEvidence().catch(showError); renderEvidence(); });
      const remove = node('button', 'btn btn-sm btn-outline-danger', 'Entfernen'); remove.type = 'button'; remove.addEventListener('click', () => toggleEvidence(id));
      row.append(copy, up, remove); modalList.append(row);
    });
  }

  function renderOutliers() {
    const panel = document.querySelector('[data-outlier-panel]');
    panel.replaceChildren();
    state.eligible.filter((member) => state.selected.has(member.id)).forEach((member) => {
      let evaluated = 0; let agreement = 0; let divergent = 0;
      state.votes.forEach((vote) => {
        if (!['yes', 'no'].includes(vote.direction)) return;
        const choice = vote.members.find((entry) => entry.id === member.id)?.choice;
        if (!['yes', 'no'].includes(choice)) return;
        evaluated += 1;
        if (choice === vote.direction) agreement += 1; else divergent += 1;
      });
      const row = node('div', 'outlier-row');
      row.append(node('strong', '', member.name), node('span', 'small text-body-secondary', `${agreement} von ${evaluated} auswertbaren Stimmen stimmen mit der Kohortmehrheit überein.`), divergent ? badge(`${divergent} abweichend`, 'text-bg-warning') : badge('Keine Abweichung', 'text-bg-light border'));
      panel.append(row);
    });
  }

  const contextTypeMeta = {
    image: { label: 'Bild', icon: 'bi-image' },
    youtube: { label: 'YouTube', icon: 'bi-youtube' },
    link: { label: 'Weblink', icon: 'bi-link-45deg' },
  };

  async function loadContexts() {
    const payload = await api(`/api/insights/${publicId}/contexts`);
    state.contexts = payload.items;
    renderContexts();
  }

  function contextPreview(context) {
    if (context.context_type === 'image') {
      const image = node('img', 'context-image');
      image.src = context.media_url;
      image.alt = context.label || 'Hochgeladenes Kampagnenmaterial';
      image.loading = 'lazy';
      return image;
    }
    if (context.context_type === 'youtube') {
      const ratio = node('div', 'ratio ratio-16x9 context-video');
      const frame = node('iframe');
      frame.src = `https://www.youtube-nocookie.com/embed/${context.youtube_video_id}`;
      frame.title = context.label || 'Nutzerbereitgestelltes YouTube-Video';
      frame.loading = 'lazy';
      frame.referrerPolicy = 'strict-origin-when-cross-origin';
      frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
      frame.setAttribute('allow', 'encrypted-media; picture-in-picture');
      frame.setAttribute('allowfullscreen', '');
      ratio.append(frame);
      return ratio;
    }
    const icon = node('div', 'context-link-preview');
    const symbol = node('i', 'bi bi-box-arrow-up-right');
    symbol.setAttribute('aria-hidden', 'true');
    icon.append(symbol);
    return icon;
  }

  function renderContexts() {
    const list = document.querySelector('[data-context-list]');
    const empty = document.querySelector('[data-context-empty]');
    list.replaceChildren();
    empty.classList.toggle('d-none', state.contexts.length > 0);
    state.contexts.forEach((context, index) => {
      const item = node('article', 'context-card');
      const preview = node('div', 'context-preview');
      preview.append(contextPreview(context));
      const body = node('div', 'context-card-body');
      const heading = node('div', 'd-flex flex-wrap align-items-center gap-2 mb-2');
      heading.append(node('span', 'badge rounded-pill text-bg-light border', contextTypeMeta[context.context_type].label));
      heading.append(node('strong', '', context.label || (context.context_type === 'image' ? context.original_filename : 'Ohne Bezeichnung')));
      body.append(heading);
      if (context.attribution) body.append(node('p', 'small text-body-secondary mb-2', `Quelle/Urheber: ${context.attribution}`));
      if (context.description) body.append(node('p', 'mb-2', context.description));
      if (context.source_url) {
        const source = node('a', 'small d-inline-flex align-items-center gap-1 mb-3', 'Quelle öffnen');
        source.href = context.source_url;
        source.target = '_blank';
        source.rel = 'noopener noreferrer';
        source.append(node('i', 'bi bi-box-arrow-up-right'));
        body.append(source);
      }
      const actions = node('div', 'context-actions');
      const up = node('button', 'btn btn-sm btn-outline-secondary', 'Nach oben');
      up.type = 'button'; up.disabled = index === 0; up.addEventListener('click', () => moveContext(index, -1));
      const down = node('button', 'btn btn-sm btn-outline-secondary', 'Nach unten');
      down.type = 'button'; down.disabled = index === state.contexts.length - 1; down.addEventListener('click', () => moveContext(index, 1));
      const edit = node('button', 'btn btn-sm btn-outline-secondary', 'Bearbeiten');
      edit.type = 'button'; edit.addEventListener('click', () => openContextModal(context.context_type, context));
      const remove = node('button', 'btn btn-sm btn-outline-danger', 'Entfernen');
      remove.type = 'button'; remove.addEventListener('click', () => deleteContext(context));
      actions.append(up, down, edit, remove);
      body.append(actions);
      item.append(preview, body);
      list.append(item);
    });
  }

  function openContextModal(type, context = null) {
    const form = document.querySelector('[data-context-form]');
    form.reset();
    form.elements.context_type.value = type;
    form.elements.context_id.value = context?.id || '';
    form.elements.label.value = context?.label || '';
    form.elements.attribution.value = context?.attribution || '';
    form.elements.description.value = context?.description || '';
    form.elements.source_url.value = context?.source_url || '';
    const isImage = type === 'image';
    document.querySelector('[data-context-file-row]').classList.toggle('d-none', !isImage || Boolean(context));
    document.querySelector('[data-context-url-row]').classList.toggle('d-none', false);
    document.querySelector('[data-context-url-label]').textContent = isImage ? 'Quellenlink (optional)' : type === 'youtube' ? 'YouTube-Adresse' : 'Webadresse';
    form.elements.source_url.required = !isImage;
    form.elements.image.required = isImage && !context;
    document.querySelector('[data-context-modal-title]').textContent = `${context ? 'Material bearbeiten' : contextTypeMeta[type].label + ' hinzufügen'}`;
    document.querySelector('[data-context-modal-error]').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance('#context-modal').show();
  }

  function contextMetadata(form) {
    return {
      label: form.elements.label.value,
      attribution: form.elements.attribution.value,
      description: form.elements.description.value,
      source_url: form.elements.source_url.value,
    };
  }

  async function saveContext(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('[type="submit"]');
    const errorBox = document.querySelector('[data-context-modal-error]');
    const startedAt = performance.now();
    form.setAttribute('aria-busy', 'true');
    errorBox.classList.add('d-none');
    try {
      await withBusy({
        button: submit,
        buttonText: 'Wird gespeichert …',
        activityText: 'Kampagnenmaterial wird gespeichert …',
        slowText: 'Das Speichern des Kampagnenmaterials dauert etwas länger – bitte warten.',
      }, async () => {
        const id = form.elements.context_id.value;
        const type = form.elements.context_type.value;
        const metadata = contextMetadata(form);
        if (id) {
          await api(`/api/insights/${publicId}/contexts/${id}`, { method: 'PATCH', body: JSON.stringify(metadata) });
        } else if (type === 'image') {
          const data = new FormData();
          data.append('image', form.elements.image.files[0]);
          Object.entries(metadata).forEach(([key, value]) => { if (value) data.append(key, value); });
          await api(`/api/insights/${publicId}/context-images`, { method: 'POST', body: data });
        } else {
          await api(`/api/insights/${publicId}/contexts`, { method: 'POST', body: JSON.stringify({ context_type: type, ...metadata }) });
        }
        await loadContexts();
        bootstrap.Modal.getInstance('#context-modal').hide();
        setSaveStatus(`Kampagnenmaterial gespeichert${formatDuration(startedAt)}`);
      });
    } catch (error) {
      errorBox.textContent = error.message;
      errorBox.classList.remove('d-none');
      errorBox.focus();
    } finally {
      form.setAttribute('aria-busy', 'false');
    }
  }

  async function moveContext(index, offset) {
    const next = index + offset;
    if (next < 0 || next >= state.contexts.length) return;
    const reordered = [...state.contexts];
    [reordered[index], reordered[next]] = [reordered[next], reordered[index]];
    try {
      const payload = await api(`/api/insights/${publicId}/contexts/order`, {
        method: 'PUT', body: JSON.stringify({ context_ids: reordered.map((context) => context.id) }),
      });
      state.contexts = payload.items;
      renderContexts();
    } catch (error) { showContextError(error); }
  }

  async function deleteContext(context) {
    if (!window.confirm(`„${context.label || 'Dieses Material'}“ wirklich entfernen?`)) return;
    try {
      await api(`/api/insights/${publicId}/contexts/${context.id}`, { method: 'DELETE', body: '{}' });
      await loadContexts();
      setSaveStatus('Kampagnenmaterial entfernt');
    } catch (error) { showContextError(error); }
  }

  function showContextError(error) {
    const box = document.querySelector('[data-context-error]');
    box.textContent = error.message || 'Das Kampagnenmaterial konnte nicht geändert werden.';
    box.classList.remove('d-none');
    box.focus();
  }

  function updateReview() {
    const scope = scopePayload();
    const party = state.options.parties.find((item) => item.id === scope.party_id)?.name || 'Nicht gewählt';
    const chamber = state.options.chambers.find((item) => item.id === scope.chamber_id)?.name || 'Nicht gewählt';
    const values = [
      ['Rahmen', `${party} · ${chamber} · ${scope.period_from || '–'} bis ${scope.period_to || '–'}`],
      ['Mitglieder', `${state.selected.size} ausgewählt`],
      ['Evidenz', `${state.evidence.length} Abstimmungen ausgewählt`],
      ['Kampagnenkontext', `${state.contexts.length} Elemente · nutzerbereitgestellt`],
      ['Aussage', document.querySelector('#wizard-claim').value || 'Noch nicht formuliert'],
    ];
    const summary = document.querySelector('[data-review-summary]'); summary.replaceChildren();
    values.forEach(([title, text]) => { const item = node('div', 'review-item'); item.append(node('p', 'eyebrow mb-1', title), node('p', 'mb-0', text)); summary.append(item); });
  }

  function validationErrors(publication) {
    const errors = [];
    if (!scopeComplete()) errors.push({ step: 0, field: '#scope-chamber', text: 'Rahmen vollständig wählen' });
    if (!state.selected.size) errors.push({ step: 1, field: '[data-member-list] input', text: 'Mindestens ein Mitglied auswählen' });
    if (!state.evidence.length) errors.push({ step: 2, field: '#vote-search', text: 'Mindestens eine Abstimmung als Evidenz auswählen' });
    if (!document.querySelector('#wizard-title').value.trim()) errors.push({ step: 3, field: '#wizard-title', text: 'Titel ergänzen' });
    if (!document.querySelector('#wizard-claim').value.trim()) errors.push({ step: 3, field: '#wizard-claim', text: 'Aussage ergänzen' });
    return publication ? errors : [];
  }

  function showValidation(errors) {
    const alert = document.querySelector('[data-validation-alert]');
    alert.replaceChildren(node('strong', 'd-block mb-2', 'Bitte prüfe folgende Punkte:'));
    const list = node('ul', 'mb-0'); errors.forEach((error) => list.append(node('li', '', error.text))); alert.append(list);
    alert.classList.remove('d-none');
    showStep(errors[0].step, false).then(() => {
      alert.scrollIntoView({ behavior: 'smooth', block: 'center' }); alert.focus();
      document.querySelector(errors[0].field)?.focus();
    });
  }

  function showError(error) {
    const alert = document.querySelector('[data-validation-alert]');
    alert.textContent = error.message || 'Die Änderung konnte nicht gespeichert werden.';
    alert.classList.remove('d-none'); alert.focus();
    setSaveStatus('Speichern fehlgeschlagen', 'bi-cloud-slash');
  }

  async function showStep(index, prepare = true, trigger = null) {
    index = Math.max(0, Math.min(index, steps.length - 1));
    if (state.transitionPromise) return state.transitionPromise;
    const needsPreparation = prepare && (
      (index >= 1 && state.currentStep === 0)
      || (index >= 2 && state.currentStep <= 1)
    );
    const transition = async () => {
      try {
        if (prepare && index >= 1 && state.currentStep === 0) { await saveScope(); await loadMembers(false); }
        if (prepare && index >= 2 && state.currentStep <= 1) {
          if (!state.selected.size) throw new Error('Wähle mindestens ein Mitglied aus.');
          await saveMembers();
          state.baseline = new Set(state.selected);
          await loadVotes(false);
        }
        if (index === 4) updateReview();
      } catch (error) { showError(error); return; }
      state.currentStep = index;
      steps.forEach((step, position) => { step.classList.toggle('active', position === index); step.hidden = position !== index; });
      tabs.forEach((tab, position) => {
        const active = position === index;
        tab.classList.toggle('active', active); tab.toggleAttribute('aria-current', active); tab.setAttribute('aria-selected', String(active));
      });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    if (!needsPreparation) return transition();

    const loadingMembers = index === 1;
    const message = loadingMembers
      ? 'Mitglieder werden für den gewählten Rahmen geladen …'
      : 'Abstimmungen werden für den gewählten Mitgliederkreis berechnet …';
    const button = trigger?.matches('[data-next], [data-prev]') ? trigger : null;
    state.transitionPromise = withBusy({
      button,
      buttonText: loadingMembers ? 'Mitglieder werden geladen …' : 'Abstimmungen werden berechnet …',
      activityText: message,
      slowText: loadingMembers
        ? 'Das Laden der Mitglieder dauert etwas länger – bitte warten.'
        : 'Die Berechnung dauert etwas länger – bitte warten.',
      controls: '[data-step-target], [data-next], [data-prev]',
      formBusy: true,
    }, transition);
    try {
      return await state.transitionPromise;
    } finally {
      state.transitionPromise = null;
    }
  }

  async function submitWizard(event) {
    event.preventDefault();
    if (state.submitting) return;
    const visibility = document.querySelector('#wizard-visibility').value;
    const errors = validationErrors(visibility === 'public');
    if (errors.length) { showValidation(errors); return; }
    document.querySelector('[data-validation-alert]').classList.add('d-none');
    state.submitting = true;
    const startedAt = performance.now();
    try {
      await withBusy({
        button: event.submitter || root.querySelector('[type="submit"]'),
        buttonText: 'Insight wird gespeichert …',
        activityText: 'Insight wird geprüft und gespeichert …',
        slowText: 'Das Speichern dauert etwas länger – bitte warten.',
        controls: '[data-step-target], [data-next], [data-prev], [data-context-add]',
        formBusy: true,
      }, async () => {
        if (scopeComplete()) await saveScope();
        if (state.selected.size) await saveMembers();
        await saveEvidence();
        const payload = await api(`/api/insights/${publicId}`, {
          method: 'PATCH', body: JSON.stringify({
            title: document.querySelector('#wizard-title').value,
            claim_text: document.querySelector('#wizard-claim').value,
            explanatory_notes: document.querySelector('#wizard-notes').value,
            visibility,
          }),
        });
        if (payload.insight.share_url) {
          document.querySelector('[data-wizard-share-url]').value = payload.insight.share_url;
          document.querySelector('[data-wizard-share]').classList.remove('d-none');
          setSaveStatus(`Freigabelink erstellt${formatDuration(startedAt)}`);
        } else {
          setSaveStatus(`Insight gespeichert${formatDuration(startedAt)}`);
          location.href = '/#meine-insights';
        }
      });
    } catch (error) {
      const step = error.code === 'EVIDENCE_WITHOUT_PARTICIPATION' ? 2 : 4;
      await showStep(step, false); showError(error);
    } finally {
      state.submitting = false;
    }
  }

  async function initialize() {
    await withBusy({
      activityText: 'Assistent wird geladen …',
      slowText: 'Das Laden des Assistenten dauert etwas länger – bitte warten.',
      activityDelay: 0,
      controls: '[data-step-target], [data-next], [data-prev]',
      formBusy: true,
    }, async () => {
      const session = await api('/api/session');
      if (!session.authenticated) { location.href = '/'; return; }
      state.csrf = session.csrf_token;
      const payload = await api(`/api/insights/${publicId}/wizard`);
      state.insight = payload.insight; state.options = payload.options;
      state.selected = new Set(payload.insight.members.map((member) => member.id));
      state.baseline = new Set(state.selected); state.evidence = payload.insight.evidence_ids;
      initializeScope();
      document.querySelector('#wizard-title').value = payload.insight.title || '';
      document.querySelector('#wizard-claim').value = payload.insight.claim_text || '';
      document.querySelector('#wizard-notes').value = payload.insight.explanatory_notes || '';
      document.querySelector('#wizard-visibility').value = payload.insight.visibility;
      document.querySelector('[data-wizard-title]').textContent = payload.insight.title || 'Insight erstellen';
      await loadContexts();
      if (scopeComplete()) { await saveScope(); await loadMembers(false); if (state.selected.size) await loadVotes(false); }
      await showStep(0, false);
    });
  }

  tabs.forEach((tab, index) => tab.addEventListener('click', () => showStep(index, true, tab)));
  root.querySelectorAll('[data-next]').forEach((button) => button.addEventListener('click', () => showStep(state.currentStep + 1, true, button)));
  root.querySelectorAll('[data-prev]').forEach((button) => button.addEventListener('click', () => showStep(state.currentStep - 1, true, button)));
  root.addEventListener('submit', submitWizard);
  document.querySelector('#member-search').addEventListener('input', (event) => { state.memberSearch = event.target.value; renderMembers(); });
  document.querySelector('[data-members-all]').addEventListener('click', () => { state.selected = new Set(state.eligible.map((member) => member.id)); renderMembers(); });
  document.querySelector('[data-members-none]').addEventListener('click', () => { state.selected.clear(); renderMembers(); });
  document.querySelector('[data-cohort-toggle]').addEventListener('click', () => document.querySelector('[data-cohort-editor]').classList.toggle('d-none'));
  document.querySelector('[data-cohort-reset]').addEventListener('click', () => { state.selected = new Set(state.baseline); renderMembers(); saveMembers().then(loadVotes).catch(showError); });
  document.querySelector('[data-cohort-search]').addEventListener('input', (event) => { state.cohortSearch = event.target.value; renderCohort(); });
  document.querySelector('[data-vote-search]').addEventListener('click', () => { state.query = document.querySelector('#vote-search').value; loadVotes().catch(showError); });
  document.querySelector('#vote-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); state.query = event.target.value; loadVotes().catch(showError); } });
  document.querySelectorAll('[data-direction]').forEach((button) => button.addEventListener('click', () => { state.direction = button.dataset.direction; document.querySelectorAll('[data-direction]').forEach((item) => item.classList.toggle('active', item === button)); renderVotes(); }));
  document.querySelector('[data-cohesion-filter]').addEventListener('change', (event) => { state.cohesion = event.target.value; renderVotes(); });
  document.querySelector('[data-vote-type-filter]').addEventListener('change', (event) => { state.voteType = event.target.value; renderVotes(); });
  document.querySelector('[data-topic-filter]').addEventListener('change', (event) => { state.topic = event.target.value; renderVotes(); });
  document.querySelector('[data-classification-filter]').addEventListener('change', (event) => { state.classification = event.target.value; renderVotes(); });
  document.querySelector('[data-member-filter]').addEventListener('change', (event) => { state.memberFilter = event.target.value; renderVotes(); });
  document.querySelectorAll('[data-mobile-view]').forEach((button) => button.addEventListener('click', () => { state.mobileView = button.dataset.mobileView; document.querySelectorAll('[data-mobile-view]').forEach((item) => item.classList.toggle('active', item === button)); renderVotes(); }));
  document.querySelector('[data-outlier-toggle]').addEventListener('click', () => document.querySelector('[data-outlier-panel]').classList.toggle('d-none'));
  document.querySelector('[data-evidence-review]').addEventListener('click', () => bootstrap.Modal.getOrCreateInstance('#evidence-modal').show());
  document.querySelectorAll('[data-context-add]').forEach((button) => button.addEventListener('click', () => openContextModal(button.dataset.contextAdd)));
  document.querySelector('[data-context-form]').addEventListener('submit', saveContext);
  document.querySelector('[data-theme-cycle]').addEventListener('click', () => { const order = ['system', 'light', 'dark']; const current = document.documentElement.dataset.themePreference || 'system'; applyTheme(order[(order.indexOf(current) + 1) % order.length]); });
  document.querySelectorAll('#scope-country, #scope-legislature').forEach((select) => select.addEventListener('change', initializeScope));

  initialize().catch(showError);
})();
