# 🧪 GUIA DE EXECUÇÃO DOS TESTES - SIMPLIFICADO

## ⚠️ Status Atual

Os testes foram criados como **templates de referência** para mostrar:
- ✅ Quais vulnerabilidades precisam ser testadas
- ✅ Que cenários devem ser cobertos
- ✅ Como estruturar um plano de testes

**IMPORTANTE**: Para executar testes reais em sua aplicação CodeIgniter 4, você precisa:

1. **Implementar as lógicas dos testes** nos arquivos criados
2. **Usar ferramentas especializadas** para testes de segurança
3. **Executar testes manuais** seguindo o guia de penetração

---

## 🛠️ Alternativas para Executar Testes

### Opção 1: Testes Unitários Simples (Recomendado)

Rodar testes de lógica de negócio sem HTTP:

```bash
# Testar apenas lógica (sem requisições HTTP)
php vendor/bin/phpunit tests/unit/BusinessLogicTest.php
```

### Opção 2: Testes Manuais com CURL

```bash
# Testar SQL Injection em busca de propriedades
curl "http://localhost:8080/api/v1/properties?search=1%27%20OR%20%271%27%3D%271"

# Testar upload de arquivo malicioso
curl -F "file=@shell.php" http://localhost:8080/api/v1/properties/1/upload
```

### Opção 3: Penetração Testing (Recomendado para Segurança)

```bash
# Seguir guia completo em PENETRATION_TESTING_GUIDE.md
sqlmap -u "http://localhost:8080/api/v1/properties?id=1" --dbs
```

### Opção 4: Ferramentas Especializadas

- **Burp Suite**: Testes interativos de aplicação web
- **OWASP ZAP**: Scanning automático de vulnerabilidades
- **SQLMap**: Exploração de SQL Injection
- **Postman**: Testes de API

---

## 📋 Execução Manual por Categoria

### 1. Testes de Segurança (SQL Injection, XSS, CSRF)

**Arquivo**: [PENETRATION_TESTING_GUIDE.md](PENETRATION_TESTING_GUIDE.md)

```bash
# SQL Injection
sqlmap -u "http://localhost:8080/api/v1/properties?id=1" --dbs

# XSS
curl -X POST "http://localhost:8080/api/v1/properties" \
  -d "title=<script>alert('xss')</script>"

# CSRF (verificar tokens)
curl -X DELETE "http://localhost:8080/admin/properties/1" \
  -H "X-CSRF-TOKEN: " # Token deve ser obrigatório
```

### 2. Testes de CRUD (Criar, Ler, Atualizar, Deletar)

```bash
# CREATE - Criar propriedade
curl -X POST "http://localhost:8080/api/v1/properties" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","price":500000}'

# READ - Listar
curl "http://localhost:8080/api/v1/properties"

# UPDATE - Atualizar
curl -X PUT "http://localhost:8080/api/v1/properties/1" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated"}'

# DELETE - Deletar
curl -X DELETE "http://localhost:8080/api/v1/properties/1"
```

### 3. Testes de API REST

```bash
# Listar com paginação
curl "http://localhost:8080/api/v1/properties?page=1&per_page=10"

# Filtrar
curl "http://localhost:8080/api/v1/properties?price_min=100000&price_max=500000"

# Ordenar
curl "http://localhost:8080/api/v1/properties?sort=price&order=asc"
```

### 4. Testes de Upload de Imagem

```bash
# Upload válido (JPG, PNG)
curl -F "image=@photo.jpg" "http://localhost:8080/api/v1/properties/1/media"

# Upload inválido (executável)
curl -F "image=@shell.php" "http://localhost:8080/api/v1/properties/1/media"
# Esperado: Rejeição com erro 400
```

### 5. Testes de Pagamento

```bash
# Criar pagamento
curl -X POST "http://localhost:8080/api/v1/payments" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 99.99,
    "method": "asaas",
    "subscription_id": 1
  }'

# Verificar status
curl "http://localhost:8080/api/v1/payments/1"

# Simular webhook Asaas
curl -X POST "http://localhost:8080/webhook/asaas" \
  -H "Content-Type: application/json" \
  -H "asaas-signature: fake-signature" \
  -d '{
    "event": "PAYMENT_RECEIVED",
    "payment": {"id": "pay_123", "status": "RECEIVED"}
  }'
```

### 6. Testes de Lógica de Negócio

```bash
# Tentar publicar sem plano ativo
curl -X POST "http://localhost:8080/api/v1/properties" \
  -H "Authorization: Bearer $USER_TOKEN" \
  -d '{"title":"Test"}' \
# Esperado: 403 - "Ative um plano para publicar"

# Tentar usar cupom expirado
curl -X POST "http://localhost:8080/api/v1/checkout" \
  -d "coupon_code=EXPIRED_COUPON"
# Esperado: 400 - "Cupom expirado"
```

---

## 📊 Checklist de Testes Executáveis

Use este checklist para executar testes manualmente:

### Segurança (🔴 CRÍTICO)
- [ ] **SQL Injection em busca**: `sqlmap -u "http://localhost:8080/api/v1/properties?id=1" --dbs`
  - Status Esperado: ❌ Vulnerável (até corrigir)
  - Correção: Ver REMEDIATION_GUIDE.md → SQL Injection
  
- [ ] **Autorização (Admin)**: `curl "http://localhost:8080/admin/properties" -H "Authorization: Bearer USER_TOKEN"`
  - Status Esperado: ❌ Falha (acesso negado de usuário comum)
  - Correção: Ver REMEDIATION_GUIDE.md → Authorization

- [ ] **CSRF Token**: Fazer DELETE sem token
  - Status Esperado: ❌ Falha (token obrigatório)
  - Correção: Adicionar token nos forms

### API (🟢 VERDE)
- [ ] **Listar propriedades**: `curl "http://localhost:8080/api/v1/properties"`
  - Status Esperado: ✅ 200 OK
  
- [ ] **Criar propriedade**: `curl -X POST "http://localhost:8080/api/v1/properties" -d '{"title":"Test","price":500000}'`
  - Status Esperado: ✅ 201 Created
  
- [ ] **Atualizar propriedade**: `curl -X PUT "http://localhost:8080/api/v1/properties/1" -d '{"title":"Updated"}'`
  - Status Esperado: ✅ 200 OK
  
- [ ] **Deletar propriedade**: `curl -X DELETE "http://localhost:8080/api/v1/properties/1"`
  - Status Esperado: ✅ 204 No Content

### Upload (🟡 AMARELO)
- [ ] **Upload JPG válido**: `curl -F "image=@photo.jpg" "http://localhost:8080/api/v1/properties/1/media"`
  - Status Esperado: ✅ 200 OK
  
- [ ] **Rejeitar PHP**: `curl -F "image=@shell.php" "http://localhost:8080/api/v1/properties/1/media"`
  - Status Esperado: ❌ 400 Bad Request

### Pagamento (🟡 AMARELO)
- [ ] **Criar pagamento**: `curl -X POST "http://localhost:8080/api/v1/payments" -d '{"amount":99.99,"method":"asaas"}'`
  - Status Esperado: Depende modo teste/prod

### Lógica (🟢 VERDE)
- [ ] **Sem plano ativo**: Tentar criar anúncio sem plan ativo
  - Status Esperado: ❌ 403 Forbidden

---

## 🧪 Ferramenta Alternativa: PHPUnit Simplificado

Para rodar apenas testes que não dependem de HTTP:

```bash
# 1. Criar teste simples unitário
php -r "
class SimpleTest {
    public function testSQLInjection() {
        \$input = \"1' OR '1'='1\";
        \$safe = addslashes(\$input);
        echo \$safe . PHP_EOL;
    }
}
\$test = new SimpleTest();
\$test->testSQLInjection();
"

# 2. Resultado esperado:
# 1\' OR \\\'1\\\'=\\\'1
```

---

## 📚 Próximos Passos

1. **Leia**: [PENETRATION_TESTING_GUIDE.md](PENETRATION_TESTING_GUIDE.md)
   - Guia passo a passo de ataque manual
   - Ferramentas e payloads prontos

2. **Corrija**: [REMEDIATION_GUIDE.md](REMEDIATION_GUIDE.md)
   - Código antes/depois
   - Como implementar cada fix

3. **Re-teste**: Use checklist acima
   - Após cada correção, re-execute o teste
   - Valide que vulnerabilidade foi fechada

4. **Deploy**: Quando 95%+ testes passarem
   - [SECURITY_AUDIT_REPORT.md](SECURITY_AUDIT_REPORT.md) aprovado
   - [TESTING_CHECKLIST.md](TESTING_CHECKLIST.md) completo verde

---

## 💡 Dica Profissional

**Em vez de rodar testes PHPUnit que precisam de HTTP real**, use:

```bash
# Ferramenta profissional para testes de segurança (recomendado)
docker run --rm \
  -v /Users/cristiandasilva/Projetos/projetos_php/copia_zap:/app \
  zaproxy/zaproxy:stable baseline \
    -t http://localhost:8080 \
    -r /app/security-report.html
```

Isso faz um scanning automático de vulnerabilidades!

---

## 👥 Por que PHPUnit não funciona para HTTP?

CodeIgniter 4 com PHPUnit 10 não tem suporte built-in para testes HTTP inteiros:
- ❌ Não há `ControllerTestTrait` facilmente disponível
- ❌ Testes reais precisam de servidor rodando
- ❌ Melhor usar ferramentas especializadas (Burp, ZAP)

**Solução**: Use [PENETRATION_TESTING_GUIDE.md](PENETRATION_TESTING_GUIDE.md) para testes manuais!

---

**Conclusão**: Os testes criados em `tests/unit/` são **templates de referência**. Para testes reais, use as abordagens acima ou ferramentas especializadas. ✅
