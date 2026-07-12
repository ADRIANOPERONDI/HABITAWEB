import { test, expect } from '@playwright/test';

/**
 * Jornada 1: funil público — home → busca → mapa → detalhe → lead.
 * Não depende de login; usa imóveis ACTIVE que já existem no banco (não criamos
 * fixtures de imóvel aqui — o app já tem dados reais de imóveis publicados).
 *
 * Seletores confirmados batendo direto no HTML renderizado (não no que a spec
 * original pré-crash supunha):
 * - /imoveis e /imoveis/mapa renderizam a MESMA view (app/Views/web/search_map.php,
 *   ver App\Controllers\Web\SearchController::executeSearch) — a lista de cards
 *   carrega via fetch assíncrono para app/Views/web/partials/_property_map_list.php,
 *   cujo card real é `article.premium-property-card` com
 *   `a.premium-property-link[href*="/imovel/"]` (não `.card.property-card`, que é
 *   markup de home.php/search.php, views não usadas por essa rota).
 * - Esse link abre em nova aba (target="_blank"), então o clique precisa esperar
 *   o evento 'popup', não uma navegação na mesma page.
 * - O mapa usa L.markerClusterGroup() (search_map.php) com iconCreateFunction
 *   customizado (.premium-cluster-icon) — no zoom inicial (Brasil inteiro,
 *   [-14.235,-51.925] zoom 4), os imóveis de teste (espalhados entre várias
 *   cidades) sempre agrupam em clusters, nunca aparecem como `.price-marker-pill`
 *   individual. `.leaflet-marker-icon` cobre os dois casos (pin solto ou cluster).
 */
test.describe('Funil público', () => {
  test('home → busca por cidade → resultados → detalhe do imóvel', async ({ page, context }) => {
    await page.goto('/');
    await expect(page.locator('form.search-container-floating')).toBeVisible();

    await page.goto('/imoveis');
    const firstCard = page.locator('.premium-property-card a.premium-property-link[href*="/imovel/"]').first();
    await expect(firstCard).toBeVisible();

    const href = await firstCard.getAttribute('href');

    const [detailPage] = await Promise.all([
      context.waitForEvent('page'),
      firstCard.click(),
    ]);
    await detailPage.waitForLoadState();

    await expect(detailPage).toHaveURL(new RegExp(href!.replace(/[/]/g, '\\/')));
    await expect(detailPage.locator('#leadForm')).toBeVisible();
  });

  test('mapa carrega e renderiza pelo menos um marcador ou cluster', async ({ page }) => {
    await page.goto('/imoveis/mapa');

    await expect(page.locator('#map')).toBeVisible();
    // Marcadores/clusters são injetados via fetch assíncrono (fetchMapData()) —
    // só aparecem depois que a chamada à API do mapa resolve.
    await expect(page.locator('.leaflet-marker-icon').first()).toBeVisible({ timeout: 10_000 });
  });

  test('envio do formulário de lead mostra feedback de sucesso', async ({ page, context }) => {
    await page.goto('/imoveis');
    const firstCard = page.locator('.premium-property-card a.premium-property-link[href*="/imovel/"]').first();
    await expect(firstCard).toBeVisible();

    const [detailPage] = await Promise.all([
      context.waitForEvent('page'),
      firstCard.click(),
    ]);
    await detailPage.waitForLoadState();
    await expect(detailPage.locator('#leadForm')).toBeVisible();

    const unique = Date.now();
    await detailPage.fill('#leadForm input[name="nome_visitante"]', 'Playwright E2E');
    await detailPage.fill('#leadForm input[name="email_visitante"]', `playwright-e2e-${unique}@teste.habitaweb.local`);
    await detailPage.fill('#leadForm input[name="telefone_visitante"]', '11999998888');

    await detailPage.click('#leadForm #btnLead');

    const feedback = detailPage.locator('#leadFeedback');
    await expect(feedback).toBeVisible();
    await expect(feedback).not.toHaveClass(/d-none/);
    await expect(feedback).toHaveClass(/text-success/);
  });
});
