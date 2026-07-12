---
title: 'Statutory Discount Approval Rules Foundation'
type: 'feature'
created: '2026-07-12'
status: 'done'
baseline_commit: '0cf5a6e478a011682d4df5695da71edb85b1ad38'
context:
  - '{project-root}/docs/roadmap/pos-admin-configuration-terminal-capability-backlog.md'
  - '{project-root}/docs/implementation-plans/statutory-discount-engine.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** IPOS displays a statutory-discount manager approval flow, but it is not safely enforced at checkout: manager identity and branch are not verified correctly, approvals are not context-bound or single-use, and checkout can attach an arbitrary approval record after cart or beneficiary data changes.

**Approach:** Establish a statutory-discount-only approval rules foundation using the existing `manager_approvals` and `audit_logs` infrastructure. Resolve tenant defaults with optional branch strengthening, verify an independent same-branch manager by account password and dedicated permission, bind a short-lived approval to a server-generated statutory context hash, and atomically consume it during sale creation.

## Boundaries & Constraints

**Always:** Treat `DiscountType.requires_approval` as a non-relaxable minimum; permit rules to strengthen approval to always-required but never disable a required type; use tenant-default plus deterministic branch override; require an active same-tenant, branch-assigned manager with `pos.approve_discount`; prohibit self-approval; throttle credential attempts; use a two-minute expiry; bind approval to tenant, branch, terminal, cashier, discount type, cart lines/totals, beneficiaries, and server statutory calculation using a versioned keyed HMAC; validate and consume once inside the sale transaction; audit sanitized issue, rejection, expiry, replay, and consumption events.

**Ask First:** POS PIN authorization, monetary/percentage thresholds, multiple approval levels, offline approval, administrator-initiated approvals, or integrating any non-statutory action.

**Never:** Create parallel `pos_approvals` or approval-specific audit tables; store passwords/PINs/raw beneficiary identity in approval metadata; allow configuration to waive statutory identity, VAT, MEMC, or approval requirements; trust client hashes/calculations; consume approval outside the sale transaction; alter void/refund, cash drawer, regular promotion, price override, offline import, or offline-sequence behavior.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Resolve rule | Tenant default with optional branch strengthening | Required-type minimum is preserved; branch rule wins deterministically | Missing rule falls back to discount-type requirement |
| Authorize | Cashier submits cart/beneficiary context plus manager credentials | Server recalculates, verifies manager, and issues two-minute context-bound approval | Wrong/inactive/cross-branch/unpermitted/self manager fails generically and is throttled |
| Checkout | Unchanged required statutory context and unused approval | Approval locks and consumes in the same transaction as sale creation | Any mismatch, expiry, reuse, or invalid state rejects the sale |
| Rollback | Sale creation fails after approval validation | Approval remains usable until expiry | No partial sale, discount, or approval consumption |
| Policy edit | Rule changes after approval issuance | Approval remains bound to captured rule/version and context | New requests use the new effective rule |

</frozen-after-approval>

## Code Map

- `database/migrations/*approval_rules*` -- tenant/branch statutory rule storage and uniqueness constraints.
- `database/migrations/*harden_manager_approvals*` -- lifecycle, terminal, discount, rule, context-HMAC, expiry, and consumption fields.
- `app/Models/ApprovalRule.php` and `app/Models/ManagerApproval.php` -- scoped rule and approval lifecycle models.
- `app/Services/POS/ApprovalRuleResolver.php` -- non-relaxable tenant/branch rule resolution.
- `app/Services/POS/ManagerAuthorizationService.php` -- credential verification, context construction, issuance, validation, and atomic consumption.
- `app/Http/Controllers/Admin/ApprovalRuleController.php` and `resources/js/Pages/Admin/ApprovalRules/Index.jsx` -- permission-protected strengthening controls.
- `app/Http/Controllers/POS/ManagerApprovalController.php` -- throttled statutory authorization endpoint.
- `app/Services/POS/SaleCreationService.php` -- server recalculation and transaction-bound approval consumption.
- `resources/js/Pages/POS/Components/SpecialDiscountModal.jsx` -- manager credential capture and direct returned approval reference.
- `app/Services/RbacSeeder.php`, `routes/web.php`, and `routes/api.php` -- dedicated permissions and protected routes.

## Tasks & Acceptance

**Execution:**
- [x] Add scoped approval-rule storage and harden existing manager approvals with database-backed lifecycle constraints.
- [x] Implement deterministic rule resolution plus versioned server context HMAC and manager authorization/consumption service.
- [x] Add admin rule UI that can only preserve or strengthen statutory approval requirements.
- [x] Repair the statutory modal authorization flow and enforce approval during server-side checkout recalculation/transaction.
- [x] Add dedicated permissions, throttling, sanitized audits, and focused tenant/branch authorization tests.

**Acceptance Criteria:**
- Given a required statutory discount, when checkout lacks a valid matching approval, then no sale or discount is persisted.
- Given a valid independent manager and unchanged statutory context, when checkout succeeds, then exactly one approval is consumed atomically with the sale.
- Given cart, beneficiary, calculation, cashier, terminal, rule, or branch drift, when checkout reuses the approval, then it is rejected without partial writes.
- Given checkout rollback, when the transaction fails, then the approval remains unconsumed until its original expiry.
- Given an administrator edits rules, when a discount type already requires approval, then no configuration can weaken that minimum.

## Spec Change Log

- 2026-07-12: Implemented the approved statutory-only foundation, preserving the frozen intent and boundaries.
- 2026-07-12: Review hardened tenant-plus-branch rule composition, terminal validation, invalid-context rejection, and checkout payload validation.

## Design Notes

The existing approval UUID is an authenticated reference, not a bearer credential. The authoritative context HMAC is created only by the server from normalized UUIDs, ordered cart lines, fixed decimal strings, minimized beneficiary attributes, statutory calculation outputs, and a schema-version marker. Approval events use existing audit logs; approval rows retain only the minimum immutable enforcement snapshot.

## Verification

**Commands:**
- `php artisan test tests/Feature/POS/ManagerAuthorizationTest.php tests/Feature/POS/StatutoryDiscountComplianceTest.php` -- authorization and checkout enforcement pass.
- `php artisan test tests/Feature/Admin/ApprovalRuleManagementTest.php tests/Feature/StatutoryDiscountServiceTest.php` -- rule isolation and statutory calculations remain green.
- `npm run build` -- admin rule and statutory modal UI compile.
- `git diff --check` -- Task 3 scoped changes have no whitespace errors.

## Suggested Review Order

**Checkout enforcement**

- Server snapshots, validates, and gates every submitted statutory calculation.
  [`SaleCreationService.php:118`](../../app/Services/POS/SaleCreationService.php#L118)

- Approval consumption locks and verifies the complete immutable sale context.
  [`ManagerAuthorizationService.php:82`](../../app/Services/POS/ManagerAuthorizationService.php#L82)

- Checkout rejects malformed statutory payloads before reaching persistence.
  [`ValidateCheckoutRequest.php:46`](../../app/Http/Requests/ValidateCheckoutRequest.php#L46)

**Authorization policy**

- Manager credentials, independence, branch assignment, permission, and terminal are verified together.
  [`ManagerAuthorizationService.php:26`](../../app/Services/POS/ManagerAuthorizationService.php#L26)

- Tenant and branch rules compose without weakening statutory type minimums.
  [`ApprovalRuleResolver.php:10`](../../app/Services/POS/ApprovalRuleResolver.php#L10)

- Database lifecycle fields support short-lived, single-use approvals.
  [`2026_07_12_100002_harden_manager_approvals_table.php:8`](../../database/migrations/2026_07_12_100002_harden_manager_approvals_table.php#L8)

**Administration and UI**

- Permission-protected updates store tenant defaults or branch-scoped strengthening rules.
  [`ApprovalRuleController.php:21`](../../app/Http/Controllers/Admin/ApprovalRuleController.php#L21)

- Admin UI communicates the non-relaxable statutory minimum.
  [`Index.jsx:5`](../../resources/js/Pages/Admin/ApprovalRules/Index.jsx#L5)

- POS authorization captures credentials and uses the returned approval directly.
  [`SpecialDiscountModal.jsx:164`](../../resources/js/Pages/POS/Components/SpecialDiscountModal.jsx#L164)

**Verification**

- Authorization tests cover credential rejection, rollback, and replay protection.
  [`ManagerAuthorizationTest.php:68`](../../tests/Feature/POS/ManagerAuthorizationTest.php#L68)

- Rule tests prove branch policy cannot weaken a tenant default.
  [`ApprovalRuleManagementTest.php:52`](../../tests/Feature/Admin/ApprovalRuleManagementTest.php#L52)
