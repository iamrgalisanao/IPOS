# Current Focus

- **Primary Objective**: Maintain strict alignment between governance, risk matrices, and the active codebase as we transition phases.
- **Recently Closed Focus**:
  - **Epic 40 (Cash Drawer Audit & Manager Shift Reconciliation)**: Verified and ready for deployment. Cash drawer threshold resolution, high-value cash drop manager verification, spot audits, and immutable shift deposits are fully implemented.
  - **Epic 35 (Recipe Maintenance and Costing Engine)**: Verified and ready for deployment. Final Story 35.3 completed with automated POS checkout recipe stock deduction.
  - **Epic 34 (Enterprise Async Reporting Export)**: Verified and ready for deployment. Streamed BIR E-Journal export pipeline with data tracking, local private storage, ProcessDataExportJob queuing, HMAC-SHA-256 integrity, and retention pruning.
  - **Epic 28 Phase 2 (Controlled Offline Sales)**: Implemented and locally validated for controlled early partner pilot readiness; external CPA/BIR review deferred.
  - **Epic 29 (Platform Tenant Provisioning & Subscription Feature Gating)**: Closed, implemented and locally validated; non-blocking residual follow-ups tracked separately.
  - **Epic 41 (POS Terminal Production Hardening for Android Tablet)**: Implemented and locally validated. Terminal identity binding residual hardening is closed; pilot validation and hardware integration review remain operational follow-ups.
  - **POS Terminal Pilot Hardening Checkpoint (2026-07-11)**: Documentation, UAT, queue diagnostics, offline cash capture, route/session hardening, terminal identity recovery messaging, and clean repository baseline are aligned to checkpoint commit `6c2b5d0`.
  - **POS Admin Configuration & Terminal Capability Backlog (2026-07-11)**: Benchmark review accepted as a planning reference. Admin Config Snapshot Foundation slice is implemented and locally validated; admin-facing product surfaces remain backlog.
  - **Epic 38 (F&B Table & Bill Operations)**: Closed, implemented and locally validated. Architecture lock, story implementation guide, story specs, and ADR index are reconciled with the validated roadmap.
  - **G-066**: Full-suite risky/incomplete residual cleanup closed.
- **Open Non-Blocking Follow-Ups**:
  - **Feature-gate residual hardening**: optional full POS shell gating.
  - **POS terminal UAT/release gate**: execute `docs/validation/pos-terminal-offline-uat-2026-07-11.md` using the clean checkpoint as the baseline.
  - **Admin configuration planning**: plan the next product-facing slice after the Config Snapshot foundation, likely snapshot/version audit visibility or register activation/config download UX.
  - **Hardware validation**: receipt printer and cash drawer physical validation is deferred until devices are available; do not claim hardware readiness before that evidence exists.
- **Immediate Task**: Execute POS terminal offline UAT and release-gate review without hardware-dependent cases. After UAT, choose the next admin-configuration product-surface slice using the implemented Config Snapshot foundation. Keep hardware integration blocked/deferred until printer and drawer devices are available, and keep catalog import write-path work locked unless separately approved.
