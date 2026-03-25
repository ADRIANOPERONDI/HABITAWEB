<?php

namespace App\Services;

use App\Models\AccountModel;
use CodeIgniter\Files\File;

/**
 * KYCService - Gerencia verificação de identidade (Know Your Customer)
 * 
 * Funcionalidades:
 * - Validação de documentos (frente, verso)
 * - Verificação facial (liveness detection)
 * - Gerenciamento de status de verificação
 * - Cache de verificações
 */
class KYCService
{
    protected $logger;
    protected $accountModel;
    protected $minImageWidth = 640;
    protected $minImageHeight = 480;
    protected $maxFileSize = 5242880; // 5MB
    protected $allowedMimeTypes = ['image/jpeg', 'image/png'];

    public function __construct()
    {
        $this->logger = service('logger');
        $this->accountModel = model('App\Models\AccountModel');
    }

    /**
     * Validar e processar upload de documentos (frente e verso)
     * 
     * @param int $accountId
     * @param array $files ['id_front' => File, 'id_back' => File]
     * @return array [success => bool, message => string, data => array]
     */
    public function validateAndStoreDocuments(int $accountId, array $files): array
    {
        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return [
                'success' => false,
                'message' => 'Conta não encontrada',
                'data' => []
            ];
        }

        $uploadResults = [];

        // Validar frente
        if (isset($files['id_front'])) {
            $frontResult = $this->_validateAndStoreImage($files['id_front'], 'id_front', $accountId);
            if (!$frontResult['success']) {
                return $frontResult;
            }
            $uploadResults['id_front'] = $frontResult['path'];
        }

        // Validar verso
        if (isset($files['id_back'])) {
            $backResult = $this->_validateAndStoreImage($files['id_back'], 'id_back', $accountId);
            if (!$backResult['success']) {
                return $backResult;
            }
            $uploadResults['id_back'] = $backResult['path'];
        }

        // Atualizar conta com paths
        try {
            $this->accountModel->update($accountId, [
                'id_front' => $uploadResults['id_front'] ?? $account->id_front,
                'id_back' => $uploadResults['id_back'] ?? $account->id_back,
                'verification_status' => 'PENDING', // Transição para PENDING após upload
            ]);

            $this->logger->info("[KYC] Documentos armazenados para account_id={$accountId}");

            return [
                'success' => true,
                'message' => 'Documentos armazenados com sucesso',
                'data' => $uploadResults
            ];
        } catch (\Exception $e) {
            $this->logger->error("[KYC] Erro ao salvar paths de documentos: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao processar documentos',
                'data' => []
            ];
        }
    }

    /**
     * Validar e processar upload de selfie
     * 
     * @param int $accountId
     * @param File $file
     * @return array [success => bool, message => string, path => string]
     */
    public function validateAndStoreSelfie(int $accountId, File $file): array
    {
        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return [
                'success' => false,
                'message' => 'Conta não encontrada'
            ];
        }

        $result = $this->_validateAndStoreImage($file, 'selfie', $accountId);
        if (!$result['success']) {
            return $result;
        }

        try {
            $this->accountModel->update($accountId, [
                'selfie' => $result['path'],
                'verification_status' => 'PENDING',
            ]);

            $this->logger->info("[KYC] Selfie armazenada para account_id={$accountId}");

            return [
                'success' => true,
                'message' => 'Selfie armazenada com sucesso',
                'path' => $result['path']
            ];
        } catch (\Exception $e) {
            $this->logger->error("[KYC] Erro ao salvar selfie: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao processar selfie'
            ];
        }
    }

    /**
     * Executar verificação de reconhecimento facial (liveness detection)
     * 
     * IMPORTANTE: Esta é uma integração de MOCK. Em produção, integre com:
     * - AWS Rekognition
     * - Jumio
     * - Ou outro provider especializado em KYC
     * 
     * @param int $accountId
     * @param array $options [provider => 'mock|aws|jumio', threshold => 0.9]
     * @return array [success => bool, message => string, livenessData => array]
     */
    public function verifyFacialLiveness(int $accountId, array $options = []): array
    {
        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return [
                'success' => false,
                'message' => 'Conta não encontrada',
                'livenessData' => []
            ];
        }

        // Validar pré-requisitos
        if (empty($account->selfie) || empty($account->id_front)) {
            return [
                'success' => false,
                'message' => 'Selfie e documentação are required para verificação facial',
                'livenessData' => []
            ];
        }

        $provider = $options['provider'] ?? env('KYC_LIVENESS_PROVIDER', 'mock');
        $threshold = $options['threshold'] ?? 0.90;

        try {
            switch ($provider) {
                case 'mock':
                    return $this->_verifyLivenessMock($accountId, $account, $threshold);
                case 'aws':
                    return $this->_verifyLivenessAWS($accountId, $account, $threshold);
                case 'jumio':
                    return $this->_verifyLivenessJumio($accountId, $account, $threshold);
                default:
                    return [
                        'success' => false,
                        'message' => "Provedor '{$provider}' não suportado",
                        'livenessData' => []
                    ];
            }
        } catch (\Exception $e) {
            $this->logger->error("[KYC] Erro em verificação facial: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro na verificação facial',
                'livenessData' => []
            ];
        }
    }

    /**
     * Marcar conta como verificada ou rejeitada
     * 
     * @param int $accountId
     * @param string $status 'VERIFIED' ou 'REJECTED'
     * @param string $notes Notas opcionais
     * @return bool
     */
    public function markAccountVerified(int $accountId, string $status = 'VERIFIED', string $notes = ''): bool
    {
        if (!in_array($status, ['VERIFIED', 'REJECTED', 'EXPIRED'])) {
            $this->logger->warning("[KYC] Status inválido tentando marcar: {$status}");
            return false;
        }

        try {
            $updateData = [
                'verification_status' => $status,
                'is_verified' => ($status === 'VERIFIED'),
            ];

            if (!empty($notes)) {
                $updateData['verification_notes'] = $notes;
            }

            $this->accountModel->update($accountId, $updateData);
            $this->logger->info("[KYC] Conta {$accountId} marcada como {$status}");

            return true;
        } catch (\Exception $e) {
            $this->logger->error("[KYC] Erro ao marcar verificação: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verificar se uma conta está totalmente verificada
     * 
     * @param int $accountId
     * @return bool
     */
    public function isAccountFullyVerified(int $accountId): bool
    {
        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return false;
        }

        return !empty($account->id_front)
               && !empty($account->id_back)
               && !empty($account->selfie)
               && $account->is_verified === true
               && $account->verification_status === 'VERIFIED';
    }

    /**
     * Obter status legível de verificação
     * 
     * @param int $accountId
     * @return string
     */
    public function getVerificationStatus(int $accountId): string
    {
        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return 'NÃO ENCONTRADO';
        }

        $statusLabels = [
            'NONE' => 'Não iniciado',
            'PENDING' => 'Pendente de revisão',
            'VERIFIED' => 'Verificado',
            'REJECTED' => 'Rejeitado',
            'EXPIRED' => 'Expirado - Re-verificação requerida',
        ];

        return $statusLabels[$account->verification_status] ?? 'Desconhecido';
    }

    // ================== PRIVATE METHODS ==================

    /**
     * Validar imagem individual (dimensões, tipo, tamanho, EXIF)
     * 
     * @param File $file
     * @param string $fieldName
     * @param int $accountId
     * @return array [success => bool, message => string, path => string]
     */
    private function _validateAndStoreImage(File $file, string $fieldName, int $accountId): array
    {
        // 1. Validar MIME type
        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            return [
                'success' => false,
                'message' => "Tipo de arquivo inválido para {$fieldName}. Aceitos: JPEG, PNG"
            ];
        }

        // 2. Validar tamanho
        if ($file->getSize() > $this->maxFileSize) {
            return [
                'success' => false,
                'message' => "Arquivo {$fieldName} é muito grande. Máximo: 5MB"
            ];
        }

        // 3. Validar dimensões
        $imageInfo = getimagesize($file->getTempName());
        if ($imageInfo === false) {
            return [
                'success' => false,
                'message' => "Arquivo {$fieldName} não é uma imagem válida"
            ];
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];

        if ($width < $this->minImageWidth || $height < $this->minImageHeight) {
            return [
                'success' => false,
                'message' => "Imagem {$fieldName} muito pequena. Mínimo: {$this->minImageWidth}x{$this->minImageHeight}px"
            ];
        }

        // 4. Remover EXIF data (privacidade)
        try {
            $this->_removeEXIFData($file->getTempName());
        } catch (\Exception $e) {
            $this->logger->warning("[KYC] Falha ao remover EXIF: " . $e->getMessage());
            // Não falha o upload se EXIF falhar
        }

        // 5. Salvar arquivo
        $uploadPath = WRITEPATH . 'uploads/kyc/' . $accountId;
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $newName = $fieldName . '_' . time() . '.' . $file->getExtension();
        try {
            $file->move($uploadPath, $newName);
            $relativePath = 'uploads/kyc/' . $accountId . '/' . $newName;

            $this->logger->info("[KYC] Imagem {$fieldName} upload para account_id={$accountId}");

            return [
                'success' => true,
                'message' => ucfirst($fieldName) . ' uploaded com sucesso',
                'path' => $relativePath
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao salvar arquivo: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Remover dados EXIF de imagem (privacidade)
     * 
     * @param string $imagePath
     * @return bool
     */
    private function _removeEXIFData(string $imagePath): bool
    {
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        if ($extension === 'jpeg' || $extension === 'jpg') {
            if (extension_loaded('exif')) {
                // Se imagick está disponível, usar como melhor prática
                if (extension_loaded('imagick')) {
                    $image = new \Imagick($imagePath);
                    $image->stripImage();
                    $image->writeImage($imagePath);
                    return true;
                }

                // Fallback: carregar e recodificar sem EXIF
                $image = imagecreatefromjpeg($imagePath);
                imagejpeg($image, $imagePath, 90);
                imagedestroy($image);
                return true;
            }
        } elseif ($extension === 'png') {
            if (extension_loaded('imagick')) {
                $image = new \Imagick($imagePath);
                $image->stripImage();
                $image->writeImage($imagePath);
                return true;
            }
        }

        return false; // Não conseguiu remover, mas não falha
    }

    /**
     * [MOCK] Verificação facial de teste - simula API de liveness
     * 
     * @param int $accountId
     * @param object $account
     * @param float $threshold
     * @return array
     */
    private function _verifyLivenessMock(int $accountId, object $account, float $threshold): array
    {
        // Simular verificação com 90% de sucesso
        $success = mt_rand(1, 100) <= 90;

        $livenessData = [
            'provider' => 'mock',
            'timestamp' => date('Y-m-d H:i:s'),
            'account_id' => $accountId,
            'success' => $success,
            'confidence' => $success ? mt_rand(90, 99) / 100 : mt_rand(10, 50) / 100,
            'message' => $success ? 'Liveness check passed' : 'Liveness check failed - Please try again',
        ];

        if ($success) {
            $this->markAccountVerified($accountId, 'VERIFIED', 'Mock liveness verification passed');
        }

        return [
            'success' => $success,
            'message' => $livenessData['message'],
            'livenessData' => $livenessData
        ];
    }

    /**
     * [AWS Rekognition] Integração com AWS - placeholder para produção
     * 
     * @param int $accountId
     * @param object $account
     * @param float $threshold
     * @return array
     */
    private function _verifyLivenessAWS(int $accountId, object $account, float $threshold): array
    {
        // TODO: Implementar integração real com AWS Rekognition
        // 1. Inicializar cliente AWS SDK
        // 2. Chamar CompareFaces() entre selfie e ID
        // 3. Se similarity > threshold, retornar sucesso
        // 4. Else, retornar falha

        $this->logger->warning('[KYC] AWS Rekognition ainda não implementado, usando mock');
        return $this->_verifyLivenessMock($accountId, $account, $threshold);
    }

    /**
     * [Jumio] Integração com Jumio - placeholder para produção
     * 
     * @param int $accountId
     * @param object $account
     * @param float $threshold
     * @return array
     */
    private function _verifyLivenessJumio(int $accountId, object $account, float $threshold): array
    {
        // TODO: Implementar integração real com Jumio KYC API
        // 1. Upload de imagens para Jumio
        // 2. Aguardar callback webhook
        // 3. Processar resultado

        $this->logger->warning('[KYC] Jumio ainda não implementado, usando mock');
        return $this->_verifyLivenessMock($accountId, $account, $threshold);
    }
}
