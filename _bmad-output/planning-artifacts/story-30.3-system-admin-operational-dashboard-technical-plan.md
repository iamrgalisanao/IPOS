# Story 30.3 — System Admin Operational Dashboard Technical Plan

Date: 2026-05-21  
Status: Draft / Pending Approval  
Epic: 30 — System Admin Tenant Operations & Compliance Intelligence  
Source Lock: `story-30.3-system-admin-operational-dashboard-scope-lock.md`

---

## 1. Goal

Create a technical implementation roadmap for the System Admin Operational Dashboard. The dashboard will summarize tenant operational/compliance status using existing read-only data from Story 29.5 and Story 30.1.

**CRITICAL GOVERNANCE BOUNDARY:** Story 30.3 is a read-only operational intelligence dashboard. It must not create, update, suspend, remediate, or enable anything.

---

## 2. Implementation Slices

### Slice A — Dashboard Summary Service

**Objective:** Build a read-only backend service that aggregates data for the dashboard without relying on a UI.

**In Scope:**
- Aggregate tenant readiness counts (Blocked, Pending, Ready).
- Aggregate compliance detail counts (e.g., number of branches missing machine profiles, tenants with mismatched features).
- Aggregate controlled offline pilot readiness counts.
- Fetch the recent readiness sign-offs history list.
- Implementation logic isolated within `SystemAdminDashboardService` or similar.

**Out of Scope:**
- No UI components or HTTP endpoints yet.
- No mutation of existing readiness data.

### Slice B — Read-only Dashboard API

**Objective:** Expose the data gathered in Slice A through a secure, internal API endpoint exclusively for platform admins.

**In Scope:**
- Implement `GET /system-admin/operations-dashboard` endpoint.
- Protect the endpoint using existing `auth` and `platform.admin` middleware.
- Return a structured JSON payload formatted for dashboard cards, tables, and widgets.

**Out of Scope:**
- No new write/mutation endpoints.
- No public-facing APIs.

### Slice C — System Admin Dashboard UI

**Objective:** Build the frontend components to visualize the operational and compliance status payload.

**In Scope:**
- Top-level overview cards (Counts for Tenants, Readiness, Pilot status).
- Tenant status table or list displaying high-level states.
- Compliance and pilot readiness widgets for quick visual insight.
- Actionable links routing the admin to specific tenant readiness and compliance detail pages.
- Integrate smoothly with existing System Admin navigation.

**Out of Scope:**
- No UI elements to perform bulk operations, remediations, or suspensions.

### Slice D — Tests and Closure

**Objective:** Validate all slices through comprehensive feature and unit testing, followed by the generation of a governance closure artifact.

**In Scope:**
- Backend service tests ensuring accurate count aggregation.
- Endpoint access tests verifying proper `platform.admin` enforcement and correct payload shapes.
- UI payload/Inertia tests (if applicable) verifying the page component is rendered with the expected props.
- Create `story-30.3-system-admin-operational-dashboard-closure.md` artifact summarizing the completed scope and evidence.

---

## 3. Architecture Constraints

1. **Read-Only:** All queries and logic strictly retrieve and compute data. No `create()`, `update()`, or `delete()` calls on any tenant, branch, profile, or subscription models within this flow.
2. **Derived Data First:** Reuse the logic and source data from `TenantReadinessService` and `PilotEligibilityService` directly.
3. **No Scoring or AI/Automation:** No risk scoring algorithms, automated suspensions, or billing changes are to be triggered or modeled during these operations.
