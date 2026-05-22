# Epic 28 Phase 2 — CPA/BIR Review Checklist Package

> [!WARNING]
> **POST-DEVELOPMENT REVIEW PACKAGE**
> This package is prepared for post-development CPA/BIR review. Development may proceed as production-grade implementation for controlled early partner adoption, but external compliance claims remain prohibited until final review is completed.

---

## 1. Review Purpose
This package compiles the key architectural decisions, compliance guardrails, and technical trade-offs required for **Controlled Offline Sales (Phase 2)** of the IPOS system. 

Before any code is committed, a certified CPA and BIR compliance consultant must formally review the questions in Section 3 and sign off on the design.

---

## 2. Proposed Offline Model
- **Online-first IPOS**: The system operates primarily in online mode, prioritizing real-time server validation.
- **Level 1 Completed**: Offline-tolerant catalog/cart shell. No official offline sale creation is allowed; checkout is hard-blocked when connectivity is lost.
- **Level 2 Proposed**: Controlled offline sales with server-issued invoice blocks and reconciliation.
- **Level 3 Deferred**: Full local-first branch node containing local relational database engines and device-bound controls.

---

## 3. Decisions Required from CPA/BIR Consultant

### A. Offline Invoice Representation
- Can a terminal print an invoice while offline if the number comes from a server-preallocated block?
- Must the receipt be marked "Pending Sync" or "Provisional"?
- What exact wording must appear on customer-facing offline documents?
- Should offline documents be treated as official invoices or temporary order acknowledgements until sync?

> [!IMPORTANT]
> **Safer Wording / Compliance Rule**:
> Offline transaction representations must be visibly marked as provisional/pending-sync unless and until the CPA/BIR-approved Phase 2 model confirms that pre-allocated offline invoice numbers may be printed as official invoices. Any locally assigned invoice number must come only from a server-reserved, terminal-bound block and must remain subject to server reconciliation.

### B. GCT and Z-Read Treatment
- Should offline sales affect the fiscal day of local sale time or server sync time?
- Can historical Z-read records be adjusted/reopened after delayed sync?
- What audit note is required for late synced transactions?
- Should official GCT update only after server acceptance?

### C. Lost/Revoked Invoice Blocks
- What format should be used for lost/voided/suspended invoice sequence declarations?
- Who must authorize range revocation?
- Should recovered unused ranges remain permanently unavailable?

### D. Local Storage Trust Model
- Is browser IndexedDB acceptable only as provisional queue storage?
- Is a native wrapper or encrypted local database required for official offline selling?
- What minimum device-bound controls are required?

### E. E-Journal and Hash Chain
- Must the e-journal include the full printed invoice text?
- Are local hash chains acceptable as diagnostic tamper-evidence?
- What server-side verification report should be generated?

### F. Decimal and Tax Calculation Parity
- Confirm rounding mode.
- Confirm decimal precision.
- Confirm how PHP server calculations and JavaScript client calculations must be compared.
- Define rejection/reconciliation rules for centavo mismatches.

---

## 4. Technical Risks for Review
- **IndexedDB Tampering**: User exposure to browser developer tools allows direct database mutation. Without local hardware security modules (HSM) or trusted execution environments, client logs can be manually adjusted.
- **Terminal Clock Manipulation**: Users modifying local system clocks can alter transaction occurrence timestamps, corrupting the sequential timeline of Z-reports.
- **Duplicate Invoice Sequences**: Network latency or sync failures could result in overlapping sequence blocks if server-side lock state checks are bypassed.
- **Permanent Cache Loss**: Clearing browser data (cookies/site data) risks losing un-synced offline transactions and sequence metadata.
- **Tax Parity Discrepancies**: Floating point math calculations in JavaScript mismatching database-validated totals.
- **Official vs Provisional Receipt Confusion**: Customers or audit personnel misinterpreting unsynced receipts as official documents before formal server reconciliation.

---

## 5. Engineering Recommendation
Epic 28 Phase 2 is approved for production-grade development for controlled early partner adoption. External CPA/BIR review is deferred until post-development. Marketing or formal compliance claims remain prohibited until review is completed.

---

## 6. CPA/BIR Review Sign-Off

Reviewed by:

- Name:
- Role / License No.:
- Organization:
- Date Reviewed:
- Decision:
  - [ ] Approved for implementation planning
  - [ ] Approved with revisions
  - [ ] Not approved
  - [ ] Requires further BIR/RDO clarification

Reviewer Notes:

