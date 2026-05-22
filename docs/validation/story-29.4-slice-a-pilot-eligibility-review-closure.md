# Story 29.4 Slice A — Pilot Eligibility Review and Readiness Checklist Closure

Status: Implemented & Locally Validated
Date: 2026-05-20
Epic: 29 — Platform Tenant Provisioning and Subscription Feature Gating
Story: 29.4 — Controlled Offline Sales Pilot Provisioning UI
Slice: A — Pilot Eligibility Review and Readiness Checklist

---

## Summary

Story 29.4 Slice A delivers a read-only System Admin pilot eligibility review for the Controlled
Offline Sales pilot provisioning workflow. The eligibility endpoint evaluates 11 checklist items
across the tenant → branch → terminal chain and returns a structured outcome of `ready`,
`pending`, or `blocked` with individual check results, blocking reasons, and pending reasons.

No mutations were introduced. Controlled offline sales is not enabled, altered, or activated by
this slice.

---

## Completed Work

- Added `PilotEligibilityService` — evaluates all 11 checklist items, resolves outcome, and
  returns `checks[]`, `blocking_reasons[]`, and `pending_reasons[]`.
- Added `PilotProvisioningController::eligibility()` — read-only System Admin endpoint with
  branch/profile resolution from query params, protected by `platform.admin` middleware.
- Added route `GET /system-admin/tenants/{company}/pilot-eligibility` →
  named `system-admin.pilot.eligibility`.
- Added `PilotProvisioningTest.php` — 18 tests covering ready, blocked (4 paths), pending
  (7 paths), security gates (2), response shape, no-mutation guarantee, and query-param selection.

## Checklist Coverage

| # | Key | Level | Covers |
|---|---|---|---|
| 1 | `tenant_active` | blocked | Tenant status |
| 2 | `branch_exists` | blocked | Branch exists for tenant |
| 3 | `owner_exists` | blocked | Active tenant_user exists |
| 4 | `machine_profile_exists` | blocked | SalesMachineProfile for branch |
| 5 | `machine_profile_compliance_complete` | pending | MIN, MSN, PTU, ATGCN, supplier accreditation |
| 6 | `tenant_offline_enabled` | pending | Tenant-level offline setting |
| 7 | `branch_offline_enabled` | pending | Branch-level offline setting |
| 8 | `terminal_offline_enabled` | pending | Terminal offline setting (null = inherit) |
| 9 | `offline_prefix_assigned` | pending | Sequence prefix not blank |
| 10 | `offline_sequence_active` | pending | Sequence status active |
| 11 | `manage_offline_permission_assigned` | pending | Role carries manage_offline_sales_settings |

---

## Validation Evidence

```
./vendor/bin/pest tests/Feature/SystemAdmin/PilotProvisioningTest.php
Result: 18 tests / 96 assertions — PASS

./vendor/bin/pest tests/Feature/SystemAdmin
Result: 40 tests / 223 assertions — PASS (no regressions)
```

---

## Governance Note

Story 29.4 Slice A implements read-only pilot eligibility review only. It does not enable
controlled offline sales, does not change offline sync or posting behavior, does not generate
local official GCT/Z-read/e-journal records, and does not make any BIR-certified offline sales
claim.

G-062 (accounting regression follow-up) is closed. Slice A remains non-blocking and has no
release-level caveat from this item.

---

## Next Action

Story 29.4 Slice B — Pilot Enablement Controls requires an explicit mutation boundary approval
before implementation begins. Slice B scope must be reviewed and locked before any enable/disable
endpoint work starts.
