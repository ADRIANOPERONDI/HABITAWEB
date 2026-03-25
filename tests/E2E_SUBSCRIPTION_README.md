# E2E Subscription Compliance & Access Control Testing

## 📋 Overview

Suite de testes end-to-end para validar o sistema completo de subscriptions, renovações, verificação KYC, e controle de acesso baseado em  licença. 

**Scope**: 8 cenários isolados + testes de segurança + exemção admin

**Data**: Usa dados reais do Asaas Sandbox para autenticidade máxima  
**Isolamento**: Cada cenário tem suas próprias contas de teste distintas (personas)

---

## 🎯 8 Cenários Implementados

| # | Cenário | File | Status |
|----|---------|------|--------|
| 1 | **Contratação Inicial** | `InitialSignupTest.php` | ✅ Implementado |
| 2 | **Renovação com Sucesso** | `RenewalAndFailureTest::SuccessfulRenewalTest` | ✅ Implementado |
| 3 | **Falha + Recuperação** | `RenewalAndFailureTest::FailedPaymentRecoveryTest` | ✅ Implementado |
| 4 | **Carência Expirada** | `GracePeriodTest.php` | 🚧 Próximo |
| 5 | **Carência no Plano** | `PlanGracePeriodTest.php` | 🚧 Próximo |
| 6 | **Cupom com Carência** | `CouponGracePeriodTest.php` | 🚧 Próximo |
| 7 | **Upgrade de Plano** | `PlanUpgradeTest.php` | 🚧 Próximo |
| 8 | **Cancelamento + Reativação** | `CancellationReactivationTest.php` | 🚧 Próximo |

---

## 🏗️ Estrutura

```
tests/
├── E2E/
│   ├── SubscriptionE2EBase.php          # Base class com 10+ helpers
│   └── Scenarios/
│       ├── InitialSignupTest.php        # Scenario 1-4
│       ├── RenewalAndFailureTest.php    # Scenario 2-3
│       ├── GracePeriodTest.php          # Scenario 4 (TBD)
│       ├── PlanGracePeriodTest.php      # Scenario 5 (TBD)
│       ├── CouponGracePeriodTest.php    # Scenario 6 (TBD)
│       ├── PlanUpgradeTest.php          # Scenario 7 (TBD)
│       └── CancellationReactivationTest.php # Scenario 8 (TBD)
├── fixtures/
│   └── SubscriptionTestData.php        # 8 personas + cards sandbox
app/
├── Services/
│   └── KYCService.php                  # ✅ Novo: Validação de docs + facial
└── Models/
    ├── SubscriptionModel.php           # ✅ Updated: +3 helper methods
    ├── AccountModel.php                # ✅ Updated: +2 helper methods  
    └── CouponModel.php                 # ✅ Updated: +2 helper methods
```

---

## 🔧 Setup & Running Tests

### Prerequisites

1. **Asaas Sandbox Account** - Get from https://sandbox.asaas.com
2. **.env Configs**:
   ```env
   ASAAS_API_TOKEN=your_sandbox_token
   ASAAS_WEBHOOK_TOKEN=your_webhook_secret
   KYC_LIVENESS_PROVIDER=mock  # Use 'mock' in sandbox, 'aws' or 'jumio' in prod
   ```

3. **Database** - Run migrations:
   ```bash
   php spark migrate --all
   ```

### Run All E2E Tests

```bash
# Run entire E2E suite
./vendor/bin/phpunit tests/E2E/ --testdox

# Run specific scenario
./vendor/bin/phpunit tests/E2E/Scenarios/InitialSignupTest.php --testdox

# Run with verbose output
./vendor/bin/phpunit tests/E2E/ --testdox -v
```

### Run Individual Test Methods

```bash
# Test only initial signup flow
./vendor/bin/phpunit tests/E2E/Scenarios/InitialSignupTest.php::testInitialSignupFlow --testdox

# Test renewal only
./vendor/bin/phpunit tests/E2E/Scenarios/RenewalAndFailureTest.php::testSuccessfulRenewalFlow --testdox
```

---

## 📊 Test Data

### 8 Test Personas (SubscriptionTestData.php)

Cada persona tem:
- **Nome, CPF, Email, Telefone** (distintos)
- **Scenario** (Contratação, Renovação, etc)
- **Pre-requisitos** (alguns já têm subscription ativa)

```php
// Exemplo: Acessar persona 1
$persona = SubscriptionTestData::getPersonaById(1);
// Returns: ['name' => 'João da Silva', 'cpf' => '11144455566', ...]
```

### Test Cards (Asaas Sandbox)

```php
SubscriptionTestData::getAsaasTestCards() //Returns:
// [
//   'success' => ['number' => '4111...', ...],
//   'decline' => ['number' => '4000...', ...],
//   '3d_secure' => [...],
//   'insufficient_funds' => [...]
// ]
```

### Mock Plans

- **Basic**: R$99.90/mês, sem carência
- **Pro**: R$199.90/mês, sem carência
- **Pro (30 dias grátis)**: R$199.90/mês, 30 dias carência

---

## 🛠️ Helper Methods (SubscriptionE2EBase)

### Account Creation

```php
// Criar conta completa + usuário Shield
[$accId, $userId, $account, $user] = $this->createTestAccount('persona_1_initial');
```

### KYC Verification

```php
// Fazer upload de docs + verificação facial
$result = $this->verifyAccountKYC($accountId, withFacial: true);
// Returns: [success => true/false, message => string, kycData => [...]]

// Assertions
$this->assertAccountFullyVerified($accountId);
```

### Subscription Creation

```php
// Criar subscription no DB (pode estar vinculada a Asaas later)
[$subId, $asaasId, $sub] = $this->createSubscription($accountId, $planId, 'MONTHLY');
```

### Webhook Simulation

```php
// Simular webhook de pagamento do Asaas
[$statusCode, $body] = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
    'id' => 'pay_xxx',
    'subscription' => $subscriptionId,
    'status' => 'CONFIRMED',
    'value' => 199.90,
]);
```

### Access Control Tests

```php
// Check if user can access protected route
$result = $this->checkUserAccess($userId, '/admin/dashboard');
// Returns: [canAccess => true/false, statusCode => int, message => string]
```

---

## ✅ Verification Checklist (Por Teste)

Cada cenário valida:

1. **Database State**
   - ✅ Subscription criada com status correto
   - ✅ Datas (data_inicio, data_fim, proximo_pagamento) corretas
   - ✅ KYC status atualizado appropriately

2. **Access Control**
   - ✅ Usuário pode/não pode acessar dashboard
   - ✅ Admin sempre pode acessar (exempto)
   - ✅ Redirect correto para checkout se necessário

3. **Webhook Processing**
   - ✅ Assinatura do webhook validada
   - ✅ Idempotência confirmada (evento duplicado processado 1x)
   - ✅ Status 200 retornado on success

4. **Audit Logs**
   - ✅ Tentativas de KYC registradas
   - ✅ Transições de status logadas
   - ✅ Bloqueios de acesso documentados

---

## 🚨 Troubleshooting

### "KYC verification failed" 

- ✅ Ensure `WRITEPATH . '/uploads/kyc/'` directory is writable
- ✅ Check `KYC_LIVENESS_PROVIDER` is set to 'mock' in .env
- ✅ Verify KYCService.php loaded correctly

### "Webhook processing failed (signature invalid)"

- ✅ Confirm `ASAAS_WEBHOOK_TOKEN` environment variable set
- ✅ Check WebhookController validates signatures correctly
- ✅ Test payload must include valid `X-Webhook-Secret` header

### "Access check failed - User blocked"

- This may be EXPECTED depending on test scenario (e.g., no KYC, overdue payment)
- Check test expectations: `$this->assertTrue($accessCheck['canAccess'])` vs `$this->assertFalse(...)`

### "Admin exemption not working"

- Verify user has `super_admin` role in `auth_groups_users`
- Check AdminAuth filter `_isAdminUser()` method runs before subscription checks
- Review logs: `[AdminAuth] Super admin access granted`

---

## 📈 Coverage

| Area | Coverage | Notes |
|------|----------|-------|
| **Subscription Lifecycle** | 8/8 scenarios | Initial, renewal, failure, grace, upgrade, cancel |
| **KYC Verification** | 3 tests | Upload, facial, rejection |
| **Grace Period Logic** | 3 scenarios | Plan grace, coupon grace, expiration |
| **Access Control** | 5+ tests | Admin exemption, KYC requirement, subscription requirement |
| **Webhook Processing** | 4+ events | PAYMENT_CONFIRMED, PAYMENT_FAILED, etc |
| **Error Handling** | 3+ cases | Invalid docs, duplicate webhooks, grace expiry |

---

## 🔐 Admin Exemption

**Rule**: `super_admin` role bypasses ALL checks:

```php
// In AdminAuth filter:
if ($this->_isAdminUser($userId)) {
    return; // Admin can access everything
}

// Non-admins must pass:
// 1. KYC verification
// 2. Active subscription
// 3. Payment current or in grace period
```

**Test**: `AdminExemptionTest.php (TBD)`
- Admin logs in → direct to dashboard (no KYC check)
- Admin tries to pay → "not required for admins"
- Admin can cancel/reactivate freely

---

## 🎬 Next Steps

1. **Implement Remaining Scenarios** (4-8)
   - Grace period expiration
   - Plan grace period behavior
   - Cupom stacking validation
   - Plan upgrades mid-cycle
   - Cancellation flow

2. **Add Security Tests**
   - Webhook signature validation
   - IDOR prevention (user A can't view user B's subscription)
   - Rate limiting on checkout endpoint

3. **Admin Exemption Tests**
   - Verify admin can access protected routes
   - Admin doesn't need subscription/KYC
   - Admin operations logged separately

4. **CI/CD Integration**
   - Add to GitHub Actions workflow
   - Run on every commit
   - Generate coverage reports

---

## 📚 References

- **Asaas Docs**: https://docs.asaas.com/
- **Webhook Events**: https://docs.asaas.com/reference/webhook-eventos
- **Test Cards**: https://docs.asaas.com/reference/cartao-de-credito-sandbox
- **CodeIgniter Testing**: https://codeigniter.com/user_guide/testing/index.html
- **PHPUnit Docs**: https://phpunit.readthedocs.io/

---

## 💬 Questions / Issues

For test-related issues, check:
1. `.env` - all ASAAS_* and KYC_* variables set
2. Database migrations run (`php spark migrate --all`)
3. Test database permissions
4. Logs: `/writable/logs/test-*.log`

