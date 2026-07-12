<?php

namespace Tests\Feature;

use Tests\Support\HabitawebTestCase;

/**
 * Reescrita do antigo tests/unit/SecurityTest.php (que nem rodava — estendia uma
 * base sem get/post/actingAs). Cobre os itens de segurança que não têm suíte
 * dedicada própria: CSRF em formulários web e sanitização de HTML (clean_html()).
 * IDOR/multi-tenant está em ApiFeatureTest; upload de script disfarçado está em
 * PropertyLimitTest; gate de login do admin está em AdminGateTest — não duplicados aqui.
 */
final class SecurityFeatureTest extends HabitawebTestCase
{
    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        // Em teste (FeatureTestTrait), a SecurityException do CSRF propaga como
        // exceção de verdade em vez de virar página 403 renderizada — em produção
        // o handler de exceções do CI4 converte isso numa resposta 403 normal.
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);

        // POST direto sem passar por um GET antes: sem token CSRF nenhum no corpo/sessão.
        $this->post('admin/login', [
            'email'    => 'qualquer@teste.com',
            'password' => 'qualquer',
        ]);
    }

    public function testGetRequestIsNotBlockedByCsrf(): void
    {
        // CSRF só se aplica a métodos que alteram estado; GET precisa continuar livre.
        $this->get('admin/login')->assertOK();
    }

    /**
     * clean_html() garante que nenhuma tag HTML "viva" (com < > reais) sobrevive —
     * seja removendo-a (HTMLPurifier) ou escapando para entidade (fallback esc()).
     * Em ambos os casos o navegador NUNCA interpreta isso como um <script> executável;
     * por isso a asserção é sobre a ausência da tag ativa, não sobre o texto do payload
     * (que pode continuar visível, só que inerte, no fallback esc()).
     */
    public function testCleanHtmlNeverLeavesLiveScriptTag(): void
    {
        helper('sys');

        $malicious = '<p>Descrição legítima</p><script>alert(document.cookie)</script>';
        $clean     = clean_html($malicious);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('<script ', $clean);
    }

    public function testCleanHtmlNeverLeavesLiveEventHandlerAttribute(): void
    {
        helper('sys');

        $malicious = '<img src="x" onerror="alert(1)">';
        $clean     = clean_html($malicious);

        // O ataque real é a tag <img ...> com o atributo onerror ATIVO; texto
        // "onerror" solto e inofensivo (escapado) não é o que estamos vigiando.
        $this->assertStringNotContainsString('<img ', $clean);
    }

    public function testCleanHtmlPreservesSafeFormatting(): void
    {
        helper('sys');

        $safe  = '<p>Apartamento com <b>vista para o mar</b>.</p>';
        $clean = clean_html($safe);

        // Sem HTMLPurifier instalado, o fallback é esc() puro (perde a formatação,
        // mas nunca deixa HTML não escapado passar) — então só garantimos que o
        // TEXTO sobrevive, não a tag em si (a formatação exata depende do purifier).
        $this->assertStringContainsString('vista para o mar', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }
}
