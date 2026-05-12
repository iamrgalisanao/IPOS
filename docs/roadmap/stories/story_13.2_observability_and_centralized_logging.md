# Story 13.2: Observability and Centralized Logging

Status: Implemented 2026-05-12

Implementation status summary:

- Slice A: Implemented 2026-05-12
- Slice B: Implemented 2026-05-12
- Slice C: Implemented 2026-05-12
- Slice D: Implemented 2026-05-12
- Slice E: Implemented 2026-05-12

Validated baseline as of 2026-05-13:

- `tests/Feature/Observability/RequestCorrelationTest.php`
- `tests/Feature/Observability/AccountingOutboxObservabilityTest.php`
- `tests/Feature/Observability/CheckoutObservabilityTest.php`
- `tests/Feature/Observability/SupportObservabilityTest.php`
- `tests/Feature/Observability/AccountingIntegrationFailureObservabilityTest.php`
- `php artisan test tests/Feature/Observability`
- full `php artisan test` (`703 passed`, `1 risky` baseline in `Tests\Feature\Shift\ShiftClosingTest`)
- `npm run build`

## 1. Goal

Provide enough production telemetry to diagnose checkout uncertainty, accounting sync failures, queue behavior, support-assisted access, and exception paths without relying on ad hoc log tailing.

## 2. Current Repository Anchors

The implementation should build on code paths that already exist in the repository:

- `config/logging.php` already defines the active Laravel log channels, but no request correlation or support-specific structured processors are configured yet.
- `routes/console.php` already schedules and dispatches `accounting:process-outbox`, which is the main queue-facing accounting diagnostic path.
- `app/Jobs/ProcessAccountingOutboxJob.php` already restores tenant and branch context around accounting queue work, making it the correct anchor for queue correlation propagation.
- `app/Services/Accounting/AccountingOutboxProcessorService.php` already records sync attempts and classifies failures, making it the correct slice for operational sync logging.
- `app/Services/Accounting/QuickBooksSyncService.php` already sanitizes provider error detail before surfacing it, making it the correct baseline for safe integration logs.
- `app/Http/Controllers/CheckoutController.php` already controls the checkout validation and recovery flow, making it the correct request path for checkout observability.
- `app/Http/Middleware/IdentifyTenantContext.php` and `app/Http/Middleware/IdentifySupportAssistedContext.php` already establish request scope, making them the correct anchors for correlation and support-session log context.

## 3. Purpose and Outcomes

By the end of Story 13.2, IPOS should support:

- request and queue correlation identifiers that survive across the critical control paths
- structured operational logs with tenant, branch, actor, route, queue, and support-session context
- safer production diagnostics for checkout recovery, accounting outbox processing, provider failures, and support-assisted access
- a minimal centralized baseline for failed jobs and exception review

## 4. Non-Goals

Story 13.2 should explicitly avoid:

- introducing third-party observability vendors as a required dependency
- building a broad operational monitoring UI
- creating new accounting, settlement, or POS write flows
- exposing raw provider payloads, OAuth tokens, secrets, or unsafe financial payload fragments
- changing support-assisted mode into a write-capable administrative surface

## 5. Implementation Boundaries

Implement only:

1. request correlation and queue correlation propagation
2. structured log context for critical operational paths
3. focused logging around checkout recovery, accounting outbox processing, QuickBooks failures, and support-assisted access
4. centralized baseline for failed jobs and exception handling guidance
5. tests and validation proving observability does not leak secrets

Do not implement yet:

- external telemetry vendors
- support dashboard or ops dashboard redesign
- provider-side retry or reconnect actions beyond existing paths
- export/reporting features for logs
- secret-viewing tools or debugging consoles

## 6. Proposed Implementation Order

### Slice A: Correlation Foundation

Implement:

- request correlation middleware for web and API requests
- correlation id generation using request header passthrough or generated UUID fallback
- correlation id sharing into log context and Inertia-shared props only if needed for safe display or support tracing

Required fields:

- `correlation_id`
- `tenant_id`
- `branch_id`
- `actor_id`
- `actor_type`
- `route_name`

Guardrails:

- never trust client-supplied correlation ids without normalization
- do not expose secrets in generated log context

### Slice B: Queue Propagation

Implement:

- correlation id propagation into `ProcessAccountingOutboxJob`
- queue-side restoration of correlation context before processing begins
- structured logging around outbox claim, sync success, sync failure, retry schedule, and completion

Required queue fields:

- `correlation_id`
- `job_class`
- `queue`
- `outbox_id`
- `tenant_id`
- `branch_id`
- `attempt_count`

### Slice C: Critical Request Logging

Implement focused logs for:

- checkout validation and idempotency conflict paths
- checkout uncertainty/status recovery paths
- support-assisted session validation and access
- support audit review access

Required behavior:

- use structured context instead of freeform only
- never log raw tokens, Authorization headers, provider payloads, or full unsafe financial payloads

### Slice D: Integration Failure Logging

Implement focused logs for:

- QuickBooks connection failures
- QuickBooks sync failures and provider-classified error categories
- accounting outbox retry scheduling
- failed job and exception baseline capture for critical jobs

Expected rule:

- reuse existing error sanitization and masking helpers instead of creating a second redaction path

### Slice E: Validation and Safe Baseline

Add tests or checks proving:

- correlation ids exist on critical request and queue paths
- support-assisted logs include support session context safely
- failed accounting sync paths emit structured context without secret leakage
- sensitive payloads remain redacted in logs and exception-facing diagnostics

## 7. Proposed Technical Approach

### 7.1 Request Correlation

Recommended files:

- `app/Http/Middleware/AttachRequestCorrelation.php`
- `app/Services/Observability/RequestCorrelation.php`
- `bootstrap/app.php`

Recommended behavior:

- normalize incoming correlation header if present
- otherwise generate a UUID
- attach it to the request attributes, response headers, and log context

### 7.2 Queue Correlation Propagation

Recommended files:

- `app/Jobs/ProcessAccountingOutboxJob.php`
- `app/Services/Observability/RequestCorrelation.php`
- optional queue helper for restoring correlation context inside jobs

Recommended behavior:

- serialize correlation id into the job payload when dispatched
- restore correlation context before processing
- clear context after job completion just like tenant and branch context are already cleared

### 7.3 Structured Logging Baseline

Recommended files:

- `config/logging.php`
- `app/Services/Observability/OperationalLogger.php`
- targeted controllers and services already owning critical logic

Recommended behavior:

- add a dedicated operational log channel or structured stack extension
- centralize repeated log context assembly in a helper rather than duplicating arrays everywhere

### 7.4 Safe Redaction and Secret Discipline

Reuse existing safe redaction anchors:

- `app/Services/Support/SupportPayloadMasker.php`
- `app/Services/Accounting/QuickBooksConnectionService.php::sanitizeCallbackError()`

Rule:

- logs must not emit raw `Authorization`, bearer tokens, access tokens, refresh tokens, provider payloads, or full unsafe financial fragments

## 8. Validation Expectations

Primary validation areas:

- request correlation presence on critical routes
- queue correlation persistence for accounting outbox processing
- support-assisted log events include support session identifiers safely
- QuickBooks/provider errors are sanitized before logging
- no new write-side effects are introduced by observability work
- previous Epic 1-13 functionality remains green

## 9. Suggested Test and Check Surfaces

Suggested test files:

- `tests/Feature/Observability/RequestCorrelationTest.php`
- `tests/Feature/Observability/AccountingOutboxObservabilityTest.php`
- `tests/Feature/Observability/SupportObservabilityTest.php`

Suggested command checks:

- focused observability feature tests
- full `php artisan test`
- `npm run build`

## 10. Delivery Notes

Story 13.2 should be implemented as infrastructure for diagnosis, not as a user-facing feature. The first acceptable version is the smallest end-to-end baseline that gives reliable correlation ids, structured log context, and safe provider failure visibility on the already-existing critical flows.