import { defineConfig, devices } from '@playwright/test';

// PRECISA bater com app.baseURL em .env (hoje 'http://localhost:8080/'). Todo
// redirect() de produção (ex.: LoginController -> config('Auth')->loginRedirect())
// gera URL ABSOLUTA a partir desse baseURL, não da porta real da requisição — e
// CodeIgniter\Config\DotEnv::setVariable() usa getenv($name, true) (só local),
// que ignora variável de ambiente do shell e sempre reaplica o valor do .env. Ou
// seja, apontar o webServer para outra porta aqui NÃO muda o baseURL que a app
// usa para redirecionar — o browser é redirecionado de volta pra 8080 e cai em
// ERR_CONNECTION_REFUSED se nada estiver escutando lá. Confirmado batendo com
// curl: POST /admin/login com o server em :8098 respondeu 303 para
// http://localhost:8080/admin (porta errada). Se precisar mudar a porta, mude
// app.baseURL no .env também.
const E2E_PORT = process.env.E2E_PORT ?? '8080';
const E2E_BASE_URL = process.env.E2E_BASE_URL ?? `http://localhost:${E2E_PORT}`;

/**
 * Config do E2E de frontend (Fase 2). O Playwright sobe o próprio `php spark
 * serve` (webServer abaixo) — que conecta no banco que .env apontar (o MESMO
 * banco do seu ambiente normal de desenvolvimento).
 *
 * NOTA IMPORTANTE: tentei originalmente isolar isso apontando o servidor para
 * habitaweb_test via variáveis de ambiente do processo, mas descobri que
 * CodeIgniter\Config\DotEnv sobrescreve incondicionalmente com os valores de
 * .env qualquer chave já presente no arquivo — variável de ambiente do shell
 * NÃO vence. Ou seja, essa isolação não é confiável e eu removi para não criar
 * falsa sensação de segurança. Por isso app/Commands/E2ESetup.php (rodado no
 * globalSetup abaixo) é estritamente NÃO-DESTRUTIVO — nunca DELETE/TRUNCATE, só
 * cria os registros fixos que faltarem — e imprime o banco conectado antes de
 * escrever, para você conferir. Se quiser isolamento real, aponte .env para um
 * banco dedicado de homologação antes de rodar `npm run test:e2e`.
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
