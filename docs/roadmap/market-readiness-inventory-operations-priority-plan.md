# Market Readiness Inventory Operations Priority Plan

Status: In Progress (Priority 3 Implemented Locally)
Date: 2026-05-25

## 1. Purpose

Define the next safe product direction after Story 31.7 and the vendor report
gap analysis.

The goal is to improve pilot marketability by making inventory workflows visibly
usable, practical, and easy to explain without expanding high-risk compliance,
tax, billing, accounting, offline posting, or recursive recipe deduction scope.

## 2. Grounding Evidence

Inputs:

1. `docs/reports/vendor-report-gap-analysis.md`
2. `docs/user-enablement/stocktake-module-end-to-end-implementation-guide.md`
3. `docs/validation/story-31.7-all-products-ingredient-composition-report-closure.md`
4. `docs/ai-governance/release-readiness-checklist.md`
5. `docs/roadmap/epic-32-recursive-pos-recipe-deduction-parking-note.md`

Key conclusion:

IPOS is pilot-marketable through compliance, auditability, and accounting
confidence, but should close the operational inventory UX gap before broad
market launch.

## 3. Market Positioning Boundary

Allowed positioning:

> IPOS is a pilot-ready POS and inventory platform for operational control,
> accounting confidence, and audit-ready reporting.

Disallowed positioning:

1. BIR-certified.
2. Fully accredited.
3. Mass-market production-ready for all merchants.
4. Complete replacement for mature UTAK/Mosaic inventory operations.
5. Enterprise ERP replacement.

## 4. Priority Order

### Priority 1: Unified Inventory and Reporting Hub

Status:

Implemented and locally validated on 2026-05-25.

Objective:

Create one inventory entry point that groups stock visibility, stocktake,
composition, variance, low-stock, and movement surfaces.

Why first:

This improves perceived usability without changing inventory engines.

Boundaries:

1. Read-mostly navigation and aggregation surface.
2. No new stock mutation workflow.
3. No procurement automation trigger.

### Priority 2: Print-Friendly Stocktake and Inventory Report Views

Status:

Implemented and locally validated on 2026-05-25.

Objective:

Make stocktake and key inventory reports usable for branch operating binders,
manager review, and audit filing.

Why second:

This directly supports pilot demos and branch enablement.

Boundaries:

1. Report/print surfaces only.
2. No stocktake posting behavior change.
3. No BIR certification format claim.

### Priority 3: Low-Stock and Reorder Dashboard

Status:

Implemented and locally validated on 2026-05-25.

Objective:

Expose low-stock, negative-stock, reorder level, and branch-level stock risk in
a simple manager-facing view.

Why third:

UTAK and Mosaic public materials emphasize practical stock visibility and
replenishment decisions.

Boundaries:

1. Recommendation visibility only.
2. No auto-generated PO mutation.
3. No scheduler or procurement automation expansion.

### Priority 4: Branch Stock Movement Summary

Objective:

Provide a branch-scoped movement summary across receiving, stocktake,
adjustments, sales deductions, transfers, and variances.

Why fourth:

This supports accounting confidence and operational troubleshooting.

Boundaries:

1. Read-only movement reporting.
2. No reversal or adjustment mutation.
3. No accounting outbox behavior change.

### Priority 5: Stocktake Screenshot and Client Training Pack

Objective:

Complete enablement assets for branch pilot onboarding.

Why fifth:

This converts implemented functionality into a sellable and supportable pilot
workflow.

Boundaries:

1. Documentation and screenshots only.
2. Use staging/training data.
3. No customer, employee, or production-sensitive data in screenshots.

## 5. Suggested Slices

### Slice A: Inventory Hub Planning Lock

Status: Completed (accepted and implemented)

1. Inventory navigation inventory.
2. Current route/page inventory.
3. User role workflow map.
4. Hub information architecture.
5. Acceptance criteria.

### Slice B: Inventory Hub Implementation

Status: Completed (local validation passed)

1. Add hub route and page.
2. Link existing inventory reports and stocktake surfaces.
3. Add role-aware cards for operational workflows.
4. Add no-data and permission-limited states.
5. Add focused feature tests and frontend build validation.

### Slice C: Print-Friendly Report Views

Status: Completed (local validation passed)

1. Select stocktake and inventory reports for print pass.
2. Add print CSS/layout.
3. Add export/print action placement.
4. Add training-friendly labels.
5. Validate no data leakage across branches.

### Slice D: Low-Stock and Reorder Read-Only Dashboard

Status: Completed (local validation passed)

Planning lock:

`docs/implementation-plans/slice-d-low-stock-reorder-read-only-dashboard-planning-lock.md`

1. Build read-only query service.
2. Add branch/category filters.
3. Show low-stock, negative-stock, reorder-level status, and stock value context
   where permitted.
4. Add export-safe CSV if needed.
5. Do not create purchase orders.

### Slice E: Branch Movement Summary

1. Reuse existing inventory movement records.
2. Add movement type filters and date range.
3. Add branch-scoped summary totals.
4. Add drill-down links where existing pages already exist.
5. Preserve append-only source records.

### Slice F: Pilot Enablement Pack

1. Capture stocktake screenshots.
2. Add inventory hub screenshots after implementation.
3. Create branch manager demo script.
4. Create pilot checklist addendum.
5. Add support escalation and rollback notes.

## 6. Explicit Non-Goals

1. No recursive POS recipe deduction.
2. No recipe editor rebuild.
3. No catalog import write path.
4. No auto-reorder purchasing mutation.
5. No new accounting sync behavior.
6. No tax, Z-read, GCT, receipt, or e-journal change.
7. No BIR certification claim.
8. No broad offline-sales rollout.

## 7. Recommended Next Action

Create Slice E as a formal planning lock for the Branch Stock Movement Summary,
then implement only after data sources, role boundaries, acceptance criteria,
and non-goal boundaries are reviewed.
