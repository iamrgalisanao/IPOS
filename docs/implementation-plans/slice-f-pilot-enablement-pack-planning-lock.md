# Slice F Planning Lock: Pilot Enablement Pack

Status: Proposed Scope Lock
Date: 2026-05-25
Parent Plan: `docs/roadmap/market-readiness-inventory-operations-priority-plan.md`

## 1. Purpose

Define the planning lock for a pilot enablement pack that converts completed
inventory capabilities into training-safe, demo-ready, and supportable branch
materials without adding runtime system behavior.

## 2. Scope Boundary

This task is planning-only.

Do not implement:

1. Any backend or frontend feature behavior changes.
2. Inventory mutation workflows or automation.
3. Tax, receipt, Z-read, GCT, e-journal, accounting, or offline engine changes.
4. Tenant provisioning, subscription, or feature-gating behavior changes.
5. BIR certification/accreditation claims.

## 3. Baseline Existing Surface

Completed capability slices available for enablement assets:

1. Slice B: Inventory Hub (read-only navigation surface).
2. Slice C: Print-friendly stocktake and inventory report views.
3. Slice D: Low-stock/reorder read-only dashboard.
4. Slice E: Branch stock movement summary.

Current limitation:

1. No single planning-locked, training-safe enablement pack ties these
   completed capabilities into a branch onboarding and demo workflow.

## 4. Target Scope for Slice F

Target deliverables after lock acceptance:

1. Training-safe screenshot pack covering Inventory Hub and key report/dashboard
   states.
2. Branch manager demo script for pilot walkthrough.
3. Pilot checklist addendum for inventory operations onboarding.
4. Support escalation and rollback reference notes for pilot operators.
5. Clear artifact inventory with ownership and update cadence.

## 5. Candidate Artifact Locations

Preferred output locations (to be finalized on acceptance):

1. `docs/user-enablement/` for scripts, checklist addendum, and usage notes.
2. `docs/validation/` for closure evidence summary.
3. `docs/reports/` only if comparative presentation notes are required.

## 6. Data Privacy and Compliance Boundaries

Required controls for all screenshots and examples:

1. Use staging/training data only.
2. No production customer, employee, payment, or identifying data.
3. No secrets, tokens, credentials, or internal endpoints exposed.
4. Avoid any wording that implies formal accreditation/certification.
5. Keep all examples consistent with current approved market positioning.

## 7. Information Architecture Rules

1. Keep enablement assets additive to existing docs; do not replace system
   source-of-truth behavior documents.
2. Ensure screenshots map to currently implemented UI states only.
3. Use role-oriented flow ordering (branch manager first, staff reference
   second).
4. Separate demo narrative from operational incident handling notes.
5. Include explicit boundary disclaimers where relevant.

## 8. Acceptance Criteria

Implementation may proceed only if:

1. Planned artifacts are documentation-only and screenshot-only.
2. Screenshot capture plan explicitly confirms non-production, sanitized data.
3. Demo script covers Inventory Hub, print-friendly reports, low-stock/reorder
   visibility, and movement summary visibility.
4. Pilot checklist addendum includes preparation, run, and post-demo validation
   checkpoints.
5. Escalation and rollback notes include owner, trigger condition, and response
   path.
6. Governance updates preserve non-goal boundaries and no-claim language.

## 9. Non-Goals

1. No new application code or feature behavior changes.
2. No automated screenshot generation pipeline.
3. No runtime telemetry or analytics instrumentation expansion.
4. No pilot branch activation, provisioning mutation, or commercial rollout
   approval.
5. No compliance/legal certification assertions.

## 10. Implementation Readiness Checklist

Before implementation starts, confirm:

1. Screens and flows to capture are enumerated and mapped to completed slices.
2. Training dataset and redaction rules are explicitly documented.
3. Target docs and naming conventions are defined.
4. Reviewer/approver roles for enablement materials are defined.
5. Closure evidence format is defined before execution.

## 11. Decision

Slice F is ready for review as a planning lock.

Implementation should begin only after this planning lock is accepted.
