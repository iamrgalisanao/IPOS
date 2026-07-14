# Story 38.8: Offline Restrictions and Online-Only Error Flags

## Status

Implemented - Pending Review

This story has a local implementation ready for review. Application code was limited to the online-only dining mutation middleware, frontend dining offline guard, floor-map read-only offline behavior, and regression tests.

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/story-38.3.md`
4. `docs/implementation-plans/epic-38/stories/story-38.4.md`
5. `docs/implementation-plans/epic-38/stories/story-38.6.md`
6. `resources/js/POS/offline/offlineGuards.ts`
7. `resources/js/POS/offline/connectivityStore.ts`
8. `resources/js/Pages/POS/Terminal/FloorMap.jsx`
9. `app/Http/Controllers/POS/DiningFloorMapController.php`
10. `routes/web.php`

## Objective

Enforce the Epic 38 offline policy for restaurant dining workflows.

Dining table and ticket state is shared mutable operational state. It must not be mutated while the terminal is offline, using stale connectivity state, or unable to reach the server. The POS may still show a cached floor map in read-only mode, and the existing direct walk-in offline cash sale path must remain available outside dining tickets.

## Dependencies

1. Story 38.3 floor map read model.
2. Story 38.4 dining item mutation endpoints.
3. Story 38.6 bill split allocator endpoints.
4. Existing POS terminal context middleware.
5. Existing frontend connectivity store.
6. Existing controlled offline sale flow.

## Out of Scope

1. Offline dining ticket creation.
2. Offline dining item mutation queueing.
3. Offline table move, merge, split, or checkout replay.
4. Offline dining payment capture.
5. Offline dining reservation changes.
6. New conflict resolution UI.
7. New local database schema for dining tickets.
8. Changes to direct walk-in offline cash sale capture.
9. Replacing existing terminal connectivity detection.

## Locked Decisions

1. Offline dining mutations remain prohibited.
2. Direct offline cash sale remains separate from dining tickets.
3. Cached floor map is read-only.
4. Cached floor map data is informational and may be stale.
5. Frontend guards are required for cashier clarity but are not trusted as the only enforcement.
6. Backend dining mutation endpoints must fail closed when an offline/unsupported request signal is present.
7. Backend dining mutation endpoints must continue to require terminal, branch, tenant, permission, subscription, and timecard middleware.
8. No offline dining queue table is introduced.
9. No dining mutation may be stored locally for later replay.
10. Story 38.8 must not loosen existing controlled offline sale rules.

## User Stories

1. As a cashier, I can view the last cached dining floor map while offline so I can understand the room state before reconnecting.
2. As a cashier, I cannot open a dining ticket while offline because the table may be used by another terminal.
3. As a cashier, I cannot add, move, seat, void, or split dining items while offline because shared ticket state must stay server-authoritative.
4. As a cashier, I see a clear message explaining that dining actions require reconnecting.
5. As a cashier, I can still use the existing direct walk-in offline cash sale path when controlled offline sales are allowed.
6. As support, I can rely on the server rejecting dining mutation requests that were attempted from offline or unsupported contexts.

## Offline Policy

### Allowed Offline

1. Show cached dining floor map layout and last known table status.
2. Show an offline banner or read-only state.
3. Allow navigation back to the standard POS checkout/offline sale surface.
4. Allow direct walk-in controlled offline cash sale through the existing non-dining offline sale path when policy permits it.

### Blocked Offline

1. Open dining ticket.
2. Change guest count.
3. Add item.
4. Change item quantity.
5. Assign item seat.
6. Move item to another seat.
7. Void item.
8. Split bill by seat.
9. Split bill by item quantity.
10. Future table move/merge.
11. Future dining checkout or partial payment.

### Read-Only Cached Floor Map

When offline and cache exists:

1. Render cached service areas and tables.
2. Show cached layout and occupancy revisions.
3. Show a clear stale/offline banner.
4. Disable all mutation controls.
5. Do not fetch ticket detail from the server.
6. Do not search products.
7. Do not allow open-ticket actions.

When offline and cache is missing:

1. Show a clear unavailable state.
2. Explain that the terminal must reconnect before dining floor map can be loaded.
3. Do not offer dining mutation controls.

## Backend Contract

### Online-Only Request Signal

The frontend should send an explicit online-only signal on dining mutation requests.

Recommended header:

```text
X-IPOS-Online-Only: dining
```

Recommended connectivity header:

```text
X-IPOS-Connectivity: online
```

Backend middleware or a shared request guard should reject dining mutation requests when:

1. `X-IPOS-Online-Only: dining` is present and `X-IPOS-Connectivity` is not `online`.
2. A future server-supported terminal heartbeat/cache policy marks the terminal as unable to perform dining mutations.
3. Terminal context is missing or invalid.

Initial implementation may rely on the explicit frontend connectivity header and existing terminal middleware. It must be structured so future heartbeat-backed server decisions can be added without touching every controller.

Connectivity headers are advisory only. They improve cashier UX, testability, and diagnostics, but they must not be treated as proof that the terminal is online. The backend remains responsible for deciding whether a dining mutation can proceed using terminal context and future heartbeat or session mechanisms.

Add a centralized route middleware:

```text
DiningOnlineRequiredMiddleware
```

The middleware should be applied at the dining mutation route group level rather than repeated inside individual controllers.

Future heartbeat-based terminal availability may independently block dining mutations even when `navigator.onLine` reports online. This covers captive portals, VPN failures, reverse proxy failures, partial network outages, and other cases where the browser appears online but the server cannot safely coordinate shared dining state.

### Error Response

Dining offline mutation rejection should return `409`.

```json
{
  "code": "DINING_ONLINE_REQUIRED",
  "message": "Dining table actions require an online connection. Reconnect before changing tickets or tables."
}
```

Use `403` only when the existing terminal/auth/permission middleware fails. Use `409` when the user is authorized but the dining mutation is not allowed because the terminal is offline or in an unsupported online-only state.

`DINING_ONLINE_REQUIRED` is a domain error, not an infrastructure error. It means the dining subsystem intentionally refuses a shared-state mutation under current terminal connectivity conditions. It should not be represented as `503`, gateway timeout, or generic network failure.

### Protected Endpoints

Story 38.8 must protect existing and recently added dining mutation endpoints:

1. `POST /pos/dining/tickets`
2. Guest count mutation endpoint if present.
3. `POST /pos/dining/tickets/{ticket}/items`
4. `PATCH /pos/dining/tickets/{ticket}/items/{item}/quantity`
5. `PATCH /pos/dining/tickets/{ticket}/items/{item}/seat`
6. `POST /pos/dining/tickets/{ticket}/items/{item}/move-seat`
7. `POST /pos/dining/tickets/{ticket}/items/{item}/void`
8. `POST /pos/dining/tickets/{ticket}/splits/seat`
9. `POST /pos/dining/tickets/{ticket}/splits/items`
10. Future dining table move/merge routes when introduced.
11. Future dining checkout routes in Story 38.7.

Read endpoint behavior:

1. `GET /pos/dining/floor-map` remains allowed online and is the source for frontend cache.
2. `GET /pos/dining/tickets/{ticket}` remains allowed only when online.
3. The frontend should avoid calling ticket detail while offline.
4. Cached floor map display must not imply live ticket detail availability.

The frontend may choose not to call read endpoints while offline and use cache instead.

## Frontend Contract

### Shared Guard

Add or extend a small frontend helper near `resources/js/POS/offline/offlineGuards.ts`.

Recommended API:

```text
assertDiningOnline()
isDiningMutationAllowed()
diningOnlineOnlyHeaders()
```

Behavior:

1. If `navigator.onLine` is false, reject dining mutation.
2. If `globalState.status === 'offline'`, reject dining mutation.
3. If `globalState.status === 'checking'`, reject dining mutation.
4. If `globalState.terminalContextInvalid` is true, reject dining mutation.
5. Return the standard cashier message for disabled actions.
6. Add `X-IPOS-Online-Only: dining` and `X-IPOS-Connectivity: online|offline|checking` to dining mutation requests.
7. Treat `assertDiningOnline()` as the single frontend authority for dining mutation availability.

Future websocket reconnect events should reuse the same dining online guard rather than bypassing it.

### Floor Map UI

`resources/js/Pages/POS/Terminal/FloorMap.jsx` should:

1. Continue using cached floor map data when offline.
2. Display cached/offline state clearly.
3. Display cache age when available, such as last updated time and relative age.
4. Disable item add/search/edit controls while offline.
5. Disable split controls when split UI exists.
6. Avoid fetching ticket details while offline.
7. Avoid product search while offline.
8. Show a short message in the ticket side panel when selected occupied table details cannot be loaded offline.
9. Avoid wording that implies cached table status is authoritative.

Suggested copy:

```text
Dining actions require an online connection. Cached floor map is read-only.
```

Suggested cache-age copy:

```text
Last updated 14:37 (12 minutes ago)
```

Optional client telemetry may emit a lightweight event such as `DiningMutationBlockedOffline` when the frontend blocks a dining mutation. This must not create audit records and must not be treated as a business event.

### Direct Offline Sale Preservation

Do not change the existing checkout/offline cash sale guard:

1. `validateCheckoutAllowed()` remains responsible for non-dining offline sale capture.
2. Story 38.8 must not make direct walk-in offline cash sale depend on dining floor map state.
3. Story 38.8 must not create dining tickets from offline cart data.

## Database Migrations

No database migration is expected.

If implementation discovers that server-side online-only state requires a durable field, stop and revise this story before adding schema. The current story should be middleware/helper/controller/frontend enforcement only.

## API Contracts

### Mutation Request Headers

Dining mutation requests should include:

```text
X-IPOS-Online-Only: dining
X-IPOS-Connectivity: online
```

If offline:

```text
X-IPOS-Online-Only: dining
X-IPOS-Connectivity: offline
```

The frontend should not intentionally send mutation requests while offline, but backend tests must prove the server rejects such requests if they arrive.

`checking` must be treated the same as offline for dining mutations:

```text
X-IPOS-Connectivity: checking
```

returns `DINING_ONLINE_REQUIRED`.

### Offline Mutation Rejection

Response:

```json
{
  "code": "DINING_ONLINE_REQUIRED",
  "message": "Dining table actions require an online connection. Reconnect before changing tickets or tables."
}
```

Status:

```text
409 Conflict
```

### Cached Floor Map

No new backend endpoint is required for cached offline floor map display. Cache is frontend-owned from the last successful `GET /pos/dining/floor-map` response.

## Test Cases

### Backend Feature Tests

1. Opening a dining ticket with offline connectivity header returns `409 DINING_ONLINE_REQUIRED`.
2. Adding a dining item with offline connectivity header returns `409 DINING_ONLINE_REQUIRED`.
3. Quantity, seat, move, and void item mutations with offline connectivity header return `409 DINING_ONLINE_REQUIRED`.
4. Split by seat and split by item quantity with offline connectivity header return `409 DINING_ONLINE_REQUIRED`.
5. Rejected offline dining mutation creates no ticket/item/split/audit/timeline/revision rows.
6. Online dining mutation without an offline signal continues to use existing validation and revision behavior.
7. Existing direct offline sync or controlled offline sale tests still pass.
8. Terminal context failure still returns the existing terminal error and does not get masked as `DINING_ONLINE_REQUIRED`.

### Frontend Tests

1. `assertDiningOnline()` rejects when `navigator.onLine` is false.
2. `assertDiningOnline()` rejects when connectivity store is offline.
3. `diningOnlineOnlyHeaders()` includes online-only and connectivity headers.
4. Floor map renders cached data while offline.
5. Floor map disables mutation controls while offline.
6. Product search does not run while offline.
7. Ticket detail fetch does not run while offline.
8. Direct offline sale guard tests remain unchanged and passing.

### Manual/UAT Checks

1. Load floor map online and confirm cache is saved.
2. Disconnect network and reload floor map.
3. Confirm cached floor map renders read-only.
4. Confirm add/change/move/void/split controls are disabled or blocked.
5. Confirm reconnect restores online interactions after refresh or connectivity check.
6. Confirm direct walk-in offline cash sale still follows the existing controlled offline flow.

## Rollout Plan

1. Implement backend online-only dining mutation guard first.
2. Add backend feature tests for every current dining mutation endpoint.
3. Add frontend guard helper and floor map disabled/read-only UI updates.
4. Add frontend tests for offline dining helper behavior.
5. Run existing offline sales tests to confirm direct walk-in offline sale is unaffected.
6. Pilot with a terminal by loading floor map online, disconnecting network, and verifying read-only behavior.

## Rollback Considerations

1. Backend guard can be removed from dining mutation route group or middleware alias if it blocks legitimate online traffic.
2. Frontend disabled-state changes can be reverted independently from backend enforcement.
3. No data migration rollback is expected.
4. Existing offline sale behavior must be revalidated after rollback.

## Definition of Done Checklist

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Required frontend tests pass.
4. Dining mutation endpoints fail closed when offline or unsupported.
5. Direct walk-in offline cash sale remains available.
6. Cached floor map renders read-only while offline.
7. No offline dining mutation queue is introduced.
8. No architecture constraints are violated.
9. Code review is approved.
10. Relevant documentation or story notes are updated.

## Implementation Checklist

1. [x] Add backend dining online-only guard or middleware.
2. [x] Implement `DiningOnlineRequiredMiddleware`.
3. [x] Apply guard to dining mutation routes at the route group level.
4. [x] Add standard `DINING_ONLINE_REQUIRED` domain response.
5. [x] Add frontend dining offline guard helper.
6. [x] Add online-only headers to dining mutation requests.
7. [x] Update floor map offline copy, cache age, and disabled states.
8. [x] Add backend feature tests.
9. [x] Add frontend tests.
10. [x] Run existing dining tests.
11. [x] Run existing offline sales/frontend offline guard tests.

## Implementation Notes

1. Added `DiningOnlineRequiredMiddleware` and registered it as `dining.online`.
2. Applied the middleware to current dining mutation routes while leaving read routes unchanged.
3. The middleware treats `X-IPOS-Online-Only: dining` with any non-`online` connectivity value as `DINING_ONLINE_REQUIRED`.
4. Existing terminal/auth failures still run before the dining online guard and remain distinct from the domain error.
5. Added `assertDiningOnline()`, `isDiningMutationAllowed()`, and `diningOnlineOnlyHeaders()` to the frontend offline guard module.
6. Floor map mutations now use the shared dining guard and send online-only headers.
7. Cached floor map storage now records `cached_at` while remaining backward compatible with existing raw cached payloads.
8. Offline occupied-table side panel now makes clear that cached floor map state is read-only and not live ticket detail.

## Verification

1. `php artisan test tests/Feature/Dining/DiningOfflinePolicyTest.php`
2. `php artisan test tests/Feature/Dining/DiningOfflinePolicyTest.php tests/Feature/Dining/BillSplitAllocatorTest.php tests/Feature/Dining/DiningTicketItemMutationTest.php tests/Feature/Dining/DiningTicketAuditRevisionTest.php tests/Feature/Dining/DiningTicketFoundationTest.php tests/Feature/Dining/DiningFloorMapReadModelTest.php`
3. `node --test tests/Frontend/catalogCache.test.js`
4. `npm run build`
5. `php -l app/Http/Middleware/DiningOnlineRequiredMiddleware.php`
6. `php -l tests/Feature/Dining/DiningOfflinePolicyTest.php`
