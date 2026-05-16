# IPOS - Epic Breakdown (Rebaselined)

## Overview
This document defines the implementable stories for IPOS, reconciled with the **Actual Validated Implementation History**.

**For the detailed execution audit, see [validated-implementation-roadmap.md](../../docs/roadmap/validated-implementation-roadmap.md).**

Implementation status in this file should follow the validated roadmap when planning-era assumptions conflict with current repository evidence.

Planning note: Epic 14 remains the next feature-delivery candidate in the backlog, but the next approved execution work is still the bounded release-readiness checklist until `G-009` and `R-008` are closed.

---

## Epic List

### Epic 1: SaaS Foundation & Fail-Closed Tenant Isolation [Closed]
### Epic 2: Identity, RBAC & Admin Configuration [Closed]
### Epic 3: Product Catalog & Branch Inventory Foundation [Closed*]
### Epic 4: POS Checkout, Zero-Loss Cart & Transaction Integrity [Closed]
### Epic 5: Payment Handling, Split Payments & Reference Guard [Closed]
### Epic 6: Inventory Deduction and Stock Integrity [Closed]
### Epic 7: Voids, Refunds & Controlled Reversals [Closed]
### Epic 8: Accounting Outbox, QuickBooks Adapter & Onboarding [Closed]
### Epic 9: Settlement and Reconciliation Foundation [Closed]
### Epic 10: Settlement Export and Reporting [Closed]
### Epic 11: Operational Pulse, Dashboards & Business Reporting [Closed]
### Epic 12: Shift, Cash Drawer & End-of-Day Operations [Closed]
### Epic 13: Support Assisted Mode & Production Hardening [Closed]
### Epic 14: BIR Compliance & PH Tax Reporting Scope Lock [In Progress]
### Epic 15: Sales & Transaction History Back Office [Planned]
### Epic 16: Inventory Stocktake & Stock Adjustment UI [Planned]
### Epic 17: Cashier Accountability & Shift Report Export [Planned]
### Epic 18: Customer & Loyalty Foundation [Planned]
### Epic 19: Promotions & Discounts Governance [Planned]
### Epic 20: Supplier & Purchase Receiving [Planned]
### Epic 21: Branch Comparison Business Intelligence [Planned]

*Epic 3 catalog core is closed; advanced stock UX/CDN remains deferred enhancement scope.*

---

## Epic 11: Operational Pulse, Dashboards & Business Reporting [Closed]

*Validated: May 2026*

### Story 11.1: Operational Pulse Scope Lock and Dashboard Query Foundation [Completed]
**Goal**: Define dashboard scope, source-of-truth rules, role visibility, and metric contract.

### Story 11.2: Dashboard Query Service Foundation [Completed]
**Goal**: Backend read-only metrics service reusing existing query logic.

### Story 11.3: Owner Tenant-Wide Pulse Dashboard [Completed]
**Goal**: Tenant-wide UI for owners/admins with high-fidelity cards.

### Story 11.4: Branch Manager Isolated Pulse Dashboard [Completed]
**Goal**: Branch-scoped UI for managers with strict isolation.

### Story 11.5: Responsive Mobile Dashboard Optimization [Completed]
**Goal**: Fluid layouts for tablet and mobile pulse visibility.

### Story 11.6: Latest Locked Settlement Evidence Card [Completed]
**Goal**: Visual confirmation of the most recent financial lock.

---

## Epic 12: Shift, Cash Drawer & End-of-Day Operations [Closed]

*Validated: May 2026*

### Story 12.1: Shift and Cash Drawer Scope Lock [Completed]
**Goal**: Define shift lifecycle, cash drawer rules, EOD summary boundaries, permissions, and source-of-truth behavior.

### Story 12.2: Shift and Drawer Data Foundation [Completed]
**Goal**: Create persistence foundation (`shifts`, `cash_drawer_events`, and `sale_payments.shift_id`) without enforcement.

### Story 12.3: Shift Open and Active Shift Enforcement [Completed]
**Goal**: Implement shift opening workflow and one-active-shift guard per cashier/branch.

### Story 12.4: Active Shift Checkout Guard and Payment-to-Shift Assignment [Completed]
**Goal**: Require active shift for POS checkout and attach recorded payments to the shift.

### Story 12.5: Cash Drawer Operational Events [Completed]
**Goal**: Implement controlled cash movements (`cash_drop`, `cash_top_up`, `cash_in`, `cash_out`) with active shift guards.

### Story 12.6: Blind Shift Closing and Variance Calculation [Completed]
**Goal**: Implement blind close workflow where cashier submits count before system calculates expected cash and variance.

### Story 12.7: Manager Review and Shift Approval [Completed]
**Goal**: Allow managers to review variance, add notes, and approve shift closure.

### Story 12.8: Shift-Settlement Lock Coupling [Completed]
**Goal**: Prevent shift actions for periods that are already accounting-locked.

---

## Epic 13: Support Assisted Mode & Production Hardening [Closed]

*Validated: 2026-05-13*

### Story 13.1: Support Assisted Mode Scope Lock and Identity Model [Completed]
**Goal**: Define and implement the controlled support-assisted session foundation, allowing authorized platform support users to enter a tenant-scoped, read-only, masked, audited support mode without bypassing normal tenant isolation.

### Story 13.2: Observability & Centralized Logging [Completed]
**Goal**: Add request correlation, structured operational logs, queue traceability, and provider-failure diagnostics without widening write-side behavior.

### Story 13.3: Production Security Hardening [Completed]
**Goal**: Close remaining production-readiness gaps across security posture, secret handling, deployment safety, and operational guardrails.

---

## Back-Office Functionalities Priority Queue

This queue represents the next planned feature epics after the current release-readiness blocker is cleared.

1. Epic 14: BIR Compliance & PH Tax Reporting Scope Lock
2. Epic 15: Sales & Transaction History Back Office
3. Epic 16: Inventory Stocktake & Stock Adjustment UI
4. Epic 17: Cashier Accountability & Shift Report Export
5. Epic 18: Customer & Loyalty Foundation
6. Epic 19: Promotions & Discounts Governance
7. Epic 20: Supplier & Purchase Receiving
8. Epic 21: Branch Comparison Business Intelligence

---

## Epic 14: BIR Compliance & PH Tax Reporting Scope Lock [Planned]

Scope review status: Epic 14 is still the logical next feature-delivery epic because the repository already has generic tax categories, persisted sale tax snapshots, settlement/export evidence, and role-gated reporting surfaces, but it does not yet have runtime PH compliance data hardening.

Planning status: Story 14.1 scope lock completed on 2026-05-13. Story 14.2 repository groundwork is complete through schema activation, model preparation, and non-persistent tax snapshot service preparation. Story 14.3 is complete as the read-only tax reporting query foundation. Story 14.4 is now in progress with a read-only back-office UI foundation, while export behavior remains unstarted. Execution should still remain bounded by the approved readiness gates.

### Story 14.1: BIR Compliance Scope Lock and PH Tax Matrix [Completed]
**Goal**: Freeze Phase 1 compliance scope for VAT, non-VAT, exempt, zero-rated, senior/PWD, discount treatment, and reporting boundaries so implementation does not drift into unsupported BIR certification claims.

### Story 14.2: Tax Breakdown Source-of-Truth Hardening [Completed]
**Goal**: Define and persist line-level and transaction-level tax components required for compliant back-office reporting, refund treatment, and immutable historical reconstruction.

### Story 14.3: Sales Tax Reporting Query Service [Completed]
**Goal**: Build read-only tax summary queries for daily, branch, and tenant views covering gross sales, VAT sales, exempt sales, zero-rated sales, discounts, and adjustments.

Execution note: Slice A read-only query foundation, Slice B adjustment/reversal/reviewed-period coverage, and Slice C contract hardening/closure completed on 2026-05-13. Story 14.3 is complete and remains bounded to the read-only query-service layer.

### Story 14.4: BIR Tax Reporting Back-Office UI [Completed]
**Goal**: Provide accountants and owners a filterable reporting surface for PH tax summaries, breakdown cards, and drill-down totals by branch and date range.

Execution note: Slice A read-only UI foundation, Slice B grouped breakdown presentation, and Slice C closure/access hardening checkpoint completed on 2026-05-13 using `view_reports` plus `view_multi_branch_dashboard` scope rules and `SalesTaxReportingQueryService` as the sole summary source of truth.

### Story 14.5: Compliance Export Package
**Goal**: Generate CSV/PDF exports for tax summaries and supporting schedules with explicit audit metadata, filter criteria, and redaction-safe headers.

### Story 14.6: Compliance Review and Lock Controls
**Goal**: Prevent silent mutation of tax-reporting periods once reviewed or locked, and surface clear warnings when reversals or reopened periods affect prior tax outputs.

## Epic 15: Sales & Transaction History Back Office [Planned]

### Story 15.1: Sales History Scope Lock and Access Rules
**Goal**: Define who can search, view, export, and drill into historical sales, voids, refunds, and payments across branch-scoped and tenant-wide roles.

### Story 15.2: Transaction History Query Foundation
**Goal**: Create paginated, filterable read models for sales history covering sale number, customer, cashier, branch, payment method, status, totals, and reversal state.

### Story 15.3: Sales & Transaction History Index UI
**Goal**: Deliver a back-office transaction list with fast filtering, status chips, saved scope context, and tenant-safe branch/date/payment filters.

### Story 15.4: Transaction Detail Timeline and Financial Breakdown
**Goal**: Provide a single transaction detail page with item lines, payment rows, tax/discount breakdown, reversal timeline, receipt metadata, and accounting sync visibility.

### Story 15.5: Transaction Export and Audit Trail
**Goal**: Support CSV/PDF export of filtered history and log export activity for sensitive financial review surfaces.

### Story 15.6: Receipt Reprint and Evidence Linking
**Goal**: Allow authorized back-office users to re-open receipt-ready views and cross-link transactions to refund, void, shift, and settlement evidence without altering source records.

## Epic 16: Inventory Stocktake & Stock Adjustment UI [Planned]

### Story 16.1: Stocktake and Adjustment Scope Lock
**Goal**: Define stocktake session lifecycle, adjustment categories, approval rules, counted-vs-book logic, and branch isolation for inventory correction workflows.

### Story 16.2: Stocktake Session Data Foundation
**Goal**: Persist stocktake sessions, counted lines, adjustment reasons, reviewer notes, and immutable posting references without changing live inventory until approval.

### Story 16.3: Stocktake Workspace UI
**Goal**: Build a branch-scoped counting workspace with searchable SKU list, counted quantity capture, progress states, and draft-save behavior for ongoing stock counts.

### Story 16.4: Variance Review and Adjustment Approval Flow
**Goal**: Provide supervisor review of stocktake variance, reason enforcement, and approval gates before inventory movements are posted.

### Story 16.5: Stock Adjustment Posting and Movement Linking
**Goal**: Convert approved stocktake variance or manual adjustment actions into immutable inventory movements tied to actor, reason, and stocktake evidence.

### Story 16.6: Stocktake History and Export
**Goal**: Expose completed stocktakes, adjustment history, and export-ready variance reports for audit and branch operations review.

## Epic 17: Cashier Accountability & Shift Report Export [Planned]

### Story 17.1: Cashier Accountability Scope Lock
**Goal**: Define official shift report contents, accountability metrics, exception rules, and export boundaries for cashier, manager, and owner audiences.

### Story 17.2: Shift Accountability Summary Query Service
**Goal**: Aggregate shift sales, payment mix, expected cash, actual cash, variance, voids, refunds, and drawer events into a read-only report contract.

### Story 17.3: Shift Report Review UI
**Goal**: Create a back-office shift report surface with cashier-level summaries, exception badges, variance notes, and branch/date filtering.

### Story 17.4: Cashier Accountability Drill-Down
**Goal**: Allow managers to inspect individual shift details, drawer events, sales totals, payment composition, and approval notes from one evidence page.

### Story 17.5: Shift Report Export Pack
**Goal**: Export Z-read style summaries, cashier accountability reports, and variance detail schedules in print-ready and CSV forms.

### Story 17.6: Export Audit and Lock Awareness
**Goal**: Record export events and make shift reporting aware of settlement locks and reopened periods so historical reports stay explainable.

## Epic 18: Customer & Loyalty Foundation [Planned]

### Story 18.1: Customer and Loyalty Scope Lock
**Goal**: Define minimum customer profile fields, sales linkage, privacy boundaries, and the initial loyalty model without expanding into CRM or marketing automation.

### Story 18.2: Customer Master and Consent Data Foundation
**Goal**: Create tenant-scoped customer records, contact fields, notes, and consent/status markers required for attach-to-sale and future loyalty use.

### Story 18.3: Customer Search and Transaction Linking
**Goal**: Support fast customer lookup, attach-to-sale workflows, and back-office transaction history by customer while maintaining optionality for anonymous checkout.

### Story 18.4: Loyalty Ledger Foundation
**Goal**: Introduce an append-only points ledger and loyalty settings model that records accrual, redemption, reversal, and manual adjustment events safely.

### Story 18.5: Customer Back-Office Profile UI
**Goal**: Deliver a customer list and profile view showing lifetime spend, visit history, recent transactions, loyalty balance, and audit-safe notes.

### Story 18.6: Loyalty Controls and Reporting
**Goal**: Provide admin settings for earn/redeem rules, manual loyalty adjustments with reasons, and basic export/reporting for loyalty balances and changes.

## Epic 19: Promotions & Discounts Governance [Planned]

### Story 19.1: Promotions and Discounts Governance Scope Lock
**Goal**: Define the discount policy model, allowed promotion types, combinability rules, approval paths, and guardrails between manual discounts and configured promos.

### Story 19.2: Discount Policy and Promotion Data Model
**Goal**: Persist promotion rules, schedule windows, branch scope, eligibility conditions, approval status, and deactivation history.

### Story 19.3: Back-Office Promotions Management UI
**Goal**: Build an admin surface to create, edit, activate, pause, and review promotion and discount policies with branch-aware visibility.

### Story 19.4: POS Discount Governance Enforcement
**Goal**: Enforce approval-required discount paths, stacking rules, reason capture, and policy validation during checkout without breaking cashier speed.

### Story 19.5: Promotion Effectivity and Audit Reporting
**Goal**: Expose promotion usage, discount totals, override events, and rejected discount attempts for owner and accountant review.

### Story 19.6: Discount Exception Review Workflow
**Goal**: Provide managers an exception queue for high-risk or policy-breaching discount activity with full decision traceability.

## Epic 20: Supplier & Purchase Receiving [Planned]

### Story 20.1: Supplier and Receiving Scope Lock
**Goal**: Define supplier master data, purchase receiving boundaries, costing assumptions, branch receiving rules, and the distinction between receiving and full procurement.

### Story 20.2: Supplier Master and Receiving Data Foundation
**Goal**: Persist suppliers, receiving documents, received lines, invoice references, cost fields, and receiving status lifecycle.

### Story 20.3: Supplier Management Back-Office UI
**Goal**: Deliver supplier creation and maintenance screens with status, branch visibility, contact records, and audit-safe notes.

### Story 20.4: Purchase Receiving Workspace
**Goal**: Build a receiving UI where branch operators can record delivered quantities, shortages, overages, invoice references, and receiving notes.

### Story 20.5: Receiving-to-Inventory Posting
**Goal**: Post approved receiving quantities into branch inventory as immutable inbound movements linked to supplier and receiving evidence.

### Story 20.6: Supplier History and Receiving Exports
**Goal**: Provide supplier transaction history, receiving register views, and exportable receiving summaries for finance and branch operations.

## Epic 21: Branch Comparison Business Intelligence [Planned]

### Story 21.1: Branch Comparison BI Scope Lock
**Goal**: Define the owner-level comparison metrics, time windows, ranking logic, and drill-down boundaries for tenant-wide branch intelligence.

### Story 21.2: Branch Comparison Query Layer
**Goal**: Build read-only rollups for branch sales, transaction count, payment mix, refund rate, void rate, low-stock exposure, shift variance, and sync health.

### Story 21.3: Branch Comparison Dashboard UI
**Goal**: Deliver a tenant-wide comparison view with sortable leaderboards, KPI cards, branch ranking, and exception surfaces tuned for owners.

### Story 21.4: Comparative Trend and Variance Views
**Goal**: Add time-window comparison, previous-period deltas, and branch-vs-branch variance views that help owners identify underperforming locations quickly.

### Story 21.5: Drill-Down from Comparison to Operational Evidence
**Goal**: Link comparison insights to existing dashboards, transaction history, stock alerts, shift reports, and settlement evidence without duplicating source logic.

### Story 21.6: BI Export and Snapshot Sharing
**Goal**: Support exportable comparison summaries and owner-ready snapshot sharing for branch review meetings and periodic business check-ins.

## Epic 22: Visual POS Layout Builder & Enterprise Sync [Planned]

### Story 22.1: POS Layout Customization Scope Lock
**Goal**: Define the grid schema, dimensions, branch deployment strategy, multi-device sync behavior, and permission model for visual layout customization.

### Story 22.2: POS Layout Data Foundation
**Goal**: Create the `pos_layouts` table to store serialized grid configurations and establish a many-to-many relationship with `Branch` to support global and targeted deployments.

### Story 22.3: Admin Layout Sandbox Mode
**Goal**: Implement an admin-only "Layout Designer" mode within the POS web interface, enabling drag-and-drop tile positioning, resizing, and category assignment.

### Story 22.4: Layout Deployment and Sync Engine
**Goal**: Build the deployment API to push a saved layout to selected branches, ensuring all physical POS terminals pull and render the active layout schema.

### Story 22.5: Terminal Grid Renderer
**Goal**: Refactor the POS frontend to parse the assigned layout schema, dynamically generating the grid UI rather than relying solely on automated alphabetical sorting.
