# Story 30.2 Slice B — Read-only Urgency API Closure

**Epic:** 30 — System Admin Tenant Operations & Compliance Intelligence  
**Story:** 30.2 — Tenant Advisory Risk Scoring and Deadline Urgency  
**Slice:** B — Read-only API Payload  
**Status:** Implemented & Locally Validated  
**Date:** 2026-05-21

---

## Closure Summary

Story 30.2 Slice B exposes the advisory urgency signal through the existing
read-only System Admin dashboard summary API.

The implementation adds `urgency_counts` and `tenant_urgency` to the dashboard
summary payload using `SystemAdminTenantUrgencyService`. The endpoint remains
platform-admin-only, on-request, and non-persistent.

---

## Completed Scope

- Extended `SystemAdminDashboardService` to inject and use
  `SystemAdminTenantUrgencyService`.
- Added urgency aggregation to the existing dashboard summary payload:
  - `urgency_counts.low`
  - `urgency_counts.caution`
  - `urgency_counts.critical`
- Added per-tenant advisory urgency payload:
  - tenant id
  - tenant name
  - urgency band
  - score
  - reasons
  - source signals
- Preserved the existing dashboard summary endpoint:
  - `GET /api/system-admin/dashboard/summary`
  - route name: `api.system-admin.dashboard.summary`
  - middleware: `auth:sanctum`, `platform.admin`
- Added API tests for urgency payload shape, sign-off age signal, read-only behavior,
  tenant-user denial, and unauthenticated denial.

---

## Validation Evidence

### Targeted API Suite

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/SystemAdmin/SystemAdminDashboardApiTest.php
```

- Result: 6 tests / 60 assertions passing

### SystemAdmin Regression Suite

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/SystemAdmin
```

- Result: 91 tests / 561 assertions passing

---

## Governance Boundary

Story 30.2 Slice B is API-only and read-only. It does not:

- add UI display changes
- create a persisted `tenant_risk_scores` table
- invent compliance deadlines
- remediate blockers
- auto-suspend tenants
- disable features
- alter billing or subscription engine behavior
- alter POS checkout behavior
- alter offline sync/posting behavior
- alter receipt, tax, GCT, Z-read, or e-journal engines
- change System Admin persona or permission schema

---

## Files Touched

- `app/Services/SystemAdminDashboardService.php`
- `tests/Feature/SystemAdmin/SystemAdminDashboardApiTest.php`
- `docs/validation/story-30.2-slice-b-read-only-urgency-api-closure.md`
- `docs/roadmap/validated-implementation-roadmap.md`
- `docs/ai-governance/task-ledger.md`

---

## Next Recommended Slice

Proceed to Story 30.2 Slice C — Dashboard Urgency Display.

Slice C should remain read-only and should only visualize the already-validated
`urgency_counts` and `tenant_urgency` API payload.

