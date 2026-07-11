# 05. Common Errors and Troubleshooting

Status: Validated
Last Updated: 2026-07-11
System Area: System Administration & Diagnostics
User Roles: All Roles

---

## 1. Access and Permission Errors

### "Access Denied" or "Unauthorized"
* **Cause**: Your user account does not have the required role or capability permission (e.g. standard cashiers attempting to view back office reports).
* **Action**: Check your profile. If you require access, ask your system administrator to assign the appropriate role (e.g. Branch Manager or Accountant) in **User Management**.

---

## 2. Cash Drawer and Shift Operations

### "Security Block: Cashiers cannot approve their own high-value cash drop"
* **Cause**: You logged in with manager credentials to approve a high-value drop, but you are also the cashier who owns the active shift.
* **Action**: Call another supervisor or manager assigned to the branch to enter their credentials and authorize the drop.

### "A deposit record already exists for this shift"
* **Cause**: The manager clicked the approve shift button twice, or another manager already approved and reconciled the shift.
* **Action**: Refresh the page. Re-verify the status of the shift; it should now display as `approved` with the deposit voucher visible.

---

## 3. Voids & Refunds

### "Security Block: Supervisor credentials required to authorize void/refund"
* **Cause**: A cashier attempted to void or refund a transaction but does not possess `pos.void` or `pos.refund` permissions.
* **Action**: A supervisor or branch manager with direct permissions must enter their email and password in the authorization fields in the modal to approve the adjustment.

### "Conflict Error: Void is blocked because the transaction's shift is closed"
* **Cause**: The transaction occurred in a previous or closed cashier shift. Voids are restricted to same-shift operations to preserve daily Z-read reporting lines.
* **Action**: Close the modal, click **Refund Items** instead, select the returned items and quantities, and process a Refund.

### "Conflict Error: Duplicated request (Idempotency Key Collision)"
* **Cause**: The server detected the exact same idempotency header key (typically from rapid double-clicking or network retry).
* **Action**: The system automatically replies with the cached response snapshot. Refresh the page to verify if the void/refund has already succeeded and updated the status of the sale.

---

## 4. Inventory and Procurement

### "Tolerance Limit Exceeded"
* **Cause**: The unit cost or quantity listed on the supplier invoice differs from the original Purchase Order (PO) and Goods Received Voucher (GRV) by more than the allowed percentage (e.g., unit cost drifted by 3%).
* **Action**: Audit the values. Reject the invoice and request a corrected debit note from the supplier, or have a System Admin override the check to post the liability.

### "Lot Number and Expiry Date are required for perishable items"
* **Cause**: You are attempting to post a Goods Received Voucher (GRV), but one or more perishable items do not have batch lot numbers or expiration dates recorded.
* **Action**: Enter the batch info from the physical packaging before posting the GRV.

---

## 5. Integrations & Sync Diagnostics

### "QuickBooks Sync Error: Missing Account Mapping"
* **Cause**: A POS payment type or branch tax category is not mapped to any chart of accounts code inside QuickBooks Online.
* **Action**: An accountant must go to **Integrations → Account Mapping**, assign the account codes, and click **[Retry Sync]**.

### "Offline sync failed" / "Retry Sync failed"
* **Cause**: The terminal has queued provisional offline sales, but the server rejected or could not accept the sync batch. Common causes include an expired login session, invalid terminal context, malformed payload, or a validation conflict that requires review.
* **Action**: Confirm the terminal shows **Online**, refresh the POS shell, and try **Retry Sync**. If the queue remains failed, an admin should inspect the offline sync review/diagnostic screen and confirm that the terminal profile, tenant, branch, and queued payload are valid.

### "Review required" / "SEQUENCE_OUT_OF_ORDER"
* **Cause**: The server received an offline sequence that does not follow the expected terminal sequence. This can happen when older queued records are still unsynced, were quarantined for review, or belong to another terminal profile.
* **Action**: Do not re-enter the same customer sale. Open **View Queue**, confirm the affected sequence, and ask an admin to review the terminal queue and sequence registry before posting.

### "Pending server synchronization and reconciliation"
* **Cause**: A sale was captured while offline. The terminal stored the sale locally, but it is not final ledger posting yet.
* **Action**: Keep the terminal open or reconnect it to the server, then use **View Queue** or **Retry Sync**. Do not treat the transaction as final official server posting until sync is accepted.

### "Check Connection" triggers 401 Unauthorized console messages
* **Cause**: The server is reachable, but the cached POS shell does not have a valid authenticated browser session or terminal context for protected endpoints such as timecard status, active shift, layout, or local sync broker discovery.
* **Action**: Sign back in or refresh once while the server is online. The POS should continue using cached/offline state when available; support should verify the browser is running the current service-worker shell version.

### "Unexpected token '<'" while loading products
* **Cause**: The product search endpoint returned an HTML login/error page instead of JSON, usually because the browser session expired during reconnect.
* **Action**: Re-authenticate or refresh while online. If products are cached, the terminal should fall back to offline catalog results and allow cash-only offline capture.

### Old POS bundle still appears in console
* **Cause**: The terminal is still running a stale service-worker cached shell. This is common if the server was offline when the browser attempted to update `/sw.js`.
* **Action**: Bring the server online and refresh the terminal once. For the 2026-07-11 stabilization baseline, support should expect shell cache `ipos-terminal-shell-v31-20260711`; POS bundle filenames are build-hashed, so verify the active bundle against the current manifest instead of a fixed filename.

---

## 6. Employee Timecards & Lock Screen

### "403 TIMECARD_REQUIRED" / "You must be clocked in before performing this action"
* **Cause**: You are attempting to open a cashier shift, validate a checkout, create a sale, process a void/refund, or record pay-in/out without having an active clocked-in timecard.
* **Action**: Lock the terminal console, switch to the **Timecard Clock** tab, input your employee PIN, and submit a clock-in before returning to checkout.

### "429 PIN_RATE_LIMITED" / "PIN verification is temporarily unavailable"
* **Cause**: An incorrect employee PIN was input 5 times (blocks terminal for 1 minute) or 10 times (blocks terminal for 15 minutes) in a row.
* **Action**: Wait for the lockout timer to expire, or request a manager override.

### "409 OPEN_SHIFT_BLOCKS_CLOCK_OUT" / "Please close your cashier shift before clocking out"
* **Cause**: You tried to clock out of your HR timecard while you still have an open cash drawer shift.
* **Action**: Close your cashier shift first (which records blind counts and reconciles deposits) and then clock out, or request a supervisor bypass.

### "403 TERMINAL_CONTEXT_INVALID" / "Invalid terminal context"
* **Cause**: The POS terminal request is missing a valid registered terminal identity, or the `X-Terminal-ID` does not belong to the active tenant and branch.
* **Action**: Reopen the registered POS terminal URL/session for the correct branch. If this happens on a configured tablet, ask an admin to verify the Sales Machine Profile registration and terminal identifier.

### "POS Session Needs Attention"
* **Cause**: The server is reachable, but the browser session is stale or expired. This often appears after reconnecting a terminal that was offline for a while.
* **Action**: Sign in again while the server is online, then return to the POS terminal. The cart and queue should remain visible where cached state exists.

---

## 7. Offline Checkout and Split Payments

### "Offline capture is available for cash only"
* **Cause**: The terminal is offline. Offline sale capture is provisional and supports only cash tender in the Split Payment Wizard.
* **Action**: Use a cash payment to queue the sale locally, or reconnect before using card, e-wallet, bank transfer, or other payment methods.

### "Capture Offline Sale" button is disabled
* **Cause**: The payment rows are incomplete, cash tendered is below the payment amount, no active shift/timecard is available, or the terminal is not allowed to perform controlled offline capture.
* **Action**: Make sure the sale total is fully covered by cash, cash tendered is equal to or greater than the amount, the cashier is clocked in with an open shift where required, and the terminal has a valid offline machine profile.

### Offline sale is captured but not visible in Pending count
* **Cause**: The queue panel may be showing a stale summary, an older failed/review record, or a synced history list instead of the current pending queue.
* **Action**: Click **View Queue** and verify the latest local transaction reference and timestamp. If the top banner count and right-side status disagree, refresh the POS shell while online and check IndexedDB/local queue diagnostics before entering another sale.

### Printer or cash drawer is unavailable
* **Cause**: Physical receipt printer and cash drawer hardware are not attached or not yet validated for the current terminal environment.
* **Action**: Continue eligible cash-only offline capture if the terminal allows it and the sale does not require hardware output. Do not mark printer/drawer behavior as validated until physical hardware UAT is completed.
