# Story 30.2 Slice B — Read-only API Payload Closure

## Status
**Status:** Implemented & Locally Validated
**Date:** May 2026

## Objective
Extend the existing System Admin dashboard API payload to expose the new advisory urgency metrics (`urgency_counts` and `tenant_urgency`) utilizing the `SystemAdminTenantUrgencyService`. The payload must remain read-only, non-persisted, and tightly restricted to the platform admin persona.

## Implementation Details
- Injected `SystemAdminTenantUrgencyService` into `SystemAdminDashboardService`.
- Added the `urgency_counts` object summarizing the volume of tenants in `low`, `caution`, and `critical` urgency bands.
- Added the `tenant_urgency` array detailing the score, band, reasons, and signals for each tenant in the system.
- Confirmed there are no data mutations when evaluating urgency for the API payload.

## Governance & Boundary Enforcement
- The API remains completely read-only.
- **No Persistence:** Urgency calculations are strictly performed in-memory during the `/api/system-admin/dashboard/summary` request and discarded afterward.
- **Tenant Isolation & Security:** Standard `platform.admin` middleware properly intercepts unauthorized users and cross-tenant access attempts.

## Validation Evidence
- **Targeted Test Suite:** `tests/Feature/SystemAdmin/SystemAdminDashboardApiTest.php`
  - Result: 3 tests / 38 assertions passing.
  - Assertions confirm the presence and schema structure of `urgency_counts` and `tenant_urgency` in the API JSON response.
  - Access control validation proves that tenant users receive `403 Forbidden` and unauthenticated users receive `401 Unauthorized`.
- **Full Suite Integrity:** `SystemAdmin` suite (88 tests / 539 assertions) passed without regression.

## Next Steps
Proceed with **Slice C — Dashboard Integration**, implementing the UI changes on the System Admin operational dashboard to visualize the urgency bands.
