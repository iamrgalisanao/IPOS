---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7]
inputDocuments:
  - "prd.md"
  - "implementation-readiness-report-2026-05-10.md"
  - "epic-28-offline-resilient-pos-architecture-plan.md"
workflowType: 'architecture'
project_name: 'IPOS'
user_name: 'Teamsolo'
date: '2026-05-19'
---

# Architecture Decision Document

_This document defines the technical foundation for the IPOS project. It ensures that every design choice upholds the "Accounting Confidence" mission._

## Project Context Analysis

### Requirements Overview

**Functional Requirements:**
The system centers on a high-velocity POS (FR1-FR4) that feeds an asynchronous Accounting Outbox (FR5-FR7). This requires a decoupled architecture where the foreground UI provides immediate feedback while the background processes handle the integrity of the QuickBooks sync.

**Non-Functional Requirements:**
NFRs drive a focus on **Performance** (sub-200ms catalog search) and **Resilience** (99.9% availability). The "Graceful Degradation" requirement (NFR2) means the POS must remain functional for draft creation even if the accounting sync is down, necessitating a robust background queue strategy.

**Scale & Complexity:**
- **Primary domain**: SaaS B2B / Fintech-adjacent POS.
- **Complexity level**: High.
- **Estimated architectural components**: ~12-15 core modules.

### Technical Constraints & Dependencies
- **QuickBooks Online API**: The primary integration target.
- **Online-First Boundary**: Backend confirmation is the "Point of No Return" for sales.
- **Manual Reconciliation (Phase 1)**: The data model must support manual reference numbers (GCash/Maya) as a bridge to future automated settlement matching.

### Cross-Cutting Concerns Identified
- **Tenant Scoping**: Must be implemented as a low-level "fail-closed" mechanism.
- **Idempotency**: Every sync attempt must use a deterministic key based on the POS transaction ID.
- **Monetary Precision**: Use of `BCMath` or equivalent decimal-safe arithmetic.

---

## Architectural Decisions (ADRs)

### ADR-001: Fail-Closed Tenancy Enforcement
- **Context**: SaaS multi-tenancy requires strict data isolation to prevent leakage.
- **Decision**: Implicit Global Scoping with Explicit Resolution. Use `TenantContext` resolved from auth/headers. ORM-level global scopes apply `tenant_id` filter by default.
- **Alternatives**: Manual filtering (High risk of error); DB-per-tenant (High ops overhead).
- **Consequences**: Isolation is "baked-in"; raw SQL is forbidden.
- **Implementation**: Laravel `BelongsToTenant` trait + `IdentifyTenantContext` middleware.
- **Testing**: Attempt to cross-access data; expect 404/403.
- **Risks**: Raw query bypass.

### ADR-002: Atomic Transaction Lifecycle
- **Context**: Sales, payments, and sync records must stay in sync.
- **Decision**: Synchronous Atomic Service Pattern. All sale-related records (Sale, Item, Tax, Payment, Movement, Outbox, Audit) are committed in a single DB transaction.
- **Alternatives**: Event-driven side-effects (Risk of outbox loss).
- **Consequences**: Every sale is guaranteed to be in the sync pipeline.
- **Implementation**: `CompleteCheckoutAction` with `DB::transaction`.
- **Testing**: Forced failure during outbox creation must rollback inventory/sale.
- **Risks**: Increased DB lock duration.

### ADR-003: Data Model and Financial Integrity
- **Context**: Precision and auditability are critical for fintech-adjacent platforms.
- **Decision**: PostgreSQL + UUID PKs + `DECIMAL(19,4)` for money + `TIMESTAMPTZ` (UTC). Append-only financial and inventory records.
- **Alternatives**: Floating-point (Inaccurate); Soft-deletes (Breaks audit).
- **Consequences**: Perfect financial traceability; sub-cent precision for taxes.
- **Implementation**: DB migration standards enforcing `decimal(19,4)`.
- **Testing**: Sum validation of split payments vs net total.
- **Risks**: Growth of append-only ledger requiring partitioning.

### ADR-004: Accounting Outbox and Sync State Machine
- **Context**: Decoupling POS from QuickBooks API uptime.
- **Decision**: Outbox State Machine (Pending -> Processing -> Synced/Failed). Mandatory outbox entry creation.
- **Alternatives**: Direct API calls (Blocks UI).
- **Consequences**: POS stays fast; accountant sees exceptions in a dashboard.
- **Implementation**: Laravel Horizon + `accounting_outbox` table.
- **Testing**: Mock QBO failures and verify exponential backoff.
- **Risks**: Queue backlog during QBO outages.

### ADR-005: Idempotency Strategy
- **Context**: Prevent duplicate transactions from network retries or double-clicks.
- **Decision**: `client_request_uuid` for checkout; `transaction_uuid` as QBO `ExternalId`.
- **Alternatives**: Server-side session locking.
- **Consequences**: Replay attacks are blocked; duplicates in QBO are impossible.
- **Implementation**: Unique constraint on `client_request_uuid`.
- **Testing**: Double-POST with same UUID.
- **Risks**: Client-side generation of duplicate UUIDs.

### ADR-006: Support Assisted Mode
- **Context**: Support reps need to help tenants without seeing sensitive data.
- **Decision**: Read-only, Masked, Audited. Explicit tenant selection; no modification of financial records.
- **Alternatives**: Full impersonation (High security risk).
- **Consequences**: Reps can diagnose sync errors without seeing owner's net income.
- **Implementation**: `SupportMaskingMiddleware` + `support_access_logs`.
- **Testing**: Verify masked fields in support view.
- **Risks**: Support access overreach.

### ADR-007: Tech Stack Selection
- **Context**: Speed of deployment + scalable SaaS base.
- **Decision**: Laravel 11+ / React SPA / PostgreSQL / Redis / Horizon / Sanctum.
- **Alternatives**: Node.js/Fastify; Vue/Inertia.
- **Consequences**: Robust ecosystem; easy to hire for.
- **Implementation**: Initialized via standard Laravel and React boilerplates.
- **Testing**: Standard CI/CD pipeline.
- **Risks**: Complexity of managing decoupled SPA/API state.

### ADR-008: Frontend Architecture
- **Context**: High-velocity POS requires snappy response.
- **Decision**: React SPA with PWA-ready Local Storage (Zero-Loss Cart). Backend is source of truth for all calculations.
- **Alternatives**: Server-rendered forms (Too slow).
- **Consequences**: High UX performance; cart state protected against flickers.
- **Implementation**: `Redux` or `Context API` for cart; `localStorage` persistence.
- **Testing**: Refresh page mid-checkout; verify cart restore.
- **Risks**: Calc drift if frontend logic isn't aligned with backend.

### ADR-009: Deployment Topology
- **Context**: MVP budget vs growth path.
- **Decision**: VPS (Forge/DigitalOcean) with Nginx/PHP-FPM. Future: AWS/RDS/S3.
- **Alternatives**: Full AWS Serverless (Complex/Expensive for MVP).
- **Consequences**: Fast launch; clear migration path to managed cloud.
- **Implementation**: Git-based deployment.
- **Testing**: Automated smoke tests on deploy.
- **Risks**: Vertical scaling limit of a single VPS.

### ADR-010: Observability and Recovery
- **Context**: Long-term reliability of a fintech platform.
- **Decision**: Horizon for queue health; Daily UTC backups to S3; Restore-first posture.
- **Alternatives**: Ad-hoc monitoring.
- **Consequences**: Proactive identification of sync issues.
- **Implementation**: `spatie/laravel-backup` + `horizon` dashboard.
- **Testing**: Monthly restore drills.
- **Risks**: Backup corruption if not validated.

### ADR-011: Offline-Tolerant POS Shell & Compliance Boundary
- **Context**: High-velocity POS requires offline resilient catalog browsing and cart persistence, but offline checkout introduces severe compliance risks under BIR regulations (duplicate sequence numbers, untrusted local GCT/Z-reads).
- **Decision**: Keep POS and Admin in a bounded monorepo. Keep Admin 100% online. POS shell will run offline-tolerant features (IndexedDB catalog lookup, cached price rules, cart draft persistence) but block checkout offline. Official checkout, invoicing, GCT, Z-reading, and e-journaling remain server-side and online-only.
- **Alternatives**: Full local-first offline checkout (High compliance risk, difficult browser-only tamper-free storage); completely decoupled codebases (High sync/duplicate model overhead).
- **Consequences**: Complete compliance containment. Zero duplicate numbering or local GCT manipulation risk. 
- **Implementation**: `IndexedDB` caching for products/rules, connection state hooks, and client-side checkout block guards.
- **Testing**: Block payments offline; force checkout failure if config hashes mismatch.
- **Risks**: Config/pricing drift (Mitigated by server-side tax rule version hash verification on checkout).

---

## System Architecture Summary

IPOS is a decoupled SaaS platform where a high-velocity React POS communicates with a Laravel API. The core **Trust Engine** ensures that all sales are saved atomically with their sync outbox entries, inventory movements, and audit trails. Tenancy is enforced at the ORM layer, creating a "Fail-Closed" environment.

### Core API Flow (POS Checkout)
1. **POST `/pos/checkout`** (with `client_request_uuid`).
2. **Validation** (Shift, Inventory, Total Match).
3. **Atomic Write** (Sale, Payment, Stock, Outbox, Audit).
4. **201 Created** -> Frontend clears local draft.
5. **Queue** -> `SyncTransactionToQuickBooksJob`.

### Architecture Risks
- **QuickBooks API Latency**: Slow QBO responses could cause the accounting queue to back up.
- **Monetary Rounding Drift**: Mismatches between React-calculated taxes and Laravel-calculated taxes (Backend is the absolute source of truth).
- **Data Volume**: High growth in append-only logs (`inventory_movements`, `audit_logs`) will eventually require table partitioning.
- **Tax/Config Drift**: Stale client caches could display outdated tax/price rates before server validation.

---

## Next Steps
1. **POS Cache Bootstrap API**: Build service returning complete products, pricing, and tax rules with hash version.
2. **Connectivity & Caching Components**: Setup IndexedDB catalog caching and frontend connectivity state wrappers.
3. **Story Spec Authoring**: Author story specifications for Epic 28 Phase 1.
