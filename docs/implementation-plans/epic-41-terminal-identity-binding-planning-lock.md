# Epic 41 Follow-Up Planning Lock: Terminal Identity Binding Hardening

## 1. Status

Accepted for implementation planning

Date: 2026-07-08

Parent Epic:
`docs/roadmap/validated-implementation-roadmap.md` (Epic 41)

## 2. Purpose

Document the residual hardening gap discovered after Epic 41 delivery: the
tablet POS shell entry route is not explicitly bound to a verified registered
terminal identity at page-entry time.

This planning lock exists so the follow-up work is traceable, bounded, and
reviewable before implementation.

## 3. Verified Current State

Verified current terminal-shell behavior:

1. `/pos/terminal/*` routes exist and are tablet-specific entry points.
2. These routes currently require `auth`, tenant context, branch context, and
   `sales.pos` subscription entitlement.
3. The terminal shell page props currently derive `terminal_id` by selecting the
   first active `SalesMachineProfile` for the current branch.
4. Explicit terminal-identity verification exists elsewhere through
   `IdentifyTerminalContext`, which requires `X-Terminal-ID` or equivalent test
   context and validates tenant/branch ownership.
5. Checkout and related mutation flows already rely on stronger downstream
   terminal constraints than the `/pos/terminal/checkout` page-entry route.

## 4. Problem Statement

Current risk:

1. A branch with multiple active terminal profiles can render the tablet shell
   while implicitly attaching to the wrong terminal identity.
2. The shell can appear operational before terminal identity is explicitly
   proven for that session.
3. This weakens auditability for downstream sales-machine-profile attribution,
   offline queue ownership, and timecard/terminal coupling.
4. Physical pilot validation on shared or multi-terminal branches is less
   trustworthy until shell entry is terminal-specific and fail-closed.

## 5. Scope Boundary

This slice is limited to terminal identity binding hardening for the tablet POS
shell.

In scope:

1. Require the tablet terminal shell entry path to resolve a single valid
   terminal identity for the current tenant and branch.
2. Remove the unsafe fallback behavior that implicitly selects the first active
   branch terminal profile.
3. Ensure the resolved terminal identity is available to the terminal shell page
   props and downstream terminal-sensitive flows.
4. Fail closed with a clear response when terminal context is missing, invalid,
   cross-branch, or ambiguous.
5. Add focused automated coverage for route entry, success path, and terminal
   rejection cases.

Out of scope:

1. Hardware adapter integration and receipt/drawer behavior changes.
2. New Android-native bridge adapters or ESC/POS device support.
3. Offline queue engine redesign.
4. Shift lifecycle redesign.
5. Timecard PIN flow redesign.
6. New pilot enablement documentation beyond this reference note.

## 6. Target Implementation Shape

Preferred target shape:

1. The terminal shell route should depend on an explicit terminal identifier,
   either through a dedicated route parameter, a validated request header, or a
   controlled binding strategy that is deterministic and testable.
2. The shell entry controller should resolve the exact
   `SalesMachineProfile` instead of inferring one from branch scope.
3. Any missing or invalid terminal context should stop rendering before the POS
   shell bootstraps.
4. The chosen approach must preserve the existing Inertia-based POS reuse model
   and must not fork checkout business logic.

**Development Override:**
To facilitate active development of the POS terminal shell without requiring
headers in every browser request, a configuration override is provided:
- `config('app.enforce_terminal_binding')` (mapped to `ENFORCE_TERMINAL_BINDING` env).
- When set to `false`, the `IdentifyTerminalContext` middleware passes through
  without requiring a verified terminal identity.
- This should be enabled (`true`) for all production and pilot environments.

## 7. Acceptance Criteria

Implementation may proceed only if:

1. `/pos/terminal/checkout` no longer falls back to the first active branch
   terminal profile.
2. A valid terminal identity is explicitly resolved before the tablet shell is
   rendered.
3. Invalid or ambiguous terminal context returns a fail-closed response.
4. Tenant and branch ownership checks are preserved.
5. Existing downstream checkout and offline-sync terminal assumptions remain
   compatible.
6. Focused tests cover at least:
   - valid terminal shell access
   - missing terminal context rejection
   - invalid terminal rejection
   - cross-branch or cross-tenant rejection

## 8. Non-Goals and Guardrails

1. Do not introduce a broad POS route rewrite.
2. Do not relax existing terminal, branch, tenant, or timecard safeguards.
3. Do not silently create or auto-assign terminal profiles.
4. Do not add production claims about Android pilot readiness from this slice
   alone.
5. Do not change receipt, tax, accounting, settlement, or BIR behavior.

## 9. Suggested Validation Commands

Primary validation should be narrow and route-focused where possible.

Candidate validation set:

1. Focused feature test covering terminal shell route access and rejection.
2. Existing targeted POS route or timecard/terminal tests if touched.
3. `npm run build` only if frontend terminal-entry code changes require it.

## 10. Follow-Up Position

This slice should be treated as the first Epic 41 residual hardening follow-up.

If completed successfully, the next likely Epic 41 residual reviews are:

1. hardware adapter integration into receipt/drawer behavior
2. local active-shift recovery completeness
3. tablet-specific route surface cleanup (`shift`, `sync-status`, `settings`)
