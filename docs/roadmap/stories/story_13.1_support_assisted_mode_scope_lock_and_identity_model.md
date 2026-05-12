# Story 13.1: Support Assisted Mode Scope Lock and Identity Model

## Goal

Define and implement the controlled support-assisted session foundation, allowing authorized platform support users to enter a tenant-scoped, read-only, masked, audited support mode without bypassing normal tenant isolation.

## Implementation Boundaries

Implement only:

1. Support access session model.
2. Support context service.
3. Dedicated support-assisted route namespace.
4. Session lifecycle:
   - start
   - active
   - expired
   - revoked
   - ended
5. Read-only assisted route guard.
6. Audit logging for support session lifecycle.
7. Masking contract foundation.
8. Tests proving support users remain blocked from normal tenant routes.

Do not implement yet:

- broad support dashboard
- write actions
- retries
- mapping edits
- QuickBooks reconnect actions
- export actions
- settlement actions
- POS actions
- unrestricted tenant browsing

## Suggested Data Model

### `support_access_sessions`

Fields:

- `id`
- `support_user_id`
- `tenant_id`
- `branch_id` nullable
- `reason`
- `approved_by` nullable
- `started_at`
- `expires_at`
- `ended_at` nullable
- `status` `active|expired|revoked|ended`
- `masking_profile`
- `metadata` json nullable
- `created_at`
- `updated_at`

Recommended statuses:

- `active`
- `expired`
- `revoked`
- `ended`

Do not add approval workflow yet unless required. Keep `approved_by` nullable for future escalation.

## Required Tests for Story 13.1

Primary test file:

- `tests/Feature/Support/SupportAssistedModeTest.php`

Required coverage:

- Platform support user remains blocked from normal tenant routes.
- Authorized support user can start assisted session with reason.
- Session requires target tenant.
- Session may optionally narrow to branch.
- Session records support user ID.
- Session records reason.
- Session records `started_at` and `expires_at`.
- Expired session blocks access.
- Revoked session blocks access.
- Ended session blocks access.
- Assisted route resolves tenant from support session, not support user tenant.
- Assisted route is read-only.
- `POST`/`PATCH`/`DELETE` assisted attempts return `403` or `405`.
- Blocked write attempt is audited.
- Session start is audited.
- Session end/expiry/revoke is audited.
- Assisted payload is masked.
- Payload excludes tokens/secrets/provider credentials.
- Tenant A support session cannot read Tenant B data.
- Branch-narrowed support session cannot read other branch data.
- No accounting outbox records are created.
- No QuickBooks/provider APIs are called.
- No sales/payments/inventory/refunds/voids are mutated.
- Previous Epic 1-12 tests remain green.

## Design Guardrails

- Keep current `IdentifyTenantContext` behavior that blocks `platform_support` users from normal tenant routes.
- Resolve assisted tenant scope from the support access session, not from the support user model.
- Restrict assisted mode to explicitly whitelisted read-only routes.
- Require audit logging for every support session lifecycle transition and blocked write attempt.
- Introduce masking before broad support-facing views are added.

## Implementation Slice Order

### Slice 1: Data Foundation

Status: Implemented 2026-05-12

Implement:

- `support_access_sessions` migration
- `SupportAccessSession` model
- status constants:
  - `active`
  - `expired`
  - `revoked`
  - `ended`
- relationships:
  - `supportUser`
  - `tenant`
  - `branch`
  - `approvedBy`

Do not add routes yet.

### Slice 2: Support Context and Session Service

Status: Implemented 2026-05-12

Implement:

- `SupportContext`
- `SupportAccessSessionService`

Required methods:

- `startSession(...)`
- `endSession(...)`
- `revokeSession(...)`
- `resolveActiveSession(...)`
- `assertActiveSession(...)`

Rules:

- reason required
- tenant required
- branch optional
- expiry required or defaulted
- support user only
- no tenant route bypass

### Slice 3: Assisted Route Middleware

Status: Implemented 2026-05-12

Implement:

- support-assisted middleware
- resolve tenant and branch from support session
- block expired, revoked, or ended sessions
- deny unsafe HTTP methods unless explicitly allowlisted

Route namespace:

- `/support/assisted/...`

Do not expose normal tenant routes.

### Slice 4: Masking Contract

Status: Implemented 2026-05-12

Implement a small masking layer first.

Allowed initial payloads:

- QuickBooks connection state
- accounting outbox health
- mapping readiness
- tenant and branch metadata

Must redact:

- `access_token`
- `refresh_token`
- `client_secret`
- `api_key`
- `private_key`
- `Authorization`
- Bearer tokens
- raw OAuth responses

### Slice 5: Audit Events

Status: Implemented 2026-05-12

Implement append-only audit logs for:

- `support_session_started`
- `support_session_ended`
- `support_session_revoked`
- `support_session_expired`
- `support_page_viewed`
- `support_record_inspected`
- `support_blocked_action_attempted`

### Slice 6: Tests

Status: Implemented 2026-05-12

Create:

- `tests/Feature/Support/SupportAssistedModeTest.php`

Cover the full Story 13.1 acceptance gate.

## Post-Contract Extension Note

Accepted follow-on implementation after the original six-slice contract:

- support-safe audit review endpoint under the existing assisted route boundary

This endpoint was implemented as an approved extension after the original Story 13.1 six-slice plan. It is not required to interpret the original slice count, and it should not be treated as a separate mandatory Slice 7 unless the roadmap is explicitly revised.

## Story 13.1 Boundary Lock

Do not implement yet:

- unrestricted tenant impersonation
- support write access
- retry/reconnect/export actions
- settlement actions
- POS actions
- mapping edits
- QuickBooks provider calls
- broad support dashboard

Start with the smallest support-assisted read-only foundation.

## Token-Friendly Validation Format

# Story 13.1 Final Validation Attestation

## Acceptance Coverage
- Required ACs:
- controlled support access sessions with explicit tenant and optional branch scope
- support users remain blocked from normal tenant routes
- assisted routes resolve context from support sessions, not support user tenant identity
- assisted route surface is explicitly read-only
- session lifecycle states are enforced and audited
- assisted payloads are masked before support review
- blocked support action attempts are denied and audited
- support audit review remains inside the existing assisted boundary
- no accounting outbox or provider-side effects are introduced
- previous Epic 1-12 regression stays green
- Covered:
- Slice 1 implemented: data foundation for `support_access_sessions`
- Slice 2 implemented: `SupportContext` and `SupportAccessSessionService`
- Slice 3 implemented: assisted route middleware and dedicated assisted namespace
- Slice 4 implemented: recursive masking contract via `SupportPayloadMasker`
- Slice 5 implemented: support-specific audit event persistence and masked audit logging
- Slice 6 implemented: focused support acceptance coverage across session, route, masking, audit, and review boundaries
- accepted post-contract extension implemented: support-safe audit review endpoint under the existing assisted route boundary
- Missing:
- none for the approved Story 13.1 scope

## Tests
- Focused support test:
  - Tests: 27
  - Assertions: 207
- Full regression:
  - Tests: 688 passed
  - Assertions: 2993
- Frontend build: passed
- Failures: none in final support suite or full regression run
- Errors: none in final support suite, full regression run, or final frontend build
- Skips: none reported
- Exit code: 0 for final support suite, full regression run, and frontend build

## Boundary
Confirmed no:
- unrestricted tenant impersonation
- support write access
- POS intervention
- retry/reconnect/export actions
- provider API calls
- source financial mutation

## Epic 13 Approval Note

Epic 13 is approved as the next roadmap direction after Epic 12 closure.

Epic 12 is already closed in the validated roadmap and aligned planning artifacts, so Story 13.1 is the correct implementation entry point.