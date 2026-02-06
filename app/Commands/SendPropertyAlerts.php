<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PropertyAlertModel;
use App\Models\PropertyModel;
use App\Services\NotificationService;

class SendPropertyAlerts extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'alerts:send';
    protected $description = 'Envia alertas de novos imóveis para os usuários inscritos.';

    public function run(array $params)
    {
        $alertModel    = new PropertyAlertModel();
        $propertyModel = new PropertyModel();
        $notification  = new NotificationService();

        CLI::write('Iniciando processamento de alertas...', 'yellow');

        $alerts = $alertModel->where('status', 'ATIVO')->findAll();

        if (empty($alerts)) {
            CLI::write('Nenhum alerta ativo encontrado.', 'cyan');
            return;
        }

        foreach ($alerts as $alert) {
            if (!$this->shouldSend($alert)) {
                continue;
            }

            CLI::write("Processando alerta para: {$alert['email']}", 'white');

            $lastSent = $alert['last_sent_at'] ?? $alert['created_at'];
            
            // Busca novos imóveis compatíveis
            $newProperties = $this->getNewProperties($alert['filtros'], $lastSent);

            if (!empty($newProperties)) {
                CLI::write("  -> " . count($newProperties) . " novos imóveis encontrados.", 'green');
                
                if ($notification->sendPropertyAlertEmail($alert, $newProperties)) {
                    $alertModel->update($alert['id'], [
                        'last_sent_at' => date('Y-m-d H:i:s')
                    ]);
                    CLI::write("  -> E-mail enviado com sucesso.", 'green');
                } else {
                    CLI::error("  -> Falha ao enviar e-mail.");
                }
            } else {
                CLI::write("  -> Nenhum imóvel novo compatível.", 'light_gray');
                
                // Mesmo sem imóveis, se for diário/semanal, atualizamos o last_sent_at para marcar que processamos o período
                if (in_array($alert['frequencia'], ['DIARIO', 'SEMANAL'])) {
                    $alertModel->update($alert['id'], [
                        'last_sent_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }

        CLI::write('Processamento concluído.', 'yellow');
    }

    protected function shouldSend(array $alert): bool
    {
        if (empty($alert['last_sent_at'])) {
            return true;
        }

        $lastSent = strtotime($alert['last_sent_at']);
        $now      = time();

        switch ($alert['frequencia']) {
            case 'IMEDIATO':
                // Imediato processa sempre que rodar o cron (ex: a cada 5 min)
                return true;
            case 'DIARIO':
                // 24 horas
                return ($now - $lastSent) >= 86400;
            case 'SEMANAL':
                // 7 dias
                return ($now - $lastSent) >= 604800;
        }

        return false;
    }

    protected function getNewProperties(array $filtros, string $since): array
    {
        $propertyModel = new PropertyModel();
        $builder = $propertyModel->where('status', 'ACTIVE')
                                 ->where('created_at >', $since);

        // Aplica os filtros salvos
        if (!empty($filtros['tipo_negocio'])) {
            $builder->where('tipo_negocio', $filtros['tipo_negocio']);
        }
        if (!empty($filtros['cidade'])) {
            $builder->like('cidade', $filtros['cidade'], 'both');
        }
        if (!empty($filtros['bairro'])) {
            $builder->like('bairro', $filtros['bairro'], 'both');
        }
        if (!empty($filtros['tipo_imovel'])) {
            $builder->where('tipo_imovel', $filtros['tipo_imovel']);
        }

        return $builder->findAll(10); // Limita a 10 imóveis no e-mail
    }
}
