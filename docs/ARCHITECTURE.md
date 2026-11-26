# Architecture audit

This document describes the implementation present in this repository as inspected on 2026-11-25. It is an API/backend application; the storefront and dashboard user interfaces are not present here.

## System shape

```text
External storefront / dashboard clients
              │ JSON over HTTP (routes/api.php)
              ▼
Laravel 11 API ── central MySQL (accounts, store registry, access control)
       │
       └── per-request tenant MySQL database (catalog, customers, carts, orders, settings)
              │
              ├── Stripe / Stripe Connect
              ├── FedEx
              ├── mail transport, S3, optional Pusher
              └── Laravel queue and scheduler
```

`resources/js` contains only the Laravel Vite bootstrap and Axios setup. No React, Vue, or other storefront/admin application is included. The app serves a minimal Blade welcome view and XML sitemap route; most product UI is expected to be an external client. `routes/api.php` is the principal application surface and returns JSON/resources.

## Laravel organization

- `app/Http/Controllers/api/Admin` handles dashboard administration; `api/Web` handles storefront/customer actions; `api/FedEx` handles shipping configuration/rates; `AuthController` and `TwoFactorController` implement authentication.
- `app/Services` contains tenant resolution/provisioning, cart context, localization, payments, Stripe account resolution, FedEx rates, order numbering, and transactional email.
- `app/Models` is a broad Eloquent domain model set. Most commerce models inherit Laravel's default connection, which is switched to `tenant` by request middleware. `Store` and `CentralUser` explicitly use `central`.
- `app/Http/Resources` shapes many API responses. Exports/imports, mailables, jobs, events/listeners, console commands, and seeders support operations and workflows.
- Laravel 11 bootstrap is customized through `app/Http/Kernel.php`, `bootstrap/app.php`, and service providers.

## Central and tenant data

`config/database.php` defines `central`, `tenant`, `provisioner`, and the legacy/default `mysql` connection. The central registry's `stores` rows map a store hash to tenant database coordinates and encrypted tenant password. Tenant data lives in independent MySQL databases, rather than a shared tenant schema selected by `store_id` alone. Legacy `store_id` columns remain widespread in tenant tables and are used for row scoping and migration compatibility.

Central schema migrations live under `database/migrations/central`; tenant schema migrations live under `database/migrations/tenant`. The root migration directory is the legacy/platform schema and contains historical migrations, including a legacy `stores` table creation. `TenantSchemaInstaller` runs only the tenant migration directory against the resolved tenant connection. The provisioner credentials create the database and scoped tenant DB user. Tenant passwords are encrypted by `TenantCredentialCrypt` and decrypted by `TenantCredentialResolver`.

`ResolveStoreByHash` validates `X-Store-Hash` (`store-` plus 12 lowercase alphanumeric chars) and resolves the central registry record. `ConnectTenantDatabase` connects, sets the default connection to tenant during the request, and restores/disconnects in `finally` (including streamed responses). `ResolveDashboardTenant` checks central store ownership or `store_user` membership, connects tenant, and exposes the trusted context. A store hash is an identifier, not an authorization credential.

## Store creation and provisioning

`POST /api/dashboard/user/register` invokes `AuthController::registerOwner`. Owner registration creates/reuses a central user, creates a central `Store` registry record, registers it as pending, then calls `TenantProvisioningService::provisionDatabase`. Provisioning claims a pending row, generates a tenant schema/user/password, creates a database and restricted database account, installs tenant migrations, seeds tenant roles/payment methods, migrates selected legacy store data, and marks the registry active only after success. The registry stores the DB password encrypted. Owner membership and central permissions are assigned as part of onboarding. Staff registration instead attaches a central user to an existing active store and grants submitted central permissions; it does not create a tenant database.

Provisioning can also be run through `tenant:provision`; the command supports a dry run for data migration and a confirmation option. Other operational commands migrate all tenants, backfill registry/hash/payment data, import products, and seed tenant defaults. Failed provisioning can leave the registry in a non-active provisioning state and may leave resources for operator cleanup; it is not a distributed transaction across MySQL DDL, imports, and central state.

## Identity, staff, and authorization

There are two user model contexts: `CentralUser` is explicitly central and central dashboard auth is selected for dashboard paths by the custom Sanctum token model; tenant `User` is used for storefront customers and legacy-compatible user operations. Customer registration/login and customer resources are tenant-resolved. Dashboard tokens are stored centrally, including on tenant-scoped dashboard requests. Tokens are Sanctum personal access tokens with a custom `PersonalAccessToken::findToken` choosing central vs tenant storage using request path/context.

Role values are used in code (`2` is customer; owner/staff roles include `1`, `3`–`6`). Staff/store membership and per-store permissions are central tables after the central migration. `CheckPermission` requires authenticated non-customer, trusted store context, and an assigned permission pivot for that central store ID. Dashboard routes combine authentication, store hash resolution, tenant authorization, and route-specific permission middleware, though route coverage is not uniform (see risks). Two-factor flows support email OTP and authenticator setup through `TwoFactorService`.

## Commerce domains

- **Catalog:** Products, images, identifiers, SEO/content/extras, brands, categories and product-category pivot; product controllers expose CRUD, filtering, import/export, search, featured/related listing and redirects. `ProcessProductImport` is queued and progress is cached. There is no dedicated variant model/table in the inspected model list; variant behavior should not be assumed from the existence of product attributes or `item_type` fields.
- **Inventory:** Product quantity/availability fields and `InventorySetting` are present. Product/admin controllers manage catalog stock fields. The audit did not find a separate stock ledger/reservation subsystem.
- **Customers and carts:** Tenant customers, addresses, saved addresses, wishlists, visitor sessions and carts. `CartContextService` supports guest/customer cart identity and transfer. Cart rows and shipping-rate selections are tenant-owned.
- **Orders and checkout:** `OrderController` is the large active implementation for storefront order calculation/place order, customer history/returns, dashboard order creation/drafts, status changes, invoices/refunds, notes and timeline. Orders, items, payments, calculations, coupon usage, checkout form data, return requests and shipments are tenant models. Order placement integrates Stripe and FedEx paths and sends mail synchronously in active methods. A very large controller with substantial commented historical implementations is a maintenance and regression risk.
- **Shipping:** Shipping zones/methods, store shipping addresses, FedEx configuration and quote logs are tenant records. `ShippingRateService`, `FedExAuthService`, and `FedExRateService` mediate FedEx calls. Checkout rate APIs are separate from dashboard shipping configuration.
- **Taxes:** Order calculation models/fields exist, but the inspected integrations do not establish a tax-provider integration or comprehensive tax engine. Tax behavior should be confirmed against the active `OrderController::calculate` implementation and configured store rules before being treated as production-ready.
- **Payments:** A payment-method catalog in `config/payment_methods.php` is applied to tenant-level `StorePaymentMethod` records. Stripe Connect credentials/configuration live in tenant data; OAuth callback/webhook routes and payment intent/webhook code exist. Stripe dynamic settings are feature-flagged. PayPal credentials can be stored, but the payment settings service explicitly says connection/API verification remains later work; Apple/Google wallet providers are availability-gated and PayPal provider connection is not implemented. Offline methods are configuration based.

## Settings and APIs

Store-specific profile, localization, URLs/redirects, SEO scripts, banners/carousels/logos, email marketing, order status/notifications, returns, inventory, comments, promotions, transactional email templates, payment and shipping configuration are represented by tenant models/controllers. Platform plans/subscriptions and identity/access metadata are central. Store configuration is not one unified settings object.

API endpoints are grouped by `/api/dashboard`, `/api/web`, and domain prefixes. Dashboard requests generally use Sanctum, `X-Store-Hash`, dashboard membership authorization and permission checks; storefront public routes use `X-Store-Hash` and tenant database middleware, with customer-specific endpoints additionally using Sanctum. Requests/responses use JSON and Laravel API resources. The actual frontend implementation is outside this repository, so no client-side state management, routing, or API wrapper can be audited here.

## Queues, scheduling, and external services

`config/queue.php` defaults to `sync`; database, SQS, Redis and Beanstalkd options are configured. Jobs implement `ShouldQueue` for product import/image processing and abandoned-cart mail. `SendAbandonedCartReminder` iterates active central stores, resolves each tenant, reserves eligible cart reminder timestamps and dispatches jobs. Laravel scheduler runs this every five minutes with overlap prevention. `checkout:clean-expired` is hourly via `routes/console.php` and deletes old tenant checkout form rows. Shipment-created events trigger a synchronous mail listener. Realtime stats event uses `ShouldBroadcastNow`; Pusher is configured as an option, while broadcast default is `null` and frontend Echo setup is commented out.

Composer dependencies and code show Stripe, FedEx, AWS S3, mail, optional Pusher, Excel/CSV import/export, PDF invoice/packing-slip generation, and Google2FA. The repository does not include frontend clients or a deployment/runbook that establishes production worker, cron, webhook, domain, or credential setup.

## Security and tenant boundaries

Positive controls include distinct central/tenant connections, tenant DB credentials with database-scoped grants, encrypted tenant DB passwords, validated store-hash format, central store resolution, dashboard ownership/membership checks, per-store permission pivots, Sanctum tokens, two-factor code, request validation in many controller actions, and store context in the custom token lookup. These are implementation facts, not a complete security certification.

Verified risks and gaps are listed separately in [DEVELOPMENT-GUIDE.md](DEVELOPMENT-GUIDE.md). No application code was changed during this audit.
