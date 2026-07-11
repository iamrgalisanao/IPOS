# Terminal Sync Diagnostics & Reliability User Guide

Status: Validated
Last Updated: 2026-07-11
System Area: POS Terminal, Back Office -> Admin Settings
User Roles: Cashier, Owner/Admin, Support Team

---

## 1. Purpose

The Terminal Sync Diagnostics module provides cashiers, support teams, and administrators with visibility into the synchronization state between the local POS terminal cache and the IPOS Core server. It prevents data loss by exposing pending offline captures, retryable failures, review-required conflicts, sync latency, missing terminal sequence ranges, and format errors.

---

## 2. Who Can Use This Feature

This feature contains sensitive operational and diagnostic configurations. Access is restricted to:
* **Cashier**: Can see terminal-local queue status, use **Check Connection**, and view cashier-safe queue entries.
* **Owner / Admin**
* **Technical Support / System Engineers**

---

## 3. Access Path

Go to:
* **POS Terminal -> Checkout -> Offline banner / View Queue / Retry Sync**
* **Main Menu → Administration → Sync Diagnostics**
* **Main Menu → Sandbox → Payload Validation**

---

## 4. Operational Instructions

### A. POS Terminal Quick View
1. On the POS terminal, review the top connectivity banner and the right-side **Offline Sync Status** panel.
2. Confirm whether the terminal is **Online**, **Offline**, or **Checking/Pending**.
3. Use the banner queue count and **View Queue** to confirm whether locally captured sales are pending, failed, or in review.

### B. Cashier-Facing Queue Status
The POS terminal shows a cashier-safe summary:
* **Pending**: Offline sale was captured locally and is waiting for server synchronization.
* **Syncing**: The terminal is currently attempting to submit the queued sale.
* **Failed**: The terminal could not reach the server or received a retryable server failure.
* **Review / Conflict**: The server rejected the record for administrative review, such as sequence mismatch or validation conflict.

Cashiers may use:
1. **Check Connection** to re-test server reachability.
2. **View Queue** to confirm the sale is still stored locally.
3. **Retry Sync** only when the terminal shows **Online**.

If **Review / Conflict** remains after reconnecting, do not re-enter the same sale. Escalate to an admin so the queue record can be reviewed without creating a duplicate transaction.

### C. Offline Checkout Sync Rules
1. Offline checkout is provisional and cash-only.
2. The Split Payment Wizard must still open so the cashier can confirm tendered cash.
3. The sale is queued locally after **Capture Offline Sale**.
4. The queued sale is not final ledger posting until the server accepts reconciliation.
5. Card, e-wallet, bank transfer, voids, refunds, and official finalization require server connectivity.

### D. Browser Shell and Service Worker Refresh
The POS terminal uses a service-worker cached shell so a refresh can still load the terminal while the server is unavailable. After a deployment, the terminal must reconnect once while the server is available so the new shell version can install.

If support asks for the active bundle, open the browser console and confirm the POS bundle name against the current build manifest. Bundle filenames are build-hashed and should not be treated as fixed reference values. For the 2026-07-11 stabilization baseline, the expected shell cache is `ipos-terminal-shell-v31-20260711`.

### E. Session and Terminal Access Messages
If the POS terminal shows a session or access banner:
1. **POS Session Needs Attention** means the server is reachable but the browser session is stale or expired. Sign in again while online.
2. **Terminal Context Not Verified** means the registered terminal identity is missing or mismatched. Retry context checks or ask support to verify the Sales Machine Profile.
3. **Clock-In Required** means the cashier must use the shift/timecard flow before performing the protected action.

These messages should not hide the cart, queue drawer, or checkout controls. If controls overlap or disappear, capture a screenshot and treat it as a UI defect.

### F. Hardware Availability Boundary
Printer and cash drawer devices are not available in the current reference UAT environment. Offline cash capture should not be blocked only because these devices are absent, but the project must not claim receipt printer or drawer readiness until physical hardware validation is completed.

### G. Monitoring Terminal Sync Statuses in Admin
1. Navigate to **Administration → Sync Diagnostics**.
2. Review the list of active terminals. Each row displays:
   * **Terminal ID** & **Assigned Branch**
   * **Last Heartbeat / Contact Timestamp**
   * **Sync Latency** (Time elapsed since the last transaction was pushed)
   * **Connection Status** (Green = Online/Sync Current, Amber = Sync Lagging, Red = Offline)

### H. Validating Terminal Sequence Registry
Terminal-bound sequence numbering prevents offline sales from overwriting invoice sequences:
1. Open the details page for a target POS terminal.
2. Review the **Sequence Registry Ledger**.
3. If the terminal sequence registry shows missing sequences (e.g. gap between sequence #104 and #106), the dashboard will flag a warning.
4. If the POS banner reports `SEQUENCE_OUT_OF_ORDER`, compare the expected terminal sequence with the queued local reference.
5. *Action*: Check the terminal's local queue buffer. If the missing sequence exists locally, trigger sync for that sequence first; otherwise classify the record for admin review before accepting later sequences.

### I. Utilizing the Sandbox Payload Validator
Before registering a new terminal build or troubleshooting a payload structure error:
1. Navigate to **Sandbox → Payload Validation**.
2. Paste the raw terminal JSON payload.
3. Click **[Validate Payload]**.
4. The validator will check the structure, tax models, currency precision, and UUID formats.
5. If validation passes, the screen displays a success indicator; if it fails, it highlights the exact JSON path and schema mismatch reason.
