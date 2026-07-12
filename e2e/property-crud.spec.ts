import { test, expect } from '@playwright/test';
import path from 'path';
import { STORAGE_STATE_TENANT } from './support/globalSetup';

const FIXTURE_JPG = path.join(__dirname, '..', 'tests', '_support', 'fixtures', 'valid.jpg');

/**
 * Jornada 5: criação de imóvel no admin — formulário premium com CKEditor
 * (descrição) e Select2 (conta/corretor). Persona: e2e-tenant (grupo 'user',
 * não admin/superadmin) — por isso a tela NÃO mostra o seletor de
 * conta/imobiliária (só aparece para isAdmin, ver
 * App\Controllers\Admin\PropertyController::new()); a conta é implicitamente a
 * do próprio tenant.
 *
 * CKEditor: em vez de digitar no iframe de edição, usa a própria API do editor
 * (window.editorInstance.setData/getData) exposta pelo form.php — é o mesmo
 * caminho que o botão "Salvar Rascunho" usa pra sincronizar o textarea antes do
 * submit, então é uma interação real, não um atalho que contorna o app.
 *
 * Salva como DRAFT (validação mais permissiva que ACTIVE) — o objetivo aqui é
 * cobrir o fluxo de criação em si (CKEditor + submit AJAX), não toda regra de
 * completude de anúncio.
 */
test.describe('Property CRUD (admin)', () => {
  test.use({ storageState: STORAGE_STATE_TENANT });

  test('cria um imóvel como rascunho preenchendo título, preço e descrição (CKEditor)', async ({ page }) => {
    await page.goto('/admin/properties/new');
    await expect(page.locator('#propertyForm')).toBeVisible();

    const unique = Date.now();
    const titulo = `Apartamento Playwright E2E ${unique}`;
    await page.fill('input[name="titulo"]', titulo);

    const precoInput = page.locator('input[name="preco"]');
    await precoInput.click();
    await precoInput.fill('');
    await precoInput.pressSequentially('350000', { delay: 20 });

    await page.waitForFunction(() => !!(window as any).editorInstance);
    await page.evaluate(() => {
      (window as any).editorInstance.setData('Descrição gerada pelo teste Playwright E2E.');
    });

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/admin/properties') && r.request().method() === 'POST'),
      page.click('#btnDraftSave'),
    ]);

    const body = await response.json();
    expect(body.success, `esperava success=true, resposta: ${JSON.stringify(body)}`).toBe(true);
    expect(body.id).toBeTruthy();

    // A resposta troca a action do form pra edição (PUT) e libera a aba de
    // mídia — sinal de que o create realmente terminou no client também.
    await expect(page.locator('#propertyForm')).toHaveAttribute('action', new RegExp(`/admin/properties/${body.id}$`));

    await page.goto('/admin/properties');
    await expect(page.getByText(titulo)).toBeVisible();
  });

  test('fotos escolhidas antes do cadastro entram na fila e sobem junto do salvar', async ({ page }) => {
    await page.goto('/admin/properties/new');
    await expect(page.locator('#propertyForm')).toBeVisible();

    const unique = Date.now();
    const titulo = `Imóvel Fila de Fotos E2E ${unique}`;
    await page.fill('input[name="titulo"]', titulo);

    const precoInput = page.locator('input[name="preco"]');
    await precoInput.click();
    await precoInput.fill('');
    await precoInput.pressSequentially('275000', { delay: 20 });

    await page.waitForFunction(() => !!(window as any).editorInstance);
    await page.evaluate(() => {
      (window as any).editorInstance.setData('Cobre a fila de fotos pré-cadastro.');
    });

    // Escolhe a foto ANTES de o imóvel existir: nada de rascunho automático —
    // entra na fila local com preview e badge.
    await page.click('button[data-bs-target="#media"]');
    await page.setInputFiles('#fileInput', FIXTURE_JPG);
    await expect(page.locator('.pending-media')).toHaveCount(1);
    await expect(page.locator('.pending-media .badge')).toContainText('Enviada ao salvar');
    await expect(page.locator('#propertyForm')).toHaveAttribute('action', /\/admin\/properties$/);

    // Salvar cria o imóvel e a fila sobe na sequência, sem nenhum passo extra.
    const uploadResponsePromise = page.waitForResponse(
      (r) => /\/admin\/properties\/\d+\/media$/.test(r.url()) && r.request().method() === 'POST'
    );
    const [createResponse] = await Promise.all([
      page.waitForResponse((r) => /\/admin\/properties$/.test(r.url()) && r.request().method() === 'POST'),
      page.click('#btnDraftSave'),
    ]);
    const created = await createResponse.json();
    expect(created.success, `esperava success=true, resposta: ${JSON.stringify(created)}`).toBe(true);

    const uploaded = await (await uploadResponsePromise).json();
    expect(uploaded.success, `upload da fila falhou: ${JSON.stringify(uploaded)}`).toBe(true);
    expect(uploaded.is_main, 'primeira foto do imóvel deve virar capa').toBe(true);

    // Preview pendente sai, tile real (com id do banco) entra.
    await expect(page.locator('.pending-media')).toHaveCount(0);
    await expect(page.locator(`#media-${uploaded.id}`)).toBeVisible();
  });
});
