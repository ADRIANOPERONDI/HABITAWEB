<?php

namespace App\Controllers\Install;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

class InstallController extends BaseController
{
    public function index()
    {
        if ($this->isInstalled()) {
            return view('install/already_installed');
        }

        // Redireciona para step 1
        return redirect()->to('/install/step/1');
    }

    public function step($stepNumber)
    {
        if ($this->isInstalled()) {
            return redirect()->to('/');
        }

        $data = [
            'currentStep' => (int)$stepNumber,
            'formData' => session('install_data') ?? []
        ];

        switch ($stepNumber) {
            case 1:
                return view('install/step1', $data);
            case 2:
                return view('install/step2', $data);
            case 3:
                return view('install/step3', $data);
            case 4:
                return view('install/step4', $data);
            case 5:
                return view('install/step5', $data);
            default:
                return redirect()->to('/install/step/1');
        }
    }

    public function testDatabase()
    {
        // Headers JSON para resposta
        $this->response->setHeader('Content-Type', 'application/json');
        
        // jQuery envia como form-data, não JSON
        $config = $this->request->getPost();

        try {
            $dbDriver = $config['db_driver'] ?? 'Postgre';
            $port = $config['db_port'] ?? ($dbDriver == 'MySQLi' ? 3306 : 5432);
            
            $customDb = db_connect([
                'hostname' => $config['db_host'] ?? '',
                'username' => $config['db_user'] ?? '',
                'password' => $config['db_pass'] ?? '',
                'database' => $config['db_name'] ?? '',
                'DBDriver' => $dbDriver,
                'port'     => $port,
                'charset'  => 'utf8',
                'DBCollat' => '',
            ]);

            // Tenta fazer uma query simples
            $customDb->query('SELECT 1');
            
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Conexão estabelecida com sucesso!'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Falha na conexão: ' . $e->getMessage()
            ]);
        }
    }

    public function saveStep()
    {
        $step = $this->request->getPost('step');
        $installData = session('install_data') ?? [];

        // Mescla dados do step atual
        $installData = array_merge($installData, $this->request->getPost());

        // Salva na sessão
        session()->set('install_data', $installData);

        // Redireciona para próximo step
        $nextStep = (int)$step + 1;
        return redirect()->to("/install/step/{$nextStep}");
    }

    public function process()
    {
        if ($this->isInstalled()) {
            return redirect()->to('/');
        }

        $installData = session('install_data') ?? [];

        if (empty($installData)) {
            return redirect()->to('/install/step/1')->with('error', 'Dados de instalação não encontrados.');
        }

        try {
            // 1. Remover .env antigo se existir
            if (file_exists(ROOTPATH . '.env')) {
                unlink(ROOTPATH . '.env');
            }
            
            // 2. Criar arquivo .env
            $this->createEnvFile($installData);
            
            // 3. Salvar dados de instalação em arquivo temporário
            file_put_contents(WRITEPATH . '.install-pending', json_encode($installData));
            
            // 4. Retornar view com meta refresh para forçar novo request (reload do .env)
            return view('install/finalizing');

        } catch (\Exception $e) {
            log_message('error', 'Erro na instalação: ' . $e->getMessage());
            return redirect()->to('/install/step/1')->with('error', 'Erro durante instalação: ' . $e->getMessage());
        }
    }
    
    public function finalize()
    {
        log_message('info', '=== FINALIZE INICIADO ===');
        
        // Verifica se tem instalação pendente
        if (!file_exists(WRITEPATH . '.install-pending')) {
            log_message('error', 'Arquivo .install-pending não encontrado!');
            return redirect()->to('/');
        }
        
        log_message('info', 'Arquivo .install-pending encontrado');
        
        try {
            // Lê dados da instalação pendente
            $installData = json_decode(file_get_contents(WRITEPATH . '.install-pending'), true);
            log_message('info', 'Dados de instalação carregados');
            
            // Agora o .env JÁ FOI CARREGADO pelo CodeIgniter, podemos executar migrations
            $db = db_connect(); // Usa configuração do .env novo
            log_message('info', 'Conexão com banco estabelecida');
            
            // 1. Executar Migrations do Shield primeiro (cria tabela users)
            log_message('info', 'Iniciando migrations do Shield...');
            $shieldConfig = new \Config\Migrations();
            $shieldConfig->enabled = true;
            
            $shieldMigrate = new \CodeIgniter\Database\MigrationRunner($shieldConfig, $db);
            $shieldMigrate->setNamespace('CodeIgniter\Shield');
            $shieldMigrate->latest();
            log_message('info', 'Migrations do Shield concluídas');
            
            // 2. Executar Migrations do Settings (cria tabela settings)
            log_message('info', 'Iniciando migrations do Settings...');
            $settingsConfig = new \Config\Migrations();
            $settingsConfig->enabled = true;
            
            $settingsMigrate = new \CodeIgniter\Database\MigrationRunner($settingsConfig, $db);
            $settingsMigrate->setNamespace('CodeIgniter\Settings');
            $settingsMigrate->latest();
            log_message('info', 'Migrations do Settings concluídas');
            
            // 3. Executar Migrations do App (adiciona account_id)
            log_message('info', 'Iniciando migrations do App...');
            $config = new \Config\Migrations();
            $config->enabled = true;
            
            $migrate = new \CodeIgniter\Database\MigrationRunner($config, $db);
            $migrate->latest();
            log_message('info', 'Migrations do App concluídas');

            // 4. Atualizar configurações do sistema no banco
            log_message('info', 'Atualizando configurações do sistema...');
            $this->updateSystemSettings($installData, $db);
            log_message('info', 'Configurações atualizadas');

            // 5. Criar conta e usuário admin
            log_message('info', 'Criando conta e usuário admin...');
            $this->createAdminUser($installData, $db);
            log_message('info', 'Admin criado');

            // 6. Executar seeders (planos, etc)
            log_message('info', 'Executando seeders...');
            $seeder = \Config\Database::seeder();
            $seeder->setPath(APPPATH . 'Database/Seeds');
            $seeder->call('PlanSeeder');
            $seeder->call('PaymentGatewaysSeeder');
            log_message('info', 'Seeders concluídos');

            // 7. Criar arquivo de bloqueio
            log_message('info', 'Criando arquivo de bloqueio...');
            $this->createLockFile();
            log_message('info', 'Arquivo de bloqueio criado');
            
            // 8. Remover arquivo temporário
            unlink(WRITEPATH . '.install-pending');
            log_message('info', 'Arquivo temporário removido');

            // Limpar sessão
            session()->remove('install_data');
            log_message('info', '=== INSTALAÇÃO CONCLUÍDA COM SUCESSO ===');

            return view('install/completed', [
                'admin_email' => $installData['admin_email']
            ]);

        } catch (DatabaseException $e) {
            log_message('error', '!!! ERRO DE BANCO NA FINALIZAÇÃO: ' . $e->getMessage());
            return redirect()->to('/install/step/1')->with('error', 'Erro de banco: ' . $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', '!!! ERRO NA FINALIZAÇÃO: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return redirect()->to('/install/step/1')->with('error', 'Erro fatal: ' . $e->getMessage());
        }
    }

    protected function isInstalled(): bool
    {
        return file_exists(WRITEPATH . '.installed');
    }

    protected function createEnvFile(array $data)
    {
        $dbDriver = $data['db_driver'] ?? 'Postgre';
        $dbPort = $data['db_port'] ?? ($dbDriver == 'MySQLi' ? 3306 : 5432);
        
        $envTemplate = "# CONFIGURAÇÕES GERADAS PELO INSTALADOR
# Data: " . date('Y-m-d H:i:s') . "

CI_ENVIRONMENT = production

# Database
database.default.hostname = {$data['db_host']}
database.default.database = {$data['db_name']}
database.default.username = {$data['db_user']}
database.default.password = {$data['db_pass']}
database.default.DBDriver = {$dbDriver}
database.default.port = {$dbPort}
database.default.charset = utf8
database.default.DBDebug = true


# App
app.baseURL = '{$data['base_url']}'

# Encryption
encryption.key = " . bin2hex(random_bytes(16)) . "

# Session
app.sessionDriver = 'CodeIgniter\\\\Session\\\\Handlers\\\\FileHandler'
app.sessionSavePath = null

# Email (Configure depois)
email.protocol = mail
email.fromEmail = {$data['site_email']}
email.fromName = {$data['site_name']}

# Asaas (Sandbox - Configure para produção depois)
ASAAS_ENV=sandbox
ASAAS_API_KEY=your_key_here
ASAAS_WEBHOOK_TOKEN=your_webhook_token_here
";

        file_put_contents(ROOTPATH . '.env', $envTemplate);
        @chmod(ROOTPATH . '.env', 0600); // Permissão restrita
    }

    protected function reloadDatabaseConfig(array $data)
    {
        // Força reconexão com novas credenciais
        $newConfig = [
            'hostname' => $data['db_host'],
            'username' => $data['db_user'],
            'password' => $data['db_pass'],
            'database' => $data['db_name'],
            'DBDriver' => 'Postgre',
            'port'     => $data['db_port'] ?? 5432,
            'charset'  => 'utf8',
            'DBCollat' => '',
        ];

        // Substitui conexão padrão
        $db = db_connect($newConfig);
        \Config\Database::connect($newConfig, false);
    }

    protected function updateSystemSettings(array $data, $db)
    {
        // Atualiza settings básicos (assumindo que migrations já criaram a tabela)
        $db->table('system_settings')->where('key', 'site.name')->update(['value' => $data['site_name']]);
        $db->table('system_settings')->where('key', 'site.email')->update(['value' => $data['site_email']]);
        $db->table('system_settings')->where('key', 'seo.title')->update(['value' => $data['site_name'] . ' - Encontre seu lugar']);
    }

    protected function createAdminUser(array $data, $db)
    {
        // 1. Criar conta ADMIN
        $db->table('accounts')->insert([
            'nome'       => 'Administrador',
            'email'      => $data['admin_email'],
            'tipo_conta' => 'ADMIN',
            'status'     => 'ACTIVE',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $accountId = $db->insertID();

        // 2. Criar usuário Shield
        $userModel = new UserModel();
        $user = new User([
            'username' => explode('@', $data['admin_email'])[0],
            'email'    => $data['admin_email'],
            'password' => $data['admin_password'],
            'active'   => 1,
        ]);

        $userModel->save($user);
        $userId = $userModel->getInsertID();

        // 3. Vincular à conta
        $db->table('users')->where('id', $userId)->update(['account_id' => $accountId]);

        // 4. Adicionar ao grupo superadmin direto na tabela (sem usar addGroup que depende de settings)
        $db->table('auth_groups_users')->insert([
            'user_id'  => $userId,
            'group'    => 'superadmin',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function createLockFile()
    {
        file_put_contents(WRITEPATH . '.installed', date('Y-m-d H:i:s'));
    }
}
