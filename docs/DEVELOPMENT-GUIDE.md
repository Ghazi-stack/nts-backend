# Development guide and audit findings

## Repository conventions observed

- Laravel 11 / PHP 8.2; Composer manages backend dependencies. Vite builds the small `resources/js` and `resources/css` asset set.
- Route definitions live mainly in `routes/api.php`; controllers use `api/Admin`, `api/Web`, and `api/FedEx` namespaces.
- Tenant API work must establish trusted store context before using models that inherit the default connection. Console commands/jobs must resolve tenant credentials explicitly.
- Central and tenant migrations are separate deployment domains. Never run a tenant schema operation on the default or central connection.
- Reuse existing domain services/resources/models and preserve existing response shapes. Keep secrets in environment/config or encrypted tenant registry fields; never add credentials to docs or source.
- No app code was changed during the architecture audit. The existing `.gitignore` working-tree edit was present before this work and was not touched.

## Verification gaps and technical risks

These are items found by static inspection; they are not claims that a live system is exploitable or broken.

1. **Frontend absent:** There is no dashboard/storefront UI, so client auth storage, CSRF strategy, API retry behavior, state management, and route guards cannot be verified here.
2. **Middleware exceptions:** Dashboard routes are not consistently permission-gated. For example, permission-management endpoints have authentication but no explicit `permission:` middleware in their route declarations; some storefront routes have tenant context but require endpoint-level ownership checks. Audit controller methods before treating URL grouping as authorization.
3. **Open diagnostic endpoint:** `GET /api/test-s3` performs a write to a predictable S3 object path and is registered without auth middleware. This is operational/debug functionality on a public route.
4. **Remote image fetch:** `ProcessProductImage` fetches a user-provided URL with TLS verification disabled (`verify => false`) and checks response content type. This creates SSRF/TLS-validation and payload-size/content-validation concerns; job exceptions are swallowed.
5. **Credential storage review:** Tenant DB password is explicitly encrypted, but tenant payment/FedEx credentials have JSON casts and are handled through tenant models. Confirm at-rest protection and response redaction for every serialization path. Do not assume a field is protected merely because it is secret.
6. **Queue deployment dependency:** Queue defaults to `sync`, so jobs may execute inline unless deployment overrides it. Scheduler entries require a working Laravel scheduler/cron. No deployment worker/scheduler guide is present.
7. **Cross-database provisioning:** Database creation, migrations, seeding, data copy and central activation cannot share one transaction. Failure handling can leave an inactive provisioning row and partially provisioned resources.
8. **Data migration heuristic:** Legacy import infers relationships by conventional `*_id`, excludes selected tables, intersects columns and uses legacy IDs. Validate per-store ownership, shared reference data, uniqueness and foreign-key correctness for all schema variants.
9. **Large legacy controllers:** `OrderController` and `AuthController` contain extensive commented-out historical implementations alongside active methods. This complicates review, static searching, and safe maintenance.
10. **Tax and variants uncertain/incomplete:** No tax-provider integration or dedicated variant model was found in the inspected implementation. Existing calculation/product fields do not by themselves prove full tax or variant support.
11. **Payment integrations partial:** PayPal is not connected/verified in the payment settings service; wallet availability is gated and PayPal provider is hard-coded disconnected. Stripe dynamic settings are feature-flagged. Verify which Stripe mode/flow is active before enabling payments.
12. **Realtime frontend path:** Pusher is an optional dependency/configuration, but broadcasting defaults to `null`; `resources/js/bootstrap.js` leaves Echo setup commented out.
13. **Test coverage is sparse in repository:** Only example tests were listed during file inventory. No test suite was run because this task was a documentation audit and the repository instruction's test step applies after implementation changes.

## Follow-up audit checks

Before production changes, verify deployed database migration status and actual grants, inventory all route middleware with Laravel's route list, inspect response serialization for credentials, test tenant switching under long-lived workers, review queue connection and worker deployment, confirm Stripe/FedEx webhook signature settings, and run tenant isolation tests using different central/legacy IDs. These checks require deployment context absent from this repository.
