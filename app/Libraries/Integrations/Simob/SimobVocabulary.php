<?php

namespace App\Libraries\Integrations\Simob;

/**
 * Palpites automáticos para o de/para do Simob.
 *
 * Os ids de categoria e de característica são criados por cada imobiliária, o
 * que impede um mapeamento fixo no código. O que dá para fazer é adivinhar pelo
 * NOME e deixar o tenant confirmar na tela — que é o que estas tabelas fazem.
 *
 * Todo palpite entra em integration_mappings com is_confirmed = false. O sync
 * usa o palpite mesmo sem confirmação (senão o primeiro sync viria vazio), mas
 * o painel destaca os não revisados.
 */
final class SimobVocabulary
{
    /**
     * Descrição de categoria -> tipo_imovel do Habitaweb.
     *
     * Chave = fragmento procurado na descrição normalizada. A ordem importa:
     * o primeiro que casar vence, então o mais específico vem antes
     * ("sala comercial" antes de "sala", "casa comercial" antes de "casa").
     */
    private const CATEGORY_GUESSES = [
        'apartamento'    => 'APARTAMENTO',
        'apto'           => 'APARTAMENTO',
        'kitnet'         => 'APARTAMENTO',
        'kitinete'       => 'APARTAMENTO',
        'studio'         => 'APARTAMENTO',
        'cobertura'      => 'COBERTURA',
        'sobrado'        => 'SOBRADO',
        'sala comercial' => 'SALA',
        'sala'           => 'SALA',
        'loja'           => 'LOJA',
        'ponto comercial' => 'COMERCIAL',
        'predio comercial' => 'COMERCIAL',
        'comercial'      => 'COMERCIAL',
        'galpao'         => 'GALPAO',
        'barracao'       => 'GALPAO',
        'pavilhao'       => 'GALPAO',
        'lote'           => 'LOTE',
        'terreno'        => 'TERRENO',
        'area'           => 'TERRENO',
        'chacara'        => 'TERRENO',
        'sitio'          => 'TERRENO',
        'fazenda'        => 'TERRENO',
        'casa'           => 'CASA',
        'residencia'     => 'CASA',
    ];

    /**
     * Descrição de característica -> coluna de properties.
     *
     * Só entram colunas que o import pode escrever com segurança. Nada que
     * esteja em PropertyService::GUARDED_FIELDS.
     */
    private const CHARACTERISTIC_GUESSES = [
        'dormitorio'          => 'quartos',
        'quarto'              => 'quartos',
        'suite'               => 'suites',
        'banheiro'            => 'banheiros',
        'wc'                  => 'banheiros',
        'lavabo'              => 'banheiros',
        'vaga de garagem'     => 'vagas',
        'vagas de garagem'    => 'vagas',
        'garagem'             => 'vagas',
        'vaga'                => 'vagas',
        'area do terreno'     => 'area_total',
        'area total'          => 'area_total',
        'area do imovel'      => 'area_total',
        'area construida'     => 'area_construida',
        'area edificada'      => 'area_construida',
        // "ÁREA DA EDIFICAÇÃO EM M²" é o rótulo real usado por pelo menos uma
        // imobiliária (Giusti) — nenhum fragmento acima batia com ele.
        'area da edificacao'  => 'area_construida',
        'area total edif'     => 'area_construida',
        'area privativa'      => 'area_privativa',
        'area util'           => 'area_privativa',
        'condominio'          => 'valor_condominio',
        'taxa de condominio'  => 'valor_condominio',
        'iptu'                => 'iptu',
        'mobiliado'           => 'mobiliado',
        'semimobiliado'       => 'semimobiliado',
        'semi mobiliado'      => 'semimobiliado',
        'aceita pet'          => 'aceita_pets',
        'aceita animais'      => 'aceita_pets',
        'permite animais'     => 'aceita_pets',
        'desocupado'          => 'is_desocupado',
        'vago'                => 'is_desocupado',
    ];

    /**
     * Colunas que o tenant pode escolher na tela de mapeamento de característica.
     *
     * @return array<string, string> coluna => rótulo
     */
    public static function targetFields(): array
    {
        return [
            'quartos'          => 'Quartos',
            'suites'           => 'Suítes',
            'banheiros'        => 'Banheiros',
            'vagas'            => 'Vagas de garagem',
            'area_total'       => 'Área total (m²)',
            'area_construida'  => 'Área construída (m²)',
            'area_privativa'   => 'Área privativa (m²)',
            'valor_condominio' => 'Valor do condomínio',
            'iptu'             => 'IPTU',
            'mobiliado'        => 'Mobiliado',
            'semimobiliado'    => 'Semimobiliado',
            'aceita_pets'      => 'Aceita pets',
            'is_desocupado'    => 'Desocupado',
        ];
    }

    /**
     * Tipos de imóvel oferecidos na tela de mapeamento de categoria.
     *
     * @return array<string, string>
     */
    public static function propertyTypes(): array
    {
        return [
            'APARTAMENTO' => 'Apartamento',
            'CASA'        => 'Casa',
            'COBERTURA'   => 'Cobertura',
            'SOBRADO'     => 'Sobrado',
            'SALA'        => 'Sala',
            'LOJA'        => 'Loja',
            'COMERCIAL'   => 'Comercial',
            'GALPAO'      => 'Galpão',
            'TERRENO'     => 'Terreno',
            'LOTE'        => 'Lote',
        ];
    }

    /** @return string|null tipo_imovel, ou null se nenhum palpite servir */
    public static function guessPropertyType(string $descricao): ?string
    {
        $needle = self::normalize($descricao);

        if ($needle === '') {
            return null;
        }

        foreach (self::CATEGORY_GUESSES as $fragment => $type) {
            if (str_contains($needle, $fragment)) {
                return $type;
            }
        }

        return null;
    }

    /** @return string|null coluna de properties, ou null se não houver palpite */
    public static function guessTargetField(string $descricao): ?string
    {
        $needle = self::normalize($descricao);

        if ($needle === '') {
            return null;
        }

        foreach (self::CHARACTERISTIC_GUESSES as $fragment => $field) {
            if (str_contains($needle, $fragment)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Minúsculas, sem acento e sem pontuação.
     *
     * As descrições vêm em caixa alta com acento e sufixo de unidade
     * ("ÁREA DO TERRENO - m²"), e o mesmo campo aparece como "Dormitório(s)"
     * numa imobiliária e "DORMITORIOS" em outra.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        // Tira tudo que não seja letra, número ou espaço, e colapsa os espaços.
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
