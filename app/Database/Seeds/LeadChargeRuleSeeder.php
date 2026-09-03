<?php

namespace App\Database\Seeds;

use App\Models\LeadChargeRuleModel;
use CodeIgniter\Database\Seeder;

/**
 * Regras padrão de cobrança por lead recebido — plataforma inteira
 * (`account_id` e `provider_code` NULL), preço fixo único por tipo de
 * negócio. Sem este seeder, `lead_charge_rules` fica vazia e
 * `LeadChargeRuleModel::resolveFor()` nunca encontra regra nenhuma — a única
 * receita do semestre de lançamento não liga sozinha.
 *
 * `valid_from` é a chave-mestra da virada comercial: o código sobe e resolve
 * a regra normalmente, mas ela só vale a partir dessa data (ver runbook
 * §13). Default é hoje; em produção, `LEAD_CHARGE_VALID_FROM` no `.env`
 * decide o dia real sem precisar mexer neste seeder.
 *
 * Idempotente: upsert por (account_id, provider_code, tipo_negocio) — não há
 * índice único no banco para essa combinação (a tabela permite regra por
 * tenant), então a garantia de não duplicar é feita aqui, olhando antes de
 * gravar, do mesmo jeito que `PlanSeeder::upsert()` faz por `chave`.
 */
class LeadChargeRuleSeeder extends Seeder
{
    public function run()
    {
        // env() só cai no default quando a chave está de fato ausente — uma
        // linha em branco em .env (LEAD_CHARGE_VALID_FROM = , o padrão vindo
        // de env.example) já conta como "presente" e devolveria string vazia
        // direto pra uma coluna DATE.
        $validFrom = (string) env('LEAD_CHARGE_VALID_FROM', '');
        $validFrom = $validFrom === '' ? date('Y-m-d') : $validFrom;

        foreach ($this->regrasPadrao() as $regra) {
            $this->upsert($regra['tipo_negocio'], $regra['value'], $validFrom);
        }
    }

    /** @return list<array{tipo_negocio: string, value: float}> */
    private function regrasPadrao(): array
    {
        return [
            ['tipo_negocio' => 'VENDA', 'value' => 80.00],
            ['tipo_negocio' => 'ALUGUEL', 'value' => 40.00],
            ['tipo_negocio' => 'TEMPORADA', 'value' => 40.00],
        ];
    }

    private function upsert(string $tipoNegocio, float $value, string $validFrom): void
    {
        $existente = $this->db->table('lead_charge_rules')
            ->where('account_id', null)
            ->where('provider_code', null)
            ->where('tipo_negocio', $tipoNegocio)
            ->get()
            ->getRowArray();

        $dados = [
            'account_id'    => null,
            'provider_code' => null,
            'tipo_negocio'  => $tipoNegocio,
            'model'         => LeadChargeRuleModel::MODEL_FIXED,
            'value'         => $value,
            // Booleano vai como 't'/'f': o query builder cru rejeita o
            // false/true nativo do PHP contra coluna boolean do Postgres
            // (mesmo motivo documentado em PlanSeeder).
            'is_active'     => 't',
            'valid_from'    => $validFrom,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($existente) {
            $this->db->table('lead_charge_rules')->where('id', $existente['id'])->update($dados);

            return;
        }

        $dados['created_at'] = date('Y-m-d H:i:s');
        $this->db->table('lead_charge_rules')->insert($dados);
    }
}
