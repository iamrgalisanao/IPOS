# Story 39.8 Reporting and Reconciliation

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
7. `docs/implementation-plans/epic-39/stories/story-39.5-store-credit-admin-review.md`
8. `docs/implementation-plans/epic-39/stories/story-39.6-loyalty-ledger.md`
9. `docs/implementation-plans/epic-39/stories/story-39.7-loyalty-redemption.md`
10. `app/Models/CustomerFinancialAccount.php`
11. `app/Models/StoreCreditLedgerEntry.php`
12. `app/Models/StoreCreditRefundIssuance.php`
13. `app/Models/StoreCreditRedemption.php`
14. `app/Models/AccountingOutbox.php`
15. `app/Services/StoreCredit/StoreCreditBalanceService.php`
16. `app/Services/StoreCredit/StoreCreditAdminReviewService.php`
17. `app/Services/Accounting/AccountingOutboxQueryService.php`
18. `app/Http/Controllers/Reports/DataExportController.php`
19. `app/Jobs/Reports/ProcessDataExportJob.php`

## 3. Objective

Provide read-only operational, financial, and reconciliation reporting for Epic 39 store credit and loyalty activity.

This story closes Epic 39 by making ledger evidence usable for operations, accounting review, and support diagnostics. It must not introduce new ledger mutation flows, accounting provider transport, customer wallet self-service, admin adjustment execution, loyalty-to-money conversion, or official compliance certification formats.

## 4. User Story

As an owner, manager, or accountant,
I want to review store credit liability, store credit movement, loyalty point movement, and reconciliation exceptions,
so that I can explain customer balances, verify financial liability, and diagnose accounting export issues without changing ledger history.

## 5. Locked Decisions

1. Story 39.8 is read-only.
2. Reports must not mutate store credit ledgers, loyalty ledgers, customer financial accounts, sales, payments, refunds, receipts, accounting outbox rows, or audit records.
3. Ledger rows remain the financial and points source of truth.
4. Reporting services may use deterministic projections, but projections are rebuildable read models only.
5. Customer statements are read models and must not become a separate financial source of truth.
6. Store credit liability is derived from monetary store credit ledger entries.
7. Loyalty point reports are derived from loyalty ledger entries.
8. Store credit and loyalty must never be combined into one money-equivalent balance.
9. Loyalty points must never be reported as accounting liability.
10. Store credit liability must be grouped by currency and must not net different currencies together.
11. Totals from different currencies must never be aggregated into a single grand total.
12. Operational reports and financial reports may share query infrastructure, but their semantics and audiences must stay distinct.
13. Accounting outbox rows remain accounting evidence, not report-owned state.
14. Reconciliation reports may expose pending, failed, exported, or missing accounting evidence, but must not retry or export accounting events.
15. Reconciliation responses must include a health summary derived from warning and critical exception counts.
16. External accounting provider delivery, credentials, chart-of-account mapping, and sync behavior remain out of scope.
17. CSV export, if implemented, must escape injection-prone values and must not loosen report authorization.
18. Report filters must be tenant-scoped and branch-scoped where branch evidence exists.
19. Cross-tenant customer accounts, ledgers, sales, refunds, payments, and accounting records must remain hidden.
20. Reports must expose enough source references to answer the recovery questions in the Architecture Lock:
    - which refund issued this store credit;
    - which payment redeemed this store credit;
    - which sale earned these points;
    - which refund or void reversed these points;
    - which ledger rows are included in current liability;
    - which accounting events remain pending or failed.
21. Story 39.8 must not introduce balance editing, reversal posting, refund issuance, redemption, accrual, or loyalty redemption.
22. Report totals must be computed from committed rows only.
23. Date filters must use a documented basis: business date where available, otherwise immutable creation timestamp.
24. Report responses must identify the basis used for each total so accounting and operations do not compare incompatible windows.
25. Report responses must include a report schema version so payload evolution is explicit.
26. Report responses must include a report instance or correlation identifier so support can reference a generated report.

## 6. Dependencies

1. Story 39.2 Append-Only Store Credit Ledger.
2. Story 39.3 Store Credit Refund Issuance.
3. Story 39.4 Store Credit Redemption.
4. Story 39.6 Loyalty Ledger.
5. Story 39.7 Loyalty Redemption.
6. Existing accounting outbox infrastructure.
7. Existing report authorization and export hardening patterns.
8. Existing tenant, branch, customer, sale, payment, refund, and account models.

## 7. Current Codebase Context

Existing reporting context:

1. Report query services already exist for sales, product mix, sales timing, inventory, tax, shift, and settlement reporting.
2. Report controllers return filtered report payloads and, for some reports, CSV exports.
3. `DataExport` and `ProcessDataExportJob` support asynchronous export workflows.
4. Existing CSV exporters escape unsafe values to reduce CSV injection risk.

Existing accounting context:

1. `AccountingOutbox` is append-only.
2. `AccountingOutboxQueryService` provides accounting outbox visibility.
3. Accounting outbox records track event type, source reference, payload, status, attempts, and sync evidence.
4. Accounting export and sync retry behavior already belongs to accounting services and jobs.

Existing Epic 39 context:

1. `CustomerFinancialAccount` owns tenant-scoped account identity.
2. `StoreCreditLedgerEntry` owns append-only monetary store credit movement.
3. `StoreCreditRefundIssuance` links refund issuance to store credit credit entries and accounting evidence.
4. `StoreCreditRedemption` links payment redemption to store credit debit entries and accounting evidence.
5. Loyalty stories introduce a separate points ledger and redemption evidence.
6. Derived balances already come from replaying ledger entries, not from mutable account balance fields.

Implementation implication:

Story 39.8 should add report query services and report endpoints that read existing evidence. It should not add a new posting service, direct ledger writer, accounting exporter, or reconciliation repair command.

## 8. Reporting Domains

### 8.1 Operational Customer Account Reports

Purpose:

Answer customer service and support questions.

Required views:

1. Customer account statement.
2. Store credit movement history.
3. Loyalty point movement history.
4. Source transaction links.
5. Account status and customer linkage.

Required behavior:

1. Statements must derive rows from ledger entries.
2. Statements must show opening balance, movement rows, and closing balance for the requested window.
3. Opening balance must be derived from ledger history before the window, not stored as a mutable account field.
4. Store credit statement amounts must use integer centavos and currency code.
5. Loyalty statement amounts must use integer points and must not display as money.
6. Source references must identify sale, payment, refund, void, reversal, issuance, redemption, or admin review evidence where applicable.

### 8.2 Store Credit Financial Liability Reports

Purpose:

Answer accounting questions about outstanding monetary liability.

Required views:

1. Liability summary by currency.
2. Liability movement by period.
3. Issued value.
4. Redeemed value.
5. Reversed value.
6. Adjusted value if adjustment entries exist.
7. Expired or forfeited value only if those entry types are implemented.
8. Outstanding liability.

Required calculation:

```text
outstanding_liability
    =
    total_store_credit_credits
    -
    total_store_credit_debits
```

Constraints:

1. Credits and debits must be derived from ledger entry types.
2. Each currency must be calculated separately.
3. Reports must not infer liability from customer account rows alone.
4. Reports must not infer liability from sales, refunds, or accounting outbox rows without ledger support.
5. Accounting outbox rows may be used for reconciliation status, not primary liability calculation.
6. Currency-specific totals must remain separate and must not be summed into a cross-currency grand total.

### 8.3 Store Credit Issuance Reconciliation

Purpose:

Tie refund-created credit to refund evidence, ledger movement, and accounting outbox evidence.

Required reconciliation links:

1. `SaleRefund`
2. `StoreCreditRefundIssuance`
3. Credit `StoreCreditLedgerEntry`
4. `AccountingOutbox` event for issued liability
5. Customer financial account
6. Branch and tenant

Required exception states:

1. Issuance evidence exists without ledger credit.
2. Ledger credit exists without issuance evidence for a refund source.
3. Ledger credit exists without required accounting outbox evidence.
4. Accounting outbox event is pending.
5. Accounting outbox event is failed.
6. Accounting outbox event is exported but payload source reference does not match the ledger source reference.

### 8.4 Store Credit Redemption Reconciliation

Purpose:

Tie redeemed credit to payment evidence, ledger debit, sale evidence, receipt/payment references, and accounting outbox evidence.

Required reconciliation links:

1. `Sale`
2. `SalePayment`
3. `StoreCreditRedemption`
4. Debit `StoreCreditLedgerEntry`
5. `AccountingOutbox` event for redeemed liability
6. Customer financial account
7. Branch and tenant

Required exception states:

1. Redemption evidence exists without ledger debit.
2. Ledger debit exists without redemption evidence for a payment source.
3. Store credit payment exists without redemption evidence.
4. Ledger debit exists without required accounting outbox evidence.
5. Accounting outbox event is pending.
6. Accounting outbox event is failed.
7. Accounting outbox event is exported but payload source reference does not match the ledger source reference.

### 8.5 Loyalty Activity Reports

Purpose:

Answer operational reward questions without creating accounting liability.

Required views:

1. Loyalty point balance by customer financial account.
2. Loyalty accrual movement by sale.
3. Loyalty redemption movement by sale or payment context.
4. Loyalty reversal movement if reversal entries are implemented.
5. Rule version and rule snapshot references used for accrual or redemption.

Constraints:

1. Loyalty reports use points only.
2. Loyalty reports must not display point totals as store credit.
3. Loyalty reports must not create or depend on monetary accounting outbox events.
4. Loyalty reports must show rule version evidence where points were earned or redeemed through a rule.

### 8.6 Reconciliation Exception Reports

Purpose:

Surface evidence gaps without repairing them automatically.

Required exception categories:

1. Missing accounting outbox evidence for monetary store credit ledger postings that require it.
2. Pending accounting outbox events.
3. Failed accounting outbox events.
4. Store credit ledger source references without expected source records.
5. Loyalty ledger source references without expected source records.
6. Duplicate source reference attempts blocked by uniqueness/idempotency.
7. Ledger sequence gaps if the implementation can distinguish committed gaps from rolled-back allocation attempts.
8. Projection freshness warnings if persisted projections are used.

Required behavior:

1. Exception reports must be read-only.
2. Exception reports must include source identifiers for support escalation.
3. Exception reports must not expose cross-tenant identifiers.
4. Exception reports must not include one-click repair or retry controls in this story.
5. Exception reports must include a health summary:
   - `healthy` when no warning or critical exceptions exist;
   - `warnings` when pending, stale, or delayed evidence exists;
   - `critical` when failed, missing, or mismatched evidence exists.

## 9. Proposed Report Services

Implementation may adjust names to match local conventions, but the following ownership boundaries should hold:

1. `CustomerAccountStatementReportService`
   - builds account statements from store credit and loyalty ledger rows;
   - separates monetary and points sections;
   - computes opening and closing derived balances.
2. `StoreCreditLiabilityReportService`
   - computes issued, redeemed, reversed, adjusted, expired, forfeited, and outstanding liability totals by currency;
   - reads store credit ledger rows and optional deterministic projections.
3. `StoreCreditReconciliationReportService`
   - links refund issuance and redemption records to ledger and accounting outbox evidence;
   - surfaces exception rows.
4. `LoyaltyActivityReportService`
   - summarizes accrual, redemption, reversal, and rule-version activity from loyalty ledger rows.
5. `Epic39ReportCsvExportService`
   - optional;
   - escapes CSV values;
   - enforces row limits or delegates large exports to the existing asynchronous export flow.

Controllers should remain thin and should not contain report calculations.

## 10. Suggested Routes and Endpoints

Final route names may follow existing report conventions, but Story 39.8 should expose equivalent capabilities.

```text
GET /reports/customer-accounts/{account}/statement
GET /reports/store-credit/liability
GET /reports/store-credit/movements
GET /reports/store-credit/reconciliation
GET /reports/loyalty/activity
GET /reports/epic-39/reconciliation-exceptions
```

Optional CSV endpoints:

```text
GET /reports/store-credit/liability.csv
GET /reports/store-credit/movements.csv
GET /reports/store-credit/reconciliation.csv
GET /reports/loyalty/activity.csv
GET /reports/epic-39/reconciliation-exceptions.csv
```

If the result set can be large, CSV export should use the existing `DataExport` and queued export pattern instead of synchronous generation.

## 11. Required Filters

Common filters:

1. `business_date_from`
2. `business_date_to`
3. `branch_id`
4. `customer_financial_account_id`
5. `customer_id`
6. `page`
7. `per_page`
8. `sort`

Store credit filters:

1. `currency_code`
2. `movement_type`
3. `source_type`
4. `accounting_status`
5. `include_zero_balance_accounts`

Loyalty filters:

1. `points_movement_type`
2. `rule_id`
3. `rule_version`
4. `source_type`

Validation requirements:

1. Date ranges must be bounded.
2. `per_page` must be capped.
3. Branch filters must be restricted to branches visible to the actor.
4. Account filters must be tenant-scoped.
5. Currency filters must match supported account currencies.
6. Unsupported sort keys must be rejected with `422`.

## 12. Response Shape Requirements

Every report response must include:

1. `report_name`
2. `tenant_id`
3. `branch_scope`
4. `filters`
5. `generated_at`
6. `report_schema_version`
7. `report_instance_id`
8. `basis`
9. `projection_version` if a projection is used
10. `is_projection_stale` if a projection is used
11. `totals`
12. `rows`

Reconciliation report responses must also include:

1. `health`
2. `warning_count`
3. `critical_count`
4. `exception_counts`

The `basis` field must identify whether calculations used:

1. `business_date`
2. `ledger_created_at`
3. `accounting_outbox_created_at`
4. another documented immutable timestamp

Store credit money fields must include:

1. integer centavos;
2. currency code;
3. display formatting only as a presentation concern.

Loyalty point fields must include:

1. integer points;
2. no currency code;
3. no money-equivalent conversion.

Ordering requirements:

1. Ledger-based reports must use deterministic ordering.
2. Store credit and loyalty movement reports should default to `ledger_sequence DESC`.
3. Date-based reports should default to `business_date DESC` with a stable secondary key such as source record id.
4. Pagination must preserve deterministic ordering across pages.

## 13. Authorization

Recommended permissions:

1. `reports.customer_accounts.view`
2. `reports.store_credit.view`
3. `reports.store_credit.financial.view`
4. `reports.store_credit.export`
5. `reports.loyalty.view`
6. `reports.loyalty.export`
7. `reports.epic39_reconciliation.view`

Authorization rules:

1. Store managers may view operational reports for their visible branches.
2. Accountants or owners may view financial liability reports.
3. Cashiers should not receive broad financial liability reports.
4. CSV export requires explicit export permission.
5. Customer PII must be minimized for accounting users when not needed for reconciliation.
6. Cross-tenant hidden resources return `404`.
7. Authorized users without branch access receive `403` or an empty branch-scoped result according to existing report conventions.

## 14. CSV Export Requirements

CSV export is optional in the first implementation, but if included it must satisfy:

1. Escape values beginning with `=`, `+`, `-`, or `@`.
2. Include report name, filters, generation timestamp, and basis metadata.
3. Preserve integer centavos in machine-readable columns.
4. Preserve currency code columns for store credit.
5. Preserve integer point columns for loyalty.
6. Avoid exporting secrets, tokens, or internal payloads that are not needed for reconciliation.
7. Enforce the same authorization as the interactive report.
8. Use queued `DataExport` for large result sets.
9. Expire generated export files according to existing retention policy.

## 15. Projection Rules

Persisted projections are allowed only if they follow these rules:

1. Rebuildable from ledger and accounting outbox rows.
2. Include `projection_version`.
3. Include `generated_at` or equivalent freshness timestamp.
4. Include `is_projection_stale` or equivalent freshness indicator when the projection can lag behind committed ledger rows.
5. Include tenant and branch scope.
6. Never used as posting authority.
7. Never used as the only source for audit or compliance evidence.
8. Rebuild process must not mutate source ledger rows.

First implementation may use direct query services without new projection tables if performance is acceptable.

## 16. Error Handling

Recommended response codes:

| Condition | HTTP status |
| --- | ---: |
| Successful report query | `200` |
| Successful CSV export request | `200` or `202` |
| Validation failure | `422` |
| Unauthorized report access | `403` |
| Cross-tenant hidden account/report source | `404` |
| Export too large for synchronous generation | `422` or `202` queued export |
| Unsupported export format | `422` |

Report query failures must not partially mutate state because reporting is read-only.

## 17. Test Plan

Backend feature tests:

1. Store credit liability derives from ledger credits and debits.
2. Store credit liability is grouped by currency.
3. Store credit liability does not include loyalty points.
4. Loyalty reports display points without currency or money conversion.
5. Customer statement derives opening and closing balances from ledger history.
6. Refund issuance reconciliation links refund, issuance, ledger credit, and accounting outbox evidence.
7. Redemption reconciliation links sale, payment, redemption, ledger debit, and accounting outbox evidence.
8. Pending accounting outbox rows appear in reconciliation results.
9. Failed accounting outbox rows appear in reconciliation results.
10. Missing accounting evidence appears as a reconciliation exception.
11. Reports are tenant scoped.
12. Reports are branch scoped where branch evidence exists.
13. Unauthorized users cannot access financial liability reports.
14. CSV export escapes injection-prone values if export is implemented.
15. Report queries do not create, update, or delete ledger rows.
16. Report queries do not create, update, or delete accounting outbox rows.
17. Report queries do not alter customer account state.
18. Bounded date range validation rejects excessive ranges.
19. Pagination caps are enforced.
20. Projection metadata is present if projections are used.
21. Report schema version is present.
22. Report instance id is present.
23. Reconciliation health summary reflects warning and critical exception counts.
24. Ledger report pagination is deterministic.

Frontend or Inertia tests, if UI is implemented:

1. Store credit liability page shows money totals by currency.
2. Loyalty activity page shows points only.
3. Operational account statement separates store credit and loyalty sections.
4. Reconciliation exceptions are visible to authorized users.
5. Financial reports are hidden from users without permission.
6. Export controls are hidden or disabled without export permission.

## 18. Acceptance Criteria

1. Store credit liability is derived from store credit ledger entries.
2. Store credit liability totals reconcile to included ledger rows.
3. Loyalty activity is derived from loyalty ledger entries.
4. Loyalty points are never reported as currency, store credit, or accounting liability.
5. Operational customer account reports and financial liability reports are separated by purpose and permission.
6. Refund issuance reports link refund, issuance, ledger credit, and accounting outbox evidence.
7. Redemption reports link sale/payment, redemption, ledger debit, and accounting outbox evidence.
8. Pending or failed accounting outbox evidence is visible in reconciliation reports.
9. Reports are tenant scoped.
10. Reports are branch scoped where branch evidence exists.
11. Reports do not mutate ledger, customer account, sale, refund, payment, or accounting state.
12. CSV exports, if implemented, escape injection-prone values.
13. Report filters are validated and bounded.
14. Cross-tenant hidden resources are not exposed.
15. Projection metadata is included when projections are used.
16. Report schema version and report instance id are included.
17. Reconciliation health summary is included for exception and reconciliation reports.
18. Different currencies are never aggregated into one grand total.
19. Ledger-based pagination uses deterministic ordering.

## 19. Implementation Checklist

1. Add report request validators.
2. Add store credit liability query service.
3. Add store credit movement query service.
4. Add store credit reconciliation query service.
5. Add loyalty activity query service.
6. Add customer account statement query service.
7. Add report controllers and routes.
8. Add permissions and policy checks.
9. Add optional CSV export service or queued export integration if approved.
10. Add backend feature tests for report calculations, reconciliation, isolation, authorization, and read-only behavior.
11. Add frontend pages/tests if UI is included.
12. Update user/admin documentation for available reports.

## 20. Definition of Done

Story 39.8 is done when:

1. Acceptance criteria pass.
2. Backend feature tests pass.
3. Frontend tests pass where UI is touched.
4. Store credit liability reports reconcile to ledger entries.
5. Loyalty reports remain points-only.
6. Accounting reconciliation exposes pending and failed outbox evidence.
7. Report queries are proven read-only.
8. Tenant and branch isolation are verified.
9. CSV injection protection is verified if export is implemented.
10. Report schema version, report instance id, and reconciliation health summary are verified.
11. Documentation is updated.
12. Code review confirms no Epic 39 architectural constraints are violated.
