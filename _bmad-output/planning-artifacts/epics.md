---
stepsCompleted: [1, 2]
inputDocuments:
  - prd.md
  - architecture.md
  - implementation-readiness-report-2026-05-10.md
  - ux-design-specification.md
---

# IPOS - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for IPOS, decomposing the requirements from the PRD, UX Design Specification, and Architecture requirements into implementable stories. It follows a "Velocity-First, Integrity-as-a-Background-Service" hierarchy.

## Requirements Inventory

### Functional Requirements

- **FR1**: Cashier can search/add products and apply discounts with mandatory reasons.
- **FR2**: System prevents duplicate sales and clearly communicates transaction states (Draft -> Confirmed).
- **FR3**: System enforces referential integrity for Voids and Refunds.
- **FR4**: Cashier can process split payments and capture manual reference numbers for digital methods.
- **FR5**: System decouples POS checkout from QuickBooks availability via a non-blocking queue (Accounting Outbox).
- **FR6**: Accountant can map accounts via a guided wizard and monitor sync status in an exception dashboard.
- **FR7**: System provides human-readable error reasons and supports manual/automatic retry.
- **FR8**: Admin can configure branches, tax categories, and user permissions.
- **FR9**: System generates real-time pulse dashboards and reconciliation-ready reports.

### NonFunctional Requirements

- **NFR1**: Performance: Catalog search <200ms; UI feedback <100ms.
- **NFR2**: Availability: 99.9% target; graceful POS degradation if sync is down.
- **NFR3**: Resilience: Sync queue implements exponential backoff with categorized error logging.
- **NFR4**: Operations: Regular tenant-safe database backups and restore procedures.

### Additional Requirements (Architecture & Integrity)

- **ADR-001**: Fail-Closed Tenancy Enforcement (Implicit Global Scoping).
- **ADR-002**: Atomic Transaction Lifecycle (Sale, Stock, Outbox committed together).
- **ADR-003**: Financial Integrity: `DECIMAL(19,4)` and append-only financial/inventory records.
- **ADR-005**: Idempotency: `client_request_uuid` for checkout; `transaction_uuid` for QBO sync.
- **ADR-006**: Support Assisted Mode: Read-only, masked, and audited platform access.
- **ADR-010**: Observability: Horizon queue monitoring and daily backups.

### UX Design Requirements

- **UX-DR1**: Final Tap Closure: Unmistakable state feedback for transaction completion.
- **UX-DR2**: Zero-Loss Cart: PWA-optimized local persistence for draft orders.
- **UX-DR3**: Tri-Signal Pattern: Every status uses Color + Icon + Label.
- **UX-DR4**: Hybrid POS Modes: Support for both Grid and List entry styles.
- **UX-DR5**: Status Uncertain Trap: Modal/Banner to handle timeouts and avoid duplicate sales.
- **UX-DR6**: Integrity Components: `IPOSButton`, `TransactionStore`, `Reference Guard`.
- **UX-DR7**: Destructive Action Levels: Distinct patterns for Clear (L2) vs. Void (L3).
- **UX-DR8**: Role-Aware ARIA: Accounting-silent announcements for cashiers.
- **UX-DR9**: Mobile Owner Pulse: Read-only, summary-first mobile dashboard.

### FR Coverage Map

- **FR1**: Epic 4 (POS Checkout)
- **FR2**: Epic 4 (POS Checkout)
- **FR3**: Epic 7 (Voids & Refunds)
- **FR4**: Epic 5 (Payments)
- **FR5**: Epic 8 (Outbox Infrastructure)
- **FR6**: Epic 9 (Accounting UX)
- **FR7**: Epic 9 (Accounting UX)
- **FR8**: Epic 1 (Tenancy), Epic 2 (Identity & Admin)
- **FR9**: Epic 10 (Pulse & Reporting)

## Epic List

### Epic 1: SaaS Foundation & Fail-Closed Tenant Isolation
### Epic 2: Identity, RBAC & Admin Configuration
### Epic 3: Product Catalog & Branch Inventory
### Epic 4: POS Checkout, Zero-Loss Cart & Transaction State Machine
### Epic 5: Payment Handling, Split Payments & Reference Guard
### Epic 6: Shift, Cash Drawer & End-of-Day Operations
### Epic 7: Transaction Integrity: Voids, Refunds & Reversals
### Epic 8: Accounting Outbox & QuickBooks Sync Infrastructure
### Epic 9: Guided Accounting Mapping & Sync Exception Recovery
### Epic 10: Operational Pulse, Reporting & Reconciliation Readiness
### Epic 11: Support Assisted Mode, Observability & Production Hardening

---

## Epic 1: SaaS Foundation & Fail-Closed Tenant Isolation

Establish the secure, multi-tenant bedrock of the platform where isolation is "fail-closed" by default.

### Story 1.1: Tenant and Branch Foundation Models
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tenants/branches migrations, UUIDs, tenant_id foreign keys, seeders/factories.

### Story 1.2: Tenant Context Resolution and Fail-Closed Middleware
**Priority**: MVP Must-Have
**Acceptance Criteria**: 403 if no tenant context, X-Tenant-ID header, no default fallback.

### Story 1.3: Automatic Tenant Scoping via Global Query Filters
**Priority**: MVP Must-Have
**Acceptance Criteria**: BelongsToTenant trait, WHERE tenant_id clause auto-injected, fail if missing.

### Story 1.4: Branch Context Resolution and Access Enforcement
**Priority**: MVP Must-Have
**Acceptance Criteria**: X-Branch-ID context, 403 if mismatch, branch user assignment.

### Story 1.5: Tenant and Branch Isolation Feature Tests (Adversarial)
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tenant A cannot access Tenant B, Branch User isolation proof.

### Story 1.6: Append-Only Audit Logging Foundation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Immutable audit_logs table, tenant_id/branch_id/actor_id/before-after metadata.

### Story 1.7: Platform Support Boundary Foundation
**Priority**: MVP Should-Have
**Acceptance Criteria**: Distinct support role, read-only/audited, financial record immutability for support.

---

## Epic 2: Identity, RBAC & Admin Configuration

Define who can do what and configure the business environment (branches, taxes, users).

### Story 2.1: Multi-Tenant Identity Foundation & User Model
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tenant-scoped users, multi-auth support, no global user pool.

### Story 2.2: Standardized RBAC: Role & Permission Schema
**Priority**: MVP Must-Have
**Acceptance Criteria**: Roles (Owner, Manager, Cashier, Accountant, Support), Permission-gating.

### Story 2.3: Tenant-Scoped User Onboarding & Invite Flow
**Priority**: MVP Must-Have
**Acceptance Criteria**: Invitations, Role assignment, Activation lifecycle.

### Story 2.4: Branch Assignment & Multi-Branch Access Control
**Priority**: MVP Must-Have
**Acceptance Criteria**: User-to-Branch pivot, scoped access, tenant-level visibility for owners.

### Story 2.5: Admin Dashboard: User & Role Management
**Priority**: MVP Must-Have
**Acceptance Criteria**: CRUD users/roles, permission assignment, tenant-scoped.

### Story 2.6: Branch Management: Location & Metadata Config
**Priority**: MVP Must-Have
**Acceptance Criteria**: Create/Edit branches, branch settings, status management.

### Story 2.7: Tax Category Configuration & Global Defaults
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tax rates, categories, inclusive/exclusive flag, defaults.

### Story 2.8: Support Assisted Mode: Role & Permission Foundation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Identity for support actors, read-only session foundation.

---

## Epic 3: Product Catalog & Branch Inventory

Enable businesses to manage their products and branch-scoped stock levels.

### Story 3.1: Centralized Product Catalog & SKU Management
**Priority**: MVP Must-Have
**Acceptance Criteria**: Product CRUD, SKU uniqueness per tenant, unit of measure.

### Story 3.2: Product Categories & Hierarchical Organization
**Priority**: MVP Must-Have
**Acceptance Criteria**: Nested categories, product assignment.

### Story 3.3: Global Pricing vs. Branch-Scoped Overrides
**Priority**: MVP Must-Have
**Acceptance Criteria**: Default price, branch-specific price overrides.

### Story 3.4: Product Search API: Indexing & Performance
**Priority**: MVP Must-Have
**Acceptance Criteria**: Fast lookup by SKU/Name/Barcode, indexed tenant queries.

### Story 3.5: Multi-Unit of Measure (UOM) Support
**Priority**: MVP Must-Have
**Acceptance Criteria**: Support for Piece, Kilo, Box, etc., conversion factors.

### Story 3.6: Branch-Scoped Stock Level Persistence
**Priority**: MVP Must-Have
**Acceptance Criteria**: Stock quantity per branch, no cross-branch pooling.

### Story 3.7: Stock Movement: In/Out/Adjustment Logs
**Priority**: MVP Must-Have
**Acceptance Criteria**: Immutable stock logs, Reason codes (Restock, Return, Adjustment).

### Story 3.8: Low-Stock Thresholds & Reorder Alerts
**Priority**: MVP Must-Have
**Acceptance Criteria**: Per-branch reorder levels, alert indicators.

### Story 3.9: Product Image Handling & Secure CDN Delivery
**Priority**: MVP Should-Have
**Acceptance Criteria**: Image upload/resizing, secure URL generation.

### Story 3.10: Branch Stock Initial Load & Bulk Import
**Priority**: MVP Must-Have
**Acceptance Criteria**: CSV import for stock levels, validation report.

### Story 3.11: Inventory UX, State-Icon Mapping & Mobile Views
**Priority**: MVP Must-Have
**Acceptance Criteria**: Inventory dashboard, Tri-Signal stock status.

### Story 3.12: Inventory Isolation & Integrity Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: No cross-branch stock leakage, stock transaction integrity.

---

## Epic 4: POS Checkout, Zero-Loss Cart & Transaction State Machine

The core cashier experience with an "unbreakable" transaction lifecycle.

### Story 4.1: POS State Machine & Transaction ID Lifecycle
**Priority**: MVP Must-Have
**Acceptance Criteria**: Draft -> Confirmed -> Syncing/Synced, UUID generation.

### Story 4.2: Zero-Loss Cart: Local Persistence & PWA Sync
**Priority**: MVP Must-Have
**Acceptance Criteria**: IndexedDB/LocalStorage persistence, recovery on reload.

### Story 4.3: Real-Time Cart Calculation: Taxes & Discounts
**Priority**: MVP Must-Have
**Acceptance Criteria**: Subtotal/Tax/Discount/Total calculation, precision-safe.

### Story 4.4: Discount Reason Guard & Manual Overrides
**Priority**: MVP Must-Have
**Acceptance Criteria**: Mandatory discount reasons, permission gate for high discounts.

### Story 4.5: Atomic Sale Commitment: Record, Stock & Outbox
**Priority**: MVP Must-Have
**Acceptance Criteria**: DB Transaction for Sale + Stock Log + Accounting Outbox.

### Story 4.6: Receipt Generation: Thermal & Digital Print-Ready
**Priority**: MVP Must-Have
**Acceptance Criteria**: Web-print support, mandatory fields (TIN, Tax, Items).

### Story 4.7: Status Uncertain Trap: Timeout & Recovery UX
**Priority**: MVP Must-Have
**Acceptance Criteria**: "Checking status..." modal, duplicate sale prevention on timeout.

### Story 4.8: Hybrid Entry Modes: Grid & List Support
**Priority**: MVP Must-Have
**Acceptance Criteria**: Keyboard/Barcode (List) vs. Touch (Grid) support.

### Story 4.9: POS Navigation, Hotkeys & Accessibility
**Priority**: MVP Must-Have
**Acceptance Criteria**: Rapid item entry hotkeys, ARIA compliance.

### Story 4.10: Role-Aware POS UI & Accounting-Silent Mode
**Priority**: MVP Must-Have
**Acceptance Criteria**: Hide accounting details from cashier, permission-gated tools.

### Story 4.11: Transaction Integrity & State Machine Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: State transition validation, atomicity failure recovery.

---

## Epic 5: Payment Handling, Split Payments & Reference Guard

Flexible checkout with support for multiple payment methods and reconciliation metadata.

### Story 5.1: Dynamic Payment Method Selector
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tenant-scoped methods, inactive filtering, method metadata.

### Story 5.2: Cash Payment Handling and Change Calculation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Change due calculation, tender vs applied tracking.

### Story 5.3: Reference Guard for Digital Payment Capture
**Priority**: MVP Must-Have
**Acceptance Criteria**: Mandatory reference for e-wallets/cards, validation logic.

### Story 5.4: Split-Pay Wizard and Balance Validation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Multiple methods per sale, balance tracking, no underpayment.

### Story 5.5: Payment Metadata and Reconciliation Readiness
**Priority**: MVP Must-Have
**Acceptance Criteria**: Store provider/type/reference, no PII.

### Story 5.6: Payment Validation and Inline Correction
**Priority**: MVP Must-Have
**Acceptance Criteria**: Real-time amount validation, error correction UI.

### Story 5.7: Payment Payload Integration with Checkout API
**Priority**: MVP Must-Have
**Acceptance Criteria**: API schema for payments, backend validation.

### Story 5.8: Payment UX, Tri-Signal Validation, and Accessibility
**Priority**: MVP Must-Have
**Acceptance Criteria**: Success/Error signals, keyboard focus.

### Story 5.9: Payment Integrity and Accounting-Silent POS Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Payment total == Sale total check, no accounting leaks.

---

## Epic 6: Shift, Cash Drawer & End-of-Day Operations

Operational control for storefront shifts and cash reconciliation.

### Story 6.1: Shift Open and Drawer Assignment
**Priority**: MVP Must-Have
**Acceptance Criteria**: Shift record, Opening cash, Cashier assignment.

### Story 6.2: Active Shift Enforcement and Checkout Guard
**Priority**: MVP Must-Have
**Acceptance Criteria**: Block sale without shift, cashier/branch validation.

### Story 6.3: Cash Drawer Events: Cash In / Cash Out
**Priority**: MVP Must-Have
**Acceptance Criteria**: Manual drawer entries (Pay-ins/Payouts), reason codes.

### Story 6.4: Expected Cash Calculation and Shift Summary
**Priority**: MVP Must-Have
**Acceptance Criteria**: Start + Sales + In - Out calculation.

### Story 6.5: Shift Close, Cash Counting, and Over/Short Calculation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Actual vs Expected, Variance recording.

### Story 6.6: Manager Review, Variance Flagging, and Shift Approval
**Priority**: MVP Must-Have
**Acceptance Criteria**: Manager sign-off on variances, audit trail.

### Story 6.7: End-of-Day Branch Summary / Z-Read Operational Summary
**Priority**: MVP Must-Have
**Acceptance Criteria**: Aggregate branch totals, print-ready EOD report.

### Story 6.8: Shift and Cash Drawer Audit Logs
**Priority**: MVP Must-Have
**Acceptance Criteria**: Immutable logs for all drawer actions.

### Story 6.9: Shift UX, Tri-Signal States, and Accessibility
**Priority**: MVP Must-Have
**Acceptance Criteria**: Status icons, simple workflow.

### Story 6.10: Shift Isolation and Reconciliation Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: No cross-cashier shift access, reconciliation accuracy.

---

## Epic 7: Transaction Integrity: Voids, Refunds & Reversals

Audited corrections through immutable reversal records.

### Story 7.1: Permission-Gated Reversal Actions
**Priority**: MVP Must-Have
**Acceptance Criteria**: Manager/Owner pin/permission required for voids/refunds.

### Story 7.2: Confirmed Sale Void: Full Reversal Flow
**Priority**: MVP Must-Have
**Acceptance Criteria**: Sale remains immutable, void record created, reversal in outbox.

### Story 7.3: Refund Flow: Full and Partial Refunds
**Priority**: MVP Must-Have
**Acceptance Criteria**: Partial refund support, linked to original sale.

### Story 7.4: Inventory Reversal and Restocking Strategy
**Priority**: MVP Must-Have
**Acceptance Criteria**: Auto-restock option, stock movement log.

### Story 7.5: Payment Reversal Tracking and Refund Method Capture
**Priority**: MVP Must-Have
**Acceptance Criteria**: Track how refund was paid (Cash/Original).

### Story 7.6: Reversal Reason Codes and Audit Logs
**Priority**: MVP Must-Have
**Acceptance Criteria**: Mandatory reasons, immutable logs.

### Story 7.7: Impact on Shift and EOD Summaries
**Priority**: MVP Must-Have
**Acceptance Criteria**: Voids/Refunds correctly net out from shift totals.

### Story 7.8: Transaction Terminology and UX Guardrails
**Priority**: MVP Must-Have
**Acceptance Criteria**: Clear UI distinction between Void and Refund.

### Story 7.9: Reversal Data Model and API Contract
**Priority**: MVP Must-Have
**Acceptance Criteria**: API schema for reversals, QBO mapping ready.

### Story 7.10: Transaction Integrity and Reversal Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Integrity checks, no double refunds, isolation.

---

## Epic 8: Accounting Outbox & QuickBooks Sync Infrastructure

Decoupled background sync engine with idempotent job handling.

### Story 8.1: Accounting Outbox Table and State Machine
**Priority**: MVP Must-Have
**Acceptance Criteria**: Statuses (Pending, Processing, Synced, Failed, etc.), Atomic creation.

### Story 8.2: Outbox Trigger from Sales, Voids, and Refunds
**Priority**: MVP Must-Have
**Acceptance Criteria**: Auto-queue on confirmed action, tenant isolation.

### Story 8.3: Accounting Sync Attempt Logs
**Priority**: MVP Must-Have
**Acceptance Criteria**: Log every try, error messages, timestamps.

### Story 8.4: Tenant-Scoped Sync Job Orchestration
**Priority**: MVP Must-Have
**Acceptance Criteria**: Isolated workers, tenant context hydration.

### Story 8.5: QuickBooks Connection Guard and Token Handling
**Priority**: MVP Must-Have
**Acceptance Criteria**: Token encryption, refresh logic, connection status.

### Story 8.6: Tenant-Scoped QuickBooks Payload Builder
**Priority**: MVP Must-Have
**Acceptance Criteria**: Build QBO JSON from POS data, mapping application.

### Story 8.7: Idempotency and External Reference Persistence
**Priority**: MVP Must-Have
**Acceptance Criteria**: Store QBO ID, prevent duplicate syncs.

### Story 8.8: Retry Logic and Error Classification Engine
**Priority**: MVP Must-Have
**Acceptance Criteria**: Backoff, classification (Auth vs Mapping vs Network).

### Story 8.9: Non-Blocking Sync Architecture
**Priority**: MVP Must-Have
**Acceptance Criteria**: POS doesn't wait for QBO.

### Story 8.10: Outbox Status API Foundation
**Priority**: MVP Must-Have
**Acceptance Criteria**: API for dashboard to query sync status.

### Story 8.11: Queue Monitoring and Horizon Foundation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Worker dashboard, failure alerts.

### Story 8.12: Accounting Outbox Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Sync failure simulation, recovery tests.

---

## Epic 9: Guided Accounting Mapping & Sync Exception Recovery

Accountant tools for mapping rules and sync error resolution.

### Story 9.1: QuickBooks Connection and Lifecycle Management
**Priority**: MVP Must-Have
**Acceptance Criteria**: Connection UI, company name/realm display, disconnect audited.

### Story 9.2: Guided Accounting Mapping Wizard
**Priority**: MVP Must-Have
**Acceptance Criteria**: Map Sales/Tax/Payments/Discounts to QBO accounts.

### Story 9.3: Default Mapping Templates and Quick-Start
**Priority**: MVP Should-Have
**Acceptance Criteria**: Standard templates, one-click start.

### Story 9.4: Mapping Health Checklist and Live Sync Readiness
**Priority**: MVP Must-Have
**Acceptance Criteria**: Check connection/mappings, block sync if red.

### Story 9.5: Test Sync and Mapping Validation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Dry-run sync, validation report.

### Story 9.6: Sync Exception Dashboard
**Priority**: MVP Must-Have
**Acceptance Criteria**: List failures, reason, actions.

### Story 9.7: Exception Resolution: Retry, Resolve, Ignore, and Archive
**Priority**: MVP Must-Have
**Acceptance Criteria**: Manual status updates, audited actions.

### Story 9.8: Role-Based Visibility and Accountant Permissions
**Priority**: MVP Must-Have
**Acceptance Criteria**: 403 for cashiers, accountant/admin only.

### Story 9.9: Sync Exception UX and Tri-Signal Feedback
**Priority**: MVP Must-Have
**Acceptance Criteria**: Health signals (Green/Yellow/Red).

### Story 9.10: Sync Exception, Mapping, and Accounting-Silent Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Boundary tests, mapping logic validation.

---

## Epic 10: Operational Pulse, Reporting & Reconciliation Readiness

Visibility for owners across desktop and mobile pulse dashboards.

### Story 10.1: Owner Operational Pulse Dashboard
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tenant-wide summary, Gross/Net, Payment Mix, Sync Health.

### Story 10.2: Branch Manager Operational Dashboard
**Priority**: MVP Must-Have
**Acceptance Criteria**: Branch summary, Over/Short, Low Stock, Assigned only.

### Story 10.3: Mobile Owner Pulse Read-Only Summary
**Priority**: MVP Must-Have (Read-Only)
**Acceptance Criteria**: High-level sales on mobile, read-only.

### Story 10.4: Sales, Net Sales, and Payment Mix Reporting
**Priority**: MVP Must-Have
**Acceptance Criteria**: Detailed reporting, branch/date/payment filters.

### Story 10.5: Branch Comparison and Top Performance Metrics
**Priority**: MVP Must-Have
**Acceptance Criteria**: Branch leaderboard, ranked by Net Sales.

### Story 10.6: Void and Refund Operational Summary
**Priority**: MVP Must-Have
**Acceptance Criteria**: Audit report for reversals, reason analysis.

### Story 10.7: Reconciliation-Ready Payment and Reference Reports
**Priority**: MVP Must-Have
**Acceptance Criteria**: Reference number list, CSV export.

### Story 10.8: Inventory Health: Low Stock and Movement Alerts
**Priority**: MVP Must-Have
**Acceptance Criteria**: Critical stock alerts, movement log.

### Story 10.9: Report Export and Print-Ready Output
**Priority**: MVP Must-Have (Payment CSV)
**Acceptance Criteria**: CSV/Print support.

### Story 10.10: Reporting Freshness and Performance Guardrails
**Priority**: MVP Must-Have
**Acceptance Criteria**: Indexed queries, last-updated timestamp.

### Story 10.11: Role-Based Dashboard Visibility and Isolation
**Priority**: MVP Must-Have
**Acceptance Criteria**: Strict API 403 enforcement for unauthorized roles.

### Story 10.12: Reporting Isolation and Accounting-Silent Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Leakage tests, cashier block verification.

---

## Epic 11: Support Assisted Mode, Observability & Production Hardening

Final platform hardening, support boundaries, and recovery automation.

### Story 11.1: Support Assisted Mode: Identity and Session Boundary
**Priority**: MVP Must-Have
**Acceptance Criteria**: Time-boxed session, read-only by default, audited.

### Story 11.2: Masked Support Views and Content Protection
**Priority**: MVP Must-Have
**Acceptance Criteria**: API-level masking, PII protection.

### Story 11.3: Support Access and Action Audit Logs
**Priority**: MVP Must-Have
**Acceptance Criteria**: Immutable session logs.

### Story 11.4: Queue, Job, and Sync Health Monitoring
**Priority**: MVP Must-Have
**Acceptance Criteria**: Horizon dashboard, failed job alerts.

### Story 11.5: Application Logging and Centralized Error Tracking
**Priority**: MVP Must-Have
**Acceptance Criteria**: Sentry integration, request_id tracing.

### Story 11.6: Automated Backups and Tenant-Safe Recovery Posture
**Priority**: MVP Must-Have
**Acceptance Criteria**: Daily offsite backups, RPO 24h.

### Story 11.7: Restore Drill Documentation and Recovery Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Tested guide, staging restore success.

### Story 11.8: SSL/TLS, Secret Handling, and Environment Hardening
**Priority**: MVP Must-Have
**Acceptance Criteria**: SSL enforcement, hardened VPS config.

### Story 11.9: Final MVP Validation and Security Isolation Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Isolation regression, boundary tests.

### Story 11.10: Observability and Recovery Feature Tests
**Priority**: MVP Must-Have
**Acceptance Criteria**: Alert/Log/Backup verification.

### Story 11.11: Deployment Checklist and Pilot Readiness Gate
**Priority**: Must-Have
**Acceptance Criteria**: Go/No-Go checklist, critical flow validation.
