# Epic 41 Scenario Catalog

Date: 2026-07-18
Status: Template - Pending Pilot Execution
Related Story: `docs/implementation-plans/epic-41/stories/story-41.8-pilot-uat-and-release-gate.md`

## 1. Purpose

This catalog defines the stable pilot UAT scenarios for Epic 41 POS Terminal Offline Readiness and Release Validation.

Scenario IDs are stable release-gate references. Do not renumber an existing scenario after evidence, defects, observations, deferrals, or waivers reference it. If expected behavior changes, increment `scenario_version` and preserve the prior version in the release evidence trail.

## 2. Scenario Status Values

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
not_applicable
```

`passed_with_observation` means the expected result was met and no contract violation occurred, but a non-blocking operational or documentation concern was observed.

`blocked` means the scenario could not execute because an entry prerequisite, environment dependency, hardware item, or prior defect prevented execution. A blocked critical scenario is not a pass.

## 3. Scenario Contract

Every scenario execution record must capture:

| Field | Required |
| --- | --- |
| `scenario_id` | Yes |
| `scenario_version` | Yes |
| `contract_version` | Yes |
| `title` | Yes |
| `source_story` | Yes |
| `source_acceptance_criteria` | Yes |
| `risk_category` | Yes |
| `severity_if_failed` | Yes |
| `roles` | Yes |
| `preconditions` | Yes |
| `test_data` | Yes |
| `execution_steps` | Yes |
| `expected_result` | Yes |
| `required_evidence` | Yes |
| `actual_result` | Execution |
| `status` | Execution |
| `defect_reference` | Conditional |
| `observation_reference` | Conditional |
| `deferral_reference` | Conditional |
| `waiver_reference` | Conditional |
| `executed_by` | Execution |
| `executed_at` | Execution |
| `reviewed_by` | Execution |
| `reviewed_at` | Execution |
| `environment_reference` | Yes |

For Severity 1 and Severity 2 risk scenarios, `executed_by` must not be the sole `reviewed_by`.

## 4. Scenario Groups

```text
BASE-*  Online baseline
OFF-*   Offline capture and transition
SYNC-*  Synchronization and replay
REV-*   Review, conflict, and drift
POL-*   Permission and online-only restrictions
CON-*   Inventory, loyalty, and downstream consequences
REC-*   Recovery, storage, and terminal identity
HW-*    Hardware and peripheral evidence
SUP-*   Support diagnostics
FIS-*   Fiscal and receipt wording
REL-*   Release and rollback exercises
```

## 5. Scenario Matrix

| Scenario ID | Version | Source | Risk | Severity If Failed | Title | Primary Roles | Required Evidence | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| BASE-001 | 1 | 41.1, 41.5 | transaction_integrity | Severity 2 | Online baseline checkout remains stable | Cashier, Engineering | sale reference, payment reference, screenshot, test note | not_started |
| BASE-002 | 1 | 41.1 | operational_readiness | Severity 3 | Pilot environment prerequisites verified | Branch manager, Engineering | environment manifest, pilot scope manifest | not_started |
| OFF-001 | 1 | 41.1, 41.2, 41.5 | offline_policy | Severity 1 | Offline shell and cached catalog load while server is unreachable | Cashier, Engineering | browser screenshot, service-worker version, catalog snapshot reference | not_started |
| OFF-002 | 1 | 41.1, 41.5 | cash_exposure | Severity 1 | Cash-only durable offline capture succeeds only after verified local write | Cashier, Support | local reference, IndexedDB queue evidence, capture banner | not_started |
| OFF-003 | 1 | 41.2, 41.7 | storage_and_recovery | Severity 1 | Durable local persistence failure blocks capture success state | Cashier, Engineering | failure reproduction note, queue absence/presence evidence, screenshot | not_started |
| OFF-004 | 1 | 41.5 | fiscal_and_receipt | Severity 1 | Provisional acknowledgment remains distinct from official invoice | Cashier, Compliance | acknowledgment screenshot/template version, wording signoff | not_started |
| OFF-005 | 1 | 41.1, 41.5 | offline_policy | Severity 2 | Browser refresh after capture preserves queue state | Cashier, Support | local reference before/after refresh, diagnostics export | not_started |
| SYNC-001 | 1 | 41.3 | transaction_integrity | Severity 1 | Reconnect sync posts accepted record exactly once | Engineering, Support | import reference, sale reference, payment reference, diagnostics | not_started |
| SYNC-002 | 1 | 41.3 | transaction_integrity | Severity 1 | Exact replay does not duplicate sale, payment, inventory, or loyalty consequences | Engineering | before/after counts, import reference, consequence status | not_started |
| SYNC-003 | 1 | 41.3, 41.4 | transaction_integrity | Severity 1 | Drift is rejected before mutation | Engineering, Support | rejected import, no-sale evidence, drift reason | not_started |
| SYNC-004 | 1 | 41.2, 41.4 | transaction_integrity | Severity 2 | Multiple tabs cannot race queue synchronization | Engineering, Support | lease evidence, queue state before/after | not_started |
| REV-001 | 1 | 41.4 | cash_exposure | Severity 1 | Cash-collected review-required record remains visible and resolution-gated | Support, Branch manager | review record, cash status, resolution state | not_started |
| REV-002 | 1 | 41.4 | transaction_integrity | Severity 2 | Review-required predecessor is not retried as ordinary network failure | Support, Engineering | predecessor state, retry evidence, review reason | not_started |
| REV-003 | 1 | 41.4 | transaction_integrity | Severity 2 | Suspected duplicate business capture enters review unless exact replay is proven | Support, Engineering | duplicate evidence, review state, no duplicate sale | not_started |
| POL-001 | 1 | 41.5 | offline_policy | Severity 1 | Non-cash payment is blocked offline | Cashier, Compliance | screenshot, payment policy snapshot | not_started |
| POL-002 | 1 | 41.5 | offline_policy | Severity 1 | Statutory discount is blocked offline | Cashier, Compliance | screenshot, discount policy snapshot | not_started |
| POL-003 | 1 | 41.1, 41.5 | offline_policy | Severity 1 | Void, refund, dining mutation, stocktake, inventory adjustment, and admin mutation are blocked offline | Cashier, Owner/Admin | blocked-operation screenshots, policy reference | not_started |
| POL-004 | 1 | 41.5 | identity_and_tenant_isolation | Severity 2 | Cashier switching preserves original envelope actor evidence | Cashier, Shift manager | envelope actor evidence, current cashier evidence | not_started |
| POL-005 | 1 | 41.5 | operational_readiness | Severity 2 | Shift close with unsynced records is blocked or clearly provisional | Shift manager, Branch manager | shift screen evidence, queue count, policy message | not_started |
| CON-001 | 1 | 41.6 | inventory_and_loyalty | Severity 1 | Inventory synchronous consequence succeeds inside accepted-sale transaction | Engineering | movement reference, sale reference, import status | not_started |
| CON-002 | 1 | 41.6 | inventory_and_loyalty | Severity 1 | Strict-stock failure with no cash collected is rejected without partial consequences | Engineering, Support | rejected import, no-sale/no-movement evidence | not_started |
| CON-003 | 1 | 41.6 | cash_exposure | Severity 1 | Strict-stock failure with cash collected becomes review-required | Support, Branch manager | review import, cash status, no partial consequence | not_started |
| CON-004 | 1 | 41.6, Epic 39 | inventory_and_loyalty | Severity 2 | Loyalty pending consequence remains support-visible | Support, Engineering | consequence attempt, current consequence status | not_started |
| CON-005 | 1 | 41.6, Epic 39 | inventory_and_loyalty | Severity 2 | Loyalty retryable failure and retry exhaustion remain support-visible | Support, Engineering | attempt history, projection status, error code | not_started |
| CON-006 | 1 | 41.6, Epic 39 | inventory_and_loyalty | Severity 1 | Store credit remains not applicable for first-release offline cash sale | Engineering | consequence snapshot, absence of store-credit ledger | not_started |
| REC-001 | 1 | 41.7 | storage_and_recovery | Severity 1 | Browser storage loss is evidence-qualified and does not invent acceptance | Support, Engineering | queue health heartbeat, recovery state | not_started |
| REC-002 | 1 | 41.7 | identity_and_tenant_isolation | Severity 1 | Terminal revoked while offline fails closed on reconnect | Support, Engineering | terminal state, import status, review/reject reason | not_started |
| REC-003 | 1 | 41.4, 41.7 | transaction_integrity | Severity 2 | Device clock change is detected and business-date policy is applied server-side | Engineering | time evidence, business-date decision | not_started |
| REC-004 | 1 | 41.7 | storage_and_recovery | Severity 2 | Tombstone compaction preserves accepted reference and checksum evidence | Support | tombstone evidence, diagnostics export | not_started |
| HW-001 | 1 | 41.7 | hardware | Severity 3 | Browser print is classified as limited and not physical readiness | Compliance, Engineering | adapter evidence, wording note | not_started |
| HW-002 | 1 | 41.7 | hardware | Severity 2 | Physical printer success is claimed only with physical device evidence | Branch manager, Support | print observation, device model, receipt template | not_started |
| HW-003 | 1 | 41.7 | hardware | Severity 2 | Cash drawer readiness is claimed only with physical device evidence | Branch manager, Support | drawer observation, device model, adapter version | not_started |
| HW-004 | 1 | 41.7 | hardware | Severity 3 | Hardware unavailable is recorded as deferral, not pass | Release owner | deferral record, operating limitation | not_started |
| SUP-001 | 1 | 41.2, 41.4, 41.7 | support_readiness | Severity 2 | Support can export bounded diagnostics without sensitive-data overexposure | Support, Compliance | export manifest, masking review, retention class | not_started |
| SUP-002 | 1 | 41.4, 41.6 | support_readiness | Severity 2 | Support can locate pending, failed, review-required, accepted, and consequence-specific states | Support | screenshots or exports per status | not_started |
| FIS-001 | 1 | 41.5 | fiscal_and_receipt | Severity 1 | Local offline reference, server sale reference, and official invoice reference remain distinct | Compliance, Engineering | references from capture through acceptance | not_started |
| FIS-002 | 1 | 41.5 | fiscal_and_receipt | Severity 1 | Production acknowledgment wording has explicit compliance signoff scope | Compliance | signoff record, template versions | not_started |
| REL-001 | 1 | 41.8 | rollback_and_containment | Severity 1 | Pilot-stop containment disables new offline capture without deleting existing queue evidence | Release owner, Support | containment log, queue evidence, notification record | not_started |
| REL-002 | 1 | 41.8 | release_governance | Severity 1 | Release decision cannot jump from documentation completion to go approval | Release owner | decision record transition history | not_started |

## 6. Traceability Summary

| Gate Category | Minimum Scenario Coverage |
| --- | --- |
| `architecture_integrity` | OFF-001, POL-003, REL-002 |
| `transaction_integrity` | BASE-001, SYNC-001, SYNC-002, SYNC-003 |
| `cash_exposure` | OFF-002, REV-001, CON-003 |
| `identity_and_tenant_isolation` | REC-002, POL-004 |
| `offline_policy` | POL-001, POL-002, POL-003 |
| `inventory_and_loyalty` | CON-001 through CON-006 |
| `fiscal_and_receipt` | OFF-004, FIS-001, FIS-002 |
| `storage_and_recovery` | OFF-003, REC-001, REC-004 |
| `hardware` | HW-001 through HW-004 |
| `support_readiness` | SUP-001, SUP-002 |
| `operational_readiness` | BASE-002, POL-005 |
| `rollback_and_containment` | REL-001 |
