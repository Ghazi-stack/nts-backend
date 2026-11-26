# Product management gap analysis

**Compared against:** current repository implementation and BigCommerce's documented product catalog model. Feature grades refer to this repository, not the general Laravel stack. A “partially implemented” grade means there is useful code/schema but the capability does not cover the complete product-management behavior expected of a SaaS catalog.

BigCommerce baseline references: [Catalog overview](https://docs.bigcommerce.com/developer/docs/admin/catalog-and-inventory/products-overview), [variants API](https://docs.bigcommerce.com/developer/api-reference/rest/admin/catalog/product-variants), [modifiers API](https://docs.bigcommerce.com/developer/api-reference/rest/admin/catalog/product-modifiers), [options v3 guide](https://support.bigcommerce.com/s/article/Product-Options-v3). BigCommerce distinguishes option combinations that select a sellable variant from modifiers that collect add-on/custom input. Variant fulfillment can use variant price, weight/dimensions and inventory rather than the parent product values.

## Capability matrix

| # | Feature | Classification | Evidence and gap |
|---:|---|---|---|
| 1 | Product create/edit/delete | **Partially implemented** | Dashboard add, single/bulk update, single/bulk delete routes exist in `routes/api.php` and `ProductController`. Product/content/identifier/SEO/image writes are not wrapped in one visible transaction; sparse update defaults can erase omitted values; delete cleanup depends on uneven FK cascade. |
| 2 | Product schema and migrations | **Partially implemented** | Tenant `products` SQL snapshot has broad fields and indexes; price precision has later migration. Schema is encoded SQL; old root schema differs. Migration snapshot lacks product attributes/options/variants and dependent FK cascade completeness. |
| 3 | Physical/digital/service product types | **Partially implemented** | `product_type`/`item_type` nullable strings; tenant default physical and current import forces physical. No type enum or digital file/entitlement/fulfillment path found. |
| 4 | Categories/subcategories | **Partially implemented** | Parent/children recursion, many-to-many pivot, CRUD, visibility, metadata and storefront reads exist. Cross-store parent/category ID validation is inconsistent; only basic category taxonomy is present. |
| 5 | Product images/gallery | **Partially implemented** | Multi-image rows, primary, alt text, S3 upload, updates, imports and response URLs exist. No position field, variant association, transformed derivatives, or robust ingestion pipeline. |
| 6 | Pricing and sale pricing | **Partially implemented** | Base/cost/sale/MSRP/retail/fixed shipping, discounts and bulk tiers exist at product level. No variant overrides, price lists, scheduled promotions/history, or normalized currency/channel/customer-specific price model. |
| 7 | SKU and uniqueness | **Partially implemented** | Create requires SKU; update can change it; validation and SQL unique key scope to `(store_id, sku)`. SKU nullable in schema, normalization differs between writes and import, and there is no variant SKU. |
| 8 | Inventory/stock | **Partially implemented** | `track_inventory`, `current_stock`, `low_stock`, min/max, display/backorder settings and direct cart/order checks/mutations exist. No variant-level stock, location balances, atomic reservations, movement ledger, replenishment or transfer model; mutations are not uniform. |
| 9 | Attributes/specifications | **Not implemented** | Generic `custom_fields` JSON exists. Historical root migration creates key/value `product_attributes`, but no model and no such table in consolidated tenant schema. No typed/filterable specification definitions. |
| 10 | Product options (size/color/material) | **Not implemented** | Only free-text `option_set`/`option_set_align`; no option/value models or API. |
| 11 | Product variants and variant SKUs | **Not implemented** | No variant model/table/routes/resource. `parent_id` and `is_duplicate` implement duplicate grouping, not variant combinations. |
| 12 | Variant price/stock/image/weight overrides | **Not implemented** | No variant entity to own overrides or relations. Existing parent product fields apply to product rows only. |
| 13 | Weight and dimensions | **Partially implemented** | Product JSON dimensions and weight are exposed and imported/exported. Unit metadata is missing; legacy scalar fields coexist; no variant-specific package dimensions. |
| 14 | Product status and visibility | **Partially implemented** | `visible_on_storefront`, `allow_purchase`, `purchasability_status`, redirect and featured flags support gating. No canonical draft/active/archived lifecycle or channel publication workflow. |
| 15 | Search/filter/sort | **Partially implemented** | SQL `LIKE`, MySQL indexes/fulltext, category/brand/price/stock/visibility filters, pagination, sorting and search counters exist. Query/filter capabilities are basic and duplicated; no scalable dedicated search/index sync layer, attribute/variant faceting or verified page-size limits. |
| 16 | Product import/export | **Partially implemented** | Queued CSV/XLS/XLSX import, SKU upsert, progress/cancel cache and streamed CSV export exist. Contract is product-only; no option/variant rows; import partial-failure reporting and progress ownership need work. |
| 17 | Product APIs/frontend integration | **Needs investigation** | Backend admin/storefront routes and JSON resources exist, but no product frontend is in this repository. Response shapes differ between list/detail/bulk paths; external consumers, versioned contracts, and actual end-to-end integration behavior cannot be established from this checkout. |
| 18 | Tenant isolation/store-specific catalog | **Partially implemented** | Separate tenant DB, `tenant` model connections, current store helper and many explicit `store_id` filters. Related association validators and some relationships are not consistently store-scoped; jobs depend on tenant-aware execution. |

No listed capability currently qualifies as fully implemented against a BigCommerce-like catalog benchmark. This does not mean every existing product workflow is unusable; it means the end-to-end capability has notable limits relative to the requested target.

## Main architectural gaps

### A. Product-level row is overloaded

The `products` row mixes listing identity, sellable SKU, price, stock, product type, customs, visibility, and compatibility fields. This works for simple products but has nowhere authoritative to persist each sellable size/color combination. `quantity` and `current_stock` can diverge, and `price` vs `sale_price` semantics differ across endpoint/export paths.

### B. Options/modifiers are conflated with strings

BigCommerce's model separates options which identify a variant from modifiers which are additional shopper input. Current `option_set` is a string with no normalized choices, combination matrix, required state, display type, price/weight rule, or product/variant relation. A compatible extension should preserve this field for legacy API clients while introducing explicit option and modifier resources.

### C. Stock is not a reliable inventory domain yet

The active system stores product stock as scalar columns and mutates those columns inline in large checkout code. It has no concurrency-safe inventory allocation, variant-level SKU/stock, inventory ledger, or location dimension. A future system needs clear available/on-hand/reserved quantities and an idempotent order allocation lifecycle before multi-variant inventory is safe.

### D. Tenant scoping and data integrity need stronger invariants

Product lookups are usually scoped, but add/update validators do not always verify that referenced brand/category/parent IDs belong to the current store. The tenant snapshot omits several product foreign keys. There is no consistent catalog service boundary for validation and atomic writes; controller behavior varies by endpoint.

### E. Compatibility surface is broader than one route

`ProductResource`, `ProductListResource`, CSV columns, carts, order items, search and storefront URLs all use product-level IDs/fields. Existing consumers probably assume a cart item identifies a product by `product_id`. Variant introduction must accept legacy product-only cart/order payloads and resolve a default/base variant transparently during a transition.

## Proposed target design summary

Use `products` as the durable product/listing identity, then add normalized tenant-local entities for `product_options`, `product_option_values`, `product_variants`, variant-option assignments, product modifiers/modifier values/rules, inventory items/locations/levels/movements/reservations, and product/variant image associations. Keep current product columns as compatibility projections while existing APIs are supported. Details and indexes are in [PRODUCT-DATABASE-DESIGN.md](PRODUCT-DATABASE-DESIGN.md).

## Items needing runtime investigation before implementation

- Confirm whether deployed tenant schemas include drifted columns/tables beyond the current consolidated SQL snapshot and what migration batches have run.
- Inventory external storefront/dashboard clients and their precise response/request contracts, including whether IDs mean product, SKU, or order line.
- Check all active cart/order flows for stock checks, decrements, refunds, cancellation, backorders, draft orders and idempotency. Static controller search shows multiple active mutation points.
- Check the actual queue connection and how product imports establish tenant DB context in workers.
- Confirm price display/checkout semantics and currency precision expected by current stores.
- Confirm image CDN URL behavior and legacy paths to preserve during gallery changes.
- Confirm whether product types besides physical are used in tenant data, and how `parent_id` is used in production imports.

## Recommended roadmap

### Phase 0 — baseline and compatibility contract

1. Inventory deployed schemas and sample products without copying customer data into docs; count null/duplicate SKUs and orphan product relations per tenant.
2. Capture actual API examples/contracts for dashboard list/detail/create/update, storefront product detail, cart add, order item, import and export.
3. Define canonical pricing, SKU normalization/case handling, product lifecycle status, stock policy and default product-to-variant mapping.
4. Add isolation/integrity test cases when implementation starts; include legacy and duplicate-parent records.

### Phase 1 — SKU and inventory foundations (highest priority)

1. Normalize SKU policy, handle blank legacy SKUs, enforce per-tenant uniqueness at write/service and database layers, and establish variant-safe SKU scope. Add lookup paths by SKU without loading unrelated products.
2. Introduce inventory item/level and movement/reservation concepts with idempotent order allocation, cancellation/refund rules, and a transition projection to `products.current_stock`.
3. Audit all stock mutation paths and make product-level stock honor `track_inventory` consistently before distributing stock across variants.
4. Preserve existing response fields as derived compatibility values; use legacy product stock as the default/base sellable inventory until variants are enabled for a product.

### Phase 2 — Options and variants

1. Implement product-scoped option definitions and values; distinguish **variant options** (select a sellable SKU) from **modifiers** (custom input/add-ons).
2. Create one default variant for existing simple products, with stable IDs/mapping. Add explicit combination values, SKU, barcode identifiers and availability.
3. Add variant-level price/sale/cost, dimensions/weight, inventory item, image association and purchasability. Keep parent product values as fallback/display summary.
4. Add product admin endpoints/resources and storefront variant-resolution/selection APIs; maintain product-only add-to-cart compatibility by resolving the default variant.
5. Prevent invalid option combinations; cap generated combinations and support bulk variant editing to avoid combinatorial explosion.

### Phase 3 — catalog content and operations

1. Add typed product attributes/specifications and filter definitions, distinct from shopper options and modifiers.
2. Add lifecycle status/channel publication, category ownership validation, category tree integrity and explicit image sort ordering.
3. Extend imports/exports to a versioned multi-row product/option/variant format with dry-run, row errors, retryability, user/store-scoped progress and idempotent upserts.
4. Improve search and facets based on actual catalog size; index attributes and variant availability only after schema semantics stabilize.

### Phase 4 — rollout and retirement

1. Backfill existing product fields into default variants in batches with reconciliation reports and resumable checkpoints.
2. Dual-read/dual-write behind tenant-scoped feature flags; compare API and checkout totals/stock before cutover.
3. Enable variants per tenant, monitor SKU collisions, stock drift and abandoned/invalid carts; support rollback to simple product projection.
4. Retire duplicate legacy writes/fields only after client migration and a deprecation window.
