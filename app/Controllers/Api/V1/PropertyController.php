<?php

namespace App\Controllers\Api\V1;

use App\Services\PropertyService;

class PropertyController extends BaseController
{
    /** Filtros aceitos em GET /properties. Qualquer outra chave é ignorada. */
    private const ALLOWED_FILTERS = [
        'status', 'tipo_negocio', 'tipo_imovel', 'cidade', 'bairro', 'estado',
        'quartos', 'banheiros', 'vagas', 'suites', 'min_price', 'max_price',
        'user_id_responsavel', 'client_id', 'external_id', 'search', 'term',
        'order', 'sort', 'page', 'per_page',
    ];

    /** Tipos de denúncia aceitos. */
    private const REPORT_TYPES = ['WRONG_INFO', 'DUPLICATE', 'SOLD', 'FRAUD', 'INAPPROPRIATE', 'OTHER'];

    protected PropertyService $propertyService;

    public function __construct()
    {
        $this->propertyService = service('propertyService');
    }

    /**
     * GET /api/v1/properties
     */
    public function index()
    {
        $accountId = $this->currentAccountId();

        if (! $accountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        // Allowlist dos filtros. Antes o getGet() cru era repassado ao service,
        // que aceitava qualquer chave — inclusive account_id e show_deleted.
        $filters = array_intersect_key($this->request->getGet(), array_flip(self::ALLOWED_FILTERS));

        // ESCOPO DE TENANT — o ponto crítico.
        // listProperties() só filtra por conta se o CHAMADOR mandar account_id.
        // Como o controller repassava o querystring cru e nunca setava essa
        // chave, GET /api/v1/properties devolvia os imóveis ATIVOS DE TODAS AS
        // CONTAS da plataforma para qualquer credencial válida — e ?status=ALL
        // ampliava para rascunhos e pausados. Aqui o account_id é imposto pelo
        // servidor; só superadmin pode consultar outra conta, e explicitamente.
        if ($this->isSuperAdmin()) {
            $requested = $this->request->getGet('account_id');
            if ($requested !== null && $requested !== '') {
                $filters['account_id'] = (int) $requested;
            }
        } else {
            $filters['account_id'] = $accountId;
        }

        return $this->respondSuccess($this->propertyService->listProperties($filters));
    }

    /**
     * POST /api/v1/properties
     */
    public function create()
    {
        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        $currentAccountId = $this->currentAccountId();

        if (! $currentAccountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        // Força account_id do usuário logado se não for super admin
        if (! $this->isSuperAdmin() || ! isset($data['account_id'])) {
            $data['account_id'] = $currentAccountId;
        }

        $images = $this->extractImages($data);

        $validation = $this->propertyService->validatePropertyData($data);

        if (! $validation['valid']) {
            return $this->respondError(
                'Dados do imóvel inválidos.',
                422,
                $validation['errors'],
                self::ERR_VALIDATION
            );
        }

        $result = $this->propertyService->trySaveProperty($data);

        if (! $result['success']) {
            return $this->respondError(
                $result['message'],
                $this->statusForSaveFailure($result),
                $result['errors'] ?? [],
                isset($result['errors']['limit']) ? self::ERR_PLAN_LIMIT : self::ERR_VALIDATION
            );
        }

        $payload = [
            'property_id' => $result['property_id'],
            'property'    => $result['data'],
        ];

        if ($images !== []) {
            $payload['images'] = $this->ingestImages((int) $result['property_id'], $images);
        }

        return $this->respondSuccess($payload, 'Imóvel criado com sucesso.', 201);
    }

    /**
     * PUT|PATCH /api/v1/properties/(:id)
     */
    public function update($id = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property; // já é uma resposta de erro
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Nunca permitir reatribuir o imóvel a outra conta via corpo da requisição.
        unset($data['account_id']);

        $images = $this->extractImages($data);

        $validation = $this->propertyService->validatePropertyData($data, true);

        if (! $validation['valid']) {
            return $this->respondError('Dados do imóvel inválidos.', 422, $validation['errors'], self::ERR_VALIDATION);
        }

        // partialUpdate = true: campos booleanos ausentes ficam como estão em
        // vez de serem zerados (semântica correta de PATCH).
        $result = $this->propertyService->trySaveProperty($data, (int) $id, false, true);

        if (! $result['success']) {
            return $this->respondError(
                $result['message'],
                $this->statusForSaveFailure($result),
                $result['errors'] ?? [],
                isset($result['errors']['limit']) ? self::ERR_PLAN_LIMIT : self::ERR_VALIDATION
            );
        }

        $payload = [
            'property_id' => $result['property_id'],
            'property'    => $result['data'],
        ];

        if ($images !== []) {
            $payload['images'] = $this->ingestImages((int) $id, $images);
        }

        return $this->respondSuccess($payload, 'Imóvel atualizado com sucesso.');
    }

    /**
     * GET /api/v1/properties/(:id)
     */
    public function show($id = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        $details = $this->propertyService->getPropertyDetails((int) $id);

        if (! $details || ! isset($details['property'])) {
            return $this->respondNotFound('Imóvel não encontrado.');
        }

        return $this->respondSuccess($details);
    }

    /**
     * DELETE /api/v1/properties/(:id)
     */
    public function delete($id = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        if ($this->propertyService->deleteProperty((int) $id)) {
            return $this->respondSuccess(['property_id' => (int) $id], 'Imóvel desativado com sucesso.');
        }

        return $this->respondError('Erro ao desativar imóvel.', 500, [], self::ERR_INTERNAL);
    }

    /**
     * POST /api/v1/properties/(:id)/report
     */
    public function report($id = null)
    {
        if (! $id) {
            return $this->respondError('ID do imóvel é obrigatório.', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        // A rota vive atrás do api_auth, então o imóvel precisa existir de fato —
        // antes nada verificava, e denúncias podiam ser gravadas para ids inexistentes.
        $property = model('App\\Models\\PropertyModel')->find($id);

        if (! $property) {
            return $this->respondNotFound('Imóvel não encontrado.');
        }

        $input = $this->getJsonBody();

        if ($input === null) {
            return $this->respondInvalidJson();
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        $type   = strtoupper((string) ($input['type'] ?? 'WRONG_INFO'));

        if ($reason === '') {
            return $this->respondError('O motivo da denúncia é obrigatório.', 422, [], self::ERR_VALIDATION);
        }

        if (! in_array($type, self::REPORT_TYPES, true)) {
            return $this->respondError(
                'type deve ser um de: ' . implode(', ', self::REPORT_TYPES) . '.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        $reportModel = \CodeIgniter\Config\Factories::models(\App\Models\PropertyReportModel::class);

        $data = [
            'property_id' => (int) $id,
            // auth()->id() é da SESSÃO e é sempre null sob Bearer — toda denúncia
            // pela API era gravada como anônima. auth_user_id vem do filtro.
            'user_id'     => $this->request->auth_user_id ?? null,
            'ip_address'  => $this->request->getIPAddress(),
            'reason'      => $reason,
            'type'        => $type,
            'status'      => 'PENDING',
        ];

        if ($reportModel->insert($data)) {
            return $this->respondSuccess(null, 'Denúncia recebida com sucesso.', 201);
        }

        return $this->respondError('Erro ao salvar denúncia.', 500, [], self::ERR_INTERNAL);
    }

    /**
     * POST /admin/properties/calculate-score
     * (montada fora do grupo api/v1, no painel admin)
     */
    public function calculateScore()
    {
        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        $property = new \App\Entities\Property($data);

        $mediaCount = $data['media_count'] ?? 0;

        if (! empty($data['id']) && $mediaCount == 0) {
            $mediaCount = model('App\Models\PropertyMediaModel')->countByProperty((int) $data['id']);
        }

        $result = (new \App\Services\CurationService())->calculateDetailedScore($property, $mediaCount);

        return $this->respondSuccess($result);
    }

    /**
     * GET /api/v1/properties/(:id)/media
     */
    public function listMedia($id = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        $media = model('App\Models\PropertyMediaModel')
            ->where('property_id', (int) $id)
            ->orderBy('principal', 'DESC')
            ->orderBy('ordem', 'ASC')
            ->findAll();

        return $this->respondSuccess([
            'property_id' => (int) $id,
            'media'       => array_map(static fn ($m) => [
                'id'         => (int) $m->id,
                'url'        => media_url($m->url),
                'path'       => $m->url,
                'ordem'      => (int) $m->ordem,
                'principal'  => (bool) $m->principal,
                'source_url' => $m->source_url,
            ], $media),
        ]);
    }

    /**
     * POST /api/v1/properties/(:id)/media
     *
     * Aceita multipart (campo "file") OU JSON {"url": "https://..."}.
     */
    public function uploadMedia($id = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        // Via URL
        if ($this->isJsonRequest()) {
            $body = $this->getJsonBody();

            if ($body === null) {
                return $this->respondInvalidJson();
            }

            $url = trim((string) ($body['url'] ?? ''));

            if ($url === '') {
                return $this->respondError(
                    'Informe "url" no corpo JSON ou envie o arquivo em "file" (multipart/form-data).',
                    400,
                    [],
                    self::ERR_INVALID_PAYLOAD
                );
            }

            $result = $this->propertyService->addMediaFromUrl($url, (int) $id, [
                'ordem'     => isset($body['ordem']) ? (int) $body['ordem'] : null,
                'principal' => isset($body['principal']) ? (bool) $body['principal'] : null,
            ]);

            return $this->respondMediaResult($result);
        }

        // Via upload multipart
        $file = $this->request->getFile('file');

        if (! $file) {
            return $this->respondError('Arquivo não enviado no campo "file".', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        return $this->respondMediaResult($this->propertyService->addMedia((int) $id, $file));
    }

    /**
     * POST /api/v1/properties/(:id)/media/batch
     * Ingestão de várias imagens por URL numa única chamada.
     */
    public function uploadMediaBatch($id = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        $body = $this->getJsonBody();

        if ($body === null) {
            return $this->respondInvalidJson();
        }

        $images = $body['images'] ?? $body['urls'] ?? null;

        if (! is_array($images) || $images === []) {
            return $this->respondError(
                'Envie as imagens em "images": [{"url": "..."}] ou "urls": ["..."].',
                400,
                [],
                self::ERR_INVALID_PAYLOAD
            );
        }

        $max = \App\Services\PropertyImportService::MAX_IMAGES_PER_ITEM;

        if (count($images) > $max) {
            return $this->respondError(
                "Máximo de {$max} imagens por requisição.",
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        $summary = $this->ingestImages((int) $id, $images);

        return $this->respondSuccess(
            $summary,
            'Ingestão concluída.',
            $summary['errors'] === [] ? 201 : 207
        );
    }

    /**
     * DELETE /api/v1/properties/(:id)/media/(:mediaId)
     */
    public function deleteMedia($id = null, $mediaId = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        if (! $mediaId) {
            return $this->respondError('ID da mídia é obrigatório.', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        $result = $this->propertyService->deleteMedia((int) $id, (int) $mediaId);

        return $result['success']
            ? $this->respondSuccess($result, 'Mídia removida com sucesso.')
            : $this->respondError($result['message'], 404, [], self::ERR_NOT_FOUND);
    }

    /**
     * POST /api/v1/properties/(:id)/media/(:mediaId)/main
     */
    public function setMainMedia($id = null, $mediaId = null)
    {
        $property = $this->findOwnedProperty($id);

        if (! $property instanceof \App\Entities\Property) {
            return $property;
        }

        if (! $mediaId) {
            return $this->respondError('ID da mídia é obrigatório.', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        $result = $this->propertyService->setMainMedia((int) $id, (int) $mediaId);

        return $result['success']
            ? $this->respondSuccess($result, 'Capa definida com sucesso.')
            : $this->respondError($result['message'], 404, [], self::ERR_NOT_FOUND);
    }

    /**
     * Carrega o imóvel garantindo que ele pertence à conta autenticada.
     *
     * @return \App\Entities\Property|\CodeIgniter\HTTP\ResponseInterface
     */
    private function findOwnedProperty($id)
    {
        if (! $id) {
            return $this->respondError('ID do imóvel é obrigatório.', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        $accountId = $this->currentAccountId();

        if (! $accountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        $property = model('App\\Models\\PropertyModel')->find((int) $id);

        if (! $property) {
            return $this->respondNotFound('Imóvel não encontrado.');
        }

        if ($property->account_id != $accountId && ! $this->isSuperAdmin()) {
            log_message('warning', "IDOR attempt: account {$accountId} tentou acessar o imóvel {$id}");

            return $this->respondForbidden('Acesso negado a este imóvel.');
        }

        return $property;
    }

    /**
     * Extrai e remove a chave "images" do payload do imóvel.
     */
    private function extractImages(array &$data): array
    {
        $images = $data['images'] ?? $data['fotos'] ?? $data['photos'] ?? [];
        unset($data['images'], $data['fotos'], $data['photos']);

        return is_array($images) ? $images : [];
    }

    /**
     * Ingere uma lista de imagens (URLs) e devolve o resultado item a item.
     */
    private function ingestImages(int $propertyId, array $images): array
    {
        $imported = [];
        $skipped  = 0;
        $errors   = [];

        foreach (array_values($images) as $position => $image) {
            $url    = null;
            $ordem  = $position + 1;
            $isMain = false;

            if (is_string($image)) {
                $url = $image;
            } elseif (is_array($image)) {
                $url    = $image['url'] ?? $image['src'] ?? null;
                $ordem  = isset($image['ordem']) ? (int) $image['ordem'] : $ordem;
                $isMain = (bool) ($image['principal'] ?? $image['is_main'] ?? false);
            }

            if (! is_string($url) || trim($url) === '') {
                $errors[] = ['position' => $position, 'error' => 'Imagem sem URL válida.'];
                continue;
            }

            $result = $this->propertyService->addMediaFromUrl(trim($url), $propertyId, [
                'ordem'     => $ordem,
                'principal' => $isMain,
            ]);

            if (! $result['success']) {
                $errors[] = ['position' => $position, 'url' => $url, 'error' => $result['message']];
                continue;
            }

            if ($result['skipped'] ?? false) {
                $skipped++;
                continue;
            }

            $imported[] = $result['media'];
        }

        return [
            'requested' => count($images),
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    private function respondMediaResult(array $result)
    {
        if ($result['success']) {
            return $this->respondSuccess($result['media'], 'Mídia adicionada com sucesso.', 201);
        }

        $isLimit = ($result['code'] ?? null) === 'PHOTO_LIMIT_REACHED';

        return $this->respondError(
            $result['message'],
            $isLimit ? 409 : 400,
            [],
            $isLimit ? self::ERR_PHOTO_LIMIT : self::ERR_VALIDATION
        );
    }

    /**
     * 409 quando o bloqueio é de plano (o cliente precisa agir na assinatura),
     * 422 quando é dado inválido.
     */
    private function statusForSaveFailure(array $result): int
    {
        return isset($result['errors']['limit']) ? 409 : 422;
    }
}
