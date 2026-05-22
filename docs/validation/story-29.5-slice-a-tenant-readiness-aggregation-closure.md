# Story 29.5 Slice A — Tenant Readiness Aggregation Service + Readiness API Closure

**Epic:** 29 — Platform Tenant Provisioning and Subscription Feature Gating
**Story:** 29.5 — Tenant Onboarding Readiness Review
**Slice:** A — Readiness Aggregation Service + Readiness API
**Status:** Accepted with Governance Notes
**Date:** 2026-05-21

---

## What Was Implemented

### Service
- `app/Services/TenantReadinessService.php`
  - `getReadinessSummary(Tenant $company): array` — aggregates all readiness components into a single structured payload.
  - Branch-level readiness rows: admin assignment, compliance completeness, pilot eligibility outcome/reasons, profile metadata.
  - Tenant admin roster: active tenant_user accounts with role names.
  - Blocker aggregation: missing branches, unassigned admins, incomplete compliance, inactive branches, subscription plan missing, feature gate misalignment.
  - Pending actions list: human-readable remediation messages for each blocker.
  - `calculateReadinessState()` — derives `ready_for_operations`, `ready_for_pilot`, or `blocked` based on aggregated checks.
  - Checklist metrics (`checks`) for dashboard display.
  - All queries use `withoutGlobalScopes()` and eager-loaded relationships to avoid tenant-context dependency in a platform-admin cross-tenant surface.

### Controller
- `app/Http/Controllers/SystemAdmin/TenantReadinessController.php`
  - Single `show(Tenant $company)` method — delegates to `TenantReadinessService::getReadinessSummary()`.
  - Returns `JsonResponse` only.
  - No mutations, no writes.

### Route
- `GET /system-admin/tenants/{company}/readiness`
- Route name: `system-admin.readiness.show`
- Middleware: `auth` + `platform.admin`
- Access: System Admin only; tenant users receive HTTP 403.

### Readiness State Logic
| State | Condition |
| :--- | :--- |
| `ready_for_operations` | No blockers, tenant active, all branches active, all admins assigned, all compliance complete, all branches pilot-eligible |
| `ready_for_pilot` | No root blockers, tenant active, at least one pilot-eligible branch |
| `blocked` | Any structural blocker present OR tenant inactive OR no branches |

---

## Acceptance Decision

Story 29.5 Slice A is accepted as implemented and locally validated.

### Accepted Completed Work
- Added `TenantReadinessService`.
- Added `TenantReadinessController`.
- Added `GET /system-admin/tenants/{company}/readiness`.
- Added `system-admin.readiness.show` route.
- Added readiness state derivation: `blocked`, `ready_for_pilot`, `ready_for_operations`.
- Added branch-level readiness rows.
- Added tenant admin roster visibility.
- Added blocker and pending-action aggregation.
- Added checklist metrics for dashboard display.
- Added `TenantReadinessReviewTest.php`.

---

## Completed Scope — Boundaries Upheld

- No tenant/branch/user/machine profile creation endpoints added.
- No pilot enable/disable mutations added.
- No subscription engine or billing behavior changes.
- No offline sync/posting backend changes.
- No BIR/CPA workflow.
- Sign-off action deferred to Slice B.

---

## Validation Evidence

### New Test Suite
- `tests/Feature/SystemAdmin/TenantReadinessReviewTest.php`
  - `test_platform_admin_can_view_readiness_summary_payload` — payload structure, `ready_for_operations` state
  - `test_tenant_user_is_forbidden_from_platform_readiness_endpoint` — 403 authorization boundary
  - `test_readiness_is_blocked_without_branches_and_admins` — `blocked` state derivation
  - `test_readiness_is_ready_for_pilot_when_some_branches_are_ready_but_not_all_operational` — `ready_for_pilot` state derivation with partial branch eligibility
  - `test_readiness_reports_blocked_when_subscription_feature_overrides_are_misaligned` — feature gate alignment check
  - **Result: 5 tests / 22 assertions passing**

### SystemAdmin Suite Regression
```
./vendor/bin/pest tests/Feature/SystemAdmin
```
- **Result: 58 tests / 291 assertions passing — zero regressions**

---

## Related Artifacts

- [story-29.5-tenant-onboarding-readiness-review-scope-lock.md](../../_bmad-output/planning-artifacts/story-29.5-tenant-onboarding-readiness-review-scope-lock.md)
- [validated-implementation-roadmap.md](../roadmap/validated-implementation-roadmap.md) (Epic 29 section)
- [task-ledger.md](../ai-governance/task-ledger.md) (G-065)

---

## Governance Note

Story 29.5 Slice A implements read-only tenant onboarding readiness aggregation only. It does not create or mutate tenants, branches, users, machine profiles, pilot enablement records, subscription settings, billing behavior, or offline sync/posting logic.

---

## Next Slice

Story 29.5 Slice B — Readiness Sign-Off Workflow. Slice B introduces the first mutation: a `POST sign-off-readiness` endpoint that records a `ready_for_pilot` or `ready_for_operations` decision to the audit trail. Slice B requires an explicit scope lock and mutation boundary approval before implementation begins.
