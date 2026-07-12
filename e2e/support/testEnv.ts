import fs from 'node:fs';
import path from 'node:path';

const ROOT_DIR = path.join(__dirname, '..', '..');
const TEST_ENV_PATH = path.join(ROOT_DIR, '.env.testing');

function parseTestEnv(): NodeJS.ProcessEnv {
  const parsed: NodeJS.ProcessEnv = {};

  for (const rawLine of fs.readFileSync(TEST_ENV_PATH, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#') || !line.includes('=')) {
      continue;
    }

    const separator = line.indexOf('=');
    const key = line.slice(0, separator).trim();
    let value = line.slice(separator + 1).trim();

    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }

    parsed[key] = value;
  }

  if (parsed['database.default.database'] !== 'habitaweb_test') {
    throw new Error('.env.testing deve apontar exatamente para habitaweb_test.');
  }

  return parsed;
}

export const E2E_PROCESS_ENV: NodeJS.ProcessEnv = {
  ...process.env,
  ...parseTestEnv(),
  // O ambiente "testing" do CI4 é reservado ao bootstrap do PHPUnit e não
  // inicializa pelo Spark. Este marcador dedicado mantém o mesmo isolamento.
  CI_ENVIRONMENT: 'development',
  HABITAWEB_E2E_TESTING: '1',
};
