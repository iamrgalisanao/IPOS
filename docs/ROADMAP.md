# Development Roadmap: IPOS (Zero-Loss Cart & Checkout)

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

## Epic 9: External Accounting Integration [In Progress]
**Goal**: Synchronize outbox events with external accounting providers (e.g., QuickBooks).

Implementation evidence in the repository shows the queue worker and scheduler, QuickBooks connectivity layer, and accounting inspection/status surfaces are already present. The Epic remains in progress because the full external push and operational dashboard flow are not yet fully evidenced as complete.

### [Story 9.1] Background Queue Worker & Scheduler [Completed]
### [Story 9.2] QuickBooks OAuth/Connectivity Layer [Completed]
### [Story 9.3] Event Mapping & Push Logic [In Progress]
### [Story 9.4] Sync Dashboard & Manual Retry UI [In Progress]
