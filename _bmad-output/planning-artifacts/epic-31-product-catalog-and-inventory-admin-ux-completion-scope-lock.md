# Epic 31 Scope Lock - Product Catalog and Inventory Admin UX Completion

Status: Approved Next Priority / Planning
Date: 2026-05-21
Governance Ref: G-068

---

## 1. Objective

Complete Back Office admin workflows for product catalog management, branch inventory visibility, product setup hardening, and operational inventory controls.

This epic is a usability and operational completeness track over already established product/catalog/inventory foundations.

---

## 2. Why This Next

Recent execution closed System Admin provisioning, compliance detail visibility, dashboarding, and advisory urgency (Epic 29 + Epic 30).

The highest practical product gap is now day-to-day admin usability in catalog/inventory management surfaces, especially where earlier work was backend-first or planning-heavy.

---

## 3. Candidate Stories

- 31.1 Product Catalog Admin UX Review and Gap Lock
- 31.2 Product Create/Edit UX Hardening
- 31.3 Branch Pricing and Availability Management UI
- 31.4 Inventory Overview and Stock Visibility Dashboard
- 31.5 Recipe / Ingredient Admin Management UI
- 31.6 Catalog Import/Export and Audit Hardening

---

## 4. Scope Boundaries

Included:
- Back Office admin UX surfaces for product and inventory operations.
- Read/write workflow hardening for product setup and branch-level controls.
- Visibility dashboards and exports that support operations and auditability.

Excluded unless separately approved:
- POS runtime behavior changes and checkout flow redesign.
- Billing/subscription engine changes.
- Offline sync/posting engine changes.
- Tax, receipt, GCT, Z-read, and e-journal engine changes.
- Persona enforcement schema redesign or cross-tenant privilege model changes.

---

## 5. Initial Sequencing Recommendation

1. Story 31.1 - lock UX gap inventory, route map, and acceptance boundaries.
2. Story 31.2 - harden product create/edit flows and validation clarity.
3. Story 31.3 - complete branch pricing and availability controls.
4. Story 31.4 - add inventory overview and stock visibility dashboard slices.
5. Story 31.5 - complete recipe and ingredient admin management surfaces.
6. Story 31.6 - finalize import/export pathways with audit hardening.

---

## 6. Acceptance Gate for Epic Start

Epic 31 may proceed when Story 31.1 delivers:
- an inventory of current admin surfaces and UX gaps,
- a route/component ownership map,
- explicit in-scope and out-of-scope acceptance boundaries,
- test strategy covering role checks, tenant/branch isolation, and audit expectations.

No implementation beyond planning is approved by this scope lock alone.
