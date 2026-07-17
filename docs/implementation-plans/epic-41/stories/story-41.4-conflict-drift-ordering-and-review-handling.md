# Story 41.4 Conflict, Drift, Ordering, and Review Handling

## Status

Approved for Implementation

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Define and implement safe handling for offline sync records that cannot be accepted automatically because of conflict, drift, stale evidence, terminal identity mismatch, ordering gaps, or review-required cash exposure.

Story 41.3 made server synchronization deterministic and idempotent. Story 41.4 extends that foundation by making every unsafe result explainable, durable, non-retryable where appropriate, and visible to cashier, support, and drawer accountability workflows.

The goal is not to repair data automatically. The goal is to keep unsafe offline records from disappearing, duplicating, auto-posting under the wrong authority, or retrying forever as if they were ordinary network failures.

## Dependencies

Requires:

1. Story 41.2 local queue identity, leasing, retry, diagnostics, tombstone, and cash-status behavior.
2. Story 41.3 server synchronization, idempotency, fingerprint, status lookup, attempt history, and consequence status behavior.
3. Existing `OfflineSalesImport`, `OfflineSyncAttempt`, `OfflineSyncBatch`, and v1 sync endpoint.
4. Existing admin offline import/review screens and support diagnostics patterns.
5. Existing shift/drawer accountability behavior for unresolved cash-collected records.

## Complexity

Large

## Architecture Constraints

Story 41.4 must preserve these locked decisions:

1. Browser queue records remain provisional and never become official sale authority.
2. `SaleCreationService` remains the committed sale creation authority.
3. Drift is detected before mutation.
4. Exact replay is not a conflict.
5. Review-required is a stable server outcome, not a retryable transport failure.
6. Cash-collected unsafe records remain visible until resolved.
7. Local material envelope payloads are immutable after durable capture.
8. Terminal identity and terminal binding epoch remain part of sync evidence.
9. Device time is evidence, not committed business-date authority.
10. Inventory, loyalty, store credit, fiscal, and accounting consequences remain server-authoritative.
11. Conflict classification may block sale creation but may not create, repair, or reinterpret a sale.
12. Duplicate detection is evidence for review and never becomes posting authority.
13. Review-required state is immutable until a future governed resolution workflow acts on it.
14. Cross-tenant data must not be disclosed through conflict diagnostics.

## Benchmark and Architecture Direction

The reference benchmark for this story is Mosaic-style operational control: suspicious offline records should be visible, diagnosable, and reviewable without allowing cashier retry loops or support shortcuts to mutate official sales.

Story 41.4 intentionally goes stricter than public competitor behavior by preserving:

1. server-authoritative sale creation;
2. explicit idempotency and fingerprint replay;
3. immutable local capture evidence;
4. role-safe diagnostics;
5. durable review state;
6. no auto-merge of suspected duplicates;
7. no terminal ownership transfer after offline capture.

Conceptual flow:

```text
Offline envelope
        |
Identity, sequence, duplicate, policy, and time checks
        |
OfflineSyncConflictDecision
        |
allow_post        -> Story 41.3 sale sync path
retryable_failed -> ordinary retry/backoff
review_required  -> durable review workflow
rejected         -> stable non-posted result
```

## Scope

In scope:

1. Conflict and drift taxonomy.
2. Durable review-required reason codes.
3. Retryable versus non-retryable classification.
4. Batch and envelope ordering policy.
5. Predecessor blockage behavior.
6. Terminal revoked, replaced, compromised, cross-branch, or stale epoch outcomes.
7. Fingerprint, identity, payment-evidence, catalog, policy, and clock-drift review classification.
8. Support/admin diagnostics for review-required records.
9. Cashier-facing status and retry behavior updates.
10. Drawer/shift visibility for cash-collected unresolved records.
11. Status lookup behavior for review-required records.
12. Backend and frontend regression tests.

Out of scope:

1. Automatic data repair.
2. Forced posting without review evidence.
3. Fiscal override behavior.
4. Support UI for final manual resolution execution.
5. Inventory or loyalty consequence validation beyond classification hooks.
6. New payment methods or non-cash offline support.
7. Offline void/refund.
8. Terminal hardware recovery.

## Current Implementation Context

Story 41.3 introduced:

1. `OfflineEnvelopeSynchronizationService`.
2. `server_sync_status` values:

```text
accepted
replayed
retryable_failed
review_required
rejected
```

3. `review_reason`, `rejection_reason`, `retryable_error_code`, and consequence-status snapshots.
4. `offline_sync_attempts` as attempt history separate from stable envelope outcome.
5. v1 sync endpoint:

```text
POST /api/v1/pos/offline-sales/sync
GET /api/v1/pos/offline-sales/{offline_transaction_uuid}/sync-status
```

6. Frontend queue mapping that keeps missing-result records retryable and maps `review_required` to a non-retryable local conflict state.

Story 41.4 should build on this instead of replacing it.

## Conflict Taxonomy

The implementation must classify unsafe records into stable reason codes.

The classifier must separate these concepts:

1. `conflict_family` - broad category for filtering and support routing.
2. `reason_code` - stable machine-readable cause.
3. `review_severity` - operational urgency.
4. `retry_classification` - whether automatic retry is allowed.
5. `suggested_action_code` - next human or system action.

Recommended conflict families:

| Family | Example reason | Expected server status |
| --- | --- | --- |
| Identity conflict | `review_terminal_identity_conflict` | `review_required` |
| Fingerprint drift | `rejected_fingerprint_drift` or `review_fingerprint_drift_cash_collected` | `rejected` or `review_required` |
| Duplicate suspicion | `review_suspected_duplicate_capture` | `review_required` |
| Ordering conflict | `review_predecessor_blocked` | `review_required` |
| Sequence gap | `review_sequence_gap` | `review_required` |
| Terminal state | `review_terminal_revoked_after_capture` | `review_required` |
| Policy/config stale | `review_stale_catalog_or_policy` | `review_required` |
| Payment evidence | `rejected_missing_payment_evidence`, `review_required_cash_payment_configuration` | `rejected` or `review_required` |
| Clock drift | `review_device_clock_drift` | `review_required` |
| Business date | `review_business_date_out_of_policy` | `review_required` |
| Cash exposure | `review_cash_collected_unposted` | `review_required` |

Guiding rule:

```text
If physical cash may have been collected or goods may have been released,
prefer review_required over ordinary rejected unless the envelope is clearly
pre-cash or safely non-commercial.
```

## Conflict Decision Object

Every unsafe or posting-eligible envelope must resolve to an `OfflineSyncConflictDecision`.

Required fields:

| Field | Purpose |
| --- | --- |
| `decision` | One of `allow_post`, `retryable_failed`, `review_required`, `rejected`. |
| `conflict_family` | Broad family such as `identity`, `duplicate`, `ordering`, `policy`, `time`, `terminal_state`, `cash_exposure`. |
| `reason_code` | Stable cause such as `review_suspected_duplicate_capture`. |
| `review_severity` | One of `low`, `medium`, `high`, `critical`. |
| `retry_classification` | One of `automatic_retry`, `manual_retry`, `non_retryable`, `support_only`, or null when `decision = allow_post`. |
| `cash_exposure` | `none`, `possible`, `collected`, or `unknown`. |
| `blocks_predecessors` | Whether resolution of this record requires reopening or holding an earlier record's workflow. Normally false in the first release. |
| `blocks_successors` | Whether later records in the same tenant, terminal, and terminal binding epoch must stop. |
| `suggested_action_code` | Plain action code for cashier, manager, or support. |
| `diagnostic_reference` | Stable reference to support-safe diagnostic evidence. |

Allowed decisions:

1. `allow_post` - continue into the Story 41.3 sale sync path.
2. `retryable_failed` - no durable business decision exists; ordinary retry/backoff may continue.
3. `review_required` - durable non-posted review state; ordinary retry must stop.
4. `rejected` - stable non-posted rejection; ordinary retry must stop.

For `allow_post`, conflict fields should remain null unless the enum contract formally defines a non-conflict value. Do not invent artificial values such as `conflict_family = none` or `reason_code = no_conflict`.

Recommended safe decision shape:

```json
{
  "decision": "allow_post",
  "conflict_family": null,
  "reason_code": null,
  "review_severity": null,
  "retry_classification": null,
  "cash_exposure": "collected",
  "blocks_predecessors": false,
  "blocks_successors": false,
  "suggested_action_code": null,
  "diagnostic_reference": null
}
```

For `allow_post`, `retry_classification = null` means no retry decision exists yet because the envelope is permitted to proceed to Story 41.3 processing. Posting may still later produce an accepted, replayed, review, rejection, or retryable outcome through the normal sync path.

Invariant:

```text
OfflineSyncConflictDecision decides routing only.
It must not create a sale, repair a sale, merge duplicates, or mutate fiscal,
inventory, loyalty, store-credit, payment, or accounting consequences.
```

## Review Severity and Retry Classification

Review severity values:

| Severity | Meaning |
| --- | --- |
| `low` | Informational review; no cash exposure and no posting risk. |
| `medium` | Operational review required; limited cashier or branch impact. |
| `high` | Cash, drawer, or official-record risk. |
| `critical` | Security, compromised terminal, cross-tenant, or fraud-risk condition. |

Retry classification is separate from sync status.

Allowed values:

| Retry classification | Meaning |
| --- | --- |
| `automatic_retry` | Queue backoff may retry without user action. |
| `manual_retry` | Retry requires cashier or manager action. |
| `non_retryable` | Retry is prohibited because a stable outcome exists. |
| `support_only` | Only support or a future governed workflow may act. |

## Retry Behavior

Retry behavior must be deterministic.

Retryable:

1. network failure before response;
2. transient database deadlock after rollback;
3. temporary unavailable dependency before mutation;
4. server outage where no stable envelope result was returned.

Non-retryable without review:

1. `review_required`;
2. `rejected`;
3. terminal identity conflict;
4. fingerprint drift;
5. payment evidence conflict;
6. suspected duplicate;
7. predecessor blocked by review-required record.

Local queue behavior:

```text
retryable_failed -> local failed/retryable
review_required -> local conflict/review
rejected -> local conflict/rejected
accepted/replayed -> local synced
```

Review-required records must not be submitted again by ordinary retry/backoff loops.

## Ordering and Predecessor Policy

Offline queues have two ordering concepts:

1. `offline_transaction_uuid` for idempotency.
2. local terminal ordering evidence such as `local_sequence`, `offline_sequence_number`, and `terminal_binding_epoch`.

Story 41.4 must define processing behavior when records arrive out of order.

Recommended first-release policy:

1. Exact replay may be processed independently.
2. New envelopes may be accepted out of order only when there is no unresolved predecessor dependency.
3. A missing predecessor first creates `gap_detected`, not immediate review.
4. A predecessor with `retryable_failed` may pause later envelopes only when strict terminal ordering is enabled.
5. A predecessor with `review_required` blocks later same-terminal records when accepting later records could hide cash/accountability drift.
6. A predecessor with `accepted` or `replayed` does not block.
7. A predecessor with `rejected` blocks only when cash is collected or unknown.

The implementation must make this configurable or policy-isolated so later stories can adjust strictness without rewriting sync posting.

Sequence namespace:

```text
tenant_id
terminal_id
terminal_binding_epoch
local_sequence
```

The system must not order across terminals, binding epochs, branches, or tenants. Cashier identity is diagnostic evidence only and must not become part of ordering authority.

`blocks_successors` applies only within the same:

```text
tenant_id
terminal_id
terminal_binding_epoch
```

It must not block other terminals, other epochs, unrelated branch activity, exact replay, or status lookup.

Sequence-gap progression:

```text
gap_detected
        |
wait configurable grace period
        |
query status or predecessor evidence
        |
still unresolved?
        |
review_sequence_gap
```

The first detected gap timestamp must remain stable across retries unless a future governed reset workflow changes it.

Persist:

1. `sequence_gap_detected_at`;
2. `sequence_gap_grace_expires_at`;
3. `missing_sequence_from`;
4. `missing_sequence_to`;
5. `predecessor_lookup_last_attempt_at`;
6. `sequence_gap_state`.

During the grace period, the server should return a retryable or deferred result rather than premature review:

```text
server_sync_status = retryable_failed
retryable_error_code = retry_sequence_gap_waiting
sequence_gap_state = grace_period
```

After grace-period expiry and unresolved predecessor lookup:

```text
server_sync_status = review_required
reason_code = review_sequence_gap
sequence_gap_state = escalated
```

Predecessor dependency values:

| Value | Meaning |
| --- | --- |
| `none` | Record may be evaluated independently. |
| `informational` | Missing predecessor is noted but does not block posting. |
| `strict` | Missing or unresolved predecessor blocks posting and may become review-required after grace period. |

## Drift Policy

Drift means the server cannot prove the submitted envelope is the same durable business capture previously seen or expected.

Drift sources:

1. same UUID with different server fingerprint;
2. same UUID under different tenant, branch, terminal, binding epoch, or cashier context;
3. current request fingerprint does not match client-submitted evidence;
4. local sequence reused for different business payload;
5. stale catalog/config hash where posting under current rules would materially change result;
6. device clock or business date outside policy tolerance.

Rules:

1. No sale, payment, inventory, loyalty, store-credit, receipt, or accounting mutation may occur after detected drift.
2. Exact replay must return the original stable result.
3. Cash-collected drift should normally enter `review_required`.
4. Non-cash or clearly pre-cash malformed drift may be `rejected`.
5. Drift outcomes must persist enough evidence for support to compare submitted and stored fingerprints without exposing sensitive payload unnecessarily.

## Suspected Duplicate Policy

Different UUIDs can still represent the same real-world cash sale.

The implementation must add suspected duplicate detection before automatic posting.

Suggested duplicate signals:

1. same tenant, branch, terminal, cashier, business date, and local reference;
2. same local receipt number;
3. same item set, quantities, total, and timestamp within a configured window;
4. same row hash or payload hash under a different UUID;
5. same terminal sequence reused after reinstall or epoch mismatch.

Duplicate evidence fields:

| Field | Purpose |
| --- | --- |
| `duplicate_score` | Integer confidence score from 0 to 100. |
| `duplicate_rule_ids` | Rule identifiers that contributed to the score. |
| `duplicate_candidate_import_id` | Candidate offline import reference, if present. |
| `duplicate_candidate_sale_id` | Candidate committed sale reference, if present. |
| `duplicate_detection_version` | Version of duplicate scoring rules used. |
| `duplicate_review_threshold` | Versioned threshold used when the decision was made. |
| `duplicate_candidates` | Versioned list of candidate references, scores, and matched rules. |

Outcome:

```text
suspected duplicate -> review_required
```

Never auto-merge or auto-post a suspected duplicate as exact replay unless the UUID and fingerprint replay contract proves it is the same envelope.

Duplicate detection is evidence, not authority. A high duplicate score may produce `review_required`, but it must not by itself create a sale, reject a sale, reverse a sale, or mark a different UUID as exact replay.

Threshold rule:

```text
score >= configured duplicate_review_threshold
        -> review_required
score < configured duplicate_review_threshold
        -> diagnostic evidence only; do not block posting by score alone
```

Candidate cardinality:

```json
[
  {
    "candidate_type": "sale",
    "candidate_id": 123,
    "score": 91,
    "rule_ids": ["same_terminal_total_time_window", "same_receipt_reference"]
  }
]
```

The top-ranked candidate may be stored in indexed columns for searching, but the full candidate evidence should remain in versioned metadata.

## Terminal Revocation and Replacement Policy

Terminal state can change while a browser is offline.

Cases:

1. terminal profile revoked after capture;
2. terminal profile inactive at sync;
3. terminal binding epoch changed;
4. terminal was replaced;
5. terminal was marked compromised or stolen;
6. branch ownership changed;
7. cashier no longer belongs to branch.

Recommended outcomes:

| Case | Cash not collected | Cash collected or unknown |
| --- | --- | --- |
| inactive/revoked terminal | rejected | review_required |
| binding epoch mismatch | rejected | review_required |
| compromised/stolen terminal | rejected | review_required |
| replacement terminal tries old UUID | rejected | review_required |
| cross-branch terminal | rejected | review_required |

No record may be auto-transferred from one terminal profile to another.

Cross-boundary behavior:

1. Cross-tenant mismatch must use the project's hidden-resource policy, returning `404` or `403` without disclosing tenant, branch, terminal, or envelope existence. It must create a security audit event.
2. Cross-branch within the same tenant may create durable review when cash was collected or cash status is unknown.
3. Cross-branch without cash exposure may be rejected without review.
4. A true cross-tenant authorization mismatch must not persist the foreign payload into the target tenant's ordinary offline-import table. Retain only masked identifiers and request metadata in security audit evidence.

Compromised-terminal quarantine:

1. no auto-posting;
2. no ordinary retry;
3. severity `critical`;
4. preserve cash and local capture evidence;
5. notify security/admin workflow where available;
6. block subsequent envelopes from the same terminal binding epoch;
7. do not transfer ownership to a replacement terminal.

Only a governed security action can lift or resolve quarantine for a compromised terminal epoch. Normal terminal reactivation, reassignment, or replacement must not clear quarantine.

Security quarantine routing:

```text
review_severity = critical
assigned_team = security
retry_classification = support_only
```

Branch users may see only a role-safe operational message. Security and audit users receive the detailed event and masked diagnostic evidence.

## Policy, Time, and Business-Date Drift

Policy drift must classify materiality before deciding whether review is required.

Allowed materiality values:

| Value | Meaning | Example |
| --- | --- | --- |
| `non_material` | Does not change financial, fiscal, inventory, loyalty, or compliance outcome. | Cosmetic label change. |
| `material_review` | May change posting interpretation and requires review. | Offline cash policy changed after capture. |
| `prohibited` | Posting under submitted evidence is forbidden. | Offline sale captured after terminal was disabled by policy. |

Time evidence is separate from committed business date.

Persist or expose these fields where applicable:

1. `time_evidence_status`;
2. `business_date_status`;
3. `proposed_business_date`;
4. `resolved_business_date`;
5. `business_date_review_reason`.

Device clock evidence must never directly determine the committed business date when server policy cannot validate it.

## Review-Required Data Contract

Each review-required record should persist:

1. `review_reason`;
2. `reason_code`;
3. `conflict_family`;
4. `review_severity`;
5. `retry_classification`;
6. `suggested_action_code`;
7. cash exposure flag;
8. terminal identity evidence;
9. local reference and local receipt number;
10. offline transaction UUID;
11. server fingerprint;
12. client fingerprint;
13. submitted catalog/config hashes;
14. status lookup payload;
15. last sync attempt evidence;
16. consequence status showing no official sale mutation unless already accepted;
17. support-safe diagnostic summary.
18. `conflict_policy_version`;
19. `duplicate_detection_version`;
20. `ordering_policy_version`;
21. `review_payload_schema_version`;
22. `review_opened_at`;
23. `review_due_at`;
24. `review_escalation_level`;
25. `last_review_activity_at`;
26. `assigned_team`;
27. `review_sla_policy_id`;
28. `review_sla_policy_version`;
29. `review_decision_snapshot`;
30. `current_resolution_status`.

Avoid storing duplicate sensitive data outside the existing raw-payload retention policy.

Immutable review-state rule:

```text
review_required is a stable durable outcome.
Ordinary retry, status lookup, or cashier refresh cannot change it.
Only a future governed resolution workflow may transition it to:
resolved_posted
resolved_cash_returned
resolved_rejected
```

The original reason, evidence, policy versions, and diagnostic reference must remain preserved after any future resolution.

Future resolution workflows must not overwrite:

1. `original_conflict_decision`;
2. `original_reason_code`;
3. `original_review_severity`;
4. `original_policy_versions`;
5. original duplicate, ordering, terminal, policy, and time evidence.

Recommended separation:

```text
review_decision_snapshot
current_resolution_status
resolution_events
```

Review due dates must be derived from a versioned service-level policy:

```text
review_sla_policy_id
review_sla_policy_version
review_opened_at
review_due_at
```

Controllers and UI code must not independently calculate due dates.

Cash exposure derivation:

```text
cash_status = collected
        -> cash_exposure = collected
cash_status = disputed or capture uncertain
        -> cash_exposure = possible or unknown
cash_status = not_confirmed
        -> cash_exposure = none
```

Support users must not freely edit `cash_exposure`; changes require append-only evidence or a future governed resolution event.

## Conflict Decision Audit Events

Recommended events:

```text
offline_sync_conflict_allowed
offline_sync_conflict_retryable
offline_sync_review_opened
offline_sync_envelope_rejected
offline_sync_duplicate_suspected
offline_sync_sequence_gap_detected
offline_sync_sequence_gap_escalated
offline_sync_terminal_quarantined
offline_sync_policy_drift_detected
```

Audit events must not log complete raw payloads.

Decision events must represent state transitions, not reads. Status lookup and exact replay must not emit `offline_sync_review_opened` again after review is already open.

## Review Decision Idempotency

Review opening must be idempotent under concurrent sync attempts.

Invariant:

```text
one active review decision per offline import
```

Repeated classification of the same envelope must return the stored review result and diagnostic reference rather than creating duplicate review rows, duplicate review-opened audit events, or conflicting support records.

## Role-Safe Status Projections

Cashier projection:

1. `sync_status`;
2. plain-language message;
3. cash exposure warning;
4. manager or support action;
5. local reference;
6. last updated timestamp.

Branch manager projection:

1. cash status;
2. shift and drawer impact;
3. review reason;
4. terminal and cashier;
5. suggested action.

Support and audit projection:

1. fingerprints;
2. sequence evidence;
3. duplicate candidates;
4. policy versions;
5. attempt history;
6. terminal state;
7. masked payload comparison.

No projection may expose cross-tenant evidence or raw sensitive payload beyond the existing retention and masking policy.

## Backend Implementation Plan

Recommended components:

1. `OfflineSyncConflictClassifier`
   - Maps identity, fingerprint, policy, ordering, payment, clock, and duplicate conditions into reason codes.

2. `OfflineSyncOrderingService`
   - Evaluates same-terminal predecessor state, sequence gaps, and blockage rules.

3. `OfflineSuspectedDuplicateService`
   - Searches prior accepted/review records for same local reference, same local receipt, same business tuple, or same payload evidence under a different UUID.

4. `OfflineTerminalStatePolicy`
   - Evaluates revoked, inactive, compromised, stale epoch, cross-branch, and cross-tenant terminal outcomes.

5. `OfflinePolicyDriftService`
   - Classifies catalog, config, offline policy, and business-date drift materiality.

6. `OfflineSyncReviewStateService`
   - Persists review-required status, review reason, cash exposure, and diagnostic metadata.

7. `OfflineSyncSupportPayloadBuilder`
   - Produces support-safe payloads for admin screens and status lookup.

8. `OfflineSyncConflictAuditService`
   - Emits audit/security evidence after durable review or rejection state is persisted.

Existing `OfflineEnvelopeSynchronizationService` should call these services before `SaleCreationService`.

Suggested flow:

```text
validate identity and fingerprint
        |
classify duplicate/order/terminal/policy conflicts
        |
safe to auto-post?
        |
YES -> Story 41.3 sale sync path
NO  -> persist review_required/rejected result
```

## Frontend Implementation Plan

Frontend queue behavior should:

1. stop retrying `review_required` and `rejected` results;
2. keep `retryable_failed` under Story 41.2 backoff;
3. show a distinct support-review state for cash-collected records;
4. preserve local UUID, local sequence, and cash status;
5. prevent local deletion of cash-collected review records;
6. keep shift/drawer warnings visible while unresolved review records exist;
7. use status lookup to refresh server result without resubmitting the business payload;
8. avoid promising official invoice or loyalty/inventory completion for review-required records.

Operator-facing language should be plain:

```text
Needs review
Cash may have been collected. Do not retry or delete this record.
Contact a manager or support.
```

## Admin and Support Diagnostics

Admin/support views should expose:

1. review reason;
2. terminal and cashier;
3. branch and tenant;
4. local reference;
5. local receipt number;
6. cash status;
7. server status;
8. resolution status;
9. submitted totals;
10. conflict family;
11. last attempt time;
12. suggested next action.

This story does not need to implement final resolution execution, but it must make review records discoverable and understandable.

## Data and Migration Notes

Prefer additive schema changes only if existing fields are insufficient.

Potential additive fields:

```text
offline_sales_imports.review_severity
offline_sales_imports.reason_code
offline_sales_imports.retry_classification
offline_sales_imports.suggested_action_code
offline_sales_imports.cash_exposure_status
offline_sales_imports.conflict_family
offline_sales_imports.conflict_metadata
offline_sales_imports.predecessor_offline_transaction_uuid
offline_sales_imports.predecessor_dependency
offline_sales_imports.sequence_gap_detected_at
offline_sales_imports.sequence_gap_grace_expires_at
offline_sales_imports.sequence_gap_state
offline_sales_imports.missing_sequence_from
offline_sales_imports.missing_sequence_to
offline_sales_imports.predecessor_lookup_last_attempt_at
offline_sales_imports.duplicate_score
offline_sales_imports.duplicate_review_threshold
offline_sales_imports.duplicate_rule_ids
offline_sales_imports.duplicate_candidates
offline_sales_imports.duplicate_candidate_sale_id
offline_sales_imports.duplicate_candidate_import_id
offline_sales_imports.duplicate_detection_version
offline_sales_imports.conflict_policy_version
offline_sales_imports.ordering_policy_version
offline_sales_imports.review_payload_schema_version
offline_sales_imports.time_evidence_status
offline_sales_imports.business_date_status
offline_sales_imports.proposed_business_date
offline_sales_imports.resolved_business_date
offline_sales_imports.business_date_review_reason
offline_sales_imports.review_locked_at
offline_sales_imports.review_opened_at
offline_sales_imports.review_due_at
offline_sales_imports.review_sla_policy_id
offline_sales_imports.review_sla_policy_version
offline_sales_imports.review_escalation_level
offline_sales_imports.last_review_activity_at
offline_sales_imports.assigned_team
offline_sales_imports.review_decision_snapshot
offline_sales_imports.current_resolution_status
```

Use JSON metadata only for diagnostic evidence that is not needed for high-volume querying.

Indexes to consider:

1. tenant + branch + terminal + local sequence;
2. tenant + terminal + terminal binding epoch + local sequence;
3. tenant + local receipt number;
4. tenant + branch + review reason;
5. tenant + branch + server sync status + cash status;
6. tenant + branch + business date where present.
7. tenant + branch + review due date;
8. tenant + branch + assigned team + review escalation level.

## API Contract

The v1 sync response remains the Story 41.3 response shape.

For review-required records, include:

```json
{
  "sync_status": "review_required",
  "conflict_family": "duplicate",
  "review_reason": "review_suspected_duplicate_capture",
  "reason_code": "review_suspected_duplicate_capture",
  "reason": "review_suspected_duplicate_capture",
  "review_severity": "high",
  "retry_classification": "support_only",
  "suggested_action_code": "manager_review_cash_sale",
  "consequence_status": {
    "sale": "not_applicable",
    "payment": "not_applicable",
    "inventory": "not_applicable",
    "loyalty": "not_applicable",
    "store_credit": "not_applicable",
    "receipt": "not_applicable",
    "accounting_outbox": "not_applicable"
  },
  "cash_status": "collected",
  "cash_exposure": "collected",
  "resolution_status": "pending_support",
  "diagnostic_reference": "offline-review:123",
  "review_due_at": "2026-07-18T08:00:00+08:00"
}
```

Status lookup must return the same stable review state without creating consequences.

Status lookup responses must be role-safe. Cashier calls should not receive raw fingerprints, payload comparisons, duplicate candidate IDs, or tenant/branch evidence that is reserved for manager, support, or audit projections.

## Test Requirements

Backend feature tests:

1. Same UUID with changed fingerprint enters rejected or review before mutation.
2. Same local sequence with different UUID enters review.
3. Same local receipt number with different UUID enters suspected duplicate review.
4. Different UUID with same business tuple enters suspected duplicate review.
5. Review-required predecessor blocks later same-terminal envelope when strict ordering applies.
6. Accepted predecessor allows later envelope.
7. Retryable predecessor follows configured pause or proceed policy.
8. Revoked terminal with cash collected enters review.
9. Revoked terminal without cash collected is rejected.
10. Terminal binding epoch mismatch enters review when cash collected.
11. Cross-branch terminal cannot post automatically.
12. Stale catalog/config hash enters review when posting would be unsafe.
13. Device clock drift enters review and does not set committed business date blindly.
14. Review-required record creates no sale/payment/inventory/outbox consequences.
15. Status lookup returns stable review state.
16. Review-required record does not get reprocessed as ordinary retry.
17. Support payload masks sensitive raw data.
18. Drawer/shift unresolved cash query includes cash-collected review records.
19. Conflict classifier returns a complete `OfflineSyncConflictDecision`.
20. Sequence namespace uses tenant, terminal, binding epoch, and local sequence only.
21. Sequence gap waits for grace-period expiry before becoming review-required.
22. Duplicate review persists score, rule IDs, candidate references, and detection version.
23. Compromised terminal creates critical review/quarantine and blocks same-epoch successors.
24. Material policy drift enters review or rejection according to materiality.
25. Ordinary status lookup cannot transition `review_required`.
26. Cashier, manager, and support projections hide and expose only role-appropriate fields.
27. Review aging fields populate due date, escalation level, activity timestamp, and assigned team.
28. Cross-tenant mismatch returns hidden-resource response and creates security audit evidence.

Frontend tests:

1. `review_required` maps to non-retryable local review/conflict state.
2. `rejected` maps to non-retryable local rejected/conflict state.
3. `retryable_failed` stays retryable.
4. review-required record is excluded from ordinary retry batch.
5. missing result remains retryable.
6. cashier messaging distinguishes review from network failure.
7. cash-collected review record remains visible in diagnostics.
8. cashier projection excludes support-only diagnostic details.
9. review-required record shows manager/support action and cash warning.

Suggested focused commands:

```bash
php artisan test tests/Feature/POS/OfflineSyncEpic41ContractTest.php
php artisan test tests/Feature/POS/OfflineSyncConflictReviewTest.php
php artisan test tests/Feature/POS/OfflineSyncOrderingTest.php
php artisan test tests/Feature/POS/OfflineSyncStatusWorkflowTest.php
node tests/Frontend/offlineQueueSync.test.js
```

## Acceptance Criteria

1. Conflict taxonomy is implemented with stable reason codes.
2. Drift creates no official sale, payment, inventory, loyalty, store-credit, receipt, accounting, or outbox consequence.
3. Review-required records do not retry as ordinary network failures.
4. Cash-collected review records remain visible in support and drawer accountability.
5. Exact replay remains accepted/replayed and is not treated as suspected duplicate.
6. Different-UUID suspected duplicates enter review before mutation.
7. Ordering gaps and predecessor blockage follow explicit policy.
8. Terminal revocation, epoch mismatch, and cross-branch conflicts fail closed.
9. Device clock and business-date evidence are not blindly committed.
10. Status lookup returns stable review state without resubmitting payload.
11. Frontend queue preserves local UUID, cash status, and review state.
12. Admin/support diagnostics show review reason and suggested next action.
13. Backend and frontend regression tests pass.
14. Existing Story 41.3 accepted/replayed/retryable tests remain green.
15. `OfflineSyncConflictDecision` is persisted or returned with decision, conflict family, reason code, review severity, retry classification, cash exposure, blocking flags, suggested action code, and diagnostic reference.
16. Sequence namespace is tenant, terminal, terminal binding epoch, and local sequence; no cross-terminal, cross-epoch, cross-branch, or cross-tenant ordering is performed.
17. Sequence gaps wait through the configured grace period and predecessor lookup before becoming `review_sequence_gap`.
18. Suspected duplicate review persists duplicate score, rule IDs, candidate import/sale IDs, and duplicate detection version without auto-merge.
19. Compromised terminal sync creates critical review/quarantine, blocks subsequent same-epoch envelopes, and never transfers ownership.
20. Material policy drift is classified as `non_material`, `material_review`, or `prohibited` before mutation.
21. Review-required state is immutable under ordinary retry and status lookup; only a future governed resolution workflow may resolve it.
22. Cashier, branch-manager, support, and audit status projections are role-safe.
23. Review aging and escalation fields are persisted and queryable.
24. Cross-tenant conflict protects resource existence with hidden response behavior and security audit evidence.
25. Sequence-gap grace-period timestamps remain stable across retries and are not restarted by every retry.
26. Missing predecessor envelopes return a retryable/deferred result during grace period unless strict cash policy requires immediate review.
27. Duplicate scoring preserves score, threshold, detector version, candidate evidence, and contributing rule IDs.
28. Compromised terminal review is routed to the security-owned critical queue with branch-safe messaging.
29. Review due dates persist the SLA policy identifier and version used to derive `review_due_at`.
30. Future review resolution preserves original conflict decision, reason, evidence, severity, and policy versions.
31. Concurrent attempts that classify the same envelope as `review_required` create only one active review decision and return the same stable diagnostic reference.
32. Exact replay returns the stored accepted or terminal review outcome without rerunning duplicate, ordering, or policy classifiers that could change the result.
33. Successor blocking applies only to records from the same tenant, terminal, and terminal binding epoch.
34. Status lookup and exact replay do not duplicate review-opened audit events.
35. DTOs, database records, OpenAPI, frontend types, and audit payloads use the same canonical conflict-decision field names.

## Implementation Slicing

Recommended PR sequence:

1. Add decision object, reason-code constants, conflict taxonomy, review metadata, and policy-version fields if needed.
2. Add idempotent review-state persistence.
3. Add terminal identity, cross-tenant, cross-branch, compromised-terminal, and quarantine classification.
4. Add ordering namespace, predecessor dependency, grace-period, and sequence-gap classification.
5. Add duplicate evidence scoring and suspected-duplicate review classification.
6. Add versioned duplicate threshold and multi-candidate evidence storage.
7. Add policy drift, time evidence, and business-date classification.
8. Add immutable review-state persistence, original decision snapshots, aging/escalation fields, and SLA-policy versioning.
9. Add conflict-decision audit events and security quarantine routing.
10. Add role-safe cashier, manager, support, and audit projections.
11. Update frontend local queue mapping, review messaging, cash warnings, and diagnostics.
12. Add backend conflict, duplicate, ordering, terminal-state, policy-drift, audit, and role-projection tests.
13. Add frontend review/retry mapping and role-safe diagnostic tests.
14. Update OpenAPI/docs and story implementation notes after verification.

## Risks, Guardrails, and Fallbacks

Key risks:

1. overly aggressive duplicate scoring could block legitimate sales;
2. weak sequence-gap handling could create unnecessary support workload;
3. cashier-facing diagnostics could expose support-only evidence;
4. compromised-terminal handling could accidentally allow replay from an unsafe epoch;
5. review-required records could become invisible to drawer accountability.

Guardrails:

1. suspected duplicates always enter review instead of auto-merge or auto-reject;
2. sequence gaps use a configurable grace period before review;
3. role-safe projections are tested separately;
4. compromised terminals are quarantined at terminal binding epoch granularity;
5. cash-collected review records remain queryable by shift and drawer workflows.

Fallbacks:

1. if duplicate scoring is uncertain, lower confidence should still produce evidence but not block unrelated envelopes;
2. if predecessor status cannot be queried during grace period, classify as review rather than auto-posting;
3. if role projection cannot be determined, return the cashier-safe projection;
4. if policy-drift materiality cannot be computed, treat it as `material_review`;
5. if cross-tenant evidence appears, return hidden-resource response and write security audit evidence only.

## Definition of Done

Story 41.4 is done when:

1. acceptance criteria pass;
2. backend conflict/review tests pass;
3. frontend queue review-state tests pass;
4. Story 41.3 sync tests remain green;
5. review-required records are durable and discoverable;
6. cash-collected records cannot disappear from accountability;
7. retry behavior excludes review-required and rejected records;
8. no official consequences are created for unsafe conflicts;
9. documentation is updated;
10. local PR review is complete;
11. CI passes before merge.
