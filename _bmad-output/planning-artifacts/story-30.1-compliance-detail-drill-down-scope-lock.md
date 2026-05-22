# Story 30.1 — Compliance Detail Drill-Down Scope Lock

Date: 2026-05-21  
Status: Implemented & Locally Validated  
Epic: 30 — System Admin Tenant Operations & Compliance Intelligence  
Source Handoff: `epic-30-system-admin-tenant-operations-compliance-intelligence-architecture-handoff.md`

---

## 1. Goal

Expand the existing System Admin readiness payload from aggregate pass/fail checks
into explainable compliance/readiness detail.

Story 30.1 should help a System Admin answer:

- Which readiness check failed?
- Which tenant, branch, admin, profile, or subscription field caused it?
- What existing source data supports the result?
- What human remediation action is expected?

This story is a read-only visibility improvement. It must not create new onboarding
records, change sign-off behavior, remediate blockers, enforce new gates, or mutate
POS/offline/compliance engines.

---

## 2. Architecture Decision

Derive compliance detail from existing data first.

No new persistence layer is approved for Story 30.1. The existing
`TenantReadinessService` already computes the required readiness facts from tenant,
branch, user/admin, sales machine profile, subscription metadata, pilot eligibility,
blockers, and pending actions. Story 30.1 should expose those details more clearly in
the readiness response.

Persisted compliance check history, time-series state tracking, or a dedicated
`compliance_check_log` table is deferred unless a later story proves that historical
per-check transitions are required.

---

## 3. In Scope

- Add a `compliance_detail` or equivalent explainability section to
  `TenantReadinessService::getReadinessSummary()`.
- Include per-check status, source, related entity, reason code, and remediation text
  for current readiness blockers and pending checks.
- Preserve the existing `checks`, `blockers`, `pending_actions`, `branches`, and
  `admins` payload fields for backward compatibility.
- Include branch-level detail for:
  - active/inactive branch state
  - branch admin presence
  - machine profile presence
  - sales machine compliance field completeness
  - pilot eligibility reasons already returned by `PilotEligibilityService`
- Include tenant-level detail for:
  - tenant profile completeness
  - subscription plan assignment
  - feature gate alignment
  - branch existence
- Add focused feature tests for detail payload shape and representative failure cases.

---

## 4. Out of Scope

- New compliance persistence tables.
- New audit log tables.
- New readiness sign-off decisions.
- Any change to `tenant_readiness_sign_offs` behavior.
- Automatic remediation.
- Auto-suspension.
- Automatic pilot enablement or disablement.
- Subscription billing or entitlement engine changes.
- POS checkout behavior changes.
- Offline sync/posting behavior changes.
- Receipt, tax, GCT, Z-read, or e-journal engine changes.
- BIR/CPA certification workflow or claims.
- System Admin persona or permission schema changes.
- Hardware sync as mandatory blocker.

---

## 5. Source Data Map

| Detail Area | Existing Source | Current Signal | Proposed Detail |
|---|---|---|---|
| Tenant profile | `Tenant` | `tenant_profile_complete` | status, name presence, remediation |
| Subscription plan | `Tenant.subscription_metadata` | `subscription_plan_assigned` | plan value, missing plan reason |
| Feature gate alignment | `Tenant.subscription_metadata`, `config/subscriptions.php` | `feature_gates_aligned` | invalid override keys or mismatch summary |
| Branch existence | `Branch` | `branch_count` | branch count and missing branch action |
| Branch activity | `Branch.status` | `all_branches_active` | per-branch active/inactive state |
| Branch admin coverage | `User`, `Role`, branch assignment | `all_branches_have_admin` | per-branch admin presence |
| Machine profile presence | `SalesMachineProfile` | branch `profile` presence | profile missing/completed state |
| Machine compliance | `SalesMachineProfile` required fields | `all_profiles_compliance_complete` | missing required fields by profile |
| Pilot eligibility | `PilotEligibilityService` | `pilot_outcome`, reasons | blocking and pending reasons per branch |
| Sign-off context | `TenantReadinessSignOff` | export sign-off history | not required for 30.1 readiness detail; may be reused by dashboard later |

---

## 6. Proposed Payload Shape

The exact key name may be adjusted during implementation, but the payload should be
stable and testable.

```json
{
  "compliance_detail": {
    "tenant": [
      {
        "code": "subscription_plan_assigned",
        "status": "passed",
        "severity": "info",
        "source": "tenant.subscription_metadata.plan",
        "entity": { "type": "tenant", "id": "..." },
        "message": "Subscription plan is assigned.",
        "remediation": null
      }
    ],
    "branches": [
      {
        "branch_id": "...",
        "branch_name": "Main",
        "checks": [
          {
            "code": "machine_profile_compliance",
            "status": "failed",
            "severity": "blocker",
            "source": "sales_machine_profiles",
            "entity": { "type": "sales_machine_profile", "id": "..." },
            "missing_fields": [
              "permit_to_use_number",
              "supplier_accreditation_number"
            ],
            "message": "Sales machine profile compliance fields are incomplete.",
            "remediation": "Complete sales machine compliance fields for branch Main."
          }
        ]
      }
    ]
  }
}
```

Recommended enums:

- `status`: `passed`, `failed`, `pending`, `not_applicable`
- `severity`: `info`, `warning`, `blocker`

---

## 7. Authorization Model

Use the existing System Admin route authorization:

- middleware: `auth`, `platform.admin`
- endpoint: `GET /system-admin/tenants/{company}/readiness`

No broader tenant-user access is approved in Story 30.1.

---

## 8. Implementation Notes

Preferred implementation:

- Add helper methods inside `TenantReadinessService`.
- Keep detail generation deterministic and derived from the same facts used for
  current blockers and checks.
- Preserve existing response fields so Story 29.5 tests and consumers remain stable.
- Avoid controller-level detail assembly; the service owns readiness interpretation.

Do not add an abstraction unless duplicate detail generation appears in at least three
places. One boring service method is enough for this slice.

---

## 9. Test Plan

Add focused tests to `tests/Feature/SystemAdmin/TenantReadinessReviewTest.php`.

Required scenarios:

- Platform admin receives `compliance_detail` in readiness response.
- Tenant with no branches includes tenant-level branch-missing detail.
- Branch without active admin includes branch admin detail.
- Branch without machine profile includes profile-missing detail.
- Branch with incomplete machine profile includes missing required field names.
- Misaligned feature overrides include feature gate detail.
- Operationally ready tenant returns passed details without introducing blockers.
- Tenant user remains forbidden from readiness endpoint.

Recommended command:

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/SystemAdmin/TenantReadinessReviewTest.php
```

Before closure, run:

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/SystemAdmin
```

---

## 10. Rollback Plan

Rollback is low-risk because Story 30.1 is additive and read-only.

If regression occurs:

1. Remove the new `compliance_detail` response section and helper methods.
2. Preserve existing Story 29.5 response fields.
3. Re-run `TenantReadinessReviewTest.php`.
4. Record the regression in the task ledger before attempting another detail shape.

Rollback success criteria:

- Existing readiness summary endpoint returns the Story 29.5 payload.
- Sign-off, export, pilot provisioning, subscription gates, POS checkout, and offline
  sync behavior are unchanged.

---

## 11. Approval Gate

Implementation may proceed only after approval of:

- derived-data-first architecture
- payload shape
- source data map
- read-only boundary
- test plan
- rollback plan

After approval, proceed with Story 30.1 Slice A: Compliance Detail Service /
Read-only API.

---

## 12. Scope-Lock Review Packet (Required Sections)

### 1. Exact Compliance Detail Categories

Story 30.1 must expose only the following compliance detail categories.

- Tenant profile completeness
- Subscription plan assignment
- Feature gate alignment
- Branch existence
- Branch active/inactive state
- Branch admin coverage
- Machine profile presence
- Sales machine compliance required-field completeness
- Pilot eligibility blockers and pending reasons

### 2. Source Data Fields / Services

Story 30.1 must derive detail from existing fields/services only.

- `Tenant`: profile/name metadata used by readiness checks
- `Tenant.subscription_metadata`: plan and feature gate override facts
- `Branch`: existence and active/inactive state
- `User` + role/branch assignment: branch admin coverage
- `SalesMachineProfile`: profile presence and compliance field completeness
- `PilotEligibilityService`: branch-level blockers/pending reasons
- `TenantReadinessService`: canonical readiness summary assembly

No new persistence or history table is approved in this story.

### 3. System Admin View/API Boundaries

Story 30.1 boundary is a read-only additive extension to the existing readiness
surface.

- Route boundary: `GET /system-admin/tenants/{company}/readiness`
- Auth boundary: existing `auth` + `platform.admin` middleware
- Contract boundary: additive `compliance_detail` payload only; existing payload
  keys remain backward compatible
- No new write endpoint is approved

### 4. Derived-Only Rules

Story 30.1 must follow strict derived-only behavior.

- No new source-of-truth entity for compliance checks
- No mutation side effects when computing detail
- No decision capture changes (sign-off behavior remains as-is)
- No automatic remediation or policy enforcement actions
- Detail must be computed from the same facts already used for readiness checks

### 5. Permission and Platform-Admin Access Rules

- Platform admin access remains allowed via existing policy/middleware boundary
- Non-platform users remain forbidden from System Admin readiness endpoint
- Story 30.1 does not introduce persona split or new role matrix
- Any future persona-scoped variations are deferred to Story 30.4

### 6. Test Matrix

| ID | Scenario | Expected Result |
|---|---|---|
| T1 | Platform admin requests readiness | 200 response includes `compliance_detail` |
| T2 | Tenant has no branches | tenant-level branch-missing detail present |
| T3 | Branch has no active admin | branch admin coverage check fails with remediation |
| T4 | Branch has no machine profile | machine profile presence check fails |
| T5 | Machine profile missing required fields | missing field list appears in branch check |
| T6 | Feature override mismatch | feature-gate alignment detail shows mismatch summary |
| T7 | Operationally ready tenant | checks pass without introducing synthetic blockers |
| T8 | Non-platform tenant user calls endpoint | forbidden response retained |
| T9 | Existing readiness consumers | legacy fields (`checks`, `blockers`, `pending_actions`) preserved |

### 7. Explicit Non-Goals

Story 30.1 is read-only and must not mutate any of the following records/domains:

- tenant
- branch
- machine profile
- subscription
- billing
- POS
- offline sync/posting
- receipt
- tax
- GCT
- Z-read
- e-journal

Additional non-goals:

- no provisioning mutations
- no automatic remediation
- no auto-suspension
- no certification/claim workflow changes
