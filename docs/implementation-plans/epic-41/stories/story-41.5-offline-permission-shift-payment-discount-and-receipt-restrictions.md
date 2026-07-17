# Story 41.5 Offline Permission, Shift, Payment, Discount, and Receipt Restrictions

## Status

Planned Scaffold

Date: 2026-07-17

## Objective

Validate and harden offline restrictions for permissions, shift accountability, payment methods, approval paths, discounts, provisional receipt behavior, and privileged operations.

## Dependencies

Requires:

1. Story 41.1.
2. Story 41.2.
3. Existing permission/payment UI restrictions.
4. Shift policy.
5. Fiscal document policy.

## Complexity

Large

## Deliverables

1. Offline payment method matrix.
2. Cash-only enforcement checks.
3. Non-cash disabled-state validation.
4. Manager approval boundary.
5. Offline discount policy with statutory discounts blocked.
6. Open-shift requirement.
7. Pending-queue shift-close behavior.
8. Cashier-switch and logout restrictions.
9. Provisional expected-cash presentation.
10. Provisional document wording and printing behavior.
11. Customer acknowledgment when final invoice is pending.
12. Pre-sync error behavior with local cancellation blocked after durable cash capture.
13. Mixed-tender restoration tests.
14. Online-only route/action guard review.
15. Permission and branch/terminal isolation tests.

## Out of Scope

1. New payment provider integrations.
2. Offline external payment authorization.
3. Offline manager approval issuance unless separately approved.
4. Official offline invoice issuance unless compliance separately approves it.
5. Offline void/refund implementation.

## Acceptance Checks

1. Card, e-wallet, bank transfer, and external tenders remain blocked offline.
2. Offline UI cannot bypass online-only permission checks.
3. Privileged operations show recoverable online-required guidance.
4. Cash-only provisional capture remains the only allowed offline payment path.
5. Offline capture is blocked without valid cached open-shift authority.
6. Shift close with unsynced sales is blocked or clearly provisional.
7. Offline payment UI cannot construct mixed-tender transactions through split-payment components or restored local state.
8. Provisional acknowledgments cannot be mistaken for official invoices.
9. Statutory discount requests are blocked with online-required guidance.
10. Pre-sync correction does not edit or erase a durably captured envelope.
11. Provisional expected-cash display is not treated as official drawer accounting.

## Notes

This story is where cashier ergonomics meets governance. The UI should guide, block, and explain without implying that offline capture is a committed sale.
