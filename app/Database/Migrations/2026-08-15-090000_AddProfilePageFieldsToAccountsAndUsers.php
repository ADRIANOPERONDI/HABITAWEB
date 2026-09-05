<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Perfil público da imobiliária (Fase 5): endereço, capa, descrição, redes
 * sociais e slug de URL amigável em `accounts`; exibição pública opcional do
 * corretor (foto, cargo, bio, CRECI pessoal) em `users` — hoje a equipe só
 * existe no admin (TeamController), sem nenhum campo pensado para vitrine.
 *
 * `slug` é backfillado aqui a partir de `nome` para que a rota
 * `imobiliaria/(:segment)` (etapa 5.3) funcione de imediato para as contas já
 * existentes — sem backfill, toda conta atual ficaria sem página até o
 * próximo save manual.
 */
class AddProfilePageFieldsToAccountsAndUsers extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();

        $accountColumns = [
            'slug'                => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'cep'                 => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'estado'              => ['type' => 'VARCHAR', 'constraint' => 2, 'null' => true],
            'cidade'              => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'bairro'              => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'rua'                 => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'numero'              => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'complemento'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'latitude'            => ['type' => 'NUMERIC', 'constraint' => '10,7', 'null' => true],
            'longitude'           => ['type' => 'NUMERIC', 'constraint' => '10,7', 'null' => true],
            'descricao'           => ['type' => 'TEXT', 'null' => true],
            'capa'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'site'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'horario_atendimento' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'instagram'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'facebook'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'linkedin'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'youtube'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'tiktok'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ];

        $accountFields = [];
        foreach ($accountColumns as $name => $def) {
            if (! $this->db->fieldExists($name, 'accounts')) {
                $accountFields[$name] = $def;
            }
        }
        if ($accountFields !== []) {
            $this->forge->addColumn('accounts', $accountFields);
        }

        $userColumns = [
            'publico' => ['type' => 'BOOLEAN', 'default' => false, 'null' => false],
            'cargo'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'foto'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'bio'     => ['type' => 'TEXT', 'null' => true],
            'creci'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
        ];

        $userFields = [];
        foreach ($userColumns as $name => $def) {
            if (! $this->db->fieldExists($name, 'users')) {
                $userFields[$name] = $def;
            }
        }
        if ($userFields !== []) {
            $this->forge->addColumn('users', $userFields);
        }

        $this->backfillSlugs();

        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_accounts_slug ON accounts (slug)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS uq_accounts_slug');

        $this->db->resetDataCache();

        $accountColumns = [
            'slug', 'cep', 'estado', 'cidade', 'bairro', 'rua', 'numero', 'complemento',
            'latitude', 'longitude', 'descricao', 'capa', 'site', 'horario_atendimento',
            'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok',
        ];
        foreach ($accountColumns as $column) {
            if ($this->db->fieldExists($column, 'accounts')) {
                $this->forge->dropColumn('accounts', $column);
            }
        }

        foreach (['publico', 'cargo', 'foto', 'bio', 'creci'] as $column) {
            if ($this->db->fieldExists($column, 'users')) {
                $this->forge->dropColumn('users', $column);
            }
        }
    }

    /**
     * Gera slug a partir de `nome` para toda conta ainda sem um, com
     * desempate por sufixo numérico quando o nome colide. `mb_url_title`
     * (não `url_title`) porque nome de imobiliária é overwhelmingly
     * acentuado — "São Paulo Imóveis" sem transliteração viraria um slug
     * com caracteres soltos em vez de "sao-paulo-imoveis".
     */
    private function backfillSlugs(): void
    {
        helper('text');

        $rows = $this->db->query('SELECT id, nome FROM accounts WHERE slug IS NULL ORDER BY id')->getResultArray();
        if ($rows === []) {
            return;
        }

        $used = [];
        $existing = $this->db->query('SELECT slug FROM accounts WHERE slug IS NOT NULL')->getResultArray();
        foreach ($existing as $row) {
            $used[$row['slug']] = true;
        }

        foreach ($rows as $row) {
            $base = mb_url_title((string) ($row['nome'] ?? ''), '-', true);
            if ($base === '') {
                $base = 'conta';
            }

            $slug = $base;
            $suffix = 1;
            while (isset($used[$slug])) {
                $suffix++;
                $slug = $base . '-' . $suffix;
            }
            $used[$slug] = true;

            $this->db->query('UPDATE accounts SET slug = ? WHERE id = ?', [$slug, $row['id']]);
        }
    }
}
