# Story 29.1A Wave 2 Planning - sales.pos and catalog.* Enforcement Strategy

Date: 2026-05-20
Status: Planning Only / Scope Locked
Implementation Phase: Not Started (No Code Changes)

---

## 1. Purpose
Define a risk-ranked enforcement strategy for deferred feature flags (`sales.pos`, `catalog.view`, `catalog.edit`) before any Wave 2 middleware rollout.

Governance boundary: Wave 2 in this artifact is planning-only. No route, controller, or UI enforcement code is changed by this plan.

---

## 2. Governance Note
Story 29.1A Wave 1 hardens feature-gate enforcement coverage using the existing subscription feature system. It does not rebuild entitlement logic, change billing behavior, alter user-level permissions, or continue tenant onboarding work.

---

## 3. Required Decisions Before Wave 2 Implementation

### 3.1 Plans that must include `sales.pos`
Current `config/subscriptions.php` shows all tiers include `sales.pos`:
- basic
- professional
- enterprise

Wave 2 decision gate:
- Confirm policy that all production tiers must retain `sales.pos = true` by default.
- Confirm whether tenant override may disable `sales.pos` (exception mode) and who can approve it.

### 3.2 POS lock scope when `sales.pos` is disabled
Decision options:
- Option A (lower disruption): block checkout/create-sale flows only; keep non-transactional shell visible.
- Option B (higher restriction): block entire POS shell (`/pos*`) including search, layout fetch, and shift-adjacent entry points.

Wave 2 recommendation:
- Start with Option A in planning assumptions, then run branch/cashier workflow impact review before implementation.

### 3.3 `catalog.view` and `catalog.edit` route mapping
Target mapping proposal:
- `catalog.view`: read/list/show/search routes needed by back office and POS read-only catalog dependencies.
- `catalog.edit`: create/update/delete/management routes (`admin/products*`, `admin/product-categories*`, branch pricing edits, recipe update actions).

### 3.4 Shared dependency analysis (POS + Inventory)
Wave 2 must classify catalog endpoints into:
- Safe to gate directly (pure admin edit surfaces)
- Shared runtime dependencies (POS/Inventory read paths) requiring phased rollout and fallback-safe behavior

### 3.5 Navigation behavior rules
- Hide menu item when route family is entirely unavailable to tenant plan.
- Disable (with explanation) only where route remains partially available and workflow continuity is required.
- Avoid exposing links to routes blocked by plan gate.

### 3.6 Branch/context-sensitive test strategy
Wave 2 test matrix must include:
- tenant entitled + valid branch context (allow)
- tenant not entitled + valid branch context (deny)
- tenant entitled + missing branch context (existing branch middleware behavior preserved)
- tenant with override toggles against plan defaults
- cashier vs owner/admin role overlays (permission + feature interactions)

---

## 4. Risk Ranking

### High Risk
- `sales.pos` route family and checkout paths:
  - direct revenue-path impact
  - branch context dependencies
  - cashier usability/bootstrapping implications

### Medium Risk
- `catalog.view` read surfaces:
  - shared dependencies with POS and inventory flows
  - potential indirect breakage in search/list APIs

### Low to Medium Risk
- `catalog.edit` admin-only write surfaces:
  - usually isolated to back-office admin routes
  - still requires role/permission interplay validation

---

## 5. Wave 2 Planning Sequence (No Code Changes)
1. Confirm `sales.pos` plan policy and override policy with governance owner.
2. Build full route inventory for `sales.pos`, `catalog.view`, `catalog.edit` with dependency tags.
3. Produce route classification: isolated vs shared-critical.
4. Produce UI navigation rule table (hide vs disable) per module/surface.
5. Produce test plan for branch/context-sensitive scenarios.
6. Submit implementation-ready checklist for Wave 2 execution approval.

---

## 6. Explicit Non-Goals for This Plan
- No middleware additions in this artifact.
- No entitlement engine changes.
- No billing behavior changes.
- No onboarding story progression (29.2+) until Wave 2 plan review is approved.
