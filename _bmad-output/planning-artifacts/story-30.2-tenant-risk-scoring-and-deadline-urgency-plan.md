# Story 30.2 — Tenant Advisory Risk Scoring and Deadline Urgency Plan

## Goal
Provide a read-only advisory urgency signal for System Admin users based on existing tenant readiness, compliance detail, and sign-off data.

The purpose is to help System Admins prioritize which tenants need review first. This story does not enforce, suspend, remediate, disable, or mutate anything.

## In Scope
- Advisory urgency band:
  - low
  - caution
  - critical
- On-request deterministic calculation only.
- No persistence in the first slice.
- Derived from existing data only:
  - readiness state
  - blocker count
  - blocker categories
  - pending action count
  - compliance detail output from Story 30.1
  - days since last readiness sign-off, if available
  - days since tenant creation, if available and safe
- Read-only API payload for System Admin dashboard use.
- Explanation fields showing why a tenant received its urgency band.

## Out of Scope
- Persisted `tenant_risk_scores` table.
- Invented compliance deadlines without authoritative source data.
- Auto-remediation.
- Auto-suspension.
- Feature disablement.
- Billing/subscription changes.
- POS/offline/tax/receipt/GCT/Z-read/e-journal changes.
- Persona or permission schema changes.
- Auto-suspension is not approved in Epic 30. Story 30.4 is reserved for role-based System Admin persona views and must not introduce suspension logic.

## Recommended Implementation Slices
**Slice A — Advisory Urgency Calculation Service**
- Create read-only service.
- Calculate urgency band on request.
- Use existing readiness/compliance/sign-off data only.
- No database writes.

**Slice B — Read-only API Payload**
- Expose urgency summary to System Admin only.
- Include explanation fields and source indicators.
- No persistence.

**Slice C — Dashboard Integration**
- Add urgency band to existing System Admin dashboard.
- Display low/caution/critical counts.
- Link to existing compliance detail pages.
- No action buttons.

**Slice D — Closure and Validation**
- Tests for low/caution/critical mapping.
- Tests for no mutation.
- Tests for missing sign-off handling.
- Tests for tenant isolation and platform-admin access.

## Approval Gate
Before implementation can proceed, this plan asserts adherence to the following boundaries:
- advisory-only language
- no persisted score table
- no invented deadlines
- no auto-remediation
- no auto-suspension
- on-request calculation only
- derived from existing readiness/compliance/sign-off data
