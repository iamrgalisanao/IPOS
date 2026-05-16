# Epic 22: Visual POS Layout Builder & Enterprise Sync
**Implementation Plan**

## 1. Objective
Refine the POS terminal interface to be centrally customizable by administrators. Introduce an enterprise-grade POS layout customization engine that blends an administrative sandbox visual grid editor with centralized multi-branch deployment rules. This allows admins to design hyper-optimized POS interfaces and securely sync them to active terminals across specific branches.

## 2. Final Scope
*   **In Scope:** 
    *   A new `pos_layouts` entity that stores grid schemas in JSON, supporting layout versioning.
    *   A `branch_pos_layout` tracking system linking `PosLayout` to `Branch`, ensuring only one active layout per branch.
    *   A Terminal API endpoint to fetch the active layout, resolving strictly by the authenticated terminal's branch context.
    *   An Interactive Grid Editor (Sandbox) for Admins.
    *   Real-time deployment/publishing capabilities forcing POS terminals to fetch the active layout.
    *   A strict permission-based authorization model (`pos-layouts.view`, `pos-layouts.manage`, `pos-layouts.publish`).
*   **Out of Scope:** 
    *   Automated A/B testing of different layouts.
    *   Custom layout assignments based on Cashier User ID (Layouts are strictly Branch-level, not User-level).

## 3. Data Architecture
### `pos_layouts` Table
*   `id` (uuid, primary key)
*   `tenant_id` (uuid, foreign key)
*   `name` (string, e.g., "Holiday Rush Grid", "Standard Layout")
*   `version` (integer, increments on publish)
*   `schema` (jsonb, containing the grid definitions, row counts, column counts, and tile assignments)
*   `status` (enum: draft, published, archived)
*   `created_by` (uuid, foreign key)
*   `updated_by` (uuid, foreign key)
*   `timestamps`

### `branch_pos_layout` (Pivot)
*   `branch_id` (uuid)
*   `pos_layout_id` (uuid)
*   `active_from` (timestamp)
*   `active_until` (timestamp, nullable)
*   `is_active` (boolean, strictly enforces one active layout per branch)
*   `published_by` (uuid, foreign key)
*   `published_at` (timestamp)
*   `timestamps`

## 4. Execution Strategy (Slices)

### Slice A: Schema & Layout Foundation
**Goal:** Create the core models, migrations, and validate layout schema integrity.
*   **Tasks:**
    1.  Create `pos_layouts` and `branch_pos_layout` migrations.
    2.  Implement `PosLayout` model and relationships.
    3.  Implement validation rules for the `schema` JSON structure.
    4.  Ensure `TenantContext` isolation applies to layout CRUD operations.

### Slice B: Admin Layout CRUD + Validation
**Goal:** Implement backend CRUD for managing POS layout drafts with strict schema validation.
*   **Objective:** Provide administrative control over POS layout entities within a tenant scope.
*   **Scope:**
    *   List, Create, Show, Update, and Archive POS layouts.
    *   Permission-gated access (`pos-layouts.view/manage`).
    *   Schema integrity enforcement via `PosLayoutSchemaValidator`.
*   **Out of Scope:** Visual editor (Slice D), Publishing/Sync (Slice E).
*   **Design Rules:**
    *   **Drafts:** Fully editable.
    *   **Published/Archived:** Read-only (prevents live terminal instability).
    *   **Tenant Isolation:** Automated via `BelongsToTenant`.
*   **Route Design:**
    *   `GET /admin/pos-layouts` (List)
    *   `POST /admin/pos-layouts` (Create Draft)
    *   `GET /admin/pos-layouts/{posLayout}` (Detail)
    *   `PUT /admin/pos-layouts/{posLayout}` (Update Draft)
    *   `POST /admin/pos-layouts/{posLayout}/archive` (Archive)
*   **Test Strategy:** Feature tests for authorized/unauthorized access, tenant scoping, schema validation, and status-based mutation locks.

### Slice C: Terminal Layout Fetch & Fallback Rendering
**Goal:** Prove the POS terminal can safely retrieve and render a branch-specific layout with robust fallback behavior.
*   **Objective:** Implement terminal-side layout resolution and safe rendering.
*   **Scope:**
    *   `GET /pos/layout` endpoint scoped to authenticated branch context.
    *   Resolution logic: Fetches the latest active, published layout for the branch.
    *   Response Contract: Includes `layout` schema and current source-of-truth `products` data for all items in the layout.
    *   Frontend Integration: `Index.jsx` fetches layout on mount and passes it to `ProductGrid`.
    *   Layout-Aware Rendering: `ProductGrid` renders a CSS grid based on the schema when no search/category filter is active.
    *   Fallback Strategy: Standard alphabetical grid remains active if no valid layout is found or if search is active.
*   **Out of Scope:** Visual editor (Slice D), Administrative publishing/deployment (Slice E).
*   **Design Rules:**
    *   **Isolation:** Fetch resolves strictly via `BranchContext` and `TenantContext` middleware.
    *   **Read-Only:** Terminal cannot modify layouts.
    *   **Data Integrity:** Product prices and taxes are ALWAYS fetched from the `CatalogService`, never from the layout schema.
*   **Route Design:**
    *   `GET /pos/layout` (Returns `{ layout, products, fallback }`)
*   **Test Strategy:** Feature tests for layout resolution, branch isolation, and fallback behavior for unpublished/missing/invalid layouts.

### Slice D: Visual Sandbox Editor
**Goal:** Build a high-fidelity, interactive visual editor for authorized admins.
*   **Objective:** Provide a WYSIWYG sandbox for designing POS grid layouts.
*   **Scope:**
    *   Interactive Grid Editor UI in the Admin panel.
    *   Design/Preview mode toggle.
    *   Grid dimension controls (Rows/Columns).
    *   Tile Registry sidebar with Product/Category search.
    *   Drag-and-drop or click-to-place placement logic.
    *   Real-time schema serialization and validation.
    *   Draft-save integration using existing CRUD endpoints.
*   **Out of Scope:** Publishing (Slice E), Rollback (Slice F), Price/Tax editing.
*   **Design Rules:**
    *   **WYSIWYG**: Editor must reuse `ProductGrid` rendering rules for parity.
    *   **Safety**: Editor must not permit saving pricing, tax, or inventory data.
    *   **Permission**: Requires `pos-layouts.manage`.
*   **Dependencies**: `dnd-kit` (recommended for drag-and-drop interaction).
*   **Validation**: Client-side coordinate safety and backend schema integrity.

### Slice E: Publish / Branch Deployment / Sync
**Goal:** Safely deploy and sync layouts to active terminals.
*   **Tasks:**
    1.  Build `POST /admin/pos-layouts/{id}/publish` endpoint (requires `pos-layouts.publish` permission).
    2.  Implement deployment UI modal to select target branches.
    3.  Enforce business rule: A branch can have only one active layout at a time (deactivate previous layouts by setting `active_until` and `is_active = false`).

### Slice F: Governance / Audit / Rollout Hardening
**Goal:** Ensure rollout is secure, auditable, and rollback-ready with a safe "Option A" re-publish strategy.
*   **Objective:** Implement audit logging, deployment history, and a safe rollback mechanism.
*   **Scope:**
    *   **Audit Logging**: Log `pos_layout_published`, `pos_layout_branch_assigned`, and `pos_layout_branch_replaced` events using the existing `AuditLog` infrastructure.
    *   **Deployment History**: Add a new "Deployment History" view for each POS layout and branch.
    *   **Rollback Strategy (Option A)**: Implement a "Rollback" action that re-publishes a previously active layout for a branch, reusing `PosLayoutPublishService`.
    *   **Audit Metadata**: Record actor, tenant, branch, layout version, and replaced layout details.
    *   **Final Hardening**: Perform final security review and regression testing.
*   **Out of Scope:**
    *   Live WebSocket hot-swapping.
    *   A/B testing or user-specific layouts.
    *   Direct activation of old pivot rows (Option B).
*   **Design Rules:**
    *   **Metadata Only**: Audit logs must not store full schema JSON or sensitive business data.
    *   **Auditable Rollback**: Rollbacks are treated as new publishing events to maintain a clean history.
    *   **Permission**: Rollback requires `pos-layouts.publish`.
*   **Audit Events:**
    *   `pos_layout_published`: Logged when a layout status changes to published.
    *   `pos_layout_branch_assigned`: Logged for each branch receiving a new layout.
    *   `pos_layout_branch_replaced`: Logged when an existing branch layout is deactivated during publishing.
    *   `pos_layout_rollback_completed`: Logged when a rollback republish succeeds.
*   **Test Strategy:**
    *   Verify audit records created after publishing.
    *   Verify metadata contains correct `layout_id` and `branch_id`.
    *   Verify rollback correctly deactivates current and re-activates target previous layout.
    *   Verify tenant isolation for history and audit views.
    *   Verify no side effects on pricing/inventory logic.

## 5. Security & Constraints
*   **Business Integrity:** Layout customization must not change product prices, tax behavior, checkout calculations, or inventory behavior.
*   **Permission Guard:** Access requires `pos-layouts.view`, `pos-layouts.manage`, or `pos-layouts.publish`. Role checks are avoided.
*   **Tenant/Branch Isolation:** An admin cannot assign layouts to branches outside their `tenant_id`. The terminal endpoint strictly resolves using authenticated branch context.
*   **Terminal Safety:** Terminal must fall back to default layout if schema is missing, invalid, or unpublished. Cashiers cannot edit layouts from the POS terminal.

## 6. Risks & Open Questions
*   **Risk:** Invalid JSON schemas could crash the React frontend during a busy shift.
    *   *Mitigation:* Strict backend validation rules (Slice A) and robust React error boundaries with automatic fallback (Slice C).
*   **Risk:** Large layouts (e.g. 10x10) causing rendering lag on low-end tablets.
    *   *Mitigation:* Limit grid dimensions in `PosLayoutSchemaValidator`.
*   **Risk:** Missing product data in tiles (e.g. deactivated products).
    *   *Mitigation:* `ProductGrid` must handle missing products in the resolved map by rendering an "Unavailable" tile.
*   **Open Question:** Should layouts automatically sync to live terminals mid-shift? (Recommendation: Terminals should fetch layout on load or after a transaction completes to avoid disruption).

## 7. Implementation Readiness
*   **Status:** **CLOSED / EPIC COMPLETE.**
*   **Final Validation:** All slices (A-F) implemented, verified with 43/43 layout tests and 16/16 security tests. Audit logging and transactional rollback fully operational.
