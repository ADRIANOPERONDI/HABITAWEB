import { test, expect, devices } from '@playwright/test';

/**
 * Regressão do item 1 do pedido de ajustes de 30/09/2026: no celular a home
 * era diagramada com ~580px de largura numa tela de 375px (a barra de busca
 * flutuante não quebrava linha), então o conteúdo parecia "deslocado" e
 * cortado — só o navbar, que é fixed, ficava alinhado à tela.
 *
 * Importante: em emulação mobile o Chrome alarga `window.innerWidth` até a
 * largura do conteúdo quando ele transborda, então comparar `scrollWidth`
 * com `innerWidth` passaria mesmo com o bug. A régua aqui é a largura do
 * viewport configurado no teste.
 *
 * Só Chromium está instalado; os presets de iPhone usam WebKit, por isso as
 * larguras de iPhone são emuladas em cima do perfil do Pixel 7.
 */
// `defaultBrowserType` não pode entrar num test.use() dentro de describe (forçaria
// outro worker) — fica de fora; o projeto já é Chromium.
const { defaultBrowserType: _ignorado, ...pixel7 } = devices['Pixel 7'];

const larguras = [
  { nome: 'Pixel 7 (412px)', use: { ...pixel7 } },
  { nome: 'iPhone estreito (375px)', use: { ...pixel7, viewport: { width: 375, height: 812 } } },
];

for (const largura of larguras) {
  test.describe(`Home no celular — ${largura.nome}`, () => {
    test.use(largura.use);

    test('não gera rolagem horizontal nem deixa elemento fora da tela', async ({ page }) => {
      await page.goto('/');
      await expect(page.locator('form.search-container-floating')).toBeVisible();

      const viewportWidth = page.viewportSize()!.width;
      const medidas = await page.evaluate((limite) => {
        const ofensores: string[] = [];
        document.querySelectorAll<HTMLElement>('body *').forEach((el) => {
          // Dentro do mapa, tiles e pins passam da borda por natureza (o container
          // corta com overflow hidden); o dropdown do Select2 só existe aberto.
          if (el.closest('.leaflet-container') || el.closest('.select2-dropdown')) return;
          const r = el.getBoundingClientRect();
          if (r.width > 0 && r.right > limite + 1) {
            const classes = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
            ofensores.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${classes ? '.' + classes : ''} right=${Math.round(r.right)}`);
          }
        });
        return {
          scrollWidth: document.documentElement.scrollWidth,
          bodyScrollWidth: document.body.scrollWidth,
          ofensores,
        };
      }, viewportWidth);

      expect(medidas.ofensores, `elementos além da borda direita: ${medidas.ofensores.join(' | ')}`).toEqual([]);
      expect(medidas.scrollWidth).toBeLessThanOrEqual(viewportWidth);
      expect(medidas.bodyScrollWidth).toBeLessThanOrEqual(viewportWidth);
    });

    test('barra de busca e títulos cabem na largura da tela', async ({ page }) => {
      await page.goto('/');
      const viewportWidth = page.viewportSize()!.width;

      for (const seletor of ['form.search-container-floating', 'form.search-container-floating .btn-search-round', '.section-title']) {
        const caixas = await page.locator(seletor).evaluateAll((els) =>
          els.map((el) => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; })
        );
        expect(caixas.length, `nenhum elemento para ${seletor}`).toBeGreaterThan(0);
        for (const caixa of caixas) {
          expect(caixa.left, `${seletor} começa antes da borda esquerda`).toBeGreaterThanOrEqual(-1);
          expect(caixa.right, `${seletor} passa da borda direita`).toBeLessThanOrEqual(viewportWidth + 1);
        }
      }

      // No celular a barra vira cartão: os campos ficam empilhados (um por linha)
      // e o botão ocupa a largura toda, em vez de sobrar para fora da tela.
      const campos = await page.locator('form.search-container-floating .search-item').evaluateAll((els) =>
        els.map((el) => Math.round(el.getBoundingClientRect().top))
      );
      expect(new Set(campos).size, 'cada campo da busca deve ocupar a própria linha').toBe(campos.length);
    });
  });
}
