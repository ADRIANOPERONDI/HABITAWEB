import { test, expect, type Page } from '@playwright/test';

/**
 * Item 3 do pedido de ajustes de 30/09/2026: a ferramenta de perímetro no
 * mapa da home. Desenhar a área na home leva para a busca (/imoveis/mapa) já
 * com o polígono aplicado; a busca também aceita o polígono direto na URL.
 *
 * O mapa da home só renderiza quando há imóvel ACTIVE com coordenadas — o
 * `e2e:setup` garante um em São Paulo (-23.5505, -46.6333).
 *
 * Leaflet.draw ignora um vértice marcado a menos de 50ms do anterior e
 * fecha sozinho se o terceiro clique cair a 10px do primeiro, por isso os
 * cliques esperam o vértice aparecer e ficam a 80px uns dos outros.
 */
const QUADRADO_SAO_PAULO = [[-46.8, -23.7], [-46.4, -23.7], [-46.4, -23.4], [-46.8, -23.4]];

/**
 * Clica um vértice e confirma que ele entrou. Se o ponto caiu em cima de um
 * pin/cluster (o clique vai para o marcador, não para o desenho), desloca um
 * pouco e tenta de novo. Os pontos ficam na metade inferior do mapa, longe
 * da barra de desenho (canto superior direito) e do zoom (superior esquerdo).
 */
async function clicarVertice(page: Page, x: number, y: number, esperados: number): Promise<void> {
  for (let tentativa = 0; tentativa < 4; tentativa++) {
    await page.mouse.click(x + tentativa * 28, y + tentativa * 18);
    await page.waitForTimeout(120); // Leaflet.draw ignora vértice a <50ms do anterior
    if (await page.locator('#homeMap .leaflet-editing-icon').count() === esperados) return;
  }
  await expect(page.locator('#homeMap .leaflet-editing-icon')).toHaveCount(esperados);
}

test.describe('Perímetro no mapa da home', () => {
  test('botão de desenhar área está visível e não cobre o zoom', async ({ page }) => {
    await page.goto('/');
    const botao = page.locator('#btnStartDraw');
    await expect(botao).toBeVisible();

    const zoom = await page.locator('#homeMap .leaflet-control-zoom').boundingBox();
    const caixa = await botao.boundingBox();
    expect(zoom && caixa).toBeTruthy();
    const separados = caixa!.x >= zoom!.x + zoom!.width || caixa!.x + caixa!.width <= zoom!.x
      || caixa!.y >= zoom!.y + zoom!.height || caixa!.y + caixa!.height <= zoom!.y;
    expect(separados, 'o controle de desenho não pode sobrepor o controle de zoom').toBe(true);
  });

  test('desenhar e concluir a área abre a busca com o polígono aplicado', async ({ page }) => {
    await page.goto('/');
    await page.locator('#btnStartDraw').click();
    // O Leaflet.draw é carregado sob demanda no primeiro clique.
    await expect(page.locator('#homeMap .leaflet-mouse-marker')).toBeAttached({ timeout: 10_000 });
    await expect(page.locator('#btnFinishDraw')).toBeVisible();
    await expect(page.locator('#btnFinishDraw')).toBeDisabled();

    // O mapa tem 560px numa janela de 720px: centraliza para os três pontos
    // caírem dentro da área visível (clique fora da janela não chega ao mapa).
    await page.evaluate(() => document.getElementById('homeMap')!.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    const mapa = await page.locator('#homeMap').boundingBox();
    expect(mapa).toBeTruthy();
    const px = (fx: number) => mapa!.x + mapa!.width * fx;
    const py = (fy: number) => mapa!.y + mapa!.height * fy;

    await clicarVertice(page, px(0.22), py(0.58), 1);
    await clicarVertice(page, px(0.62), py(0.58), 2);
    await clicarVertice(page, px(0.42), py(0.86), 3);

    await expect(page.locator('#btnFinishDraw')).toBeEnabled();
    await page.locator('#btnFinishDraw').click();

    await page.waitForURL(/\/imoveis\/mapa\?polygon=/);
    const polygon = JSON.parse(decodeURIComponent(new URL(page.url()).searchParams.get('polygon')!));
    expect(polygon).toHaveLength(3);

    // A busca abre com a área já desenhada e o botão de apagar no lugar do de desenhar.
    await expect(page.locator('#map path.leaflet-interactive')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#btnClearDraw')).toBeVisible();
    await expect(page.locator('#inputPolygon')).toHaveValue(JSON.stringify(polygon));
  });

  test('cancelar o desenho volta ao estado inicial sem sair da home', async ({ page }) => {
    await page.goto('/');
    await page.locator('#btnStartDraw').click();
    await expect(page.locator('#btnCancelDraw')).toBeVisible({ timeout: 10_000 });

    await page.locator('#btnCancelDraw').click();

    await expect(page.locator('#btnStartDraw')).toBeVisible();
    await expect(page.locator('#btnCancelDraw')).toBeHidden();
    expect(new URL(page.url()).pathname).toBe('/');
  });
});

test.describe('Deep link de polígono na busca', () => {
  test('/imoveis/mapa?polygon= filtra a lista pela área e desenha o polígono', async ({ page }) => {
    await page.goto('/imoveis/mapa?polygon=' + encodeURIComponent(JSON.stringify(QUADRADO_SAO_PAULO)));

    await expect(page.locator('#inputPolygon')).toHaveValue(JSON.stringify(QUADRADO_SAO_PAULO));
    await expect(page.locator('#map path.leaflet-interactive')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#btnClearDraw')).toBeVisible();
    await expect(page.locator('#mapResultsSubtitle')).toHaveText(/área desenhada/, { timeout: 10_000 });
    await expect(page.locator('.premium-property-card').first()).toBeVisible({ timeout: 10_000 });
    // Recarregar mantém o polígono na URL (o JS não pode apagá-lo ao sincronizar).
    await page.reload();
    expect(new URL(page.url()).searchParams.get('polygon')).toBe(JSON.stringify(QUADRADO_SAO_PAULO));
  });

  test('polígono inválido na URL é ignorado sem quebrar a página', async ({ page }) => {
    await page.goto('/imoveis/mapa?polygon=abc');
    await expect(page.locator('#map')).toBeVisible();
    await expect(page.locator('#inputPolygon')).toHaveValue('');
    await expect(page.locator('#btnStartDraw')).toBeVisible();
  });
});
