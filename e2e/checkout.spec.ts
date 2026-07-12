import { test, expect } from '@playwright/test';
import { STORAGE_STATE_TENANT } from './support/globalSetup';

/**
 * Jornada 3 (parcial): checkout — seleção de plano + validação de cupom via AJAX.
 *
 * Escopo deliberadamente limitado: PARA por aqui, sem submeter o pagamento de
 * verdade. `CheckoutController::process` chama PaymentService::initializeSubscription
 * / initiateTokenizationPayment, que batem no gateway Asaas real (mesmo em
 * sandbox) — e as credenciais configuradas neste banco de dev estão
 * inválidas/expiradas (confirmado em writable/logs: 401 "chave de API
 * fornecida é inválida" + falha ao descriptografar config). Submeter o
 * formulário de fato só produziria erro de gateway (não do checkout em si) e,
 * como o PHP dev server built-in é single-threaded, uma chamada de rede lenta
 * trava a suíte inteira pro request seguinte — o mesmo tipo de problema que já
 * causou o cascade de falhas corrigido em public-funnel.spec.ts. O fluxo
 * completo de pagamento pertence a Tests\E2E\SubscriptionSandboxTest
 * (grupo asaas-sandbox, backend), que só roda com credenciais reais.
 *
 * A validação de cupom em si (checkout/validate-coupon) não toca o gateway —
 * só PlanModel + PaymentService::validateCoupon local — por isso é segura e
 * valiosa de cobrir aqui.
 */
test.describe('Checkout', () => {
  test.use({ storageState: STORAGE_STATE_TENANT });

  test('seleciona o plano E2E e valida cupom inválido e válido via AJAX', async ({ page }) => {
    await page.goto('/checkout/plans');

    const planCard = page.locator('.card', { hasText: 'Plano E2E Playwright' });
    await expect(planCard).toBeVisible();
    await planCard.getByRole('link', { name: 'Selecionar Plano' }).click();

    await expect(page).toHaveURL(/\/checkout\/plan\/\d+/);
    await expect(page.locator('#coupon_code_input')).toBeVisible();

    // Cupom inexistente -> feedback de erro.
    await page.fill('#coupon_code_input', 'CUPOM-INEXISTENTE-XPTO');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('checkout/validate-coupon')),
      page.click('#apply_coupon_btn'),
    ]);
    await expect(page.locator('#coupon_feedback')).toHaveClass(/text-danger/);
    await expect(page.locator('#coupon_code_input')).toHaveClass(/is-invalid/);

    // Cupom real e ativo (seed OFERTA524OFF, 10% off, sem restrição/expiração)
    // -> feedback de sucesso e valor final atualizado na tela.
    await page.fill('#coupon_code_input', 'OFERTA524OFF');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('checkout/validate-coupon')),
      page.click('#apply_coupon_btn'),
    ]);
    await expect(page.locator('#coupon_feedback')).toHaveClass(/text-success/);
    await expect(page.locator('#coupon_code_input')).toHaveClass(/is-valid/);
    await expect(page.locator('#hidden_coupon_code')).toHaveValue('OFERTA524OFF');
  });
});
