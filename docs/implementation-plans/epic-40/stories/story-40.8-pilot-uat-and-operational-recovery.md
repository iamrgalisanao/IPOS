# Story 40.8 Pilot UAT and Operational Recovery

## 1. Status

Done

Date: 2026-07-16

## 2. Objective

Validate the complete Epic 40 inventory control chain in a pilot-ready workflow and produce the operational recovery documentation needed for branch rollout.

Story 40.8 is not a new inventory feature story. It is the readiness, UAT, and recovery story that proves Stories 40.1 through 40.7 work together as one operational system.

## 3. User Story

As an implementation lead, branch manager, inventory controller, or support operator,
I want a pilot UAT checklist and recovery playbook for the hardened inventory lifecycle,
so that the team can validate inventory setup, sales deductions, recipe usage, stocktake reconciliation, adjustments, reports, and recovery paths before enabling Epic 40 behavior in a real branch.

## 4. Architecture Alignment

This story validates the architecture defined in:

```text
docs/implementation-plans/epic-40/epic-40-architecture-lock.md
docs/implementation-plans/epic-40/epic-40-implementation-guide.md
```

Non-negotiable constraints:

1. Inventory remains the owner of branch stock state, movement history, variance evidence, unit conversion resolution, stocktake evidence, adjustment evidence, and operational inventory reports.
2. Inventory does not own sale creation, payment settlement, refund authority, accounting liability, product pricing, tax, receipt compliance, or procurement approval.
3. `branch_inventories.current_stock` remains operational state and must be explainable by movement and reconciliation evidence.
4. `inventory_movements` remain append-only.
5. Variance categories remain distinct:
   - negative-stock exception,
   - physical count variance,
   - system reconciliation exception,
   - configuration gap.
6. Stocktake posting remains the reconciliation event.
7. Manual adjustments remain governed by reason and authorization policy.
8. Reports are read-only evidence projections.
9. Offline inventory mutation remains prohibited.
10. Story 40.8 must not introduce mutation behavior disguised as UAT tooling.

## 5. Existing Implementation Context

Story 40.8 depends on the implemented Epic 40 surfaces:

| Story | Capability to validate | Existing implementation areas |
| --- | --- | --- |
| 40.1 | Movement evidence and reconciliation foundation | `InventoryMovement`, `InventoryMovementRecorder`, movement sequence, before/change/after fields |
| 40.2 | Unit conversion governance | `UnitConversion`, `UnitConversionGovernanceService`, `UnitConversionResolver`, unit conversion UI |
| 40.3 | Negative-stock exception lifecycle | `InventoryVarianceLog`, `InventoryVarianceLifecycleService`, variance log UI and export |
| 40.4 | Recipe deduction snapshot integrity | `ProductRecipe`, `RecipeDeductionService`, recipe snapshots in movement metadata |
| 40.5 | Stocktake reconciliation | `StocktakeSession`, `StocktakeLine`, `StocktakePostingService`, stocktake summary/export |
| 40.6 | Adjustment authorization | `InventoryAdjustmentService`, `InventoryAdjustmentReasonService`, adjustment approval service |
| 40.7 | Reporting and audit evidence | `Inventory/Reports` controllers/services, report hub, CSV export, report watermarks |

Existing user-enablement documents to reconcile or extend:

```text
docs/user-enablement/inventory-pilot-branch-walkthrough-run-sheet.md
docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md
docs/user-enablement/inventory-pilot-checklist-addendum.md
docs/user-enablement/inventory-pilot-branch-manager-demo-script.md
docs/user-enablement/inventory-pilot-screenshot-capture-pack.md
docs/user-guide/04-module-guides/inventory.md
```

If the older `docs/user-enablement/inventory-pilot-escalation-and-rollback-notes.md` file exists, implementation should rename or migrate it to `docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md` and update references rather than maintaining two competing recovery documents.

## 6. Scope

### 6.1 In Scope

1. Create or update a pilot UAT checklist for the full Epic 40 chain.
2. Create or update an operational recovery playbook.
3. Create a support diagnostics checklist for inventory evidence investigation.
4. Define pilot seed-data expectations and validation scenarios.
5. Define UAT signoff criteria by role.
6. Define runbook steps for:
   - sale deduction,
   - recipe deduction,
   - conversion resolution,
   - negative-stock exception,
   - refund return,
   - void reversal,
   - stocktake activity during count,
   - stocktake posting,
   - manual adjustment approval and denial,
   - movement/current-stock reconciliation,
   - report/export evidence.
7. Define regression tests or feature tests that prove the UAT readiness endpoints/pages remain reachable and read-only.
8. Update user guide and enablement docs to reflect implemented Epic 40 behavior.
9. Define pilot entry, go/no-go, hypercare, and exit criteria.
10. Define defect severity, scenario disposition, waiver, and retest rules.
11. Define evidence privacy, storage, ownership, and retention expectations.
12. Mark Epic 40 implementation artifacts as ready for pilot after review and validation.

### 6.2 Out of Scope

1. New inventory mutation logic.
2. New stocktake posting behavior.
3. New adjustment approval rules.
4. New unit conversion behavior.
5. New recipe deduction behavior.
6. New procurement automation.
7. Accounting, costing, valuation, or COGS reporting.
8. Production deployment automation.
9. Offline inventory mutation or offline inventory report caching.
10. External ERP/accounting integration.
11. Background report exports.
12. Feature-flag framework redesign.
13. Data repair scripts that mutate production stock automatically.
14. Deleting, rewriting, or directly rolling back committed inventory evidence.

## 7. Locked Decisions

### 7.1 Pilot Readiness Is Documentation and Validation First

Story 40.8 produces operational readiness artifacts and validation checks.

It may add read-only readiness pages, links, tests, or documentation, but it must not create new inventory correction workflows.

### 7.2 Recovery Is Guided, Not Automatic

Recovery playbooks should guide authorized users to existing workflows:

| Problem | Recovery path |
| --- | --- |
| Incorrect conversion setup before sale | Deactivate/version conversion rule, validate future deductions, investigate historical movement snapshots |
| Negative stock from soft policy | Review Negative Stock Exceptions, link correction, perform governed receiving/stocktake/adjustment where appropriate |
| Stocktake mismatch | Use stocktake summary, movement watermarks, and posted correction evidence |
| Manual adjustment denied | Review approval rule/reason threshold; do not bypass approval |
| Movement/current-stock mismatch | Use Reconciliation Exceptions and Stock Card; escalate if integrity exception persists |
| Recipe lineage investigation | Use Product Composition, Stock Card, movement recipe snapshots, and sale source reference |

The system must not silently repair inventory as part of UAT.

### 7.3 Pilot Evidence Must Be Screenshot and Export Ready

The UAT pack should identify which screenshots or exports prove each scenario.

Required evidence examples:

1. Inventory Hub before and after pilot.
2. Current Stock report with revision/watermark metadata.
3. Stock Card for selected branch/product.
4. Movement Summary for pilot period.
5. Negative Stock Exception report row.
6. Physical Count Variance or Stocktake Summary.
7. Reconciliation Exception or reconciled proof.
8. Usage Reconciliation row showing expected evidence status.
9. Configuration and Integrity report.
10. CSV export with matching filters.

### 7.4 Role-Based UAT Is Required

UAT must identify expected role behavior:

| Role | Expected validation |
| --- | --- |
| Branch manager | Can view operational inventory reports and stocktake progress |
| Inventory controller | Can perform stocktake and permitted adjustments |
| Cashier/POS operator | Cannot mutate inventory directly outside POS sale/refund/void flows |
| Auditor/Admin | Can export audit/integrity reports and inspect variance lifecycle |
| Support | Can trace evidence across movements, variance logs, stocktake, and reports |

### 7.5 No Offline Inventory Mutation

UAT must explicitly validate that inventory mutation remains online-authoritative.

If a pilot terminal is offline, the expected first-release behavior is:

```text
inventory mutation unavailable or blocked
```

unless an already-approved POS flow performs server-side inventory mutation after connectivity is restored.

### 7.6 Pilot Completion Requires Evidence, Not Verbal Confirmation

A pilot is not complete unless the UAT record includes:

1. scenario result,
2. actor/role,
3. branch,
4. timestamp,
5. source reference,
6. report/export/screenshot evidence,
7. pass/fail status,
8. unresolved issue owner,
9. retest result when needed.

### 7.7 UAT and Live Pilot Are Separate Stages

Controlled UAT and controlled live pilot validation are distinct activities.

Controlled UAT may use isolated or synthetic data to exercise destructive edge cases, replay paths, and failure conditions.

Controlled live pilot validation must use approved branch operating scenarios and must not deliberately create destructive business activity unless the branch, implementation lead, and product owner have approved the scenario.

### 7.8 Application Rollback Does Not Rewrite Inventory Evidence

Application rollback and inventory-data reversal are separate concepts.

Application rollback or containment may:

1. disable feature flags,
2. stop new governed inventory actions,
3. restore a previous application release,
4. return branch users to the approved previous operating workflow.

Application rollback must not:

1. delete inventory movements,
2. rewrite movement snapshots,
3. reset `branch_inventories.current_stock` directly,
4. delete stocktake corrections,
5. remove variance evidence,
6. reverse successful business transactions outside governed source workflows.

Committed inventory evidence remains append-only and must be corrected only through approved business workflows.

### 7.9 Severity 1 and Severity 2 Defects Block Rollout

Unresolved Severity 1 or Severity 2 defects block pilot activation, rollout, or exit approval.

Severity 3 defects may be accepted only with a named owner, documented workaround, risk acceptance, and follow-up date.

Severity 4 defects may enter the backlog if they do not affect inventory integrity, authorization, evidence, or branch operation.

### 7.10 Replay Validation Must Prove No State Drift

Exact replay scenarios must prove that replay does not change:

1. inventory movement count,
2. `branch_inventories.current_stock`,
3. inventory revision,
4. approval consumption,
5. variance or exception count.

### 7.11 Recovery Uses Classified Containment

Recovery paths must classify the issue as one of:

1. configuration,
2. operational misuse,
3. authorization,
4. data integrity,
5. software defect,
6. reporting defect,
7. training gap.

Branch users must not perform technical repairs for data integrity or software defects.

### 7.12 Pilot Evidence Is Access-Controlled

Pilot screenshots, exports, and incident evidence may include employee names, sale references, customer-related information, branch details, and internal inventory data.

The evidence pack must capture only required evidence, mask customer personal information where not needed, avoid credentials or tokens, identify an evidence owner, define retention, and restrict audit exports to authorized recipients.

### 7.13 Documentation Validation Is Separate From Runtime Behavior

Application tests validate route reachability, permissions, report behavior, integrated workflows, and no-mutation guarantees.

Repository or CI validation may verify that required markdown documents, links, scenario IDs, and headings exist.

Production application behavior must not depend on markdown documentation files.

### 7.14 Containment Claims Must Match Actual Enforcement

Story 40.8 may document unsupported containment modes for future implementation, but it must not claim that a mode is system-enforced unless the relevant mutation paths already honor it.

Each containment mode must be classified by `containment_enforcement_type`:

```text
system_enforced
feature_flag_enforced
operational_procedure
unavailable
```

### 7.15 Database Restore Is Disaster Recovery, Not Stock Correction

Database backup restoration is reserved for platform-level disaster recovery and is not an operational method for correcting individual inventory discrepancies.

Routine inventory discrepancies use stocktake, governed adjustment, receiving, refund, void, or approved source workflows.

Story 40.8 may record backup owner, last verified backup, restore-test status, RPO, and RTO, but it must not design new backup automation.

## 8. Pilot Governance and Execution Controls

### 8.1 Pilot Entry Criteria

UAT or pilot activation cannot begin until every mandatory entry criterion is satisfied:

1. Stories 40.1 through 40.7 are deployed to the pilot environment.
2. Required migrations completed successfully.
3. Feature flags and tenant/branch configuration are documented.
4. Pilot branch, users, terminals, products, and recipes are identified.
5. All required roles and permissions are provisioned.
6. Pilot seed data has passed configuration-gap validation.
7. Opening or migration inventory baseline is established.
8. Backup and recovery ownership is confirmed.
9. Known defects are documented and classified.
10. Test evidence repository is ready.
11. Support contacts and escalation hours are confirmed.
12. Pilot date and observation window are approved.

### 8.2 Pilot Scope Record

Every UAT or pilot execution record must identify:

1. `pilot_tenant_id`,
2. `pilot_branch_ids`,
3. `pilot_terminal_ids`,
4. `pilot_business_dates`,
5. `pilot_product_scope`,
6. `pilot_user_scope`,
7. `pilot_start_at`,
8. `pilot_end_at`,
9. pilot mode.

Allowed pilot modes:

| Mode | Meaning |
| --- | --- |
| `isolated_simulation` | Controlled test environment or isolated dataset. Destructive edge cases are allowed. |
| `parallel_run_branch` | Branch validates Epic 40 behavior beside an approved current workflow. |
| `controlled_live_branch` | Approved branch uses Epic 40 behavior for defined live operating scenarios. |

### 8.3 Execution Stages

Stage 1 - Controlled UAT:

1. runs in a test environment or isolated pilot dataset,
2. permits destructive edge cases,
3. validates replay and failure scenarios,
4. uses known expected quantities,
5. captures screenshots, exports, and actual results.

Destructive UAT means business operations that intentionally create shortages, denied approvals, failed conversions, refunds, voids, or controlled discrepancies through supported application workflows.

It does not authorize direct editing, deletion, or corruption of inventory records.

A deliberate integrity-mismatch drill may use a prepared fixture, migration-generated test state, isolated database snapshot, or test-only controlled harness approved by engineering.

Stage 2 - Controlled Live Pilot:

1. uses an approved branch,
2. runs during approved operating hours,
3. limits product or workflow scope where needed,
4. avoids artificial destructive transactions unless business-approved,
5. requires daily reconciliation and monitoring,
6. requires active support coverage.

Production smoke tests after deployment but before branch activation should validate authentication, branch context, Inventory Hub, report routes, reason and conversion lookup, read-only reconciliation, permissions, and feature flags.

Smoke tests must not create live stock movements unless a controlled test item and governed cleanup workflow are approved.

### 8.4 Defect Severity Rules

| Severity | Definition | UAT disposition |
| --- | --- | --- |
| Severity 1 - Critical | Stock changes without canonical movement, cross-tenant/branch exposure, duplicate sale or adjustment deduction, movement/current-stock corruption, unauthorized inventory mutation, or inability to stop unsafe behavior. | Immediate no-go or pilot stop. |
| Severity 2 - High | Incorrect conversion or recipe deduction, stocktake posting miscalculation, approval bypass, materially false report/export evidence, or refund/void over-restoration. | No-go unless fixed and fully retested. |
| Severity 3 - Medium | Non-critical report/filter issue, confusing workflow, recoverable permission defect, or incomplete non-canonical audit display. | Conditional go only with owner and workaround. |
| Severity 4 - Low | Cosmetic or documentation issue. | May enter backlog. |

### 8.5 Scenario Status and Waiver Rules

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
```

Waivers are not informal. A waived scenario must record:

1. `waived_by`,
2. `waiver_reason`,
3. `risk_acceptance`,
4. `expiry_or_follow_up_date`.

### 8.6 Go/No-Go Criteria

Go criteria:

1. All Severity 1 and Severity 2 defects are closed and retested.
2. No movement/current-stock mismatch remains unexplained.
3. No cross-tenant or cross-branch access issue exists.
4. Replay scenarios pass.
5. Sale, recipe, refund, void, stocktake, and adjustment evidence reconcile.
6. Required roles complete training.
7. Recovery and escalation contacts are confirmed.
8. Audit exports pass permission and safety tests.
9. Pilot branch manager signs operational readiness.
10. Implementation, support, and product owners sign technical readiness.

No-go conditions:

1. Duplicate or missing movement effects.
2. Current stock cannot be explained.
3. Approval can be bypassed.
4. Stocktake can post an incorrect correction.
5. Historical evidence changes after configuration edits.
6. Branch isolation fails.
7. Support cannot identify a containment path.
8. Required evidence is missing.

### 8.7 Formal Signoff Matrix

| Role | Signoff responsibility |
| --- | --- |
| Product owner | Scope and accepted behavior |
| Engineering lead | Technical readiness |
| QA/UAT lead | Test completion |
| Implementation lead | Branch readiness |
| Branch manager | Operational acceptance |
| Inventory controller | Inventory workflow acceptance |
| Support lead | Recovery and escalation readiness |
| Security/auditor where applicable | Permission and evidence review |

### 8.8 Containment Modes

| Mode | Behavior | First-release enforcement |
| --- | --- | --- |
| `normal` | Pilot continues under approved operating rules. | Existing application behavior |
| `observe_only` | Continue collecting diagnostics; do not enable newly introduced optional actions. Existing core POS sales behavior follows approved policy. | Operational procedure or existing feature flag |
| `inventory_mutation_suspended` | Block manual adjustment and stocktake posting; permit only explicitly approved source flows while support investigates. | System-enforced only if all governed mutation routes support it |
| `reporting_only` | Inventory reports remain available; discretionary inventory mutation is blocked. | Feature-flag, access-policy, or operational procedure |
| `pilot_stopped` | Pilot branch returns to the approved previous operating mode and evidence is preserved for investigation. | Operational containment plus application rollback where approved |

The UAT record must identify for each mode:

1. who authorizes it,
2. who executes it,
3. whether it is technically enforced,
4. which workflows are blocked,
5. which workflows remain permitted,
6. how normal operation is restored.

### 8.9 Post-Go-Live Hypercare

After pilot activation, a configurable observation window must be recorded.

Daily hypercare checks must include:

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

Numeric reconciliation tolerance:

```text
abs(current_stock - movement_derived_stock) < 0.0001
```

This tolerance applies only when an authoritative baseline exists.

Operational alert thresholds are configurable and may include:

1. `open_negative_stock_exception_count`,
2. `unresolved_reconciliation_exception_count`,
3. `duplicate_source_effect_count`,
4. `failed_stocktake_post_count`,
5. `failed_adjustment_post_count`,
6. `movement_sync_latency`.

Immediate pilot-stop conditions:

1. cross-tenant or cross-branch exposure,
2. unauthorized stock mutation,
3. duplicate committed stock effect,
4. missing canonical movement after stock change,
5. unexplained current-stock mismatch,
6. incorrect stocktake correction,
7. approval bypass,
8. historical evidence mutation.

### 8.10 Pilot Exit Criteria

A pilot is complete only when:

1. required observation window is completed,
2. no unresolved Severity 1 or Severity 2 defect exists,
3. daily reconciliation remains within approved tolerance,
4. no unexplained inventory revision or movement drift exists,
5. support tickets are reviewed and categorized,
6. branch users demonstrate required workflows,
7. at least one recovery drill succeeds,
8. evidence pack is complete,
9. remaining risks have named owners,
10. rollout recommendation is approved.

Pilot outcome must be classified as:

```text
ready_for_rollout
extend_pilot
conditional_rollout
pilot_failed
```

`conditional_rollout` is permitted only for accepted Severity 3 or Severity 4 defects. It is prohibited when any Severity 1 or Severity 2 defect remains unresolved.

Conditional rollout requires:

1. conditions,
2. owner,
3. deadline,
4. monitoring requirement,
5. rollback or containment trigger.

The readiness pack must include a known-issues and residual-risk register:

1. `risk_id`,
2. description,
3. severity,
4. affected story,
5. affected branch or scope,
6. workaround,
7. owner,
8. target resolution,
9. pilot impact,
10. rollout impact,
11. accepted by.

## 9. Required UAT Scenarios

### 9.1 Scenario Record Template

Every scenario must include:

1. `scenario_id`,
2. source story,
3. source acceptance criteria,
4. test data,
5. preconditions,
6. steps,
7. expected result,
8. required evidence,
9. actual result,
10. status,
11. defect IDs,
12. initial current stock,
13. expected movement delta,
14. expected final current stock,
15. expected inventory revision,
16. expected movement count,
17. expected exception count.

Coverage planning fields:

1. `required_execution_count`,
2. `successful_execution_count`,
3. branches covered,
4. terminals covered,
5. users or roles covered,
6. business days covered.

Replay scenarios must additionally record:

1. movement count before replay,
2. movement count after replay,
3. inventory revision before replay,
4. inventory revision after replay,
5. exception count before replay,
6. exception count after replay.

Dual-control scenarios must preserve both actors. At minimum, this applies to adjustment requester/approver, stocktake counter/poster where role policy requires separation, go-live signoff, and reconciliation exception review.

Required seed-data categories:

1. direct inventory product,
2. recipe product,
3. ingredient product,
4. fractional conversion,
5. strict-policy product,
6. soft-negative product,
7. approval-required adjustment reason,
8. zero-variance stocktake product,
9. positive-variance stocktake product,
10. negative-variance stocktake product,
11. legacy-evidence fixture,
12. missing-configuration fixture.

Cleanup rules:

1. Test-only sales should be voided/refunded through governed flows.
2. Test-only stocktakes remain as evidence in isolated environments.
3. Movements must not be deleted merely to reset UAT.
4. Live pilot evidence must be retained according to operational policy.
5. Synthetic test identifiers must be clearly marked.

At minimum, the pilot plan should repeat direct sale deduction, recipe deduction, refund replay, void replay, approval-required adjustment, movement-aware stocktake, and report export scenarios. Exact sample size is approved in the pilot plan rather than hard-coded in this story.

Example scenario traceability:

```text
UAT-40-004
Source: Story 40.3 AC9, AC10, AC12
Scenario: Soft-negative sale creates movement and exception atomically
```

### 9.2 Setup Validation

Validate:

1. Inventory-tracked product has branch inventory.
2. Product base unit is correct.
3. Unit conversion rules resolve as expected.
4. Product-specific conversion wins over tenant-wide conversion.
5. Historical conversion version remains visible after a rule change.
6. Recipe ingredient has branch inventory.
7. Adjustment reasons are active and direction-aware.
8. Approval thresholds match pilot branch policy.

Evidence:

1. Unit conversion page screenshot.
2. Product/recipe setup screenshot.
3. Configuration and Integrity report.

### 9.3 Sale Deduction and Replay

Validate:

1. Paid sale creates inventory movement rows.
2. Movement rows have branch sequence, before/change/after, business date, source reference, and source effect key.
3. Replaying the sale deduction does not duplicate movements.
4. Current stock matches movement-derived stock.
5. Stock Card shows the sale deduction row.

Evidence:

1. Sale reference.
2. Stock Card.
3. Current Stock report.
4. Reconciliation Exception report.

### 9.4 Offline Sale Synchronization

Validate:

1. Terminal becomes offline.
2. Approved offline cash sale is queued when the terminal policy allows it.
3. No server inventory movement exists while the transaction has not synchronized.
4. No local action is presented as authoritative inventory state.
5. On reconnection, the sale synchronizes.
6. The server creates the correct product or recipe movement.
7. Repeated synchronization does not duplicate the movement.
8. Current stock and movement-derived stock reconcile.
9. Card, e-wallet, void, refund, adjustment, and stocktake mutation remain blocked offline according to approved policy.

Evidence:

1. Local queue reference.
2. Server sale reference.
3. Sync status.
4. Movement count before sync.
5. Movement count after sync.
6. Inventory revision after sync.
7. Stock Card.

This scenario validates that eventual server-side mutation remains idempotent and authoritative. It does not introduce offline inventory mutation.

### 9.5 Recipe Deduction Lineage

Validate:

1. Composite product sale deducts configured ingredient quantities.
2. Ingredient movement references parent product and sale item.
3. Recipe and conversion snapshots are preserved.
4. Later recipe edits do not change historical movement evidence.
5. Product Composition report and Stock Card can explain lineage.

Evidence:

1. Product Composition report.
2. Stock Card ingredient row.
3. Movement detail/export with recipe/conversion snapshot.

### 9.6 Negative Stock Exception

Validate:

1. Strict policy blocks insufficient stock.
2. Soft-negative policy permits sale only when variance evidence is created atomically.
3. Negative Stock Exception report shows incremental shortage, resulting negative quantity, current status, severity, age, and correction link count.
4. Variance lifecycle actions remain separate from report generation.
5. Branch-limited user cannot see another branch exception.

Evidence:

1. POS result or blocked sale result.
2. Negative Stock Exception report.
3. Variance log detail.
4. Audit event where applicable.

### 9.7 Void and Refund Inventory Restoration

Validate:

1. Void reversal does not over-restore stock.
2. Refund return does not over-restore stock.
3. Repeated partial refund replay does not create duplicate positive movements.
4. Movement rows reference original source.
5. Stock Card and Movement Summary explain the restoration.

Evidence:

1. Original sale.
2. Void/refund reference.
3. Stock Card.
4. Movement Summary.

### 9.8 Stocktake Activity During Count

Validate:

1. Stocktake captures count-start watermark.
2. Sale or movement during count is either blocked by policy or reflected through movement-during-count evidence.
3. Expected-at-count-time and expected-at-posting are distinct.
4. Posting creates controlled correction movement only when required.
5. Posted stocktake cannot be silently mutated.

Evidence:

1. Stocktake session.
2. Stocktake summary.
3. Physical Count Variance report.
4. Stock Card correction movement.

### 9.9 Manual Adjustment Authorization

Validate:

1. Adjustment requires structured reason.
2. Reason direction policy is enforced.
3. Notes are required when configured.
4. High-risk adjustment requires approval.
5. Denied approval creates no movement and does not change current stock.
6. Approved adjustment creates append-only movement evidence.
7. Opening balance is allowed only before prior committed movement exists.

Evidence:

1. Adjustment request/preview.
2. Approval/denial result.
3. Stock Card.
4. Audit log where applicable.

### 9.10 Reporting and Export Evidence

Validate:

1. Inventory Hub exposes operational and audit/integrity report paths.
2. Current Stock is current operational state, not historical as-of stock.
3. Stock Card requires branch and product.
4. Movement Summary uses business-date activity and captured ledger watermark.
5. System Reconciliation returns `indeterminate` when baseline is missing.
6. Usage Reconciliation does not invent expected usage when independent evidence is unavailable.
7. Audit/integrity exports require `audit_inventory`.
8. CSV exports preserve numeric negatives and neutralize formula-like text.
9. Report routes do not mutate inventory tables.

Evidence:

1. Current Stock report.
2. Stock Card.
3. Movement Summary.
4. Reconciliation Exceptions.
5. Usage Reconciliation.
6. Export audit log.

## 10. Recovery Playbook Requirements

Story 40.8 should create or update:

```text
docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md
```

The playbook must include:

1. Symptom.
2. Likely evidence source.
3. First diagnostic report.
4. Allowed operational correction path.
5. Forbidden shortcut.
6. Escalation owner.
7. Data to capture before escalation.
8. Retest steps.
9. Classification.
10. Authorized resolver.
11. Containment mode.

Required recovery scenarios:

1. Wrong unit conversion discovered after sales.
2. Recipe ingredient quantity mistake discovered after sales.
3. Negative stock exception left unresolved.
4. Stocktake posted unexpected correction.
5. Manual adjustment denied unexpectedly.
6. Current stock and movement-derived stock mismatch.
7. Missing branch inventory setup.
8. Movement chain appears discontinuous.
9. Audit export blocked due to permissions.
10. Pilot branch selected wrong context.

Recovery classification and authority:

| Classification | Allowed resolver |
| --- | --- |
| Configuration | Authorized admin |
| Operational misuse | Implementation or training lead |
| Authorization | Tenant admin |
| Data integrity | Support/engineering escalation |
| Software defect | Engineering |
| Reporting defect | Engineering or reporting owner |
| Training gap | Implementation/training lead |

Forbidden shortcuts:

1. Direct SQL update of `current_stock`.
2. Deleting or editing inventory movement rows.
3. Reusing opening balance to reset stock.
4. Creating a generic adjustment to imitate receiving or refund.
5. Marking an exception resolved without required evidence.
6. Editing posted stocktake lines.
7. Reusing another user's approval.
8. Changing current recipe or conversion to reinterpret history.
9. Deleting UAT evidence to obtain a clean result.
10. Using another branch context to bypass access restrictions.

At least one controlled recovery drill is required before pilot readiness signoff.

Example drill:

```text
Inject or select a known configuration problem
  -> identify it in Configuration Gaps
  -> stop unsafe use
  -> correct configuration through authorized workflow
  -> run controlled retest
  -> verify historical evidence unchanged
  -> close incident record
```

Deliberate corruption must not be performed in a live pilot branch.

## 11. Support Diagnostics Checklist

Create or update a support diagnostics section that asks, in order:

1. What tenant and branch?
2. Which product or ingredient?
3. What source reference: sale, refund, void, stocktake, or adjustment?
4. What is the latest branch movement sequence?
5. Does Stock Card show before/change/after?
6. Does Current Stock show expected inventory revision?
7. Is a negative-stock exception involved?
8. Is this a physical count variance or system reconciliation exception?
9. Is the product setup valid in Configuration Gaps?
10. Does the movement include conversion and recipe snapshots?
11. Is the issue reproducible with the same filters/export?
12. What screenshot/export proves the state?

The checklist must lead support toward evidence, not direct database edits.

## 12. Evidence Handling and Privacy

Pilot evidence must follow these handling rules:

1. Capture only required evidence.
2. Mask customer personal information where not needed.
3. Store evidence in an approved restricted repository.
4. Do not place production credentials or access tokens in screenshots.
5. Record evidence owner and retention period.
6. Restrict audit exports to authorized recipients.
7. Preserve evidence needed for retest, waiver, go/no-go, and exit decisions.

The evidence pack must include a manifest with:

1. `evidence_id`,
2. `scenario_id`,
3. `artifact_type`,
4. file name or location,
5. captured by,
6. captured at,
7. tenant,
8. branch,
9. source reference,
10. whether it contains sensitive data,
11. masking status,
12. retention class,
13. optional checksum,
14. reviewed by.

## 13. Documentation Deliverables

Expected documentation updates:

```text
docs/user-enablement/inventory-pilot-branch-walkthrough-run-sheet.md
docs/user-enablement/inventory-pilot-containment-and-recovery-notes.md
docs/user-enablement/inventory-pilot-checklist-addendum.md
docs/user-enablement/inventory-pilot-branch-manager-demo-script.md
docs/user-enablement/inventory-pilot-screenshot-capture-pack.md
docs/user-guide/04-module-guides/inventory.md
docs/validation/epic-40-pilot-uat-readiness.md
```

If a document already exists, update it rather than creating a competing duplicate.

The previous `inventory-pilot-escalation-and-rollback-notes.md` naming should be retired for Epic 40 inventory readiness because "rollback" is ambiguous for append-only inventory evidence.

## 14. Implementation Slices

### Slice 1 - Pilot Governance

Deliver:

1. Pilot scope.
2. Entry criteria.
3. Stage definitions.
4. Role and signoff matrix.
5. Defect severity.
6. Go/no-go criteria.
7. Containment enforcement classification.
8. Conditional rollout restrictions.
9. Residual-risk register.

### Slice 2 - UAT Execution Pack

Deliver:

1. Scenario traceability.
2. Test data.
3. Initial and expected stock values.
4. Evidence requirements.
5. Replay invariants.
6. Status and waiver rules.
7. Offline synchronization scenario.
8. Repeat/sample coverage.
9. Evidence manifest.
10. Supported-workflow-only destructive testing rules.

### Slice 3 - Recovery and Containment

Deliver:

1. Recovery playbook.
2. Support diagnostics checklist.
3. Recovery classification.
4. Containment modes.
5. Escalation ownership matrix.
6. Forbidden shortcuts list.
7. Retest instructions.
8. At least one controlled recovery drill.
9. Disaster recovery versus operational correction boundary.
10. Executable versus procedural containment matrix.

### Slice 4 - Training and Branch Enablement

Deliver:

1. Inventory user guide update.
2. Pilot walkthrough update.
3. Screenshot capture pack update.
4. Branch manager demo script update.
5. Support contact instructions.
6. Evidence privacy handling.

### Slice 5 - Pilot Activation and Hypercare

Deliver:

1. Deployment smoke test checklist.
2. Go/no-go meeting record.
3. Activation record.
4. Daily monitoring checklist.
5. Exit criteria.
6. Rollout recommendation.
7. Numeric reconciliation tolerance.
8. Alert thresholds.
9. Immediate stop conditions.
10. Daily result and incident record.

### Slice 6 - Tests and Repository Validation

Deliver:

1. Application route and permission tests.
2. Feature/regression tests for critical pilot routes:
   - Inventory Hub,
   - Current Stock,
   - Stock Card,
   - Movement Summary,
   - Negative Stock Exceptions,
   - Physical Count Variance,
   - Reconciliation Exceptions,
   - Usage Reconciliation,
   - Configuration and Integrity.
3. Integrated critical-path tests where practical.
4. No-mutation assertion for pilot/readiness routes.
5. Repository validation for required documents, internal links, scenario IDs, and headings.
6. Epic 40 readiness validation document.
7. Implementation guide status update.
8. Open-risk list.
9. Recommendation for Epic 40 closure or follow-up backlog.

## 15. Testing Requirements

Application tests should verify:

1. Inventory Hub remains reachable for authorized inventory/report users.
2. New report routes required for pilot validation are reachable.
3. Audit/integrity exports remain permission-gated.
4. Pilot/readiness validation does not mutate:
   - `branch_inventories`,
   - `inventory_movements`,
   - `inventory_variance_logs`,
   - `stocktake_sessions`,
   - `stocktake_lines`.
5. Existing inventory test suite remains green.

Repository validation or CI scripts may verify:

1. required documents exist,
2. internal links resolve,
3. referenced scenario IDs exist,
4. required headings are present.

Recommended test files:

```text
tests/Feature/Inventory/Epic40PilotReadinessTest.php
tests/Feature/Inventory/InventoryReportingAuditEvidenceTest.php
tests/Feature/Inventory/InventoryHubTest.php
tests/Feature/Inventory/StocktakeReportTest.php
tests/Feature/Inventory/InventoryAdjustmentGovernanceTest.php
```

## 16. Acceptance Criteria

Story 40.8 is accepted when:

1. Story 40.8 remains a UAT/recovery/readiness story and does not introduce new inventory mutation behavior.
2. Pilot UAT checklist covers setup validation, sale deduction, recipe deduction, negative stock, void/refund restoration, stocktake, adjustment authorization, reporting, and exports.
3. Every UAT scenario identifies required evidence.
4. Recovery playbook covers conversion errors, recipe mistakes, negative stock, stocktake mismatches, adjustment denial, reconciliation mismatch, missing setup, movement-chain issues, audit export permission issues, and wrong branch context.
5. Support diagnostics checklist traces issues through tenant, branch, product, source reference, movement sequence, reports, snapshots, and exports.
6. Role-based UAT expectations are documented.
7. Offline inventory mutation prohibition is explicitly tested or documented in the pilot checklist.
8. Existing pilot enablement documents are updated rather than duplicated.
9. Inventory user guide reflects Epic 40 implemented behavior.
10. Readiness validation document is created.
11. Readiness pages, report routes, document-navigation links, exports, and diagnostic endpoints introduced or touched by Story 40.8 do not mutate inventory state. Existing sale, refund, void, stocktake, and adjustment workflows may mutate inventory only through their established governed services during controlled UAT.
12. Existing Inventory feature tests remain green.
13. Frontend build passes if user-facing docs/navigation pages are touched.
14. Epic 40 implementation guide is updated to show Story 40.7 Done and Story 40.8 review/implementation status.
15. No Epic 40 Architecture Lock constraint is violated.
16. Pilot entry criteria block activation until mandatory readiness conditions are satisfied.
17. Application rollback or pilot stop preserves committed inventory movements and posted evidence.
18. Unresolved Severity 1 or Severity 2 defects block go/no-go approval.
19. Exact replay UAT proves movement count, current stock, inventory revision, approval consumption, and exception count remain unchanged.
20. Hypercare monitoring records daily integrity, exception, reconciliation, and support checks during the configured observation window.
21. At least one controlled recovery drill is executed and evidenced.
22. Evidence handling rules cover access, masking, ownership, retention, and audit-export recipients.
23. Pilot exit is classified as `ready_for_rollout`, `extend_pilot`, `conditional_rollout`, or `pilot_failed` with recorded rationale.
24. Every containment mode identifies whether it is system-enforced, feature-flag-enforced, procedural, or currently unavailable.
25. Approved offline sale synchronization creates server-authoritative inventory movements exactly once, and repeated synchronization does not change stock or movement count again.
26. Hypercare records integrity stop conditions, configured thresholds, incidents, and resulting containment or pilot-stop decisions.
27. `conditional_rollout` and `ready_for_rollout` are prohibited when any unresolved Severity 1 or Severity 2 defect exists.
28. Captured evidence is indexed by scenario, branch, source reference, owner, sensitivity, and retention classification.
29. Story 40.8 readiness, diagnostic, document-navigation, and export routes create no stock, movement, variance, stocktake, or adjustment mutation.
30. Routine inventory discrepancy recovery uses governed inventory workflows, and database restore is not used as an item-level correction mechanism.

## 17. Definition of Done

Done means:

1. Acceptance criteria pass.
2. Documentation deliverables are complete.
3. Required tests pass.
4. Inventory feature tests pass.
5. Frontend build passes if UI is touched.
6. No new mutation path is introduced.
7. Pilot runbook and recovery playbook are internally consistent.
8. User guide and pilot enablement docs reflect current implementation.
9. Open risks are listed with owner and recommended follow-up.
10. Epic 40 guide status is updated.
11. Local PR review is completed before commit.
12. Pilot entry, go/no-go, hypercare, and exit records are represented in the readiness pack.
13. Severity, waiver, retest, and signoff rules are documented.
14. Repository validation is separated from application runtime tests.

## 18. Non-Goals Reminder

Story 40.8 must not become:

1. Inventory valuation.
2. Procurement automation.
3. Accounting integration.
4. A data repair script story.
5. A new stock correction workflow.
6. A new report engine.
7. Offline inventory mutation.
8. Production deployment automation.
