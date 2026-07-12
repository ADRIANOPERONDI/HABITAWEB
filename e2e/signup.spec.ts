import { test, expect } from '@playwright/test';

/**
 * Jornada 2: signup em /anuncie — validação de e-mail ao vivo, toggle CPF/CNPJ,
 * submit -> ativação por e-mail.
 *
 * Limite deliberado: o app NÃO expõe em lugar nenhum (log, DB em texto claro,
 * endpoint de teste) o código de 6 dígitos enviado por e-mail
 * (App\Controllers\Auth\ActivationController::issueActivationCode grava só o
 * hash via Shield; NotificationService::sendEmail nem tenta enviar se SMTP não
 * estiver configurado — sem fallback de log em dev). Sem SMTP real configurado
 * neste ambiente, não há como obter o código pra completar a verificação de
 * fato. O teste vai até a fronteira real e honesta: conta criada + redirecionado
 * pra tela de ativação com o campo de código visível.
 */
test.describe('Signup /anuncie', () => {
  test('validação de e-mail ao vivo sinaliza e-mail já cadastrado', async ({ page }) => {
    await page.goto('/anuncie');

    // e2e-tenant já existe (criado por php spark e2e:setup).
    await page.fill('input[name="email"]', 'e2e-tenant@teste.habitaweb.local');
    await page.locator('input[name="email"]').blur();

    await expect(page.locator('input[name="email"]')).toHaveClass(/is-invalid/, { timeout: 5_000 });
    await expect(page.locator('#email_feedback')).toBeVisible();
  });

  test('toggle CPF/CNPJ troca máscara e rótulo do campo de documento', async ({ page }) => {
    await page.goto('/anuncie');

    // O toggle depende de jquery.mask, carregado via $.getScript() de um CDN
    // externo só depois do 'load' da página (ver script inline em
    // web/auth/register.php) — precisa esperar isso terminar antes de clicar.
    await page.waitForFunction(() => typeof (window as any).jQuery?.fn?.mask === 'function');

    await expect(page.locator('#label_documento')).toHaveText('CPF');

    await page.locator('label[for="doc_cnpj"]').click();
    await expect(page.locator('#label_documento')).toHaveText('CNPJ');
    await expect(page.locator('#input_documento')).toHaveAttribute('placeholder', '00.000.000/0000-00');

    await page.locator('label[for="doc_cpf"]').click();
    await expect(page.locator('#label_documento')).toHaveText('CPF');
    await expect(page.locator('#input_documento')).toHaveAttribute('placeholder', '000.000.000-00');
  });

  /**
   * RESOLVIDO (2026-07-10): este teste descobriu um bug crítico — todo cadastro
   * novo via /anuncie quebrava com HTTP 500 ("Call to a member function
   * getRow() on false", dentro de insertID()/LASTVAL(), disparado por
   * Shield\Models\UserModel::saveEmailIdentity() logo após criar o usuário).
   *
   * Causa raiz real (confirmada via writable/logs, não só teoria): o log
   * mostrava, IMEDIATAMENTE antes, "ERRO: valor é muito longo para tipo
   * character varying(30)" seguido de "transação atual foi interrompida,
   * comandos ignorados até o fim do bloco de transação" — um INSERT
   * silenciosamente rejeitado (DBDebug=false) que envenena toda a transação
   * aberta por $db->transStart() em AccountService::registerUser(), fazendo a
   * query de LASTVAL() seguinte (não relacionada) explodir. O culpado:
   * 'username' => explode('@', $email)[0] . rand(100,999) sem limite de
   * tamanho, contra users.username varchar(30) — qualquer e-mail com parte
   * local longa (comum em endereços corporativos) estourava a coluna. Esta
   * própria suíte reproduziu isso (Date.now() no e-mail de teste = 13 dígitos).
   *
   * Pior: como PHP trata "Call to a member function on false" como \Error, não
   * \Exception, o catch(\Exception) existente em registerUser() não capturava
   * a falha — a transação nunca era revertida (rollback) e a conta do passo 1
   * ficava órfã no banco (confirmado: havia contas órfãs desde 2026-01-15/16),
   * bloqueando permanentemente reuso do mesmo e-mail/documento.
   *
   * Corrigido em AccountService::registerUser() (username truncado com
   * mb_substr antes do sufixo aleatório) e catch(\Exception) -> catch(\Throwable)
   * ali e em RegisterController::process(), para qualquer falha futura similar
   * reverter a transação e mostrar erro genérico em vez de 500 cru.
   */
  test('cadastro com dados válidos cria a conta e redireciona para ativação por e-mail', async ({ page }) => {
    await page.goto('/anuncie');

    const unique = Date.now();
    await page.fill('input[name="nome"]', 'Playwright E2E Imobiliária');
    await page.fill('input[name="email"]', `playwright-signup-${unique}@teste.habitaweb.local`);
    await page.selectOption('select[name="tipo_conta"]', 'IMOBILIARIA');
    await page.locator('label[for="doc_cpf"]').click();
    await page.fill('#input_documento', generateValidCpf());
    await page.fill('#password', 'PlaywrightE2E#123');
    await page.check('#terms');

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/ativacao\/codigo/);

    // A mensagem de sucesso do cadastro é exibida num modal SweetAlert2 que
    // cobre o resto da página — precisa fechar antes de checar o formulário
    // de ativação por baixo.
    const successModalOk = page.getByRole('button', { name: 'OK' });
    if (await successModalOk.isVisible().catch(() => false)) {
      await successModalOk.click();
    }

    await expect(page.locator('input[name="token"]')).toBeVisible();
    await expect(page.getByRole('button', { name: /reenviar/i })).toBeVisible();
  });
});

/** Mesmo algoritmo de dígito verificador usado pelo validador CPF do servidor. */
function generateValidCpf(): string {
  const base = Array.from({ length: 9 }, () => Math.floor(Math.random() * 10));

  const calcDigit = (digits: number[]): number => {
    let sum = 0;
    let weight = digits.length + 1;
    for (const d of digits) sum += d * weight--;
    const rest = sum % 11;
    return rest < 2 ? 0 : 11 - rest;
  };

  const d1 = calcDigit(base);
  const d2 = calcDigit([...base, d1]);

  return [...base, d1, d2].join('');
}
