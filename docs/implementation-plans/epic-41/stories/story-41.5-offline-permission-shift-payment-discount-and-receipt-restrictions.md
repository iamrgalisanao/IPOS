# Story 41.5 Offline Permission, Shift, Payment, Discount, and Receipt Restrictions

## Status

Ready for Implementation

Date: 2026-07-17

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Harden the cashier-facing offline checkout boundary so controlled offline capture remains limited to first-release provisional cash sales with valid cached shift authority, safe permissions, no offline approvals, no non-cash payment construction, no statutory discount application, and no document that can be mistaken for an official invoice.

Story 41.5 is intentionally a restriction and guardrail story. It should not expand offline capabilities. It should make the existing offline cashier experience safer, clearer, and harder to bypass from restored UI state, stale local storage, browser console calls, queued server-sale payments, or backend sync payloads.

## Dependencies

Requires:

1. Story 41.1 offline architecture and policy lock.
2. Story 41.2 offline queue identity, durable capture, queue metadata, cash status, and diagnostics.
3. Story 41.3 server synchronization, idempotency, transaction atomicity, and official identity allocation.
4. Story 41.4 review-required conflict handling and role-safe diagnostics.
5. Existing POS checkout shell and terminal context enforcement.
6. Existing shift, drawer, payment, statutory discount, receipt, and manager authorization behavior.

## Complexity

Large

## Architecture Constraints

Story 41.5 must preserve these locked decisions:

1. Offline terminal behavior is provisional only.
2. The first-release offline mutation boundary is standalone cash sale capture.
3. Existing server-created sale payment recording remains online-only.
4. Card, e-wallet, bank transfer, store credit, loyalty redemption, external tender, on-account, and mixed tender are blocked offline.
5. Statutory discounts are online-only in the first release.
6. Manager approval issuance is online-only.
7. Official invoice, receipt compliance data, BIR fiscal records, e-journal, GCT, and Z-read authority remain server-side.
8. Cached shift authority is evidence for allowing local capture, not final shift settlement authority.
9. Offline sale envelopes are immutable after durable cash capture.
10. Unsynced cash-collected records remain visible to cashier, support, and drawer accountability workflows.
11. Browser UI restrictions are not sufficient; server sync validation must enforce the same policy.
12. Offline route/action guards must fail closed and use recoverable online-required messaging.
13. No local queue path may bypass Story 41.2 and Story 41.3 by later posting directly to ordinary payment endpoints.
14. Story 41.5 follows the Mosaic-style API-first validation and lifecycle benchmark already used by Stories 41.3 and 41.4.
15. StoreHub remains a secondary benchmark for cashier UX, employee/shift workflow, BackOffice review, and online-only customer/loyalty behavior.
16. UTAK remains a secondary benchmark for simple SMB-friendly offline cashier operation and sync expectations.

## Benchmark Direction

Primary benchmark:

```text
Mosaic-style API-first policy enforcement
+
IPOS-owned offline terminal guard and synchronization module
```

Story 41.5 should extend the existing Epic 41 synchronization architecture rather than introduce a new provider-style subsystem.

Conceptual flow:

```text
POS UI action
    |
OfflineActionPolicy
    |
CachedShiftAuthorityValidator
    |
CashOnlyPaymentValidator
    |
Canonical OfflineSalesQueue envelope
    |
OfflineSync API
    |
OfflineEnvelopePolicyValidator
    |
Story 41.4 reject/review decision
    |
SaleCreationService only when allowed
```

The provider benchmark is architectural only. Do not add a runtime dependency on Mosaic, StoreHub, or UTAK.

## Scope

In scope:

1. Offline payment method matrix and first-release cash-only enforcement.
2. Guarding the split-payment wizard while offline or in offline capture mode.
3. Removing or quarantining offline payment queue behavior for existing server-created sales.
4. Cached open-shift authority validation for offline capture.
5. Cashier switch, logout, and shift-close restrictions when unresolved offline records exist.
6. Blocking statutory discounts, manager approval issuance, and privileged route actions offline.
7. Provisional receipt/acknowledgment wording and print behavior.
8. Provisional expected-cash display and drawer-accountability labeling.
9. Backend sync validation for payment, discount, shift, and fiscal-document policy.
10. Frontend and backend regression tests for offline restrictions.

Out of scope:

1. New payment provider integrations.
2. Offline card, e-wallet, bank transfer, store credit, loyalty, or external payment authorization.
3. Offline manager approval issuance.
4. Official offline invoice issuance.
5. Offline void/refund.
6. Offline dining ticket mutation.
7. Final support resolution execution for review-required records.
8. Hardware printer certification or physical cash drawer validation.

## Current Implementation Context

Relevant existing files:

1. `resources/js/Pages/POS/Index.jsx`
   - Builds offline capture payloads.
   - Restores cart and payment wizard state from local draft storage.
   - Uses cached active-shift data when offline.
   - Calls `offlineSalesQueue.appendTransaction(...)` for offline sale capture.
   - Opens `SplitPayWizard` in `offlineCaptureMode` for offline draft sales.

2. `resources/js/Pages/POS/Components/SplitPayWizard.jsx`
   - Uses grouped payment method buttons.
   - Filters payment methods by `allow_offline` while offline.
   - Allows `onOfflineCapture(...)` in offline capture mode.
   - Currently has a separate offline branch that can queue payments against an existing server sale through `offlinePaymentQueue`.

3. `resources/js/POS/offline/offlinePaymentQueue.ts`
   - Stores pending payments for existing server-created sales in `localStorage`.
   - Posts later to `/pos/sales/{sale}/payments/split`.
   - Already quarantines records whose `sale_id` starts with `offline-draft-`.
   - This path conflicts with the Story 41 first-release boundary unless fully disabled or migrated to a review-only legacy quarantine.

4. `resources/js/POS/offline/offlineSalesQueue.ts`
   - Canonical offline sale envelope store.
   - Owns durable offline provisional sale records, leases, statuses, diagnostics, and tombstones.

5. `resources/js/POS/offline/offlineGuards.ts`
   - Resolves offline capture readiness from cached tenant, branch, terminal, sequence, and tax hash state.
   - Does not yet validate shift authorization freshness, payment matrix, or online-only action restrictions.

6. `resources/js/Pages/POS/Components/SpecialDiscountModal.jsx`
   - Already blocks backend discount calculation and application when offline.
   - Still needs explicit first-release messaging and tests that statutory discount state cannot be restored into offline capture.

7. `resources/js/Pages/POS/Components/Receipt.jsx`
   - Renders official receipt/invoice data.
   - Needs a separate provisional offline acknowledgment rendering mode so offline documents cannot be mistaken for official receipts.

8. `app/Services/POS/OfflineSync/OfflineEnvelopeSynchronizationService.php`
   - Server-side sync authority for offline sale envelopes.
   - Already rejects non-cash tender and statutory discount payloads.
   - Should validate shift/payment/fiscal policy with stable reason codes consistent with Story 41.4.

9. `app/Http/Controllers/POS/PaymentController.php`
   - Records payments for server-created sales.
   - Must remain online-only for first-release offline behavior.

10. Shift/drawer code and tests:
    - `app/Http/Controllers/Shift/ShiftController.php`
    - `app/Http/Controllers/POS/POSDrawerController.php`
    - `tests/Feature/POS/TimecardControllerTest.php`
    - `tests/Feature/Shift/*`

## Policy Matrix

First-release offline payment policy:

| Capability | Offline behavior | Server sync behavior |
| --- | --- | --- |
| Standalone cash sale capture | Allowed only with valid readiness and cached open shift | May post through offline sync if all checks pass |
| Multiple cash rows | Allowed only if collapsed into one cash-settled envelope | Treated as cash payment evidence only |
| Card | Disabled and rejected | Review/reject before sale creation |
| E-wallet | Disabled and rejected | Review/reject before sale creation |
| Bank transfer | Disabled and rejected | Review/reject before sale creation |
| Store credit redemption | Disabled | Reject/review before mutation |
| Loyalty redemption | Disabled | Reject/review before mutation |
| External/on-account tender | Disabled | Reject/review before mutation |
| Mixed tender | Disabled | Reject/review before mutation |
| Existing server-sale payment while offline | Disabled; no new queue records | Existing legacy records quarantine or require online retry |
| Statutory discount | Disabled | Reject/review before sale creation |
| Commercial promotion from cached price snapshot | May be preserved only if already in allowed cached sale snapshot policy | Server remains authority and may classify drift |
| Manager approval issuance | Disabled | No offline approval accepted as new authority |
| Official receipt/invoice | Not available offline | Assigned only after accepted sync |
| Provisional acknowledgment | Allowed with strict wording | No official numbering consumed |

## Required Offline Guard Model

Add or centralize a guard layer that can answer:

```text
Can this action execute while offline?
Can this action execute from restored local state?
Can this action execute with current cached shift authority?
Can this action create or mutate a durable offline envelope?
```

Recommended frontend helper shape:

```ts
type OfflineAction =
  | 'capture_cash_sale'
  | 'record_server_sale_payment'
  | 'apply_statutory_discount'
  | 'issue_manager_approval'
  | 'print_official_receipt'
  | 'close_shift'
  | 'lock_terminal'
  | 'logout_cashier'
  | 'switch_cashier'
  | 'void_sale'
  | 'refund_sale';

type OfflineActionDecision = {
  allowed: boolean;
  reason: string;
  message: string;
  severity: 'info' | 'warning' | 'blocked';
  requiresOnline: boolean;
};
```

This does not need to become a large framework, but the behavior should be deterministic and testable. Avoid duplicating hard-coded strings across components where possible.

Recommended frontend files:

```text
resources/js/POS/offline/offlineActionPolicy.ts
resources/js/POS/offline/offlineShiftAuthority.ts
resources/js/POS/offline/offlinePaymentPolicy.ts
resources/js/POS/offline/offlineDraftSanitizer.ts
resources/js/POS/offline/offlineDocumentPolicy.ts
```

Small pure functions are preferred over a large policy framework.

## Shift Authority Requirements

Offline capture requires cached proof of a previously server-validated open shift.

The cached shift evidence must include or preserve:

1. `shift_id`.
2. `drawer_session_id` where available.
3. `cashier_session_id` or cashier user ID.
4. `business_date`.
5. `opened_at`.
6. cached-at timestamp.
7. branch ID.
8. tenant ID.
9. cashier ID.
10. optional authorization expiry or policy version if already available.

Rules:

1. If no active cached shift exists, offline capture is blocked.
2. If cached shift tenant, branch, or cashier does not match current context, offline capture is blocked.
3. If cached shift is stale beyond policy, offline capture is blocked.
4. If cached shift indicates closed, submitted, approved, or unknown status, offline capture is blocked.
5. Offline capture payload must include shift evidence.
6. The server sync path must verify shift evidence before sale creation.
7. Synced offline sales remain attributed to the original captured shift.
8. Closing a shift with unresolved offline records must be blocked or clearly marked provisional according to the existing shift workflow chosen in implementation.

Locked first-release decision:

```text
A shift cannot enter final closed or reconciled state while any
cash-collected offline envelope attributed to that shift remains
unsynced, retryable, blocked, or review-required.
```

An administrator may mark the shift as operationally suspended if an existing workflow supports that state, but not financially closed.

Do not introduce a provisional close state in Story 41.5 unless the existing shift model already supports a clearly separate non-final state.

Invariant:

```text
Any unsafe envelope with collected or uncertain cash remains
review_required until an authorized resolution records the final outcome.
```

### Shift Freshness Contract

Story 41.5 must introduce or document an explicit cached-shift authority expiry contract:

```text
offline_shift_authority_ttl_minutes
```

Default first-release behavior:

```text
valid until the configured business-day boundary or 12 hours after
server validation, whichever occurs first
```

Rules:

1. Tenants may shorten the period.
2. Tenants may not extend it beyond the platform maximum without an architecture revision.
3. The resolved expiry must be stored in cached shift evidence as `authorized_offline_until`.
4. The policy version used to calculate the expiry must be stored as `shift_authorization_policy_version`.
5. Historical envelopes must use the captured authorization snapshot and must not be reinterpreted under later policy versions.
6. The authorization evidence must include `shift_authorization_id`, `shift_authorization_issued_at`, and `shift_status_snapshot`.
7. Remove or avoid `shift_authorization_version` unless it is introduced with a distinct documented purpose.

## Payment UI Requirements

The split-payment component must behave differently by mode:

### Online Mode

Existing online split-payment behavior remains unchanged.

### Offline Capture Mode

Rules:

1. Only cash method buttons are enabled.
2. Payment heading should read `Cash Payment` or equivalent, not imply full split tender support.
3. Adding multiple rows is disabled unless the implementation collapses them into one cash evidence row before durable capture.
4. Non-cash rows restored from local state are removed or block submission with online-required guidance.
5. `validatePaymentRows(...)` must fail closed if any non-cash method is present.
6. Submission must call `onOfflineCapture(...)` only after cash-only validation succeeds.
7. No call to ordinary `/pos/sales/{sale}/payments/split` may happen offline.

### Offline Existing Server-Sale Payment Mode

First-release rule:

```text
disabled
```

The existing `offlinePaymentQueue` path must be handled safely:

1. New offline payment queue records for existing server-created sales must not be created.
2. Any existing local records must be migrated or projected into read-only legacy status.
3. The `online` event handler must not silently post legacy offline payments if the story disables the queue.
4. The POS UI must explain that existing sale payment completion requires reconnection.

This prevents an older local queue from bypassing the official offline sale sync envelope.

Locked first-release legacy statuses:

```text
legacy_pending
legacy_conflict
```

Deferred future statuses:

```text
resolved_online
abandoned_by_authorized_support
```

Rules:

1. Do not silently delete legacy records.
2. Do not automatically convert legacy payment records into canonical offline sale envelopes.
3. Do not automatically post legacy records on browser `online`.
4. Do not allow cashier reassignment.
5. Legacy records may be displayed for diagnostics and online-resolution guidance only.
6. Legacy migration must be deterministic, idempotent, and non-destructive.

Each migrated legacy payment record must preserve:

```text
legacy_schema_version
quarantined_at
quarantine_reason
source_record_hash
```

The original record remains recoverable for diagnostics until an authorized resolution process exists.

## Discount and Approval Requirements

Statutory discounts remain online-only.

Rules:

1. Opening `SpecialDiscountModal` while offline must show online-required guidance or be disabled from the cart.
2. Discount calculation API calls must not be attempted while offline.
3. Manager authorization API calls must not be attempted while offline.
4. A restored draft containing statutory discount state must not proceed into offline capture.
5. Offline sync payloads containing `statutory_discount` must be rejected or review-required before sale creation.
6. Commercial promotions may only be carried through existing approved cached snapshot behavior; do not introduce new offline discount computation.

Recommended server reason codes:

| Condition | Status | Reason |
| --- | --- | --- |
| statutory discount payload before cash collected | `rejected` | `rejected_statutory_discount_offline` |
| statutory discount payload with collected cash | `review_required` | `review_statutory_discount_offline_cash_collected` |
| offline manager approval submitted | `rejected` or `review_required` | `rejected_offline_manager_approval` |

## Provisional Document Requirements

Offline capture may show or print only a provisional acknowledgment.

Required wording:

```text
OFFLINE TRANSACTION ACKNOWLEDGMENT
Not yet posted as official sale
Not an official invoice
Final invoice pending synchronization
Local reference: {offline_sequence}
```

Must not display:

1. official invoice number,
2. server sale number,
3. BIR final receipt language,
4. e-journal finalization language,
5. wording such as `Transaction recorded` without a provisional qualifier.

The component should use a distinct data flag, for example:

```ts
receipt_mode: 'official' | 'offline_acknowledgment'
```

The official receipt renderer may be reused internally only if the mode visibly changes headings, labels, identity fields, and print/reprint behavior.

Reprint rules:

1. Official receipt reprint authorization remains unchanged online.
2. Offline acknowledgment print/reprint does not authorize an official invoice reprint.
3. Offline acknowledgment printing must be tracked as append-only local events, not merely a mutable print counter.

Required local print event shape:

```text
offline_acknowledgment_printed
offline_transaction_uuid
local_reference
printed_at
cashier_id
terminal_id
document_hash
```

This event is support evidence only. It is not official receipt or e-journal evidence.

The immutable offline sale envelope and document print evidence must remain separate:

```text
immutable offline sale envelope
+
append-only offline document event log
```

Printing can occur after durable capture. Therefore print events must not mutate the original business envelope. Associate print events by `offline_transaction_uuid`. During synchronization, document events may be submitted as a separate evidence collection or included in a sync wrapper without rewriting the canonical envelope payload.

## Cashier Messaging Requirements

Cashier-facing messaging must be short and actionable:

1. Non-cash selected offline:
   `Reconnect to use card, e-wallet, bank, store credit, loyalty, or other payment methods.`
2. No valid open shift:
   `Open shift must be confirmed online before offline cash capture.`
3. Statutory discount offline:
   `Special discounts require online validation. Reconnect before applying.`
4. Existing sale payment offline:
   `Reconnect to finish payment for this sale. Offline mode only supports new cash sale capture.`
5. Provisional receipt:
   `Final invoice will be available after sync.`
6. Pending unsynced records on shift close:
   `Unsynced offline cash exists. Reconnect and sync before closing this shift.`

## Backend Validation Requirements

Backend sync must enforce the same policy even if the browser is bypassed.

Introduce or document an explicit pre-mutation validator:

```text
OfflineEnvelopePolicyValidator
```

Suggested internal validators:

```text
OfflineTenderPolicyValidator
OfflineShiftEvidenceValidator
OfflineDiscountPolicyValidator
OfflineApprovalPolicyValidator
OfflineFiscalIdentityValidator
OfflinePaymentTotalValidator
```

Enhance or confirm backend validation for:

1. payment method is cash,
2. every payment row maps to an active tenant cash method,
3. no mixed tender,
4. payment total equals envelope total,
5. no statutory discount,
6. no manager approval payload,
7. shift evidence exists,
8. shift evidence belongs to the same tenant, branch, cashier, and terminal context,
9. shift evidence was open when captured or within permitted offline policy,
10. no official receipt/invoice identity appears in the raw offline payload.
11. cash status is treated as evidence, not unquestioned client authority.
12. cash payment methods include stable ID, type snapshot, version evidence, and amount in centavos.

Validation must happen before `SaleCreationService` is called.

Recommended evaluation order:

```text
1. Envelope identity and schema trust
2. Terminal and binding epoch
3. Cash-status classification
4. Prohibited fiscal identity
5. Prohibited approval, discount, customer tender, and redemption data
6. Payment method and mixed-tender validation
7. Payment amount validation
8. Cached shift authority validation
9. Policy and version freshness
10. Story 41.4 review/reject decision
11. Sale creation
```

### Cash Status Trust Boundary

Client-provided `cash_status` is evidence only.

Allowed server classification:

```text
not_collected
collected
uncertain
```

Rules:

1. The server may upgrade `not_collected` to `uncertain` based on envelope state, print evidence, capture-completion markers, or inconsistent payload data.
2. The server must not downgrade `collected` to `not_collected`.
3. `collected` and `uncertain` use Story 41.4 review-required behavior when a prohibited payload cannot be safely posted.

### Payment Method Identity

Do not rely only on a client string such as `cash`.

Each offline payment row must include or be mapped to:

```text
payment_method_id
payment_method_type_snapshot = cash
payment_method_name_snapshot
payment_method_version
payment_method_configured_at
payment_method_configuration_hash
amount_centavos
```

The server must confirm that the referenced payment method:

1. belongs to the tenant,
2. has envelope evidence that is structurally a cash method,
3. is of platform type `cash` when current configuration is still available,
4. is valid for the branch where branch-scoped payment method configuration applies.

Historical validation model:

```text
Option A is locked for Story 41.5:
store an immutable payment-method snapshot in the envelope.
```

Required snapshot fields:

```text
payment_method_id
payment_method_type
payment_method_name
payment_method_version
configured_at
configuration_hash
```

The first-release server must not require unavailable historical payment-method records. It verifies tenant ownership and confirms that the captured snapshot is structurally cash. If future server-side versioned payment-method configuration records are introduced, they may strengthen validation without reinterpreting prior accepted envelopes.

### Fiscal Field Prohibition

Prohibited fiscal-field detection must be semantic and payload-version-aware, not merely a hard-coded list of current JSON keys.

Policy:

```text
Reject or review any offline payload field that claims server-issued
fiscal, sale, invoice, receipt, e-journal, GCT, Z-read, or final posting
identity.
```

Maintain a prohibited-field registry per payload version.

Safe schema rule:

1. Known payload versions validate against an allowlisted schema plus prohibited semantic fields.
2. Unknown payload versions fail closed before sale creation.
3. Unknown fields that semantically claim server-issued fiscal, sale, invoice, receipt, e-journal, GCT, Z-read, or final posting identity must reject or enter review before mutation.

Suggested reason codes:

| Condition | Cash not collected | Cash collected or uncertain |
| --- | --- | --- |
| non-cash tender | `rejected_non_cash_tender` | `review_non_cash_tender_cash_collected` |
| mixed tender | `rejected_mixed_tender_offline` | `review_mixed_tender_cash_collected` |
| missing payment evidence | `rejected_missing_payment_evidence` | `review_missing_payment_evidence_cash_collected` |
| missing shift evidence | `rejected_missing_shift_authority` | `review_missing_shift_authority_cash_collected` |
| stale shift evidence | `rejected_stale_shift_authority` | `review_stale_shift_authority_cash_collected` |
| statutory discount | `rejected_statutory_discount_offline` | `review_statutory_discount_offline_cash_collected` |
| offline approval payload | `rejected_offline_manager_approval` | `review_offline_manager_approval_cash_collected` |
| official receipt fields present | `rejected_official_receipt_identity_offline` | `review_official_receipt_identity_cash_collected` |

Review versus reject should reuse Story 41.4 cash-exposure behavior.

## Data and Payload Requirements

Offline envelope payload must include:

1. `cashier_shift_id`.
2. `drawer_session_id` where available.
3. `shift_authorization_id`.
4. `shift_authorization_policy_version`.
5. `shift_authorization_issued_at`.
6. `authorized_offline_until`.
7. `shift_status_snapshot = open`.
8. `shift_opened_at`.
9. `shift_cached_at`.
10. `business_date`.
11. `cash_status`.
12. payment method evidence showing cash-only capture.
13. immutable payment-method snapshot.
14. `payment_method_type_snapshot`.
15. `payment_method_version`.
16. `amount_centavos`.

Document print events must be stored separately from the immutable envelope and linked by `offline_transaction_uuid`.

Offline envelope payload must not include:

1. official invoice number,
2. server sale number,
3. final receipt identity,
4. offline manager approval as authoritative approval,
5. statutory discount authorization payload,
6. store credit redemption,
7. loyalty redemption,
8. external tender references.

If legacy or malicious payloads include prohibited fields, server sync must reject or review before mutation.

## UI Implementation Requirements

Expected frontend changes:

1. Add or extend offline action guard helpers in `resources/js/POS/offline/offlineGuards.ts`.
2. Harden `SplitPayWizard`:
   - cash-only mode,
   - disable row adding where it can create mixed tender,
   - remove the offline existing-sale payment queue branch,
   - clear or reject restored non-cash rows in offline capture mode.
3. Harden `Index.jsx`:
   - block offline checkout without valid cached open shift,
   - block statutory discount entry while offline,
   - block shift close/logout/cashier switch when unresolved offline records exist,
   - build complete shift evidence into offline envelopes,
   - ensure offline capture creates only the canonical offline sale queue envelope.
4. Harden `Receipt.jsx` or add a small wrapper for offline acknowledgment mode.
5. Update queue summary messaging so unresolved cash-collected records surface in the cashier shell.
6. Keep dining, void, refund, inventory, loyalty, and store credit actions online-only.
7. Allow screen locking without ending envelope ownership.
8. Block full cashier switch while unresolved cash records belong to the current cashier unless a future authorized custody-transfer workflow exists.
9. Fail closed rather than locally reassigning unresolved envelopes.
10. Treat terminal lock, cashier logout, and cashier switching as separate offline actions with separate tests.

## Server Implementation Requirements

Expected backend changes:

1. Extend `SyncBatchRequest` if needed to validate shift/document policy fields.
2. Add `OfflineEnvelopePolicyValidator` or equivalent explicit pre-mutation policy validator.
3. Extend `OfflineEnvelopeSynchronizationService` with explicit policy validator orchestration.
4. Preserve `SaleCreationService` as the sale authority.
5. Preserve existing exact replay and drift behavior.
6. Add stable reason codes and consequence status snapshots for new review/reject paths.
7. Do not create a sale, payment, inventory, loyalty, store-credit, accounting, or receipt consequence when policy validation fails.
8. Add tests that malicious payloads cannot bypass UI restrictions.

## Test Plan

Frontend tests:

1. `tests/Frontend/offlineQueueSync.test.js`
   - offline capture envelope includes shift evidence,
   - non-cash restored rows cannot be captured,
   - unresolved records remain visible.

2. New or extended split payment test:
   - offline capture mode enables cash only,
   - card/e-wallet/bank/other buttons are disabled,
   - adding rows cannot create mixed tender,
   - existing server-sale payment offline is blocked and not queued.

3. Special discount test:
   - modal blocks calculation/application while offline,
   - restored statutory discount state cannot proceed to offline capture.

4. Receipt/acknowledgment test:
   - offline acknowledgment contains required wording,
   - official invoice fields are absent,
   - print events are append-only local evidence.

Backend feature tests:

1. Offline sync rejects or reviews non-cash tender before sale creation.
2. Offline sync rejects or reviews mixed tender before sale creation.
3. Offline sync rejects or reviews statutory discount payload before sale creation.
4. Offline sync rejects or reviews missing/stale shift evidence before sale creation.
5. Offline sync rejects official receipt identity in raw payload before sale creation.
6. Offline sync upgrades inconsistent `cash_status` evidence to `uncertain` where required.
7. Offline sync validates cash method ID/type/version evidence.
8. Offline sync accepts immutable payment-method snapshot evidence without requiring unavailable historical payment-method tables.
9. Offline sync fails closed for unknown payload schema versions.
10. Cash-collected or uncertain-cash unsafe records enter `review_required` with support-visible reason codes.
11. Pre-cash unsafe records are rejected and do not create sale/payment records.
12. Exact replay of a review/rejected result remains idempotent.

Regression suites:

```bash
php artisan test tests/Feature/POS/OfflineSyncEpic41ContractTest.php
php artisan test tests/Feature/POS/OfflineSyncStatusWorkflowTest.php tests/Feature/POS/OfflineSyncValidationTest.php
php artisan test tests/Feature/POS/PaymentRecordingTest.php tests/Feature/POS/SplitPaymentRecordingTest.php
php artisan test tests/Feature/POS/StatutoryDiscountComplianceTest.php tests/Feature/POS/ReceiptTest.php
php artisan test tests/Feature/Shift
node tests/Frontend/offlineQueueSync.test.js
node tests/Frontend/offlinePaymentQueue.test.js
```

For final local PR:

```bash
php artisan test tests/Feature/POS
npm run build
git diff --check
```

## Implementation Slices

Recommended PR sequence:

1. Frontend guard foundation
   - action guard helper,
   - shift evidence validation,
   - resolved shift-expiry contract,
   - online-required messaging.

2. Payment UI restrictions
   - cash-only offline capture,
   - disable existing-sale offline payment queue,
   - restored-state sanitation.

3. Provisional acknowledgment
   - receipt mode split,
   - required wording,
   - append-only local print events.

4. Backend policy validation
   - `OfflineEnvelopePolicyValidator`,
   - shift/payment/discount/approval/fiscal validators,
   - reason codes,
   - cash-exposure review behavior.
   - known-schema allowlist and unknown-schema fail-closed behavior.

5. Tests and documentation
   - frontend tests,
   - backend malicious-payload tests,
   - update story status after implementation.

## Acceptance Criteria

Story 41.5 is acceptable when:

1. Offline capture can only produce a standalone cash sale envelope.
2. Existing server-created sale payment recording is online-only.
3. Non-cash, mixed tender, store credit, loyalty, external tender, and on-account attempts are blocked in UI and rejected or reviewed server-side.
4. Offline capture is blocked without valid cached open-shift authority.
5. Shift close, logout, or cashier switch cannot hide unresolved offline cash-collected records.
6. Statutory discounts and manager approvals are online-only.
7. Provisional offline documents cannot be mistaken for official invoices.
8. Server sync rejects or reviews prohibited payloads before sale creation.
9. Cash-collected unsafe records remain visible through Story 41.4 review state.
10. Exact replay of accepted, review-required, or rejected outcomes remains idempotent.
11. Frontend restored local state cannot reintroduce blocked offline payment or discount behavior.
12. Existing online payment, discount, receipt, and shift behavior is not regressed.
13. `offlinePaymentQueue` cannot create new offline server-sale payments and cannot auto-post legacy records.
14. Cached shift evidence has a captured `authorized_offline_until` and policy version.
15. Offline acknowledgment print events are retained as local support evidence.
16. Cash-exposed unsafe records remain `review_required`, never ordinary rejected status.
17. Historical cash payment validation uses immutable payment-method snapshot evidence.
18. Unknown payload schema versions fail closed before sale creation.

## Developer Notes

1. Be especially careful with `offlinePaymentQueue.ts`. It is a legacy convenience queue for server-sale payments and should not remain an active first-release offline posting path unless architecture is revised.
2. Do not rely on `allow_offline` alone for tender policy. First-release architecture says cash-only, even if a method is incorrectly configured as offline-allowed.
3. Do not make the browser authoritative for shift close, drawer settlement, or receipt identity.
4. Avoid deleting unresolved local records. Mark blocked/conflict states and preserve diagnostics.
5. Keep cashier wording simple. Support/audit detail belongs in diagnostics, not cashier checkout copy.
6. Do not introduce offline statutory discount estimation.
7. Do not introduce offline manager PIN/password approval.
8. Do not reinterpret historical offline envelopes when guards change.
9. Keep the immutable sale envelope separate from append-only document events.
10. Do not add historical payment-method validation unless the evidence source exists.

## Open Review Questions

No open architecture questions remain in this draft.

The following decisions are locked for review:

1. Final shift close is blocked while unresolved offline cash records exist.
2. `offlinePaymentQueue` becomes read-only legacy quarantine with no new writes and no automatic posting.
3. Cached shift authority has an explicit resolved expiry and policy version.
4. Cash status is evidence and may be upgraded to `uncertain` by the server.
5. Cash payment methods require immutable snapshot evidence: ID, type, name, version, configured-at, configuration hash, and centavo amount.
6. Offline acknowledgment print events are logged locally as append-only support evidence outside the immutable sale envelope.
7. Logout and cashier switching do not transfer unresolved envelope ownership.
8. Fiscal-field prohibition is semantic and payload-version-aware.
9. Unknown payload schema versions fail closed.
10. Screen lock, logout, and cashier switching are distinct policy actions.
