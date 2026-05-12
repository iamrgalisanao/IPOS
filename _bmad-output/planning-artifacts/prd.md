---
stepsCompleted:
  - step-01-init
  - step-02-discovery
  - step-02b-vision
  - step-02c-executive-summary
  - step-03-success
  - step-04-journeys
  - step-05-domain
  - step-06-innovation
  - step-07-project-type
  - step-08-scoping
  - step-09-functional
  - step-10-nonfunctional
  - step-11-polish
releaseMode: phased
inputDocuments:
  - "User-provided Project Context (Conversational Input)"
  - "Teamsolo Response to BMAD Agent Team (Conversational Input)"
  - "Adopted PRD Hierarchy & Revised Vision (Conversational Input)"
workflowType: 'prd'
classification:
  projectType: 'SaaS B2B'
  domain: 'Fintech'
  complexity: 'High'
  projectContext: 'brownfield'
documentCounts:
  briefCount: 0
  researchCount: 0
  brainstormingCount: 0
  projectDocsCount: 1
---

# Product Requirements Document - IPOS

## Executive Summary

IPOS is an online-first cloud POS platform designed for Philippine SMBs that need fast frontline sales processing, real-time operational visibility, and accounting-ready financial records. The product follows a **“Velocity-First, Integrity-as-a-Background-Service”** hierarchy: cashier workflows must remain fast and uninterrupted, while accounting synchronization, payment reconciliation, and audit logging run safely in the background. By connecting POS transactions to accounting workflows and reconciliation records, IPOS reduces duplicate manual encoding, improves owner visibility, and creates a traceable financial trail from counter sale to back-office review.

### Core Differentiator: "Accounting Confidence"
The assurance that every completed sale is captured safely, traceable through its lifecycle, recoverable when integration issues occur, and ready for accounting review. 
> **Fast at the counter. Clear for the owner. Reliable in the books.**

### Key Innovations (The IPOS Trust Engine)
1.  **Zero-Loss Cart**: A PWA-optimized persistent cart experience that protects work-in-progress order integrity during network flickers or submission failures.
2.  **Accounting Outbox**: A non-blocking sync architecture that decouples POS checkout from QuickBooks API availability via an idempotent background queue.
3.  **Reconciliation-First Sync**: A payment-aware sync approach that prepares digital payments (GCash, Maya, Bank Transfer) for settlement matching and audit.

## Project Classification

- **Project Type**: SaaS B2B Platform with optimized PWA POS.
- **Domain**: Fintech (Retail POS, Accounting Sync, Payment Reconciliation).
- **Complexity**: High (Accounting-safe, Audit-heavy, Integrated).
- **Strategy**: Phase 1 focuses on the **"Trust Engine"** (Foreground Velocity + Background Integrity).

## Success Criteria

### Measurable Outcomes
*   **Maria (Cashier)**: 0% cart loss in tested recovery scenarios (refresh, flicker, failed submit).
*   **The Owner**: Dashboard reflects saved transactions within 60 seconds; payment breakdown visible in <3 clicks.
*   **The Accountant**: 99%+ of valid transactions eventually sync to QuickBooks via automatic or manual retry.
*   **Technical Integrity**: 100% of transactions are stored as immutable base records; 0 duplicate QuickBooks records created during retries.

### MVP Launch Readiness Checklist
*   [ ] Cashier can complete a sale end-to-end and generate a receipt.
*   [ ] Transaction is saved immutably with inventory movement recorded.
*   [ ] Owner dashboard reflects the sale and payment breakdown accurately.
*   [ ] QuickBooks sync is configured via a guided setup wizard.
*   [ ] Failed syncs are visible in an exception dashboard and can be manually retried.
*   [ ] No known "Critical" issues causing data loss or duplicate completed sales.

## Product Scope

### Phase 1: MVP (The Trust Engine)
*   **POS Velocity**: Fast search/add, split payments, and persistent cart.
*   **Operational Pulse**: Real-time owner dashboards and shift management.
*   **Integrity**: QuickBooks Outbox, Guided Mapping, and Reconciliation readiness.
*   **Compliance**: Immutable logs, Tax/Discount breakdown, and Tenant isolation.

### Phase 2: Growth (Advanced Reconciliation)
*   Automated settlement matching, direct e-wallet API integration, and MDR/Fee automation.

### Phase 3: Vision (Enterprise & Scale)
*   Multi-branch orchestration, full offline selling, native mobile apps, and formal BIR certification.

## User Journeys

### 1. Maria’s High-Velocity Shift
*   Maria processes a busy lunch rush. The UI is snappy; modifiers and discounts apply instantly. She handles a GCash payment, enters the reference, and hits confirm. The sale completes instantly in the foreground while sync begins in the background.

### 2. Maria’s Flicker Recovery
*   A complex order is in progress when the shop's Wi-Fi drops. The **Zero-Loss Cart** preserves the items locally. Maria hits "Complete Sale" when connection returns; the system confirms the cart is ready for submission, and she finishes the sale without re-entry.

### 3. Accountant’s Sync & Reconciliation
*   Elena reviews the **Sync Exception Dashboard**. She identifies a mapping error, fixes it in the **Guided Mapping Wizard**, and retries the sync. She then generates a report matching POS records to actual bank settlements.

## Domain-Specific Requirements

### 1. Sales & Financial Rules
*   **Immutability**: Completed transactions cannot be edited/deleted; corrections require audited Voids/Refunds.
*   **Tax Handling**: Native support for VATable, Exempt, Zero-Rated, and Non-VAT sales.
*   **Senior Citizen / PWD**: Workflow support for required discounts and document attribution.
*   **Monetary Precision**: Decimal-safe arithmetic (no floats) with consistent rounding across POS and Sync.

### 2. Inventory & Catalog
*   **Tenant Catalog**: Product master is tenant-level; Branch inventory and reorder levels are branch-scoped.
*   **Movement Logs**: Every stock change is linked to a user, timestamp, and source transaction.

## SaaS Architecture & Security

### 1. Tenant Isolation (Fail-Closed)
*   Shared-database model with strict scoping. Requests without a resolved `tenant_id` and `branch_id` result in immediate denial.
*   Encryption: Secrets (QuickBooks tokens) are encrypted at rest; all traffic uses TLS 1.2+ (TLS 1.3 preferred).

### 2. RBAC & Support Access
*   **Permission Groups**: POS, Branch Manager, Owner, and Accountant.
*   **Assisted Mode**: Support reps have read-only, masked visibility of integration health. No access to sensitive financial totals. 100% of support access is audited.

### 3. Data Integrity
*   **Idempotent Sync**: Every transaction uses a stable key to prevent duplicate records in QuickBooks during retries.
*   **Audit Trail**: 100% of sensitive actions (Voids, Settings, Admin access) generate append-only logs with before/after metadata.

## Functional Requirements (Phase 1)

### 1. POS & Checkout
*   **FR1**: Cashier can search/add products and apply discounts with mandatory reasons.
*   **FR2**: System prevents duplicate sales and clearly communicates transaction states (Draft -> Confirmed).
*   **FR3**: System enforces referential integrity for Voids and Refunds.
*   **FR4**: Cashier can process split payments and capture manual reference numbers for digital methods.

### 2. Sync Engine (Outbox)
*   **FR5**: System decouples POS checkout from QuickBooks availability via a non-blocking queue.
*   **FR6**: Accountant can map accounts via a guided wizard and monitor sync status in an exception dashboard.
*   **FR7**: System provides human-readable error reasons and supports manual/automatic retry.

### 3. Administration & Reporting
*   **FR8**: Admin can configure branches, tax categories, and user permissions.
*   **FR9**: System generates real-time pulse dashboards and reconciliation-ready reports.

## Non-Functional Requirements (Phase 1)

### 1. Performance & Reliability
*   **NFR1**: Catalog search <200ms; UI feedback <100ms.
*   **NFR2**: Target 99.9% availability during business hours with graceful degradation (POS stays open if sync is down).

### 2. Resilience & Observability
*   **NFR3**: Sync queue implements exponential backoff with categorized error logging (Auth, Rate-Limit, Mapping).
*   **NFR4**: Regular tenant-safe database backups with documented restore procedures.
