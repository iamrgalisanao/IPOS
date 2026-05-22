# Story 29.4 — Slice B: Pilot Enablement Controls

Status: Pending Mutation Boundary Approval
Date: 2026-05-20
Parent Story: 29.4 — Controlled Offline Sales Pilot Provisioning UI
Slice A Reference: story-29.4-slice-a-pilot-eligibility-review-readiness-checklist-plan.md

---

## Slice B Goal

Add controlled enable/disable mutations to the System Admin pilot provisioning workflow,
allowing a platform admin to activate or deactivate controlled offline sales at the
tenant, branch, or terminal level — but **only after the eligibility checklist returns
`ready`**. This is a gated, audited mutation layer on top of the read-only Slice A
foundation.

---

## Preconditions for Slice B Implementation

**All of the following must be confirmed before Slice B implementation begins:**

1. Slice A closure is accepted (evidence recorded).
2. Explicit mutation boundary approval is given by a human decision-maker.
3. The audit log schema (already implemented in Epic 28) can accept pilot-provisioning
   event types without structural migration.
4. Agreement on whether `offline_sales_enabled` writes are recorded in the existing
   AuditLogger or a new pilot event table.

---

## Proposed Slice B Scope

### What changes

| Component | Change |
|---|---|
| `PilotProvisioningController` | Add `enable()` and `disable()` POST endpoints |
| `PilotEligibilityService` | Add `guardReadiness()` — throws if outcome ≠ `ready` before mutations are allowed |
| Audit trail | Record pilot enable/disable as platform-level audit events |
| Route additions | `POST {company}/pilot-enable` + `POST {company}/pilot-disable` |

### What does NOT change

- `OfflineSettingsValidator` — remains read-only runtime guard (unchanged)
- `OfflineSyncBatch`, `OfflineTerminalJournal`, `OfflineSequenceRecovery` — no changes
- GCT/Z-read/e-journal engine — no changes
- BIR certification or CPA/BIR review workflow — explicitly out of scope
- Broad offline enablement across all tenants — explicitly out of scope

---

## Enable endpoint contract (proposed)

```
POST /system-admin/tenants/{company}/pilot-enable
Authorization: platform.admin middleware

Body (JSON):
{
  "branch_id": "<uuid>",           // required
  "profile_id": "<uuid>",          // required
  "enable_tenant":  true | false,  // optional; only when tenant level is off
  "enable_branch":  true | false,  // optional; only when branch level is off
  "enable_terminal": true | false  // optional; only when terminal level is off
}

Precondition check (server-enforced):
  - Run PilotEligibilityService::evaluate() before any write.
  - If outcome ≠ 'ready' after enabling the requested flags → reject with 422.

Success response:
{
  "success": true,
  "outcome": "ready",
  "enabled_at": "<iso8601>",
  "checks": [...]
}
```

```
POST /system-admin/tenants/{company}/pilot-disable
Authorization: platform.admin middleware

Body (JSON):
{
  "branch_id":  "<uuid>",
  "profile_id": "<uuid>",
  "level": "tenant" | "branch" | "terminal"
}

Effect: sets offline_sales_enabled = false at the specified level only.
Does not reset sequence prefix or status.
```

---

## Guard rule (non-negotiable)

The `enable()` action must:
1. Evaluate eligibility **before** writing.
2. Apply only the requested flag changes in a single DB transaction.
3. Re-evaluate eligibility **after** applying changes.
4. If the post-write evaluation is not `ready` → roll back and return 422.

This prevents partial-write states where a flag is enabled but the terminal
is still missing compliance fields or a prefix.

---

## Audit trail (proposed event types)

| Event | Recorded on |
|---|---|
| `pilot_enabled` | Successful enable at any level |
| `pilot_disabled` | Successful disable at any level |
| `pilot_enable_rejected` | Guard blocked the enable due to non-ready state |

Payload should include: `tenant_id`, `branch_id`, `profile_id`, `level`, `actor_id`,
`outcome_before`, `outcome_after`.

---

## Required Tests (Slice B)

| # | Test |
|---|---|
| 1 | Enable succeeds when outcome is ready |
| 2 | Enable rejected when outcome is not ready (compliance incomplete) |
| 3 | Enable rejected when outcome is not ready (prefix missing) |
| 4 | Disable succeeds at tenant level |
| 5 | Disable succeeds at branch level |
| 6 | Disable succeeds at terminal level |
| 7 | Enable is transactional — rolled back if post-write check fails |
| 8 | Audit event recorded on successful enable |
| 9 | Audit event recorded on successful disable |
| 10 | Audit event recorded when enable is rejected |
| 11 | Non-platform-admin receives 403 on both endpoints |
| 12 | Cross-tenant branch/profile mismatch returns 404 |

---

## Primary Risks

- Guard re-evaluation window: a race condition between the pre-write check and the
  actual write is possible in concurrent requests. Mitigate with a DB row-level lock
  or short advisory lock on the tenant + branch combination.
- Audit trail completeness: if the AuditLogger does not support `platform_action`
  actor type, a schema migration may be required. Verify before implementation.
- `offline_sales_enabled = true` at tenant level is a wide flag. Confirm whether
  enabling it for one branch/terminal unintentionally exposes other branches.
  If so, Slice B may need a more granular enablement model.

---

## Explicit Out of Scope for Slice B

- Enabling offline sales globally for all branches/tenants.
- Changing `OfflineSettingsValidator` runtime behavior.
- Offline sync/posting pipeline changes.
- GCT/Z-read/e-journal generation.
- BIR-certified claims or CPA/BIR review workflow.
- Pilot enrollment or expiry date tracking.

---

## Approval Gate

This plan must be explicitly approved before Slice B implementation begins. The
approval decision should confirm:

1. Mutation boundary is acceptable at this stage.
2. Audit trail approach (AuditLogger vs new event table) is agreed.
3. Wide-flag risk for `tenant.offline_sales_enabled` is addressed.
4. Race-condition mitigation strategy is accepted.
