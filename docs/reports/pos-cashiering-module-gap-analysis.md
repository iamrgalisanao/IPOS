# IPOS POS Cashiering & Shift Module Gap Analysis
**Date:** 2026-05-29  
**Target:** Cashiering Module & POS Terminal Comparison (UTAK, Mosaic, iRipple, ANSI, StoreHub, and IPOS)

---

## 1. Executive Summary

The cashiering and shift-management module is the operational core of any POS system. It controls user accountability, drawer integrity, cash safety, and BIR tax compliance. This document evaluates how **UTAK, Mosaic, iRipple, ANSI, and StoreHub** implement their POS terminal cashiering modules, focusing on:
1.  **Shift & Drawer Operations** (Opening, pay-ins/payouts, blind counts, spot audits, shift reconciliation).
2.  **Offline Database & Security Controls** (How local data is secured and synchronized).
3.  **Invoice Sequence Integrity** (Prevention of duplicate sequences and gaps).
4.  **Z-Reading & Day Closure Compliance** (Philippine BIR CAS constraints and offline limitations).
5.  **Multi-Register & Store-Level Sync** (Register-to-register, table coordination, KDS routing).

While **IPOS** contains highly compliant Z-read calculations, blind closing, and split-payment reference guards, several key gaps remain regarding **local network multi-register sync**, **manager-led web-portal reconciliation**, **on-the-fly spot audits**, and **offline Z-reading constraints**.

---

## 2. Competitive Analysis: Cashiering & Terminal Implementations

### A. UTAK POS (SMB Tablet Cashiering)
*   **User/Shift Tracking:** Cashiers switch profiles directly on the tablet layout. All transactions are tagged with the active user ID.
*   **Drawer Controls:** Features a simplified "Cash Drawer" tab where cashiers input starting cash (float), additions (pay-ins), and expenses (payouts). The tablet uses a live formula: `Float + Additions - Expenses + Cash Sales = Expected Cash`.
*   **Closing & Reconciling:** At shift end, the cashier prints a Z-report showing cash sales vs. card sales, inputs the final count, and resets register totals locally.
*   **Offline Trust Model:** Sandboxed SQLite on Android/iOS. If the internet drops, local data remains locked in the OS sandbox.
*   **Invoicing:** Machine Identification Number (MIN) prefix is appended to all invoice sequences (`UTAK-M01-0001`).

### B. Mosaic POS / Resto iQ (F&B Operations Portal)
*   **User/Shift Tracking:** Standard login per cashier. Prompts for shift opening float before enabling checkout.
*   **Drawer Controls:** Supports two shift closure modes:
    1.  **Standard Shift Change:** Cashier logs out using a blind-count currency breakdown popup.
    2.  **Spot Audit:** Guided count showing expected amounts and immediate over/short totals for quick verification.
*   **Closing & Reconciling:** 
    *   *POS Level:* Cashier performs blind count and logs out, generating an X-read.
    *   *Manager Portal:* Managers log in to the Resto iQ web dashboard under `End of Day > Reconcile Day`. They inspect unbalanced drawers, input corrections, and click `Close Day` to lock the daily transactions.
*   **Offline Trust Model:** Sandboxed SQLite storage inside an iOS/Web wrapper.
*   **Invoicing:** Terminal-specific offline counters compiled and validated during manager reconciliation.

### C. iRipple / Barter RMS (Enterprise Retail POS)
*   **User/Shift Tracking:** Hard coupled to physical cash drawers. Shift opening requires manager authentication if cashiers are swapped.
*   **Drawer Controls:** Heavy focus on cashier audit trails. Integrates cash drops (mid-day drawer clearings to keep drawer cash low) and payouts with reason codes.
*   **Closing & Reconciling:** Enforces strict blind closing. Cashiers must count every coin, note, voucher, and card receipt and input the total counts. The POS highlights variances and automatically prints a detailed X/Z-report, resetting register memory totals to zero.
*   **Offline Trust Model:** Relational database (SQL Server Compact or SQLite) bound to local Windows services, protected by active OS-level file permissions.
*   **Invoicing:** Unique sequential terminal-bound suffix/prefix combinations.

### D. ANSI Information Systems (Enterprise ERP Integrated POS)
*   **User/Shift Tracking:** Windows-bound cashier authentication mapped directly to ERP employee database profiles.
*   **Drawer Controls:** Cash drawer opening is controlled by database triggers. Mid-day cash drops are pushed directly to a vault ledger.
*   **Closing & Reconciling:** Enforces blind counts. Upon Z-report execution, sales data is atomically exported, summarized, and locked into the central **SAP Business One** General Ledger (GL) to prevent historical database edits.
*   **Offline Trust Model:** Runs a local database engine (SQL Express) on Windows. If connection drops, local transactions accumulate and are streamed to SAP HANA upon reconnection.
*   **Invoicing:** Chronological non-resettable sequential numbering hardwired to the local machine profile.

### E. StoreHub (iPad & Android Retail POS)
*   **User/Shift Tracking:** PIN-based quick swap on POS layout.
*   **Drawer Controls:** Tracks float, pay-ins, payouts, and card types. 
*   **Closing & Reconciling:** Cashier performs a shift close with options for a printer-printed summary. **Z-Reading day closure is strictly blocked if the iPad is offline.** Operators must connect to the internet to consolidate shift totals and close the business day.
*   **Offline Trust Model:** Sandboxed iOS/Android SQLite.
*   **Multi-Register Coordination:** **Multiple Register Sync (MRS)** allows one master iPad to act as a local database sync broker, coordinating table seating, cart orders, and KDS printer streams across other registers via a local Wi-Fi router.

---

## 3. Comparative Feature Matrix

| Feature | UTAK | Mosaic POS | iRipple | ANSI | StoreHub | IPOS (Current) |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Shift Open Float Setup** | Yes | Yes | Yes | Yes | Yes | Yes (Epic 12.1) |
| **Drawer Pay-in/Payout** | Yes | Yes | Yes | Yes | Yes | Yes (Epic 12.2) |
| **Mid-Day Cash Drops** | No | No | Yes | Yes | Yes | No |
| **Blind Counting** | No | Yes | Yes | Yes | Yes | Yes (Epic 12.6) |
| **Spot Auditing UI** | No | Yes (Spot) | Yes (Manager)| Yes | No | No |
| **Web-Portal Reconciliation**| No | Yes (BackOffice) | Yes | Yes (ERP) | No | No (Server only) |
| **Offline DB Protection** | Android Sandbox| iOS Sandbox | OS Permissions | SQL Service | iPad Sandbox | Browser IndexedDB |
| **Offline Z-Reading** | Yes | Yes | Yes | Yes | **Blocked** | Yes (Late-sync) |
| **Local Subnet Register Sync**| No | No | No | Yes (Server) | **Yes (MRS)** | No |

---

## 4. Key Gap Analysis for IPOS

### Gap 1: Local Subnet Multi-Register Sync (MRS)
*   **Competitor Standard (StoreHub):** Allows multiple tablet terminals within the same branch to coordinate checkouts, table layouts, and kitchen printing without internet, utilizing a designated master tablet as a local LAN sync broker.
*   **IPOS State:** IPOS is designed as a standalone browser/Tauri client. Terminals only sync with the cloud backend (`POST /api/pos/offline-sync`).
*   **Impact:** In multi-register F&B outlets, a network dropout isolates registers from each other, preventing shared table management and causing duplicate print orders.

### Gap 2: Spot Audits & Manager Auditing Interfaces
*   **Competitor Standard (Mosaic):** Cashiers can trigger a "Spot Audit" requiring manager authorization. The UI displays expected cash vs. actual cash on the register screen instantly to identify discrepancies mid-shift.
*   **IPOS State:** IPOS currently supports blind closing at shift end (`Epic 12.6`), but lacks a live manager-facing spot audit or drawer inspection overlay during an active shift.
*   **Impact:** Managers cannot run surprise till checks or audit cash levels during peak hours without closing the cashier's active shift.

### Gap 3: Mid-Day Cash Drops (Vault Transfers)
*   **Competitor Standard (iRipple/ANSI):** To maintain drawer safety, cashiers are prompted to perform a "Cash Drop" when cash sales cross a configurable limit (e.g., PHP 10,000). The POS logs the drop as a transfer to the branch vault.
*   **IPOS State:** IPOS supports basic pay-ins and payouts, but lacks structured cash drop configurations or drawer cash limit warnings.
*   **Impact:** Cashiers retain high amounts of currency in their registers, increasing the risk of operational leakage or theft.

### Gap 4: Manager-Led Web Reconciliation Portal
*   **Competitor Standard (Mosaic):** If a shift is closed with a variance, the cashier logs out. The final reconciliation does not occur on the register; instead, a manager reconciles the drawer, reviews discrepancy notes, and confirms the deposit in the back-office portal before closing the day.
*   **IPOS State:** Shift closing and Z-reads are processed and calculated directly on the terminal, with manager approvals handled as a simple review trigger (`Epic 12.7`).
*   **Impact:** Backend managers cannot modify transaction counts or adjust discrepancy notes once the terminal closes the shift.

---

## 5. Architectural Recommendations for IPOS Roadmap

### Phase 1: Cashiering UX Hardening (Epic 32/33 Extension)
1.  **Add Cash Drawer Warning Thresholds:** Configure a tenant-scoped "Max Drawer Cash Limit." If Cash expected exceeds this threshold, flash a POS warning requesting a **Cash Drop (Vault Transfer)**.
2.  **Implement Spot Audit UI Overlay:** Add a manager-password-protected "Spot Audit" action within the register settings. This displays expected vs. counted figures without ending the cashier's shift.

### Phase 2: Manager Back-Office Reconciliation Dashboard (Epic 33 Extension)
1.  **Introduce Reconciliation Workspaces:** In the IPOS admin portal, allow managers to view closed shifts flagged with variances. Managers can adjust physical counts, add auditing comments, and formally approve the drawer before posting daily summaries to the General Ledger.

### Phase 3: Multiple Register Sync (Epic 36 Extension)
1.  **Tauri Local LAN Sync Broker:** Deploy a lightweight broker service within the Tauri container of the primary register. Other registers on the same local subnet connect to the broker's local IP address to write drafts and table updates, which the master register packages and syncs to the cloud.
