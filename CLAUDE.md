# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**Habitaweb** is a multi-tenant real-estate listing SaaS built on **CodeIgniter 4** (PHP >= 8.1) with **CodeIgniter Shield** for auth. It has a public property-search portal, an admin panel (per-account), a versioned REST API, and integrations with three payment gateways (Asaas, Stripe, Mercado Pago) for subscription billing.

The working directory is `copia_zap` but the app/product name throughout code, DB, and docs is **Habitaweb**.

## Fluxo de trabalho obrigatório (branches e commits)

**Toda e qualquer alteração** neste repositório — feature, correção, refactor, ajuste
de migration, mudança de doc — é feita numa **branch nova**, nunca direto na `main`.

1. Antes de começar, criar a branch a partir da `main` atualizada:
   `git checkout main && git pull && git checkout -b <tipo>/<escopo>`
   Tipos em uso: `feature/`, `fix/`, `refactor/`, `chore/`, `docs/`.
2. **Cada etapa do trabalho recebe o seu próprio commit**, feito assim que a etapa
   fecha e com a suíte de testes passando. Não acumular várias etapas num commit só
   — o objetivo é poder voltar versão etapa a etapa (`git revert <sha>` de uma etapa
   isolada, sem arrastar as outras junto).
3. Mensagem de commit: `<tipo>(<escopo>): <o que mudou>`, uma linha, imperativo, em
   português. Ex.: `feat(integracoes): tabela de credenciais por tenant`.
   Nada de "Ajustes".
4. Migration nova = commit próprio, separado do código que a consome.
5. Merge na `main` só via PR, depois de `vendor/bin/phpunit` verde.

Claude Code deve criar a branch **antes** da primeira edição e commitar ao fim de
cada etapa, sem esperar ser lembrado.

## Commands

### Running the app
```bash
php spark serve            # dev server at http://localhost:8080
```

### Database
Default DB driver is **Postgre** (see `.env`, overriding the MySQLi default in `app/Config/Database.php`).
```bash
php spark migrate --all -n CodeIgniter\\Shield   # Shield (auth) tables
php spark migrate --all -n CodeIgniter\\Settings # Settings tables
php spark migrate                                 # App migrations (app/Database/Migrations)
php spark db:seed PlanSeeder                       # seed subscription plans (other seeders in app/Database/Seeds)
```
Initial setup is CLI-only: copy `env.example` to `.env`, run migrations and
seeders, then create the administrator with Shield. There is intentionally no
web installer route.

### Tests
PHPUnit config is `phpunit.xml.dist`; test DB env vars point at Postgres (`habitaweb_test`). `.env.testing` holds the DB credentials used by `run_tests.sh`.
```bash
vendor/bin/phpunit                          # run everything
vendor/bin/phpunit --filter testMethodName  # run a single test
vendor/bin/phpunit tests/unit/PaymentGatewayTest.php   # run a single file

./run_tests.sh setup      # create/prepare the test database
./run_tests.sh all        # unit + feature + e2e
./run_tests.sh unit       # tests/unit
./run_tests.sh feature    # tests/Feature
./run_tests.sh api        # tests/Feature/Api only (API v1 surface)
./run_tests.sh e2e        # tests/E2E
./run_tests.sh sandbox    # @group asaas-sandbox (hits the real Asaas sandbox)
./run_tests.sh coverage   # full suite + coverage report (build/logs)

# Partner journey over REAL HTTP — needs a server running:
php spark serve &
./run_tests.sh smoke [http://localhost:8080]
```
`tests/E2E/Scenarios/*` contains subscription lifecycle scenarios (signup, upgrade, grace period, cancellation/reactivation, failed-payment recovery) built on `tests/E2E/SubscriptionE2EBase.php`.

`tests/E2E/partner_smoke.php` is **not** a PHPUnit test — it's a standalone script that boots CI4 only to seed a tenant, then drives the whole partner journey (auth → import → images → export → tenant isolation) through cURL against a live server. It covers what `FeatureTestTrait` cannot: webserver, real `Content-Type` headers, files on disk, rate-limit headers.

Test helpers: `tests/_support/Factories/TenantFactory.php` (account + subscription + Shield user + API key/JWT) and `tests/_support/ApiTestTrait.php` (`makeApiTenant()`, `postJson()`, `resetApiState()`). Note `postJson()` sends a **raw** JSON body on purpose — `withBodyFormat('json')` also stuffs native PHP ints into `$_POST`, which no real HTTP request does and which makes the global `invalidchars` filter throw.

Image fixtures live in `tests/_support/fixtures/images/` — including a JPEG with real GPS EXIF (proves the EXIF strip) and a PHP webshell renamed to `.jpg` (proves content-based MIME rejection).

Composer also exposes `composer test` (plain `phpunit`).

### Custom spark commands
`app/Commands/` contains only operational commands: Asaas sync, expiry
checks, curation, the email worker, metrics flushing/pruning, media
generation, upload migration, cleanup, password/account maintenance,
integration sync/outbox (`integration:sync`, `integration:outbox`), and the
commercial-model commands from the pricing restructuring — lead-charge
lifecycle (`leads:aprovar-cobrancas`, `leads:fechar-ciclo`,
`creditos:conceder`), launch-ramp transitions (`assinaturas:aplicar-rampa`)
and the one-time legacy-plan migration (`planos:migrar-comercial`). The
`e2e:setup` command is test-only: it requires the Playwright marker and
refuses every database except `habitaweb_test`.

## Architecture

### Multi-tenancy model
Everything hangs off `accounts` (see `App\Entities\Account`, `App\Models\AccountModel`). A `users` row belongs to an `account_id`; properties, leads, subscriptions, payment transactions, API keys, etc. are all scoped by account. There is no row-level tenant filter at the framework level — controllers/services are responsible for scoping queries by the authenticated account.

Auth groups (`app/Config/AuthGroups.php`, Shield-based): `superadmin`, `admin`, `developer`, `user`, `beta`. Route-level authorization uses `filter => 'group:superadmin'` / `'group:superadmin,admin'` in `app/Config/Routes.php`.

### Request surfaces
- **Public web** (`App\Controllers\Web\*`, `App\Controllers\Home`): property search/detail, lead capture, checkout, partner marketplace, favorites. Routes are SEO-friendly path segments (`imoveis/(:segment)/(:segment)/(:segment)`).
- **Admin panel** (`App\Controllers\Admin\*`, prefix `/admin`): protected by the `admin_auth` filter (`App\Filters\AdminAuth`). This filter does more than login-check — it also enforces, per non-superadmin account: KYC verification approved, an ACTIVE subscription, and no invoice overdue >3 days (with a proactive gateway re-sync via `PaymentService::syncPendingPayments` before hard-blocking). A small allowlist of paths (checkout, logout, profile, subscription, api-keys, activation) stays reachable even when blocked, so the user can fix billing/KYC.
- **REST API** (`App\Controllers\Api\V1\*`, prefix `/api/v1`): protected by `api_auth` filter (`App\Filters\ApiAuth`), which accepts **three** credentials via `Authorization: Bearer ...` — a custom API key (`pk_...`, bcrypt-verified through `ApiKeyModel`), a **JWT** (3 dot-separated segments, verified by `App\Libraries\Auth\JwtManager`), or a Shield token. It injects `auth_user_id` / `auth_account_id` / `auth_account_type` / `auth_type` / `auth_api_key_id` onto the request. Rate-limited per-key via `api_rate_limit`; a JWT inherits the quota of the API key that minted it (claim `key_id`). Documented at `/api/docs` (Swagger UI, assets self-hosted in `public/assets/swagger/`).

  **Response envelope.** Every V1 endpoint answers through `Api\V1\BaseController::respondSuccess()` / `respondError()` — one shape for success and one for errors, with a stable `error_code` (the `BaseController::ERR_*` constants) that clients program against instead of the Portuguese `message`. Never use the `ResponseTrait` helpers (`failNotFound`, `respondCreated`, …) in V1: they emit a different shape. Parse JSON bodies with `getJsonBody()`, not `getJSON()` — the latter throws an `HTTPException` with no HTTP code (renders as 500) on malformed input.

  **Route ordering matters.** Specific routes must be registered *before* `$routes->resource(...)`, and every resource passes `'placeholder' => '(:num)'`. The router is first-match-wins, so a resource-registered `DELETE properties/(:any)` would otherwise swallow `DELETE properties/5/media/9`.

### Partner catalog sync (two-way)
`properties.external_id` is the partner's own identifier for a listing; `(account_id, external_id)` has a unique partial index and is the upsert key. `App\Services\PropertyImportService` drives `POST /api/v1/import/properties`, which content-negotiates between a JSON batch (≤200 items, images by URL) and a CSV upload, normalizes partner field aliases (`title`→`titulo`, `price`→`preco`, …) and returns a per-item `action` of `created|updated|error`. The return leg is `GET /api/v1/export/properties?format=json&updated_since=...`, whose output can be fed straight back into the import without duplicating anything. `App\Services\PropertyService::validatePropertyData()` is the shared field-level validator (the model itself only validates `account_id`).

### Image ingestion
Two entry points, one pipeline: multipart upload (`PropertyService::addMedia`) and URL ingestion (`PropertyService::addMediaFromUrl`, used by the import and `/media/batch`). Both go through `persistMedia()`, which strips EXIF via `App\Libraries\Media\ImageSanitizer`, generates the `card`/`gallery` variants, writes through the storage abstraction and enforces exactly one cover. Remote fetches go through `App\Libraries\Media\RemoteImageFetcher`, whose SSRF guard lives in `App\Libraries\Http\UrlGuard` (also used to validate webhook `target_url`). MIME is decided by file content, never by extension or client-declared type. `PropertyService` accepts an injected fetcher (`setImageFetcher()`) so tests can exercise URL ingestion without network.

### Privileged fields
`PropertyService::GUARDED_FIELDS` lists columns that are in `PropertyModel::$allowedFields` but must never be client-writable (`is_destaque`, `highlight_level`, `is_verified`, `score_qualidade`, counters…). They are stripped in `trySaveProperty()` whenever `$isStaff === false`. Add to this list rather than relying on controllers to filter.

> Postgres returns booleans as `'t'`/`'f'`, and the string `'f'` is truthy in PHP. Any boolean column read through a model **must** be declared in that model's `$casts` — the *entity* `$casts` alone does not apply to model reads.
- **Webhooks** (`App\Controllers\Webhook\WebhookController`, plus legacy `App\Controllers\Web\WebhookController` routes under `/asaas/webhook`, `/webhook/asaas`, `/webhook/(:segment)`): CSRF is disabled for `webhook/*` and `asaas/*` in `app/Config/Filters.php`.

### Payment gateways
`App\PaymentGateways\GatewayInterface` defines a common contract (customer CRUD, subscription CRUD, one-off payments, webhook parsing, pending-payment lookup) implemented by `AsaasGateway`, `StripeGateway`, and `MercadoPagoGateway`. `App\Services\PaymentService` is the orchestration layer above these (sync, overdue detection, etc.); `App\Services\AsaasService` holds Asaas-specific logic. Gateway credentials/config live in DB (`PaymentGatewayConfigModel`/`PaymentGatewayModel`), manageable at `/admin/payment-gateways`, not just `.env`.

### Commercial model (plans, lead charges, launch ramp)
`PRATA`/`OURO`/`DIAMANTE` are the current plans (`PlanSeeder`); the plans they
replaced live under `<CHAVE>_LEGADO` (`ativo=false`, same features so no
tenant loses anything on the seed run — see the seeder's own docblock).
`App\Services\PlanGate::has($accountId, $feature)` is the single point of
truth for plan-gated features (constants in `App\Entities\PlanFeature`) —
always go through it rather than reading `plans.features` directly. Property
listings are billed by **lead received**, not by closed deal
(`App\Services\LeadChargeService`, `lead_charges`/`lead_charge_rules`
tables); `spark leads:aprovar-cobrancas` and `spark leads:fechar-ciclo`
(cron, see `GUIA_ESCALABILIDADE_PRODUCAO.md` §3.4) turn approved charges
into gateway invoices monthly.

**Launch ramp** (`App\Services\LaunchRampService`, `plan_launch_ramps`
table): a subscription's mensalidade can start free and ramp up over its
own lifetime (from `subscriptions.ramp_started_at`, not the calendar — a
coupon-style global date range doesn't fit "6 months free from *this*
account's signup"). `ramp_started_at IS NULL` means the subscription simply
isn't enrolled and always pays full price — that's the safety default
every call site relies on. `spark assinaturas:aplicar-rampa` (daily cron)
applies faixa transitions; it corrects price automatically on a
subscription that already has a real gateway subscription, but deliberately
does **not** auto-create the first-ever real charge for a 0%→X% transition
(no payment method confirmed, no advance-notice email infra) — that one
transition is flagged in `audit_logs` for manual follow-up.
`spark planos:migrar-comercial` is the one-time, human-operated tool that
moves accounts off `*_LEGADO` plans onto the new ones (see the runbook in
`GUIA_ESCALABILIDADE_PRODUCAO.md` §13).

### Property scoring
`App\Services\Scoring\ScorerFactory::make($propertyType)` returns a `PropertyScorerInterface` implementation chosen by property type — `LandScorer` (terreno/lote), `CommercialScorer` (comercial/sala/loja), `WarehouseScorer` (galpão), defaulting to `ResidentialScorer` (apartamento/casa/cobertura/sobrado, and unknown types). Used by curation/verification and the admin "calculate score" endpoint.

### Integrations with external platforms (`app/Libraries/Integrations`)

A per-tenant connector layer that pulls a real-estate agency's catalog from the
system it already uses, and pushes captured leads back into that system's CRM.
The first (and so far only) connector is **Simob** (Flexpro Sistemas).

**Direction is dictated by the external API, not by preference.** Simob's API is
read-only for properties — there is no endpoint to create or update a listing
there. So: properties flow *Simob → Habitaweb*, leads flow *Habitaweb → Simob*
(via `/crm_interesse/create`). Do not promise two-way property sync.

- `IntegrationProviderInterface` + `AbstractProvider` — the contract. Resolved at
  runtime from `integration_providers.class_name` by `IntegrationRegistry`, the
  same dispatch pattern as `PaymentGateways`. A new connector is a class plus one
  DB row; no controller, service or view changes.
- `Http/IntegrationHttpClient` — the only place that talks to the network.
  Timeout, exponential backoff on 429/5xx (and *only* those — 4xx is not
  retryable), `UrlGuard` on the tenant-supplied base URL, and logging that never
  includes token, headers or response body.
- `Simob/` — `SimobClient` (raw endpoints), `SimobPropertyMapper`,
  `SimobLeadMapper`, `SimobVocabulary` (auto-guessing for the per-tenant mapping).

**Three Simob-specific traps**, each with a dedicated test:
1. **No JSON bodies.** Every POST is `multipart/form-data` with a single field
   `data` holding a JSON string. `['json' => $payload]` fails.
2. **Category and characteristic IDs are per-agency** ("Dormitório(s)" is id 41 at
   one agency, 249 at another), so the field mapping lives in
   `integration_mappings`, scoped per tenant, seeded by fuzzy name matching and
   confirmed by the tenant in the panel.
3. **No `updated_since` filter.** Incremental sync works by ordering
   `atualizacao desc` and stopping when the page's last item predates the last
   sync.

**Services:** `IntegrationService` (credentials, test connection, mappings),
`IntegrationSyncService` (catalog run), `IntegrationOutboxService` (leads out),
`IntegrationCommissionService` (charge per closed lead).

**Commands:** `integration:sync` and `integration:outbox` (both cron, every
minute). `integration:sync` running every minute is what gives the panel's
"Sincronizar agora" low latency (it never runs the sync inside the web
request — it only flags `sync_priority_requested_at`, and the next cron pass
picks it up with no PHP time limit); each integration's own automatic
interval stays ~25 min via `AccountIntegrationModel::dueForSync()`'s
staleness filter, not the cron frequency. Both documented in
`GUIA_ESCALABILIDADE_PRODUCAO.md` §3.4.

**Tenant panel:** `/admin/integracoes`. Routes take only the *connector code* —
the account always comes from `auth()->user()`, never from the URL, so there is
no id to tamper with.

> Imported properties are **read-only mirrors**. `IntegrationService::MANAGED_FIELDS`
> lists the columns the sync overwrites (`status` is deliberately NOT in it —
> a mirrored property's status is sync-managed only on the CREATE, so the
> tenant's own pause/publish choice afterward survives every later sync).
> The guard itself lives in `PropertyService::trySaveProperty()` (the
> `$fromSync` param is how the sync's own writes bypass it) — controllers
> just surface `ignored_fields` from its return, they don't filter anything
> themselves. Deleting a mirrored property calls
> `PropertyService::deleteOrPauseProperty()`, which pauses instead of
> deleting — soft-deleting it would make the next sync's dedupe treat it as
> new and reimport it. The `readonly` in the form is convenience, not the
> barrier.

> The sync lock is a column, not a cache key: `account_integrations.sync_locked_until`,
> acquired with an atomic conditional `UPDATE ... WHERE sync_locked_until IS
> NULL OR sync_locked_until < now()` (1 row affected = acquired). A cache-based
> lock couldn't survive a PHP Fatal Error (`max_execution_time`, for
> instance) — that skips every `catch`/`finally`, so the key stayed locked
> until it expired on its own with no way to clear it early. The column
> expires the same way but a `register_shutdown_function` also clears it
> immediately when the shutdown handler runs.

> Integration credentials use **reversible** encryption (`Services::encrypter()`,
> the `PaymentGatewayConfigModel` pattern) — unlike `api_keys.key_hash`, which is
> bcrypt. An inbound key only needs verifying; an outbound token must be replayed
> on every call.

> Integration properties are linked through `property_external_refs`, **not**
> `properties.external_id`. That column is single-valued and already the upsert
> key for the partner import; a tenant using both paths would collide.

### Other domain services (`app/Services`)
`AccountService`, `ClientService`, `CurationService` (property moderation/reports), `DashboardService`, `ExportService`, `FinancialService`, `FraudService`, `IntegrationService`, `IntegrationSyncService`, `IntegrationOutboxService`, `IntegrationCommissionService`, `KYCService`, `LeadService`, `NotificationService`, `PromotionService` (property boosting/"turbo"/promotion packages), `PropertyService`, `RankingService`, `WebhookService`.

### Entities vs Models
`app/Entities/*` are CodeIgniter Entity classes (typed property access/mutation) paired 1:1 with `app/Models/*` (query builder + validation + business rule helpers, e.g. `PaymentTransactionModel::isAccountBlockedByOverdue()`).

## Notable repo quirks

- `README.md` documents a **planned, not-yet-built** AI layer (`AIService`, `PropertyInsightService`, `TrustService`, `LeadInsightService`) gated by `AI_ENABLED`/`AI_PROVIDER` env vars, meant to be fully optional with mandatory local fallback when disabled/unavailable. Treat this as a roadmap note, not existing code, unless you find the actual classes.
