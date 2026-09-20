<?php

namespace Tests\Unit;

use App\Libraries\Text\CityName;
use PHPUnit\Framework\TestCase;

/**
 * `properties.cidade` é texto livre e cada caminho de escrita gravava de um
 * jeito — o sync do Simob em caixa alta, o ViaCEP do formulário em Title Case.
 * Como DISTINCT é sensível a caixa no Postgres, a mesma cidade virava duas
 * opções no filtro da busca, e escolher uma escondia os imóveis da outra.
 *
 * @internal
 */
final class CityNameTest extends TestCase
{
    /**
     * @dataProvider casos
     */
    public function testNormaliza(?string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, CityName::normalize($entrada));
    }

    public static function casos(): array
    {
        return [
            'caixa alta do sync Simob'   => ['SÃO MIGUEL DO OESTE', 'São Miguel do Oeste'],
            'minúscula'                  => ['são miguel do oeste', 'São Miguel do Oeste'],
            'já canônica é idempotente'  => ['São Miguel do Oeste', 'São Miguel do Oeste'],
            'preposição de'              => ['RIO DE JANEIRO', 'Rio de Janeiro'],
            'preposição das'             => ['MOGI DAS CRUZES', 'Mogi das Cruzes'],
            'palavra única'              => ['DESCANSO', 'Descanso'],
            'acento preservado'          => ['FLORIANÓPOLIS', 'Florianópolis'],
            'hífen'                      => ['MOGI-GUAÇU', 'Mogi-Guaçu'],
            'apóstrofo'                  => ["SANTA BÁRBARA D'OESTE", "Santa Bárbara d'Oeste"],
            'espaço repetido colapsa'    => ['SÃO  MIGUEL   DO OESTE', 'São Miguel do Oeste'],
            'espaço nas bordas'          => ['  Chapecó  ', 'Chapecó'],
            'vazio'                      => ['', ''],
            'só espaço'                  => ['   ', ''],
            'null passa reto'            => [null, null],
        ];
    }

    /**
     * A garantia que sustenta a deduplicação: duas grafias da mesma cidade têm
     * de convergir para a MESMA string, senão o DISTINCT continua devolvendo
     * duas opções.
     */
    public function testGrafiasDivergentesConvergem(): void
    {
        $variantes = ['SÃO MIGUEL DO OESTE', 'são miguel do oeste', 'São Miguel Do Oeste', 'São Miguel do Oeste'];

        $normalizadas = array_unique(array_map([CityName::class, 'normalize'], $variantes));

        $this->assertCount(1, $normalizadas);
        $this->assertSame('São Miguel do Oeste', reset($normalizadas));
    }

    /**
     * mb_convert_case(MB_CASE_TITLE) sozinho devolve "São Miguel Do Oeste" —
     * é exatamente o que a lista de preposições existe para evitar.
     */
    public function testNaoCapitalizaPreposicaoNoMeio(): void
    {
        $this->assertStringNotContainsString(' Do ', CityName::normalize('SÃO MIGUEL DO OESTE'));
        $this->assertStringNotContainsString(' De ', CityName::normalize('RIO DE JANEIRO'));
    }

    /** Preposição no começo do nome é palavra de verdade, não partícula. */
    public function testPreposicaoNaPrimeiraPalavraEeCapitalizada(): void
    {
        $this->assertSame('Do Carmo', CityName::normalize('DO CARMO'));
    }
}
