# IPOS - Validated Implementation Roadmap

## Overview
This document represents the **Actual Execution Truth** of the IPOS project. It has been reconciled against validated implementation history and project-gate closures.

## Architecture Decision: Android Tablet POS Terminal Production Strategy

IPOS will continue using the existing POS Terminal module inside the monolithic Laravel/Inertia/React application. 

For long-term production readiness, the POS Terminal will be hardened as a tablet-first PWA and prepared for future Android native wrapping if hardware integration, kiosk control, or offline requirements become stronger. The current POS terminal UI, checkout flow, drawer workflows, shift handling, offline sync, and queue-backed backend services will be preserved. Do not split to a new repo or rewrite in native Android.

---

## Epic Summary

| Epic | Description | Status |
| :--- | :--- | :--- |
| **Epic 1** | SaaS Foundation & Fail-Closed Tenant Isolation | **[Closed]** |
| **Epic 2** | Identity, RBAC & Admin Configuration | **[Closed]** |
| **Epic 3** | Product Catalog & Branch Inventory Foundation | **[Closed]** |
| **Epic 4** | POS Checkout, Zero-Loss Cart & Transaction Integrity | **[Closed]** |
| **Epic 5** | Payment Handling, Split Payments & Reference Guard | **[Closed]** |
| **Epic 6** | Inventory Deduction and Stock Integrity | **[Closed]** |
| **Epic 7** | Voids, Refunds & Controlled Reversals | **[Closed]** |
| **Epic 8** | Accounting Outbox, QuickBooks Adapter & Onboarding | **[Closed]** |
| **Epic 9** | Settlement and Reconciliation Foundation | **[Closed]** |
| **Epic 10** | Settlement Export and Reporting | **[Closed]** |
| **Epic 11** | Operational Pulse, Dashboards & Business Reporting | **[Closed]** |
| **Epic 12** | Shift, Cash Drawer & End-of-Day Operations | **[Closed]** |
| **Epic 13** | Support Assisted Mode & Production Hardening | **[Closed]** |
| **Epic 14** | BIR Tax Reporting & Compliance Exports | **[Closed]** |
| **Epic 15** | Sales & Transaction History Back Office | **[Closed]** |
| **Epic 16** | Inventory Stocktake & Stock Adjustment UI | **[Closed]** |
| **Epic 17** | Cashier Accountability & Shift Report Export | **[Closed]** |
| **Epic 20** | Supplier & Purchase Receiving | **[Closed]** |
| **Epic 22** | Visual POS Layout Builder & Enterprise Sync | **[Closed]** |
| **Epic 25** | Subscription-Based Feature Gating | **[Closed]** |
| **Epic 26** | Advanced Supply Chain, Expiry Tracking & Automated Procurement | **[Closed]** |
| **Epic 27** | Ingredient Inventory Upgrade (Phase 1) | **[Closed]** |
| **Epic 28** | Offline-Resilient POS Architecture | **[Phase 1 Closed / Phase 2 Implemented & Locally Validated — Controlled Early Partner Pilot Ready]** |
| **Epic 29** | Platform Tenant Provisioning & Subscription Feature Gating | **[Closed — Implemented & Locally Validated / Non-Blocking Residual Follow-Ups Tracked Separately]** |
| **Epic 30** | System Admin Tenant Operations & Compliance Intelligence | **[Closed — Implemented & Locally Validated / 30.4 + 30.5 Planning-Locked Deferred]** |
| **Epic 31** | Product Catalog & Inventory Admin UX Completion | **[Closed - Implemented & Locally Validated / Import Write Path Deferred]** |
| **Epic 32** | IPOS POS Terminal Sync Diagnostics & Reliability | **[Closed — Implemented & Locally Validated]** |
| **Epic 33** | Late-Sync Auditability & Z-Report Reconciliation | **[Closed — Implemented & Locally Validated]** |
| **Epic 34** | Enterprise Async Reporting Export | **[Closed]** |
| **Epic 35** | Recipe Maintenance and Costing Engine | **[Closed]** |
| **Epic 36** | Local Register Sync and Store-Level Coordination | **[Closed — Implemented & Locally Validated]** |
| **Epic 37** | Advanced Promotions & Bundling Engine | **[Proposed]** |
| **Epic 38** | F&B Table & Bill Manipulation Operations | **[Proposed]** |
| **Epic 39** | Loyalty & Store Credit Ledger | **[Proposed]** |
| **Epic 40** | Cash Drawer Audit & Manager Shift Reconciliation | **[Closed]** |
| **Epic 41** | POS Terminal Production Hardening for Android Tablet | **[Implemented & Locally Validated / UAT Release Gate Pending / Hardware Validation Deferred]** |
| **Epic 42** | Windows POS Terminal Electron Wrapper | **[Closed — Implemented & Locally Validated]** |
| **Epic 43** | POS Lock Screen & Employee Timecards | **[Closed — Implemented & Locally Validated]** |
| **Epic 44** | POS Admin Configuration & Terminal Capability | **[Closed — Implemented & Locally Validated]** |




*Epic 3 is closed as a Product/Catalog Core Foundation epic. Backend product/catalog capabilities are implemented and validated via downstream dependencies (POS, Stocktake, Accounting). Advanced UX/CDN/Product CRUD management UI is deferred and should be tracked separately as a future management feature.*

## Current Execution Reference

The current project baseline is the POS terminal hardening checkpoint commit
`6c2b5d0` (`chore: checkpoint POS terminal hardening`). That checkpoint keeps
the repository clean after implementing the terminal offline cash capture,
queue diagnostics, route/session hardening, user management, statutory discount,
timecard, and Electron wrapper source work.

The next active gate is the POS terminal offline UAT and release-gate review:
- [pos-terminal-offline-uat-2026-07-11.md](../validation/pos-terminal-offline-uat-2026-07-11.md)
- [_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md](../../_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md)

Hardware printer and cash drawer validation is explicitly deferred because the
devices are not yet available. The project must not claim physical hardware
readiness, drawer opening, or printer validation until a later hardware-backed
UAT run captures evidence.

The next architecture planning track after POS UAT is the admin-configuration
and terminal-capability backlog. Its first recommended implementation-lock
candidate is **Admin Config Snapshot Foundation**, because it provides the
versioned bridge between Back Office configuration and terminal offline
behavior:
- [pos-admin-configuration-terminal-capability-backlog.md](pos-admin-configuration-terminal-capability-backlog.md)

## Parked Planning Direction

**Market Readiness Inventory Operations Planning** remains a parked planning
track after Story 31.7 and the vendor report gap analysis.

Planning artifact:
- [market-readiness-inventory-operations-priority-plan.md](market-readiness-inventory-operations-priority-plan.md)

---

## Epic 44: POS Admin Configuration & Terminal Capability [Closed]

**Status:** Closed — Implemented & Locally Validated

**Decision:** The attached benchmark review has been accepted and fully implemented. All configuration and governance gaps (including POS layout override selectors, cash drawer reason dynamic configurations, payment method offline policies, and configuration snapshot audit logs) are completed and validated.

**Reference Artifact:**
- [pos-admin-configuration-terminal-capability-backlog.md](pos-admin-configuration-terminal-capability-backlog.md)

**Current Coverage Summary:**
- Implemented and validated complete product configuration surfaces for RBAC, user management, Sales Machine Profiles, POS layout assignment, product catalogs, payment offline policies, tax snapshots, statutory discounts, cash drawer dynamic reasons, sync monitoring, and offline import reviews.
- A branch/register-scoped **Config Snapshot** foundation is fully integrated with version hashes for layouts, catalogs, taxes, discounts, payment methods, terminal policies, and printer profiles.

**Implemented Foundation Slice:**
- Admin Config Snapshot & Back Office Governance Integration.

**Explicit Boundaries:**
- No local official GCT/Z-read/e-journal finalization.
- No terminal-side master pricing, tax, discount, role, or payment method configuration.
- No physical printer/cash drawer readiness claim until hardware is available.

---

## Epic 1: SaaS Foundation & Fail-Closed Tenant Isolation [Closed]
*Validated: May 2026*
- 1.1 Tenant and Branch Foundation Models
- 1.2 Tenant Context Resolution and Fail-Closed Middleware
- 1.3 Automatic Tenant Scoping via Global Query Filters
- 1.4 Branch Context Resolution and Access Enforcement
- 1.5 Tenant and Branch Isolation Feature Tests (Adversarial)
- 1.6 Append-Only Audit Logging Foundation

## Epic 2: Identity, RBAC & Admin Configuration [Closed]
*Validated: May 2026*
- 2.1 Multi-Tenant Identity Foundation & User Model
- 2.2 Standardized RBAC: Role & Permission Schema
- 2.3 Tenant-Scoped User Onboarding & Invite Flow
- 2.4 Branch Assignment & Multi-Branch Access Control
- 2.5 Admin Dashboard: User & Role Management
- 2.6 Branch Management: Location & Metadata Config
- 2.7 Tax Category Configuration & Global Defaults

## Epic 3: Product Catalog & Branch Inventory Foundation [Closed]
- 3.1 Centralized Product Catalog & SKU Management
- 3.2 Product Categories & Organization
- 3.3 Global Pricing vs. Branch-Scoped Overrides
- 3.4 Product Search API: Indexing & Performance
- 3.5 Multi-Unit of Measure (UOM) Support
- 3.6 Branch-Scoped Stock Level Persistence
- 3.7 Stock Movement: In/Out/Adjustment Logs
- 3.8 Low-Stock Thresholds & Reorder Alerts
- *3.9+ Advanced UX/CDN (Deferred)*

## Epic 4: POS Checkout, Zero-Loss Cart & Transaction Integrity [Closed]
- 4.1 Hybrid POS Entry and Sticky Cart UI
- 4.2 Zero-Loss Cart (Local Persistence)
- 4.3 `client_request_uuid` (Idempotency)
- 4.4 Checkout Validation API
- 4.5 Atomic Sale Creation (DB Transaction)
- 4.6 Receipt Generation (Print-Ready)
- 4.7 Final Tap Checkout Flow (Closure UX)
- 4.8 Checkout Failure Handling (State Recovery)

## Epic 5: Payment Handling, Split Payments & Reference Guard [Closed]
- 5.1 Payment Data Schema and Basic Payment Recording
- 5.3 Split-Pay Wizard UI and Reference Guard
- 5.4 Cash Tendered and Change Calculation UI
- 5.6 Payment Failure Handling and Wizard Preservation
- 5.7 Payment Audit Trail and Completion Hardening

## Epic 6: Inventory Deduction and Stock Integrity [Closed]
- 6.1 Inventory Deduction Trigger After Successful Payment
- 6.2 Low Stock Thresholds and Branch Stock Alerts
- 6.3 Inventory Deduction Failure UX
- 6.4 Inventory Movement Visibility

## Epic 7: Voids, Refunds & Controlled Reversals [Closed]
- 7.1 Reversal Architecture (Append-Only Reversals)
- 7.2 Full-Sale Void Service
- 7.3 Partial Refund Service
- 7.4 Reversal Audit Trail
- 7.5 Terminal-Side Void & Refund Modal Integration
- 7.6 Supervisor Auth Override Validation
- 7.7 Timing Controls (Same-Shift Voids Restricted, Closed Shift Electronic Refunds Queue Routing)
- 7.8 Idempotency Protection Middleware

## Epic 8: Accounting Outbox, QuickBooks Adapter & Onboarding [Closed]
- 8.1 Accounting Outbox Schema and Event Capture
- 8.2 Accounting Outbox Visibility
- 8.3 Sync State Machine
- 8.4 Processor Skeleton
- 8.5 Payload Normalization and Mapping Contracts
- 8.6 QuickBooks Provider Adapter / Sandbox Push Path
- 8.7 Mapping Persistence
- 8.8 Sync Dashboard and Manual Retry
- 8.9 Mapping Management UI
- 8.10 QuickBooks Production Onboarding / Connection Flow
- 8.11 QuickBooks Sync Readiness Check

## Epic 9: Settlement and Reconciliation Foundation [Closed]
- 9.1 Settlement Period Schema and Lifecycle
- 9.2 Tenant/Branch Period Isolation
- 9.3 Status Lifecycle Enforcement (Open/Review/Approved/Locked)
- 9.4 Snapshot-Before-Lock Requirement
- 9.5 Reopen Workflow with Required Reason
- 9.6 Read-Only Summaries and Variance Classification
- 9.7 Immutable Append-Only Snapshots
- 9.8 Settlement Review Dashboard and Action Bar

## Epic 10: Settlement Export and Reporting [Closed]
- 10.1 Export/Report Scope Lock (Policy)
- 10.2 Settlement Summary (CSV/PDF)
- 10.3 Variance Ledger (CSV)
- 10.4 Accounting Sync Log (CSV with Redaction)
- 10.5 Permission-Gated Export UI Actions & Audit Logging

---

## Future Roadblocks

## Epic 11: Operational Pulse, Dashboards & Business Reporting [Closed]
*Validated: May 2026*
- 11.1 Owner Operational Pulse Dashboard (Tenant-wide)
- 11.2 Branch Manager Operational Dashboard (Branch-scoped)
- 11.3 Mobile Owner Pulse (Responsive Read-Only)
- 11.4 Detailed Sales, Net Sales, and Payment Mix Reporting
- 11.5 Branch Comparison Metrics

## Epic 12: Shift, Cash Drawer & End-of-Day Operations [Closed]
*Validated: May 2026*
- 12.1 Shift Open/Close Lifecycle
- 12.2 Cash Drawer Pay-ins/Payouts
- 12.3 Actual vs Expected Cash Reconciliation
- 12.4 Z-Read Operational Reports
- 12.5 Shift-Settlement Lock Coupling
- 12.6 Blind Closing & Variance Calculation
- 12.7 Manager Review & Approval Flow
- 12.8 Shift Summary UI & Dashboard Integration

### Epic 13: Support Assisted Mode & Production Hardening [Closed]
- 13.1 Support Assisted Mode Scope Lock and Identity Model [Implemented]
- 13.2 Observability & Centralized Logging [Implemented]
- 13.3 Production Security Hardening [Implemented]

Implementation note: Story 13.1 is still defined as a six-slice contract. A support-safe audit review endpoint was accepted later as a narrow extension and does not create a mandatory Slice 7 unless the roadmap is explicitly revised.

Current execution note: Story 13.3 completed on 2026-05-13 after the full Story 13.3 security suite passed and the full backend regression remained green. Epic 13 is now closed.

## Epic 14: BIR Tax Reporting & Compliance Exports [Closed]
*Validated: May 2026*
- 14.1 BIR Compliance Scope Lock and PH Tax Matrix [Implemented]
- 14.2 Tax Breakdown Source-of-Truth Hardening [Implemented]
- 14.3 Sales Tax Reporting Query Service [Implemented]
- 14.4 BIR Tax Reporting Back-Office UI [Implemented]
- 14.5 Compliance Export Package [Implemented]

Implementation note: Story 14.5 CSV export baseline is complete. PDF generation and print-ready templates are deferred per user instruction.

*Tax Compliance Hardening follow-up: Completed — Validated (Slices A-E complete. Unified backend checkout, product catalog UI, reports, and exports under the canonical VAT-inclusive Philippine tax matrix).*

### BIR POS Accreditation & EOPT Hardening Extension
**Final Status:** Implemented & Locally Validated — Pending Formal BIR/Accounting Review

To support baseline BIR audit-readiness, the following compliance extension steps have been completed:
- **[x] Step 1: BIR Compliance Schema Foundation** [Implemented & Validated]
- **[x] Step 2: Sequential Numbering & Reprint Control** [Implemented & Validated — Signed-Off: [bir_reprint_validation_report.md](../reports/bir_reprint_validation_report.md)]
- **[x] Step 3: Z-Read Shift-Lock Engine & GCT State Machine** [Implemented & Validated — Signed-Off: [bir_z_read_validation_report.md](../reports/bir_z_read_validation_report.md)]

  #### Step 3 Completed Scope:
  - Created `ZReadGenerationService` for isolated transaction calculations.
  - Added atomic Z-read generation database transactions (`DB::transaction`).
  - Locked `SalesMachineProfile` during GCT updates using pessimistic locking (`lockForUpdate`).
  - Generated immutable `register_z_reads` ledger entries.
  - Incremented `z_read_counter` on successful Z-read generation.
  - Updated `grand_cumulative_total` atomically.
  - Associated finalized sales with `register_z_read_id`.
  - Blocked mutation/deletion of Z-read-covered sales via model boot events.
  - Prevented duplicate inclusion of already finalized sales.
  - Added database rollback protection test coverage.
  - Added void/refund aggregation test coverage.

  **Validation Evidence**:
  - Test Suite: `tests/Feature/Compliance/RegisterZReadLedgerTest.php`
  - Result: 215 tests / 753 assertions passing successfully (100% green).

  **Governance Note**:
  This validates the Z-read/GCT state machine slice only. Broader BIR/EOPT accreditation readiness remains dependent on completion and validation of training mode isolation, e-journal export, final report layouts, official machine registration data, and formal BIR/accounting review.

- **[x] Step 4: Training Mode Isolation** [Implemented & Locally Validated]

  #### Step 4 Completed Scope:
  - Added `is_training_mode` persistence across checkout request and sale lifecycle.
  - Isolated training invoice numbering using `TRAIN-INV-*` format.
  - Prevented training sales from consuming official production invoice sequence.
  - Excluded training sales from production Z-read and GCT calculations.
  - Prevented training payments from deducting inventory.
  - Prevented training transactions from creating accounting outbox sync records.
  - Added required training receipt watermark.
  - Added training-specific audit events for sale, payment, receipt print, and receipt reprint.

  **Validation Evidence**:
  - Test Suite: `tests/Feature/POS/CheckoutTrainingModeTest.php`
  - Result: 3 tests / 35 assertions passing successfully (100% green).

  **Governance Note**:
  This validates the training mode isolation slice only. Full BIR/EOPT accreditation readiness remains pending until e-journal export/internal hash support, final output review, official machine registration data, and formal accounting/BIR validation are completed.

- **[x] Step 5: Electronic Journal Exporter & Internal Hashes** [Implemented & Locally Validated]

  #### Step 5 Completed Scope:
  - Created `EJournalExportService` for compliance logging.
  - Added pipe-delimited text export layout format.
  - Included sales, voids, refunds, reprints, and training-mode records.
  - Added clear training/non-official classification markings.
  - Added internal HMAC-SHA-256 tamper-evident hash per row.
  - Added export route through tax reporting controller.
  - Enforced report permission access control.
  - Enforced tenant and branch isolation.
  - Added deterministic hash validation test coverage.

  **Validation Evidence**:
  - `vendor/bin/phpunit tests/Feature/Epic14/EJournalExportTest.php`
  - Result: 6 tests / 27 assertions passing successfully (100% green).
  - `vendor/bin/phpunit tests/Feature/Epic14/`
  - Result: 65 tests / 625 assertions passing successfully (100% green).

  **Governance Note**:
  The e-journal exporter supports baseline audit-readiness and internal diagnostic tamper detection. It is not represented as an officially accredited BIR rolling-chain journal format. Formal BIR/accounting review remains required before accreditation claims.

Implementation plan: [epic-14-implementation-plan.md](./epic-14-implementation-plan.md) and [bir_compliance_implementation_plan.md](../../.gemini/antigravity/brain/5071b287-8d3b-48ee-87ed-517b1ff03580/bir_compliance_implementation_plan.md)

## Epic 15: Sales & Transaction History Back Office [Closed]
- 15.1 Sales History Scope Lock and Access Rules [Implemented]
- 15.2 Transaction History Query Foundation [Implemented]
- 15.3 Sales & Transaction History Index UI [Implemented]
- 15.4 Transaction Detail Timeline and Financial Breakdown [Implemented]
- 15.5 Transaction Export and Audit Trail [Implemented]
- 15.6 Receipt Reprint and Evidence Linking [Implemented]

### Receipt Reprint Compliance Slice — Accepted
The receipt reprint authorization and audit flow has been implemented and validated.

* **Completed**:
  - First print increments receipt print tracking.
  - Subsequent prints require a reprint reason.
  - Missing reason returns structured 422 validation response.
  - Successful reprint increments print count.
  - Successful reprint logs `receipt_reprint`.
  - Receipt payload includes reprint status and reason.
  - Frontend receipt layout renders visible duplicate/reprint watermark.
  - Cashier UI prompts for reprint authorization before re-spooling.
* **Validation Evidence**:
  - `./vendor/bin/pest tests/Feature/POS/ReceiptTest.php`
  - Result: 10 passed / 28 assertions
  - Frontend build passed.
* **Governance Note**:
  This slice satisfies the receipt reprint control requirement only. It does not complete full BIR accreditation readiness. Remaining compliance work includes invoice sequencing, Z-read/GCT ledger, training mode isolation, and e-journal export.

## Epic 22: Visual POS Layout Builder & Enterprise Sync [Closed]
*Validated: May 2026*
- [x] 22.1 Schema & Layout Foundation (Slice A) [CLOSED]
- [x] 22.2 Admin Layout CRUD + Validation (Slice B) [CLOSED]
- [x] 22.3 Terminal Layout Fetch & Fallback Rendering (Slice C) [CLOSED]
- [x] 22.4 Visual Sandbox Editor (Slice D) [CLOSED]
- [x] 22.5 Publish / Branch Deployment / Sync (Slice E) [CLOSED]
- [x] 22.6 Governance / Audit / Rollout Hardening (Slice F) [CLOSED]

## Epic 16: Inventory Stocktake & Stock Adjustment UI [Closed]
- [x] 16.1 Stocktake Session Foundation [Implemented]
- [x] 16.2 Stocktake Counting UI [Implemented]
- [x] 16.3 Review and Variance Handling [Implemented]
- [x] 16.4 Posting and Inventory Adjustment [Implemented]
- [x] 16.5 Approval and RBAC Hardening [Implemented]
- [x] 16.6 Export / Reporting [Implemented]

## Epic 17: Cashier Accountability & Shift Report Export [Closed]
*Validated: May 2026*
- 17.1 Cashier Accountability Scope Lock [Implemented]
- 17.2 Shift Accountability Backend Foundation [Implemented]
- 17.3 Cashier Accountability UI [Implemented]
- 17.4 Shift Report Export [Implemented]
- 17.5 RBAC, Audit, and Historical Integrity Hardening [Implemented]

**Closure Note:**
Epic 17 delivered read-only cashier accountability reports, shift-level aggregation, secure CSV export, RBAC enforcement, audit logging, tenant/branch/cashier isolation, and historical immutability checks. No shift mutation, settlement mutation, inventory mutation, payment mutation, or accounting outbox mutation is introduced by this epic. All 35 tests / 141 assertions passed successfully.

---

## Epic 20: Supplier & Purchase Receiving [Closed]
*Status: CLOSED & VALIDATED*
- 20.1 Supplier & Purchase Foundation Scope Lock [Implemented]
- 20.2 Supplier Directory Foundation [Implemented]
- 20.3 Purchase Order Backend & Lifecycle [Implemented]
- 20.4 Purchase Receiving Draft Workspace [Implemented]
- 20.5 Atomic Receiving Posting & WAC Valuation [Implemented]
- 20.6 Procurement UI & CSV Security Hardening [Implemented]
- 20.7 RBAC, Audit, and Closure Hardening [Implemented]

**Closure Note:**
Epic 20 delivered supplier directory management, purchase order lifecycle, purchase receiving drafts, atomic receiving posting, branch-level WAC valuation, procurement CSV exports, RBAC enforcement, audit logging, and tenant/branch isolation. All 48 tests / 263 assertions in the full procurement feature suite passed successfully, and production compilation (`npm run build`) completed successfully. Accounts payable, supplier returns/RMA, auto-reorder, and mandatory perishable enforcement remain deferred to future scope.

---

## Epic 25: Subscription-Based Feature Gating [Closed]
*Status: CLOSED & VALIDATED*
- 25.1 Subscription Configuration & Tier Definitions
- 25.2 System-Level Feature Gating Middleware
- 25.3 Background Queue Job & Console Guarding
- 25.4 Frontend Gating & Inertia Integration
- 25.5 Legacy Grandfathering & Onboarding Flow

---

## Epic 26: Advanced Supply Chain, Expiry Tracking & Automated Procurement [Closed]
*Status: CLOSED & VALIDATED*
- **[x] 26.1-A: Expiry Lot Schema & Model Foundation** [Implemented / Validated]
- **[x] 26.1-B: Receiving-Time Expiry Capture** [Implemented / Validated / Signed-Off]
- **[x] 26.1-C: FEFO Selection & Concurrency Planning Only** [Planning Locked / Signed-Off: [story_26.1-c_fefo_allocation_planning.md](./stories/story_26.1-c_fefo_allocation_planning.md)]
- **[x] 26.1-D: FEFO Allocation Service Foundation** [Implemented / Validated / Signed-Off: [spec-26-1-d-fefo-allocation-service-foundation.md](../_bmad-output/implementation-artifacts/spec-26-1-d-fefo-allocation-service-foundation.md)]
- **[x] 26.1-E: FEFO POS Transaction Integration** [Implemented / Validated / Signed-Off: [spec-26-1-e-fefo-pos-transaction-integration.md](../_bmad-output/implementation-artifacts/spec-26-1-e-fefo-pos-transaction-integration.md)]
- **[x] 26.2-A: PAR Levels & Lead-Time Auto-Reorder Planning Only** [Planning Locked / Signed-Off: [story-26.2-a-par-levels-and-auto-reorder-planning-scope-lock.md](../_bmad-output/planning-artifacts/story-26.2-a-par-levels-and-auto-reorder-planning-scope-lock.md)]
- **[x] 26.2-B: Branch Inventory Threshold Schema Foundation** [Implemented / Validated / Signed-Off: [spec-26-2-b-branch-inventory-threshold-schema-foundation-closure.md](../_bmad-output/implementation-artifacts/spec-26-2-b-branch-inventory-threshold-schema-foundation-closure.md)]
- **[x] 26.2-C: Replenishment Recommendation Service** [Implemented / Validated / Signed-Off: [spec-26-2-c-replenishment-recommendation-service-closure.md](../_bmad-output/implementation-artifacts/spec-26-2-c-replenishment-recommendation-service-closure.md)]
- **[x] 26.2-D: Draft PO Generation** [Implemented / Validated / Signed-Off: [spec-26-2-d-draft-po-generation-closure.md](../_bmad-output/implementation-artifacts/spec-26-2-d-draft-po-generation-closure.md)]
- **[x] 26.2-E: Scheduler / Console Command** [Implemented / Validated / Signed-Off]

- **[x] 26.3-A: Supplier Returns / RMA Planning Only** [Planning Locked / Signed-Off: [story_26.3-a_supplier_returns_planning_scope_lock.md](./stories/story_26.3-a_supplier_returns_planning_scope_lock.md)]
- **[x] 26.3-B: Supplier Returns & Return Lines Schema Foundation** [Implemented / Validated / Signed-Off]
- **[x] 26.3-C: RMA State Machine & Immutability Integration** [Implemented / Validated / Signed-Off]
- **[x] 26.3-D: High-Precision WAC Recalculation Service** [Implemented / Validated / Signed-Off]
- **[x] 26.3-E: Expiry Lot Depletion & FEFO Return Flow** [Implemented / Validated / Signed-Off: [story-26.3-e-supplier-return-debit-note-planning-scope-lock.md](../_bmad-output/planning-artifacts/story-26.3-e-supplier-return-debit-note-planning-scope-lock.md)]
- **[x] 26.3-F: Supplier Return Accounting Outbox Handoff** [Implemented / Validated / Signed-Off: [spec-26-3-f-supplier-return-accounting-outbox-handoff.md](../_bmad-output/implementation-artifacts/spec-26-3-f-supplier-return-accounting-outbox-handoff.md)]
- **[x] 26.4-A: 3-Way AP Document Matching Planning** [Planning Locked / Signed-Off: [story-26.4-a-3-way-ap-document-matching-planning-scope-lock.md](../_bmad-output/planning-artifacts/story-26.4-a-3-way-ap-document-matching-planning-scope-lock.md)]
- **[x] 26.4-B: Supplier Invoice Schema Foundation** [Implemented / Validated / Signed-Off: [spec-26-4-b-supplier-invoice-schema-foundation.md](../_bmad-output/implementation-artifacts/spec-26-4-b-supplier-invoice-schema-foundation.md)]
- **[x] 26.4-C: 3-Way Matching Engine Foundation** [Implemented / Validated / Signed-Off: match26c]
- **[x] 26.4-D: Supplier Invoice Posting & Accounting Outbox Handoff** [Implemented / Validated / Signed-Off: ap26d]
- **[x] 26.5-A: Master Corporate Split POs & Branch IBT Planning Scope Lock** [Planning Locked / Signed-Off: poibt265a]
- **[x] 26.5-B: Master PO & Branch IBT Schema Foundation** [Implemented / Validated / Signed-Off: G-038]
- **[x] 26.5-C: Master PO Split Service Implementation** [Implemented / Validated / Signed-Off: mposplit265c]
- **[x] 26.5-D: IBT Stock Movement Engine** [Implemented / Validated / Signed-Off: ibt265d]

**Closure Note:**
Epic 26 delivered the complete suite for enterprise supply chain operations. Key highlights include Expiry & Batch Lot tracking with POS FEFO integration, PAR-level auto-reordering schedulers, high-precision WAC recalculations on Supplier Returns (RMAs), transactional 3-Way AP Document Matching, multi-branch Master PO Splits, and Inter-Branch Stock Transfers (IBTs) with fail-closed multi-tenant and pessimistic lock protections. Standard cashier roles are strictly prohibited from all mutations, and all transactions are pushed to the Accounting Outbox for QBO syncing. The full Procurement Feature Suite (148 tests / 735 assertions) passed with 100% success.

---

## Epic 27: Ingredient Inventory Upgrade (Phase 1) [Closed]
*Validated: May 2026*
- **[x] 27.1-A: Dynamic Unit Conversion Foundation** [Implemented - Spec: [ingredient-inventory-upgrade-phase-1-plan.md](./ingredient-inventory-upgrade-phase-1-plan.md)]
- **[x] 27.1-B: Inventory Deduction Policy Settings** [Implemented]
- **[x] 27.1-C: Variance & Warning Logging** [Implemented]
- **[x] 27.1-D: InventoryService Policy-Based Refactor** [Implemented]
- **[x] 27.1-E: Feature Test Verification** [Implemented]

### Completed
- Added branch-level inventory deduction policy.
- Added tenant-scoped unit conversion records.
- Added product-specific conversion override support.
- Added immutable inventory variance logs.
- Refactored inventory deduction to support strict and soft-negative policies.
- Preserved existing strict-block behavior as default.
- Added variance logging for allowed negative stock deductions.
- Added clean validation response handling for unsupported conversions.
- Added regression coverage for inventory deduction behavior.

### Validation Evidence
- `./vendor/bin/phpunit tests/Feature/POS/InventoryDeductionPolicyTest.php`
- `./vendor/bin/phpunit tests/Feature/POS`

### Governance Note
This phase does not implement multi-level BOM, recursive recipes, inventory productions, weighted average costing, COGS analytics, procurement costing, or commissary workflows. Those remain deferred to future phases. Hardening/fixes added for PaymentController and the unrelated FEFO test harness alignment do not expand Phase 1 scope boundaries.

### Next Technical Step
- **Phase 1 UI/Admin Management Planning**: See [epic-27-phase-1-ui-planning.md](./epic-27-phase-1-ui-planning.md) for UX, API endpoints, RBAC, and verification guidelines.

---

## Epic 28: Offline-Resilient POS Architecture (Phase 1: Offline-Tolerant POS Shell) [Closed]
*Status: CLOSED & VALIDATED*

Epic 28 Phase 1 implements an offline-tolerant POS shell only. It allows cached catalog browsing, cart draft persistence, connectivity awareness, and checkout locking during offline state. It does not support official offline selling, invoice generation, receipt printing, Z-read, GCT, e-journal finalization, or offline sales queueing.

### Completed
- Added POS bootstrap cache endpoint.
- Added client IndexedDB catalog cache.
- Added cache metadata storage for tax/config hash, TTL, generated timestamp, tenant/branch profile, and permissions.
- Added connectivity guard helpers.
- Added persistent POS online/offline/cache status banner.
- Added offline checkout lock behavior.
- Added centralized cart draft store module.
- Refactored transaction store to use cart draft module.
- Added sensitive metadata filtering from offline cart drafts.
- Added frontend and backend validation coverage.
- Confirmed production build passes.

### Validation Evidence
- `php vendor/pestphp/pest/bin/pest tests/Feature/POS/OfflineBootstrapCacheTest.php`
- Result: 5 tests / 30 assertions passing
- Frontend Node test suite passed
- `npm run build` passed

### Governance Note
This phase is cache/cart resilience only. Official checkout, invoice generation, receipt printing, inventory mutation, Z-read, GCT, and e-journal finalization remain server-authoritative and online-only.

## Epic 28 Phase 2 — Controlled Offline Sales

Status: Implemented & Locally Validated — Controlled Early Partner Pilot Ready, External CPA/BIR Review Deferred

### Current Position
- Development implementation is complete for controlled early partner pilot readiness.
- Feature remains controlled by tenant/branch/terminal enablement and disable controls.
- Early partner adoption may be supported under controlled rollout with monitoring.
- External CPA/BIR review will occur after the feature is fully developed.
- No marketing or official compliance claim is allowed before final review.

### Required Controls
- tenant/branch/terminal disable toggles
- terminal-bound sequence prefixes
- provisional/pending-sync labels
- server-authoritative reconciliation
- fixed-point decimal parity
- late-sync audit classification
- lost/revoked range handling
- no local official GCT/Z-read finalization

### Phase 2 Story Backlog
- **[x] 28.5: Epic 28 Phase 2 Slice A — Settings, Terminal Sequence Registry, and Admin Controls** [Planning Locked / Scope Locked: [story-28.5-epic-28-phase-2-slice-a-scope-lock.md](../_bmad-output/planning-artifacts/story-28.5-epic-28-phase-2-slice-a-scope-lock.md)]
- **[x] 28.6: Epic 28 Phase 2 Slice B — Offline Import Schema & Reconciliation Foundation** [Implemented & Locally Validated]
- **[x] 28.7: Epic 28 Phase 2 Slice C — Offline Sync Validation & Reconciliation Service** [Implemented & Locally Validated]
- **[x] 28.8: Epic 28 Phase 2 Slice D — Offline Import Server Recalculation** [Implemented & Locally Validated]
- **[x] 28.9: Epic 28 Phase 2 Slice E — Offline Import Posting Readiness & Admin Conflict Review** [Implemented & Locally Validated]
- **[x] 28.10: Epic 28 Phase 2 Slice F — Offline Import Official Posting & Reconciliation** [Implemented & Locally Validated]
- **[x] 28.11: Epic 28 Phase 2 Slice G — POS Offline Transaction Queue & Sync UX** [Implemented & Locally Validated]

Implementation note: Story 28.10 completes official server-side offline import posting and reconciliation. Story 28.11 adds provisional terminal-side queueing, append-only hash-chain diagnostics, guarded offline capture, sync batching, retryable failure handling, and cashier sync status UX only. The 2026-07-11 stabilization pass further hardens reconnect handling, stale service-worker shell rollover, cached catalog fallback, offline draft payment routing, and review-required queue classification. Local official GCT, Z-read, and e-journal finalization remain out of scope; official finalization remains server-authoritative.

**Closure Artifact:**
- [epic-28-phase-2-controlled-offline-sales-closure-report.md](../validation/epic-28-phase-2-controlled-offline-sales-closure-report.md)
- [pos-terminal-offline-stabilization-2026-07-10.md](../validation/pos-terminal-offline-stabilization-2026-07-10.md)
- [pos-terminal-offline-uat-2026-07-11.md](../validation/pos-terminal-offline-uat-2026-07-11.md)

### POS Terminal Hardening Pass

Status: Development Checkpointed; UAT Release Gate Pending

The July 2026 cashier-led offline testing and follow-up readiness review found
that the controlled offline cash-sale path is implemented for pilot use, but
requires a hardening pass before broader rollout. The pass covers terminal route
surface completion, admin offline conflict review UX, queue diagnostics and
retention, legacy route/session hardening, hardware readiness visibility, and
formal UAT sign-off.

Checkpoint `6c2b5d0` completes the current development baseline for the
implemented hardening slices. The next active step is UAT/release-gate evidence
collection. Hardware printer and cash drawer physical validation remains
deferred until devices are available.

Planning Artifact:
- [pos-terminal-hardening-pass-development-ready-plan.md](../../_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md)

---

## Epic 29: Platform Tenant Provisioning & Subscription Feature Gating [Closed]
*Initiated: May 2026*

Provides multi-tenant onboarding foundation (system admin provisioning, tenant lifecycle) and subscription-aware feature gating to enforce entitlements across POS, catalog, reports, and procurement surfaces.

### Story 29.1: Platform Tenant Provisioning Foundation [Closed]

**Status:** Implemented & Locally Validated

**Scope:**
- System admin tenant creation and branch provisioning models
- Tenant provisioning schema, validation, and initial state
- Platform admin scoped tenant management dashboard
- Tenant lifecycle controls (active/suspended/archived)
- Default subscription plan assignment on creation
- Platform isolation enforcement and fail-closed tests

**Validation Evidence:**
- [docs/validation/story-29.1-implementation-report.md](../validation/story-29.1-implementation-report.md)
- Test Suite: `tests/Feature/SystemAdmin/TenantProvisioningTest.php`

**Governance Note:**
Story 29.1 provides the tenant creation foundation only. Branch/owner onboarding (Story 29.2) is blocked until Story 29.1A completion.

---

### Story 29.1A: Feature Gate Enforcement Coverage Hardening [Substantially Complete — Optional POS Shell Gating Deferred]

**Status:** Closed — Wave 1 + Wave 2 Slices A-D Implemented & Target-Locally Validated

**Scope Overview:**
Hardens subscription feature enforcement across five configured features: `sales.pos`, `catalog.view`, `catalog.edit`, `reports.*`, `procurement.*` by mapping routes to gating middleware and UI visibility.

**Wave 1 (Complete):**
- Added `subscription.feature` middleware to isolated route groups:
  - `reports.basic` ⟹ tax report routes
  - `reports.advanced` ⟹ cashier accountability routes
  - `procurement.basic` ⟹ supplier/PO/receiving routes
  - `procurement.advanced` ⟹ supplier returns routes
  - `layout.custom` ⟹ POS layout routes
- Updated left navigation to hide/show features by entitlement
- Added regression test coverage for Wave 1 features

**Wave 2 Strategy:**
- Slice A: High-confidence catalog write routes (`catalog.edit`)
- Slice B: Catalog read routes (`catalog.view`) with dependency classification
- Slice C: POS checkout-only (`sales.pos`)
- Slice D: POS shell/supporting route gating (`sales.pos`)

**Implementation & Closure (Completed):**
- Wave 1: Reports, procurement, layout custom features gated and validated
- Slice A: `catalog.edit` admin write routes gated and validated
- Slice B Phase B1: `catalog.view` safe index routes gated and validated
- Slice B Phase B2: product create/edit form routes gated with `catalog.edit` and validated
- Slice C: POS checkout-sensitive routes gated with `sales.pos` and validated
- Slice D: POS shell/search/active-shift/bootstrap/offline-sync routes gated with
  `sales.pos` and validated

**Wave 2 — Slice A (Complete):**
Implemented `subscription.feature:catalog.edit` gating on admin product/category write endpoints:
- `POST/PUT/PATCH/DELETE /admin/products*`
- `POST/PUT/PATCH/DELETE /admin/product-categories*`
- Preserved permission checks alongside feature gates
- Updated nav visibility for Product Catalog editor entry points

**Validation Evidence (Slice A):**
- Test Suite: `tests/Feature/Subscription/RouteFeatureGateTest.php` (write routes slice)
- Result: 12 tests / 35 assertions passing

**Wave 2 — Slice B Phase B1 (Implemented & Validated — Closure Evidence Prepared):**
Implemented `subscription.feature:catalog.view` gating on safe back-office read routes:
- `GET /admin/product-categories` (`admin.product-categories.index`)
- `GET /admin/products` (`admin.products.index`)
- Updated Product Catalog nav to require both `manage_products` permission and `catalog.view` entitlement
- Preserved runtime shared dependencies: `pos.search`, `inventory.stocktakes.catalog.search` remain ungated
- Deferred create/edit form routes to Slice B2 pending interaction analysis with Slice A write gate; subsequently closed in Slice B2

**Validation Evidence (Slice B1):**
- Test Suite: `tests/Feature/Subscription/RouteFeatureGateTest.php`
- Result: 19 tests / 37 assertions passing (full suite with Wave 1 + Slice A + B1)
- Tests include:
  - deny non-entitled access to both index routes (2 tests)
  - allow entitled access to both index routes (2 tests)
  - mixed entitlement behavior: view allowed, edit denied (1 test)
  - non-regression checks: POS and stocktake search paths remain ungated (2 tests)

**Closure Artifact:**
- [docs/validation/story-29.1a-wave-2-slice-b-b1-catalog-view-closure.md](../validation/story-29.1a-wave-2-slice-b-b1-catalog-view-closure.md)
  - Documents exact routes gated, routes intentionally deferred, test evidence
  - Scope boundaries and governance notes for next phase decision

**Wave 2 — Slice B Phase B2 (Implemented & Validated):**
Implemented `subscription.feature:catalog.edit` gating on product form routes tied to edit workflows:
- `GET /admin/products/create` (`admin.products.create`)
- `GET /admin/products/{product}/edit` (`admin.products.edit`)
- Preserved runtime shared reads: `pos.search` and `inventory.stocktakes.catalog.search` remain ungated

**Validation Evidence (Slice B2):**
- Test Suite: `tests/Feature/Subscription/RouteFeatureGateTest.php`
- Result: 21 tests / 41 assertions passing
- Full Subscription suite: 35 tests / 80 assertions passing

**Closure Artifact:**
- [docs/validation/story-29.1a-wave-2-slice-b2-product-form-route-gating-closure.md](../validation/story-29.1a-wave-2-slice-b2-product-form-route-gating-closure.md)

**Wave 2 — Slice C (Implemented & Validated):**
Implemented `subscription.feature:sales.pos` gating on POS checkout-sensitive routes only:
- `POST /pos/checkout/validate`
- `POST /pos/checkout/create-sale`
- `POST /pos/checkout/status`
- `GET /pos/sales/{sale_id}/receipt`
- `POST /pos/sales/{sale_id}/payments`
- `POST /pos/sales/{sale_id}/payments/split`
- POS shell/search/active-shift/bootstrap/offline-sync routes were deferred to
  Slice D and subsequently closed.

**Validation Evidence (Slice C):**
- RouteFeatureGateTest.php: 25 tests / 63 assertions passing
- Checkout/payment focused POS tests: 72 tests / 231 assertions passing

**Closure Artifact:**
- [docs/validation/story-29.1a-wave-2-slice-c-sales-pos-checkout-gating-closure.md](../validation/story-29.1a-wave-2-slice-c-sales-pos-checkout-gating-closure.md)

**Wave 2 — Slice D (Implemented & Validated):**
Implemented `subscription.feature:sales.pos` gating on POS shell/supporting routes:
- `GET /pos`
- `GET /pos/search`
- `GET /pos/active-shift`
- `GET /pos/bootstrap-cache`
- `POST /api/pos/offline-sync`

Also updated authenticated navigation so the POS Terminal entry requires the
`sales.pos` feature entitlement, and updated System Admin tenant provisioning
feature coverage to compute live route middleware coverage instead of static
feature notes.

**Validation Evidence (Slice D):**
- `php artisan test tests/Feature/Subscription/RouteFeatureGateTest.php tests/Feature/SystemAdmin/TenantProvisioningTest.php`: 36 passed / 160 assertions
- `npm run build`: passing

**Closure Artifact:**
- [docs/validation/story-29.1a-wave-2-slice-d-pos-shell-gating-closure.md](../validation/story-29.1a-wave-2-slice-d-pos-shell-gating-closure.md)

**Governance Notes:**
- Story 29.1A Wave 2 closure documented through Slice D with POS shell gating closed
- Coverage map and closure artifacts updated through Wave 2 Slice C
- Task ledger (G-060/G-061) reflects Story 29.1A completion and Story 29.2 unblocking
- No entitlement-engine or billing behavior changes
- Permission model unchanged; feature gates are layered on top
- No residual Wave 2 feature-gate item remains open in this track.

**Planning Artifacts:**
- [story-29.1a-feature-gate-enforcement-coverage-hardening-scope-lock.md](../_bmad-output/planning-artifacts/story-29.1a-feature-gate-enforcement-coverage-hardening-scope-lock.md)
- [story-29.1a-wave-2-sales-pos-catalog-risk-ranked-plan.md](../_bmad-output/planning-artifacts/story-29.1a-wave-2-sales-pos-catalog-risk-ranked-plan.md)
- [story-29.1a-wave-2-slice-a-catalog-edit-plan.md](../_bmad-output/planning-artifacts/story-29.1a-wave-2-slice-a-catalog-edit-plan.md)
- [story-29.1a-wave-2-slice-b-catalog-view-plan.md](../_bmad-output/planning-artifacts/story-29.1a-wave-2-slice-b-catalog-view-plan.md)
- [story-29.1a-wave-2-implementation-checklist.md](../_bmad-output/planning-artifacts/story-29.1a-wave-2-implementation-checklist.md)

**Coverage Map:**
- [docs/validation/story-29.1a-feature-gate-coverage-map-initial.md](../validation/story-29.1a-feature-gate-coverage-map-initial.md)

---

### Story 29.2: Branch & Owner Tenant Admin Onboarding Setup [Implemented & Target-Locally Validated]

**Status:** Implemented & Target-Locally Validated

**Governance Decision:**
Story 29.2 onboarding implementation is accepted for the targeted domain (System Admin provisioning/onboarding and related payment/audit checks). Story 29.1A POS shell gating was later closed in Wave 2 Slice D and remains compatible with this acceptance.

**Residual Gaps Deferred:**
- None for Story 29.1A Wave 2 feature-gate coverage.

**Validation Evidence:**
- `./vendor/bin/pest tests/Feature/SystemAdmin` -> 18 tests passing
- `./vendor/bin/pest tests/Feature/SystemAdmin tests/Feature/POS/PaymentAuditTest.php tests/Feature/AuditLoggingTest.php` -> 27 tests passing

**Governance Update:**
G-062 accounting/full-suite blocker triage is resolved. G-066 full-suite risky/incomplete cleanup is also closed; latest full-suite baseline is green with 1351 passed, 0 failed, 0 risky, 0 incomplete (6237 assertions).

**Next Action:**
Proceed to Story 29.3 Sales Machine Profile and Compliance Registration under standard validation flow.

---

### Story 29.3: Sales Machine Profile and Compliance Registration [Implemented & Target-Locally Validated]

**Status:** Implemented & Target-Locally Validated

**Completed:**
- Added machine profile onboarding request validation.
- Added System Admin onboarding endpoint for machine profile registration/update.
- Implemented register/update logic using existing SalesMachineProfile fields.
- Added compliance completeness evaluation and integrated machine profile status into onboarding readiness.
- Added machine profile onboarding event recording.
- Added targeted System Admin feature and readiness tests.

**Validation Evidence:**
- `./vendor/bin/pest tests/Feature/SystemAdmin` -> 22 tests / 127 assertions passing
- `./vendor/bin/pest tests/Feature/SystemAdmin tests/Feature/POS/PaymentAuditTest.php tests/Feature/AuditLoggingTest.php` -> 31 tests / 147 assertions passing

**Closure Artifact:**
- [docs/validation/story-29.3-sales-machine-profile-compliance-registration-closure.md](../validation/story-29.3-sales-machine-profile-compliance-registration-closure.md)

**Governance Note:**
Story 29.3 completes the sales machine profile and compliance registration step in the tenant onboarding path. It does not modify core BIR tax computation, official receipt logic, subscription feature-gating behavior, or controlled offline sales pilot provisioning.

**Governance Update:**
G-062 accounting/full-suite blocker triage is resolved. G-066 full-suite risky/incomplete cleanup is also closed; latest full-suite baseline is green with 1351 passed, 0 failed, 0 risky, 0 incomplete (6237 assertions).

**Next Action:**
After Story 29.3 closure evidence is recorded, proceed to Story 29.4 Controlled Offline Sales Pilot Provisioning UI.

---

### Story 29.4: Controlled Offline Sales Pilot Provisioning UI [Implemented & Target-Locally Validated]

**Status:** Slice A + Slice B Implemented & Target-Locally Validated

**Goal:**
Create a System Admin provisioning UI/workflow that verifies whether a tenant, branch, and sales machine profile are eligible for controlled offline sales pilot enablement.

**Slice A — Pilot Eligibility Review and Readiness Checklist [Implemented & Validated]**

- Read-only `PilotEligibilityService` evaluating 11 checklist items across tenant/branch/terminal.
- `PilotProvisioningController::eligibility()` endpoint returning `ready` / `pending` / `blocked` outcome.
- Route: `GET /system-admin/tenants/{company}/pilot-eligibility` → `system-admin.pilot.eligibility`.
- 18 tests / 96 assertions passing.
- No mutations, no offline enablement, no new migrations.

**Validation Evidence (Slice A):**
- `tests/Feature/SystemAdmin/PilotProvisioningTest.php`: 18 tests / 96 assertions passing
- `tests/Feature/SystemAdmin`: 40 tests / 223 assertions passing

**Closure Artifact:**
- [story-29.4-slice-a-pilot-eligibility-review-closure.md](../docs/validation/story-29.4-slice-a-pilot-eligibility-review-closure.md)

**Slice B — Pilot Enablement Controls [Implemented & Validated]**

- `PilotProvisioningController::enable()` — validates requested flags, evaluates eligibility before write, runs in `DB::transaction()`, re-evaluates post-write; rolls back and records `pilot_enable_rejected` audit on non-ready outcome. Returns 422 on rejection, 200 with `{success, outcome, enabled_at, checks}` on success.
- `PilotProvisioningController::disable()` — sets `offline_sales_enabled = false` at requested level only (tenant | branch | terminal). Wide-flag protection: disabling terminal does not alter branch or tenant flags.
- `platformAudit()` private helper — wraps TenantContext temporarily so AuditLogger receives tenant context without leaving stale state.
- Routes: `POST /system-admin/tenants/{company}/pilot-enable` → `system-admin.pilot.enable`; `POST /system-admin/tenants/{company}/pilot-disable` → `system-admin.pilot.disable`.
- 13 tests / 46 assertions passing (`PilotProvisioningMutationTest.php`). Full SystemAdmin suite: 53 tests / 269 assertions. Zero regressions.

**Validation Evidence (Slice B):**
- `tests/Feature/SystemAdmin/PilotProvisioningMutationTest.php`: 13 tests / 46 assertions passing
- `tests/Feature/SystemAdmin`: 53 tests / 269 assertions passing

**Closure Artifact:**
- [story-29.4-slice-b-pilot-enablement-controls-closure.md](../docs/validation/story-29.4-slice-b-pilot-enablement-controls-closure.md)

**Out of Scope (all slices):**
- Broad offline enablement across tenants.
- Offline sync/posting backend behavior changes.
- GCT/Z-read/e-journal engine changes.
- BIR-certified claims or CPA/BIR review workflow.

**Planning Artifact:**
- [story-29.4-controlled-offline-sales-pilot-provisioning-ui-scope-lock.md](../_bmad-output/planning-artifacts/story-29.4-controlled-offline-sales-pilot-provisioning-ui-scope-lock.md)
- [story-29.4-slice-a-pilot-eligibility-review-readiness-checklist-plan.md](../_bmad-output/planning-artifacts/story-29.4-slice-a-pilot-eligibility-review-readiness-checklist-plan.md)

**Governance Note:**
G-062 is closed. Story 29.4 remains fully closed with no failing full-suite blockers.

**Next Action:**
Story 29.4 fully closed. Proceed to Story 29.5 Tenant Onboarding Readiness Review without a G-062 release caveat.

---

### Story 29.5: Tenant Onboarding Readiness Review [Implemented & Locally Validated]

**Status:** Implemented & Locally Validated

**Goal:**
Create a final readiness review surface that consolidates tenant provisioning, branch setup, owner/admin assignment, sales machine compliance, subscription feature-gate status, and controlled offline pilot readiness into a single System Admin dashboard. Enable sign-off on: "Ready for Pilot Operations," "Ready for Full Production," or "Blocked — Action Required."

**Slice A Completed Scope:**
- Tenant onboarding readiness aggregation service
- System Admin read-only readiness endpoint
- Branch, owner/admin, sales machine, subscription, feature-gate, and pilot-readiness checks
- Aggregated blocker and pending-action surface
- Three-state readiness derivation: `ready_for_pilot`, `ready_for_operations`, `blocked`
- Checklist metrics for dashboard display

**Out of Scope:**
- New onboarding mutations (all mutations remain in Stories 29.1–29.4)
- Offline sync/posting backend changes
- BIR/CPA review workflow
- Billing automation
- Tenant migration or re-onboarding

**Slice B Completed Scope:**
- Append-only readiness sign-off table and model.
- `POST /system-admin/tenants/{company}/sign-off-readiness`.
- Decisions: `ready_for_pilot`, `ready_for_operations`, `blocked`.
- Signer, timestamp, notes, calculated readiness state, and readiness snapshot persistence.
- Ready-state blocker guards.
- Audit logging for accepted and rejected valid decision attempts.

**Slice C Completed Scope:**
- `GET /system-admin/tenants/{company}/readiness/export`.
- JSON export with readiness summary and sign-off history.
- CSV export with summary, checks, blockers, pending actions, branches, sign-off history, and non-certification notice.
- Simple printable HTML readiness summary.
- System Admin-only read access.

**Evidence:**
- [story-29.5-tenant-onboarding-readiness-review-scope-lock.md](../_bmad-output/planning-artifacts/story-29.5-tenant-onboarding-readiness-review-scope-lock.md)
- [story-29.5-slice-b-readiness-signoff-workflow-plan.md](../_bmad-output/planning-artifacts/story-29.5-slice-b-readiness-signoff-workflow-plan.md)
- [story-29.5-slice-c-readiness-export-printable-summary-plan.md](../_bmad-output/planning-artifacts/story-29.5-slice-c-readiness-export-printable-summary-plan.md)
- [story-29.5-slice-a-tenant-readiness-aggregation-closure.md](../validation/story-29.5-slice-a-tenant-readiness-aggregation-closure.md)
- [story-29.5-slice-b-readiness-signoff-workflow-closure.md](../validation/story-29.5-slice-b-readiness-signoff-workflow-closure.md)
- [story-29.5-slice-c-readiness-export-printable-summary-closure.md](../validation/story-29.5-slice-c-readiness-export-printable-summary-closure.md)
- [story-29.5-tenant-onboarding-readiness-review-closure.md](../validation/story-29.5-tenant-onboarding-readiness-review-closure.md)
- `tests/Feature/SystemAdmin/TenantReadinessReviewTest.php`

**Governance Notes:**
Story 29.5 Slice A implements read-only tenant onboarding readiness aggregation only. It does not create or mutate tenants, branches, users, machine profiles, pilot enablement records, subscription settings, billing behavior, or offline sync/posting logic.

Story 29.5 Slice B implements readiness decision capture only. It records append-only readiness sign-off outcomes and audits accepted/rejected valid decision attempts. It does not remediate blockers, provision onboarding entities, enable controlled offline sales, modify subscription/billing state, or alter any offline posting or compliance engine behavior.

Story 29.5 Slice C implements read-only readiness export and printable summary only. It does not mutate onboarding, pilot enablement, subscription, billing, or offline sync/posting records. The export is an internal operational readiness artifact and not a BIR/CPA certification format.

**Validation Evidence:**
- `TenantReadinessReviewTest.php`: 16 tests / 84 assertions passing
- Full SystemAdmin suite: 69 tests / 353 assertions passing

**Epic 29 Closure Report:**
- [epic-29-platform-tenant-provisioning-closure-report.md](../validation/epic-29-platform-tenant-provisioning-closure-report.md)

**Final Governance Decision:**
Epic 29 is implemented and locally validated. Feature-gate hardening is closed through Story 29.1A Wave 2 Slice D, and the full suite baseline remains clean in the latest recorded governance evidence.

## Epic 30: System Admin Tenant Operations & Compliance Intelligence [Closed — Implemented & Locally Validated / 30.4 + 30.5 Planning-Locked Deferred]
*Initialized: May 2026*

Epic 30 is proposed as the next architecture planning track after Epic 29. It should move System Admin work from provisioning readiness into operational intelligence, with an initial focus on compliance detail visibility and tenant risk/deadline urgency.

**Planning Input:**
- [multi-tenant-saas-comprehensive-analysis.md](../validation/multi-tenant-saas-comprehensive-analysis.md)
- [epic-30-system-admin-tenant-operations-compliance-intelligence-architecture-handoff.md](../../_bmad-output/planning-artifacts/epic-30-system-admin-tenant-operations-compliance-intelligence-architecture-handoff.md)
- [story-30.1-compliance-detail-drill-down-scope-lock.md](../../_bmad-output/planning-artifacts/story-30.1-compliance-detail-drill-down-scope-lock.md)
- [story-30.1-compliance-detail-drill-down-closure.md](../validation/story-30.1-compliance-detail-drill-down-closure.md)
- [story-30.2-tenant-risk-scoring-and-deadline-urgency-plan.md](../../_bmad-output/planning-artifacts/story-30.2-tenant-risk-scoring-and-deadline-urgency-plan.md)
- [story-30.2-slice-a-advisory-urgency-calculation-service-closure.md](../validation/story-30.2-slice-a-advisory-urgency-calculation-service-closure.md)
- [story-30.2-slice-b-read-only-urgency-api-closure.md](../validation/story-30.2-slice-b-read-only-urgency-api-closure.md)
- [story-30.2-slice-c-dashboard-urgency-display-closure.md](../validation/story-30.2-slice-c-dashboard-urgency-display-closure.md)
- [story-30.4-system-admin-persona-based-views-scope-lock.md](../../_bmad-output/planning-artifacts/story-30.4-system-admin-persona-based-views-scope-lock.md)
- [story-30.5-optional-hardware-readiness-tracking-scope-lock.md](../../_bmad-output/planning-artifacts/story-30.5-optional-hardware-readiness-tracking-scope-lock.md)
- [epic-30-system-admin-tenant-operations-compliance-intelligence-closure-report.md](../validation/epic-30-system-admin-tenant-operations-compliance-intelligence-closure-report.md)

**Candidate Stories for Architecture Review:**
- 30.1 Compliance Detail Drill-Down
- 30.3 System Admin Operational Dashboard
- 30.2 Tenant Risk Scoring and Deadline Urgency
- 30.4 System Admin Persona-Based Views
- 30.5 Optional Hardware Readiness Tracking

**Architecture Sequencing Decision:**
Run 30.1 first as a read-only, derived-data compliance detail slice. Then build the
operational dashboard before risk scoring so urgency bands have a stable System Admin
surface. Keep persona work design-first until least-privilege mutation needs are
proven. Keep hardware readiness optional until dashboard usage validates it as a
useful signal.

**Story 30.1 Completed Scope:**
- Additive `compliance_detail` output on the existing readiness summary.
- Tenant-level compliance/readiness detail for tenant profile, subscription plan,
  feature gate alignment, and branch existence.
- Branch-level detail for branch activity, branch admin coverage, machine profile
  presence, machine compliance fields, and pilot eligibility reasons.
- Derived-only implementation inside `TenantReadinessService`.
- Existing readiness response fields preserved.
- Existing System Admin-only access preserved.

**Story 30.1 Validation Evidence:**
- `TenantReadinessReviewTest.php`: 19 tests / 182 assertions passing
- Full SystemAdmin suite: 72 tests / 451 assertions passing

**Story 30.2 Implemented Scope:**
- Slice A: advisory urgency calculation service implemented and validated.
- `SystemAdminTenantUrgencyService` calculates low/caution/critical urgency bands
  on request from existing readiness, compliance, and sign-off data.
- Slice B: read-only API payload implemented and validated.
- Existing dashboard summary endpoint now includes `urgency_counts` and
  `tenant_urgency`.
- Slice C: dashboard urgency display implemented and validated.
- Existing System Admin dashboard now displays urgency counts and per-tenant
  advisory urgency details from the validated API payload.
- No persisted risk score table, invented deadlines, remediation, suspension,
  feature disablement, or engine mutations were introduced.

**Story 30.2 Validation Evidence:**
- `SystemAdminTenantUrgencyServiceTest.php`: 5 tests / 22 assertions passing
- `SystemAdminDashboardApiTest.php`: 6 tests / 60 assertions passing
- Full SystemAdmin suite after Slice B: 91 tests / 561 assertions passing
- Frontend build after Slice C: `npm run build` passing
- Full SystemAdmin suite after Slice C: 91 tests / 561 assertions passing

**Story 30.3 Completed Scope:**
- Slice A (Dashboard Summary Service) implemented and validated.
- `SystemAdminDashboardService` created to aggregate tenant readiness counts, compliance details, pilot readiness, and recent sign-offs.
- Strictly read-only, relying on `TenantReadinessService` and `PilotEligibilityService`.
- Slice B (Read-only Dashboard API) implemented and validated.
- Added `SystemAdminDashboardController` with `GET /api/system-admin/dashboard/summary`.
- Endpoint protected by `auth:sanctum` and `platform.admin` middleware.
- Slice C (Dashboard UI Implementation) implemented and validated.
- Created React Inertia component `SystemAdmin/Dashboard/Index.jsx`.
- Fetch data from Slice B API and display readiness, compliance, and pilot counts.
- 83 tests / 500 assertions passing in SystemAdmin suite.

**Governance Boundary:**
Story 30.1, Story 30.3 (Slices A, B, and C), and Story 30.2 Slices A-C are implemented and locally validated.
Story 30.4 is planning locked and deferred; runtime implementation is not approved.
Decision: Persona-based System Admin views are documented as a future planning
option. Existing platform-admin dashboard access remains unchanged.
Story 30.5 is planning locked and deferred; optional hardware readiness remains
advisory planning only with no runtime implementation approval.
Remaining Epic 30 stories/slices are not approved for implementation until their own planning locks are created and accepted.
Competitor/provider patterns in the research artifact remain public-source or
inferred benchmarks only, not confirmed internal implementations or mandatory IPOS
requirements.

**Final Epic 30 Closure Decision:**
Epic 30 is implemented and locally validated for compliance detail visibility,
operational dashboarding, and advisory urgency intelligence.
Stories 30.4 and 30.5 are planning-locked deferred non-blocking future enhancements.
No persona enforcement, hardware enforcement, POS blocking, auto-remediation,
auto-suspension, billing change, or offline/tax engine change is approved.

**Non-Blocking Deferred Items:**
- automated remediation
- auto-suspension
- mandatory hardware sync blocking
- billing automation
- formal BIR/CPA certification workflow

## Epic 31: Product Catalog & Inventory Admin UX Completion [Closed - Implemented & Locally Validated / Import Write Path Deferred]
*Initialized: May 2026*

Epic 31 is approved as the next roadmap priority after Epic 30 closure.
The focus is Back Office operational usability for product/catalog/inventory
management surfaces that were previously foundation-heavy, deferred, or partially
covered by earlier implementation tracks.

**Planning Intent:**
- complete practical admin workflows for product setup and maintenance
- improve branch-level pricing and availability control UX
- add inventory visibility surfaces for day-to-day operations
- preserve governance boundaries: no POS blocking, no compliance-engine mutation,
  no billing/subscription engine changes unless separately approved

**Candidate Stories:**
- 31.1 Product Catalog Admin UX Review and Gap Lock
- 31.2 Product Create/Edit UX Hardening
- 31.3 Branch Pricing and Availability Management UI
- 31.4 Inventory Overview and Stock Visibility Dashboard
- 31.5 Recipe / Ingredient Admin Management UI
- 31.6 Catalog Import/Export and Audit Hardening
- 31.7 All-Products Ingredient Composition Report (post-closure extension)

**Planning and Closure Artifacts:**
- [epic-31-product-catalog-and-inventory-admin-ux-completion-scope-lock.md](../../_bmad-output/planning-artifacts/epic-31-product-catalog-and-inventory-admin-ux-completion-scope-lock.md)
- [story-31.1-product-catalog-admin-ux-review-gap-lock.md](../../_bmad-output/planning-artifacts/story-31.1-product-catalog-admin-ux-review-gap-lock.md)
- [story-31.2-product-create-edit-ux-hardening-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.2-product-create-edit-ux-hardening-scope-lock.md)
- [story-31.2-slice-a-product-create-edit-field-clarity-closure.md](../validation/story-31.2-slice-a-product-create-edit-field-clarity-closure.md)
- [story-31.2-slice-b-validation-error-feedback-hardening-closure.md](../validation/story-31.2-slice-b-validation-error-feedback-hardening-closure.md)
- [story-31.2-slice-c-save-success-navigation-consistency-closure.md](../validation/story-31.2-slice-c-save-success-navigation-consistency-closure.md)
- [story-31.2-slice-d-branch-pricing-recipe-entry-point-ux-polish-closure.md](../validation/story-31.2-slice-d-branch-pricing-recipe-entry-point-ux-polish-closure.md)
- [story-31.2-product-create-edit-ux-hardening-closure.md](../validation/story-31.2-product-create-edit-ux-hardening-closure.md)
- [story-31.3-branch-pricing-availability-management-ui-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.3-branch-pricing-availability-management-ui-scope-lock.md)
- [story-31.3-branch-pricing-availability-management-ui-closure.md](../validation/story-31.3-branch-pricing-availability-management-ui-closure.md)
- [story-31.4-inventory-overview-stock-visibility-dashboard-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.4-inventory-overview-stock-visibility-dashboard-scope-lock.md)
- [story-31.4-slice-a-inventory-dashboard-shell-closure.md](../validation/story-31.4-slice-a-inventory-dashboard-shell-closure.md)
- [story-31.4-slice-b-read-only-inventory-summary-data-closure.md](../validation/story-31.4-slice-b-read-only-inventory-summary-data-closure.md)
- [story-31.4-inventory-overview-stock-visibility-dashboard-closure.md](../validation/story-31.4-inventory-overview-stock-visibility-dashboard-closure.md)
- [story-31.5-recipe-ingredient-admin-management-ui-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.5-recipe-ingredient-admin-management-ui-scope-lock.md)
- [story-31.5-slice-a-recipe-workspace-ui-shell-closure.md](../validation/story-31.5-slice-a-recipe-workspace-ui-shell-closure.md)
- [story-31.5-slice-b-ingredient-search-selection-save-feedback-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.5-slice-b-ingredient-search-selection-save-feedback-scope-lock.md)
- [story-31.5-slice-b-ingredient-search-selection-save-feedback-closure.md](../validation/story-31.5-slice-b-ingredient-search-selection-save-feedback-closure.md)
- [story-31.5-recipe-ingredient-admin-management-ui-closure.md](../validation/story-31.5-recipe-ingredient-admin-management-ui-closure.md)
- [story-31.6-catalog-import-export-audit-hardening-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.6-catalog-import-export-audit-hardening-scope-lock.md)
- [story-31.6-slice-a-catalog-export-surface-product-category-csv-export-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.6-slice-a-catalog-export-surface-product-category-csv-export-scope-lock.md)
- [story-31.6-slice-a-catalog-export-surface-product-category-csv-export-closure.md](../validation/story-31.6-slice-a-catalog-export-surface-product-category-csv-export-closure.md)
- [story-31.6-slice-b-import-template-and-validation-strategy-scope-lock.md](../../_bmad-output/planning-artifacts/story-31.6-slice-b-import-template-and-validation-strategy-scope-lock.md)
- [story-31.6-slice-b-import-template-and-validation-strategy-closure.md](../validation/story-31.6-slice-b-import-template-and-validation-strategy-closure.md)
- [story-31.6-catalog-import-export-audit-hardening-closure.md](../validation/story-31.6-catalog-import-export-audit-hardening-closure.md)
- [story-31.7-all-products-ingredient-composition-report-implementation-spec.md](story-31.7-all-products-ingredient-composition-report-implementation-spec.md)
- [story-31.7-all-products-ingredient-composition-report-closure.md](../validation/story-31.7-all-products-ingredient-composition-report-closure.md)
- [epic-31-product-catalog-and-inventory-admin-ux-completion-closure-report.md](../validation/epic-31-product-catalog-and-inventory-admin-ux-completion-closure-report.md)

**Story 31.7 Completed Scope:**
- Story 31.7 All-Products Ingredient Composition Report is implemented and
  locally validated as a post-Epic-31 extension.
- Added read-only product-to-ingredient composition reporting across sellable
  products.
- Added direct mode aligned with current POS deduction behavior and flattened
  sub-recipe mode explicitly labeled planning-only.
- Added branch-aware stock, reorder, cost, ingredient coverage, and parent
  bottleneck coverage context.
- Added cost-field masking for users without `audit_inventory`.
- Added shared unit conversion resolver consumed by both reporting and
  `InventoryService`, preserving strict checkout behavior.
- Added CSV export with formula-injection hardening, stable branch columns, cost
  masking, and configurable max-row ceiling.
- Added Inventory Dashboard navigation entry for the report.
- No recipe edit workflow, POS recursive deduction behavior, procurement
  automation trigger, import write-path expansion, tax/accounting/subscription
  engine change, tenant isolation change, or branch isolation change was
  introduced.

**Story 31.7 Validation Evidence:**
- `php artisan test tests/Feature/Inventory/ProductCompositionReportTest.php tests/Unit/Inventory/UnitConversionResolverTest.php`: 14 passed / 129 assertions
- `php artisan test tests/Feature/Inventory/UnitConversionManagementTest.php tests/Feature/POS/SaleCreationFefoTest.php tests/Feature/POS/InventoryDeductionPolicyTest.php tests/Feature/POS/SaleCreationTest.php`: 55 passed / 173 assertions
- `npm run build`: passing

**Story 31.6 Slice B Completed Scope:**
- Story 31.6 Slice B Import Template and Validation Strategy is implemented and
  locally validated.
- Added product and product category CSV template downloads.
- Added validation-only product and product category import preview flows.
- Added `CatalogImportPreviewService` for template generation, required/optional
  column handling, duplicate checks, reference checks, tenant-scoped lookups,
  row-level failure reporting, and preview summaries.
- Added audit logging for template downloads and preview attempts.
- Added import-preview controls and result displays to existing product and
  category list UI.
- No actual import writes, bulk create/update, background jobs, pricing, tax,
  inventory, recipe/BOM, POS/runtime, subscription, accounting certification,
  RBAC, tenant isolation, or branch isolation changes were introduced.

**Story 31.6 Slice B Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 27 passed / 71 assertions
- `ProductCatalogTest.php`: 10 passed / 42 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 9 passed / 43 assertions

**Story 31.6 Slice A Completed Scope:**
- Story 31.6 Slice A Catalog Export Surface / Product & Category CSV Export is
  implemented and locally validated.
- Added read-only product and product category CSV exports under existing catalog
  list boundaries.
- Export access uses existing `manage_products` and `catalog.view` expectations.
- Added CSV formula-injection hardening, safe response headers, timestamped
  filenames, and export audit events.
- Added export actions to the existing product and category list UI.
- No import upload workflow, bulk create/update, pricing, tax, inventory,
  recipe/BOM, POS/runtime, subscription, accounting certification, background
  processing, RBAC, tenant isolation, or branch isolation changes were
  introduced.

**Story 31.6 Slice A Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 26 passed / 67 assertions
- `ProductCatalogTest.php`: 8 passed / 23 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 8 passed / 41 assertions

**Story 31.5 Slice B Completed Scope:**
- Story 31.5 Slice B Ingredient Search / Selection and Save Feedback Hardening is
  implemented and locally validated.
- Scope remained frontend-only in the existing Product Edit recipe workspace.
- Improved ingredient search-result messaging, no-result messaging, duplicate
  ingredient guidance, add/remove workspace feedback, row-level quantity/unit
  error display from existing Inertia/server errors, recipe save success/error
  feedback, and processing-state copy.
- Existing `admin.products.recipe.update` endpoint and request payload shape were
  preserved.
- No recipe/BOM computation, inventory deduction/posting, costing/WAC/FEFO, POS,
  tax, accounting, backend contract, controller persistence, validation-rule,
  subscription, RBAC, tenant isolation, or branch isolation changes were
  introduced.

**Story 31.5 Slice B Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions
- `UnitConversionManagementTest.php`: 8 passed / 14 assertions
- `VarianceLogAuditingTest.php`: 6 passed / 10 assertions

**Story 31.5 Slice A Completed Scope:**
- Story 31.5 Recipe / Ingredient Admin Management UI Slice A is implemented and
  locally validated.
- Current baseline uses the Product Edit embedded Recipe / Ingredients entry point,
  `ProductController@updateRecipe`, `ProductRecipe`, and existing product recipe
  relationships.
- Added recipe workspace framing, guide copy, row count visibility, ingredient
  search/select guidance, desktop row labels, row numbering, and clearer
  per-sale quantity/unit context.
- Preserved the existing recipe update endpoint and `ProductRecipe` behavior.
- No recipe/BOM computation, inventory deduction/posting, costing/WAC/FEFO, POS,
  tax, accounting, production/commissary workflow, backend contract, persistence
  rule, subscription, RBAC, tenant isolation, or branch isolation changes were
  introduced.

**Story 31.5 Slice A Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.5 Final Decision:**
- Story 31.5 Recipe / Ingredient Admin Management UI is implemented and locally
  validated.
- Slices A-B are closed.
- Scope remained frontend-only within the existing Product Edit recipe
  workspace.
- No `ProductController@updateRecipe` behavior changes, recipe validation-rule
  changes, ProductRecipe persistence changes, recipe/BOM computation changes,
  inventory deduction/posting changes, costing/WAC/FEFO changes, POS, tax,
  accounting, backend contract, subscription, RBAC, tenant isolation, or branch
  isolation changes were introduced.
- Story 31.6 Catalog Import/Export and Audit Hardening is in progress through
  Slices A-B. Actual import writes, bulk create/update, background processing,
  and write-path behavior remain locked pending explicit approval.

**Story 31.4 Final Decision:**
- Story 31.4 Inventory Overview and Stock Visibility Dashboard is implemented and
  locally validated.
- Slices A-B are closed.
- Scope remained read-only for inventory dashboard shell and summary data.
- No stock mutation controls, write endpoint changes, posting/deduction changes,
  movement semantic changes, WAC/FEFO/costing changes, procurement automation,
  POS, tax, accounting, RBAC, subscription, tenant isolation, or branch
  isolation changes were introduced.

**Story 31.4 Slice B Completed Scope:**
- Added read-only inventory summary data to the Inventory Overview dashboard.
- Added `InventoryDashboardController@index` behind the existing dashboard route.
- Preserved `GET /inventory/dashboard` and `inventory.dashboard.index`.
- Added tracked item, low-stock, and negative-stock summary counts.
- Added branch-level and product-level read-only stock visibility tables.
- Added negative-stock spotlight and movement summary counts.
- Added read-only filters for branch, product name/SKU, stock status, and movement
  date range.
- No inventory mutation controls, write endpoints, posting/deduction behavior,
  movement semantics, WAC/FEFO/costing, procurement automation, POS, tax,
  accounting, RBAC, subscription, tenant isolation, or branch isolation changes
  were introduced.

**Story 31.4 Slice B Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.4 Slice A Completed Scope:**
- Added read-only Inventory Overview dashboard shell.
- Added `GET /inventory/dashboard` route using existing inventory permissions.
- Added Inventory Overview sidebar navigation entry.
- Added placeholder filter UI for branch, product, stock status, and date range.
- Added read-only stock visibility cards and low/negative stock explanatory copy.
- Linked to existing Stocktakes, Variance Logs, Unit Conversions, and Inventory
  Movements surfaces where permissions allow.
- No inventory mutation controls, write endpoints, backend API contracts,
  inventory deduction/posting, WAC/FEFO/costing, procurement automation, POS, tax,
  accounting, RBAC, subscription, tenant isolation, or branch isolation changes
  were introduced.

**Story 31.4 Slice A Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.3 Final Decision:**
- Story 31.3 Branch Pricing and Availability Management UI is implemented and
  locally validated.
- Scope remained frontend-only in the Product Edit branch pricing surface.
- Added global-vs-branch pricing hierarchy, branch override feedback, field-level
  modal feedback, empty-state guidance, and clearer action labels.
- No branch pricing engine, pricing calculation, availability computation,
  inventory, tax, POS, recipe/BOM, validation rule, persistence, subscription,
  RBAC, tenant isolation, branch isolation, or endpoint contract changes were
  introduced.

**Story 31.3 Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.2 Final Decision:**
- Story 31.2 Product Create/Edit UX Hardening is implemented and locally validated.
- Slices A-D are closed.
- Scope remained frontend-only for Create/Edit UX hardening.
- No controller, validation rule, pricing, tax, inventory, recipe/BOM, POS, RBAC,
  tenant isolation, branch isolation, or subscription gate changes were introduced.

**Story 31.2 Slice A Completed Scope:**
- Product Create/Edit field clarity and required-field UX hardening.
- Helper text and placeholder improvements for name, SKU, barcode, pricing, unit
  of measure, category, and description fields.
- Status wording aligned from `Archived` to `Inactive`.
- UI-only changes to `Create.jsx` and `Edit.jsx`.
- No controller, persistence, pricing, tax, inventory, recipe, POS, RBAC, or
  subscription gate changes.

**Story 31.2 Slice A Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.2 Slice B Completed Scope:**
- Product Create/Edit validation and save feedback hardening.
- Top-level validation summary banner for server validation errors.
- Field-level error border/ring styling for active errors.
- Consistent `InputError` coverage, including category selection on Edit.
- `preserveScroll` submit behavior for validation continuity.
- Save failure feedback and Edit success acknowledgment.
- Frontend-only changes to `Create.jsx` and `Edit.jsx`.
- No controller, validation rule, persistence, pricing, tax, inventory, recipe,
  POS, RBAC, or subscription gate changes.

**Story 31.2 Slice B Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.2 Slice C Completed Scope:**
- Product Create/Edit save/success and navigation consistency polish.
- Labeled Back to Products navigation on Create/Edit.
- Clearer create save-state copy and processing label.
- Edit success-state copy and icon using existing `recentlySuccessful` signal.
- View Product List affordance after successful update.
- Frontend-only changes to `Create.jsx` and `Edit.jsx`.
- No request, redirect, controller, validation rule, persistence, pricing, tax,
  inventory, recipe, POS, RBAC, tenant isolation, branch isolation, or subscription
  gate changes.

**Story 31.2 Slice C Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Story 31.2 Slice D Completed Scope:**
- Product Edit Branch Pricing and Recipe/Ingredients entry-point UX polish.
- Clearer section headers, helper text, and behavior-accurate empty states.
- Improved action labels for recipe changes and branch override entry points.
- Visual separation between metadata, recipe, and branch pricing sections.
- Frontend-only changes to `Edit.jsx`.
- No branch pricing engine, recipe/BOM, inventory, tax, POS, validation rule,
  persistence, subscription, RBAC, tenant isolation, or branch isolation changes.

**Story 31.2 Slice D Validation Evidence:**
- `npm run build`: passing
- `RouteFeatureGateTest.php`: 25 passed / 63 assertions
- `ProductCatalogTest.php`: 7 passed / 16 assertions
- `ProductPricingTest.php`: 6 passed / 20 assertions
- `CatalogInventoryIsolationTest.php`: 7 passed / 37 assertions

**Architecture Boundary for Initial Planning:**
- prioritize read/write Back Office usability and operational clarity
- keep tenant and branch isolation fail-closed
- preserve append-only audit behavior for critical catalog/inventory mutations
- exclude pilot enablement logistics (Epic 28) unless explicitly re-prioritize## Epic 32: IPOS POS Terminal Sync Diagnostics & Reliability [Closed — Implemented & Locally Validated]
*Validated: May 2026*

Epic 32 brings observability, testability, and supportability to the POS terminal sync pipeline.

**Completed Stories:**
- **32.1: Sandbox Validation Service** dry-run verification for terminal payloads without database mutations.
- **32.2: Submission status lookup endpoints** query details by UUID or sequence number.
- **32.3: Operational Sync Health Dashboard** displaying terminal metrics, pending/failed/posted counters, and sync batches.

**Architecture Boundary & Guidelines:**
- Strictly enforce tenant and branch scopes in all submission status lookups to prevent cross-tenant enumeration (fail-closed, return 404 on invalid ownership).

---

## Epic 33: Late-Sync Auditability & Z-Report Reconciliation [Closed — Implemented & Locally Validated]
*Validated: May 2026*

Epic 33 implements compliance-only safeguards for late-synced offline transactions, protecting finalized Z-report daily journals from mutations.

**Completed Stories:**
- **33.1: Prior Period Adjustments Ledger** recording retroactive entries without altering finalized Z-report numbers.
- **33.2: Late-Sync Reconciliation Service** shunting the reporting basis of late imports to the branch's active open settlement period.
- **33.3: Sync Discrepancy Logging** tracking GCT or sequence discrepancies.
- **33.4: Back-Office Adjustments Dashboard** providing search, filter, and drill-down audit capabilities.

**Architecture Boundary & Guidelines:**
- Do not mutate historically closed, signed, or hashed Z-reports.
- Record retroactive sales in the current open period's sub-ledgers.
- Log reconciliation discrepancy alerts if the terminal's reported local GCT differs from the server's calculated totals.
- Cashier cash-handling workflows (spot audits, cash drops, warnings, and shift dashboard) are separated and deferred to **Epic 40**.


## Epic 34: Enterprise Async Reporting Export [Closed]
*Validated: May 2026*

Epic 34 securely moves heavy compliance exports into a background worker pipeline, preventing HTTP timeouts and memory crashes.

**Status:**
Closed — Implemented and validated.

**Key Deliverables:**
- 34.1 `data_exports` lifecycle tracking and private storage disk mapping.
- 34.2 `ProcessDataExportJob` queued worker implementation.
- 34.3 Streamed CSV generation via `EJournalExportService::exportToFile()` with HMAC-SHA-256 validation.
- 34.4 Secure dashboard and download controls.
- 34.5 `PruneExpiredDataExports` scheduler for 48-hour retention pruning.

---

## Epic 40: Cash Drawer Audit & Manager Shift Reconciliation [Closed]
*Validated: May 2026*

Epic 40 hardens operational cash control, spot audits, and manager shift reconciliation.

**Status:**
Closed — Implemented and validated.

**Key Deliverables:**
- 40.1 Branch-to-tenant hierarchical cash threshold resolution.
- 40.2 High-value cash drop manager verification and cashier self-approval blocking.
- 40.3 Mid-shift spot audit workflow and variance calculation via `SpotAuditService`.
- 40.4 Immutable shift deposit record creation during shift approval.

---

## Epic 35: Recipe Maintenance and Costing Engine [Closed]
*Validated: May 2026*

Epic 35 introduces raw ingredient inventories, Bills of Materials (BOM), and recursive recipe-based stock depletions for F&B operations.

**Completed Stories:**
- 35.1 Unit of Measure (UOM) Conversion Resolver
- 35.2 Interactive Bill of Materials (BOM) & Recipe Editor UI
- 35.3 Automated POS Checkout Recipe Stock Deduction Engine
- 35.4 Weighted Average Cost (WAC) Margin Valuation Ledger

**Architecture Boundary & Guidelines:**
- Run ingredient stock depletions asynchronously via background job queues to protect live POS checkout latency.
- Allow raw stocks to enter negative values with dashboard warnings rather than failing transaction checkouts.

---

## Epic 36: Local Register Sync and Store-Level Coordination [Closed — Implemented & Locally Validated]
*Initialized: May 2026*

Epic 36 enables store-level synchronization, table management, and order distribution between multiple registers on a local network during offline periods (aligning with StoreHub Multiple Register Sync / MRS capability).

**Stories:**
- 36.1 Local Subnet Sync Broker Service [Closed]
- 36.2 Local Pub/Sub and Table/Order State Sharing [Closed]
- 36.3 Local Print Broker for network orders and receipt routing [Closed]

**Architecture Boundary & Guidelines:**
- Enforce strict single-owner locking per table or order to resolve split-brain conflicts.
- Always use Terminal-Bound sequence prefixes to ensure zero receipt number collisions when syncing back to the cloud.
- Support local broker discovery over local Wi-Fi / LAN to allow seamless slave register connections.

---

## Epic 37: Advanced Promotions & Bundling Engine [Proposed]
*Initialized: May 2026*

Epic 37 is proposed to enable complex promotional logic and auto-applied bundling rules in the checkout cart.

**Proposed Stories:**
- 37.1 Declarative Promotion Rule Engine (Buy X Get Y, combo packages)
- 37.2 Automatic Promotion Application Service in Cart
- 37.3 Promotion Usage Reporting & Cost Analysis

**Architecture Boundary & Guidelines:**
- Do not allow manual stacking of promotions unless explicitly configured in rule sets.
- Calculate promotions deterministically on the server side (and locally offline using the identical logic engine).

---

## Epic 38: F&B Table & Bill Manipulation Operations [Proposed]
*Initialized: May 2026*

Epic 38 introduces visual table management and complex checkout bill splitting, moving, and merging.

**Proposed Stories:**
- 38.1 Dining Floor Table Status Visualizer
- 38.2 Split-Bill by Seat/Item Service
- 38.3 Merge/Move Orders between Table IDs

**Architecture Boundary & Guidelines:**
- Table layout configurations must be synced from BackOffice and cached locally.
- Bill split operations must preserve strict accounting balance constraints (sum of parts must exactly equal original total).

---

## Epic 39: Loyalty & Store Credit Ledger [Proposed]
*Initialized: May 2026*

Epic 39 adds loyalty point accumulation and customer store credit wallets (e.g., for handling refunds and returns).

**Proposed Stories:**
- 39.1 Append-Only Store Credit Wallet Ledger
- 39.2 Customer Loyalty Points Accumulation Engine
- 39.3 Store Credit Wallet Payment Integration

**Architecture Boundary & Guidelines:**
- Store credit and loyalty point deductions must be modeled as append-only ledger entries (no mutable updates to wallet balances).
- Enforce strict authentication checks (e.g., manager approval or customer verification) when redeeming store credit or loyalty points.

---

## Epic 41: POS Terminal Production Hardening for Android Tablet [Implemented & Locally Validated / UAT Release Gate Pending / Hardware Validation Deferred]

**Status:** Implemented & Locally Validated / UAT Release Gate Pending / Hardware Validation Deferred
**Decision:** Ready for POS terminal offline UAT and release-gate review. Physical Android tablet, receipt printer, and cash drawer validation must remain deferred until hardware devices are available.

**Proposed Stories:**
- **41.1 Tablet POS Shell Hardening**: Full-screen tablet layout, touch-optimized controls, persistent cashier/session state, and prevention of accidental navigation loss.
- **41.2 POS Terminal PWA Foundation**: manifest.json, service worker, installable app mode, offline fallback, and asset caching.
- **41.3 Terminal State Recovery**: Recover active cart after refresh, recover active shift, show pending sync state, and warn before clearing local state.
- **41.4 Hardware Adapter Abstraction**: Define printer/cash drawer adapter interface (e.g., `PosHardwareAdapter`), implement initial browser/network printer strategy, prepare Android bridge adapter later.
- **41.5 Android Kiosk Readiness**: Document recommended Android settings, lock orientation, full-screen mode, app pinning/kiosk deployment guide.
- **41.6 Production Tablet Validation**: Test checkout, payment, spot audit, cash drop, receipt printing, queue-backed inventory deduction, reconnect/reload behavior.

**Architecture Boundary & Guidelines:**
- Do not make Android-specific logic spread across the POS UI. Instead, use: `POS UI -> Hardware Adapter -> Android/PWA/Browser implementation`.
- PWA offline support should not mean finalizing unsynced official transactions recklessly. It should support safe draft/cart persistence and controlled offline transaction queueing only under existing sync rules.
- Do not split the POS terminal into a separate repo yet.
- Do not rewrite the POS terminal in native Android now.
- Do not discard the existing POS workflows.

**Residual Hardening Follow-Up (2026-07-08):**
- Terminal identity binding for `/pos/terminal/checkout` is implemented and locally validated. The `terminal` middleware now enforces a verified terminal identity at shell entry, and the unsafe first-active-profile fallback has been removed.
- Reference planning lock: `docs/implementation-plans/epic-41-terminal-identity-binding-planning-lock.md`.
- Closure evidence: `docs/validation/epic-41-terminal-identity-binding-closure.md`.

**Pilot Hardening Checkpoint (2026-07-11):**
- Checkpoint commit: `6c2b5d0` (`chore: checkpoint POS terminal hardening`).
- UAT reference: `docs/validation/pos-terminal-offline-uat-2026-07-11.md`.
- Development reference: `_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md`.
- Hardware boundary: no physical receipt printer or cash drawer validation is included in the current checkpoint.

---

## Epic 42: Windows POS Terminal Electron Wrapper (Phase 1.5) [Closed — Implemented & Locally Validated]
*Initiated: July 2026*

Epic 42 introduces a secure hardware kiosk shell for Windows X Lite machines using Electron in "Hosted App" mode. This provides OS-level kiosk lockdown and security without breaking the existing server-side Inertia.js coupling.

**Approved Stories:**
- **42.1 Monorepo Workspace Initialization**: Configure NPM workspaces and create `packages/pos-terminal`. [Closed]
- **42.2 Hosted App Main Process (`main.js`)**: Configure `BrowserWindow` with `kiosk: true`, `devTools: false`, and strict navigation guards. [Closed]
- **42.3 Input and Popup Lockdown**: Block dangerous keyboard shortcuts (F12, F5, Ctrl+R, Alt+F4) and external window requests. [Closed]
- **42.4 Windows Installer Compilation**: Configure `electron-builder` to output a `.exe` installer (unsigned for dev). [Closed]

**Architecture Boundary & Guidelines:**
- **Do not bundle React code:** The Electron shell must point to the live URL (`/pos/terminal/checkout`).
- **Do not decouple Inertia:** All data fetching and routing remains server-rendered.
- **Future Ready:** The shell should be capable of implementing the `PosHardwareAdapter` native bridge (e.g. ESC/POS) in Phase 2.

---

## Epic 43: POS Lock Screen & Employee Timecards [Closed — Implemented & Locally Validated]
*Initiated: July 2026*

Epic 43 delivers labor compliance enforcements and quick lock-screen access for employee clock-in/out PIN operations. It ensures cash drawer accountability by locking POS features unless an active cashier timecard exists.

**Approved Stories:**
- **43.1 Unauthenticated Lock Screen Toggle Endpoint**: Create a `/pos/timecard/toggle` route running outside the primary `auth` middleware stack but with valid branch, tenant, and terminal constraints. [Closed]
- **43.2 Terminal Context Identification Middleware**: Implement `IdentifyTerminalContext` middleware verifying `X-Terminal-ID` headers against `SalesMachineProfile` profiles. [Closed]
- **43.3 Enforced Clocked-In State Guards**: Implement `EnforceClockedIn` middleware protecting cashier-controlled routes (opening shifts, checkout validation/creation, payment split, cash events) unless the actor has an active timecard. [Closed]
- **43.4 Decaying PIN Lockouts**: Track failed PIN attempts via `TimecardSecurityService` to lock registers on repeated failure thresholds. [Closed]
- **43.5 Touch Keypad & Live Status Indicators**: Provide an interactive keypad on the lock screen and a dynamic clock indicator badge in `ShiftHUD.jsx`. [Closed]

**Architecture Boundary & Guidelines:**
- **Derive contexts securely**: Never trust tenant, branch, or user identifiers from the request payload. Resolve them from authenticated terminal metadata.
- **Isolate labor records**: Maintain a clear separation between HR-focused timecards and cash-drawer-focused cashier shifts.
- **Do not block shift closing**: Allow managers to override shift closures even if the cashier is not clocked in.
