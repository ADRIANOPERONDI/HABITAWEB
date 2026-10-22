import { test, expect, devices, type Page } from '@playwright/test';

/**
 * Regressão do item 13 da revisão da área pública (busca e página do imóvel
 * no celular). Cada teste cobre um defeito encontrado na conferência:
 *
 * - a página da busca tinha `height: 100vh - 106px` com `min-height: 620px` e o
 *   body em overflow hidden: no iPhone o fim da página (59-62px) ficava fora da
 *   tela e inalcançável, e o mapa rolava para baixo do cabeçalho;
 * - a fileira Todos/Comprar/Alugar/Filtros/Limpar rolava de lado e o "Limpar"
 *   ficava cortado na borda;
 * - o fim da lista (último preço, "Carregar mais") ficava sempre embaixo do
 *   botão flutuante "Ver mapa";
 * - no modal de filtros o "Aplicar filtros" ficava abaixo da dobra;
 * - a barra de desenho empilhava Concluir/Desfazer/Cancelar um por linha,
 *   cobrindo a área do mapa onde se desenha;
 * - a página do imóvel tinha 12px de rolagem lateral (`row g-5`) e o
 *   formulário de contato não tinha rótulos nem autocomplete;
 * - "Comprar" abria SÓ o mapa: a lista ficava escondida e tocar num
 *   agrupamento/pin dava zoom sem mostrar imóvel nenhum. Agora a busca abre
 *   na lista e, no mapa, uma faixa de cartões mostra os imóveis da área, do
 *   agrupamento ou do pin tocado.
 *
 * Mesmo esquema de home-mobile.spec.ts: só Chromium está instalado, então as
 * larguras de iPhone são emuladas em cima do perfil do Pixel 7. A tela de
 * 375x667 (iPhone SE/8) é a que expunha o corte de altura.
 */
const { defaultBrowserType: _ignorado, ...pixel7 } = devices['Pixel 7'];

const telas = [
  { nome: 'Pixel 7 (412px)', use: { ...pixel7 } },
  { nome: 'iPhone SE (375x667)', use: { ...pixel7, viewport: { width: 375, height: 667 } } },
];

async function abrirBusca(page: Page): Promise<void> {
  await page.goto('/imoveis');
  await expect(page.locator('.map-property-card').first()).toBeAttached({ timeout: 15_000 });
}

for (const tela of telas) {
  test.describe(`Busca e imóvel no celular — ${tela.nome}`, () => {
    test.use(tela.use);

    test('a busca cabe na tela, nada fica cortado e o fim da lista sai de baixo do botão', async ({ page }) => {
      await abrirBusca(page);
      const { width: vw, height: vh } = page.viewportSize()!;

      const fimDaPagina = await page.locator('#mapPremiumPage').evaluate((el) => el.getBoundingClientRect().bottom);
      expect(fimDaPagina, 'a página da busca passa da altura da tela').toBeLessThanOrEqual(vh + 1);

      // Abre direto na lista, com o primeiro cartão na tela.
      await expect(page.locator('#mapPremiumPage')).toHaveClass(/show-list/);
      await expect(page.locator('.map-property-card').first()).toBeInViewport();
      await expect(page.locator('#btnMobileToggle')).toHaveText(/Ver mapa/);

      const botoes = await page.locator('.map-chip-row > *').evaluateAll((els) =>
        els.map((el) => ({ texto: (el as HTMLElement).innerText.trim(), direita: el.getBoundingClientRect().right }))
      );
      for (const botao of botoes) {
        expect(botao.direita, `"${botao.texto}" passa da borda direita`).toBeLessThanOrEqual(vw + 1);
      }

      const sobreposicao = await page.evaluate(() => {
        const lista = document.getElementById('propertyListContainer')!;
        lista.scrollTop = lista.scrollHeight;
        const itens = Array.from(document.querySelectorAll('.map-property-card, #btnLoadMoreMap'));
        const fim = Math.max(...itens.map((el) => el.getBoundingClientRect().bottom));
        const botao = document.getElementById('btnMobileToggle')!.getBoundingClientRect().top;
        return { fim, botao };
      });
      expect(sobreposicao.fim, 'o fim da lista fica embaixo do botão "Ver mapa"').toBeLessThanOrEqual(sobreposicao.botao);
    });

    test('o modal de filtros mostra "Aplicar filtros" sem precisar rolar', async ({ page }) => {
      await abrirBusca(page);
      await page.locator('#btnAdvancedFilters').tap();
      await expect(page.locator('#advancedFiltersModal')).toHaveClass(/show/);

      const aplicar = page.locator('#btnApplyAdvancedFilters');
      await expect(aplicar).toBeInViewport({ ratio: 1 });
    });

    test('a barra de desenho fica numa linha só, sem cobrir o mapa nem o botão da lista', async ({ page }) => {
      await abrirBusca(page);
      await page.locator('#btnMobileToggle').tap();
      await page.locator('#btnStartDraw').tap();
      await expect(page.locator('#btnFinishDraw')).toBeVisible({ timeout: 15_000 });

      const caixas = await page.evaluate(() => {
        const barra = document.querySelector('.map-draw-toolbar')!.getBoundingClientRect();
        const botao = document.getElementById('btnMobileToggle')!.getBoundingClientRect();
        const faixa = document.getElementById('mapCardStrip')!;
        const topoFaixa = faixa.hidden ? botao.top : faixa.getBoundingClientRect().top;
        return { altura: barra.height, fimBarra: barra.bottom, topoBotao: Math.min(botao.top, topoFaixa) };
      });
      expect(caixas.altura, 'Concluir/Desfazer/Cancelar empilharam').toBeLessThanOrEqual(60);
      expect(caixas.fimBarra, 'a barra de desenho encosta nos cartões ou no botão da lista').toBeLessThanOrEqual(caixas.topoBotao);
    });

    test('no mapa os imóveis aparecem em cartões, inclusive do agrupamento e do pin tocados', async ({ page }) => {
      await abrirBusca(page);
      await page.locator('#btnMobileToggle').tap();
      await expect(page.locator('#mapPremiumPage')).not.toHaveClass(/show-list/);
      await expect(page.locator('#btnMobileToggle')).toHaveText(/Lista \(\d+\)/);

      const faixa = page.locator('#mapCardStrip');
      await expect(faixa.locator('.map-strip-card').first()).toBeInViewport();

      const agrupamento = page.locator('.premium-cluster-icon').first();
      if (await agrupamento.count()) {
        await agrupamento.tap();
        await expect(page.locator('#mapPremiumPage'), 'tocar no agrupamento não troca de tela').not.toHaveClass(/show-list/);
        await expect(faixa.locator('.map-strip-card').first()).toBeInViewport();
      }

      const pin = page.locator('.price-marker-pill').first();
      if (await pin.count()) {
        const id = (await pin.getAttribute('id'))!.replace('marker-pill-', '');
        await pin.tap();
        await expect(page.locator(`#strip-card-${id}`)).toBeInViewport({ ratio: 1 });
      }

      await page.locator('#btnMobileToggle').tap();
      await expect(page.locator('#mapPremiumPage')).toHaveClass(/show-list/);
    });

    test('a página do imóvel não rola de lado e o formulário tem rótulos e autocomplete', async ({ page }) => {
      await abrirBusca(page);
      const href = await page.locator('.map-property-card a.premium-property-link').first().getAttribute('href');
      await page.goto(href!);
      await expect(page.locator('#leadForm')).toBeVisible();

      const vw = page.viewportSize()!.width;
      const larguraDoConteudo = await page.evaluate(() => document.documentElement.scrollWidth);
      expect(larguraDoConteudo, 'a página do imóvel rola de lado').toBeLessThanOrEqual(vw);

      const campos = await page.locator('#leadForm input:not([type=hidden]), #leadForm textarea').evaluateAll((els) =>
        els.map((el) => ({
          nome: (el as HTMLInputElement).name,
          rotulo: ((el as HTMLInputElement).labels?.length ?? 0) > 0,
          autocomplete: el.getAttribute('autocomplete'),
        }))
      );
      for (const campo of campos) {
        expect(campo.rotulo, `${campo.nome} sem rótulo`).toBe(true);
      }
      expect(campos.filter((c) => c.nome !== 'mensagem').map((c) => c.autocomplete)).toEqual(['name', 'email', 'tel']);
    });
  });
}
