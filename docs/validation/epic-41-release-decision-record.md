# Epic 41 Release Decision Record

Date: 2026-07-18
Status: Template - No Release Decision Yet
Related Story: `docs/implementation-plans/epic-41/stories/story-41.8-pilot-uat-and-release-gate.md`

## 1. Purpose

This record captures the final release decision for Epic 41 POS Terminal Offline Readiness.

This template being present does not mean pilot validation passed or production rollout is approved.

## 2. Allowed Decision Statuses

```text
not_started
ready_for_pilot
pilot_in_progress
pilot_passed_with_deferrals
pilot_failed
go_approved
no_go
deferred
```

Current decision status:

```text
not_started
```

## 3. Allowed State Transitions

```text
not_started
    -> ready_for_pilot

ready_for_pilot
    -> pilot_in_progress
    -> deferred

pilot_in_progress
    -> pilot_passed_with_deferrals
    -> pilot_failed
    -> deferred

pilot_passed_with_deferrals
    -> go_approved
    -> no_go
    -> deferred

pilot_failed
    -> ready_for_pilot
    -> no_go
    -> deferred

go_approved
    -> no_go only through emergency revocation record
```

Forbidden transitions:

```text
not_started -> go_approved
ready_for_pilot -> go_approved
pilot_in_progress -> go_approved
```

## 4. Decision Immutability

Once signed:

1. the signed decision record is immutable,
2. corrections create a superseding decision record,
3. emergency revocation creates a new decision event,
4. signoff timestamps and identities are retained,
5. previous decisions are never overwritten.

## 5. Decision Header

| Field | Value |
| --- | --- |
| decision_record_id |  |
| supersedes_decision_record_id |  |
| decision_owner |  |
| decision_date |  |
| pilot_scope_id |  |
| scope_version |  |
| environment_ids |  |
| final_decision | not_started |
| rollback_or_containment_reference |  |

## 6. Pilot Scope Summary

| Field | Value |
| --- | --- |
| tenant_aliases |  |
| branch_aliases |  |
| terminal_aliases |  |
| binding_epochs |  |
| cashier_roles |  |
| start_date |  |
| end_date |  |
| build_reference |  |
| feature_policy_reference |  |

## 7. Gate Evaluation

Gate result values:

```text
passed
failed
passed_with_deferral
not_applicable
```

| Gate Category | Result | Evidence | Defect/Waiver/Deferral | Signoff |
| --- | --- | --- | --- | --- |
| architecture_integrity |  |  |  |  |
| transaction_integrity |  |  |  |  |
| cash_exposure |  |  |  |  |
| identity_and_tenant_isolation |  |  |  |  |
| offline_policy |  |  |  |  |
| inventory_and_loyalty |  |  |  |  |
| fiscal_and_receipt |  |  |  |  |
| storage_and_recovery |  |  |  |  |
| hardware |  |  |  |  |
| support_readiness |  |  |  |  |
| operational_readiness |  |  |  |  |
| rollback_and_containment |  |  |  |  |

## 8. Scenario Execution Summary

| Status | Count | Notes |
| --- | --- | --- |
| not_started |  |  |
| blocked |  |  |
| in_progress |  |  |
| passed |  |  |
| passed_with_observation |  |  |
| failed |  |  |
| retest_required |  |  |
| retested_passed |  |  |
| waived |  |  |
| not_applicable |  |  |

## 9. Defect and Waiver Summary

| Category | Count | Blocks Release? | Notes |
| --- | --- | --- | --- |
| open Severity 1 |  | Yes | Severity 1 cannot be waived |
| open Severity 2 |  | Yes unless formally waived |  |
| active Severity 2 waivers |  | Conditional | Requires all authorities |
| open Severity 3 |  | Conditional |  |
| open Severity 4 |  | No |  |
| deferrals |  | Conditional | Must have owner and target date |

## 10. Hardware Validation Status

| Item | Status | Evidence | Decision Impact |
| --- | --- | --- | --- |
| browser storage |  |  |  |
| printer adapter |  |  |  |
| physical printer |  |  |  |
| cash drawer adapter |  |  |  |
| physical drawer |  |  |  |
| scanner or manual-entry fallback |  |  |  |

Hardware readiness cannot be claimed without physical evidence.

## 11. Compliance Wording Signoff

| Item | Version / Reference | Reviewer | Status | Notes |
| --- | --- | --- | --- | --- |
| offline acknowledgment template |  |  |  |  |
| official receipt template |  |  |  |  |
| invoice retrieval flow |  |  |  |  |
| customer-facing loyalty wording |  |  |  |  |
| review-required cash wording |  |  |  |  |
| hardware claim wording |  |  |  |  |
| certification disclaimer wording |  |  |  |  |

## 12. Required Signoffs

| Role | Name | Result | Date | Notes |
| --- | --- | --- | --- | --- |
| Branch manager |  |  |  |  |
| Owner/Admin |  |  |  |  |
| Support reviewer |  |  |  |  |
| Compliance reviewer |  |  |  |  |
| Engineering release owner |  |  |  |  |
| Release owner |  |  |  |  |

## 13. Hypercare Metrics

| Metric | Threshold | Actual | Result | Notes |
| --- | --- | --- | --- | --- |
| duplicate sale count | 0 |  |  |  |
| lost cash-collected record count | 0 |  |  |  |
| cross-scope posting count | 0 |  |  |  |
| capture false-success count | 0 |  |  |  |
| Severity 1 incidents | 0 |  |  |  |
| unresolved cash beyond agreed SLA | 0 |  |  |  |
| average_sync_delay | pilot-defined |  |  |  |
| maximum_sync_delay | pilot-defined |  |  |  |
| support_incident_count | measured |  |  |  |

## 14. No-Go Containment Evidence

Complete this section for `no_go`, emergency revocation, or pilot stop.

| Containment Step | Evidence | Owner | Completed At | Notes |
| --- | --- | --- | --- | --- |
| New offline capture disabled |  |  |  |  |
| Existing queue preserved |  |  |  |  |
| Branch notified |  |  |  |  |
| Support owner assigned |  |  |  |  |
| Diagnostics extracted |  |  |  |  |
| Unresolved cash listed |  |  |  |  |
| Rollout expansion frozen |  |  |  |  |

## 15. Final Decision

Final decision:

```text
not_started
```

Decision rationale:

```text
Pending pilot execution.
```

Authorized scope:

```text
No production rollout scope approved by this template.
```
