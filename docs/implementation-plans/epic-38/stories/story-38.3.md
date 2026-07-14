# Story 38.3: Table Status Resolver and POS Floor Map Read Model

## Status

Implemented - Pending Review

This story has a local implementation ready for review. Any implementation feedback must stay within this story boundary unless the Epic 38 architecture lock is formally revised.

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
3. `docs/implementation-plans/epic-38/stories/story-38.1.md`
4. `docs/implementation-plans/epic-38/stories/story-38.2.md`
5. `docs/implementation-plans/epic-38/stories/story-38.5.md`

## Objective

Render accurate branch dining table state in the POS terminal by introducing a read-only table status resolver, a POS floor-map read model endpoint, and a terminal UI surface that can display the last cached floor map in offline read-only mode.

This story gives cashiers visibility into tables and active ticket summaries. It does not add item ordering, table move/merge, split bills, checkout, or offline dining mutations.

## Dependencies

1. Story 38.1 service area and dining table layout foundation.
2. Story 38.2 dining ticket and table mapping foundation.
3. Story 38.5 audit, revision, and timeline foundation.
4. Existing POS terminal identity middleware.
5. Existing POS connectivity/offline frontend helpers.
6. Existing service-area admin layout metadata and `layout_revision` behavior.

## Out of Scope

1. Creating or editing service areas from POS.
2. Drag/drop layout editing from POS.
3. Opening a ticket from the floor map.
4. Adding, moving, voiding, or seating ticket items.
5. Moving, merging, or unmerging tables.
6. Split bills.
7. Checkout and payment.
8. New persistent CQRS projection tables.
9. Kitchen display routing.
10. Offline dining mutation queue.
11. Manager override flows.

## Locked Decisions

1. `DiningTableStatusResolver` derives POS-facing status. It does not persist `occupied` onto `dining_tables.operational_state`.
2. `dining_tables.operational_state` remains the source for manager/host-controlled non-ticket states such as `reserved` and `cleaning`.
3. Active occupancy is derived from active primary `dining_ticket_tables` rows joined to active `dining_tickets`.
4. Active ticket statuses remain `open` and `settling`.
5. Closed and voided tickets never make a table appear occupied.
6. Inactive service areas and inactive/soft-deleted tables are hidden from the default POS floor map.
7. The POS floor-map endpoint is read-only and must not mutate tickets, tables, layout revisions, audit logs, timeline events, or domain events.
8. Offline mode may display the last cached floor map only. All dining actions remain disabled while offline.
9. Read models are query projections only; they do not own dining state.
10. Layout coordinates come from Story 38.1 `layout_metadata`. Story 38.3 does not introduce a second layout format.
11. Table status precedence must be deterministic and tested.
12. The floor map must be tenant, branch, and terminal scoped.
13. The response must include enough revision metadata for the frontend to detect stale cached layout/status.
14. Story 38.3 must not require Story 38.4 item rows to exist.
15. Layout revision and occupancy revision are separate concepts and must be exposed separately.
16. Open duration is presentation data and should be calculated client-side from `opened_at`.
17. `DiningTableStatusResult` is immutable. Construct once and expose read-only fields only.
18. `DiningFloorReadModel` owns serialization of `DiningTableStatusResult` for REST, websocket, or future read APIs.

## User Stories

1. As a cashier, I can see all active service areas and tables for my branch so I can choose the correct table.
2. As a cashier, I can distinguish vacant, occupied, reserved, cleaning, and inactive/unavailable states so I avoid opening duplicate or inappropriate tickets.
3. As a cashier, I can see a compact active ticket summary on occupied tables so I understand guest count, ticket number, revision, and open time.
4. As a terminal user, I can still view the last cached floor map while offline, but all dining mutations are disabled.
5. As support, I can inspect the response metadata and identify which layout and ticket revision the POS terminal last rendered.

## Status Resolution Rules

### Resolver Statuses

The resolver returns these POS-facing statuses:

```text
vacant
occupied
reserved
cleaning
inactive
unavailable
```

`inactive` is returned only when a caller explicitly requests inactive records for diagnostics. The default POS endpoint should hide inactive service areas and inactive/soft-deleted tables.

### Status Precedence

Resolve in this order:

1. If service area is inactive, table is `unavailable`.
2. If table is inactive or soft-deleted, table is `inactive`.
3. If table has an active primary ticket mapping with ticket status `open` or `settling`, table is `occupied`.
4. If table `operational_state = reserved`, table is `reserved`.
5. If table `operational_state = cleaning`, table is `cleaning`.
6. If table `operational_state = available`, table is `vacant`.
7. Any unknown state resolves to `unavailable` and includes a diagnostic reason.
8. Unknown states should write an application warning log for support diagnostics. This is not an audit event.

Rationale:

1. Inactive resources should not be accidentally presented as usable.
2. Active tickets should be visible even if a stale manual operational state was left as `available`.
3. Reserved and cleaning states are manager/host controlled and only apply when no active ticket exists.

## Technical Approach

1. Add `DiningTableStatusResolver`.
2. Add a typed status result value object, recommended name `DiningTableStatusResult`.
3. Add a read-model service, recommended name `DiningFloorReadModel`.
4. Add a read-only POS endpoint returning service areas, table coordinates, derived statuses, active ticket summaries, and revision metadata.
5. Add POS terminal floor-map UI surface using existing POS/terminal visual conventions.
6. Add client-side floor-map caching for offline read-only display.
7. Document server-side cache policy even if first implementation performs direct queries.
8. Add backend feature tests for resolver precedence, tenant/branch scoping, and endpoint payload shape.
9. Add frontend/component tests for status rendering and offline read-only behavior if the current frontend test stack supports it.
10. Keep table/ticket mutation buttons disabled or absent unless already implemented by prior stories.
11. Reuse existing connectivity helpers for offline detection and cached data warnings.
12. Update story status and implementation guide after implementation.

## Backend Design

### `DiningTableStatusResolver`

Required method:

```php
resolve(DiningTable $table): DiningTableStatusResult
```

Optional batch method:

```php
resolveMany(Collection $tables): Collection
```

Responsibilities:

1. Apply status precedence consistently.
2. Return `DiningTableStatusResult`; do not return a loose string or generic array from the resolver boundary.
3. Include status reason codes for support diagnostics.
4. Include active ticket summary when status is `occupied`.
5. Avoid N+1 queries by supporting eager-loaded active ticket mappings.
6. Never mutate `DiningTable`, `DiningTicket`, `DiningTicketTable`, timeline, revision, or audit rows.
7. `resolveMany()` must perform no additional database queries after the required relations have been eager-loaded by the read model.
8. Results must be immutable after construction.

Recommended `DiningTableStatusResult` shape:

```json
{
  "status": "occupied",
  "reason": "active_primary_ticket",
  "active_ticket": {
    "id": "ticket-uuid",
    "ticket_number": "DT-20260714-000001",
    "status": "open",
    "guest_count": 2,
    "ticket_revision": 3,
    "opened_at": "2026-07-14T10:30:00+08:00"
  },
  "layout": null,
  "diagnostics": []
}
```

`opened_minutes_ago` must not be returned by the resolver. The frontend should calculate elapsed duration from `opened_at` so live timers can update without polling and the payload remains stable.

### `DiningFloorReadModel`

Required method:

```php
forBranch(string $tenantId, string $branchId, ?SalesMachineProfile $terminal = null): array
```

Responsibilities:

1. Query active service areas for the branch.
2. Query active dining tables for each area.
3. Eager-load active primary ticket mappings and active ticket summaries.
4. Resolve table statuses through `DiningTableStatusResolver`.
5. Include service-area layout revision metadata.
6. Include response-level freshness metadata.
7. Keep payload compact enough for frequent POS refresh.
8. Produce deterministic ordering:
   - service areas by `sort_order`, then name
   - tables by `sort_order`, then `table_number`
9. Hide cross-tenant and cross-branch records.
10. Serialize `DiningTableStatusResult` instances into the API payload; controllers should not hand-build status payloads.
11. Ordering must remain stable even when layout or occupancy revisions change.

Recommended payload:

```json
{
  "data": {
    "branch_id": "branch-uuid",
    "terminal_id": "terminal-uuid",
    "generated_at": "2026-07-14T10:55:00+08:00",
    "layout_revision": "layout-sha256-or-integer",
    "occupancy_revision": "occupancy-sha256",
    "service_areas": [
      {
        "id": "area-uuid",
        "name": "Main Dining",
        "layout_revision": 4,
        "tables": [
          {
            "id": "table-uuid",
            "table_number": "T1",
            "capacity": 4,
            "shape": "square",
            "layout": {
              "x": 120,
              "y": 80,
              "width": 80,
              "height": 80,
              "rotation": 0
            },
            "status": "occupied",
            "status_reason": "active_primary_ticket",
            "active_ticket": {
              "id": "ticket-uuid",
              "ticket_number": "DT-20260714-000001",
              "status": "open",
              "guest_count": 2,
              "ticket_revision": 3,
              "opened_at": "2026-07-14T10:30:00+08:00"
            }
          }
        ]
      }
    ]
  }
}
```

### Revision Metadata

The response should expose separate deterministic revisions:

```json
{
  "layout_revision": "layout-sha256-or-integer",
  "occupancy_revision": "occupancy-sha256"
}
```

`layout_revision` represents service-area/table geometry and visual metadata.

Acceptable implementation:

1. Hash service-area IDs, service-area `layout_revision`, dining table IDs, table `updated_at`, and table layout metadata.
2. Use a stable SHA-256 string.
3. Change the revision whenever visible layout geometry or table display metadata changes.
4. Do not use the current timestamp in the hash.

`occupancy_revision` represents table status and active ticket summary state.

Acceptable implementation:

1. Hash dining table IDs, derived statuses, active ticket IDs, active ticket statuses, active ticket revisions, active ticket guest counts, and active ticket `opened_at` values.
2. Use a stable SHA-256 string.
3. Change the revision whenever visible occupancy/status/ticket summary data changes.
4. Do not use the current timestamp or elapsed duration in the hash.

Rationale:

1. Layout-only changes can trigger geometry redraw.
2. Occupancy-only changes can update status badges and ticket summaries without redrawing the floor layout.
3. This keeps future polling, server-side caching, and websocket updates easier to optimize.

### Server-Side Caching Policy

Initial implementation may query directly. If server-side caching is added, use this policy:

1. Cache read-model responses for at most 30 seconds.
2. Cache key must include tenant, branch, terminal if terminal-specific data is included, `layout_revision`, and `occupancy_revision`.
3. Invalidate or bypass cache when service-area layout revisions change.
4. Invalidate or bypass cache when active dining ticket status or `ticket_revision` changes.
5. Cache must never serve cross-tenant or cross-branch floor-map data.
6. Cache keys must include a payload version prefix.

Recommended cache key:

```text
ipos:dining-floor-map:v1:{tenant_id}:{branch_id}:{terminal_id}
```

Future websocket events should invalidate or refresh `layout_revision` and/or `occupancy_revision` through the read model. They must not bypass `DiningFloorReadModel` serialization.

### Performance Target

Target read-model latency:

```text
<150 ms for 200 tables on a warm application process
```

This is an optimization guide, not a hard acceptance blocker for the first implementation. If the target is missed, prefer eager-loading and read-model caching before introducing persistent projection tables.

## API Contracts

### Fetch POS floor map

Recommended route:

```text
GET /pos/dining/floor-map
```

Recommended route name:

```text
pos.dining.floor-map.index
```

Required middleware:

```text
auth
tenant
branch
terminal
permission:create_sale
subscription.feature:sales.pos
```

Notes:

1. The endpoint is read-only.
2. `timecard.clocked_in` may be added if product policy requires floor-map viewing only after clock-in. Opening tickets remains protected by the existing Story 38.2 timecard requirement.
3. The endpoint must return `403` if terminal context is missing or invalid.
4. Cross-tenant or cross-branch access must behave as hidden data, not leaked data.

Success response:

```json
{
  "data": {
    "branch_id": "branch-uuid",
    "terminal_id": "terminal-uuid",
    "generated_at": "2026-07-14T10:55:00+08:00",
    "layout_revision": "layout-61b8...",
    "occupancy_revision": "occupancy-8c1f...",
    "service_areas": []
  }
}
```

Recommended response codes:

| Condition | HTTP status |
| --- | ---: |
| Successful read | `200` |
| Unauthorized | `403` |
| Missing or invalid terminal context | `403` |
| Feature disabled | `403` |
| Validation failure for optional filters | `422` |

## Frontend Design

### POS Floor Map Surface

Recommended location:

```text
resources/js/Pages/POS/Terminal/FloorMap.jsx
```

or, if the implementation fits better inside the existing tablet checkout shell:

```text
resources/js/Pages/POS/Components/DiningFloorMap.jsx
```

Required UI behavior:

1. Render service-area tabs or a compact segmented control.
2. Render tables according to stored layout coordinates.
3. Use clear visual status treatments:
   - vacant
   - occupied
   - reserved
   - cleaning
   - inactive/unavailable
4. Show ticket number, guest count, and open duration for occupied tables.
5. Do not expose edit handles, drag/drop, resize, or admin controls.
6. Show offline cached state clearly.
7. Disable or hide dining action buttons while offline.
8. Avoid marketing-style layout; this is an operational POS surface.
9. Ensure table labels fit within fixed table dimensions on tablet and desktop.
10. Avoid overlapping table labels and status badges.
11. Calculate open duration from `opened_at` on the client; do not depend on server-provided elapsed-minute fields.

### Offline Cached Read-Only Behavior

Required behavior:

1. On successful online floor-map fetch, cache the response locally.
2. When offline, render the last cached floor map if available.
3. Display a clear stale/offline state in existing POS offline UI style.
4. Disable all dining mutations while offline.
5. If no cached floor map exists, show an empty operational state with a retry/refresh affordance.
6. Never create, update, or queue dining tickets while offline.

Recommended cache key:

```text
ipos:dining-floor-map:v1:{tenant_id}:{branch_id}:{terminal_id}
```

## Data Privacy and Security

1. Active ticket summaries must not include payment details.
2. Active ticket summaries must not include customer PII.
3. Response should include only IDs and fields required by the POS floor map.
4. Tenant and branch scoping must be enforced server-side.
5. Terminal context must be resolved by middleware, not request body trust.
6. Cached floor-map data should be scoped by tenant, branch, and terminal.

## Test Cases

### Backend Resolver Tests

1. Active table with no active ticket resolves `vacant`.
2. Active table with active `open` primary ticket resolves `occupied`.
3. Active table with active `settling` primary ticket resolves `occupied`.
4. Active table with closed ticket history resolves `vacant`.
5. Active table with voided ticket history resolves `vacant`.
6. Reserved table without active ticket resolves `reserved`.
7. Cleaning table without active ticket resolves `cleaning`.
8. Active ticket takes precedence over stale `available`, `reserved`, or `cleaning` operational state.
9. Inactive table resolves `inactive` when included for diagnostics.
10. Inactive service area resolves `unavailable` when included for diagnostics.
11. Unknown operational state resolves `unavailable` with diagnostic reason.

### Backend Endpoint Tests

1. Authorized terminal user receives only the current branch's active service areas and active tables.
2. Cross-branch service areas and tables are excluded.
3. Cross-tenant records are excluded.
4. Response includes table layout metadata from Story 38.1.
5. Response includes active ticket summary for occupied tables.
6. Response excludes payment, receipt, sale, and customer-sensitive data.
7. `layout_revision` changes when layout revision or visible geometry changes.
8. `occupancy_revision` changes when ticket revision/status/guest count changes.
9. Endpoint does not create audit logs, timeline events, revision rows, or domain events.
10. Missing terminal context is rejected.
11. Unknown operational states log an application warning and resolve to `unavailable`.

### Frontend Tests

1. Vacant, occupied, reserved, cleaning, and unavailable states render distinctly.
2. Occupied table shows ticket number, guest count, and open duration.
3. Service-area tab switching preserves layout geometry.
4. Offline mode renders cached map read-only.
5. Offline mode disables dining action buttons.
6. Empty cached state renders without crashing.
7. Long table numbers fit inside table tiles.
8. Layout does not overlap critical status text at tablet and desktop widths.
9. Open duration updates from `opened_at` without requiring server-provided elapsed-minute fields.
10. Service area and table ordering remains visually stable across occupancy-only refreshes.

### Manual UAT

1. Admin creates service areas and tables.
2. Cashier opens a ticket for a table.
3. POS floor map shows that table as occupied.
4. Closing/voiding the ticket makes the table appear vacant again.
5. Reserved and cleaning states appear when configured.
6. Terminal goes offline and shows last cached map as read-only.
7. No dining mutations are possible offline.

## Rollout Plan

1. Implement resolver and read-model service.
2. Add backend endpoint and route.
3. Add backend resolver and endpoint tests.
4. Add POS floor-map UI surface.
5. Add client-side cache and offline read-only behavior.
6. Add frontend tests where supported.
7. Run dining regression tests from Stories 38.1, 38.2, and 38.5.
8. Run full backend suite and frontend build.
9. Pilot on a simple cafe layout before enabling more complex table maps.

## Rollback Considerations

1. No database migration is required unless implementation introduces optional support tables.
2. Removing the route/UI should not affect ticket, audit, timeline, revision, sale, payment, or receipt data.
3. Cached floor-map data can be safely ignored by future builds.
4. If a frontend rollback occurs, backend resolver may remain harmless as a read-only endpoint.

## Acceptance Criteria

1. POS endpoint returns active service areas and active tables for the current branch.
2. Table status resolver correctly identifies vacant, occupied, reserved, cleaning, inactive, and unavailable states.
3. Active `open` or `settling` primary ticket mappings make a table occupied.
4. Closed and voided ticket history does not make a table occupied.
5. Active ticket summary includes ticket ID, ticket number, ticket status, guest count, ticket revision, and opened time.
6. Floor-map payload includes separate deterministic `layout_revision` and `occupancy_revision` metadata.
7. Endpoint is tenant, branch, and terminal scoped.
8. Endpoint is read-only and creates no audit/version/timeline records.
9. POS UI renders table status accurately.
10. Offline POS mode renders cached floor map read-only and disables dining mutations.
11. No checkout, payment, split, item mutation, table move/merge, or offline dining mutation behavior is introduced.

## Definition of Done Checklist

1. Acceptance criteria pass.
2. Backend resolver tests pass.
3. Backend endpoint tests pass.
4. Frontend floor-map rendering tests pass where supported.
5. Offline read-only behavior is verified.
6. Story 38.1, 38.2, and 38.5 dining regressions pass.
7. Full backend test suite passes.
8. Frontend production build passes.
9. No architecture constraints are violated.
10. Code review is approved.
11. Documentation and story status are updated.
