<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * social_links(): o rodapé só mostra rede configurada e com destino válido.
 *
 * @internal
 */
final class SocialLinksTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('sys');
    }

    public function testNadaConfiguradoNaoMostraNenhumIcone(): void
    {
        $this->assertSame([], social_links([]));
        $this->assertSame([], social_links(['social.facebook' => '', 'social.instagram' => '#', 'social.whatsapp_number' => '']));
    }

    public function testWhatsappLeAChaveDoPainelEMontaLinkWaMe(): void
    {
        $links = social_links(['social.whatsapp_number' => '(49) 99999-1234']);

        $this->assertSame('https://wa.me/5549999991234', $links[0]['url']);
        $this->assertSame('whatsapp', $links[0]['network']);
    }

    public function testWhatsappComDdiNaoDuplicaEInvalidoSome(): void
    {
        $this->assertSame('https://wa.me/5549999991234', social_links(['social.whatsapp_number' => '+55 49 99999-1234'])[0]['url']);
        $this->assertSame([], social_links(['social.whatsapp_number' => '1234']));
    }

    public function testAceitaUrlSemEsquemaEArrobaDoInstagram(): void
    {
        $links = social_links(['social.facebook' => 'facebook.com/habitaweb', 'social.instagram' => '@habitaweb']);

        $this->assertSame('https://facebook.com/habitaweb', $links[0]['url']);
        $this->assertSame('https://instagram.com/habitaweb', $links[1]['url']);
    }

    public function testDestinoDeOutroDominioEhDescartado(): void
    {
        $this->assertSame([], social_links(['social.facebook' => 'https://golpe.example/facebook', 'social.instagram' => 'javascript:alert(1)']));
        $this->assertSame([], social_links(['social.facebook' => 'https://facebook.com.golpe.example/x']));
        $this->assertSame('https://www.facebook.com/habitaweb', social_links(['social.facebook' => 'https://www.facebook.com/habitaweb'])[0]['url']);
    }
}
