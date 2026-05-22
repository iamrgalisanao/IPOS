# Compliance Register

Last updated: 2026-05-19

## Data Privacy Act Alignment

- Tenant-scoped accounting and QuickBooks data paths were reviewed for tenant isolation controls.
- QuickBooks tokens are encrypted at rest in the application model layer.
- QuickBooks connect and disconnect actions generate audit log records.
- Repository-managed MCP and compose configuration now use environment injection or secure input variables instead of committed secrets.
- Previously committed credentials should still be rotated because historical exposure remains a compliance concern.

---

## Philippine Tax & BIR/EOPT Compliance (Epic 14 Compliance Extension)

- **Status**: `Implemented & Locally Validated — Pending Formal BIR/Accounting Review`
- **Scope**: Steps 1-5 completed.
- **Implemented Controls**:
  - **Sequential Numbering**: gaps-free, machine-scoped invoice sequence persistence.
  - **Reprint Guards**: visible stamp and cashier reason required for duplicate rendering.
  - **Z-Read & GCT State Machine**: atomic daily counters and un-resettable Grand Cumulative Totals locked at shift close.
  - **Training Mode Isolation**: complete database, receipt watermark, and Z-read exclusion for training mode transactions.
  - **Electronic Journal Export**: unified chronologically classified export with row-by-row SHA-256 HMAC diagnostic hash.

> [!WARNING]
> The internal tamper-evident hash is built for internal diagnostic validation and data-tampering audits only. It does not represent an officially accredited rolling-chain compliance schema. Broader BIR/EOPT accreditation readiness remains pending until final report layouts, official machine registration data, and formal BIR/accounting review are completed.

---

## Current Status

- **Identity & Secrets**: `Proceed with Caution` (rotate previously committed credentials before production reliance).
- **Tax & BIR Compliance Extension**: `Implemented & Locally Validated — Pending Formal BIR/Accounting Review`.