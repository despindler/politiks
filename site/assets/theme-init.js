(() => {
  const allowed = new Set(['system', 'light', 'dark']);
  let preference = 'system';
  try {
    const stored = window.localStorage.getItem('politiks-theme');
    if (stored && allowed.has(stored)) preference = stored;
  } catch (_) {
    // Storage may be unavailable in privacy modes; system preference remains safe.
  }
  const resolved = preference === 'system'
    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    : preference;
  document.documentElement.dataset.bsTheme = resolved;
  document.documentElement.dataset.themePreference = preference;
})();
