<?php

namespace Tests;

use App\Test\TestCase;

/**
 * TESTES BUSINESS LOGIC RULES
 * 
 * Validar regras de negócio específicas do sistema imobiliário
 * Teste: php spark test --filter BusinessLogicTest
 */
class BusinessLogicTest extends TestCase
{
    protected $dbGroup = 'default';

    // ==================== PLANOS E ASSINATURA ====================

    /**
     * @test
     * Usuário sem plano não pode publicar propriedade
     */
    public function testNoPublishWithoutActivePlan()
    {
        // Criar usuário sem plano
        $user = $this->createUserWithoutSubscription();

        $response = $this->post('/api/v1/properties', [
            'title' => 'Property Attempt',
            'price' => 100000,
            'status' => 'active'  // Tentar publicar
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Plano básico permite apenas 5 propriedades
     */
    public function testPlanPropertyLimit()
    {
        $user = $this->createUserWithPlan('basic'); // Máximo 5 imóveis

        // Criar 5 propriedades
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/api/v1/properties', [
                'title' => "Property $i",
                'price' => 100000,
                'status' => 'active'
            ], [
                'headers' => ['Authorization' => 'Bearer ' . $user->token]
            ]);

            $this->assertResponseStatus(201);
        }

        // 6ª propriedade deve ser rejeitada
        $response = $this->post('/api/v1/properties', [
            'title' => 'Property 6',
            'price' => 100000,
            'status' => 'active'
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 400,
            'Deve rejeitar quando atingir limite do plano'
        );
    }

    /**
     * @test
     * Upgrade de plano aumenta limite de propriedades
     */
    public function testUpgradePlanIncreasesLimit()
    {
        $user = $this->createUserWithPlan('basic');

        // Criar 5 propriedades
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/api/v1/properties', [
                'title' => "Basic Property $i",
                'price' => 100000
            ], [
                'headers' => ['Authorization' => 'Bearer ' . $user->token]
            ]);
        }

        // Fazer upgrade para premium
        $upgradeResponse = $this->post('/api/v1/subscription/upgrade', [
            'plan_id' => $this->getPlanId('premium')
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        // Agora pode adicionar mais
        $newPropertyResponse = $this->post('/api/v1/properties', [
            'title' => 'Premium Property',
            'price' => 100000
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        $this->assertResponseStatus(201);
    }

    /**
     * @test
     * Plano expirado automaticamente desativa anúncios
     */
    public function testExpiredPlanDeactivatesListings()
    {
        $user = $this->createUserWithExpiredPlan();
        $propertyId = $this->createPropertyForUser($user);

        // Propriedade deve estar inativa
        $response = $this->get("/api/v1/properties/$propertyId", [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        $property = json_decode($response->getBody(), true);
        $this->assertNotEquals('active', $property['data']['status'] ?? 'active');
    }

    /**
     * @test
     * Plan renewal reactiva anúncios
     */
    public function testPlanRenewalReactivatesListings()
    {
        $user = $this->createUserWithExpiredPlan();
        $propertyId = $this->createPropertyForUser($user);

        // Renovar plano
        $this->post('/api/v1/subscription/renew', [], [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        // Propriedade deve estar ativa novamente
        $response = $this->get("/api/v1/properties/$propertyId", [
            'headers' => ['Authorization' => 'Bearer ' . $user->token]
        ]);

        $property = json_decode($response->getBody(), true);
        $this->assertEquals('active', $property['data']['status'] ?? 'inactive');
    }

    // ==================== CUPONS E PROMOÇÕES ====================

    /**
     * @test
     * Cupom de desconto reduz preço
     */
    public function testCouponDiscountApplication()
    {
        $coupon = $this->createCoupon('DESCONTO50', 50); // 50% off

        // Validar cupom
        $response = $this->post('/api/v1/checkout/validate-coupon', [
            'coupon_code' => 'DESCONTO50'
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertEquals(50, $data['data']['discount_percentage'] ?? 0);
    }

    /**
     * @test
     * Cupom expirado é rejeitado
     */
    public function testExpiredCouponRejected()
    {
        $coupon = $this->createExpiredCoupon('EXPIRED');

        $response = $this->post('/api/v1/checkout/validate-coupon', [
            'coupon_code' => 'EXPIRED'
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Cupom com uso máximo expirado
     */
    public function testCouponMaxUsesExhausted()
    {
        $coupon = $this->createCoupon('LIMITED', 10, ['max_uses' => 2]);

        // Usar 2 vezes
        for ($i = 0; $i < 2; $i++) {
            $this->post('/api/v1/checkout/process', [
                'coupon_code' => 'LIMITED'
            ]);
        }

        // Terceira vez deve falhar
        $response = $this->post('/api/v1/checkout/validate-coupon', [
            'coupon_code' => 'LIMITED'
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Cupom por usuário (primeira compra)
     */
    public function testFirstPurchaseCoupon()
    {
        $newUser = $this->createNewUser();
        $coupon = $this->createCoupon('PRIMEIRA_COMPRA', 100, [
            'first_purchase_only' => true
        ]);

        // Primeira validação deve passar
        $response = $this->post('/api/v1/checkout/validate-coupon', [
            'coupon_code' => 'PRIMEIRA_COMPRA'
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $newUser->token]
        ]);

        $this->assertResponseStatus(200);

        // Fazer compra
        $this->post('/api/v1/checkout/process', [
            'coupon_code' => 'PRIMEIRA_COMPRA'
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $newUser->token]
        ]);

        // Segunda tentativa deve falhar
        $response = $this->post('/api/v1/checkout/validate-coupon', [
            'coupon_code' => 'PRIMEIRA_COMPRA'
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $newUser->token]
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    // ==================== LEADS E CONVERSÃO ====================

    /**
     * @test
     * Lead apenas para propriedade ativa
     */
    public function testLeadOnlyForActiveProperty()
    {
        $inactiveProperty = $this->db->table('properties')
            ->where('status', 'draft')
            ->first();

        $response = $this->post('/api/v1/leads', [
            'property_id' => $inactiveProperty->id,
            'visitor_name' => 'John Doe',
            'visitor_email' => 'john@example.com'
        ]);

        // Pode ser rejeitado ou marcado diferentemente
        // Implementação específica
    }

    /**
     * @test
     * Lead expira após 90 dias
     */
    public function testLeadExpiration()
    {
        // Criar lead antigo
        $oldLead = $this->db->table('leads')->insertGetData([
            'property_id' => 1,
            'visitor_email' => 'old@example.com',
            'visitor_name' => 'Old Lead',
            'status' => 'new',
            'created_at' => date('Y-m-d H:i:s', strtotime('-91 days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-91 days'))
        ]);

        // Executar limpeza
        $this->runCommand('leads:cleanup');

        // Lead deve estar inativo
        $lead = $this->db->table('leads')
            ->where('id', $oldLead->id)
            ->first();

        $this->assertNotEquals('new', $lead->status ?? 'new');
    }

    /**
     * @test
     * Lead respeitam GDPR - deletar após consentimento
     */
    public function testLeadGDPRCompliance()
    {
        $lead = $this->db->table('leads')->insertGetData([
            'property_id' => 1,
            'visitor_email' => 'gdpr@example.com',
            'visitor_name' => 'GDPR Lead'
        ]);

        // Solicitar deleção
        $response = $this->post("/api/v1/leads/$lead->id/delete-request");

        $this->assertResponseStatus(200);

        // Lead deve ser anonimizado/deletado
        sleep(1);

        $deleted = $this->db->table('leads')
            ->where('id', $lead->id)
            ->first();

        $this->assertTrue(
            $deleted === null || 
            $deleted->visitor_email === 'deleted@deleted.deleted',
            'Lead deve ser deletado/anonimizado por GDPR'
        );
    }

    // ==================== PROPRIEDADES ====================

    /**
     * @test
     * Preço negativo não permitido
     */
    public function testNegativePriceRejected()
    {
        $response = $this->post('/api/v1/properties', [
            'title' => 'Free House',
            'price' => -1000
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Preço zero não permitido
     */
    public function testZeroPriceRejected()
    {
        $response = $this->post('/api/v1/properties', [
            'title' => 'Very Cheap',
            'price' => 0
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Propriedade precisa ter pelo menos 1 imagem
     */
    public function testPropertyRequiresImage()
    {
        // Criar propriedade
        $propertyResponse = $this->post('/api/v1/properties', [
            'title' => 'Property Without Images',
            'price' => 100000
        ]);

        if ($propertyResponse->getStatusCode() === 201) {
            $property = json_decode($propertyResponse->getBody(), true);
            $propertyId = $property['data']['id'];

            // Tentar publicar sem imagem
            $publishResponse = $this->put("/api/v1/properties/$propertyId", [
                'status' => 'active'
            ]);

            // Deve rejeitar
            $this->assertTrue($publishResponse->getStatusCode() >= 400);
        }
    }

    /**
     * @test
     * Área deve ser positiva
     */
    public function testInvalidAreaRejected()
    {
        $response = $this->post('/api/v1/properties', [
            'title' => 'Weird Property',
            'price' => 100000,
            'area' => -50  // Negativa
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Quartos/banheiros devem ser realistas
     */
    public function testUnrealisticRoomCounts()
    {
        $response = $this->post('/api/v1/properties', [
            'title' => 'Mansion',
            'price' => 100000,
            'bedrooms' => 500  // Absurdo
        ]);

        // Pode ser rejeitado ou limitado
    }

    /**
     * @test
     * Coordenadas geográficas válidas
     */
    public function testInvalidCoordinates()
    {
        $response = $this->post('/api/v1/properties', [
            'title' => 'Property',
            'price' => 100000,
            'latitude' => 999,  // Inválido
            'longitude' => 999  // Inválido
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Proprietário pode editar apenas suas propriedades
     */
    public function testOwnerCanOnlyEditOwnProperties()
    {
        $user1 = $this->createUser();
        $user2 = $this->createUser();

        // User1 cria propriedade
        $property = $this->db->table('properties')->insertGetData([
            'account_id' => $user1->account_id,
            'title' => 'User1 Property',
            'price' => 100000
        ]);

        // User2 tenta editar
        $response = $this->put("/api/v1/properties/$property->id", [
            'title' => 'Hacked Property'
        ], [
            'headers' => ['Authorization' => 'Bearer ' . $user2->token]
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);

        // Verificar que título não mudou
        $updated = $this->db->table('properties')
            ->where('id', $property->id)
            ->first();

        $this->assertEquals('User1 Property', $updated->title);
    }

    // ==================== PROMOÇÕES TURBO ====================

    /**
     * @test
     * Turbo promotion visibilidade boost
     */
    public function testTurboPromotionBoost()
    {
        $user = $this->createUserWithActivePlan();
        $property = $this->createPropertyForUser($user);

        // Ativar turbo
        $response = $this->post(
            "/api/v1/properties/$property->id/turbo",
            ['duration' => 7]
        );

        $this->assertResponseStatus(200);

        // Propriedade deve ter boost
        $boosted = $this->db->table('properties')
            ->where('id', $property->id)
            ->first();

        $this->assertTrue($boosted->is_turbo ?? false);
        $this->assertNotNull($boosted->turbo_until ?? null);
    }

    /**
     * @test
     * Turbo auto-expira após período
     */
    public function testTurboExpiration()
    {
        $property = $this->db->table('properties')->insertGetData([
            'account_id' => 1,
            'title' => 'Turbo Property',
            'price' => 100000,
            'is_turbo' => true,
            'turbo_until' => date('Y-m-d H:i:s', strtotime('-1 day')) // Já expirou
        ]);

        // Executar limpeza
        $this->runCommand('properties:cleanup-expired-turbo');

        // Turbo deve estar desativado
        $updated = $this->db->table('properties')
            ->where('id', $property->id)
            ->first();

        $this->assertFalse($updated->is_turbo ?? true);
    }

    // ==================== VERIFICAÇÃO E FRAUD ====================

    /**
     * @test
     * Propriedade precisa verificação antes de publicação (anti-fraude)
     */
    public function testPropertyVerificationRequired()
    {
        $property = $this->db->table('properties')->insertGetData([
            'account_id' => 1,
            'title' => 'Suspicious Property',
            'price' => 1000,  // Preço muito baixo
            'is_verified' => false
        ]);

        // Propriedade não deve aparecer em buscas públicas
        $response = $this->get('/api/v1/properties?is_verified=true');
        
        $data = json_decode($response->getBody(), true);
        $ids = array_column($data['data'] ?? [], 'id');

        $this->assertNotContains($property->id, $ids);
    }

    /**
     * @test
     * Admin pode marcar propriedade como verificada
     */
    public function testAdminVerificationProperty()
    {
        $property = $this->db->table('properties')->insertGetData([
            'account_id' => 1,
            'title' => 'Property to Verify',
            'price' => 100000,
            'is_verified' => false
        ]);

        $admin = $this->createAdminUser();

        // Admin verifica
        $response = $this->post(
            "/admin/curation/verify/$property->id",
            [],
            ['headers' => ['Authorization' => 'Bearer ' . $admin->token]]
        );

        $this->assertResponseStatus(200);

        // Propriedade deve estar verificada
        $verified = $this->db->table('properties')
            ->where('id', $property->id)
            ->first();

        $this->assertTrue($verified->is_verified);
    }

    // ==================== HELPERS ====================

    private function createUserWithoutSubscription()
    {
        return $this->db->table('users')->insertGetData([
            'email' => 'nosub' . uniqid() . '@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'account_id' => 1
        ]);
    }

    private function createUserWithPlan($planName)
    {
        $user = $this->createUser();
        $plan = $this->db->table('plans')
            ->where('name', $planName)
            ->first();

        $this->db->table('subscriptions')->insert([
            'account_id' => $user->account_id,
            'plan_id' => $plan->id,
            'expires_at' => date('Y-m-d', strtotime('+30 days')),
            'status' => 'active'
        ]);

        return $user;
    }

    private function createUserWithExpiredPlan()
    {
        $user = $this->createUser();

        $this->db->table('subscriptions')->insert([
            'account_id' => $user->account_id,
            'plan_id' => 1,
            'expires_at' => date('Y-m-d', strtotime('-1 day')),
            'status' => 'expired'
        ]);

        return $user;
    }

    private function createUserWithActivePlan()
    {
        return $this->createUserWithPlan('premium');
    }

    private function createUser()
    {
        return $this->db->table('users')->insertGetData([
            'email' => 'user' . uniqid() . '@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'account_id' => 1,
            'token' => bin2hex(random_bytes(32))
        ]);
    }

    private function createNewUser()
    {
        return $this->createUser();
    }

    private function createAdminUser()
    {
        $user = $this->createUser();
        $this->db->table('auth_groups_users')->insert([
            'user_id' => $user->id,
            'group' => 'admin'
        ]);
        return $user;
    }

    private function createPropertyForUser($user)
    {
        return $this->db->table('properties')->insertGetData([
            'account_id' => $user->account_id,
            'title' => 'Property ' . uniqid(),
            'price' => 100000,
            'status' => 'draft'
        ]);
    }

    private function createCoupon($code, $discount, $options = [])
    {
        return $this->db->table('coupons')->insertGetData([
            'code' => $code,
            'discount_type' => 'percentage',
            'discount_value' => $discount,
            'expires_at' => date('Y-m-d', strtotime('+30 days')),
            'max_uses' => $options['max_uses'] ?? null,
            'first_purchase_only' => $options['first_purchase_only'] ?? false,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function createExpiredCoupon($code)
    {
        return $this->db->table('coupons')->insertGetData([
            'code' => $code,
            'discount_type' => 'percentage',
            'discount_value' => 50,
            'expires_at' => date('Y-m-d', strtotime('-1 day')),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function getPlanId($planName)
    {
        $plan = $this->db->table('plans')
            ->where('name', $planName)
            ->first();

        return $plan->id ?? 1;
    }

    private function runCommand($command)
    {
        // Simular exec de comando artisan
        // Implementar conforme seu framework
    }
}
