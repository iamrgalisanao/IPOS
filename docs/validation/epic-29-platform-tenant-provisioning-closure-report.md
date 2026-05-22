# Epic 29 — Platform Tenant Provisioning and Compliance Onboarding Closure Report

**Status:** Implemented & Locally Validated  
**Date:** 2026-05-21  
**Governance Refs:** G-059, G-060, G-061, G-063, G-064, G-065

---

## 1. Executive Summary

Epic 29 establishes the System Admin provisioning layer for tenant onboarding,
subscription and feature visibility, branch and owner setup, sales machine compliance
registration, controlled offline sales pilot provisioning, and final tenant readiness
review.

The epic is implemented and locally validated. Feature-gate hardening is substantially
complete, the full suite is clean, and the only remaining feature-gate item is optional
full POS shell gating tracked as a non-blocking future enhancement.

---

## 2. Completed Stories

- **Story 29.1 — Platform Tenant Provisioning Foundation**  
  Added System Admin tenant provisioning, lifecycle status controls, subscription plan
  assignment, feature visibility, tenant override visibility, readiness visibility, and
  self-escalation protection.

- **Story 29.1A — Feature Gate Enforcement Coverage Hardening**  
  Completed Wave 1 enforcement for reports/procurement/layout, Wave 2 Slice A for
  `catalog.edit` write routes, Wave 2 Slice B1 for `catalog.view` safe index routes,
  Wave 2 Slice B2 for product create/edit form route gating, and Wave 2 Slice C for
  checkout-sensitive `sales.pos` routes. Optional full POS shell gating is documented
  and deferred.

- **Story 29.2 — Initial Branch and Owner Admin Setup**  
  Added initial branch creation, owner/admin creation, role assignment, bootstrap token
  generation, onboarding initialization, and onboarding event tracking.

- **Story 29.3 — Sales Machine Profile and Compliance Registration**  
  Added sales machine profile registration/update, compliance completeness evaluation,
  machine profile onboarding event recording, and readiness integration.

- **Story 29.4 — Controlled Offline Sales Pilot Provisioning UI**  
  Added read-only pilot eligibility review and controlled enable/disable pilot mutations
  with transaction rollback, audit logging, and wide-flag protection.

- **Story 29.5 — Tenant Onboarding Readiness Review**  
  Added readiness aggregation, append-only readiness sign-off, and lightweight JSON/CSV/
  printable HTML readiness exports.

---

## 3. Validation Evidence

| Story | Evidence | Result |
| :--- | :--- | :--- |
| 29.1 | `tests/Feature/SystemAdmin/TenantProvisioningTest.php` | 7 tests / 59 assertions passing |
| 29.2 | `tests/Feature/SystemAdmin`, targeted onboarding/provisioning/audit domain | 18 SystemAdmin tests passing; 27 targeted tests passing |
| 29.3 | `tests/Feature/SystemAdmin`, onboarding/provisioning/audit targeted suites | 22 tests / 127 assertions passing; 31 tests / 147 assertions passing |
| 29.4 | `PilotProvisioningTest.php`, `PilotProvisioningMutationTest.php`, full SystemAdmin suite | 18 tests / 96 assertions; 13 tests / 46 assertions; 53 tests / 269 assertions passing |
| 29.5 | `TenantReadinessReviewTest.php`, full SystemAdmin suite | 16 tests / 84 assertions; 69 tests / 353 assertions passing |
| Full suite baseline | `php -d memory_limit=512M ./vendor/bin/pest` | 1351 passed / 0 failed / 0 risky / 0 incomplete / 6237 assertions |

---

## 4. Feature-Gating Status

- Existing subscription feature-gating engine is exposed in System Admin.
- Wave 1 enforcement completed for reports, procurement, and layout routes.
- Wave 2 Slice A completed for `catalog.edit` write routes.
- Wave 2 Slice B1 completed for `catalog.view` safe index routes.
- Wave 2 Slice B2 completed for product create/edit form route gating.
- Wave 2 Slice C completed for POS checkout-only `sales.pos` gating.

### Residual Feature-Gate Follow-Up

- Optional full POS shell gating only.

This item is documented and deferred as a non-blocking enhancement. It does not block
Epic 29 closure.

---

## 5. Governance Boundaries

Epic 29 does not:

- Rebuild the subscription engine.
- Implement billing automation.
- Claim BIR certification.
- Change offline sync/posting engines.
- Alter local GCT, Z-read, e-journal, receipt, or tax behavior.
- Replace formal CPA/BIR review where applicable.

---

## 6. Residual Follow-Ups

- **G-066:** Full-suite risky/incomplete test cleanup is closed. Latest full-suite validation: 1351 passed / 0 failed / 0 risky / 0 incomplete / 6237 assertions.
- Feature-gate hardening is substantially complete; only optional full POS shell gating remains deferred for future approval.
- Optional onboarding UX polish may be handled later.
- Future CPA/BIR review remains separate where applicable.

---

## 7. Final Governance Decision

Epic 29 is implemented and locally validated. It is accepted for final closure.
Feature-gate hardening is substantially complete, with only optional full POS shell
gating deferred as a non-blocking enhancement. Optional onboarding UX polish remains
tracked separately.
