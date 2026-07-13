---
title: 'Epic 37 Promotions Gap Closure'
type: 'feature'
created: '2026-07-13'
status: 'done'
baseline_commit: '81ca8a55a45449af01db713c84142a6fae1db410'
context:
  - '{project-root}/docs/roadmap/epic-37-38-39-proposed-specifications.md'
  - '{project-root}/docs/user-guide/changelog.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Epic 37 Phase A has backend promotion calculation and persistence, but the admin management surface is not routed/renderable and one conflict-policy edge case is under-specified in tests. This leaves managers unable to configure promotions from Back Office and creates ambiguity about priority versus customer-benefit ordering.

**Approach:** Wire the existing `PromotionController` into tenant admin routes, add a native Inertia management page for create/edit/deactivate of BOGO, minimum-spend, and combo promotions, and harden tests around the documented highest-benefit conflict rule. Keep the implementation inside current Phase A surfaces; do not start offline preview, reporting dashboards, or loyalty features.

## Boundaries & Constraints

**Always:** Keep commercial promotions structurally separate from statutory discounts; preserve tenant and branch scoping; use `manage_promotions` permission; use server-side calculation as the source of truth; protect route model updates from cross-tenant access; match existing Laravel + React/Inertia patterns.

**Ask First:** Any new database schema, a new subscription feature gate, a broad navigation redesign, or changing existing non-Epic-37 worktree changes.

**Never:** Implement offline JS promotion preview, coupon redemption, loyalty points, X/Z reporting, or refund accounting expansions in this slice. Do not alter statutory discount tables or statutory calculation records.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|----------------------------|----------------|
| Admin create | Authorized admin submits promotion and one supported rule | Promotion, branches, and rule are persisted; user returns to the index with success feedback | Validation errors stay on the form |
| Cross-tenant branch | Payload includes a branch outside tenant | Request is rejected | Validation error/403, no promotion persisted |
| Conflict policy | Two non-stackable promotions match same line, lower priority gives better customer savings | Higher benefit promotion wins; priority is a tie-breaker | Targeted test fails if ordering regresses |
| Unauthorized access | User lacks `manage_promotions` | Route is forbidden | 403 response |

</frozen-after-approval>

## Code Map

- `routes/web.php` -- Admin route wiring for promotion index/store/update/destroy.
- `app/Http/Controllers/Admin/PromotionController.php` -- Existing controller requiring tenant/permission hardening and page props.
- `resources/js/Pages/Admin/Promotions/Index.jsx` -- New Inertia admin management screen.
- `app/Services/POS/PromotionCalculationService.php` -- Conflict ordering implementation.
- `tests/Feature/POS/PromotionCalculationTest.php` -- Existing calculation coverage; add priority-vs-benefit regression test.
- `tests/Feature/Admin/PromotionManagementTest.php` -- New route/controller tests for admin CRUD and authorization.
- `docs/user-guide/changelog.md` -- Note Phase A gap closure evidence.

## Tasks & Acceptance

**Execution:**
- [x] `routes/web.php` -- Add `manage_promotions` protected admin promotion routes.
- [x] `app/Http/Controllers/Admin/PromotionController.php` -- Validate tenant-scoped branch IDs, enforce permission consistently, and normalize page props.
- [x] `resources/js/Pages/Admin/Promotions/Index.jsx` -- Build compact admin UI for listing, creating, editing, and deactivating supported promotion rules.
- [x] `app/Services/POS/PromotionCalculationService.php` -- Align conflict sorting with documented highest-benefit-first policy and deterministic tie-breakers.
- [x] `tests/Feature/POS/PromotionCalculationTest.php` -- Add regression for lower-priority higher-benefit winning.
- [x] `tests/Feature/Admin/PromotionManagementTest.php` -- Cover index, create, tenant branch guard, and unauthorized access.
- [x] `docs/user-guide/changelog.md` -- Add concise validation evidence for this closure slice.

**Acceptance Criteria:**
- Given an authorized tenant admin, when they open `/admin/promotions`, then the page renders existing tenant promotions with branch and rule details.
- Given a supported promotion payload, when it is submitted, then the promotion rule is persisted and visible on the index.
- Given overlapping non-stackable candidates, when one has higher customer benefit but lower priority, then the higher-benefit candidate applies.
- Given a user without `manage_promotions`, when they access promotion admin routes, then the request returns 403.

## Spec Change Log

## Verification

**Commands:**
- `DB_DATABASE=tsms_db_test php artisan test tests/Feature/POS/PromotionCalculationTest.php tests/Feature/Admin/PromotionManagementTest.php` -- expected: all tests pass.
- `npm run build` -- expected: frontend compiles successfully.

## Suggested Review Order

**Admin Entry Points**

- Route group exposes promotion CRUD behind `manage_promotions`.
  [`web.php:738`](../../routes/web.php#L738)

- Index scopes promotions and selector data to tenant/branch access.
  [`PromotionController.php:20`](../../app/Http/Controllers/Admin/PromotionController.php#L20)

**Validation And Persistence**

- Store validates branch scope, rule shape, pairing, and tenant-owned references.
  [`PromotionController.php:60`](../../app/Http/Controllers/Admin/PromotionController.php#L60)

- Update recreates a missing rule instead of silently leaving an invalid promotion.
  [`PromotionController.php:207`](../../app/Http/Controllers/Admin/PromotionController.php#L207)

- Deactivate preserves history by setting inactive instead of soft-deleting.
  [`PromotionController.php:235`](../../app/Http/Controllers/Admin/PromotionController.php#L235)

- Pairing and reference guards prevent crafted inconsistent rule payloads.
  [`PromotionController.php:307`](../../app/Http/Controllers/Admin/PromotionController.php#L307)

**Calculation Policy**

- Conflict sorting now follows highest benefit, priority, then oldest creation.
  [`PromotionCalculationService.php:94`](../../app/Services/POS/PromotionCalculationService.php#L94)

- Flat amount-off rewards distribute once across eligible lines.
  [`PromotionCalculationService.php:346`](../../app/Services/POS/PromotionCalculationService.php#L346)

**Back Office UI**

- Form normalization converts nested numeric fields before Inertia submission.
  [`Index.jsx:81`](../../resources/js/Pages/Admin/Promotions/Index.jsx#L81)

- Table and modal provide create, edit, and deactivate workflows.
  [`Index.jsx:351`](../../resources/js/Pages/Admin/Promotions/Index.jsx#L351)

**Tests And Evidence**

- Admin feature tests cover access, tenant guards, pairing, and deactivate.
  [`PromotionManagementTest.php:61`](../../tests/Feature/Admin/PromotionManagementTest.php#L61)

- POS tests cover amount-off and benefit-priority conflict regressions.
  [`PromotionCalculationTest.php:234`](../../tests/Feature/POS/PromotionCalculationTest.php#L234)
