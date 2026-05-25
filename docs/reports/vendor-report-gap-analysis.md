# Vendor Report Gap Analysis

Status: First-pass competitive research memo pending evidence hardening

## Objective

Compare how report and inventory-ingredient workflows are presented by UTAK, Mosaic, iRipple, and ANSI, then map those patterns against the current IPOS report implementation.

This document should be used as directional product research, not final roadmap evidence, until the evidence table is fully completed and source confidence is reviewed.

## Revised Assessment

The current conclusion is directionally valid:

**IPOS appears stronger in compliance, auditability, traceability, and accounting confidence, while competitors such as UTAK and Mosaic show stronger public evidence around practical inventory operations and F&B inventory workflows.**

However, the evidence depth is uneven:

1. UTAK has concrete public guide evidence for inventory workflows such as ingredients-based monitoring, retail inventory monitoring, stock deduction, adding inventory items, linking inventory items, and ingredient encoding/linking guidance.
2. Mosaic has strong official evidence for recipe input, inventory auto-deduction, food cost monitoring, variance reporting, stock cards, inventory movement reports, real-time stock reports, purchasing, inventory counts, and recipe/purchasing unit conversions.
3. ANSI's official public evidence supports POS, real-time inventory tracking, forecasting/purchasing, financial reporting, SAP Business One positioning, and automated inventory subtraction, but not enough to confidently claim recipe-composition capability unless another direct source is found.
4. iRipple references remain lower-confidence in the current research set and should be hardened before being used as roadmap evidence.

## Research Sources

- UTAK POS Guides inventory page: https://utakpos.wixsite.com/utakposguides/inventory
- UTAK POS Guides tablet inventory page: https://utakpos.wixsite.com/utakposguides/inventory-2
- Mosaic Resto iQ Inventory and Purchasing support page: https://support.mosaic-solutions.com/knowledge/resto-iq-inventory-and-purchasing
- Mosaic Inventory Management feature page: https://www.mosaic-solutions.com/features/inventory-management/
- ANSI WinVQP POS retail page: https://ansi.ph/product-item/winvqp-pos-for-retail-retail-management-system/
- Current IPOS report routes, controllers, services, tests, and Inertia pages

## Evidence Table

| Vendor | Observed Capability | Source | Confidence | IPOS Equivalent | Gap | Priority |
| --- | --- | --- | --- | --- | --- | --- |
| UTAK | Back-office inventory guides: ingredients-based monitoring, retail monitoring, adding inventory items, stock deduction, reset, and link inventory items | Official UTAK guide | High | Inventory module and stocktake foundation | IPOS needs more practical workflow screens and guide-like UX | Pilot-critical |
| UTAK | Tablet/back-office operational inventory actions: create ingredients, staff inventory input, print inventory, and show retail stocks | Official UTAK guide | High | Stock adjustment, inventory movement, stocktake | IPOS should expose easier operational inventory flows | Pilot-critical |
| Mosaic | Recipe input and inventory auto-deduction as items are sold | Official Mosaic inventory page | High | Product composition and inventory deduction logic | Need clearer recipe maintenance workspace and monitoring UX | Competitive parity |
| Mosaic | Food cost, variance, par-level, physical count, shrinkage, and audit support | Official Mosaic inventory page and support docs | High | Audit logs, inventory reports, variance concepts | IPOS can differentiate by tying variance to audit/compliance explanations | Differentiator |
| Mosaic | Resto iQ inventory docs: stock cards, inventory variance, food cost, movements, real-time stock, expiry/aging, purchase requests/orders, UOM conversions | Official Mosaic support center | High | Reporting, procurement, and inventory modules | IPOS needs unified inventory/reporting hub | Pilot-critical |
| ANSI | Retail POS with real-time inventory tracking, forecasting/purchasing, financial reporting, SAP Business One positioning | Official ANSI pages | Medium-High | Compliance, audit, and accounting confidence | Good enterprise/compliance comparison, but recipe claims should be avoided | Enterprise positioning |
| ANSI | Recipe/composition capability | Not directly found in current public evidence | Low | Product composition report | Do not claim unless backed by direct ANSI source | Do not prioritize as evidence |
| iRipple | Recipe-driven inventory depletion and stock monitoring | Public snippets only in current memo | Low-Medium | Product recipes, deduction engine, composition report | Needs official source hardening before roadmap use | Evidence hardening |

## Vendor Findings

### UTAK

- Public guides focus on operational inventory tasks.
- The inventory area includes ingredients-based monitoring, retail monitoring, adding inventory items, stock deduction, resetting inventory, and linking inventory items.
- Tablet-facing guides include creating ingredients, staff inventory input, printing inventory, and showing retail stocks.
- The workflow is user-facing and practical rather than analytics-heavy.

### Mosaic

- Public Mosaic pages strongly position inventory around F&B cost control.
- Official feature material references recipe input, automatic inventory deduction as items are sold, real-time food cost monitoring, variance/par-level reporting, physical counts, shrinkage, and inventory valuation.
- Resto iQ support documentation exposes a broad operational inventory surface: stock cards, variance and food cost reports, movement reports, real-time stock reports, expiry/aging, adjustments, products, UOM setup, actual inventory counts, transfers, purchase requests, and purchase orders.

### iRipple

- Public snippets describe inventory ingredients as recipes tied to menu items.
- The apparent pattern is stock consumption through recipe definition and stock monitoring.
- Current evidence is not strong enough for final competitive claims; official source hardening is needed.

### ANSI

- Official public material emphasizes POS, inventory tracking, forecasting and purchasing, financial reporting, SAP Business One positioning, and automated inventory subtraction with every purchase.
- ANSI is useful as an enterprise/accounting/compliance comparison.
- Public evidence for a dedicated recipe or ingredient composition report was not found in the current research pass.

## Current IPOS Report Implementation

### Already Implemented

- Tax reporting and e-journal exports.
- Cashier accountability and shift reporting.
- Stocktake summaries and variance CSV export.
- Inventory variance logs.
- Product ingredient composition report with:
  - branch filtering
  - category filtering
  - direct vs flattened sub-recipe modes
  - branch stock and cost context
  - cost masking for non-auditors
  - CSV export with formula-injection protection
  - export row ceiling
  - shared unit conversion resolver

### Current Strengths

- Strong compliance and audit orientation.
- Branch-aware access control.
- Server-authoritative reporting.
- Safe export handling.
- Useful recipe composition visibility for planning.
- Better accounting confidence story than most operational-only inventory surfaces.

## Gap Analysis

### Gaps Versus UTAK

- IPOS does not yet present a single operational inventory hub.
- IPOS lacks tablet-style inventory guidance and print-oriented workflow pages.
- IPOS is more report-centric than staff-execution-centric.
- Stocktake documentation exists, but screenshots and branch-ready training assets still need to be completed.

### Gaps Versus Mosaic

- IPOS has recipe and composition logic, but does not yet present recipe setup and monitoring as a polished operational workspace.
- IPOS composition reporting is read-only and does not function like a recipe editor.
- IPOS does not yet provide the same visible ingredient/unit setup guidance.
- IPOS should make food-cost, variance, and par-level views easier to discover from a unified inventory reporting area.

### Gaps Versus iRipple

- IPOS lacks a simplified operational view that ties recipes to live stock depletion visibility.
- IPOS does not yet provide a simplified ingredient-to-menu-item monitoring surface.
- Evidence should be hardened before iRipple becomes a major roadmap benchmark.

### Gaps Versus ANSI

- IPOS does not yet offer a broad analytics-style inventory command center.
- IPOS lacks replenishment-oriented report views and low-stock prioritization.
- IPOS does not yet unify inventory reports with business analytics in one entry point.
- ANSI recipe-composition claims should be avoided unless direct evidence is found.

## Revised Roadmap Buckets

### 1. Pilot-Critical

These matter most for early buyer confidence and daily usability:

- Unified inventory and reporting hub.
- Print-friendly stocktake and inventory report views.
- Stocktake guide screenshots and guided workflow.
- Low-stock and reorder dashboard.
- Branch stock movement summary.
- Simple actual-count vs system-count variance view.

Reason: UTAK and Mosaic publicly show practical workflows that users can understand quickly. IPOS should not only have strong backend correctness; it should make inventory work visibly easy.

### 2. Competitive Parity

These help IPOS compete more directly with F&B and retail POS expectations:

- Ingredient setup workflow.
- Recipe maintenance workspace.
- Ingredient-to-menu linking.
- Recipe-based stock deduction visibility.
- Ingredient-to-menu monitoring report.
- Inventory movement and adjustment summaries.
- Branch-level stock transfer reporting.

Important wording:

Avoid saying **"ingredient and recipe maintenance report."**

Use:

- **"Ingredient setup workflow"**
- **"Recipe maintenance workspace"**
- **"Recipe composition report"**
- **"Ingredient-to-menu monitoring report"**

Maintenance is an editing/workflow function. Reporting is separate.

### 3. Differentiators

These should become IPOS's stronger positioning:

- Composition planning report.
- Stocktake vs composition variance reconciliation.
- Scheduled manager/auditor report packs.
- Compliance-aware report explanations.
- Audit trail explanations for stock movement, voids, refunds, tax treatment, and report changes.
- BIR/accounting confidence layer.

This is where IPOS can stand apart. Competitors may show operational inventory strength, but IPOS can position itself as:

> A POS and inventory platform built for operational control, accounting confidence, and audit-ready reporting.

## Market Implication

For **early pilots**, the most important gap is not advanced recipe logic. It is usability: inventory users need a clear hub, print-ready views, guided stocktake flow, low-stock alerts, and simple branch movement visibility.

For **F&B competitiveness**, IPOS needs recipe maintenance, ingredient linking, recipe depletion monitoring, and food-cost/variance support because Mosaic publicly positions around recipe input, auto-deduction, food cost, variance, and par-level reporting.

For **enterprise and compliance buyers**, IPOS should lead with auditability, scheduled reports, traceable sales/inventory/accounting movements, and compliance-aware explanations. ANSI's public positioning around SAP Business One, inventory, accounting, and financial reporting shows that enterprise buyers value integrated accounting and operational visibility.

## Revised Bottom Line

Keep this document as a **research memo** until the evidence table is completed and source confidence is reviewed.

The strongest current conclusion is:

**IPOS should market compliance, auditability, and accounting confidence now, while closing the operational inventory UX gap through a unified inventory hub, print-friendly stocktake/reporting views, low-stock dashboards, ingredient setup workflows, and recipe maintenance polish.**

That gives IPOS a better competitive path:

1. First: look usable and operationally complete.
2. Second: match expected F&B inventory workflows.
3. Third: differentiate through audit-grade, compliance-aware reporting.
