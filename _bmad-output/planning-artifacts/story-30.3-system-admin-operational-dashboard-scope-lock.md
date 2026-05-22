# Story 30.3 — System Admin Operational Dashboard Scope Lock

Date: 2026-05-21  
Status: Planning Locked  
Epic: 30 — System Admin Tenant Operations & Compliance Intelligence  
Source Handoff: `epic-30-system-admin-tenant-operations-compliance-intelligence-architecture-handoff.md`

---

## 1. Goal

Create a System Admin dashboard that summarizes tenant operational/compliance status using existing read-only data from Story 29.5 and Story 30.1.

---

## 2. Architecture Decision

Build a read-only, aggregate view relying on the data structures implemented in Story 29.5 (Tenant Readiness) and Story 30.1 (Compliance Detail Drill-Down). 

No new risk scoring algorithm, persistence mechanism, or remediation automation should be introduced at this stage. The goal is surface-level visibility based on established signals.

---

## 3. In Scope

- Tenant readiness overview cards
- Compliance status summary
- Blocked / pending / ready tenant counts
- Recent readiness sign-offs
- Controlled offline pilot readiness summary
- Links to tenant compliance detail drill-down
- Read-only dashboard endpoint / view

---

## 4. Out of Scope

- Risk scoring algorithms
- Auto-remediation
- Auto-suspension
- New provisioning mutations
- Billing/subscription changes
- POS/offline/tax engine changes

---

## 5. Explicit Non-Goals

Story 30.3 is read-only and derived-only.
It does not change provisioning, billing, subscription engine behavior, POS checkout, offline sync/posting, receipt, tax, GCT, Z-read, e-journal, automatic remediation, auto-suspension, or persona/permission schema behavior.
