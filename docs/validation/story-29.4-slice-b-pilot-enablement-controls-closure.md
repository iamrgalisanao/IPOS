# Story 29.4 Slice B — Pilot Enablement Controls: Closure Artifact

**Date:** 2026-05-20  
**Governance Ref:** G-064  
**Story:** 29.4 Controlled Offline Sales Pilot Provisioning UI  
**Slice:** B — Pilot Enablement Controls (Mutations)

---

## Summary

Slice B implements the actual pilot enable/disable mutations for the controlled offline sales pilot
provisioning workflow. Mutations are scoped to tenant/branch/terminal offline settings for the
selected pilot scope only. All four mutation gate questions were explicitly approved before
implementation began.

---

## Approved Gate Decisions

| Gate | Question | Decision |
|:---|:---|:---|
| Mutation boundary | Only tenant/branch/terminal offline settings for selected pilot scope | **Approved** |
| Audit trail | Every attempt recorded with actor, target, before/after values, outcome, reason | **Approved** |
| Wide-flag protection | Tenant-level enable must not auto-activate all branches/terminals | **Approved** |
| Race-condition mitigation | Runs in transaction, rolled back if post-write eligibility outcome ≠ ready | **Approved** |

---

## Implemented Artifacts

### Controller Changes (`app/Http/Controllers/SystemAdmin/PilotProvisioningController.php`)

- **`enable(Request $request, Tenant $company): JsonResponse`**  
  Validates `branch_id`, `profile_id`, and optional per-level flags (`enable_tenant`, `enable_branch`, `enable_terminal`). Resolves branch/profile with `withoutGlobalScopes()` cross-tenant safety. Evaluates eligibility pre-write via `PilotEligibilityService`. Runs `DB::transaction()` applying only the explicitly requested flag updates. Re-evaluates eligibility post-write; throws `RuntimeException('post_write_not_ready')` to trigger rollback if outcome ≠ `ready`. On rejection, records `pilot_enable_rejected` audit outside transaction (persists after rollback) and returns HTTP 422. On success, records `pilot_enabled` audit and returns HTTP 200 with `{success, outcome, enabled_at, checks}`.

- **`disable(Request $request, Tenant $company): JsonResponse`**  
  Validates `branch_id`, `profile_id`, and `level` (one of `tenant|branch|terminal`). Sets `offline_sales_enabled = false` at the requested level only inside a `DB::transaction()`. Wide-flag protection enforced: disabling at terminal level leaves branch and tenant flags unchanged. Records `pilot_disabled` audit. Returns HTTP 200 with `{success, level, disabled_at}`.

- **`platformAudit(string $action, Tenant $company, Branch $branch, SalesMachineProfile $profile, array $metadata): void`**  
  Private helper. Temporarily sets `TenantContext` to the target company before calling `AuditLogger::log()`, then clears it in a `finally` block. Satisfies `AuditLogger`'s `hasTenant()` requirement for a platform-admin operator with no ambient tenant context.

### Routes (`routes/web.php`)

Two POST routes added to the `system-admin.` prefixed group (middleware: `['auth', 'platform.admin']`):

```
POST /system-admin/tenants/{company}/pilot-enable   → system-admin.pilot.enable
POST /system-admin/tenants/{company}/pilot-disable  → system-admin.pilot.disable
```

---

## Validation Evidence

| Suite | Tests | Assertions | Result |
|:---|:---:|:---:|:---:|
| `PilotProvisioningMutationTest.php` (Slice B) | 13 | 46 | ✅ PASS |
| `PilotProvisioningTest.php` (Slice A, no regressions) | 18 | 96 | ✅ PASS |
| Full `tests/Feature/SystemAdmin` | 53 | 269 | ✅ PASS |

### Test Coverage (Slice B — 13 tests)

1. Enable succeeds when outcome is ready
2. Enable rejected when compliance incomplete (MIN null) — 422
3. Enable rejected when prefix missing — 422
4. Enable applies requested tenant flag (wide-flag protection: only requested level toggled)
5. Disable succeeds at tenant level
6. Disable succeeds at branch level
7. Disable succeeds at terminal level (branch and tenant flags unchanged)
8. Enable rolled back if post-write outcome not ready — DB values unchanged after rollback
9. Audit event recorded on successful enable (`pilot_enabled`)
10. Audit event recorded on successful disable (`pilot_disabled`)
11. Audit event recorded when enable rejected (`pilot_enable_rejected`)
12. Tenant user cannot access enable or disable (403)
13. Cross-tenant branch returns 404 on enable

---

## Scope Boundaries Upheld

- No changes to `PilotEligibilityService` — evaluation logic is read-only.
- No new migrations — all flag columns existed from prior epics.
- No broad offline enablement — mutations target only the explicitly specified pilot scope (one branch, one profile).
- No GCT/Z-read/e-journal engine changes.
- No BIR-certified claims.
- No changes to Slice A endpoints.

---

## Governance Caveats

- **G-062** is closed. Full-suite blockers were resolved, and Story 29.4 remains validated without release-level caveats from this item.

---

## Related Artifacts

- [story-29.4-slice-a-pilot-eligibility-review-closure.md](story-29.4-slice-a-pilot-eligibility-review-closure.md)
- [story-29.4-controlled-offline-sales-pilot-provisioning-ui-scope-lock.md](../../_bmad-output/planning-artifacts/story-29.4-controlled-offline-sales-pilot-provisioning-ui-scope-lock.md)
- [validated-implementation-roadmap.md](../roadmap/validated-implementation-roadmap.md)
- [task-ledger.md](../ai-governance/task-ledger.md) (G-064)
