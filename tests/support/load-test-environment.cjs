const fs = require('node:fs');
const path = require('node:path');

function parseEnvironment(content) {
  const result = {};

  for (const rawLine of content.split(/\r?\n/u)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) {
      continue;
    }

    const match = line.match(/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/u);
    if (!match) {
      continue;
    }

    let value = match[2].trim();
    if (
      value.length >= 2
      && ((value.startsWith('"') && value.endsWith('"'))
        || (value.startsWith("'") && value.endsWith("'")))
    ) {
      value = value.slice(1, -1);
    }

    result[match[1]] = value;
  }

  return result;
}

function loadTestEnvironment(root = path.resolve(__dirname, '..', '..')) {
  const envPath = path.join(root, '.env.test');
  if (!fs.existsSync(envPath)) {
    return;
  }

  const values = parseEnvironment(fs.readFileSync(envPath, 'utf8'));
  for (const [key, value] of Object.entries(values)) {
    if (process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

module.exports = { loadTestEnvironment, parseEnvironment };
