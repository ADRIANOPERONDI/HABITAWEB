# Habitaweb - Sistema de Gestão Imobiliária

Sistema completo de gestão de imóveis com portal público, painel administrativo e CRM integrado.

## 🚀 Requisitos

- PHP >= 8.1
- PostgreSQL >= 13 (ou MySQL >= 8.0)
- Composer
- Node.js >= 16 (apenas para desenvolvimento de assets)
- Extensões PHP: intl, mbstring, json, pdo_pgsql (ou pdo_mysql), curl, gd

## 📦 Instalação

O Habitaweb possui um instalador automático via web para facilitar a configuração inicial.

### Opção 1: Instalação Automática (Recomendado)
1. Configure seu servidor (Apache/Nginx) apontando para a pasta `public/`.
2. Acesse a URL do seu site no navegador.
3. O sistema redirecionará automaticamente para o assistente de instalação (`/install`).
4. Siga os 5 passos do wizard para configurar banco de dados, variáveis de ambiente e administrador.

### Opção 2: Instalação Manual
Utilize esta opção se preferir configurar via terminal:
```bash
git clone git@github.com:ADRIANOPERONDI/HABITAWEB.git
cd habitaweb
```

### 2. Instale as dependências
```bash
composer install
```

### 3. Configure o banco de dados
Crie um banco de dados PostgreSQL ou MySQL:
```bash
# PostgreSQL
psql -U postgres -c "CREATE DATABASE habitaweb;"

# MySQL
mysql -u root -p -e "CREATE DATABASE habitaweb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4. Configure o arquivo .env
Copie o arquivo de exemplo e configure suas variáveis:
```bash
cp env.example .env
```

Edite o `.env` e configure:
- Credenciais do banco de dados
- URL base da aplicação
- Chave de encriptação (gere com: `php spark key:generate`)
- Configurações de email (SMTP)
- Chaves da API Asaas (pagamentos)

### 5. Execute as migrations
```bash
# Shield (auth)
php spark migrate --all -n CodeIgniter\\Shield

# Settings
php spark migrate --all -n CodeIgniter\\Settings

# App
php spark migrate
```

### 6. Execute os seeders
```bash
php spark db:seed PlanSeeder
```

### 7. Crie o usuário administrador
```bash
php spark shield:user create
# Siga as instruções para criar o super admin
```

### 8. Inicie o servidor de desenvolvimento
```bash
php spark serve
```

Acesse: http://localhost:8080

## 📚 Documentação

- **Guia do Usuário**: docs/user-guide.md
- **API Reference**: docs/api-reference.md
- **Deployment**: docs/deployment.md

## 🔒 Segurança

- Todas as senhas são hasheadas com bcrypt
- CSRF protection habilitado
- Rate limiting em rotas sensíveis
- Validação server-side em todos os formulários

## � Reset para Nova Instalação

Se você deseja "zerar" o sistema para realizar uma nova instalação limpa em outro servidor ou ambiente:

1. **Remova o arquivo de bloqueio**:
   ```bash
   rm writable/.installed
   ```
2. **Remova o arquivo de configuração**:
   ```bash
   rm .env
   ```
3. **Limpe o Banco de Dados** (Exemplo PostgreSQL):
   ```bash
   psql -U postgres -d habitaweb -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"
   ```
4. **Acesse o navegador**: O sistema redirecionará automaticamente para o Instalador Web (`/install`).

## �📄 Licença

Todos os direitos reservados © 2026
