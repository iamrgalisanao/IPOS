# Inventory Operational Control User Guide

Status: Epic 40 Pilot Ready
Last Updated: 2026-07-16
System Area: Back Office -> Inventory
User Roles: Owner/Admin, Branch Manager, Inventory Controller, Auditor/Admin, Support

## 1. Purpose

The inventory module explains branch stock through canonical evidence:

1. current operational stock,
2. append-only inventory movements,
3. unit conversion snapshots,
4. recipe deduction snapshots,
5. variance and exception records,
6. stocktake reconciliation evidence,
7. governed manual adjustments,
8. read-only operational and audit reports.

Inventory does not own sale creation, payment settlement, refund authority, product pricing, tax, receipt compliance, procurement approval, or accounting.

## 2. Access Path

Open:

```text
Main Menu -> Inventory -> Inventory Hub
```

The Inventory Hub groups links for:

1. Inventory Overview.
2. Stocktake Operations.
3. Reports and Audit.
4. Catalog and Recipe Setup.
5. Inbound and Procurement links where enabled.

Available links depend on role permissions and branch assignment.

## 3. Key Concepts

### Current Stock

`branch_inventories.current_stock` is the current operational stock value. It must be explainable by movement and reconciliation evidence.

### Stock Card

The Stock Card is the branch/product movement ledger. It shows sequence-ordered before, change, and after quantities.

### Movement Summary

Movement Summary explains business-date activity using captured ledger watermarks. It is not a replacement for the Stock Card.

### Unit Conversion

Inventory deductions use deterministic unit conversion rules. Historical movement snapshots preserve the conversion used at the time of deduction.

### Recipe Deduction

Composite products deduct ingredient quantities through recipe snapshots. Later recipe edits do not change historical movement evidence.

### Variance Records

Variance categories are distinct:

1. negative-stock exception,
2. physical count variance,
3. system reconciliation exception,
4. configuration gap.

Variance records do not directly change stock.

## 4. Common Workflows

### A. Validate Current Stock

1. Open Inventory Hub.
2. Open Current Stock.
3. Select the branch.
4. Review current quantity, inventory revision, and latest movement sequence.
5. If the value looks wrong, open Stock Card for the same branch/product.
6. Compare current stock with movement-derived evidence.

### B. Investigate a Product

1. Open Stock Card.
2. Select branch and product.
3. Review movement sequence, source reference, before/change/after quantities, and movement category.
4. Use the source reference to trace back to sale, refund, void, stocktake, or adjustment evidence.

### C. Review Recipe Ingredient Deductions

1. Open Product Composition.
2. Select parent product and branch where applicable.
3. Confirm ingredient coverage and recipe quantities.
4. Open Stock Card for the ingredient.
5. Verify the movement includes parent product, sale item, recipe snapshot, and conversion snapshot evidence.

### D. Review Negative Stock Exceptions

1. Open Negative Stock Exceptions.
2. Filter by branch, product, status, or date.
3. Review incremental shortage, resulting negative quantity, severity, age, and correction links.
4. Use governed receiving, stocktake, adjustment, refund, void, or approved source workflows to resolve the underlying issue.
5. Do not mark an exception resolved without evidence.

### E. Perform Stocktake Reconciliation

1. Open Stocktake Sessions.
2. Create or continue a stocktake using approved branch procedure.
3. Capture count-start watermark.
4. Record physical counts.
5. Review expected-at-count and expected-at-posting values.
6. Post only when authorized.
7. Review Physical Count Variance and Stock Card correction movement after posting.

Posted stocktake lines must not be silently edited.

### F. Request or Approve Manual Adjustment

1. Choose a structured reason.
2. Confirm reason direction matches the quantity sign.
3. Enter notes when configured.
4. Submit for approval when threshold or policy requires it.
5. Confirm denied requests create no movement.
6. Confirm approved requests create append-only movement evidence.

Opening balance is allowed only before prior committed branch/product movements exist.

## 5. Reports and Evidence

Use these reports during UAT, support, and daily operations:

| Report | Purpose |
| --- | --- |
| Current Stock | Current operational stock with revision and watermark metadata |
| Stock Card | Branch/product movement ledger |
| Movement Summary | Business-date movement activity |
| Negative Stock Exceptions | Soft-negative exception lifecycle evidence |
| Physical Count Variance | Stocktake variance evidence |
| Reconciliation Exceptions | Current stock versus movement-derived stock issues |
| Usage Reconciliation | Expected versus recorded usage foundation |
| Configuration and Integrity | Setup gaps and evidence-chain integrity checks |

Reports are read-only. Exports must preserve filters, branch permissions, numeric negatives, and audit-export restrictions.

## 6. Offline Boundary

Offline inventory mutation is prohibited.

If terminal policy allows an offline cash sale, the terminal may queue the sale under the broader POS offline design. Inventory is not authoritative locally. When the sale synchronizes, the server-authoritative flow creates the canonical movement exactly once.

Card, e-wallet, void, refund, adjustment, and stocktake mutation remain blocked offline according to approved policy.

## 7. Recovery Rules

Routine inventory discrepancies must use governed workflows:

1. stocktake,
2. governed adjustment,
3. receiving,
4. refund,
5. void,
6. approved source workflow.

Do not use:

1. direct SQL update of `current_stock`,
2. movement deletion or editing,
3. opening balance reset over historical evidence,
4. generic adjustment to imitate receiving or refund,
5. posted stocktake line edits,
6. another user's approval,
7. current recipe or conversion changes to reinterpret history.

Database restore is reserved for platform-level disaster recovery and is not an item-level inventory correction method.

## 8. Pilot and Hypercare

For Epic 40 pilot branches:

1. Complete pilot entry criteria before UAT.
2. Record scenario evidence in the evidence manifest.
3. Use Severity 1 through Severity 4 defect classification.
4. Block rollout for unresolved Severity 1 or Severity 2 defects.
5. Run daily hypercare checks during the observation window.
6. Classify outcome as `ready_for_rollout`, `extend_pilot`, `conditional_rollout`, or `pilot_failed`.

Hypercare checks include current stock versus movement-derived stock, duplicate source-effect detection, open negative-stock exceptions, movement-chain discontinuities, stocktake/adjustment failures, configuration gaps, report/export errors, support tickets, and inventory mutation latency.
