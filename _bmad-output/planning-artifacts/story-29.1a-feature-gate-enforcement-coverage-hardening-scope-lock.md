# Story 29.1A - Feature Gate Enforcement Coverage Hardening

Date: 2026-05-20
Status: In Progress / Scope Locked
Implementation Phase: Wave 1 Implemented & Locally Validated; Wave 2 Planning Only

---

## 1. Goal
Harden feature-gate enforcement coverage by mapping configured feature flags to route groups, identifying enforcement gaps, and safely expanding `subscription.feature` middleware coverage without changing entitlement engine semantics.

---

## 2. Scope Boundaries

### In Scope
- Build a feature-gate coverage map for all configured flags in `config/subscriptions.php`
- Map each flag to route groups and currently enforced middleware locations
- Identify and classify coverage state:
  - implemented
  - partial
  - missing
- Add safe `subscription.feature` middleware to low-risk routes where gaps are confirmed
- Align navigation visibility/hiding with effective entitlements for hardened modules
- Add regression tests per hardened gated module and access path
- Produce implementation evidence and updated coverage summary notes

### Out of Scope
- Rebuilding subscription/entitlement engine
- Replacing `EnforceSubscriptionGate`
- Full platform-wide enforcement in one pass if risk is high
- Billing automation or subscription payment integration
- Branch/owner onboarding workflows (Story 29.2)
- Sales machine/compliance onboarding workflows (Story 29.3/29.4)

---

## 3. Governance Guardrails
- Maintain fail-closed behavior for newly gated routes.
- Apply middleware expansion incrementally with explicit rollback-safe commits.
- Do not infer competitor internal architecture from public sources.
- Keep onboarding stories blocked until feature-gate coverage map is complete and reviewed.

---

## 4. Implementation Sequence
1. Extract configured feature flags from `config/subscriptions.php`.
2. Build route-to-feature mapping inventory.
3. Mark each feature route surface as implemented/partial/missing.
4. Select low-risk missing surfaces for first middleware hardening pass.
5. Patch route middleware where safe.
6. Align UI navigation visibility for those hardened surfaces.
7. Add regression tests per hardened feature route.
8. Publish coverage report and residual-gap notes.

---

## 5. Acceptance Criteria
- Every configured feature flag has a documented route coverage state.
- Hardened low-risk gaps are protected by `subscription.feature` middleware.
- Hardened modules hide/disable unavailable navigation paths for non-entitled tenants.
- Regression tests prove allowed/blocked behavior for hardened paths.
- Remaining partial/missing areas are explicitly documented for follow-on slices.

---

## 6. Exit Criteria and Handoff
- Story 29.1A exits when the first hardening wave is implemented, tested, and documented with residual gaps.
- Only after 29.1A mapping/hardening review may work proceed to Story 29.2 onboarding surfaces.

---

## 7. Wave 1 Closure Note
Story 29.1A Wave 1 hardens feature-gate enforcement coverage using the existing subscription feature system. It does not rebuild entitlement logic, change billing behavior, alter user-level permissions, or continue tenant onboarding work.

Wave 1 validation evidence:
- `./vendor/bin/pest tests/Feature/Subscription/RouteFeatureGateTest.php`
- Result: 10 tests / 10 assertions passing

---

## 8. Wave 2 Planning Link
- `_bmad-output/planning-artifacts/story-29.1a-wave-2-sales-pos-catalog-risk-ranked-plan.md`
- Scope: planning-only for `sales.pos`, `catalog.view`, and `catalog.edit` enforcement strategy.
- Constraint: no Wave 2 code changes until planning review approval.
