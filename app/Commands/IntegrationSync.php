<?php

namespace App\Commands;

use App\Libraries\Geo\NominatimGeocoder;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationSyncRunModel;
use App\Services\IntegrationSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sincroniza os catálogos das integrações ativas.
 *
 * Mesma estrutura do AsaasSync: varre os tenants elegíveis, mais antigos
 * primeiro, com try/catch por tenant para que uma imobiliária com problema não
 * impeça as outras de sincronizar.
 *
 * Cron sugerido (ver GUIA_ESCALABILIDADE_PRODUCAO.md — rodar em UMA instância):
 *   *###/30 * * * * cd /var/www/habitaweb && php spark integration:sync >> writable/logs/integration-sync.log 2>&1
 */
class IntegrationSync extends BaseCommand
{
    protected $group       = 'Integracoes';
    protected $name        = 'integration:sync';
    protected $description = 'Importa os catálogos das integrações ativas (Simob e afins).';
    protected $usage       = 'integration:sync [options]';
    protected $options     = [
        '--provider' => 'Só este conector (ex.: simob).',
        '--account'  => 'Só esta conta (id numérico).',
        '--limit'    => 'Máximo de integrações nesta rodada (padrão: 50).',
        '--full'     => 'Ignora o corte incremental e varre o catálogo inteiro.',
    ];

    public function run(array $params)
    {
        $provider = $this->option('provider', $params);
        $account  = $this->option('account', $params);
        $limit    = (int) ($this->option('limit', $params) ?? 50);
        $full     = array_key_exists('full', $params) || in_array('--full', $params, true);

        $integrations = model(AccountIntegrationModel::class)->dueForSync(
            $provider === null ? null : (string) $provider,
            $account === null ? null : (int) $account,
            max(1, $limit)
        );

        if ($integrations === []) {
            CLI::write('Nenhuma integração ativa para sincronizar.', 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write(sprintf('%d integração(ões) na fila.', count($integrations)), 'green');

        // NominatimGeocoder explícito: o default do service é NullGeocoder
        // (seguro pra suíte de testes) — este comando é o único chamador de
        // produção, e é aqui que a geocodificação de verdade precisa ligar.
        $service  = new IntegrationSyncService(geocoder: new NominatimGeocoder());
        $comErro  = 0;

        foreach ($integrations as $integration) {
            $rotulo = sprintf('conta %d / %s', $integration->account_id, $integration->provider_code);

            try {
                CLI::write("→ {$rotulo}...");

                // "Sincronizar agora" do painel só marca a prioridade
                // (AccountIntegrationModel::dueForSync()); quem roda de fato é
                // esta mesma passada do cron, um pouco mais cedo. O trigger
                // registrado reflete o pedido original, pra manter o
                // histórico em /admin/integracoes/{code}/execucoes legível.
                $trigger = $integration->sync_priority_requested_at !== null
                    ? IntegrationSyncRunModel::TRIGGER_MANUAL
                    : IntegrationSyncRunModel::TRIGGER_CRON;

                $result = $service->run($integration, $trigger, $full);

                CLI::write('  ' . $result->humanSummary(), $result->errors > 0 ? 'yellow' : 'green');

                if ($result->planLimitReached) {
                    CLI::write('  Limite de imóveis do plano atingido.', 'yellow');
                }

                foreach (array_slice($result->errorMessages, 0, 3) as $msg) {
                    CLI::write('  ! ' . $msg, 'yellow');
                }

                if ($result->errors > 0) {
                    $comErro++;
                }
            } catch (\Throwable $e) {
                // Uma integração quebrada não pode impedir as outras.
                $comErro++;
                CLI::error("  {$rotulo}: " . $e->getMessage());
                log_message('error', "[integration:sync] {$rotulo}: " . $e->getMessage());
            }
        }

        CLI::write('Concluído.', $comErro > 0 ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }

    /** Lê --opcao=valor tanto na forma de array associativo quanto posicional. */
    private function option(string $name, array $params): ?string
    {
        if (array_key_exists($name, $params) && $params[$name] !== null && $params[$name] !== true) {
            return (string) $params[$name];
        }

        $fromCli = CLI::getOption($name);

        return is_string($fromCli) && $fromCli !== '' ? $fromCli : null;
    }
}
