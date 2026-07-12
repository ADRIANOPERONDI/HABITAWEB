import { defineConfig, devices } from '@playwright/test';
import { E2E_PROCESS_ENV } from './e2e/support/testEnv';

const E2E_PORT = process.env.E2E_PORT ?? '8080';
const E2E_BASE_URL = process.env.E2E_BASE_URL ?? `http://localhost:${E2E_PORT}`;
const E2E_SERVER_ENV = {
  ...E2E_PROCESS_ENV,
  HABITAWEB_E2E_BASE_URL: `${E2E_BASE_URL}/`,
};

/**
 * Config do E2E de frontend (Fase 2). O Playwright sobe o próprio `php spark
 * serve` com as variáveis de `.env.testing`. O setup também exige o marcador
 * HABITAWEB_E2E_TESTING e recusa qualquer banco diferente de habitaweb_test.
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false, // testes de admin compartilham sessão/estado; evita corrida
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [['html', { open: 'never' }]],
  globalSetup: require.resolve('./e2e/support/globalSetup.ts'),

  webServer: {
    command: `php spark serve --host localhost --port ${E2E_PORT}`,
    env: E2E_SERVER_ENV,
    url: E2E_BASE_URL,
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
  },

  use: {
    baseURL: E2E_BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Necessário para o fluxo de liveness (app/Views/Admin/profile/index.php,
        // public/js/liveness.js) — sem isso getUserMedia() nunca resolve em CI/headless.
        launchOptions: {
          args: [
            '--use-fake-device-for-media-stream',
            '--use-fake-ui-for-media-stream',
          ],
        },
      },
    },
  ],
});
