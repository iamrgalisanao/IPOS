# Epic 26 Context: Advanced Supply Chain, Expiry Tracking & Automated Procurement

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Epic 26 expands the core inbound logistics foundation to provide enterprise-grade supply chain capabilities, natively integrating expiry lot management, automated reordering thresholds, supplier returns, 3-way invoice matching, and tenant-wide split purchase orders. This prevents perishable waste, automates replenishment, protects financial integrity via QuickBooks sync, and streamlines multi-branch logistics.

## Stories

- Story 26.1: Expiry Lot FEFO Ingestion & Validation
- Story 26.2: PAR Levels & Lead-Time Auto-Reorder Schedulers
- Story 26.3: Supplier Returns (RMA) & WAC Valuation Protection
- Story 26.4: 3-Way AP Document Matching & QBO Outbox
- Story 26.5: Master Corporate Split POs & Branch IBTs

## Requirements & Constraints

1. **Multi-Tenant Fail-Closed Isolation**: All operations, background jobs, and queries must execute under the validated `TenantContext`. Cross-tenant queries are strictly prohibited and trigger runtime exceptions.
2. **State Immutability**: Posted documents (GRVs, RMAs, invoice matches) are strictly read-only and historically immutable. Reversals must be offset via corrective transactions.
3. **High-Precision Valuation**: Reverse logistics WAC calculations must utilize `bcmath` to 4 decimal places.
4. **No Direct Financial Ledgering**: IPOS calculates AP liabilities but relies on the asynchronous `AccountingOutbox` pattern to sync events to external QuickBooks system of record.
5. **RBAC Guardrails**: Restrict privileged procurement actions to `Tenant Owner` and `Procurement Manager` roles. Cashiers are strictly unauthorized.

## Technical Decisions

1. **Schema Extensions**:
   - `products`: `expiry_tracking_enabled` (boolean)
   - `branch_inventories`: `reorder_level` (decimal), `max_level` (decimal), `lead_time_days` (integer)
   - `supplier_returns` / `supplier_return_lines` for reverse logistics.
   - `supplier_invoices` for 3-way matching.
2. **First-Expired, First-Out (FEFO)**: Enforce expiry lot allocation on checkout and receiving for tracking-enabled items.
3. **Asynchronous Outbox Sync**: Any financial transitions write to `accounting_outbox` in the same DB transaction to protect from system failure and API latency.

## UX & Interaction Patterns

1. **Alert Registers**: High-density dashboards for near-expiry batches and low-stock alerts.
2. **Inertia integration**: Standardized React/Vue forms for PO recommendations, draft PO review, and RMA entry.
3. **CSV/PDF Controls**: Controlled exports gated by RBAC with secure download generation.
