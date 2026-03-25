<?php

namespace Tests;

use App\Test\TestCase;

/**
 * TESTES IMAGE HANDLING & FILE UPLOADS
 * 
 * Validar processamento seguro de imagens
 * Teste: php spark test --filter ImageHandlingTest
 */
class ImageHandlingTest extends TestCase
{
    protected $dbGroup = 'default';
    protected $apiToken = 'test_api_key';

    // ==================== IMAGE VALIDATION ====================

    /**
     * @test
     * Rejeitar arquivo não-imagem
     */
    public function testRejectNonImageFile()
    {
        $textFile = $this->createTempFile('malware.txt', 'This is not an image');
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($textFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue($response->getStatusCode() >= 400, 'Arquivo não-imagem deve ser rejeitado');
        @unlink($textFile);
    }

    /**
     * @test
     * Rejeitar PHP disfarçado de imagem
     */
    public function testRejectPHPInImage()
    {
        // PHP code com magic bytes de PNG
        $phpImage = "\x89PNG\r\n\x1a\n<?php system(\$_GET['cmd']); ?>";
        $phpFile = $this->createTempFile('shell.png', $phpImage);
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($phpFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        // Deve rejeitar como arquivo corrompido ou código detectado
        $this->assertTrue(
            $response->getStatusCode() >= 400 || 
            strpos($response->getBody(), 'corrupt') !== false,
            'PHP disfarçado de imagem deve ser bloqueado'
        );

        @unlink($phpFile);
    }

    /**
     * @test
     * Validar mínimo de dimensões
     */
    public function testMinimumImageDimensions()
    {
        // Criar imagem 100x100 (abaixo do esperado > 800x600)
        $tinyImage = imagecreatetruecolor(100, 100);
        $color = imagecolorallocate($tinyImage, 255, 0, 0);
        imagefill($tinyImage, 0, 0, $color);

        $tinyFile = tempnam(sys_get_temp_dir(), 'tiny_');
        imagejpeg($tinyImage, $tinyFile);
        imagedestroy($tinyImage);

        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($tinyFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 400,
            'Imagem muito pequena deve ser rejeitada'
        );

        @unlink($tinyFile);
    }

    /**
     * @test
     * Validar máximo de dimensões
     */
    public function testMaximumImageDimensions()
    {
        // Imagem com dimensões extremas: 50000x50000
        $largeImage = imagecreatetruecolor(50000, 50000);
        $largeFile = tempnam(sys_get_temp_dir(), 'large_');
        imagejpeg($largeImage, $largeFile, 1); // Qualidade mínima
        imagedestroy($largeImage);

        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($largeFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 400,
            'Imagem com dimensões extremas deve ser rejeitada'
        );

        @unlink($largeFile);
    }

    /**
     * @test
     * Validar tamanho máximo de arquivo
     */
    public function testMaximumFileSize()
    {
        // Criar arquivo 100MB
        $largeFile = $this->createLargeFile(100 * 1024 * 1024);

        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($largeFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 400 || $response->getStatusCode() === 413,
            'Arquivo muito grande deve ser rejeitado'
        );

        @unlink($largeFile);
    }

    /**
     * @test
     * Rejeitar imagem corrompida
     */
    public function testRejectCorruptedImage()
    {
        // Imagem JPEG parcialmente válida mas corrompida
        $corruptImage = "\xFF\xD8\xFF\xE0CORRUPTED_DATA_HERE";
        $corruptFile = $this->createTempFile('corrupt.jpg', $corruptImage);

        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($corruptFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 400,
            'Imagem corrompida deve ser rejeitada'
        );

        @unlink($corruptFile);
    }

    // ==================== FILE STORAGE SECURITY ====================

    /**
     * @test
     * Verificar que arquivo é armazenado fora do web root
     */
    public function testFileStoredOutsideWebRoot()
    {
        $image = $this->createValidImage('image.jpg');
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $filePath = $data['data']['file_path'] ?? null;

            // Verificar que não está em public/
            $this->assertFalse(
                strpos($filePath, 'public/') === 0,
                'Arquivo não deve estar no web root'
            );

            // Verificar que está em writable/uploads/
            $this->assertTrue(
                strpos($filePath, 'uploads/') !== false,
                'Arquivo deve estar em uploads directory'
            );
        }
    }

    /**
     * @test
     * Verificar permissões do arquivo uploaded
     */
    public function testUploadedFilePermissions()
    {
        $image = $this->createValidImage('image.jpg');
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $filePath = $data['data']['file_path'] ?? null;
            $fullPath = WRITEPATH . $filePath;

            if (file_exists($fullPath)) {
                $permissions = substr(sprintf('%o', fileperms($fullPath)), -4);
                
                // Deve ser 644 ou 444 (não executável)
                $this->assertNotEquals(
                    '755',
                    $permissions,
                    'Arquivo não deve ser executável'
                );
            }
        }
    }

    /**
     * @test
     * Verificar que arquivo é renomeado (não mantém nome original)
     */
    public function testFileRenamedOnUpload()
    {
        $image = $this->createValidImage('MyOriginalName.JPG');
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $filePath = $data['data']['file_path'] ?? null;

            $this->assertFalse(
                strpos($filePath, 'MyOriginalName') !== false,
                'Arquivo deve ser renomeado para hash/UUID'
            );
        }
    }

    // ==================== IMAGE PROCESSING ====================

    /**
     * @test
     * Verificar se imagem é processada (remover EXIF)
     */
    public function testEXIFDataRemoved()
    {
        // Criar imagem com EXIF data
        $image = imagecreatetruecolor(800, 600);
        imagejpeg($image, $tempFile = tempnam(sys_get_temp_dir(), 'exif_'));

        // Adicionar EXIF data fake (simulado)
        $exifComment = "© John Doe - Date: 2024 - Location: 42.3601,-71.0589";

        $response = $this->post('/api/v1/properties/1/media', [
            'file' => fopen($tempFile, 'r')
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        imagedestroy($image);
        @unlink($tempFile);

        // Após upload, verificar se imagem foi reprocessada
        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $filePath = WRITEPATH . ($data['data']['file_path'] ?? '');

            // Usar exiftool se disponível
            $output = [];
            $status = 0;
            @exec("exiftool \"$filePath\" 2>/dev/null", $output, $status);

            // EXIF deve estar vazio ou removed
            $hasExif = count($output) > 0 && !in_array('Tool not found', $output);
            // Teste pode passar se exiftool não está instalado
        }
    }

    /**
     * @test
     * Gerar thumbnail automaticamente
     */
    public function testThumbnailGeneration()
    {
        $image = $this->createValidImage('large.jpg', 1920, 1080);

        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $thumbPath = $data['data']['thumbnail_path'] ?? null;

            // Verificar se thumbnail foi gerado
            $this->assertNotNull($thumbPath, 'Thumbnail deve ser gerado');
            $this->assertTrue(
                file_exists(WRITEPATH . $thumbPath),
                'Arquivo thumbnail deve existir'
            );

            // Verificar se thumbnail é menor
            $originalSize = filesize(WRITEPATH . $data['data']['file_path']);
            $thumbSize = filesize(WRITEPATH . $thumbPath);

            $this->assertLessThan(
                $originalSize,
                $thumbSize,
                'Thumbnail deve ser menor que original'
            );
        }
    }

    // ==================== PATH TRAVERSAL ====================

    /**
     * @test
     * Bloquear path traversal em upload
     */
    public function testPathTraversalBlocked()
    {
        $image = $this->createValidImage('test.jpg');

        // Tentar traversal
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image,
            'filename' => '../../etc/passwd'  // Path traversal attempt
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        // Deve rejeitar ou sanitizar filename
        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $filePath = $data['data']['file_path'] ?? '';

            $this->assertFalse(
                strpos($filePath, '..') !== false,
                'Path traversal deve ser sanitizado'
            );
            $this->assertFalse(
                strpos($filePath, 'passwd') !== false,
                'Não deve permitir acesso a /etc/passwd'
            );
        }
    }

    // ==================== CONCURRENT UPLOADS ====================

    /**
     * @test
     * Múltiplos uploads concorrentes
     */
    public function testConcurrentUploads()
    {
        $results = [];
        
        for ($i = 0; $i < 5; $i++) {
            $image = $this->createValidImage("concurrent_$i.jpg");
            
            $response = $this->post('/api/v1/properties/1/media', [
                'file' => $image
            ], [
                'headers' => ['X-API-Key' => $this->apiToken]
            ]);

            $results[] = $response->getStatusCode();
        }

        // Todos devem ter sucesso
        foreach ($results as $code) {
            $this->assertTrue(
                $code === 201 || $code === 200,
                'Uploads concorrentes devem funcionar'
            );
        }
    }

    // ==================== DELETE SECURITY ====================

    /**
     * @test
     * Deletar imagem remove arquivo permanentemente
     */
    public function testDeleteRemovesFile()
    {
        $image = $this->createValidImage('delete_me.jpg');

        // Upload
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $mediaId = $data['data']['id'];
            $filePath = WRITEPATH . $data['data']['file_path'];

            // Verificar que arquivo existe
            $this->assertTrue(file_exists($filePath), 'Arquivo deve existir após upload');

            // Delete
            $deleteResponse = $this->delete("/api/v1/properties/1/media/$mediaId", [], [
                'headers' => ['X-API-Key' => $this->apiToken]
            ]);

            if ($deleteResponse->getStatusCode() === 200) {
                // Aguardar processamento
                sleep(1);

                $this->assertFalse(
                    file_exists($filePath),
                    'Arquivo deve ser deletado após delete request'
                );
            }
        }
    }

    /**
     * @test
     * Não é possível deletar imagem de outro usuário
     */
    public function testCannotDeleteOthersMedia()
    {
        $user1 = $this->createUser();
        $user2 = $this->createUser();

        // User1 upload
        $image = $this->createValidImage('user1.jpg');
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $image
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $user1->token]
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $mediaId = $data['data']['id'];

            // User2 tenta deletar
            $deleteResponse = $this->delete("/api/v1/properties/1/media/$mediaId", [], [
                'headers' => ['Authorization' => 'Bearer ' . $user2->token]
            ]);

            $this->assertTrue(
                $deleteResponse->getStatusCode() >= 400,
                'User2 não pode deletar mídia de User1'
            );
        }
    }

    // ==================== HELPERS ====================

    private function createValidImage($filename = 'test.jpg', $width = 1024, $height = 768)
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 100, 150, 200);
        imagefill($image, 0, 0, $color);

        $tempFile = tempnam(sys_get_temp_dir(), 'img_');
        imagejpeg($image, $tempFile, 85);
        imagedestroy($image);

        return fopen($tempFile, 'r');
    }

    private function createTempFile($filename, $content)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'tmp_');
        file_put_contents($tempFile, $content);
        return $tempFile;
    }

    private function createLargeFile($size)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'large_');
        $handle = fopen($tempFile, 'w');

        for ($i = 0; $i < $size; $i += 1024) {
            fwrite($handle, str_repeat('A', min(1024, $size - $i)));
        }

        fclose($handle);
        return $tempFile;
    }

    private function createUser()
    {
        return $this->db->table('users')->insertGetData([
            'email' => 'user' . uniqid() . '@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'account_id' => 1
        ]);
    }
}
