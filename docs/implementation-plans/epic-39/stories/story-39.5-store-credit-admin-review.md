# Story 39.5 Store Credit Admin Review

## 1. Status

Done

Date: 2026-07-15

## 2. References

1. `docs/implementation-plans/epic-39/epic-39-architecture-lock.md`
2. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
3. `docs/implementation-plans/epic-39/stories/story-39.1-customer-account-foundation.md`
4. `docs/implementation-plans/epic-39/stories/story-39.2-store-credit-ledger.md`
5. `docs/implementation-plans/epic-39/stories/story-39.3-store-credit-refund.md`
6. `docs/implementation-plans/epic-39/stories/story-39.4-store-credit-redemption.md`
7. `app/Http/Controllers/Admin/CustomerFinancialAccountController.php`
8. `app/Models/CustomerFinancialAccount.php`
9. `app/Models/StoreCreditLedgerEntry.php`
10. `app/Models/StoreCreditRefundIssuance.php`
11. `app/Models/StoreCreditRedemption.php`
12. `app/Services/Customers/CustomerFinancialAccountService.php`
13. `app/Services/StoreCredit/StoreCreditBalanceService.php`
14. `app/Services/StoreCredit/StoreCreditLedgerService.php`
15. `routes/web.php`

## 3. Objective

Provide authorized admin review surfaces for customer financial accounts, derived store credit balances, ledger history, and source transaction evidence.

This story is an operational review story. It must not introduce manual credit, manual debit, balance editing, ledger mutation, accounting export, loyalty points, or customer self-service wallet functionality.

## 4. User Story

As an authorized admin or support operator,
I want to review a customer's store credit account, balance, ledger history, and source evidence,
so that I can answer customer service questions and investigate disputes without changing financial records.

## 5. Locked Decisions

1. Store credit admin review is read-only for monetary value.
2. `StoreCreditLedgerService` remains the only store credit ledger posting authority.
3. Admin controllers and UI components must not write `store_credit_ledger_entries` directly.
4. Admin review may display derived balance, but the ledger remains the source of truth.
5. Admin review must not introduce a mutable balance column.
6. Admin review must not create `admin_credit_adjustment` or `admin_debit_adjustment` ledger entries.
7. Adjustment request and review policy may be documented or displayed as guidance only.
8. Adjustment execution requires a separately approved story with authorization, reason, approval, idempotency, accounting, and audit contracts.
9. Ledger history must be ordered by account-scoped `ledger_sequence`.
10. Source links must point to immutable or authoritative evidence records where available.
11. Operational account history must be clearly separated from financial liability reporting.
12. Story 39.5 must not claim complete outstanding liability reporting; that belongs to Story 39.8.
13. Cross-tenant account and ledger access must return hidden-resource behavior.
14. Balance visibility requires an admin permission appropriate to store credit review.
15. Customer anonymization must not remove ledger evidence from the review surface, but PII must stay redacted.
16. Offline admin review may not mutate cached balances or ledger state.
17. Store credit and loyalty remain separate; this story exposes store credit only.
18. Exact ledger evidence must be shown as posted; admin review must not recalculate historical snapshots from mutable sale, refund, payment, or customer data.

## 6. Dependencies

1. Story 39.1 Customer Account Foundation.
2. Story 39.2 Append-Only Store Credit Ledger.
3. Story 39.3 Store Credit Refund Issuance.
4. Story 39.4 Store Credit Redemption.
5. Existing `CustomerFinancialAccountController` admin routes.
6. Existing `customer-accounts.view` permission.
7. Existing `customer-accounts.manage` permission for account lifecycle actions.
8. Existing `StoreCreditBalanceService` derived balance behavior.
9. Existing immutable refund issuance and redemption evidence models.

## 7. Current Codebase Context

Existing customer account admin context:

1. `routes/web.php` exposes `/admin/customer-accounts`.
2. `CustomerFinancialAccountController::index()` returns up to 100 customer financial accounts.
3. `CustomerFinancialAccountController::show()` returns one customer financial account with its customer.
4. `CustomerFinancialAccountController::store()` creates accounts through `CustomerFinancialAccountService`.
5. `CustomerFinancialAccountController::status()` updates account lifecycle status through `CustomerFinancialAccountService`.
6. `CustomerFinancialAccountController::anonymize()` anonymizes customer identity while preserving account linkage.
7. `customer-accounts.view` protects read access.
8. `customer-accounts.manage` protects creation, lifecycle updates, and anonymization.

Existing store credit ledger context:

1. `StoreCreditLedgerEntry` is immutable on update and delete.
2. Ledger entries include `tenant_id`, `branch_id`, `customer_financial_account_id`, `ledger_sequence`, `ledger_schema_version`, `ledger_category`, `entry_type`, `direction`, `amount_centavos`, `currency_code`, `source_type`, `source_id`, `source_reference`, `source_snapshot`, `idempotency_key`, `request_fingerprint`, `business_date`, `posted_by`, and `posted_at`.
3. Refund issuance posts `refund_credit` entries.
4. Redemption posts `redemption_debit` entries.
5. Future adjustment entry types exist in the model as reserved ledger concepts, but no admin adjustment execution is approved by this story.
6. `StoreCreditBalanceService::availableBalanceCentavos()` derives balance by replaying ledger entries.
7. `StoreCreditLedgerService` owns posting, idempotency, source uniqueness, currency checks, non-negative balance checks, sequence allocation, and audit logging.

Implementation implication:

Story 39.5 should extend the existing admin customer account review surface with ledger and balance visibility. It should not create a second ledger service, direct ledger writer, accounting report, or adjustment workflow.

## 8. Domain Scope

In scope:

1. Admin account list enhancements for derived store credit balance visibility.
2. Admin account detail view for account metadata, lifecycle state, derived balance, and recent ledger activity.
3. Store credit ledger history endpoint or response section for a single customer financial account.
4. Ledger filters for date range, branch, entry type, direction, category, source type, and source reference.
5. Source evidence links for refund issuance and redemption records where available.
6. Balance derivation using `StoreCreditBalanceService`.
7. Tenant-scoped and permission-protected review access.
8. Admin UI or JSON response contracts for support review.
9. Empty-state, no-ledger, and anonymized-customer behavior.
10. Audit evidence for sensitive account review if the existing audit policy requires it.
11. Backend feature tests for visibility, isolation, filtering, balance derivation, and no mutation.
12. Frontend tests if an Inertia admin page is added or materially changed.

Out of scope:

1. Manual store credit issuance.
2. Manual store credit debit.
3. Bulk balance edits.
4. Direct ledger row editing.
5. Adjustment execution workflow.
6. Approval workflow for adjustments.
7. Customer self-service wallet.
8. Loyalty points.
9. Financial liability report completion.
10. Accounting provider export or provider-specific mappings.
11. Store credit expiration or forfeiture execution.
12. Refund handling for prior store-credit redemptions.
13. Customer merge.
14. Offline store credit mutation.
15. Negative store credit balances.

## 9. Review Invariants

1. Review does not mutate financial value.
2. Review does not update ledger rows.
3. Review does not create ledger rows.
4. Review does not delete ledger rows.
5. Review does not persist cached balance as authoritative state.
6. Review does not hide immutable ledger evidence when customer PII is anonymized.
7. Review derives balances from ledger history or deterministic projections.
8. Review displays monetary amounts using integer centavos converted only for presentation.
9. Review orders ledger history by `ledger_sequence`, with `posted_at` as display metadata.
10. Review treats ledger `source_snapshot` as historical evidence and does not recompute it.
11. Review distinguishes support investigation from accounting reconciliation.
12. Review must not expose cross-tenant customers, accounts, or ledger rows.

## 10. Route and Response Contract

The implementation may either extend the existing customer account endpoints or add read-only nested endpoints under the same route group.

Recommended endpoints:

```text
GET /admin/customer-accounts
GET /admin/customer-accounts/{customerFinancialAccount}
GET /admin/customer-accounts/{customerFinancialAccount}/ledger
GET /admin/customer-accounts/{customerFinancialAccount}/ledger/{storeCreditLedgerEntry}
```

All endpoints require:

```text
permission:customer-accounts.view
```

If a separate permission is introduced, use:

```text
store-credit.review
```

and keep `customer-accounts.view` as the minimum account identity permission.

Recommended account detail response:

```json
{
  "customer_financial_account": {
    "id": "account-uuid",
    "customer_id": "customer-uuid",
    "customer_display_name": "Maria Santos",
    "customer_status": "active",
    "account_status": "active",
    "currency_code": "PHP",
    "opened_at": "2026-07-15T09:00:00Z",
    "suspended_at": null,
    "closed_at": null
  },
  "store_credit": {
    "available_balance_centavos": 125000,
    "currency_code": "PHP",
    "balance_source": "ledger",
    "ledger_entry_count": 4,
    "last_ledger_sequence": 4,
    "last_posted_at": "2026-07-15T10:30:00Z"
  },
  "recent_ledger_entries": []
}
```

Recommended ledger row response:

```json
{
  "id": "ledger-entry-uuid",
  "ledger_sequence": 4,
  "ledger_schema_version": 1,
  "ledger_category": "debit",
  "entry_type": "redemption_debit",
  "direction": "debit",
  "amount_centavos": 50000,
  "currency_code": "PHP",
  "business_date": "2026-07-15",
  "posted_at": "2026-07-15T10:30:00Z",
  "branch": {
    "id": "branch-uuid",
    "name": "Main Branch"
  },
  "source": {
    "type": "sale_payment",
    "id": "sale-payment-uuid",
    "reference": "SALE-000123",
    "link_type": "store_credit_redemption"
  },
  "source_snapshot": {
    "ledger_schema_version": 1
  },
  "posted_by": {
    "id": "user-uuid",
    "name": "Cashier"
  }
}
```

## 11. Query and Filter Contract

Account list filters:

1. `q`: customer name, email, phone, or external reference search.
2. `status`: account status.
3. `customer_status`: customer status, including anonymized where supported.
4. `has_store_credit_activity`: boolean.
5. `min_balance_centavos`: optional support filter if implemented efficiently.
6. `max_balance_centavos`: optional support filter if implemented efficiently.

Ledger filters:

1. `date_from`.
2. `date_to`.
3. `branch_id`.
4. `entry_type`.
5. `ledger_category`.
6. `direction`.
7. `source_type`.
8. `source_reference`.
9. `posted_by`.

Sorting:

1. Default ledger sorting is `ledger_sequence` descending for admin review.
2. Exports or reconciliation previews must preserve a deterministic sequence order.
3. The backend must not sort by formatted money values.

Pagination:

1. Ledger history must be paginated.
2. Default page size should be small enough for admin UI performance.
3. Maximum page size should be bounded.

## 12. Source Evidence Contract

Review should expose source evidence without creating a new source of truth.

Refund credit source:

1. Ledger entry type is `refund_credit`.
2. Link to `store_credit_refund_issuances` when available.
3. Show source refund reference from `source_reference` and `source_snapshot`.
4. Do not recompute refund totals from current sale state.

Redemption debit source:

1. Ledger entry type is `redemption_debit`.
2. Link to `store_credit_redemptions` when available.
3. Show sale payment, sale, receipt, and authorized balance snapshot references where available.
4. Do not recompute redemption totals from current payment state.

Reserved future source types:

1. `admin_credit_adjustment`.
2. `admin_debit_adjustment`.
3. `reversal_credit`.
4. `reversal_debit`.
5. `expiration_debit`.
6. `forfeiture_debit`.

These may be displayed if rows exist from future stories, but Story 39.5 must not create them.

## 13. Data Model

No new monetary mutation table is required for Story 39.5.

Allowed schema changes:

1. Add indexes that improve account/ledger review queries.
2. Add non-authoritative read-model/projection support only if the implementation documents rebuild behavior.
3. Add permission seed data for review access if missing.

Optional review projection:

```text
StoreCreditAccountSummaryProjection
```

Purpose:

Provide a rebuildable performance optimization for account lists and high-volume ledger histories.

Allowed fields:

```text
account_id
last_ledger_sequence
derived_balance_centavos
ledger_entry_count
last_posted_at
rebuilt_at
```

Rules:

1. The projection is not authoritative.
2. The projection may be deleted and rebuilt from `store_credit_ledger_entries`.
3. The projection must not allow manual balance overrides.
4. The projection must not replace ledger-derived balance verification in tests.
5. If the projection is stale, the UI must either refresh it or clearly fall back to ledger-derived values.
6. Story 39.5 implementation may defer the projection if ledger volumes do not require it yet.

Recommended indexes to evaluate:

```text
store_credit_ledger_entries(customer_financial_account_id, ledger_sequence)
store_credit_ledger_entries(customer_financial_account_id, entry_type, ledger_sequence)
store_credit_ledger_entries(customer_financial_account_id, direction, ledger_sequence)
store_credit_ledger_entries(customer_financial_account_id, business_date, ledger_sequence)
store_credit_ledger_entries(tenant_id, branch_id, business_date)
store_credit_ledger_entries(source_type, source_id)
```

Not allowed:

1. `customer_financial_accounts.balance_centavos`.
2. Mutable balance override columns.
3. Editable ledger notes on immutable ledger rows.
4. Admin adjustment tables that imply approved execution.
5. Accounting export state owned by admin review.

## 14. Service Design

Recommended service:

```text
StoreCreditAdminReviewService
```

Responsibilities:

1. Resolve tenant-scoped customer financial accounts.
2. Build account summary DTOs.
3. Derive available balance via `StoreCreditBalanceService`.
4. Query ledger history with bounded filters and pagination.
5. Attach source evidence references.
6. Normalize anonymized customer display.
7. Return operational review DTOs for controllers or Inertia props.

Forbidden service behavior:

1. Calling `StoreCreditLedgerEntry::create()`.
2. Calling `StoreCreditLedgerService::post()` to create adjustments.
3. Updating or deleting ledger entries.
4. Updating derived balances.
5. Creating accounting outbox rows.
6. Executing approval or adjustment workflow.

Controller responsibilities:

1. Enforce permissions.
2. Resolve route model binding inside tenant scope.
3. Validate filters.
4. Call the review service.
5. Return JSON or Inertia props.

Controllers must stay thin and must not build financial calculations inline.

## 15. Authorization and Isolation

Required access:

```text
store-credit.review
```

Minimum related account identity access:

```text
customer-accounts.view
```

Rules:

1. Users without review permission receive `403`.
2. Cross-tenant accounts return `404` or existing hidden-resource behavior.
3. Cross-tenant ledger entries return `404` or existing hidden-resource behavior.
4. Branch filtering must not leak branch names or ledger counts from inaccessible tenants.
5. Account lifecycle mutation remains protected by `customer-accounts.manage`.
6. Store credit review does not grant account creation, status change, anonymization, refund, redemption, or adjustment permissions.
7. `store-credit.review` may initially be assigned to the same admin roles as `customer-accounts.view`, but it should remain a separate permission for future support-role separation.

## 16. UI Requirements

If a UI is implemented, it should provide:

1. Account search and filtered list.
2. Account detail header with customer identity, account status, currency, and lifecycle timestamps.
3. Derived balance summary.
4. Ledger history table.
5. Entry type, direction, amount, branch, business date, posted date, source reference, and posted-by columns.
6. Source evidence drawer or detail panel with no edit affordances.
7. Anonymized customer state.
8. Empty state for accounts without ledger entries.
9. Clear copy that adjustment execution is not available in this story.
10. No balance edit controls.
11. No manual credit or debit buttons.
12. No adjustment submission form.

Admin UI copy should describe financial data as ledger-derived store credit history, not as an accounting liability report.

## 17. Error and Response Codes

| Condition | HTTP status |
| --- | ---: |
| Successful account list/detail | `200` |
| Successful ledger list/detail | `200` |
| Validation failure for filters | `422` |
| Unauthorized | `403` |
| Cross-tenant or hidden account | `404` |
| Cross-tenant or hidden ledger entry | `404` |
| Unsupported source evidence link | `200` with unavailable source metadata |

Recommended unsupported source metadata:

```json
{
  "source": {
    "type": "unknown_or_future_source",
    "id": "source-id",
    "reference": "reference",
    "link_available": false
  }
}
```

## 18. Audit and Privacy

Audit requirements:

1. Account lifecycle mutations remain audited through Story 39.1.
2. Ledger postings remain audited through Stories 39.2, 39.3, and 39.4.
3. Story 39.5 should not create mutation audit events for read-only browsing.
4. If local policy requires audit for sensitive financial viewing, log compact events such as `STORE_CREDIT_ACCOUNT_REVIEWED` without copying full ledger snapshots into audit metadata.

Privacy requirements:

1. Do not expose removed customer PII for anonymized customers.
2. Display stable non-personal account and ledger identifiers.
3. Preserve ledger evidence and source references after anonymization.
4. Do not include raw request fingerprints unless the viewer has a system diagnostics or developer support permission.
5. Mask customer contact fields consistently with existing admin customer patterns.

## 19. Test Plan

Backend feature tests:

1. Authorized admin can list customer financial accounts with derived store credit summary.
2. Unauthorized user cannot view account review endpoints.
3. Cross-tenant user cannot view another tenant's account.
4. Cross-tenant user cannot view another tenant's ledger entries.
5. Account detail derives balance from credit and debit ledger rows.
6. Ledger history is ordered by `ledger_sequence`, never by `posted_at` as the financial ordering source.
7. Ledger filters return only matching rows.
8. Ledger pagination is bounded.
9. Refund credit rows expose refund issuance source evidence.
10. Redemption debit rows expose redemption source evidence.
11. Anonymized customers remain reviewable without exposing removed PII.
12. Review endpoints do not create ledger rows.
13. Review endpoints do not update ledger rows.
14. Review endpoints do not create accounting outbox rows.
15. Review endpoints do not allow adjustment execution.

Service/unit tests:

1. Review service returns zero balance, never null, for accounts without ledger entries.
2. Review service calculates mixed credit/debit balance correctly.
3. Review service handles missing optional source evidence without failing the ledger list.
4. Review service keeps source snapshots unchanged.

Frontend tests where UI is changed:

1. Admin account detail renders balance, status, and ledger rows.
2. Empty ledger state renders without fake movement.
3. Filter changes reload ledger history.
4. No manual credit, manual debit, or balance edit action is visible.
5. Source evidence panel shows refund and redemption references.

Regression tests:

1. Existing account creation still works.
2. Existing account status update still works.
3. Existing refund-to-store-credit issuance still works.
4. Existing store credit redemption still works.
5. Existing append-only ledger immutability tests still pass.

## 20. Acceptance Criteria

1. Authorized admins can view customer financial accounts with store credit review data.
2. Unauthorized users cannot view store credit review data.
3. Cross-tenant accounts and ledger rows are hidden.
4. Derived balance is calculated from ledger entries.
5. Accounts without ledger entries show zero derived balance.
6. Ledger history is paginated and ordered deterministically.
7. Ledger filters are validated and tenant-scoped.
8. Refund credit entries show refund issuance source evidence where available.
9. Redemption debit entries show redemption source evidence where available.
10. Anonymized customers remain reviewable without exposing removed PII.
11. Review surfaces distinguish operational account history from financial liability reporting.
12. No admin balance edit capability is introduced.
13. No manual credit/debit adjustment execution is introduced.
14. Review endpoints do not create, update, or delete ledger rows.
15. Review endpoints do not create accounting outbox rows.
16. Tests prove the review surface is read-only.

## 21. Implementation Checklist

1. Review existing admin account routes and controller response shapes.
2. Add or extend request validation for account and ledger filters.
3. Add `StoreCreditAdminReviewService` or equivalent review query service.
4. Use `StoreCreditBalanceService` for derived balance.
5. Add ledger query support scoped by customer financial account and tenant.
6. Add source evidence DTO mapping for refund issuance.
7. Add source evidence DTO mapping for redemption.
8. Add bounded pagination.
9. Prefer cursor pagination for large ledger histories where practical.
10. Add `StoreCreditAccountSummaryProjection` only if needed for performance, and keep it rebuildable.
11. Add missing indexes only if query plans require them.
12. Add `store-credit.review` permission seed updates.
13. Add admin UI or JSON response updates according to existing project conventions.
14. Add backend feature tests.
15. Add frontend tests if UI is changed.
16. Run focused tests.
17. Run full relevant store credit regression tests.
18. Update this story status after implementation and PR review.

## 22. Definition of Done

Story 39.5 is done when:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Frontend tests pass where UI is touched.
4. Tenant isolation is verified.
5. Permission checks are verified.
6. Derived balance behavior is verified.
7. Ledger immutability is preserved.
8. No admin adjustment execution path exists.
9. No mutable balance field is introduced.
10. Source evidence links are verified.
11. Anonymized customer behavior is verified.
12. Operational review copy does not claim financial liability-report completeness.
13. Code review is approved.
14. Documentation is updated.

## 23. Implementation Record

Implemented: 2026-07-15

Status: Done

Summary:

1. Added a read-only store credit admin review service.
2. Added dedicated ledger review request validation.
3. Added permission-gated account list summaries plus store credit account review, ledger history, and ledger detail endpoints under admin customer accounts.
4. Added `store-credit.review` RBAC permission and assigned it to the default admin, branch manager, and accountant role templates.
5. Added backend feature coverage for authorization, tenant isolation, derived balance, sequence ordering, source evidence, anonymized customer privacy, filters, pagination metadata, and no mutation side effects.

Validation:

```text
php artisan test tests/Feature/StoreCredit/StoreCreditAdminReviewTest.php
php artisan test tests/Feature/Customers/CustomerFinancialAccountFoundationTest.php tests/Feature/StoreCredit/StoreCreditLedgerFoundationTest.php tests/Feature/StoreCredit/StoreCreditRefundIssuanceTest.php tests/Feature/StoreCredit/StoreCreditRedemptionTest.php tests/Feature/StoreCredit/StoreCreditAdminReviewTest.php
```

Changed files:

1. `app/Http/Controllers/Admin/CustomerFinancialAccountController.php`
2. `app/Http/Requests/StoreCredit/StoreCreditLedgerReviewRequest.php`
3. `app/Services/RbacSeeder.php`
4. `app/Services/StoreCredit/StoreCreditAdminReviewService.php`
5. `routes/web.php`
6. `tests/Feature/StoreCredit/StoreCreditAdminReviewTest.php`
7. `docs/implementation-plans/epic-39/epic-39-implementation-guide.md`
8. `docs/implementation-plans/epic-39/stories/story-39.5-store-credit-admin-review.md`
