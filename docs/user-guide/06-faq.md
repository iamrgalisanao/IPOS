# 06. Frequently Asked Questions (FAQ)

Status: Validated  
Last Updated: 2026-05-30  
System Area: General Operations  
User Roles: All Roles

---

## Q1: Why does a reprint receipt show "DUPLICATE" on the layout?
To satisfy compliance audits, receipts must uniquely identify original tax invoices. Printing a receipt multiple times automatically triggers a duplicate reprint flag. The terminal prints a visible watermark so duplicate receipts cannot be submitted as original tax claims.

---

## Q2: Can a cashier delete or modify a closed shift?
No. Once a cashier submits their count and the shift is approved, the shift record becomes immutable. Any modifications or corrections must be recorded as audit ledger adjustments by managers or accountants, preserving the historical transaction counts.

---

## Q3: What is the difference between a Cash Drop and a Bank Deposit?
* **Cash Drop**: A mid-shift transfer of cash from the register drawer to the store's physical safe/vault to reduce risk.
* **Bank Deposit**: The final daily cash reconciliation voucher submitted when a manager approves a cashier's shift, detailing the actual funds deposited into the company's bank account.

---

## Q4: How is the product's Weighted Average Cost (WAC) calculated?
WAC is updated automatically upon posting a Goods Received Voucher (GRV):
$$\text{New WAC} = \frac{(\text{Current On-Hand Qty} \times \text{Current WAC}) + (\text{Received Qty} \times \text{Purchase Cost})}{\text{Current On-Hand Qty} + \text{Received Qty}}$$
This maintains accurate inventory valuation based on real supplier intake costs.
