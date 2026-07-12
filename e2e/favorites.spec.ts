import { test, expect } from '@playwright/test';
import { STORAGE_STATE_TENANT } from './support/globalSetup';

/**
 * Jornada 7: favoritar um imóvel na página pública e ver refletido em
 * /meus-favoritos.
 *
 * O botão .btn-favorite em property_details.php não tinha NENHUM JS associado
 * (confirmado por busca em todo public/assets/js) — o endpoint de API
 * (POST /api/v1/favorites/toggle) funcionava, mas exige Authorization: Bearer
 * (pk_ key ou token Shield), que um visitante comum do site (autenticado só por
 * sessão/cookie) não tem. Corrigido com uma rota web dedicada
 * (POST /favoritos/toggle, fora do grupo api_auth, protegida por CSRF normal +
 * auth()->loggedIn() no próprio controller) e o JS de clique no botão. Ver
 * App\Controllers\Web\PropertyDetailsController::show (isFavorited) e
 * app/Config/Routes.php.
 *
 * Round-trip completo (favoritar -> aparece -> desfavoritar -> some) para o
 * teste ficar determinístico independente do estado inicial do botão.
 */
test.describe('Favoritos', () => {
  test.use({ storageState: STORAGE_STATE_TENANT });

  test('favoritar reflete em /meus-favoritos e desfavoritar remove', async ({ page }) => {
    await page.goto('/imoveis');
    const firstCard = page.locator('.premium-property-card a.premium-property-link[href*="/imovel/"]').first();
    await expect(firstCard).toBeVisible();
    const href = await firstCard.getAttribute('href');
    const propertyId = href!.match(/\/imovel\/(\d+)/)![1];

    await page.goto(`/imovel/${propertyId}`);
    const favoriteBtn = page.locator('.btn-favorite');
    await expect(favoriteBtn).toBeVisible();

    // Garante baseline "não favoritado", independente de execuções anteriores.
    if ((await favoriteBtn.getAttribute('data-favorited')) === '1') {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/favoritos/toggle')),
        favoriteBtn.click(),
      ]);
      await expect(favoriteBtn).toHaveAttribute('data-favorited', '0');
    }

    // Favoritar -> ícone muda -> aparece em /meus-favoritos.
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/favoritos/toggle')),
      favoriteBtn.click(),
    ]);
    await expect(favoriteBtn).toHaveAttribute('data-favorited', '1');
    await expect(favoriteBtn.locator('i')).toHaveClass(/fa-solid/);

    await page.goto('/meus-favoritos');
    await expect(page.locator(`a[href*="/imovel/${propertyId}"]`).first()).toBeVisible();

    // Desfavoritar de volta pela página do imóvel -> some da lista.
    await page.goto(`/imovel/${propertyId}`);
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/favoritos/toggle')),
      page.locator('.btn-favorite').click(),
    ]);
    await expect(page.locator('.btn-favorite')).toHaveAttribute('data-favorited', '0');

    await page.goto('/meus-favoritos');
    await expect(page.locator(`a[href*="/imovel/${propertyId}"]`)).toHaveCount(0);
  });
});
