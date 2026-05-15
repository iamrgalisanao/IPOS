# Epic 13: Support Assisted Mode & Production Hardening - Proposed Implementation Plan

Date: 2026-05-13
Status: In Progress
Roadmap Status: In Progress

## 1. Purpose

Epic 13 is the production-readiness layer that sits above the already-implemented tenant, POS, accounting, settlement, dashboard, and shift foundations.

Its purpose is to:

- give internal support staff a controlled, audited, read-only assisted mode
- improve production observability for queue, sync, and critical request paths
- harden deployment and runtime security before broader back-office expansion

This epic should not introduce new cashier workflows or broaden tenant access casually. It is a control-plane epic, not a feature-velocity epic.

## 2. Current Repository Anchors

The plan is based on implementation points already present in the repository:

- tenant isolation is enforced through fail-closed middleware and tenant context
- platform support users are explicitly blocked from normal tenant routes in [docs/roadmap/validated-implementation-roadmap.md](./validated-implementation-roadmap.md) and in `IdentifyTenantContext`
- append-only audit logging already exists through `AuditLog`
- accounting outbox, QuickBooks connection flows, settlement review, dashboards, and shift operations are already implemented and test-covered

Important implication:

- support assisted mode must not be implemented by simply allowing `platform_support` users through normal tenant middleware
- support access must be a separate, explicit, auditable route and context model

## 3. Epic Outcomes

By the end of Epic 13, IPOS should support:

- controlled support access sessions with explicit reason capture
- read-only, masked operational visibility into approved support surfaces
- centralized logging and correlation for support and sync troubleshooting
- production hardening controls that reduce accidental exposure, misconfiguration, and unsafe debug behavior

## 4. Non-Goals

Epic 13 should explicitly avoid:

- unrestricted impersonation of tenant users
- write access by support staff to tenant financial or operational records
- direct POS intervention by support staff
- silent bypass of tenant or branch isolation rules
- introducing third-party observability vendors as a mandatory dependency if a first-party logging baseline is sufficient for initial rollout
- broad “admin superuser” behavior that collapses tenant boundaries

## 5. Story Sequence

Recommended implementation order:

1. Story 13.1 Support Assisted Mode Identity
2. Story 13.2 Observability & Centralized Logging
3. Story 13.3 Production Security Hardening

Rationale:

- Story 13.1 defines the highest-risk access model and needs the strongest guardrails first
- Story 13.2 adds the diagnostic foundation needed to operate Story 13.1 safely in production
- Story 13.3 finalizes deployment and runtime protections once the access and logging surface is known

## 6. Story 13.1 - Support Assisted Mode Scope Lock and Identity Model

### 6.1 Goal

Allow authorized support staff to enter a tenant-scoped assisted mode that exposes only approved read-only surfaces with masking and 100% audit coverage.

### 6.2 Implementation Boundaries

This story implements only:

- support access session model
- support context service
- dedicated support-assisted route namespace
- session lifecycle states: `start`, `active`, `expired`, `revoked`, `ended`
- read-only assisted route guard
- audit logging for support session lifecycle
- masking contract foundation
- tests proving support users remain blocked from normal tenant routes

This story does not implement yet:

- broad support dashboard
- write actions
- retries
- mapping edits
- QuickBooks reconnect actions
- export actions
- settlement actions
- POS actions
- unrestricted tenant browsing

### 6.3 Proposed Design

Introduce a dedicated support-access model rather than reusing normal tenant authentication directly.

Recommended components:

- `support_access_sessions` table
- `SupportAccessSession` model
- `SupportContext` service (parallel to `TenantContext`)
- support-only middleware for assisted mode route entry
- masked serializer/transform layer for support-visible payloads
- dedicated support route group, separate from normal tenant web routes

Recommended session fields:

- `id`
- `support_user_id`
- `tenant_id`
- `branch_id` nullable
- `reason`
- `approved_by` nullable if approval is introduced
- `started_at`
- `expires_at`
- `ended_at` nullable
- `status` (`active`, `expired`, `revoked`, `ended`)
- `masking_profile`
- `metadata`

### 6.4 Routing and Middleware Rules

- keep current `IdentifyTenantContext` behavior that blocks `platform_support` users from normal tenant routes
- introduce a dedicated assisted route namespace such as `/support/assisted/...`
- support routes must resolve tenant scope from the support session, not from the support user’s `tenant_id`
- support routes must be explicitly whitelisted and read-only

Do not introduce an approval workflow yet unless implementation forces it. Keep `approved_by` nullable for future escalation.

### 6.5 Audit Requirements

Every assisted-mode event should create append-only audit records:

- session started
- tenant selected
- branch narrowed if applicable
- support page viewed
- masked record inspected
- session ended or expired
- access denied or blocked action attempted

Audit metadata should include:

- support user id
- target tenant id
- target branch id if present
- reason
- IP address
- user agent
- route name
- session id / correlation id

### 6.6 Required Test Coverage

Primary test file:

- `tests/Feature/Support/SupportAssistedModeTest.php`

Required coverage:

- platform support user remains blocked from normal tenant routes
- authorized support user can start assisted session with reason
- session requires target tenant
- session may optionally narrow to branch
- session records support user id
- session records reason
- session records `started_at` and `expires_at`
- expired session blocks access
- revoked session blocks access
- ended session blocks access
- assisted route resolves tenant from support session, not support user tenant
- assisted route is read-only
- `POST`/`PATCH`/`DELETE` assisted attempts return `403` or `405`
- blocked write attempt is audited
- session start is audited
- session end, expiry, and revoke are audited
- assisted payload is masked
- payload excludes tokens, secrets, and provider credentials
- tenant A support session cannot read tenant B data
- branch-narrowed support session cannot read other branch data
- no accounting outbox records are created
- no QuickBooks/provider APIs are called
- no sales, payments, inventory, refunds, or voids are mutated
- previous Epic 1-12 tests remain green

### 6.7 Implementation Slice Order

Execute Story 13.1 in this order:

1. Data foundation
2. Support context and session service
3. Assisted route middleware
4. Masking contract
5. Audit events
6. Tests

The detailed slice contract and boundary lock live in [stories/story_13.1_support_assisted_mode_scope_lock_and_identity_model.md](./stories/story_13.1_support_assisted_mode_scope_lock_and_identity_model.md).

Implementation note:

- Story 13.1 remains a six-slice contract.
- A support-safe audit review endpoint was later accepted and implemented as a narrow post-contract extension.
- That audit review endpoint is not part of the original slice count and should not be treated as a mandatory Slice 7 unless Epic 13 is explicitly re-scoped.

## 7. Story 13.2 - Observability & Centralized Logging

### 7.1 Goal

Provide enough production telemetry to diagnose checkout, sync, support-access, and queue failures without depending on ad hoc log tailing.

### 7.2 Scope

Observability should focus first on:

- request correlation ids
- structured log context for critical flows
- queue and outbox processing visibility
- support-assisted session visibility
- failed job and exception centralization

### 7.3 Proposed Work Packages

1. Introduce request / correlation id generation for web, queue, and support flows.
2. Standardize structured log fields for tenant id, branch id, actor id, actor type, route, job id, and correlation id.
3. Add focused logging around:
   - checkout uncertainty and retry recovery
   - accounting outbox processing and retries
   - QuickBooks connection failures
   - settlement lock/reopen actions
   - support-assisted session lifecycle
4. Add an internal health/ops summary for queue backlog and failed jobs if not already visible enough through existing dashboards.
5. Document log channels and retention expectations.

### 7.4 Recommended Deliverables

- request correlation middleware
- queue correlation propagation
- dedicated log channel or structured formatter for operational diagnostics
- centralized exception handling guidance for production
- support-access event logs searchable by session id

### 7.5 Validation Slice

Tests or checks should verify:

- correlation ids exist on critical requests
- queue jobs preserve correlation context where applicable
- support-assisted events emit the expected structured fields
- sensitive secrets are not logged

Detailed story artifact: [stories/story_13.2_observability_and_centralized_logging.md](./stories/story_13.2_observability_and_centralized_logging.md)

## 8. Story 13.3 - Production Security Hardening

### 8.1 Goal

Reduce the risk of production misconfiguration, secret exposure, unsafe debug state, and overly permissive operational access.

### 8.2 Scope

This story should harden runtime and deployment defaults rather than redesign application architecture.

Recommended hardening areas:

- enforce `APP_DEBUG=false` expectations outside local
- verify secure cookie and session settings
- confirm CSRF, proxy, and trusted-host posture where applicable
- review rate limiting on sensitive routes
- confirm secret storage and redaction practices
- ensure exported logs and diagnostics do not leak credentials or token values
- document production environment checklist and rollback expectations

### 8.3 Proposed Work Packages

1. Add configuration validation / guardrails for unsafe production settings.
2. Review sensitive route protections for support, accounting, profile, and export flows.
3. Add explicit redaction helpers for secrets, OAuth tokens, and provider payload fragments.
4. Review authorization defaults for any route that can trigger external side effects.
5. Create a production hardening checklist document tied to deployment.

### 8.4 Validation Slice

Checks should cover:

- production config guardrails fail fast or warn clearly when unsafe settings are present
- logs redact secrets consistently
- high-risk routes remain permission-gated and CSRF-protected
- support-assisted mode cannot be used to bypass write permissions

## 9. Dependencies

Epic 13 depends on the following completed foundations:

- Epic 1 tenant and branch isolation
- Epic 2 identity and RBAC
- Epic 8 accounting outbox and QuickBooks connection state
- Epic 9 settlement review and lock model
- Epic 11 dashboards and reporting query services
- Epic 12 shift and cash drawer operational evidence

No dependency on Epics 14-21 is required.

## 10. Proposed Delivery Phases

### Phase A: Scope Lock and Access Model

- finalize support-assisted visibility rules
- define masked fields and prohibited fields
- choose support session model and route namespace

### Phase B: Assisted Mode Foundation

- create session persistence and support context
- implement read-only support routes for approved operational surfaces
- add audit logging for support access lifecycle

### Phase C: Observability Baseline

- implement correlation ids and structured logging
- improve queue / sync diagnostics
- expose basic internal health information for operations

### Phase D: Security Hardening

- add config guardrails and secret redaction protections
- validate sensitive route protections
- document production deployment safety checklist

Current execution note:

- Story 13.1 is implemented.
- Story 13.2 is implemented.
- Story 13.3 is the active remaining work for Epic 13.
- Slice A should start with production configuration guardrails before widening into route review or deployment documentation.

## 11. Acceptance Gates

Epic 13 should not be considered complete until all of the following are true:

- support staff can access only approved assisted-mode routes
- support payloads are masked and read-only by design
- all assisted-mode activity is audited append-only
- critical request and job paths include correlation-ready diagnostics
- production configuration and logging have explicit hardening checks
- no tenant isolation regressions are introduced

## 12. Development-Ready Story Checklist

Before story implementation starts, each story should have:

- explicit route list in scope
- explicit payload fields allowed and masked
- explicit audit events required
- explicit negative tests for blocked access and write attempts
- clear redaction rules for logs and UI

## 13. Recommended First Story File

The first detailed story to author for implementation should be:

- Story 13.1: Support Assisted Mode Scope Lock and Identity Model

This is the controlling abstraction for the entire epic. Once its scope, masking model, and route boundaries are fixed, Stories 13.2 and 13.3 become much easier to implement without rework.

Detailed story artifact: [stories/story_13.1_support_assisted_mode_scope_lock_and_identity_model.md](./stories/story_13.1_support_assisted_mode_scope_lock_and_identity_model.md)