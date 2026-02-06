<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Entities\User;

class RegisterController extends BaseController
{
    protected $db;
    protected $accountModel;
    protected $subscriptionModel;
    protected $planModel;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->accountModel = model('App\Models\AccountModel');
        $this->subscriptionModel = model('App\Models\SubscriptionModel');
        $this->planModel = model('App\Models\PlanModel');
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

        // 1. Transaction Start
        $this->db->transStart();

        try {
            // 2. Create Account
            $accountData = [
                'nome' => $data['nome'],
                'tipo_conta' => $data['tipo_conta'], // Ensure matches DB enum/varchar
                'documento' => $data['documento'],
                'documento' => $data['documento'],
                'status' => 'PENDING', // Payment required before activation
                'email' => $data['email']
            ];
            
            $this->accountModel->insert($accountData);
            $accountId = $this->accountModel->getInsertID();

            // 3. Create User (Shield)
            $users = model('App\Models\UserModel'); // Use custom model with account_id
            $user = new User([
                'username' => explode('@', $data['email'])[0] . rand(100,999),
                'email'    => $data['email'],
                'password' => $data['password'],
                'active'   => 1,
                'account_id' => $accountId
            ]);
            
            $users->save($user);
            $userId = $users->getInsertID();
            
            // Force verify ID
            if (!$userId) {
                throw new \Exception("Erro ao gerar ID do usuário.");
            }
            $user->id = $userId; // Explicitly set ID for addGroup usage

            // 4. Assign Group based on Type
            $group = 'user'; // Default PF
            if ($data['tipo_conta'] === 'IMOBILIARIA') {
                $group = 'imobiliaria_admin';
            } elseif ($data['tipo_conta'] === 'CORRETOR') {
                $group = 'imobiliaria_corretor'; // Or separate group if needed
            }
            
            $user->addGroup($group);

            // 5. Subscription is NOT created automatically anymore.
            // User must select a plan and pay to active the account/subscription.
            // Redirect to plan selection handles this.

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new \Exception("Erro ao criar conta.");
            }

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
        
        // Verifica na tabela auth_identities (Shield)
        $db = \Config\Database::connect();
        $exists = $db->table('auth_identities')
                     ->where('type', 'email_password')
                     ->where('secret', $email)
                     ->countAllResults() > 0;
                     
        return $this->response->setJSON([
            'exists' => $exists, 
            'valid' => true,
            'message' => $exists ? 'Este e-mail já está cadastrado.' : 'E-mail disponível.'
        ]);
    }
}

