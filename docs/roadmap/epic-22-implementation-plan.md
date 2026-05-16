# Epic 22: Visual POS Layout Builder & Enterprise Sync
**Implementation Plan**

## 1. Executive Summary
This epic introduces an enterprise-grade POS layout customization engine. By blending the intuitiveness of a "Tablet-First" visual grid editor with centralized multi-branch deployment rules, admins can design hyper-optimized POS interfaces directly from an administrative sandbox and instantly sync them to active terminals across specific branches.

## 2. Technical Boundaries (Scope Lock)
*   **In Scope:** 
    *   A new `pos_layouts` entity that stores grid schemas in JSON.
    *   Many-to-many relationship linking `PosLayout` to `Branch`.
    *   An Interactive Grid Editor activated via the `is_admin_mode` POS sandbox.
    *   Real-time deployment/publishing capabilities forcing POS terminals to fetch the active layout.
*   **Out of Scope:** 
    *   Automated A/B testing of different layouts.
    *   Custom layout assignments based on Cashier User ID (Layouts are strictly Branch-level, not User-level).

## 3. Data Architecture
### `pos_layouts` Table
*   `id` (uuid, primary key)
*   `tenant_id` (uuid, foreign key)
*   `name` (string, e.g., "Holiday Rush Grid", "Standard Layout")
*   `schema` (jsonb, containing the grid definitions, row counts, column counts, and tile assignments)
*   `timestamps`

### `branch_pos_layout` (Pivot)
*   `branch_id` (uuid)
*   `pos_layout_id` (uuid)
*   `active_from` (timestamp, defaults to now)

*Constraint Check:* The `TenantScope` applies strictly to `pos_layouts`. A branch can only have one actively applied layout at a given time.

## 4. Slice Execution Strategy

### Slice 1: Database & Foundation
1.  **Migration:** Create `pos_layouts` and pivot tables.
2.  **Model & Relations:** Setup `PosLayout` model, adding `belongsToMany` relations to `Branch`.
3.  **Governance Validation:** Ensure `TenantContext` isolation applies to the layout CRUD operations.

### Slice 2: The Layout Engine (Backend API)
1.  **API Routes:**
    *   `GET /api/v1/pos/layout` (Public terminal endpoint, fetches the active layout for the requester's branch).
    *   `POST /admin/pos-layouts` (Admin creation of layout).
    *   `POST /admin/pos-layouts/{id}/publish` (Admin endpoint to attach layout to branches).
2.  **Controller:** Build `PosLayoutController` with strict authorization checks (`hasRole('admin|owner')`).

### Slice 3: The Admin Sandbox Editor (Frontend)
1.  **Refactoring Index.jsx:** Build upon the existing `Admin Sandbox Mode`. 
2.  **Grid Mode Toggle:** Add a "Design Mode" switch. When toggled, the POS grid tiles become draggable using libraries like `dnd-kit` or React DnD.
3.  **Tile Registry:** Allow admins to pull products or categories from a sidebar and drop them onto blank tiles.
4.  **Save Action:** Serialize the drag-and-drop state into JSON and post to the backend.

### Slice 4: Enterprise Deployment & Terminal Sync (Frontend)
1.  **Publishing Modal:** Build a UI dialog allowing the admin to select target branches for the newly created layout.
2.  **Terminal Consumption:** Update the `ProductGrid.jsx` to parse the `schema` JSON instead of relying solely on alphabetical mapping. If no layout is mapped to the branch, fallback to the default alphabetical render.

## 5. Security & RBAC Constraints
*   **Editor Guard:** Only users with `admin` or `owner` roles can access the Sandbox Mode and invoke the `PosLayoutController` mutating endpoints.
*   **Tenant Boundary:** An admin cannot assign a layout to a branch outside of their `tenant_id`.
*   **Terminal Safety:** The `GET /api/v1/pos/layout` endpoint is read-only and cached, preventing terminal requests from overloading the database during high-volume periods.

## 6. Testing Strategy
*   **Feature Tests:** Write `PosLayoutTest.php` to validate JSON persistence, pivot attachment, and strict tenant isolation.
*   **Unit Tests:** Validate the JSON schema parsing logic to ensure malformed grid schemas gracefully fallback to the default automated layout.
