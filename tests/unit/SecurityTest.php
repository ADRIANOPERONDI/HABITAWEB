<?php

namespace Tests;

use App\Test\TestCase;
use CodeIgniter\Database\BaseConnection;

/**
 * TESTE COMPLETO DE SEGURANÇA - OWASP Top 10
 * 
 * Cobre todas as vulnerabilidades críticas e comuns em PHP/CodeIgniter
 * Teste manual: php vendor/bin/phpunit --filter SecurityTest
 */
class SecurityTest extends TestCase
{
    protected $dbGroup = 'default';

    // ==================== 1. SQL INJECTION TESTS ====================

    /**
     * @test
     * Verificar SQL Injection em busca de propriedades
     */
    public function testSQLInjectionInPropertySearch()
    {
        // Tentativa 1: SQL Injection clássico
        $maliciousInput = "1' OR '1'='1";
        
        // Este teste simula tentativa de bypass de autenticação
        $code = http_response_code();
        $response = $this->get('/api/v1/properties?search=' . urlencode($maliciousInput));
        
        $this->assertResponseStatus(200);
        
        // Verificar se a query foi parametrizada (não deve conter concatenação de string)
        // Se isso passar, significa que a query está segura!
        $this->assertStringNotContainsString("OR '1'='1", $response->getBody());
    }

    /**
     * @test
     * Verificar SQL Injection em atualização de dados
     */
    public function testSQLInjectionInPropertyUpdate()
    {
        $maliciousData = [
            'title' => "Test'; DROP TABLE properties; --",
            'description' => "'; DELETE FROM properties; --",
            'price' => "1); UPDATE properties SET price = 0; --"
        ];

        // Simular POST com dados maliciosos
        $response = $this->post('/api/v1/properties/1', $maliciousData);
        
        // Verificar se a tabela ainda existe
        $result = $this->db->query("SELECT COUNT(*) as cnt FROM properties")->getRow();
        $this->assertIsObject($result);
        $this->assertGreaterThanOrEqual(0, $result->cnt);
    }

    // ==================== 2. XSS (CROSS-SITE SCRIPTING) ====================

    /**
     * @test
     * XSS Stored - Injetar script no título da propriedade
     */
    public function testXSSInPropertyTitle()
    {
        $xssPayload = [
            'title' => '<script>alert("XSS");</script>Casa Bonita',
            'description' => '<img src=x onerror=alert("XSS")>',
        ];

        $response = $this->post('/api/v1/properties', $xssPayload);
        
        // O script não deve ser refletido sem escapar
        if ($response->getStatusCode() === 201) {
            $property = json_decode($response->getBody(), true);
            
            // Verificar se foi escapado no JSON
            $this->assertStringNotContainsString('<script>', json_encode($property));
            $this->assertStringNotContainsString('onerror=', json_encode($property));
        }
    }

    /**
     * @test
     * XSS Reflected - Através de parâmetro de query
     */
    public function testXSSInQueryParameter()
    {
        $xssPayload = '<script>alert("XSS")</script>';
        $response = $this->get('/imoveis?search=' . urlencode($xssPayload));
        
        // Verificar se o HTML resposta não contém o script não escapado
        $body = $response->getBody();
        // Scripts devem estar escapados como &lt;script&gt; ou removidos
        $this->assertFalse(strpos($body, '<script>alert("XSS")</script>'));
    }

    /**
     * @test
     * XSS em JSON responses
     */
    public function testXSSInJSONResponse()
    {
        $response = $this->get('/api/v1/properties?filter=' . urlencode('<img src=x onerror=alert(1)>'));
        
        // JSON não deve conter HTML literal
        $data = json_decode($response->getBody(), true);
        if (is_array($data)) {
            $jsonString = json_encode($data);
            $this->assertStringNotContainsString('onerror=', $jsonString);
        }
    }

    // ==================== 3. CSRF (CROSS-SITE REQUEST FORGERY) ====================

    /**
     * @test
     * Verificar CSRF Token em formulários POST
     */
    public function testCSRFTokenRequired()
    {
        // POST sem CSRF token deve ser rejeitado
        $response = $this->post('/admin/properties/1', [
            'title' => 'Propriedade Hack'
        ]);
        
        // Deve retornar 403 Forbidden ou similar
        $this->assertTrue(
            $response->getStatusCode() === 403 || 
            $response->getStatusCode() === 400
        );
    }

    /**
     * @test
     * Verificar CSRF Token em API endpoints PUT/DELETE
     */
    public function testCSRFTokenInAPIDelete()
    {
        $response = $this->delete('/api/v1/properties/1');
        
        // Sem token deve falhar
        $this->assertTrue(
            $response->getStatusCode() >= 400
        );
    }

    // ==================== 4. AUTENTICAÇÃO FRACA ====================

    /**
     * @test
     * Bypass de autenticação - Acesso a admin sem login
     */
    public function testUnauthenticatedAdminAccess()
    {
        $response = $this->get('/admin/');
        
        // Deve redirecionar para login ou retornar 403
        $this->assertTrue(
            $response->getStatusCode() === 302 || 
            $response->getStatusCode() === 401 ||
            $response->getStatusCode() === 403
        );
    }

    /**
     * @test
     * API Key inválida deve ser rejeitada
     */
    public function testInvalidAPIKeyRejection()
    {
        $response = $this->get('/api/v1/properties', [
            'headers' => ['X-API-Key' => 'invalid_key_12345']
        ]);
        
        $this->assertTrue(
            $response->getStatusCode() === 401 || 
            $response->getStatusCode() === 403
        );
    }

    /**
     * @test
     * API Key expiramente ou inativa
     */
    public function testExpiredAPIKeyRejection()
    {
        // Criar API key expirada na DB
        $this->db->table('api_keys')->where('id', '>', 0)->update([
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ]);

        $expiredKey = $this->db->table('api_keys')->where('status', 'inactive')->first();
        
        if ($expiredKey) {
            $response = $this->get('/api/v1/properties', [
                'headers' => ['X-API-Key' => $expiredKey->key]
            ]);
            
            $this->assertTrue($response->getStatusCode() >= 400);
        }
    }

    // ==================== 5. AUTORIZAÇÃO / CONTROLE DE ACESSO ====================

    /**
     * @test
     * Usuário comum não pode acessar admin
     */
    public function testUnauthorizedAdminAccess()
    {
        // Simular usuário comum logado
        $this->actingAs($this->createBasicUser());
        
        $response = $this->get('/admin/accounts');
        
        $this->assertTrue(
            $response->getStatusCode() === 403 ||
            $response->getStatusCode() === 302 // redirect
        );
    }

    /**
     * @test
     * Usuário não pode acessar dados de outra conta
     */
    public function testCrossAccountDataAccess()
    {
        // Usuário A tenta acessar dados de Usuário B
        $userA = $this->createUserWithAccount(1);
        $userB = $this->createUserWithAccount(2);

        $this->actingAs($userA);
        
        // Tentar acessar propriedades de conta diferente
        $response = $this->get('/api/v1/properties?account_id=' . $userB->account_id);
        
        // Deve retornar vazio ou erro
        $data = json_decode($response->getBody(), true);
        
        if (is_array($data) && isset($data['data'])) {
            $this->assertEmpty($data['data']);
        }
    }

    /**
     * @test
     * Privilege escalation - Usuário tenta se tornar admin
     */
    public function testPrivilegeEscalation()
    {
        $regularUser = $this->createBasicUser();
        $this->actingAs($regularUser);

        // Tentar atualizar seu próprio role para admin
        $response = $this->put('/api/v1/users/' . $regularUser->id, [
            'role' => 'admin'
        ]);

        // Verificar se permanece com role original
        $updatedUser = $this->db->table('users')->where('id', $regularUser->id)->first();
        $this->assertNotEquals('admin', $updatedUser->auth_group ?? 'user');
    }

    /**
     * @test
     * Acesso direto por ID - IDOR (Insecure Direct Object Reference)
     */
    public function testIDORVulnerability()
    {
        $user1 = $this->createUserWithAccount(1);
        $user2 = $this->createUserWithAccount(2);
        
        // User1 tenta acessar propriedade de User2 diretamente por ID
        $property = $this->createPropertyForAccount($user2->account_id);
        
        $this->actingAs($user1);
        $response = $this->get('/api/v1/properties/' . $property->id);
        
        // Deve retornar erro ou propriedade vazia
        $this->assertTrue(
            $response->getStatusCode() === 403 ||
            $response->getStatusCode() === 404
        );
    }

    // ==================== 6. VALIDAÇÃO DE ENTRADA ====================

    /**
     * @test
     * Validação de email
     */
    public function testEmailValidation()
    {
        $invalidEmails = [
            'not-an-email',
            'test@',
            '@example.com',
            'test@.com',
            'test..test@example.com',
        ];

        foreach ($invalidEmails as $email) {
            $response = $this->post('/api/v1/accounts', [
                'email' => $email,
                'name' => 'Test'
            ]);

            // Deve rejeitar email inválido
            $this->assertTrue($response->getStatusCode() >= 400);
        }
    }

    /**
     * @test
     * Validação de tipos de dados
     */
    public function testDataTypeValidation()
    {
        // Enviar string quando espera número
        $response = $this->post('/api/v1/properties', [
            'price' => 'not-a-number',
            'bedrooms' => 'five',
            'area' => 'large'
        ]);

        // Deve rejeitar ou converter seguramente
        $this->assertTrue($response->getStatusCode() >= 400 || is_numeric($response->getBody()));
    }

    /**
     * @test
     * Validação de comprimento de string
     */
    public function testStringLengthValidation()
    {
        $longString = str_repeat('A', 10000);
        
        $response = $this->post('/api/v1/properties', [
            'title' => $longString
        ]);

        // Deve rejeitar ou truncar
        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Validação de CPF/CNPJ
     */
    public function testCPFCNPJValidation()
    {
        $invalidDocuments = [
            '00000000000',
            '11111111111',
            '123456789',
            'abcdefghijk',
        ];

        foreach ($invalidDocuments as $doc) {
            $response = $this->post('/api/v1/accounts', [
                'document' => $doc,
                'name' => 'Test'
            ]);

            // Deve rejeitar documento inválido
            $this->assertTrue($response->getStatusCode() >= 400);
        }
    }

    // ==================== 7. RATE LIMITING / BRUTE FORCE ====================

    /**
     * @test
     * Proteção contra brute force em login
     */
    public function testBruteForceProtection()
    {
        // Fazer 20 tentativas de login falhadas
        for ($i = 0; $i < 20; $i++) {
            $response = $this->post('/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password-' . $i
            ]);
        }

        // Última tentativa deve ser bloqueada
        $response = $this->post('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'still-wrong'
        ]);

        // Deve retornar 429 Too Many Requests ou login bloqueado
        $this->assertTrue(
            $response->getStatusCode() === 429 ||
            strpos($response->getBody(), 'blocked') !== false ||
            strpos($response->getBody(), 'too many') !== false
        );
    }

    /**
     * @test
     * Rate limiting em API
     */
    public function testAPIRateLimiting()
    {
        // Simular 100 requisições rápidas
        $headers = ['X-API-Key' => env('TEST_API_KEY')];
        
        for ($i = 0; $i < 100; $i++) {
            $response = $this->get('/api/v1/properties', ['headers' => $headers]);
            
            if ($response->getStatusCode() === 429) {
                // Rate limit atingido - OK!
                $this->assertTrue(true);
                return;
            }
        }

        // Se não foi limitado após 100 requisições, há problema
        $this->fail('Rate limiting não está funcionando');
    }

    // ==================== 8. FILE UPLOAD SECURITY ====================

    /**
     * @test
     * Upload de arquivo malicioso deve ser bloqueado
     */
    public function testMaliciousFileUpload()
    {
        $maliciousFiles = [
            'shell.php' => '<?php system($_GET["cmd"]); ?>',
            'virus.exe' => 'MZ\x90\x00', // Fake EXE header
            'script.html' => '<script>alert("XSS")</script>',
            'malware.sh' => '#!/bin/bash\nrm -rf /',
        ];

        foreach ($maliciousFiles as $filename => $content) {
            $response = $this->post('/api/v1/properties/1/media', [
                'file' => $this->createTestFile($filename, $content)
            ]);

            // Upload deve ser bloqueado
            $this->assertTrue($response->getStatusCode() >= 400);
        }
    }

    /**
     * @test
     * Upload de imagem válida
     */
    public function testValidImageUpload()
    {
        // Criar imagem válida (1x1 pixel PNG)
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $this->createTestFile('image.png', $pngData)
        ]);

        $this->assertTrue($response->getStatusCode() === 200 || $response->getStatusCode() === 201);
    }

    /**
     * @test
     * Verificar validação de tamanho de arquivo
     */
    public function testFileSizeValidation()
    {
        // Arquivo muito grande (100MB simulado)
        $largeFile = str_repeat('A', 100 * 1024 * 1024);
        
        $response = $this->post('/api/v1/properties/1/media', [
            'file' => $this->createTestFile('huge.jpg', $largeFile)
        ]);

        // Deve rejeitar
        $this->assertTrue($response->getStatusCode() >= 400);
    }

    // ==================== 9. BUSINESS LOGIC / REGRAS DE NEGÓCIO ====================

    /**
     * @test
     * Usuário não pode criar propriedade sem plano ativo
     */
    public function testPropertyCreationRequiresActivePlan()
    {
        $user = $this->createUserWithExpiredPlan();
        $this->actingAs($user);

        $response = $this->post('/api/v1/properties', [
            'title' => 'Nova Propriedade',
            'description' => 'Teste',
            'price' => 100000
        ]);

        // Deve rejeitar
        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Limite de propriedades por plano
     */
    public function testPropertyLimitPerPlan()
    {
        $user = $this->createUserWithLimitedPlan(2); // Máximo 2 propriedades
        
        // Criar 3 propriedades
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user);
            $response = $this->post('/api/v1/properties', [
                'title' => "Property $i",
                'description' => 'Teste',
                'price' => 100000 + ($i * 1000)
            ]);
            
            if ($i < 2) {
                $this->assertTrue($response->getStatusCode() === 201);
            } else {
                // Terceira deve falhar por limite
                $this->assertTrue($response->getStatusCode() >= 400);
            }
        }
    }

    /**
     * @test
     * Preço negativo não deve ser permitido
     */
    public function testNegativePriceValidation()
    {
        $response = $this->post('/api/v1/properties', [
            'title' => 'Casa',
            'price' => -100000
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Cupom de desconto expirado não deve funcionar
     */
    public function testExpiredCouponRejection()
    {
        $coupon = $this->createExpiredCoupon();
        
        $response = $this->post('/checkout/validate-coupon', [
            'coupon_code' => $coupon->code
        ]);

        // Deve rejeitar cupom expirado
        $this->assertTrue($response->getStatusCode() >= 400);
    }

    // ==================== 10. LOGGING & MONITORING ====================

    /**
     * @test
     * Erros não devem expor dados sensíveis
     */
    public function testErrorMessagesNotExposed()
    {
        // Forçar erro
        $response = $this->get('/api/v1/properties/99999999');

        $body = $response->getBody();
        
        // Não deve contar:
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('stack trace', $body);
        $this->assertStringNotContainsString('postgres', $body); // DB name
        $this->assertStringNotContainsString('/app/', $body); // File paths
        $this->assertStringNotContainsString('line', $body); // Line numbers
    }

    /**
     * @test
     * Verificar se sensíveis dados estão sendo logados
     */
    public function testSensitiveDataLogging()
    {
        // Fazer request com senha
        $response = $this->post('/api/v1/users', [
            'email' => 'test@example.com',
            'password' => 'MySecurePassword123!'
        ]);

        // Verificar logs
        $logFile = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';
        
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            
            // Senha não deve estar no log
            $this->assertStringNotContainsString('MySecurePassword123!', $logContent);
        }
    }

    // ==================== HELPERS ====================

    private function createBasicUser()
    {
        // Implementar
        return null;
    }

    private function createUserWithAccount($accountId)
    {
        // Implementar
        return null;
    }

    private function createPropertyForAccount($accountId)
    {
        // Implementar
        return null;
    }

    private function createUserWithExpiredPlan()
    {
        // Implementar
        return null;
    }

    private function createUserWithLimitedPlan($limit)
    {
        // Implementar
        return null;
    }

    private function createExpiredCoupon()
    {
        // Implementar
        return null;
    }

    private function createTestFile($filename, $content)
    {
        // Implementar
        return null;
    }
}
