# Current Focus

- **Primary Objective**: Maintain strict alignment between governance, risk matrices, and the active codebase as we transition phases.
- **Recently Closed Focus**:
  - **Epic 40 (Cash Drawer Audit & Manager Shift Reconciliation)**: Verified and ready for deployment. Cash drawer threshold resolution, high-value cash drop manager verification, spot audits, and immutable shift deposits are fully implemented.
  - **Epic 35 (Recipe Maintenance and Costing Engine)**: Verified and ready for deployment. Final Story 35.3 completed with automated POS checkout recipe stock deduction.
  - **Epic 34 (Enterprise Async Reporting Export)**: Verified and ready for deployment. Streamed BIR E-Journal export pipeline with data tracking, local private storage, ProcessDataExportJob queuing, HMAC-SHA-256 integrity, and retention pruning.
  - **Epic 28 Phase 2 (Controlled Offline Sales)**: Implemented and locally validated for controlled early partner pilot readiness; external CPA/BIR review deferred.
  - **Epic 29 (Platform Tenant Provisioning & Subscription Feature Gating)**: Closed, implemented and locally validated; non-blocking residual follow-ups tracked separately.
  - **Epic 41 (POS Terminal Production Hardening for Android Tablet)**: Implemented and locally validated. Terminal identity binding residual hardening is closed; pilot validation and hardware integration review remain operational follow-ups.
  - **G-066**: Full-suite risky/incomplete residual cleanup closed.
- **Open Non-Blocking Follow-Ups**:
  - **Feature-gate residual hardening**: optional full POS shell gating.
  - **POS terminal pilot hardening**: monitor offline queue retry behavior, service-worker shell refresh behavior, and hardware adapter readiness.
- **Immediate Task**: Stabilize POS terminal pilot behavior around controlled offline sales, employee timecards, terminal identity binding, and statutory discount checkout surfaces. Keep catalog import write-path work locked unless separately approved.
