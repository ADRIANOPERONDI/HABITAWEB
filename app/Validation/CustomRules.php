<?php

namespace App\Validation;

class CustomRules
{
    /**
     * Validação de CPF
     */
    public function valid_cpf(string $cpf, ?string &$error = null): bool
    {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        if (strlen($cpf) != 11) {
            $error = 'O CPF deve conter 11 dígitos.';
            return false;
        }
        
        // Verifica se todos os dígitos são iguais
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            $error = 'CPF inválido.';
            return false;
        }
        
        // Validação do primeiro dígito verificador
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $cpf[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1 = ($remainder < 2) ? 0 : 11 - $remainder;
        
        if ($cpf[9] != $digit1) {
            $error = 'CPF inválido.';
            return false;
        }
        
        // Validação do segundo dígito verificador
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $cpf[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2 = ($remainder < 2) ? 0 : 11 - $remainder;
        
        if ($cpf[10] != $digit2) {
            $error = 'CPF inválido.';
            return false;
        }
        
        return true;
    }

    /**
     * Validação de CNPJ
     */
    public function valid_cnpj(string $cnpj, ?string &$error = null): bool
    {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        
        if (strlen($cnpj) != 14) {
            $error = 'O CNPJ deve conter 14 dígitos.';
            return false;
        }
        
        // Verifica se todos os dígitos são iguais
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            $error = 'CNPJ inválido.';
            return false;
        }
        
        // Validação do primeiro dígito verificador
        $sum = 0;
        $weights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for ($i = 0; $i < 12; $i++) {
            $sum += $cnpj[$i] * $weights[$i];
        }
        $remainder = $sum % 11;
        $digit1 = ($remainder < 2) ? 0 : 11 - $remainder;
        
        if ($cnpj[12] != $digit1) {
            $error = 'CNPJ inválido.';
            return false;
        }
        
        // Validação do segundo dígito verificador
        $sum = 0;
        $weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for ($i = 0; $i < 13; $i++) {
            $sum += $cnpj[$i] * $weights[$i];
        }
        $remainder = $sum % 11;
        $digit2 = ($remainder < 2) ? 0 : 11 - $remainder;
        
        if ($cnpj[13] != $digit2) {
            $error = 'CNPJ inválido.';
            return false;
        }
        
        return true;
    }

    /**
     * Validação dinâmica de documento (CPF ou CNPJ)
     */
    public function valid_documento(string $documento, string $params, array $data, ?string &$error = null): bool
    {
        $tipo = $data[$params] ?? '';
        $documento = preg_replace('/[^0-9]/', '', $documento);
        
        if ($tipo === 'CPF') {
            return $this->valid_cpf($documento, $error);
        } elseif ($tipo === 'CNPJ') {
            return $this->valid_cnpj($documento, $error);
        } else {
            $error = 'Tipo de documento não especificado.';
            return false;
        }
    }

    /**
     * Bloquear emails temporários
     */
    public function professional_email(string $email, ?string &$error = null): bool
    {
        $blockedDomains = [
            'temp-mail.org', 'tempmail.com', 'guerrillamail.com', '10minutemail.com',
            'mailinator.com', 'throwaway.email', 'getnada.com', 'trashmail.com',
            'yopmail.com', 'fakeinbox.com', 'maildrop.cc', 'sharklasers.com',
            'guerrillamailblock.com', 'spam4.me', 'mintemail.com', 'getairmail.com'
        ];
        
        $domain = explode('@', strtolower($email))[1] ?? '';
        
        if (in_array($domain, $blockedDomains)) {
            $error = 'Por favor, use um email profissional válido. Emails temporários não são permitidos.';
            return false;
        }
        
        return true;
    }

    /**
     * Senha forte
     */
    public function strong_password(string $password, ?string &$error = null): bool
    {
        if (strlen($password) < 8) {
            $error = 'A senha deve ter no mínimo 8 caracteres.';
            return false;
        }
        
        $hasUpper = preg_match('/[A-Z]/', $password);
        $hasLower = preg_match('/[a-z]/', $password);
        $hasNumber = preg_match('/[0-9]/', $password);
        $hasSpecial = preg_match('/[!@#$%^&*(),.?":{}|<>_\-+=\[\]\/\\\\]/', $password);
        
        if (!$hasUpper) {
            $error = 'A senha deve conter pelo menos uma letra maiúscula.';
            return false;
        }
        
        if (!$hasLower) {
            $error = 'A senha deve conter pelo menos uma letra minúscula.';
            return false;
        }
        
        if (!$hasNumber) {
            $error = 'A senha deve conter pelo menos um número.';
            return false;
        }
        
        if (!$hasSpecial) {
            $error = 'A senha deve conter pelo menos um caractere especial (!@#$%^&* etc).';
            return false;
        }
        
        return true;
    }
}
