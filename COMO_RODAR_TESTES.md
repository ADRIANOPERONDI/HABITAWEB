# 🧪 COMO RODAR OS TESTES - GUIA PASSO A PASSO

## ⚡ Início Rápido (2 minutos)

```bash
cd /Users/cristiandasilva/Projetos/projetos_php/copia_zap

# Setup do banco de dados de teste
./run_tests.sh setup

# Executar TODOS os testes (295+)
./run_tests.sh all
```

---

## 📋 Tabela de Comandos Disponíveis

| Comando | O Quê | Tempo | Testes |
|---------|-------|-------|--------|
| `./run_tests.sh all` | Todos os testes | ~45min | 295+ |
| `./run_tests.sh security` | OWASP segurado | ~8min | 60 |
| `./run_tests.sh crud` | E2E workflows | ~5min | 25 |
| `./run_tests.sh api` | REST endpoints | ~7min | 40 |
| `./run_tests.sh image` | Upload segurança | ~6min | 35 |
| `./run_tests.sh payment` | Payment gateways | ~8min | 45 |
| `./run_tests.sh business` | Regras negócio | ~7min | 50 |
| `./run_tests.sh coverage` | Tudo + cobertura | ~60min | 295+ |
| `./run_tests.sh report` | Gera relatório HTML | ~2min | 295+ |
| `./run_tests.sh setup` | Criar BD teste | ~1min | N/A |
| `./run_tests.sh clean` | Limpar relatórios | <1min | N/A |

---

## 👨‍💻 Desenvolvimento: Rodar um Teste Específico

### Teste de Segurança (SQL Injection, XSS, etc)
```bash
./run_tests.sh security
```
**O que testa:**
- SQL Injection (3 testes)
- XSS Stored/Reflected (4 testes)
- CSRF Token validation (2 testes)
- Authentication bypass (3 testes)
- Authorization flaws (5 testes)
- File upload exploits (3 testes)

**Esperado:**
```
FAILURES: 
  - testSQLInjectionInPropertySearch        ❌ Vai falhar até corrigir
  - testUnauthorizedAdminAccess             ❌ Vai falhar até corrigir
  - testMaliciousFileUpload                 ❌ Pode falhar
```

### Testes de CRUD E2E (Property, Account, Lead, Subscription)
```bash
./run_tests.sh crud
```
**O que testa:**
- Full Property lifecycle
- Image upload and ordering
- Account creation and management
- Lead capture and conversion
- Subscription upgrade

**Esperado:**
```
OK (25 tests) ✅ Deve passar
```

### Testes de API REST (GET, POST, PUT, DELETE)
```bash
./run_tests.sh api
```
**O que testa:**
- Properties CRUD
- Media endpoints
- Leads management
- Accounts
- Payments
- Webhooks (Asaas, Stripe, MP)

**Esperado:**
```
FAILURES: 2-3 (auth/permission tests)
OK (37-38 tests) 
```

### Testes de Upload de Imagem
```bash
./run_tests.sh image
```
**O que testa:**
- File type validation
- Size limits
- Dimension validation
- EXIF data stripping
- Path traversal prevention
- Corruption detection

**Esperado:**
```
OK (35 tests) ✅ Deve passar
```

### Testes de Pagamento (Asaas, Stripe, MP)
```bash
./run_tests.sh payment
```
**O que testa:**
- Asaas payment creation (5)
- Stripe integration (4)
- Mercado Pago (3)
- Webhook validation (10)
- Card security (8)
- Idempotency (5)
- Fraud prevention (5)

**Esperado:**
```
OK (45 tests) ✅ Deve passar (com mock gateways)
```

### Testes de Lógica de Negócio
```bash
./run_tests.sh business
```
**O que testa:**
- Plans and subscriptions
- Coupons and discounts
- Leads management
- Property validation
- Turbo promotions
- Pricing rules

**Esperado:**
```
OK (50 tests) ✅ Deve passar
```

---

## 📊 Executar com Relatório de Cobertura

```bash
./run_tests.sh coverage
```

Gera:
- `build/logs/html/index.html` - Visualizar no navegador
- `build/logs/testdox.html` - TestDox format
- `build/logs/logfile.xml` - JUnit format para CI/CD

**Resultado esperado:**
```
Total Lines:       1000      50%
Total Methods:     100       75%
Total Classes:     20        85%
```

---

## 📈 Gerar Relatório Executivo

```bash
./run_tests.sh report
```

Arquivos gerados:
- `build/logs/testdox.html` - Abrir no navegador para ver todos os testes e status
- `build/logs/testdox.txt` - Versão texto para incluir em docs

**Abrir relatório:**
```bash
open build/logs/testdox.html  # macOS
# ou
firefox build/logs/testdox.html  # Linux
```

---

## 🛠️ Setup: Criar Banco de Dados de Teste

**IMPORTANTE**: Fazer UMA VEZ antes de rodar qualquer teste!

```bash
./run_tests.sh setup
```

O que faz:
1. Lê credenciais de `.env.testing`
2. Cria banco de dados `habitaweb_test`
3. Valida conexão PostgreSQL
4. Pronto para usar

**Pré-requisitos:**
- PostgreSQL rodando: `brew services start postgresql` (macOS)
- Credenciais corretas em `.env.testing`
- Usuário `postgres` com privilégio de criar BD

**Se der erro:**
```bash
# Verificar se PostgreSQL está rodando
psql --version

# Conectar manualmente
psql -h localhost -U postgres -d habitaweb_test

# Se BD não existir, criar:
createdb -h localhost -U postgres habitaweb_test
```

---

## 🧹 Limpar Relatórios Antigos

```bash
./run_tests.sh clean
```

Remove:
- `build/logs/` (inteiro)
- Espaço em disco: ~50MB

Use antes de `./run_tests.sh coverage` para relatórios limpos.

---

## 📋 Tabela de Referência Rápida

### Padrão de Saída de Teste

Quando um teste PASSA ✅:
```
✓ testSQLInjectionInPropertySearch
```

Quando um teste FALHA ❌:
```
✗ testUnauthorizedAdminAccess
  Error: Expected 403, got 200
```

### Taxa de Sucesso Esperada

| Fase | Taxa | Status |
|------|------|--------|
| **Antes de correção** | ~92% | 🟡 |
| **Após corrigir críticos** | ~98% | 🟢 |
| **Final (tudo corrigido)** | 100% | ✅ |

### Quanto Tempo Esperar

```
./run_tests.sh security     →  ~8 minutos
./run_tests.sh crud         →  ~5 minutos
./run_tests.sh api          →  ~7 minutos
./run_tests.sh payment      →  ~8 minutos
./run_tests.sh business     →  ~7 minutos
./run_tests.sh image        →  ~6 minutos

./run_tests.sh all          →  ~45 minutos
./run_tests.sh coverage     →  ~60 minutos
```

---

## 🔍 Debugar um Teste que Falha

### Opção 1: Rodar alguns testes de uma suite
```bash
# Rodar apenas testes de SQL Injection
./run_tests.sh security 2>&1 | grep -i "sql"
```

### Opção 2: Rodar um teste específico com PHPUnit direto
```bash
php vendor/bin/phpunit \
    --filter "testSQLInjectionInPropertySearch" \
    tests/unit/SecurityTest.php \
    --verbose
```

### Opção 3: Ver stack trace completo
```bash
php vendor/bin/phpunit \
    tests/unit/SecurityTest.php \
    --verbose \
    --testdox-text
```

---

## 🎯 Fluxo Recomendado

### Semana 1: Análise
```bash
# 1. Setup
./run_tests.sh setup

# 2. Rodar todos os testes
./run_tests.sh all

# 3. Revisar relatório
./run_tests.sh report
open build/logs/testdox.html

# 4. Identificar críticos
# Consultar SECURITY_AUDIT_REPORT.md
```

### Semana 2: Correção (Sprint 1)
```bash
# 1. Corrigir SQL Injection
# Consultar REMEDIATION_GUIDE.md → SQL Injection

# 2. Re-testar segurança
./run_tests.sh security

# 3. Se passou, rodar tudo
./run_tests.sh all
```

### Semana 3: Correção (Sprint 2+)
```bash
# Mesmo padrão conforme REMEDIATION_GUIDE.md

# Verificar cobertura final
./run_tests.sh coverage
```

---

## ❓ FAQ - Erros Comuns

### ❌ "database habitaweb_test does not exist"
**Solução:**
```bash
./run_tests.sh setup
```

### ❌ "connection refused on port 5432"
**Solução:**
```bash
# Iniciar PostgreSQL
brew services start postgresql

# Verificar status
brew services list | grep postgres
```

### ❌ "permission denied: ./run_tests.sh"
**Solução:**
```bash
chmod +x ./run_tests.sh
```

### ❌ "PHPUnit not found"
**Solução:**
```bash
composer install
```

### ❌ Teste leva muito tempo
**Solução:**
```bash
# Rodar menos testes por vez
./run_tests.sh image    # só imagens
./run_tests.sh crud     # só CRUD
```

---

## 📞 Suporte

**Para mais detalhes, consulte:**
- [COMPLETE_TEST_GUIDE.md](COMPLETE_TEST_GUIDE.md) - Setup completo
- [SECURITY_AUDIT_REPORT.md](SECURITY_AUDIT_REPORT.md) - Vulnerabilidades
- [REMEDIATION_GUIDE.md](REMEDIATION_GUIDE.md) - Como corrigir
- [INDEX.md](INDEX.md) - Índice de tudo

---

## ✅ Checklist Inicial

- [ ] PostgreSQL instalado: `psql --version`
- [ ] Projeto clonado: `/Users/cristiandasilva/Projetos/projetos_php/copia_zap`
- [ ] `.env.testing` existe
- [ ] Dependências instaladas: `composer install`
- [ ] BD de teste criada: `./run_tests.sh setup`
- [ ] Pronto para rodar: `./run_tests.sh all`

---

**Pronto!** 🚀 Você agora pode rodar todos os testes. Comece com:

```bash
./run_tests.sh setup && ./run_tests.sh all
```
