# Story 30.3 Slice C — System Admin Dashboard UI Plan

## Objective
Create the System Admin operational dashboard UI that consumes the read-only dashboard summary API (`/api/system-admin/dashboard/summary`) developed in Slice B.

## Scope
**In Scope:**
- System Admin dashboard page component (e.g., `resources/js/Pages/SystemAdmin/Dashboard/Index.jsx`).
- React components/sections for summary cards:
  - Tenant readiness counts (`ready_for_operations`, `ready_for_pilot`, `blocked`)
  - Compliance status counts (missing profile/plan, mismatched features, missing branches/admin, inactive branches, compliance failures)
  - Pilot readiness counts (`ready`, `pending`, `blocked`)
  - Recent sign-off history list
- UI links to tenant readiness and compliance detail pages.
- Data fetching logic using Axios from the `api.system-admin.dashboard.summary` endpoint.
- Proper loading and error states for robust UX.
- Strictly read-only implementation with no mutation buttons or interactions that submit form data.

**Out of Scope:**
- Risk scoring and urgency algorithms
- Auto-remediation actions
- Tenant suspension actions
- Any provisioning or pilot enablement mutations (must go through existing distinct UI pages)
- Billing/subscription changes
- POS/offline/tax engine changes

## Implementation Steps
1. **Routing Setup**:
   - Define a web route in `routes/web.php` for `GET /system-admin/dashboard` returning `Inertia::render('SystemAdmin/Dashboard/Index')`.
2. **Page Component**:
   - Scaffold `resources/js/Pages/SystemAdmin/Dashboard/Index.jsx`.
   - Use the existing Inertia React stack (`@inertiajs/react`) and local React component patterns.
   - Setup API client logic (`axios.get('/api/system-admin/dashboard/summary')`) inside the page.
3. **Dashboard Layout**:
   - Create visually distinct sections for Readiness, Compliance, Pilot Eligibility, and Recent Sign-Offs.
4. **Integration**:
   - Ensure the recent sign-off history can navigate to the Tenant Readiness review page for the specific tenant.
5. **Testing**:
   - Since the UI is Inertia React, verify component routing with standard HTTP/Inertia tests (ensure the dashboard web route loads without error for System Admins and is blocked for others).
   - Keep frontend behavior read-only; no form submissions or mutation buttons.

## Governance Statement
Slice C focuses strictly on visualizing read-only data aggregated by the System Admin Dashboard API. It will not perform or enable any mutations to tenant state, offline setup, or tax compliance structures.
