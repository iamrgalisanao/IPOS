# IPOS - Validated Implementation Roadmap

## Overview
This document represents the **Actual Execution Truth** of the IPOS project. It has been reconciled against validated implementation history and project-gate closures.

---

## Epic Summary

| Epic | Description | Status |
| :--- | :--- | :--- |
| **Epic 1** | SaaS Foundation & Fail-Closed Tenant Isolation | **[Closed]** |
| **Epic 2** | Identity, RBAC & Admin Configuration | **[Closed]** |
| **Epic 3** | Product Catalog & Branch Inventory Foundation | **[Closed*]** |
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
| **Epic 15** | Sales & Transaction History Back Office | **In Progress** |
| **Epic 22** | Visual POS Layout Builder & Enterprise Sync | **In Progress** |

*\*Epic 3 catalog core is closed; advanced stock UX/CDN are pending enhancements.*

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

Implementation plan: [epic-14-implementation-plan.md](./epic-14-implementation-plan.md)

## Epic 15: Sales & Transaction History Back Office [In Progress]
- 15.1 Sales History Scope Lock and Access Rules [Implemented]
- 15.2 Transaction History Query Foundation [Implemented]
- 15.3 Sales & Transaction History Index UI [Implemented - Accepted Early]
- 15.4 Transaction Detail Timeline and Financial Breakdown [Implemented - Accepted Early]
- 15.5 Transaction Export and Audit Trail [Implemented - Validated]
- 15.6 Receipt Reprint and Evidence Linking [Implemented - Accepted Early]

## Epic 22: Visual POS Layout Builder & Enterprise Sync [In Progress]
- [x] 22.1 Schema & Layout Foundation (Slice A) [CLOSED]
- [x] 22.2 Admin Layout CRUD + Validation (Slice B) [CLOSED]
- [x] 22.3 Terminal Layout Fetch & Fallback Rendering (Slice C) [CLOSED]
- [ ] 22.4 Visual Sandbox Editor (Slice D)
- [ ] 22.5 Publish / Branch Deployment / Sync (Slice E)
- [ ] 22.6 Governance / Audit / Rollout Hardening (Slice F)
