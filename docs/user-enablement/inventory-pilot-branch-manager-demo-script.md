# Inventory Pilot Branch Manager Demo Script

Date: 2026-07-16
Audience: Branch managers and pilot stakeholders
Duration: 60 to 90 minutes

## 1. Opening Script

1. Introduce scope: this is an Epic 40 pilot UAT and operational-readiness walkthrough.
2. Clarify boundary: no claims of BIR certification or accreditation.
3. Clarify data source: isolated UAT data for destructive scenarios; controlled live data only after signoff.
4. Clarify inventory boundary: no direct stock reset, movement deletion, or evidence rewriting.
5. Clarify support boundary: recovery uses governed workflows and containment, not ad hoc repair.

## 2. Demo Flow

### Step 1: Pilot Scope and Entry Criteria

1. Confirm tenant, branch, terminal, product, user, and business-date scope.
2. Confirm roles and permissions.
3. Confirm baseline inventory and seed-data checks.
4. Confirm evidence repository and support contact window.

### Step 2: Inventory Hub and Setup Validation

1. Open Inventory Hub.
2. Show report and setup links.
3. Show product/recipe setup and unit conversion governance.
4. Explain that reports are read-only and mutations remain in established workflows.

### Step 3: Sale, Recipe, Offline Sync, and Replay

1. Run direct sale deduction scenario.
2. Confirm Stock Card and Current Stock values.
3. Replay the same source effect and confirm no duplicate movement.
4. Run approved offline cash sale synchronization scenario if terminal policy allows it.
5. Run recipe deduction and show ingredient lineage.

### Step 4: Exceptions, Stocktake, and Adjustments

1. Show strict negative-stock block and soft-negative exception evidence.
2. Show stocktake count-start watermark, movement-during-count handling, and posting evidence.
3. Show adjustment approval-required and denied scenarios.
4. Explain that denied adjustments create no movement.

### Step 5: Reports, Evidence, and Recovery

1. Open Current Stock, Stock Card, Movement Summary, Negative Stock Exceptions, Physical Count Variance, Reconciliation Exceptions, Usage Reconciliation, and Configuration and Integrity.
2. Capture screenshots/exports and update the evidence manifest.
3. Walk through containment modes and recovery classification.
4. Review go/no-go, hypercare, and exit criteria.

## 3. Key Talking Points

1. Pilot value: branch users can prove stock behavior before rollout.
2. Boundary value: inventory evidence is append-only and corrections use governed workflows.
3. Governance value: Severity 1 and Severity 2 defects block rollout.
4. Support value: containment and recovery are documented before activation.
5. Hypercare value: daily monitoring continues after go-live.

## 4. Demo Success Criteria

1. Audience can locate all pilot surfaces from Inventory Hub.
2. Audience can explain current stock versus stock card versus movement summary.
3. Audience can identify source evidence for sale, recipe, refund, void, stocktake, and adjustment effects.
4. Audience understands offline inventory mutation remains prohibited.
5. Audience understands severity, containment, and escalation path.
6. No prohibited claim language used during the walkthrough.

## 5. Closing Script

1. Summarize pilot result, unresolved defects, and residual risks.
2. Confirm go/no-go or retest decision.
3. Confirm hypercare owner and daily monitoring cadence.
4. Capture feedback and unclear steps for guide refinement.
