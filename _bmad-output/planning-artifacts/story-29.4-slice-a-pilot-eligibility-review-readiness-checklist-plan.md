# Story 29.4 — Slice A: Pilot Eligibility Review and Readiness Checklist

Status: Slice Plan — Approved for Implementation
Date: 2026-05-20
Parent Story: 29.4 — Controlled Offline Sales Pilot Provisioning UI
Scope Lock Reference: story-29.4-controlled-offline-sales-pilot-provisioning-ui-scope-lock.md

---

## Slice A Goal

Implement a **read-only** system-admin eligibility endpoint that evaluates whether a given
tenant → branch → terminal combination satisfies all preconditions for controlled offline sales
pilot enablement, and returns a structured checklist with an overall outcome of `ready`,
`pending`, or `blocked`.

No mutation logic (enable/disable offline sales, write enablement packs) is included in Slice A.

---

## Codebase Anchors

The following existing components are directly reused or extended — no parallel
reimplementation is permitted:

| Component | File | Role in Slice A |
|---|---|---|
| `OfflineSettingsValidator` | `app/Services/POS/OfflineReadiness/OfflineSettingsValidator.php` | Settings checks (tenant/branch/terminal `offline_sales_enabled`, prefix, sequence status) |
| `TenantProvisioningController::isMachineProfileComplianceComplete` | `app/Http/Controllers/SystemAdmin/TenantProvisioningController.php` | Compliance field check pattern — **extract to shared logic** |
| `SalesMachineProfile` | `app/Models/SalesMachineProfile.php` | `offline_sales_enabled`, `offline_sequence_prefix`, `offline_sequence_status`, compliance fields |
| `Branch` | `app/Models/Branch.php` | `offline_sales_enabled` |
| `Tenant` | `app/Models/Tenant.php` | `status`, `offline_sales_enabled` |
| Route prefix | `routes/web.php` line 37 | `system-admin/tenants` prefix group — add new GET route here |

---

## Checklist Definition

Each check carries a **fail-outcome** that governs overall result resolution.

| # | Key | Pass Condition | Fail Outcome |
|---|---|---|---|
| 1 | `tenant_active` | `Tenant::status === 'active'` | **blocked** |
| 2 | `branch_exists` | ≥ 1 `Branch` row for this tenant | **blocked** |
| 3 | `owner_exists` | ≥ 1 active `User` with `actor_type = 'tenant_user'` for this tenant | **blocked** |
| 4 | `machine_profile_exists` | ≥ 1 `SalesMachineProfile` row for this branch | **blocked** |
| 5 | `machine_profile_compliance_complete` | All 5 required compliance fields non-blank (MIN, MSN, PTU, ATGCN, supplier accreditation number) | pending |
| 6 | `tenant_offline_enabled` | `tenant.offline_sales_enabled === true` | pending |
| 7 | `branch_offline_enabled` | `branch.offline_sales_enabled === true` | pending |
| 8 | `terminal_offline_enabled` | `profile.offline_sales_enabled !== false` (null inherits enabled) | pending |
| 9 | `offline_prefix_assigned` | `profile.offline_sequence_prefix` is not blank | pending |
| 10 | `offline_sequence_active` | `profile.offline_sequence_status` is `null` or `'active'` | pending |
| 11 | `manage_offline_permission_assigned` | ≥ 1 role in tenant RBAC carries `manage_offline_sales_settings` permission | pending |

**Outcome resolution (evaluated in order):**
1. Any check with fail-outcome `blocked` fails → overall `blocked`
2. Any check with fail-outcome `pending` fails → overall `pending`
3. All checks pass → overall `ready`

---

## New Artifacts

### 1. `app/Services/PilotEligibilityService.php`

**Purpose:** Evaluate all 11 checklist items for a given Tenant + Branch + SalesMachineProfile
combination and return a structured result payload.

**Public interface:**

```php
public function evaluate(Tenant $tenant, Branch $branch, SalesMachineProfile $profile): array
// Returns:
// [
//   'outcome'  => 'ready' | 'pending' | 'blocked',
//   'checks'   => [
//     ['key' => 'tenant_active', 'status' => 'pass' | 'fail', 'message' => '...'],
//     ...
//   ],
// ]
```

**Dependencies injected:**
- `OfflineSettingsValidator` — delegates checks 6–10 to `validate()`, then maps sub-results
- `TenantContext` — required for any `withoutGlobalScopes` cross-tenant reads

**Implementation notes:**
- Extract the compliance field check from `TenantProvisioningController::isMachineProfileComplianceComplete`
  into a private method on this service (no breaking change to the controller — it keeps its copy
  until a future refactor story).
- Check 11 (`manage_offline_permission_assigned`) queries
  `Permission::withoutGlobalScopes()->where('name','manage_offline_sales_settings')` then checks
  whether any `Role` carrying that permission belongs to the tenant's user set via RBAC pivot tables.
  Wrap in `withoutGlobalScopes()` since this is a system-admin cross-tenant read.
- All DB reads must use `withoutGlobalScopes()` because the system-admin context does not
  set a live TenantContext.

### 2. `app/Http/Controllers/SystemAdmin/PilotProvisioningController.php`

**Purpose:** Single read-only controller exposing the pilot eligibility endpoint.

**Method:** `eligibility(Request $request, Tenant $company)`

**Resolution logic for branch and profile:**
1. If `branch_id` query param provided → load `Branch::withoutGlobalScopes()->where('tenant_id', $company->id)->findOrFail($branchId)`
2. Else → take first branch ordered by `created_at`; if none, short-circuit with blocked result
   (branch_exists check will surface the failure without needing a 404)
3. If `profile_id` query param provided → load profile; else take first `SalesMachineProfile` for that branch
4. If no profile found → short-circuit with blocked (machine_profile_exists check surfaces failure)

**Response:**
```php
return response()->json([
    'tenant'  => ['id' => $company->id, 'name' => $company->name],
    'branch'  => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
    'profile' => $profile ? ['id' => $profile->id, 'profile_code' => $profile->profile_code] : null,
    'outcome' => $result['outcome'],
    'checks'  => $result['checks'],
]);
```

**Authorization:** Protected by `['auth', 'platform.admin']` via route group — no additional
gate needed in the method.

### 3. Route addition in `routes/web.php`

Add inside the existing `system-admin.onboarding.*` group block (line 37):

```php
Route::get('{company}/pilot-eligibility', [
    \App\Http\Controllers\SystemAdmin\PilotProvisioningController::class, 'eligibility'
])->name('pilot.eligibility');
```

Named route will resolve to: `system-admin.pilot.eligibility`

### 4. `tests/Feature/SystemAdmin/PilotProvisioningTest.php`

---

## Test Matrix (Slice A)

| # | Test Name | Setup | Expected Outcome |
|---|---|---|---|
| 1 | ready path — all checks pass | active tenant, branch, owner, compliant profile, offline enabled at all levels, prefix+sequence active, permission assigned | `outcome: ready`, all checks `pass` |
| 2 | blocked — tenant inactive | tenant status = `suspended` | `outcome: blocked`, `tenant_active: fail` |
| 3 | blocked — no branch | tenant with no branches | `outcome: blocked`, `branch_exists: fail` |
| 4 | blocked — no owner | tenant+branch, no active tenant_user | `outcome: blocked`, `owner_exists: fail` |
| 5 | blocked — no machine profile | tenant+branch+owner, no SalesMachineProfile for branch | `outcome: blocked`, `machine_profile_exists: fail` |
| 6 | pending — compliance incomplete | profile missing permit_to_use_number | `outcome: pending`, `machine_profile_compliance_complete: fail` |
| 7 | pending — tenant offline disabled | tenant.offline_sales_enabled = false | `outcome: pending`, `tenant_offline_enabled: fail` |
| 8 | pending — branch offline disabled | branch.offline_sales_enabled = false | `outcome: pending`, `branch_offline_enabled: fail` |
| 9 | pending — terminal offline disabled | profile.offline_sales_enabled = false | `outcome: pending`, `terminal_offline_enabled: fail` |
| 10 | pending — prefix missing | profile.offline_sequence_prefix = null | `outcome: pending`, `offline_prefix_assigned: fail` |
| 11 | pending — sequence suspended | profile.offline_sequence_status = 'suspended' | `outcome: pending`, `offline_sequence_active: fail` |
| 12 | pending — permission not assigned | no role with manage_offline_sales_settings | `outcome: pending`, `manage_offline_permission_assigned: fail` |
| 13 | security — non-platform-admin blocked | auth as tenant_user | 403 |
| 14 | security — unauthenticated blocked | no session | redirect to login |
| 15 | branch scoping — profile_id from another tenant rejected | profile.tenant_id ≠ company.id | 404 |

---

## Outcome → Human Message Mapping

For future UI rendering (Slice A returns raw checks; UI label mapping lives in frontend):

| Outcome | Human Label | Color Signal |
|---|---|---|
| `ready` | Ready for offline pilot | green |
| `pending` | Setup incomplete — review checklist | amber |
| `blocked` | Missing prerequisites — cannot proceed | red |

---

## Out of Scope for Slice A

The following are **explicitly excluded** and tracked for later slices:

- Any write/mutation endpoint (enabling/disabling offline settings)
- Enablement pack generation or display
- Branch selector UI component
- Inertia page render (Slice A returns JSON only; Inertia page is Slice B)
- CPA/BIR review workflow
- Broad offline enablement across all tenants

---

## Preconditions

- Story 29.3 closure accepted and recorded.
- `OfflineSettingsValidator` must not be modified — only consumed.
- All cross-tenant reads must use `withoutGlobalScopes()`.
- No new migrations required — all model fields already exist.

---

## Exit Criteria

- `PilotEligibilityService` implemented and unit-testable.
- `PilotProvisioningController::eligibility` registered and route resolving correctly.
- All 15 test cases in `PilotProvisioningTest.php` passing under targeted run:
  `./vendor/bin/pest tests/Feature/SystemAdmin/PilotProvisioningTest.php`
- Existing SystemAdmin suite remains green:
  `./vendor/bin/pest tests/Feature/SystemAdmin`
- G-062 remains open and non-blocking.
- Governance docs updated with Slice A implementation evidence once exit criteria are met.
