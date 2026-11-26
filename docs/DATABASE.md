# Database architecture and domain map

## Connections and ownership

| Connection | Purpose in code | Typical data |
|---|---|---|
| `central` | Platform identity and store registry; explicit in `Store`, `CentralUser`, registry services | users, stores/registry, store memberships, permissions, plans/subscriptions, central Sanctum tokens |
| `tenant` | Runtime store-specific application database; database name and credentials are set per request | catalog, customers, carts, orders, payments, shipping, settings, tenant users/tokens |
| `provisioner` | Administrative MySQL connection used to create tenant databases and DB users | DDL only |
| `mysql` (default legacy) | Existing/legacy source database and default connection outside tenant-scoped middleware | legacy application data used by `TenantDataMigrator` |

Connection names do not imply IDs are interchangeable. Central registry ID and legacy tenant `store_id` are explicitly bridged through `legacy_store_id`; onboarding code uses both in different contexts. Review every relation/query where IDs cross connections.

## Migration layout

- `database/migrations/central`: platform account, plan/subscription, two-factor/staff auth and central permission/membership schema.
- `database/migrations/tenant`: consolidated tenant schema plus incremental tenant-specific settings/payment/shipping/order updates. `TenantSchemaInstaller` invokes Artisan migrate against this path and connection.
- `database/migrations` root: historical/default schema plus legacy store conversion and later central registry changes. It is not equivalent to a clean tenant installation.
- `database/seeders`: permission, tenant role, shipping, payment method/currency, and transactional email template defaults.

The tenant installer verifies the live target database before and after migration. The provisioning pipeline seeds tenant defaults then selectively copies legacy rows. `TenantDataMigrator` selects rows with the legacy store ID, expands related rows through conventional `*_id` links, intersects source/target columns, batches inserts, disables FK checks during copy, excludes platform tables, and stops if selected target tables already contain rows. It also deduplicates nonempty product SKUs. This is a custom import, not a general-purpose migration framework.

## Tenant domain tables (representative)

- Catalog/content: `products`, `categories`, `category_product`, `brands`, `product_images`, `product_identifiers`, `product_contents`, `product_seo`, `product_extra`, `product_filters`, `web_pages`, `blog_posts`, `banners`, `carousels`, `logos`, `redirects`.
- Customer/storefront: `users` (tenant customer/legacy user context), `customers`, `customer_addresses`, `saved_addresses`, `carts`, `cart_shipping_rates`, `wishlists`, `reviews`, `visitor_sessions`, `customer_messages`, `newsletters`.
- Sales: `orders`, `order_items`, `payments`, `order_calculations`, `order_cart_item_products`, `coupon_code`, `coupon_usages`, `promotions`, `manual_discounts`, `return_requests`, `shipments`, `order_notes`, `order_timelines`, checkout form data.
- Operations/configuration: `store_settings`, `url_settings`, `localization_settings`, `inventory_settings`, order/return/comment/promotion settings, payment methods/providers/webhook endpoints, shipping zones/methods/FedEx configuration and quote logs, email templates/phrases, scripts, logs.

This list is based on migrations and model files, not a guarantee that every listed table has complete CRUD or production use. No dedicated product variant model/table appeared in the inspected model inventory. Inventory is represented through product fields/settings; there is no evident stock movement ledger.

## Model connection behavior

Most tenant models rely on Laravel's default connection rather than declaring `$connection = 'tenant'`; correctness therefore depends on request middleware changing the default connection before model access. `Store`/`CentralUser` explicitly use central. `User` dynamically returns `tenant` when request attributes include a tenant store; otherwise it follows the default. `PersonalAccessToken` customizes token lookup to use central for dashboard paths and tenant context for customer paths. This coupling makes console jobs and non-HTTP access responsible for explicitly resolving/configuring the tenant before using tenant models.

## Schema and operations risks

- Root, central and tenant migration directories represent different deployment histories; applying the wrong path/connection could create or mutate the wrong schema.
- Root migration filenames include duplicate-looking table creation and malformed-looking extensions (for example `*.php.php`); inspect migration status and Laravel discovery behavior before changing deployment processes.
- `TenantDataMigrator` heuristically infers relationships from conventional `*_id` names and shared schema columns. Verify copied table counts and relationships for each legacy dataset; nonconventional relationships may be omitted or unrelated global rows may be copied when referenced.
- Cross-database onboarding is not atomic. Partial DDL/import can remain when a later step fails, although a store is only marked active after successful completion.
- Historical `store_id` values, registry IDs, user IDs and product/order IDs must not be assumed equal across central and tenant databases.
