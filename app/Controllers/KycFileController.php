<?php

namespace App\Controllers;

use App\Models\AccountModel;

/**
 * Serve documentos sensíveis de KYC (RG frente/verso, selfie) apenas para usuários
 * autorizados — o próprio dono da conta ou um revisor interno (superadmin/admin).
 *
 * Os arquivos NÃO ficam acessíveis por URL direta: novos uploads são gravados fora
 * do webroot (WRITEPATH) e os legados (ainda em public/uploads/verification) são
 * bloqueados por .htaccess e servidos aqui via leitura de disco.
 */
class KycFileController extends BaseController
{
    /** Campos de documento que podem ser servidos por esta rota. */
    private array $allowedFields = ['id_front', 'id_back', 'selfie'];

    public function show(int $accountId, string $field)
    {
        // Whitelist do campo (evita acessar colunas arbitrárias via URL).
        // Além dos documentos, aceita liveness_{N}: frame N do array JSON em
        // accounts.liveness_data — os frames ficam no disco privado e só podem
        // sair por este proxy, nunca por URL direta.
        $livenessIndex = null;
        if (preg_match('/^liveness_(\d{1,2})$/', $field, $m)) {
            $livenessIndex = (int) $m[1];
        } elseif (! in_array($field, $this->allowedFields, true)) {
            return $this->response->setStatusCode(404);
        }

        $me = auth()->user();
        if (! $me) {
            return $this->response->setStatusCode(403);
        }

        // Autorização: dono da conta OU revisor interno.
        $isReviewer = $me->inGroup('superadmin', 'admin');
        if (! $isReviewer && (int) $me->account_id !== $accountId) {
            return $this->response->setStatusCode(403);
        }

        $account = model(AccountModel::class)->find($accountId);
        if (! $account) {
            return $this->response->setStatusCode(404);
        }

        if ($livenessIndex !== null) {
            $frames = json_decode((string) ($account->liveness_data ?? ''), true);
            $stored = is_array($frames) ? ltrim((string) ($frames[$livenessIndex] ?? ''), '/') : '';
        } else {
            $stored = ltrim((string) ($account->{$field} ?? ''), '/');
        }

        if ($stored === '') {
            return $this->response->setStatusCode(404);
        }

        // Resolve via storage abstrato: disco privado primeiro (uploads novos,
        // fora do webroot), depois o disco público como fallback legado —
        // restrito a uploads/ (arquivos antigos de public/uploads/verification),
        // preservando a mesma contenção do realpath-check anterior. A defesa
        // contra path traversal ("..", null byte, symlink pra fora do baseDir)
        // agora mora em LocalStorage::sanitize()/resolveExisting().
        $disks = [service('privateStorage')];
        if (str_starts_with($stored, 'uploads/')) {
            $disks[] = service('publicStorage');
        }

        foreach ($disks as $disk) {
            $stream = $disk->readStream($stored);
            if ($stream !== null) {
                $body = (string) stream_get_contents($stream);
                fclose($stream);

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->buffer($body) ?: 'application/octet-stream';

                return $this->response
                    ->setHeader('Content-Type', $mime)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->setHeader('X-Content-Type-Options', 'nosniff')
                    ->setBody($body);
            }
        }

        return $this->response->setStatusCode(404);
    }
}
