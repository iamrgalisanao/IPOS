# Epic 28: Offline-Resilient POS Architecture Plan

> [!NOTE]
> This document defines the implementation roadmap for Epic 28. It details a phased approach to introducing offline tolerance in the POS cashier interface while maintaining a hard compliance boundary where official accounting, invoicing, and tax calculations remain strictly server-authoritative.

## 1. Objective & Scope

The primary objective is to make the POS cashier runtime resilient to network dropouts and internet instability without violating Philippine BIR compliance regulations (RMO No. 24-2023).

### Key Architectural Constraint
```md
POS Offline Shell = Convenience Caching / Cart Persistence Layer
Laravel Server     = Sole Authority for Compliance, Invoicing, GCT & Taxes
```

### In-Scope (Phase 1: Offline-Tolerant POS Shell)
- **Bootstrap Cache Endpoint**: A backend API that compiles product catalogs, pricing overrides, tax display rules, and branch profile settings into a single payload.
- **Client Cache Store (IndexedDB)**: Client-side storage of catalog items to enable high-velocity category browsing and keyword search while offline.
- **Cart Draft Persistence**: Local storage mechanism to save the state of active carts, protecting cashiers against browser crashes or network drops.
- **Connectivity Detection**: Reactive network monitoring to toggle the online/offline state of the UI.
- **Checkout Guard**: Disabling checkout buttons when offline and displaying a visual banner warning cashiers that connectivity is required to finalize transactions.

### Out-of-Scope (Deferred to Phase 2/3)
- Local official GCT finalization, local official Z-read finalization, and local official e-journal finalization.
- Phase 2 is approved for development under a hybrid terminal-bound model, but remains out-of-scope for public release or compliance claims until post-development review is complete.

---

## 2. Technical Design Decisions

### Monorepo Boundaries
Rather than splitting the codebase, IPOS maintains folder-level segregation under the existing Laravel/React monorepo:
- **`resources/js/Admin/`**: Remains 100% online-only.
- **`resources/js/POS/`**: Receives helper modules under `resources/js/POS/offline/` for local cache persistence and connection guards.
- **`Shared/`**: Shared components (like the connection status indicator) remain in the common UI folder.

```md
resources/js/POS/offline/
├── catalogCache.ts      # IndexedDB wrapper for catalog storage
├── cartDraftStore.ts    # localStorage/sessionStorage helper for active carts
├── connectivityStore.ts # React hook for navigator.onLine state
└── offlineGuards.ts     # Checkout validation rules
```

### Configuration and Tax Version Validation
To mitigate the risk of cache drift (e.g., changes to tax rules or prices not syncing to the offline register):
1. The backend cache bootstrap payload includes a `tax_configuration_version_hash`.
2. When the cashier completes a checkout, the React POS sends this hash in the API request headers (`X-Tax-Config-Hash`).
3. The Laravel server compares the client's hash with the database. If there is a mismatch, the transaction is rejected and the client is forced to synchronize rules.

---

## 3. Detailed Phase Breakdown

### Phase 1: Offline-Tolerant POS Cache & Shell
Implement caching layers for product lookups and cart recovery, maintaining strict online checkout enforcement.

#### Step 1.1: Cache Bootstrap API Endpoint
- **Task**: Create `/api/pos/bootstrap-cache` returning:
  - Active products and branch-specific price overrides.
  - Tax types, tax categories, and active tax rules.
  - Active cashier permissions, tenant config, and branch settings.
  - `tax_configuration_version_hash`.
- **Backend Service**: `App\Services\POS\OfflineReadiness\CacheBootstrapService`.

#### Step 1.2: Client IndexedDB Caching
- **Task**: Initialize IndexedDB on POS boot. If online, fetch `/api/pos/bootstrap-cache` and write records to local tables.
- **Frontend File**: `resources/js/POS/offline/catalogCache.ts`.

#### Step 1.3: Connectivity State Hook
- **Task**: Expose network connectivity events.
- **Hook**: `useConnectivityStore` monitoring `online` and `offline` browser events.
- **UI Element**: Render a persistent colored status banner ("Connected" vs "Offline - Checkout Locked").

#### Step 1.4: Cart Draft Store
- **Task**: Persist active cart items to local storage. Restore cart payload on page reload or cash drawer lockout recovery.
- **Frontend File**: `resources/js/POS/offline/cartDraftStore.ts`.

#### Step 1.5: Checkout Connectivity Guard
- **Task**: Block checkout actions if connectivity is lost.
- **Logic**: Enforce in `offlineGuards.ts` and disable elements on the POS checkout screen.

---

## 4. Test Scenarios

### Backend Integration Tests
- **Cache Endpoint Validation**: Check `/api/pos/bootstrap-cache` returns complete schemas with a valid version hash under dynamic tenant scopes.
- **Configuration Lockout**: Verify that sending an outdated `X-Tax-Config-Hash` to POST `/pos/checkout` returns a `409 Conflict` (Config Stale) response and blocks transaction persistence.

### Frontend Integration / Playwright Tests
- **Offline Shell Loading**: Mock offline state and ensure POS catalog loads cached items from IndexedDB.
- **Cart Draft Recovery**: Modify cart, trigger page refresh, and verify items are restored.
- **Checkout Block**: Assert that the payment button is disabled in offline mode and clicking it produces no API requests.

---

## 5. Phase 2 Implementation Framework: Controlled Offline Sales

**Status**: Approved for Production-Grade Development (External Review Deferred)

Epic 28 Phase 2 is approved for production-grade development for controlled early partner adoption. External CPA/BIR review is deferred until post-development. Marketing or formal compliance claims remain prohibited until review is completed.

### Hybrid Terminal-Bound Model
1.  **Terminal-Bound Prefixes**: Each terminal has a permanent registered prefix (e.g., `INV-T01-000001`, `INV-T02-000001`). Each terminal sequence is independent and non-overlapping.
2.  **Server-Side Registry**: The server remains the registrar of terminal prefixes, starting sequences, current accepted sequences, suspended/lost ranges, and reconciliation status.
3.  **Independent Client Consumption**: The offline client may consume only its own terminal-bound sequence context.
4.  **Provisional Client Status**: Official invoice status remains pending until server reconciliation, unless later CPA/BIR review approves offline-issued invoices as official.
5.  **Strict Security Containment**: Browser-only IndexedDB is strictly a provisional checkout queue. Official offline selling requires native/encrypted local storage wrappers.
6.  **Audit-Locked Reconciliation**: Implement a dedicated `ReconciliationService` on the Laravel backend. It must validate the client signature, check for duplicate client UUIDs, recalculate taxes using fixed-point decimals, and write the late-sync audit logs.
7.  **Fiscal-Day Alignment**: Record the transaction under its actual local timestamp, but apply a "Late-Sync Adjustment" status to ensure Z-report integrity and GCT verification.

### Phase 3: Local compliance layer (Deferred)
- Local Grand Cumulative Total (GCT) calculations within an encrypted database wrapper (e.g. SQLite SQLCipher inside a native wrapper like Electron) to satisfy tamper-free requirement audits.

