# Story 41.2 Offline Transaction Queue Integrity

## Status

Approved for Implementation

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Harden local queue identity, persistence, diagnostics, and support visibility for offline provisional sales.

Story 41.2 owns local durability and queue integrity only. It does not decide whether a queued envelope is accepted by the server and must not interpret a locally stored transaction as a committed sale.

## Evidence Boundary

Public competitor evidence validates the operational need for offline queue integrity:

1. UTAK supports offline capture and later synchronization while warning that central consequences depend on timely sync.
2. StoreHub supports the operator expectation that offline sales are retained and later synchronized.
3. Mosaic validates visible connectivity, transaction synchronization status, cashier/terminal context, audit history, and support intervention for abnormal date or terminal states.

Public competitor documentation does not establish IPOS's queue UUID, terminal epoch, immutable fingerprint, lease, retry, tombstone, or multi-tab race-control architecture. Those remain IPOS-specific safeguards.

## Dependencies

Requires:

1. Story 41.1.
2. Existing IndexedDB/local queue implementation.
3. Existing queue diagnostics.
4. Existing terminal identity binding.

## Complexity

Large

## Non-Reopened Policy Decisions

Story 41.2 must consume Story 41.1 as a non-negotiable contract and must not reopen:

1. offline tender scope,
2. statutory discount blocking,
3. local cancellation blocking after durable cash capture,
4. final shift-close blocking while unresolved records exist,
5. no local stock deduction,
6. receipt authority,
7. loyalty promise restrictions.

## Scope

In scope:

1. Terminal-epoch queue identity.
2. Local sequence allocation.
3. Immutable envelope persistence.
4. Mutable queue projection.
5. Append-only state events.
6. Transactional IndexedDB writes.
7. Read-back verification.
8. Business payload fingerprint and local checksum metadata.
9. Single-writer lease.
10. Retry scheduling.
11. Queue limits.
12. Capture-uncertain recovery.
13. Accepted tombstones.
14. Retention and compaction.
15. Operator dashboard data.
16. Support diagnostics and export safeguards.

Out of scope:

1. Server posting behavior.
2. Server idempotency implementation.
3. Hardware behavior.
4. Non-cash offline payment.
5. Official invoice creation.
6. Offline void/refund implementation.

## 1. Queue Data Model

The local queue must separate immutable business evidence from mutable operational state.

Minimum local stores:

| Store | Responsibility |
| --- | --- |
| `offline_envelopes` | Immutable canonical business payload |
| `offline_queue_state` | Current mutable queue projection |
| `offline_status_events` | Append-only transition history |
| `offline_sync_attempts` | Request/result/error evidence |
| `offline_tombstones` | Compacted accepted identity |
| `offline_queue_meta` | Sequence counter, terminal epoch, schema version |

The immutable envelope and mutable state must not be stored as one repeatedly overwritten object.

Recommended indexes:

```text
offline_envelopes:
- offline_transaction_uuid
- [terminal_id, terminal_binding_epoch, local_sequence]

offline_queue_state:
- queue_status
- server_status
- resolution_status
- retention_status
- next_retry_at
- lease_expires_at
- cashier_id
- shift_id

offline_status_events:
- offline_transaction_uuid
- occurred_at

offline_sync_attempts:
- offline_transaction_uuid
- sync_attempt_id
- attempt_started_at

offline_tombstones:
- offline_transaction_uuid
- server_sale_uuid
```

## 2. State Dimensions

Story 41.2 must not overload one queue-state enum with persistence, processing, server outcome, resolution, and retention meanings.

Required state dimensions:

### Local Persistence State

```text
creating
persisting
durably_captured
capture_uncertain
storage_failed
```

### Queue Processing State

```text
pending
leased
syncing
retry_scheduled
blocked
processing_complete
```

### Server Outcome State

```text
not_submitted
accepted
replayed
retryable_failed
review_required
rejected
```

### Resolution State

```text
none
pending_support
resolved_posted
resolved_cash_returned
resolved_rejected
```

### Retention State

```text
full_payload
retained_full
compacted
purged
```

Example projection:

```json
{
  "persistence_status": "durably_captured",
  "queue_status": "pending",
  "server_status": "not_submitted",
  "resolution_status": "none",
  "retention_status": "full_payload"
}
```

Each state dimension must define:

1. legal previous states,
2. legal next states,
3. whether automatic retry is allowed,
4. whether cashier action is allowed,
5. whether final shift close is blocked,
6. whether support action is required.

### Legal Transition Matrices

Persistence transitions:

```text
creating -> persisting
persisting -> durably_captured
persisting -> capture_uncertain
persisting -> storage_failed
capture_uncertain -> durably_captured
capture_uncertain -> storage_failed
```

Forbidden persistence transitions:

```text
durably_captured -> persisting
durably_captured -> storage_failed
```

A later corruption issue is diagnostic/support evidence and must not rewrite original capture history.

Queue processing transitions:

```text
pending -> leased
leased -> syncing
leased -> pending
syncing -> pending
syncing -> retry_scheduled
syncing -> blocked
syncing -> processing_complete
retry_scheduled -> leased
blocked -> pending
blocked -> processing_complete
```

`processing_complete` means local queue processing is done for this record because the server result, rejection, or support-resolution state no longer allows ordinary sync processing.

Server outcome transitions:

```text
not_submitted -> retryable_failed
not_submitted -> accepted
not_submitted -> replayed
not_submitted -> review_required
not_submitted -> rejected

retryable_failed -> retryable_failed
retryable_failed -> accepted
retryable_failed -> replayed
retryable_failed -> review_required
retryable_failed -> rejected
```

After `accepted`, `replayed`, `review_required`, or `rejected`, ordinary synchronization must not replace the result without a formal support or resolution event.

Resolution transitions:

```text
none -> pending_support
pending_support -> resolved_posted
pending_support -> resolved_cash_returned
pending_support -> resolved_rejected
```

Retention transitions:

```text
full_payload -> retained_full
retained_full -> compacted
compacted -> purged
```

Review-required, cash-collected, and capture-uncertain records must not enter compaction unless formally resolved and retention rules allow it.

## 3. Queue Identity

Queue identity must include:

1. `offline_transaction_uuid`,
2. `terminal_id`,
3. `terminal_binding_epoch`,
4. `local_sequence`.

Uniqueness rules:

```text
UNIQUE terminal_id + terminal_binding_epoch + local_sequence
UNIQUE offline_transaction_uuid
```

Sequence rules:

1. local sequence starts at `1` for each terminal binding epoch,
2. sequence is allocated only inside the atomic capture transaction,
3. sequence never decreases within one epoch,
4. sequence reset is not allowed within one epoch,
5. gaps are allowed only when documented by failed or uncertain capture evidence,
6. counter corruption blocks new capture and requires recovery,
7. a new terminal epoch starts a separate sequence namespace.

## 4. Atomic Local Capture

A locally captured transaction exists only when all required local records commit atomically.

The following must happen within one IndexedDB transaction:

1. allocate the next local sequence,
2. persist the immutable envelope,
3. persist the initial queue projection,
4. append the initial capture event,
5. update the terminal epoch sequence counter.

The transaction commits all five or none.

IndexedDB transaction rules:

1. all atomic capture writes occur in one read-write transaction,
2. no asynchronous network operation occurs inside that transaction,
3. envelope construction and fingerprint generation happen before opening the write transaction where practical,
4. sequence allocation and writes happen inside the transaction,
5. the application treats transaction completion as the commit signal,
6. read-back verification occurs after completion,
7. transaction abort leaves no partial records.

Invalid partial states include:

1. envelope exists without queue state,
2. local sequence advances but no envelope exists,
3. queue state exists without business payload,
4. capture event is missing,
5. duplicate local sequence is allocated after restart.

## 5. Durability and Read-Back

Read-back verification confirms that the browser transaction committed and the record is immediately retrievable.

It does not:

1. convert browser storage into the official ledger,
2. guarantee survival after device loss,
3. guarantee survival after browser profile deletion,
4. guarantee survival after storage eviction,
5. prove malicious tamper resistance.

Cashier messaging must not imply official posting or permanent central record creation.

## 6. Fingerprint and Checksum

### Business Payload Fingerprint

The business payload fingerprint:

1. is generated from a canonical serialized envelope,
2. excludes mutable queue state,
3. uses stable key ordering,
4. uses normalized decimal representation,
5. uses normalized timestamp representation,
6. handles explicit nulls consistently,
7. uses a versioned algorithm,
8. is used for server idempotency and drift detection.

Canonical serialization rules:

1. decimals use base-10 canonical strings,
2. decimals use no scientific notation,
3. decimals use no locale separators,
4. each monetary or quantity domain uses either fixed scale or a documented trailing-zero normalization rule,
5. timestamps use ISO 8601,
6. timestamps include explicit timezone offset or UTC `Z`,
7. timestamps use fixed fractional-second precision,
8. arrays preserve semantically defined ordering,
9. object keys use stable lexicographic ordering,
10. strings use a documented Unicode normalization form,
11. missing values and explicit `null` are treated consistently by schema version,
12. booleans serialize as JSON booleans,
13. currency codes use uppercase ISO-style codes.

### Queue Integrity Checksum

The queue integrity checksum:

1. detects accidental local corruption,
2. may cover the stored immutable envelope and selected local metadata,
3. is verified before synchronization,
4. is not presented as proof against malicious tampering.

Required metadata:

```text
fingerprint_algorithm
fingerprint_schema_version
checksum_algorithm
checksum_schema_version
```

## 7. Lease and Race Model

Lease fields:

```text
lease_id
queue_owner_instance_id
lease_acquired_at
lease_expires_at
lease_heartbeat_at
worker_type
worker_version
```

Rules:

1. lease acquisition must be atomic,
2. only the current lease holder may transition a record to `syncing`,
3. lease renewal must occur before expiry,
4. a stale worker cannot complete a record after its lease expires unless it reacquires ownership,
5. server responses correlate with attempt ID,
6. foreground and service-worker processors use the same lease contract,
7. lease scope must be explicit.

First-release model:

```text
mandatory per-record lease
terminal-level synchronization coordinator
```

Rules:

1. the per-record lease owns the record transition,
2. the terminal-level coordinator elects which records are eligible,
3. the coordinator lock does not own the record itself,
4. sequence and predecessor rules are evaluated before acquiring a record lease,
5. workers may process more than one independent record only when Story 41.4 allows it.

This allows unrelated records to continue where sequence dependency permits while still preventing duplicate processing of the same record.

## 7.1 Projection Revision

Mutable queue projections must include:

```text
queue_state_revision
```

Every mutation uses compare-and-swap semantics:

```text
update where revision = expected_revision
then revision = revision + 1
```

For IndexedDB, this is implemented by reading and updating within the same transaction.

This protects:

1. retry scheduling,
2. support blocking,
3. lease acquisition,
4. resolution transitions,
5. cash-state updates.

## 8. Stale Response Protection

Every sync attempt must record:

```text
sync_attempt_id
lease_id
attempt_started_at
attempt_generation
```

A server response may transition local state only when:

1. it matches the active attempt, or
2. it is safely recognized as an idempotent accepted/replayed result for the same UUID and fingerprint.

Late responses from stale workers must not overwrite newer queue state incorrectly.

## 9. Retry Scheduling

Required retry fields:

```text
automatic_retry_count
manual_retry_count
last_attempt_at
next_retry_at
last_retryable_error_code
retry_policy_version
```

Error fields must be separated:

```text
local_error_code
network_error_code
server_error_code
review_reason
```

Do not collapse storage corruption, connectivity timeout, HTTP failure, server rejection, and review classification into one `last_error` field.

Recommended backoff:

```text
delay = min(base_delay * 2^automatic_retry_count, maximum_delay)
```

with jitter.

Rules:

1. connectivity restoration may advance `next_retry_at`,
2. rejected HTTP/client errors do not retry,
3. `review_required` does not retry,
4. server `429` and transient `5xx` follow server guidance where supplied,
5. retry count resets only after acceptance or formal support action,
6. manual retry cannot bypass status restrictions,
7. manual retry cannot bypass `next_retry_at` excessively,
8. retry behavior remains observable.

Retry exhaustion:

1. retry count may be large, but automatic retry cadence becomes capped,
2. exceeding a configured alert threshold changes queue health to `support_required`,
3. record remains `retryable_failed` unless error classification changes,
4. retry exhaustion does not automatically reclassify a record as rejected,
5. manual retry remains policy controlled,
6. cash exposure remains visible.

## 10. Network Outcome Uncertainty

The queue must handle this case:

```text
Request reaches server
Server commits sale
Response is lost
Client sees timeout
```

Local state may become `retryable_failed`, but retry must reuse the same UUID and business fingerprint.

Required fields:

```text
last_request_sent_at
response_received_at
network_outcome_unknown
```

Server retry is expected to return `replayed` or the prior accepted result when the original request committed.

## 11. Policy Limit Calculations

Queue limit checks must be explicit:

```text
new_pending_count > maximum_unsynced_transaction_count
new_pending_exposure > maximum_unsynced_cash_amount
age > configured maximum
```

Rules:

1. the current transaction is allowed only when adding it remains within the maximum,
2. a value equal to the maximum remains allowed,
3. anything exceeding the maximum is blocked before capture success,
4. exposure is based on unresolved offline sale totals or net collected exposure, not gross tender before change.

Pending count includes unresolved records where:

```text
persistence_status = durably_captured or capture_uncertain
retention_status != purged
resolution_status is not resolved_posted/resolved_cash_returned/resolved_rejected
```

Pending count excludes:

1. accepted and safely retained records,
2. fully resolved returned-cash records,
3. purged tombstones.

Cash exposure includes:

1. collected,
2. disputed,
3. return pending,
4. capture uncertain where cash may have been collected.

Cash exposure excludes:

1. not confirmed cash.

Age basis:

```text
estimated_offline_age
```

`estimated_offline_age` is derived from current device time versus `captured_at_device` adjusted by last trusted server offset. It is an offline safety check, not authoritative transaction time. If device clock evidence becomes unreliable, queue health becomes `support_required` or capture is blocked according to policy.

## 12. Capacity Preflight

Required capacity fields:

```text
estimated_usage_bytes
estimated_quota_bytes
estimated_new_record_bytes
capacity_status
```

Capacity states:

```text
healthy
warning
capture_blocked
unknown
```

Rules:

1. warn before hard limit,
2. block if the new envelope cannot safely fit,
3. treat quota-estimation APIs as advisory,
4. still handle transactional write failure,
5. show actionable guidance,
6. never tell the cashier to clear browser storage when unsynced records exist.

Persistent storage capability:

```text
storage_persistence_status:
- granted
- denied
- unsupported
- unknown
```

Rules:

1. request persistent storage where supported,
2. record whether it was granted,
3. denial is not automatic failure unless tenant policy requires it,
4. warn or reduce offline limits where storage is best-effort,
5. never promise browser persistent storage is equivalent to server durability.

## 13. Uncertain Local Capture Recovery

On app restart, the queue must:

1. scan for incomplete atomic-capture evidence,
2. verify envelope, queue state, event history, sequence, and checksum,
3. classify each record,
4. block ordinary recapture where cash may have been collected,
5. expose support-resolution action without modifying the original envelope.

Recovery classifications:

```text
complete_and_recoverable
locally_corrupt
capture_uncertain
accepted_tombstone_only
```

Malformed records are not automatically deleted.

Startup integrity scan rules:

1. scan unresolved and recently compacted records first,
2. keep indexes for status, terminal epoch, local sequence, and retention class,
3. validate checksums lazily for accepted compacted history where appropriate,
4. always verify unresolved records before sync,
5. show startup recovery status if the scan is still running,
6. block new offline capture until critical queue integrity checks finish.

## 14. Schema Migration

Local schema behavior must define:

1. versioned local schema,
2. forward-only migrations,
3. backup or copy-on-write where practical,
4. no destructive migration while unresolved records exist,
5. migration failure causes offline capture to fail closed,
6. existing queue remains available for support extraction,
7. old envelope fingerprints remain verifiable with their original schema version.

## 15. Service-Worker and Build Compatibility

Queue records must preserve:

```text
client_build_version
queue_schema_version
envelope_schema_version
sync_contract_version
```

Rules:

1. a new worker must not take control until it can read the existing queue,
2. unresolved records block destructive cache reset,
3. sync service supports approved older contract versions during the retention window,
4. incompatible records enter support review rather than being deleted,
5. `clear cache and reload` is not the default support procedure when unsynced records exist.

## 16. Retention and Tombstones

Minimum accepted tombstone fields:

```text
offline_transaction_uuid
terminal_id
terminal_binding_epoch
local_sequence
business_payload_fingerprint
server_sale_uuid
server_sale_number
official_invoice_number
accepted_at
final_server_status
receipt_status
```

Compaction is permitted only when:

1. server acceptance is durably confirmed,
2. all required server identities are stored,
3. required customer support or receipt delivery data is no longer needed locally,
4. retention period has elapsed,
5. no unresolved local event remains.

Retention classes:

```text
accepted_short
replayed_short
rejected_medium
review_required_long
capture_uncertain_long
cash_collected_long
```

A rejected cash-collected record must not be purged simply because it is old.

## 17. Cash Status Events

Cash status is not a freely editable projection.

Recommended cash statuses:

```text
not_confirmed
collected
return_pending
returned
disputed
```

Every transition requires an append-only event.

For `returned`, preserve:

```text
amount_returned
returned_by
returned_at
return_reason
customer_acknowledgment_reference
support_case
```

The current projection may show the latest cash status, but original collection evidence remains intact.

## 18. Cashier Access After Switching

First-release access rules:

1. a cashier can see aggregate pending count and cash exposure for the active terminal,
2. a cashier can see their own queue details,
3. another cashier cannot edit or resolve prior cashier records,
4. branch manager/support roles may inspect all terminal records,
5. automatic sync continues regardless of which cashier is currently signed in,
6. current cashier identity is never substituted into an existing envelope,
7. shift-close checks all unresolved records tied to that shift.

## 19. Operator Dashboard Contract

Dashboard data:

```text
connection_status
sync_worker_status
pending_count
pending_cash_exposure
retryable_failed_count
review_required_count
rejected_count
capture_uncertain_count
oldest_pending_at
oldest_pending_age
last_successful_sync_at
next_retry_at
active_queue_lease
terminal_id
terminal_binding_epoch
branch
cashier
shift
storage_capacity_status
```

The cashier view should remain simple. Technical data such as fingerprints, lease IDs, worker versions, and checksums is restricted to support diagnostics.

## 20. Queue Health

Terminal-level queue health states:

```text
healthy
degraded
blocked
support_required
```

Derivation:

1. `healthy`: no unresolved errors and storage available,
2. `degraded`: pending or retryable records exist but normal processing continues,
3. `blocked`: limits, storage, schema, or policy prevent new capture,
4. `support_required`: review, corruption, uncertain capture, or terminal identity issue exists.

`processing_complete` means no ordinary queue worker may continue processing this record in its current lifecycle. It does not necessarily mean the commercial issue is resolved.

Valid example:

```json
{
  "queue_status": "processing_complete",
  "server_status": "review_required",
  "resolution_status": "pending_support"
}
```

## 21. Support Export Safeguards

Queue extraction or diagnostics export requires:

1. authorized support role,
2. masked customer data,
3. no credentials or secrets,
4. no raw encryption keys,
5. evidence checksum,
6. export timestamp,
7. terminal and epoch context,
8. explicit `provisional local evidence` labeling.

Ordinary cashiers cannot export raw queue payloads.

Support export metadata:

```text
export_id
generated_at
generated_by
terminal_id
terminal_binding_epoch
queue_schema_version
record_count
export_checksum
filter_summary
```

For each record, include immutable identifiers and current projections. Export must not rewrite or repair local data. It is a snapshot of provisional local evidence, not a new authoritative record.

## 22. Local Data Protection

First-release security decision:

1. minimize sensitive data stored locally,
2. rely on OS/browser profile protection for baseline local storage isolation,
3. never store secrets, PINs, approval credentials, card data, or raw encryption keys,
4. apply field masking in cashier and support diagnostics,
5. use encryption only where a device-bound or server-issued key design exists,
6. document residual risk of browser-local storage.

Browser-only encryption with a key stored beside encrypted data must not be represented as strong encryption.

## 23. Local Diagnostic Counters

Expose local diagnostic counters for pilot and support:

```text
lease_contention_count
stale_response_ignored_count
checksum_failure_count
schema_migration_failure_count
storage_write_failure_count
capture_uncertain_count
retry_exhausted_count
```

## 24. Implementation Notes

Implementation should preserve these safeguards:

1. use `storage_failed` as a terminal-level diagnostic event when no transaction record exists, or as a record state only when sufficient recovery evidence was durably captured separately,
2. do not create a fabricated envelope merely to store storage failure,
3. `retained_full` requires an eligible outcome such as `accepted`, `replayed`, or a formally resolved support outcome whose retention policy permits it,
4. pending, review-required, capture-uncertain, or cash-disputed records normally remain `full_payload`,
5. formal support resolution must append explicit events such as `support_resolution_opened`, `support_resolution_approved_posting`, `support_resolution_cash_returned`, and `support_resolution_rejected`,
6. do not change `server_status` from `rejected` to `accepted` without preserving original outcome and resolution authority,
7. the terminal-level coordinator identifies eligible records, evaluates ordering and predecessor rules, initiates per-record lease acquisition, and never bypasses record leases,
8. the coordinator must not hold an IndexedDB transaction while making a network request,
9. `active_queue_lease` details are support diagnostics; cashier UI should present simpler states such as `Sync active`, `Waiting to retry`, or `Needs support`.

Suggested service boundaries:

| Component | Responsibility |
| --- | --- |
| `OfflineQueueRepository` | IndexedDB transactions and indexes |
| `OfflineCaptureService` | Atomic capture and verification |
| `QueueTransitionPolicy` | Legal status transitions |
| `QueueLeaseService` | Lease acquisition and renewal |
| `QueueRetryPolicy` | Backoff and eligibility |
| `QueueIntegrityService` | Fingerprints, checksums, startup scan |
| `QueueRetentionService` | Tombstones, compaction, purge |
| `QueueDiagnosticsService` | Operator and support projections |

## Implementation Slices

1. Schema and compatibility.
2. Atomic capture.
3. Projection and event model.
4. Processing ownership.
5. Retention and recovery.
6. Operator and regression coverage.

## Acceptance Criteria

1. Queue records preserve enough evidence for safe sync.
2. Cashier success is shown only after durable write and verification.
3. Queue records survive page refresh where browser storage remains available.
4. Terminal reinstall or storage loss cannot silently claim old queue identity.
5. Only one valid lease holder can transition or submit a queue record.
6. Material business payload is immutable after durable capture.
7. Retryable failures use bounded retry policy.
8. Review-required and rejected records do not auto-retry.
9. Cashier switching does not alter envelope ownership or actor evidence.
10. Accepted records retain minimal tombstones through retention policy.
11. Policy limits block before capture success when adding the current transaction would exceed configured maximums.
12. Cash-status changes preserve append-only transition evidence.
13. Local stores separate immutable envelopes, queue projection, status events, sync attempts, tombstones, and metadata.
14. Atomic local capture commits envelope, initial queue projection, capture event, and sequence allocation together or not at all.
15. Persistence, queue processing, server outcome, resolution, and retention states are represented separately.
16. Canonical serialization and recorded algorithm version produce stable fingerprints independent of mutable queue metadata.
17. Only one active lease holder can transition a record to syncing or apply an ordinary sync result.
18. Attempt and lease correlation prevents stale responses from overwriting newer queue state.
19. Network outcome uncertainty retries with the same UUID and business fingerprint.
20. Schema migration preserves unresolved envelopes, fingerprints, statuses, events, and sequence evidence or fails closed.
21. Capacity preflight blocks capture before success if a new envelope cannot safely fit.
22. Accepted compaction retains a complete tombstone.
23. Cash collection and return remain separately traceable with actor, amount, time, reason, support reference, and acknowledgment evidence.
24. Operator dashboard shows queue health, pending count, cash exposure, oldest age, last sync, and required action without sensitive payloads.
25. New client or service worker builds process approved older contracts safely or preserve records in support-required state.
26. Any state-dimension transition must exist in the approved transition matrix or be rejected and recorded as an integrity event.
27. Queue projection mutations use revision/compare-and-swap protection.
28. IndexedDB atomic capture performs no network work and commits sequence, envelope, projection, and capture event in one transaction before read-back verification.
29. Queue limits count only unresolved records and applicable cash states according to the approved counting contract.
30. Persistent storage capability is requested where supported and recorded without presenting browser storage as central durability.
31. Retry exhaustion keeps the record preserved, sets queue health to `support_required`, and does not silently reject or purge.
32. Startup integrity scan completes critical identity, schema, projection, event, and checksum checks before new offline capture or synchronization proceeds.
33. Local diagnostics expose lease contention, stale responses, checksum failures, migration failures, storage write failures, capture uncertainty, and retry exhaustion.

## Test Planning Notes

Later implementation should include tests for:

1. IndexedDB object-store separation,
2. atomic capture rollback,
3. canonical fingerprint stability,
4. checksum mismatch handling,
5. local sequence uniqueness,
6. sequence counter corruption,
7. per-record lease acquisition,
8. stale worker response,
9. retry backoff and manual retry restrictions,
10. lost-response replay,
11. quota preflight and write failure,
12. schema migration with unresolved records,
13. service-worker upgrade compatibility,
14. accepted tombstone compaction,
15. rejected/review retention,
16. cash returned event history,
17. cashier switching access rules,
18. operator dashboard projection,
19. support export authorization and masking,
20. multi-tab and foreground/service-worker race handling,
21. legal transition matrix enforcement,
22. queue projection revision conflict,
23. IndexedDB transaction boundary without network work,
24. policy-limit population and age basis,
25. persistent storage capability handling,
26. retry exhaustion,
27. startup integrity scan,
28. support export metadata,
29. local data-protection masking,
30. local diagnostic counters.

Priority order:

1. atomic capture rollback,
2. duplicate local-sequence prevention,
3. canonical fingerprint stability across reloads,
4. multi-tab lease race,
5. expired-worker late response,
6. server-committed but response-lost retry,
7. schema migration with unresolved records,
8. service-worker upgrade with old envelopes,
9. capture uncertainty after write ambiguity,
10. compaction without loss of tombstone identity,
11. cash return event history,
12. startup integrity scan blocking unsafe capture.

## Definition of Done

Story 41.2 is ready for implementation when:

1. queue data model is reviewed and approved,
2. state dimensions and legal transitions are explicit,
3. local atomic capture contract is approved,
4. fingerprint and checksum versioning are approved,
5. lease and stale-response handling are approved,
6. retry and network-uncertainty behavior are approved,
7. retention and migration rules are approved,
8. dashboard and support diagnostics contracts are approved,
9. queue projection revision behavior is approved,
10. persistent storage and encryption/key-management boundary is approved,
11. startup integrity scan and indexes are approved,
12. acceptance criteria are sufficient for implementation,
13. story index and implementation guide status are updated.
