# Story 30.5 - Optional Hardware Readiness Tracking Scope Lock

Date: 2026-05-21
Status: Planning Locked / Deferred (No Implementation Approval)
Epic: 30 - System Admin Tenant Operations and Compliance Intelligence
Predecessor Context: Story 30.1, Story 30.3 Slices A-C, Story 30.2 Slices A-C, Story 30.4 Planning Locked / Deferred

---

## 1. Goal

Evaluate whether hardware or device readiness should appear as a read-only advisory signal in System Admin operational visibility.

Story 30.5 is planning-only. It does not approve runtime implementation.

---

## 2. In Scope

- Define candidate hardware readiness advisory signals using existing data sources only.
- Define where optional hardware readiness could appear in existing System Admin dashboard surfaces.
- Define a read-only payload proposal for future review.
- Define advisory wording that avoids certification claims.
- Define future validation criteria for usefulness before implementation approval.

---

## 3. Out of Scope

- Blocking POS usage.
- Auto-disabling sales.
- Mandatory device sync enforcement.
- Offline sync or posting behavior changes.
- Receipt, tax, GCT, Z-read, or e-journal behavior changes.
- Hardware certification or compliance claims.
- Any billing or subscription enforcement changes.
- Any runtime code changes in this story.

---

## 4. Candidate Advisory Signals (Planning Baseline)

These are non-binding candidates for future review:

- machine_profile_present
- machine_profile_compliance_complete
- offline_sequence_status
- profile_status
- last_known_sync_age_days (only if already derivable from existing telemetry)

Notes:
- Candidate signals must remain advisory and read-only.
- If a signal requires new persistence or telemetry capture, it is deferred by default.

---

## 5. Advisory-Only Rules

- Hardware readiness, if implemented in a future story, must be informational only.
- Hardware readiness must not become a hard gate for checkout or sales flows without separate explicit approval.
- Any future UI language must avoid compliance or certification claims.

---

## 6. Approval Gate For Any Future Implementation Story

Implementation may proceed only after explicit approval of:

- a concrete operational requirement for hardware readiness visibility
- confirmed advisory signal definitions and source-of-truth mapping
- non-enforcement boundary confirmation
- no-impact assessment for POS and offline behavior
- approved test plan for read-only behavior and payload stability

Until approved, Story 30.5 remains planning-only and deferred.

---

## 7. Next Action

Default recommendation:

1. Close Epic 30 with Story 30.4 and Story 30.5 deferred.
2. Re-open Story 30.5 only when operational evidence shows hardware readiness is needed for support triage.
