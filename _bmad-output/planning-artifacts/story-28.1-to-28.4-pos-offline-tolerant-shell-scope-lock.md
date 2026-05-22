# Story 28.1 to 28.4: POS Offline-Tolerant Shell Scope Lock

**Date**: 2026-05-19  
**Status**: Planning Only / Scope Locked  
**Implementation Phase**: Not Started  

---

## 1. Goal
Define the precise business rules, architectural constraints, security guidelines, and validation paths for the Phase 1 implementation of **Epic 28: Offline-Tolerant POS Shell**.

This scope lock ensures that we build a highly resilient client-side experience for catalog browsing and cart drafting without compromising the official database records, tax compliance calculations, or transaction numbering logic, which must remain strictly server-authoritative.

---

## 2. Story Scope Boundaries

### Story 28.1: POS Cache Bootstrap API Endpoint
*   **In Scope**:
    *   API route `/api/pos/bootstrap-cache` returning a full catalog payload: products, category mappings, tax categories/rules, active cashier permissions, and tenant branch settings.
    *   Generation of a server-side `tax_configuration_version_hash` embedded in the payload.
    *   Server-side validation during `POST /pos/checkout` to compare client-submitted tax hash with current DB settings, rejecting the checkout (HTTP 409) if the cache is stale.
*   **Out Scope**: Incremental catalog syncing or WebSockets updates.

### Story 28.2: Client IndexedDB Caching Services
*   **In Scope**:
    *   IndexedDB database initialization in the POS SPA shell.
    *   Client-side search service parsing cached product records (sku, name, category) instantly without backend queries.
    *   Automatic background sync of the IndexedDB catalog on POS reload/login.
*   **Out Scope**: Local modification or editing of catalog items. Catalog is read-only.

### Story 28.3: Connectivity State & Checkout Guard UI
*   **In Scope**:
    *   A reactive React hook (`useConnectivityStore`) tracking `navigator.onLine` and pinging the server fallback.
    *   A prominent visual banner indicating offline status.
    *   Disabling the "Pay" / "Finalize Sale" checkout buttons and blocking form submission when connection state is offline.
*   **Out Scope**: Automatic local storage of checkout transactions to sync later. Checkout is blocked completely when offline.

### Story 28.4: Cart Draft Persistence & Restore
*   **In Scope**:
    *   Automatic serialization of the current cart draft into local storage or IndexedDB.
    *   Restoration of the active cart draft after page refresh or browser restarts.
    *   Clearance of the saved draft immediately upon successful server receipt of a finalized sale.
*   **Out Scope**: Syncing multiple drafts across different register machines. Cart draft is machine-bound.

---

## 3. Compliance and Security Rules

### A. Tax Rule Compliance & Mismatch Mitigation
*   **Rule**: The client-side cache must not calculate official receipt totals.
*   **Enforcement**: The backend remains the absolute source of truth. The checkout request must pass:
    ```json
    {
      "cart_items": [...],
      "client_tax_config_hash": "a4b2c8..."
    }
    ```
*   The backend validates `client_tax_config_hash`. If a mismatch is detected, it throws:
    ```json
    {
      "error": "STALE_TAX_CONFIG",
      "message": "Your tax and pricing rules are outdated. Synchronizing cache..."
    }
    ```
*   The frontend intercepts this error, forces a background refresh of the IndexedDB, and updates the cashier's display before allowing another checkout attempt.

### B. Offline State Verification
*   **Rule**: The cashier interface must never bypass the checkout connectivity guard.
*   **Enforcement**: Form validation logic must check the reactive connectivity store. Any attempt to bypass the disabled button by simulating DOM events must be blocked by the underlying React controller logic.

---

## 4. Bounded Monorepo Directory Setup
File organization follows the monorepo bounded module pattern:

```md
resources/js/POS/
├── offline/
│   ├── catalogCache.ts      # IndexedDB catalog initialization & query helpers
│   ├── cartDraftStore.ts    # Local cart draft serialization/restoration
│   ├── connectivityStore.ts # React store and hook for connectivity tracking
│   └── offlineGuards.ts     # Connectivity validation checks
```

---

## 5. Test Matrix

| Test ID | Level | Domain | Scenario Description | Expected Outcome |
| :--- | :--- | :--- | :--- | :--- |
| **TC-28.1-01** | Integration | Backend | Request bootstrap cache API | Response returns active catalog data + tax version hash under current tenant scope. |
| **TC-28.1-02** | Integration | Backend | Submit checkout with stale tax hash | HTTP 409 response; transaction rejected; database unchanged. |
| **TC-28.2-01** | Unit | Frontend | Sync catalog to IndexedDB | IndexedDB stores all products with search index keywords. |
| **TC-28.2-02** | Unit | Frontend | Offline catalog keyword search | Returns matching cached products within 50ms without API requests. |
| **TC-28.3-01** | Integration | Frontend | Lose network connection | Banner updates to "Offline", checkout button is disabled. |
| **TC-28.3-02** | Integration | Frontend | Attempt offline submit | JS controller blocks transaction submission; logs a warning. |
| **TC-28.4-01** | Unit | Frontend | Edit cart items | Cart items are serialized to local draft storage. |
| **TC-28.4-02** | Integration | Frontend | Refresh browser with active cart | Cart UI restores the draft exactly as left. |
| **TC-28.4-03** | Integration | Frontend | Finalize transaction successfully | Local cart draft is cleared from client storage. |
