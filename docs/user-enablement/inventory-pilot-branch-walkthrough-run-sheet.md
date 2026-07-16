# Inventory Pilot Branch Walkthrough Run Sheet

Date: 2026-07-16
Use: Story 40.8 controlled UAT and live-pilot facilitator quick-reference

## 1. Session Header

1. Date/time:
2. Branch:
3. Facilitator:
4. Observer:
5. Audience roles:
6. Pilot mode: `isolated_simulation`, `parallel_run_branch`, or `controlled_live_branch`
7. Evidence repository:
8. Support contact window:

## 2. Mandatory Opening (1-2 minutes)

1. State this is a pilot-readiness validation for Epic 40 inventory behavior.
2. Confirm whether this is controlled UAT or controlled live pilot.
3. Confirm no certification/accreditation claims.
4. Confirm no direct database correction, movement deletion, or evidence rewriting is permitted.
5. Confirm screenshots/exports follow privacy and retention rules.

## 3. Walkthrough Sequence (Use In Order)

1. Entry criteria and pilot scope confirmation.
2. Inventory Hub orientation.
3. Setup validation: product, branch inventory, unit conversion, recipe, and adjustment reasons.
4. Direct sale deduction and exact replay.
5. Offline sale synchronization, where approved by terminal policy.
6. Recipe deduction lineage.
7. Negative-stock strict and soft-policy behavior.
8. Void/refund restoration and replay.
9. Stocktake activity during count and posting evidence.
10. Manual adjustment approval and denial.
11. Reports, exports, and evidence manifest.
12. Go/no-go, containment, and hypercare review.

## 4. Timebox Guide

1. Opening and boundary statements: 5 minutes.
2. Setup and scope confirmation: 10 minutes.
3. Transaction and replay scenarios: 30 to 45 minutes.
4. Stocktake, adjustment, and reports: 30 to 45 minutes.
5. Evidence manifest and defects review: 15 minutes.
6. Go/no-go or retest decision: 15 minutes.

## 5. Facilitator Prompts

1. "Can you locate this surface from the hub without assistance?"
2. "Which source reference proves this stock movement?"
3. "Does current stock reconcile to movement-derived stock?"
4. "Which screenshot/export is the required evidence?"
5. "Is this issue configuration, authorization, data integrity, software, reporting, training, or operational misuse?"
6. "Which containment mode applies if this scenario fails?"

## 6. Real-Time Checks

Mark each as Done/Not Done during the session:

1. Boundary disclaimer stated.
2. Pilot mode and branch scope recorded.
3. Entry criteria satisfied.
4. Required roles and permissions verified.
5. No unsupported feature promises made.
6. No sensitive data exposed.
7. Evidence manifest updated.
8. Severity, waiver, and retest rules used when needed.
9. Post-session checklist completed.

## 7. Escalation Trigger Shortlist

Escalate immediately if:

1. Cross-tenant or cross-branch data appears.
2. Unauthorized stock mutation is possible.
3. Stock changes without canonical movement.
4. Duplicate committed stock effect appears.
5. Current stock cannot be explained by movement evidence.
6. Approval can be bypassed.
7. Historical evidence mutates after configuration change.
8. Required screen is inaccessible for expected role.
9. Potential sensitive data appears.

## 8. Closeout and Handoff

1. Record defects, observations, and residual risks.
2. Confirm sign-off entries in checklist addendum.
3. Confirm evidence manifest completeness.
4. Confirm next hypercare or retest owner.
5. Log deltas using:
   `docs/validation/pilot-enablement-pack-cycle-1-feedback-delta-log-template.md`
