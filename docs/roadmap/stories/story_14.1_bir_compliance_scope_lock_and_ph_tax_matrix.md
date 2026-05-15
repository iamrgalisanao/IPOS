# Story 14.1: BIR Compliance Scope Lock and PH Tax Matrix

Status: Completed 2026-05-13

Implementation status summary:

- Slice 1: Completed 2026-05-13
- Slice 2: Completed 2026-05-13
- Slice 3: Completed 2026-05-13
- Slice 4: Completed 2026-05-13
- Slice 5: Completed 2026-05-13

Closure note:

- Story 14.1 is complete as a scope-lock and planning artifact.
- It does not claim Epic 14 runtime implementation is complete.
- Story 14.2 is the next execution-ready implementation story once release-readiness work allows Epic 14 feature delivery.

## Goal

Freeze the Phase 1 Philippines tax and compliance reporting contract so Epic 14 implementation can extend the current POS and reporting model without making unsupported BIR certification claims or introducing ambiguous tax math.

This scope lock must also align the repository with the practical BIR implementation baseline for POS software: invoice-first output, statutory discount evidence, mixed-tax breakdowns, and audit-ready transaction records.

## Why This Story Is Next

Repository evidence shows Epic 14 is the right next feature-delivery candidate once release-readiness blockers are cleared:

- tax categories already exist, but only as generic `vatable`, `exempt`, `zero-rated`, and `non-vat` classifications
- sale creation already snapshots line-level tax category, rate, and amount
- reporting and export surfaces already exist for settlement and accounting review
- discount handling in POS sale creation is still a placeholder, which means PH compliance treatment must be locked before discount implementation widens financial behavior

Current anchor surfaces:

- `app/Services/ConfigurationService.php`
- `app/Models/TaxCategory.php`
- `app/Services/POS/SaleCreationService.php`
- `app/Services/POS/PaymentRecordingService.php`
- `app/Services/POS/ReceiptService.php`
- `app/Services/Settlement/SettlementSummaryQueryService.php`
- `app/Services/Settlement/SettlementExportService.php`

## BIR Research Baseline

Epic 14 planning should follow the strongest repository-relevant implementation signals gathered from current BIR and practitioner material:

- RMO 24-2023 treats CRM, POS, and similar invoice-generating software as regulated sales machines/software that require accreditation and registration through Enhanced eAccReg.
- The same order expects machine and invoice outputs to carry machine and permit identifiers, mixed-sale VAT breakdowns, and statutory discount details when applicable.
- Current PH invoicing practice after the EOPT transition is invoice-first, with Official Receipts treated as supplementary proof of payment rather than primary proof of sale.
- SC/PWD and similar statutory discounts are not just pricing rules; they are compliance evidence requiring beneficiary identifiers, discount breakdowns, and VAT-exemption handling.
- Industry-standard BIR-ready POS implementations consistently include X/Z readings, e-journal style transaction evidence, reprint traceability, and discount reports or books that can survive audit review.

## Scope Review Outcome

Epic 14 should start with a strict scope-lock story, not immediate reporting UI work.

Reasoning:

1. The repository already persists enough baseline tax data to support a compliance contract, but not enough normalized detail to safely answer PH-specific reporting questions.
2. Senior/PWD and discount treatment are not yet represented as immutable reporting concepts in the sale model.
3. The receipt and invoice contract is still generic and does not yet reflect BIR-oriented invoice output requirements.
4. Query, export, and UI layers should consume a locked compliance matrix instead of inventing rules independently.

## Implementation Boundaries

Implement only:

1. Phase 1 compliance scope definition for PH tax reporting.
2. Source-of-truth matrix for tax classes and discount treatment.
3. Explicit invoice and receipt contract boundaries for BIR-oriented POS output.
4. Explicit reporting boundaries for sales, voids, refunds, and reopened periods.
5. Immutable terminology and data-contract decisions that later stories must reuse.
6. Acceptance criteria and slice order for Stories 14.2 through 14.6.

Do not implement yet:

- BIR certification claims
- actual eAccReg/PTU/ATG submission workflows
- fiscal device or e-invoicing integrations
- final tax-report UI screens
- export file generation
- live discount engine logic
- broad tax-category CRUD redesign
- retroactive mutation of historical sales

## Current Repository Constraints

### Existing capabilities

- `TaxCategory` supports tenant-scoped tax metadata with `code`, `name`, `tax_type`, and `rate`.
- `SaleCreationService` snapshots line-level tax category, tax type, tax rate, and tax amount.
- `PaymentRecordingService` groups sale tax payloads by tax category for downstream accounting normalization.
- `ReceiptService` exposes branch code, business registration number, item lines, and sale totals using immutable sale and payment records.
- settlement reporting and export services already provide a pattern for read-only summaries and controlled export authorization.

### Gaps this story must lock before implementation widens

- no PH compliance matrix for VAT sale buckets versus discount buckets
- no immutable distinction yet for senior/PWD discount treatment
- no approved invoice-first contract for POS output after the OR-to-invoice transition
- no machine-profile, permit, or invoice-series contract for BIR-oriented receipt output
- no locked policy for whether discount amounts reduce gross, taxable, exempt, or zero-rated bases in reporting outputs
- no explicit policy for how voids and refunds adjust prior tax-report periods
- no beneficiary evidence model for statutory discount claims
- no approved audit-report boundary for X/Z-like readings, e-journal exports, or statutory discount books
- no approved language separating internal reporting support from formal BIR compliance claims

## Phase 1 Compliance Matrix To Lock

Story 14.1 must freeze the repository-wide interpretation of these reporting buckets:

1. VATable sales
2. VAT-exempt sales
3. Zero-rated sales
4. Non-VAT sales if retained as a distinct operational bucket
5. senior citizen discount treatment
6. PWD discount treatment
7. other statutory discount treatment deferred or excluded from Phase 1
8. commercial or operational discount treatment that is not part of Phase 1 compliance reporting
9. void and refund reversal treatment
10. reopened-period disclosure rules

Story 14.1 must also freeze the Phase 1 invoice and evidence buckets:

1. principal invoice versus supplementary proof-of-payment output
2. required seller and branch identity fields
3. machine-profile and permit identifiers to be modeled in the repository even if BIR registration workflows are deferred
4. statutory discount beneficiary evidence required on stored records and generated outputs
5. audit-ready transaction evidence versus later export-package outputs

For each bucket, define:

- source transaction types included
- whether the amount affects gross sales, net sales, taxable base, tax due, or disclosure-only totals
- whether the bucket requires new persisted fields in Story 14.2
- whether the bucket is Phase 1 supported, deferred, or explicitly out of scope

## Required Decisions

Story 14.1 must explicitly answer these questions:

1. Which existing `tax_type` values remain valid for Epic 14, and which require PH-specific aliasing or additional metadata?
2. Is senior/PWD treatment modeled as a tax classification, a discount classification, or a combined reporting concept?
3. Which transaction timestamp is the reporting source of truth for tax summaries: sale creation, payment completion, shift close, or settlement lock?
4. How do partial refunds and sale voids affect tax summaries for open versus already reviewed periods?
5. Which roles may access PH tax reporting and export surfaces?
6. Which invoice fields must be persisted as immutable sale evidence versus rendered dynamically from current tenant or branch config?
7. Which machine-profile fields are required in the repository contract even if formal accreditation and permit workflows remain out of scope?
8. Which outputs are internal compliance-support reports versus externally asserted BIR-ready deliverables?

## Story 14.1 Deliverables

1. A locked PH tax matrix with repository-safe terminology.
2. A locked invoice and statutory discount evidence contract for downstream POS and reporting work.
3. A source-of-truth section for downstream read models and exports.
4. Explicit non-goals and deferred compliance scope.
5. Acceptance criteria for Story 14.2 data hardening.
6. Acceptance criteria for Story 14.3 query service.
7. Acceptance criteria for Story 14.4 through 14.6 so UI and lock behavior do not drift.

## Design Guardrails

- Reuse existing sale and tax snapshot patterns where possible.
- Treat historical sales as immutable evidence; corrections must be additive or explicitly reversible.
- Keep PH compliance reporting read-only until a later story explicitly introduces lock workflow behavior.
- Do not let reporting logic derive financial truth from QuickBooks or any external provider.
- Keep statutory discounts and commercial promotions as separate concepts even if both eventually affect totals.
- Preserve room for BIR-required machine and permit identifiers without forcing the repository to claim accreditation workflows it does not yet implement.
- Do not merge operational discount governance into this story; Epic 19 owns discount-engine behavior.

## Required Test Planning Coverage

Planning-level coverage to define before Story 14.2 begins:

- line-item examples for VATable, exempt, zero-rated, and non-VAT products
- example transactions with senior and PWD treatment
- example invoice outputs with mixed VATable, exempt, and zero-rated lines
- example invoice outputs with statutory beneficiary metadata and discount breakdowns
- examples covering operational discounts that must remain out of Phase 1 compliance totals unless explicitly approved
- refund and void examples showing reversal impact
- reopened-period examples proving historical outputs remain explainable
- examples distinguishing principal invoice evidence from supplementary payment acknowledgment output

## Implementation Slice Order

### Slice 1: Compliance Vocabulary Lock

Define the canonical PH tax vocabulary, supported tax buckets, invoice-first terminology, and approved repository wording.

### Slice 2: Transaction and Reporting Boundary Lock

Define which transaction states, timestamps, reversal events, and invoice issuance events contribute to compliance reporting.

### Slice 3: PH Tax Matrix Examples

Document representative examples for VAT, exempt, zero-rated, non-VAT, senior/PWD, statutory discount, and invoice evidence scenarios.

### Slice 4: Downstream Story Contracts

Write the explicit data-contract expectations for Stories 14.2 through 14.6, including invoice fields, beneficiary evidence, and reporting buckets.

### Slice 5: Approval and Attestation

Record the approved scope note that Epic 14 implementation is internal compliance-support functionality, not a blanket BIR certification claim.

## Exit Criteria

Story 14.1 is complete when:

- Epic 14 has a locked Phase 1 compliance scope
- tax and discount terminology is unambiguous
- invoice and statutory discount evidence requirements are unambiguous
- downstream stories have explicit contracts
- unsupported BIR claims are explicitly excluded
- the repository has a single approved PH tax-reporting interpretation to implement against

## Story 14.1 Closure Attestation

Story 14.1 is complete.

The repository now has a locked Epic 14 planning baseline covering:

- Phase 1 PH tax vocabulary and reporting boundaries
- invoice-first and statutory discount evidence boundaries
- explicit separation between internal compliance-support functionality and formal BIR certification claims
- downstream implementation contracts for Stories 14.2 through 14.6

This closure marks planning completion only. Runtime implementation begins with Story 14.2.