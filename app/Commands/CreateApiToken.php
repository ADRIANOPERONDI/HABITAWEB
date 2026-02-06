<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Models\UserModel;

class CreateApiToken extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:create-token';
    protected $description = 'Gera um Token de API a um usuário.';
    protected $usage       = 'portal:create-token [email] [nome_token]';
    protected $arguments   = [
        'email'      => 'Email do usuário',
        'nome_token' => 'Nome para identificar o token',
    ];

    public function run(array $params)
    {
        $email = array_shift($params);
        $tokenName = array_shift($params) ?? 'default_token';

        if (!$email) {
            $email = CLI::prompt('Email do usuário');
        }

        $userModel = new UserModel();
        $user = $userModel->findByCredentials(['email' => $email]);

        if (!$user) {
            CLI::error("Usuário com email '$email' não encontrado.");
            return;
        }

        // Gera o token
        $token = $user->generateAccessToken($tokenName);

        CLI::write("Token gerado com sucesso!", 'green');
        CLI::write("Usuário: " . $user->email, 'white');
        CLI::write("Nome do Token: $tokenName", 'white');
        CLI::write("Token (Copie agora, não será mostrado novamente):", 'yellow');
        CLI::write($token->raw_token, 'cyan');
    }
}
