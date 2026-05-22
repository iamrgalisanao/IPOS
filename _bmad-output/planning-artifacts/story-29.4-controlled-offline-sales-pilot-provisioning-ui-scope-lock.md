# Story 29.4 Controlled Offline Sales Pilot Provisioning UI Scope Lock

Status: Planning Lock Initiated
Date: 2026-05-20
Epic: 29 - Platform Tenant Provisioning and Subscription Feature Gating

## Goal
Create a System Admin provisioning UI and workflow that verifies whether a tenant, branch, and sales machine profile are eligible for controlled offline sales pilot enablement.

## In Scope
- Pilot eligibility review screen.
- Tenant, branch, and terminal selection.
- Checklist-driven readiness validation.
- Verify tenant, branch, and terminal offline settings.
- Verify terminal prefix and sequence status.
- Verify compliance fields are complete.
- Verify required admin/reviewer permissions exist.
- Generate or display branch-specific enablement pack fields.
- Mark pilot target as ready, pending, or blocked.

## Out of Scope
- Enabling broad offline sales globally.
- Changing offline sync and posting backend logic.
- Local official GCT, Z-read, and e-journal logic changes.
- BIR-certified claims.
- CPA/BIR review workflow.

## Preconditions
- Story 29.3 closure evidence recorded and accepted.
- Story 29.1/29.1A/29.2/29.3 governance states preserved.
- G-062 remains open and tracked separately as release-level caveat.

## Primary Risks
- Eligibility checks diverging from existing controlled-offline backend constraints.
- Incorrect pilot-ready states due to incomplete terminal compliance metadata.
- Permission mismatch between system-admin orchestration and tenant-level reviewer assignment.

## Validation Plan (Targeted)
- Add System Admin feature tests for pilot eligibility checklist and state outcomes.
- Re-run focused onboarding/provisioning and controlled-offline adjacent suites.
- Preserve G-062 accounting follow-up as non-blocking for Story 29.4 planning/implementation.

## Exit Criteria
- Planning lock is approved for Story 29.4 implementation.
- UI/workflow scope boundaries remain consistent with controlled pilot model.
- Governance docs updated with Story 29.4 planning state and next implementation action.
