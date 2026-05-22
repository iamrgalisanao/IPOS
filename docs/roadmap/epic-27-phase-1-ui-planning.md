# Implementation Plan: Epic 27 Phase 1 — UI & Admin Management Planning

**Status: PLANNED / PROPOSED**  
**Epic:** Epic 27 — Ingredient Inventory Upgrade  
**Phase:** Phase 1 UI / Admin Management  

---

## 1. Overview & Objectives

Now that the backend database models, migrations, service layer (`InventoryService`), and transaction control policies have been fully implemented and validated, Phase 1 UI provides the administrative interfaces for managing these features.

### Key Objectives:
1.  **Branch Policy Administration**: Allow tenant administrators to toggle between `Strict Block` and `Allow Negative Stock (Log Variance)` for each branch.
2.  **Unit Conversion Rules Management**: A dedicated interface to create, edit, deactivate, and view conversion ratios (both tenant-wide global rules and product-specific overrides).
3.  **Audit Ledger Visibility**: A read-only historical viewer for `inventory_variance_logs` to enable managers to audit checkout shortages.

---

## 2. User Experience & UI Layouts

Following the established IPOS premium visual aesthetics (vibrant accents, high-contrast dark modes, and crisp table structures), we define the UI components using React, Inertia, and TailwindCSS (or Vanilla CSS custom tokens).

### A. Branch Settings: Deduction Policy
Integrated directly into the existing **Branch Edit Screen** (accessible via Admin Settings):

```
+-------------------------------------------------------------+
| Branch Settings: Manila Main                                |
+-------------------------------------------------------------+
| [...]                                                       |
|                                                             |
| Inventory Deduction Policy                                  |
| ( ) Strict Block (Default)                                  |
|     Checkout will fail if any recipe ingredient has         |
|     insufficient theoretical stock.                         |
|                                                             |
| (o) Allow Negative Stock (Log Warning & Variance)           |
|     Checkout will complete. Deficit quantities will be      |
|     tracked in the inventory variance log for audits.       |
|                                                             |
| [ Cancel ]                                   [ Save Changes ]|
+-------------------------------------------------------------+
```

### B. Unit Conversions Management Screen
Located under **Inventory > Settings > Unit Conversions**:

```
+-------------------------------------------------------------------------+
| Unit Conversions                                         [ + New Rule ] |
+-------------------------------------------------------------------------+
| [ Filter by Product... ]                        [ Search unit codes... ]|
+-------------------------------------------------------------------------+
| Scope     | From Unit  | To Unit  | Factor   | Status   | Actions        |
+-----------+------------+----------+----------+----------+----------------+
| Global    | Crate      | kg       | 10.0000  | [Active] | [Edit] [Deact] |
| Espresso  | Bag        | kg       |  2.5000  | [Active] | [Edit] [Deact] |
| Milk 1L   | Case       | Liter    | 12.0000  | [Active] | [Edit] [Deact] |
| Global    | Piece      | Unit     |  1.0000  | [Inact.] | [Edit] [Activ] |
+-------------------------------------------------------------------------+
```

**New/Edit Conversion Modal:**
```
+-------------------------------------------------------------------------+
| Create Unit Conversion Rule                                             |
+-------------------------------------------------------------------------+
| Scope:                                                                  |
| ( ) Tenant Global (applies to all products unless overridden)           |
| (o) Product-Specific Override                                           |
|     Product: [ Select Product (e.g., Espresso Beans) ]                  |
|                                                                         |
| Conversion Ratio:                                                       |
| 1 [ From Unit (e.g. Bag) ] = [ Factor (e.g. 2.5000) ] [ To Unit (e.g. kg)]|
|                                                                         |
| Status:                                                                 |
| [x] Rule is active and ready to use                                      |
|                                                                         |
| [ Cancel ]                                                [ Create Rule ]|
+-------------------------------------------------------------------------+
```

### C. Inventory Variance Logs Viewer
Located under **Inventory > Reports > Variance Audit Logs**:
This dashboard is strictly read-only. No edit or delete actions are present.

```
+---------------------------------------------------------------------------+
| Inventory Variance Logs                                       [ Export CSV ]|
+---------------------------------------------------------------------------+
| [ Filter by Date Range ]   [ Filter by Branch ]  [ Filter by Ingredient ] |
+---------------------------------------------------------------------------+
| Date/Time | Branch | Composite Product | Ingredient | Deficit  | Policy   |
+-----------+--------+-------------------+------------+----------+----------+
| May 19    | Manila | Espresso (Double) | Beans (kg) | 0.050 kg | Soft Neg |
| May 19    | Manila | Cappuccino 12oz   | Milk (L)   | 0.120 L  | Soft Neg |
+---------------------------------------------------------------------------+
```

---

## 3. API & Controller Contracts

We will expose standard Inertia routes and json endpoint responses, protected by tenant context and permission RBAC middleware.

### A. Branch Policy Update
*   **Route**: `PUT /admin/branches/{branch}/inventory-policy`
*   **Request Validation**:
    ```php
    $request->validate([
        'inventory_deduction_policy' => ['required', 'string', 'in:strict_block,allow_negative_with_warning'],
    ]);
    ```
*   **Authorization**: Gated by permission `edit_branch` or admin role.

### B. Unit Conversions Management
*   **List**: `GET /inventory/unit-conversions`
    *   Returns Inertia view with paginated list of conversions (tenant-scoped) and products dictionary.
*   **Create**: `POST /inventory/unit-conversions`
    *   Validates uniqueness on composite fields: `['tenant_id', 'product_id', 'from_unit', 'to_unit']`.
    *   **Request Validation**:
        ```php
        $request->validate([
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'from_unit' => ['required', 'string', 'max:50'],
            'to_unit' => ['required', 'string', 'max:50'],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['boolean'],
        ]);
        ```
*   **Update**: `PUT /inventory/unit-conversions/{id}`
    *   Allows updating `conversion_factor` and `is_active` status.
*   **Delete**: `DELETE /inventory/unit-conversions/{id}`
    *   Removes conversion definition.

### C. Variance Audit Logs Read-Only Viewer
*   **List**: `GET /inventory/reports/variance-logs`
    *   Provides list view supporting filters (`branch_id`, `ingredient_id`, `date_start`, `date_end`).
*   **Export CSV**: `GET /inventory/reports/variance-logs/export`
    *   Streams a filtered list of variance logs in standard pipe/comma separated CSV format for corporate export.
    *   **Immutability Enforcement**: The controller action only lists and filters. There is no write, patch, or delete endpoint associated with this resource.

---

## 4. Security & RBAC Enforcement

1.  **Branch Setting**:
    *   Read access requires `view_branch`.
    *   Toggle setting requires `edit_branch`.
2.  **Unit Conversions**:
    *   Create, Edit, Delete require `manage_unit_conversions` or `manage_inventory` permission.
3.  **Variance Logs**:
    *   Read access requires `view_inventory_reports` or `audit_inventory` permission.
4.  **Multi-Tenant Scoping**:
    *   All queries must apply the `BelongsToTenant` scope. Unit conversions and variance logs from Tenant A must never be accessible or modifiable by users in Tenant B.

---

## 5. Verification & Test Plan

1.  **Role & Access Control Verification**:
    *   Assert that non-authorized cashiers receive 403 Forbidden on unit conversion updates and policy adjustments.
    *   Assert that tenant boundaries block cross-tenant list fetches.
2.  **Form Input Validation Tests**:
    *   Assert that zero or negative conversion factors return 422 validations.
    *   Assert that duplicate `['product_id', 'from_unit', 'to_unit']` combinations return 422 validations.
3.  **Logs Export Integrity**:
    *   Assert that the exported CSV accurately displays the parent composite product, short ingredient details, policy used, and timestamps.
    *   Assert that no HTTP method (POST/PUT/DELETE) is defined or allowed for the variance log paths.
