---
title: 'Story 37.7 Promotion Refund and Void Reversal Behavior'
type: 'feature'
created: '2026-07-14'
status: 'review'
context:
  - '{project-root}/docs/roadmap/epic-37-38-39-proposed-specifications.md'
  - '{project-root}/_bmad-output/implementation-artifacts/story-37.6-promotion-receipt-xz-reporting-integration.md'
---

## Intent

Close Epic 37 Story 37.7 by reversing commercial promotion discount aggregates when a promoted sale is voided or refunded. Promotion snapshot records remain immutable audit evidence; only sale-level and sale-item remaining discount aggregates are adjusted for reporting accuracy.

## Scope

**Included**

- Full-sale voids zero remaining commercial promotion totals and sale item promotion allocations.
- Partial refunds reverse promotion discount amounts proportionally by refunded item quantity.
- Repeated partial refunds converge to zero without over-reversal.
- Original `sale_promotions` and `sale_promotion_lines` records remain intact.
- Audit events record commercial promotion reversal details for voids and refunds.

**Excluded**

- New promotion rule calculation behavior.
- Schema changes.
- Refund-specific negative promotion snapshot tables.
- Changes to statutory discount reversal policy beyond compatibility with the existing flow.

## Acceptance Criteria

- Given a paid sale with applied commercial promotion snapshots, when it is fully voided, then `commercial_discount_total` and remaining sale item promotion allocations are zeroed while the original promotion snapshot remains.
- Given a promoted sale item is partially refunded, then the sale's commercial promotion total and the item's remaining promotion allocation are reduced proportionally.
- Given subsequent refunds complete the promoted quantity reversal, then remaining commercial promotion totals reach zero without deleting audit snapshots.
- Existing void, refund, statutory discount, inventory, payment reversal, and promotion calculation tests continue to pass.

## File List

- `app/Services/POS/VoidService.php`
- `app/Services/POS/RefundService.php`
- `tests/Feature/POS/VoidServiceTest.php`
- `tests/Feature/POS/RefundServiceTest.php`

## Verification

- `php artisan test tests/Feature/POS/VoidServiceTest.php tests/Feature/POS/RefundServiceTest.php`

