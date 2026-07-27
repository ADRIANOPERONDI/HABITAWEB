#!/usr/bin/env php
<?php

/**
 * Smoke E2E da jornada do parceiro — HTTP REAL, dados REAIS.
 *
 * Diferente das suítes de tests/Feature (que roteiam pelo framework em processo),
 * este script fala com um servidor de verdade por cURL. É o que exercita o stack
 * completo: webserver, filtros, rate limit, storage em disco, headers de
 * resposta e Content-Type — coisas que o FeatureTestTrait não cobre.
 *
 * Uso:
 *   php spark serve &
 *   php tests/E2E/partner_smoke.php --base-url=http://localhost:8080
 *
 * Opções:
 *   --base-url=URL   Servidor alvo (padrão: http://localhost:8080)
 *   --keep           Não remove os dados criados ao final
 *
 * Sai com código 0 se tudo passou, 1 se algo falhou.
 */

// ---------------------------------------------------------------- bootstrap

$options = [
    'base-url' => 'http://localhost:8080',
    'keep'     => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--keep') {
        $options['keep'] = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}

$baseUrl = rtrim($options['base-url'], '/');

// Sobe o CodeIgniter só para semear o tenant e limpar no final. As chamadas de
// API em si são todas por HTTP contra $baseUrl.
// A sequência abaixo espelha o arquivo ./spark da raiz do projeto.
define('SMOKE_ROOT', dirname(__DIR__, 1));
define('PROJECT_ROOT', dirname(SMOKE_ROOT));

require_once PROJECT_ROOT . '/vendor/autoload.php';

// O Boot do CI4 lê a constante ENVIRONMENT, que vem de CI_ENVIRONMENT.
if (! defined('FCPATH')) {
    define('FCPATH', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
}

chdir(FCPATH);

require_once PROJECT_ROOT . '/app/Config/Paths.php';

$paths = new Config\Paths();
require_once $paths->systemDirectory . '/Boot.php';

// Boot::bootConsole() NÃO chama defineEnvironment() (só bootSpark() chama), mas
// loadEnvironmentBootstrap() logo em seguida usa a constante ENVIRONMENT —
// sem defini-la aqui, o boot morre com "Undefined constant ENVIRONMENT".
if (! defined('ENVIRONMENT')) {
    define('ENVIRONMENT', $_SERVER['CI_ENVIRONMENT'] ?? $_ENV['CI_ENVIRONMENT'] ?? getenv('CI_ENVIRONMENT') ?: 'development');
}

CodeIgniter\Boot::bootConsole($paths);

// ------------------------------------------------------------------ helpers

final class Smoke
{
    public int $passed = 0;
    public int $failed = 0;
    private array $failures = [];
    private float $startedAt;

    public function __construct(private string $baseUrl)
    {
        $this->startedAt = microtime(true);
    }

    public function section(string $title): void
    {
        echo "\n\033[1;36m▸ {$title}\033[0m\n";
    }

    public function check(string $label, bool $condition, string $detail = ''): bool
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✓\033[0m {$label}\n";

            return true;
        }

        $this->failed++;
        $this->failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
        echo "  \033[31m✗\033[0m {$label}";
        echo $detail !== '' ? "\n      \033[33m{$detail}\033[0m\n" : "\n";

        return false;
    }

    public function info(string $text): void
    {
        echo "    \033[90m{$text}\033[0m\n";
    }

    /**
     * Requisição HTTP real.
     *
     * @return array{status:int, body:array|null, raw:string, headers:array}
     */
    public function request(string $method, string $path, array $opts = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = ['Accept: application/json'];

        if (! empty($opts['token'])) {
            $headers[] = 'Authorization: Bearer ' . $opts['token'];
        }

        $curl = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HEADER         => true,
        ];

        if (isset($opts['json'])) {
            $headers[]                  = 'Content-Type: application/json';
            $curl[CURLOPT_POSTFIELDS]   = json_encode($opts['json'], JSON_UNESCAPED_UNICODE);
        } elseif (isset($opts['multipart'])) {
            $curl[CURLOPT_POSTFIELDS] = $opts['multipart'];
        }

        $curl[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $curl);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return ['status' => 0, 'body' => null, 'raw' => 'cURL: ' . $error, 'headers' => []];
        }

        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $raw        = substr($response, $headerSize);

        $parsed = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v]              = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }

        $decoded = json_decode($raw, true);

        return [
            'status'  => $status,
            'body'    => is_array($decoded) ? $decoded : null,
            'raw'     => $raw,
            'headers' => $parsed,
        ];
    }

    public function summary(): int
    {
        $elapsed = round(microtime(true) - $this->startedAt, 2);
        $total   = $this->passed + $this->failed;

        echo "\n" . str_repeat('─', 62) . "\n";

        if ($this->failed === 0) {
            echo "\033[1;32m  TUDO PASSOU\033[0m  {$this->passed}/{$total} verificações em {$elapsed}s\n";
            echo str_repeat('─', 62) . "\n";

            return 0;
        }

        echo "\033[1;31m  FALHOU\033[0m  {$this->failed} de {$total} verificações em {$elapsed}s\n\n";
        foreach ($this->failures as $failure) {
            echo "  \033[31m•\033[0m {$failure}\n";
        }
        echo str_repeat('─', 62) . "\n";

        return 1;
    }
}

$smoke = new Smoke($baseUrl);

echo "\n\033[1mSmoke E2E — jornada do parceiro\033[0m\n";
echo "Alvo: {$baseUrl}\n";

// ------------------------------------------------- 0. servidor no ar?

$smoke->section('0. Servidor');

$ping = $smoke->request('GET', '/api/docs/json');

if (! $smoke->check('Servidor respondendo em ' . $baseUrl, $ping['status'] === 200, $ping['raw'] ?: 'sem resposta')) {
    echo "\n\033[33mSuba o servidor antes:  php spark serve\033[0m\n";
    exit(1);
}

$smoke->check('openapi.json é servido e é JSON válido', isset($ping['body']['openapi']));
$smoke->info('OpenAPI ' . ($ping['body']['openapi'] ?? '?') . ' — ' . count($ping['body']['paths'] ?? []) . ' paths');

$docs = $smoke->request('GET', '/api/docs');
$smoke->check('Página /api/docs carrega', $docs['status'] === 200);
$smoke->check(
    'Swagger UI é servido localmente (sem CDN)',
    str_contains($docs['raw'], 'assets/swagger/swagger-ui-bundle.js') && ! str_contains($docs['raw'], 'unpkg.com/swagger-ui'),
);

$asset = $smoke->request('GET', '/assets/swagger/swagger-ui-bundle.js');
$smoke->check('Assets do Swagger UI acessíveis', $asset['status'] === 200);

// ------------------------------------------------- 1. semear o parceiro

$smoke->section('1. Preparando a conta do parceiro');

$stamp   = date('His') . bin2hex(random_bytes(3));
$db      = \Config\Database::connect();
$created = ['account' => null, 'user' => null, 'properties' => []];

try {
    $accountModel = new \App\Models\AccountModel();
    $accountModel->insert([
        'tipo_conta'          => 'IMOBILIARIA',
        'nome'                => "Parceiro Smoke {$stamp}",
        'documento'           => substr('11' . preg_replace('/\D/', '', $stamp) . '000000000', 0, 14),
        'email'               => "smoke{$stamp}@habitaweb.local",
        'telefone'            => '51999990000',
        'status'              => 'ACTIVE',
        'is_verified'         => true,
        'verification_status' => 'APPROVED',
    ]);
    $accountId          = (int) $accountModel->getInsertID();
    $created['account'] = $accountId;

    $plan = (new \App\Models\PlanModel())->where('chave', 'PRATA')->first()
        ?? (new \App\Models\PlanModel())->first();

    if (! $plan) {
        throw new RuntimeException('Nenhum plano encontrado — rode: php spark db:seed PlanSeeder');
    }

    (new \App\Models\SubscriptionModel())->insert([
        'account_id'        => $accountId,
        'plan_id'           => $plan->id,
        'status'            => 'ACTIVE',
        'billing_cycle'     => 'mensal',
        'data_inicio'       => date('Y-m-d'),
        'data_fim'          => date('Y-m-d', strtotime('+1 year')),
        'proximo_pagamento' => date('Y-m-d', strtotime('+1 month')),
    ]);

    $userModel = new \CodeIgniter\Shield\Models\UserModel();
    $user      = new \CodeIgniter\Shield\Entities\User([
        'username' => "smoke{$stamp}",
        'email'    => "user{$stamp}@habitaweb.local",
        'password' => 'SmokeTeste#' . bin2hex(random_bytes(4)),
        'active'   => 1,
    ]);
    $userModel->save($user);
    $userId          = (int) $userModel->getInsertID();
    $created['user'] = $userId;

    $db->table('users')->where('id', $userId)->update(['account_id' => $accountId]);
    $userModel->find($userId)->addGroup('user');

    $keyResult = (new \App\Models\ApiKeyModel())->generateKey($accountId, "Smoke {$stamp}", $userId, 1000);

    if (! $keyResult['success']) {
        throw new RuntimeException('Falha ao gerar API key: ' . ($keyResult['message'] ?? ''));
    }

    $apiKey = $keyResult['plain_key'];

    $smoke->check('Conta, assinatura e usuário criados', true);
    $smoke->check('API Key gerada', str_starts_with($apiKey, 'pk_'));
    $smoke->info("account_id={$accountId} · plano={$plan->nome} · chave=" . substr($apiKey, 0, 12) . '…');
} catch (Throwable $e) {
    $smoke->check('Preparação da conta', false, $e->getMessage());
    exit($smoke->summary());
}

// ------------------------------------------------- 2. autenticação

$smoke->section('2. Autenticação');

$noAuth = $smoke->request('GET', '/api/v1/properties');
$smoke->check('Sem credencial → 401', $noAuth['status'] === 401, 'status=' . $noAuth['status']);
$smoke->check('401 vem no envelope JSON padrão', ($noAuth['body']['error_code'] ?? null) === 'MISSING_TOKEN');

$badKey = $smoke->request('GET', '/api/v1/properties', ['token' => 'pk_live_naoexiste000000000000000000000']);
$smoke->check('Chave inválida → 401', $badKey['status'] === 401);

$me = $smoke->request('GET', '/api/v1/auth/me', ['token' => $apiKey]);
$smoke->check('GET /auth/me com API Key → 200', $me['status'] === 200, $me['raw']);
$smoke->check('Retorna a conta correta', ($me['body']['data']['account']['id'] ?? null) === $accountId);
$smoke->check('Informa o plano', ! empty($me['body']['data']['plan']['chave']));

$smoke->check(
    'Headers de rate limit presentes',
    isset($me['headers']['x-ratelimit-limit'], $me['headers']['x-ratelimit-remaining'], $me['headers']['x-ratelimit-reset']),
);
$smoke->info('X-RateLimit-Limit: ' . ($me['headers']['x-ratelimit-limit'] ?? '?')
    . ' · Remaining: ' . ($me['headers']['x-ratelimit-remaining'] ?? '?'));

// JWT
$token = $smoke->request('POST', '/api/v1/auth/token', ['json' => ['api_key' => $apiKey]]);
$smoke->check('POST /auth/token → 200', $token['status'] === 200, $token['raw']);

$accessToken  = $token['body']['data']['access_token'] ?? '';
$refreshToken = $token['body']['data']['refresh_token'] ?? '';

$smoke->check('Access token é um JWT (3 segmentos)', substr_count($accessToken, '.') === 2);
$smoke->check('Expira em 1 hora', ($token['body']['data']['expires_in'] ?? 0) === 3600);

$jwtCall = $smoke->request('GET', '/api/v1/auth/me', ['token' => $accessToken]);
$smoke->check('JWT autentica uma chamada protegida', $jwtCall['status'] === 200, $jwtCall['raw']);

$refresh = $smoke->request('POST', '/api/v1/auth/refresh', ['json' => ['refresh_token' => $refreshToken]]);
$smoke->check('POST /auth/refresh → 200', $refresh['status'] === 200);

$reuse = $smoke->request('POST', '/api/v1/auth/refresh', ['json' => ['refresh_token' => $refreshToken]]);
$smoke->check('Reusar refresh antigo → 401 TOKEN_REVOKED', ($reuse['body']['error_code'] ?? null) === 'TOKEN_REVOKED');

// ------------------------------------------------- 3. sincronizar catálogo

$smoke->section('3. Sincronizando o catálogo (ida)');

$catalogo = [
    [
        'external_id'  => "SMOKE-{$stamp}-1",
        'titulo'       => 'Apartamento 3 dormitórios no Centro Histórico',
        'descricao'    => 'Apartamento reformado, andar alto, vista livre para o Guaíba.',
        'tipo_negocio' => 'VENDA',
        'tipo_imovel'  => 'apartamento',
        'preco'        => 749000,
        'cidade'       => 'Porto Alegre',
        'bairro'       => 'Centro Histórico',
        'estado'       => 'RS',
        'cep'          => '90020-070',
        'quartos'      => 3,
        'banheiros'    => 2,
        'suites'       => 1,
        'vagas'        => 1,
        'area_total'   => 98.5,
        'mobiliado'    => true,
        'aceita_pets'  => true,
        // Publicado: é o que um parceiro faz ao sincronizar o catálogo ativo.
        // Imóvel em DRAFT (o padrão) não aparece no portal nem aceita lead público.
        'status'       => 'ACTIVE',
    ],
    [
        // Payload com nomes em inglês, para exercer os aliases.
        'reference'     => "SMOKE-{$stamp}-2",
        'title'         => 'Casa com pátio no Bairro Ipiranga',
        'description'   => 'Casa térrea, pátio amplo com churrasqueira.',
        'operation'     => 'sale',
        'property_type' => 'casa',
        'price'         => 1200000,
        'city'          => 'Porto Alegre',
        'neighborhood'  => 'Ipiranga',
        'state'         => 'rs',
        'bedrooms'      => 4,
        'status'        => 'ACTIVE',
    ],
    [
        'external_id'  => "SMOKE-{$stamp}-3",
        'titulo'       => 'Sala comercial para locação',
        'tipo_negocio' => 'ALUGUEL',
        'tipo_imovel'  => 'sala',
        'preco'        => 3500,
        'cidade'       => 'Porto Alegre',
        'bairro'       => 'Moinhos de Vento',
        'estado'       => 'RS',
        'status'       => 'ACTIVE',
    ],
];

$dry = $smoke->request('POST', '/api/v1/import/properties', [
    'token' => $apiKey,
    'json'  => ['properties' => $catalogo, 'validate_only' => true],
]);
$smoke->check('Simulação (validate_only) → 200', $dry['status'] === 200, $dry['raw']);
$smoke->check('Simulação não grava nada', ($dry['body']['data']['results'][0]['action'] ?? '') === 'would_create');

$import = $smoke->request('POST', '/api/v1/import/properties', [
    'token' => $apiKey,
    'json'  => ['properties' => $catalogo],
]);
$smoke->check('Import real → 200', $import['status'] === 200, $import['raw']);

$summary = $import['body']['data']['summary'] ?? [];
$smoke->check('3 imóveis criados', ($summary['created'] ?? 0) === 3, json_encode($summary));
$smoke->check('Nenhum erro', ($summary['errors'] ?? 1) === 0);

$propertyIds = array_column($import['body']['data']['results'] ?? [], 'property_id');
$smoke->check('Todo item devolveu property_id', count(array_filter($propertyIds)) === 3);
$created['properties'] = array_filter($propertyIds);
$firstId               = (int) ($propertyIds[0] ?? 0);

$smoke->info('property_ids: ' . implode(', ', $propertyIds));

// O 2º item do catálogo foi enviado com nomes em inglês (title/price/city/...)
// e operation="sale" minúsculo — tudo precisa ter sido normalizado.
$aliasCheck = $smoke->request('GET', "/api/v1/properties?external_id=SMOKE-{$stamp}-2", ['token' => $apiKey]);
$aliasFound = $aliasCheck['body']['data']['properties'][0] ?? [];

$smoke->check(
    'Aliases em inglês foram mapeados e normalizados',
    ($aliasFound['tipo_negocio'] ?? '') === 'VENDA'
        && ($aliasFound['estado'] ?? '') === 'RS'
        && (int) ($aliasFound['quartos'] ?? 0) === 4
        && str_contains((string) ($aliasFound['titulo'] ?? ''), 'Casa com pátio'),
    json_encode([
        'tipo_negocio' => $aliasFound['tipo_negocio'] ?? null,
        'estado'       => $aliasFound['estado'] ?? null,
        'quartos'      => $aliasFound['quartos'] ?? null,
    ]),
);

// ------------------------------------------------- 4. reimport (upsert)

$smoke->section('4. Reimportando (o teste da via de mão dupla)');

$catalogo[0]['preco']  = 699000;
$catalogo[0]['titulo'] = 'Apartamento 3 dormitórios no Centro Histórico — REAJUSTADO';

$reimport = $smoke->request('POST', '/api/v1/import/properties', [
    'token' => $apiKey,
    'json'  => ['properties' => $catalogo],
]);

$reSummary = $reimport['body']['data']['summary'] ?? [];
$smoke->check('Reimport → 200', $reimport['status'] === 200, $reimport['raw']);
$smoke->check('3 atualizados, 0 criados', ($reSummary['updated'] ?? 0) === 3 && ($reSummary['created'] ?? -1) === 0, json_encode($reSummary));

$countAfter = $db->table('properties')->where('account_id', $accountId)->where('deleted_at', null)->countAllResults();
$smoke->check('Continua com 3 imóveis (não duplicou)', $countAfter === 3, "encontrados: {$countAfter}");

$updated = $smoke->request('GET', "/api/v1/properties/{$firstId}", ['token' => $apiKey]);
$smoke->check('Preço foi realmente atualizado', (float) ($updated['body']['data']['property']['preco'] ?? 0) === 699000.0);

// ------------------------------------------------- 5. imagens

$smoke->section('5. Cadastro de imagens');

$fixture = SMOKE_ROOT . '/_support/fixtures/images/casa-com-gps.jpg';

if (! is_file($fixture)) {
    $smoke->check('Fixture de imagem disponível', false, $fixture);
} else {
    $smoke->check('Fixture tem EXIF/GPS antes do upload',
        \App\Libraries\Media\ImageSanitizer::hasExif($fixture));

    $upload = $smoke->request('POST', "/api/v1/properties/{$firstId}/media", [
        'token'     => $apiKey,
        'multipart' => ['file' => new CURLFile($fixture, 'image/jpeg', 'casa.jpg')],
    ]);

    $smoke->check('Upload multipart → 201', $upload['status'] === 201, $upload['raw']);

    $mediaId   = $upload['body']['data']['id'] ?? null;
    $mediaPath = $upload['body']['data']['path'] ?? '';
    $mediaUrl  = $upload['body']['data']['url'] ?? '';

    $smoke->check('Primeira imagem virou capa', ($upload['body']['data']['principal'] ?? false) === true);

    $stored = dirname(SMOKE_ROOT) . '/public/' . $mediaPath;
    $smoke->check('Arquivo existe no disco', is_file($stored), $stored);

    if (is_file($stored)) {
        $smoke->check('EXIF/GPS removido da imagem publicada',
            ! \App\Libraries\Media\ImageSanitizer::hasExif($stored));
        $smoke->check('Imagem continua válida após o strip', @getimagesize($stored) !== false);

        $card    = \App\Libraries\Media\ImageVariantGenerator::variantPath($mediaPath, 'card');
        $gallery = \App\Libraries\Media\ImageVariantGenerator::variantPath($mediaPath, 'gallery');
        $smoke->check('Variante card (480px) gerada', is_file(dirname(SMOKE_ROOT) . '/public/' . $card));
        $smoke->check('Variante gallery (1280px) gerada', is_file(dirname(SMOKE_ROOT) . '/public/' . $gallery));
    }

    if ($mediaPath !== '') {
        // Busca pelo CAMINHO no servidor deste smoke. A URL absoluta devolvida
        // pela API é montada com app.baseURL do .env, que aponta para outra
        // porta quando o smoke roda contra um servidor alternativo.
        $fetch = $smoke->request('GET', '/' . ltrim($mediaPath, '/'));
        $smoke->check('Imagem servida pela URL pública', $fetch['status'] === 200, 'status=' . $fetch['status']);
        $smoke->info('URL retornada pela API: ' . $mediaUrl);
    }

    $webshell = SMOKE_ROOT . '/_support/fixtures/images/webshell.jpg';
    if (is_file($webshell)) {
        $evil = $smoke->request('POST', "/api/v1/properties/{$firstId}/media", [
            'token'     => $apiKey,
            'multipart' => ['file' => new CURLFile($webshell, 'image/jpeg', 'foto.jpg')],
        ]);
        $smoke->check('PHP disfarçado de .jpg é recusado', $evil['status'] === 400, 'status=' . $evil['status']);
    }

    $list = $smoke->request('GET', "/api/v1/properties/{$firstId}/media", ['token' => $apiKey]);
    $smoke->check('Listagem de imagens → 200', $list['status'] === 200);
    $smoke->check('Uma imagem cadastrada', count($list['body']['data']['media'] ?? []) === 1);

    if ($mediaId) {
        $del = $smoke->request('DELETE', "/api/v1/properties/{$firstId}/media/{$mediaId}", ['token' => $apiKey]);
        $smoke->check('DELETE da imagem → 200', $del['status'] === 200);

        $stillThere = $smoke->request('GET', "/api/v1/properties/{$firstId}", ['token' => $apiKey]);
        $smoke->check('DELETE de mídia NÃO apagou o imóvel', $stillThere['status'] === 200,
            'O imóvel sumiu — regressão do shadowing de rota.');
    }
}

// ------------------------------------------------- 6. exportar (volta)

$smoke->section('6. Exportando (a volta da mão dupla)');

$export = $smoke->request('GET', '/api/v1/export/properties?format=json&per_page=100', ['token' => $apiKey]);
$smoke->check('Export JSON → 200', $export['status'] === 200, $export['raw']);

$exported = $export['body']['data']['properties'] ?? [];
$smoke->check('3 imóveis exportados', count($exported) === 3, 'total=' . count($exported));
$smoke->check('Export traz external_id', ! empty($exported[0]['external_id'] ?? null));
$smoke->check('Export traz as imagens', array_key_exists('images', $exported[0] ?? []));

$roundTrip = $smoke->request('POST', '/api/v1/import/properties', [
    'token' => $apiKey,
    'json'  => ['properties' => $exported],
]);
$rtSummary = $roundTrip['body']['data']['summary'] ?? [];
$smoke->check('Round-trip (exportar → reimportar) atualiza, não duplica',
    ($rtSummary['updated'] ?? 0) === 3 && ($rtSummary['created'] ?? -1) === 0, json_encode($rtSummary));

$countFinal = $db->table('properties')->where('account_id', $accountId)->where('deleted_at', null)->countAllResults();
$smoke->check('Ainda 3 imóveis depois do round-trip', $countFinal === 3, "encontrados: {$countFinal}");

$csv = $smoke->request('GET', '/api/v1/export/properties?format=csv', ['token' => $apiKey]);
$smoke->check('Export CSV → 200', $csv['status'] === 200);
$smoke->check('CSV contém a coluna external_id', str_contains($csv['raw'], 'external_id'));

// ------------------------------------------------- 7. isolamento

$smoke->section('7. Isolamento entre contas');

$otherStamp = date('His') . bin2hex(random_bytes(3));
$other      = new \App\Models\AccountModel();
$other->insert([
    'tipo_conta'          => 'IMOBILIARIA',
    'nome'                => "Concorrente {$otherStamp}",
    'documento'           => substr('22' . preg_replace('/\D/', '', $otherStamp) . '000000000', 0, 14),
    'email'               => "outro{$otherStamp}@habitaweb.local",
    'status'              => 'ACTIVE',
    'is_verified'         => true,
    'verification_status' => 'APPROVED',
]);
$otherAccountId = (int) $other->getInsertID();

$otherUserModel = new \CodeIgniter\Shield\Models\UserModel();
$otherUser      = new \CodeIgniter\Shield\Entities\User([
    'username' => "outro{$otherStamp}",
    'email'    => "u{$otherStamp}@habitaweb.local",
    'password' => 'Outro#' . bin2hex(random_bytes(4)),
    'active'   => 1,
]);
$otherUserModel->save($otherUser);
$otherUserId = (int) $otherUserModel->getInsertID();
$db->table('users')->where('id', $otherUserId)->update(['account_id' => $otherAccountId]);

$otherKey = (new \App\Models\ApiKeyModel())->generateKey($otherAccountId, 'Concorrente', $otherUserId, 1000)['plain_key'];

$leak = $smoke->request('GET', '/api/v1/properties', ['token' => $otherKey]);
$smoke->check('Listagem do concorrente → 200', $leak['status'] === 200);
$smoke->check(
    'Concorrente NÃO vê os imóveis do parceiro',
    ! str_contains($leak['raw'], "SMOKE-{$stamp}-1"),
    'VAZAMENTO CROSS-TENANT na listagem de imóveis.',
);

$leakAll = $smoke->request('GET', '/api/v1/properties?status=ALL&account_id=' . $accountId, ['token' => $otherKey]);
$smoke->check(
    'status=ALL + account_id forjado não amplia o escopo',
    ! str_contains($leakAll['raw'], "SMOKE-{$stamp}-1"),
    'VAZAMENTO: querystring conseguiu ampliar o escopo.',
);

$idor = $smoke->request('GET', "/api/v1/properties/{$firstId}", ['token' => $otherKey]);
$smoke->check('Acesso direto ao imóvel alheio → 403', $idor['status'] === 403, 'status=' . $idor['status']);

$idorWrite = $smoke->request('PUT', "/api/v1/properties/{$firstId}", [
    'token' => $otherKey,
    'json'  => ['titulo' => 'INVADIDO'],
]);
$smoke->check('Alteração do imóvel alheio → 403', $idorWrite['status'] === 403);

$accountsLeak = $smoke->request('GET', '/api/v1/accounts', ['token' => $otherKey]);
$smoke->check(
    'Listagem de contas NÃO vaza outras contas',
    ! str_contains($accountsLeak['raw'], "Parceiro Smoke {$stamp}"),
    'VAZAMENTO CROSS-TENANT na listagem de contas.',
);

// ------------------------------------------------- 8. validação e erros

$smoke->section('8. Validação e tratamento de erro');

$invalid = $smoke->request('POST', '/api/v1/properties', [
    'token' => $apiKey,
    'json'  => ['tipo_imovel' => 'casa'],
]);
$smoke->check('Campos obrigatórios ausentes → 422', $invalid['status'] === 422, 'status=' . $invalid['status']);
$smoke->check('Erro aponta o campo faltante', isset($invalid['body']['details']['titulo']));

$guarded = $smoke->request('POST', '/api/v1/properties', [
    'token' => $apiKey,
    'json'  => [
        'external_id' => "SMOKE-{$stamp}-guard",
        'titulo' => 'Tentativa de turbo grátis', 'tipo_negocio' => 'VENDA',
        'tipo_imovel' => 'casa', 'preco' => 100000,
        'cidade' => 'Porto Alegre', 'bairro' => 'Centro',
        'is_destaque' => true, 'highlight_level' => 3, 'is_verified' => true,
    ],
]);
$smoke->check('Imóvel criado → 201', $guarded['status'] === 201, $guarded['raw']);

$guardedId = (int) ($guarded['body']['data']['property_id'] ?? 0);
if ($guardedId) {
    $created['properties'][] = $guardedId;
    $row                     = $db->table('properties')->where('id', $guardedId)->get()->getRowArray();
    $smoke->check('is_destaque NÃO foi concedido pelo payload', in_array($row['is_destaque'], ['f', false, 0, '0'], true));
    $smoke->check('is_verified NÃO foi concedido pelo payload', in_array($row['is_verified'], ['f', false, 0, '0'], true));
    $smoke->check('highlight_level permaneceu 0', (int) $row['highlight_level'] === 0);
}

$badJson = $smoke->request('POST', '/api/v1/properties', ['token' => $apiKey, 'json' => 'x']);
$smoke->check('Corpo JSON inválido → 4xx (não 500)', $badJson['status'] >= 400 && $badJson['status'] < 500,
    'status=' . $badJson['status']);

$notFound = $smoke->request('GET', '/api/v1/rota-inexistente', ['token' => $apiKey]);
$smoke->check('Endpoint inexistente → 404', $notFound['status'] === 404);
$smoke->check('404 é JSON, não HTML', ($notFound['body']['error_code'] ?? null) === 'NOT_FOUND',
    substr($notFound['raw'], 0, 80));
$smoke->check('404 tem Content-Type JSON',
    str_contains($notFound['headers']['content-type'] ?? '', 'application/json'));

$nonNumeric = $smoke->request('GET', '/api/v1/properties/abc', ['token' => $apiKey]);
$smoke->check('ID não numérico → 404 (não 500)', $nonNumeric['status'] === 404, 'status=' . $nonNumeric['status']);

// ------------------------------------------------- 9. leads e webhooks

$smoke->section('9. Leads e webhooks');

$lead = $smoke->request('POST', '/api/v1/leads', [
    'json' => [
        'property_id'      => $firstId,
        'nome_visitante'   => 'Maria Compradora',
        'email_visitante'  => "maria{$stamp}@example.com",
        'telefone_visitante' => '51988887777',
        'mensagem'         => 'Gostaria de agendar uma visita.',
    ],
]);
$smoke->check('Lead público (sem auth) → 201', $lead['status'] === 201, $lead['raw']);

$leads = $smoke->request('GET', '/api/v1/leads', ['token' => $apiKey]);
$smoke->check('Parceiro vê o lead recebido', str_contains($leads['raw'], "maria{$stamp}@example.com"));

$otherLeads = $smoke->request('GET', '/api/v1/leads', ['token' => $otherKey]);
$smoke->check('Concorrente NÃO vê o lead', ! str_contains($otherLeads['raw'], "maria{$stamp}@example.com"));

$hook = $smoke->request('POST', '/api/v1/webhooks', [
    'token' => $apiKey,
    'json'  => ['name' => 'Smoke leads', 'event' => 'lead.created', 'target_url' => 'https://example.com/hook'],
]);
$smoke->check('Webhook criado → 201', $hook['status'] === 201, $hook['raw']);
$smoke->check('Secret gerado automaticamente', ! empty($hook['body']['data']['webhook']['secret'] ?? null));

$ssrf = $smoke->request('POST', '/api/v1/webhooks', [
    'token' => $apiKey,
    'json'  => ['name' => 'SSRF', 'event' => 'lead.created', 'target_url' => 'http://169.254.169.254/latest/meta-data/'],
]);
$smoke->check('Webhook para endereço interno é recusado (SSRF)', $ssrf['status'] === 422, 'status=' . $ssrf['status']);

$hookId = $hook['body']['data']['webhook']['id'] ?? null;
if ($hookId) {
    $noop = $smoke->request('PUT', "/api/v1/webhooks/{$hookId}", ['token' => $apiKey, 'json' => []]);
    $smoke->check('PUT de webhook com corpo vazio → 422 (não 500)', $noop['status'] === 422, 'status=' . $noop['status']);
}

// ------------------------------------------------- limpeza

$smoke->section('10. Limpeza');

if ($options['keep']) {
    $smoke->info('--keep informado: dados preservados.');
    $smoke->info("account_id={$accountId} · api_key={$apiKey}");
} else {
    try {
        foreach ([$accountId, $otherAccountId] as $acc) {
            $ids = array_column($db->table('properties')->select('id')->where('account_id', $acc)->get()->getResultArray(), 'id');

            if ($ids !== []) {
                $db->table('property_media')->whereIn('property_id', $ids)->delete();
                $db->table('property_features')->whereIn('property_id', $ids)->delete();
                $db->table('leads')->whereIn('property_id', $ids)->delete();
            }

            $db->table('properties')->where('account_id', $acc)->delete();
            $db->table('integration_webhooks')->where('account_id', $acc)->delete();
            $db->table('api_refresh_tokens')->where('account_id', $acc)->delete();
            $db->table('api_keys')->where('account_id', $acc)->delete();
            $db->table('subscriptions')->where('account_id', $acc)->delete();
            $db->table('users')->where('account_id', $acc)->delete();
            $db->table('accounts')->where('id', $acc)->delete();
        }

        // Arquivos de imagem gerados no disco público.
        foreach ($created['properties'] as $pid) {
            $dir = dirname(SMOKE_ROOT) . '/public/uploads/properties/' . $pid;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }

        $smoke->check('Dados de teste removidos', true);
    } catch (Throwable $e) {
        $smoke->check('Limpeza', false, $e->getMessage());
    }
}

exit($smoke->summary());
