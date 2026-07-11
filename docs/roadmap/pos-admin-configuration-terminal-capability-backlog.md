# POS Admin Configuration and Terminal Capability Backlog

Date: 2026-07-11
Status: Planning Reference / Not Yet Implementation-Locked
System Area: Back Office Configuration, POS Terminal, Offline Sync, Terminal Governance

## Purpose

This backlog converts the POS admin-configuration benchmark review into an IPOS
planning reference. It is not a claim that all capabilities are implemented.
Instead, it defines the target architecture for how Back Office configuration
should enable and constrain POS terminal behavior.

## Product Direction

IPOS should use a hybrid configuration model:

```text
Back Office = master configuration and governance
POS Terminal = execution, operational overrides, and controlled local settings
Config Snapshot = versioned bridge between Back Office and terminal
Audit Logs = traceability for sensitive configuration and terminal actions
```

The POS terminal should not become a general admin surface. It should execute
against a branch/register-scoped configuration snapshot and expose only limited
operational actions such as manual sync, printer test, availability toggles,
table movement, clock in/out, open/close shift, and cash drawer movements.

## Current IPOS Coverage Assessment

| Capability Area | Current Coverage | Notes |
| --- | --- | --- |
| Roles, permissions, and user readiness | Partial / implemented foundation | RBAC and User Management exist, including POS roles, branch assignments, cashier PIN readiness, and backend permission middleware. Role creation/permission editing is not yet a complete admin-config product surface. |
| Register / terminal profiles | Partial | `SalesMachineProfile` exists with terminal identity, compliance fields, offline sequence settings, and terminal middleware enforcement. Full register activation, layout/printer assignment, suspend/revoke workflow, and device activation UX remain backlog. |
| POS layout builder | Partial / implemented foundation | Admin POS layout routes and published branch layouts exist. Register-specific layout assignment and layout version hash propagation should be strengthened. |
| Product catalog | Implemented foundation | Products, categories, branch pricing, tax assignment, inventory visibility, recipes, and export/template surfaces exist. Variants, modifiers, channel-scoped availability, and catalog version hashing are backlog. |
| Payment methods | Partial | Payment methods exist and POS uses active methods. Admin configuration for branch scope, offline allowance, reference rules, and split-payment policy is backlog. |
| Taxes and charges | Partial / implemented foundation | Tax categories and sale tax snapshots exist. Effective-dated admin tax rule versioning and configurable service charges are backlog. |
| Discounts | Partial / strong statutory slice | SC/PWD/Solo Parent statutory discount engine is implemented. General promo/regular discount rules and configurable approval thresholds remain backlog. |
| Cash drawer and shifts | Implemented operations / partial configuration | Shift, cash drawer events, cash drops, spot audit, and manager reconciliation exist. Configurable drawer reasons and approval policies remain backlog. |
| Service areas and tables | Not yet complete | Table/service-area F&B configuration remains a future module. |
| Printer routing and hardware | Deferred | Hardware devices are unavailable. Schema/admin UX can be planned, but physical validation stays deferred. |
| Online channel settings | Not yet complete | Channel-scoped availability and pause/resume controls remain future scope. |
| Audit logs | Partial | Audit logging exists across several sensitive paths. A unified filter/export audit-log console for configuration and terminal actions remains backlog. |
| Config snapshot and sync | Major gap | Offline cache/bootstrap exists, but a full versioned terminal configuration snapshot contract is not yet implemented. This is the highest-leverage next architecture slice. |

## Target Capability Map

| Back Office Configuration | POS Terminal Capability Enabled |
| --- | --- |
| POS Layout Builder | Sell / checkout product grid |
| Register / Terminal Profiles | Activate register, download configuration snapshot |
| Product / Variant / Modifier Management | Sell products, select variants/modifiers, mark availability |
| Payment Methods | Checkout tender selection, offline tender restrictions |
| Taxes / Charges | Cart totals, receipts, reports, offline payload hashes |
| Discount Rules | Regular discounts, statutory discounts, manager approvals |
| Roles / Permissions | Login, checkout, void/refund, discounts, reports, admin access |
| Cash Drawer Reasons | Pay-in/pay-out, open/close shift controls |
| Service Areas / Tables | F&B table/order flow |
| Printer Routing | Receipts, kitchen tickets, shift reports |
| Online Channel Settings | Channel availability and pause/resume controls |
| Audit Logs | Configuration traceability and terminal action history |
| Config Snapshot / Sync Rules | Manual sync, offline readiness, stale-config detection |

## Recommended Implementation Sequence

### Phase A — Foundation

1. Config Snapshot and Versioning
2. Register / Terminal Profile Expansion
3. Unified Configuration Audit Log Viewer
4. Payment Method Admin Policy
5. Approval Rules Configuration

### Phase B — POS Enablement

1. Terminal activation and config download
2. Manual sync/refresh result UX
3. Layout-register assignment and version detection
4. Cash drawer reason configuration
5. Hardware/printer profile schema, without physical readiness claims

### Phase C — Advanced Operations

1. Variants and modifiers
2. Channel availability and online channel pause/resume
3. Service areas and tables
4. Printer routing/KOT
5. X/Z and shift report printing hardening

## First Implementation Candidate

The first implementation-lock candidate should be:

**Admin Config Snapshot Foundation**

Goal: create a repeatable, branch/register-scoped snapshot that the POS terminal
can download and use as the source of truth while online or offline.

Minimum snapshot contents:

- tenant, branch, and register profile identity
- assigned layout and `layout_version_hash`
- catalog and `catalog_version_hash`
- tax rules and `tax_configuration_version_hash`
- statutory discount metadata and `discount_rules_version_hash`
- active payment methods and `payment_methods_version_hash`
- terminal/offline policy and `terminal_policy_version_hash`
- printer profile placeholder and `printer_profile_version_hash`

Acceptance direction:

1. Snapshot generation is deterministic and idempotent.
2. Snapshot is scoped to tenant, branch, and terminal profile.
3. POS terminal downloads only its allowed snapshot.
4. Offline sales include relevant snapshot hashes.
5. Server can classify stale snapshot submissions as accepted-with-warning,
   rejected, or review-required according to policy.

## Boundaries

- Do not move master pricing, taxes, discount rules, roles, or payment method
  configuration into the POS terminal.
- Do not claim printer/cash drawer readiness until hardware is available and
  physical UAT is completed.
- Do not introduce local official GCT, Z-read, e-journal, or BIR-certified
  offline receipt finalization.
- Do not bypass backend permission checks even if frontend controls are hidden.

## Reference Sources

- Attached benchmark review: POS Admin Configuration & Terminal Capability Backlog.
- Current roadmap: `docs/roadmap/validated-implementation-roadmap.md`.
- POS terminal hardening reference:
  `_bmad-output/planning-artifacts/pos-terminal-hardening-pass-development-ready-plan.md`.
- POS offline UAT:
  `docs/validation/pos-terminal-offline-uat-2026-07-11.md`.
