# Story 30.2 Slice A — Advisory Urgency Calculation Service Closure

## Status
**Status:** Implemented & Locally Validated
**Date:** May 2026

## Objective
Implement the initial slice of Story 30.2 by creating an advisory urgency calculation service (`SystemAdminTenantUrgencyService`). The service provides a non-persisted, deterministic calculation of a tenant's urgency band (low, caution, critical) to help System Admins prioritize operational monitoring and onboarding assistance.

## Implementation Details
- Created `SystemAdminTenantUrgencyService` to calculate urgency bands strictly on request.
- Implemented `evaluate` and `evaluateFromReadinessSummary` to derive the urgency band safely without any database writes.
- Implemented explainability by including `reasons` and `signals` in the output payload.
- Calculated heuristics:
  - `critical`: Blocked tenants, or tenants with critical readiness blockers. Includes a time-based stagnation reason if blocked for > 30 days.
  - `caution`: Tenants ready for pilot but requiring monitoring, or tenants with pending configuration actions. Includes a time-based stagnation reason if signed off > 14 days ago.
  - `low`: Tenants fully ready for operations with no blockers or pending actions.

## Governance & Boundary Enforcement
- The service performs **read-only derivations** using existing data (Tenant Readiness Summary, `tenant_readiness_sign_offs`).
- **No Persistence:** There is no `tenant_risk_scores` table.
- **No Automations:** The service does not implement auto-suspension, auto-remediation, or disable features.

## Validation Evidence
- **Targeted Test Suite:** `tests/Feature/SystemAdmin/SystemAdminTenantUrgencyServiceTest.php`
  - Result: 5 tests / 22 assertions passing.
  - Tests confirm `low`, `caution`, and `critical` urgency evaluations based on varying mocked readiness states.
  - Assertions confirm that `Tenant` mutation does not occur when urgency is calculated.
- **Full Suite Integrity:** `SystemAdmin` suite (88 tests / 522 assertions) passed without regression.

## Next Steps
Proceed with **Slice B — Read-only API Payload**, exposing this calculated urgency metric to System Admins for consumption in the dashboard UI.
