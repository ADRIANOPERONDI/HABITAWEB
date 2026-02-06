<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PromoCleanup extends BaseCommand
{
    protected $group       = 'Promotion';
    protected $name        = 'promo:cleanup';
    protected $description = 'Verifica e desativa promoções/destaques expirados.';

    public function run(array $params)
    {
        CLI::write('Iniciando verificação de promoções expiradas...', 'yellow');

        try {
            $service = service('promotionService');
            $service->deactivateExpired();
            
            CLI::write('Verificação concluída com sucesso!', 'green');
        } catch (\Exception $e) {
            CLI::error('Erro ao processar limpeza: ' . $e->getMessage());
        }
    }
}
