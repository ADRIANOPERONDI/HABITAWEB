# Guia de Escalabilidade para Produção — Habitaweb (2000+ usuários)

> Gerado em 2026-07-11, junto com a implementação das Fases 1, 2, 3a, 4.1 e 5.1
> do plano de escalabilidade. Este documento explica **o que foi implementado no
> código**, **como configurar o servidor** (Redis, load balancer, PostgreSQL,
> PgBouncer, storage compartilhado) e **como verificar** que cada peça funciona.

---

## 1. Visão geral do que mudou no código

| Fase | O que era | O que é agora | Arquivos |
|---|---|---|---|
| 1 | Cache e sessão em **arquivo local** (`writable/cache`, `writable/session`) — quebrava com 2+ instâncias | Cache e sessão em **Redis** (handlers nativos do CI4, sem pacote novo) | `.env`, `.env.testing`, `app/Controllers/Install/InstallController.php` |
| 2 | Rate limit da API e bloqueio de força bruta com contadores **não atômicos e por instância** | Contador **atômico** (`HINCRBY`) no rate limit da API; throttle de login via `Throttler` do CI4 — ambos **compartilhados entre instâncias** via Redis | `app/Filters/ApiRateLimit.php`, `app/Controllers/Admin/Auth/LoginController.php` |
| 3a | 5 pontos de upload gravando direto em disco local (`FCPATH`/`WRITEPATH`), cada um com sua própria implementação | Camada única `StorageInterface` com 2 discos (público/privado); backend trocável (local hoje, S3/NFS depois) **sem tocar nos call sites** | `app/Libraries/Storage/*`, `app/Config/Storage.php`, `app/Config/Services.php` + 6 call sites |
| 4.1 | Toda visita a `/admin/subscription` fazia N chamadas HTTP síncronas ao Asaas | Debounce de **120s por conta** via cache — no máximo 1 rajada de sync por conta por janela | `app/Controllers/Admin/SubscriptionController.php` |
| 5.1 | Limite de plano protegido por `pg_advisory_lock` (preso à conexão física — **quebra silenciosamente sob PgBouncer em modo transação**) | `SELECT ... FOR UPDATE` dentro de transação explícita — correto sob **qualquer** modo de pooling | `app/Services/PropertyService.php` |

Verificação executada (tudo passou):
- **16/16 testes Playwright** (rodando com sessão/cache já em Redis).
- **67/67 testes PHPUnit** (unit + feature + e2e), sem regressão.
- Teste real de duas instâncias: sessão criada na instância A funciona na B (mesmo apagando `writable/session/`); 6ª tentativa de login errada bloqueada mesmo dividida 3+3 entre instâncias; duas ativações de imóvel em paralelo contra conta no limite do plano → exatamente 1 venceu.
- **Testes de carga locais** (seção 9): 5.922 requisições no funil público com 0 falhas, P95 60ms; rate limit admitiu **exatamente** o limite sob 20 requisições paralelas (contador atômico sem perda); 100 requisições paralelas da mesma sessão logada sem nenhuma falha.

---

## 2. Redis

### 2.1 Instalação no servidor (Ubuntu/Debian)

```bash
sudo apt install redis-server php8.x-redis   # extensão phpredis é obrigatória
sudo systemctl enable --now redis-server
```

Em `/etc/redis/redis.conf`:

```conf
# Escute só onde precisa. Uma instância única: só localhost.
# Múltiplas instâncias de app: escute no IP privado da rede interna.
bind 127.0.0.1 10.0.0.5        # ajuste 10.0.0.5 para o IP privado do host Redis

# SENHA OBRIGATÓRIA se escutar além de localhost.
# ⚠️ Use senha SÓ ALFANUMÉRICA (a-z A-Z 0-9): a session.savePath do CI4 é
# parseada com parse_url() e caracteres como @ : / ? # quebram o parse.
requirepass SuaSenhaAlfanumericaLonga123

# Sessões não podem ser descartadas sob pressão de memória.
# noeviction = erro em vez de descartar (correto para sessão).
maxmemory 512mb
maxmemory-policy noeviction

# Persistência: AOF ligado para não deslogar todo mundo num restart do Redis.
appendonly yes
appendfsync everysec
```

```bash
sudo systemctl restart redis-server
redis-cli -a "$REDIS_PASSWORD" ping   # → PONG
```

### 2.2 Configuração no `.env` da aplicação

Estas chaves estão documentadas no `env.example`. Em produção, ajuste
host/senha apenas no `.env` protegido da instância:

```ini
# Cache (Redis)
cache.handler = redis
cache.backupHandler = file
cache.redis.host = 10.0.0.5          # IP do host Redis (127.0.0.1 se local)
cache.redis.password = ${REDIS_PASSWORD}
cache.redis.port = 6379
cache.redis.timeout = 1
cache.redis.database = 0

# Session (Redis)
session.driver = CodeIgniter\Session\Handlers\RedisHandler
session.savePath = tcp://10.0.0.5:6379?auth=SuaSenhaAlfanumericaLonga123&database=1&timeout=1
```

**Regras importantes (aprendidas lendo o código do framework, não ignore):**

1. **Cache e sessão em índices Redis DIFERENTES** (`database=0` vs `database=1`).
   Motivo: `cache()->clean()` executa `FLUSHDB` (apaga o índice inteiro), e o
   comando `php spark geo:reset` chama isso. Se dividissem o índice, um
   `geo:reset` deslogaria todos os usuários. *(No dev desta máquina usamos 4/5
   porque 0-2 estão ocupados por outro projeto — em produção com Redis dedicado,
   0/1 está ótimo.)*
2. **`cache.backupHandler = file`, nunca `dummy`**: se o Redis cair, o CI4 cai
   pro handler de arquivo (comportamento antigo, funciona) em vez de transformar
   todo `cache()->get()` em no-op — o que mandaria todas as consultas quentes
   direto pro Postgres em todas as instâncias ao mesmo tempo.
3. **`timeout = 1`** (segundos): com Redis fora do ar, cada request paga no
   máximo 1s antes de cair pro backup — sem isso, cada page load penduraria.
4. As chaves antigas `app.sessionDriver` / `app.sessionSavePath` **nunca
   funcionaram** (prefixo errado — o CI4 espera `session.driver` /
   `session.savePath`). Foram corrigidas; não as recoloque.
5. Ajustar `Config\Session::$lockRetryInterval/$lockMaxRetries` **não tem efeito**
   com Redis nesta versão do CI4 (o handler lê propriedades com outros nomes que
   não existem no config e cai nos defaults internos: até ~30s de espera de lock).
   É um quirk do framework, não um bug nosso.

### 2.3 Verificação

```bash
# 1. Sessão compartilhada: logue no site, depois
redis-cli -a SENHA -n 1 keys 'ci_session*'     # deve listar sua sessão

# 2. Cache ativo:
redis-cli -a SENHA -n 0 keys '*'               # app_settings_global, home_partners, ...

# 3. Fallback: pare o Redis, o site deve continuar respondendo (mais lento,
#    cache em writable/cache/) — e NUNCA dar erro 500 por causa disso.
sudo systemctl stop redis-server && curl -sI https://seusite/ | head -1
sudo systemctl start redis-server
```

> ⚠️ Com o Redis parado, **sessões** ficam indisponíveis (usuários deslogados
> temporariamente) — o fallback `file` cobre só o cache. Por isso a seção de
> persistência/AOF acima e, se quiser alta disponibilidade real, Redis Sentinel
> ou um Redis gerenciado (ElastiCache, DigitalOcean Managed Redis etc.).

---

## 3. Load balancer + múltiplas instâncias

### 3.1 O que já está resolvido no código

Com as fases implementadas, a aplicação **não guarda mais estado obrigatório no
disco local da instância**, exceto os uploads (ver seção 4):

- Sessões → Redis (qualquer instância atende qualquer usuário; **não precisa de
  sticky session**).
- Cache → Redis (invalidações e contadores valem para todas as instâncias).
- Rate limit / bloqueio de força bruta → Redis, contadores atômicos e globais.
- Tokens/identidades de auth (Shield) → já eram no Postgres.

### 3.2 Exemplo de configuração nginx (load balancer)

```nginx
upstream habitaweb_app {
    least_conn;                      # sem sticky — sessão está no Redis
    server 10.0.0.11:80 max_fails=3 fail_timeout=15s;
    server 10.0.0.12:80 max_fails=3 fail_timeout=15s;
    # adicione instâncias conforme a carga
}

server {
    listen 443 ssl http2;
    server_name seusite.com.br;

    # ... certificados ...

    client_max_body_size 12m;        # uploads de imagem até 10MB + margem

    location / {
        proxy_pass http://habitaweb_app;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**Atenção — IP real do cliente:** o rate limit anônimo e o bloqueio de força
bruta usam `$request->getIPAddress()`. Atrás de proxy, configure o CI4 para
confiar no `X-Forwarded-For` **apenas** vindo do IP do load balancer, em
`app/Config/App.php`:

```php
public array $proxyIPs = ['10.0.0.1' => 'X-Forwarded-For']; // IP do LB
```

Sem isso, todas as requisições parecem vir do IP do LB e um único bucket de
rate limit seria compartilhado por todos os visitantes anônimos.

### 3.3 Em cada instância de app (nginx + php-fpm)

```nginx
server {
    listen 80;
    root /var/www/habitaweb/public;

    # Estáticos (inclusive uploads públicos) direto do disco — sem PHP.
    location ~* \.(jpg|jpeg|png|webp|gif|css|js|ico|svg|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }

    # Nunca sirva outros .php diretamente.
    location ~ \.php$ { return 404; }
}
```

php-fpm (`www.conf`) — ponto de partida para instâncias de 4 vCPU / 8GB:

```ini
pm = dynamic
pm.max_children = 25        # ver fórmula de conexões na seção 5
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 1000
```

### 3.4 Cron jobs: em UMA instância só

Os comandos agendados (`php spark asaas:sync`, `subscription:check`,
`send-property-alerts` etc.) devem rodar **em apenas uma** instância (ou num
host worker dedicado). Rodar em todas dispararia sincronizações e e-mails
duplicados. Marque uma instância como "worker" no seu provisionamento e instale
o crontab só nela.

**Novo (obrigatório após a Rodada 2):** o contador de visitas de imóvel e os
recálculos de score adiados agora acumulam em Redis e são descarregados no
Postgres por cron — adicione à instância worker:

```cron
*/5 * * * * cd /var/www/habitaweb && php spark metrics:flush
```

Sem esse cron, as visitas continuam sendo contadas (ficam no Redis), mas a
coluna `visitas_count` para de refletir no painel até o próximo flush. Se o
Redis cair, o app volta sozinho a gravar visitas direto no banco (fallback).

### 3.5 `app.baseURL`

Em produção, `app.baseURL` no `.env` de **todas** as instâncias deve ser a URL
pública (`https://seusite.com.br/`) — nunca o endereço interno da instância. O
CI4 gera redirects absolutos a partir dela.

---

## 4. Uploads — storage compartilhado (a única pendência de infra)

### 4.1 Estado atual

Todo upload/leitura/exclusão agora passa por `App\Libraries\Storage\StorageInterface`
com dois discos definidos em `Config\Services`:

| Disco | Serviço | Base local hoje | Conteúdo | Exposição |
|---|---|---|---|---|
| Público | `service('publicStorage')` | `public/` (FCPATH) | fotos de imóvel, logos, imagens de settings | servido estático pelo nginx |
| Privado | `service('privateStorage')` | `writable/` (WRITEPATH) | documentos KYC (RG/selfie), frames de biometria | **nunca** tem URL pública; só via proxy autenticado (`/admin/kyc-file/...`) |

O backend atual é `LocalStorage` (disco local) — **funciona com 1 instância**.
Com 2+ instâncias, escolha UMA das opções abaixo. Nenhuma exige mudar código de
aplicação; a segunda exige implementar uma classe nova.

### 4.2 Opção A — NFS/EFS compartilhado (rápida, recomendada para começar)

Monte um filesystem de rede no mesmo caminho em todas as instâncias:

```bash
# em cada instância (exemplo com NFS)
sudo mount -t nfs4 10.0.0.20:/exports/habitaweb-public-uploads  /var/www/habitaweb/public/uploads
sudo mount -t nfs4 10.0.0.20:/exports/habitaweb-private         /var/www/habitaweb/writable/uploads
# adicione ao /etc/fstab para persistir
```

- Zero mudança de código, zero migração de dados (só copiar os arquivos atuais
  para o export uma vez).
- O nginx de cada instância continua servindo `public/uploads` estático.
- No Nginx, bloqueie qualquer extensão executável dentro de uploads (o
  `.htaccess` versionado cobre somente Apache):

```nginx
location ~* ^/uploads/.*\.(?:php[0-9]?|phtml|pht|phar|cgi|pl|py|rb|sh|asp|aspx|jsp)$ {
    deny all;
    return 403;
}
location ^~ /uploads/verification/ {
    deny all;
    return 403;
}
```

- Limitação: o NFS vira ponto único de falha/gargalo em escala muito grande —
  suficiente para 2000 usuários, reavalie depois.

Também monte compartilhado (ou trate por instância) o `writable/kyc` — os
documentos KYC novos vão para `writable/kyc/` e `writable/uploads/kyc/`.

### 4.3 Opção B — Object storage S3-compatível em DUAS VIAS — **IMPLEMENTADO (atualizado 2026-07-11)**

`app/Libraries/Storage/S3Storage.php` está pronto (league/flysystem +
flysystem-aws-s3-v3, já no composer.json), coberto por testes
(`tests/unit/S3StorageTest.php` — round-trip, fail-closed do disco privado,
path traversal), e ligado em `Config\Services` pelo driver.

**Operação em duas vias (`FallbackStorage`)**: com `storage.driver = s3`, o
sistema **nunca depende só do S3**:

- **Upload**: tenta o S3 primeiro; se falhar (S3 fora do ar, bucket
  inacessível, timeout), loga um warning e grava no disco local — o upload só
  falha se as DUAS vias falharem. Timeouts curtos no cliente S3
  (connect 3s / request 15s) garantem que a queda para o local é rápida.
- **Leitura/URL**: `exists()`/`getPublicUrl()` resolvem **onde o arquivo
  realmente está** (S3 ou local) — um acervo espalhado entre as duas vias
  continua 100% servido. A localização é cacheada (Redis) para não pagar uma
  chamada de rede por imagem a cada render.
- **Config incompleta**: `storage.driver = s3` sem as chaves `storage.s3.*`
  não derruba o app — degrada para disco local com warning no log.
- **Fail-closed preservado**: o disco privado (KYC) continua sem URL pública
  em qualquer via (coberto por `tests/unit/FallbackStorageTest.php`, 6 testes).

Para ativar:

```ini
storage.driver = s3
storage.s3.key = ...
storage.s3.secret = ...
storage.s3.region = ...
storage.s3.bucketPublic = habitaweb-public       # fotos de imóvel (bucket público ou CDN na frente)
storage.s3.bucketPrivate = habitaweb-kyc         # documentos KYC (SEMPRE privado)
storage.s3.endpoint = https://...                # só para R2/Spaces/MinIO
storage.s3.publicBaseUrl = https://cdn.seusite.com.br
```

**Fluxo de ativação (nesta ordem):**

1. Configurar as chaves `storage.s3.*` acima no `.env` (driver ainda `local`).
2. `php spark storage:migrate-s3 --dry-run` → conferir a lista.
3. `php spark storage:migrate-s3` → envia tudo preservando as MESMAS chaves
   relativas (por isso **nenhuma coluna do banco muda**). Idempotente (pula o
   que já está no bucket) e não-destrutivo (nunca apaga o arquivo local).
4. Trocar `storage.driver = s3` no `.env` de todas as instâncias e validar.

Garantias já implementadas:
- Validação de conteúdo (MIME real via finfo, dimensões, EXIF) acontece **antes**
  do `put()`, na camada de serviço — vale para qualquer backend.
- `getPublicUrl()` do disco privado retorna `null` **sempre** (fail-closed,
  coberto por teste) — documentos KYC nunca ganham URL pública; leitura via
  proxy autenticado (`readStream`) ou `getSignedUrl()` (presigned URL, quando
  o provedor suporta).

✅ **Caveat de `base_url()` RESOLVIDO (2026-07-11)**: todos os pontos que
montavam URL de upload com `base_url()` direto (logo de parceiro, logo da
conta no detalhe do imóvel, imagens de settings nos layouts/login/dashboard,
galeria do form admin) foram convertidos para o helper `media_url()` /
`media_variant_url()`, que resolvem via storage abstrato. Com driver `local`
o resultado é byte-a-byte idêntico a `base_url()`; com S3 + `publicBaseUrl`
(CDN) as URLs passam a apontar para o bucket/CDN automaticamente.

---

## 5. PostgreSQL e PgBouncer

### 5.1 Dimensionamento de conexões

Cada worker php-fpm abre 1 conexão Postgres. Fórmula:

```
conexões ≈ (N instâncias × pm.max_children)
         + ~10 (crons/workers: asaas:sync, subscription:check, ...)
         + margem admin/relatórios (~10)
```

Exemplo: 4 instâncias × 25 workers = 100 + 20 = **~120 conexões** → acima do
`max_connections = 100` padrão. Em `postgresql.conf`:

```conf
max_connections = 200          # cada conexão ≈ 5-10MB de RAM no host do banco
shared_buffers = 25% da RAM
effective_cache_size = 60% da RAM
```

### 5.2 PgBouncer (quando N×M crescer além disso)

O bug que impedia pooling **já foi corrigido** (Fase 5.1): o único ponto do
código que dependia de estado preso à conexão física (`pg_advisory_lock` no
limite de plano) foi trocado por `SELECT ... FOR UPDATE` transacional.
**Verificado na prática (2026-07-11)**: duas ativações simultâneas de imóvel,
conta exatamente no limite do plano, executadas ATRAVÉS de um PgBouncer local
em `pool_mode = transaction` — exatamente uma venceu, a outra foi rejeitada
corretamente. Template de configuração pronto em
`deploy/pgbouncer/pgbouncer.ini.example`. Com isso:

```ini
[databases]
habitaweb = host=10.0.0.30 port=5432 dbname=habitaweb

[pgbouncer]
pool_mode = transaction        # seguro APÓS a Fase 5.1 (já aplicada)
max_client_conn = 500
default_pool_size = 25
```

E no `.env` das instâncias, aponte para o PgBouncer:

```ini
database.default.hostname = 10.0.0.30
database.default.port = 6432
```

`pConnect = false` (config atual) está correto para esse cenário — não mude.

> Se por qualquer motivo for feito rollback da Fase 5.1 (voltar ao advisory
> lock), o PgBouncer **precisa** ficar em `pool_mode = session`, senão o limite
> de imóveis por plano deixa de valer sob concorrência — silenciosamente.

---

## 6. Outras mudanças de comportamento que valem saber

- **Sync do Asaas em `/admin/subscription`**: agora no máximo 1 rajada de
  sincronização por conta a cada 120s (chave `subscription_sync_stale_{id}` no
  cache). A primeira visita após um checkout continua sincronizando na hora
  (não há chave ainda). O cron `asaas:sync` continua sendo a reconciliação
  principal.
- **Rate limit da API**: mesma janela fixa de 1h e mesmos headers
  `X-RateLimit-*` de antes (contrato público preservado) — só o incremento
  virou atômico. Nunca chame `get()` numa chave que só sofreu `increment()`
  (limitação do handler Redis do CI4; o código atual já respeita isso).
- **Throttle de login**: virou token-bucket (5 tentativas/15min com reposição
  gradual) — em vez de bloquear 15min secos, o direito a novas tentativas
  "goteja" de volta. Login correto não consome tentativa.
- **EXIF/otimização de imagem**: agora acontecem no arquivo temporário **antes**
  de gravar no storage (pré-requisito para backend remoto). Comportamento final
  idêntico (verificado nos testes).
- **Fotos no cadastro do imóvel (2026-07-11)**: o formulário de imóvel NOVO não
  cria mais um rascunho automático só para poder subir foto. As fotos escolhidas
  antes do primeiro salvar entram numa **fila local com preview** (badge
  "Enviada ao salvar", removíveis individualmente) e sobem todas juntas quando
  o imóvel é salvo — inclusive no "Finalizar & Publicar", cujo redirect espera
  a fila terminar. Coberto por teste Playwright (`e2e/property-crud.spec.ts`).
- **Frames de liveness na revisão de KYC (2026-07-11)**: passaram a ser servidos
  pelo proxy autenticado (`admin/kyc-file/{conta}/liveness_{n}`) — antes a view
  montava `base_url()` direto, que não funcionava (os frames ficam no disco
  privado) e seria vazamento de biometria se funcionasse.
- **CodeIgniter 4.6.4 → 4.7.4 (2026-07-11)**: fecha a CVE-2026-48062 (bypass da
  regra `ext_in` de upload — o app não a usava, mas agora a base está corrigida).
  As propriedades de config novas do 4.7 foram sincronizadas em `app/Config/*`
  (11 arquivos, valores default do framework).

---

## 7. Checklist de deploy em produção

1. [ ] Redis instalado, com senha alfanumérica, `maxmemory-policy noeviction`, AOF ligado.
2. [ ] Extensão `php-redis` instalada em todas as instâncias (`php -m | grep redis`).
3. [ ] `.env` de todas as instâncias com as chaves `cache.*` e `session.*` (seção 2.2) apontando para o MESMO Redis.
4. [ ] `app.baseURL` = URL pública em todas as instâncias.
5. [ ] `app/Config/App.php` → `$proxyIPs` com o IP do load balancer.
6. [ ] Uploads compartilhados (NFS montado OU S3Storage implementada) — **antes** de ligar a segunda instância.
7. [ ] Crontab instalado em UMA instância apenas.
8. [ ] `max_connections` do Postgres recalculado pela fórmula da seção 5.1.
9. [ ] Smoke test: logar por uma instância, forçar a próxima requisição na outra (derrubando a primeira do upstream), confirmar que a sessão sobrevive.
10. [ ] Smoke test: upload de foto de imóvel por uma instância, abrir a URL pública através da outra.

---

## 8. Testes de carga — como validar o servidor

### 8.1 Resultados dos testes já executados (dev local, 2026-07-11)

Ambiente: máquina de desenvolvimento, servidor embutido do PHP com 8 workers
(`PHP_CLI_SERVER_WORKERS=8`), Redis e Postgres locais. Números de produção com
nginx + php-fpm serão melhores; o que importa aqui é **correção sob carga** e a
ordem de grandeza.

| Teste | Resultado |
|---|---|
| Funil público (k6, rampa até 40 VUs, 40s): home + busca + pins do mapa | **5.922 req, 0 falhas, 147 req/s, P95 60ms, máx 110ms** |
| Conexões Postgres durante o teste acima | Pico de **5** (de 8 workers) — o cache Redis absorveu (63k hits) |
| Atomicidade do rate limit: 150 req anônimas, 20 paralelas, mesmo IP (limite 100/h) | **Exatamente 100 admitidas, 50 × 429, contador Redis = 150** (zero incrementos perdidos — com o contador antigo passariam mais de 100) |
| Mesma sessão logada: 100 req, 10 paralelas, `/admin/dashboard` | **100/100 OK, 0 falhas** (P95 ~2s pela serialização do lock de sessão — ver 8.5) |

Achado de dimensionamento: **cada visitante anônimo cria uma sessão no Redis**
(o CSRF do CI4 exige) — o teste de 1.974 iterações deixou ~1.976 chaves de
sessão (TTL 2h). Para 2000 usuários simultâneos + tráfego anônimo, estime
dezenas de milhares de sessões vivas; cada uma tem poucos KB, então os 512MB de
`maxmemory` da seção 2.1 comportam com folga — mas monitore
(`redis-cli -n 1 dbsize` e `INFO memory`).

### 8.2 Ferramentas

- **k6** (recomendada — cenários com rampa, thresholds, percentis): `brew install k6` / [k6.io](https://k6.io)
- **ab** (Apache Bench, já vem no macOS/Linux): checagens rápidas de uma URL só.

### 8.3 Roteiro de testes — rode nesta ordem

Sempre contra **staging ou uma janela de manutenção** — nunca contra produção
com usuários reais sem avisar. Exporte a base: `export BASE_URL=https://staging.seusite.com.br`.

**1. Baseline (1 usuário)** — estabelece a latência "de referência" de cada rota:

```bash
ab -n 50 -c 1 $BASE_URL/
ab -n 50 -c 1 $BASE_URL/imoveis
```

Anote o P95. Se a baseline já for ruim (>500ms), carga nenhuma vai melhorar —
resolva a rota primeiro.

**2. Rampa (k6)** — o teste principal. Salve como `load_public.js`:

```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [
    { duration: '1m', target: 100 },   // aquecimento
    { duration: '3m', target: 500 },   // rampa
    { duration: '5m', target: 2000 },  // alvo: 2000 VUs
    { duration: '3m', target: 2000 },  // sustenta
    { duration: '1m', target: 0 },     // desce
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],      // <1% de erro = aprovado
    http_req_duration: ['p(95)<800'],    // P95 < 800ms sob carga total
  },
};

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8090';

export default function () {
  check(http.get(`${BASE}/`), { 'home 200': (r) => r.status === 200 });
  check(http.get(`${BASE}/imoveis`), { 'busca 200': (r) => r.status === 200 });
  check(http.get(`${BASE}/api/imoveis/mapa?bounds=-90,-180,90,180`), { 'mapa 200': (r) => r.status === 200 });
  sleep(Math.random() * 3 + 1); // 1-4s entre ações, como usuário real
}
```

```bash
k6 run -e BASE_URL=$BASE_URL load_public.js
```

> 2000 VUs com `sleep` de 1-4s ≈ 500-800 req/s — tráfego realista de 2000
> usuários simultâneos navegando. Sem o `sleep`, 2000 VUs viram um teste de
> estresse muito mais agressivo que o cenário real.

**3. Fluxo autenticado (k6)** — login + dashboard, valida sessão Redis sob carga.
Crie ANTES alguns usuários de teste (nunca use contas reais):

```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [{ duration: '2m', target: 200 }, { duration: '3m', target: 200 }, { duration: '1m', target: 0 }],
  thresholds: { http_req_failed: ['rate<0.01'] },
};

const BASE = __ENV.BASE_URL;

export default function () {
  // 1. pega o form de login (cria sessão + CSRF)
  const page = http.get(`${BASE}/admin/login`);
  const token = page.html().find('input[name=csrf_test_name]').attr('value');

  // 2. autentica (cada VU pode usar o mesmo usuário de teste; k6 isola cookies por VU)
  const res = http.post(`${BASE}/admin/login`, {
    email: __ENV.TEST_EMAIL,
    password: __ENV.TEST_PASSWORD,
    csrf_test_name: token,
  });
  check(res, { 'login redireciona': (r) => r.status === 303 || r.status === 302 });

  // 3. navega logado
  check(http.get(`${BASE}/admin/dashboard`), { 'dashboard 200': (r) => r.status === 200 });
  sleep(2);
}
```

> ⚠️ O throttle de força bruta (5 falhas/15min por IP) NÃO interfere aqui desde
> que as credenciais estejam corretas — login certo não consome tentativa. Se o
> teste começar a falhar login em massa (senha errada no env), o IP da máquina
> de teste será bloqueado — comportamento correto, mas invalida o teste.

**4. Soak (resistência)** — mesma carga média por 1-2 horas, para pegar vazamento
de memória, conexões penduradas e crescimento de sessões no Redis:

```bash
k6 run -e BASE_URL=$BASE_URL --stage 5m:300 --stage 110m:300 --stage 5m:0 load_public.js
```

**5. Prova de atomicidade do rate limit** (rápida, sem k6) — repita a que foi
executada em dev, contra o servidor real:

```bash
# limite anônimo = 100/h. Exatamente 100 devem responder 401 e o resto 429.
seq 1 150 | xargs -P 20 -I{} curl -s -o /dev/null -w "%{http_code}\n" $BASE_URL/api/v1/properties | sort | uniq -c
```

Depois limpe o contador para não deixar o IP de teste bloqueado por 1h:
`redis-cli -n 0 del rate_limit_ip_<SEU_IP> rate_limit_window_ip_<SEU_IP>`.

### 8.4 O que monitorar NO SERVIDOR durante os testes

Abra um terminal em cada host e acompanhe:

```bash
# Postgres — conexões e queries lentas (no host do banco):
watch -n 2 "psql -U postgres -d habitaweb -t -c \"SELECT count(*), state FROM pg_stat_activity WHERE datname='habitaweb' GROUP BY state;\""
psql -c "SELECT pid, now()-query_start AS dur, query FROM pg_stat_activity WHERE state='active' ORDER BY dur DESC LIMIT 5;"

# Redis — ops/s, memória, hit rate:
redis-cli -a SENHA INFO stats | grep -E 'instantaneous_ops_per_sec|keyspace_hits|keyspace_misses'
redis-cli -a SENHA INFO memory | grep used_memory_human
redis-cli -a SENHA -n 1 dbsize    # sessões vivas

# php-fpm — fila de espera (ative pm.status_path = /fpm-status no www.conf):
curl -s localhost/fpm-status | grep -E 'active processes|listen queue'

# Sistema:
htop   # CPU/RAM por instância
```

Sinais de gargalo e onde mexer:

| Sintoma | Causa provável | Ação |
|---|---|---|
| `listen queue` do php-fpm > 0 constante | Poucos workers | Subir `pm.max_children` (recalcule a fórmula da seção 5.1) ou adicionar instância |
| Conexões PG perto do `max_connections` | Fórmula estourou | PgBouncer (seção 5.2) |
| P95 sobe mas CPU das instâncias ociosa | Espera de I/O — banco ou rede | `pg_stat_activity` p/ query lenta; índice faltando |
| `keyspace_misses` alto no Redis | Cache frio ou TTL curto demais | Verificar se `cache.handler=redis` está ativo em TODAS as instâncias |
| Erros só acima de N VUs | Limite de FDs/soquetes | `ulimit -n`, `worker_connections` do nginx |

### 8.5 Como interpretar sem se enganar

- **Erros 429 na API durante teste de carga de um IP só não são bug** — são o
  rate limit funcionando. Para teste de throughput da API, use uma API key com
  `rate_limit_per_hour` alto (configurável por chave no admin), ou gere carga
  de múltiplos IPs.
- **Requisições paralelas da MESMA sessão serializam** (lock de sessão do
  Redis, até ~30s de espera) — por isso o P95 do teste 4 da tabela em 8.1 subiu
  para ~2s com 10 paralelas do mesmo cookie. Usuários diferentes não fazem fila
  entre si. Não interprete isso como gargalo do servidor: é o comportamento
  padrão de sessão PHP (o handler de arquivo fazia o mesmo).
- **Nunca aponte teste de carga para fluxos que chamam o Asaas** (checkout,
  `/admin/subscription`) — você estaria fazendo load test no gateway de
  pagamento de terceiros. O debounce da Fase 4.1 protege o caso acidental, mas
  não faça de propósito.
- Rode cada cenário **3 vezes** e compare — uma medição só não é dado.
- Teste com **cache quente** (segunda rodada em diante) E **cache frio**
  (`redis-cli -n 0 flushdb` antes — só em staging!) para conhecer os dois mundos.

---

## 9. OPcache e preload (nível PHP — ganho grátis, só configuração)

Sem OPcache, cada requisição recompila todo o PHP do framework do zero. Em
produção, no `php.ini` (ou pool do php-fpm):

```ini
opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.interned_strings_buffer = 16

; validate_timestamps=0 = nunca re-checa mtime dos arquivos (máxima performance).
; ⚠️ Com isso, TODO deploy exige: systemctl reload php8.x-fpm
opcache.validate_timestamps = 0
```

**Preload (opcional, ganho adicional):** o repositório já tem um `preload.php`
na raiz. Antes de usar, **remova a exclusão de `system/Database/Postgre/`**
dentro dele (o sample do CI4 exclui esse diretório, mas o driver deste app É
Postgres — com a exclusão, as classes mais usadas ficariam de fora):

```ini
opcache.preload = /var/www/habitaweb/preload.php
opcache.preload_user = www-data
```

Verificação: `php -r 'print_r(opcache_get_status(false)["opcache_statistics"]);'`
(hits crescendo e `oom_restarts = 0`; se `oom_restarts` subir, aumente
`memory_consumption`).

Checklist de deploy (seção 7): adicionar item — "OPcache habilitado com
`validate_timestamps=0` + reload do php-fpm incluído no script de deploy".

---

## 10. Fila de e-mail em Redis — **IMPLEMENTADO (2026-07-11)**

`NotificationService::sendEmail()` agora **enfileira por padrão** (lista Redis
`hw:queue:email`) e o worker `spark email:work` consome via BLPOP e envia —
o handshake SMTP saiu da thread da requisição. Comportamentos garantidos
(cobertos por `tests/Feature/EmailQueueTest.php` + verificação ao vivo):

- **Redis fora do ar** → envio síncrono na hora (comportamento antigo, nada quebra).
- **SMTP não configurado** → retorna `false` SEM enfileirar (não esconde erro de config).
- **Falha de envio** → reenfileira até 3 tentativas; depois vai para
  `hw:queue:email:failed` (inspecione com `redis-cli LRANGE hw:queue:email:failed 0 -1`).
- **Teste de SMTP do admin** (`/admin/settings` → testar e-mail) continua
  síncrono de propósito (`immediate: true`) — precisa do resultado real.

Como rodar o worker em produção (numa instância só, como os crons):

```ini
; OPÇÃO A — systemd (recomendado, entrega quase em tempo real)
; /etc/systemd/system/habitaweb-email.service
[Unit]
Description=Habitaweb - worker da fila de e-mail
After=redis-server.service

[Service]
User=www-data
WorkingDirectory=/var/www/habitaweb
ExecStart=/usr/bin/php spark email:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```cron
# OPÇÃO B — cron por minuto (mais simples, latência até ~1min)
* * * * * cd /var/www/habitaweb && php spark email:work --max-time 55
```

Monitoramento: `redis-cli LLEN hw:queue:email` (fila crescendo sem parar =
worker parado ou SMTP quebrado).

---

## 11. Redis HA — opções quando a instância única deixar de bastar

O que acontece hoje se o Redis cair: **cache** degrada para arquivo local
(fallback automático, site continua no ar), **rate limit/força bruta** também
caem no arquivo (por instância — janela de proteção reduzida), **visitas e
e-mails** caem no modo síncrono, mas **sessões ficam indisponíveis** — todo
mundo deslogado até o Redis voltar (AOF `everysec` limita a perda a ~1s de
sessões novas num crash). Ou seja: o único dano real de uma queda curta é
relogin em massa. Escolha o remédio pelo custo disso para o negócio:

| Opção | Como | Prós/Contras |
|---|---|---|
| **Redis gerenciado** (ElastiCache, DO Managed Redis, Upstash...) — **recomendado** | Apontar `cache.redis.host`/`session.savePath` para o endpoint gerenciado | Failover automático sem mudar o app; custo mensal; latência de rede (mantenha na mesma região/VPC) |
| **Sentinel + VIP/HAProxy** | 3 nós Sentinel decidem o master; como os handlers do CI4 falam Redis "puro" (não o protocolo Sentinel), coloque um HAProxy/keepalived apontando pro master atual e o app conecta no VIP | Sem custo de serviço gerenciado; mais 2 peças de infra para operar; failover ~10-30s |
| **Réplica passiva + failover manual** | `replicaof` num segundo nó; trocar o IP no `.env` + reload quando o primário morrer | Quase grátis; RTO depende de humano acordado |

Redis **Cluster** não é recomendado aqui: o handler de sessão do CI4 não
suporta o modo cluster, e o volume de dados (sessões + cache) está longe de
precisar de sharding.

Persistência mínima em qualquer opção (já na seção 2.1): `appendonly yes`,
`appendfsync everysec`, `maxmemory-policy noeviction`.

---

## 12. O que ainda NÃO foi implementado (backlog consciente)

| Item | Por quê ficou de fora | Gatilho para fazer |
|---|---|---|
| ~~`S3Storage` (Fase 3b)~~ | **Feito (2026-07-11)** — ver seção 4.3; falta só a decisão de provedor/credenciais para ativar | — |
| ~~Fila de e-mail em Redis (Fase 4.2)~~ | **Feito (2026-07-11)** — ver seção 10 | — |
| ~~PgBouncer~~ | **Template + prova em modo transação feitos (2026-07-11)** — ver seção 5.2 e `deploy/pgbouncer/`; falta só provisionar quando a fórmula da seção 5.1 mandar | — |
| Redis HA provisionado | Decisão de infra/custo (opções na seção 11) | Quando relogin em massa por queda do Redis for inaceitável |
| ~~Conversão dos últimos `base_url()` de upload para `getPublicUrl()`~~ | **Feito (2026-07-11)** — helpers `media_url()`/`media_variant_url()` em todos os call sites de upload | — |
| CI com serviço Redis (`phpunit.yml`) | Suíte local já roda contra Redis real; CI segue com FileHandler | Primeira regressão que só se manifestaria com handler Redis |
| ~~Upgrade CI4 4.6.4 → 4.7.2+ (CVE-2026-48062, regra `ext_in`)~~ | **Feito (2026-07-11)** — 4.7.4 instalado, configs sincronizadas, `composer audit` limpo, suíte completa verde | — |
