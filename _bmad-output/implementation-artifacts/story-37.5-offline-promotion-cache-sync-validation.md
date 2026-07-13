---
title: 'Story 37.5 Offline Promotion Cache and Sync Validation'
type: 'story'
created: '2026-07-14'
status: 'implemented'
epic: '37'
story: '37.5'
branch: 'story/37.5-offline-promotion-sync'
---

# Story 37.5: Offline Promotion Cache and Sync Validation

## Story

As a cashier using the POS while offline, I need the terminal to cache the active promotion rules and submit the promotion rule hash with offline sales, so the server can revalidate stale or changed promotion assumptions during sync.

## Acceptance Criteria

1. Bootstrap cache includes a branch-applicable commercial promotion payload and a deterministic `discount_rules_version_hash`.
2. The discount rules hash changes when active branch-applicable promotion content changes.
3. IndexedDB stores and returns cached promotion rules with the existing bootstrap cache metadata.
4. Offline sync classifies stale promotion hashes as `accepted_with_warning` when no client promotion preview was submitted.
5. Offline sync classifies stale promotion hashes as `conflict` when the terminal submitted a promotion preview or discount amount that may differ from current server rules.
6. Customer-segment, coupon, loyalty, or other online-only promotion requirements remain blocked from this slice.

## Tasks / Subtasks

- [x] Add branch-applicable promotion rule payload to bootstrap cache.
- [x] Extend discount rule hash to include statutory discount types plus active commercial promotions/rules.
- [x] Store/read cached promotion rules in the POS IndexedDB cache.
- [x] Add offline sync classification for promotion hash drift and preview mismatch risk.
- [x] Add backend and frontend tests for bootstrap payload, hash drift, cache storage, and sync classification.
- [x] Run focused validation and update Dev Agent Record.

## Dev Notes

- Existing bootstrap surface: `app/Services/POS/OfflineReadiness/CacheBootstrapService.php`.
- Existing IndexedDB cache surface: `resources/js/POS/offline/catalogCache.ts`.
- Existing sync classification surface: `app/Services/POS/OfflineSync/OfflineReconciliationService.php`.
- Existing request validation already accepts `imports.*.discount_rules_version_hash`.
- Server calculation remains authoritative. This story must not make client-side promotion math the source of truth.
- Do not implement coupon redemption, loyalty points, customer segment checks, receipt reporting, X/Z reporting, or refund/void promotion reversal in this slice.
- Preserve statutory/commercial separation. The shared `discount_rules_version_hash` may include both families only as cache-drift metadata.

## Dev Agent Record

### Debug Log

- 2026-07-14: Story created from Epic 37 next-task review after CI-green merge to `main`.
- 2026-07-14: Implemented branch-applicable promotion cache payload and branch-aware discount rule hash.
- 2026-07-14: Added offline sync classification for stale/missing promotion hash with and without terminal promotion preview.
- 2026-07-14: Added IndexedDB promotion rule persistence and focused backend/frontend tests.

### Completion Notes

- Bootstrap payload now includes `promotion_rules` and a `discount_rules_version_hash` that covers statutory discount types plus active branch-applicable commercial promotions/rules.
- Offline sync treats stale promotion hashes without a submitted preview as `accepted_with_warning`, and stale/missing hashes with submitted promotion preview or discount amounts as `conflict`.
- POS IndexedDB cache stores and returns promotion rules alongside catalog, tax, payment, and metadata caches.

### File List

- `app/Services/POS/OfflineReadiness/CacheBootstrapService.php`
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- `app/Http/Requests/POS/SyncBatchRequest.php`
- `resources/js/POS/offline/catalogCache.ts`
- `tests/Feature/POS/OfflineBootstrapCacheTest.php`
- `tests/Feature/POS/OfflineSyncValidationTest.php`
- `tests/Frontend/catalogCache.test.js`
- `_bmad-output/implementation-artifacts/story-37.5-offline-promotion-cache-sync-validation.md`

### Change Log

- 2026-07-14: Created Story 37.5 scope and acceptance criteria.
- 2026-07-14: Implemented and focused-test validated Story 37.5.

## Verification

- `php artisan test tests/Feature/POS/OfflineBootstrapCacheTest.php tests/Feature/POS/OfflineSyncValidationTest.php tests/Feature/POS/PromotionCalculationTest.php` — passed, 57 tests / 209 assertions.
- `node --test tests/Frontend/catalogCache.test.js` — passed, 12 tests.
- `scripts/ci-local.sh` — passed, 1,712 Pest tests / 8,407 assertions and Vite production build.
