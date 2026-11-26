# API and business workflows

All API routes are declared in `routes/api.php` and are automatically prefixed by Laravel's API route registration. The external storefront/dashboard clients are not in this repository.

## Request context

1. Client supplies `X-Store-Hash` for a tenant-scoped operation.
2. `store.hash` validates the identifier and looks up the central registry.
3. `tenant.database` asks `TenantCredentialResolver` to require an active store, decrypt credentials, configure/purge/reconnect `tenant`, and set it as default during request execution.
4. For dashboard actions, `tenant.dashboard` verifies central owner or `store_user` membership and associates the tenant context. `permission:<name>` then checks the central user permission pivot for the resolved central store ID.
5. Middleware disconnects and restores connection state after the response. Streaming responses defer cleanup until stream callback completion.

Public/customer storefront routes mostly use `web` as a route prefix and require store-hash/tenant middleware; customer-only operations add Sanctum. Dashboard routes are grouped under `dashboard`; authentication is central for dashboard token paths. There are exceptions and uneven route middleware, so consult the route declaration for each endpoint before relying on a policy.

## Store onboarding

`POST /api/dashboard/user/register` calls owner/staff registration. Owner path validates fields and role, creates/reuses central owner identity, creates central store registry row, generates a hash, and runs tenant provisioning synchronously. The provisioner claims the registry row as provisioning, creates a per-store database and limited database account, installs tenant schema, seeds roles/payment/email/shipping defaults, imports eligible legacy records, then activates the central store. Owner membership and permissions are added centrally. Staff path targets an active store, creates/reuses a central identity, adds central membership and selected permissions, and sends access setup mail. Tenant creation is not performed for staff.

## Customer and cart flow

Customer registration/login/reset and storefront customer endpoints resolve a store and use tenant data. Cart endpoints support guest context, SKU/item add, update/delete, shipping-rate selection, and authenticated guest-cart transfer. `CartContextService` is the common identity/context utility. Checkout form data is persisted per tenant and expired rows are cleaned hourly. Storefront API exposes `/web/orders/order-calculate` and `/web/orders/place-order`; authenticated routes expose customer order history, invoices, return requests, saved addresses, and messages.

## Checkout and order flow

The active `OrderController::calculate` and `store` perform the commerce path. The controller validates the checkout payload, reads tenant cart/product/customer/store settings, computes totals/discounts/shipping data, verifies Stripe intent/payment state where applicable, creates order and item/payment records, updates cart/product/order-related state, and sends order mail. FedEx rate retrieval is handled by `CheckoutShippingController` plus FedEx services. Dashboard order creation supports existing/new customers and drafts; dashboard routes also support status updates, invoices, refunds, notes/timeline, shipment management and tracking imports. This behavior is concentrated in a very large controller and should be traced at the named active methods when changing checkout behavior.

The code contains a Stripe Connect OAuth callback and Connect webhook, plus Stripe payment webhook routes. Actual successful payment/order finalization depends on the active route/controller path and Stripe environment configuration; webhook signature/setup requirements are deployment-specific and not documented elsewhere in this repo.

## Catalog workflows

Dashboard product/category/brand routes use store context and permissions. Product creation/update uses product relations for media, identifiers, SEO and content; CSV import/export and search/listing operations are exposed. Import dispatches `ProcessProductImport` and stores progress in cache. Product image jobs can retrieve remote images and send assets to S3. Storefront endpoints expose product lists/details/search/related/featured/popular products, categories, brands, pages and content assets.

## Other routes

- Store settings endpoints manage profile, localization, URL/redirects, SEO/scripts, payment setup, inventory/order/return/comment/promotion/email settings.
- FedEx dashboard endpoints manage credentials/configuration, methods/zones and shipping addresses; checkout exposes rate lookup.
- Customer review, wishlist, newsletter, contact request, bulk inquiry, visitor tracking and sitemap endpoints are available.
- Two-factor routes expose OTP verification/resend and authenticated enable/setup/confirm/disable operations.
- `GET /api/test-s3` is a standalone diagnostic route that writes a fixed object to the configured S3 disk.

## Background execution

`cart:send-abandoned-reminders` is scheduled every five minutes and iterates active central stores. It connects each tenant, finds eligible abandoned carts, reserves reminder timestamps and dispatches `SendAbandonedCartEmailJob`. The queue default in `config/queue.php` is `sync`, so asynchronous behavior requires deployment configuration and workers. `checkout:clean-expired` is scheduled hourly in `routes/console.php`. Product imports/image processing implement `ShouldQueue`. Shipment creation dispatches an event whose listener sends mail inline. Realtime stats are `ShouldBroadcastNow`; broadcast default is `null`.
