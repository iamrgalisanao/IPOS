# Story 29.2 - Initial Branch and Owner Admin Setup Closure

Status: Implemented & Target-Locally Validated

## Completed
- Implemented company onboarding flow for initial branch and owner/admin setup.
- Added tenant context wrapping for cross-tenant System Admin operations.
- Added lazy onboarding initialization support.
- Added owner/admin user creation with role assignment.
- Added bootstrap token generation and response exposure.
- Added onboarding event tracking.
- Updated schema/model support for user first and last names.
- Added onboarding initialized event type support.
- Verified related payment/audit checks remained green.

## Validation Evidence
- `./vendor/bin/pest tests/Feature/SystemAdmin`
  - Result: 18 tests passing
- `./vendor/bin/pest tests/Feature/SystemAdmin tests/Feature/POS/PaymentAuditTest.php tests/Feature/AuditLoggingTest.php`
  - Result: 27 tests passing in provisioning/onboarding/audit domain

## Acceptance Scope
Accepted as a targeted implementation checkpoint for Story 29.2:
- Tenant provisioning tests green.
- Company onboarding tests green.
- Payment/audit checks green.
- Bootstrap token response path fixed.
- Tenant context handling improved.
- Owner/admin role assignment fixed.

## Governance Update
G-062 accounting/full-suite blocker triage is resolved. G-066 full-suite risky/incomplete cleanup is also closed. Latest full-suite baseline is green (1351 passed, 0 failed, 0 risky, 0 incomplete, 6237 assertions).

## Governance Notes
- Story 29.1: Implemented and locally validated.
- Story 29.1A: Completed with residual gaps documented.
- Story 29.2: Implemented and target-locally validated.

## Next Action
Proceed to Story 29.3 Sales Machine Profile and Compliance Registration.
