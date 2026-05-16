# IPOS Security Checklist

## 1. Credential Governance (G-009)
- [x] Phase 1: Hygiene Audit (Confirmed zero tracked secrets).
- [x] Phase 2: Credential Reinjection Workflow (CLI-only implemented and validated).
- [x] Phase B CLI Tool Closure: COMPLETE (Verified redaction, audit, and auth).
- [ ] Phase 3: Validation (Awaiting HITL reinjection for non-local environments).

### Credential Management Tooling
- **Artisan Command**: `php artisan credentials:inject`
- **Supported Categories**: `database`, `redis`, `mail_provider`, `slack`, `hermes`, `quickbooks_sandbox`.
- **Status**: [Operational]

## 2. Sensitive Data Protection
- [x] `SupportPayloadMasker` integrated in support assisted mode.
- [x] Audit logging metadata-only for credential updates.
- [x] Production secret exposure tests passing.

## 3. Production Hardening
- [x] Security headers middleware implemented.
- [x] Sensitive route protection verified.
- [x] Production configuration guard implemented.

## 4. Export & Audit (Epic 15)
- [x] CSV Formula Injection Protection verified (Prefix sanitizer).
- [x] Tenant/Branch isolation enforced in export query builder.
- [x] Redaction of internal POS payloads and secrets from CSV exports.
- [x] Export events recorded in append-only AuditLog.
+

## 5. Shift Operations (Epic 12 Hardening)
- [x] Denomination-based cash counting validated (Slice 1).
- [x] High-value cash drop threshold guard (₱5,000) enforced (Slice 2).
- [x] Manager approval required for threshold-exceeding drops.
- [x] Cashier self-approval for high-value drops blocked.
- [x] Tenant/Branch isolation enforced for activeShift endpoint.
- [x] Immutable drawer event audit trail verified.
- [x] Blind Reconciliation enforced in HUD (Sensitive fields omitted for cashiers).
- [x] Manager Live Operations Monitor restricted to authorized branch scope.
- [x] Active Shift API resolves context safely via branch middleware.

## 6. POS Layout Builder (Epic 22)
- [x] Layout customization explicitly blocked from mutating pricing, tax, or inventory (Slice A).
- [x] Schema validation blocks unsafe fields (`price`, `tax`, `inventory`, `discount`).
- [x] Permission-based access (`pos-layouts.view/manage/publish`) enforced via RBAC and Controller-level authorization (Slice B).
- [x] Immutable status locks for `published` and `archived` layouts prevent unauthorized terminal configuration changes.
- [x] Admin CRUD endpoints strictly follow tenant isolation guardrails.
- [x] Terminal fallback to default rendering for invalid/missing layouts (Slice C).
- [x] Layout rendering uses source-of-truth Catalog pricing/tax/stock (Slice C).
- [x] POS search/category filters correctly bypass layout mode for usability (Slice C).
- [ ] Publishing enforcement (one active layout per branch) planned for Slice E.

---
Last updated: 2026-05-15
Status: **Ready for Go-Live Validation**
