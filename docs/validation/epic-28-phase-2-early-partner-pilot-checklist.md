# Epic 28 Phase 2 Early Partner Pilot Checklist

Date: 2026-05-20
Scope: Controlled early partner pilot rollout for offline-resilient POS with server-authoritative official posting.

## 1. Rollout Controls
- [ ] Confirm pilot branches and terminals are explicitly allowlisted.
- [ ] Confirm controlled offline sales is enabled only for selected pilot tenants/branches/terminals.
- [ ] Confirm non-pilot branches remain online-only or explicitly disabled.
- [ ] Confirm offline sales settings and terminal sequence registry are active per approved terminals only.
- [ ] Confirm terminal prefix and sequence status are verified before go-live.
- [ ] Confirm posting authority is restricted to approved admin roles.
- [ ] Confirm environment configuration and secrets are production-safe.
- [ ] Confirm communication plan for pilot start, freeze windows, and rollback windows.

## 2. Monitoring and Alerting
- [ ] Track offline import volume per terminal and per day.
- [ ] Track validation rejection rates by reason.
- [ ] Track late-sync imports older than configured threshold.
- [ ] Track duplicate sequence or prefix mismatch attempts.
- [ ] Track conflict review rates and override-approved ratios.
- [ ] Track admin override-approved actions by reviewer.
- [ ] Track posting success and failure rates.
- [ ] Track queue retry behavior and sync lag.
- [ ] Define alert thresholds and on-call escalation contacts.

## 3. Backup and Export Procedures
- [ ] Verify backup jobs cover offline sync and sales data stores.
- [ ] Verify restore test for recent backup snapshot.
- [ ] Verify export procedures for reconciliation evidence and audit logs.
- [ ] Verify retention policy for pilot transaction and reconciliation records.
- [ ] Verify incident-time evidence bundle steps for compliance review.

## 4. Issue Escalation Workflow
- [ ] Define severity levels for sync and posting incidents.
- [ ] Define first-response SLA and ownership for each severity.
- [ ] Define escalation chain (Operations, Engineering, Product, Compliance).
- [ ] Define incident communication templates for pilot partners.
- [ ] Define post-incident review and action tracking template.

## 5. Partner Onboarding Notes
- [ ] Provide cashier guidance: offline queue is provisional, official posting is server-side.
- [ ] Provide manager guidance: conflict review and posting controls.
- [ ] Provide support guidance: known limitations and expected behavior.
- [ ] Confirm partner acknowledgement of deferred CPA/BIR external review status.
- [ ] Confirm pilot boundaries: no local official GCT, Z-read, or e-journal finalization.

## 6. Disable and Rollback Instructions
- [ ] Document immediate disable steps for terminal offline capture per branch.
- [ ] Document safe rollback to online-only cashier operation.
- [ ] Confirm queued offline transactions are reviewed before disabling a terminal.
- [ ] Confirm no pending queued transaction is abandoned without documented recovery action.
- [ ] Document reconciliation safety checks before and after disable.
- [ ] Document sequence registry and status handling during rollback.
- [ ] Confirm offline sales disable action is audit-logged.
- [ ] Document sign-off checklist for rollback completion.

## 7. Readiness Sign-off
- [ ] Product Owner sign-off
- [ ] Engineering Lead sign-off
- [ ] Operations Lead sign-off
- [ ] Compliance Lead sign-off
- [ ] Pilot go-live date approved

## 8. Compliance Disclaimer
- [ ] Confirm all pilot participants understand this is a controlled early partner rollout.
- [ ] Confirm no BIR-certified, BIR-approved, or officially accredited offline-sales claim is made.
- [ ] Confirm final CPA/BIR review remains scheduled after broader application development.
- [ ] Confirm provisional/offline transaction wording remains subject to final compliance review.
