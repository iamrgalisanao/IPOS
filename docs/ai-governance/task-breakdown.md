# Task Breakdown

Last updated: 2026-05-12

## Scope

This breakdown covers the reviewed accounting outbox, QuickBooks connectivity, and sync visibility surfaces that were assessed during the 2026-05-12 code review and security review.

## Traceability Map

| Work Slice | PRD / Requirement | Epic / Story | Primary Implementation Evidence | Validation Evidence | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| Accounting outbox state machine and persistence | FR5, NFR3, ADR-004 | Epic 8 / Story 8.1 | `app/Models/AccountingOutbox.php`, `app/Services/Accounting/AccountingOutboxSyncStateService.php` | `tests/Feature/Accounting/AccountingOutboxSyncStateTest.php`, `tests/Feature/Accounting/AccountingOutboxTest.php` | Implemented |
| Outbox processing and attempt logs | FR5, FR7, NFR3 | Epic 8 / Story 8.3 | `app/Services/Accounting/AccountingOutboxProcessorService.php`, `app/Models/AccountingSyncAttempt.php` | `tests/Feature/Accounting/AccountingOutboxProcessorTest.php` | Implemented |
| Tenant-scoped sync job orchestration and scheduler | FR5, NFR2, ADR-001 | Epic 8 / Story 8.4 | `app/Jobs/ProcessAccountingOutboxJob.php`, `routes/console.php` | `tests/Feature/Accounting/AccountingOutboxOrchestrationTest.php` | Implemented |
| QuickBooks connection guard, token handling, and lifecycle | FR5, FR6, ADR-001 | Epic 8 / Story 8.5, Epic 9 / Story 9.1 | `app/Services/Accounting/QuickBooksConnectionService.php`, `app/Http/Controllers/Accounting/QuickBooksConnectionController.php`, `app/Models/QuickBooksConnection.php` | `tests/Feature/Accounting/QuickBooksConnectionTest.php` | Implemented |
| Payload normalization and external reference persistence | FR5, ADR-005 | Epic 8 / Stories 8.6-8.7 | `app/Services/Accounting/NormalizedPayloadService.php`, `app/Services/Accounting/AccountingOutboxProcessorService.php` | `tests/Feature/Accounting/PayloadNormalizationTest.php`, `tests/Feature/Accounting/AccountingOutboxProcessorTest.php` | Implemented |
| Retry logic and error classification | FR7, NFR3 | Epic 8 / Story 8.8 | `app/Services/Accounting/AccountingOutboxProcessorService.php` | `tests/Feature/Accounting/AccountingOutboxProcessorTest.php`, `tests/Feature/Accounting/AccountingOutboxSyncStateTest.php` | Implemented |
| Outbox status and visibility APIs | FR6, FR7 | Epic 8 / Story 8.10, Epic 9 / Stories 9.6-9.8 | `routes/api.php`, `app/Http/Controllers/Accounting/AccountingOutboxController.php`, `app/Services/Accounting/AccountingOutboxQueryService.php` | `tests/Feature/Accounting/AccountingOutboxVisibilityTest.php` | Implemented |
| Queue and sync observability foundation | FR7, NFR4, ADR-010 | Epic 8 / Story 8.11, Epic 13 / Story 13.2 Slices A-E | `bootstrap/app.php`, `app/Http/Middleware/AttachRequestCorrelation.php`, `app/Services/Observability/RequestCorrelation.php`, `app/Jobs/ProcessAccountingOutboxJob.php`, `app/Http/Controllers/CheckoutController.php`, `app/Http/Middleware/IdentifySupportAssistedContext.php`, `app/Services/Accounting/QuickBooksSyncService.php`, `app/Services/Accounting/AccountingOutboxProcessorService.php` | `tests/Feature/Observability/RequestCorrelationTest.php`, `tests/Feature/Observability/AccountingOutboxObservabilityTest.php`, `tests/Feature/Observability/CheckoutObservabilityTest.php`, `tests/Feature/Observability/SupportObservabilityTest.php`, `tests/Feature/Observability/AccountingIntegrationFailureObservabilityTest.php` | Implemented |
| Guided accounting mapping wizard and templates | FR6 | Epic 9 / Stories 9.2-9.3 | No reviewed implementation evidence found in current scope | No direct validation evidence in reviewed slice | Not Yet Evidenced |
| Mapping readiness, dry-run validation, and sync exception UX | FR6, FR7 | Epic 9 / Stories 9.4-9.10 | Partial evidence only through permissions, status endpoints, and outbox visibility | `tests/Feature/Accounting/AccountingOutboxVisibilityTest.php` | In Progress |

## Review Notes

- This breakdown is intentionally limited to the accounting and QuickBooks surfaces that were reviewed on 2026-05-12.
- Where roadmap or planning language is broader than the current codebase, implementation status is based on repository evidence rather than planning intent.
- `Partially Implemented` means foundational infrastructure exists but the full operational or UX surface described by the story is not yet fully evidenced in the reviewed files.
- The observability foundation row above now reflects Story 13.2 Slices A-E, including validated provider failure logging and closure-level secret-leak prevention assertions across the observability suite.

## Next Traceability Gap

- Extend this document to cover the owner reporting and reconciliation surfaces when Epic 10 review work begins.