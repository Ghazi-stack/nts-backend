# Product management implementation audit

**Scope:** static trace of product/catalog code in this repository, inspected 2026-11-25. No application code, migrations, or database tables were changed. “Implemented” below means code/schema exists here; runtime deployment state and external frontend behavior were not available for verification.

## 1. Product CRUD and API surface

### Dashboard routes

The dashboard product routes in `routes/api.php` use `store.hash`, `tenant.dashboard`, and the product permission middleware:

| Operation | Route suffix | Handler |
|---|---|---|
| List/search filters | `GET /dashboard/products/products-list` | `ProductController::index` |
| Purchasable list | `GET /dashboard/products/purchasable-products` | `purchasableProducts` |
| Filtered list | `GET /dashboard/products/products-filter` | `filterProducts` |
| Product detail | `GET /dashboard/products/product/{id}` | `show` |
| Add product | `POST /dashboard/products/add-product` | `addProduct` (requires `add_product`) |
| Update single | `POST /dashboard/products/update-single-product/{id}` | `updateSingleProduct` |
| Bulk update | `PUT /dashboard/products/update-product` | `updateProduct` |
| Delete one/bulk | `DELETE /dashboard/products/delete-product/{id?}` | `destroyProduct` |
| Detach categories | `DELETE /dashboard/products/delete-product/categories` | `destroyProductCategories` |
| Search | `GET /dashboard/products/search-product` | `searchProduct` (`search_products`) |
| Import/progress/cancel | `POST import-csv`, `GET import-progress/{progressKey}`, `POST cancel-import` | `importCSV`, `importProgress`, `cancelImport` (`import_products`) |
| Export | `GET /dashboard/products/export-csv` | `exportCSV` (`export_products`) |

Category CRUD is under `/dashboard/categories` and requires `categories`. Brands have their own permission-gated CRUD under `/dashboard/brands`.

### Storefront routes

`routes/api.php` wraps `/web/*` routes in `ResolveStoreByHash` and `ConnectTenantDatabase`. Product reads include `/web/products/products`, `/get-product/{slug}`, `/get-product-by-url/{slug}`, `/search-product`, `/featured-products`, `/related-products`, `/products/by-brand`, `/popular-products`, `/last-week-orders`, `/last-week-product`, and `/product-byIds/{id?}`. These call methods in `ProductController`; some storefront detail handlers look up by SKU despite route naming a slug. Category and brand read routes use `CategoryController` and `BrandController`.

There is no storefront/dashboard application in this repository, only the API and a minimal Blade/Vite shell. Thus frontend product forms, variant selector behavior, API client types, validation display, and contract consumption cannot be verified. `ProductResource` and `ProductListResource` are the key response shapers; endpoint-specific responses also wrap them in JSON status/message/data/pagination structures.

### CRUD implementation observations

- `addProduct` validates a large camelCase payload, creates a `Product`, attaches selected categories, stores image rows, and creates related content, identifiers, and SEO rows. These are multiple writes without a visible encompassing transaction; a mid-flow failure can leave a partial product.
- `updateSingleProduct` scopes the product lookup by `store_id`, updates scalar fields, syncs categories and upserts content/identifier/SEO. Its assignments default omitted fields to false, zero, empty string, or null in a number of cases; it behaves more like a full form submission than a safe sparse PATCH. It also generates a new slug on every update.
- `updateProduct` bulk-updates products selected by IDs under the current store. This path handles many of the same fields but has a distinct payload and response shape.
- `destroyProduct` scopes product lookup by current store and supports one or many IDs. It explicitly removes SEO rows, then deletes the product. The consolidated tenant schema does not add product foreign keys for every dependent table, so cleanup of images, content, identifiers, category pivot rows, or storage objects is not consistently guaranteed by database cascade. Product-related order/cart history also needs deliberate delete behavior.
- `current_store_id()` uses the registry `legacy_store_id` when present, falling back to registry ID or `storeId` request header. Models are tenant-connection scoped, but many controller queries still add explicit `store_id` filters.

## 2. Product persistence and schema

`Product` explicitly uses the `tenant` connection. Its principal fields include:

| Concern | Existing storage |
|---|---|
| Identity | `id`, `store_id`, `name`, `slug`, nullable `sku` |
| Base commercial values | `price`, `msrp`, `retail_price`, `cost_price`, `sale_price`, `fixed_shipping_cost`, `discount_type`, JSON-like `bulk_pricing_tiers` |
| Type/legacy option fields | `product_type`, `item_type`, `option_set`, `option_set_align` strings |
| Stock | `track_inventory`, `current_stock`, `low_stock`, `quantity`, `sold_count`, purchase quantity limits |
| Shipping | JSON `dimensions` plus legacy weight/dimension fields; `free_shipping`, fixed shipping cost |
| Discovery/visibility | `visible_on_storefront`, `is_featured`, `sort_order`, `search_keywords`, `allow_purchase`, `purchasability_status`, `is_redirect` |
| Content/metadata | `custom_fields` JSON, `condition`, `faq`, customs fields, relation-backed descriptions, identifiers and SEO |
| Legacy parent/duplicate data | `parent_id`, `is_duplicate` |

The clean tenant creation schema is `database/migrations/tenant/2026_10_20_000002_create_consolidated_tenant_schema.php`. It executes base64-encoded SQL, not readable `Schema::create` definitions. Decoding the product statement shows the current tenant `products` columns above, with `weight` decimal and dimensions stored as long text/JSON-compatible data. The same schema has unique key `products_store_id_sku_unique (store_id, sku)` and several overlapping search/list indexes. A later tenant migration `xxxx_xx_xx_xxxxxx_change_price_decimal_in_products_table.php` changes base price precision to decimal(16,6); inspect deployed migration history before relying on exact precision. Legacy root migrations in `database/migrations` are not a clean tenant schema and include multiple historical changes.

Important migration/model discrepancy: root migration `2025_07_11_135644_create_product_attributes_table.php` creates key/value `product_attributes`, and `Product.php` imports `ProductAttribute`, but `app/Models/ProductAttribute.php` is absent and the consolidated tenant schema does not create `product_attributes`. The relationship is not defined in `Product`. This is not a functional product-specification system in the tenant implementation.

Other product-owned models and relations:

- `ProductImage`: product, path/url, primary flag, alt text/description, store ID. No explicit display sort position.
- `ProductContent`: description, warranty, availability text, template layout.
- `ProductIdentifier`: MPN, UPC, GTIN, BPN. The relation is `hasOne`.
- `ProductSeo`: page title, product URL, meta fields, redirect flag. The relation is `hasOne`.
- `ProductExtra`: retail/distributor/GPS/accounting metadata.
- `ProductFilter`/`ProductSearch`: store-scoped filter configuration and search counts; not option/variant attributes.
- `Category`: hierarchical self relation via `parent_id`; products linked many-to-many through `category_product`.
- `Brand`: store-owned brand with product relation.

The encoded tenant snapshot includes indexes for product/category joins, images, and product lookups but does not install `product_attributes`, product-option tables, or product-variant tables. Category-product is many-to-many, despite the legacy `products.category_id` primary-category field still being written by some paths.

## 3. Product types, attributes, options, and variants

`product_type` and `item_type` are nullable strings rather than constrained enums or referenced product-type records. The tenant schema default for `product_type` is `physical`; `ProductImport` hard-codes physical for imported rows. Other code can store arbitrary values. No verified digital download asset, fulfillment rule, downloadable entitlement, service product lifecycle, or type-specific schema was found. Treat physical/digital distinctions as an incomplete label, not supported end-to-end product types.

The product payload includes `optionSet` and `optionSetAlign`, stored as strings. There are no option/option-value models, option definitions, variant option value pivots, modifier configuration models, or relevant API endpoints. `custom_fields` is a JSON-like map exposed as generic custom fields, not normalized specifications with types/units/filter semantics.

`parent_id`/`is_duplicate` relationships are used by add/update and import code to mark a parent with “duplicate” children. They do not encode a Cartesian option combination, variant SKU, or variant inventory. No `ProductVariant` model/table or variant route/resource was found. Therefore variant-specific prices, stock, weight/dimensions, image associations, purchasability, barcode, and fulfillment are absent.

## 4. Categories, taxonomy, and brands

Category rows contain `store_id`, name/slug, description, `parent_id`, visibility/channel, image, SEO fields, layout, sort order, and default product sort. `Category::parent`, `children`, and `childrenRecursive` provide nested categories. `category_product` is the many-to-many product association; product also retains a `category_id` field for primary/legacy usage. Routes support create, update, bulk update, delete, subcategory delete, metadata/image update, listing, read by ID/slug, and storefront category filter.

This provides a practical hierarchical category base, but tenant ownership is not enforced uniformly at association boundaries. For example, add-product obtains category IDs using an ID-only query, while product search finds store-scoped category IDs. Category create/update includes store scoping on target rows, but parent existence validators in some paths validate only existence, not that the parent belongs to the same `store_id`. Audit these paths before expanding catalog taxonomy. The tenant-per-database model is an additional isolation barrier, but does not replace row-level ownership checks for legacy/mixed-store rows.

## 5. Images and gallery

Product creation accepts arrays of uploaded image files or image URLs; files/remote content are placed in S3 under `product_images/{productId}` and `ProductImage` rows retain the key/url and primary/alt text. Product updates use `processImagesFromAdd`: submitted image paths are kept/created and omitted existing image rows are deleted from S3 and DB. Import code also parses product image fields and can queue `ProcessProductImage`. `ProductImageResource` returns the S3 URL, path/url, primary, store ID, alt text, and description.

The existing gallery supports multiple images and a primary flag, but has no explicit sort order, crop/transform metadata, variant-image mapping, or media processing lifecycle. The creation and update image ingestion paths differ; direct URL retrieval occurs in controllers and the import job, so validate those code paths and storage failure results carefully.

## 6. Price, SKU, stock, shipping attributes

Price fields exist at product level: base price, cost, sale, MSRP/retail and fixed shipping. CSV export maps “Calculated Price” as sale price if greater than zero, otherwise base price. There are no price lists, customer-group/channel currency-specific product prices, scheduled sales, price history, or variant price adjustments. APIs expose both `price` and a capitalized `Price` in `ProductListResource`; `ProductResource` returns camelCase aliases for several amounts.

SKU is nullable in schema, required on dashboard create, and optional on update. Dashboard validation uses a store-scoped unique rule; tenant SQL also has a unique `(store_id, sku)` key. Multiple null SKUs can still be accepted by typical MySQL unique-index semantics. No normalization/case-folding is applied consistently by create/update, while `ProductImport::normalizeSku` strips control characters and uppercases. SKU is not represented separately for variants; UPC/GTIN/MPN/BPN are a single `ProductIdentifier` row per product and are not variant-specific.

Inventory is a product-level counter and flags. Cart add checks requested quantity against `current_stock` in at least one active path, but its check is conditional around min/max purchase behavior and does not establish an atomic reservation. Active order methods decrement stock directly; some paths increment sold count and others only decrement. Audited active paths do not consistently gate stock mutation on `track_inventory` or centralize the decrement rule. Inventory settings model/store backorder display, “update stock levels”, refund/cancel handling, notifications and text, but those settings are not proof of a fully wired stock ledger. There are no inventory locations, variant quantities, reservations, movements, transfers, or cycle counts.

Product dimensions are a cast JSON array with `weight`, `width`, `height`, `depth`; legacy scalar dimensions also exist in initial migrations. Product API resources normalize those into a fixed dimensions object. The tenant schema stores separate `weight` plus JSON-compatible dimensions; import builds weight/width/height/depth data in dimensions. Units are not stored with each measurement in the product structure.

## 7. Visibility, search, sorting, import/export

Product state is represented by visibility, featured, purchasability, allow-purchase, redirect and duplicate flags; no explicit lifecycle status enum such as draft/active/archived is present. Storefront product queries usually filter `visible_on_storefront`, while dashboard listing can filter visibility, stock, brand, categories, search and price. The admin list supports `id`, `name`, `sku`, stock and price sorting; `page` and `pageSize` are accepted, with no evident upper cap in the inspected list method. Public search matches product name/SKU/slug/search keywords and store-scoped category/brand, caches results, and records search stats. This is SQL `LIKE`/MySQL full-text indexing rather than a dedicated search service. Filter customization is limited to `ProductFilter` records and implementation coverage needs investigation.

Import accepts CSV/XLS/XLSX, stores an upload, calculates non-empty row count, queues `ProductImport`, and returns a cache progress key. The import normalizes SKU, requires SKU/name, resolves brands/categories, updates-or-creates product by `(store_id, sku)`, updates several relations, supports image processing, and checks a cancellation cache key. `ProcessProductImport` is an older queue job; active `importCSV` queues Laravel Excel import directly on `imports`. `importProgress` and `cancelImport` are permission-routed, but the progress key is not visibly bound to the requesting user/store in the inspected controller. Import/export cover product-level columns only and do not encode variants/options. Export streams CSV through `ProductExport`, with product fields, category paths, image details, identifiers/SEO-related data, and custom fields. Spreadsheet behavior and import row error reporting are not a versioned stable interchange contract.

## 8. Tenant isolation notes

Product controllers use `current_store_id()` and most primary product queries explicitly constrain `store_id`; `Product` itself binds to the `tenant` connection. Storefront APIs get tenant DB context from `X-Store-Hash`; dashboard adds central membership checks before product controller access. Product resource/schema include store IDs in related records.

Risks to verify: category IDs and parent IDs are not consistently checked against current `store_id`; some related model relations do not add store constraints themselves; imports and jobs use `store_id` argument but queue workers must connect to the correct tenant before model work. Tenant database isolation reduces cross-store reach, but old/imported rows and store ID translation mean row scoping remains important. IDs from central registry and tenant legacy IDs are not interchangeable; `current_store_id()` deliberately returns `legacy_store_id` when set.

## 9. BigCommerce comparison baseline

BigCommerce documents a product catalog with distinct variant options and modifiers. Variants represent fulfillable combinations and can carry variant-specific SKU/inventory/pricing/weight/dimensions/images; modifiers capture customer choices that do not select a variant and can affect price/weight. Product APIs cover variants, options/modifiers, product catalog details and digital product files. This distinction matters: a string `option_set` is not equivalent to a working variant system. See [BigCommerce product variants API](https://docs.bigcommerce.com/developer/api-reference/rest/admin/catalog/product-variants), [product modifiers API](https://docs.bigcommerce.com/developer/api-reference/rest/admin/catalog/product-modifiers), [Catalog overview](https://docs.bigcommerce.com/developer/docs/admin/catalog-and-inventory/products-overview), and [BigCommerce option/variant explanation](https://support.bigcommerce.com/s/article/Product-Options-v3).

This comparison is a capability benchmark, not a claim that every BigCommerce tier or configuration enables every capability. Specific current parity items and proposed design are in [PRODUCT-GAP-ANALYSIS.md](PRODUCT-GAP-ANALYSIS.md) and [PRODUCT-DATABASE-DESIGN.md](PRODUCT-DATABASE-DESIGN.md).
