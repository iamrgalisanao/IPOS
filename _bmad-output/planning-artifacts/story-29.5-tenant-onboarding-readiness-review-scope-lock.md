# Story 29.5 Tenant Onboarding Readiness Review Scope Lock

**Status:** Planning Lock Initiated  
**Date:** 2026-05-20  
**Epic:** 29 - Platform Tenant Provisioning and Subscription Feature Gating

---

## Goal

Create a final System Admin readiness review surface that consolidates all tenant onboarding, branch setup, owner/admin setup, sales machine compliance, subscription feature-gate status, and controlled offline pilot readiness into a single actionable dashboard/summary. Enable human operators to confidently assess and sign off on: "Ready for Pilot Operations" or "Ready for Full Production" or "Blocked — Action Required."

---

## In Scope

### Readiness Summary View
- **Tenant-level snapshot:** Tenant profile status, subscription plan, primary contact, created date, activation status.
- **Branch completeness:** List of all branches with status (active/inactive), branch admin assigned, compliance flag, ready indicator.
- **Owner/Admin team:** System admins, tenant admins, branch admins assigned; permission roles verified.
- **Sales Machine Compliance:** Per-branch sales machine profile status (profile created, compliance fields complete, prefix assigned, sequence active).
- **Feature-Gate Status:** Enabled/disabled modules per subscription plan; any feature-gate enforcement violations or pending approvals.
- **Controlled Offline Pilot Readiness:** Eligibility outcome (ready/pending/blocked) for each branch; reason for pending/blocked state (if any).

### Blockers & Pending Actions Surface
- **Aggregated blocker list:** Highlight any missing compliance fields, unassigned admins, inactive terminals, feature gates not aligning with subscription.
- **Pending actions:** What must be completed before "ready for pilot" or "ready for operations" can be declared.
- **Approval workflow:** Explicit "Sign Off as Ready for Pilot" or "Sign Off as Ready for Operations" button with audit trail.

### Decision State Capture
- **Three distinct readiness states:**
  - `ready_for_pilot`: All Story 29.x components complete; offline pilot eligibility verified; approved to proceed to early partner pilot.
  - `ready_for_operations`: All components verified; tenant approved for general production operations.
  - `blocked`: One or more blockers prevent readiness; requires explicit remediation.
- **Sign-off timestamp, actor (system admin), reason, and audit trail.**

### Export / Printable Summary (Lightweight)
- **Printable readiness summary:** PDF or simple HTML export containing tenant name, branch list, compliance status, sign-off state, and date.
- **CSV or JSON export of readiness checklist** for bulk audit or cross-tenant reporting (if lightweight to implement).
- **No heavy report generation;** prefer simple data serialization.

### Integration with Existing Stories
- **Story 29.1:** Tenant profile and subscription plan retrieved from `Tenant` and `Subscription` models.
- **Story 29.2:** Branch admin and owner/admin assignment verified via `User` role/tenant assignments.
- **Story 29.3:** Sales machine profile compliance fields (MIN, CCLC, CCLSM, TCC, operator permission, prefix, sequence) validated.
- **Story 29.4:** Pilot eligibility outcome (`PilotEligibilityService`) retrieved and displayed.

---

## Out of Scope

- **New onboarding mutations:** No new branch creation, no new profile registration, no new user role assignments. All mutations remain in Stories 29.1–29.4.
- **New offline sync/posting backend behavior:** Review is read-only; no changes to offline posting, GCT, Z-read, or e-journal logic.
- **BIR/CPA review workflow:** No BIR certification or CPA sign-off flow. This remains a controlled pilot readiness check only.
- **Billing automation:** No new payment capture, subscription tier activation, or billing event triggers.
- **Tenant migration or re-onboarding:** View assumes tenant is already provisioned and branches/profiles already exist.

---

## Preconditions

- Story 29.4 Slice B implementation is validated and closed.
- All Stories 29.1, 29.1A, 29.2, 29.3, 29.4 are completed with validated test suites.
- `PilotEligibilityService` is available and returning accurate eligibility outcomes.
- Database models (`Tenant`, `Branch`, `SalesMachineProfile`, `User`, `AuditLog`) are stable.
- G-062 accounting regression remains separate and non-blocking for Story 29.5 planning/implementation.

---

## Primary Risks

1. **Readiness checklist divergence:** If readiness logic does not align with actual Story 29.1–29.4 implementations, false positives or false negatives could emerge.
   - Mitigation: Cross-reference each checklist item with the corresponding story's implementation and test coverage.

2. **Incomplete tenant data:** If branches, profiles, or users are in inconsistent states (e.g., profile exists but compliance incomplete), readiness aggregation must handle gracefully.
   - Mitigation: Explicit null checks, fallback "pending" states, and logged warnings for data inconsistencies.

3. **Authorization boundaries:** System admin can see all tenants; must ensure no tenant-level data leakage via readiness view.
   - Mitigation: Enforce platform-admin-only access; use `withoutGlobalScopes()` for cross-tenant reads where needed.

4. **Performance on large tenants:** Aggregating readiness across many branches and profiles could be slow.
   - Mitigation: Eager-load relationships; consider caching or background job for heavy tenants.

---

## Validation Plan (Targeted)

- **Readiness review feature tests:** Create `SystemAdmin/TenantReadinessReviewTest.php` with 10–15 tests covering:
  - Tenant readiness summary structure and content accuracy.
  - Blocker aggregation (missing compliance, unassigned admin, inactive terminals).
  - Decision state transitions and audit trail recording.
  - Authorization (system admin only).
  - Export/printable summary generation (smoke test).
- **No new controller mutations:** All endpoints are read-only GET requests and POST for sign-off action only.
- **Re-run full SystemAdmin suite:** Ensure no regressions from Stories 29.1–29.4.
- **Preserve G-062 accounting follow-up** as non-blocking for Story 29.5 planning/implementation.

---

## Architecture Notes

### Proposed Service Layer

**`TenantReadinessService`**
- `getReadinessSummary(Tenant $company): array` — Aggregates all components, returns readiness state and blockers.
- `aggregateBlockers(Tenant $company): array` — Lists all pending actions and blockers.
- `calculateReadinessState(Tenant $company): string` — Evaluates and returns `ready_for_pilot`, `ready_for_operations`, or `blocked`.
- `recordSignOff(Tenant $company, string $state, string $reason, User $actor): void` — Logs sign-off action to AuditLog.

### Proposed Endpoint

**`GET /system-admin/tenants/{company}/readiness`** → JSON summary with:
```json
{
  "tenant_id": "uuid",
  "tenant_name": "...",
  "subscription_plan": "...",
  "branches": [ { "id", "name", "status", "compliance_complete", "pilot_ready" } ],
  "admins": [ { "name", "role", "email" } ],
  "blockers": [ "Missing compliance: MIN", "..." ],
  "readiness_state": "ready_for_pilot" | "ready_for_operations" | "blocked",
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
    "pilot_eligibility": "ready" | "pending" | "blocked"
  }
}
```

**`POST /system-admin/tenants/{company}/sign-off-readiness`** → Accepts:
```json
{
  "state": "ready_for_pilot" | "ready_for_operations",
  "reason": "..."
}
```
Returns: HTTP 200 with audit log ID or HTTP 422 if blockers remain.

---

## Exit Criteria

- ✅ Planning lock is approved for Story 29.5 implementation.
- ✅ Readiness checklist aligns with Stories 29.1–29.4 implementations.
- ✅ Governance docs updated with Story 29.5 planning state.
- ✅ Service layer and endpoint structure confirmed.
- ✅ No conflicts with existing onboarding/provisioning boundaries.
- ✅ Stakeholder (human owner / product manager) reviews and approves scope.

---

## Next Action

Submit scope lock for stakeholder review and approval. Upon approval, proceed to Story 29.5 implementation readiness checklist and sprint planning.
