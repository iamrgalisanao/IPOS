# Epic 28 Phase 2 Pilot Runbook

Date: 2026-05-20
Status: Finalized for pilot operations (awaiting selected pilot branch/terminal)
Scope: Step-by-step procedures for enabling, monitoring, disabling, and recovering controlled offline sales during early partner pilot.

## 1. Purpose and Governance Boundary
This runbook operationalizes Epic 28 Phase 2 under controlled early partner conditions.

Hard boundary:
- Client offline payloads are claims.
- Server recalculation is truth.
- Official posting occurs only on the server.
- No local official GCT, Z-read, or e-journal finalization.
- No marketing or formal compliance claims.

## 2. Roles and Ownership
- Pilot Commander (Operations Lead): Go/no-go ownership and execution coordination.
- Technical Lead (Engineering): Runtime controls, incident triage, rollback execution.
- Compliance Observer: Boundary adherence and evidence quality checks.
- Branch Manager: Branch readiness, cashier coordination, incident reporting.
- Support Lead: Ticket intake, communication, and escalation routing.

## 3. Entry Criteria (Pre-Go-Live)
All items must be true before enabling controlled offline sales for a branch.

- Pilot branch and terminal are allowlisted.
- Non-pilot branches are online-only or explicitly disabled.
- Terminal prefix and sequence status are verified.
- Approved admin roles for posting are confirmed.
- Monitoring dashboards and alert routes are active.
- Backup and restore verification is current.
- Partner onboarding and boundary briefing are completed.
- Compliance disclaimer acknowledgement is recorded.

## 4. Enablement Procedure
Use these steps per pilot branch.

1. Confirm branch and terminal are in approved pilot list.
2. Verify terminal sequence registry status is active and healthy.
3. Verify controlled offline sales setting is enabled only for approved scope.
4. Verify non-pilot branches remain disabled or online-only.
5. Execute smoke check:
- offline queue creation
- sync submission
- server-side posting path health
6. Confirm monitoring signals are receiving events.
7. Announce pilot enablement window and support contact channel.
8. Record enablement timestamp, owner, and branch in pilot log.

## 5. Monitoring Procedure (During Pilot)
Monitor continuously during active cashier windows.

Primary metrics:
- Offline imports per terminal and per day
- Validation rejection rate by reason
- Late-sync imports older than threshold
- Duplicate sequence and prefix mismatch attempts
- Conflict review and override-approved ratio
- Override-approved actions by reviewer
- Posting success/failure rate
- Queue retry behavior and sync lag

Daily operations cadence:
1. Start-of-day health check
2. Mid-shift metric scan and anomaly review
3. End-of-day reconciliation summary
4. Evidence export and archive

## 6. Incident Handling and Escalation
Severity model:
- SEV-1: posting failures causing branch-wide inability to reconcile
- SEV-2: elevated rejection/conflict rates, degraded sync behavior
- SEV-3: isolated terminal issues with workaround available

Response flow:
1. Open incident ticket with branch, terminal, and timeline.
2. Capture current queue status and recent sync outcomes.
3. Apply immediate containment if financial risk is detected.
4. Escalate by severity to Operations, Engineering, Product, Compliance.
5. Provide partner communication update using approved template.
6. Record root cause, impact, and corrective action.

## 7. Backup and Evidence Export
Before and after any major incident action:

1. Verify latest successful backup snapshot.
2. Export reconciliation evidence and audit logs.
3. Capture import statuses and posting outcomes for affected window.
4. Store evidence package under pilot incident reference.
5. Confirm retention policy compliance.

## 8. Disable Procedure (Controlled Offline Sales)
Use when risk threshold is exceeded or pilot pause is required.

1. Notify branch and support that disable window is starting.
2. Review queued offline transactions for affected terminals.
3. Ensure no pending queue entry is abandoned without documented recovery action.
4. Disable controlled offline sales for target branch/terminal scope.
5. Confirm disable action is audit-logged.
6. Validate branch is running online-only mode.
7. Capture post-disable system status and issue summary.

## 9. Rollback and Recovery Procedure
Rollback goal: safe return to stable cashier operations with reconciled state.

1. Confirm disable has completed and is auditable.
2. Reconcile remaining imports via server-side review/posting path.
3. Resolve conflicts and document override decisions.
4. Verify no stranded queued transaction remains unresolved.
5. Re-check sequence registry consistency.
6. Validate end-state metrics are stable.
7. Approve branch return-to-service sign-off.

## 10. Partner Communication Notes
Required messaging points:
- Pilot is controlled and limited in scope.
- Offline queue is provisional; official posting is server-side.
- No official claim of BIR-certified or accredited offline finalization.
- CPA/BIR external review remains post-development.

## 11. Exit Criteria for Pilot Window
A pilot window can be marked complete when all are satisfied:

- No unresolved SEV-1/SEV-2 incidents.
- Queue and sync behavior within thresholds.
- Reconciliation completeness validated.
- Evidence package stored and reviewed.
- Compliance observer sign-off recorded.

## 12. Required Artifacts and References
- docs/validation/epic-28-phase-2-controlled-offline-sales-closure-report.md
- docs/validation/epic-28-phase-2-early-partner-pilot-checklist.md
- docs/ai-governance/task-ledger.md

## 13. Pilot Log Template
For every enablement, disablement, rollback, or major incident, record:

- Date/time:
- Branch:
- Terminal / machine profile:
- Action taken:
- Performed by:
- Approved by:
- Reason:
- Queue count before action:
- Queue count after action:
- Sync status:
- Evidence export reference:
- Incident/ticket reference:
- Notes:
