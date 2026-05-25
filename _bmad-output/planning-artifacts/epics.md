# Project Epics & Strategic Roadmap
> [!NOTE]
> This planning artifact represents the synchronized tracking ledger of all architectural Epics in the IPOS application. It has been perfectly aligned with the authoritative [validated-implementation-roadmap.md](../docs/roadmap/validated-implementation-roadmap.md) to serve as a high-fidelity state tracking ledger.

## 1. Active Epic Status Summary

| Epic | Category | Description | Status |
| :--- | :--- | :--- | :--- |
| **Epic 1** | Foundation | SaaS Foundation & Fail-Closed Tenant Isolation | **[Closed]** |
| **Epic 2** | Core Config | Identity, RBAC & Admin Configuration | **[Closed]** |
| **Epic 3** | Core Config | Product Catalog & Branch Inventory Foundation | **[Closed]** |
| **Epic 4** | POS | POS Checkout, Zero-Loss Cart & Transaction Integrity | **[Closed]** |
| **Epic 5** | POS | Payment Handling, Split Payments & Reference Guard | **[Closed]** |
| **Epic 6** | Inventory | Inventory Deduction and Stock Integrity | **[Closed]** |
| **Epic 7** | POS | Voids, Refunds & Controlled Reversals | **[Closed]** |
| **Epic 8** | Accounting | Accounting Outbox, QuickBooks Adapter & Onboarding | **[Closed]** |
| **Epic 9** | POS | Settlement and Reconciliation Foundation | **[Closed]** |
| **Epic 10** | Accounting | Settlement Export and Reporting | **[Closed]** |
| **Epic 11** | Pulse | Operational Pulse, Dashboards & Business Reporting | **[Closed]** |
| **Epic 12** | POS | Shift, Cash Drawer & End-of-Day Operations | **[Closed]** |
| **Epic 13** | Hardening | Support Assisted Mode & Production Hardening | **[Closed]** |
| **Epic 14** | Compliance | BIR Tax Reporting & Compliance Exports | **[Closed - Formal Review Pending]** |
| **Epic 15** | Back Office | Sales & Transaction History Back Office | **[Closed]** |
| **Epic 16** | Inventory | Inventory Stocktake & Stock Adjustment UI | **[Closed]** |
| **Epic 17** | Auditor | Cashier Accountability & Shift Report Export | **[Closed]** |
| **Epic 20** | Procurement | Supplier & Purchase Receiving | **[Closed]** |
| **Epic 22** | POS Config | Visual POS Layout Builder & Enterprise Sync | **[Closed]** |
| **Epic 23** | Inventory | Recipe Management & Bill of Materials (BOM) | **[Closed]** |
| **Epic 25** | Core Config | Subscription-Based Feature Gating | **[Closed]** |
| **Epic 26** | Supply Chain | Advanced Supply Chain, Expiry Tracking & Automated Procurement | **[Closed]** |
| **Epic 28** | Infrastructure | Offline-Resilient POS Architecture | **[Implemented & Locally Validated - Controlled Early Partner Pilot Ready / External Review Deferred]** |
| **Epic 29** | Platform Admin | Tenant Provisioning & Compliance Onboarding | **[In Progress]** |
| **Epic 21** | BI | Branch Comparison Business Intelligence | **[Planned]** |

---

## 2. Epic Detail Ledger

### Epic 1: SaaS Foundation & Fail-Closed Tenant Isolation [Closed]
- [x] 1.1 Tenant Model & Multi-Tenant Migration Blueprint [Completed]
- [x] 1.2 Tenant Context & Request Resolver Middleware [Completed]
- [x] 1.3 Scope Verification Engine (Fail-Closed Dynamic Global Scopes) [Completed]
- [x] 1.4 Tenant Isolation Test Suite [Completed]

### Epic 2: Identity, RBAC & Admin Configuration [Closed]
- [x] 2.1 User Management & Multi-Tenant Registration Flow [Completed]
- [x] 2.2 Role-Based Access Control (RBAC) System [Completed]
- [x] 2.3 System Permissions Dictionary & Seeding [Completed]
- [x] 2.4 User Branch Affiliation [Completed]

### Epic 3: Product Catalog & Branch Inventory Foundation [Closed]
- [x] 3.1 Centralized Product Catalog & SKU Management [Completed]
- [x] 3.2 Product Categories & Organization [Completed]
- [x] 3.3 Global Pricing vs. Branch-Scoped Overrides [Completed]
- [x] 3.4 Product Search API: Indexing & Performance [Completed]
- [x] 3.5 Multi-Unit of Measure (UOM) Support [Completed]
- [x] 3.6 Branch-Scoped Stock Level Persistence [Completed]
- [x] 3.7 Stock Movement: In/Out/Adjustment Logs [Completed]
- [x] 3.8 Low-Stock Thresholds & Reorder Alerts [Completed]
- [x] 3.9 Product Catalog CRUD & Back-Office Management UI [Completed]

### Epic 4: POS Checkout, Zero-Loss Cart & Transaction Integrity [Closed]
- [x] 4.1 Zero-Loss Local Cart Sync State [Completed]
- [x] 4.2 Checkout Calculation Engine [Completed]
- [x] 4.3 Pessimistic Concurrency Lock at Checkout [Completed]

### Epic 5: Payment Handling, Split Payments & Reference Guard [Closed]
- [x] 5.1 Payment Recording Service & DB Schemas [Completed]
- [x] 5.2 Split Payments & Dynamic Calculations [Completed]
- [x] 5.3 M-PESA & External Reference Unique Guard [Completed]

### Epic 6: Inventory Deduction and Stock Integrity [Closed]
- [x] 6.1 Real-time POS Inventory Deductions [Completed]
- [x] 6.2 Negative Stock Prevention Control [Completed]
- [x] 6.3 DB-level Integrity Check [Completed]

### Epic 7: Voids, Refunds & Controlled Reversals [Closed]
- [x] 7.1 Double-refund & Double-void Prevention [Completed]
- [x] 7.2 Manager Approval RBAC controls for Reversals [Completed]
- [x] 7.3 Reference Integrity & Voided Log tracking [Completed]

### Epic 8: Accounting Outbox, QuickBooks Adapter & Onboarding [Closed]
- [x] 8.1 Outbox Sync State Machine [Completed]
- [x] 8.2 Outbox Immutability Controls [Completed]
- [x] 8.3 QBO API Payload Builder [Completed]

### Epic 9: Settlement and Reconciliation Foundation [Closed]
- [x] 9.1 Shift Reconciliation & End-of-Day Drawer Settlement [Completed]
- [x] 9.2 Variance Tracking & Auditing [Completed]

### Epic 10: Settlement Export and Reporting [Closed]
- [x] 10.1 Multi-Tenant Shift Report Aggregation [Completed]
- [x] 10.2 CSV Settlement Exporter [Completed]

### Epic 11: Operational Pulse, Dashboards & Business Reporting [Closed]
- [x] 11.1 Real-time Branch Sales Analytics [Completed]
- [x] 11.2 Sales Margin Reports [Completed]

### Epic 12: Shift, Cash Drawer & End-of-Day Operations [Closed]
- [x] 12.1 Cash Drawer Reconciliation & EOD Drawer [Completed]
- [x] 12.2 Variance Auditing [Completed]
- [x] 12.3 Actual vs Expected Cash Reconciliation [Completed]
- [x] 12.4 Z-Read Operational Reports [Completed]
- [x] 12.5 Shift-Settlement Lock Coupling [Completed]
- [x] 12.6 Blind Closing & Variance Calculation [Completed]
- [x] 12.7 Manager Review & Approval Flow [Completed]
- [x] 12.8 Shift Summary UI & Dashboard Integration [Completed]

### Epic 13: Support Assisted Mode & Production Hardening [Closed]
- [x] 13.1 Support Assisted Mode Scope Lock and Identity Model [Completed]
- [x] 13.2 Observability & Centralized Logging [Completed]
- [x] 13.3 Production Security Hardening [Completed]

### Epic 14: BIR Tax Reporting & Compliance Exports [Closed - Formal Review Pending]
- [x] 14.1 BIR Compliance Scope Lock and PH Tax Matrix [Completed]
- [x] 14.2 Tax Breakdown Source-of-Truth Hardening [Completed]
- [x] 14.3 Sales Tax Reporting Query Service [Completed]
- [x] 14.4 BIR Tax Reporting Back-Office UI [Completed]
- [x] 14.5 Compliance Export Package [Completed]

> [!IMPORTANT]
> **EOPT/BIR Accreditation Hardening Extension**:
> - **[x] Step 1: BIR Compliance Schema Foundation** [Completed]
> - **[x] Step 2: Sequential Numbering & Reprint Gating** [Completed]
> - **[x] Step 3: Z-Read Shift-Lock Engine & GCT State Machine** [Completed]
>   
>   **Completed Scope**:
>   - Created `ZReadGenerationService` for isolated transaction calculations.
>   - Added atomic Z-read generation database transactions (`DB::transaction`).
>   - Locked `SalesMachineProfile` during GCT updates using pessimistic locking (`lockForUpdate`).
>   - Generated immutable `register_z_reads` ledger entries.
>   - Incremented `z_read_counter` on successful Z-read generation.
>   - Updated `grand_cumulative_total` atomically.
>   - Associated finalized sales with `register_z_read_id`.
>   - Blocked mutation/deletion of Z-read-covered sales via model boot events.
>   - Prevented duplicate inclusion of already finalized sales.
>   - Added database rollback protection test coverage.
>   - Added void/refund aggregation test coverage.
>
>   **Validation Evidence**:
>   - Test Suite: `tests/Feature/Compliance/RegisterZReadLedgerTest.php` (215 tests / 753 assertions passing)
>
>   **Governance Note**:
>   This validates the Z-read/GCT state machine slice only. Broader BIR/EOPT accreditation readiness remains dependent on completion and validation of training mode isolation, e-journal export, final report layouts, official machine registration data, and formal BIR/accounting review.
>
> - **[x] Step 4: Training Mode Isolation** [Completed]
> - **[x] Step 5: Electronic Journal Exporter & Internal Hashes** [Completed]
>
>   **Current State**:
>   The compliance extension is implemented and locally validated. Formal BIR/accounting review remains pending before any accreditation claim.

### Epic 15: Sales & Transaction History Back Office [Closed]
- [x] 15.1 Sales History Scope Lock and Access Rules [Completed]
- [x] 15.2 Transaction History Query Foundation [Completed]
- [x] 15.3 Sales & Transaction History Index UI [Completed]
- [x] 15.4 Transaction Detail Timeline and Financial Breakdown [Completed]
- [x] 15.5 Transaction Export and Audit Trail [Completed]
- [x] 15.6 Receipt Reprint and Evidence Linking [Completed]

### Epic 16: Inventory Stocktake & Stock Adjustment UI [Closed]
- [x] 16.1 Stocktake Scope Lock and Schema [Completed]
- [x] 16.2 Bulk Stock Adjustment Service [Completed]

### Epic 17: Cashier Accountability & Shift Report Export [Closed]
- [x] 17.1 Cashier Accountability Scope Lock [Completed]
- [x] 17.2 Shift Accountability Backend Foundation [Completed]
- [x] 17.3 Cashier Accountability UI [Completed]
- [x] 17.4 Cashier Accountability Export Integration [Completed]
- [x] 17.5 RBAC, Audit, and Historical Integrity Hardening [Completed]

### Epic 20: Supplier & Purchase Receiving [Closed]
- [x] 20.1 Supplier & Purchase Foundation Scope Lock [Completed]
- [x] 20.2 Supplier Directory Foundation [Completed]
- [x] 20.3 Purchase Order Backend & Lifecycle [Completed]
- [x] 20.4 Purchase Receiving Draft Workspace [Completed]
- [x] 20.5 Atomic Receiving Posting & WAC Valuation [Completed]
- [x] 20.6 Procurement UI & CSV Security Hardening [Completed]
- [x] 20.7 RBAC, Audit, and Closure Hardening [Completed]

### Epic 22: Visual POS Layout Builder & Enterprise Sync [Closed]
- [x] 22.1 Schema & Layout Foundation (Slice A) [Completed]
- [x] 22.2 Admin Layout CRUD + Validation (Slice B) [Completed]
- [x] 22.3 Terminal Layout Fetch & Fallback Rendering (Slice C) [Completed]

### Epic 23: Recipe Management & Bill of Materials (BOM) [Closed]
- [x] 23.1 Recipe Schema & Multi-Tenant Model Foundation [Completed]
- [x] 23.2 Back-Office Recipe Builder UI [Completed]
- [x] 23.3 Ingredient-Aware Inventory Deduction Service [Completed]
- [x] 23.4 Unit Conversion & Ingredient Normalization [Completed]
- [x] 23.5 Recipe Costing & COGS Analysis Reporting [Completed]

### Epic 25: Subscription-Based Feature Gating [Closed]
- [x] 25.1 Feature Definition & Model Gating [Completed]
- [x] 25.2 Multi-Tenant Gating Middleware [Completed]

### Epic 26: Advanced Supply Chain, Expiry Tracking & Automated Procurement [Closed]
- [x] 26.1 Expiry Lot tracking and POS FEFO integration [Completed]
- [x] 26.2 PAR-level replenishment automation triggers [Completed]
- [x] 26.3 WAC Supplier Returns and RMA state integration [Completed]
- [x] 26.4 3-Way AP Document Matching and invoicing [Completed]
- [x] 26.5 Multi-branch Split POs & Inter-Branch Stock Transfers (IBTs) [Completed]

### Epic 28: Offline-Resilient POS Architecture [Implemented & Locally Validated - Controlled Early Partner Pilot Ready / External Review Deferred]
- [x] 28.1 POS Cache Bootstrap API Endpoint [Completed]
- [x] 28.2 Client IndexedDB Caching Services [Completed]
- [x] 28.3 Connectivity State & Checkout Guard UI [Completed]
- [x] 28.4 Cart Draft Persistence & Restore [Completed]
- [x] 28.5 Settings, Terminal Sequence Registry & Admin Controls [Completed]
- [x] 28.6 Offline Import Schema & Reconciliation Foundation [Completed]
- [x] 28.7 Offline Sync Validation & Reconciliation Service [Completed]
- [x] 28.8 Offline Import Server Recalculation [Completed]
- [x] 28.9 Offline Import Posting Readiness & Admin Conflict Review [Completed]
- [x] 28.10 Offline Import Official Posting & Reconciliation [Completed]
- [x] 28.11 POS Offline Transaction Queue & Sync UX [Completed]

### Epic 29: Platform Tenant Provisioning & Compliance Onboarding [In Progress]
- [x] 29.1 Platform Tenant Provisioning Foundation [Implemented & Locally Validated]
- [ ] 29.1A Feature Gate Enforcement Coverage Hardening [Planned]
- [ ] 29.2 Initial Branch & Owner Admin Setup [Planned]
- [ ] 29.3 Sales Machine Profile / Terminal Registration [Planned]
- [ ] 29.4 Compliance Profile Setup [Planned]
- [ ] 29.5 Controlled Offline Sales Pilot Provisioning [Planned]

---

## 3. Future Strategic Roadmap

### Market Readiness Inventory Operations Track (G-070) [In Progress]
- [x] Slice A Planning Lock: Unified Inventory & Reporting Hub [Completed]
- [x] Slice B Implementation: Read-Only Inventory Hub Surface [Implemented & Locally Validated]
- [x] Slice C Planning/Implementation: Print-Friendly Stocktake & Inventory Report Views [Implemented & Locally Validated]
- [ ] Slice D Planning Lock: Low-Stock and Reorder Read-Only Dashboard [Next]

### Epic 21: Branch Comparison Business Intelligence [Planned]
- [ ] 21.1 Cross-Branch Financial Aggregations [Planned]
- [ ] 21.2 Performance Metric Visualization Dashboard [Planned]
