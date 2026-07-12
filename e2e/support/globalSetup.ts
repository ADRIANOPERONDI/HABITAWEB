import { chromium, type FullConfig } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { E2E_PROCESS_ENV } from './testEnv';

export const STORAGE_STATE_SUPERADMIN = path.join(__dirname, '.auth-superadmin.json');
export const STORAGE_STATE_TENANT = path.join(__dirname, '.auth-tenant.json');
export const STORAGE_STATE_PENDING_KYC = path.join(__dirname, '.auth-pending-kyc.json');

export const SUPERADMIN_EMAIL = 'e2e-superadmin@teste.habitaweb.local';
export const SUPERADMIN_PASSWORD = 'E2ESuperAdmin#Teste123';
export const TENANT_EMAIL = 'e2e-tenant@teste.habitaweb.local';
export const TENANT_PASSWORD = 'E2ETenant#Teste123';
export const PENDING_KYC_EMAIL = 'e2e-pending-kyc@teste.habitaweb.local';
export const PENDING_KYC_PASSWORD = 'E2EPendingKyc#Teste123';

const ROOT_DIR = path.join(__dirname, '..', '..');

/**
 * Roda antes de toda a suíte: garante os dados fixos (app/Commands/E2ESetup.php
 * — não-destrutivo, ver comentário lá) e faz login de verdade via navegador para
 * as duas personas reaproveitadas pelos testes (superadmin e tenant comum),
 * salvando a sessão em storageState para não logar de novo em cada teste.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  console.log('[globalSetup] Rodando php spark e2e:setup...');
  execFileSync('php', ['spark', 'e2e:setup'], {
    cwd: ROOT_DIR,
    env: E2E_PROCESS_ENV,
    stdio: 'inherit',
  });

  const baseURL = config.projects[0]?.use?.baseURL ?? 'http://localhost:8080';
  const browser = await chromium.launch();

  await loginAndSaveState(browser, baseURL, SUPERADMIN_EMAIL, SUPERADMIN_PASSWORD, STORAGE_STATE_SUPERADMIN);
  await loginAndSaveState(browser, baseURL, TENANT_EMAIL, TENANT_PASSWORD, STORAGE_STATE_TENANT);
  await loginAndSaveState(browser, baseURL, PENDING_KYC_EMAIL, PENDING_KYC_PASSWORD, STORAGE_STATE_PENDING_KYC);

  await browser.close();
}

async function loginAndSaveState(
  browser: import('@playwright/test').Browser,
  baseURL: string,
  email: string,
  password: string,
  storageStatePath: string,
): Promise<void> {
  const page = await browser.newPage({ baseURL });
  const response = await page.goto('/admin/login');
  console.log(`[globalSetup] GET /admin/login -> status=${response?.status()} url=${page.url()}`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');

  if (page.url().includes('/admin/login')) {
    throw new Error(`[globalSetup] Login falhou para ${email} — ainda na página de login após submit.`);
  }

  await page.context().storageState({ path: storageStatePath });
  await page.close();
  console.log(`[globalSetup] Sessão salva para ${email} -> ${storageStatePath}`);
}
