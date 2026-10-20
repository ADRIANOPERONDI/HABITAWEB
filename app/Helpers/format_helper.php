<?php

if (! function_exists('short_price')) {
    /**
     * Formata um valor em Reais de forma curta para marcadores de mapa (sem o
     * "R$"). Abaixo de 10 mil o valor sai exato ("2.500"): arredondar 2.500
     * para "3 mil" muda a percepção do preço de um aluguel em 20%. De 10 mil
     * a 999 mil usa "mil" com até 1 casa ("12,5 mil", "350 mil") e de 1 milhão
     * em diante "mi" com até 2 casas ("1,25 mi"), sempre sem zeros sobrando.
     */
    function short_price(float $price): string
    {
        $trim = static fn (float $v, int $dec): string => rtrim(rtrim(number_format($v, $dec, ',', '.'), '0'), ',');

        if ($price < 10000) {
            return number_format($price, 0, ',', '.');
        }

        // 999.960 arredondaria para "1.000 mil": nesse caso já é 1 milhão.
        if ($price < 1000000 && round($price / 1000, 1) < 1000) {
            return $trim($price / 1000, 1) . ' mil';
        }

        return $trim($price / 1000000, 2) . ' mi';
    }
}

if (! function_exists('map_price_label')) {
    /**
     * Texto completo do marcador de preço do mapa: o mesmo critério de
     * `price_label()` (zero/ausente = "Sob consulta", nunca "R$ 0"), mas na
     * forma curta e sem o sufixo "/mês" que não cabe no pin.
     */
    function map_price_label(?float $preco): string
    {
        if ($preco === null || $preco <= 0) {
            return 'Sob consulta';
        }

        return 'R$ ' . short_price($preco);
    }
}

if (! function_exists('price_label')) {
    /**
     * Rótulo público de preço de imóvel. Preço zerado ou ausente não é
     * "grátis" — é o anunciante (ou o Simob, num imóvel sem valor cadastrado
     * na origem) não ter informado o valor —, e mostrar "R$ 0,00" na vitrine
     * passa a impressão errada. Aluguel ganha o sufixo "/mês" embutido, pra
     * não repetir o mesmo `if ($tipo === 'ALUGUEL')` em cada view.
     */
    function price_label(?float $preco, string $tipoNegocio): string
    {
        if ($preco === null || $preco <= 0) {
            return 'Sob consulta';
        }

        $label = 'R$ ' . number_format($preco, 2, ',', '.');

        return $tipoNegocio === 'ALUGUEL' ? $label . '/mês' : $label;
    }
}

if (! function_exists('property_specs')) {
    /**
     * Características numéricas de um imóvel prontas para exibir (área,
     * quartos, banheiros, vagas e, no detalhe, suítes), já filtradas para que
     * um cadastro incompleto nunca gere campo solto ("m²" sem número, ícone
     * sem valor):
     *  - só entra o que se aplica ao tipo (terreno não tem quarto/vaga;
     *    PropertyCategoryHelper::getApplicableFields é a fonte);
     *  - NULL é "não informado" e some; 0 é zero informado de verdade;
     *  - em 'card' o zero também some (poluição visual); em 'detail' aparece;
     *  - área zerada ou ausente some sempre.
     *
     * @param string $context 'card' ou 'detail'
     *
     * @return list<array{key: string, icon: string, value: string, label: string}>
     */
    function property_specs(object $property, string $context = 'card'): array
    {
        $applicable = \App\Helpers\PropertyCategoryHelper::getApplicableFields((string) ($property->tipo_imovel ?? ''));
        $specs      = [];

        $area = (float) ($property->area_total ?? 0);
        if ($area <= 0) {
            $area = (float) ($property->area_construida ?? 0);
        }
        if ($area > 0 && in_array('area_total', $applicable, true)) {
            $specs[] = [
                'key'   => 'area',
                'icon'  => 'fa-ruler-combined',
                'value' => rtrim(rtrim(number_format($area, 2, ',', '.'), '0'), ',') . ' m²',
                'label' => 'Área',
            ];
        }

        $counts = [
            'quartos'   => ['fa-bed', 'Quartos', true],
            'suites'    => ['fa-person-shelter', 'Suítes', false],
            'banheiros' => ['fa-bath', 'Banh.', true],
            'vagas'     => ['fa-car', 'Vagas', true],
        ];
        foreach ($counts as $field => [$icon, $label, $inCard]) {
            if (! in_array($field, $applicable, true) || ($context === 'card' && ! $inCard)) {
                continue;
            }

            $raw = $property->{$field} ?? null;
            if ($raw === null || $raw === '' || (int) $raw < 0) {
                continue;
            }
            if ((int) $raw === 0 && ($context === 'card' || $field === 'suites')) {
                continue;
            }

            $specs[] = ['key' => $field, 'icon' => $icon, 'value' => (string) (int) $raw, 'label' => $label];
        }

        return $specs;
    }
}
