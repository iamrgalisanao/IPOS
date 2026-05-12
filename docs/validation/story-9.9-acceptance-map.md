# Story 9.9 Acceptance Coverage Map

| # | Requirement | Proof / Test Method | Status |
|---|---|---|---|
| 1 | Authorized user sees reopen action for locked period | `SettlementReopenUiTest::test_authorized_user_sees_reopen_action_for_locked_period` | Covered |
| 2 | Unauthorized user does not see reopen action | `SettlementReopenUiTest::test_unauthorized_user_does_not_see_reopen_action` | Covered |
| 3 | Reopen action hidden for non-locked periods | `SettlementReopenUiTest::test_reopen_action_hidden_for_non_locked_periods` | Covered |
| 4 | Reopen requires reason | `SettlementReopenUiTest::test_reopen_requires_reason` | Covered |
| 5 | Authorized user can reopen locked period from UI route/action | `SettlementReopenUiTest::test_authorized_user_can_reopen_locked_period_from_ui_route` | Covered |
| 6 | Reopened status is reflected after reopen | `SettlementReopenUiTest::test_authorized_user_can_reopen_locked_period_from_ui_route` | Covered |
| 7 | Reopen records reopened_by | `SettlementReopenUiTest::test_reopen_records_actor_timestamp_reason_and_audit` | Covered |
| 8 | Reopen records reopened_at | `SettlementReopenUiTest::test_reopen_records_actor_timestamp_reason_and_audit` | Covered |
| 9 | Reopen records reopen_reason | `SettlementReopenUiTest::test_reopen_records_actor_timestamp_reason_and_audit` | Covered |
| 10 | Reopen logs `settlement_period_reopened` | `SettlementReopenUiTest::test_reopen_records_actor_timestamp_reason_and_audit` | Covered |
| 11 | Tenant A cannot reopen Tenant B period | `SettlementReopenUiTest::test_tenant_a_cannot_reopen_tenant_b_period` | Covered |
| 12 | Branch A cannot reopen Branch B period unless permission allows | `SettlementReopenUiTest::test_branch_a_cannot_reopen_branch_b_period_unless_permission_allows` | Covered |
| 13 | Reopen does not create snapshots automatically | `SettlementReopenUiTest::test_reopen_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 14 | Reopen does not mutate sales/payments/inventory/refunds/voids | `SettlementReopenUiTest::test_reopen_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 15 | Reopen does not create accounting outbox records | `SettlementReopenUiTest::test_reopen_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 16 | Reopen does not call QuickBooks/provider APIs | `SettlementReopenUiTest::test_reopen_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 17 | Story 9.2–9.8 tests remain green | `php artisan test tests/Feature/Settlement` | Covered |
| 18 | Previous Epic 1–8 tests remain green | `php artisan test` | Covered |

## Boundary Confirmation
Confirmed no:
- export/report generation
- journal/posting behavior
- QuickBooks/provider calls
- source financial mutation
- snapshot creation

