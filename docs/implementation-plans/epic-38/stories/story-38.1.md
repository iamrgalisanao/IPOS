# Story 38.1: Service Areas and Visual Floor Plan Configuration

## Status

Review

## References

1. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
2. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`

## Objective

Create the admin-side foundation for branch dining layouts.

## Locked Decisions

1. Layout metadata uses structured JSON with schema version `1`.
2. Dining layout uses dedicated canvas components while reusing existing admin form, validation, modal, button, and permission components.
3. Tables and service areas with historical references are deactivated instead of hard-deleted.
4. Permission uses existing `manage_pos_layouts`.
5. Story 38.1 does not introduce ticket occupancy behavior.
6. Service-area names remain unique across active and inactive records; reactivation uses the existing record.
7. `is_active` is the only activation authority. Table runtime availability uses `operational_state`, not an `inactive` status value.
8. Layout saves use `layout_revision` optimistic concurrency.
9. Shape validation rejects inconsistent square/circle dimensions instead of normalizing user input.
10. Activation endpoints use an explicit `{ "is_active": boolean }` payload.

## Dependencies

1. Architecture lock.
2. Epic 38 implementation guide.
3. Existing admin authorization and UI conventions.

## Technical Approach

1. Add branch-scoped service areas and dining tables.
2. Treat `service_areas.layout_metadata` as the canvas configuration.
3. Treat `dining_tables.position_metadata` as each table's visual placement.
4. Use dedicated request classes for create/update validation.
5. Enforce tenant and branch scope through policies and scoped model queries.
6. Reuse `manage_pos_layouts`.
7. Support basic drag, resize, table creation, editing, activation, and deactivation.
8. Save layout changes through explicit server requests; avoid silently persisting every pointer movement.
9. Use optimistic UI only for visual editing. The server remains authoritative.
10. Do not introduce ticket occupancy behavior in this story.

## Layout Metadata Schema

`service_areas.layout_metadata` minimum schema:

```json
{
  "version": 1,
  "canvas_width": 1600,
  "canvas_height": 900,
  "grid_size": 10,
  "background": {
    "type": "none",
    "image_url": null
  }
}
```

`dining_tables.position_metadata` minimum schema:

```json
{
  "x": 120,
  "y": 80,
  "width": 120,
  "height": 80,
  "rotation": 0,
  "shape": "rectangle",
  "label_position": "center",
  "z_index": 1
}
```

Validation requirements:

1. Coordinates and dimensions must be integers.
2. `x`, `y`, `width`, and `height` must remain within canvas bounds.
3. `canvas_width` must be `320` through `5000`.
4. `canvas_height` must be `240` through `5000`.
5. `grid_size` must be `1` through `100`.
6. `x` and `y` must be greater than or equal to `0`.
7. `width` and `height` must be `40` through `1000`.
8. `capacity` must be `1` through `999`.
9. `z_index` must be `0` through `10000`.
10. Minimum table dimensions must be enforced.
11. Rotation must be limited to `0` through `359`.
12. Shape initially allows `rectangle`, `square`, `circle`, and `oval`.
13. `label_position` initially allows `center`, `top`, and `bottom`.
14. Shape-specific dimensions must be validated consistently:
   - `square`: `width` must equal `height`.
   - `circle`: `width` must equal `height`.
   - `oval`: `width` and `height` may differ.
   - `rectangle`: `width` and `height` may differ.
15. Unknown metadata versions must be rejected.
16. Version `1` background metadata accepts only `background.type = none` and `background.image_url = null`.
17. Background image upload and arbitrary drawing objects are out of scope.

## Component Strategy

Reuse existing admin components for:

1. Forms.
2. Validation display.
3. Modals.
4. Buttons.
5. Permission-gated controls.

Create dining-specific canvas components:

1. `ServiceAreaCanvas`
2. `DiningTableNode`
3. `DiningTablePropertiesPanel`
4. `LayoutToolbar`

Low-level drag, resize, snap-to-grid, and coordinate helpers may be shared if suitable existing helpers are available. Dining layout business logic must stay separate from terminal product-layout business logic.

## Inactive and Deleted-Table Policy

1. Do not hard-delete a dining table once referenced by any dining history.
2. Allow hard deletion only when the table has never been referenced.
3. Otherwise, use `is_active = false`.
4. Deactivation must be blocked while the table has an active ticket.
5. Historical ticket-table records must continue to resolve the original table ID and label.
6. Inactive tables must not appear as selectable tables on the POS floor map.
7. A service area with historical references should also be deactivated rather than deleted.
8. `DELETE` must not silently deactivate records. Destructive delete and non-destructive deactivation are separate operations.
9. Unreferenced records may be physically deleted and return `204`.
10. Historically referenced records reject delete with a domain conflict and should be deactivated through the activation endpoint.
11. Records linked to active dining tickets reject both deletion and deactivation.

## Database Migrations

### `service_areas`

Recommended columns:

```text
id
tenant_id
branch_id
name
normalized_name
layout_metadata JSON
layout_revision INTEGER DEFAULT 1
is_active BOOLEAN DEFAULT TRUE
created_by nullable
updated_by nullable
created_at
updated_at
```

Indexes and constraints:

```text
INDEX tenant_id, branch_id
INDEX tenant_id, branch_id, is_active
UNIQUE tenant_id, branch_id, normalized_name
```

Service-area names remain unique across active and inactive records. If a service area is deactivated, the same normalized name cannot be reused by a new record; reactivation must use the existing record.

### `dining_tables`

Recommended columns:

```text
id
tenant_id
branch_id
service_area_id
table_number
capacity
operational_state
position_metadata JSON
is_active BOOLEAN DEFAULT TRUE
created_by nullable
updated_by nullable
created_at
updated_at
deleted_at nullable only if project conventions require soft deletes
```

Indexes and constraints:

```text
INDEX tenant_id, branch_id
INDEX service_area_id, is_active
UNIQUE service_area_id, table_number
FOREIGN KEY service_area_id -> service_areas.id RESTRICT
```

Story 38.1 should use only administratively controlled operational states:

```text
available
reserved
cleaning
```

`is_active = false` represents inactive. `occupied` is later derived by `DiningTableStatusResolver` and must not be manually assigned in this story. POS-facing status is later derived as:

```text
inactive when is_active = false
occupied when an active ticket exists
reserved when operational_state = reserved
cleaning when operational_state = cleaning
vacant otherwise
```

## API Contracts

Recommended admin routes:

```text
GET    /admin/service-areas
POST   /admin/service-areas
GET    /admin/service-areas/{serviceArea}
PUT    /admin/service-areas/{serviceArea}
DELETE /admin/service-areas/{serviceArea}
PATCH  /admin/service-areas/{serviceArea}/activation

POST   /admin/service-areas/{serviceArea}/tables
PUT    /admin/service-areas/{serviceArea}/tables/{diningTable}
DELETE /admin/service-areas/{serviceArea}/tables/{diningTable}
PATCH  /admin/service-areas/{serviceArea}/tables/{diningTable}/activation

PUT    /admin/service-areas/{serviceArea}/layout
```

The layout endpoint accepts a batch of table placements so one layout save is atomic:

```json
{
  "expected_layout_revision": 4,
  "layout_metadata": {
    "version": 1,
    "canvas_width": 1600,
    "canvas_height": 900,
    "grid_size": 10,
    "background": {
      "type": "none",
      "image_url": null
    }
  },
  "tables": [
    {
      "id": 101,
      "position_metadata": {
        "x": 100,
        "y": 80,
        "width": 120,
        "height": 80,
        "rotation": 0,
        "shape": "rectangle",
        "label_position": "center",
        "z_index": 1
      }
    }
  ]
}
```

The server must verify that every table belongs to the same tenant, branch, and service area before applying the batch.

On successful layout save, the server increments `layout_revision`. A stale `expected_layout_revision` returns a conflict response and must not overwrite newer changes. Real-time collaborative editing remains out of scope.

Delete and activation behavior:

1. `DELETE` physically deletes only unreferenced records and returns `204`.
2. Referenced records reject delete with a domain conflict.
3. Deactivation uses explicit activation endpoints.
4. Active-ticket references reject both delete and deactivation.
5. Activation endpoint request body:

```json
{
  "is_active": false
}
```

Reactivation uses:

```json
{
  "is_active": true
}
```

Activation endpoints must return a domain conflict when:

1. Deactivating a table with an active ticket.
2. Deactivating a service area containing a table with an active ticket.
3. Reactivating a child table under an inactive service area.

Recommended response codes:

| Condition | HTTP status |
| --- | --- |
| Successful create | `201` |
| Successful update/layout save | `200` |
| Successful physical delete | `204` |
| Validation failure | `422` |
| Unauthorized | `403` |
| Cross-tenant/branch hidden resource | `404` |
| Stale `layout_revision` | `409` |
| Referenced record cannot be deleted | `409` |
| Active-ticket record cannot be deactivated | `409` |

Revision conflict response shape:

```json
{
  "code": "LAYOUT_REVISION_CONFLICT",
  "message": "The layout was updated by another user.",
  "current_layout_revision": 5
}
```

## Administrative Audit Events

Story 38.1 layout administration should use the existing administrative audit mechanism.

Minimum event names:

1. `SERVICE_AREA_CREATED`
2. `SERVICE_AREA_UPDATED`
3. `SERVICE_AREA_DEACTIVATED`
4. `DINING_TABLE_CREATED`
5. `DINING_TABLE_UPDATED`
6. `DINING_TABLE_DEACTIVATED`
7. `DINING_LAYOUT_SAVED`

Batch layout audit payloads should avoid large duplicate snapshots. Store service area ID, revision, affected table IDs, and compact before/after coordinates or a change summary.

## UI Notes

The admin page should contain:

1. Service-area selector.
2. Add service area action.
3. Canvas editor.
4. Add table action.
5. Table properties panel.
6. Activate/deactivate controls.
7. Save and discard actions.
8. Unsaved-change warning.

The first release should support:

1. Drag.
2. Resize.
3. Snap to grid.
4. Table shape.
5. Table number.
6. Capacity.
7. Rotation.
8. Active state.

Out of scope:

1. Background image editing.
2. Walls, doors, dividers, and decorations.
3. Live occupancy.
4. POS table operations.
5. Multi-select and bulk alignment.
6. Real-time collaborative editing.

## Test Cases

Backend tests should cover:

1. Tenant and branch isolation.
2. Permission enforcement using `manage_pos_layouts`.
3. Duplicate table number rejection within one service area.
4. Same table number allowed in different service areas.
5. Invalid coordinates and dimensions rejected.
6. Out-of-bounds placement rejected.
7. Cross-service-area table updates rejected.
8. Cross-branch route binding rejected.
9. Deactivation behavior.
10. Hard deletion permitted only for unreferenced records.
11. Batch layout update is atomic.
12. Migration rollback succeeds.
13. Required indexes and foreign keys exist.
14. A stale layout revision cannot overwrite a newer saved layout.
15. Table activation state cannot contradict its operational state.
16. Shape-specific dimension rules reject inconsistent square/circle dimensions.
17. Service-area deletion and deactivation follow the same historical-reference policy as dining tables.
18. Administrative layout mutations produce audit records.
19. Version `1` background metadata accepts only the unsupported-neutral `none` state.
20. Two simultaneous layout saves cannot silently overwrite each other.
21. Unreferenced empty service area can be deleted.
22. Service area containing active tables cannot be deleted until handled according to policy.
23. Historically referenced service area is deactivated instead of deleted.
24. Service area with an active dining ticket cannot be deactivated.
25. Cross-branch child-table manipulation through a valid service-area route is rejected.
26. Activation endpoints accept only explicit `is_active` payloads.
27. Reactivating a table under an inactive service area is rejected.
28. Stale layout revision returns `409` with `LAYOUT_REVISION_CONFLICT` and the current revision.

Frontend tests should cover:

1. Service-area selection.
2. Creating and editing tables.
3. Drag and resize behavior.
4. Validation error display.
5. Unsaved-change warning.
6. Save success and failure handling.
7. Inactive tables visually distinguished.
8. Unauthorized controls hidden or disabled.

## Rollout Plan

1. Deploy migrations and backend endpoints first.
2. Seed permission assignments using existing `manage_pos_layouts`.
3. Deploy the admin editor behind a dining-layout feature flag.
4. Enable for an internal or pilot tenant.
5. Create one cafe layout and validate coordinates, resizing, and persistence.
6. Confirm that no POS terminal behavior changes before Story 38.3.
7. Enable for additional tenants after pilot validation.

## Rollback Considerations

1. The feature flag can hide the editor without deleting layout data.
2. Application rollback must tolerate the new tables remaining in the database.
3. Migration rollback is acceptable only before production dining data exists.
4. Once referenced by later dining-ticket stories, layout records must not be removed by rollback scripts.
5. Deactivation should be preferred over destructive cleanup.

## Definition of Done Checklist

1. Acceptance checks pass.
2. Required backend feature tests pass.
3. Required frontend tests pass, where the story touches UI.
4. No architecture constraints are violated.
5. Code review is approved.
6. Relevant documentation or story notes are updated.
7. Migration rollback is verified in a clean environment.
8. Tenant and branch isolation tests pass.
9. Layout batch updates are transactionally atomic.
10. Permission tests pass for authorized and unauthorized users.
11. Inactive/deleted-table behavior matches the locked decision.
12. Layout JSON schema and version are documented.
13. Pilot layout is successfully created and reloaded without coordinate drift.
14. Layout revision conflict behavior is verified.
15. Administrative audit events are verified.

## Implementation Checklist / PR Plan

### PR 1: Migration and Domain Foundation

1. Add migrations for `service_areas` and `dining_tables`.
2. Add enums/value objects for layout metadata, position metadata, table operational state, shape, and label position.
3. Add models, factories, relationships, indexes, constraints, and casts.
4. Add migration rollback verification.

### PR 2: Authorization and Backend Services

1. Add policies and scoped route binding behavior.
2. Add request validators for service areas, dining tables, activation, and layout batch save.
3. Add create/update/delete/activation services.
4. Add administrative audit events.
5. Add tenant, branch, authorization, and isolation tests.

### PR 3: Atomic Layout Endpoint

1. Add layout batch endpoint.
2. Enforce `expected_layout_revision`.
3. Validate canvas bounds, shape rules, label positions, and version 1 background metadata.
4. Save layout changes in one transaction.
5. Emit compact layout audit payload.
6. Add concurrency and atomicity tests.

### PR 4: Admin UI

1. Add service-area management page.
2. Add dining canvas components.
3. Add table properties panel.
4. Add save/discard behavior.
5. Add unsaved-change protection.
6. Add validation, success, and failure states.

### PR 5: Feature Flag, Regression Tests, and Pilot

1. Wire `manage_pos_layouts` permission access.
2. Gate the admin editor behind a dining-layout feature flag.
3. Add frontend regression tests.
4. Create pilot cafe layout.
5. Verify no POS terminal behavior changes before Story 38.3.
6. Update rollout notes after pilot validation.

## Dev Agent Record

### Implementation Notes

1. Added branch-scoped `service_areas` and `dining_tables` persistence with UUID primary keys, tenant/branch indexes, unique service-area names, table number uniqueness per service area, layout revision tracking, activation state, and soft-delete support for dining tables.
2. Added `ServiceArea` and `DiningTable` models/factories with tenant scoping and JSON casts.
3. Added dining layout validation for version 1 layout metadata, bounded canvas/table geometry, shape-specific dimension rules, label positions, background restrictions, and table capacity limits.
4. Added dining layout services for create/update/delete/activation operations, atomic layout batch saves, `expected_layout_revision` conflict handling, branch access enforcement, and administrative audit events.
5. Added admin routes and controller endpoints under the existing `layout.custom` feature gate and `pos-layouts.manage` permission.
6. Added a compact Inertia admin editor for service-area/table creation, table placement, activation controls, save/discard behavior, and unsaved-change warning.
7. Added Dining Layouts navigation entry for users with `pos-layouts.manage`.
8. Added backend feature tests for tenant/branch isolation, permission enforcement, duplicate names/numbers, metadata validation, shape validation, atomic layout saves, revision conflicts, activation conflicts, deletion rules, cross-branch table manipulation, and audit records.

### Validation

1. `php artisan test tests/Feature/Dining/ServiceAreaLayoutTest.php` passed.
2. `php artisan test tests/Feature/POS/PosLayoutCrudTest.php tests/Feature/Dining/ServiceAreaLayoutTest.php` passed.
3. `npm run build` passed.
4. `php artisan test` passed: 1,729 tests, 8,485 assertions.

## File List

1. `app/Http/Controllers/Admin/ServiceAreaController.php`
2. `app/Http/Requests/Dining/StoreDiningTableRequest.php`
3. `app/Http/Requests/Dining/StoreServiceAreaRequest.php`
4. `app/Http/Requests/Dining/UpdateActivationRequest.php`
5. `app/Http/Requests/Dining/UpdateDiningLayoutRequest.php`
6. `app/Http/Requests/Dining/UpdateDiningTableRequest.php`
7. `app/Http/Requests/Dining/UpdateServiceAreaRequest.php`
8. `app/Models/DiningTable.php`
9. `app/Models/ServiceArea.php`
10. `app/Services/Dining/DiningLayoutMetadataValidator.php`
11. `app/Services/Dining/DiningLayoutService.php`
12. `database/factories/DiningTableFactory.php`
13. `database/factories/ServiceAreaFactory.php`
14. `database/migrations/2026_07_14_000001_create_service_areas_and_dining_tables.php`
15. `resources/js/Layouts/AuthenticatedLayout.jsx`
16. `resources/js/Pages/Admin/ServiceAreas/Index.jsx`
17. `routes/web.php`
18. `tests/Feature/Dining/ServiceAreaLayoutTest.php`
19. `docs/implementation-plans/epic-38/epic-38-architecture-lock.md`
20. `docs/implementation-plans/epic-38/epic-38-implementation-guide.md`
21. `docs/implementation-plans/epic-38/stories/story-38.1.md`
22. `docs/implementation-plans/epic-38/stories/story-38.2.md`
23. `docs/implementation-plans/epic-38/stories/story-38.3.md`
24. `docs/implementation-plans/epic-38/stories/story-38.4.md`
25. `docs/implementation-plans/epic-38/stories/story-38.5.md`
26. `docs/implementation-plans/epic-38/stories/story-38.6.md`
27. `docs/implementation-plans/epic-38/stories/story-38.7.md`
28. `docs/implementation-plans/epic-38/stories/story-38.8.md`

## Change Log

1. 2026-07-14: Implemented Story 38.1 service-area and dining-table layout foundation; added backend validation/services/routes, admin Inertia editor, tests, and story documentation updates.
