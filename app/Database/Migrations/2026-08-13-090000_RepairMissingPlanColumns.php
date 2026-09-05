<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Repara colunas de `plans` que migrations anteriores registraram como aplicadas
 * sem nunca as criar.
 *
 * `AddMoreFieldsToPlans` (2026-02-05-065000) abre com
 * `if (! $this->db->tableExists('plans')) { return; }`. `tableExists()` consulta
 * `listTables()`, que devolve `dataCache['table_names']` quando já preenchido
 * (BaseConnection::listTables). Numa migração desde banco vazio, esse cache é
 * populado antes de `CreatePlansTable` rodar — então o guard enxerga um banco sem
 * `plans`, a migration retorna em silêncio, e o CI4 a registra como concluída. As
 * colunas nunca entram e nenhuma nova execução as recria, porque a linha já está
 * em `migrations`.
 *
 * O efeito não é cosmético: `PropertyService::checkPhotoLimit` e
 * `Api\V1\AuthController::me` fazem SELECT em `limite_fotos_por_imovel`. Sem a
 * coluna, a query devolve `false` e ambos quebram com "call to a member function
 * on false".
 *
 * Por isso aqui se usa `tableExists($t, false)` e `fieldExists` após limpar o
 * cache: reparo não pode depender do mesmo cache que causou o problema.
 */
class RepairMissingPlanColumns extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();

        if (! $this->db->tableExists('plans', false)) {
            throw new \RuntimeException(
                'Tabela `plans` inexistente. Rode as migrations de criação antes deste reparo.'
            );
        }

        $missing = [];

        if (! $this->db->fieldExists('limite_fotos_por_imovel', 'plans')) {
            $missing['limite_fotos_por_imovel'] = [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10,
                'null'       => true,
            ];
        }

        if (! $this->db->fieldExists('destaques_mensais', 'plans')) {
            $missing['destaques_mensais'] = [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'null'       => true,
            ];
        }

        if ($missing !== []) {
            $this->forge->addColumn('plans', $missing);
        }
    }

    public function down()
    {
        // Sem down: este é um reparo de schema divergente. Remover as colunas
        // devolveria o banco ao estado quebrado que a migration existe para
        // corrigir, e `AddMoreFieldsToPlans::down` já cobre a remoção legítima.
    }
}
