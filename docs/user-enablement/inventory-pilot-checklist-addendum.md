# Inventory Pilot Checklist Addendum

Date: 2026-07-16
Scope: Epic 40 Story 40.8 - Pilot UAT and Operational Recovery

## 1. Pilot Entry Checklist

1. Stories 40.1 through 40.7 are deployed to the pilot environment.
2. Required migrations completed successfully.
3. Feature flags and tenant/branch configuration are documented.
4. Pilot tenant, branch, terminals, products, recipes, and users are identified.
5. Required roles and permissions are provisioned.
6. Pilot seed data passed Configuration and Integrity validation.
7. Opening or migration inventory baseline is established.
8. Backup owner, last verified backup, restore-test status, RPO, and RTO are recorded if available.
9. Known defects are documented and classified.
10. Evidence repository is ready.
11. Support contacts and escalation hours are confirmed.
12. Pilot date and observation window are approved.

## 2. Pilot Scope Record

1. `pilot_tenant_id`:
2. `pilot_branch_ids`:
3. `pilot_terminal_ids`:
4. `pilot_business_dates`:
5. `pilot_product_scope`:
6. `pilot_user_scope`:
7. `pilot_start_at`:
8. `pilot_end_at`:
9. Pilot mode:
10. Evidence repository:

## 3. Scenario Execution Checklist

For every scenario, record:

1. Scenario ID.
2. Source story and acceptance criteria.
3. Test data and preconditions.
4. Required execution count and successful execution count.
5. Branches, terminals, roles, and business days covered.
6. Initial current stock.
7. Expected movement delta.
8. Expected final current stock.
9. Expected inventory revision.
10. Expected movement count.
11. Expected exception count.
12. Required evidence IDs.
13. Actual result.
14. Status.
15. Defect IDs.

Required scenario groups:

1. Setup validation.
2. Direct sale deduction and replay.
3. Offline sale synchronization when terminal policy allows offline cash sale.
4. Recipe deduction lineage.
5. Negative stock exception.
6. Void and refund restoration.
7. Stocktake activity during count.
8. Manual adjustment authorization and denial.
9. Reporting and export evidence.

## 4. Scenario Status Values

Use only:

```text
not_started
blocked
in_progress
passed
passed_with_observation
failed
retest_required
retested_passed
waived
```

Waivers require:

1. `waived_by`,
2. `waiver_reason`,
3. `risk_acceptance`,
4. `expiry_or_follow_up_date`.

## 5. Defect Severity

| Severity | Rollout effect |
| --- | --- |
| Severity 1 - Critical | Immediate no-go or pilot stop |
| Severity 2 - High | No-go unless fixed and fully retested |
| Severity 3 - Medium | Conditional go only with owner and workaround |
| Severity 4 - Low | May enter backlog |

`conditional_rollout` and `ready_for_rollout` are prohibited when any Severity 1 or Severity 2 defect remains unresolved.

## 6. Go/No-Go Checklist

1. All Severity 1 and Severity 2 defects are closed and retested.
2. No movement/current-stock mismatch remains unexplained.
3. No cross-tenant or cross-branch access issue exists.
4. Replay scenarios pass with no state drift.
5. Sale, recipe, refund, void, stocktake, and adjustment evidence reconcile.
6. Required roles completed training.
7. Recovery and escalation contacts are confirmed.
8. Audit exports pass permission and safety tests.
9. Branch manager signs operational readiness.
10. Implementation, support, and product owners sign technical readiness.

## 7. Hypercare Daily Checklist

During the configured observation window, record:

1. Current stock versus movement-derived stock.
2. Duplicate source-effect detection.
3. Open negative-stock exceptions.
4. Movement-chain discontinuities.
5. Stocktake posting failures.
6. Adjustment approval failures or unusual volume.
7. Missing configuration gaps.
8. Report/export errors.
9. Branch support tickets.
10. Inventory mutation latency or queue failures.

Immediate stop triggers:

1. Cross-tenant or cross-branch exposure.
2. Unauthorized stock mutation.
3. Duplicate committed stock effect.
4. Missing canonical movement after stock change.
5. Unexplained current-stock mismatch.
6. Incorrect stocktake correction.
7. Approval bypass.
8. Historical evidence mutation.

## 8. Post-Pilot Checklist

1. Collect questions, confusion points, and requested clarifications.
2. Record role-specific pain points for next enablement revision.
3. Archive the pilot version used by date, branch, and mode.
4. Log any mismatch between script and current UI.
5. Raise follow-up items to Product Operations, Engineering, Support, or Training owners.
6. Classify pilot outcome as `ready_for_rollout`, `extend_pilot`, `conditional_rollout`, or `pilot_failed`.
7. Confirm residual risks have owner, target resolution, pilot impact, rollout impact, and acceptance.

## 9. Sign-Off Record

1. Product owner:
2. Engineering lead:
3. QA/UAT lead:
4. Implementation lead:
5. Branch manager:
6. Inventory controller:
7. Support lead:
8. Security/auditor where applicable:
9. Open follow-ups:
