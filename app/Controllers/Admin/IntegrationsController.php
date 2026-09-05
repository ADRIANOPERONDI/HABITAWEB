<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Entities\AccountIntegration;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Simob\SimobVocabulary;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationMappingModel;
use App\Models\IntegrationSyncRunModel;
use App\Services\IntegrationService;

/**
 * Painel de integrações do tenant.
 *
 * Toda ação resolve a integração por (conta do usuário logado, código do
 * conector). O account_id NUNCA vem do request: as rotas só recebem o código do
 * conector, e a conta sai de auth()->user(). É o que impede um tenant de ler ou
 * mexer na credencial do outro trocando um id na URL.
 */
class IntegrationsController extends BaseController
{
    private IntegrationService $service;

    /** Intervalo mínimo entre dois "Sincronizar agora" do mesmo tenant. */
    private const MANUAL_SYNC_COOLDOWN = 300;

    /**
     * A partir de quantos segundos rodando uma execução RUNNING é oferecida
     * pra abortar manualmente. Mesmo TTL de IntegrationSyncService::LOCK_TTL —
     * antes disso, ela pode só estar lenta, não travada.
     */
    private const STALE_RUN_SECONDS = 900;

    public function __construct()
    {
        $this->service = new IntegrationService();
    }

    // ------------------------------------------------------------------ telas

    public function index()
    {
        return view('Admin/integracoes/index', [
            'overview' => $this->service->overviewFor($this->accountId()),
        ]);
    }

    public function configure(string $code)
    {
        $provider = $this->service->findProvider($code);

        if ($provider === null || ! $provider->is_active) {
            return redirect()->to(site_url('admin/integracoes'))
                ->with('error', 'Conector não encontrado.');
        }

        $integration = $this->service->findOrCreate($this->accountId(), $code);
        $runModel    = model(IntegrationSyncRunModel::class);

        return view('Admin/integracoes/configure', [
            'provider'       => $provider,
            'integration'    => $integration,
            'credentials'    => $this->service->maskedCredentials($integration),
            'settings'       => $integration->settings(),
            'unconfirmed'    => $this->service->countUnconfirmed($integration),
            'synced'         => $this->service->countSyncedProperties($this->accountId(), $code),
            // Imóvel importado entra como rascunho (initial_status): sem ver
            // quantos estão parados nessa fila, o tenant não descobre que
            // precisa publicá-los — e nem que existe um botão pra isso.
            'drafts'         => model(\App\Models\PropertyExternalRefModel::class)
                ->countDraftsFor($this->accountId(), $code),
            'lastRun'        => $runModel->lastFor((int) $integration->id),
            // Simob traz até `max_images` fotos por imóvel; se isso passar do
            // teto do plano, o excedente é descartado silenciosamente na
            // hora do sync (ver SyncResult::photoLimitHits) — o tenant
            // precisa saber disso ANTES de estranhar por que faltam fotos.
            'planPhotoLimit' => $this->planPhotoLimit(),
        ]);
    }

    public function mappings(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return redirect()->to(site_url('admin/integracoes'))->with('error', 'Integração não encontrada.');
        }

        return view('Admin/integracoes/mappings', [
            'integration'     => $integration,
            'provider'        => $this->service->findProvider($code),
            'categories'      => $this->service->mappingsOf($integration, IntegrationMappingModel::KIND_CATEGORY),
            'characteristics' => $this->service->mappingsOf($integration, IntegrationMappingModel::KIND_CHARACTERISTIC),
            'propertyTypes'   => SimobVocabulary::propertyTypes(),
            'targetFields'    => SimobVocabulary::targetFields(),
        ]);
    }

    public function runs(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return redirect()->to(site_url('admin/integracoes'))->with('error', 'Integração não encontrada.');
        }

        return view('Admin/integracoes/runs', [
            'integration'     => $integration,
            'provider'        => $this->service->findProvider($code),
            'runs'            => model(IntegrationSyncRunModel::class)->recentFor((int) $integration->id, 30),
            'staleRunSeconds' => self::STALE_RUN_SECONDS,
        ]);
    }

    // ------------------------------------------------------------------ ações

    /** POST: grava credenciais e preferências (formulário clássico). */
    public function save(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return redirect()->to(site_url('admin/integracoes'))->with('error', 'Integração não encontrada.');
        }

        $post = $this->request->getPost();

        try {
            $saved = $this->service->saveCredentials($integration, $post['config'] ?? []);
            $this->service->saveSettings($integration, $post['settings'] ?? []);
        } catch (IntegrationException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        // Só os NOMES dos campos, nunca os valores — mesmo critério do
        // PaymentGatewayController.
        audit_log('integration.updated', [
            'provider' => $code,
            'fields'   => $saved,
        ]);

        return redirect()->to(site_url("admin/integracoes/{$code}"))
            ->with('message', 'Configurações salvas. Teste a conexão para ativar.');
    }

    /** POST (AJAX): testa a conexão de verdade e devolve o resultado. */
    public function test(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Integração não encontrada.']);
        }

        $result = $this->service->testConnection($integration);

        audit_log('integration.tested', ['provider' => $code, 'success' => $result->success]);

        // 200 mesmo em falha: o teste EXECUTOU, e a falha é o resultado dele.
        // Um 5xx aqui faria o jQuery cair no handler de erro genérico e
        // esconder a mensagem que explica o problema ao tenant.
        return $this->response->setJSON($result->toArray());
    }

    /**
     * POST (AJAX): agenda "sincronizar agora" — não roda mais dentro do
     * request web.
     *
     * Rodar a sincronização de verdade aqui dentro (chamando
     * IntegrationSyncService::run() direto) travava o request web até o
     * catálogo inteiro (imagens incluídas) terminar de baixar — contra um
     * catálogo do tamanho normal de uma imobiliária isso estoura o
     * max_execution_time do PHP com um Fatal Error, que não é capturável, e
     * as travas de cache do serviço ficam presas até expirar sozinhas (até
     * 30 min), sem nenhuma rota pra destravar. Quem processa de fato agora é
     * o cron `integration:sync` (a cada 1 min), que trata esta integração
     * antes das outras via `sync_priority_requested_at`
     * (AccountIntegrationModel::dueForSync()) — sem o limite de tempo do PHP
     * em CLI.
     */
    public function syncNow(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Integração não encontrada.']);
        }

        if (! $integration->isConnected()) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Teste a conexão antes de sincronizar.',
            ]);
        }

        // AccountIntegrationModel::dueForSync() (quem o cron usa pra achar
        // o que processar) exige is_active=true — sem essa checagem aqui,
        // o clique "funcionava" (marcava sync_priority_requested_at, a tela
        // mostrava "Agendado, aguardando o cron...") mas o cron IGNORAVA
        // pra sempre uma integração pausada, sem nenhum aviso do motivo. O
        // tenant ficava vendo "agendado" indefinidamente por uma conta que
        // nunca ia sincronizar.
        if (! $integration->is_active) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Ative a sincronização automática antes de sincronizar agora.',
            ]);
        }

        // Trava só contra clique repetido — o trabalho pesado não roda mais
        // aqui, mas nada impede o tenant de martelar o botão.
        $cacheKey = "integration_manual_sync_{$integration->id}";

        if (cache($cacheKey) !== null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Uma sincronização já foi agendada há pouco. Aguarde alguns minutos.',
            ]);
        }

        cache()->save($cacheKey, time(), self::MANUAL_SYNC_COOLDOWN);

        $this->service->markPriority($integration);

        audit_log('integration.sync_requested', ['provider' => $code]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Sincronização agendada. Deve rodar em até 1 minuto — atualize esta página daqui a pouco para ver o resultado.',
        ]);
    }

    /**
     * GET (AJAX, polling): o painel só sabe que "agendou" — este endpoint é
     * quem diz se já rodou. Sem ele, depois de clicar em "Sincronizar agora"
     * o tenant só descobre o resultado atualizando a página na mão de vez em
     * quando.
     */
    public function status(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Integração não encontrada.']);
        }

        $lastRun = model(IntegrationSyncRunModel::class)->lastFor((int) $integration->id);
        $running = $lastRun !== null && $lastRun->status === IntegrationSyncRunModel::STATUS_RUNNING;

        return $this->response->setJSON([
            'success'            => true,
            'status'             => $integration->status,
            'is_active'          => $integration->is_active,
            'priority_pending'   => $integration->sync_priority_requested_at !== null,
            'running'            => $running,
            'running_seconds'    => $running ? max(0, time() - strtotime((string) $lastRun->started_at)) : null,
            'last_run'           => $lastRun === null ? null : [
                'id'            => (int) $lastRun->id,
                'status'        => $lastRun->status,
                'trigger_type'  => $lastRun->trigger_type,
                'finished_at'   => $lastRun->finished_at ? (string) $lastRun->finished_at : null,
                'duration'      => $lastRun->durationSeconds(),
                'created_count' => (int) $lastRun->created_count,
                'updated_count' => (int) $lastRun->updated_count,
                'skipped_count' => (int) $lastRun->skipped_count,
                'ignored_count' => (int) $lastRun->ignored_count,
                'paused_count'  => (int) $lastRun->paused_count,
                'images_count'  => (int) $lastRun->images_count,
                'error_count'   => (int) $lastRun->error_count,
                'error_message' => $lastRun->error_message,
            ],
            'cooldown_remaining' => $this->cooldownRemaining($integration),
        ]);
    }

    /**
     * POST: aborta manualmente uma execução presa em RUNNING além do tempo
     * razoável. `closeStaleRunning()` (início de `run()`) já cobre o caso
     * comum — a próxima rodada do cron fecha sozinha —, mas entre uma
     * execução travar e o próximo cron passar por ali pode levar minutos, e
     * o tenant não tem por que esperar sem poder fazer nada.
     */
    public function abortRun(string $code, int $runId)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Integração não encontrada.']);
        }

        $runModel = model(IntegrationSyncRunModel::class);
        $run      = $runModel->find($runId);

        if ($run === null || (int) $run->account_integration_id !== (int) $integration->id) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Execução não encontrada.']);
        }

        if ($run->status !== IntegrationSyncRunModel::STATUS_RUNNING) {
            return $this->response->setJSON(['success' => false, 'message' => 'Esta execução já terminou.']);
        }

        if (time() - strtotime((string) $run->started_at) < self::STALE_RUN_SECONDS) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Esta execução ainda está dentro do tempo esperado. Aguarde mais um pouco antes de abortar.',
            ]);
        }

        $runModel->finish(
            (int) $run->id,
            IntegrationSyncRunModel::STATUS_ERROR,
            [],
            'Abortada manualmente pelo tenant — trava liberada.'
        );
        model(AccountIntegrationModel::class)->releaseLock((int) $integration->id);

        audit_log('integration.sync_aborted', ['provider' => $code, 'run_id' => $runId]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Execução abortada. Você já pode sincronizar novamente.',
        ]);
    }

    /** POST (AJAX): liga/desliga o sync automático. */
    public function toggle(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Integração não encontrada.']);
        }

        $result = $this->service->toggleActive($integration, ! $integration->is_active);

        audit_log('integration.toggled', ['provider' => $code, 'active' => ! $integration->is_active]);

        return $this->response->setJSON($result->toArray());
    }

    /**
     * POST: grava o de/para revisado.
     *
     * `confirm_all_suggestions=1` é o atalho "Confirmar todas as sugestões":
     * em vez de gravar cada `<select>` do formulário (que exigiria o tenant
     * ter revisado a tela inteira antes de submeter), confirma só o que já
     * tinha destino pelo palpite automático — o resto (inclusive
     * "— Não importar —") continua pendente pra revisão manual depois.
     */
    public function saveMappings(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return redirect()->to(site_url('admin/integracoes'))->with('error', 'Integração não encontrada.');
        }

        $confirmAllSuggested = $this->request->getPost('confirm_all_suggestions') === '1';
        $total               = 0;

        foreach ([IntegrationMappingModel::KIND_CATEGORY, IntegrationMappingModel::KIND_CHARACTERISTIC] as $kind) {
            $total += $confirmAllSuggested
                ? $this->service->confirmAllSuggested($integration, $kind)
                : $this->service->saveMappings($integration, $kind, $this->request->getPost($kind) ?? []);
        }

        audit_log('integration.mappings_saved', ['provider' => $code, 'count' => $total, 'confirm_all' => $confirmAllSuggested]);

        return redirect()->to(site_url("admin/integracoes/{$code}/mapeamentos"))
            ->with('message', "{$total} mapeamento(s) confirmado(s).");
    }

    /** POST (AJAX): redescobre categorias e características na origem. */
    public function rediscover(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Integração não encontrada.']);
        }

        try {
            $resumo = $this->service->seedMappings($integration);
        } catch (IntegrationException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }

        // "0 novas" na origem sozinho lê como falha — testConnection() já
        // semeia sozinho a cada teste bem-sucedido, então redescobrir logo
        // depois legitimamente não acha nada novo. Mostrar quantas foram
        // ENCONTRADAS (não só as novas) deixa claro que a busca funcionou.
        $formata = static fn (array $r, string $rotulo): string => sprintf(
            '%d %s encontrada(s) (%d nova(s), %d atualizada(s))',
            $r['found'],
            $rotulo,
            $r['new'],
            $r['updated']
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => $formata($resumo['category'], 'categoria(s)') . '; '
                . $formata($resumo['characteristic'], 'característica(s)'),
        ]);
    }

    /** POST: apaga as credenciais. Os imóveis já importados permanecem. */
    public function disconnect(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return redirect()->to(site_url('admin/integracoes'))->with('error', 'Integração não encontrada.');
        }

        $this->service->disconnect($integration);

        audit_log('integration.disconnected', ['provider' => $code]);

        return redirect()->to(site_url('admin/integracoes'))
            ->with('message', 'Integração desconectada. Os imóveis já importados continuam no seu catálogo.');
    }

    // ---------------------------------------------------------------- interno

    /**
     * A conta do usuário logado — nunca do request.
     *
     * Superadmin sem conta vinculada cai na 1, mesmo critério do
     * ApiKeysController.
     */
    private function accountId(): int
    {
        $user = auth()->user();

        return (int) ($user->account_id ?? ($user->inGroup('superadmin') ? 1 : 0));
    }

    private function resolve(string $code): ?AccountIntegration
    {
        return $this->service->find($this->accountId(), $code);
    }

    /** Segundos restantes do cooldown de "Sincronizar agora" — 0 se livre. */
    private function cooldownRemaining(AccountIntegration $integration): int
    {
        $iniciadoEm = cache("integration_manual_sync_{$integration->id}");

        if ($iniciadoEm === null) {
            return 0;
        }

        return max(0, self::MANUAL_SYNC_COOLDOWN - (time() - (int) $iniciadoEm));
    }

    /** Teto de fotos por imóvel do plano ativo da conta — null = sem limite. */
    private function planPhotoLimit(): ?int
    {
        $subscription = model(\App\Models\SubscriptionModel::class)
            ->where('account_id', $this->accountId())
            ->whereIn('status', ['ACTIVE', 'TRIAL'])
            ->orderBy('id', 'DESC')
            ->first();

        if ($subscription === null) {
            return null;
        }

        $plan = model(\App\Models\PlanModel::class)->find($subscription->plan_id);

        return ($plan === null || empty($plan->limite_fotos_por_imovel)) ? null : (int) $plan->limite_fotos_por_imovel;
    }
}
