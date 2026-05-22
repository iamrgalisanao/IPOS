# Story 29.5 Tenant Onboarding Readiness Review Implementation Readiness Checklist

**Date:** 2026-05-20  
**Epic:** 29 - Platform Tenant Provisioning and Subscription Feature Gating  
**Story:** 29.5 - Tenant Onboarding Readiness Review  
**Governance Ref:** G-065

---

## Executive Summary

Story 29.5 breaks into four safe, sequential implementation slices (A–D). Each slice is isolated, non-blocking to others, and preserves all onboarding/subscription/offline boundaries. This checklist confirms implementation readiness and defines the approval boundary before Slice A implementation begins.

---

## Approval Boundary (STRICT)

### What Story 29.5 Does
- ✅ Aggregates readiness status across Stories 29.1–29.4 components
- ✅ Provides read-only readiness summary endpoint
- ✅ Captures sign-off action with audit trail
- ✅ Exports lightweight readiness summary (PDF/CSV)

### What Story 29.5 Does NOT Do
- ❌ Create tenants, branches, users, sales machine profiles
- ❌ Trigger subscription engine changes or billing automation
- ❌ Enable/disable pilot or offline sync/posting behavior
- ❌ Perform BIR/CPA review or certification workflow
- ❌ Apply any new onboarding mutations
- ❌ Change GCT, Z-read, or e-journal logic

---

## Implementation Slices

### Slice A: Readiness Aggregation Service [ISOLATED, NON-BLOCKING]

**Purpose:**  
Implement `TenantReadinessService` to aggregate readiness status across all onboarding components.

**Scope:**
- Create `app/Services/TenantReadinessService.php`
- Method: `getReadinessSummary(Tenant $company): array`
  - Aggregates: tenant profile, subscription plan, branches, owner/admin assignments, sales machine profiles, compliance fields, feature gates, pilot eligibility
  - Returns: `[tenantId, tenantName, subscriptionPlan, branches[], admins[], blockers[], readinessState, checks[]]`
- Method: `calculateReadinessState(Tenant $company): string`
  - Evaluates all components; returns `ready_for_pilot` | `ready_for_operations` | `blocked`
- Method: `aggregateBlockers(Tenant $company): array`
  - Lists all pending actions (missing fields, unassigned admins, inactive terminals, feature gates misaligned)
- All methods are **read-only**; no mutations

**Design Notes:**
- Eager-load relationships to avoid N+1 queries
- Cross-tenant safety: uses global scopes; System Admin has `platform.admin` middleware
- No transaction wrapping (read-only)
- Return structure compatible with JSON serialization

**Validation:**
- Unit tests for `TenantReadinessService` (5–8 tests)
  - Test readiness aggregation accuracy
  - Test blocker detection
  - Test decision state calculation

**Exit Criteria:**
- ✅ Service compiles and passes unit tests
- ✅ No mutations to any onboarding/subscription model
- ✅ Service is ready for consumption by Slice B endpoint

---

### Slice B: System Admin Readiness API [DEPENDS ON SLICE A, NON-BLOCKING TO C/D]

**Purpose:**  
Implement read-only GET endpoint to display tenant readiness summary with blockers and next actions.

**Scope:**
- Add controller method: `PilotProvisioningController::readiness(Tenant $company): JsonResponse`
- Route: `GET /system-admin/tenants/{company}/readiness` → `system-admin.readiness`
- Middleware: `['auth', 'platform.admin']`
- Response structure:
  ```json
  {
    "tenant_id": "uuid",
    "tenant_name": "...",
    "subscription_plan": "...",
    "branches": [
      {
        "id": "uuid",
        "name": "...",
        "admin": "...",
        "compliance_complete": true/false,
        "pilot_ready": true/false,
        "pilot_outcome": "ready|pending|blocked"
      }
    ],
    "admins": [
      {
        "name": "...",
        "role": "system_admin|tenant_admin|branch_admin",
        "email": "..."
      }
    ],
    "blockers": ["Missing compliance: MIN", "..."],
    "readiness_state": "ready_for_pilot|ready_for_operations|blocked",
    "signed_off_at": "timestamp or null",
    "signed_off_by": "user name or null",
    "checks": {
      "tenant_profile_complete": true/false,
      "subscription_plan_assigned": true/false,
      "branch_count": n,
      "all_branches_active": true/false,
      "all_branches_have_admin": true/false,
      "all_profiles_compliance_complete": true/false,
      "feature_gates_aligned": true/false,
      "pilot_eligibility": "ready|pending|blocked"
    }
  }
  ```
- Inject `TenantReadinessService` in controller constructor
- Call service to aggregate and return

**Design Notes:**
- Controller action only; no new service logic
- Response is pure read from service
- Can coexist with existing `PilotProvisioningController` methods

**Validation:**
- Feature tests for readiness endpoint (3–4 tests)
  - Test endpoint returns correct structure
  - Test authorization (system admin only, tenant user 403)
  - Test cross-tenant isolation (wrong tenant 404)
  - Test accuracy of aggregated data

**Exit Criteria:**
- ✅ Endpoint compiles and routes correctly
- ✅ Feature tests pass
- ✅ Ready for Slice C sign-off mutation

---

### Slice C: Sign-Off Workflow [DEPENDS ON SLICE B, NON-BLOCKING TO D]

**Purpose:**  
Implement POST endpoint to capture readiness sign-off action with audit trail.

**Scope:**
- Add controller method: `PilotProvisioningController::signOffReadiness(Request $request, Tenant $company): JsonResponse`
- Route: `POST /system-admin/tenants/{company}/sign-off-readiness` → `system-admin.sign-off-readiness`
- Middleware: `['auth', 'platform.admin']`
- Request validation:
  ```json
  {
    "state": "ready_for_pilot" | "ready_for_operations",
    "reason": "string (optional)"
  }
  ```
- Logic:
  - Call `TenantReadinessService::calculateReadinessState($company)`
  - If calculated state = `blocked`, return 422 with blocker list (cannot sign off as blocked)
  - If sign-off state is `ready_for_pilot` but calculated state is `ready_for_operations`, allow (less-restrictive sign-off)
  - If sign-off state is `ready_for_operations` but calculated state is `ready_for_pilot`, return 422 (cannot skip to operations without pilot first)
  - Create sign-off record in new `tenant_readiness_sign_offs` table:
    - `tenant_id`, `signed_off_by` (actor_user_id), `signed_off_state`, `reason`, `readiness_checks` (snapshot of checks at sign-off time), `created_at`
  - Call `AuditLogger` to record action: `tenant_readiness_signed_off`, metadata with state/reason
  - Return 200 with: `{ success: true, signed_off_at, signed_off_state, readiness_state_calculated }`

**Schema Migration:**
- Create `tenant_readiness_sign_offs` table:
  - id (uuid)
  - tenant_id (uuid, foreign key)
  - signed_off_by (user_id)
  - signed_off_state (string: ready_for_pilot | ready_for_operations)
  - reason (text, nullable)
  - readiness_checks (json snapshot of checks at sign-off)
  - created_at
  - Indexes: tenant_id, created_at

**Design Notes:**
- Sign-off is appended only (no updates/deletes to sign-off records)
- Snapshot readiness_checks in sign-off record for audit trail
- AuditLog records action separately (TenantContext wrapper if needed)

**Validation:**
- Feature tests for sign-off endpoint (4–5 tests)
  - Test successful sign-off for ready_for_pilot
  - Test successful sign-off for ready_for_operations (after pilot)
  - Test rejection when state = blocked
  - Test rejection when attempting to skip pilot (sign-off operations before pilot)
  - Test audit log recorded
  - Test authorization

**Exit Criteria:**
- ✅ Migration runs without error
- ✅ Endpoint compiles and routes correctly
- ✅ Feature tests pass
- ✅ Sign-off records stored correctly

---

### Slice D: Export / Printable Summary [DEPENDS ON SLICE B, OPTIONAL]

**Purpose:**  
Implement lightweight printable/exportable readiness summary (PDF or CSV).

**Scope:**
- Add controller method: `PilotProvisioningController::exportReadinessSummary(Request $request, Tenant $company): Response`
- Route: `GET /system-admin/tenants/{company}/readiness/export` → query param `format=pdf|csv`
- Logic:
  - Call `TenantReadinessService::getReadinessSummary($company)`
  - If `format=pdf`: use `barryvdh/laravel-dompdf` to render HTML template
  - If `format=csv`: serialize as CSV with headers
  - Return file download

**Templates:**
- Simple HTML template for PDF: tenant name, sign-off state, branch list, blockers, checks
- CSV: flattened rows (one row per branch with tenant context)

**Design Notes:**
- No new service logic; consume existing `TenantReadinessService`
- Lightweight rendering; no charts or heavy graphics
- PDF template kept simple to avoid rendering issues

**Validation:**
- Feature tests for export endpoint (2–3 tests)
  - Test PDF export returns 200 with correct content-type
  - Test CSV export returns 200 with correct content-type
  - Test exports contain expected data

**Exit Criteria:**
- ✅ Endpoint compiles and routes correctly
- ✅ Export tests pass (happy path)
- ✅ Optional; can be deferred to post-pilot if time-constrained

---

## Implementation Readiness Checklist

### Pre-Implementation
- [ ] Scope lock approved by stakeholder
- [ ] Four slices understood and boundaries confirmed
- [ ] No blockers from G-062 (accounting regression remains separate)
- [ ] Developer assigned and ready to begin Slice A

### Slice A: Readiness Service
- [ ] Create `app/Services/TenantReadinessService.php`
- [ ] Implement `getReadinessSummary()` method
- [ ] Implement `calculateReadinessState()` method
- [ ] Implement `aggregateBlockers()` method
- [ ] Write unit tests (5–8 tests)
- [ ] Run tests: `./vendor/bin/pest tests/Unit/TenantReadinessServiceTest.php`
- [ ] Confirm no mutations to any model

### Slice B: Readiness Endpoint
- [ ] Add controller method `readiness()` to `PilotProvisioningController`
- [ ] Add route to `routes/web.php`
- [ ] Write feature tests (3–4 tests)
- [ ] Run tests: `./vendor/bin/pest tests/Feature/SystemAdmin/TenantReadinessReviewTest.php`
- [ ] Verify response structure and accuracy

### Slice C: Sign-Off Workflow
- [ ] Create migration `create_tenant_readiness_sign_offs_table.php`
- [ ] Run migration: `php artisan migrate`
- [ ] Add controller method `signOffReadiness()` to `PilotProvisioningController`
- [ ] Add route to `routes/web.php`
- [ ] Write feature tests (4–5 tests)
- [ ] Run tests
- [ ] Verify sign-off records stored and audit logged

### Slice D: Export Summary (Optional)
- [ ] Add controller method `exportReadinessSummary()` to `PilotProvisioningController`
- [ ] Add routes (pdf + csv variants)
- [ ] Create HTML template
- [ ] Write feature tests (2–3 tests)
- [ ] Run tests

### Post-Implementation (All Slices)
- [ ] Run full SystemAdmin suite: `./vendor/bin/pest tests/Feature/SystemAdmin`
- [ ] Confirm 0 regressions (prior: 53 tests / 269 assertions)
- [ ] Update roadmap: Story 29.5 from "Planning Lock Initiated" to "Implemented & Target-Locally Validated"
- [ ] Create closure artifact: `story-29.5-tenant-onboarding-readiness-review-closure.md`
- [ ] Update task ledger G-065: change status to "Implemented & Target-Locally Validated"

---

## Sprint Planning: Estimated Task Breakdown

| Slice | Task | Est. Effort | Dependencies | Owner |
|:---|:---|:---:|:---|:---|
| A | Readiness Service Implementation | 3 hrs | None | Dev |
| A | Readiness Service Unit Tests | 2 hrs | Service | QA/Dev |
| B | Readiness Endpoint Implementation | 2 hrs | Slice A | Dev |
| B | Readiness Endpoint Feature Tests | 2 hrs | Endpoint | QA/Dev |
| C | Sign-Off Schema Migration | 1 hr | None | Dev |
| C | Sign-Off Endpoint Implementation | 3 hrs | Slice B + Migration | Dev |
| C | Sign-Off Feature Tests | 2 hrs | Endpoint | QA/Dev |
| D | Export Service & Templates | 2 hrs | Slice B | Dev |
| D | Export Feature Tests | 1.5 hrs | Service | QA/Dev |
| **Post** | Full SystemAdmin Suite Regression | 1 hr | All Slices | QA |
| **Post** | Governance Artifacts & Closure | 1 hr | All Slices | Dev/PM |
| **Total** | | **~20.5 hrs** | Sequenced | Team |

---

## Risk Mitigation

| Risk | Mitigation |
|:---|:---|
| Readiness logic divergence from Story 29.1–29.4 implementations | Cross-reference each aggregation point with source story tests; review logic with dev team |
| Incomplete tenant data causing false negatives | Explicit null checks; fallback "pending" states; logging for inconsistencies |
| Performance on large tenants | Eager-load relationships; N+1 query testing; consider caching for heavy tenants (post-pilot) |
| Sign-off state transition logic errors | Feature tests cover happy path and edge cases (blocked state, skip pilot, etc.); review state logic carefully |
| G-062 accounting regression affecting test suite | Keep G-062 separate; if it blocks full suite, run targeted `tests/Feature/SystemAdmin` only |

---

## Validation Plan

### Targeted Test Suites
- **Unit:** `tests/Unit/TenantReadinessServiceTest.php` (5–8 tests)
- **Feature:** `tests/Feature/SystemAdmin/TenantReadinessReviewTest.php` (10–14 tests)
  - Readiness endpoint
  - Sign-off workflow
  - Export functionality
- **Regression:** `./vendor/bin/pest tests/Feature/SystemAdmin` (full suite)

### Expected Results
- All new tests pass (green)
- Full SystemAdmin suite: 53+ existing tests + ~13 new = ~66+ tests / ~330+ assertions
- Zero regressions

### Known Caveat
- **G-062:** Accounting regression remains separate. If full project test suite is needed, G-062 must be resolved or formally deferred before release sign-off.

---

## Approval Checkpoint

**This checklist defines Story 29.5 implementation readiness.**

### Before Slice A implementation begins, confirm:
- [ ] Scope lock approved by stakeholder
- [ ] All four slices understood and boundaries confirmed
- [ ] No blockers or scope changes identified
- [ ] Team ready to start Slice A
- [ ] G-062 remains tracked separately as non-blocking for Story 29.5

### Approval Gate:
**Stakeholder confirms:** "Proceed with Story 29.5 Slice A implementation"

---

## Next Actions

1. **Stakeholder Review:** Review scope lock and implementation readiness checklist
2. **Approval Gate:** Confirm scope and boundaries
3. **Slice A Implementation:** Upon approval, proceed with `TenantReadinessService` implementation
4. **Sequential Delivery:** Slices A–B–C–D in order; Slice D is optional if time-constrained

---

## Related Artifacts

- [story-29.5-tenant-onboarding-readiness-review-scope-lock.md](story-29.5-tenant-onboarding-readiness-review-scope-lock.md)
- [validated-implementation-roadmap.md](../../docs/roadmap/validated-implementation-roadmap.md)
- [task-ledger.md](../../docs/ai-governance/task-ledger.md) (G-065)
