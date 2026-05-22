# Story 29.1A Wave 2 Implementation Checklist

Date: 2026-05-20
Status: In Progress (Slice A Complete, Slice B Phase B1 Complete, B2/C/D Deferred)
Scope: Execution checklist for approved Wave 2 strategy (`sales.pos`, `catalog.view`, `catalog.edit`) with incremental slice-based rollout and validation.

Decision Note: Slice A (`catalog.edit` admin write routes) is implemented and locally validated. Slice B Phase B1 (`catalog.view` on index routes) is implemented and locally validated. Slice B2 and Slices C/D remain deferred pending explicit approval.

---

## Governance Lock
- [ ] Confirm Wave 2 remains planning-only until explicit approval.
- [ ] Confirm Story 29.2 onboarding remains blocked.
- [ ] Confirm no changes to entitlement engine or billing behavior.
- [ ] Confirm user-level permission model remains unchanged.

---

## Global Preconditions (Before Any Slice)
- [ ] Reconfirm `config/subscriptions.php` feature matrix and default tier behavior.
- [ ] Revalidate route inventory for `sales.pos`, `catalog.view`, `catalog.edit`.
- [ ] Tag each target route as isolated vs shared dependency.
- [ ] Prepare rollback notes per slice (route middleware removals and nav fallback behavior).
- [ ] Define regression criteria for allow/deny + branch/context-sensitive behavior.

---

## Slice A - catalog.edit Admin Write Routes (Implemented)
Goal: Harden admin-only write surfaces with lowest operational risk.

### Route Scope Checklist
- [x] Enumerate `catalog.edit` write routes (`admin/products*`, `admin/product-categories*`, write actions).
- [x] Confirm no POS runtime read dependency is coupled to these write endpoints.
- [x] Mark excluded shared routes (if any) for later slices.

### Enforcement Plan Checklist
- [x] Add proposed middleware placement list (planning-only).
- [x] Confirm route-level permission checks remain intact after feature gating.
- [ ] Define nav handling for non-entitled users (hide preferred for admin write entry points).

### Test Plan Checklist
- [x] Deny test for non-entitled tenant (403 expected).
- [x] Allow test for entitled tenant with valid role/permission.
- [x] Branch/context interaction check for any branch-bound write endpoints.

### Approval Gate
- [x] Approve Slice A implementation package.

---

## Slice B - catalog.view Read Routes with Dependency Review
Goal: Gate read surfaces while protecting POS/inventory shared dependencies.

### Route Scope Checklist
- [x] Enumerate `catalog.view` read routes used by back-office.
- [x] Enumerate shared read dependencies used by POS and inventory flows.
- [x] Separate pure read/admin routes from shared runtime dependencies.

### Enforcement Plan Checklist
- [x] Plan middleware for isolated read routes first.
- [x] Define defer list for shared dependency endpoints requiring deeper review.
- [x] Define nav behavior: hide where fully unavailable, disable with explanation only when partial access is intentional.

### Test Plan Checklist
- [x] Allow/deny tests for isolated catalog read routes.
- [x] Regression checks ensuring POS/inventory critical reads are not accidentally blocked.
- [x] Branch/context-sensitive tests where applicable.

### Approval Gate
- [x] Approve Slice B Phase B1 implementation package.

---

## Slice C - sales.pos Checkout-Only Gate
Goal: Introduce safer first step for `sales.pos` by gating transaction-critical actions only.

### Policy Confirmation Checklist
- [ ] Confirm all active plans should include `sales.pos` by default.
- [ ] Confirm override policy for temporary disable scenarios.
- [ ] Confirm checkout-only gate is approved before shell-wide gate.

### Route Scope Checklist
- [ ] Enumerate checkout action routes (`create-sale`, payment submission, receipt-sensitive transaction endpoints).
- [ ] Exclude shell/bootstrap/search routes for this slice.
- [ ] Identify branch middleware dependencies and fallback behavior.

### Enforcement Plan Checklist
- [ ] Define checkout-only middleware placement list (planning-only).
- [ ] Validate cashier workflow continuity when entitled.
- [ ] Validate clear denial UX/error paths when not entitled.

### Test Plan Checklist
- [ ] Allow/deny tests for checkout endpoints by entitlement.
- [ ] Branch context + entitlement interaction tests.
- [ ] Cashier role + entitlement interaction tests.

### Approval Gate
- [ ] Approve Slice C implementation package.

---

## Slice D - Optional Full POS Shell Gate (Only If Later Approved)
Goal: Consider full shell gate only after checkout-only rollout is stable and reviewed.

### Preconditions
- [ ] Slice C implemented and validated with no critical regressions.
- [ ] Operational review confirms no tenant bootstrapping/cashier disruption.
- [ ] Explicit governance approval for shell-level gating.

### Route Scope Checklist
- [ ] Enumerate shell routes (`/pos`, `/pos/search`, `/pos/active-shift`, layout/bootstrap dependencies).
- [ ] Validate support fallback for non-entitled tenants.

### Test Plan Checklist
- [ ] Full shell allow/deny tests.
- [ ] Bootstrapping and shift workflow regression tests.
- [ ] Branch/context-sensitive shell entry tests.

### Approval Gate
- [ ] Approve Slice D implementation package (optional; may be deferred).

---

## Cross-Slice Safety Checks
- [ ] No onboarding flows (Story 29.2+) activated.
- [ ] No unintended route lockout for entitled tenants.
- [ ] Navigation behavior matches effective entitlement outcomes.
- [ ] Residual gap list updated after each implemented slice.

---

## Wave 2 Completion Criteria
- [ ] Each approved slice has implementation evidence + regression evidence.
- [ ] Deferred/high-risk surfaces are documented with reasons and next action.
- [ ] Governance review sign-off captured before moving to Story 29.2.
