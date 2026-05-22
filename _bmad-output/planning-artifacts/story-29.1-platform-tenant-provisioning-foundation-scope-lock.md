# Story 29.1 - Platform Tenant Provisioning Foundation

Date: 2026-05-20
Status: Accepted / Scope Locked
Implementation Phase: Implemented & Locally Validated

---

## 1. Goal
Create the System Admin foundation for provisioning and managing tenant/company records, including subscription plan and module/feature access visibility using the existing feature-gating system.

---

## 2. Story Scope Boundaries

### In Scope
- System Admin tenant/company list
- Create tenant/company form
- Edit tenant/company profile
- Tenant status management:
  - trial
  - active
  - suspended
- Subscription/plan assignment using existing `subscriptions.php` configuration
- Feature/module enablement using existing tenant entitlement logic
- Tenant-level feature override visibility using existing `subscription_metadata`
- Readiness indicator showing missing onboarding pieces
- Feature gate coverage summary in System Admin view, including:
  - configured feature flags
  - currently enforced route groups
  - missing/partial enforcement notes
- Protection against tenant self-escalation:
  - Tenant Admin cannot self-enable features outside assigned plan and supported overrides

### Out of Scope
- new feature-gating engine
- replacing `EnforceSubscriptionGate` middleware
- full route enforcement rollout for all configured modules
- billing automation
- payment subscription integration
- user-level entitlement redesign
- branch-specific feature entitlement system
- branch creation wizard
- owner/admin user creation wizard
- sales machine profile setup
- BIR/PTU/MIN compliance wizard
- controlled offline sales pilot provisioning
- tenant admin operational dashboard changes

---

## 3. Governance Boundary
Story 29.1 creates the platform-level tenant provisioning foundation only. It does not complete full tenant onboarding by itself, and it does not rebuild feature-gating internals.

Feature-gating in this story is exposure and control of existing capabilities, plus explicit documentation of enforcement coverage gaps.

---

## 4. Feature Gate Research Findings
Public research across UTAK, Mosaic Solutions, iRipple, and ANSI indicates common market patterns for packaging capabilities as bundles, modules/add-ons, and enterprise-configurable suites.

Public sources do not provide enough evidence to confirm each provider's internal feature-gating architecture. Story decisions must therefore treat competitor behavior as packaging/access-control benchmark input, not internal implementation proof.

Implication for IPOS: keep current feature-gating engine, expand provisioning visibility and control, and document enforcement coverage gaps for follow-on hardening.

---

## 5. Feature Gate Provisioning Requirements (Story 29.1)

### Story 29.1 Must
- Show configured feature flags from `config/subscriptions.php`
- Show tenant effective entitlements using current tenant entitlement logic
- Show tenant-level overrides from `subscription_metadata`
- Show whether each surfaced feature is currently route-enforced
- Show notes for missing/partial route enforcement coverage
- Allow System Admin to assign plan/tier and manage supported tenant-level overrides
- Keep Tenant Admin from self-enabling features outside assigned plan
- Surface readiness warnings for high-risk features requiring operational prerequisites

### Story 29.1 Must Not
- Rebuild feature-gating engine
- Replace `EnforceSubscriptionGate`
- Redesign entitlement resolution semantics
- Complete full route enforcement rollout for every configured feature
- Add billing/payment subscription automation

---

## 6. Recommended Feature-Gate Model

### Layer 1: Plan-Level Entitlement
Commercial package controls broad module access (for example: Starter, Growth, Pro, Enterprise).

### Layer 2: Tenant-Level Override
Controlled exceptions for pilot, trial, or tenant-specific enablement via `subscription_metadata`.

### Layer 3: Route and UI Enforcement
Technical enforcement via middleware, service guards, and UI visibility from shared capabilities.

Current gap to address incrementally: broad adoption and coverage consistency, not core engine replacement.

---

## 7. System Admin Feature Gate Summary Baseline

| Feature Key | Category | Plan Controlled | Tenant Override | Route Enforced | UI Hidden When Disabled | Readiness Dependency |
|---|---|---:|---:|---:|---:|---|
| `pos.core` | POS | Yes | Yes | Required | Required | Branch + terminal |
| `inventory.core` | Inventory | Yes | Yes | Required | Required | Products + branch inventory |
| `procurement.advanced` | Procurement | Yes | Yes | Partial today | Required | Suppliers configured |
| `reports.advanced` | Reports | Yes | Yes | Partial today | Required | Sales data |
| `accounting.quickbooks_sync` | Accounting | Yes | Yes | Implemented | Required | QuickBooks connection |
| `pos.layout_custom` | POS UX | Yes | Yes | Implemented | Required | Branch layout |
| `offline.controlled_sales` | Offline Sales | Yes + Pilot | Yes | Required | Required | Terminal prefix + pilot checklist |

This baseline is a provisioning and coverage visibility contract for Story 29.1, not a full enforcement-completion commitment.

---

## 8. Planned Story Sequence
- 29.1 - Platform Tenant Provisioning Foundation
- 29.1A - Feature Gate Enforcement Coverage Hardening
- 29.2 - Initial Branch and Owner Admin Setup
- 29.3 - Sales Machine Profile and Compliance Registration
- 29.4 - Controlled Offline Sales Pilot Provisioning UI
- 29.5 - Tenant Onboarding Readiness Review

### 29.1A Scope Hand-off
- Map all configured feature flags to route groups
- Add missing `subscription.feature` middleware where safe and low-risk
- Align navigation visibility/hiding with effective entitlements
- Add regression coverage per gated module and access path

---

## 9. Closure Record

### Story 29.1 - Platform Tenant Provisioning Foundation

Status: Implemented & Locally Validated

#### Completed
- Upgraded System Admin tenant provisioning page from stub to working foundation UI.
- Added tenant search.
- Added tenant creation controls.
- Added tenant edit controls.
- Added status management for tenant lifecycle.
- Added subscription/plan assignment using existing subscription configuration.
- Added feature/module visibility using existing entitlement logic.
- Added tenant-level override visibility through subscription metadata.
- Added feature gate coverage summary.
- Added readiness visibility.
- Added explicit protection test against tenant self-escalation.

#### Validation Evidence
- `./vendor/bin/pest tests/Feature/SystemAdmin/TenantProvisioningTest.php`
- Result: 7 tests / 59 assertions passing

#### Governance Note
Story 29.1 exposes and manages existing subscription and feature-gating capabilities through System Admin provisioning. It does not rebuild the feature-gating engine and does not complete full tenant onboarding by itself.

