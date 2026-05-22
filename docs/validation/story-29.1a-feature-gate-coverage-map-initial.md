# Story 29.1A Feature Gate Coverage Map

Date: 2026-05-21
Status: Route Inventory Complete / Wave 1 + Wave 2 Slice A + Slice B Phase B1/B2 + Slice C Hardening Applied
Scope: Configured features from `config/subscriptions.php` mapped against current route/UI enforcement in `routes/web.php` and shared Inertia navigation.

## Coverage Matrix

| Feature Flag | Configured in Plan Matrix | Route Surfaces Identified | subscription.feature Enforced | Coverage State | Notes |
|---|---:|---|---:|---|---|
| sales.pos | Yes | `/pos`, `/pos/search`, `/pos/active-shift`, checkout endpoints | Partial | Partial (Wave 2 Slice C) | Applied to checkout-sensitive routes only; full POS shell/search/active-shift/bootstrap/offline-sync remain ungated. |
| catalog.view | Yes | Product/catalog read surfaces | Partial | Partial (Wave 2 Slice B1) | Applied to `admin.product-categories.index` and `admin.products.index`; runtime shared reads remain deferred. |
| catalog.edit | Yes | `admin/product-categories` write routes, `admin/products` write routes, branch pricing writes, recipe update, product create/edit form routes | Yes | Implemented (Wave 2 Slice A + B2) | Applied to approved admin write endpoints and form routes tied to edit workflows. |
| reports.basic | Yes | `reports/tax*` | Yes | Implemented (Wave 1) | Added `subscription.feature:reports.basic` to isolated tax-report routes. |
| reports.advanced | Yes | `reports/cashier-accountability*` | Yes | Implemented (Wave 1) | Added `subscription.feature:reports.advanced` to accountability routes. |
| procurement.basic | Yes | `procurement/suppliers*`, `procurement/purchase-orders*`, `procurement/receivings*` | Yes | Implemented (Wave 1) | Added `subscription.feature:procurement.basic` to isolated procurement route groups. |
| procurement.advanced | Yes (enterprise) | `procurement/returns*` | Yes | Implemented (Wave 1) | Added `subscription.feature:procurement.advanced` to supplier returns routes. |
| quickbooks.sync | Yes (enterprise) | `accounting/quickbooks`, `accounting/outbox`, `accounting/mappings` | Yes | Implemented | Currently the strongest explicit subscription middleware coverage. |
| layout.custom | Yes (pro/enterprise) | `/admin/pos-layouts*`, `/pos/layout` | Yes | Implemented (Wave 1) | Existing gate for admin layouts plus new gate on `/pos/layout`. |

## Route Inventory by Feature Flag

### reports.basic
- `reports.tax.index`
- `reports.tax.export.csv`
- `reports.tax.export.ejournal`

### reports.advanced
- `reports.cashier-accountability.index`
- `reports.cashier-accountability.show`
- `reports.cashier-accountability.export`

### procurement.basic
- `procurement.suppliers.*`
- `procurement.purchase-orders.*`
- `procurement.receivings.*`

### procurement.advanced
- `procurement.returns.*`

### layout.custom
- `admin.pos-layouts.*`
- `pos.layout`

### quickbooks.sync
- `accounting.quickbooks.*`
- `accounting.outbox.*`
- `accounting.mappings.*`

### sales.pos
- Checkout-sensitive routes gated in Slice C:
	- `pos.checkout.validate`
	- `pos.checkout.create-sale`
	- `pos.checkout.status`
	- `pos.sales.receipt`
	- `pos.sales.payments`
	- `pos.sales.payments.split`

### Deferred in Wave 2
- Optional full POS shell gating:
	- `pos.index`
	- `pos.search`
	- `pos.active-shift`
	- `pos.bootstrap-cache`
	- `pos.offline-sync`
- `catalog.view` runtime/shared routes:
	- `pos.search`
	- `inventory.stocktakes.catalog.search`

## Wave 1 Outcome
- Complete route inventory produced for first-wave target features.
- Middleware hardening applied to isolated route groups (`reports.*`, `procurement.*`, `layout.custom`).
- UI navigation now checks effective entitlements for:
	- POS Layouts
	- Tax Reports
	- Cashier Accountability
	- Procurement menu items
- Regression allow/deny tests added for hardened route families.

## Next Work Queue (Wave 2)
- Optional full POS shell gating remains deferred pending explicit future approval.
- Expand tests for branch-sensitive gated routes where context fallback is required.

## Governance Constraint
Do not proceed to Story 29.2 onboarding until this mapping is completed and reviewed with explicit residual gaps.
