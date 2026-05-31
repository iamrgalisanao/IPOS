# Inventory Stocktake & Adjustments User Guide

Status: Validated  
Last Updated: 2026-05-30  
System Area: Back Office → Inventory / Procurement  
User Roles: Owner/Admin, Branch Manager

---

## 1. Purpose

This module handles the physical inventory lifecycle: capturing item batches and expiration dates upon supplier intake, performing periodic stocktake counts, and adjusting stock levels to reflect actual physical inventories.

---

## 2. Access Path

Go to:
* **Main Menu → Procurement → Goods Receiving (GRVs)**
* **Main Menu → Inventory → Stocktakes**

---

## 3. Screen Overview

* **Goods Receiving Workspace**: Workspace to intake POs, capture batch lots, and input expiry details.
* **Active Stocktake Board**: Shows counting sessions, physical count entry cells, and real-time calculated system variances.
* **Variance Approval Panel**: Summarizes discrepancies and prompts for reason codes before posting adjustments.

---

## 4. Operational Instructions

### A. Goods Intake and Expiry Lot Capture
Perishable items require strict lot and expiry mapping before they can be sold:
1. Go to **Procurement → Goods Receiving** and click **[New GRV]**.
2. Select the matching Purchase Order and Supplier.
3. For each received product, enter the **Quantity**, **Lot Number**, and **Expiration Date**.
   > [!WARNING]
   > Gated Control: You will be blocked from posting the receiving voucher if any perishable item is missing a Lot Number or Expiry Date.
4. Click **[Post GRV]**. The items will be committed to inventory and made available for POS FEFO (First-Expired, First-Out) sales immediately.

### B. Performing a Physical Stocktake
Reconcile actual physical shelf stock with system records:
1. Go to **Inventory → Stocktakes** and click **[Initialize Stocktake]**.
2. Select target categories (or choose *Full Store Count*).
3. Assign staff to perform the physical count and type the counted quantities into the **Count Entry Grid**.
4. Review the **Variance Summary**:
   * **Variance = Counted Qty - Expected Qty**.
5. For any items showing a variance, select the appropriate **Adjustment Reason Code** (e.g. *Spoilage*, *Damaged Stock*, *Pilferage*).
6. Click **[Post Stocktake]**.

---

## 5. Expected Results

* Stock levels update instantly upon GRV posting using Weighted Average Cost (WAC) calculations.
* Inventory adjustments create immutable stock movement logs and synchronize physical count sheets to Back Office ledgers.
