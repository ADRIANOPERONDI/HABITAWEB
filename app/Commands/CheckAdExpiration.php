<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Config\Factories;

class CheckAdExpiration extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:check-expiration';
    protected $description = 'Verifica e atualiza status de anúncios antigos (Anti-Velharia).';

    public function run(array $params)
    {
        $propertyModel = Factories::models(\App\Models\PropertyModel::class);
        $db = \Config\Database::connect();

        CLI::write('Iniciando verificação de anúncios vencidos...', 'yellow');

        // 1. Marcar como REVIEW (30 dias sem update)
        // Regra: Status ACTIVE e updated_at < 30 dias atrás
        $dateLimitReview = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $toReview = $propertyModel->where('status', 'ACTIVE')
                                  ->where('updated_at <', $dateLimitReview)
                                  ->findAll();

        $countReview = 0;
        foreach ($toReview as $prop) {
            $propertyModel->update($prop->id, ['status' => 'REVIEW']);
            // Opcional: Aqui dispararia email "Atualize seu anúncio!"
            CLI::write("Imóvel #{$prop->id} marcado como REVIEW.", 'green');
            $countReview++;
        }

        // 2. Pausar (7 dias em REVIEW sem update)
        // Se entrou em REVIEW e não foi mexido, updated_at não mudou (ou mudou só na troca de status).
        // A lógica é: se está em REVIEW e updated_at (data da mudança para review ou ultima mexida) < 7 dias atras
        $dateLimitPause = date('Y-m-d H:i:s', strtotime('-7 days'));
        
        $toPause = $propertyModel->where('status', 'REVIEW')
                                 ->where('updated_at <', $dateLimitPause)
                                 ->findAll();

        $countPause = 0;
        foreach ($toPause as $prop) {
            $propertyModel->update($prop->id, ['status' => 'PAUSED']);
            // Opcional: Email "Seu anúncio foi pausado por inatividade."
            CLI::write("Imóvel #{$prop->id} PAUSADO por inatividade.", 'red');
            $countPause++;
        }

        CLI::write("Processo concluído.", 'white');
        CLI::write("Marcados para Revisão: $countReview", 'yellow');
        CLI::write("Pausados: $countPause", 'red');
    }
}
