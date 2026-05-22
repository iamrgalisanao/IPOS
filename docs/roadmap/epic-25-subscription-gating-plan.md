# Epic 25: Subscription-Based Feature Gating

## Overview
**Goal**: Implement a robust, secure, and configurable system-level feature gating engine that restricts tenant capabilities (e.g., maximum branches, QuickBooks integration, visual layout editing, or advanced reporting) based on their subscription tier defined in the database.

---

## Status
*   **Status**: `COMPLETED / VALIDATED / CLOSED` (Accepted on 2026-05-17)
*   **Core Architecture**: Multi-layer gating engine implemented across HTTP middlewares, CLI commands, background queues, and Inertia frontend parameters. Powered by `tenants.subscription_metadata` JSON configuration.

---

## Canonical Subscription Tier Matrix

Below is the official packaging matrix mapping the three core subscription tiers of the IPOS platform to their respective operational features and numeric resource limits (as configured in [subscriptions.php](file:///Users/teamsolo/Documents/Dev/IPOS/config/subscriptions.php)):

| Operational Category | Feature / Limit Key | Basic Plan (`basic`) | Professional Plan (`professional`) | Enterprise Plan (`enterprise`) |
| :--- | :--- | :---: | :---: | :---: |
| **Tier Details** | **Name** | Basic Plan | Professional Plan | Enterprise Plan |
| **Sales & Operations** | `sales.pos` (POS Operations) | ✅ Enabled | ✅ Enabled | ✅ Enabled |
| | `layout.custom` (Visual POS Builder) | ❌ Locked | ✅ Enabled | ✅ Enabled |
| **Product & Catalog** | `catalog.view` (Browse Catalog) | ✅ Enabled | ✅ Enabled | ✅ Enabled |
| | `catalog.edit` (Manage Products/Recipes) | ❌ Locked | ✅ Enabled | ✅ Enabled |
| **Procurement & Supply** | `procurement.basic` (Supplier & POs) | ❌ Locked | ✅ Enabled | ✅ Enabled |
| | `procurement.advanced` (Expiry / RMA)* | ❌ Locked | ❌ Locked | ✅ Enabled |
| **Integrations** | `quickbooks.sync` (QuickBooks Sync) | ❌ Locked | ❌ Locked | ✅ Enabled |
| **Analytics & Reporting**| `reports.basic` (Sales Hist. / Z-Rep.) | ✅ Enabled | ✅ Enabled | ✅ Enabled |
| | `reports.advanced` (Tax / Audit Logs) | ❌ Locked | ✅ Enabled | ✅ Enabled |
| **Resource Limits** | `max_branches` (Active Branch limit) | **1** | **5** | **Unlimited** (`PHP_INT_MAX`) |
| | `max_users` (Active User accounts) | **3** | **15** | **Unlimited** (`PHP_INT_MAX`) |

> [!NOTE]
> *`procurement.advanced` covers high-risk procurement extensions (Expiry lot management, FEFO fulfillment, RMA returns, and QuickBooks liability handoff) mapped to Epic 26 (currently parked as `PLANNED / DEFERRED`).

---

## System Integration Under the Hood

### 1. Developer APIs (Models)
*   **Check Feature Entitlement**:
    ```php
    if ($tenant->hasFeature('quickbooks.sync')) {
        // execute premium feature logic
    }
    ```
*   **Enforce Resource Bounds**:
    ```php
    if (!$tenant->withinLimit('max_branches', $currentBranchCount)) {
        abort(403, 'Maximum active branch count exceeded for your plan.');
    }
    ```

### 2. HTTP Route Protection
Endpoints are guarded directly inside [web.php](file:///Users/teamsolo/Documents/Dev/IPOS/routes/web.php) by registering the `subscription.feature` middleware.
*   **Example Route Protection**:
    ```php
    Route::middleware(['auth', 'tenant', 'subscription.feature:quickbooks.sync'])->group(function () {
        // premium accounting endpoints
    });
    ```

### 3. Background Job Execution Guarding
Any background asynchronous job or queue processor must hook into the tenant state context to prevent unauthorized background synchronization execution:
```php
public function handle(): void
{
    $tenant = $this->getTenantContext();
    if (!$tenant->hasFeature('quickbooks.sync')) {
        $this->fail(new \Exception('Background job blocked: Tenant lacks Quickbooks sync entitlement.'));
        return;
    }
    // process job logic
}
```

---

## Completed Stories

### Story 25.1: Subscription Configuration & Tier Definitions
*   **Goal**: Establish a central config file (`config/subscriptions.php`) mapping tiers (`basic`, `professional`, `enterprise`) to their permitted features and resource limits.
*   **Deliverables**:
    *   `config/subscriptions.php`
    *   Tenant helper methods: `Tenant::hasFeature()` and `Tenant::withinLimit()`.

### Story 25.2: System-Level Feature Gating Middleware
*   **Goal**: Create a global/route-level middleware (`EnforceSubscriptionGate`) that dynamically checks the active tenant's subscription configuration and aborts with a graceful payload if missing.
*   **Deliverables**:
    *   `App\Http\Middleware\EnforceSubscriptionGate`
    *   Route registration and middleware grouping.

### Story 25.3: Background Queue Job & Console Guarding
*   **Goal**: Ensure background sync workers (e.g., QuickBooks Sync Jobs) and CLI commands verify the tenant's subscription validity before processing queue tasks.
*   **Deliverables**:
    *   Subscription check hook in base job classes.

### Story 25.4: Frontend Gating & Inertia Integration
*   **Goal**: Share subscription feature parameters globally in Inertia state to dynamically show, hide, or render upgrade screens for locked modules in the sidebar and views.
*   **Deliverables**:
    *   Prop mapping in `HandleInertiaRequests.php`.
    *   Upgrade Page component and conditional locks in sidebar navigation layout.

### Story 25.5: Legacy Grandfathering & Onboarding Flow
*   **Goal**: Create schema migrations and data seeders to cleanly migrate existing tenants to a grandfathered tier with all capabilities active, avoiding user disruption.
*   **Deliverables**:
    *   Database migration setting default `subscription_metadata`.
