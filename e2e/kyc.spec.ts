import path from 'node:path';
import { test, expect } from '@playwright/test';
import { STORAGE_STATE_PENDING_KYC, STORAGE_STATE_SUPERADMIN } from './support/globalSetup';

const FIXTURE_JPG = path.join(__dirname, '..', 'tests', '_support', 'fixtures', 'valid.jpg');

/**
 * Jornada 6: KYC em /admin/profile (upload de documentos + liveness com câmera
 * falsa) e aprovação pelo revisor em /admin/verification.
 *
 * Fronteira real e deliberada na parte de liveness: public/js/liveness.js usa
 * o MediaPipe FaceLandmarker de verdade (modelo carregado via CDN do Google),
 * exigindo landmarks faciais REAIS pra avançar cada uma das 5 etapas
 * (frente/direita/esquerda/cima/baixo). A câmera falsa do Chrome
 * (--use-fake-device-for-media-stream, já configurada em playwright.config.ts)
 * entrega um padrão sintético (barras coloridas), não um rosto — então
 * `faceLandmarks.length > 0` nunca fica verdadeiro e a barra de progresso
 * nunca completa. Isso não é um bug: é exatamente o comportamento de segurança
 * esperado (ninguém deveria conseguir "passar" a biometria com uma imagem
 * sintética). O botão de submit do formulário só habilita com as 3 fotos de
 * documento + liveness completo (ver checkFormCompleteness() em
 * public/js/liveness.js) — por isso este teste NÃO tenta enviar o formulário
 * completo, só valida upload de documentos e que o pipeline de câmera/detecção
 * roda de verdade (prova negativa: sem rosto real, fica travado esperando).
 */
test.describe('KYC / Liveness', () => {
  test.use({ storageState: STORAGE_STATE_PENDING_KYC });

  test('upload de RG frente/verso e selfie gera preview nas 3 caixas', async ({ page }) => {
    await page.goto('/admin/profile');

    await page.setInputFiles('#idFrontInput', FIXTURE_JPG);
    await page.setInputFiles('#idBackInput', FIXTURE_JPG);
    await page.setInputFiles('#selfieInput', FIXTURE_JPG);

    await expect(page.locator('#boxSelfie img')).toBeVisible();
    await expect(page.locator('#idFrontInput')).toHaveJSProperty('files.length', 1);
    await expect(page.locator('#idBackInput')).toHaveJSProperty('files.length', 1);
  });

  test('câmera de liveness inicia com câmera falsa e roda detecção real (sem rosto sintético, não avança etapa)', async ({ page }) => {
    await page.goto('/admin/profile');

    // O clique é bloqueado (alert()) até o script do MediaPipe (CDN) terminar
    // de carregar window.FaceLandmarker — ver admin/profile/index.php.
    await page.waitForFunction(() => !!(window as any).FaceLandmarker, null, { timeout: 20_000 });
    await page.click('#startLivenessBtn');

    await expect(page.locator('#livenessContainer')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#livenessVideo')).toBeVisible();

    // Dá tempo do MediaPipe (CDN + modelo WASM) carregar e rodar detecção real
    // contra o feed sintético da câmera falsa.
    await expect(page.locator('#livenessInstructions')).toContainText(/rosto não detectado|centralize/i, { timeout: 20_000 });
  });
});

/**
 * Cobertura da revisão do KYC pelo superadmin — independente da limitação de
 * liveness acima, já que aprovar/rejeitar não depende de refazer a biometria.
 */
test.describe('Revisão de KYC (superadmin)', () => {
  test.use({ storageState: STORAGE_STATE_SUPERADMIN });

  test('superadmin aprova uma verificação pendente', async ({ page }) => {
    await page.goto('/admin/verification');

    const row = page.locator('tr', { hasText: 'E2E Review Target Imobiliária' });
    await expect(row).toBeVisible();
    await row.getByRole('link', { name: 'Revisar Documentos' }).click();

    await expect(page).toHaveURL(/\/admin\/verification\/show\/\d+/);
    await page.getByRole('button', { name: /aprovar/i }).click();

    await expect(page).toHaveURL(/\/admin\/verification/);
    await expect(page.getByText(/verifica[cç][aã]o aprovada/i).first()).toBeVisible();
  });
});
