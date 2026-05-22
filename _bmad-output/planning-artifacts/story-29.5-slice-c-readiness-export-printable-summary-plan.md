# Story 29.5 — Slice C: Lightweight Readiness Export / Printable Summary

Status: Approved for Read-Only Implementation
Date: 2026-05-21
Parent Story: 29.5 — Tenant Onboarding Readiness Review
Slice B Reference: story-29.5-slice-b-readiness-signoff-workflow-plan.md

---

## Slice C Goal

Add a lightweight read-only export surface for the tenant readiness review. The export
must help System Admins share or archive the current readiness state, blockers,
pending actions, branch/profile readiness, and sign-off history.

---

## In Scope

- `GET /system-admin/tenants/{company}/readiness/export`
- Formats: JSON, CSV, and simple printable HTML.
- Include readiness state, blockers, pending actions, checklist metrics, branch/profile
  readiness rows, and readiness sign-off history.
- System Admin authorization only.

---

## Out of Scope

- PDF certification format.
- BIR/CPA official review workflow.
- Tenant, branch, user, profile, pilot, subscription, billing, or offline mutations.
- Changes to offline sync/posting, GCT, Z-read, e-journal, receipt, or tax logic.

---

## Validation

- Export endpoint returns JSON payload with sign-off history.
- CSV export contains readiness summary, blockers, branches, and sign-off history.
- Printable HTML export contains tenant and readiness content.
- Tenant users remain forbidden.

