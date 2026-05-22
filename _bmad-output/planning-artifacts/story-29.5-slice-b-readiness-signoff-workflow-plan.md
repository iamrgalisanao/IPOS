# Story 29.5 — Slice B: Readiness Sign-Off Workflow

Status: Pending Mutation Boundary Approval
Date: 2026-05-21
Parent Story: 29.5 — Tenant Onboarding Readiness Review
Slice A Reference: story-29.5-slice-a-tenant-readiness-aggregation-closure.md

---

## Slice B Goal

Add a controlled System Admin readiness decision workflow on top of the Slice A
read-only readiness summary. The workflow records a platform operator decision for
`ready_for_pilot`, `ready_for_operations`, or `blocked`, preserves the readiness
snapshot at decision time, and writes an audit trail.

This is the first Story 29.5 mutation slice. It must not create or modify onboarding
records, pilot enablement settings, subscription settings, billing state, or offline
sync/posting behavior.

---

## Preconditions for Slice B Implementation

All of the following must be confirmed before implementation begins:

1. Slice A closure is accepted and recorded in governance.
2. Explicit mutation boundary approval is given by a human decision-maker.
3. The persistence approach for readiness decisions is approved.
4. The audit event name and payload shape are approved.
5. The rule for blocked tenants is approved:
   - Ready sign-offs are rejected when blockers exist.
   - A `blocked` decision may be recorded as an explicit review outcome with the
     blocker snapshot and operator notes.

---

## Proposed Slice B Scope

### What changes

| Component | Change |
|---|---|
| Database | Add append-only readiness sign-off table |
| Model | Add `TenantReadinessSignOff` model |
| `TenantReadinessService` | Add decision validation and snapshot creation helpers |
| Controller | Add POST endpoint to record readiness decision |
| Audit trail | Record readiness decision as platform-level audit event |
| Route additions | `POST {company}/sign-off-readiness` |
| Tests | Add feature tests for decision, guard, snapshot, audit, and authorization behavior |

### What does NOT change

- Tenant creation, activation, suspension, or archive behavior.
- Branch creation or branch lifecycle behavior.
- User, owner, admin, role, or permission assignment behavior.
- Sales machine profile creation or compliance registration behavior.
- Pilot enablement flags or offline sales settings.
- Subscription entitlements, feature gates, or billing behavior.
- Offline sync, posting, GCT, Z-read, e-journal, or receipt logic.

---

## Endpoint Contract

```
POST /system-admin/tenants/{company}/sign-off-readiness
Authorization: platform.admin middleware

Body (JSON):
{
  "state": "ready_for_pilot" | "ready_for_operations" | "blocked",
  "notes": "Human-readable decision context, required for blocked, optional otherwise"
}
```

### Success response

```json
{
  "success": true,
  "decision_id": "uuid",
  "signed_off_state": "ready_for_pilot",
  "readiness_state_calculated": "ready_for_pilot",
  "signed_off_at": "2026-05-21T00:00:00+08:00"
}
```

### Rejection response

Return HTTP 422 when a requested ready-state decision conflicts with current blockers
or the calculated readiness state.

```json
{
  "message": "Tenant readiness cannot be signed off while blockers remain.",
  "readiness_state_calculated": "blocked",
  "blockers": []
}
```

---

## Decision Rules

| Requested decision | Allowed when | Rejected when |
|---|---|---|
| `ready_for_pilot` | Calculated state is `ready_for_pilot` or `ready_for_operations` and no blockers exist | Any blocker exists |
| `ready_for_operations` | Calculated state is `ready_for_operations` and no blockers exist | Calculated state is `blocked` or `ready_for_pilot` |
| `blocked` | Always allowed when operator notes are present | Notes are missing |

`blocked` is not a readiness approval. It records a formal review decision that the
tenant is not ready and preserves the blocker snapshot for follow-up.

---

## Persistence Contract

Create append-only table: `tenant_readiness_sign_offs`

| Column | Purpose |
|---|---|
| `id` | UUID primary key |
| `tenant_id` | Tenant/company under review |
| `signed_off_by` | System Admin user id |
| `signed_off_state` | `ready_for_pilot`, `ready_for_operations`, or `blocked` |
| `readiness_state_calculated` | State calculated by `TenantReadinessService` at decision time |
| `notes` | Operator decision notes |
| `readiness_snapshot` | JSON snapshot of summary, checks, blockers, pending actions |
| `created_at` | Decision timestamp |

Append-only behavior is required. Slice B must not update or delete previous sign-off
records.

---

## Audit Trail

Proposed audit event: `tenant_readiness_signed_off`

Payload should include:
- `tenant_id`
- `decision_id`
- `signed_off_state`
- `readiness_state_calculated`
- `actor_id`
- `blocker_count`
- `notes_present`

Audit logging should use the existing platform/System Admin audit pattern where
available. If tenant context is required by the existing audit logger, implementation
must temporarily set and restore context without leaking state across requests.

---

## Required Tests

| # | Test |
|---|---|
| 1 | Platform admin can record `ready_for_pilot` when calculated state is ready for pilot |
| 2 | Platform admin can record `ready_for_operations` when calculated state is ready for operations |
| 3 | `ready_for_pilot` is rejected when blockers exist |
| 4 | `ready_for_operations` is rejected when calculated state is only `ready_for_pilot` |
| 5 | `blocked` decision succeeds with notes and stores blockers snapshot |
| 6 | `blocked` decision is rejected when notes are missing |
| 7 | Sign-off record preserves readiness snapshot at decision time |
| 8 | Audit event is recorded for successful decision |
| 9 | Tenant user receives 403 on sign-off endpoint |
| 10 | Unauthenticated user is redirected or denied according to existing auth behavior |
| 11 | Cross-tenant branch/profile data is not mutated or required |
| 12 | Full SystemAdmin suite remains green |

---

## Primary Risks

1. **False approval risk:** A stale readiness summary could be used to sign off a tenant
   whose data changed after the UI loaded.
   - Mitigation: Recalculate readiness server-side inside the POST request before
     validating the requested decision.

2. **Audit context leakage:** Platform Admin flows may need tenant-scoped audit context.
   - Mitigation: Use the existing temporary context pattern and restore prior context
     after logging.

3. **Misinterpreting `blocked`:** A blocked decision must not look like an approval.
   - Mitigation: Store `blocked` as a review outcome, require notes, and keep response
     labels explicit.

4. **Scope creep into remediation:** Operators may expect sign-off to fix blockers.
   - Mitigation: Slice B only records decisions; remediation remains in existing
     onboarding, compliance, subscription, or pilot-provisioning flows.

---

## Explicit Out of Scope for Slice B

- Creating tenants, branches, users, roles, or sales machine profiles.
- Editing compliance registration data.
- Enabling or disabling pilot/offline sales settings.
- Changing subscription plans, entitlement config, feature gates, or billing.
- Changing offline sync/posting, receipt, GCT, Z-read, e-journal, or tax logic.
- BIR/CPA certification or external compliance approval workflow.
- Printable/PDF/CSV export; this remains deferred to a later slice.

---

## Approval Gate

This plan must be explicitly approved before Slice B implementation begins. The
approval decision should confirm:

1. Mutation boundary is acceptable for readiness decision capture.
2. Append-only `tenant_readiness_sign_offs` persistence is approved.
3. `blocked` decision semantics are approved as a review outcome, not a readiness sign-off.
4. Audit event name and payload are acceptable.
5. Ready-state guard behavior is accepted.

