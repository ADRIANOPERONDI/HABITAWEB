import { test, expect } from '@playwright/test';

/**
 * Vitrine pública de planos (D1) — a versão anterior mostrava "10 Destaques"
 * e nenhum preço de lead nem rampa; este teste cobre o link "Planos" da nav
 * (ausente até D1) e os dois elementos de página inteira que independem do
 * plano específico: o preço de lead (`lead_charge_rules`, semeado pelo
 * fixture E2E) e o resumo da rampa de lançamento (`plan_launch_ramps`,
 * semeado pela própria migration da tabela).
 */
test.describe('Vitrine de planos', () => {
  test('nav leva a checkout/plans, que mostra preço de lead e rampa de lançamento', async ({ page }) => {
    await page.goto('/');

    await page.getByRole('link', { name: 'Planos' }).click();
    await expect(page).toHaveURL(/\/checkout\/plans/);

    const planCard = page.locator('.card', { hasText: 'Plano E2E Playwright' });
    await expect(planCard).toBeVisible();

    // Preço de lead — plataforma inteira, igual em todo plano (ver
    // ensureLeadChargeRule() em E2ESetup.php).
    await expect(page.getByText('Venda R$ 80,00')).toBeVisible();

    // Resumo da rampa — texto genérico, não amarrado a nenhum plano.
    await expect(page.getByText(/ciclo mensal/i)).toBeVisible();
  });
});
