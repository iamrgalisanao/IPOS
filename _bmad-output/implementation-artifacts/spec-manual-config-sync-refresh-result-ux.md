---
title: 'Manual Configuration Sync and Refresh Result UX'
type: 'feature'
created: '2026-07-12'
status: 'done'
baseline_commit: 'd2fe30325a36d9ae1077c887c2849cf7f2fb9201'
context:
  - '{project-root}/docs/roadmap/pos-admin-configuration-terminal-capability-backlog.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-terminal-activation-ui-config-download.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Terminals can fetch configuration implicitly, but cashiers have no always-available manual configuration refresh and receive no durable, truthful result for success, stale configuration, offline/network failure, cache-write failure, or invalid terminal context. Existing “Sync” language also conflates configuration download with uploading queued offline transactions.

**Approach:** Turn the terminal Sync Status page into an interactive configuration refresh surface backed by a structured connectivity-store result contract. A refresh downloads the protected canonical bootstrap, writes it completely before publishing success, preserves the prior cache and active cart on failure, and clearly separates configuration refresh from transaction queue synchronization.

## Boundaries & Constraints

**Always:** Make manual refresh available in healthy, stale, and recoverable offline states; distinguish reachability checks, configuration downloads, and offline transaction uploads; de-duplicate concurrent refreshes; report checking/refreshing/success/stale/offline/failure/invalid-terminal states with actionable messages; retain last-known configuration until a replacement write succeeds; expose the effective snapshot hash and server generation time when available; preserve activation routing for invalid terminal context.

**Ask First:** Adding admin-initiated remote refresh commands, background push delivery, forced refresh that interrupts checkout, new server persistence for refresh history, or changing stale-submission acceptance policy.

**Never:** Clear the cart or offline transaction queues; claim success before IndexedDB persistence completes; erase valid cached configuration on refresh failure; silently treat an HTTP success with malformed configuration as refreshed; merge transaction upload into the config-refresh button; weaken tenant, branch, terminal, device, subscription, or permission checks.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Healthy refresh | Valid bound online terminal | Protected bootstrap is fetched, cached, and summarized with time/hash | Duplicate clicks share one in-flight operation |
| Stale config | Cached snapshot differs or exceeds TTL | UI labels configuration stale and offers refresh | Stale state clears only after successful write |
| Offline/network | Browser offline or backend unreachable | Prior cache remains usable under existing offline policy | Result explains no download occurred and offers retry |
| Cache failure | Server responds but IndexedDB write fails | Existing cache and cart remain unchanged | Result is failure, never success |
| Invalid terminal | Protected endpoint returns terminal-context rejection | Activation-required state remains authoritative | No cached data is deleted or exposed as current |
| Malformed response | Missing required snapshot hash/time/payload fields | No replacement is committed | Safe validation error is displayed |

</frozen-after-approval>

## Code Map

- `resources/js/POS/offline/connectivityStore.ts` -- structured refresh state/result, de-duplication, reachability, and invalid-terminal handling.
- `resources/js/POS/offline/catalogCache.ts` -- canonical bootstrap validation, atomic write boundary, and cached snapshot metadata reads.
- `resources/js/Pages/POS/Terminal/SyncStatus.jsx` -- always-available manual configuration refresh and result presentation.
- `resources/js/Pages/POS/Components/ConnectivityBanner.jsx` -- stale/offline entry points consuming the same result contract without merging queue sync.
- `tests/Frontend/connectivityStore.test.mjs` and `tests/Frontend/catalogCache.test.js` -- state, failure, de-duplication, and cache-preservation coverage.

## Tasks & Acceptance

**Execution:**
- [x] Define a structured manual-refresh result/state contract and one in-flight refresh path in `connectivityStore.ts`.
- [x] Add bootstrap validation and metadata access in `catalogCache.ts` without mutating prior cache on invalid/write-failed responses.
- [x] Replace the read-only terminal Sync Status guidance with a clear config-refresh control, last result, hash/time, and separate queue guidance.
- [x] Align Connectivity Banner stale/offline buttons and notices with the shared refresh result while leaving queue upload controls separate.
- [x] Add focused frontend coverage for every matrix path and compile the UI without touching unrelated POS checkout hunks.

**Acceptance Criteria:**
- Given a healthy bound terminal, when the cashier refreshes configuration, then the exact protected bootstrap is stored before success displays with its hash and generation time.
- Given stale or offline configuration, when refresh cannot complete, then the prior cache and active POS state remain available and the UI states that no replacement was downloaded.
- Given concurrent refresh clicks, when one request is active, then only one endpoint fetch/write runs and all callers receive the same result.
- Given invalid terminal context, when refresh runs, then the activation-required state is set without deleting the last-known cache.
- Given pending offline transactions, when configuration refresh completes, then their upload status and queue records are unchanged.

## Spec Change Log

## Design Notes

Configuration refresh and transaction synchronization are deliberately separate operations. The shared store owns request/result truth so the checkout banner and tablet Sync page cannot disagree. The server remains the source of canonical configuration; the client validates required envelope metadata and commits through the existing IndexedDB abstraction before updating visible success state.

## Verification

**Commands:**
- `node --test tests/Frontend/connectivityStore.test.mjs tests/Frontend/catalogCache.test.js` -- refresh and cache edge cases pass.
- `php artisan test tests/Feature/POS/OfflineBootstrapCacheTest.php tests/Feature/POS/TerminalActivationTest.php` -- protected bootstrap/activation remains green.
- `npm run build` -- Sync Status and banner compile.
- `git diff --check` -- Task 2 scoped changes have no whitespace errors.

## Suggested Review Order

**Refresh state machine**

- Start with structured refresh results, de-duplication, and check/refresh serialization.
  [`connectivityStore.ts:84`](../../resources/js/POS/offline/connectivityStore.ts#L84)

- Review manual refresh outcomes and success-after-persistence guarantees.
  [`connectivityStore.ts:168`](../../resources/js/POS/offline/connectivityStore.ts#L168)

**Cache integrity**

- Inspect canonical envelope validation before any IndexedDB transaction begins.
  [`catalogCache.ts:57`](../../resources/js/POS/offline/catalogCache.ts#L57)

- Confirm invalid snapshots preserve the previously cached configuration.
  [`catalogCache.test.js:237`](../../tests/Frontend/catalogCache.test.js#L237)

**Terminal UX**

- Review the always-available configuration refresh, truthful state, hash, and timestamp.
  [`SyncStatus.jsx:7`](../../resources/js/Pages/POS/Terminal/SyncStatus.jsx#L7)

- Confirm banner recovery uses config refresh while queue upload stays separate.
  [`ConnectivityBanner.jsx:12`](../../resources/js/Pages/POS/Components/ConnectivityBanner.jsx#L12)

**Race regressions**

- Verify stale results clear when a later check proves freshness.
  [`connectivityStore.test.mjs:113`](../../tests/Frontend/connectivityStore.test.mjs#L113)

- Verify concurrent refresh callers share one persistence operation.
  [`connectivityStore.test.mjs:158`](../../tests/Frontend/connectivityStore.test.mjs#L158)

- Verify reachability checks wait for the active refresh result.
  [`connectivityStore.test.mjs:181`](../../tests/Frontend/connectivityStore.test.mjs#L181)
