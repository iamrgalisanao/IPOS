# Sales History & Prior-Period Reports User Guide

Status: Validated
Last Updated: 2026-07-04
System Area: Back Office → Sales
User Roles: Owner/Admin, Branch Manager, Accountant, Cashier

---

## 1. Purpose

This module serves as the historical record of all transactions completed at the branch terminals. It enables supervisors to search sales invoices, review detailed financial breakdowns (VAT, discounts), reprint customer receipts with proper audits, and manage adjustments for late-syncing offline sales, as well as execute secure, supervisor-authorized Voids and Refunds.

---

## 2. Access Path

Go to:
* **Main Menu → Sales → Transaction History**
* **Main Menu → Financials → Prior-Period Adjustments** (Accountants and Admins only)

---

## 3. Screen Overview

* **Sales History Table**: Contains invoice number, cashier, terminal sequence, payment method, gross/net sales, status (completed, voided, refunded), and timestamps.
* **Receipt reprint dialog**: Popup prompting for reprint reasons when printing a customer receipt multiple times.
* **Void & Refund Modal**: Interface to enter adjustments, choose return item quantities, specify payment routing, and enter supervisor credentials for overrides.
* **Prior-Period Adjustments Dashboard**: Displays ledger logs of transactions uploaded after a daily Z-report was already finalized, shifted into active open settlement periods.

---

## 4. Operational Instructions

### A. Searching and Filtering Sales
1. Navigate to **Sales → Transaction History**.
2. Enter an invoice number or select a Date Range.
3. Filter by **Branch** or **Terminal**.
4. Click **Apply Filter**.
5. Select any transaction row to view the line items, payment tender logs, and audit logs.

### B. Reprinting a Receipt
Reprinting receipts is monitored to prevent double-claiming tax invoices:
1. Open the transaction detail panel of the target sale.
2. Click **[Print Receipt]**.
3. If this is the *first* print, the invoice prints normally.
4. If this is a *reprint* (second or subsequent print):
5. A dialog will pop up requesting a **Reprint Reason** (e.g., "Customer lost original", "Receipt printer paper jam").
6. Enter the reason and click **[Confirm Reprint]**.
7. The system will log a `receipt_reprint` audit event, and the receipt will print with a visible **"DUPLICATE / REPRINT"** watermark along with the logged reason.

### C. Processing Voids & Refunds
Adjustments are restricted to transactions in a `paid` status and require supervisor validation:
1. Locate and open the transaction detail page.
2. Click **[Void Sale]** (for same-shift cancellations) or **[Refund Items]** (for item returns):
   - **Voiding a Sale**: Allowed only if the cashier shift is still **open**. If the shift is closed, voids are blocked and a Refund must be processed. Enter a Reason Code and Notes, and input supervisor credentials if required.
   - **Refunding Items**: Select the specific items and quantities returned, and check if the physical item was received (which flags stock as `pending_inspection` for back-office review).
3. **Card/E-wallet Refunds (Closed Shifts)**: If the payment was made via card or e-wallet and the shift is closed, the return defaults to routing to the **Manual Electronic Refund Approval Queue**. Initiating a Cash Payout instead is treated as an exception and requires supervisor credential overrides and an exception reason.
4. **Idempotency Guard**: All submissions contain a unique `Idempotency-Key` to block double-click network retries.

### D. Prior-Period Adjustments (Late Offline Syncs)
When a terminal goes offline and uploads sales *after* a manager has already approved and closed the daily Z-report, the system automatically shifts those sales to protect the closed records:
1. Navigate to **Financials → Prior-Period Adjustments**.
2. Review the list of adjusted transactions.
3. The system maps these sales to the current **Active Open Settlement Period** for accounting posting.
4. Review the stats card showing the *Original Date* vs. *Adjusted Posting Date* and the financial summaries (Adjusted VAT, Net Sales).

---

## 5. Expected Results

* Users can trace any sale to the exact cashier, branch, and terminal.
* Every reprint records an audit reason and prints watermarked duplicates.
* Voids and refunds are deducted separately from Gross Sales in X/Z-Reports (`Net Sales = Gross Sales - Voids - Refunds`).
* Closed Z-reports remain unmutated; late offline syncs are captured in an append-only prior-period adjustments log.
