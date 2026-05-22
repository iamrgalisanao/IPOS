# Story 29.3 Sales Machine Profile and Compliance Registration Scope Lock

Status: Initiated
Date: 2026-05-20
Epic: 29 - Platform Tenant Provisioning and Subscription Feature Gating

## Objective
Implement System Admin assisted setup for sales machine profile and compliance registration after tenant, initial branch, and owner/admin onboarding are complete.

## In Scope
- Add or finalize onboarding step for sales machine profile registration per tenant/branch.
- Capture required machine profile compliance fields used by BIR/EOPT flows already present in the codebase.
- Provide System Admin and/or authorized tenant admin workflow to create and review profile completeness.
- Add readiness signal integration so tenant setup can surface machine profile compliance status.
- Add targeted feature tests for happy path and key guardrails.

## Out of Scope
- Rebuilding core BIR receipt/tax reporting engines.
- New billing/entitlement engine behavior.
- Broad redesign of onboarding UX outside Story 29.3 flow.
- Full-suite accounting regression resolution tracked under G-062.

## Preconditions
- Story 29.1 closed.
- Story 29.1A completed with residual gaps documented.
- Story 29.2 implemented and target-locally validated.
- G-062 accounting follow-up remains open and tracked separately.

## Primary Risks
- Compliance field mismatch with existing BIR validation expectations.
- Missing authorization boundaries between platform support and tenant admins.
- Regression risk in readiness summaries consumed by System Admin dashboards.

## Validation Plan (Targeted)
- `./vendor/bin/pest tests/Feature/SystemAdmin`
- Add/extend tests for machine profile and compliance registration pathways.
- Re-run adjacent targeted suites that assert onboarding readiness behavior.

## Exit Criteria
- Story 29.3 flow implemented with authorization and tenant isolation preserved.
- Compliance registration fields persisted and surfaced in onboarding/readiness views.
- Targeted Story 29.3 suites green.
- Governance docs updated with caveat boundaries (full-suite accounting issue remains tracked under G-062).
