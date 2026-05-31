# Changelog

All notable changes to the IPOS platform, modules, and user workflows are documented below, mapped to their validated roadmap epics.

---

## [1.4.0] - 2026-05-31
### Added
* **Epic 34: Enterprise Async Reporting Export** (Validated)
  * Implemented asynchronous BIR E-Journal export pipeline to prevent HTTP timeouts for large compliance reports.
  * Added `data_exports` lifecycle tracking and a private storage disk for secure export retention.
  * Streamed CSV generation with HMAC-SHA-256 row integrity hashing.
  * Implemented a 48-hour retention pruning policy via scheduled jobs.
* **Epic 35: Recipe Maintenance and Costing Engine** (Proposed/Validated)
  * Dynamic unit conversion system (`UnitConversionResolver`) for resolving product-specific or global UOM conversions.
  * Interactive Recipe / BOM editor UI in product management.
  * Real-time WAC-based recipe cost estimator (`RecipeCostingService`) calculating cost based on branch-specific WAC or catalog fallbacks.
  * Robust inventory deduction engine during POS checkout.

---

## [1.3.0] - 2026-05-30
### Added
* **Epic 40: Cash Drawer Audit & Manager Shift Reconciliation** (Validated)
  * Surprise Manager Spot Audits with email/password re-verification and denomination counts.
  * Cash Drawer Threshold Warnings and manager-approved Cash Drops above limits.
  * Self-Approval Prevention Guard blocking shift cashier owners from approving their own high-value drops.
  * Immutable **Bank Deposit Voucher** creation during shift approval inside a single transaction.
  * Update React Shift Summary UI (`Show.jsx`) with warning badges, spot audit timelines, deposit voucher details, and a deposit input modal.

---

## [1.2.0] - 2026-05-28
### Added
* **Epic 32: IPOS POS Terminal Sync Diagnostics & Reliability** (Validated)
  * Terminal sync heartbeat and diagnostic latency charts.
  * Terminal-safe JSON payload validation sandbox.
  * Missing sequence range tracking and sequence gap warnings.
* **Epic 33: Late-Sync Auditability & Z-Report Reconciliation** (Validated)
  * Prior-Period Adjustments dashboard.
  * Non-mutable Z-report locking.
  * Re-shunting late-sync offline transactions to active open settlement periods.
* **Epic 28: Offline-Resilient POS Architecture** (Validated)
  * Offline-tolerant POS shell with IndexedDB cached catalog browsing and cart draft persistence.
  * provisional terminal-side queueing, append-only hash-chain diagnostics, and retryable synchronization.
* **Epic 29: Platform Tenant Provisioning & Subscription Feature Gating** (Validated)
  * Multi-tenant onboarding foundation, system admin tenant creation, and branch provisioning.
  * Subscription feature gating enforcing entitlements across POS checkout, catalogs, reports, and procurement.
* **Epic 30: System Admin Tenant Operations & Compliance Intelligence** (Validated)
  * Platform-wide monitoring dashboards, compliance analytics, and operational intelligence rules.
* **Epic 31: Product Catalog & Inventory Admin UX Completion** (Validated)
  * Complete back-office product/catalog editor workflows, RBAC edit permissions, and dynamic category configuration.

---

## [1.1.0] - 2026-05-15
### Added
* **Epic 26: Advanced Supply Chain & Expiry Tracking** (Validated)
  * Expiry lot capture on goods intake (GRVs).
  * Automated POS First-Expired, First-Out (FEFO) lot depletion.
  * Inter-Branch Stock Transfers (IBTs) and WAC costing adjustments.
* **Epic 27: Ingredient Inventory Upgrade** (Validated)
  * Dynamic unit conversions configuration and policy adjustments.
* **Epic 14: BIR Tax Reporting & Compliance Exports** (Validated)
  * Unified checkout, catalog, and reports under the VAT-inclusive Philippine tax matrix.
  * Sequential numbering controls, duplicate print watermarks, e-journal pipe-delimited text exports, and HMAC-SHA-256 tamper hashes.
* **Epic 15: Sales & Transaction History Back Office** (Validated)
  * Access-controlled invoice searching, detailed financial breakdowns, and receipt reprint audit logging.
* **Epic 16: Inventory Stocktake & Stock Adjustment UI** (Validated)
  * Counting session initialization, count entry grids, and posted stock variance reason-logging.
* **Epic 17: Cashier Accountability & Shift Report Export** (Validated)
  * Scoped shift summaries, cashier blind counts, and manager approval interfaces with secure CSV exports.
* **Epic 20: Supplier & Purchase Receiving** (Validated)
  * Supplier directories, PO creation, and atomic goods receiving posting with WAC adjustment.
* **Epic 22: Visual POS Layout Builder & Enterprise Sync** (Validated)
  * Interactive grid builder for custom product panels, layout caching, and branch sync deployments.
* **Epic 25: Subscription-Based Feature Gating** (Validated)
  * System-level middleware gating capabilities according to active subscription plan.

---

## [1.0.0] - 2026-05-01
### Added
* **Epic 1: SaaS Foundation & Tenant Isolation** (Validated)
  * Tenant and branch context resolution, global scope filters, and fail-closed isolation.
* **Epic 2: Identity, RBAC & Admin Configuration** (Validated)
  * User roles, branch assignments, permissions, tax categories, and tenant onboarding setup.
* **Epic 3: Product Catalog & Branch Inventory Foundation** (Validated)
  * Master items list, branch-scoped stock tracking, and reorder levels.
* **Epic 4: POS Zero-Loss Checkout & UUID Idempotency** (Validated)
  * Offline-resilient cart local storage and UUID idempotency checks on checkout.
* **Epic 5: Split Payments & Billing** (Validated)
  * Reference guards, cash tender calculations, and multi-tender split-payment checkout.
* **Epic 6: Inventory Deduction and Stock Integrity** (Validated)
  * Automated stock depletion on payment completion.
* **Epic 7: Voids, Refunds & Controlled Reversals** (Validated)
  * Append-only transaction voids, payment reversals, and refunds.
* **Epic 8: Accounting Outbox, QuickBooks Adapter & Onboarding** (Validated)
  * Event capture queue, QuickBooks Online connection flow, and manual sync retries.
* **Epic 9: Settlement and Reconciliation Foundation** (Validated)
  * Settlement period lifecycles, immutable period seals, and reopen reason auditing.
* **Epic 10: Settlement Export and Reporting** (Validated)
  * Settlement summaries, variance ledgers, and sync logs in CSV and PDF formats.
* **Epic 11: Operational Pulse, Dashboards & Business Reporting** (Validated)
  * Owner and Branch Manager dashboards.
* **Epic 12: Shift, Cash Drawer & End-of-Day Operations** (Validated)
  * Shift open/close floats and blind closing variance reports.
* **Epic 13: Support Assisted Mode & Production Hardening** (Validated)
  * Read-only support team access credentials and security hardening.
