# Task Ledger

Last updated: 2026-05-13

## Active Governance Tasks
 
 | ID | Task | Status | Evidence | Next Action |
 | :--- | :--- | :--- | :--- | :--- |
 | G-002 | Maintain formal guardrail audit outputs | In Progress | `docs/ai-governance/guardrail-health-report.md`, `_bmad-output/implementation-artifacts/guardrail-health-summary.md` | Refresh after Epic 14 closure |
 | G-009 | Rotate previously committed MCP and Hermes credentials | Partially Resolved | `docs/ai-governance/g-009-credential-reinjection-plan.md`, `app/Console/Commands/Security/InjectCredentialsCommand.php`, `app/Services/Security/CredentialManagementService.php` | Phase B CLI Tool implemented. Local operational workflow verified via mail_provider trial. Perform actual reinjection/validation for target environment before final release |
 | G-017 | Close release-readiness blockers after Epic 13/14 | Resolved | `docs/roadmap/validated-implementation-roadmap.md`, `docs/ai-governance/release-readiness-checklist.md`, `docs/ai-governance/release-decision.md` | Hand off to human owner for production reinjection and deployment sign-off |
 | G-019 | Execute Epic 15: Sales & Transaction History | In Progress | `epic-15-implementation-plan.md`, `SalesHistoryQueryService.php`, `SalesHistoryController.php`, `SalesHistoryQueryTest.php` | Slice A: Completed — Validated; Slice B: Completed Early — Accepted as Baseline; Slice C: Completed Early — Accepted as Baseline; Slice D: Completed — Validated. **Governance Note**: Scope drift and Slice D closure review successfully completed. |
 | G-020 | Execute Shift Operations Hardening | In Progress | `slice-3-validation-report.md`, `shift-hardening-slice-4-plan.md` | Slices 1-4: Completed — Validated. Awaiting final branch closure. |
 | G-021 | Execute Epic 22: Visual POS Layout Builder | In Progress | `docs/roadmap/epic-22-implementation-plan.md`, `docs/ai-governance/epic-22-slice-a-validation-report.md` | Slice A: Completed — Validated. Schema, RBAC, and models created. Proceed to Slice B planning. |

 ## Completed Governance Tasks

 | ID | Task | Completed On | Evidence |
 | :--- | :--- | :--- | :--- |
 | G-018 | Epic 14 Closure Review and Roadmap Reconciliation | 2026-05-15 | `walkthrough.md`, `docs/roadmap/validated-implementation-roadmap.md` |
 | G-015 | Initialize Epic 12: Shift Operations planning | 2026-05-13 | `story_12.1_shift_and_cash_drawer_scope_lock.md`, `docs/roadmap/validated-implementation-roadmap.md` |
 | G-014 | Initialize Epic 11: Operational Pulse Dashboard planning | 2026-05-13 | `story_11.1_operational_pulse_scope_lock.md`, `docs/roadmap/validated-implementation-roadmap.md` |
 | G-012 | Initialize Epic 10: Settlement Expansion planning | 2026-05-12 | `story_10.1_settlement_export_and_report_scope_lock.md` |
 | G-013 | Epic 9 Closure Review and Roadmap Reconciliation | 2026-05-12 | `epic_9_closure_report.md`, `docs/ROADMAP.md` |
 | G-016 | Epic 11 Closure Review and Roadmap Reconciliation | 2026-05-12 | `walkthrough.md`, `docs/ROADMAP.md` |
 | G-011 | Reconcile roadmap and guardrail artifacts with implemented settlement review, action, and reopen workflows | 2026-05-12 | `docs/ROADMAP.md`, `docs/validation/story-9.7-validation.md`, `docs/validation/story-9.8-validation.md`, `docs/validation/story-9.9-validation.md`, `docs/ai-governance/guardrail-health-report.md`, `_bmad-output/implementation-artifacts/guardrail-health-summary.md` |

| G-010 | Reconcile roadmap and planning artifacts with implemented settlement controls | 2026-05-12 | `docs/ROADMAP.md`, `_bmad-output/planning-artifacts/epics.md`, `docs/ai-governance/guardrail-health-report.md`, `_bmad-output/implementation-artifacts/guardrail-health-summary.md` |
| G-004 | Initialize missing governance ledgers for audit continuity | 2026-05-12 | `docs/ai-governance/task-ledger.md`, `docs/ai-governance/risk-register.md` |
| G-001 | Reconcile roadmap with implemented accounting integration surface | 2026-05-12 | `docs/ROADMAP.md` |
| G-003 | Establish active work focus document | 2026-05-12 | `.agents/rules/03-current-focus.md` |
| G-005 | Refresh or supersede stale planning artifacts that still describe pre-implementation gaps | 2026-05-12 | `docs/ai-governance/current-state-alignment-note-2026-05-12.md` |
| G-006 | Establish explicit review artifacts for accounting and integration changes | 2026-05-12 | `docs/ai-governance/code-review-log.md`, `_bmad-output/implementation-artifacts/code-review-summary.md`, `_bmad-output/implementation-artifacts/security-validation-summary.md`, `docs/ai-governance/compliance-register.md` |
| G-007 | Remove hardcoded Hermes API token from compose configuration | 2026-05-12 | `docker-compose.yml`, `.vscode/mcp.json` |
| G-008 | Create task breakdown traceability for reviewed accounting surfaces | 2026-05-12 | `docs/ai-governance/task-breakdown.md` |
