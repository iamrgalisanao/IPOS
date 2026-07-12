---
title: 'Terminal Activation UI and Configuration Download Completion'
type: 'feature'
created: '2026-07-12'
status: 'done'
baseline_commit: 'a2610ea'
context:
  - '{project-root}/docs/roadmap/pos-admin-configuration-terminal-capability-backlog.md'
  - '{project-root}/docs/implementation-plans/epic-41-terminal-identity-binding-planning-lock.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Terminal activation has a committed backend foundation and unfinished admin/POS UI, but the current handshake depends on pre-seeded tenant context, returns an unused placeholder token, exposes incomplete configuration data, and is not connected reliably to invalid terminal detection. Shipping it would produce misleading activation success and weak one-time-code handling.

**Approach:** Complete a secure, rate-limited, atomic activation-code handshake; expose generate/revoke controls to authorized administrators; connect invalid terminal detection to the activation modal; and persist the exact server-generated bootstrap payload before reloading the POS into its verified terminal context.

## Boundaries & Constraints

**Always:** Treat activation codes as short-lived secrets; perform context-free lookup deliberately without allowing tenant enumeration; consume codes atomically once; authorize and audit admin generation/revocation without logging raw codes; retain authenticated user/session authorization for POS operations; bind the browser installation identifier to the selected terminal; return and cache the canonical bootstrap payload and hashes; reject expired, reused, revoked, suspended, mismatched-device, and throttled attempts with safe messages.

**Ask First:** Introducing a long-lived terminal bearer-token model, device certificates, remote device attestation, activation approval queues, or changes to subscription/terminal entitlement policy.

**Never:** Present a placeholder token as authentication; trust local storage alone as authorization; leak activation codes to logs/audit metadata; infer or invent bootstrap fields client-side; bypass tenant/branch/terminal middleware after activation; include unrelated checkout, accounting, discount, inventory, or offline-sequence changes.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Generate | Authorized admin requests a code for an allowed terminal | Previous code is replaced; one raw code is shown once; secret is not audited | Foreign tenant/branch or missing permission is denied |
| Activate | Valid unexpired code plus stable browser-install ID | Code is consumed once, device is bound, canonical bootstrap is returned and cached, POS reloads verified | Concurrent reuse yields one success and one safe failure |
| Invalid attempt | Bad, expired, suspended, revoked, or rate-limited request | No binding or configuration is disclosed | Generic actionable response without tenant enumeration |
| Revoke | Authorized admin revokes an active terminal | Binding becomes unusable immediately and terminal is prompted to reactivate | Existing sales/config history remains intact |
| Refresh | Bound terminal requests protected bootstrap | Exact effective config and hashes are downloaded | Missing/mismatched binding produces `TERMINAL_CONTEXT_INVALID` |

</frozen-after-approval>

## Code Map

- `app/Http/Controllers/POS/RegisterActivationController.php` -- public one-time activation handshake and canonical payload response.
- `app/Http/Controllers/Admin/SalesMachineProfileController.php` -- authorized code generation/revocation and one-time display prop.
- `app/Http/Middleware/IdentifyTerminalContext.php` -- post-activation tenant/branch/device verification.
- `routes/api.php` and `routes/web.php` -- activation throttling and terminal-protected bootstrap routing.
- `app/Http/Middleware/HandleInertiaRequests.php` -- safe one-time activation-code flash sharing.
- `resources/js/Pages/Admin/SalesMachineProfiles/Index.jsx` -- activation lifecycle controls.
- `resources/js/Pages/POS/Components/ActivationModal.jsx` and `resources/js/Pages/POS/Index.jsx` -- activation input, exact cache persistence, and verified reload.
- `resources/js/POS/offline/connectivityStore.ts` -- invalid terminal detection via protected bootstrap/heartbeat.
- `tests/Feature/POS/TerminalActivationTest.php` -- security, authorization, lifecycle, and payload regression coverage.

## Tasks & Acceptance

**Execution:**
- [x] Harden backend activation lookup, throttling, transactionality, response shape, secret handling, admin authorization, and revocation semantics.
- [x] Wire protected terminal detection and one-time admin flash data without broad global API middleware changes.
- [x] Finish admin activation controls and POS modal using a cryptographically strong browser-install identifier, canonical bootstrap caching, and reload after success.
- [x] Separate unrelated dirty edits and extend focused backend/frontend coverage for every matrix path.

**Acceptance Criteria:**
- Given no tenant context, when a valid code is activated, then exactly its terminal is bound once without tenant data leakage.
- Given an activated browser installation, when protected bootstrap runs, then terminal/device context is verified and the returned canonical payload is cached unchanged.
- Given revocation or device mismatch, when the terminal refreshes, then POS operations remain blocked and reactivation guidance appears.
- Given code generation or activation, when audit and application logs are inspected, then no raw activation secret is present.
- Given unrelated existing worktree changes, when this task is committed, then only activation-slice files and tests are included.

## Spec Change Log

## Design Notes

The activation code is bootstrap authorization only, not an ongoing terminal credential. After binding, existing authenticated user sessions plus tenant, branch, terminal, subscription, permission, and device middleware remain the authorization chain. Successful activation reloads the POS so headers and runtime context derive from persisted identity rather than stale component state.

The public handshake deliberately bypasses tenant/branch global scopes only for the activation-code hash lookup. It locks and consumes the matching row inside the same database transaction that generates the canonical bootstrap payload, so bootstrap failure leaves the code reusable and concurrent reuse cannot disclose configuration twice. Protected refresh passes the middleware-resolved terminal into bootstrap generation rather than allowing first-profile fallback.

## Verification

**Commands:**
- `php artisan test tests/Feature/POS/TerminalActivationTest.php` -- activation lifecycle and security cases pass.
- `php artisan test tests/Feature/POS/OfflineBootstrapCacheTest.php tests/Feature/POS/TerminalIdentityBindingTest.php` -- adjacent terminal/bootstrap behavior remains green.
- `node --test tests/Frontend/connectivityStore.test.mjs tests/Frontend/catalogCache.test.js` -- detection and cache contracts remain green.
- `npm run build` -- activation/admin UI compiles.
- `git diff --check` -- activation-scoped diff has no whitespace errors.

## Suggested Review Order

**Activation handshake**

- Start with atomic context-free code consumption and canonical bootstrap response.
  [`RegisterActivationController.php:25`](../../app/Http/Controllers/POS/RegisterActivationController.php#L25)

- Review public endpoint throttling and intentionally narrow exposure.
  [`api.php:56`](../../routes/api.php#L56)

- Confirm generation/revocation authorization and secret-safe audit behavior.
  [`SalesMachineProfileController.php:208`](../../app/Http/Controllers/Admin/SalesMachineProfileController.php#L208)

**Verified configuration refresh**

- Trace exact middleware-resolved terminal into bootstrap generation.
  [`OfflineReadinessController.php:25`](../../app/Http/Controllers/POS/OfflineReadinessController.php#L25)

- Confirm protected bootstrap requires authenticated terminal and device context.
  [`web.php:806`](../../routes/web.php#L806)

- Review invalid-context state retention without destroying last-known cache.
  [`connectivityStore.ts:70`](../../resources/js/POS/offline/connectivityStore.ts#L70)

**Admin and terminal UX**

- Review one-time code display, pending controls, heartbeat staleness, and pagination.
  [`Admin/SalesMachineProfiles/Index.jsx:21`](../../resources/js/Pages/Admin/SalesMachineProfiles/Index.jsx#L21)

- Review durable browser binding, canonical caching, recovery messaging, and reload.
  [`ActivationModal.jsx:12`](../../resources/js/Pages/POS/Components/ActivationModal.jsx#L12)

**Regression evidence**

- Inspect context-free success, one-time consumption, authorization, and protected refresh coverage.
  [`TerminalActivationTest.php:137`](../../tests/Feature/POS/TerminalActivationTest.php#L137)

- Confirm public activation throttling is enforced.
  [`TerminalActivationTest.php:393`](../../tests/Feature/POS/TerminalActivationTest.php#L393)
