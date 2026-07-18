# Epic 41 POS Terminal Offline Readiness and Release Validation

## Status

Story Planning Active — Story 41.7 Implemented - Local Verification Complete

Date: 2026-07-18

## Objective

Epic 41 turns the existing POS terminal offline hardening work into a formal release-readiness track.

The goal is not to expand offline capability indiscriminately. The goal is to define, validate, and govern what the terminal may do offline, what remains online-only, what is locally queued, what is server-authoritative, how replay and drift are handled, and what evidence is retained locally and centrally before branch rollout.

## Documentation Map

1. [Architecture Lock](epic-41-architecture-lock.md)
2. [Implementation Guide](epic-41-implementation-guide.md)
3. [Stories](stories/)
4. [Architecture Decision Records](adr/)
5. [Diagrams](diagrams/)

## Story Order

1. Story 41.1 Offline Architecture and Policy Lock.
2. Story 41.2 Offline Transaction Queue Integrity.
3. Story 41.3 Server Synchronization, Idempotency, and Transaction Atomicity.
4. Story 41.4 Conflict, Drift, Ordering, and Review Handling.
5. Story 41.5 Offline Permission, Shift, Payment, Discount, and Receipt Restrictions.
6. Story 41.6 Inventory, Loyalty, and Cross-Domain Consequence Validation.
7. Story 41.7 Hardware, Storage-Loss, and Terminal Recovery.
8. Story 41.8 Pilot UAT and Release Gate.

## Related Prior Work

1. [POS Terminal Offline Stabilization Validation Note](../../validation/pos-terminal-offline-stabilization-2026-07-10.md)
2. [POS Terminal Offline Checkout and Sync UAT](../../validation/pos-terminal-offline-uat-2026-07-11.md)
3. [Epic 41 Terminal Identity Binding Closure](../../validation/epic-41-terminal-identity-binding-closure.md)
4. [Epic 40 Retrospective](../epic-40/epic-40-retrospective.md)
5. [Epic 40 Pilot UAT Readiness](../../validation/epic-40-pilot-uat-readiness.md)

## Architecture Principles

1. Offline terminal state is provisional until server synchronization accepts it.
2. Server-side services remain the authority for sale posting, inventory effects, loyalty effects, store credit effects, receipt compliance, fiscal evidence, and reporting.
3. Offline cash capture may be allowed only within explicit terminal, shift, catalog-age, and storage-durability policy.
4. Non-cash payment, void, refund, stocktake, inventory adjustment, dining mutation, and privileged admin operations remain online-only unless a future Architecture Lock explicitly changes that rule.
5. Offline replay must be idempotent.
6. Drift must fail closed into review rather than silently posting inconsistent state.
7. Terminal identity remains mandatory for terminal shell access and synchronization.
8. Hardware readiness must not be claimed without physical device validation.
9. Offline customer documents are provisional unless a formally approved fiscal configuration permits official offline invoice issuance.
10. Offline capture is a standalone cash checkout path; dine-in ticket mutation remains online-only.
11. Cash-collected unresolved records remain visible until explicitly resolved.
12. Cached stock is provisional visibility only and is not locally deducted.
13. Statutory discounts remain online-only for the first release.

## Entry Point

New reviewers should read the Architecture Lock first, then the Implementation Guide. Story specifications should be drafted only after the Architecture Lock is approved.
