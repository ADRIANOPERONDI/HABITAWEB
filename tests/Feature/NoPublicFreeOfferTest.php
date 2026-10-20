<?php

namespace Tests\Feature;

use Tests\Support\HabitawebTestCase;

/**
 * A gratuidade dos primeiros meses é condição de negociação com cada
 * imobiliária (revisão da área pública, item 11): nenhuma página pública
 * anuncia "grátis".
 *
 * @internal
 */
final class NoPublicFreeOfferTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
    }

    /** @return iterable<string, array{string}> */
    public static function paginasPublicas(): iterable
    {
        yield 'home'          => ['/'];
        yield 'anuncie'       => ['anuncie'];
        yield 'busca'         => ['imoveis'];
        yield 'planos'        => ['checkout/plans'];
    }

    /** @dataProvider paginasPublicas */
    public function testPaginaPublicaNaoAnunciaGratis(string $uri): void
    {
        $html = $this->get($uri)->response()->getBody();

        $this->assertDoesNotMatchRegularExpression('/gr[aá]tis/iu', strip_tags($html), "\"grátis\" visível em {$uri}");
    }
}
