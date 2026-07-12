<?php

namespace Tests\Support;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * UploadedFile::isValid()/move() checam is_uploaded_file()/move_uploaded_file(),
 * que só retornam true para arquivos que vieram de um POST HTTP real via SAPI do
 * PHP — nunca verdadeiro em CLI/teste, mesmo com um caminho de arquivo válido.
 * Esta subclasse troca essas checagens por copy()/rename() normais, para poder
 * testar App\Services\PropertyService::addMedia() com fixtures reais em disco.
 */
class TestUploadedFile extends UploadedFile
{
    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK;
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $targetPath = rtrim($targetPath, '/') . '/';
        if (! is_dir($targetPath)) {
            mkdir($targetPath, 0755, true);
        }

        $name ??= $this->getName();
        $destination = $overwrite ? $targetPath . $name : $targetPath . $name;

        if (! copy($this->getTempName(), $destination)) {
            throw new \RuntimeException("TestUploadedFile: falha ao copiar para {$destination}");
        }

        return true;
    }
}
