# 🔧 GUIA DE REMEDIAÇÃO DE VULNERABILIDADES
## Sistema ZAP - Prioridades e Soluções

---

## 🔴 CRÍTICO - CORRIJA HOJE

### 1. SQL Injection
**Arquivo**: `app/Controllers/Admin/PaymentGatewayController.php:169`

**Antes** ❌:
```php
public function setPrimary($id)
{
    $db->query("UPDATE payment_gateways SET is_primary = false");
    $db->table('payment_gateways')->where('id', $id)->update(['is_primary' => true]);
}
```

**Depois** ✅:
```php
public function setPrimary($id)
{
    // Usar QueryBuilder (parametrizado)
    $this->db->table('payment_gateways')
        ->whereNotIn('id', [$id])
        ->update(['is_primary' => false]);
    
    $this->db->table('payment_gateways')
        ->where('id', $id)
        ->update(['is_primary' => true]);
}
```

**Checklist**:
- [ ] Substituir raw queries por QueryBuilder
- [ ] Nunca concatenar strings em SQL
- [ ] Usar `->where()` com parâmetros
- [ ] Testar com: `php spark test --filter SecurityTest::testSQLInjection`
- [ ] Code review antes de merge

---

### 2. Autorização Fraca
**Arquivo**: `app/Controllers/Admin/LeadsController.php`

**Antes** ❌:
```php
public function updateStatus($id)
{
    $status = $this->request->getPost('status');
    // ❌ SEM VERIFICAÇÃO!
    $this->leadModel->update($id, ['status' => $status]);
    return $this->json(['success' => true]);
}
```

**Depois** ✅:
```php
public function updateStatus($id)
{
    $status = $this->request->getPost('status');
    
    // Verificar que lead pertence ao usuário
    $lead = $this->leadModel
        ->join('properties', 'properties.id = leads.property_id')
        ->where('leads.id', $id)
        ->where('properties.account_id', Auth::user()->account_id)
        ->first();
    
    if (!$lead) {
        throw new AuthorizationException('Acesso negado');
    }
    
    $this->leadModel->update($id, ['status' => $status]);
    return $this->json(['success' => true]);
}
```

**Implementar Middleware**:
```php
// app/Filters/AuthorizeResource.php
class AuthorizeResource implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = Auth::user();
        $resourceId = $request->uri->getSegment(4); // /api/v1/leads/{id}
        
        $resource = $this->getResource($resourceId);
        
        if ($resource->account_id !== $user->account_id) {
            throw new AuthorizationException();
        }
    }
}
```

**Checklist**:
- [ ] Adicionar validação em todos endpoints de recurso
- [ ] Implementar middleware de autorização
- [ ] Testar cross-account access: `php spark test --filter CRUDFlowTest::testCrossAccountDataAccess`
- [ ] Code review com security focus
- [ ] Testes de integração passando

---

## 🟠 ALTO - CORRIJA EM 1-2 SPRINTS

### 3. CSRF Token Protection
**Arquivo**: Múltiplos - Admin formulários

**Setup**:
```php
// .env
CSRF_HEADER_NAME = X-CSRF-TOKEN
CSRF_COOKIE_SECURE = true
CSRF_COOKIE_HTTPONLY = true
CSRF_COOKIE_SAMESITE = Strict
```

**Em Formulários HTML**:
```php
<!-- Adicionar em TODOS os formulários -->
<form method="POST" action="/admin/properties">
    <?= csrf_field() ?>
    <input type="text" name="title" />
    <button type="submit">Salvar</button>
</form>
```

**Em APIs (JSON)**:
```javascript
// Frontend
fetch('/api/v1/properties', {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': document.querySelector('[name="csrf"]').value,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({...})
});
```

**Backend Validação** (já feito no CI4, mas confirmar):
```php
// Em Controller
public function store()
{
    // CodeIgniter valida automaticamente se $this->request->getPost('_token')
    // ou header 'X-CSRF-TOKEN' presente
    
    // Para APIs, validar manualmente:
    if (!$this->validateCSRFToken()) {
        throw new TokenMismatchException();
    }
}

private function validateCSRFToken()
{
    $token = $this->request->getHeaderLine('X-CSRF-TOKEN') 
        ?: $this->request->getPost('_token');
    
    return hash_equals(session('_token'), $token);
}
```

**Checklist**:
- [ ] Adicionar `csrf_field()` em todos forms
- [ ] Validar token em todos POST/PUT/DELETE
- [ ] Testar: `php spark test --filter SecurityTest::testCSRFTokenRequired`
- [ ] Frontend atualizado com token
- [ ] Cookies configurados com flags de segurança

---

### 4. XSS Protection - Content Security Policy
**Arquivo**: Múltiplos - output em views

**Adicionar Headers**:
```php
// app/Filters/SecurityHeaders.php
class SecurityHeaders implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');
        
        // CSP - Block inline scripts
        $response->setHeader('Content-Security-Policy', 
            "default-src 'self'; " .
            "script-src 'self' https://cdn.example.com; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data: https:; " .
            "font-src 'self'; " .
            "frame-ancestors 'none';"
        );
        
        // XSS Protection
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
```

**Escapar Output**:
```php
// ❌ ANTES
<h1><?= $property->title ?></h1>

// ✅ DEPOIS
<h1><?= esc($property->title, 'html') ?></h1>

// Para atributos
<img src="<?= esc($property->image, 'attr') ?>"/>

// Para JavaScript
<script>
var property = <?= json_encode($property) ?>;
</script>
```

**Testar XSS**:
```bash
# Tentar payload
curl -X POST http://localhost:8080/api/v1/properties \
  -H "Content-Type: application/json" \
  -d '{"title": "<img src=x onerror=alert(1)>"}'

# Verificar se foi escapado
curl http://localhost:8080/api/v1/properties | grep "&lt;img"
```

**Checklist**:
- [ ] CSP headers implementados
- [ ] Todos outputs escapados com `esc()`
- [ ] Testar: `php spark test --filter SecurityTest::testXSSInPropertyTitle`
- [ ] Browser headers verificados
- [ ] Frontend sanitização também implementada

---

### 5. Rate Limiting
**Arquivo**: Múltiplos - Auth e APIs

**Implementar Middleware**:
```php
// app/Filters/RateLimiter.php
class RateLimiter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $ip = $request->getIPAddress();
        $path = $request->getPath();
        $key = "rate_limit:{$ip}:{$path}";
        
        $cache = service('cache');
        $limit = $arguments[0] ?? 10;  // 10 requisições
        $window = $arguments[1] ?? 60; // em 60 segundos
        
        $current = $cache->get($key) ?? 0;
        
        if ($current >= $limit) {
            return service('response')
                ->setStatusCode(429)
                ->setJSON(['message' => 'Too many requests']);
        }
        
        $cache->save($key, $current + 1, $window);
    }
}
```

**Aplicar a Rotas Críticas**:
```php
// app/Config/Routes.php

// Auth - máximo 5 tentativas por minuto
$routes->post('auth/login', 'AuthController::login')
    ->filter('rate_limit:5,60');

// API - máximo 100 requests por minuto
$routes->group('api/v1', function($routes) {
    $routes->get('properties', 'PropertyController::index')
        ->filter('rate_limit:100,60');
}, ['filter' => 'rate_limit:100,60']);
```

**Testar Rate Limit**:
```bash
#!/bin/bash
for i in {1..50}; do
    curl -s -X POST http://localhost:8080/auth/login \
        -d "email=test@example.com&password=attempt$i" \
        -w "Status: %{http_code}\n" | tail -1
done
# Após 5 tentativas, deve retornar 429
```

**Checklist**:
- [ ] Middleware implementado
- [ ] Aplicado em /auth/login
- [ ] Aplicado em APIs
- [ ] Redis/Cache configurado
- [ ] Testar: `php spark test --filter SecurityTest::testBruteForceProtection`
- [ ] Response headers com X-RateLimit-*

---

## 🟡 MÉDIO - CORRIJA EM 3-4 SPRINTS

### 6. IDOR Protection
```php
// Adicionar em BaseController
protected function authorize($resourceId)
{
    $user = Auth::user();
    
    // Verificar propriedade
    $resource = $this->model->find($resourceId);
    
    if (!$resource || $resource->account_id !== $user->account_id) {
        throw new AuthorizationException('Resource not found');
    }
    
    return $resource;
}

// Usar em Controllers
public function show($id)
{
    $property = $this->authorize($id); // ✅ Verificar
    return $this->json($property);
}
```

### 7. Image Processing - Remove EXIF
```php
// app/Libraries/ImageProcessor.php
public function processImage($filePath)
{
    // Remover EXIF
    $image = new \Imagick($filePath);
    $image->stripImage(); // Remove EXIF e comentários
    $image->setImageCompressionQuality(85);
    $image->writeImage($filePath);
    
    // Gerar thumbnail
    $thumb = clone $image;
    $thumb->scaleImage(300, 300, true);
    $thumb->writeImage(str_replace('.jpg', '_thumb.jpg', $filePath));
}
```

### 8. Logging - Não logar sensíveis
```php
// ❌ ANTES
log_message('info', "Payment: " . json_encode($paymentData));

// ✅ DEPOIS
$safeData = [
    'amount' => $paymentData['amount'],
    'status' => $paymentData['status'],
    'card_last4' => substr($paymentData['card'], -4)
];
log_message('info', "Payment: " . json_encode($safeData));
```

---

## ✅ CHECKLIST GERAL DE REMEDIAÇÃO

- [ ] **SQL Injection** - Todos QueryBuilder
- [ ] **Autorização** - Verificação em todos endpoints
- [ ] **CSRF** - Tokens em formulários
- [ ] **XSS** - CSP headers + esc() em output
- [ ] **Rate Limiting** - Middleware em /auth e /api
- [ ] **IDOR** - Validação de ownership
- [ ] **Image** - EXIF removido
- [ ] **Logging** - Sem dados sensíveis
- [ ] **Testes** - 95%+ passing
- [ ] **Code Review** - Security review approval
- [ ] **Deploy** - Com WAF ativado

---

## 🚀 PRÓXIMA SPRINT

**Semana 1**:
- [ ] SQL Injection fix
- [ ] Autorização fix
- [ ] CSRF tokens
- [ ] re-test

**Semana 2**:
- [ ] Rate limiting
- [ ] XSS headers
- [ ] Image processing
- [ ] Deploy staging

**Semana 3+**:
- [ ] IDOR protection
- [ ] Logging cleanup
- [ ] Monitoring setup
- [ ] Deploy production

---

**Pronto para contribuir com melhorias?**  
Seguir esta priorização garante segurança máxima em tempo mínimo.
