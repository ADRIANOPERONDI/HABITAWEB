<?php

namespace App\Entities;

/**
 * Catálogo das features funcionais de plano.
 *
 * Fonte única: o form do superadmin, a vitrine de planos e os gates do app leem
 * daqui. Acrescentar recurso é acrescentar uma constante e uma linha em
 * catalog() — sem migration, porque as flags vivem em `plans.features` (JSONB).
 *
 * A chave é string com namespace (`painel.completo`) e não bitmask nem coluna
 * booleana: o JSONB é consultável em SQL (`features->>'exposicao.busca'`), o que
 * a lane patrocinada usa direto no WHERE, e permanece legível num dump.
 */
final class PlanFeature
{
    /** Painel de resultados completo (conversão, série temporal, ranking, origem). */
    public const PAINEL_COMPLETO = 'painel.completo';

    /** Elegível a slot patrocinado dentro do resultado de busca. */
    public const EXPOSICAO_BUSCA = 'exposicao.busca';

    /** Aparece na vitrine "Imobiliárias em destaque". */
    public const EXPOSICAO_VITRINE = 'exposicao.vitrine';

    /** Página pública premium (capa, equipe, lançamentos, portfólio). */
    public const PAGINA_PREMIUM = 'pagina.premium';

    /** Relatórios de mercado do portal (bairros e faixas mais procurados). */
    public const INTELIGENCIA_MERCADO = 'inteligencia.mercado';

    /** Comparativo da carteira própria com a média da plataforma. */
    public const COMPARATIVO_MERCADO = 'comparativo.mercado';

    /**
     * @return array<string, array{label: string, descricao: string}>
     */
    public static function catalog(): array
    {
        return [
            self::PAINEL_COMPLETO => [
                'label'     => 'Painel completo',
                'descricao' => 'Conversão, desempenho por imóvel, evolução no tempo e origem dos acessos.',
            ],
            self::EXPOSICAO_BUSCA => [
                'label'     => 'Destaque nos resultados',
                'descricao' => 'Elegível aos espaços identificados como Patrocinado dentro da busca.',
            ],
            self::EXPOSICAO_VITRINE => [
                'label'     => 'Imobiliárias em destaque',
                'descricao' => 'Aparece na vitrine institucional da home.',
            ],
            self::PAGINA_PREMIUM => [
                'label'     => 'Página premium',
                'descricao' => 'Capa personalizada, equipe, lançamentos e portfólio completo.',
            ],
            self::INTELIGENCIA_MERCADO => [
                'label'     => 'Inteligência de mercado',
                'descricao' => 'Bairros, faixas de preço e tipos mais procurados na praça.',
                // Tela ainda não existe (fase 2) — fica fora dos cards de
                // checkout/upsell (ver visiveis()) até ter o que mostrar.
                // A constante e a entrada no catálogo continuam aqui pro dia
                // em que a tela existir.
                'oculto'    => true,
            ],
            self::COMPARATIVO_MERCADO => [
                'label'     => 'Comparativo com o mercado',
                'descricao' => 'Participação na oferta e nos leads, e conversão ante a média do portal.',
            ],
        ];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Features prontas pra aparecer em card/vitrine — sem as marcadas
     * `oculto` no catalog() (ainda sem tela nenhuma). Views voltadas ao
     * tenant (checkout, upsell do dashboard) usam isto em vez de
     * catalog()/all() direto; o formulário de planos do superadmin continua
     * usando o catálogo inteiro, porque a equipe precisa configurar a
     * feature antes de a tela existir.
     *
     * @return string[]
     */
    public static function visiveis(): array
    {
        return array_keys(array_filter(
            self::catalog(),
            static fn (array $feature): bool => empty($feature['oculto'])
        ));
    }

    public static function exists(string $feature): bool
    {
        return isset(self::catalog()[$feature]);
    }

    public static function label(string $feature): string
    {
        return self::catalog()[$feature]['label'] ?? $feature;
    }
}
