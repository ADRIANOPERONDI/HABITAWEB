# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**Habitaweb** is a multi-tenant real-estate listing SaaS built on **CodeIgniter 4** (PHP >= 8.1) with **CodeIgniter Shield** for auth. It has a public property-search portal, an admin panel (per-account), a versioned REST API, and integrations with three payment gateways (Asaas, Stripe, Mercado Pago) for subscription billing.

The working directory is `copia_zap` but the app/product name throughout code, DB, and docs is **Habitaweb**.

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
`app/Commands/` contains only operational commands such as Asaas sync, expiry
checks, curation, the email worker, metrics flushing, media generation, upload
migration, cleanup and password/account maintenance. The `e2e:setup` command
is test-only: it requires the Playwright marker and refuses every database
except `habitaweb_test`.

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

### Property scoring
`App\Services\Scoring\ScorerFactory::make($propertyType)` returns a `PropertyScorerInterface` implementation chosen by property type — `LandScorer` (terreno/lote), `CommercialScorer` (comercial/sala/loja), `WarehouseScorer` (galpão), defaulting to `ResidentialScorer` (apartamento/casa/cobertura/sobrado, and unknown types). Used by curation/verification and the admin "calculate score" endpoint.

### Other domain services (`app/Services`)
`AccountService`, `ClientService`, `CurationService` (property moderation/reports), `DashboardService`, `ExportService`, `FinancialService`, `FraudService`, `KYCService`, `LeadService`, `NotificationService`, `PromotionService` (property boosting/"turbo"/promotion packages), `PropertyService`, `RankingService`, `WebhookService`.

### Entities vs Models
`app/Entities/*` are CodeIgniter Entity classes (typed property access/mutation) paired 1:1 with `app/Models/*` (query builder + validation + business rule helpers, e.g. `PaymentTransactionModel::isAccountBlockedByOverdue()`).

## Notable repo quirks

- `README.md` documents a **planned, not-yet-built** AI layer (`AIService`, `PropertyInsightService`, `TrustService`, `LeadInsightService`) gated by `AI_ENABLED`/`AI_PROVIDER` env vars, meant to be fully optional with mandatory local fallback when disabled/unavailable. Treat this as a roadmap note, not existing code, unless you find the actual classes.
