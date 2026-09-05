<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Colunas do modelo comercial 2026 em `plans`.
 *
 * Até aqui a diferenciação entre planos era 100% numérica (limite de imóveis,
 * de fotos, de selos). O modelo novo diferencia por FUNÇÃO — painel básico ou
 * completo, direito a slot patrocinado, página premium — e nada disso cabe numa
 * coluna de contagem.
 *
 * A divisão adotada: número vira coluna tipada, porque entra em aritmética e em
 * WHERE; comportamento vira flag em `features` JSONB, porque é lido como booleano
 * e cresce sem exigir migration a cada recurso novo.
 *
 * Não remove `destaques_mensais` aqui de propósito: primeiro o código para de
 * usá-la, depois a coluna cai (migration seguinte). Derrubar junto quebraria a
 * tela de assinatura entre um deploy e outro.
 */
class AddCommercialFieldsToPlans extends Migration
{
    public function up()
    {
        // tableExists() com cache já marcou uma migration como aplicada sem
        // criar coluna nenhuma neste repositório (ver RepairMissingPlanColumns).
        $this->db->resetDataCache();

        $fields = [];

        if (! $this->db->fieldExists('features', 'plans')) {
            $fields['features'] = [
                'type'    => 'JSONB',
                'null'    => false,
                'default' => '{}',
            ];
        }

        if (! $this->db->fieldExists('credito_leads_mensal', 'plans')) {
            $fields['credito_leads_mensal'] = [
                'type'       => 'NUMERIC',
                'constraint' => '10,2',
                'null'       => false,
                'default'    => 0,
            ];
        }

        // Peso DENTRO da lane patrocinada — nunca multiplicador do ranking
        // orgânico. 0 significa inelegível a slot pago.
        if (! $this->db->fieldExists('exposure_weight', 'plans')) {
            $fields['exposure_weight'] = [
                'type'    => 'SMALLINT',
                'null'    => false,
                'default' => 0,
            ];
        }

        // Turbinadas extras por mês concedidas a quem assina no ciclo anual. O
        // benefício do anual é exposição, não desconto.
        if (! $this->db->fieldExists('turbo_bonus_anual', 'plans')) {
            $fields['turbo_bonus_anual'] = [
                'type'    => 'SMALLINT',
                'null'    => false,
                'default' => 0,
            ];
        }

        // PlanModel::$allowedFields reivindicava `descricao` sem que nenhuma
        // migration a criasse; foi retirada de lá na Fase 0 e agora existe.
        if (! $this->db->fieldExists('descricao', 'plans')) {
            $fields['descricao'] = [
                'type' => 'TEXT',
                'null' => true,
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('plans', $fields);
        }
    }

    public function down()
    {
        $this->db->resetDataCache();

        foreach (['features', 'credito_leads_mensal', 'exposure_weight', 'turbo_bonus_anual', 'descricao'] as $column) {
            if ($this->db->fieldExists($column, 'plans')) {
                $this->forge->dropColumn('plans', $column);
            }
        }
    }
}
