<?php

namespace App\Libraries\Storage;

/**
 * Contrato único de armazenamento de arquivos enviados (imagens de imóvel,
 * logos, documentos de KYC, frames de biometria).
 *
 * Motivação: com múltiplas instâncias de app atrás de um load balancer, um
 * arquivo gravado no disco local de uma instância não existe nas outras. Todo
 * ponto de upload/leitura/exclusão passa por esta interface para que o backend
 * possa ser trocado (disco local hoje, S3/NFS depois) sem tocar nos call sites.
 *
 * Convenções que os backends DEVEM respeitar:
 *  - Caminhos são sempre relativos ao "disco" (ex.: uploads/properties/1/x.jpg),
 *    nunca absolutos — o mesmo valor gravado no banco hoje.
 *  - Validação de conteúdo (MIME real, dimensões, EXIF) acontece ANTES do put(),
 *    na camada de serviço/controller, sobre o arquivo temporário — o storage só
 *    recebe bytes já validados. Nunca mover essa validação para dentro de um
 *    backend específico.
 *  - Um disco privado (documentos KYC) NUNCA pode retornar URL pública:
 *    getPublicUrl() retorna null — fail-closed por contrato, não por convenção.
 */
interface StorageInterface
{
    /**
     * Grava o arquivo de origem (caminho local, tipicamente o tmp do upload já
     * validado/pós-processado) no caminho relativo do disco. Retorna o caminho
     * relativo final (o que deve ser persistido no banco).
     *
     * O arquivo de origem é consumido (removido) após a gravação bem-sucedida.
     *
     * @throws \RuntimeException se a gravação falhar.
     */
    public function put(string $relativePath, string $sourceFile): string;

    public function delete(string $relativePath): bool;

    public function exists(string $relativePath): bool;

    /**
     * URL pública permanente do arquivo, ou null se este disco não serve
     * arquivos publicamente (disco privado — KYC).
     */
    public function getPublicUrl(string $relativePath): ?string;

    /**
     * URL assinada de curta duração para leitura, ou null se o backend não
     * suporta (disco local serve via proxy autenticado — ver KycFileController).
     */
    public function getSignedUrl(string $relativePath, int $ttlSeconds): ?string;

    /**
     * Stream de leitura (resource) ou null se o arquivo não existe.
     * Usado pelo proxy autenticado de documentos KYC.
     *
     * @return resource|null
     */
    public function readStream(string $relativePath);
}
