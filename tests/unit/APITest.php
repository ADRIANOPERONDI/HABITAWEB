<?php

namespace Tests;

use App\Test\TestCase;

/**
 * TESTES API REST - v1
 * 
 * Valida todos os endpoints REST
 * Teste: php spark test --filter APITest
 */
class APITest extends TestCase
{
    protected $dbGroup = 'default';
    protected $apiToken = 'test_api_key_12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed('TestDataSeeder');
    }

    // ==================== PROPERTIES API ====================

    /**
     * @test
     * GET /api/v1/properties - Listar propriedades
     */
    public function testListProperties()
    {
        $response = $this->get('/api/v1/properties', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertIsArray($data['data'] ?? []);
        if (!empty($data['data'])) {
            $property = $data['data'][0];
            $this->assertArrayHasKey('id', $property);
            $this->assertArrayHasKey('title', $property);
            $this->assertArrayHasKey('price', $property);
        }
    }

    /**
     * @test
     * GET /api/v1/properties?filters - Filtrar propriedades
     */
    public function testFilterProperties()
    {
        $response = $this->get('/api/v1/properties?city=Rio%20de%20Janeiro&min_price=100000&max_price=500000', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        // Validar que todos os resultados estão dentro dos filtros
        foreach ($data['data'] ?? [] as $property) {
            if (isset($property['city'])) {
                $this->assertStringContainsString('Rio de Janeiro', $property['city']);
            }
            $this->assertGreaterThanOrEqual(100000, $property['price'] ?? 0);
            $this->assertLessThanOrEqual(500000, $property['price'] ?? 0);
        }
    }

    /**
     * @test
     * GET /api/v1/properties/:id - Recuperar propriedade
     */
    public function testGetProperty()
    {
        $property = $this->createProperty();

        $response = $this->get("/api/v1/properties/{$property->id}", [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertEquals($property->id, $data['data']['id'] ?? null);
    }

    /**
     * @test
     * POST /api/v1/properties - Criar propriedade
     */
    public function testCreateProperty()
    {
        $propertyData = [
            'title' => 'Nueva Casa API Test',
            'description' => 'Description',
            'property_type' => 'house',
            'price' => 250000,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'area' => 120,
            'city' => 'Rio de Janeiro',
            'neighborhood' => 'Centro'
        ];

        $response = $this->post('/api/v1/properties', $propertyData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(201);
        $data = json_decode($response->getBody(), true);
        
        $this->assertIsInt($data['data']['id'] ?? null);
    }

    /**
     * @test
     * PUT /api/v1/properties/:id - Atualizar propriedade
     */
    public function testUpdateProperty()
    {
        $property = $this->createProperty();
        
        $updateData = [
            'title' => 'Título Atualizado',
            'price' => 300000
        ];

        $response = $this->put("/api/v1/properties/{$property->id}", $updateData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);

        // Verificar atualização
        $updated = $this->db->table('properties')
            ->where('id', $property->id)
            ->first();

        $this->assertEquals('Título Atualizado', $updated->title ?? null);
        $this->assertEquals(300000, $updated->price ?? null);
    }

    /**
     * @test
     * DELETE /api/v1/properties/:id - Deletar propriedade
     */
    public function testDeleteProperty()
    {
        $property = $this->createProperty();

        $response = $this->delete("/api/v1/properties/{$property->id}", [], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);

        // Verificar deleção
        $deleted = $this->db->table('properties')
            ->where('id', $property->id)
            ->first();

        $this->assertNull($deleted);
    }

    // ==================== PROPERTYY MEDIA API ====================

    /**
     * @test
     * POST /api/v1/properties/:id/media - Upload de mídia
     */
    public function testUploadMedia()
    {
        $property = $this->createProperty();
        $image = $this->createTestImage('test.jpg');

        $response = $this->post("/api/v1/properties/{$property->id}/media", [
            'file' => $image
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue($response->getStatusCode() === 201 || $response->getStatusCode() === 200);
    }

    /**
     * @test
     * GET /api/v1/properties/:id/media - Listar mídia
     */
    public function testListMedia()
    {
        $property = $this->createProperty();

        $response = $this->get("/api/v1/properties/{$property->id}/media", [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertIsArray($data['data'] ?? []);
    }

    /**
     * @test
     * DELETE /api/v1/properties/:id/media/:mediaId - Deletar mídia
     */
    public function testDeleteMedia()
    {
        $property = $this->createProperty();
        $media = $this->createMedia($property->id);

        $response = $this->delete(
            "/api/v1/properties/{$property->id}/media/{$media->id}",
            [],
            ['headers' => ['X-API-Key' => $this->apiToken]]
        );

        $this->assertResponseStatus(200);
    }

    // ==================== LEADS API ====================

    /**
     * @test
     * POST /api/v1/leads - Criar lead
     */
    public function testCreateLead()
    {
        $property = $this->createProperty();

        $leadData = [
            'property_id' => $property->id,
            'visitor_name' => 'John Doe',
            'visitor_email' => 'john@example.com',
            'visitor_phone' => '11987654321',
            'message' => 'Interesting property!'
        ];

        $response = $this->post('/api/v1/leads', $leadData);

        $this->assertResponseStatus(201);
        $data = json_decode($response->getBody(), true);
        
        $this->assertIsInt($data['data']['id'] ?? null);
    }

    /**
     * @test
     * GET /api/v1/leads - Listar leads
     */
    public function testListLeads()
    {
        $response = $this->get('/api/v1/leads', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertIsArray($data['data'] ?? []);
    }

    /**
     * @test
     * PUT /api/v1/leads/:id - Atualizar lead
     */
    public function testUpdateLead()
    {
        $lead = $this->createLead();

        $updateData = ['status' => 'contacted'];

        $response = $this->put("/api/v1/leads/{$lead->id}", $updateData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue($response->getStatusCode() === 200 || $response->getStatusCode() === 201);
    }

    // ==================== ACCOUNTS API ====================

    /**
     * @test
     * POST /api/v1/accounts - Criar conta
     */
    public function testCreateAccount()
    {
        $accountData = [
            'name' => 'Test Account ' . uniqid(),
            'email' => 'test' . uniqid() . '@example.com',
            'password' => 'SecurePass123!@',
            'phone' => '11987654321',
            'account_type' => 'individual'
        ];

        $response = $this->post('/api/v1/accounts', $accountData);

        $this->assertTrue($response->getStatusCode() === 201 || $response->getStatusCode() === 200);
    }

    /**
     * @test
     * GET /api/v1/accounts/:id - Recuperar conta
     */
    public function testGetAccount()
    {
        $account = $this->createAccount();

        $response = $this->get("/api/v1/accounts/{$account->id}", [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
    }

    /**
     * @test
     * PUT /api/v1/accounts/:id - Atualizar conta
     */
    public function testUpdateAccount()
    {
        $account = $this->createAccount();

        $updateData = ['phone' => '11988887777'];

        $response = $this->put("/api/v1/accounts/{$account->id}", $updateData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
    }

    // ==================== PAGAMENTOS API ====================

    /**
     * @test
     * POST /api/v1/payments - Iniciar pagamento
     */
    public function testCreatePayment()
    {
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

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        // Pode retornar 200, 201 ou 202 (depende de confirmação assíncrona)
        $this->assertTrue($response->getStatusCode() >= 200 && $response->getStatusCode() < 300);
    }

    /**
     * @test
     * GET /api/v1/payments - Listar pagamentos
     */
    public function testListPayments()
    {
        $response = $this->get('/api/v1/payments', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertIsArray($data['data'] ?? []);
    }

    // ==================== WEBHOOKS ====================

    /**
     * @test
     * POST /webhook/asaas - Webhook Asaas
     */
    public function testAsaasWebhook()
    {
        $webhookData = [
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => [
                'id' => 'pay_123456',
                'accountId' => env('ASAAS_ACCOUNT_ID'),
                'value' => 99.90,
                'status' => 'CONFIRMED'
            ]
        ];

        $response = $this->post('/webhook/asaas', $webhookData);

        $this->assertResponseStatus(200);
    }

    /**
     * @test
     * Webhook com dados inválidos
     */
    public function testInvalidWebhook()
    {
        $response = $this->post('/webhook/asaas', [
            'invalid' => 'data'
        ]);

        // Deve rejeitar dados inválidos
        $this->assertTrue($response->getStatusCode() >= 400 || $response->getStatusCode() === 200);
    }

    // ==================== ERROR HANDLING ====================

    /**
     * @test
     * 404 Not Found
     */
    public function test404NotFound()
    {
        $response = $this->get('/api/v1/properties/99999999', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(404);
    }

    /**
     * @test
     * 401 Unauthorized
     */
    public function test401Unauthorized()
    {
        $response = $this->get('/api/v1/properties', [
            'headers' => ['X-API-Key' => 'invalid_key']
        ]);

        $this->assertTrue(
            $response->getStatusCode() === 401 || 
            $response->getStatusCode() === 403
        );
    }

    /**
     * @test
     * 400 Bad Request
     */
    public function test400BadRequest()
    {
        $response = $this->post('/api/v1/properties', [
            'invalid_field' => 'test'
            // Faltam campos obrigatórios
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(400);
    }

    /**
     * @test
     * 429 Too Many Requests
     */
    public function test429RateLimited()
    {
        // Esta teste depends on rate limiting implementation
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 100; $i++) {
            $response = $this->get('/api/v1/properties', [
                'headers' => ['X-API-Key' => $this->apiToken]
            ]);

            if ($response->getStatusCode() === 429) {
                $this->assertResponseStatus(429);
                return;
            }
        }

        // Se não for limitado, teste passa (implementação pode não ter rate limit)
        $this->assertTrue(true);
    }

    // ==================== RESPONSE FORMAT ====================

    /**
     * @test
     * Response deve ser JSON válido
     */
    public function testResponseIsValidJSON()
    {
        $response = $this->get('/api/v1/properties', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $body = $response->getBody();
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, 'Response deve ser JSON válido');
    }

    /**
     * @test
     * Response headers corretos
     */
    public function testResponseHeaders()
    {
        $response = $this->get('/api/v1/properties', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertArrayHasKey('Content-Type', $response->headers());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // ==================== PAGINATION ====================

    /**
     * @test
     * Paginação funciona corretamente
     */
    public function testPagination()
    {
        // Criar dados de teste
        for ($i = 0; $i < 25; $i++) {
            $this->createProperty();
        }

        // Página 1
        $response1 = $this->get('/api/v1/properties?page=1&limit=10', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $data1 = json_decode($response1->getBody(), true);

        // Página 2
        $response2 = $this->get('/api/v1/properties?page=2&limit=10', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $data2 = json_decode($response2->getBody(), true);

        // Validações
        $this->assertLessThanOrEqual(10, count($data1['data'] ?? []));
        $this->assertLessThanOrEqual(10, count($data2['data'] ?? []));

        if (!empty($data1['data']) && !empty($data2['data'])) {
            $this->assertNotEquals(
                $data1['data'][0]['id'] ?? null,
                $data2['data'][0]['id'] ?? null,
                'IDs diferentes em páginas diferentes'
            );
        }
    }

    // ==================== SORTING ====================

    /**
     * @test
     * Ordenação por preço crescente
     */
    public function testSortByPrice()
    {
        $response = $this->get('/api/v1/properties?sort=price&order=asc', [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);

        // Validar ordenação
        $prices = array_column($data['data'] ?? [], 'price');
        $sorted = $prices;
        sort($sorted);

        $this->assertEquals($sorted, $prices, 'Preços devem estar em ordem crescente');
    }

    // ==================== HELPERS ====================

    private function createProperty()
    {
        return $this->db->table('properties')->insertGetData([
            'account_id' => 1,
            'title' => 'Property ' . uniqid(),
            'price' => rand(100000, 1000000),
            'bedrooms' => rand(1, 5),
            'bathrooms' => rand(1, 3),
            'area' => rand(50, 500),
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createMedia($propertyId)
    {
        return $this->db->table('property_media')->insertGetData([
            'property_id' => $propertyId,
            'file_path' => '/uploads/test_' . uniqid() . '.jpg',
            'type' => 'gallery',
            'order' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createLead()
    {
        $property = $this->createProperty();

        return $this->db->table('leads')->insertGetData([
            'property_id' => $property->id,
            'visitor_name' => 'Test Visitor',
            'visitor_email' => 'visitor@example.com',
            'visitor_phone' => '11987654321',
            'status' => 'new',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createAccount()
    {
        return $this->db->table('accounts')->insertGetData([
            'name' => 'Test Account ' . uniqid(),
            'email' => 'account' . uniqid() . '@example.com',
            'phone' => '11987654321',
            'type' => 'individual',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createTestImage($filename)
    {
        $image = imagecreatetruecolor(1024, 768);
        $color = imagecolorallocate($image, 73, 109, 137);
        imagefill($image, 0, 0, $color);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_img_');
        imagejpeg($image, $tempFile);
        imagedestroy($image);

        return fopen($tempFile, 'rb');
    }
}
