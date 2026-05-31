# Architecture Evaluation & Feedback: POS Integration Competitiveness Roadmap (Epics 32–36)

This document provides a technical evaluation, structured breakdown, and risk assessment for the proposed **Epics 32 through 36** in the IPOS roadmap. These epics aim to elevate IPOS from a compliance-focused POS intake server to a highly competitive, operationally resilient enterprise retail and F&B platform.

---

## Epic 32: IPOS POS Terminal Sync Diagnostics & Reliability
**Core Purpose:** Make IPOS’s own POS terminal sync observable, testable, and supportable.

### 1. Context & Business Value
Currently, IPOS has strong internal queue rules (idempotency, checksum checks, database validation). However, it behaves like a "black box" for external POS client providers (like UTAK or Mosaic clients). When integrations fail, resolving them requires reviewing Laravel backend logs. Exposing developer diagnostic tools accelerates onboarding, lowers support overhead, and creates integration reliability.

### 2. Proposed Story Breakdown
*   **Story 32.1: Developer OpenAPI & Interactive Sandbox Portal**  
    Publish interactive documentation for `POST /api/pos/offline-sync` and sandbox validation endpoints (`POST /api/v1/sandbox/payload/validate`) complete with payload examples and validation rules.
*   **Story 32.2: Submission Status Query API**  
    Expose a secured, rate-limited endpoint `GET /api/v1/submissions/{submission_uuid}` allowing registers to check if a specific transaction sequence number was accepted, duplicate, queued, or rejected.
*   **Story 32.3: Operational Sync Health Dashboard**  
    A read-only admin screen showing terminal heartbeats, time since last sync, pending batch queue size, and sync failure rates.
*   **Story 32.4: Real-time Integration Event Webhooks**  
    Allow enterprise tenants to register webhook URLs for automatic notification of sync failures (`sync.failed`, `submission.rejected`).

### 3. Compliance & Architectural Risks
*   **Data Disclosure Vulnerability:** Terminal tokens are scoped by tenant/branch. The `GET /api/v1/submissions/{submission_uuid}` query must fail-close (return a `404 Not Found`) if the requested submission belongs to a different tenant or terminal to prevent cross-tenant enumeration.
*   **DDoS via Heartbeat Polling:** POS clients could flood the sync health endpoints with persistent polling.
    *   *Mitigation:* Implement strict Redis-backed rate limiting per terminal client.

---

## Epic 33: Late-Sync Auditability & Z-Report Reconciliation
**Core Purpose:** Ensure delayed offline sales are auditable and do not silently alter closed reports.

### 1. Context & Business Value
Under Philippine BIR (Bureau of Internal Revenue) and CAS guidelines, once a Z-reading is closed for a business day, historical transactions for that period cannot be silently added or altered. However, offline terminals in remote locations may sync data days or weeks late. IPOS must resolve this "reconciliation time-warp" to preserve tax compliance while accurately updating inventory and gross cumulative totals.

### 2. Proposed Story Breakdown
*   **Story 33.1: Multi-Dimensional Transaction Time Tracking**  
    Separate transaction dates into three attributes:
    1.  `invoice_issued_at` (actual business sale date/tax occurrence).
    2.  `reporting_basis_at` (the date of entry in the current accounting period).
    3.  `sync_completed_at` (immutable audit-trail timestamp of ingestion).
*   **Story 33.2: Prior Period Adjustment Ledger**  
    Instead of altering historically closed Z-reports, record late-sync sales in the current fiscal period's sub-ledger as "Prior Period/Offline Adjustments," keeping the General Ledger (GL) reconciled.
*   **Story 33.3: Automatic Z-Report Audit Flagging & Discrepancy Logs**  
    If a transaction is retroactively injected into a locked day, flag that Z-report as "Altered by Late-Sync" and create an immutable audit trail entry detailing the submission UUID, receipt number, and change in sales/VAT.

### 3. Compliance & Architectural Risks
*   **Grand Cumulative Total (GCT) Drift:** Local terminals accumulate offline totals while the server accumulates online ones. If an offline transaction is rejected or duplicated, server and client GCTs will drift.
    *   *Mitigation:* The sync payload must include the terminal's reported local GCT. The server must log a reconciliation discrepancy alert if the calculated server total diverges from the terminal's reported GCT.

---

## Epic 34: Enterprise Async Reporting Export
**Core Purpose:** Prevent timeout issues for large compliance/report exports.

### 1. Context & Business Value
Large retail networks generate massive transaction volumes, resulting in e-journals and ledger tables spanning millions of rows. Normal HTTP-response exports (e.g., directly downloading a CSV via Laravel) will time out, consume excessive server memory, or crash browser tabs.

### 2. Proposed Story Breakdown
*   **Story 34.1: Asynchronous Export Engine & Worker**  
    Offload export queries (CSV, PDF, XML e-journals) to background queue workers (using Laravel Horizon). Workers stream DB results chunk-by-chunk to prevent memory leaks.
*   **Story 34.2: Export Status & Notification Dashboard**  
    A UI dashboard where users track export state (`PENDING`, `COMPLETED`, `FAILED`) and download finished packages. Notify users via email when large files are ready.
*   **Story 34.3: Tenant-Scoped External Storage Broker**  
    Allow enterprise tenants to connect their own AWS S3 or Google Cloud Storage buckets for automatic scheduled exports of e-journals and statutory reports.

### 3. Compliance & Architectural Risks
*   **Tamper-Proof File Integrity:** exported e-journals must match the SHA-256 HMAC chain stored in the IPOS database.
    *   *Mitigation:* Compute a file checksum immediately upon export completion, store it in the database, and append it to the export metadata file for auditor validation.
*   **PII Data Exposure:** Exported files sitting on storage buckets represent a security risk under the Data Privacy Act (NPC).
    *   *Mitigation:* Implement a strict retention policy (e.g., auto-delete local temporary export files after 24–48 hours) and enforce signed URL access for downloads.

---

## Epic 35: Recipe Maintenance and Costing Engine
**Core Purpose:** Strengthen F&B inventory, BOM, recipe, and COGS capability.

### 1. Context & Business Value
To win F&B accounts, IPOS must provide more than static product inventory. It needs an operational recipe database that automatically decrements raw ingredients when finished menu items are sold, tracks unit costs, and calculates Cost of Goods Sold (COGS).

### 2. Proposed Story Breakdown
*   **Story 35.1: Unit of Measure (UOM) Conversion Resolver**  
    A backend calculator that handles complex conversions (e.g., purchase unit = "1 Box", stock unit = "10 Bottles", recipe unit = "50 Milliliters").
*   **Story 35.2: Interactive Bill of Materials (BOM) & Recipe Editor**  
    A React workspace allowing operators to construct composite items (e.g., "1 Burger" requires "1 Bun", "150g Beef Patty", "15g Sauce").
*   **Story 35.3: Automated Recipe Deduction Engine**  
    Trigger ingredient depletion logs during sale reconciliation (both online POS sales and offline sync uploads).
*   **Story 35.4: Weighted Average Cost (WAC) Ledger**  
    Calculate margins and COGS dynamically based on historical purchasing prices of raw materials.

### 3. Compliance & Architectural Risks
*   **Concurrency & Negative Stock:** High-volume sales can cause rapid, concurrent stock deductions. If a raw material runs dry, failing a sale is operationally unacceptable.
    *   *Mitigation:* Allow ingredient stock to enter a negative state on the dashboard but log an "Inventory Variance warning." Deductions must run asynchronously via queue jobs to avoid locking POS checkouts.

---

## Epic 36: Local Register Sync and Store-Level Coordination
**Core Purpose:** Support multi-terminal/table/KDS coordination during degraded internet conditions.

### 1. Context & Business Value
In large malls or provincial outlets, internet connections frequently drop. While individual terminals can function offline, they cannot share order information. A waiter taking an order on Terminal A cannot send it to the kitchen display (KDS) or print a bill from Terminal B.

### 2. Proposed Story Breakdown
*   **Story 36.1: Local Subnet Sync Broker**  
    A sync daemon running inside the Tauri desktop client that hosts a local HTTP server to act as a localized database coordinator when the cloud is offline.
*   **Story 36.2: Real-time Local State Pub/Sub (WebSockets/MQTT)**  
    Share table layouts, order statuses, and cart items between registers on the same local area network (LAN).
*   **Story 36.3: Local Print Broker Integration**  
    Directly route order tickets and receipts to local IP network printers without sending print jobs to the cloud.

### 3. Compliance & Architectural Risks
*   **Split-Brain Conflict Resolution:** If Register A and Register B edit the same order offline and later connect to the cloud, resolving the conflict is highly complex.
    *   *Mitigation:* Enforce strict **Single-Authoritative Owner** rules per table or order. Design the offline data model with conflict-free replicated data types (CRDTs) or require terminal operators to manually select the correct sync version on conflict.
*   **Sequence Allocation Protection:**
    *   *Mitigation:* Maintain separate Terminal-Bound Sequence prefixes registered at the BIR level for each physical register to ensure zero invoice number overlapping.

---

## Strategic Roadmap & Priority Matrix

To execute these epics effectively, the following implementation order is recommended to establish high-value foundations first:

```
[Phase 1: Integration & Dev UX]  -->  [Phase 2: Audit & Large Exports]  -->  [Phase 3: Costing & Recipes]  -->  [Phase 4: Local LAN Sync]
      (Epic 32)                             (Epic 33 & 34)                         (Epic 35)                      (Epic 36)
```

1.  **Immediate Priority (Epic 32):** Exposing testing portals and lookup APIs provides immediate relief for POS provider integrations.
2.  **Compliance Hardening (Epic 33 & 34):** Establishes the database stability and e-journal export pipelines required before broad enterprise deployment.
3.  **F&B Competitive Parity (Epic 35):** Enables core restaurant operations.
4.  **Premium/Offline Resilience (Epic 36):** Deployed last as it requires a local Tauri wrapper distribution setup.
