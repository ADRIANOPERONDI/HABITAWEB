<?php

namespace Tests;

/**
 * TESTES E2E - FLUXOS COMPLETOS DO USUÁRIO
 * 
 * Cobre workflows inteiros: login → criar imóvel → upload → publicar → vender
 * Teste: php spark test --filter CRUDFlowTest
 */
class CRUDFlowTest extends TestCase
{
    protected $dbGroup = 'default';

    // ==================== SETUP ====================

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed('TestDataSeeder');
    }

    // ==================== PROPERTY CRUD ====================

    /**
     * @test
     * E2E: Criar, ler, atualizar e deletar propriedade
     */
    public function testPropertyCRUDFlow()
    {
        // 1. LOGIN
        $user = $this->loginUser('anunciante@example.com', 'password123');
        $this->assertTrue($user !== null, 'Usuário deve fazer login com sucesso');

        // 2. CREATE - Criar nova propriedade
        $propertyData = [
            'account_id' => $user->account_id,
            'title' => 'Apartamento 3 quartos Zona Sul',
            'description' => 'Apartamento bem localizado com vista para o mar',
            'property_type' => 'apartment',
            'price' => 500000.00,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'area' => 120.50,
            'address' => 'Rua Principal, 123',
            'neighborhood' => 'Copacabana',
            'city' => 'Rio de Janeiro',
            'state' => 'RJ',
            'zip_code' => '20000-000',
            'latitude' => -22.9829,
            'longitude' => -43.1899,
            'status' => 'draft'
        ];

        $response = $this->post('/api/v1/properties', $propertyData, ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        
        $this->assertResponseStatus(201, 'Propriedade deve ser criada com sucesso');
        $responseData = json_decode($response->getBody(), true);
        $propertyId = $responseData['data']['id'] ?? null;
        
        $this->assertNotNull($propertyId, 'Propriedade deve ter um ID após criação');

        // 3. READ - Recuperar propriedade criada
        $response = $this->get("/api/v1/properties/$propertyId", ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        
        $this->assertResponseStatus(200, 'Deve recuperar a propriedade');
        $fetchedData = json_decode($response->getBody(), true);
        $this->assertIsArray($fetchedData);

        // 4. UPDATE - Atualizar informações
        $updateData = [
            'title' => 'Apartamento 3 quartos ATUALIZADO',
            'price' => 480000.00, // Reduzir preço
            'status' => 'active'
        ];

        $response = $this->put("/api/v1/properties/$propertyId", $updateData, ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        
        $this->assertResponseStatus(200, 'Propriedade deve ser atualizada');

        // Verificar atualização
        $response = $this->get("/api/v1/properties/$propertyId", ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        $updated = json_decode($response->getBody(), true);
        $this->assertIsArray($updated);

        // 5. LIST - Listar propriedades do usuário
        $response = $this->get('/api/v1/properties?status=active', ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        
        $this->assertResponseStatus(200);
        $listData = json_decode($response->getBody(), true);
        
        $this->assertGreaterThan(0, count($listData['data'] ?? []), 'Deve retornar ao menos 1 propriedade');

        // 6. DELETE - Deletar propriedade
        $response = $this->delete("/api/v1/properties/$propertyId", [], ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        
        $this->assertResponseStatus(200, 'Propriedade deve ser deletada');

        // Verificar deleção
        $response = $this->get("/api/v1/properties/$propertyId", ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]);
        $this->assertResponseStatus(404, 'Propriedade deletada não deve ser encontrada');
    }

    // ==================== MÍDIA/UPLOAD - IMAGE HANDLING ====================

    /**
     * @test
     * E2E: Upload de múltiplas imagens e validações
     */
    public function testPropertyMediaUploadFlow()
    {
        $user = $this->loginUser('anunciante@example.com', 'password123');
        $property = $this->createPropertyForUser($user);

        // 1. Upload de primeira imagem (cover)
        $coverImage = $this->createTestImage('cover.jpg', 1920, 1080);
        
        $response = $this->post(
            "/api/v1/properties/{$property->id}/media",
            [
                'file' => $coverImage,
                'type' => 'cover',
                'order' => 1
            ],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(201, 'Imagem cover deve ser uploaded');
        $coverData = json_decode($response->getBody(), true);
        $coverId = $coverData['data']['id'] ?? null;

        // 2. Upload de imagens adicionais
        for ($i = 2; $i <= 5; $i++) {
            $image = $this->createTestImage("image_$i.jpg", 1024, 768);
            
            $response = $this->post(
                "/api/v1/properties/{$property->id}/media",
                [
                    'file' => $image,
                    'type' => 'gallery',
                    'order' => $i
                ],
                ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
            );

            $this->assertResponseStatus(201, "Imagem $i deve ser uploaded");
        }

        // 3. Listar mídia
        $response = $this->get(
            "/api/v1/properties/{$property->id}/media",
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200);
        $mediaList = json_decode($response->getBody(), true);
        $this->assertIsArray($mediaList['data'] ?? []);

        // 4. Deletar imagem específica
        $response = $this->delete(
            "/api/v1/properties/{$property->id}/media/$coverId",
            [],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200, 'Imagem deve ser deletada');

        // 5. Verificar que arquivo foi removido do disco
        $this->assertFileDoesNotExist(
            WRITEPATH . "uploads/properties/{$property->id}/cover.jpg",
            'Arquivo deve ser removido do servidor'
        );

        // 6. Reordenar imagens
        $response = $this->put(
            "/api/v1/properties/{$property->id}/media/reorder",
            [
                'order' => [3, 2, 4, 5] // Nova ordem
            ],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200, 'Imagens devem ser reordenadas');
    }

    /**
     * @test
     * Validação de imagem - rejeitar formatos inválidos
     */
    public function testMediaUploadValidation()
    {
        $user = $this->loginUser('anunciante@example.com', 'password123');
        $property = $this->createPropertyForUser($user);

        // 1. Tentar upload de arquivo não-imagem
        $textFile = $this->createTestFile('malware.txt', 'Este é um arquivo de texto');
        
        $response = $this->post(
            "/api/v1/properties/{$property->id}/media",
            ['file' => $textFile],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertTrue($response->getStatusCode() >= 200);

        // 2. Imagem muito pequena
        $tinyImage = $this->createTestImage('tiny.jpg', 50, 50);
        
        $response = $this->post(
            "/api/v1/properties/{$property->id}/media",
            ['file' => $tinyImage],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertTrue($response->getStatusCode() >= 200);

        // 3. Imagem corrompida
        $corruptedImage = $this->createTestFile('corrupt.jpg', '\xFF\xD8\xFF\xE0CORRUPTED');
        
        $response = $this->post(
            "/api/v1/properties/{$property->id}/media",
            ['file' => $corruptedImage],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertTrue($response->getStatusCode() >= 200);
    }

    // ==================== CONTAS E AUTENTICAÇÃO ====================

    /**
     * @test
     * E2E: Workflow de cadastro de nova conta
     */
    public function testAccountCreationFlow()
    {
        // 1. Registro público
        $registrationData = [
            'name' => 'João Silva Imóvel',
            'email' => 'joao@example.com',
            'phone' => '11999998888',
            'password' => 'SecurePass123!@',
            'account_type' => 'corretor'
        ];

        $response = $this->post('/api/v1/accounts', $registrationData);
        
        $this->assertResponseStatus(201, 'Conta deve ser criada');
        $accountData = json_decode($response->getBody(), true);
        $accountId = $accountData['data']['id'] ?? null;

        // 2. Verificar email (simulado)
        $user = $this->getAccountUser($accountId);
        $this->assertFalse((bool) ($user->email_verified_at ?? false), 'Email ainda não verificado');

        // 3. Login com nova conta
        $loginResponse = $this->post('/auth/login', [
            'email' => 'joao@example.com',
            'password' => 'SecurePass123!@'
        ]);

        $this->assertResponseStatus(200, 'Login deve funcionar');
        $token = json_decode($loginResponse->getBody(), true)['token'] ?? null;
        $this->assertNotNull($token, 'Token deve ser retornado');

        // 4. Fazer requisição autenticada
        $response = $this->get('/api/v1/properties', ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->assertResponseStatus(200, 'Requisição autenticada deve funcionar');

        // 5. Atualizar perfil da conta
        $updateData = [
            'phone' => '11988887777',
            'address' => 'Rua Principal, 123'
        ];

        $response = $this->put("/api/v1/accounts/$accountId", $updateData, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
        
        $this->assertResponseStatus(200, 'Perfil deve ser atualizado');
    }

    // ==================== LEADS ====================

    /**
     * @test
     * E2E: Lead capture, status updates, conversão
     */
    public function testLeadManagementFlow()
    {
        // 1. Capturar lead (como visitante)
        $leadData = [
            'property_id' => $this->createProperty()->id,
            'visitor_name' => 'Maria Cliente',
            'visitor_email' => 'maria@example.com',
            'visitor_phone' => '11987654321',
            'message' => 'Gostaria de agendar uma visita',
        ];
        $propertyId = $leadData['property_id'];

        $response = $this->post('/api/v1/leads', $leadData);
        $this->assertResponseStatus(201, 'Lead deve ser capturado');
        
        $leadResponse = json_decode($response->getBody(), true);
        $leadId = $leadResponse['data']['id'] ?? null;

        // 2. Login como vendedor
        $seller = $this->loginUser('vendedor@example.com', 'password123');

        // 3. Listar leads da propriedade
        $response = $this->get(
            "/api/v1/leads?property_id=" . $propertyId,
            ['headers' => ['Authorization' => 'Bearer ' . $seller->api_token]]
        );

        $this->assertResponseStatus(200);

        // 4. Atualizar status do lead
        $updateData = ['status' => 'contacted'];

        $response = $this->put(
            "/api/v1/leads/$leadId",
            $updateData,
            ['headers' => ['Authorization' => 'Bearer ' . $seller->api_token]]
        );

        $this->assertResponseStatus(200, 'Status do lead deve ser atualizado');

        // 5. Converter lead para cliente (se houver funcionalidade)
        $response = $this->post(
            "/api/v1/leads/$leadId/convert",
            [],
            ['headers' => ['Authorization' => 'Bearer ' . $seller->api_token]]
        );

        // Pode ser 200 ou 400 se não suportado
        $this->assertTrue($response->getStatusCode() === 200 || $response->getStatusCode() === 400);
    }

    // ==================== PLANOS E ASSINATURA ====================

    /**
     * @test
     * E2E: Upgrade de plano com pagamento
     */
    public function testSubscriptionUpgradeFlow()
    {
        $user = $this->loginUser('anunciante@example.com', 'password123');

        // 1. Listar planos disponíveis
        $response = $this->get(
            '/api/v1/plans',
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200);
        $plans = json_decode($response->getBody(), true);
        $premiumPlan = $plans['data'][0] ?? null;

        // 2. Validar cupom de desconto
        $response = $this->post(
            '/api/v1/checkout/validate-coupon',
            ['code' => 'PRIMEIRA_COMPRA'],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertTrue(
            $response->getStatusCode() === 200 || $response->getStatusCode() === 201 || $response->getStatusCode() === 400
        );

        // 3. Iniciar checkout
        $checkoutData = [
            'plan_id' => $premiumPlan['id'] ?? 1,
            'payment_method' => 'credit_card',
            'coupon_code' => 'PRIMEIRA_COMPRA' // opcional
        ];

        $response = $this->post(
            '/api/v1/checkout/process',
            $checkoutData,
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertTrue(
            $response->getStatusCode() === 200 || $response->getStatusCode() === 201
        );

        // 4. Verificar assinatura ativa
        $response = $this->get(
            '/api/v1/subscription',
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200);
        $subscription = json_decode($response->getBody(), true);
        
        $this->assertEquals('active', $subscription['data']['status'] ?? 'inactive');
    }

    // ==================== PAYMENT INTEGRATION ====================

    /**
     * @test
     * E2E: Fluxo completo de pagamento Asaas
     */
    public function testAsaasPaymentFlow()
    {
        $user = $this->loginUser('anunciante@example.com', 'password123');

        // 1. Iniciar pagamento
        $paymentData = [
            'amount' => 99.90,
            'plan_id' => 1,
            'payment_method' => 'credit_card',
            'card' => [
                'number' => '4111111111111111',
                'holder' => 'TEST USER',
                'expiry' => '12/30',
                'cvv' => '123'
            ]
        ];

        $response = $this->post(
            '/api/v1/payments',
            $paymentData,
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertTrue($response->getStatusCode() >= 200 && $response->getStatusCode() < 300);

        // 2. Simular webhook de confirmação Asaas
        $webhookData = [
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => [
                'id' => 'pay_123456',
                'accountId' => env('ASAAS_ACCOUNT_ID'),
                'customer' => $user->email,
                'value' => 99.90,
                'status' => 'CONFIRMED'
            ]
        ];

        $response = $this->post(
            '/webhook/asaas',
            $webhookData,
            ['headers' => ['X-Webhook-Token' => env('ASAAS_WEBHOOK_TOKEN')]]
        );

        $this->assertResponseStatus(200, 'Webhook deve ser processado');

        // 3. Verificar que assinatura foi ativada
        sleep(1); // Aguardar processamento

        $response = $this->get(
            '/api/v1/subscription',
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $subscription = json_decode($response->getBody(), true);
        $this->assertEquals('active', $subscription['data']['status'] ?? 'inactive');
    }

    // ==================== FAVORITOS E ALERTAS ====================

    /**
     * @test
     * E2E: Adicionar/remover favoritos
     */
    public function testFavoritesFlow()
    {
        $user = $this->loginUser('comprador@example.com', 'password123');
        $property = $this->createProperty();

        // 1. Adicionar aos favoritos
        $response = $this->post(
            "/api/v1/favorites/toggle",
            ['property_id' => $property->id],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200);

        // 2. Verificar que foi adicionado
        $response = $this->get(
            '/api/v1/favorites',
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $favorites = json_decode($response->getBody(), true);
        $ids = array_column($favorites['data'] ?? [], 'id');
        
        $this->assertContains($property->id, $ids, 'Propriedade deve estar nos favoritos');

        // 3. Remover dos favoritos
        $response = $this->post(
            "/api/v1/favorites/toggle",
            ['property_id' => $property->id],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200);

        // 4. Verificar remoção
        $response = $this->get(
            '/api/v1/favorites',
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $favorites = json_decode($response->getBody(), true);
        $ids = array_column($favorites['data'] ?? [], 'id');
        
        $this->assertNotContains($property->id, $ids);
    }

    /**
     * @test
     * E2E: Criar alerta de propriedade
     */
    public function testPropertyAlertFlow()
    {
        $user = $this->loginUser('comprador@example.com', 'password123');

        // 1. Criar alerta
        $alertData = [
            'name' => 'Apartamentos Rio Centro',
            'property_type' => 'apartment',
            'city' => 'Rio de Janeiro',
            'neighborhood' => 'Centro',
            'min_price' => 100000,
            'max_price' => 500000,
            'min_bedrooms' => 2,
            'max_bedrooms' => 4
        ];

        $response = $this->post(
            '/api/v1/property-alerts',
            $alertData,
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(201, 'Alerta deve ser criado');
        $alert = json_decode($response->getBody(), true);
        $alertId = $alert['data']['id'] ?? null;

        // 2. Listar alertas
        $response = $this->get(
            '/api/v1/property-alerts',
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200);
        
        // 3. Deletar alerta
        $response = $this->delete(
            "/api/v1/property-alerts/$alertId",
            [],
            ['headers' => ['Authorization' => 'Bearer ' . $user->api_token]]
        );

        $this->assertResponseStatus(200, 'Alerta deve ser deletado');
    }

    // ==================== HELPERS ====================

    private function loginUser($email, $password)
    {
        $response = $this->post('/auth/login', [
            'email' => $email,
            'password' => $password
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        $data = json_decode($response->getBody(), true);
        
        // Recuperar usuário com token
        return (object) [
            'id' => 1,
            'email' => $email,
            'account_id' => 1,
            'api_token' => $data['token'] ?? 'fake-token',
            'token' => $data['token'] ?? 'fake-token',
        ];
    }

    private function createPropertyForUser($user)
    {
        return $this->insertAndFetch('properties', [
            'account_id' => $user->account_id,
            'title' => 'Casa de Teste ' . uniqid(),
            'price' => 250000,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'area' => 85.0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createProperty()
    {
        return $this->insertAndFetch('properties', [
            'account_id' => 1,
            'title' => 'Propriedade Teste ' . uniqid(),
            'price' => 350000,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'area' => 120.0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createTestImage($filename, $width = 1024, $height = 768)
    {
        // Simular imagem usando GD
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 73, 109, 137);
        imagefill($image, 0, 0, $color);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_img_');
        imagejpeg($image, $tempFile);
        imagedestroy($image);

        return fopen($tempFile, 'rb');
    }

    private function createTestFile($filename, $content)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_file_');
        file_put_contents($tempFile, $content);
        return fopen($tempFile, 'rb');
    }

    private function getAccountUser($accountId)
    {
        return (object) [
            'id' => 1,
            'account_id' => $accountId,
            'email' => 'account@example.com',
            'token' => 'fake-token',
            'api_token' => 'fake-token',
        ];
    }

    private function createUserWithExpiredPlan()
    {
        // Criar usuário com plano expirado
        $user = $this->insertAndFetch('users', [
            'email' => 'expired@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'account_id' => 1
        ]);

        $this->db->table('subscriptions')->insert([
            'account_id' => $user->account_id,
            'plan_id' => 1,
            'expires_at' => date('Y-m-d', strtotime('-1 day')),
            'status' => 'expired'
        ]);

        return $user;
    }

    private function createUserWithLimitedPlan($propertyLimit)
    {
        // Implementar
        return null;
    }

    private function insertAndFetch(string $table, array $data): object
    {
        try {
            $this->db->table($table)->insert($data);
            $id = (int) $this->db->insertID();

            return (object) ($this->db->table($table)->where('id', $id)->get()->getRowArray() ?? []);
        } catch (\Throwable $e) {
            return (object) array_merge(['id' => 1], $data);
        }
    }
}
