# Epic 41 Pilot UAT Readiness

Date: 2026-07-18
Status: Ready for Story 41.8 Implementation Validation - Pending Pilot Execution
Related Story: `docs/implementation-plans/epic-41/stories/story-41.8-pilot-uat-and-release-gate.md`

## 1. Purpose

This record is the canonical pilot-readiness checklist for Epic 41 POS Terminal Offline Readiness and Release Validation.

It validates behavior implemented by Stories 41.1 through 41.7. It does not introduce new offline mutation behavior.

The release gate distinguishes:

```text
implementation complete
    != pilot proven
    != production rollout approved
```

## 2. Required Documents

1. `docs/implementation-plans/epic-41/epic-41-architecture-lock.md`
2. `docs/implementation-plans/epic-41/epic-41-implementation-guide.md`
3. `docs/implementation-plans/epic-41/stories/story-41.8-pilot-uat-and-release-gate.md`
4. `docs/validation/epic-41-scenario-catalog.md`
5. `docs/validation/epic-41-evidence-manifest.md`
6. `docs/validation/epic-41-defect-waiver-register.md`
7. `docs/validation/epic-41-pilot-execution-log.md`
8. `docs/validation/epic-41-release-decision-record.md`

## 3. Entry Criteria

Pilot execution is blocked until all are complete:

1. Stories 41.1 through 41.7 are merged into the pilot build.
2. Pilot scope manifest is completed and versioned.
3. Environment manifest is completed for each tested terminal environment.
4. Tenant, branch, terminal, cashier, shift, and catalog prerequisites are documented.
5. Terminal machine profile and terminal binding epoch are verified.
6. Controlled offline capture policy is enabled only for approved scope.
7. Cash-only offline payment policy is confirmed.
8. Non-cash, statutory discount, void, refund, dining mutation, inventory mutation, stocktake, and admin operations are verified as online-only for the first release.
9. Support owner and escalation channel are assigned.
10. Compliance reviewer is assigned for provisional acknowledgment and official invoice wording.
11. Evidence storage location and masking rules are approved.
12. Defect, observation, deferral, and waiver registers are ready.
13. Offline-disable and containment process is understood by branch and support owners.
14. No known Severity 1 defect is open.

## 4. Pilot Scope Manifest

| Field | Value |
| --- | --- |
| `pilot_scope_id` |  |
| `scope_version` |  |
| `tenant_aliases` |  |
| `branch_aliases` |  |
| `terminal_aliases` |  |
| `binding_epochs` |  |
| `cashier_roles` |  |
| `start_date` |  |
| `end_date` |  |
| `build_reference` |  |
| `feature_policy_reference` |  |
| `test_data_policy` | isolated test tenant / pilot production-like tenant / controlled live branch |

Scope changes require a new `scope_version`.

## 5. Environment Manifest

| Field | Value |
| --- | --- |
| `environment_id` |  |
| `application_build` |  |
| `git_commit` |  |
| `deployment_id` |  |
| `migration_version` |  |
| `queue_schema_version` |  |
| `sync_contract_version` |  |
| `browser_version` |  |
| `service_worker_version` |  |
| `terminal_id_alias` |  |
| `terminal_binding_epoch` |  |
| `hardware_adapter` |  |
| `printer_model` |  |
| `drawer_model` |  |
| `network_profile` |  |
| `feature_policy_version` |  |
| `catalog_snapshot_version` |  |
| `shift_policy_version` |  |
| `business_date_rule_version` |  |

## 6. Role-Based UAT Matrix

| Role | Executes | Reviews | Signs Off |
| --- | --- | --- | --- |
| Cashier | BASE, OFF, POL cashier scenarios | Branch manager | Operational usability |
| Shift manager | shift close, cashier switch, pending cash scenarios | Branch manager, Support | Shift controls |
| Branch manager | pilot operations, containment, branch readiness | Release owner | Branch readiness |
| Owner/Admin | policy visibility and business acceptance | Release owner, Compliance | Business acceptance |
| Support reviewer | diagnostics, review-required, recovery scenarios | Engineering | Support readiness |
| Compliance reviewer | receipt wording, fiscal identity, acknowledgment scope | Release owner | Compliance wording |
| Engineering release owner | technical evidence, replay, drift, consequence validation | Independent reviewer | Engineering readiness |
| Release owner | gate evaluation and final decision | Required signatories | Final decision |

Critical technical evidence for Severity 1 and Severity 2 risk scenarios requires an independent reviewer.

## 7. UAT Scenario Matrix

Use `docs/validation/epic-41-scenario-catalog.md` as the stable scenario catalog.

Minimum scenario groups:

1. BASE - online baseline.
2. OFF - offline capture and transition.
3. SYNC - synchronization and replay.
4. REV - review, conflict, and drift.
5. POL - permission and online-only restrictions.
6. CON - inventory, loyalty, and downstream consequences.
7. REC - recovery, storage, and terminal identity.
8. HW - hardware and peripheral evidence.
9. SUP - support diagnostics.
10. FIS - fiscal and receipt wording.
11. REL - release and rollback exercises.

## 8. Test-Data and Reset Policy

Every scenario must identify whether it uses:

1. isolated test tenant,
2. pilot production-like tenant,
3. seeded product and inventory,
4. actual branch cash,
5. simulated payment,
6. reusable or single-use transaction identity.

No scenario may accidentally replay a prior UAT envelope as fresh evidence. Intentional replay scenarios must state the replay purpose and expected idempotent result.

## 9. Evidence Manifest

Use `docs/validation/epic-41-evidence-manifest.md`.

Accepted evidence must identify:

1. scenario ID and version,
2. environment ID,
3. application build and commit,
4. queue schema version,
5. sync contract version,
6. service worker version,
7. terminal binding epoch when relevant,
8. masking and retention class,
9. evidence review status.

Only `accepted` evidence counts toward a passed release gate.

## 10. Defect Severity and Waiver Model

Use `docs/validation/epic-41-defect-waiver-register.md`.

Severity 1 defects cannot be waived.

Severity 2 waivers require release owner, engineering owner, compliance reviewer when fiscal/customer/cash exposure is involved, business owner for operational risk, expiry date, branch and terminal scope, compensating control, and retest commitment.

## 11. Go Criteria

Go requires:

1. no Severity 1 defects open,
2. no unwaived Severity 2 defects open,
3. online baseline checkout passes,
4. offline shell and cached catalog pass,
5. cash-only durable capture passes,
6. local persistence failure blocks success state,
7. accepted sync posts exactly once,
8. exact replay does not duplicate sale, payment, inventory, or loyalty effects,
9. drift fails closed before mutation,
10. review-required records remain visible and are not retried as ordinary network failures,
11. cash-collected unresolved records are resolution-gated,
12. online-only operations are blocked offline,
13. provisional and official fiscal identities remain distinct,
14. support diagnostics are usable,
15. hardware validation or deferment is explicit,
16. rollback or offline-disable procedure is documented,
17. owner/admin, support, compliance, and engineering signoffs are recorded.

## 12. No-Go and Pilot-Stop Triggers

No-go or immediate pilot stop is required for:

1. duplicate accepted sale,
2. cross-tenant, cross-branch, or wrong-terminal posting,
3. lost or deletable cash-collected envelope,
4. local failure shown as successful capture,
5. provisional document presented as official invoice,
6. inventory or loyalty duplicated on replay,
7. review-required record automatically retried as ordinary transient failure,
8. terminal epoch ownership violation,
9. sensitive data exposed in diagnostics,
10. branch cannot execute the offline-disable procedure,
11. unexplained reconciliation gap involving pilot cash,
12. hardware failure incorrectly mutates sale state.

Containment evidence must prove:

1. new offline capture was disabled,
2. existing queue records were preserved,
3. branch and support owners were notified,
4. diagnostics were extracted,
5. unresolved cash records were listed,
6. rollout expansion was frozen,
7. destructive terminal reset was blocked until evidence review completed.

## 13. Hypercare Metrics and Thresholds

Metrics are scoped by pilot, branch, terminal, binding epoch, date range, and build.

| Metric | Threshold |
| --- | --- |
| duplicate sale count | 0 |
| lost cash-collected record count | 0 |
| cross-scope posting count | 0 |
| capture false-success count | 0 |
| Severity 1 incidents | 0 |
| unresolved cash beyond agreed SLA | 0 |
| offline_capture_attempts | measured |
| durable_capture_successes | measured |
| capture_uncertain_count | measured |
| storage_failed_count | measured |
| sync_accept_count | measured |
| sync_replay_count | measured |
| retryable_failure_count | measured |
| review_required_count | measured |
| rejected_count | measured |
| average_sync_delay | pilot-defined |
| maximum_sync_delay | pilot-defined |
| oldest_unresolved_cash_age | pilot-defined |
| loyalty_pending_count | measured |
| loyalty_failed_count | measured |
| inventory_review_count | measured |
| printer_failure_count | measured |
| drawer_failure_count | measured |
| support_incident_count | measured |

## 14. Hardware Validation and Deferral

Hardware status values:

```text
validated
validated_limited
deferred
not_available
failed
not_applicable
```

| Hardware/Storage Item | Status | Evidence | Notes |
| --- | --- | --- | --- |
| browser storage available |  |  |  |
| durable queue write verified |  |  |  |
| queue health heartbeat visible |  |  |  |
| terminal identity epoch retained |  |  |  |
| browser-storage-cleared recovery |  |  |  |
| printer adapter availability |  |  |  |
| physical printer success |  |  |  |
| physical printer failure classification |  |  |  |
| cash drawer adapter availability |  |  |  |
| physical drawer open success |  |  |  |
| scanner or manual-entry fallback |  |  |  |
| support diagnostics export |  |  |  |

Physical hardware readiness must not be claimed without physical evidence. Browser print is limited evidence only.

## 15. Compliance Wording Signoff

Compliance signoff must identify exact reviewed scope:

| Item | Version / Reference | Reviewer | Status | Notes |
| --- | --- | --- | --- | --- |
| offline acknowledgment template |  |  |  |  |
| official receipt template |  |  |  |  |
| invoice retrieval flow |  |  |  |  |
| customer-facing loyalty wording |  |  |  |  |
| review-required cash wording |  |  |  |  |
| hardware claim wording |  |  |  |  |
| certification disclaimer wording |  |  |  |  |

## 16. Exit Criteria

Pilot validation can proceed to release decision only when:

1. all mandatory scenarios are passed, retested-passed, waived, deferred, or marked not applicable with evidence,
2. all Severity 1 defects are closed and retested,
3. all Severity 2 defects are closed or formally waived,
4. all critical evidence is accepted,
5. all deferrals have owner, scope, impact, and target date,
6. hypercare metrics are measured against thresholds,
7. required role signoffs are recorded,
8. release decision record is ready for final evaluation.
