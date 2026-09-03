import { test, expect } from '@playwright/test';
import { STORAGE_STATE_TENANT } from './support/globalSetup';

/**
 * `admin/minhas-cobrancas` (D3) — extrato de cobrança por lead do tenant.
 * O fixture E2E (`ensurePendingLeadCharge` em E2ESetup.php) cria uma
 * `lead_charge` PENDING de R$ 80,00 com prazo de contestação aberto, então
 * o botão "contestar" está sempre disponível sem depender de disparar um
 * lead de verdade (fluxo de qualidade/dedup não é o que este teste cobre).
 */
test.describe('Minhas cobranças', () => {
  test.use({ storageState: STORAGE_STATE_TENANT });

  test('mostra a cobrança pendente e permite contestar', async ({ page }) => {
    await page.goto('/admin/minhas-cobrancas');

    await expect(page.getByText('Como funciona')).toBeVisible();
    await expect(page.getByText('Aguardando aprovação')).toBeVisible();
    await expect(page.getByText('R$ 80,00').first()).toBeVisible();

    await page.click('.btn-contestar');

    await page.waitForSelector('.swal2-input', { state: 'visible' });
    await page.fill('.swal2-input', 'Não reconheço este lead.');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/contestar')),
      page.click('.swal2-confirm'),
    ]);

    await expect(page.getByText('Pronto')).toBeVisible();
  });
});
