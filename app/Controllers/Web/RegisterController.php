<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Entities\User;

class RegisterController extends BaseController
{
    protected $accountService;

    public function __construct()
    {
        $this->accountService = service('accountService');
    }

    public function index()
    {
        if (auth()->loggedIn()) {
            return redirect()->to('admin/dashboard');
        }
        
        return view('web/auth/register');
    }

    public function process()
    {
        $rules = [
            'nome' => 'required|min_length[3]',
            'email' => 'required|valid_email|is_unique[auth_identities.secret]|professional_email',
            'password' => 'required|min_length[8]|strong_password',
            'tipo_conta' => 'required|in_list[IMOBILIARIA,CORRETOR,PF]',
            'tipo_documento' => 'required|in_list[CPF,CNPJ]',
            'documento' => 'required|valid_documento[tipo_documento]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();

        try {
            $user = $this->accountService->registerUser($data);

            // Login user
            auth()->login($user);

            return redirect()->to('admin')->with('message', 'Conta criada! Escolha um plano para começar.');

        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Endpoint AJAX para verificar se email já existe
     */
    public function checkEmail()
    {
        $email = $this->request->getGet('email');
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setJSON(['exists' => false, 'valid' => false]);
        }
        
        // Verifica na tabela auth_identities via AccountService
        $exists = $this->accountService->emailExists($email);
                     
        return $this->response->setJSON([
            'exists' => $exists, 
            'valid' => true,
            'message' => $exists ? 'Este e-mail já está cadastrado.' : 'E-mail disponível.'
        ]);
    }
}

