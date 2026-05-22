# Story 30.4 - System Admin Persona-Based Views Scope Lock

Date: 2026-05-21
Status: Planning Locked / Deferred (No Implementation Approval)
Epic: 30 - System Admin Tenant Operations and Compliance Intelligence
Predecessor Context: Story 30.1, Story 30.3 Slices A-C, Story 30.2 Slices A-C

---

## 1. Goal

Define read-only persona-based System Admin dashboard and view variants for operational users without changing the current RBAC schema, role catalog, or permission model.

Story 30.4 is planning-only. It is not implementation approval.

---

## 2. In Scope

- Identify target platform personas for operational read-only view prioritization:
  - platform_admin
  - account_manager
  - compliance_reviewer
  - operations_support
- Define section priority and emphasis rules using existing dashboard data only.
- Define visibility rules using existing roles and permissions only.
- Produce a persona-view matrix for current sections and advisory data.
- Propose future least-privilege improvements as deferred recommendations.
- Define implementation guardrails and acceptance criteria for a future execution story.

---

## 3. Out of Scope

- Creating new roles.
- Creating new permissions.
- Migrating users.
- Changing RBAC schema.
- Enforcing new persona middleware.
- Auto-remediation or auto-suspension controls.
- Billing or subscription engine changes.
- POS, offline sync/posting, receipt, tax, GCT, Z-read, or e-journal changes.
- Any runtime code changes in this story.

---

## 4. Persona View Matrix (Planning Baseline)

| Persona | Primary Objective | Priority Dashboard Sections | De-emphasized Sections | Read/Write |
|---|---|---|---|---|
| platform_admin | Whole-system oversight and incident triage | readiness_counts, urgency_counts, compliance_counts, recent_sign_offs, tenant_urgency | none | Read-only |
| account_manager | Tenant health follow-through and operational escalation | tenant_urgency, readiness_counts, recent_sign_offs | deep branch-level compliance breakdown | Read-only |
| compliance_reviewer | Compliance and readiness verification | compliance_counts, readiness_counts, tenant_urgency signals | pilot operational trend emphasis | Read-only |
| operations_support | Day-to-day support queue triage | urgency_counts, tenant_urgency, pilot_counts | broad compliance aggregate detail | Read-only |

Notes:
- Story 30.4 does not hide data via new enforcement logic.
- Matrix is a presentation-priority planning baseline for future approval.

---

## 5. Visibility Rules (Existing Auth/RBAC Only)

- Continue using current platform admin access boundary already enforced for System Admin dashboard surfaces.
- Do not add middleware, policy, role mapping, or permission migration in this story.
- Persona variants are planning definitions only and must remain non-enforcing until separately approved.

---

## 6. Future Least-Privilege Recommendations (Deferred)

- Evaluate whether account_manager requires a narrowed section set versus platform_admin.
- Evaluate whether compliance_reviewer should receive compliance-first ordering by default.
- Evaluate whether operations_support should receive urgency-first triage ordering.
- Define explicit least-privilege policy changes only after concrete operational separation evidence is collected.

---

## 7. Approval Gate for Any Future Implementation Story

Implementation may proceed only after explicit approval of:

- concrete operational separation requirement
- target persona behaviors and routing strategy
- RBAC impact assessment
- migration/no-migration decision for existing users
- non-mutation and advisory-only boundary confirmation
- test plan covering access control and view behavior

Until approved, Story 30.4 remains planning-only.

---

## 8. Next Action

Accepted governance decision: Option 1 (defer implementation).

Story 30.4 remains planning-only and deferred. No implementation should begin until
all of the following are approved:

- a concrete operational separation requirement
- confirmed persona behavior
- RBAC impact assessment
- migration/no-migration decision
- approved test plan

Recommended follow-up:

1. Proceed to Story 30.5 as planning lock only.
2. Alternatively close Epic 30 with Story 30.4 and Story 30.5 both deferred.
