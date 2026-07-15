# Epic 40 Inventory Operational Control and Reconciliation

## Status

Planning Draft

Date: 2026-07-15

## Objective

Epic 40 hardens inventory operations into a reliable operational-control platform for stock deduction, movement sequencing, unit conversion, variance evidence, stocktake reconciliation, and inventory reporting.

This epic does not recreate the existing inventory module. IPOS already has inventory visibility, stocktake workflows, unit conversions, branch deduction policy, variance logs, product recipes, and inventory reports. Epic 40 focuses on making those capabilities more deterministic, auditable, and safe to build on.

## Documentation Map

1. [Architecture Lock](epic-40-architecture-lock.md)
2. [Implementation Guide](epic-40-implementation-guide.md)
3. [Stories](stories/)
4. [Architecture Decision Records](adr/)
5. [Diagrams](diagrams/)

## Story Order

1. Story 40.1 Inventory Evidence and Movement Ledger Hardening
2. Story 40.2 Unit Conversion Governance
3. Story 40.3 Negative Stock Variance Lifecycle
4. Story 40.4 Recipe Deduction Snapshot Integrity
5. Story 40.5 Stocktake Reconciliation Integration
6. Story 40.6 Inventory Adjustment Authorization
7. Story 40.7 Inventory Reporting and Audit Evidence
8. Story 40.8 Pilot UAT and Operational Recovery

## Related Epics

1. Epic 31 Product Catalog and Inventory Admin UX.
2. Epic 37 Advanced Promotions and Bundling Engine.
3. Epic 38 F&B Table and Bill Operations.
4. Epic 39 Store Credit and Loyalty Ledger.

## Architecture Principles

1. Inventory movements are append-only operational evidence.
2. Movement rows preserve branch sequence, before quantity, signed delta, and after quantity.
3. Current stock is derived operational state and must reconcile to movement history.
4. POS checkout must never silently lose inventory consequences.
5. Stocktake posting is a controlled reconciliation event with movement-watermark evidence.
6. Unit conversion must be deterministic, tenant-scoped, versioned, and auditable.
7. Negative stock is policy-driven and must create variance evidence.
8. Stock cards, movement summaries, and variance reports are separate operational views.
9. Inventory is operational evidence, not accounting authority.

## Entry Point

New engineers should start here, then read the Architecture Lock before reading story specifications.
