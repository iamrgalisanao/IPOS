# Product Catalog & Unit Conversions User Guide

Status: Validated  
Last Updated: 2026-05-30  
System Area: Back Office → Catalog  
User Roles: Owner/Admin, Branch Manager (view only)

---

## 1. Purpose

The Product Catalog controls the master list of items sold across branches. It defines standard item names, pricing tiers, barcodes, categories, and dynamic unit conversion rules that allow the system to deduct correct inventory fractions (e.g. converting a sold "Cup of Coffee" into grams of coffee beans).

---

## 2. Who Can Use This Feature

* **Owner / Admin**: Full permissions to create, update, delete, and manage catalog configurations.
* **Branch Manager**: Read-only view of products, prices, and conversions. Blocked from write mutations.

---

## 3. Access Path

Go to:
* **Main Menu → Catalog → Products**
* **Main Menu → Catalog → Unit Conversions**

---

## 4. Operational Instructions

### A. Creating a New Product
1. Navigate to **Catalog → Products** and click **[New Product]**.
2. Enter the product name, unique SKU, selling price, and category.
3. Toggle **Track Inventory** to enable/disable stock deduction audits.
4. Click **[Save Product]**.

### B. Configuring Dynamic Unit Conversions
Dynamic unit conversions allow high-precision fractional ingredient deductions:
1. Navigate to **Catalog → Unit Conversions**.
2. Click **[Add Unit Conversion]**.
3. Define the **From Unit** (e.g., `Kilogram`), the **To Unit** (e.g., `Gram`), and the **Multiplier Factor** (e.g., `1000`).
4. Apply the conversion to specific ingredients or products.
5. Save the rule. When a sale occurs, the system utilizes this conversion factor to accurately deduct inventory levels.

### C. Recipe & BOM Management (Story 35.x)
Products can be configured as composite items by defining a Bill of Materials (BOM) or recipe:
1. Open the Edit Product page for a composite item.
2. Navigate to the **Recipe / BOM** section.
3. Search and add ingredients (e.g. raw materials, semi-finished goods).
4. Set the exact quantity and unit of measure for each ingredient that makes up the composite product.
5. The **WAC Recipe Cost Estimator** will calculate the real-time estimated ingredient cost by checking the Weighted Average Cost (WAC) from the chosen branch. It highlights missing costs or missing unit conversions to ensure accurate margin calculations.

---

## 5. Expected Results

* New products appear on POS grids immediately after save and cache-flush.
* System automatically translates inventory unit increments during sales deduction, preventing stock mismatch.
* Real-time recipe costs reflect the actual inventory value of ingredients using WAC.
