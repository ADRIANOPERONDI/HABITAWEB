<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccountModel;
use Throwable;

class ProfileController extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        $accountModel = new AccountModel();
        
        $account = $accountModel->find($user->account_id);
        
        if (!$account) {
            return redirect()->to('admin')->with('error', 'Conta não encontrada.');
        }

        return view('Admin/profile/index', [
            'account' => $account,
            'user'    => $user
        ]);
    }

    public function update()
    {
        $user = auth()->user();
        $accountModel = new AccountModel();
        
        $account = $accountModel->find($user->account_id);
        if (!$account) {
            return redirect()->back()->with('error', 'Conta não encontrada.');
        }

        $data = $this->request->getPost([
            'nome', 'email', 'telefone', 'whatsapp', 'creci', 'documento'
        ]);

        $userNome = $this->request->getPost('user_nome');
        if ($userNome) {
            $userModel = model('App\Models\UserModel');
            $userModel->update($user->id, ['nome' => $userNome]);
        }

        $file = $this->request->getFile('logo');

        if ($error = $this->validateImageUpload($file, 6144, 'Logo')) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        if ($file && $file->isValid() && !$file->hasMoved()) {
            $data['logo'] = $this->moveAndOptimizeImage($file, 'uploads/accounts', 800, 800, 82);
        }

        // --- VERIFICATION DOCUMENTS ---
        $docUploaded = false;
        foreach (['id_front', 'id_back', 'selfie'] as $field) {
            $docFile = $this->request->getFile($field);

            if ($error = $this->validateImageUpload($docFile, 10240, strtoupper($field))) {
                return redirect()->back()->withInput()->with('error', $error);
            }

            if ($docFile && $docFile->isValid() && !$docFile->hasMoved()) {
                $data[$field] = $this->moveAndOptimizeImage($docFile, 'uploads/verification', 1600, 1600, 80);
                $docUploaded = true;
            }
        }

        // --- LIVENESS FRAMES (BIOMETRY) ---
        $livenessFrames = $this->request->getFiles();
        if (isset($livenessFrames['liveness_frames'])) {
            $capturedPaths = [];
            foreach ($livenessFrames['liveness_frames'] as $frame) {
                if ($error = $this->validateImageUpload($frame, 4096, 'Frame de biometria')) {
                    return redirect()->back()->withInput()->with('error', $error);
                }

                if ($frame && $frame->isValid() && !$frame->hasMoved()) {
                    $capturedPaths[] = $this->moveAndOptimizeImage($frame, 'uploads/verification/liveness', 900, 900, 75);
                }
            }
            if (!empty($capturedPaths)) {
                $data['liveness_data'] = json_encode($capturedPaths);
                $docUploaded = true;
            }
        }

        // Se enviou novos documentos e não está APROVADO, muda para PENDING
        if ($docUploaded && $account->verification_status !== 'APPROVED') {
            $data['verification_status'] = 'PENDING';
        }

        if ($accountModel->update($account->id, $data)) {
            return redirect()->back()->with('message', 'Perfil atualizado com sucesso!');
        }

        return redirect()->back()->with('error', 'Erro ao atualizar perfil.');
    }

    private function validateImageUpload($file, int $maxKb, string $label): ?string
    {
        if (! $file || ! $file->isValid() || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, $allowedMime, true)) {
            return $label . ': formato inválido. Envie JPG, PNG ou WEBP.';
        }

        if ($file->getSizeByUnit('kb') > $maxKb) {
            return $label . ': arquivo muito grande. Máximo de ' . $maxKb . 'KB.';
        }

        return null;
    }

    private function moveAndOptimizeImage($file, string $relativeDir, int $maxWidth, int $maxHeight, int $quality): string
    {
        $newName = $file->getRandomName();
        $absoluteDir = FCPATH . $relativeDir;
        $file->move($absoluteDir, $newName);

        $relativePath = $relativeDir . '/' . $newName;
        $absolutePath = FCPATH . $relativePath;

        try {
            $image = \Config\Services::image('gd');

            // Reduz a imagem preservando proporção para aliviar payload e armazenamento.
            $image->withFile($absolutePath)
                ->resize($maxWidth, $maxHeight, true, 'auto')
                ->save($absolutePath, $quality);
        } catch (Throwable $e) {
            log_message('warning', '[ProfileController] Falha ao otimizar imagem: ' . $e->getMessage());
        }

        return $relativePath;
    }
}
