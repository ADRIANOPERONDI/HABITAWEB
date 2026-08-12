<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Entities\AccountIntegration;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Simob\SimobVocabulary;
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
            'provider'    => $provider,
            'integration' => $integration,
            'credentials' => $this->service->maskedCredentials($integration),
            'settings'    => $integration->settings(),
            'unconfirmed' => $this->service->countUnconfirmed($integration),
            'synced'      => $this->service->countSyncedProperties($this->accountId(), $code),
            'lastRun'     => $runModel->lastFor((int) $integration->id),
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
            'integration' => $integration,
            'provider'    => $this->service->findProvider($code),
            'runs'        => model(IntegrationSyncRunModel::class)->recentFor((int) $integration->id, 30),
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

    /** POST: grava o de/para revisado. */
    public function saveMappings(string $code)
    {
        $integration = $this->resolve($code);

        if ($integration === null) {
            return redirect()->to(site_url('admin/integracoes'))->with('error', 'Integração não encontrada.');
        }

        $total = 0;

        foreach ([IntegrationMappingModel::KIND_CATEGORY, IntegrationMappingModel::KIND_CHARACTERISTIC] as $kind) {
            $total += $this->service->saveMappings($integration, $kind, $this->request->getPost($kind) ?? []);
        }

        audit_log('integration.mappings_saved', ['provider' => $code, 'count' => $total]);

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
            $created = $this->service->seedMappings($integration);
        } catch (IntegrationException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => sprintf(
                '%d categoria(s) e %d característica(s) nova(s) encontrada(s).',
                $created['category'],
                $created['characteristic']
            ),
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
}
