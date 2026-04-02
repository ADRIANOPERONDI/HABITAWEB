<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CheckPromotionTableCommand extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:check-promotions';
    protected $description = 'Check promotion_packages table directly';
    protected $usage       = 'php spark db:check-promotions';

    public function run(array $params = [])
    {
        $db = \Config\Database::connect();
        $builder = $db->table('promotion_packages');

        CLI::write('Running query to check promotion_packages table...', 'yellow');
        CLI::write('', 'white');

        try {
            $result = $builder->get()->getResultArray();
            
            if (empty($result)) {
                CLI::write('❌ Nenhum pacote encontrado na tabela!', 'red');
            } else {
                CLI::write(sprintf('✅ Encontrados %d pacotes:', count($result)), 'green');
                CLI::write('', 'white');
                
                foreach ($result as $idx => $pkg) {
                    CLI::write(sprintf('%d. %s (%s)', $idx + 1, $pkg['nome'], $pkg['chave']), 'white');
                    CLI::write(sprintf('   Preço: R$ %.2f | Duração: %s dias | Tipo: %s',
                        $pkg['preco'], $pkg['duracao_dias'] ?? 'N/A', $pkg['tipo_promocao']), 'white');
                }
            }
        } catch (\Throwable $e) {
            CLI::write('❌ Erro ao consultar tabela: ' . $e->getMessage(), 'red');
        }
    }
}
