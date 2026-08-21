<?php

namespace Tests\Feature\Api;

use App\Libraries\Media\ImageSanitizer;
use App\Libraries\Media\ImageVariantGenerator;
use App\Services\PropertyService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\HabitawebTestCase;
use Tests\Support\TestUploadedFile;

/**
 * Cadastro de imagens de imóvel, com arquivos de verdade
 * (tests/_support/fixtures/images) — inclusive um JPEG com GPS real embutido e
 * um webshell PHP renomeado para .jpg.
 *
 * Divisão proposital: o upload multipart é exercido no service, porque
 * UploadedFile::isValid() usa is_uploaded_file(), que é sempre falso fora de um
 * POST HTTP real do SAPI (por isso existe Tests\Support\TestUploadedFile). Já
 * as rotas que não dependem de $_FILES — ingestão por URL, DELETE de mídia,
 * definir capa, listagem e autorização — são exercidas via HTTP de verdade.
 *
 * @internal
 */
final class PropertyMediaApiTest extends HabitawebTestCase
{
    use ApiTestTrait;
    use DatabaseTestTrait;

    protected $seed = 'App\Database\Seeds\PlanSeeder';

    private string $fixtures;

    /**
     * Só os diretórios que ESTE teste criou.
     *
     * `FCPATH . 'uploads/properties'` é a mesma pasta física usada pelo
     * ambiente de desenvolvimento (só o banco é isolado, `habitaweb_test` vs
     * o banco de dev) — um tearDown() que varresse a pasta inteira apagaria
     * qualquer mídia real que estivesse lá na hora de rodar a suíte, e foi
     * exatamente isso que aconteceu: uma rodada completa de
     * `vendor/bin/phpunit` apagou do disco fotos reais sincronizadas de uma
     * integração de verdade, mesmo com os registros de `property_media`
     * intactos no banco (arquivo e linha de banco não vivem na mesma
     * transação). Mesmo padrão de PropertyMediaVariantTest/PropertyLimitTest.
     */
    private array $uploadDirsToClean = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetApiState();
        $this->fixtures = SUPPORTPATH . 'fixtures/images/';
    }

    protected function tearDown(): void
    {
        foreach ($this->uploadDirsToClean as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob("{$dir}/*") ?: []);
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    private function file(string $fixture, string $clientName, string $clientMime): TestUploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fx_');
        copy($this->fixtures . $fixture, $tmp);

        return new TestUploadedFile($tmp, $clientName, $clientMime, filesize($tmp), UPLOAD_ERR_OK);
    }

    private function makeProperty(array $tenant): int
    {
        $result = (new PropertyService())->trySaveProperty([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Casa para teste de mídia',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'preco'        => 500000,
            'cidade'       => 'Porto Alegre',
            'bairro'       => 'Centro',
        ]);

        $this->assertTrue($result['success'], 'Setup falhou ao criar o imóvel.');

        $propertyId = (int) $result['property_id'];
        $this->uploadDirsToClean[] = FCPATH . 'uploads/properties/' . $propertyId;

        return $propertyId;
    }

    /** Semeia uma mídia real no imóvel e devolve o id. */
    private function seedMedia(int $propertyId, string $fixture = 'sala.jpg'): int
    {
        $mime   = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($fixture, PATHINFO_EXTENSION)];
        $result = (new PropertyService())->addMedia($propertyId, $this->file($fixture, $fixture, $mime));

        $this->assertTrue($result['success'], 'Setup de mídia falhou: ' . ($result['message'] ?? ''));

        return (int) $result['media']['id'];
    }

    // ---------------------------------------------------------------- upload

    public function testUploadStoresImageAndGeneratesVariants(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        // 1600x1200 — mais larga que as duas variantes (card 480, gallery 1280),
        // então ambas devem ser geradas.
        $result = (new PropertyService())->addMedia($propertyId, $this->file('casa-com-gps.jpg', 'casa.jpg', 'image/jpeg'));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['media']['principal'], 'A primeira imagem deve virar capa.');

        $this->assertDatabaseHas('property_media', [
            'id'          => $result['media']['id'],
            'property_id' => $propertyId,
            'tipo'        => 'IMAGE', // padronizado (antes convivia com 'imagem' e 'FOTO')
        ]);

        $storage = service('publicStorage');
        $path    = $result['media']['path'];

        $this->assertTrue($storage->exists($path), 'Original não foi gravado.');
        $this->assertTrue(
            $storage->exists(ImageVariantGenerator::variantPath($path, 'card')),
            'Variante "card" não foi gerada.'
        );
        $this->assertTrue(
            $storage->exists(ImageVariantGenerator::variantPath($path, 'gallery')),
            'Variante "gallery" não foi gerada.'
        );
    }

    public function testVariantIsNotUpscaledWhenSourceIsSmaller(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        // sala.jpg tem 1200px de largura — menor que a variante gallery (1280px).
        // O gerador pula o upscale de propósito, então só a "card" deve existir.
        $result = (new PropertyService())->addMedia($propertyId, $this->file('sala.jpg', 'sala.jpg', 'image/jpeg'));
        $this->assertTrue($result['success']);

        $storage = service('publicStorage');
        $path    = $result['media']['path'];

        $this->assertTrue($storage->exists(ImageVariantGenerator::variantPath($path, 'card')));
        $this->assertFalse(
            $storage->exists(ImageVariantGenerator::variantPath($path, 'gallery')),
            'Não deve gerar variante maior que o original (upscale).'
        );
        // O helper de view cai no original nesse caso — por isso nada quebra.
        $this->assertTrue($storage->exists($path));
    }

    public function testUploadStripsGpsExifFromPartnerPhoto(): void
    {
        // A fixture tem GPS e Make/Model reais. O caminho da API não removia
        // EXIF (só o do painel admin removia), então a localização exata do
        // imóvel ia para o ar junto com a foto do anunciante.
        $this->assertTrue(
            ImageSanitizer::hasExif($this->fixtures . 'casa-com-gps.jpg'),
            'A fixture precisa ter EXIF para este teste fazer sentido.'
        );

        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $result = (new PropertyService())->addMedia($propertyId, $this->file('casa-com-gps.jpg', 'casa.jpg', 'image/jpeg'));
        $this->assertTrue($result['success']);

        $stored = FCPATH . $result['media']['path'];
        $this->assertFileExists($stored);
        $this->assertFalse(ImageSanitizer::hasExif($stored), 'A imagem publicada ainda contém EXIF (GPS/câmera).');
        $this->assertNotFalse(@getimagesize($stored), 'A imagem deve continuar válida após o strip.');
    }

    public function testPhpWebshellDisguisedAsJpgIsRejected(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        // Nome e Content-Type dizem "imagem"; o conteúdo é PHP.
        $result = (new PropertyService())->addMedia($propertyId, $this->file('webshell.jpg', 'foto.jpg', 'image/jpeg'));

        $this->assertFalse($result['success']);
        $this->assertDatabaseMissing('property_media', ['property_id' => $propertyId]);
    }

    public function testUndersizedImageIsRejected(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $result = (new PropertyService())->addMedia($propertyId, $this->file('minuscula.jpg', 'mini.jpg', 'image/jpeg'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Dimensões', $result['message']);
    }

    public function testPlanPhotoLimitIsEnforced(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $subscription = model('App\Models\SubscriptionModel')->where('account_id', $tenant['account']->id)->first();
        model('App\Models\PlanModel')->update($subscription->plan_id, ['limite_fotos_por_imovel' => 2]);

        $this->seedMedia($propertyId);
        $this->seedMedia($propertyId);

        $result = (new PropertyService())->addMedia($propertyId, $this->file('sala.jpg', 'foto3.jpg', 'image/jpeg'));

        $this->assertFalse($result['success'], 'O limite de fotos do plano não foi aplicado.');
        $this->assertSame('PHOTO_LIMIT_REACHED', $result['code']);
    }

    // ----------------------------------------------------- ingestão por URL

    public function testIngestImageByUrlOverHttp(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $this->fakeFetcher();

        $result = $this->postJson(
            "api/v1/properties/{$propertyId}/media",
            ['url' => 'https://cdn.parceiro.com.br/fotos/sala.jpg'],
            $tenant['api_key']
        );

        $result->assertStatus(201);
        $media = $this->envelope($result)['data'];

        $this->assertTrue($media['principal']);
        $this->assertDatabaseHas('property_media', [
            'id'         => $media['id'],
            'source_url' => 'https://cdn.parceiro.com.br/fotos/sala.jpg',
        ]);
    }

    public function testReingestingSameUrlIsDeduplicated(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);
        $url        = 'https://cdn.parceiro.com.br/fotos/sala.jpg';

        $this->fakeFetcher();
        $this->postJson("api/v1/properties/{$propertyId}/media", ['url' => $url], $tenant['api_key'])
            ->assertStatus(201);

        $this->fakeFetcher();
        $this->postJson("api/v1/properties/{$propertyId}/media", ['url' => $url], $tenant['api_key'])
            ->assertStatus(201);

        // Reimportar o mesmo catálogo não pode rebaixar a mesma foto duas vezes.
        $this->assertSame(
            1,
            model('App\Models\PropertyMediaModel')->where('property_id', $propertyId)->countAllResults(),
            'A mesma URL foi ingerida duas vezes.'
        );
    }

    public function testBatchUrlIngestOverHttp(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $this->fakeFetcher();

        $result = $this->postJson(
            "api/v1/properties/{$propertyId}/media/batch",
            ['images' => [
                ['url' => 'https://cdn.parceiro.com.br/1.jpg', 'ordem' => 1],
                ['url' => 'https://cdn.parceiro.com.br/2.jpg', 'ordem' => 2],
                ['url' => 'https://cdn.parceiro.com.br/3.jpg', 'ordem' => 3],
            ]],
            $tenant['api_key']
        );

        $result->assertStatus(201);
        $data = $this->envelope($result)['data'];

        $this->assertCount(3, $data['imported']);
        $this->assertSame([], $data['errors']);
        $this->assertSame(
            3,
            model('App\Models\PropertyMediaModel')->where('property_id', $propertyId)->countAllResults()
        );
    }

    /**
     * Troca o fetcher por um que devolve uma fixture local, sem rede.
     * Sem isso, a própria guarda de SSRF (corretamente) bloquearia qualquer
     * servidor de teste em 127.0.0.1.
     */
    private function fakeFetcher(): void
    {
        $fixture = $this->fixtures . 'sala.jpg';

        $fake = new class ($fixture) extends \App\Libraries\Media\RemoteImageFetcher {
            public function __construct(private string $fixture)
            {
            }

            public function fetch(string $url): array
            {
                $tmp = tempnam(sys_get_temp_dir(), 'fake_');
                copy($this->fixture, $tmp);

                return ['success' => true, 'path' => $tmp, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
            }
        };

        $service = new PropertyService();
        $service->setImageFetcher($fake);

        // O controller resolve o PropertyService pelo container (service()),
        // então injetar aqui faz o fake valer também dentro da requisição HTTP.
        \CodeIgniter\Config\Services::injectMock('propertyService', $service);
    }

    // ----------------------------------------------------------- rotas HTTP

    public function testDeleteMediaRouteDoesNotDeleteTheProperty(): void
    {
        // Regressão do bug mais perigoso do roteamento: resource() registrava
        // DELETE properties/(:any) ANTES da rota de mídia, então
        // DELETE /properties/5/media/9 caía em delete("5/media/9") — que no
        // MySQL apagava o imóvel 5 e no Postgres dava 500.
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);
        $mediaId    = $this->seedMedia($propertyId);

        $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->delete("api/v1/properties/{$propertyId}/media/{$mediaId}")
            ->assertStatus(200);

        $this->assertSame(
            0,
            model('App\Models\PropertyMediaModel')->where('property_id', $propertyId)->countAllResults()
        );

        $property = model('App\Models\PropertyModel')->find($propertyId);
        $this->assertNotNull($property, 'A rota de exclusão de mídia apagou o imóvel inteiro.');
        $this->assertNull($property->deleted_at);
    }

    public function testSetMainMediaKeepsExactlyOneCover(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $ids = [
            $this->seedMedia($propertyId, 'sala.jpg'),
            $this->seedMedia($propertyId, 'planta.png'),
            $this->seedMedia($propertyId, 'fachada.webp'),
        ];

        $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->post("api/v1/properties/{$propertyId}/media/{$ids[2]}/main")
            ->assertStatus(200);

        $mediaModel = model('App\Models\PropertyMediaModel');

        $this->assertSame(
            1,
            $mediaModel->where('property_id', $propertyId)->where('principal', true)->countAllResults(),
            'Deve haver exatamente uma capa.'
        );
        $this->assertTrue((bool) $mediaModel->find($ids[2])->principal);
    }

    public function testListMediaReturnsOrderedMedia(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);
        $this->seedMedia($propertyId);

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get("api/v1/properties/{$propertyId}/media");

        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertSame($propertyId, $data['property_id']);
        $this->assertCount(1, $data['media']);
        $this->assertTrue($data['media'][0]['principal']);
        $this->assertStringContainsString('uploads/properties', $data['media'][0]['path']);
    }

    public function testCrossTenantMediaAccessIsForbidden(): void
    {
        $owner      = $this->makeApiTenant();
        $attacker   = $this->makeApiTenant();
        $propertyId = $this->makeProperty($owner);
        $mediaId    = $this->seedMedia($propertyId);

        $this->withHeaders($this->withApiKey($attacker['api_key']))
            ->get("api/v1/properties/{$propertyId}/media")
            ->assertStatus(403);

        $this->withHeaders($this->withApiKey($attacker['api_key']))
            ->delete("api/v1/properties/{$propertyId}/media/{$mediaId}")
            ->assertStatus(403);

        // A mídia da vítima continua intacta.
        $this->assertDatabaseHas('property_media', ['id' => $mediaId, 'deleted_at' => null]);
    }

    public function testMediaEndpointsRequireAuthentication(): void
    {
        $tenant     = $this->makeApiTenant();
        $propertyId = $this->makeProperty($tenant);

        $this->get("api/v1/properties/{$propertyId}/media")->assertStatus(401);
        $this->postJson("api/v1/properties/{$propertyId}/media", ['url' => 'https://x.com/a.jpg'])->assertStatus(401);
    }

    // ------------------------------------------------------------ SSRF guard

    public function testRemoteFetcherBlocksInternalAddresses(): void
    {
        $fetcher = new \App\Libraries\Media\RemoteImageFetcher();

        foreach ([
            'http://127.0.0.1/foto.jpg',
            'http://localhost/foto.jpg',
            'http://10.0.0.5/foto.jpg',
            'http://192.168.1.10/foto.jpg',
            'http://169.254.169.254/latest/meta-data/',
        ] as $url) {
            $result = $fetcher->validateUrl($url);
            $this->assertFalse($result['valid'], "Deveria bloquear {$url}");
        }
    }

    public function testRemoteFetcherBlocksNonHttpSchemes(): void
    {
        $fetcher = new \App\Libraries\Media\RemoteImageFetcher();

        foreach (['file:///etc/passwd', 'gopher://x/1', 'ftp://host/a.jpg'] as $url) {
            $this->assertFalse($fetcher->validateUrl($url)['valid'], "Deveria bloquear {$url}");
        }
    }

    public function testRemoteFetcherAllowsPublicHttpsUrl(): void
    {
        $fetcher = new \App\Libraries\Media\RemoteImageFetcher();

        // Só valida esquema/destino — não baixa nada.
        $this->assertTrue($fetcher->validateUrl('https://93.184.216.34/foto.jpg')['valid']);
    }
}
