<?php

use App\Libraries\Storage\S3Storage;
use CodeIgniter\Test\CIUnitTestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * Cobre o contrato do S3Storage usando o adapter em memória do flysystem —
 * mesma interface FilesystemOperator do adapter S3 real, sem credenciais.
 * O que importa provar: round-trip put/exists/readStream/delete, o consumo do
 * arquivo de origem no put(), o fail-closed do disco privado (getPublicUrl
 * SEMPRE null — documentos KYC nunca podem ganhar URL pública) e a defesa de
 * path traversal.
 */
final class S3StorageTest extends CIUnitTestCase
{
    private function makeDisk(bool $public, ?string $baseUrl = null): S3Storage
    {
        return new S3Storage(
            new Filesystem(new InMemoryFilesystemAdapter()),
            publiclyServed: $public,
            publicBaseUrl: $baseUrl,
        );
    }

    private function makeSourceFile(string $contents = 'conteudo-de-teste'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 's3test_');
        file_put_contents($tmp, $contents);

        return $tmp;
    }

    public function testPutReadDeleteRoundTripAndSourceConsumed(): void
    {
        $disk = $this->makeDisk(true, 'https://cdn.exemplo.com');
        $src  = $this->makeSourceFile('foto-binaria');

        $key = $disk->put('uploads/properties/7/abc.jpg', $src);

        $this->assertSame('uploads/properties/7/abc.jpg', $key);
        $this->assertFileDoesNotExist($src, 'put() deve consumir o arquivo de origem');
        $this->assertTrue($disk->exists($key));

        $stream = $disk->readStream($key);
        $this->assertIsResource($stream);
        $this->assertSame('foto-binaria', stream_get_contents($stream));
        fclose($stream);

        $this->assertTrue($disk->delete($key));
        $this->assertFalse($disk->exists($key));
        $this->assertNull($disk->readStream($key));
    }

    public function testPublicUrlJoinsCdnBaseAndPrivateDiskIsFailClosed(): void
    {
        $public = $this->makeDisk(true, 'https://cdn.exemplo.com/');
        $this->assertSame(
            'https://cdn.exemplo.com/uploads/x.jpg',
            $public->getPublicUrl('/uploads/x.jpg')
        );

        // Invariante de segurança: disco privado NUNCA emite URL pública,
        // mesmo que alguém configure publicBaseUrl por engano no construtor —
        // o flag publiclyServed=false vence.
        $private = $this->makeDisk(false, 'https://cdn.exemplo.com');
        $this->assertNull($private->getPublicUrl('kyc/doc.jpg'));
    }

    public function testSignedUrlReturnsNullWhenAdapterLacksSupport(): void
    {
        // Adapter em memória não implementa temporaryUrl — o contrato exige
        // null (chamador cai no proxy autenticado), não exceção.
        $disk = $this->makeDisk(false);
        $src  = $this->makeSourceFile();
        $disk->put('kyc/doc.jpg', $src);

        $this->assertNull($disk->getSignedUrl('kyc/doc.jpg', 300));
    }

    public function testPathTraversalIsRejected(): void
    {
        $disk = $this->makeDisk(true);

        $this->expectException(InvalidArgumentException::class);
        $disk->exists('uploads/../../.env');
    }
}
