<?php

use App\Libraries\Storage\FallbackStorage;
use App\Libraries\Storage\StorageInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Cobre o disco composto de duas vias (FallbackStorage): S3 primeiro, disco
 * local quando o primário falha. Usa dublês em nível de StorageInterface —
 * o contrato do composto não depende de flysystem.
 *
 * Nota: o composto cacheia a localização dos arquivos (stloc_*) no cache do
 * ambiente de teste; cada teste usa caminhos únicos (uniqid) para não herdar
 * localização de execuções anteriores.
 */
final class FallbackStorageTest extends CIUnitTestCase
{
    private function makeSourceFile(string $contents = 'conteudo-imagem'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fbs');
        file_put_contents($path, $contents);

        return $path;
    }

    private function uniquePath(string $suffix = '.jpg'): string
    {
        return 'uploads/properties/' . uniqid('fb_', true) . $suffix;
    }

    public function testPutGravaNoPrimarioQuandoSaudavel(): void
    {
        $primary  = new FakeDiskStorage('https://cdn.exemplo.com');
        $fallback = new FakeDiskStorage('http://localhost:8080');
        $storage  = new FallbackStorage($primary, $fallback);

        $path   = $this->uniquePath();
        $source = $this->makeSourceFile();

        $this->assertSame($path, $storage->put($path, $source));
        $this->assertFileDoesNotExist($source, 'put() deve consumir o arquivo de origem');
        $this->assertTrue($primary->exists($path));
        $this->assertFalse($fallback->exists($path));
        $this->assertTrue($storage->exists($path));
        $this->assertSame('https://cdn.exemplo.com/' . $path, $storage->getPublicUrl($path));
    }

    public function testPutCaiParaOLocalQuandoPrimarioFalha(): void
    {
        cache()->delete('storage_s3_degraded');

        $primary  = new FakeDiskStorage('https://cdn.exemplo.com', failPuts: true);
        $fallback = new FakeDiskStorage('http://localhost:8080');
        $storage  = new FallbackStorage($primary, $fallback);

        $path   = $this->uniquePath();
        $source = $this->makeSourceFile();

        $this->assertSame($path, $storage->put($path, $source));
        $this->assertFileDoesNotExist($source);
        $this->assertFalse($primary->exists($path));
        $this->assertTrue($fallback->exists($path), 'com o primário fora, o arquivo deve pousar no local');
        // E a URL pública aponta para onde o arquivo REALMENTE está.
        $this->assertSame('http://localhost:8080/' . $path, $storage->getPublicUrl($path));

        // A falha do primário sinaliza o banner do painel do superadmin
        // (clientes não veem nada — para eles o upload funcionou).
        $flag = cache('storage_s3_degraded');
        $this->assertIsArray($flag);
        $this->assertSame('backend fora do ar (simulado)', $flag['reason']);

        cache()->delete('storage_s3_degraded');
    }

    public function testUploadDepoisDeCacheNegativoEhVistoImediatamente(): void
    {
        $primary  = new FakeDiskStorage('https://cdn.exemplo.com');
        $fallback = new FakeDiskStorage('http://localhost:8080');
        $storage  = new FallbackStorage($primary, $fallback);

        $path = $this->uniquePath();

        // Sonda antes do upload: negativa (e cacheada como 'n').
        $this->assertFalse($storage->exists($path));

        // put() precisa sobrescrever o negativo — senão a foto recém-enviada
        // ficaria "invisível" pelo TTL do cache.
        $storage->put($path, $this->makeSourceFile());
        $this->assertTrue($storage->exists($path));
    }

    public function testDeleteRemoveDasDuasVias(): void
    {
        $primary  = new FakeDiskStorage('https://cdn.exemplo.com');
        $fallback = new FakeDiskStorage('http://localhost:8080');
        $storage  = new FallbackStorage($primary, $fallback);

        // Acervo espalhado: mesmo caminho nas duas vias (ex.: migração parcial).
        $path = $this->uniquePath();
        $primary->files[$path]  = 'a';
        $fallback->files[$path] = 'b';

        $this->assertTrue($storage->delete($path));
        $this->assertFalse($primary->exists($path));
        $this->assertFalse($fallback->exists($path));
        $this->assertFalse($storage->exists($path));
    }

    public function testDiscoPrivadoContinuaFailClosed(): void
    {
        $primary  = new FakeDiskStorage('https://bucket-privado', publiclyServed: false);
        $fallback = new FakeDiskStorage('http://writable-local', publiclyServed: false);
        $storage  = new FallbackStorage($primary, $fallback);

        $path = 'uploads/kyc/' . uniqid('doc_', true) . '.jpg';
        $storage->put($path, $this->makeSourceFile('rg-frente'));

        // Documento KYC existe, mas NUNCA ganha URL pública — nem no composto.
        $this->assertTrue($storage->exists($path));
        $this->assertNull($storage->getPublicUrl($path));
    }

    public function testLeituraResolveArquivoQueSoExisteNoLocal(): void
    {
        $primary  = new FakeDiskStorage('https://cdn.exemplo.com');
        $fallback = new FakeDiskStorage('http://localhost:8080');
        $storage  = new FallbackStorage($primary, $fallback);

        $path = $this->uniquePath();
        $fallback->files[$path] = 'so-no-local';

        $stream = $storage->readStream($path);
        $this->assertIsResource($stream);
        $this->assertSame('so-no-local', stream_get_contents($stream));
        fclose($stream);

        $this->assertSame('http://localhost:8080/' . $path, $storage->getPublicUrl($path));
    }
}

/**
 * Dublê de StorageInterface em memória com falha de gravação simulável.
 */
final class FakeDiskStorage implements StorageInterface
{
    /** @var array<string, string> */
    public array $files = [];

    public function __construct(
        private readonly string $urlPrefix,
        private readonly bool $publiclyServed = true,
        public bool $failPuts = false,
    ) {
    }

    public function put(string $relativePath, string $sourceFile): string
    {
        if ($this->failPuts) {
            throw new \RuntimeException('backend fora do ar (simulado)');
        }

        $this->files[$relativePath] = (string) file_get_contents($sourceFile);
        @unlink($sourceFile);

        return $relativePath;
    }

    public function delete(string $relativePath): bool
    {
        if (! isset($this->files[$relativePath])) {
            return false;
        }
        unset($this->files[$relativePath]);

        return true;
    }

    public function exists(string $relativePath): bool
    {
        return isset($this->files[$relativePath]);
    }

    public function getPublicUrl(string $relativePath): ?string
    {
        return $this->publiclyServed ? $this->urlPrefix . '/' . $relativePath : null;
    }

    public function getSignedUrl(string $relativePath, int $ttlSeconds): ?string
    {
        return null;
    }

    public function readStream(string $relativePath)
    {
        if (! isset($this->files[$relativePath])) {
            return null;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->files[$relativePath]);
        rewind($stream);

        return $stream;
    }
}
