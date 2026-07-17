# Story 41.1 Offline Architecture and Policy Lock

## Status

Draft for Review

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Create the implementation specification that locks the first-release offline policy boundary for POS terminals.

This story does not implement runtime queue, synchronization, hardware, inventory, loyalty, or fiscal behavior. It defines the exact contracts those later stories must follow.

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
24. `customer_identity_snapshot` when allowed,
25. `business_payload_fingerprint`,
26. `queue_record_integrity_checksum`,
27. `capture_status`,
28. `cash_status`,
29. `server_status`,
30. `resolution_status`.

Mutable queue metadata such as retry count, last error, lease owner, sync timestamps, and support notes must not be included in the canonical business payload fingerprint.

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

## Fiscal and Receipt Boundary

First release uses a provisional acknowledgment only.

Required wording must clearly communicate:

```text
OFFLINE TRANSACTION ACKNOWLEDGMENT
Not yet posted as official sale
Local reference: ...
```

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
7. shift close with unresolved offline records is blocked or clearly provisional,
8. unresolved cash-collected records remain visible until support resolution.

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
5. any allowed local pre-sync cancellation is a separate append-only event.

Cancellation evidence must include:

1. `cancelled_by`,
2. `cancelled_at`,
3. `reason`,
4. `cash_returned`,
5. `original_envelope_checksum`,
6. `original_business_payload_fingerprint`.

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
2. current stock is not authoritative offline,
3. cached stock may be shown only with stale/provisional labelling and last-sync timestamp,
4. offline capture does not locally deduct displayed stock,
5. strict versus soft negative-stock policy is evaluated at server synchronization,
6. stock conflict after cash collection enters review rather than disappearing from accountability.

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

## Review Reasons

Required review reasons:

```text
review_required_cash_collected
review_required_suspected_duplicate
review_required_stock_policy
review_required_clock_drift
review_required_business_date
review_required_terminal_revoked
```

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
10. Local cancellation, if permitted, is append-only evidence.
11. Cached stock is not presented as authoritative offline.
12. Cached stock is not locally deducted.
13. All statutory discounts are online-only.
14. Customer and loyalty messaging does not promise points before server acceptance.
15. Device time is evidence; server resolves committed business date.
16. Top-level sync status remains generic and consequence statuses are separate.
17. Retry and review vocabularies prevent review-required records from auto-retrying.
18. Cashier switching preserves original envelope actor evidence.
19. Local storage success requires write, read-back, and checksum verification.
20. Policy does not conflict with Epic 39 loyalty or Epic 40 inventory architecture.

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
11. suspected duplicate review.

## Definition of Done

Story 41.1 is done when:

1. the implementation specification is reviewed and approved,
2. policy contracts are explicit enough for Stories 41.2 through 41.8,
3. no code changes are introduced,
4. Architecture Lock invariants are preserved,
5. story index is updated,
6. documentation review passes,
7. CI is not required unless repo policy runs docs checks.
