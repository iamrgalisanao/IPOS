# Story 31.2 Slice B Scope Lock — Validation/Error Feedback Hardening

Status: Planning / Scope Locked
Date: 2026-05-21
Epic: Epic 31 — Product Catalog and Inventory Admin UX Completion
Story: 31.2 — Product Create/Edit UX Hardening
Slice: B — Validation/Error Feedback Hardening
Governance Ref: G-068
Predecessor: Story 31.2 Slice A — Implemented & Locally Validated

---

## 0. Slice Intent

Story 31.2 Slice B defines the approved validation and error feedback hardening
boundaries for Product Create/Edit admin flows. It authorizes planning and
implementation of frontend error display improvements and preserves user-entered
values after a validation failure, without altering server-side validation rules,
controller persistence behavior, or any backend logic.

---

## 1. Goal

Improve the visibility and actionability of validation errors and save feedback on
the Product Create and Edit admin forms, without changing product validation rules,
pricing, tax, inventory, recipe, POS runtime, checkout behavior, subscription gates,
or RBAC rules.

---

## 2. Current Surface Baseline

Surfaces targeted by Slice B (frontend-only):

Pages:
- `resources/js/Pages/Admin/Products/Create.jsx`
- `resources/js/Pages/Admin/Products/Edit.jsx`

Components already in use:
- `@/Components/InputError` (field-level error display)
- `@/Components/PrimaryButton`
- Inertia `useForm` — `errors`, `processing`, `recentlySuccessful`

Controller (read-only reference; no changes approved):
- `app/Http/Controllers/Admin/ProductController.php`
  — `store()` and `update()` validation responses already return field-keyed errors

Test guardrails:
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 3. In Scope

Slice B implementation may include:

- **Top-level validation summary banner**: a section-agnostic summary strip that
  appears above the form when submission returns validation errors, listing how many
  fields require attention.
- **Grouped field-level error display**: ensure `InputError` or equivalent is
  consistently rendered beneath every required field across both Create and Edit
  forms.
- **Error state styling on fields**: apply a visual error ring or border variant to
  fields that have active `errors[field]` entries (e.g. `border-rose-400` or
  equivalent Tailwind class), without restructuring field components.
- **Preserve entered values after failure**: Inertia `useForm` already preserves
  state on validation failure; confirm this behavior is not being reset and add
  `preserveScroll: true` where missing on submit handlers if needed for UX
  continuity.
- **Save failure feedback**: ensure the sticky footer save button or an adjacent
  inline affordance communicates a save failure state (e.g. brief error message or
  button state change) distinct from the normal processing/disabled state.
- **Success feedback on Edit**: confirm the `recentlySuccessful` flag on the Edit
  form produces a visible acknowledgment (e.g. a transient banner or button state
  change) after a successful update save.

---

## 4. Out of Scope

Not approved under Slice B:

- Changing any server-side validation rule in `ProductController`, form requests, or
  model-level rules.
- Adding new validation fields or removing existing required fields.
- Changing product persistence, pricing, tax, inventory deduction, recipe/BOM,
  subscription entitlement, or RBAC logic.
- Changing branch pricing or recipe entry-point behavior.
- Changing POS checkout or POS runtime behavior.
- Import/export or bulk creation.
- Redesigning the form layout beyond error state styling and summary placement.
- Adding client-side validation logic that bypasses or supplements server rules.

---

## 5. Acceptance Boundaries

Slice B may modify:

- JSX markup in `Create.jsx` and `Edit.jsx` for error summary placement, field
  error ring styling, save feedback states.
- Inertia form `post()`/`put()` call options (e.g. `preserveScroll`) where missing.
- No backend PHP files.

Slice B must not modify:

- Controller store/update logic.
- Any validation rule definitions.
- Pricing, tax, inventory, recipe, subscription, or RBAC logic anywhere.

---

## 6. RBAC and Feature-Gate Lock

No relaxation of existing middleware or authorization checks is approved. The same
gate requirements from Story 31.2 Slice A remain mandatory:

- `manage_products` required for write pathways.
- `catalog.edit` required for create/edit form access and write operations.
- `catalog.view` without `catalog.edit` remains view-only.
- Existing tenant and branch isolation remain fail-closed.

---

## 7. Data Integrity Expectations

- No new mutation surface is introduced.
- All persistence remains server-side validated per existing rules.
- User-facing error messages must reflect actual server validation responses; no
  fabricated client-side rule messaging is approved.

---

## 8. Test Strategy Lock

Required validation for Slice B implementation:

- Authorized user can submit a valid create payload and receives success feedback.
- Authorized user can submit a valid update payload and receives success feedback.
- Submitting an invalid payload (e.g. missing required field) returns to the form
  with errors displayed, entered values preserved, and a summary banner visible.
- Error states on fields are visually distinct.
- User without `manage_products` is denied.
- Tenant without `catalog.edit` is denied.
- Frontend build passes (`npm run build`).

Recommended test suites (same as Slice A):
- `tests/Feature/Subscription/RouteFeatureGateTest.php`
- `tests/Feature/ProductCatalogTest.php`
- `tests/Feature/ProductPricingTest.php`
- `tests/Feature/CatalogInventoryIsolationTest.php`

---

## 9. Governance Lock

Story 31.2 Slice B is planning and scope-lock only.
No implementation beyond this document is approved until explicit Slice B
implementation approval is received.

Slice C (Save/Success Interaction and Navigation Consistency) and Slice D (Branch
Pricing and Recipe Entry-Point UX Polish) remain locked pending closure of Slice B.
