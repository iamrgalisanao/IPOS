# Story 41.8 Pilot UAT and Release Gate

## Status

Implemented - Local Verification Complete

Date: 2026-07-18

## Objective

Create the pilot UAT and release-gate implementation package for Epic 41 POS Terminal Offline Readiness.

This story validates the complete offline terminal chain built by Stories 41.1 through 41.7 and produces a documented release decision. It does not introduce new runtime behavior. It converts the implemented offline architecture into a repeatable branch validation process with evidence, signoff, go/no-go criteria, and explicit deferrals.

## User Story

As an implementation lead and release owner, I want a complete pilot UAT and release-gate package for offline terminal readiness, so that branch rollout decisions are based on executed evidence rather than assumptions about the implemented code.

## Architecture Boundary

Story 41.8 is a validation and governance story.

It may create:

1. UAT plans.
2. release decision records.
3. evidence manifests.
4. role-based validation matrices.
5. support diagnostics checklists.
6. pilot stop/go criteria.
7. operational runbooks and signoff templates.

It must not create:

1. new offline mutation behavior,
2. new sync state transitions,
3. new payment or fiscal logic,
4. new inventory, loyalty, or store-credit mutation behavior,
5. production deployment automation,
6. certification or compliance claims.

## Dependencies

Requires:

1. Story 41.1 Offline Architecture and Policy Lock.
2. Story 41.2 Offline Transaction Queue Integrity.
3. Story 41.3 Server Synchronization, Idempotency, and Transaction Atomicity.
4. Story 41.4 Conflict, Drift, Ordering, and Review Handling.
5. Story 41.5 Offline Permission, Shift, Payment, Discount, and Receipt Restrictions.
6. Story 41.6 Inventory, Loyalty, and Cross-Domain Consequence Validation.
7. Story 41.7 Hardware, Storage-Loss, and Terminal Recovery.

Related validation references:

1. `docs/validation/pos-terminal-offline-stabilization-2026-07-10.md`
2. `docs/validation/pos-terminal-offline-uat-2026-07-11.md`
3. `docs/validation/epic-41-terminal-identity-binding-closure.md`
4. `docs/validation/epic-28-phase-2-pilot-runbook.md`
5. `docs/validation/epic-40-pilot-uat-readiness.md`

## Complexity

Medium

The implementation is documentation-heavy, but the decision risk is high because this story is the release gate for branch offline behavior.

## Provider Benchmark

Primary benchmark:

```text
Mosaic-style evidence-driven implementation and support validation
```

Secondary benchmarks:

```text
StoreHub-style branch-pilot operational readiness
UTAK-style simple cashier UAT execution
```

These providers are validation and operating-model benchmarks only. Story 41.8 must not introduce a runtime dependency on Mosaic, StoreHub, or UTAK.

Story 41.8 uses:

1. Mosaic as the primary benchmark for integration validation, support diagnostics, abnormal-state investigation, evidence review, and implementation closure.
2. StoreHub as the secondary benchmark for branch pilot execution, hardware preparation, printer/drawer readiness, and manager operations.
3. UTAK as the secondary benchmark for cashier-friendly scripts, tablet POS simplicity, and realistic SMB online/offline limitations.

IPOS owns the final scenario catalog, evidence manifest, defect and waiver governance, release decision record, and release approval authority.

## Locked Release-Gate Principles

1. Implemented behavior is not the same as pilot-proven behavior.
2. Pilot-proven behavior is not the same as production rollout approval.
3. Offline browser state remains provisional until server acceptance.
4. Server services remain authoritative for sale posting, inventory, loyalty, store credit, receipt compliance, fiscal evidence, and reporting.
5. Cash-only standalone offline capture is the first-release offline mutation boundary.
6. Non-cash payment, void, refund, statutory discount, dining mutation, stocktake, inventory adjustment, and admin operations remain online-only.
7. Provisional acknowledgment must never be presented as an official invoice unless a future formally approved fiscal architecture allows it.
8. Hardware readiness cannot be claimed without physical device evidence.
9. Cash-collected unresolved records must remain preserved, visible, and resolution-gated.
10. Release approval must include explicit residual risk, deferrals, owner, date, and evidence references.

## Deliverables

### 1. Pilot UAT readiness plan

Create:

```text
docs/validation/epic-41-pilot-uat-readiness.md
```

The plan must include:

1. purpose,
2. scope,
3. entry criteria,
4. pilot branch and terminal scope,
5. environment prerequisites,
6. role and permission prerequisites,
7. UAT scenario matrix,
8. defect severity model,
9. stop/go criteria,
10. evidence capture requirements,
11. hypercare metrics,
12. exit criteria.

### 2. Release decision record

Create:

```text
docs/validation/epic-41-release-decision-record.md
```

The record must distinguish these statuses:

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

It must include:

1. decision owner,
2. decision date,
3. pilot scope,
4. executed scenario summary,
5. unresolved defects,
6. accepted deferrals,
7. hardware validation status,
8. compliance wording signoff,
9. support readiness signoff,
10. engineering readiness signoff,
11. final decision,
12. rollback or containment plan reference.

Release-state transitions are locked:

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

Signed release decisions are immutable. Corrections, supersession, revocation, or emergency no-go actions require a new decision record that references the previous record. Prior signed records must not be overwritten.

### 3. Evidence manifest schema

Define the evidence manifest in the readiness plan and decision record.

Required fields:

| Field | Required |
| --- | --- |
| `evidence_id` | Yes |
| `scenario_id` | Yes |
| `artifact_type` | Yes |
| `file_name_or_location` | Yes |
| `captured_by` | Yes |
| `captured_at` | Yes |
| `tenant_id_or_alias` | Yes |
| `branch_id_or_alias` | Yes |
| `terminal_id_or_alias` | Conditional |
| `cashier_id_or_alias` | Conditional |
| `offline_transaction_uuid` | Conditional |
| `local_sequence` | Conditional |
| `server_sale_reference` | Conditional |
| `official_invoice_reference` | Conditional |
| `contains_sensitive_data` | Yes |
| `masking_status` | Yes |
| `retention_class` | Yes |
| `reviewed_by` | Yes |
| `checksum` | Optional |

Additional required fields for release-gate evidence:

| Field | Required |
| --- | --- |
| `artifact_version` | Yes |
| `environment_id` | Yes |
| `application_build` | Yes |
| `git_commit` | Yes |
| `deployment_id` | Conditional |
| `queue_schema_version` | Yes |
| `sync_contract_version` | Yes |
| `service_worker_version` | Yes |
| `terminal_binding_epoch` | Conditional |
| `server_import_reference` | Conditional |
| `checksum_algorithm` | Conditional |
| `evidence_status` | Yes |

Allowed evidence statuses:

```text
captured
under_review
accepted
rejected
superseded
expired
```

Only `accepted` evidence counts toward a passed release gate. Rejected, superseded, or expired evidence remains in history and must not be deleted from the decision trail.

Evidence may be screenshots, exported diagnostics, server records, queue records, logs, printed artifacts, test output, or signed observation notes.

### 4. Environment manifest

The readiness plan must include a pilot environment manifest.

Required fields:

| Field | Required |
| --- | --- |
| `environment_id` | Yes |
| `application_build` | Yes |
| `git_commit` | Yes |
| `deployment_id` | Conditional |
| `migration_version` | Yes |
| `queue_schema_version` | Yes |
| `sync_contract_version` | Yes |
| `browser_version` | Yes |
| `service_worker_version` | Yes |
| `terminal_id_alias` | Yes |
| `terminal_binding_epoch` | Yes |
| `hardware_adapter` | Yes |
| `printer_model` | Conditional |
| `drawer_model` | Conditional |
| `network_profile` | Yes |
| `feature_policy_version` | Yes |
| `catalog_snapshot_version` | Conditional |
| `shift_policy_version` | Conditional |
| `business_date_rule_version` | Conditional |

Evidence without environment identity cannot prove which build, schema, contract, service worker, terminal epoch, or browser environment was tested.

### 5. Pilot scope manifest

Pilot scope must be versioned.

Required fields:

| Field | Required |
| --- | --- |
| `pilot_scope_id` | Yes |
| `scope_version` | Yes |
| `tenant_aliases` | Yes |
| `branch_aliases` | Yes |
| `terminal_aliases` | Yes |
| `binding_epochs` | Yes |
| `cashier_roles` | Yes |
| `start_date` | Yes |
| `end_date` | Yes |
| `build_reference` | Yes |
| `feature_policy_reference` | Yes |
| `test_data_policy` | Yes |

Changes to pilot branch, terminal, build, policy, cashier role scope, or business-date scope require a new scope version.

### 6. Scenario catalog contract

Create stable scenario IDs using these groups:

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

Scenario IDs must not be renumbered after evidence, defects, observations, deferrals, or waivers reference them.

Each scenario must include:

```text
scenario_id
scenario_version
contract_version
title
source_story
source_acceptance_criteria
risk_category
severity_if_failed
roles
preconditions
test_data
execution_steps
expected_result
required_evidence
actual_result
status
defect_reference
observation_reference
deferral_reference
waiver_reference
executed_by
executed_at
reviewed_by
reviewed_at
environment_reference
```

For Severity 1 and Severity 2 risk scenarios, `executed_by` must not be the sole `reviewed_by`.

### 7. Role-based UAT matrix

Define role responsibility for:

1. cashier,
2. shift manager,
3. branch manager,
4. owner/admin,
5. support reviewer,
6. compliance reviewer,
7. engineering release owner.

Each role must have:

1. required scenarios,
2. required evidence,
3. signoff responsibility,
4. escalation responsibility.

### 8. Offline transition scenario matrix

Cover at minimum:

1. online baseline checkout,
2. cached shell refresh while offline,
3. cached catalog visibility,
4. offline cash-only capture,
5. durable local write failure,
6. browser refresh after capture,
7. reconnect and sync,
8. exact replay,
9. request drift,
10. suspected duplicate business capture,
11. review-required predecessor handling,
12. stale catalog age limit,
13. statutory discount attempted offline,
14. non-cash payment attempted offline,
15. terminal revoked while offline,
16. device clock changed while offline,
17. browser storage cleared,
18. multiple tabs attempting sync,
19. cashier switching with pending records,
20. shift close with unsynced records.

### 9. Consequence validation scenario matrix

Cover at minimum:

1. server sale accepted once,
2. inventory synchronous consequence succeeds,
3. strict-stock failure with no cash collected is rejected,
4. strict-stock failure with cash collected is review-required,
5. loyalty pending consequence is visible,
6. loyalty retryable failure remains support-visible,
7. loyalty retry exhaustion is support-visible,
8. store credit remains not applicable,
9. accounting and fiscal finalization stay server-authoritative,
10. official invoice is retrieved only after server acceptance.

### 10. Hardware and storage validation/deferment record

Consume Story 41.7 handoff evidence.

The release gate must classify each item as:

```text
validated
validated_limited
deferred
not_available
failed
not_applicable
```

Required hardware/storage rows:

1. browser storage available,
2. durable queue write verified,
3. queue health heartbeat visible,
4. terminal identity epoch retained,
5. browser-storage-cleared recovery,
6. printer adapter availability,
7. physical printer success,
8. physical printer failure classification,
9. cash drawer adapter availability,
10. physical drawer open success,
11. scanner or manual-entry fallback,
12. support diagnostics export.

If physical printer or drawer hardware is unavailable, the release record must say hardware readiness is deferred. It must not claim physical hardware readiness.

Hardware evidence is configuration-bound. Accepted physical hardware evidence must include:

1. `evidence_valid_until`,
2. `invalidated_by_change`,
3. device model,
4. OS or WebView version,
5. browser version,
6. adapter version,
7. printer model,
8. drawer model,
9. connection method,
10. receipt template version.

Prior hardware evidence must be marked invalid or require revalidation when any material hardware or receipt configuration changes.

### 11. Support diagnostics checklist

Validate that support can diagnose:

1. pending records,
2. failed records,
3. review-required records,
4. accepted records,
5. accepted tombstones,
6. hash-chain failure,
7. terminal epoch mismatch,
8. possible storage loss,
9. cash-collected unresolved cases,
10. consequence-specific pending or failed states,
11. official invoice retrieval state,
12. bounded support export.

### 12. Compliance wording signoff

The release gate must explicitly review:

1. provisional acknowledgment wording,
2. official invoice distinction,
3. local offline reference format,
4. server sale reference format,
5. official invoice delivery process,
6. customer messaging for pending loyalty,
7. customer messaging for cash-collected review cases,
8. absence of certification claims.

Production offline acknowledgment must not be enabled without compliance signoff.

Compliance signoff must identify the exact reviewed scope:

1. offline acknowledgment template version,
2. official receipt template version,
3. invoice retrieval flow,
4. customer-facing loyalty wording,
5. review-required cash wording,
6. hardware claim wording,
7. certification disclaimer wording.

A general compliance-approved checkbox is insufficient.

### 13. Go/no-go criteria

Go requires:

1. no Severity 1 defects open,
2. no Severity 2 defects open unless explicitly waived by release owner and compliance reviewer,
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

No-go is mandatory when:

1. browser-local official sale, inventory, loyalty, store-credit, or fiscal authority is observed,
2. duplicate server sale or duplicate required consequence is produced by replay,
3. cash-collected unresolved records can be deleted or hidden without resolution,
4. local persistence failure can still show capture success,
5. cross-tenant, cross-branch, or wrong-terminal sync is possible,
6. statutory discount, non-cash payment, void, refund, or dining mutation can be completed offline,
7. provisional acknowledgment can reasonably be mistaken for official invoice,
8. hardware readiness is claimed without evidence.

Immediate pilot-stop triggers:

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

Pilot-stop status is separate from final `pilot_failed` until the release owner evaluates containment.

No-go or pilot-stop containment must record evidence that:

1. new offline capture was disabled,
2. existing queue records were preserved,
3. branch and support owners were notified,
4. diagnostics were extracted,
5. unresolved cash records were listed,
6. rollout expansion was frozen,
7. destructive terminal reset was blocked until evidence review completed.

### 14. Hypercare metrics and thresholds

The readiness plan must define pilot metrics scoped by:

```text
pilot
branch
terminal
binding epoch
date range
build
```

Minimum metrics:

```text
offline_capture_attempts
durable_capture_successes
capture_uncertain_count
storage_failed_count
sync_accept_count
sync_replay_count
retryable_failure_count
review_required_count
rejected_count
average_sync_delay
maximum_sync_delay
unresolved_cash_count
oldest_unresolved_cash_age
loyalty_pending_count
loyalty_failed_count
inventory_review_count
printer_failure_count
drawer_failure_count
support_incident_count
```

Minimum first-release thresholds:

```text
duplicate sale count = 0
lost cash-collected record count = 0
cross-scope posting count = 0
capture false-success count = 0
Severity 1 incidents = 0
unresolved cash beyond agreed SLA = 0
```

Other thresholds may be set by pilot scope, but they must be defined before pilot execution.

### 15. Test-data and reset policy

Each scenario must identify whether it uses:

1. isolated test tenant,
2. pilot production-like tenant,
3. seeded product and inventory,
4. actual branch cash,
5. simulated payment,
6. reusable or single-use transaction identity.

No scenario may accidentally replay a prior UAT envelope as fresh evidence. If a scenario intentionally replays an envelope, the replay purpose and expected idempotent result must be explicit.

### 16. Evidence retention classes

Allowed retention classes:

```text
release_record
pilot_operational
sensitive_diagnostic
hardware_validation
temporary_test
```

Definitions:

1. `release_record` is retained with the release decision.
2. `pilot_operational` is retained through the pilot plus configured support period.
3. `sensitive_diagnostic` uses short retention with restricted access.
4. `hardware_validation` is retained while the hardware configuration remains approved.
5. `temporary_test` is deleted after review and approval.

Retention must account for customer data, diagnostic sensitivity, and support obligations.

## Defect Severity Model

| Severity | Blocks rollout? | Examples |
| --- | --- | --- |
| Severity 1 - Critical | Yes, cannot be waived | Cross-tenant exposure, duplicate accepted sale, browser-local fiscal authority, lost cash-collected envelope, official invoice misrepresentation |
| Severity 2 - High | Yes unless explicitly waived by all required authorities | Sync drift posts mutation, strict-stock classification wrong, review-required retry loop, cashier ownership overwritten, diagnostics cannot locate unresolved cash |
| Severity 3 - Medium | Conditional | Confusing cashier wording, non-blocking diagnostics gap, recoverable hardware classification issue |
| Severity 4 - Low | No | Cosmetic issue, documentation typo, non-critical wording polish |

Severity 1 defects cannot be waived. A Severity 1 defect requires `pilot_failed`, `no_go`, or continued containment until fixed and retested.

Severity 2 waivers require:

1. release owner,
2. engineering owner,
3. compliance reviewer when fiscal, customer, or cash exposure is involved,
4. business owner for operational risk,
5. expiry date,
6. branch and terminal scope,
7. compensating control,
8. retest commitment.

Waivers require:

1. waived by,
2. waiver reason,
3. risk acceptance,
4. expiry or follow-up date,
5. compensating control,
6. affected branches and terminals.

Defects, observations, deferrals, and waivers are distinct:

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

Hardware unavailability is normally a deferral, not a waiver. A failed requirement accepted for limited pilot operation is a waiver.

## Scenario Status Values

Allowed scenario statuses:

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

Every `failed`, `blocked`, `waived`, or `passed_with_observation` scenario must reference a defect, observation, waiver, or deferral record.

`passed_with_observation` means the expected result was met and no contract violation occurred, but a non-blocking operational or documentation concern was observed. It must not be used to hide a failed requirement.

`blocked` means the scenario could not execute because an entry prerequisite, environment dependency, hardware item, or prior defect prevented execution. A blocked critical scenario is not a pass and normally prevents `pilot_passed_with_deferrals` unless explicitly classified as an approved deferral.

## Implementation Plan

### Slice 1 - Create validation artifacts

1. Create `docs/validation/epic-41-pilot-uat-readiness.md`.
2. Create `docs/validation/epic-41-release-decision-record.md`.
3. Add scenario catalog sections or create `docs/validation/epic-41-scenario-catalog.md`.
4. Add evidence manifest sections or create `docs/validation/epic-41-evidence-manifest.md`.
5. Add defect, observation, deferral, and waiver register sections or create `docs/validation/epic-41-defect-waiver-register.md`.
6. Add pilot execution log sections or create `docs/validation/epic-41-pilot-execution-log.md`.
7. Link validation artifacts from Epic 41 documentation.

### Slice 2 - Build scenario matrices

1. Create numbered UAT scenarios for offline transition behavior.
2. Create numbered UAT scenarios for sync replay, drift, conflicts, and review handling.
3. Create numbered UAT scenarios for consequence validation.
4. Create numbered UAT scenarios for hardware, storage loss, and terminal recovery.

### Slice 3 - Build evidence and signoff model

1. Define evidence manifest fields.
2. Define environment manifest fields.
3. Define pilot scope manifest fields.
4. Define scenario versioning.
5. Define role-based signoff table.
6. Define defect severity and waiver process.
7. Define release decision statuses and transitions.

### Slice 4 - Add release-gate governance

1. Add entry criteria.
2. Add go/no-go criteria.
3. Add pilot-stop triggers.
4. Add hypercare checks.
5. Add hypercare thresholds.
6. Add rollback or offline-disable reference.
7. Add containment evidence requirements.

### Slice 5 - Validate documentation and traceability

1. Confirm every Architecture Lock release-gate item is represented.
2. Confirm every Story 41.7 handoff item is represented.
3. Confirm Story 41.8 remains documentation and validation only.
4. Run markdown/link sanity checks where available.
5. Update Epic 41 README and Implementation Guide status.
6. Confirm no real customer data, credentials, or raw sensitive diagnostics are committed.

## Acceptance Criteria

### AC1 - Release-gate validation artifacts exist

`docs/validation/epic-41-pilot-uat-readiness.md` and `docs/validation/epic-41-release-decision-record.md` exist and are linked or discoverable from Epic 41 documentation.

### AC2 - Entry criteria are explicit

The UAT plan blocks pilot execution until branch, terminal, cashier, shift, catalog, permissions, feature policy, support owner, and evidence repository prerequisites are documented.

### AC3 - Offline happy path is covered

The UAT matrix validates online baseline, cached shell, cached catalog, cash-only capture, durable local write, reconnect, sync, and official invoice retrieval after server acceptance.

### AC4 - Offline failure paths are covered

The UAT matrix validates durable persistence failure, network failure, stale catalog, terminal revocation, device clock drift, browser storage loss, multiple tabs, cashier switching, and shift close with unsynced records.

### AC5 - Replay and drift are covered

The UAT matrix validates exact replay idempotency, drift rejection, suspected duplicate business capture, predecessor blocking, and review-required retry behavior.

### AC6 - Online-only operations are covered

The UAT matrix validates that non-cash payment, statutory discount, void, refund, dining mutation, stocktake, inventory adjustment, and privileged admin mutations remain blocked offline.

### AC7 - Consequence statuses are covered

The UAT matrix validates inventory, loyalty, store-credit, accounting, and fiscal consequence behavior using server-authoritative evidence and consequence-specific status fields.

### AC8 - Cash-collected unresolved records are covered

The UAT matrix validates that cash-collected or uncertain-cash records that fail posting remain preserved, visible, and resolution-gated.

### AC9 - Fiscal identity distinction is covered

The UAT matrix validates that local offline reference, server sale reference, and official invoice reference remain distinct.

### AC10 - Hardware readiness is evidence-qualified

The release decision record distinguishes validated hardware, limited browser behavior, deferred physical hardware, failed hardware, and unavailable hardware.

### AC11 - Support diagnostics are covered

The support checklist validates bounded diagnostics export and support visibility for queue health, terminal recovery, sync status, cash exposure, and consequence states.

### AC12 - Compliance wording is covered

The release gate requires signoff for provisional acknowledgment wording, official invoice distinction, and absence of certification claims.

### AC13 - Go/no-go decision is explicit

The release decision record includes final status, owner, date, signoffs, unresolved risks, waivers, deferrals, and rollback or containment reference.

### AC14 - Pilot and production readiness remain separate

The documents clearly state that engineering implementation completion, pilot validation, and production rollout approval are separate states.

### AC15 - No runtime behavior is introduced

The implementation changes only documentation and validation artifacts unless a separate approved story authorizes runtime code.

### AC16 - Evidence identifies the tested build

Given a scenario is marked passed, when its evidence is reviewed, then the application build, commit, environment, queue schema, sync contract, and service-worker version are identifiable.

### AC17 - Scenario IDs and versions are stable

Given evidence or a defect references a scenario, when the scenario is later revised, then the original scenario ID and version remain traceable.

### AC18 - Severity 1 cannot be waived

Given a Severity 1 defect exists, when the release decision is evaluated, then `go_approved` is prohibited.

### AC19 - Deferral and waiver remain distinct

Given an unexecuted scenario and a known failed requirement, when governance records are created, then the unexecuted scenario is recorded as a deferral and the accepted failure as a waiver.

### AC20 - Critical evidence has independent review

Given a Severity 1 or Severity 2 risk scenario is executed, when its evidence is accepted, then the executor is not the sole reviewer.

### AC21 - Release decision history is immutable

Given a decision is signed, when it is corrected, superseded, or revoked, then a new decision record is created and the prior record remains unchanged.

### AC22 - Pilot-stop triggers are executable

Given a mandatory stop condition occurs, when the pilot is contained, then offline capture is disabled without deleting existing queue evidence and containment evidence is recorded.

### AC23 - Hypercare thresholds are explicit

Given a pilot is considered for approval, when hypercare evidence is reviewed, then each required metric has a defined threshold and measured result.

### AC24 - Hardware evidence is configuration-bound

Given hardware evidence is accepted, when the device, OS, adapter, connection, or receipt configuration changes, then the prior evidence is marked invalid or requires revalidation.

### AC25 - Passed evidence is reviewed evidence

Given an artifact was captured, when a scenario is marked passed, then its evidence status is `accepted`, not merely `captured`.

## Required Review Checklist

1. Confirm the story does not add runtime behavior.
2. Confirm UAT scenarios map to Stories 41.1 through 41.7.
3. Confirm cash-only first-release boundary is preserved.
4. Confirm provisional acknowledgment does not imply official invoice.
5. Confirm hardware claims require physical evidence.
6. Confirm cash-collected unresolved records cannot be hidden.
7. Confirm support diagnostics are bounded and evidence-safe.
8. Confirm release decision requires named owner and signoffs.
9. Confirm deferrals and waivers require expiry or follow-up owner.
10. Confirm the release gate can produce `go_approved`, `no_go`, or `deferred` without ambiguity.
11. Confirm environment and build identity are required for accepted evidence.
12. Confirm Severity 1 waivers are prohibited.
13. Confirm Severity 2 waivers require all required authorities.
14. Confirm evidence status must be `accepted` before a scenario can pass.
15. Confirm signed release decisions are immutable and superseded through new records.

## Definition of Done

Story 41.8 is done when:

1. Pilot UAT readiness document exists.
2. Release decision record exists.
3. Scenario matrix covers all acceptance criteria.
4. Evidence manifest schema is included.
5. Environment manifest schema is included.
6. Pilot scope manifest schema is included.
7. Scenario ID and versioning contract is included.
8. Evidence review status model is included.
9. Defect, observation, deferral, and waiver model is included.
10. Severity 1 non-waivability is included.
11. Severity 2 waiver authority is included.
12. Role-based signoff matrix is included.
13. Hardware validation/deferment and expiry model is included.
14. Compliance wording signoff scope is included.
15. Release-state transitions and immutable decision history are included.
16. Hypercare metrics and thresholds are included.
17. Pilot-stop triggers and containment evidence are included.
18. Epic 41 README and Implementation Guide status are updated.
19. Local documentation review passes.
20. Code review confirms no runtime behavior was added.

## Out of Scope

1. New offline transaction behavior.
2. New server sync behavior.
3. New POS UI behavior.
4. New payment, inventory, loyalty, store-credit, accounting, or fiscal mutation logic.
5. Production deployment automation.
6. External BIR, CPA, or certification claims.
7. Enabling branch rollout without executed pilot evidence.

## Implementation Notes

1. Prefer existing `docs/validation` patterns over a new validation directory.
2. Keep the release record template blank for execution evidence; do not mark pilot results as passed before UAT is actually run.
3. Use aliases or masked identifiers in documentation examples unless real pilot evidence is intentionally captured under retention policy.
4. Treat physical hardware as deferred when hardware is unavailable. Do not convert a browser print dialog test into a physical printer readiness claim.
5. Keep scenario identifiers stable so defects and evidence can reference them.
6. Keep production rollout approval separate from CI, local tests, and story implementation completion.

## Final Planning Note

This story is the final Epic 41 release gate. The validation pack has been created, but that does not mean offline terminal behavior is pilot-proven or production-approved.

## Implementation Output

Created validation artifacts:

1. `docs/validation/epic-41-pilot-uat-readiness.md`
2. `docs/validation/epic-41-release-decision-record.md`
3. `docs/validation/epic-41-scenario-catalog.md`
4. `docs/validation/epic-41-evidence-manifest.md`
5. `docs/validation/epic-41-defect-waiver-register.md`
6. `docs/validation/epic-41-pilot-execution-log.md`

Pilot execution status remains pending. Production rollout approval remains separate from this implementation.
