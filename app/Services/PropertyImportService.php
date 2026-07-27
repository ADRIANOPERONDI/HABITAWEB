<?php

namespace App\Services;

use App\Models\PropertyModel;
use CodeIgniter\Config\Factories;

/**
 * Sincronização do catálogo do parceiro para o Habitaweb ("via de mão dupla").
 *
 * O ponto central é o external_id: o identificador que o imóvel tem NA
 * PLATAFORMA DO PARCEIRO. Com ele o import vira UPSERT — reenviar o mesmo
 * catálogo atualiza os imóveis existentes em vez de criar cópias. Sem ele
 * (comportamento anterior) toda sincronização duplicava a base inteira.
 *
 * Aceita dois formatos, ambos caindo no mesmo pipeline:
 *   - JSON em lote (recomendado): suporta imagens por URL no próprio item.
 *   - CSV (compatibilidade): mesma semântica, external_id como coluna opcional.
 */
class PropertyImportService
{
    /** Teto de itens por requisição no modo JSON. */
    public const MAX_JSON_ITEMS = 200;

    /** Teto de linhas por requisição no modo CSV. */
    public const MAX_CSV_ROWS = 1000;

    /** Teto de imagens ingeridas por imóvel numa única chamada de import. */
    public const MAX_IMAGES_PER_ITEM = 20;

    /**
     * Nomes alternativos aceitos para cada coluna. Evita que o parceiro tenha
     * de reescrever o payload dele só para falar português com a gente.
     */
    private const FIELD_ALIASES = [
        'title'            => 'titulo',
        'name'             => 'titulo',
        'description'      => 'descricao',
        'price'            => 'preco',
        'amount'           => 'preco',
        'city'             => 'cidade',
        'neighborhood'     => 'bairro',
        'district'         => 'bairro',
        'state'            => 'estado',
        'zipcode'          => 'cep',
        'zip_code'         => 'cep',
        'postal_code'      => 'cep',
        'street'           => 'rua',
        'address'          => 'rua',
        'number'           => 'numero',
        'complement'       => 'complemento',
        'bedrooms'         => 'quartos',
        'bathrooms'        => 'banheiros',
        'suites'           => 'suites',
        'parking'          => 'vagas',
        'parking_spaces'   => 'vagas',
        'garage'           => 'vagas',
        'total_area'       => 'area_total',
        'built_area'       => 'area_construida',
        'private_area'     => 'area_privativa',
        'lat'              => 'latitude',
        'lng'              => 'longitude',
        'lon'              => 'longitude',
        'longitud'         => 'longitude',
        'operation'        => 'tipo_negocio',
        'transaction_type' => 'tipo_negocio',
        'property_type'    => 'tipo_imovel',
        'type'             => 'tipo_imovel',
        'condo_fee'        => 'valor_condominio',
        'condominium_fee'  => 'valor_condominio',
        'tax'              => 'iptu',
        'reference'        => 'external_id',
        'reference_code'   => 'external_id',
        'external_code'    => 'external_id',
        'codigo'           => 'external_id',
        'id_externo'       => 'external_id',
        'photos'           => 'images',
        'pictures'         => 'images',
        'imagens'          => 'images',
        'fotos'            => 'images',
    ];

    /** Valores de tipo_negocio aceitos, com normalização de sinônimos comuns. */
    private const NEGOCIO_ALIASES = [
        'sale'     => 'VENDA',
        'sell'     => 'VENDA',
        'venda'    => 'VENDA',
        'rent'     => 'ALUGUEL',
        'rental'   => 'ALUGUEL',
        'aluguel'  => 'ALUGUEL',
        'locacao'  => 'ALUGUEL',
        'locação'  => 'ALUGUEL',
        'season'   => 'TEMPORADA',
        'seasonal' => 'TEMPORADA',
    ];

    protected PropertyService $propertyService;
    protected PropertyModel $propertyModel;

    public function __construct(?PropertyService $propertyService = null)
    {
        $this->propertyService = $propertyService ?? service('propertyService');
        $this->propertyModel   = Factories::models(PropertyModel::class);
    }

    /**
     * Importa um lote de imóveis em JSON.
     *
     * @param array $items         Lista de imóveis no formato do parceiro.
     * @param bool  $validateOnly  Dry-run: valida tudo e não grava nada.
     *
     * @return array{summary: array, results: array}
     */
    public function importJson(int $accountId, array $items, bool $validateOnly = false): array
    {
        $results = [];
        $index   = 0;

        foreach ($items as $rawItem) {
            $index++;

            if (! is_array($rawItem)) {
                $results[] = $this->errorResult($index, null, ['payload' => 'Cada item deve ser um objeto JSON.']);
                continue;
            }

            $results[] = $this->processItem($accountId, $rawItem, $index, $validateOnly, 'api');
        }

        return ['summary' => $this->summarize($results), 'results' => $results];
    }

    /**
     * Importa imóveis a partir de um CSV.
     *
     * @param string $filePath Caminho do arquivo já validado pelo controller.
     */
    public function importCsv(int $accountId, string $filePath, bool $validateOnly = false): array
    {
        $handle = @fopen($filePath, 'r');

        if ($handle === false) {
            return [
                'summary' => $this->summarize([]),
                'results' => [],
                'fatal'   => 'Não foi possível abrir o arquivo enviado.',
            ];
        }

        try {
            $header = fgetcsv($handle);

            // Arquivo vazio: fgetcsv devolve false. Antes isso chegava tipado em
            // validateCSVHeader(array $header) e virava TypeError → 500.
            if (! is_array($header)) {
                return [
                    'summary' => $this->summarize([]),
                    'results' => [],
                    'fatal'   => 'O arquivo CSV está vazio.',
                ];
            }

            // Normaliza o cabeçalho UMA vez e usa a versão normalizada no
            // array_combine. Antes a normalização era feita só numa cópia usada
            // para validar; o array_combine seguia com o header original, então
            // um CSV com "Titulo,Descricao" passava na validação e depois
            // inseria linhas praticamente vazias, reportadas como sucesso.
            $header = $this->normalizeHeader($header);

            $missing = array_diff(['titulo', 'tipo_negocio', 'tipo_imovel', 'preco', 'cidade', 'bairro'], $header);

            if ($missing !== []) {
                return [
                    'summary' => $this->summarize([]),
                    'results' => [],
                    'fatal'   => 'Cabeçalho do CSV inválido. Faltam as colunas: ' . implode(', ', $missing),
                ];
            }

            $results = [];
            $line    = 1;

            while (($row = fgetcsv($handle)) !== false && count($results) < self::MAX_CSV_ROWS) {
                $line++;

                // Linha totalmente vazia (comum no fim de arquivos do Excel).
                if ($row === [null] || $row === ['']) {
                    continue;
                }

                // Linha desalinhada: antes array_combine lançava ValueError, que
                // é \Error e escapava do catch (\Exception) — derrubando o request
                // inteiro DEPOIS de já ter gravado as linhas anteriores.
                if (count($row) !== count($header)) {
                    $results[] = $this->errorResult($line, null, [
                        'csv' => sprintf(
                            'A linha tem %d coluna(s), mas o cabeçalho tem %d.',
                            count($row),
                            count($header)
                        ),
                    ]);
                    continue;
                }

                $results[] = $this->processItem($accountId, array_combine($header, $row), $line, $validateOnly, 'csv');
            }

            return ['summary' => $this->summarize($results), 'results' => $results];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Processa um item: normaliza, valida e faz o upsert.
     */
    private function processItem(int $accountId, array $rawItem, int $index, bool $validateOnly, string $source): array
    {
        try {
            $data       = $this->normalizeItem($rawItem);
            $externalId = isset($data['external_id']) && trim((string) $data['external_id']) !== ''
                ? trim((string) $data['external_id'])
                : null;

            $images = $data['images'] ?? [];
            unset($data['images']);

            // Existe? Então é atualização — esta é a chave da mão dupla.
            $existing = $externalId ? $this->findByExternalId($accountId, $externalId) : null;

            $validation = $this->propertyService->validatePropertyData($data, $existing !== null);

            if (! $validation['valid']) {
                return $this->errorResult($index, $externalId, $validation['errors']);
            }

            if ($validateOnly) {
                return [
                    'index'       => $index,
                    'external_id' => $externalId,
                    'property_id' => $existing?->id !== null ? (int) $existing->id : null,
                    'action'      => $existing ? 'would_update' : 'would_create',
                    'errors'      => [],
                ];
            }

            $data['account_id']         = $accountId;
            $data['source']             = $source;
            $data['external_synced_at'] = date('Y-m-d H:i:s');

            if ($externalId !== null) {
                $data['external_id'] = $externalId;
            }

            $result = $this->propertyService->trySaveProperty(
                $data,
                $existing ? (int) $existing->id : null,
                false,
                $existing !== null // atualização parcial: não zera booleanos ausentes
            );

            if (! $result['success']) {
                return $this->errorResult(
                    $index,
                    $externalId,
                    $result['errors'] ?: ['save' => $result['message']]
                );
            }

            $propertyId = (int) $result['property_id'];

            $imageResult = $this->ingestImages($propertyId, $images);

            return [
                'index'       => $index,
                'external_id' => $externalId,
                'property_id' => $propertyId,
                'action'      => $existing ? 'updated' : 'created',
                'images'      => $imageResult,
                'errors'      => [],
            ];
        } catch (\Throwable $e) {
            // \Throwable e não \Exception: ValueError/TypeError/Error são \Error e
            // escapariam de um catch (\Exception), derrubando o lote inteiro.
            log_message('error', '[PropertyImportService] item ' . $index . ': ' . $e->getMessage());

            return $this->errorResult($index, $rawItem['external_id'] ?? null, [
                'exception' => 'Erro ao processar o item.',
            ]);
        }
    }

    /**
     * Ingere as imagens de um item. Falha de imagem NÃO invalida o imóvel —
     * o parceiro recebe o imóvel criado e o detalhe de quais fotos falharam.
     */
    private function ingestImages(int $propertyId, $images): array
    {
        if (! is_array($images) || $images === []) {
            return ['requested' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => []];
        }

        $images = array_slice($images, 0, self::MAX_IMAGES_PER_ITEM);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($images as $position => $image) {
            $url        = null;
            $isMain     = false;
            $ordem      = $position + 1;

            if (is_string($image)) {
                $url = $image;
            } elseif (is_array($image)) {
                $url    = $image['url'] ?? $image['src'] ?? $image['link'] ?? null;
                $isMain = (bool) ($image['principal'] ?? $image['is_main'] ?? $image['main'] ?? false);
                $ordem  = (int) ($image['ordem'] ?? $image['order'] ?? $ordem);
            }

            if (! is_string($url) || trim($url) === '') {
                $errors[] = ['position' => $position, 'error' => 'Imagem sem URL válida.'];
                continue;
            }

            $result = $this->propertyService->addMediaFromUrl(trim($url), $propertyId, [
                'ordem'     => $ordem,
                'principal' => $isMain,
            ]);

            if ($result['success']) {
                if ($result['skipped'] ?? false) {
                    $skipped++; // já existia (dedupe por URL de origem)
                } else {
                    $imported++;
                }
            } else {
                $errors[] = ['position' => $position, 'url' => $url, 'error' => $result['message']];
            }
        }

        return [
            'requested' => count($images),
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    /**
     * Localiza um imóvel da conta pelo external_id do parceiro.
     */
    public function findByExternalId(int $accountId, string $externalId)
    {
        return $this->propertyModel
            ->where('account_id', $accountId)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * Normaliza chaves e valores de um item vindo do parceiro.
     */
    private function normalizeItem(array $item): array
    {
        $normalized = [];

        foreach ($item as $key => $value) {
            $key = strtolower(trim((string) $key));
            $key = self::FIELD_ALIASES[$key] ?? $key;

            $normalized[$key] = is_string($value) ? trim($value) : $value;
        }

        if (isset($normalized['tipo_negocio']) && is_string($normalized['tipo_negocio'])) {
            $raw = strtolower($normalized['tipo_negocio']);
            $normalized['tipo_negocio'] = self::NEGOCIO_ALIASES[$raw] ?? strtoupper($normalized['tipo_negocio']);
        }

        if (isset($normalized['status']) && is_string($normalized['status'])) {
            $normalized['status'] = strtoupper($normalized['status']);
        }

        if (isset($normalized['estado']) && is_string($normalized['estado'])) {
            $normalized['estado'] = strtoupper($normalized['estado']);
        }

        // Uma imagem só, enviada como string, também é aceita.
        if (isset($normalized['images']) && is_string($normalized['images'])) {
            $normalized['images'] = array_filter(array_map('trim', explode('|', $normalized['images'])));
        }

        // Nunca aceitar account_id do payload — o tenant vem sempre da credencial.
        unset($normalized['account_id'], $normalized['id']);

        return $normalized;
    }

    /**
     * Cabeçalho do CSV normalizado: minúsculo, sem espaços e com aliases
     * resolvidos, além de remover o BOM que o Excel escreve na primeira célula.
     *
     * @return array<int, string>
     */
    private function normalizeHeader(array $header): array
    {
        return array_map(static function ($column) {
            $column = trim(str_replace("\xEF\xBB\xBF", '', (string) $column));
            $column = strtolower($column);

            return self::FIELD_ALIASES[$column] ?? $column;
        }, $header);
    }

    private function errorResult(int $index, ?string $externalId, array $errors): array
    {
        return [
            'index'       => $index,
            'external_id' => $externalId,
            'property_id' => null,
            'action'      => 'error',
            'errors'      => $errors,
        ];
    }

    private function summarize(array $results): array
    {
        $summary = [
            'total'   => count($results),
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
        ];

        foreach ($results as $result) {
            switch ($result['action']) {
                case 'created':
                case 'would_create':
                    $summary['created']++;
                    break;
                case 'updated':
                case 'would_update':
                    $summary['updated']++;
                    break;
                default:
                    $summary['errors']++;
            }
        }

        return $summary;
    }
}
