const { spawnSync } = require('node:child_process');
const path = require('node:path');

module.exports = async function globalSetup() {
  const root = path.resolve(__dirname, '..', '..');
  const result = spawnSync('php', ['tests/php/seed_playwright_data.php'], {
    cwd: root,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  if (result.status !== 0) {
    throw new Error((result.stderr || 'Playwright catalogue seed failed.').trim());
  }
};
