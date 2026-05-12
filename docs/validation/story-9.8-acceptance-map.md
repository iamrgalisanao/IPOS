# Story 9.8 Acceptance Coverage Map

| # | Requirement | Proof / Test Method | Status |
|---|---|---|---|
| 1 | Authorized user sees approve action for `in_review` period | `SettlementApprovalLockUiTest::test_authorized_user_sees_approve_action_for_in_review_period` | Covered |
| 2 | Unauthorized user does not see approve action | `SettlementApprovalLockUiTest::test_unauthorized_user_does_not_see_approve_action` | Covered |
| 3 | Authorized user can approve period from UI route/action | `SettlementApprovalLockUiTest::test_authorized_user_can_approve_period_from_ui_route` | Covered |
| 4 | Approved status is reflected after approval | `SettlementApprovalLockUiTest::test_authorized_user_can_approve_period_from_ui_route` | Covered |
| 5 | Authorized user sees lock action for approved period with snapshot | `SettlementApprovalLockUiTest::test_authorized_user_sees_lock_action_for_approved_period_with_snapshot` | Covered |
| 6 | Lock action hidden or disabled when no snapshot exists | `SettlementApprovalLockUiTest::test_lock_action_hidden_or_disabled_when_no_snapshot_exists` | Covered |
| 7 | Lock action returns snapshot-required validation when attempted without snapshot | `SettlementApprovalLockUiTest::test_lock_action_returns_snapshot_required_validation_when_attempted_without_snapshot` | Covered |
| 8 | Authorized user can lock approved period with snapshot | `SettlementApprovalLockUiTest::test_authorized_user_can_lock_approved_period_with_snapshot` | Covered |
| 9 | Locked status is reflected after lock | `SettlementApprovalLockUiTest::test_authorized_user_can_lock_approved_period_with_snapshot` | Covered |
| 10 | Tenant A cannot approve Tenant B period | `SettlementApprovalLockUiTest::test_tenant_a_cannot_approve_or_lock_tenant_b_period` | Covered |
| 11 | Tenant A cannot lock Tenant B period | `SettlementApprovalLockUiTest::test_tenant_a_cannot_approve_or_lock_tenant_b_period` | Covered |
| 12 | Branch A cannot approve Branch B period unless permission allows | `SettlementApprovalLockUiTest::test_branch_a_cannot_approve_or_lock_branch_b_period_unless_permission_allows` | Covered |
| 13 | Branch A cannot lock Branch B period unless permission allows | `SettlementApprovalLockUiTest::test_branch_a_cannot_approve_or_lock_branch_b_period_unless_permission_allows` | Covered |
| 14 | UI action does not create snapshots automatically | `SettlementApprovalLockUiTest::test_ui_action_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 15 | UI action does not mutate sales/payments/inventory/refunds/voids | `SettlementApprovalLockUiTest::test_ui_action_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 16 | UI action does not create accounting outbox records | `SettlementApprovalLockUiTest::test_ui_action_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 17 | UI action does not call QuickBooks/provider APIs | `SettlementApprovalLockUiTest::test_ui_action_does_not_create_snapshots_or_mutate_source_records_or_call_providers` | Covered |
| 18 | Story 9.2–9.7 tests remain green | `php artisan test tests/Feature/Settlement` | Covered |
| 19 | Previous Epic 1–8 tests remain green | `php artisan test` | Covered |

## Boundary Confirmation
Confirmed no:
- reopen UI
- export/report generation
- journal/posting behavior
- QuickBooks/provider calls
- source financial mutation

