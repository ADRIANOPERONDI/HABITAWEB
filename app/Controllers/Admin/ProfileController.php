<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccountModel;

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

        return view('admin/profile/index', [
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

        if ($file && $file->isValid() && !$file->hasMoved()) {
            $newName = $file->getRandomName();
            $file->move(FCPATH . 'uploads/accounts', $newName);
            $data['logo'] = 'uploads/accounts/' . $newName;
        }

        // --- VERIFICATION DOCUMENTS ---
        $docUploaded = false;
        foreach (['id_front', 'id_back', 'selfie'] as $field) {
            $docFile = $this->request->getFile($field);
            if ($docFile && $docFile->isValid() && !$docFile->hasMoved()) {
                $newName = $docFile->getRandomName();
                $docFile->move(FCPATH . 'uploads/verification', $newName);
                $data[$field] = 'uploads/verification/' . $newName;
                $docUploaded = true;
            }
        }

        // --- LIVENESS FRAMES (BIOMETRY) ---
        $livenessFrames = $this->request->getFiles();
        if (isset($livenessFrames['liveness_frames'])) {
            $capturedPaths = [];
            foreach ($livenessFrames['liveness_frames'] as $frame) {
                if ($frame && $frame->isValid() && !$frame->hasMoved()) {
                    $newName = $frame->getRandomName();
                    $frame->move(FCPATH . 'uploads/verification/liveness', $newName);
                    $capturedPaths[] = 'uploads/verification/liveness/' . $newName;
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
}
