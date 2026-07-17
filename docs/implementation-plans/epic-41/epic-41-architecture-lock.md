# Epic 41 POS Terminal Offline Readiness and Release Validation Architecture Lock

## 1. Status

Approved

Date: 2026-07-16

## 2. Purpose

Epic 41 defines the locked architecture for POS terminal offline readiness and release validation.

This epic formalizes the boundary between provisional terminal-local behavior and server-authoritative business posting. It protects existing compliance, inventory, dining, store credit, loyalty, and reporting boundaries while allowing controlled offline cashier continuity where policy permits.

## 3. Existing Context

Existing validated references:

1. `docs/validation/pos-terminal-offline-stabilization-2026-07-10.md`
2. `docs/validation/pos-terminal-offline-uat-2026-07-11.md`
3. `docs/validation/epic-41-terminal-identity-binding-closure.md`
4. `docs/implementation-plans/epic-40/epic-40-retrospective.md`

Existing behavior already established:

1. Offline provisional capture is cash-only.
2. Offline payment UI may reuse split-payment components, but the offline transaction must remain fully cash-settled.
3. Card, e-wallet, bank transfer, and other non-cash tenders remain disabled offline.
4. Browser IndexedDB/local queue is provisional only.
5. Official posting happens through server-side reconciliation.
6. No local official GCT, Z-read, e-journal, or BIR-certified receipt finalization exists.
7. Terminal identity binding is required for `/pos/terminal/checkout`.
8. Hardware printer and cash drawer validation remains deferred until physical devices are available.

## 4. Architecture Goal

Epic 41 must answer these questions before release:

1. What may happen offline?
2. What must remain online-only?
3. What is locally queued?
4. What is server-authoritative?
5. What happens on exact replay?
6. What happens on drift?
7. What happens after terminal reinstall, stale cache, or identity loss?
8. What evidence is retained locally and centrally?
9. Which hardware behaviors are validated versus deferred?
10. What pilot evidence is required before rollout?

## 5. Offline Capability Boundary

### 5.1 Allowed Offline Candidate

The only first-release offline cashier mutation candidate is:

```text
provisional cash sale capture
```

It is allowed only when:

1. the terminal has a valid identity,
2. the terminal has an approved controlled-offline profile,
3. the cashier is authorized and satisfies the required session/shift policy,
4. the cashier has a previously server-validated open-shift authority that has not exceeded offline policy,
5. the catalog and required pricing snapshot are already cached and within policy age,
6. local queue storage is available and the envelope can be durably written, read back, and verified,
7. the transaction uses cash only,
8. the transaction is queued with a stable local reference,
9. server synchronization later accepts and posts it.

### 5.2 Online-Only Operations

These remain online-only:

1. card, e-wallet, bank transfer, and external payment authorization,
2. void,
3. refund,
4. store credit redemption or issuance,
5. loyalty redemption,
6. manager approval issuance,
7. statutory discounts,
8. dining ticket mutation,
9. inventory stocktake,
10. inventory adjustment,
11. terminal activation or rebinding,
12. user/role/permission administration,
13. Z-read, GCT finalization, e-journal finalization, and official fiscal posting.

### 5.3 Dining Scope

Offline capture is a quick-service or standalone checkout path.

Existing dine-in tickets, table occupancy, split bills, transfer/merge operations, delayed settlement, and dining ticket mutation remain online-only in the first release.

### 5.4 Offline Payment UI Boundary

Offline payment UI must not construct a mixed-tender transaction.

Rules:

1. multiple cash entries are allowed only if they collapse into one fully cash-settled tender,
2. cash plus card, e-wallet, store credit, loyalty value, bank transfer, or external tender is prohibited,
3. unpaid balance, layaway, account receivable, or on-account effects are prohibited unless a future Architecture Lock explicitly approves them,
4. restored local UI state must not reintroduce a non-cash component,
5. if no genuine multi-tender offline use remains, the operator-facing UI should prefer `Cash Payment` wording over `Split Payment Wizard`.

### 5.5 Cash-Collected Review Boundary

Offline cash capture can create an unresolved commercial event before the server accepts the sale.

When physical cash may already have been collected, the queue record must preserve separate status dimensions:

```text
capture_status
cash_status
server_status
resolution_status
```

Recommended first-release values:

```text
capture_status:
- locally_captured
- locally_cancelled_before_completion

cash_status:
- not_collected
- collected
- returned

server_status:
- pending
- accepted
- replayed
- retryable_failed
- review_required
- rejected

resolution_status:
- none
- pending_support
- posted
- cash_returned
- formally_rejected
```

Rules:

1. a record with collected cash cannot be deleted or treated as an ordinary rejected request,
2. physical cash remains part of provisional drawer accountability until resolved,
3. support diagnostics must show that cash and goods may already have changed hands,
4. resolution must explicitly approve and post, document cash return, formally reject, or replace through a governed sale where policy permits,
5. original local identity, fingerprint, and evidence remain immutable,
6. rejected server posting must not silently remove the sale from cashier expectations,
7. shift close must surface unresolved cash-collected records.

### 5.6 Queued Record Correction and Cancellation

Once an offline envelope has been durably captured as cash collected, its material business payload is immutable.

Before cash completion, a cart or draft may be abandoned without creating a queue envelope.

After durable cash capture:

1. material business fields must not be edited in place,
2. the original envelope must not be silently deleted,
3. correction normally requires server synchronization followed by governed void/refund when accepted,
4. local cancellation is prohibited for the first release.

Resolution is either:

1. synchronize and perform an authorized online void/refund after acceptance, or
2. use the cash-collected support-resolution workflow if the envelope cannot be accepted.

## 6. Fiscal, Receipt, and Identity Boundary

Offline provisional capture must not display or print a document in a way that could be mistaken for the final registered invoice.

First-release policy:

```text
OFFLINE TRANSACTION ACKNOWLEDGMENT
Not yet posted as official sale
Local reference: ...
```

Official invoice numbering and fiscal evidence remain subject to the approved BIR configuration and server-authoritative posting contract.

Separate identities must be preserved:

```text
local_offline_reference
server_sale_uuid
server_sale_number
official_invoice_number
```

Rules:

1. local sequence is never presented as official server sale or invoice identity,
2. synchronization assigns official server identities,
3. exact replay returns the same assigned identities,
4. rejected envelopes never consume an official number unless the approved compliance policy requires otherwise,
5. gaps and cancellations follow the registered server numbering policy,
6. final invoice delivery after synchronization must be explicitly designed,
7. rejected synchronization requires customer support procedure if a provisional acknowledgment was issued.

## 7. Server Authority

Server-side services remain the sole authority for:

1. sale creation and committed sale number allocation,
2. sale items and tax/discount compliance records,
3. inventory movement posting,
4. recipe deduction posting,
5. negative-stock exception creation,
6. loyalty accrual and reversal,
7. store credit ledger mutation,
8. receipt compliance data,
9. reporting and audit projections.

The terminal may prepare a queued transaction envelope. It must not finalize official business state locally.

## 8. Shift, Drawer, and Cash Accountability

Offline cash capture requires cached proof of a previously server-validated open cashier shift.

Queued offline transaction evidence and sync result must preserve:

1. `shift_id`,
2. `drawer_session_id`,
3. `cashier_session_id`,
4. `offline_shift_snapshot`,
5. `shift_opened_at`,
6. shift authorization version,
7. shift authorization expiry.

Rules:

1. offline sale capture requires a previously server-validated open cashier shift,
2. cached shift authorization has an explicit expiry or offline validity policy,
3. a shift with pending offline transactions cannot be finalized as fully reconciled,
4. closing a shift while unsynced sales exist is either blocked or creates a clearly provisional close requiring later reconciliation,
5. synced offline sales are attributed to the original cashier shift, not automatically to whichever shift is open at synchronization time,
6. browser UI may calculate provisional expected cash for cashier awareness,
7. official shift and drawer totals remain server-authoritative,
8. reports must label locally captured but unsynced cash as provisional.

## 9. Time and Business-Date Authority

Device time is evidence, not final authority.

Queued offline transaction evidence must include:

1. `captured_at_device`,
2. `last_server_time`,
3. `device_clock_offset_at_last_sync`,
4. `offline_duration`,
5. `resolved_business_date` when assigned by the server,
6. `time_evidence_quality`.

The server resolves the committed business date using captured device time, last trusted server-time offset, tenant business-day policy, terminal sequence, and configured maximum offline duration.

Conflict cases include:

1. device clock moved backward,
2. timezone changed while offline,
3. sale crossed business-day cutoff,
4. queue synced days later,
5. local sequence order conflicts with timestamps,
6. terminal stayed offline beyond policy duration.

Additional outcomes:

```text
review_required_clock_drift
review_required_business_date
rejected_offline_window_expired
```

## 10. Offline Policy Limits

Controlled offline policy must define:

1. `maximum_offline_duration_minutes`,
2. `maximum_catalog_age_minutes`,
3. `maximum_price_policy_age_minutes`,
4. `maximum_shift_authorization_age_minutes`,
5. `maximum_unsynced_transaction_count`,
6. `maximum_unsynced_cash_amount`.

When a limit is crossed, offline capture is blocked or requires manager/support review according to policy. Cashier messaging must be actionable and must not show a generic failure.

## 11. Offline Transaction Envelope

Queued offline transaction evidence must include:

1. local offline transaction UUID,
2. terminal profile ID and terminal identifier,
3. terminal binding epoch,
4. tenant and branch IDs,
5. cashier ID,
6. shift, drawer, and cashier-session references,
7. local sequence or local monotonic ordering value,
8. cart item snapshots,
9. price and tax-relevant snapshots,
10. allowed discount snapshots,
11. payment snapshot showing cash-only tender,
12. captured-at device timestamp and local timezone,
13. catalog/cache version evidence,
14. customer identity snapshot where allowed,
15. request fingerprint,
16. sync attempt history,
17. local evidence checksum where practical,
18. local cash status,
19. cancellation or resolution evidence where applicable.

Local sequence is scoped to:

```text
terminal_id
terminal_binding_epoch
local_sequence
offline_transaction_uuid
```

The local sequence should be allocated only when the provisional cash transaction is durably committed to the local queue.

### 11.1 Fingerprint Timing

The canonical business payload fingerprint becomes immutable only after final local business content is assembled.

Required sequence:

```text
Build final cart
Resolve locally permitted pricing and discounts
Confirm fully cash-settled payment
Create canonical envelope
Generate canonical business payload fingerprint
Write envelope transactionally
Read back and verify
Mark locally captured
```

The server idempotency contract uses the canonical business payload fingerprint.

The fingerprint must not include mutable queue metadata such as:

1. retry count,
2. last error,
3. sync timestamps,
4. queue lease,
5. support notes.

Separate evidence:

```text
business_payload_fingerprint
queue_record_integrity_checksum
```

## 12. Discount and Customer Identity Policy

Offline discounts are allowed only when fully deterministic from cached policy.

Potentially allowed:

1. simple item markdown already embedded in cached pricing,
2. fixed promotional pricing,
3. prevalidated standard discount with no customer identity, statutory entitlement, or manager approval requirement.

Online-only:

1. all statutory discounts for the first release,
2. manager-approved discretionary discounts,
3. coupon redemption with usage limits,
4. loyalty reward redemption,
5. customer-segment entitlement,
6. one-time or centrally limited promotions.

Allowed offline discount evidence must include:

1. `discount_policy_id`,
2. `discount_policy_version`,
3. `eligibility_basis`,
4. `calculation_snapshot`.

The server must not silently recalculate a different amount and still treat the envelope as an exact accepted replay.

Customer identity first-release policy:

1. anonymous sales are allowed,
2. a stable customer UUID may be attached only when previously cached and policy permits,
3. customer master data cannot be created or edited offline,
4. loyalty accrual must not be promised until customer identity is accepted by the server,
5. if a customer is missing, merged, inactive, or cross-tenant at sync, policy decides whether the sale posts without loyalty or moves to review.

The customer-facing UI must not say "points earned" before server acceptance.

## 13. Idempotency and Replay

Server synchronization must be idempotent.

Exact replay of the same offline transaction envelope must:

1. not create duplicate sales,
2. not create duplicate payments,
3. not create duplicate inventory movements,
4. not create duplicate loyalty or store-credit effects,
5. return the previously accepted server result where available.

Replay drift must be rejected before mutation.

Material drift includes:

1. changed terminal identity,
2. changed tenant or branch,
3. changed cashier identity,
4. changed cart lines,
5. changed quantities,
6. changed prices or discounts,
7. changed cash payment total,
8. changed source fingerprint,
9. incompatible cached catalog/pricing version,
10. missing required local evidence.

## 14. Transaction Atomicity and Consequence Status

An offline sale envelope is accepted only when every required transactional consequence within the server checkout boundary commits atomically, or when an explicitly documented asynchronous consequence has durable outbox evidence and does not cause the sale to be falsely reported as fully processed.

Recommended first-release transaction boundary:

```text
sale + payment + inventory + variance
=
same committed transaction where architecture supports it
```

If loyalty uses an outbox or event flow:

1. `sync_status` may be `accepted` only when accepted under the consequence-completeness policy,
2. `loyalty_status` may be `pending` only when this state is explicit,
3. replay must not duplicate loyalty,
4. support can see pending or failed loyalty consequence,
5. cashier/customer messaging must not imply completion before it occurs.

Synchronization result must include consequence state:

```text
sync_status
sale_status
payment_status
inventory_status
variance_status
loyalty_status
receipt_status
review_reason
```

Avoid a generic `accepted` state when required consequences are incomplete.

## 15. Conflict, Drift, and Batch Ordering

Synchronization outcomes:

```text
accepted
replayed
retryable_failed
review_required
rejected
```

`review_required` is used when the system cannot safely decide whether to post, replay, or reject automatically.

Examples:

1. missing local sequence predecessor,
2. terminal identity mismatch,
3. stale or incompatible catalog snapshot,
4. policy changed while offline,
5. inventory, loyalty, or discount consequence cannot be safely produced,
6. suspected duplicate with non-matching fingerprint,
7. cash already collected but server posting cannot safely complete,
8. strict stock policy cannot be satisfied,
9. business-date or clock evidence requires review,
10. terminal was revoked while offline.

Specific review reasons include:

```text
review_required_cash_collected
review_required_suspected_duplicate
review_required_stock_policy
review_required_clock_drift
review_required_business_date
review_required_terminal_revoked
```

Review-required records must not be retried as ordinary network failures.

Catalog and policy drift must be classified as:

```text
benign_drift
material_drift
prohibited_drift
```

Examples:

1. benign: product description or display image changed,
2. material: price, tax, or recipe policy superseded while envelope contains immutable sale-time evidence,
3. prohibited: product deactivated before capture by server evidence, terminal revoked, discount never authorized, tax snapshot invalid, cached profile exceeded allowed age.

Server policy decides whether to honor captured price or reject. It must not silently substitute current pricing while returning `accepted`.

Batch synchronization policy:

1. synchronization uses per-envelope server transactions,
2. queue processing is sequence-aware,
3. records normally process in ascending local sequence,
4. `retryable_failed` pauses or retries according to policy,
5. `review_required` blocks later envelopes only when ordering dependency policy requires it,
6. independent later sales may continue only if the server can prove no shared dependency,
7. cashier/support UI must show when the queue is blocked by a predecessor,
8. the entire queue is never submitted as one all-or-nothing server transaction.

Duplicate detection beyond exact UUID replay:

1. exact local UUID replay returns the prior server result when fingerprints match,
2. a different local UUID may still represent the same business sale,
3. suspected duplicates should move to `review_required_suspected_duplicate` unless exact replay can be proven.

Duplicate search evidence may include:

```text
tenant
branch
terminal epoch
cashier
captured time window
cart total
cash amount
cart fingerprint
local sequence
```

## 16. Terminal Identity, Authorization, and Recovery

Terminal identity remains mandatory.

Rules:

1. terminal shell access requires verified terminal context,
2. sync requests must bind to terminal identity,
3. cross-tenant or cross-branch terminal identity fails closed,
4. missing terminal context fails closed with recovery guidance,
5. terminal reinstall or storage loss cannot silently reuse old local queue identity,
6. orphaned local queued transactions require support review,
7. terminal rebinding must not claim ownership of another terminal's queued records without explicit support workflow.

Additional terminal authorization evidence:

1. `terminal_authorization_version`,
2. `offline_profile_version`,
3. `offline_authorized_until`.

Sync policy must distinguish:

1. valid at capture and still acceptable at sync,
2. revoked after capture but envelopes allowed for review,
3. suspected stolen terminal,
4. cross-branch rebinding,
5. subscription or profile revocation.

A revoked terminal's queued records must not simply auto-post.

On terminal rebinding or reinstall:

1. increment binding epoch,
2. never restart a prior epoch silently,
3. old queue entries remain bound to the old epoch.

## 17. Queue Ownership, Local Storage, and Evidence

Local terminal storage may contain provisional evidence only.

It must:

1. store enough information to retry sync safely,
2. preserve local reference and sync state,
3. avoid storing secrets or long-lived credentials,
4. preserve queue diagnostics for support,
5. survive page refresh where browser storage remains available,
6. expose recoverable error states to the cashier or support user.

It must not:

1. be treated as the official sale ledger,
2. finalize fiscal records,
3. finalize inventory,
4. finalize loyalty or store credit,
5. bypass server-side authorization.

Queue ownership fields:

1. `queue_owner_instance_id`,
2. `lease_acquired_at`,
3. `lease_expires_at`,
4. `sync_worker_version`.

Retry metadata:

1. `next_retry_at`,
2. `retry_policy_version`,
3. `automatic_retry_count`,
4. `manual_retry_count`.

Single-writer rules:

1. only one queue processor may actively sync a terminal queue at a time,
2. UI retry and automatic retry share the same lease,
3. stale leases can expire and be recovered,
4. multiple browser tabs cannot independently mutate queue state,
5. service-worker and foreground sync must not race.

Retry rules:

1. retryable network or server failures use bounded exponential backoff,
2. reconnection may trigger an immediate eligible retry,
3. `review_required` and `rejected` never auto-retry,
4. manual retry cannot bypass the same status restrictions,
5. excessive retries must not overload the server,
6. retry behavior must remain observable.

Durable capture rule:

> Offline capture is locally confirmed only after transactional persistence and immediate read-back verification. Local confirmation reduces ordinary write-loss risk but does not convert browser storage into the official transaction ledger or eliminate device-loss risk.

If durable local persistence fails, the cashier must not be shown a successful offline sale state.

Required durability steps:

1. write transaction,
2. read-back verification,
3. checksum verification.

Capacity and storage states:

```text
queue_capacity_warning
queue_capacity_block
storage_unavailable
```

Local data protection rules:

1. store the minimum data required for safe replay and support,
2. use encryption at rest where platform capabilities support it,
3. require authenticated application access,
4. apply automatic lock/logout behavior,
5. mask sensitive fields in diagnostics,
6. purge or compact after retention,
7. never store raw approval credentials or PINs,
8. never store payment-card data.

A checksum detects accidental corruption but does not establish authenticity if a local user can modify both payload and checksum.

First-release local evidence must explicitly state whether it provides:

1. corruption detection only, or
2. cryptographic device-bound authenticity.

If tamper resistance is required, use a server-issued device key or signed terminal context rather than describing a plain local checksum as a security signature.

### 17.1 Cashier Session and Logout Behavior

Queue ownership belongs to the terminal epoch and business context. Cashier access to queue details remains permission-controlled.

Offline session policy must define:

1. maximum inactive duration,
2. screen lock behavior,
3. whether cashier switching is allowed offline,
4. whether another cashier can access the first cashier's pending queue,
5. browser restart behavior,
6. whether local PIN or biometric reauthentication can be verified offline,
7. whether pending records remain visible only to support or authorized shift users.

Cashier switching must not change the actor attributed to existing envelopes.

Accepted local records should not immediately erase all local evidence. They should move through:

```text
accepted_retained
accepted_compacted
purged
```

Minimal accepted tombstone:

1. local UUID,
2. local sequence,
3. server sale reference,
4. accepted fingerprint,
5. accepted timestamp,
6. terminal epoch,
7. final sync status.

Unnecessary customer/cart detail should be removed according to retention and privacy policy.

## 18. Inventory, Loyalty, and Store Credit Consequences

Offline terminal capture does not locally post inventory, loyalty, or store credit.

Offline stock visibility first-release policy:

1. product catalog and pricing may be cached,
2. current stock must not be presented as authoritative while offline,
3. cached stock may be shown only with a stale/provisional label and last synchronized timestamp,
4. offline capture does not locally deduct displayed stock,
5. strict versus soft negative-stock policy is evaluated by the server at synchronization,
6. cashier messaging must not promise availability based only on cached quantity.

On server acceptance:

1. `SaleCreationService` or the established server checkout path creates the committed sale.
2. Inventory services produce canonical movements and recipe effects.
3. Loyalty accrual occurs only through the approved loyalty runtime.
4. Store credit mutation occurs only through the approved store-credit ledger paths.
5. Every consequence must remain idempotent on replay.

If a required server consequence cannot be produced, the sync must fail closed into retry, rejection, or review according to policy.

If strict stock policy prevents posting after cash and goods may have changed hands, the record should enter review rather than be treated as an ordinary rejected request.

## 19. Hardware Boundary

Hardware behavior is release-gated separately.

Epic 41 must distinguish:

1. receipt printer unavailable,
2. receipt printer available but print fails,
3. cash drawer unavailable,
4. cash drawer available but open signal fails,
5. barcode scanner unavailable,
6. tablet browser-only mode.

No hardware readiness claim may be made without physical validation evidence.

## 20. Security and Privacy

Offline terminal behavior must preserve:

1. tenant isolation,
2. branch isolation,
3. terminal identity,
4. cashier identity,
5. least-privilege permissions,
6. no local storage of secrets,
7. no exposure of customer or employee data beyond what the cashier flow already requires,
8. safe support diagnostics without credential leakage.

## 21. Observability and Support

Support diagnostics must expose:

1. local offline transaction reference,
2. server sale reference where accepted,
3. terminal identity,
4. cashier identity,
5. branch and tenant,
6. sync status,
7. retry count,
8. last error code,
9. conflict/review reason,
10. sync timestamps,
11. idempotency/fingerprint reference,
12. hardware state where relevant,
13. queue owner and lease state,
14. terminal binding epoch,
15. consequence statuses,
16. provisional versus official receipt state,
17. shift and drawer references,
18. cash-collected review and resolution status,
19. retry schedule and counts.

## 22. Release Gate

Epic 41 is not complete until pilot UAT proves:

1. online baseline checkout remains stable,
2. offline shell and cached catalog work,
3. cash-only offline capture queues correctly,
4. reconnect sync posts accepted records once,
5. exact replay is idempotent,
6. drift and conflicts fail closed,
7. online-only operations remain blocked offline,
8. terminal reinstall and identity-loss recovery are documented,
9. hardware availability and deferrals are explicit,
10. support can diagnose and close queued records,
11. no fiscal, inventory, loyalty, or store-credit authority is moved into the browser,
12. cash accepted but local persistence failure blocks success state,
13. shift close with unsynced records is blocked or clearly provisional,
14. device clock drift and business-date review are validated,
15. terminal revocation while offline is validated,
16. multiple tabs cannot race queue synchronization,
17. provisional acknowledgment cannot be mistaken for official invoice,
18. accepted tombstones and retention behavior are validated,
19. cash-collected review handling is validated,
20. statutory discounts are blocked offline,
21. cached stock is labelled provisional and not locally deducted,
22. suspected duplicate detection sends uncertain matches to review,
23. cashier switching preserves original envelope ownership.

## 23. Architecture Constraints

These constraints may not be violated by future stories unless this document is formally revised:

1. Offline browser state remains provisional.
2. Server posting remains authoritative.
3. Cash is the only first-release offline tender.
4. Non-cash payment remains online-only.
5. Void and refund remain online-only.
6. Inventory mutation remains server-authoritative.
7. Loyalty and store-credit mutation remain server-authoritative.
8. Fiscal finalization remains server-authoritative.
9. Terminal identity is mandatory for shell access and sync.
10. Replay must be idempotent.
11. Drift must fail closed before mutation.
12. Review-required conflicts must not retry as ordinary network failures.
13. Hardware readiness cannot be claimed without physical device evidence.
14. No story may introduce browser-local official sale, receipt, inventory, loyalty, store-credit, or fiscal ledgers.
15. Offline cash capture is not complete until the transaction envelope is durably written and verified in local storage.
16. Local offline references, server sale references, and official invoice identifiers are separate identities.
17. Offline customer documents are provisional unless the registered fiscal configuration explicitly permits official offline issuance.
18. Offline capture requires a previously server-validated cashier shift and terminal offline authorization that have not expired.
19. Device timestamps are evidence; server policy resolves committed business date and detects clock drift.
20. Each queue is bound to a terminal identity epoch and uses a single-writer synchronization lease.
21. Accepted records retain a minimal local tombstone before privacy-based compaction or purge.
22. An envelope is accepted only when required server consequences are atomic or durably represented by an explicit pending consequence state.
23. Synchronization processes envelopes per transaction and normally in local-sequence order; review-required predecessors block only when dependency policy requires it.
24. Cached catalog, pricing, discount, shift, and terminal policies have explicit age and offline-duration limits.
25. Offline dine-in ticket mutation is unsupported in the first release; offline capture is a standalone cash checkout path.
26. No UI may promise official receipt completion, inventory posting, loyalty accrual, or central reporting until server acceptance.
27. Cash-collected records that cannot be posted remain preserved as explicit support-resolution cases.
28. Durable captured envelopes are immutable; local edit, deletion, and cancellation are blocked after durable cash capture.
29. Cached stock is not authoritative offline and is never locally deducted as committed inventory.
30. Top-level synchronization status stays generic; consequence-specific pending states belong in consequence status fields.
31. All statutory discounts remain online-only for the first release.
32. Cashier switching never changes the actor attributed to existing envelopes.
33. Suspected duplicate business captures with different local UUIDs enter review unless exact replay can be proven.
