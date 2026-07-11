# Changelog

All notable changes to the IPOS platform, modules, and user workflows are documented below, mapped to their validated roadmap epics.

---

## [1.5.7] - 2026-07-11
### Changed
* **Admin Config Snapshot Foundation**
  * POS bootstrap cache now emits `config_snapshot_hash`, `config_snapshot`, and version hashes for layout, catalog, tax, statutory discount metadata, payment methods, terminal policy, and printer profile placeholder.
  * POS IndexedDB cache preserves config snapshot metadata for offline use.
  * Offline cash-capture payloads now include the cached snapshot metadata before queueing.
  * Offline sync payloads forward snapshot metadata to the server for audit and stale-config classification.
  * Server-side offline import validation now treats snapshot drift as `accepted_with_warning` instead of blocking intake.
  * DiscountType model fillable/casts were completed to support statutory discount configuration fixtures consistently.

### Boundaries
* No terminal-side master configuration UI was added.
* No hardware readiness claim was made for receipt printers or cash drawers.
* No local official GCT, Z-read, e-journal, or BIR-certified offline finalization was introduced.

### Validation Evidence
- `php artisan test tests/Feature/POS/OfflineBootstrapCacheTest.php tests/Feature/POS/OfflineSalesAuditPayloadTest.php`
- `node --test tests/Frontend/catalogCache.test.js tests/Frontend/offlineQueueSync.test.js`
- PHP syntax checks for modified backend PHP files.
- Validation note: `docs/validation/admin-config-snapshot-foundation-2026-07-11.md`

## [1.5.6] - 2026-07-11
### Documentation
* **POS Admin Configuration & Terminal Capability Planning Reference**
  * Added the admin-configuration backlog as a roadmap planning artifact.
  * Documented current IPOS coverage across RBAC, terminal profiles, layouts, catalog, payment methods, taxes, discounts, cash drawer operations, sync review, audit logging, printer routing, and config snapshots.
  * Identified **Admin Config Snapshot Foundation** as the recommended next implementation-lock candidate after POS terminal UAT.
  * Reconfirmed that printer/cash drawer physical validation remains deferred until hardware devices are available.

### Validation Evidence
- `git diff --check`

## [1.5.5] - 2026-07-11
### Documentation
* **POS Terminal Hardening Reference Alignment**
  * Aligned roadmap, task ledger, current-focus notes, UAT, troubleshooting, and Terminal Sync Diagnostics references to the clean checkpoint commit `6c2b5d0`.
  * Clarified that the next active gate is POS terminal offline UAT/release review, not new feature development.
  * Marked receipt printer and cash drawer physical validation as deferred because hardware is not yet available.
  * Removed fixed POS bundle-name expectations from documentation; support should verify the current build manifest because bundle names are build-hashed.

### Validation Evidence
- `git diff --check`
- `npm run build`
- `node tests/Frontend/checkoutFailureState.test.js`
- `node tests/Frontend/catalogCache.test.js`
- `node tests/Frontend/offlineQueueSync.test.js`
- `node tests/Frontend/offlinePaymentQueue.test.js`
- `node tests/Frontend/connectivityStore.test.mjs`

## [1.5.4] - 2026-07-11
### Changed
* **POS Terminal Offline Reconnect and Sync UX Stabilization**
  * Hardened reconnect behavior after **Check Connection** so expected `401/419` or network reachability failures are treated as offline/session state instead of cashier-blocking hard errors.
  * Local sync broker discovery now falls back to **Local Sync Offline** when protected broker endpoints are unavailable or unauthenticated.
  * Product search now rejects non-JSON responses before parsing, allowing cached catalog fallback when a reconnect returns an HTML login/error page.
  * Offline sync now distinguishes retryable failures from review-required conflicts such as sequence-order mismatches.
  * Service-worker shell cache rolled to `ipos-terminal-shell-v31-20260711`; POS bundle filenames are build-hashed and should be verified against the current manifest.

### Documentation
* Updated the Terminal Sync Diagnostics user guide, troubleshooting guide, offline stabilization validation note, and roadmap references.
* Added the POS Terminal Offline Checkout and Sync UAT checklist for cashier, admin, reconnect, and queue review scenarios.

### Validation Evidence
- `node tests/Frontend/catalogCache.test.js`
- `node tests/Frontend/offlineQueueSync.test.js`
- `node tests/Frontend/offlinePaymentQueue.test.js`
- `node tests/Frontend/connectivityStore.test.mjs`
- `npm run build`

---

## [1.5.3] - 2026-07-10
### Changed
* **POS Terminal Offline Checkout Stabilization**
  * Updated offline `Ready to Complete` behavior so the Split Payment Wizard opens in offline mode instead of bypassing payment review.
  * Restricted offline provisional capture to cash payments only; card, e-wallet, bank, and other tender types remain disabled until server connectivity returns.
  * Fixed split-payment validation so abandoned empty split rows do not block a fully paid cash transaction.
  * Added cart item removal and footer visibility hardening so checkout controls remain reachable on tablet layouts.
  * Preserved POS shell refresh protection through service-worker cache rollover (`ipos-terminal-shell-v22-20260708`).

* **Terminal Identity and Timecard Binding**
  * Hardened terminal context validation so timecard clock-in/out records remain bound to a verified terminal profile.
  * Invalid or missing terminal context now fails closed with `TERMINAL_CONTEXT_INVALID` instead of creating terminal-less timecard records.

### Validation Evidence
- `node --test tests/Frontend/offlineQueueSync.test.js tests/Frontend/splitPaymentHelper.test.mjs tests/Frontend/splitPaymentFailureState.test.mjs tests/Frontend/checkoutUncertaintyState.test.mjs tests/Frontend/cartDraftStorage.test.js tests/Frontend/connectivityStore.test.mjs tests/Frontend/catalogCache.test.js`: 38 passed
- `php artisan test tests/Feature/POS/TimecardControllerTest.php`: 14 passed / 53 assertions
- `php artisan test tests/Feature/POS/TerminalIdentityBindingTest.php`: 7 passed / 8 assertions
- `php artisan test tests/Feature/POS/OfflineSalesAuditPayloadTest.php tests/Feature/POS/OfflineSyncValidationTest.php tests/Feature/POS/OfflineSyncIdempotencyTest.php tests/Feature/POS/TimecardControllerTest.php tests/Feature/POS/PaymentRecordingTest.php tests/Feature/POS/SplitPaymentRecordingTest.php`: 70 passed / 206 assertions
- `npm run build`

---

## [1.5.2] - 2026-07-10
### Added
* **Philippine Statutory Discount Engine (G-080)**
  * Implemented BIR-compliant Senior Citizen (20%), PWD (20%), and Solo Parent (10%) discounts with VAT exemption.
  * Added `discount_types`, `product_discount_eligibility`, `sale_discounts`, and `sale_discount_beneficiaries` tables.
  * Added `StatutoryDiscountService` with calculation pipeline (Gross → Less VAT → Discountable Base → Net).
  * Added POS Special Discount Modal with category selection, identity capture (Name/ID/TIN/SPIC), pax controls, and MEMC mode.
  * Added manager PIN approval workflow via `/api/pos/manager/authorize`.
  * Added immutable `calculation_snapshot` persistence for audit compliance.
  * Added receipt template rendering: "Less: VAT Exempt", discount label, and masked beneficiary name/ID.
  * Added refund and void flows that correctly reverse statutory discounts.
  * Added 26 compliance tests covering identity requirement, pax constraint, VAT exemption, solo parent eligibility, manager approval, receipt accuracy, and audit trail.

### Validation Evidence
- `php artisan test tests/Feature/StatutoryDiscountServiceTest.php tests/Feature/POS/StatutoryDiscountComplianceTest.php`: 26 passed / 95 assertions
- Closure artifact: `docs/validation/statutory-discount-engine-closure.md`

---

## [1.5.1] - 2026-07-08
### Changed
* **POS shell cache rollover**
  * Bumped the POS shell cache key to `ipos-terminal-shell-v21-20260708` in `public/sw.js` and `resources/views/app.blade.php` to force clients onto the latest cached shell assets.
  * Revalidated the shell update with `npm run build`, `node --test tests/Frontend/offlineQueueSync.test.js`, and `node --check public/sw.js`.

---

## [1.5.0] - 2026-07-04
### Added
* **Epic 43: POS Lock Screen & Employee Timecards** (Validated)
  * Implemented an unauthenticated lock-screen PIN toggle endpoint (`POST /pos/timecard/toggle`) requiring validated terminal context.
  * Created `IdentifyTerminalContext` middleware to authenticate register hardware context (`X-Terminal-ID`) and `EnforceClockedIn` middleware to restrict POS cashier actions.
  * Added `TimecardAccessPolicy` to assert clock-in requirements, and custom exception renderers returning structured JSON errors (`TIMECARD_REQUIRED` on 403, `PIN_RATE_LIMITED` on 429, `OPEN_SHIFT_BLOCKS_CLOCK_OUT` on 409).
  * Implemented `TimecardSecurityService` tracking terminal failure counts with automatic, decaying rate limits.
  * Integrated a touch-friendly numeric keypad and status alerts on `TerminalLockScreen.jsx`, and a pulsing status indicator badge in `ShiftHUD.jsx`.

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
* **Epic 41: POS Terminal Production Hardening for Android Tablet** (Validated)
  * Hardened the POS checkout UI for Android tablet devices (`TabletPOSLayout.jsx`).
  * Configured PWA Manifest and Service Worker for offline availability (`manifest.json`, `sw.js`).
  * Implemented hardware integration adapter (`PosHardwareAdapter.js`) for Bluetooth, USB, and network receipt printers.
  * Added the [Android Kiosk Deployment Guide](file:///Users/teamsolo/Documents/Dev/IPOS/docs/deployment/android-kiosk-deployment.md) for locking down tablet terminals in production.
* **Epic 36: Local Register Sync & Store-Level Coordination** (Validated)
  * Implemented master register discovery and automated registration over local LAN segments.
  * Added single-owner table lock leases with automatic lock expiry to prevent split-brain cart mutations.
  * Integrated LAN status badges and real-time locking UI overlays on POS terminals.
* **Epic 42: Windows POS Terminal Electron Wrapper** (Validated)
  * Wrapped POS terminal client inside an Electron wrapper for Windows desktop installations.
  * Configured local offline file assets loading and fallback local SQLite storage engine.
  * Enabled direct hardware communication with native Windows drivers for printer and drawer support.

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
