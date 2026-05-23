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

## 🤖 Confiança + IA Híbrida

O roadmap do Habitaweb prevê uma camada premium de confiança e inteligência artificial para diferenciar o portal como um marketplace imobiliário mais seguro, claro e produtivo.

A IA será **opcional**. O sistema deverá continuar funcionando normalmente sem chave externa, usando regras locais, scores, templates e dados já existentes. Quando uma chave de IA estiver configurada, os textos e recomendações poderão ser enriquecidos automaticamente. Se a IA falhar, demorar ou estiver desativada, o fallback local será obrigatório.

### Configurações futuras no `.env`

```env
AI_ENABLED=false
AI_PROVIDER=openai
AI_MODEL=
AI_API_KEY=
AI_TIMEOUT_SECONDS=8
AI_CACHE_TTL=86400
```

> Não inclua chaves reais no repositório. A variável `AI_API_KEY` deve ser configurada apenas no ambiente de execução.

### O que a IA poderá fazer

- Gerar um resumo inteligente do imóvel, destacando pontos fortes, perfil ideal de comprador/inquilino e atenções antes da visita.
- Criar um dossiê público de confiança com sinais como imóvel verificado, anunciante verificado, anúncio completo, preço coerente, localização preenchida e atendimento rastreado.
- Sugerir respostas comerciais para leads, especialmente mensagens rápidas para WhatsApp com contexto do imóvel e do interesse do visitante.
- Classificar leads por prioridade, como quente, morno, incompleto, WhatsApp sem dados ou precisa resposta rápida.
- Sugerir melhorias em título, descrição, fotos e campos faltantes dos anúncios.
- Preparar uma busca inteligente futura, permitindo consultas mais naturais, como "apartamento perto do metrô para casal com cachorro".

### Arquitetura planejada

- `AIService`: camada opcional de comunicação com o provider externo de IA.
- `PropertyInsightService`: geração de insights e resumo inteligente do imóvel.
- `TrustService`: cálculo dos sinais públicos de confiança sem expor score técnico bruto.
- `LeadInsightService`: classificação de leads e sugestões de resposta para o anunciante.
- Fallback local obrigatório: templates e regras internas devem cobrir todos os fluxos quando a IA estiver desligada ou indisponível.

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
