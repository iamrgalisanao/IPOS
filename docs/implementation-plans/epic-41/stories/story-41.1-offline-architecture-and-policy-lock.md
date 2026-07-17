# Story 41.1 Offline Architecture and Policy Lock

## Status

Approved

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Create the implementation specification that locks the first-release offline policy boundary for POS terminals.

This story does not implement runtime queue, synchronization, hardware, inventory, loyalty, or fiscal behavior. It defines the exact contracts those later stories must follow.

## Evidence Boundary

Competitor evidence validates visible offline operating patterns only.

Public materials from comparable POS providers support:

1. offline transaction continuity,
2. later synchronization,
3. visible online/offline and sync status,
4. cashier, branch, terminal, and shift awareness,
5. restricted centrally dependent features,
6. cash accountability and formal day-end behavior.

Public competitor documentation does not establish IPOS's detailed backend architecture for:

1. queue leases,
2. terminal binding epochs,
3. immutable envelope fingerprints,
4. cash-collected review lifecycle,
5. suspected duplicate detection,
6. server consequence atomicity,
7. fiscal identity separation,
8. local tombstone retention.

Story 41.1 therefore uses competitor evidence as market validation for operational needs, not as proof that StoreHub, UTAK, Mosaic, or any other provider implements the same internal controls.

## Dependencies

Requires:

1. Epic 41 Architecture Lock.
2. Epic 41 Implementation Guide.
3. Existing POS terminal identity binding.
4. Existing POS offline queue behavior.
5. Existing offline stabilization and UAT validation notes.
6. Epic 39 loyalty runtime architecture.
7. Epic 40 inventory movement architecture.

## Complexity

Medium

## Scope

In scope:

1. Offline allowed and blocked operation matrix.
2. Terminal offline policy contract.
3. Provisional local queue versus server authority boundary.
4. Offline transaction envelope contract.
5. Local evidence and retention requirements.
6. Replay, drift, review, and rejection vocabulary.
7. Provisional receipt and official invoice boundary.
8. Shift, drawer, and cashier accountability rules.
9. Trusted-time and business-date policy.
10. Discount, statutory discount, and customer identity policy.
11. Offline stock visibility policy.
12. Cash-collected review and support-resolution rules.
13. Queued-record immutability and cancellation rules.
14. Cashier switching and cached-session expiry rules.
15. Hardware validation versus deferral policy.
16. Policy precedence and policy snapshot contract.
17. Operator-visible sync status requirements.
18. Review ownership and escalation expectations.

Out of scope:

1. Runtime queue rewrite.
2. New synchronization endpoint implementation.
3. Hardware adapter implementation.
4. Official offline invoice issuance.
5. Non-cash offline payment.
6. Offline void or refund.
7. Offline dining ticket mutation.
8. Offline inventory mutation.
9. Offline loyalty or store-credit mutation.

## Locked First-Release Policy

The only first-release offline cashier mutation is:

```text
provisional cash sale capture
```

It is allowed only when all required policy gates pass:

1. terminal identity is valid,
2. terminal offline profile is valid,
3. cashier is authenticated and authorized,
4. cached open-shift authority is valid,
5. cached catalog and pricing snapshots are within policy age,
6. transaction is fully cash-settled,
7. local queue persistence succeeds,
8. local envelope read-back and checksum verification succeeds,
9. server synchronization later accepts the envelope.

## Policy Precedence

Effective offline policy is resolved in this order:

```text
Architecture Lock
Platform non-overridable safety rules
Tenant offline policy
Branch strengthening
Terminal profile strengthening
Cached authorization snapshot
```

Rules:

1. a lower scope may make offline behavior stricter,
2. a lower scope may not enable something blocked by the Architecture Lock,
3. missing or contradictory policy fails closed,
4. server policy remains authoritative at synchronization,
5. a cached policy proves why capture was allowed locally, but does not guarantee server acceptance.

## Online-Only Operation Matrix

The implementation specification must mark these online-only:

| Operation | First-release offline policy |
| --- | --- |
| Card payment | Blocked |
| E-wallet payment | Blocked |
| Bank transfer | Blocked |
| External payment authorization | Blocked |
| Store credit issuance | Blocked |
| Store credit redemption | Blocked |
| Loyalty redemption | Blocked |
| Loyalty accrual posting | Server-only after accepted sync |
| Statutory discount | Blocked |
| Manager discretionary discount | Blocked |
| Coupon with usage limits | Blocked |
| Void | Blocked |
| Refund | Blocked |
| Dining ticket mutation | Blocked |
| Table occupancy update | Blocked |
| Split bill, merge, transfer | Blocked |
| Inventory stocktake | Blocked |
| Inventory adjustment | Blocked |
| Terminal activation/rebinding | Blocked |
| User/role administration | Blocked |
| Official fiscal posting | Server-only |
| Z-read/GCT/e-journal finalization | Server-only |

## Terminal Offline Policy Contract

The implementation must define a server-issued terminal offline profile containing:

1. `terminal_id`,
2. `terminal_authorization_version`,
3. `offline_profile_version`,
4. `offline_authorized_until`,
5. `maximum_offline_duration_minutes`,
6. `maximum_catalog_age_minutes`,
7. `maximum_price_policy_age_minutes`,
8. `maximum_shift_authorization_age_minutes`,
9. `maximum_unsynced_transaction_count`,
10. `maximum_unsynced_cash_amount`,
11. allowed local discount policy IDs,
12. receipt acknowledgment policy,
13. cashier session and lock policy,
14. local evidence retention policy.

The effective policy snapshot attached to a captured envelope must include:

1. `offline_policy_snapshot_id`,
2. `offline_policy_version`,
3. `offline_policy_fingerprint`,
4. `offline_policy_effective_at`.

The snapshot must resolve:

1. duration limits,
2. transaction and cash limits,
3. catalog age,
4. price policy age,
5. shift authorization age,
6. discount allowlist,
7. customer identity policy,
8. receipt acknowledgment policy,
9. session lock policy,
10. stock display policy.

If any required policy is missing, expired, cross-tenant, cross-branch, or incompatible with the terminal, offline capture fails closed.

## Offline Transaction Envelope Contract

The policy spec must define the canonical envelope fields for later implementation:

1. `offline_transaction_uuid`,
2. `tenant_id`,
3. `branch_id`,
4. `terminal_id`,
5. `terminal_binding_epoch`,
6. `terminal_profile_id`,
7. `cashier_id`,
8. `shift_id`,
9. `drawer_session_id`,
10. `cashier_session_id`,
11. `local_sequence`,
12. `cart_item_snapshots`,
13. `price_snapshot`,
14. `tax_relevant_snapshot`,
15. `allowed_discount_snapshots`,
16. `cash_payment_snapshot`,
17. `captured_at_device`,
18. `local_timezone`,
19. `last_server_time`,
20. `device_clock_offset_at_last_sync`,
21. `offline_duration`,
22. `catalog_cache_version`,
23. `pricing_policy_version`,
24. `offline_policy_snapshot_id`,
25. `offline_policy_version`,
26. `offline_policy_fingerprint`,
27. `offline_policy_effective_at`,
28. `customer_identity_snapshot` when allowed,
29. `cash_snapshot`,
30. `business_payload_fingerprint`.

Mutable queue metadata such as retry count, last error, lease owner, sync timestamps, and support notes must not be included in the canonical business payload fingerprint.

Immutable business envelope data includes:

1. identity,
2. terminal, branch, cashier, shift, and drawer evidence,
3. cart item snapshots,
4. pricing, tax, and allowed discount evidence,
5. cash tender evidence,
6. customer snapshot when allowed,
7. capture time evidence,
8. policy snapshot evidence,
9. business payload fingerprint.

Mutable queue projection data includes:

1. `queue_status`,
2. `cash_status`,
3. `server_status`,
4. `resolution_status`,
5. retry counts,
6. error fields,
7. lease fields,
8. sync timestamps,
9. support notes.

Append-only status history must preserve:

1. event,
2. from status,
3. to status,
4. timestamp,
5. actor or worker,
6. reason,
7. error code,
8. support reference.

## Local Capture Sequence

The policy must require this sequence:

```text
Build final cart
Resolve locally allowed pricing and discounts
Confirm fully cash-settled payment
Create canonical envelope
Generate business payload fingerprint
Write envelope transactionally
Read back and verify
Verify checksum
Mark locally captured
Show provisional acknowledgment
```

If transactional write, read-back, or checksum verification fails, the cashier must not see a successful offline sale state.

If cash may have been collected but durable capture cannot be confirmed, the record must enter capture-uncertain review rather than inviting immediate duplicate re-entry.

Capture-uncertain behavior:

1. use `capture_status = uncertain` where local evidence supports it,
2. preserve any retrievable local evidence,
3. show supervisor/support instructions,
4. include the event in provisional drawer accountability,
5. route suspected recapture under another UUID through duplicate review.

## Fiscal and Receipt Boundary

First release uses a provisional acknowledgment only.

Required wording must clearly communicate:

```text
OFFLINE TRANSACTION ACKNOWLEDGMENT
Not yet posted as official sale
Local reference: ...
```

The acknowledgment must communicate:

1. payment was recorded locally,
2. central posting is pending,
3. local reference,
4. amount received,
5. it is not yet the official invoice,
6. how the customer obtains the official invoice,
7. how the customer contacts the branch if posting fails.

Exact wording and print behavior require formal compliance signoff before production use.

The specification must preserve separate identities:

1. `local_offline_reference`,
2. `server_sale_uuid`,
3. `server_sale_number`,
4. `official_invoice_number`.

The local reference is never an official sale or invoice number. Official invoice retrieval or delivery after synchronization must be specified in Story 41.3.

## Shift, Drawer, and Cash Accountability

Offline capture requires cached proof of a server-validated open shift.

Rules:

1. cached shift authority has an expiry,
2. capture is blocked if no valid cached open shift exists,
3. pending offline records remain attributed to their original cashier and shift,
4. cashier switching does not change envelope ownership,
5. browser UI may show provisional expected cash,
6. official shift and drawer totals remain server-authoritative,
7. final shift close is blocked while unresolved locally captured records exist,
8. unresolved cash-collected records remain visible until support resolution.

## Cash Evidence Contract

The immutable cash snapshot must include:

1. `currency`,
2. `sale_total`,
3. `cash_tendered`,
4. `change_due`,
5. `cash_net_collected`,
6. `rounding_policy_id`,
7. `rounding_policy_version`,
8. `rounding_adjustment`,
9. `cash_confirmed_at_device`.

`maximum_unsynced_cash_amount` is based on unresolved offline sale totals or net collected exposure, not gross tendered cash before change.

## Cash-Collected Review Policy

The policy must distinguish:

```text
capture_status
cash_status
server_status
resolution_status
```

When cash may have been collected and server sync cannot safely accept the envelope:

1. the record enters review,
2. it is not deleted,
3. it is not retried indefinitely,
4. it remains part of provisional drawer accountability,
5. support can see that cash and goods may have changed hands,
6. resolution requires a governed outcome.

Allowed resolution outcomes:

1. approve and post original captured sale,
2. document cash return,
3. formally reject,
4. replace through another governed sale where policy permits.

## Queued Record Immutability

Before cash completion, a cart draft may be abandoned.

After durable capture with cash collected:

1. material business fields are immutable,
2. envelope payload is not edited,
3. envelope is not silently deleted,
4. correction requires later governed void/refund after accepted sync or support resolution if posting cannot occur,
5. local edit, deletion, and cancellation are blocked.

First-release policy prohibits local cancellation after durable cash capture.

Resolution is either:

1. synchronize and perform an authorized online void/refund after acceptance, or
2. use the cash-collected support-resolution workflow if the envelope cannot be accepted.

## Discount and Customer Identity Policy

Allowed offline discounts:

1. simple item markdown embedded in cached pricing,
2. fixed promotional pricing with valid cached policy,
3. prevalidated standard discount with no customer identity, statutory entitlement, or manager approval requirement.

Blocked offline:

1. all statutory discounts,
2. manager-approved discretionary discounts,
3. coupons with usage limits,
4. loyalty redemption,
5. customer-segment entitlement,
6. one-time or centrally limited promotions.

Customer identity policy:

1. anonymous sales are allowed,
2. cached stable customer UUID may be attached only when policy permits,
3. customer creation and edits are blocked offline,
4. loyalty accrual is not promised before server acceptance,
5. missing, merged, inactive, or cross-tenant customers cause either post-without-loyalty or review according to server policy.

## Offline Stock Visibility Policy

The policy must state:

1. cached catalog and pricing may be shown,
2. cached product sellability and cached inventory quantity are separate concepts,
3. product sellability may be cached within policy age,
4. an item marked unavailable in the last accepted catalog is blocked,
5. current stock is not authoritative offline,
6. cached stock may be shown only with stale/provisional labelling and last-sync timestamp,
7. offline capture does not locally deduct displayed stock,
8. cached quantity alone does not authorize sale,
9. strict versus soft negative-stock policy is evaluated at server synchronization,
10. stock conflict after cash collection enters review rather than disappearing from accountability.

## Time and Business-Date Policy

Device time is evidence only.

The server resolves committed business date using:

1. `captured_at_device`,
2. `last_server_time`,
3. `device_clock_offset_at_last_sync`,
4. tenant business-day policy,
5. local sequence,
6. maximum offline duration.

Clock drift, timezone changes, business-day cutoff crossings, and late synchronization may cause review or rejection.

Time-quality outcomes:

```text
trusted
offset_estimated
device_changed
timezone_changed
sequence_conflict
offline_window_exceeded
```

Rules:

1. local sequence orders captures within one terminal epoch,
2. device timestamp does not reorder the queue,
3. business date is server resolved,
4. cashier cannot manually change the committed business date,
5. finance/support owns business-date review.

## Queue Ownership and Retry Policy

Story 41.1 must specify that later stories implement:

1. terminal epoch scoped local sequence,
2. single-writer queue lease,
3. retry metadata,
4. bounded exponential backoff,
5. no auto-retry for `review_required` or `rejected`,
6. manual retry cannot bypass status restrictions,
7. suspected duplicate detection moves uncertain matches to review.

## Sync Status Vocabulary

Top-level statuses:

```text
accepted
replayed
retryable_failed
review_required
rejected
```

Consequence statuses are separate:

```text
sale_status
payment_status
inventory_status
variance_status
loyalty_status
receipt_status
```

Do not introduce consequence-specific top-level statuses such as `accepted_with_pending_loyalty`.

## Operator Sync Visibility

The cashier-facing terminal status must show at minimum:

1. connection state: online, intermittent, or offline,
2. pending transaction count,
3. pending cash amount,
4. oldest pending age,
5. last successful synchronization,
6. blocked or review-required count,
7. current terminal and branch,
8. current cashier and shift.

## Review Reasons

Required review reasons:

```text
review_required_cash_collected
review_required_suspected_duplicate
review_required_stock_policy
review_required_clock_drift
review_required_business_date
review_required_terminal_revoked
review_required_capture_uncertain
```

## Review Ownership and Service Expectations

Each review reason must have configured ownership and service expectations.

| Review reason | Primary owner |
| --- | --- |
| Cash collected | Branch manager and support |
| Suspected duplicate | Support/audit |
| Stock policy | Inventory controller and support |
| Clock drift | Support |
| Business date | Finance/operations and support |
| Terminal revoked | Security/tenant admin and support |
| Capture uncertain | Branch manager and support |

The policy must define:

1. acknowledgment target,
2. resolution target,
3. escalation threshold,
4. customer follow-up owner.

## Security and Local Data Protection

The policy must define:

1. minimum data stored locally,
2. diagnostics masking,
3. no raw approval PINs or credentials,
4. no card data,
5. automatic lock/logout,
6. local storage retention and compaction,
7. accepted tombstone policy,
8. checksum as corruption detection only unless cryptographic device-bound authenticity is explicitly designed.

## Hardware Policy

Hardware readiness is not claimed without physical validation.

Story 41.1 documents the boundary only:

1. printer unavailable,
2. printer available but print fails,
3. cash drawer unavailable,
4. drawer available but open signal fails,
5. scanner unavailable,
6. tablet browser-only mode.

Story 41.7 owns recovery and physical validation details.

## Acceptance Criteria

1. Offline allowed/blocked operation matrix is complete.
2. Cash-only provisional capture is the only first-release offline mutation.
3. Server authority is preserved for all committed business consequences.
4. Provisional acknowledgment cannot be mistaken for official invoice.
5. Local, server sale, and official invoice identities are separate.
6. Offline dine-in ticket mutation is explicitly unsupported.
7. Offline capture is blocked without valid cached open-shift authority.
8. Cash-collected unresolved records have explicit review and resolution rules.
9. Captured envelopes are immutable after durable cash capture.
10. Local edit, deletion, and cancellation are blocked after durable cash capture.
11. Cached stock is not presented as authoritative offline.
12. Cached stock is not locally deducted.
13. Cached product sellability and cached stock quantity are separate concepts.
14. All statutory discounts are online-only.
15. Customer and loyalty messaging does not promise points before server acceptance.
16. Device time is evidence; server resolves committed business date.
17. Top-level sync status remains generic and consequence statuses are separate.
18. Retry and review vocabularies prevent review-required records from auto-retrying.
19. Cashier switching preserves original envelope actor evidence.
20. Local storage success requires write, read-back, and checksum verification.
21. Competitor evidence is used only to validate visible operational patterns, not undocumented backend architecture.
22. Policy precedence applies stricter lower-scope policies but cannot weaken platform restrictions.
23. Complete offline policy snapshot version and fingerprint are available for support review.
24. Immutable business envelope data is separated from mutable queue projection data.
25. Cash evidence preserves sale total, tendered amount, change, net collected exposure, currency, and rounding evidence.
26. Capture-uncertain recovery prevents ordinary duplicate re-entry after uncertain local persistence.
27. Operator sync visibility includes connection state, pending count, pending cash, oldest pending age, last sync, and review count.
28. Final shift close is blocked while unresolved locally captured records exist.
29. Review ownership and service expectations are defined.
30. Policy does not conflict with Epic 39 loyalty or Epic 40 inventory architecture.

## Test Planning Notes

This story is documentation/specification only. Later implementation stories should derive tests for:

1. operation matrix enforcement,
2. offline statutory discount blocking,
3. no mixed tender restoration,
4. missing/expired shift authority,
5. durable capture failure,
6. cash-collected review status,
7. envelope immutability,
8. cached stock stale label,
9. cashier switching,
10. clock drift review,
11. suspected duplicate review,
12. policy precedence conflict,
13. policy snapshot evidence,
14. immutable envelope versus mutable queue state,
15. cash evidence and rounding,
16. capture-uncertain recovery,
17. operator sync visibility,
18. shift close blocked while unresolved records exist,
19. product sellability versus stock quantity display,
20. review ownership routing.

## Definition of Done

Story 41.1 is done when:

1. the implementation specification is reviewed and approved,
2. policy contracts are explicit enough for Stories 41.2 through 41.8,
3. no code changes are introduced,
4. Architecture Lock invariants are preserved,
5. story index is updated,
6. documentation review passes,
7. CI is not required unless repo policy runs docs checks.
