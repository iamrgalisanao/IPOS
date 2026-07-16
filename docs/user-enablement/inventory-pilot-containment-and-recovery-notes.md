# Inventory Pilot Containment and Recovery Notes

Date: 2026-07-16
Scope: Epic 40 Story 40.8 - Pilot UAT and Operational Recovery

## 1. Purpose

This playbook defines how pilot teams contain, diagnose, and recover from inventory issues without deleting, rewriting, or directly resetting committed inventory evidence.

Application rollback and inventory-data reversal are separate concepts. Application containment may stop future activity, but committed inventory movements, stocktake corrections, and variance evidence remain append-only.

## 2. Containment Modes

| Mode | Enforcement type | Authorizes | Executes | Blocked workflows | Permitted workflows | Restore path |
| --- | --- | --- | --- | --- | --- | --- |
| `normal` | `system_enforced` | Pilot owner | Branch team | None beyond normal permissions | Approved pilot workflows | No action |
| `observe_only` | `operational_procedure` or existing feature flag | Implementation lead | Branch manager and support | New optional actions | Core POS sales per approved policy, diagnostics, reports | Implementation lead confirms return to `normal` |
| `inventory_mutation_suspended` | `system_enforced` only if mutation routes support it; otherwise `operational_procedure` | Engineering lead and support lead | Support lead | Manual adjustment, stocktake posting, discretionary inventory mutation | Explicitly approved source flows and reports | Support verifies defect closure and owner approval |
| `reporting_only` | `feature_flag_enforced`, access-policy enforced, or `operational_procedure` | Product owner and support lead | Tenant admin or support | Discretionary inventory mutation | Inventory reports and diagnostics | Product/support signoff |
| `pilot_stopped` | `operational_procedure` plus application rollback where approved | Product owner, engineering lead, branch manager | Implementation lead and support | Pilot workflow use | Previous approved operating mode and evidence capture | Formal go/no-go review |

Do not claim a mode is system-enforced unless the relevant mutation paths already honor that control.

## 3. Severity and Disposition

| Severity | Examples | Disposition |
| --- | --- | --- |
| Severity 1 - Critical | Cross-tenant or branch exposure, unauthorized stock mutation, duplicate committed stock effect, stock change without canonical movement, inability to stop unsafe behavior | Immediate no-go or pilot stop |
| Severity 2 - High | Incorrect recipe or conversion deduction, stocktake correction error, approval bypass, material report/export misstatement, refund or void over-restoration | No-go unless fixed and retested |
| Severity 3 - Medium | Recoverable permission issue, confusing workflow, non-critical report/filter problem, incomplete non-canonical audit display | Conditional go only with owner, workaround, risk acceptance, and follow-up date |
| Severity 4 - Low | Cosmetic, wording, or documentation issue | May enter backlog |

`conditional_rollout` is allowed only for accepted Severity 3 or Severity 4 defects. It is prohibited while any Severity 1 or Severity 2 defect remains unresolved.

## 4. Recovery Classification

| Classification | Allowed resolver | Recovery route |
| --- | --- | --- |
| Configuration | Authorized admin | Correct product, recipe, unit conversion, reason, role, or branch setup through approved screens |
| Operational misuse | Inventory controller or implementation lead | Retrain user, rerun approved workflow, record observation |
| Authorization | Tenant admin | Correct role or branch assignment through access-control workflow |
| Data integrity | Support and engineering escalation | Preserve evidence, stop affected activity, investigate movement/current-stock chain |
| Software defect | Engineering | Contain pilot, create defect, patch and retest |
| Reporting defect | Engineering or reporting owner | Validate source evidence, correct report projection, retest export |
| Training gap | Implementation/training lead | Update walkthrough, script, or job aid |

## 5. Required Recovery Scenarios

| Symptom | First diagnostic report | Allowed correction path | Forbidden shortcut | Retest |
| --- | --- | --- | --- | --- |
| Wrong unit conversion after sales | Configuration and Integrity, Stock Card | Deactivate/version conversion rule, validate future deductions, inspect historical snapshots | Changing current conversion to reinterpret history | Repeat controlled sale and confirm old movement snapshot unchanged |
| Recipe ingredient quantity mistake after sales | Product Composition, Stock Card | Correct recipe version for future sales, investigate historical movement evidence | Editing historical movement metadata | Run recipe deduction with corrected setup |
| Negative-stock exception unresolved | Negative Stock Exceptions | Governed receiving, stocktake, adjustment, or linked correction workflow | Marking resolved without evidence | Confirm exception lifecycle and correction link |
| Stocktake posted unexpected correction | Physical Count Variance, Stock Card | Review count-start and posting watermarks; post only governed correction if needed | Editing posted stocktake lines | Reconcile expected-at-count and expected-at-posting |
| Manual adjustment denied unexpectedly | Adjustment request/approval evidence | Review reason direction, threshold, and approver policy | Reusing another user's approval | Submit controlled request with correct authorization |
| Current stock mismatch | Reconciliation Exceptions, Stock Card | Escalate integrity case; preserve movement chain | Direct SQL update of `current_stock` | Confirm movement-derived and current stock reconcile |
| Missing branch inventory | Configuration and Integrity | Create setup through approved inventory/product setup workflow | Opening-balance reset over existing evidence | Confirm setup report clears |
| Movement chain discontinuity | Stock Card, Movement Summary | Engineering investigation | Deleting or resequencing movement rows | Confirm sequence chain remains intact |
| Audit export blocked | Integrity export permissions | Correct `audit_inventory` permission if approved | Sharing another user's export | Retest with authorized user |
| Wrong branch context | Current Stock branch filter | Correct branch context and access assignment | Using another branch context to bypass access | Retest branch-limited visibility |

## 6. Forbidden Shortcuts

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

## 7. Recovery Drill

At least one controlled drill is required before readiness signoff.

Recommended drill:

1. Select or inject a known configuration problem in an isolated environment.
2. Identify it in Configuration and Integrity.
3. Stop unsafe use through `observe_only` or stronger containment.
4. Correct configuration through an authorized workflow.
5. Run controlled retest.
6. Verify historical evidence remains unchanged.
7. Close the incident record with screenshots/exports.

Deliberate corruption must not be performed in a live pilot branch.

## 8. Disaster Recovery Boundary

Database backup restoration is reserved for platform-level disaster recovery. It is not an operational method for correcting individual inventory discrepancies.

Record these fields in the readiness pack when available:

1. backup owner,
2. last verified backup,
3. restore-test status,
4. RPO,
5. RTO.

## 9. Incident Record Fields

1. Incident ID.
2. Date/time.
3. Tenant and branch.
4. Source reference.
5. Classification.
6. Severity.
7. Containment mode.
8. Evidence IDs.
9. Owner.
10. Action taken.
11. Retest result.
12. Closure decision.
