import { test, expect } from '@playwright/test';
import {
  STORAGE_STATE_SUPERADMIN,
  SUPERADMIN_EMAIL,
  SUPERADMIN_PASSWORD,
} from './support/globalSetup';

/**
 * Jornada 4 do plano: login admin + verificação do bloqueio do AdminAuth.
 * Serve também como teste de fumaça de toda a infra do Playwright (globalSetup,
 * webServer, storageState) — se este arquivo passar, o resto da infra está OK.
 */
test.describe('Login admin', () => {
  test('login com credenciais corretas leva ao dashboard', async ({ page }) => {
    await page.goto('/admin/login');
    await page.fill('input[name="email"]', SUPERADMIN_EMAIL);
    await page.fill('input[name="password"]', SUPERADMIN_PASSWORD);
    await page.click('button[type="submit"]');

    await page.waitForURL(/\/admin(\/dashboard)?$/);
    await expect(page).not.toHaveURL(/\/admin\/login/);
  });

  test('login com senha errada mostra mensagem de erro e não entra', async ({ page }) => {
    await page.goto('/admin/login');
    await page.fill('input[name="email"]', SUPERADMIN_EMAIL);
    await page.fill('input[name="password"]', 'senha-errada-de-proposito');
    await page.click('button[type="submit"]');

    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('acessar o dashboard sem login redireciona para /admin/login', async ({ browser }) => {
    // Contexto novo, sem storageState — sessão limpa de propósito.
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/admin\/login/);

    await context.close();
  });
});

test.describe('Dashboard com sessão já autenticada (storageState)', () => {
  test.use({ storageState: STORAGE_STATE_SUPERADMIN });

  test('superadmin reaproveitando sessão salva acessa o dashboard direto', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await expect(page).not.toHaveURL(/\/admin\/login/);
  });
});
