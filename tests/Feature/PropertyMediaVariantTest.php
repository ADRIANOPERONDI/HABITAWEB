<?php

namespace Tests\Feature;

use App\Libraries\Media\ImageVariantGenerator;
use App\Models\PropertyModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;
use Tests\Support\TestUploadedFile;

/**
 * Cobre a geração de variantes (thumbnails) no upload de mídia de imóvel:
 * - addMedia() gera _card (480px) e _gallery (1280px) ao lado do original;
 * - property_media.url continua apontando o ORIGINAL (sem mudança de schema);
 * - deleteMedia() remove original + variantes;
 * - imagem pequena (menor que o alvo) NÃO gera variante e media_variant_url()
 *   cai graciosamente no original — o contrato que mantém o legado funcionando.
 */
final class PropertyMediaVariantTest extends HabitawebTestCase
{
    private array $uploadDirsToClean = [];

    protected function setUp(): void
    {
        parent::setUp();
        // media_variant_url vive no helper 'sys' (autocarregado via BaseController
        // em requisições reais; em teste unitário precisa ser carregado à mão).
        helper('sys');
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

    private function insertProperty(int $accountId): int
    {
        $model = new PropertyModel();
        $model->insert([
            'account_id'   => $accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel variantes',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ]);

        return (int) $model->getInsertID();
    }

    /** Gera um JPEG real com as dimensões pedidas (fixtures do repo são só 200x200). */
    private function makeJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 120, 160, 200));
        $tmp = sys_get_temp_dir() . '/' . uniqid('variant_src_', true) . '.jpg';
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        return $tmp;
    }

    public function testLargeUploadGeneratesVariantsAndDeleteRemovesAll(): void
    {
        $tenant = (new TenantFactory())->create();
        $propertyId = $this->insertProperty($tenant['account']->id);
        $this->uploadDirsToClean[] = FCPATH . 'uploads/properties/' . $propertyId;

        $tmp = $this->makeJpeg(1600, 1200);
        $file = new TestUploadedFile($tmp, 'foto.jpg', 'image/jpeg', filesize($tmp), UPLOAD_ERR_OK);

        $service = new PropertyService();
        $result = $service->addMedia($propertyId, $file);
        @unlink($tmp);

        $this->assertTrue($result['success'], $result['message'] ?? '');

        $mediaRow = model('App\Models\PropertyMediaModel')->where('property_id', $propertyId)->first();
        $original = $mediaRow->url;

        // url no banco = original, sem sufixo de variante.
        $this->assertStringNotContainsString('_card', $original);

        $cardPath    = ImageVariantGenerator::variantPath($original, 'card');
        $galleryPath = ImageVariantGenerator::variantPath($original, 'gallery');

        $this->assertFileExists(FCPATH . $original);
        $this->assertFileExists(FCPATH . $cardPath);
        $this->assertFileExists(FCPATH . $galleryPath);

        [$cardW]    = getimagesize(FCPATH . $cardPath);
        [$galleryW] = getimagesize(FCPATH . $galleryPath);
        $this->assertSame(480, $cardW);
        $this->assertSame(1280, $galleryW);

        // Helper resolve para a variante quando ela existe.
        $this->assertStringContainsString('_card', media_variant_url($original, 'card'));

        // Delete remove original + variantes.
        $delete = $service->deleteMedia($propertyId, (int) $mediaRow->id);
        $this->assertTrue($delete['success']);
        $this->assertFileDoesNotExist(FCPATH . $original);
        $this->assertFileDoesNotExist(FCPATH . $cardPath);
        $this->assertFileDoesNotExist(FCPATH . $galleryPath);
    }

    public function testSmallUploadSkipsVariantsAndHelperFallsBackToOriginal(): void
    {
        $tenant = (new TenantFactory())->create();
        $propertyId = $this->insertProperty($tenant['account']->id);
        $this->uploadDirsToClean[] = FCPATH . 'uploads/properties/' . $propertyId;

        // 300px: menor que os alvos de 480/1280 — sem upscale, sem variante.
        $tmp = $this->makeJpeg(300, 300);
        $file = new TestUploadedFile($tmp, 'foto.jpg', 'image/jpeg', filesize($tmp), UPLOAD_ERR_OK);

        $result = (new PropertyService())->addMedia($propertyId, $file);
        @unlink($tmp);

        $this->assertTrue($result['success'], $result['message'] ?? '');

        $original = model('App\Models\PropertyMediaModel')->where('property_id', $propertyId)->first()->url;

        $this->assertFileExists(FCPATH . $original);
        $this->assertFileDoesNotExist(FCPATH . ImageVariantGenerator::variantPath($original, 'card'));

        // Fallback gracioso: sem variante, o helper devolve o original.
        $this->assertStringNotContainsString('_card', media_variant_url($original, 'card'));
        $this->assertStringContainsString($original, media_variant_url($original, 'card'));
    }
}
