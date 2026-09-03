import { test, expect } from '@playwright/test';
import { STORAGE_STATE_TENANT } from './support/globalSetup';

/**
 * `admin/subscription` (D4) — a barra de "Uso do Plano" (imóveis ativos,
 * sempre ilimitado em todo plano comercial atual) deu lugar à cota de
 * turbinada e aos leads do período. O plano fixo do E2E (`E2E_PLAYWRIGHT`)
 * não tem `limite_turbo_mensal` definido (NULL = ilimitado), então a tela
 * deve mostrar "cota ilimitada", nunca a barra antiga.
 */
test.describe('Página da assinatura', () => {
  test.use({ storageState: STORAGE_STATE_TENANT });

  test('mostra cota de turbinada e leads do período em vez da barra de imóveis', async ({ page }) => {
    await page.goto('/admin/subscription');

    await expect(page.getByText('Turbinadas do plano')).toBeVisible();
    await expect(page.getByText('cota ilimitada')).toBeVisible();
    await expect(page.getByText('Leads recebidos este mês')).toBeVisible();

    // A barra "Uso do Plano" (imóveis ativos) não existe mais nesta tela.
    await expect(page.getByText('Uso do Plano')).toHaveCount(0);
  });
});
