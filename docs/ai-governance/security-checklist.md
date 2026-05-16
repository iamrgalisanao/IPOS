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
+## 5. Shift Operations (Epic 12 Hardening)
+- [x] Denomination-based cash counting validated (Slice 1).
+- [x] High-value cash drop threshold guard (₱5,000) enforced (Slice 2).
+- [x] Manager approval required for threshold-exceeding drops.
+- [x] Cashier self-approval for high-value drops blocked.
+- [x] Tenant/Branch isolation enforced for activeShift endpoint.
+- [x] Immutable drawer event audit trail verified.

---
Last updated: 2026-05-15
Status: **Ready for Go-Live Validation**
