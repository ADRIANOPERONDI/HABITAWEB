<?php

namespace Tests\Unit;

use App\Libraries\Search\PolygonFilter;
use PHPUnit\Framework\TestCase;

/**
 * O polígono da query string é repetido de volta ao cliente (input oculto da
 * busca, URL, métrica), então a borda precisa recusar tudo que não for uma
 * lista de pares [lng, lat] — e devolver o que for válido num formato único.
 *
 * @internal
 */
final class PolygonFilterTest extends TestCase
{
    private const TRIANGULO = '[[-46.7,-23.6],[-46.5,-23.6],[-46.5,-23.4]]';

    public function testPoligonoValidoVoltaCompactoENormalizado(): void
    {
        $this->assertSame(self::TRIANGULO, PolygonFilter::normalize(self::TRIANGULO));

        // Espaços, zeros à direita e quebras de linha não mudam o resultado.
        $this->assertSame(
            self::TRIANGULO,
            PolygonFilter::normalize(" [[ -46.70, -23.6 ],\n[-46.5, -23.60], [-46.5,-23.4]] ")
        );
    }

    public function testNumeroComoStringEAceitoEConvertido(): void
    {
        $this->assertSame(
            self::TRIANGULO,
            PolygonFilter::normalize('[["-46.7","-23.6"],["-46.5","-23.6"],["-46.5","-23.4"]]')
        );
    }

    public function testTerceiroElementoDoParEIgnorado(): void
    {
        $this->assertSame(
            self::TRIANGULO,
            PolygonFilter::normalize('[[-46.7,-23.6,10],[-46.5,-23.6,10],[-46.5,-23.4,10]]')
        );
    }

    public function testParseDevolvePontosComoFloat(): void
    {
        $this->assertSame(
            [[-46.7, -23.6], [-46.5, -23.6], [-46.5, -23.4]],
            PolygonFilter::parse(self::TRIANGULO)
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function entradasInvalidasProvider(): array
    {
        $quatrocentosVertices = json_encode(array_fill(0, PolygonFilter::MAX_VERTICES + 1, [-46.5, -23.5]));
        $gigante              = '[[' . str_repeat('1', PolygonFilter::MAX_INPUT_LENGTH) . ',1],[2,2],[3,3]]';

        return [
            'nulo'                      => [null],
            'array (polygon[]=)'        => [['[[1,1],[2,2],[3,3]]']],
            'string vazia'              => [''],
            'texto'                     => ['abc'],
            'json invalido'             => ['[[-46.7,-23.6],[-46.5'],
            'objeto em vez de lista'    => ['{"a":[-46.7,-23.6],"b":[-46.5,-23.6],"c":[-46.5,-23.4]}'],
            'par como objeto'           => ['[{"lng":-46.7,"lat":-23.6},{"lng":-46.5,"lat":-23.6},{"lng":-46.5,"lat":-23.4}]'],
            'so dois pontos'            => ['[[-46.7,-23.6],[-46.5,-23.6]]'],
            'par com um elemento'       => ['[[-46.7],[-46.5,-23.6],[-46.5,-23.4]]'],
            'coordenada nao numerica'   => ['[["x",-23.6],[-46.5,-23.6],[-46.5,-23.4]]'],
            'longitude fora da faixa'   => ['[[-190,-23.6],[-46.5,-23.6],[-46.5,-23.4]]'],
            'latitude fora da faixa'    => ['[[-46.7,-95],[-46.5,-23.6],[-46.5,-23.4]]'],
            'numero nao finito'         => ['[["1e400",-23.6],[-46.5,-23.6],[-46.5,-23.4]]'],
            'booleano como coordenada'  => ['[[true,-23.6],[-46.5,-23.6],[-46.5,-23.4]]'],
            'vertices demais'           => [$quatrocentosVertices],
            'string gigante'            => [$gigante],
        ];
    }

    /** @dataProvider entradasInvalidasProvider */
    public function testEntradaInvalidaViraNull(mixed $entrada): void
    {
        $this->assertNull(PolygonFilter::normalize($entrada));
        $this->assertNull(PolygonFilter::parse($entrada));
    }

    public function testLimiteDeVerticesEInclusivo(): void
    {
        $noLimite = json_encode(array_fill(0, PolygonFilter::MAX_VERTICES, [-46.5, -23.5]));

        $this->assertNotNull(PolygonFilter::normalize($noLimite));
    }
}
