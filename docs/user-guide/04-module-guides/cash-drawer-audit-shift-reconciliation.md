# Cash Drawer Audit & Shift Reconciliation User Guide

Status: Validated  
Last Updated: 2026-05-30  
System Area: POS Terminal & Back Office → Store Operations  
User Roles: Owner/Admin, Branch Manager, Cashier

---

## 1. Purpose

This module establishes daily cash drawer accountability. It governs shift open/close floats, implements surprise manager spot audits, limits risk exposure through threshold-based cash drops, and reconciliation checkouts with immutable bank deposit records.

---

## 2. Who Can Use This Feature

* **Cashier**: Opens shifts, inputs floats, initiates cash drops, and submits closing counts.
* **Branch Manager**: Re-verifies credentials to authorize spot audits and high-value cash drops; reconciles and approves daily shift deposits.
* **Owner / Admin**: Audits all historical shift entries, spot audits, and deposit vouchers.

---

## 3. Operational Instructions

### A. Starting Your Day: Shift Opening Float
Before selling at the POS terminal:
1. Log in to the terminal.
2. The **Shift Opening** modal will prompt you to enter the count of physical bills and coins (Denominations).
3. The system automatically sums the denominations to calculate the **Opening float**.
4. Click **[Confirm & Open Shift]**.

### B. Performing a Surprise Spot Audit
Store managers can execute surprise drawer audits during active shifts:
1. Navigate to the POS drawer status screen.
2. Select **[Perform Spot Audit]**.
3. The supervisor must enter their **Manager Email** and **Manager Password**.
   * *Requirement*: The manager must belong to the active tenant and hold the `approve_shift` or `manage_cash_drawer` permission.
4. Input the physical cash denominations in the drawer.
5. The system calculates the expected drawer cash (physical cash sales + opening float - cash drops) and computes any **Overage / Shortage variance**.
6. Enter notes and click **[Submit Spot Audit]**. The audit record is immutable and cannot be edited or deleted.

### C. Cash Drops & Drawer Threshold Warnings
To reduce risk, IPOS warns cashiers when drawer cash exceeds business thresholds (Branch Limit → Tenant Default → 5000.00 fallback):
1. When expected cash in the drawer exceeds the threshold:
   * The POS display flashes a **"Limit Exceeded"** warning, recommending a cash drop.
2. To perform a cash drop:
   * Select **[Record Cash Event]** and choose **Cash Drop**.
   * Enter the drop amount.
3. **Threshold Gate**:
   * If the drop amount is *below* the threshold, the cashier posts it normally.
   * If the drop amount is *above* the threshold (high-value drop):
     * A supervisor authorization block displays.
     * A manager must enter their **Email** and **Password** to approve the drop.
     * **Self-Approval Guard**: The manager cannot be the owner of the active shift (prevents self-approving high-value vault drops).
4. Click **[Confirm Drop]**. The amount is subtracted from the expected drawer cash immediately.

### D. Reconciling Shifts & Creating Deposit Vouchers
When the cashier submits their closing count, the shift goes to `closing_submitted` status for manager review:
1. Navigate to Back Office **Store Operations → Shift Audits** and open the pending shift.
2. Review the shift statistics card. A **"Threshold Exceeded"** badge will display if the limit was breached during the shift.
3. Click **[Approve Shift]**.
4. The Shift Approval modal displays, prompting for final bank deposit details:
   * **Actual Bank Deposit Amount** <span className="text-red-500">*</span>
   * **Destination Bank Name** (e.g. BDO, BPI)
   * **Bank Reference / Slip Number**
   * **Deposit Date**
   * **Variance Explanation** (Required if expected vs. counted cash shows a variance)
   * **Manager Review Notes**
5. Click **[Approve Shift]**.
6. The system creates an immutable **Bank Deposit Voucher** inside the transaction. Once saved, this deposit voucher displays in the Back Office and cannot be modified or deleted.

---

## 4. Expected Results

* Cash drawer limits are enforced dynamically per branch context.
* Managers cannot self-approve high-value cash drops.
* Approving a shift creates exactly one immutable bank deposit voucher (idempotency guard prevents duplicate records).
