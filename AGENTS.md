# Project Master Instructions

## 1. First Priority: Understand the Project

Before making any changes:

- Read this entire file.
- Inspect the project directory structure.
- Read all relevant documentation inside /docs.
- Inspect existing implementations before suggesting new ones.
- Understand how the frontend, backend, database, APIs and services interact.
- Trace existing business logic before modifying it.
- Identify dependencies and potential side effects.

Do not assume how something works without inspecting the actual code.

## 2. Project Architecture

Refer to these documents:

- docs/ARCHITECTURE.md: Overall application architecture.
- docs/DATABASE.md: Database structure and relationships.
- docs/API-FLOWS.md: Important API and business workflows.
- docs/DEVELOPMENT-GUIDE.md: Development conventions and deployment notes.

If documentation conflicts with actual implementation, investigate the discrepancy and report it.

## 3. Development Rules

- Reuse existing services, controllers, models and utilities.
- Avoid unnecessary duplicate implementations.
- Follow existing naming conventions and coding standards.
- Keep changes focused and maintainable.
- Preserve existing API response structures unless explicitly requested.
- Consider backward compatibility.
- Check related frontend and backend dependencies.
- Never modify unrelated functionality.

## 4. Database Rules

- Inspect model connections before writing database queries.
- Understand database relationships before changing migrations.
- Never assume two databases share the same IDs.
- Never hardcode database credentials.
- Preserve existing tenant isolation.
- Review migration rollback implications.

## 5. Security

- Never expose credentials, API keys or secrets.
- Never commit .env files.
- Validate user input.
- Preserve authentication and authorization checks.
- Never bypass tenant isolation.

## 6. Git Workflow

Before significant changes:

- Inspect git status.
- Review the current branch.
- Do not overwrite unrelated user changes.
- Make focused changes.
- Explain which files were modified.
- Never commit or push without permission.

## 7. Testing

After implementing changes:

- Run relevant tests.
- Check for syntax errors.
- Check related functionality.
- Report any tests that could not be executed.
- Never claim something works without verification.

## 8. Working Style

Before major implementation:

1. Explain the existing implementation.
2. Identify the root cause or requirement.
3. Propose the changes.
4. Identify affected files.
5. Implement only the approved scope.
6. Verify the changes.

Do not rewrite entire files when a focused modification is sufficient.

## 9. Important Rule

Treat this repository as an existing production application, not a new project.

Understanding existing architecture and preserving existing functionality are more important than introducing new patterns or unnecessary refactoring.

## 10. Product Vision

This is a multi-tenant SaaS ecommerce platform inspired by BigCommerce. It provides store owners with product management, categories, customers, inventory, orders, checkout, payments, shipping, store settings, and related ecommerce functionality.

The application uses:

- Laravel 11 backend.
- Separate central and tenant databases.
- External storefront and dashboard applications.
- REST APIs for communication between applications.

The frontend applications are not present in this repository unless explicitly added later. Do not infer their behavior from this backend alone.

## 11. Understand Before Modifying

Before working on any feature:

- Read `AGENTS.md` and the relevant architecture documentation.
- Inspect the existing implementation.
- Trace the actual flow through routes, middleware, controllers, services, models, and API resources.
- Understand related dependencies before changing anything.

Never assume that a feature is missing without checking the code. If documentation and implementation disagree, investigate and describe the discrepancy.

## 12. Preserve Existing Functionality and Compatibility

This is an existing production application, not a new project.

- Never remove existing code because it appears unnecessary.
- Never delete existing methods, routes, database columns, relationships, or API fields without explicit approval.
- Never rename existing database columns or API keys without explicit approval.
- Never restructure existing functionality unnecessarily.
- Do not replace an existing implementation with a new approach without first explaining why.
- Preserve backward compatibility and avoid unrelated changes.

If removal, renaming, or a compatibility-breaking change appears necessary, explain the reason and wait for approval before doing it.

## 13. Frontend API Naming Convention

All newly introduced or explicitly revised frontend-facing API requests and responses must use camelCase, including nested JSON objects. Examples include `productName`, `productId`, `storeId`, `productImages`, `currentStock`, `salePrice`, `variantId`, and `createdAt`.

- Accept camelCase JSON request keys and validate that public contract.
- Return camelCase response keys from API resources and controllers.
- Map camelCase API fields to existing snake_case database columns internally.
- Do not expose database column names directly through new API fields.
- Do not change existing API keys as part of unrelated work. Existing clients may depend on legacy key names; changing those keys is an API contract change and requires explicit approval.
- When implementing a requested API change, identify legacy key compatibility and migration behavior before changing an existing contract.

The audited repository currently contains some snake_case response keys. Treat those as existing contracts to preserve until a specific, approved compatibility plan changes them; do not silently rename them while applying this convention to new or explicitly revised API behavior.

## 14. Database and Tenant Conventions

- Preserve central and tenant database separation.
- Understand `TenantCredentialResolver`, tenant provisioning, and request middleware before changing tenant functionality.
- Never assume central IDs and tenant legacy IDs are interchangeable; use the established registry/legacy ID mapping.
- Inspect existing migrations and model connections before proposing schema changes.
- Never run tenant migrations against the central database.
- Do not modify the consolidated tenant schema or existing database structure unless explicitly requested.
- Preserve tenant isolation in queries, relations, background jobs, imports, and API responses.

## 15. Product Management Context

The current product system supports simple products. Existing functionality includes product CRUD, categories, brands, images, product-level pricing, SKU, stock, product metadata, search, filtering, and import/export.

There is currently no dedicated product variant system. Fields such as `option_set` and `parent_id` must not be assumed to represent real product variants. Future catalog work should move toward BigCommerce-like capabilities while preserving the existing simple-product workflow.

Whenever working on catalog functionality, read:

- `docs/PRODUCT-MANAGEMENT.md`
- `docs/PRODUCT-GAP-ANALYSIS.md`
- `docs/PRODUCT-DATABASE-DESIGN.md`

These documents describe the audited current state and proposed concepts. Proposed designs are not implemented functionality and must not be described or treated as already present.

## 16. Safe Development Workflow

Before modifying application code:

1. Explain how the existing implementation works.
2. Identify the requirement or root cause.
3. Identify the exact files involved.
4. Explain the proposed changes and possible effects on existing functionality.
5. Wait for approval before destructive or architectural changes.
6. Implement only the explicitly authorized scope.

After implementation:

- List all modified files and explain what changed.
- Identify any existing behavior or API contract affected.
- Run relevant tests where possible and report tests that could not be run.
- Check syntax and related functionality where appropriate.
- Never claim unverified functionality works.

## 17. Current Priority

The current priority is to work safely within the existing application, understand its flows, and gradually expand it into a BigCommerce-like platform. Do not independently redesign, refactor, or modify unrelated functionality. Implement only the feature or change explicitly requested.
