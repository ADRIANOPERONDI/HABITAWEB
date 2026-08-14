<?php

namespace App\Database\Seeds;

use App\Entities\PlanFeature;
use CodeIgniter\Database\Seeder;

/**
 * Planos comerciais 2026 — Prata (Presença), Ouro (Performance), Diamante (Liderança).
 *
 * Duas mudanças estruturais em relação à versão anterior deste seeder:
 *
 * 1. **Upsert por `chave`, nunca DELETE.** A versão antiga abria com
 *    `DELETE FROM plans`. Como `subscriptions.plan_id` tem FK com ON DELETE
 *    CASCADE, rodar este seeder num banco com clientes apagaria as assinaturas
 *    junto. Aqui cada plano é procurado pela chave e atualizado no lugar.
 *
 * 2. **As chaves PRATA/OURO/DIAMANTE passam a ser os planos novos**, e os
 *    antigos são preservados como *_LEGADO desativados, com as features
 *    equivalentes ao que já entregavam. Ninguém perde recurso no dia da
 *    migração, e TenantFactory — que resolve plano por chave — continua
 *    funcionando sem tocar em teste nenhum.
 *
 * Nenhum plano limita estoque: os três têm limite_imoveis_ativos = null. É
 * deliberado — quer-se o catálogo inteiro da imobiliária dentro do portal.
 *
 * > Booleano vai como 't'/'f' e não como true/false do PHP. Pelo query builder
 * > cru, o Postgres recebe o false do PHP como inteiro e rejeita a coluna
 * > boolean, abortando a transação inteira — mesmo motivo pelo qual
 * > PlanController já gravava 'ativo' assim.
 */
class PlanSeeder extends Seeder
{
    public function run()
    {
        $this->renomearLegados();

        foreach ($this->planos() as $plano) {
            $this->upsert($plano);
        }
    }

    /**
     * Planos comerciais 2026.
     *
     * `preco_anual` é o total do ciclo, não o equivalente mensal — é assim que
     * PaymentService::getPlanAmountForBillingCycle o interpreta. 9.900 = dez
     * mensalidades de 990 para doze meses de uso.
     *
     * Trimestral e semestral ficam em 0 de propósito: não são vendidos. Com a
     * correção da Fase 0, ciclo sem preço próprio deixa de sair de graça e passa
     * a ser recusado no checkout.
     */
    private function planos(): array
    {
        return [
            [
                'chave'                   => 'PRATA',
                'nome'                    => 'Prata — Presença',
                'descricao'               => 'Todo o seu portfólio na HabitaWeb, com página própria e recebimento de leads.',
                'limite_imoveis_ativos'   => null,
                'limite_fotos_por_imovel' => 30,
                'limite_turbo_mensal'     => 0,
                'turbo_bonus_anual'       => 2,
                'credito_leads_mensal'    => 0.00,
                'exposure_weight'         => 0,
                'limite_api_requests_dia' => 5000,
                'preco_mensal'            => 990.00,
                'preco_anual'             => 9900.00,
                'carencia_dias'           => 3,
                'features'                => [],
            ],
            [
                'chave'                   => 'OURO',
                'nome'                    => 'Ouro — Performance',
                'descricao'               => 'Mais oportunidades: turbinadas incluídas, destaque nos resultados e painel completo.',
                'limite_imoveis_ativos'   => null,
                'limite_fotos_por_imovel' => 50,
                'limite_turbo_mensal'     => 5,
                'turbo_bonus_anual'       => 3,
                'credito_leads_mensal'    => 200.00,
                'exposure_weight'         => 10,
                'limite_api_requests_dia' => 20000,
                'preco_mensal'            => 1690.00,
                'preco_anual'             => 16900.00,
                'carencia_dias'           => 3,
                'features'                => [
                    PlanFeature::PAINEL_COMPLETO   => true,
                    PlanFeature::EXPOSICAO_BUSCA   => true,
                    PlanFeature::EXPOSICAO_VITRINE => true,
                ],
            ],
            [
                'chave'                   => 'DIAMANTE',
                'nome'                    => 'Diamante — Liderança',
                'descricao'               => 'Máxima exposição, página premium e inteligência sobre o mercado da praça.',
                'limite_imoveis_ativos'   => null,
                'limite_fotos_por_imovel' => null,
                'limite_turbo_mensal'     => 10,
                'turbo_bonus_anual'       => 5,
                'credito_leads_mensal'    => 500.00,
                'exposure_weight'         => 20,
                'limite_api_requests_dia' => 50000,
                'preco_mensal'            => 2490.00,
                'preco_anual'             => 24900.00,
                'carencia_dias'           => 3,
                'features'                => [
                    PlanFeature::PAINEL_COMPLETO      => true,
                    PlanFeature::EXPOSICAO_BUSCA      => true,
                    PlanFeature::EXPOSICAO_VITRINE    => true,
                    PlanFeature::PAGINA_PREMIUM       => true,
                    PlanFeature::INTELIGENCIA_MERCADO => true,
                    PlanFeature::COMPARATIVO_MERCADO  => true,
                ],
            ],
        ];
    }

    /**
     * Preserva os planos da tabela antiga de preços sob outra chave.
     *
     * Só age se o plano ainda tiver o preço antigo — assim o seeder é idempotente
     * e rodá-lo duas vezes não cria PRATA_LEGADO_LEGADO. As features atribuídas
     * refletem o que aquele plano já entregava na prática, para que nenhum
     * cliente em contrato perca recurso no dia da virada.
     */
    private function renomearLegados(): void
    {
        $legados = [
            'PRATA'    => ['preco' => 1850.00, 'features' => []],
            'OURO'     => ['preco' => 2850.00, 'features' => [PlanFeature::PAINEL_COMPLETO => true]],
            'DIAMANTE' => ['preco' => 4250.00, 'features' => [
                PlanFeature::PAINEL_COMPLETO   => true,
                PlanFeature::EXPOSICAO_BUSCA   => true,
                PlanFeature::EXPOSICAO_VITRINE => true,
            ]],
        ];

        foreach ($legados as $chave => $config) {
            $atual = $this->db->table('plans')->where('chave', $chave)->get()->getRowArray();

            if (! $atual || (float) $atual['preco_mensal'] !== $config['preco']) {
                continue;
            }

            // `chave` é UNIQUE. Sem este guard, uma execução anterior que já
            // tenha deixado `<chave>_LEGADO` (rodada parcial, dado de outro
            // ambiente) faria este UPDATE estourar violação de unicidade — e
            // como o Postgres aborta a transação INTEIRA no primeiro erro, toda
            // query seguinte do seeder falharia em cascata com uma mensagem que
            // não aponta para a causa real.
            $legadoJaExiste = $this->db->table('plans')
                ->where('chave', $chave . '_LEGADO')
                ->countAllResults() > 0;

            if ($legadoJaExiste) {
                continue;
            }

            $this->db->table('plans')->where('id', $atual['id'])->update([
                'chave'      => $chave . '_LEGADO',
                'nome'       => $atual['nome'] . ' (legado)',
                'ativo'      => 'f',
                'features'   => json_encode($config['features']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function upsert(array $plano): void
    {
        $plano['features']   = json_encode($plano['features']);
        $plano['ativo']      = 't';
        $plano['updated_at'] = date('Y-m-d H:i:s');

        $existente = $this->db->table('plans')->where('chave', $plano['chave'])->get()->getRowArray();

        if ($existente) {
            $this->db->table('plans')->where('id', $existente['id'])->update($plano);

            return;
        }

        $plano['created_at'] = date('Y-m-d H:i:s');
        $this->db->table('plans')->insert($plano);
    }
}
