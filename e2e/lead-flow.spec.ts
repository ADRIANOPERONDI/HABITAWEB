import { test, expect, type Page } from '@playwright/test';

/**
 * Validação do fluxo de leads no navegador (item 12 da revisão): o que só um
 * navegador de verdade prova. O resto (imobiliária certa, cobrança ligada,
 * falha de entrega, repetidos/inválidos) está em
 * tests/Feature/LeadFlowValidationTest.php.
 *
 * - Duplo clique em "Quero receber contato" não gera dois contatos: o botão
 *   trava durante o envio e, mesmo que dois envios cheguem, o servidor
 *   devolve o MESMO lead (mesma pessoa, mesmo imóvel, 30 dias).
 * - Recarregar a página depois de enviar não reenvia nada: o envio é por
 *   fetch, não por POST de formulário, então o navegador não tem o que
 *   "reenviar".
 */
async function abrirPrimeiroImovel(page: Page): Promise<void> {
  await page.goto('/imoveis');
  const link = page.locator('.map-property-card a.premium-property-link').first();
  await expect(link).toBeAttached({ timeout: 15_000 });
  await page.goto((await link.getAttribute('href'))!);
  await expect(page.locator('#leadForm')).toBeVisible();
}

test('duplo clique no envio e recarregar a página não duplicam o contato', async ({ page }) => {
  await abrirPrimeiroImovel(page);

  const envios: { status: number; leadId: number | null }[] = [];
  page.on('response', async (res) => {
    if (res.request().method() === 'POST' && /\/leads$/.test(new URL(res.url()).pathname)) {
      const body = await res.json().catch(() => ({}));
      envios.push({ status: res.status(), leadId: body.lead_id ?? null });
    }
  });

  const email = `validacao.${Date.now()}@exemplo.com.br`;
  await page.fill('#leadNome', 'Validação Duplo Clique');
  await page.fill('#leadEmail', email);
  await page.fill('#leadTelefone', '(49) 99977-6655');

  await page.locator('#btnLead').dblclick();
  await expect(page.locator('#leadFeedback')).toContainText('Recebemos seu contato', { timeout: 10_000 });
  await page.waitForTimeout(1500);

  expect(envios.length, 'pelo menos um envio').toBeGreaterThanOrEqual(1);
  expect(envios.every((e) => e.status === 200), JSON.stringify(envios)).toBe(true);
  expect(new Set(envios.map((e) => e.leadId)).size, `envios viraram leads diferentes: ${JSON.stringify(envios)}`).toBe(1);

  // Recarregar não reenvia: nenhum POST novo e o formulário volta limpo.
  const antes = envios.length;
  await page.reload();
  await expect(page.locator('#leadForm')).toBeVisible();
  await page.waitForTimeout(1000);
  expect(envios.length, 'recarregar a página reenviou o contato').toBe(antes);
  await expect(page.locator('#leadEmail')).toHaveValue('');

  // Enviar de novo de propósito, mesma pessoa: continua o mesmo lead.
  await page.fill('#leadNome', 'Validação Duplo Clique');
  await page.fill('#leadEmail', email);
  await page.fill('#leadTelefone', '(49) 99977-6655');
  await page.locator('#btnLead').click();
  await expect(page.locator('#leadFeedback')).toContainText('Recebemos seu contato', { timeout: 10_000 });
  await page.waitForTimeout(800);
  expect(new Set(envios.map((e) => e.leadId)).size, 'reenvio da mesma pessoa virou outro lead').toBe(1);
});
