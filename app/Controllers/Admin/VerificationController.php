<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccountModel;

class VerificationController extends BaseController
{
    protected $accountModel;

    public function __construct()
    {
        $this->accountModel = new AccountModel();
    }

    /**
     * Lista contas aguardando verificação ou com problemas.
     */
    public function index()
    {
        $status = $this->request->getGet('status') ?: 'PENDING';
        
        $accounts = $this->accountModel
            ->where('verification_status', $status)
            ->orderBy('updated_at', 'DESC')
            ->findAll();

        return view('admin/verification/index', [
            'accounts' => $accounts,
            'currentStatus' => $status
        ]);
    }

    /**
     * Exibe os documentos de uma conta para revisão.
     */
    public function show($id)
    {
        $account = $this->accountModel->find($id);
        
        if (!$account) {
            return redirect()->to('admin/verification')->with('error', 'Conta não encontrada.');
        }

        return view('admin/verification/show', [
            'account' => $account
        ]);
    }

    /**
     * Aprova ou rejeita uma verificação.
     */
    public function update($id)
    {
        $account = $this->accountModel->find($id);
        if (!$account) {
            return redirect()->to('admin/verification')->with('error', 'Conta não encontrada.');
        }

        $action = $this->request->getPost('action'); // APPROVE or REJECT
        $notes  = $this->request->getPost('notes');

        if ($action === 'APPROVE') {
            $data = [
                'verification_status' => 'APPROVED',
                'is_verified' => true,
                'verification_notes' => 'Aprovado em ' . date('d/m/Y H:i')
            ];
            $message = 'Verificação aprovada com sucesso!';
        } else {
            $data = [
                'verification_status' => 'REJECTED',
                'is_verified' => false,
                'verification_notes' => $notes ?: 'Documentos inválidos ou ilegíveis.'
            ];
            $message = 'Verificação rejeitada.';
        }

        if ($this->accountModel->update($id, $data)) {
            
            // Log de auditoria (opcional, mas bom ter)
            log_message('notice', "[Verification] Admin " . auth()->user()->username . " alterou status da conta #{$id} para {$data['verification_status']}");

            return redirect()->to('admin/verification')->with('message', $message);
        }

        return redirect()->back()->with('error', 'Erro ao processar verificação.');
    }
}
