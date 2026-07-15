# Epic 39 Store Credit and Loyalty Ledger

## Status

Store Credit and Loyalty Runtime Complete

Date: 2026-07-15

## Objective

Epic 39 introduces customer financial accounts, append-only store credit, and loyalty point ledgers for IPOS.

The first implementation priority was store credit because it represents monetary value and touches refunds, payments, cashier controls, accounting liability, and customer balances. Loyalty points are now implemented as a separate non-monetary runtime using append-only loyalty ledger rows.

Current closeout status: store-credit runtime flows, loyalty ledger storage, loyalty accrual, loyalty redemption evidence, and loyalty reporting are implemented. Automatic loyalty reversal for void/refund remains deferred to a separate policy-driven follow-up.

## Documentation Map

1. [Architecture Lock](epic-39-architecture-lock.md)
2. [Implementation Guide](epic-39-implementation-guide.md)
3. [Stories](stories/)
4. [Architecture Decision Records](adr/)
5. [Diagrams](diagrams/)

## Story Order

1. Story 39.1 Customer Account Foundation
2. Story 39.2 Store Credit Ledger
3. Story 39.3 Store Credit Refund Issuance
4. Story 39.4 Store Credit Redemption
5. Story 39.5 Store Credit Admin Review
6. Story 39.6 Loyalty Ledger
7. Story 39.7 Loyalty Redemption
8. Story 39.8 Reporting and Reconciliation
9. Story 39.9 Loyalty Runtime Implementation

## Related Epics

1. Epic 7 Voids, Refunds, and Controlled Reversals.
2. Epic 14 BIR POS Accreditation and EOPT Hardening.
3. Epic 28 Offline-Resilient POS Architecture.
4. Epic 37 Advanced Promotions and Bundling Engine.
5. Epic 38 F&B Table and Bill Operations.

## Architecture Principles

1. Store credit balances are derived from append-only ledger entries.
2. Loyalty points and money are separate ledgers.
3. Store credit issuance must not bypass the existing refund authority.
4. Store credit redemption must not bypass the existing payment authority.
5. Offline store credit mutation and loyalty redemption are prohibited in the first release.
6. Accounting liability treatment must be explicit before implementation.
7. Customer identity must be tenant-scoped and auditable.

## Entry Point

New engineers should start here, then read the Architecture Lock before reading story specifications. Story documents must not override the Architecture Lock.
