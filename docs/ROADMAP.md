# Development Roadmap: IPOS (Zero-Loss Cart & Checkout)

> Status note: This document is a legacy planning roadmap and is no longer the execution source of truth.
> Use [docs/roadmap/validated-implementation-roadmap.md](./roadmap/validated-implementation-roadmap.md) for current approved project state.

This roadmap outlines the path to building the robust, zero-loss Point of Sale system for the IPOS project.

---

## Epic 4: Zero-Loss Cart & Transaction Processing [Completed]
**Goal**: Deliver a robust frontend POS cart that gracefully handles network drops, retains items securely via local storage, and guarantees idempotent checkout submissions to the backend.

### [Story 4.1] Cart UI and Product Search [Completed]
### [Story 4.2] Zero-Loss Cart Local Persistence [Completed]
### [Story 4.3] Transaction Draft Identity & UUID Foundation [Completed]
### [Story 4.4] Checkout Submission & Idempotent API [Completed]

---

## Epic 5: Payment Processing & Split Payments [Completed]
**Goal**: Handle complex payment scenarios directly from the POS interface.

### [Story 5.1] Payment Method Management [Completed]
### [Story 5.2] Split Payment Logic [Completed]
### [Story 5.3] Payment Audit & Reference Tracking [Completed]

---

## Epic 6: Receipt Generation & Closing [Completed]
**Goal**: Finalize transactions with printable and digital receipts.

### [Story 6.1] Thermal Receipt Layouts [Completed]
### [Story 6.2] Transaction History & Audit [Completed]

---

## Epic 7: Voids, Refunds, and Reversals [Completed]
**Goal**: Handle transaction corrections using append-only, immutable logic.

### [Story 7.1] Full Sale Void Protocol [Completed]
### [Story 7.2] Payment Reversals [Completed]
### [Story 7.3] Partial/Full Refund Logic [Completed]

---

## Epic 8: Accounting Outbox and Sync Foundation [Completed]
**Goal**: Create an accounting-silent capture layer for financial events.

### [Story 8.1] Accounting Outbox Schema and Event Capture [Completed]
### [Story 8.2] Read-Only Admin Inspection [Completed]
### [Story 8.3] Sync State Machine Foundation [Completed]
### [Story 8.4] Accounting Outbox Processor Skeleton [Completed]

---

## Epic 9: External Accounting Integration and Settlement Controls [Completed]
**Goal**: Synchronize outbox events with external accounting providers and add a read-mostly settlement control layer for review, approval, lock, and reopen workflows.

Epic 9 has completed the foundational integration and control surfaces. The accounting sync foundation (queue worker, scheduler, QuickBooks connectivity, mapping) and the settlement control layer (period lifecycle, read-only variance queries, immutable snapshots, and review dashboard) are fully implemented and validated with 544 tests.

### [Story 9.1] Background Queue Worker & Scheduler [Completed]
### [Story 9.2] QuickBooks OAuth/Connectivity Layer [Completed]
### [Story 9.3] Event Mapping & Push Logic [Completed]
### [Story 9.4] Sync Dashboard & Manual Retry UI [Completed]
### [Story 9.1 - Settlement Track] Settlement / Reconciliation Scope Lock and Architecture [Completed]
### [Story 9.2 - Settlement Track] Settlement Period Lifecycle and Scope Controls [Completed]
### [Story 9.3 - Settlement Track] Settlement Read-Only Summary Query [Completed]
### [Story 9.4 - Settlement Track] Settlement Variance Classification and Read-Only Variance Summary [Completed]
### [Story 9.5 - Settlement Track] Settlement Snapshot Persistence and Lock-Ready Summary Capture [Completed]
### [Story 9.6 - Settlement Track] Settlement Approval and Lock Workflow [Completed]
### [Story 9.7 - Settlement Track] Settlement Review Dashboard UI [Completed]
### [Story 9.8 - Settlement Track] Settlement Approval and Lock Action UI [Completed]
### [Story 9.9 - Settlement Track] Settlement Reopen Workflow [Completed]

---

## Epic 10: Settlement Expansion & Reporting [Completed]
**Goal**: Build on the implemented settlement control layer with reporting, export, exception handling, and broader reconciliation-ready operational surfaces without mutating the append-only POS or accounting source records.

### [Story 10.1] Settlement Export & Report Scope Lock [Completed]
### [Story 10.2] Settlement Summary CSV Export [Completed]
### [Story 10.3] Settlement Variance Ledger CSV Export [Completed]
### [Story 10.4] Accounting Sync Status CSV Export [Completed]
### [Story 10.5] Settlement Summary PDF Generation [Completed]

---

## Epic 11: Operational Pulse, Dashboards & Business Reporting [Completed]
**Goal**: Provide real-time, read-only operational visibility through a high-fidelity dashboard for Owners and Branch Managers.

### [Story 11.1] Operational Pulse Scope Lock and Dashboard Query Foundation [Completed]
### [Story 11.2] Dashboard Query Service Foundation [Completed]
### [Story 11.3] Owner Tenant-Wide Pulse Dashboard [Completed]
### [Story 11.4] Branch Manager Isolated Pulse Dashboard [Completed]
### [Story 11.5] Responsive Mobile Dashboard Optimization [Completed]
### [Story 11.6] Latest Locked Settlement Evidence Card [Completed]

---

## Epic 12: Shift, Cash Drawer, and End-of-Day Operations [Completed]
**Goal**: Implement operational shift control and cash drawer reconciliation on top of the validated POS and settlement foundation.

### [Story 12.1] Shift and Cash Drawer Scope Lock [Completed]

---

## Epic 13: Support Assisted Mode, Observability, and Production Hardening [Completed]
**Goal**: Add support-safe diagnostics, production observability, and hardened runtime/security defaults before release-readiness decisions.

### [Story 13.1] Support Assisted Mode Scope Lock and Identity Model [Completed]
### [Story 13.2] Observability and Centralized Logging [Completed]
### [Story 13.3] Production Security Hardening [Completed]

---

## Post-Epic 13 State

No Epic 14+ implementation story is approved in this legacy roadmap.

The next approved work is release-readiness and governance closure, centered on credential rotation and go-live validation. See [docs/ai-governance/release-readiness-checklist.md](./ai-governance/release-readiness-checklist.md).

