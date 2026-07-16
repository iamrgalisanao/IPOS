# Epic 40 Pilot UAT Readiness

Date: 2026-07-16
Status: Ready for Story 40.8 Implementation Validation
Related Story: `docs/implementation-plans/epic-40/stories/story-40.8-pilot-uat-and-operational-recovery.md`

## 1. Purpose

This record is the canonical pilot-readiness checklist for Epic 40 Inventory Operational Control and Reconciliation.

It validates existing behavior from Stories 40.1 through 40.7. It does not introduce new inventory mutation behavior.

## 2. Required Documents

1. `docs/user-enablement/pilot-enablement-pack-overview.md`
2. `docs/user-enablement/inventory-pilot-branch-walkthrough-run-sheet.md`
3. `docs/user-enablement/inventory-pilot-checklist-addendum.md`
4. `docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md`
5. `docs/user-enablement/inventory-pilot-branch-manager-demo-script.md`
6. `docs/user-enablement/inventory-pilot-screenshot-capture-pack.md`
7. `docs/user-guide/04-module-guides/inventory.md`

## 3. Entry Criteria

UAT or pilot activation is blocked until all are complete:

1. Stories 40.1 through 40.7 deployed to the pilot environment.
2. Required migrations completed.
3. Feature flags and tenant/branch configuration documented.
4. Pilot tenant, branch, terminals, products, recipes, and users identified.
5. Required roles and permissions provisioned.
6. Configuration and Integrity report reviewed.
7. Opening or migration inventory baseline established.
8. Backup owner and recovery ownership confirmed.
9. Known defects documented and classified.
10. Evidence repository ready.
11. Support contacts and escalation hours confirmed.
12. Pilot date and observation window approved.

## 4. Pilot Scope Record

| Field | Value |
| --- | --- |
| `pilot_tenant_id` |  |
| `pilot_branch_ids` |  |
| `pilot_terminal_ids` |  |
| `pilot_business_dates` |  |
| `pilot_product_scope` |  |
| `pilot_user_scope` |  |
| `pilot_start_at` |  |
| `pilot_end_at` |  |
| Pilot mode | `isolated_simulation` / `parallel_run_branch` / `controlled_live_branch` |

## 5. UAT Scenario Matrix

| Scenario ID | Scenario | Source story | Required evidence | Minimum repetition |
| --- | --- | --- | --- | --- |
| UAT-40-001 | Setup validation | 40.1, 40.2, 40.4, 40.6 | Unit conversion, product/recipe setup, Configuration and Integrity | Approved in pilot plan |
| UAT-40-002 | Direct sale deduction and replay | 40.1, 40.7 | Sale reference, Stock Card, Current Stock, Reconciliation Exceptions | Repeat per pilot plan |
| UAT-40-003 | Offline sale synchronization | 40.1, 40.4 | Local queue reference, sync status, movement counts before/after, Stock Card | Repeat per pilot plan when policy allows |
| UAT-40-004 | Recipe deduction lineage | 40.2, 40.4, 40.7 | Product Composition, Stock Card, movement snapshot | Repeat per pilot plan |
| UAT-40-005 | Negative stock exception | 40.3, 40.7 | POS result, Negative Stock Exceptions, variance detail | Repeat strict and soft policy cases |
| UAT-40-006 | Void/refund restoration | 40.1, 40.7 | Original sale, void/refund reference, Stock Card, Movement Summary | Repeat replay cases |
| UAT-40-007 | Stocktake activity during count | 40.5, 40.7 | Stocktake session, summary, Physical Count Variance, correction movement | Repeat per pilot plan |
| UAT-40-008 | Manual adjustment authorization | 40.6 | Request/preview, approval/denial, Stock Card, audit evidence | Repeat approved and denied cases |
| UAT-40-009 | Reporting and export evidence | 40.7 | Current Stock, Stock Card, Movement Summary, Reconciliation, Usage, export logs | At least once per role scope |

Each scenario record must include expected stock values, expected movement count, expected inventory revision, expected exception count, actual result, status, defect IDs, and evidence IDs.

## 6. Replay Invariants

For exact replay scenarios, record:

1. movement count before replay,
2. movement count after replay,
3. current stock before replay,
4. current stock after replay,
5. inventory revision before replay,
6. inventory revision after replay,
7. approval consumption before/after,
8. exception count before/after.

All must remain unchanged after exact replay.

## 7. Scenario Status and Waiver

Allowed statuses:

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

Waivers require `waived_by`, `waiver_reason`, `risk_acceptance`, and `expiry_or_follow_up_date`.

## 8. Defect Severity

| Severity | Blocks rollout? | Examples |
| --- | --- | --- |
| Severity 1 - Critical | Yes | Cross-branch exposure, unauthorized mutation, stock change without movement, duplicate committed effect |
| Severity 2 - High | Yes | Incorrect deduction, stocktake miscalculation, approval bypass, material report/export error |
| Severity 3 - Medium | Conditional only | Recoverable permission issue, confusing workflow, non-critical report issue |
| Severity 4 - Low | No | Cosmetic or documentation issue |

## 9. Go/No-Go Criteria

Go requires:

1. Severity 1 and Severity 2 defects closed and retested.
2. No unexplained movement/current-stock mismatch.
3. No branch or tenant isolation issue.
4. Replay scenarios pass.
5. Sale, recipe, refund, void, stocktake, and adjustment evidence reconcile.
6. Required roles completed training.
7. Recovery contacts confirmed.
8. Audit exports pass permission and safety checks.
9. Branch manager signs operational readiness.
10. Product, implementation, support, and engineering sign technical readiness.

## 10. Containment and Recovery

Use `docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md`.

Containment modes must identify whether they are:

```text
system_enforced
feature_flag_enforced
operational_procedure
unavailable
```

Routine inventory discrepancy recovery uses governed inventory workflows. Database restore is reserved for platform-level disaster recovery.

## 11. Evidence Manifest

| Field | Required |
| --- | --- |
| `evidence_id` | Yes |
| `scenario_id` | Yes |
| `artifact_type` | Yes |
| `file_name_or_location` | Yes |
| `captured_by` | Yes |
| `captured_at` | Yes |
| tenant | Yes |
| branch | Yes |
| source reference | Yes |
| contains sensitive data | Yes |
| masking status | Yes |
| retention class | Yes |
| checksum | Optional |
| reviewed by | Yes |

## 12. Hypercare

Observation window:

```text
pilot-configured business days
```

Numeric reconciliation tolerance when an authoritative baseline exists:

```text
abs(current_stock - movement_derived_stock) < 0.0001
```

Daily checks:

1. current stock versus movement-derived stock,
2. duplicate source-effect detection,
3. open negative-stock exceptions,
4. movement-chain discontinuities,
5. stocktake posting failures,
6. adjustment approval failures or unusual volume,
7. missing configuration gaps,
8. report/export errors,
9. branch support tickets,
10. inventory mutation latency or queue failures.

Immediate pilot-stop triggers:

1. cross-tenant or cross-branch exposure,
2. unauthorized stock mutation,
3. duplicate committed stock effect,
4. missing canonical movement after stock change,
5. unexplained current-stock mismatch,
6. incorrect stocktake correction,
7. approval bypass,
8. historical evidence mutation.

## 13. Exit Criteria

Pilot is complete only when:

1. observation window completed,
2. no unresolved Severity 1 or Severity 2 defect exists,
3. daily reconciliation remains within approved tolerance,
4. no unexplained inventory revision or movement drift exists,
5. support tickets reviewed and categorized,
6. branch users demonstrate required workflows,
7. recovery drill succeeds,
8. evidence pack complete,
9. residual risks have owners,
10. rollout recommendation approved.

Outcome:

```text
ready_for_rollout
extend_pilot
conditional_rollout
pilot_failed
```

`conditional_rollout` is allowed only for accepted Severity 3 or Severity 4 defects.

## 14. Residual Risk Register

| Risk ID | Description | Severity | Affected story | Scope | Workaround | Owner | Target resolution | Pilot impact | Rollout impact | Accepted by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
|  |  |  |  |  |  |  |  |  |  |  |

## 15. Signoff

| Role | Name | Decision | Date |
| --- | --- | --- | --- |
| Product owner |  |  |  |
| Engineering lead |  |  |  |
| QA/UAT lead |  |  |  |
| Implementation lead |  |  |  |
| Branch manager |  |  |  |
| Inventory controller |  |  |  |
| Support lead |  |  |  |
| Security/auditor where applicable |  |  |  |
