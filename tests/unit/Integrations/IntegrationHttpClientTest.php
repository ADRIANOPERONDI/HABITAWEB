<?php

namespace Tests\Unit\Integrations;

use App\Libraries\Integrations\Exceptions\AuthException;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Exceptions\RateLimitException;
use App\Libraries\Integrations\Http\IntegrationHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * O cliente HTTP é o único ponto da lib que fala com a rede, então é onde
 * ficam as regras de resiliência. Aqui elas são exercitadas sem tocar em
 * socket: a subclasse abaixo troca dispatch() por respostas roteirizadas.
 *
 * @internal
 */
final class IntegrationHttpClientTest extends TestCase
{
    public function testRepeteEmErro5xxEDevolveOSucessoSeguinte(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            [500, ''],
            [502, ''],
            [200, '{"success":true,"result":[1,2]}'],
        ]);

        $body = $client->get('/v2/teste');

        $this->assertSame([1, 2], $body['result']);
        $this->assertSame(3, $client->attempts, 'deveria ter gasto exatamente as 3 tentativas');
    }

    public function testDesisteDepoisDoLimiteDeTentativas(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            [500, ''],
            [500, ''],
            [500, ''],
        ]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('erro 500');

        try {
            $client->get('/v2/teste');
        } finally {
            $this->assertSame(IntegrationHttpClient::MAX_ATTEMPTS, $client->attempts);
        }
    }

    /**
     * 4xx é erro de parâmetro ou de rota: repetir não conserta e ainda queima
     * quota do tenant na plataforma externa.
     */
    public function testNaoRepeteEmErro4xx(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            [404, '{"success":false}'],
            [200, '{"success":true}'],
        ]);

        try {
            $client->get('/v2/inexistente');
            $this->fail('deveria ter lançado IntegrationException');
        } catch (IntegrationException $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
        }

        $this->assertSame(1, $client->attempts, '4xx não pode ser repetido');
    }

    /**
     * Credencial inválida tem tratamento próprio porque a consequência é
     * outra: desliga o sync em vez de reagendar.
     */
    public function testCredencialRecusadaViraAuthExceptionSemRepetir(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            [401, ''],
            [200, '{"success":true}'],
        ]);

        $this->expectException(AuthException::class);

        try {
            $client->get('/v2/teste');
        } finally {
            $this->assertSame(1, $client->attempts);
        }
    }

    public function testRateLimitViraRateLimitExceptionAposAsTentativas(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            [429, ''],
            [429, ''],
            [429, ''],
        ]);

        $this->expectException(RateLimitException::class);

        $client->get('/v2/teste');
    }

    public function testFalhaDeTransporteERepetida(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            'throw',
            [200, '{"success":true,"result":[]}'],
        ]);

        $body = $client->get('/v2/teste');

        $this->assertTrue($body['success']);
        $this->assertSame(2, $client->attempts);
    }

    /**
     * A base URL é digitada pelo tenant no painel. Sem esta barreira, a tela de
     * integrações vira um proxy para a rede interna e para o metadata da nuvem.
     */
    public function testBloqueiaUrlInternaAntesDeSair(): void
    {
        $client = new FakeHttpClient('http://169.254.169.254', [[200, '{}']]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('inválida');

        try {
            $client->get('/latest/meta-data/');
        } finally {
            $this->assertSame(0, $client->attempts, 'não pode nem ter tentado sair');
        }
    }

    public function testBloqueiaEsquemaNaoHttp(): void
    {
        $client = new FakeHttpClient('file:///etc/passwd', [[200, '{}']]);

        $this->expectException(IntegrationException::class);

        $client->get('/');
    }

    /**
     * O token de integração vai em texto puro no corpo de cada chamada — por
     * http ele trafega em claro pra qualquer um no caminho da rede. UrlGuard
     * continua aceitando http de propósito (ele também protege webhook e URL
     * de imagem, onde exigir https quebraria integrações legítimas); a
     * exigência de https é só desta classe, e só fora do ambiente de
     * desenvolvimento.
     */
    public function testBaseUrlHttpERecusadaEmProducao(): void
    {
        $client = new FakeHttpClient('http://simob-exemplo.com.br', [[200, '{}']]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('https');

        try {
            $client->get('/v2/teste');
        } finally {
            $this->assertSame(0, $client->attempts, 'não pode nem ter tentado sair');
        }
    }

    /**
     * Apontar a integração para o site institucional em vez do sistema é o
     * erro de digitação mais provável do tenant. A mensagem precisa dizer isso,
     * e não estourar um json_decode em algum lugar distante.
     */
    public function testRespostaNaoJsonViraMensagemUtil(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [
            [200, '<!DOCTYPE html><html><body>Bem-vindo</body></html>'],
        ]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('formato inesperado');

        $client->get('/');
    }

    public function testCorpoVazioViraArrayVazio(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [[204, '']]);

        $this->assertSame([], $client->get('/v2/teste'));
    }

    /**
     * CURLRequest repassa `multipart` direto pra CURLOPT_POSTFIELDS sem
     * nenhum shim estilo Guzzle (ver CURLRequest::applyBody) — um array
     * [['name'=>.., 'contents'=>..]] vira campos `0[name]`/`0[contents]` no
     * cURL nativo, nunca um campo chamado `data`. Contra a API real do Simob
     * isso produz "Informe o campo 'data' no formulario!" em endpoints que
     * validam o campo (alguns, como /imovel/caracteristicas, aceitam de
     * qualquer forma, o que mascarou o bug até chegar no de listagem).
     */
    public function testMultipartMandaOsCamposComoArrayAssociativoPlano(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10', [[200, '{"success":true}']]);

        $client->postMultipart('/v2/integracaoApi/imovel/filtro', ['data' => '{"finalidade":1}']);

        $this->assertSame(
            ['data' => '{"finalidade":1}'],
            $client->lastOptions['multipart']
        );
    }

    public function testBaseUrlPerdeABarraFinalEOEndpointNaoDuplica(): void
    {
        $client = new FakeHttpClient('https://203.0.113.10/', [[200, '{}']]);

        $client->get('/v2/teste');

        $this->assertSame('https://203.0.113.10/v2/teste', $client->lastUrl);
    }

    public function testHeadersPadraoDoConectorSaoAplicados(): void
    {
        $client = new FakeHttpClient(
            'https://203.0.113.10',
            [[200, '{}']],
            ['Authorization' => 'Bearer segredo']
        );

        $client->get('/v2/teste');

        $this->assertSame('Bearer segredo', $client->lastOptions['headers']['Authorization']);
        $this->assertSame('Habitaweb-Integracoes/1.0', $client->lastOptions['headers']['User-Agent']);
    }
}

/**
 * Cliente com dispatch() roteirizado e backoff anulado.
 *
 * Sem anular o backoff, a suíte inteira ficaria dormindo os 500ms + 1s de cada
 * cenário de retry.
 */
final class FakeHttpClient extends IntegrationHttpClient
{
    public int $attempts = 0;
    public array $lastOptions = [];
    public string $lastUrl = '';

    /** @var list<array{0:int,1:string}|'throw'> */
    private array $script;

    public function __construct(string $baseUrl, array $script, array $headers = [])
    {
        parent::__construct($baseUrl, $headers, 'Teste');
        $this->script = $script;
    }

    protected function dispatch(string $method, string $url, array $options)
    {
        $this->attempts++;
        $this->lastUrl = $url;

        $options['headers'] = array_merge([
            'Accept'     => 'application/json',
            'User-Agent' => 'Habitaweb-Integracoes/1.0',
        ], $this->defaultHeadersForTest(), $options['headers'] ?? []);

        $this->lastOptions = $options;

        $step = array_shift($this->script);

        if ($step === null) {
            throw new \LogicException('dispatch() chamado mais vezes do que o roteiro previa');
        }

        if ($step === 'throw') {
            throw new \RuntimeException('cURL error 28: timeout');
        }

        return new FakeResponse($step[0], $step[1]);
    }

    protected function backoff(int $attempt, int $retryAfterSeconds = 0): void
    {
        // Sem espera nos testes.
    }

    private function defaultHeadersForTest(): array
    {
        $ref  = new \ReflectionProperty(IntegrationHttpClient::class, 'defaultHeaders');
        $ref->setAccessible(true);

        return $ref->getValue($this);
    }
}

final class FakeResponse
{
    public function __construct(private int $status, private string $body)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }
}
