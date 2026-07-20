# Epic 41 Defect, Observation, Deferral, and Waiver Register

Date: 2026-07-18
Status: Template - Pending Pilot Execution
Related Story: `docs/implementation-plans/epic-41/stories/story-41.8-pilot-uat-and-release-gate.md`

## 1. Purpose

This register keeps pilot defects, observations, deferrals, and waivers distinct.

```text
defect
Implemented behavior does not meet the approved contract.

observation
Behavior meets the contract but needs attention or clarification.

deferral
A scenario or capability was intentionally not validated in this pilot.

waiver
A known defect or failed requirement is temporarily accepted by authorized roles.
```

A deferral is not a waiver. A waiver is not a pass.

## 2. Severity Model

| Severity | Blocks Rollout? | Examples |
| --- | --- | --- |
| Severity 1 - Critical | Yes, cannot be waived | Cross-tenant exposure, duplicate accepted sale, browser-local fiscal authority, lost cash-collected envelope, official invoice misrepresentation |
| Severity 2 - High | Yes unless explicitly waived by all required authorities | Sync drift posts mutation, strict-stock classification wrong, review-required retry loop, cashier ownership overwritten, diagnostics cannot locate unresolved cash |
| Severity 3 - Medium | Conditional | Confusing cashier wording, non-blocking diagnostics gap, recoverable hardware classification issue |
| Severity 4 - Low | No | Cosmetic issue, documentation typo, non-critical wording polish |

Severity 1 defects cannot be waived. A Severity 1 defect requires `pilot_failed`, `no_go`, or continued containment until fixed and retested.

## 3. Severity 2 Waiver Authority

Severity 2 waivers require:

1. release owner,
2. engineering owner,
3. compliance reviewer when fiscal, customer, or cash exposure is involved,
4. business owner for operational risk,
5. expiry date,
6. branch and terminal scope,
7. compensating control,
8. retest commitment.

## 4. Register Fields

| Field | Required |
| --- | --- |
| `record_id` | Yes |
| `record_type` | Yes |
| `scenario_ids` | Yes |
| `scenario_versions` | Yes |
| `severity` | Conditional |
| `description` | Yes |
| `business_impact` | Yes |
| `cash_exposure` | Yes |
| `affected_scope` | Yes |
| `owner` | Yes |
| `created_at` | Yes |
| `target_date` | Conditional |
| `expiry_date` | Conditional |
| `compensating_control` | Conditional |
| `evidence_reference` | Conditional |
| `approval_roles` | Conditional |
| `status` | Yes |
| `closure_reference` | Conditional |

## 5. Record Status Values

```text
open
in_triage
fix_in_progress
ready_for_retest
closed
deferred
waived_active
waived_expired
superseded
rejected
```

## 6. Defect Register

| Record ID | Scenario IDs | Severity | Description | Cash Exposure | Affected Scope | Owner | Status | Evidence | Closure Reference |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DEF-41-0001 |  |  |  |  |  |  | open |  |  |

## 7. Observation Register

| Record ID | Scenario IDs | Description | Business Impact | Owner | Status | Evidence | Closure Reference |
| --- | --- | --- | --- | --- | --- | --- | --- |
| OBS-41-0001 |  |  |  |  | open |  |  |

## 8. Deferral Register

| Record ID | Scenario IDs | Deferred Scope | Reason | Owner | Target Date | Decision Impact | Evidence | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DEFERRAL-41-0001 |  |  |  |  |  |  |  | deferred |

Hardware unavailability is normally recorded as a deferral, not a waiver. The release may approve only the validated scope and must not claim deferred physical hardware readiness.

## 9. Waiver Register

| Record ID | Scenario IDs | Severity | Waiver Reason | Risk Acceptance | Compensating Control | Required Approvals | Expiry Date | Retest Commitment | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| WAIVER-41-0001 |  | Severity 2 |  |  |  |  |  |  | waived_active |

Severity 1 waivers are prohibited.
