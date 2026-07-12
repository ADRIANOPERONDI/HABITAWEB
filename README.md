# Habitaweb - Sistema de Gestão Imobiliária

Sistema completo de gestão de imóveis com portal público, painel administrativo e CRM integrado.

## 🚀 Requisitos

- PHP >= 8.1 (CodeIgniter **4.7.4**)
- PostgreSQL >= 13 (ou MySQL >= 8.0)
- Composer
- Node.js >= 16 (assets em desenvolvimento e testes E2E Playwright)
- Extensões PHP: intl, mbstring, json, pdo_pgsql (ou pdo_mysql), curl, gd
- **Redis >= 6 + extensão phpredis** — recomendado em produção (cache, sessão,
  rate limit, fila de e-mail). O sistema **funciona sem Redis** (tudo tem
  fallback), mas as capacidades de escala descritas abaixo dependem dele.

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

## ⚡ Escalabilidade e infraestrutura — o que JÁ está implementado

> Referência completa (passo a passo de servidor, Redis, load balancer,
> PgBouncer, S3, testes de carga):
> **[GUIA_ESCALABILIDADE_PRODUCAO.md](GUIA_ESCALABILIDADE_PRODUCAO.md)**.
> Este resumo é para quem pega o projeto saber o que o código já suporta e o
> que é só decisão/configuração de servidor.

| Área | Estado no código | O que resta é configurar |
|---|---|---|
| Cache e sessão | Handlers Redis nativos do CI4; se o Redis cair, cache degrada para arquivo automaticamente | Instalar Redis + chaves `cache.*`/`session.*` no `.env` |
| Rate limit da API / força bruta no login | Contadores **atômicos** compartilhados entre instâncias (Redis) | Nada — liga junto com o Redis |
| Uploads (fotos, logos, KYC) | `StorageInterface` em **duas vias**: tenta S3 primeiro, cai para o disco local se o S3 falhar; URLs resolvidas por `media_url()`/`media_variant_url()` | Opcional: credenciais `storage.s3.*` + migração (abaixo) |
| Imagens | Thumbnails `_card` (480px) e `_gallery` (1280px) gerados no upload + lazy loading nas views | `php spark media:variants` uma vez p/ o acervo antigo |
| Visitas e ranking | Contagem em buffer Redis, descarregada por cron (sem Redis: gravação direta, como antes) | Cron `metrics:flush` (abaixo) |
| E-mails | Fila em Redis + worker com 3 tentativas e lista de falhas (sem Redis: envio síncrono) | Worker `email:work` (abaixo) |
| Busca por cidade/bairro | Match exato indexável; URLs SEO com acento/caixa (`/imoveis/venda/sao-paulo`) resolvem certo | Nada — as migrations criam os índices |
| Limite de imóveis por plano | Lock transacional `SELECT ... FOR UPDATE` — **provado** sob PgBouncer em modo transação | Opcional: PgBouncer (template em `deploy/pgbouncer/`) |
| Framework | CodeIgniter **4.7.4** (CVE-2026-48062 fechada), configs de `app/Config` sincronizadas | Nada |

### Configuração mínima de produção (1 servidor)

O sistema sobe sem nada disso (tudo tem fallback), mas o recomendado é:

**1. Redis + phpredis**, com estas chaves no `.env` (regras importantes na
seção 2.2 do guia: índices de cache e sessão **separados**, senha só
alfanumérica, `backupHandler = file` sempre):

```ini
cache.handler = redis
cache.backupHandler = file
cache.redis.host = 127.0.0.1
cache.redis.port = 6379
cache.redis.timeout = 1
cache.redis.database = 0

session.driver = CodeIgniter\Session\Handlers\RedisHandler
session.savePath = tcp://127.0.0.1:6379?database=1&timeout=1
```

**2. Workers — obrigatórios SE o Redis estiver ativo** (sem eles, e-mails
ficam parados na fila e as visitas ficam só no buffer):

```cron
*/5 * * * * cd /var/www/habitaweb && php spark metrics:flush
* * * * *   cd /var/www/habitaweb && php spark email:work --max-time 55
```

Para e-mail em tempo quase real, prefira a unit systemd da seção 10 do guia
no lugar do cron de `email:work`.

**3. OPcache** (seção 9 do guia): `validate_timestamps = 0` + `systemctl
reload php-fpm` incluído no script de deploy.

**4. Backfill único de thumbnails** do acervo existente:
`php spark media:variants` (idempotente, resumável, nunca toca os originais).

### Múltiplas instâncias + load balancer

O código não guarda estado obrigatório na instância (sessão/cache/contadores
no Redis, auth no Postgres) — **não precisa de sticky session**. Antes de
ligar a 2ª instância:

- `$proxyIPs` em `app/Config/App.php` com o IP do load balancer (sem isso, o
  rate limit enxerga todos os visitantes com o mesmo IP);
- uploads compartilhados: NFS montado no mesmo caminho em todas as instâncias
  (guia 4.2) **ou** S3 ativado (guia 4.3);
- crons e workers em **uma instância só**;
- `app.baseURL` = URL pública em todas as instâncias.

Checklist de deploy completo: seção 7 do guia. Roteiro de teste de carga
(k6/ab, o que monitorar, como interpretar): seção 8.

### S3 em duas vias (opcional, desligado por padrão)

Com `storage.driver = s3`, todo upload **tenta o S3 primeiro e cai para o
disco local automaticamente** se o S3 falhar; as leituras resolvem onde o
arquivo realmente está (funciona até com o acervo espalhado entre os dois).
Config incompleta não derruba o app — degrada para local com warning no log.
Ativação, nesta ordem:

1. Configurar `storage.s3.*` no `.env` (driver ainda `local`);
2. `php spark storage:migrate-s3 --dry-run` e conferir;
3. `php spark storage:migrate-s3` (idempotente, não apaga nada local, o banco
   não muda);
4. Trocar `storage.driver = s3` em todas as instâncias.

### Comandos spark de operação

| Comando | Função | Onde roda |
|---|---|---|
| `php spark metrics:flush` | Descarrega visitas/ranking do Redis no Postgres | Cron `*/5`, uma instância |
| `php spark email:work` | Worker da fila de e-mail (BLPOP, 3 tentativas → lista de falhas) | systemd ou cron, uma instância |
| `php spark media:variants` | Gera thumbnails do acervo antigo (`--dry-run`, `--start-id`) | Uma vez, manual |
| `php spark storage:migrate-s3` | Migra uploads locais para os buckets S3 | Uma vez, antes de ligar o driver s3 |

## ✅ Testes

```bash
./run_tests.sh setup                              # cria/prepara o banco habitaweb_test
vendor/bin/phpunit --testsuite unit,feature,e2e   # 86 testes (usa .env.testing: Postgres + Redis DBs 6/7)
npx playwright test                               # 17 testes E2E de navegador (sobe o servidor sozinho)
```

Os specs Playwright ficam em `e2e/` (funil público, signup, CRUD de imóvel
com fila de fotos, favoritos, checkout, KYC com liveness). `./run_tests.sh`
também aceita grupos (`security`, `api`, `payment`, ...).

## 📚 Documentação

- **Escalabilidade e produção** (Redis, LB, PgBouncer, S3, carga): [GUIA_ESCALABILIDADE_PRODUCAO.md](GUIA_ESCALABILIDADE_PRODUCAO.md)
- **Template PgBouncer**: [deploy/pgbouncer/pgbouncer.ini.example](deploy/pgbouncer/pgbouncer.ini.example)
- **API REST**: autodocumentada em `/api/docs` (rodando a aplicação)
- **Orientações para desenvolvimento com IA**: [CLAUDE.md](CLAUDE.md)
  (arquitetura, comandos, quirks do repositório)

## 🔒 Segurança

- Todas as senhas são hasheadas com bcrypt (Shield)
- CSRF protection habilitado
- Rate limiting **atômico** na API (compartilhado entre instâncias via Redis)
  e throttle token-bucket no login
- Validação server-side em todos os formulários
- Uploads validados por **MIME real** (finfo) + dimensões; metadados EXIF
  (GPS, câmera) removidos das imagens antes de gravar
- Documentos KYC e frames de biometria ficam **fora do webroot** e só saem
  pelo proxy autenticado (`/admin/kyc-file/...`) — nunca por URL pública,
  em qualquer backend de storage (fail-closed, coberto por teste)
- CodeIgniter 4.7.4 — CVE-2026-48062 corrigida; `composer audit` limpo

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

## 🔄 Reset para Nova Instalação

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

## 📄 Licença

Todos os direitos reservados © 2026
