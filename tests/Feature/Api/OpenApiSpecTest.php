<?php

namespace Tests\Feature\Api;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * O antídoto contra a documentação envelhecer.
 *
 * A versão anterior do public/openapi.json era mantida à mão e estava com 5
 * rotas faltando, documentava o import como JSON quando ele só aceitava CSV, e
 * anunciava um rate limit (5.000/h) que não batia com o código (1.000/h) —
 * nada disso quebrava teste nenhum.
 *
 * Aqui o spec é confrontado com as rotas REAIS registradas no framework, nos
 * dois sentidos. Quem adicionar uma rota de API sem documentar quebra o CI.
 *
 * @internal
 */
final class OpenApiSpecTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const SPEC = FCPATH . 'openapi.json';

    /** Prefixo do servidor declarado no spec (os paths são relativos a ele). */
    private const BASE = 'api/v1';

    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileExists(self::SPEC, 'public/openapi.json não existe.');

        $decoded = json_decode((string) file_get_contents(self::SPEC), true);

        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'public/openapi.json não é JSON válido: ' . json_last_error_msg()
        );

        $this->spec = $decoded;
    }

    /**
     * Rotas reais de api/v1 no formato "METHOD /caminho/{id}".
     *
     * @return list<string>
     */
    private function realRoutes(): array
    {
        $collection = Services::routes();

        // Num teste isolado o RouteCollection ainda não passou por
        // CodeIgniter::run(), que é quem normalmente carrega app/Config/Routes.php.
        $collection->loadRoutes();

        $found = [];

        // getRoutes() devolve um mapa plano [uri => handler] de UM verbo por vez,
        // e a chave do verbo é MAIÚSCULA (RouteCollection::get() grava em
        // Method::GET === 'GET'). Passar 'get' devolve array vazio em silêncio.
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (array_keys($collection->getRoutes($method, false)) as $uri) {
                if (! str_starts_with($uri, self::BASE . '/')) {
                    continue;
                }

                $found[] = strtolower($method) . ' ' . $this->toOpenApiPath(substr($uri, strlen(self::BASE)));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Converte os placeholders compilados do CI4 para o estilo OpenAPI.
     * Dois segmentos numéricos no mesmo caminho são {id} e {media_id}.
     */
    private function toOpenApiPath(string $path): string
    {
        $index = 0;

        return preg_replace_callback(
            '/\(\[0-9\]\+\)|\(:num\)|\(:any\)|\(\.\*\)|\(:segment\)/',
            static function () use (&$index) {
                $index++;

                return $index === 1 ? '{id}' : '{media_id}';
            },
            $path
        );
    }

    /**
     * Operações declaradas no spec, no mesmo formato.
     *
     * @return list<string>
     */
    private function specOperations(): array
    {
        $found = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $_) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $found[] = $method . ' ' . $path;
                }
            }
        }

        return $found;
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        $missing = array_diff($this->realRoutes(), $this->specOperations());

        $this->assertSame(
            [],
            array_values($missing),
            "Rotas de API sem documentação em public/openapi.json:\n  - " . implode("\n  - ", $missing)
                . "\n\nRegenere o spec ao adicionar rotas."
        );
    }

    public function testSpecDoesNotDocumentRoutesThatDoNotExist(): void
    {
        $phantom = array_diff($this->specOperations(), $this->realRoutes());

        $this->assertSame(
            [],
            array_values($phantom),
            "O spec documenta endpoints inexistentes (o parceiro vai receber 404):\n  - "
                . implode("\n  - ", $phantom)
        );
    }

    public function testSpecDeclaresBothSecuritySchemes(): void
    {
        $schemes = $this->spec['components']['securitySchemes'] ?? [];

        $this->assertArrayHasKey('ApiKeyAuth', $schemes);
        $this->assertArrayHasKey('BearerJWT', $schemes);
        $this->assertSame('bearer', $schemes['ApiKeyAuth']['scheme']);
        $this->assertSame('bearer', $schemes['BearerJWT']['scheme']);
    }

    public function testPublicEndpointsAreMarkedAsNotRequiringAuth(): void
    {
        // Estes três não passam pelo filtro api_auth; se o spec exigir
        // autenticação neles, o parceiro é levado a crer que precisa de token.
        foreach ([['/auth/token', 'post'], ['/auth/refresh', 'post'], ['/leads', 'post']] as [$path, $method]) {
            $this->assertSame(
                [],
                $this->spec['paths'][$path][$method]['security'] ?? null,
                "{$method} {$path} é público e deve declarar security: []"
            );
        }
    }

    public function testProtectedEndpointsInheritGlobalSecurity(): void
    {
        // A ausência de 'security' na operação faz valer o global (ApiKey/JWT).
        $this->assertArrayNotHasKey('security', $this->spec['paths']['/properties']['get']);
        $this->assertNotEmpty($this->spec['security']);
    }

    public function testEveryOperationDocumentsAuthAndRateLimitFailures(): void
    {
        $gaps = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                // Endpoints públicos não retornam 401.
                $isPublic = ($operation['security'] ?? null) === [];
                $required = $isPublic ? ['429'] : ['401', '429'];

                foreach ($required as $code) {
                    if (! isset($operation['responses'][$code])) {
                        $gaps[] = "{$method} {$path} não documenta {$code}";
                    }
                }
            }
        }

        $this->assertSame([], $gaps, implode("\n", $gaps));
    }

    public function testEveryOperationHasSummaryAndTag(): void
    {
        $gaps = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                if (empty($operation['summary'])) {
                    $gaps[] = "{$method} {$path} sem summary";
                }

                if (empty($operation['tags'])) {
                    $gaps[] = "{$method} {$path} sem tag";
                }
            }
        }

        $this->assertSame([], $gaps, implode("\n", $gaps));
    }

    public function testAllSchemaReferencesResolve(): void
    {
        $defined = array_keys($this->spec['components']['schemas']);
        $broken  = [];

        array_walk_recursive($this->spec, static function ($value, $key) use ($defined, &$broken) {
            if ($key === '$ref' && str_starts_with($value, '#/components/schemas/')) {
                $name = substr($value, strlen('#/components/schemas/'));

                if (! in_array($name, $defined, true)) {
                    $broken[] = $value;
                }
            }
        });

        $this->assertSame([], array_values(array_unique($broken)), 'Referências de schema quebradas.');
    }

    public function testDocumentedRateLimitMatchesTheCode(): void
    {
        // api_docs.md anunciava 5.000/h quando o default real da API Key é 1.000.
        $this->assertStringContainsString(
            '1.000 requisições/hora',
            $this->spec['info']['description'],
            'O rate limit documentado precisa refletir ApiKeyModel::generateKey() (1000/h).'
        );
    }

    public function testDocsEndpointServesTheSpec(): void
    {
        $result = $this->get('api/docs/json');

        $result->assertStatus(200);
        $this->assertSame(
            $this->spec['info']['version'],
            json_decode($result->getJSON(), true)['info']['version']
        );
    }
}
