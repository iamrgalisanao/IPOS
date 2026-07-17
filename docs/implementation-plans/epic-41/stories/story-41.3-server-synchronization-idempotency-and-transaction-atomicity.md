# Story 41.3 Server Synchronization, Idempotency, and Transaction Atomicity

## Status

Implemented - Local Verification Complete

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Make server synchronization of offline provisional cash sales deterministic, idempotent, replay-safe, and consequence-complete.

Story 41.3 owns the server authority checkpoint. A locally captured offline transaction remains provisional until the server accepts it through this contract. The browser queue may preserve evidence and retry safely, but it must not become the sale ledger, fiscal authority, inventory authority, loyalty authority, or official receipt authority.

## Dependencies

Requires:

1. Story 41.1.
2. Story 41.2.
3. Existing offline sync endpoint and frontend queue submission path.
4. Existing `SaleCreationService` checkout path.
5. Existing payment recording, inventory deduction, invoice sequencing, receipt, accounting outbox, and loyalty runtime behavior.

## Complexity

Very Large

## Benchmark and Architecture Direction

Recommended benchmark provider:

```text
Mosaic
```

Mosaic is the best public benchmark for Story 41.3 because its documented integration style is closest to the contract IPOS needs: API-first resources, lifecycle statuses, location context, audit visibility, and event or webhook-oriented extensibility. Mosaic does not publicly prove IPOS requirements such as exact UUID replay, fingerprint drift detection, row locking, per-envelope transaction atomicity, or consequence completeness. Those remain IPOS-owned controls.

Recommended IPOS implementation style:

```text
API-first server synchronization module
internal anti-corruption layer around SaleCreationService
```

Preferred orchestration:

```text
OfflineSyncController
        ↓
OfflineEnvelopeSynchronizationService
        ↓
Idempotency and replay decision
        ↓
OfflineCheckoutSnapshotAdapter
        ↓
SaleCreationService
        ↓
Canonical payment, inventory, invoice, loyalty,
accounting, receipt, and audit services
```

Do not retain `OfflineReconciliationService::reconcileImport()` as a parallel sale-posting engine. Existing manual posting logic must be replaced, wrapped, or retired so it can no longer independently recreate sales, sale items, payments, invoice identities, inventory movements, loyalty effects, accounting evidence, or receipt records.

## Non-Reopened Policy Decisions

Story 41.3 must consume Story 41.1 and Story 41.2 as locked contracts and must not reopen:

1. offline tender scope;
2. cash-only first-release offline capture;
3. local cancellation blocking after durable cash capture;
4. online-only statutory discounts;
5. online-only voids and refunds;
6. online-only dining ticket mutation;
7. no local official invoice, GCT, e-journal, Z-read, inventory, loyalty, or store-credit authority;
8. immutable local business envelope after durable capture;
9. terminal identity and terminal binding epoch as required sync evidence;
10. local queue lease and attempt metadata as client-side race evidence, not business authority.

## Scope

In scope:

1. Server request contract for offline envelope synchronization.
2. Server idempotency key and canonical business payload fingerprint validation.
3. Exact replay response behavior.
4. Drift detection before mutation.
5. Suspected duplicate detection beyond exact UUID replay.
6. Server-side row locking and transaction boundaries.
7. `SaleCreationService` ownership of committed sale creation.
8. Per-envelope consequence atomicity.
9. Stable consequence status response schema.
10. Official server sale and invoice identity allocation.
11. Durable accepted, replayed, retryable, review-required, and rejected outcomes.
12. Cash-collected review record handling.
13. Sync audit payload and support diagnostics evidence.
14. Outbox and pending-consequence policy where synchronous completion is not architecture-supported.
15. Migration from older `server_verified`, `duplicate`, `conflict`, and `posted` import states to Epic 41 sync semantics.
16. Backend and frontend contract tests required to keep queue state synchronized with server outcomes.
17. Versioned API contract and status lookup endpoint.
18. OpenAPI or generated endpoint documentation for the sync contract.

Out of scope:

1. Browser queue UI redesign beyond response-contract handling needed for this story.
2. Hardware printing.
3. Expanding offline tender types.
4. Offline void or refund implementation.
5. Story 41.4 conflict workflow UI and support-resolution execution.
6. Story 41.5 permission and payment restriction hardening beyond sync-time validation required here.
7. Story 41.6 cross-domain consequence validation beyond status reporting of consequences produced by accepted sale creation.

## Current Implementation Context

Existing files expected to be updated during implementation:

1. `app/Http/Controllers/POS/OfflineSyncController.php`
2. `app/Http/Requests/POS/SyncBatchRequest.php`
3. `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
4. `app/Models/OfflineSalesImport.php`
5. `app/Models/OfflineSyncBatch.php`
6. `resources/js/POS/offline/offlineSyncManager.ts`
7. offline sync migrations or additive migrations for missing persistence fields
8. feature and frontend tests under `tests/Feature/POS` and `tests/Frontend`

Important existing behavior:

1. `OfflineSyncController::sync()` currently receives a batch and returns per-import statuses.
2. `SyncBatchRequest` already accepts Story 41.2 fields: `offline_transaction_uuid`, `terminal_binding_epoch`, `queue_state_revision`, `sync_attempt_id`, `lease_id`, and `attempt_generation`.
3. `OfflineReconciliationService::receiveImportBatch()` currently stores imports, validates hash/sequence, recalculates totals, and marks imports with older states such as `server_verified`, `duplicate`, `conflict`, `accepted_with_warning`, or `rejected`.
4. `OfflineReconciliationService::reconcileImport()` currently contains manual sale posting logic that recreates sale, item, payment, inventory, invoice, and accounting behavior directly.
5. `offlineSyncManager.ts` currently maps `server_verified` and `duplicate` to local `synced`, while the Epic 41 contract requires explicit `accepted` and `replayed` outcomes with consequence status details.

Implementation must preserve existing passing behavior where still valid, but must replace or wrap older state names with the Epic 41 server-authoritative contract.

## 1. Server Synchronization Contract

The sync endpoint must accept a batch, but authority is per envelope.

Recommended versioned endpoint:

```text
POST /api/v1/pos/offline-sales/sync
```

The existing route may remain as a compatibility facade during migration, but the implementation must expose or document the Epic 41 contract independently from legacy endpoint semantics.

Recommended status lookup endpoint:

```text
GET /api/v1/pos/offline-sales/{offline_transaction_uuid}/sync-status
```

The lookup endpoint supports recovery after unknown network outcome, support investigation, terminal reinstall recovery, and queue reconciliation without resubmitting the full business payload.

Batch-level responsibilities:

1. authenticate tenant, branch, terminal, and cashier context;
2. resolve the active or historically valid terminal profile;
3. validate batch shape;
4. coordinate per-envelope processing;
5. return a complete result for every submitted envelope unless the entire request is malformed.

Envelope-level responsibilities:

1. validate identity and terminal binding evidence;
2. validate canonical fingerprint and checksum evidence;
3. determine exact replay, drift, new post, suspected duplicate, retryable failure, review-required, or rejection;
4. create committed sale consequences only through the server-authoritative path;
5. return stable identity and consequence status.

The server must never treat a batch as one all-or-nothing business transaction. Each envelope receives its own server transaction and result.

Core services to introduce or formalize:

| Service | Responsibility |
| --- | --- |
| `OfflineEnvelopeSynchronizationService` | Per-envelope orchestration and transaction boundary |
| `OfflineEnvelopeIdentityService` | UUID, terminal epoch, sequence, and fingerprint persistence |
| `OfflineReplayDecisionService` | New, replay, drift, retry, review, or rejection decision |
| `OfflineDuplicateReviewService` | Different-UUID suspected duplicate detection |
| `OfflineCheckoutSnapshotFactory` | Converts envelope evidence into sales-domain DTO |
| `OfflineConsequenceStatusBuilder` | Builds strict response and durable snapshot |
| `OfflineSyncAuditService` | Writes non-sensitive audit evidence |

## 2. Required Request Fields

Each import must include, or the server must reject before mutation:

```text
offline_transaction_uuid
tenant_id
branch_id
terminal_id or sales_machine_profile_id
terminal_binding_epoch
offline_sequence_number or local_sequence
submitted_at
terminal_timestamp
timezone
cashier/user evidence
cashier_shift_id where policy requires it
drawer_session_id where available
business_payload_fingerprint or payload_hash
fingerprint_algorithm
fingerprint_schema_version
items
client_subtotal
client_tax_total
client_total
payment_method = cash
payments showing fully cash-settled tender
catalog_version_hash
tax_configuration_version_hash
payment_methods_version_hash
terminal_policy_version_hash
sync_attempt_id
lease_id
attempt_generation
queue_state_revision
```

The implementation may support older payload field names during a migration window, but the response must tell the client which contract version was accepted.

Recommended request metadata:

```text
sync_contract_version
offline_envelope_schema_version
capture_status
cash_status
local_transaction_reference
local_receipt_number
captured_at_device
last_server_time
device_clock_offset_at_last_sync
offline_duration
time_evidence_quality
queue_record_integrity_checksum
checksum_algorithm
checksum_schema_version
```

## 3. Canonical Fingerprint

The server idempotency contract uses the canonical business payload fingerprint generated after final local cart and cash payment confirmation.

Server fingerprint authority:

1. receive the client-submitted fingerprint;
2. normalize the submitted envelope into the accepted contract;
3. recompute the canonical fingerprint server-side;
4. reject or mark for review before mutation if the client fingerprint differs from the server-computed fingerprint;
5. persist and compare the server-computed fingerprint for idempotency decisions.

The local queue checksum remains diagnostic evidence only. It must not replace server fingerprint recomputation.

Material fingerprint fields:

1. tenant ID;
2. branch ID;
3. terminal profile ID;
4. terminal binding epoch;
5. offline transaction UUID;
6. local sequence or offline sequence number;
7. cashier/user ID;
8. shift and drawer evidence where policy requires them;
9. item product IDs;
10. quantities;
11. price and tax snapshots used offline;
12. allowed discount snapshots used offline;
13. cash payment total and tender breakdown;
14. customer identity snapshot where allowed;
15. catalog, tax, payment method, and terminal policy snapshot hashes;
16. captured-at device time and timezone evidence where included in policy.

Fields that must not be part of the material fingerprint:

1. retry count;
2. last error;
3. sync attempt timestamp;
4. queue lease ID;
5. attempt generation;
6. queue state revision;
7. support notes;
8. local mutable queue status.

If the client submits an `offline_transaction_uuid` already known to the server:

1. matching fingerprint means exact replay;
2. different fingerprint means drift and must be rejected or review-required before mutation;
3. the server must not mutate the existing accepted sale, payment, inventory, loyalty, or accounting consequences.

## 4. Server Persistence Model

Implementation must add durable server evidence if existing tables cannot represent the contract.

Minimum persisted envelope evidence:

```text
offline_transaction_uuid
tenant_id
branch_id
sales_machine_profile_id
terminal_binding_epoch
local_sequence/offline_sequence_number
batch_reference
business_payload_fingerprint
fingerprint_algorithm
fingerprint_schema_version
raw_payload
sync_contract_version
server_sync_status
original_sync_status
review_reason
retryable_error_code
cash_status
resolution_status
reconciled_sale_id
official_invoice_number
consequence_status_snapshot
acceptance_consequence_snapshot
current_consequence_status
first_seen_at
last_replayed_at
accepted_at
rejected_at
review_required_at
```

Required uniqueness:

```text
tenant_id + offline_transaction_uuid
tenant_id + sales_machine_profile_id + terminal_binding_epoch + local_sequence
```

The same UUID submitted under another branch, terminal, epoch, or cashier context is identity drift, not a new transaction. A globally unique offline UUID is also acceptable, but tenant-scoped UUID uniqueness is the minimum required guardrail.

Insert-or-lock race strategy:

1. attempt to insert the envelope under the tenant UUID unique constraint;
2. on unique conflict, reload the existing row with `lockForUpdate`;
3. compare server-computed fingerprint and stored context;
4. return replay, drift, review, or rejection from the locked row.

Do not rely on check-then-insert logic without a database uniqueness guard.

Recommended supporting indexes:

```text
tenant_id + branch_id + sales_machine_profile_id + terminal_binding_epoch + local_sequence
tenant_id + branch_id + business_payload_fingerprint
server_sync_status
review_reason
reconciled_sale_id
```

Older `payload_hash` and `status` fields may remain, but new code must not depend on ambiguous status names when producing the Epic 41 response contract.

The server should persist the first-seen envelope before business classification once outer authentication and tenant/branch/terminal trust are established. Authenticated envelopes that reach business classification should leave durable evidence even if they are later rejected or marked review-required. Malformed unauthenticated payloads do not need business-envelope persistence.

Server-side attempt history must be persisted separately from the envelope outcome. The envelope row owns the stable authoritative outcome, sale identity, review or rejection outcome, and current sync status. Attempt rows own attempt ID, lease ID, request time, response time, transient error, HTTP result, worker identity, retryability, and deadlock or timeout evidence.

Recommended stores:

```text
offline_sales_imports
offline_sync_attempts
consequence_status_events
```

`retryable_failed` is an attempt outcome, not a terminal envelope replay result. If the same UUID and fingerprint are resubmitted after a prior `retryable_failed`, the server should attempt processing again when retry policy permits.

Raw payload storage must be protected:

1. encrypt database storage where platform policy requires it;
2. restrict raw-payload access by role;
3. define retention, purge, or archival period;
4. mask support views;
5. store no card data;
6. store no approval PINs, passwords, or credentials;
7. redact payloads from logs;
8. exclude raw payload from ordinary audit events unless explicitly approved.

## 5. Server Status Taxonomy

Top-level envelope sync status values:

```text
accepted
replayed
retryable_failed
review_required
rejected
```

Do not return `accepted_with_pending_loyalty` or any other consequence-specific condition as a top-level status.

Status rules:

1. `accepted` means this request caused the server to commit the envelope and required consequences are complete or durably represented by approved pending consequence evidence.
2. `replayed` means a previous request with the same UUID and fingerprint already committed or reached a terminal result, and this response returns that stable prior result with `original_sync_status`.
3. `retryable_failed` means no business mutation was committed and retrying the same UUID/fingerprint is safe.
4. `review_required` means automatic retry is unsafe; support or an approved workflow must resolve the record.
5. `rejected` means the server has determined the envelope cannot be posted and no ordinary retry should occur.

Exact replay convention:

```text
first accepted request:
sync_status = accepted

later exact replay:
sync_status = replayed
original_sync_status = accepted

later replay of rejected/review-required envelope:
sync_status = replayed
original_sync_status = rejected or review_required
```

`original_sync_status` means the original server processing outcome. It is not a client-supplied queue status from the physical terminal.

Review reasons include:

```text
review_required_cash_collected
review_required_suspected_duplicate
review_required_terminal_revoked
review_required_clock_drift
review_required_business_date
review_required_policy_drift
review_required_stock_policy
review_required_consequence_incomplete
```

Rejected reasons include:

```text
rejected_invalid_contract
rejected_cross_tenant_or_branch
rejected_non_cash_tender
rejected_statutory_discount_offline
rejected_missing_required_evidence
rejected_fingerprint_drift
rejected_terminal_identity_invalid
rejected_offline_window_expired
```

## 6. SaleCreationService Boundary

`SaleCreationService` remains the exclusive authority for committed sale creation.

Story 41.3 must not leave offline sync as a parallel sales engine. In particular, offline sync must not directly recreate or duplicate the logic for:

1. sale creation;
2. sale item creation;
3. tax and discount compliance records;
4. official invoice identity allocation;
5. receipt data;
6. inventory deduction or recipe movement consequences;
7. loyalty accrual or redemption consequences;
8. store-credit ledger consequences;
9. accounting outbox evidence.

Implementation should introduce a dedicated offline checkout snapshot or DTO if needed, such as:

```text
OfflineCheckoutSnapshot
```

Minimum snapshot contents:

```text
tenant_id
branch_id
user_id
sales_machine_profile_id
offline_transaction_uuid
terminal_binding_epoch
local_sequence
business_payload_fingerprint
items
payment snapshot
price/tax/discount snapshots
cashier shift and drawer evidence
captured_at_device
resolved business-date inputs
sync contract version
offline evidence references
```

The snapshot is the stable handoff from offline synchronization to the sales domain. The sales domain may reject the snapshot, but offline sync must not bypass it.

Anti-corruption layer:

```text
OfflineCheckoutSnapshot
        ↓
OfflineCheckoutAdapter
        ↓
SaleCreationService
```

The adapter may translate cached commercial snapshots, capture-time tax evidence, terminal identity, cashier shift, cash tender, customer reference, and local transaction identity into the existing checkout contract. The adapter must not perform sale posting itself.

## 7. Transaction Boundary

Each envelope must execute inside one server-side transaction where the architecture supports synchronous consequences.

Required order:

```text
begin transaction
        ↓
insert or lock envelope identity
        ↓
detect exact replay or drift
        ↓
validate terminal/tenant/branch/cashier/shift evidence
        ↓
validate cash-only and online-only restrictions
        ↓
build immutable offline checkout snapshot
        ↓
call SaleCreationService
        ↓
record payment, inventory, accounting, loyalty, and receipt consequences through existing services
        ↓
persist consequence status snapshot
        ↓
mark envelope accepted
        ↓
commit
```

For each envelope, the row-lock sequence must be:

```text
Begin transaction
        ↓
Insert or lock offline import identity
        ↓
Check exact replay
        ↓
Reject same-UUID fingerprint drift
        ↓
Evaluate suspected duplicate evidence
        ↓
Validate terminal, branch, cashier, shift, cash-only policy
        ↓
Create OfflineCheckoutSnapshot
        ↓
Call SaleCreationService
        ↓
Persist consequence snapshot and accepted state
        ↓
Commit
```

If a required synchronous consequence fails:

1. roll back the transaction;
2. return `retryable_failed` only when retry is safe and no business mutation committed;
3. return `review_required` when cash may have been collected or duplicate/partial uncertainty exists;
4. never return `accepted`.

If a consequence is intentionally asynchronous:

1. the asynchronous handoff must be durably recorded in the same transaction;
2. the consequence status must expose `pending`;
3. replay must not enqueue duplicate work;
4. support diagnostics must show the pending or failed consequence;
5. cashier/customer messaging must not imply final completion when policy requires pending disclosure.

Required consequence policy:

| Consequence | Required mode |
| --- | --- |
| Sale | Synchronous committed |
| Cash payment | Synchronous committed |
| Invoice identity | Synchronous committed |
| Inventory | Synchronous committed, or same-transaction durable command only if the existing architecture already supports it |
| Variance | Synchronous with inventory effect |
| Loyalty | Synchronous or durable outbox |
| Store credit | Not applicable for first-release offline sale |
| Receipt | Synchronous data availability |
| Accounting | Durable outbox permitted |

This table prevents treating every `pending` consequence as acceptable. `pending` is valid only for a consequence explicitly approved for durable asynchronous handling.

Invoice allocation must be committed atomically with the sale or use an approved sequence mechanism that cannot allocate the same invoice number twice. If invoice numbers are reserved before transaction completion, implementation must follow the approved fiscal numbering policy for rollback gaps, cancelled numbers, or reserved-but-unused numbers.

Asynchronous outbox effects must have explicit uniqueness rules, such as:

```text
UNIQUE sale_id + consequence_type
```

or:

```text
UNIQUE offline_transaction_uuid + consequence_type
```

Example effect keys:

```text
offline_transaction_uuid + loyalty_accrual
offline_transaction_uuid + accounting_post
offline_transaction_uuid + receipt_notification
```

Per-envelope database deadlocks must retry the entire database transaction a small bounded number of times using the same UUID and fingerprint. Exhausted deadlock retries are recorded in attempt history and returned as `retryable_failed`; the implementation must never retry only part of the transaction.

## 8. Consequence Status Schema

Every envelope result must include a strict consequence status object.

Recommended response shape:

```json
{
  "offline_transaction_uuid": "uuid",
  "offline_sequence_number": "MAIN-1",
  "sync_status": "accepted",
  "server_sale_uuid": "uuid",
  "server_sale_number": "S-000001",
  "official_invoice_number": "INV-000001",
  "local_reference": "OFF-MAIN-000001",
  "business_payload_fingerprint": "sha256...",
  "original_sync_status": null,
  "consequence_status": {
    "sale": "committed",
    "payment": "committed",
    "inventory": "committed",
    "variance": "not_applicable",
    "loyalty": "pending",
    "store_credit": "not_applicable",
    "receipt": "available",
    "accounting_outbox": "queued"
  },
  "review_reason": null,
  "retryable_error_code": null,
  "contract_version": "epic-41-sync-v1"
}
```

Allowed consequence values:

```text
committed
queued
pending
available
not_applicable
failed
blocked
```

`sync_status = accepted` is allowed only when every required consequence is `committed`, `queued`, `pending`, or `not_applicable` according to the documented consequence-completeness policy. A consequence with `failed` or `blocked` must not be hidden behind `accepted`.

Accepted responses return the stored acceptance-time consequence snapshot. Later asynchronous completion or failure must update a separate current status projection and append consequence-status events instead of silently rewriting the original acceptance snapshot.

Recommended separation:

```text
acceptance_consequence_snapshot
current_consequence_status
consequence_status_events
```

## 9. Exact Replay Contract

Exact replay means:

```text
same tenant
same branch
same terminal profile
same terminal binding epoch
same offline_transaction_uuid
same business_payload_fingerprint
```

Exact replay must:

1. return HTTP 200;
2. return `sync_status = replayed` and `original_sync_status` with the stored server outcome;
3. return the same sale UUID, sale number, invoice number, local reference, consequence status, and review/rejection reason as the original terminal result;
4. update only replay metadata such as `last_replayed_at`;
5. create no duplicate sale, payment, inventory, loyalty, store-credit, accounting, receipt, or outbox consequences.

Replay after lost response must be safe:

```text
request reaches server
server commits accepted sale
response is lost
client retries same UUID/fingerprint
server returns replayed with original identities
```

## 10. Drift and Suspected Duplicate Handling

Fingerprint drift with the same UUID must be rejected before mutation.

Suspected duplicate with different UUID must enter review unless exact replay can be proven.

Duplicate detection evidence may include:

1. tenant;
2. branch;
3. terminal binding epoch;
4. cashier;
5. local sequence proximity;
6. captured-at time window;
7. cart fingerprint;
8. total amount;
9. cash amount;
10. local receipt/reference number.

The server must not auto-post a suspected duplicate merely because the UUID is different.

First-release suspected duplicate detection should use high-confidence rules only and should not broadly fuzzy-match normal sales. Useful evidence includes same terminal epoch, same cashier, near-identical captured time, same cart fingerprint, same sale total, same cash amount, adjacent or conflicting local sequence, and same local reference.

Review output should include:

```text
duplicate_score
duplicate_rule_ids
suspected_duplicate_import_id
```

Do not auto-reject suspected duplicates unless a later policy explicitly permits it.

## 11. Cash-Collected Review Handling

Cash-collected records that cannot safely post must be preserved.

Rules:

1. do not delete or overwrite the original envelope;
2. retain raw payload, fingerprint, local reference, terminal evidence, and cash status;
3. return `review_required` when the system cannot safely decide automatically;
4. include `review_reason` and support-safe diagnostics;
5. prevent ordinary automatic retry of review-required records;
6. keep the record visible for shift close and support resolution;
7. do not consume official invoice identity unless the sale is actually committed or a formal compliance policy requires reserved numbering.

Rejected versus review-required decision matrix:

| Condition | No cash collected | Cash collected |
| --- | --- | --- |
| Non-cash tender in offline envelope | Rejected | Review required if operator evidence is inconsistent |
| Expired offline window | Rejected or review by policy | Review required |
| Terminal revoked after valid capture | Review required | Review required |
| Same UUID fingerprint drift | Rejected security drift | Review required if cash ownership is uncertain |
| Suspected duplicate | Review required | Review required |

Cash-collected envelopes favor preservation and review over silent rejection when physical cash or goods may already have changed hands.

## 12. HTTP Response Policy

Recommended endpoint responses:

| Condition | HTTP status |
| --- | ---: |
| Synchronous processing completed and all envelope results are returned | 200 |
| Exact batch or envelope replay | 200 |
| Durable intake accepted and processing continues asynchronously with status resource | 202 |
| Request validation failure | 422 |
| Unauthorized or missing tenant/branch/terminal context | 403 |
| Cross-tenant or hidden branch/terminal resource | 404 |
| Per-envelope drift, review, or rejection with a valid synchronous batch request | 200 with per-envelope status |
| Transient server failure before any result can be persisted | 500 |

Valid per-envelope business failures should normally be represented in the import result rather than failing the whole batch, unless the request cannot be trusted at all.

Use `202` only when processing genuinely continues after the HTTP request and the response includes a status resource, such as:

```text
GET /api/v1/pos/offline-sales/{offline_transaction_uuid}/sync-status
GET /api/v1/pos/offline-sales/sync-batches/{batch_reference}
```

Do not use `202` merely because the request contains a batch.

Status lookup authorization rules:

1. tenant context is mandatory;
2. authorized support roles may inspect only permitted branches;
3. terminal-bound callers may look up only their own terminal epoch records;
4. cross-tenant UUIDs return hidden-resource behavior;
5. the lookup must not reveal whether another tenant has the UUID;
6. the endpoint returns masked support-safe data according to role.

## 13. Frontend Sync Contract

`offlineSyncManager.ts` must be updated during implementation to understand the Epic 41 server statuses.

Required mapping:

```text
accepted -> local synced / server_state accepted
replayed -> local synced / server_state replayed
retryable_failed -> local failed or retry_scheduled
review_required -> local conflict or blocked with server_state review_required
rejected -> local conflict or processing_complete with server_state rejected
```

Compatibility mapping for older server responses may remain temporarily:

```text
server_verified -> accepted
duplicate -> replayed
conflict -> review_required when conflict_notes.sync_status = review_required
posted -> accepted
```

The client must preserve stale-response guards from Story 41.2:

1. `lease_id`;
2. `sync_attempt_id`;
3. `attempt_generation`;
4. `queue_state_revision`.

A stale worker response must not overwrite a newer queue state unless it is safely recognized as the same UUID/fingerprint accepted or replayed result.

## 14. Audit and Diagnostics

Every terminal result must produce server-side audit evidence.

Audit event examples:

```text
offline_sync_envelope_accepted
offline_sync_envelope_replayed
offline_sync_envelope_rejected
offline_sync_envelope_review_required
offline_sync_envelope_retryable_failed
offline_sync_fingerprint_drift_rejected
offline_sync_suspected_duplicate_review_required
```

Minimum audit payload:

```text
tenant_id
branch_id
sales_machine_profile_id
terminal_binding_epoch
offline_transaction_uuid
offline_sequence_number
business_payload_fingerprint
batch_reference
cashier_id
shift_id
sync_attempt_id
lease_id
attempt_generation
server_sync_status
original_sync_status
review_reason
retryable_error_code
server_sale_uuid
official_invoice_number
consequence_status_snapshot
```

Audit must not log full sensitive payment or customer payloads unnecessarily.

## 15. OpenAPI and Provider-Grade Contract Documentation

Story 41.3 must publish an OpenAPI fragment or generated endpoint documentation covering:

1. request versions;
2. required fields;
3. response statuses;
4. consequence values;
5. review and rejection codes;
6. exact replay behavior;
7. drift behavior;
8. batch semantics;
9. status lookup endpoint;
10. asynchronous `202` status-resource behavior if implemented.

This documentation is part of the Mosaic-style API-first contract and must stay aligned with the implementation tests.

## 16. Migration and Compatibility Notes

Implementation should expect older tests and code paths to reference:

```text
pending
validated
server_verified
duplicate
conflict
accepted_with_warning
posted
rejected
```

The implementation should either:

1. introduce new explicit fields such as `server_sync_status`, `review_reason`, and `consequence_status_snapshot`; or
2. migrate `status` semantics carefully with backwards-compatible accessors and updated tests.

Preferred approach:

```text
status = existing import lifecycle state
server_sync_status = Epic 41 envelope result
consequence_status_snapshot = strict consequence status evidence
```

This avoids overloading one column with intake, validation, review, posting, and replay semantics.

Preferred facade during migration:

```text
Epic41OfflineSyncFacade
```

The facade should normalize old request fields, produce the server-computed fingerprint, call the new replay decision engine, route posting through `SaleCreationService`, and map results back to older clients only where necessary. It should have a retirement target so legacy `server_verified`, `duplicate`, or `posted` states do not keep leaking into the Epic 41 client contract.

## 17. Test Requirements

Backend feature tests must cover:

1. new valid offline cash envelope returns `accepted` and stable official identities;
2. exact replay by UUID and fingerprint returns no duplicate sale;
3. replay after simulated lost response returns the original sale and invoice identities;
4. same UUID with different fingerprint is rejected before mutation;
5. different UUID with suspected duplicate evidence enters review;
6. non-cash tender is rejected before mutation;
7. offline statutory discount is rejected before mutation;
8. inactive or cross-branch terminal fails closed;
9. revoked-after-capture terminal follows review policy rather than auto-posting;
10. `SaleCreationService` is used as the committed sale authority;
11. payment consequence is not duplicated on replay;
12. inventory consequence is not duplicated on replay;
13. accounting outbox is not duplicated on replay;
14. loyalty consequence is committed or represented by durable pending evidence without duplicate replay;
15. cash-collected unsafe records return `review_required` and remain preserved;
16. transient failure before mutation returns retryable behavior;
17. partial consequence failure rolls back or produces approved durable pending evidence;
18. response schema includes the strict consequence status object.
19. same UUID submitted with another branch, terminal, epoch, or cashier is identity drift and cannot create another sale;
20. server recomputes the canonical fingerprint and rejects or reviews mismatches before mutation;
21. concurrent first submission of the same UUID creates one sale and returns replay for the loser;
22. synchronous completion returns HTTP 200, while HTTP 202 is used only with durable asynchronous status lookup;
23. status lookup returns stable result without requiring full payload resubmission;
24. legacy internal statuses are isolated from Epic 41 top-level response statuses.
25. retryable attempt reprocessing is allowed for prior `retryable_failed` outcomes when policy permits;
26. attempt history is traceable separately from the envelope's stable authoritative outcome;
27. outbox effect keys prevent duplicate asynchronous consequences on replay;
28. consequence status history preserves the original acceptance snapshot and later status changes;
29. bounded deadlock retry reprocesses the entire envelope transaction under the same UUID and fingerprint.

Frontend tests must cover:

1. `accepted` maps to completed local queue state;
2. `replayed` maps to completed local queue state without changing local identity;
3. `review_required` does not auto-retry as a network failure;
4. `rejected` does not auto-retry as a network failure;
5. `retryable_failed` remains retryable under Story 41.2 backoff rules;
6. stale attempt response cannot overwrite newer queue state;
7. legacy `server_verified` and `duplicate` responses remain safely mapped during the migration window if compatibility is retained.

Suggested focused commands:

```bash
php artisan test tests/Feature/POS/OfflineSyncValidationTest.php
php artisan test tests/Feature/POS/OfflineSyncIdempotencyTest.php
php artisan test tests/Feature/POS/OfflineImportPostingTest.php
php artisan test tests/Feature/POS/OfflineSalesAuditPayloadTest.php
node tests/Frontend/offlineQueueSync.test.js
```

The implementation PR may add a dedicated test file such as:

```text
tests/Feature/POS/OfflineSyncAtomicityTest.php
tests/Feature/POS/OfflineSyncReplayContractTest.php
tests/Feature/POS/OfflineSyncStatusLookupTest.php
```

## 18. Acceptance Checks

1. Exact replay creates no duplicate sale, payment, inventory, loyalty, store-credit, accounting, receipt, or outbox consequences.
2. Drift is rejected before mutation.
3. New accepted sync returns stable server sale and official invoice references.
4. `SaleCreationService` remains the authority for committed sale creation.
5. Accepted status is returned only when required consequences are complete or explicitly represented by durable pending state evidence.
6. Local reference, server sale identity, and official invoice identity remain separate.
7. Consequence-specific pending states live in consequence status fields, not top-level sync status.
8. Suspected duplicate business captures enter review unless exact replay can be proven.
9. Cash-collected records that cannot safely post are preserved for support resolution.
10. Records captured before terminal/profile revocation follow explicit server policy and never auto-transfer to a replacement terminal.
11. Per-envelope server transactions are independent; one bad envelope does not roll back an entire trusted batch.
12. The frontend queue can apply accepted, replayed, retryable, review-required, and rejected results without stale-response corruption.
13. Existing offline sync tests are updated to the Epic 41 status contract rather than silently preserving obsolete status names.
14. The same offline transaction UUID submitted with another branch, terminal, epoch, or cashier context is treated as identity drift or review and cannot create another sale.
15. The server recomputes the canonical fingerprint and rejects or reviews mismatches before mutation.
16. Concurrent first submissions for the same UUID are protected by unique insert plus locked reload.
17. Completed synchronous processing returns HTTP 200; HTTP 202 is used only for asynchronous processing with a status resource.
18. Accepted envelopes satisfy the required-consequence policy.
19. Status lookup by offline transaction UUID returns stable current result without resubmitting the business payload or creating consequences.
20. Epic 41 clients see only `accepted`, `replayed`, `retryable_failed`, `review_required`, or `rejected` as top-level statuses.
21. A prior `retryable_failed` attempt can be reprocessed with the same UUID and fingerprint when retry policy permits.
22. Multiple synchronization attempts remain separately traceable without overwriting the envelope's stable authoritative outcome.
23. Accepted envelope replay cannot create duplicate outbox commands for the same sale and consequence type.
24. Later asynchronous consequence changes preserve the original acceptance snapshot and append separate status evidence.
25. Database deadlock retry retries the entire per-envelope transaction and commits no partial consequence.

## 19. Implementation Slicing

Recommended PR sequence:

1. Add durable schema fields and constants for Epic 41 envelope status, original sync status, fingerprint, replay, consequence status, and review reasons.
2. Add tenant-scoped UUID uniqueness, terminal-epoch sequence uniqueness, and server-computed fingerprint fields.
3. Update request validation and normalization to require or safely migrate the Story 41.2 envelope fields.
4. Implement insert-or-lock idempotency, row locking, exact replay, and drift rejection.
5. Add suspected duplicate review detection.
6. Route committed posting through `SaleCreationService` using an offline snapshot adapter.
7. Add required-consequence policy enforcement, acceptance consequence snapshot, current consequence status, consequence status events, and response schema.
8. Add status lookup endpoint and OpenAPI contract fragment.
9. Update frontend sync result mapping and stale-response handling for Epic 41 statuses.
10. Add replay, atomicity, drift, duplicate, concurrency, deadlock retry, status lookup, cash-collected review, outbox idempotency, and frontend mapping tests.
11. Update documentation notes and status after local review.

## 20. Fallback Approaches

### Fallback A - Synchronous Server Orchestration

Use when all required consequences can commit within one practical database transaction:

```text
offline sync
→ SaleCreationService
→ payment/inventory/invoice
→ outbox rows
→ accepted response
```

This is the preferred first-release fallback because it has the smallest operational state space.

### Fallback B - Durable Command Plus Worker

Use when sale creation is too slow or operationally unsafe inside the request:

```text
POST envelope
→ durable verified import
→ 202 with batch/envelope status resource
→ worker locks import
→ SaleCreationService
→ status lookup
```

Requirements:

1. durable intake before `202`;
2. one worker owner;
3. exact replay returns same intake identity;
4. status lookup;
5. no accepted result before committed sale;
6. cash-collected records remain visible.

### Fallback C - Legacy Facade During Migration

Keep the existing endpoint and database lifecycle temporarily, but add `Epic41OfflineSyncFacade` to route decisions through the new contract. This reduces migration risk but should have a retirement date.

### Fallback D - Manual Review Quarantine

Use when existing sale services cannot safely consume certain old envelopes:

```text
legacy or incompatible envelope
→ persisted
→ review_required
→ no automatic posting
```

This is safer than preserving the old manual posting engine.

## 21. Definition of Done

Story 41.3 is done when:

1. acceptance checks pass;
2. all required backend feature tests pass;
3. required frontend queue mapping tests pass;
4. exact replay and drift tests prove no duplicate consequences;
5. transaction rollback tests prove incomplete sale consequences cannot be reported as accepted;
6. `SaleCreationService` boundary is verified in code review;
7. audit payloads and diagnostics are available for accepted, replayed, rejected, review-required, and retryable results;
8. OpenAPI or generated endpoint documentation is updated;
9. status lookup behavior is tested;
10. CI passes before merge;
11. story status and implementation notes are updated.

## 22. Implementation Record

Status:

```text
Implemented - Local Verification Complete
```

Implementation summary:

1. Added Epic 41 server synchronization persistence fields to `offline_sales_imports`.
2. Added `offline_sync_attempts` for attempt history separate from envelope outcome.
3. Added `OfflineEnvelopeSynchronizationService` for the v1 API-first sync contract.
4. Kept the legacy `/api/pos/offline-sync` validation-only endpoint behavior intact for older support flows.
5. Added `/api/v1/pos/offline-sales/sync` for server-authoritative accepted/replayed/rejected/review-required results.
6. Added `/api/v1/pos/offline-sales/{offline_transaction_uuid}/sync-status` lookup.
7. Added server-computed fingerprint persistence and replay/drift handling.
8. Added acceptance/current consequence snapshots and attempt tracking.
9. Routed v1 sale creation through `SaleCreationService` rather than the legacy manual posting path.
10. Updated the browser sync manager to submit to the v1 endpoint and understand Epic 41 statuses.
11. Added OpenAPI contract documentation for v1 sync and status lookup.

Files changed:

1. `app/Http/Controllers/POS/OfflineSyncController.php`
2. `app/Http/Requests/POS/SyncBatchRequest.php`
3. `app/Models/OfflineSalesImport.php`
4. `app/Models/OfflineSyncAttempt.php`
5. `app/Services/POS/OfflineSync/OfflineEnvelopeSynchronizationService.php`
6. `database/migrations/2026_07_17_120000_add_epic_41_sync_contract_to_offline_sales_imports.php`
7. `routes/api.php`
8. `resources/js/POS/offline/offlineSyncManager.ts`
9. `tests/Feature/POS/OfflineSyncEpic41ContractTest.php`
10. `tests/Frontend/offlineQueueSync.test.js`
11. `docs/api/pos-terminal-sync.openapi.yaml`
12. `docs/implementation-plans/epic-41/epic-41-implementation-guide.md`
13. `docs/implementation-plans/epic-41/stories/README.md`
14. `docs/implementation-plans/epic-41/stories/story-41.3-server-synchronization-idempotency-and-transaction-atomicity.md`

Local verification:

```bash
php artisan test tests/Feature/POS/OfflineSyncEpic41ContractTest.php tests/Feature/POS/OfflineSyncValidationTest.php tests/Feature/POS/OfflineSyncIdempotencyTest.php tests/Feature/POS/OfflineSalesAuditPayloadTest.php tests/Feature/POS/OfflineSyncStatusWorkflowTest.php
```

Result:

```text
32 tests passed, 128 assertions
```

```bash
node tests/Frontend/offlineQueueSync.test.js
```

Result:

```text
10 tests passed
```

```bash
php artisan test tests/Feature/Admin/OfflineImportPostingTest.php tests/Feature/Admin/OfflineImportReviewTest.php tests/Feature/POS/LateSyncReconciliationTest.php
```

Result:

```text
26 tests passed, 105 assertions
```

```bash
php artisan test tests/Feature/POS/OfflineSyncFoundationTest.php tests/Feature/POS/OfflineImportRecalculationTest.php tests/Feature/POS/BranchPaymentPolicyTest.php
```

Result:

```text
38 tests passed, 134 assertions
```

```bash
php artisan test tests/Feature/POS
```

Result:

```text
475 tests passed, 1810 assertions
```
