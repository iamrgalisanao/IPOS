---
title: 'G-082 Task 5 Printer Profile Schema and Admin UI'
type: 'feature'
created: '2026-07-12'
status: 'done'
baseline_commit: 'bb56437045a2b9c2538a00ae30ee36c12a24a8bd'
context:
  - '{project-root}/docs/roadmap/pos-admin-configuration-terminal-capability-backlog.md'
  - '{project-root}/docs/validation/admin-config-snapshot-foundation-2026-07-11.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** IPOS has only a placeholder printer configuration hash; administrators cannot define branch printer endpoints or assign a receipt printer to a terminal. This prevents the configuration snapshot from carrying a useful, governed printer profile while hardware validation remains deferred.

**Approach:** Complete the in-progress tenant/branch-scoped printer profile schema, permission-protected Inertia admin UI, terminal assignment, deterministic branch-default fallback, and bootstrap snapshot integration. Harden the current implementation and tests without claiming that any physical printer or cash drawer has been validated.

## Boundaries & Constraints

**Always:** Enforce tenant and authorized-branch isolation on every read and write; allow only active same-branch profiles to be assigned to a terminal; resolve an active terminal override before an active receipt default; keep hashes deterministic for identical persisted configuration; preserve current sales-machine compliance and offline-sequence guards; deactivate instead of physically deleting profiles through the admin UI.

**Ask First:** Any expansion into printer drivers, browser/device discovery, test-print execution, kitchen-ticket routing, multiple simultaneous printers per terminal, or changes to receipt/BIR finalization behavior.

**Never:** Claim hardware readiness or physical UAT; expose cross-tenant or unauthorized-branch profiles; silently assign an inactive profile; bypass backend permission middleware; move master printer administration into the POS terminal; weaken existing terminal identity, offline sync, sequence, tax, accounting, or receipt safeguards.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Manage profile | Authorized admin submits valid branch printer data | Profile is created/updated and listed for its permitted scope | Field errors are returned without partial changes |
| Default selection | Active receipt profile is marked default | It becomes the sole active receipt default for that tenant/branch | Invalid inactive/non-receipt default state is rejected or normalized |
| Terminal override | Terminal references an active same-tenant, same-branch profile | Bootstrap exposes it with `terminal_override` source and its hash | Foreign, cross-branch, or inactive assignment is rejected |
| Branch fallback | Terminal has no usable override and branch has an active receipt default | Bootstrap exposes the deterministic default with `branch_default` source | If none exists, payload is null and hash is stable |
| Deactivation | Assigned/default profile is deactivated | It stops resolving and is no longer default; terminal safely falls back or resolves none | No physical row deletion or cross-scope mutation |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_07_12_000001_create_printer_profiles_table.php` -- printer profile schema and nullable terminal assignment foreign key.
- `app/Models/PrinterProfile.php` and `app/Models/SalesMachineProfile.php` -- profile scopes, casts, and terminal relationship.
- `app/Http/Controllers/Admin/PrinterProfileController.php` -- scoped CRUD/deactivation and default rules.
- `app/Http/Controllers/Admin/SalesMachineProfileController.php` -- eligible printer choices and guarded assignment.
- `app/Services/POS/OfflineReadiness/CacheBootstrapService.php` -- terminal override/default resolution, payload, and version hash.
- `resources/js/Pages/Admin/PrinterProfiles/Index.jsx` -- printer management surface.
- `resources/js/Pages/Admin/SalesMachineProfiles/Edit.jsx` -- terminal printer assignment control.
- `app/Services/RbacSeeder.php` and `routes/web.php` -- permission grant and protected resource routes.
- `tests/Feature/Admin/PrinterProfileManagementTest.php` -- CRUD, isolation, assignment, resolution, and hash coverage.

## Tasks & Acceptance

**Execution:**
- [x] Review and harden the migration/model constraints so invalid profile/default/assignment states cannot be produced through supported writes.
- [x] Harden controller scoping, validation, atomic default switching, and deactivation behavior while retaining current user-facing flows.
- [x] Finish the admin profile and terminal-assignment UI with accurate validation feedback and no physical-readiness language.
- [x] Make bootstrap resolution/query ordering and hash inputs deterministic, with no-profile behavior remaining stable.
- [x] Expand focused feature coverage for cross-tenant route binding, branch authorization, default transitions, assigned-profile deactivation, and bootstrap payload/hash behavior.

**Acceptance Criteria:**
- Given a user with `manage_printer_profiles`, when they manage a permitted branch profile, then the change persists and the admin page reflects it.
- Given a user or profile outside the current tenant/authorized branch, when a read or mutation is attempted, then access is denied or validation fails without data leakage.
- Given printer configuration changes, when the terminal bootstrap is regenerated, then profile data, resolution source, printer hash, and overall snapshot hash consistently reflect the effective printer.
- Given no available effective printer, when bootstrap is generated, then it returns a null printer payload and a stable no-profile version without blocking POS startup.
- Given hardware is unavailable, when this task is completed, then documentation and UI make no operational readiness claim.

## Spec Change Log

## Design Notes

The schema represents configuration intent, not device capability. A terminal may reference one optional profile; otherwise it inherits the branch's active default receipt profile. Deactivation must make an assigned override unusable without breaking the terminal record, so resolution falls through safely.

## Verification

**Commands:**
- `php artisan test tests/Feature/Admin/PrinterProfileManagementTest.php` -- all printer management and resolution tests pass.
- `php artisan test tests/Feature/POS/OfflineBootstrapCacheTest.php` -- existing snapshot/bootstrap behavior remains green.
- `npm run build` -- Inertia pages compile without frontend errors.
- `git diff --check` -- no whitespace errors are introduced.

## Suggested Review Order

**Governed profile lifecycle**

- Start with transactional defaults, scope validation, and assigned-profile invariants.
  [`PrinterProfileController.php:50`](../../app/Http/Controllers/Admin/PrinterProfileController.php#L50)

- Review the schema, nullable terminal assignment, and resolution indexes.
  [`2026_07_12_000001_create_printer_profiles_table.php:14`](../../database/migrations/2026_07_12_000001_create_printer_profiles_table.php#L14)

**Terminal resolution and authorization**

- Trace override-first, receipt-only fallback and deterministic hash behavior.
  [`CacheBootstrapService.php:449`](../../app/Services/POS/OfflineReadiness/CacheBootstrapService.php#L449)

- Confirm printer assignment requires both terminal scope and printer permission.
  [`SalesMachineProfileController.php:83`](../../app/Http/Controllers/Admin/SalesMachineProfileController.php#L83)

- Verify printer CRUD routes expose only implemented, permission-protected actions.
  [`web.php:742`](../../routes/web.php#L742)

**Admin surfaces**

- Review receipt-only configuration UI and configuration-safe status language.
  [`Index.jsx:40`](../../resources/js/Pages/Admin/PrinterProfiles/Index.jsx#L40)

- Review terminal override selection and branch-default explanation.
  [`Edit.jsx:162`](../../resources/js/Pages/Admin/SalesMachineProfiles/Edit.jsx#L162)

**Regression evidence**

- Inspect permission, assigned-profile mutation, and legacy override edge coverage.
  [`PrinterProfileManagementTest.php:595`](../../tests/Feature/Admin/PrinterProfileManagementTest.php#L595)

- Confirm deactivation changes effective profile and both snapshot hashes.
  [`PrinterProfileManagementTest.php:684`](../../tests/Feature/Admin/PrinterProfileManagementTest.php#L684)
