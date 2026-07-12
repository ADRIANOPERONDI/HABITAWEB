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
./run_tests.sh all        # full suite
./run_tests.sh security   # OWASP-style tests (tests/unit/SecurityTest.php)
./run_tests.sh crud       # CRUD E2E flows (tests/unit/CRUDFlowTest.php)
./run_tests.sh api        # REST API tests (tests/unit/APITest.php)
./run_tests.sh image      # upload/image handling (tests/unit/ImageHandlingTest.php)
./run_tests.sh payment    # gateway tests (tests/unit/PaymentGatewayTest.php)
./run_tests.sh business   # plans/coupons/leads/pricing (tests/unit/BusinessLogicTest.php)
./run_tests.sh coverage   # full suite + coverage report (build/logs)
```
`tests/E2E/Scenarios/*` contains subscription lifecycle scenarios (signup, upgrade, grace period, cancellation/reactivation, failed-payment recovery) built on `tests/E2E/SubscriptionE2EBase.php`.

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
- **REST API** (`App\Controllers\Api\V1\*`, prefix `/api/v1`): protected by `api_auth` filter (`App\Filters\ApiAuth`), which accepts either a custom API key (`pk_...` prefix, validated via `ApiKeyModel`) or a Shield token (`Authorization: Bearer ...`), and injects `auth_user_id` / `auth_account_id` / `auth_account_type` / `auth_type` onto the request object for downstream use. Also rate-limited per-account via `api_rate_limit` filter. Self-documenting at `/api/docs`.
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
