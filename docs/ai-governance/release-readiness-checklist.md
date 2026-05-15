# Release Readiness Checklist

Last updated: 2026-05-13
Status: In Progress

## Purpose

This document is the bounded post-Epic-13 release-readiness checklist for IPOS.

It exists because the validated roadmap closes Epic 13 but no Epic 14+ implementation story is currently approved. The next approved work is release-readiness and go-live preparation, not new feature delivery.

## Controlling Blockers

- `G-009` in `docs/ai-governance/task-ledger.md`
- `R-008` in `docs/ai-governance/risk-register.md`

These blockers center on previously committed MCP and Hermes credentials that may still be valid and therefore must be rotated before any release approval.

## Scope Boundaries

This checklist allows only:

1. Credential rotation and reinjection through secure prompts or environment.
2. Verification that no historically exposed credentials remain active.
3. Confirmation that production guardrails and release-critical regressions remain green.
4. Release-decision documentation and handoff preparation.

This checklist does not authorize:

- new feature delivery
- new admin or support UI
- new provider actions
- new dashboards
- new external security vendors
- broad architecture redesign

## Checklist

### 1. Credential Rotation

- Identify all historically committed MCP and Hermes credentials referenced by current governance evidence.
- Rotate each credential outside the repository.
- Re-enter rotated values through secure prompts or environment injection only.
- Verify that `.vscode/mcp.json`, `docker-compose.yml`, and related runtime config no longer contain reusable live values.

Exit condition:

- all historically exposed credentials are rotated and repository-safe reinjection is confirmed.

### 2. Release-Critical Validation

- Re-run `php artisan test tests/Feature/Security` after credential remediation if any runtime configuration changes are made.
- Re-run full `php artisan test` before release decision.
- Run `npm run build` only if release-preparation changes affect frontend assets or deployment-facing frontend configuration.

Exit condition:

- security suite green
- backend regression green
- frontend build green when applicable

### 3. Governance Sync

- Refresh `docs/ai-governance/task-ledger.md` when `G-009` is complete.
- Refresh `docs/ai-governance/risk-register.md` when `R-008` is mitigated.
- Record release decision evidence in the appropriate governance artifact before go-live.

Exit condition:

- no open high-severity release blocker remains in governance records.

## G-009 Credential Reinjection Status

The credential rotation blocker has been reduced from a technical blocker to an environment deployment activity.

Status: **Partially Resolved — Technical readiness complete; local operational workflow verified**

**Completed:**
- Secret hygiene audit completed (Phase 1).
- No tracked live secret files found.
- CLI credential reinjection tool implemented (`php artisan credentials:inject`).
- Elevated permission model implemented (`security.credentials.manage`).
- Metadata-only audit logging implemented.
- Local `mail_provider` reinjection trial completed.
- Security regression passed after reinjection: 21/21 tests.
- `.env` remained git-ignored and untracked.

**Deferred:**
- `APP_KEY` rotation due to high encrypted-data/session impact.
- Admin UI credential management (Phase C).
- Staging/Production reinjection, to be performed by the human owner during deployment.

**Release Impact:**
- G-009 is technically ready.
- **Release Condition:** Final production release still requires HITL reinjection and validation for the target environment by the human owner.

## Recommended Next Action

Execute credential rotation as the next bounded release-readiness task, then refresh the governance artifacts and re-run the regression baseline.