# Story 28.5: Epic 28 Phase 2 Slice A — Settings, Terminal Sequence Registry, and Admin Controls

**Date**: 2026-05-20  
**Status**: Implemented & Locally Validated  
**Implementation Phase**: Complete  

---

## 1. Goal
Define the precise business rules, architectural constraints, database blueprints, and validation checks for **Epic 28 Phase 2 Slice A — Settings, Terminal Sequence Registry, and Admin Controls**.

This slice establishes the server-side authority model, admin toggles, and terminal-bound sequence prefix definitions upon which all subsequent offline queueing, local checkout, and server-side reconciliation layers depend.

---

## Validation Evidence

```
./vendor/bin/pest tests/Feature/Admin/OfflineSalesSettingsTest.php
Result: 14/14 tests passing

./vendor/bin/pest tests/Feature/Admin/ tests/Feature/POS/ tests/Feature/Security/
Result: 263/263 tests passing

./vendor/bin/pest tests/Feature/RbacEnforcementTest.php tests/Feature/IdentityFoundationTest.php tests/Feature/Compliance/
Result: 30/30 tests passing
```

## Governance Note

Story 28.5 implements server-side settings, terminal enablement, and terminal-bound offline sequence registry only. It does **not** implement offline transaction capture, offline queueing, sync ingestion, provisional receipt rendering, local official GCT, local official Z-read, or local official e-journal finalization. These remain out of scope pending Story 28.6 and beyond.

---

## 2. Story Scope Boundaries

### In Scope:
1. **Enablement Settings Migration**:
   - Add `offline_sales_enabled` boolean column to the `tenants` table (default: `true`).
   - Add `offline_sales_enabled` boolean column to the `branches` table (default: `true`).
   - Add `offline_sales_enabled` nullable boolean column to the `sales_machine_profiles` table (default: `null`).

2. **Terminal-Bound Sequence Registration**:
   - Add sequence configuration fields to the `sales_machine_profiles` table:
     - `offline_sequence_prefix` (string, nullable) - Unique identifier prefix for offline-generated transactions (e.g., `INV-T01-`).
     - `offline_sequence_next_value` (bigInteger/integer, default: `1`) - Next sequential number to increment.
     - `offline_sequence_status` (string, default: `'active'`) - Status of the offline range (`active`, `suspended`, `depleted`).
     - `last_offline_sync_at` (timestamp, nullable) - Time of the last offline queue reconciliation.

3. **Admin Management Controls & Views**:
   - Backend routes and controller logic to edit `offline_sales_enabled` and terminal configuration.
   - Admin settings view to toggle offline mode, edit prefix, and monitor terminal offline status/last sync times.
   - Strict validation to enforce `offline_sequence_prefix` uniqueness within a tenant.

4. **Cascading Validation Gates**:
   - Context check middleware or helper service that evaluates enablement state hierarchically:
     $$\text{Offline Allowed} = \text{Tenant Enabled} \land \text{Branch Enabled} \land \text{Terminal Enabled/Inherited}$$

### Out of Scope:
- Coding offline checkout queues, cart stores, or client-side generation logic.
- Implementing the API sync/ingestion route (`POST /api/pos/offline-sync`).
- Local official GCT calculations, e-journal finalization, or Z-read generation.
- Using any "BIR-approved" or "accredited offline mode" labeling. All offline states and settings must be labeled as "provisional" or "diagnostic draft".

---

## 3. Core Business & Cascading Rules

### A. Cascading Enablement Resolution
Before the POS bootstrap cache payload compiles or any offline sync is processed, the system must evaluate settings hierarchically:
1. If **Tenant** `offline_sales_enabled` is `false`, then offline operations are blocked for all branches and terminals in that tenant.
2. If **Branch** `offline_sales_enabled` is `false`, then offline operations are blocked for all terminals in that branch.
3. If **Terminal (SalesMachineProfile)** `offline_sales_enabled` is explicitly `false`, offline operations are blocked for that specific terminal. If it is `null`, it inherits `true` (subject to Tenant and Branch being enabled).
4. If a terminal does not have a registered `offline_sequence_prefix`, offline checkout is automatically blocked.

### B. Terminal Prefix Uniqueness
To prevent duplicate transaction numbers across separate registers operating offline:
- The combination of `tenant_id` and `offline_sequence_prefix` must be unique.
- A database-level unique constraint must enforce this check:
  `CREATE UNIQUE INDEX idx_tenant_offline_prefix ON sales_machine_profiles (tenant_id, offline_sequence_prefix) WHERE offline_sequence_prefix IS NOT NULL;`
- Validation logic on the settings update controller must check and reject duplicates with a clear user-facing error message (e.g., `"The prefix :prefix is already assigned to another terminal in this tenant."`).

### C. Sequence Field Constraints
- `offline_sequence_next_value` cannot be decremented via the admin interface to prevent transaction sequence reuse/backtracking.
- `offline_sequence_status` transitions must follow a strict state machine:
  - `active`: Normal operations.
  - `suspended`: Temporarily blocked (e.g., if range loss or tampering is suspected).
  - `depleted`: The terminal-bound sequence range limit has been reached.

---

## 4. Bounded Monorepo Directory Setup
Code changes are confined to the following directories:

```md
database/migrations/
└── [timestamp]_add_controlled_offline_sales_settings.php

app/Models/
├── Tenant.php                  # Add $casts, $fillable, and relations
├── Branch.php                  # Add $casts, $fillable, and relations
└── SalesMachineProfile.php     # Add sequence parameters and safety boot locks

app/Http/Controllers/Admin/
└── SalesMachineProfileController.php # Update endpoints to manage offline parameters

app/Services/POS/OfflineReadiness/
└── OfflineSettingsValidator.php  # Service to check cascading status
```

---

## 5. RBAC & Security Boundaries

- Only roles with the `tenant.admin` or `system.admin` permission are authorized to toggle the enablement settings or edit the sequence prefix.
- Cashier roles have read-only access to view these settings in their local POS diagnostics but cannot update or modify them.
- Any unauthorized attempt to update these fields returns an HTTP `403 Forbidden` response.

---

## 6. Test Matrix

| Test ID | Level | Target Domain | Scenario Description | Expected Outcome |
| :--- | :--- | :--- | :--- | :--- |
| **TC-28.5-01** | Unit | Migration | Run database migrations | Columns created successfully; defaults set to `true`; unique partial index verified. |
| **TC-28.5-02** | Integration | Cascades | Tenant offline mode disabled | Check helper returns `false` for all branches and terminals under that tenant. |
| **TC-28.5-03** | Integration | Cascades | Branch offline mode disabled | Check helper returns `false` for child terminals; sibling branches remain `true`. |
| **TC-28.5-04** | Integration | Cascades | Terminal override disabled | Check helper returns `false` for that terminal; other active terminals remain `true`. |
| **TC-28.5-05** | Integration | Validation | Set duplicate prefix in tenant | Controller validation throws 422; database unique index blocks duplicate insert. |
| **TC-28.5-06** | Integration | Settings | Allow null prefix | Terminal can be saved with `null` prefix, but offline check evaluates to `false`. |
| **TC-28.5-07** | Integration | RBAC | Cashier role attempts update | HTTP 403 Forbidden; request rejected; DB state unchanged. |
| **TC-28.5-08** | Unit | Model Guards | Decrement sequence number | Attempting to update `offline_sequence_next_value` to a lower value throws RuntimeException. |
