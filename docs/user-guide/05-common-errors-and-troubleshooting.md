# 05. Common Errors and Troubleshooting

Status: Validated  
Last Updated: 2026-05-30  
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

## 3. Inventory and Procurement

### "Tolerance Limit Exceeded"
* **Cause**: The unit cost or quantity listed on the supplier invoice differs from the original Purchase Order (PO) and Goods Received Voucher (GRV) by more than the allowed percentage (e.g., unit cost drifted by 3%).
* **Action**: Audit the values. Reject the invoice and request a corrected debit note from the supplier, or have a System Admin override the check to post the liability.

### "Lot Number and Expiry Date are required for perishable items"
* **Cause**: You are attempting to post a Goods Received Voucher (GRV), but one or more perishable items do not have batch lot numbers or expiration dates recorded.
* **Action**: Enter the batch info from the physical packaging before posting the GRV.

---

## 4. Integrations & Sync Diagnostics

### "QuickBooks Sync Error: Missing Account Mapping"
* **Cause**: A POS payment type or branch tax category is not mapped to any chart of accounts code inside QuickBooks Online.
* **Action**: An accountant must go to **Integrations → Account Mapping**, assign the account codes, and click **[Retry Sync]**.
