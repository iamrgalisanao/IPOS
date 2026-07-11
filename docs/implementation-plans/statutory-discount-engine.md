# Implementation Plan: Philippine Statutory Discount Engine (Epic 42)

## 1. Overview
This plan implements a robust, BIR-compliant statutory discount engine for IPOS, benchmarking against StoreHub and Mosaic. The goal is to move beyond simple percentage discounts to a verified identity-based system that handles Senior Citizen, PWD, and Solo Parent discounts with appropriate VAT treatment and audit trails.

## 2. Core Requirements

### 2.1 Discount Categories & Logic
- **Senior Citizen / PWD**: 20% discount + VAT exemption (F&B) or specific BNPC rules (Retail).
- **Solo Parent**: 10% discount on eligible products + VAT exemption.
- **Other Statutory**: National Athletes, Medal of Valor, Diplomats (VAT exemption).
- **Application Modes**:
    - **Standard**: Full bill or eligible portion.
    - **Line-Item**: Specific products eligible for discount.
    - **Portion/Pax**: Discount limited by the number of eligible persons vs total pax.
    - **MEMC**: Most Expensive Meal Combination (F&B specific).

### 2.2 Compliance & Identity
- **Required Metadata**:
    - SC/PWD: Name, ID Number, TIN.
    - Solo Parent: Name, SPIC Number, Child's Name (6y & under).
- **Validation**:
    - Eligible person count $\le$ Total pax count.
    - Product eligibility check (Retail/Pharmacy).
- **Authorization**: Optional Manager PIN approval for special discounts.

## 3. Technical Architecture

### 3.1 Database Schema Extensions

#### `discount_types` (New)
- `id` (UUID), `code` (string), `name` (string)
- `statutory_category` (enum: senior, pwd, solo_parent, other)
- `default_rate` (decimal), `vat_treatment` (enum: exempt, partial, none)
- `requires_identity` (boolean), `requires_approval` (boolean)
- `applies_to_fnb` (boolean), `applies_to_retail` (boolean)

#### `product_discount_eligibility` (New)
- `product_id` (FK), `discount_type_id` (FK)
- `status` (boolean)

#### `sale_discounts` (New/Refactor)
- `id` (UUID), `sale_id` (FK), `discount_type_id` (FK)
- `application_mode` (enum: standard, line_item, portion, memc)
- `base_amount`, `discount_amount`, `vat_exempt_amount`
- `eligible_person_count`, `total_pax_count`
- `approved_by` (FK to User)
- `calculation_snapshot` (json): Stores the exact computation steps (Gross $\rightarrow$ Less VAT $\rightarrow$ Discountable Base $\rightarrow$ Net) to ensure immutability regardless of future rule changes.

#### `sale_discount_beneficiaries` (New)
- `id` (UUID), `sale_discount_id` (FK)
- `beneficiary_name`, `id_number`, `tin`, `spic_number`, `child_name`
- `metadata_json` (for flexible future fields)
- Note: Beneficiary metadata is stored separately from generic customer records to maintain strict compliance audit trails.

### 3.2 Service Layer Logic
- **`StatutoryDiscountService`**:
    - `calculateDiscount(cart, type, metadata)`: Computes the discount based on mode (Standard vs MEMC).
    - `validateEligibility(product, type)`: Checks if a product is eligible for a specific statutory discount.
    - `applyVatExemption(amount, type)`: Handles the "Less VAT" calculation before applying the percentage discount.
    - `snapshotCalculation(saleDiscount)`: Persists the final computation result to the `calculation_snapshot` field.

## 4. Implementation Phases

### Phase 1: Foundation & Data Model
- [ ] Create migrations for `discount_types`, `product_discount_eligibility`, `sale_discounts`, and `sale_discount_beneficiaries`.
- [ ] Seed default Philippine statutory discount types with mandatory reason/type codes (no free-text labels).
- [ ] Implement `StatutoryDiscountService` core calculation logic.

### Phase 2: Discount Calculation Service (Core Engine)
- [ ] Implement the "Gross $\rightarrow$ Less VAT $\rightarrow$ Discountable Base $\rightarrow$ Net" pipeline.
- [ ] Implement logic to prevent combining statutory discounts with regular promos unless explicitly allowed.
- [ ] Implement manager approval thresholds by discount type.

### Phase 3: POS Special Discount Modal
- [ ] Add "Special Discount" trigger in the Checkout UI.
- [ ] Implement "Discount Category" selection (Senior, PWD, Solo Parent).
- [ ] Create identity input forms (Name, ID, TIN) based on selected category.
- [ ] Implement Pax/Portion count controls.
- [ ] Integrate Manager PIN prompt for authorized discounts.

### Phase 4: SaleCreationService Integration
- [ ] Update `SaleCreationService` to process `sale_discounts` and `sale_discount_beneficiaries`.
- [ ] Implement VAT-exempt amount calculation for statutory discounts.
- [ ] Ensure `SaleItem` records link to the applied statutory discounts.

### Phase 5: Receipt & Reporting
- [ ] Update Receipt template to include:
    - "LESS VAT"
    - "DISCOUNTABLE SALES"
    - Specific discount labels (e.g., "SC DISCOUNT")
    - Beneficiary Name and ID (masked).
- [ ] Update Electronic Journal to record statutory discount metadata.
- [ ] Update Z-Reading and Tax Reports to break down statutory vs commercial discounts.

### Phase 6: Manager Approval & Audit Logs
- [ ] Implement role-based access for special discount application.
- [ ] Create immutable audit logs for every statutory discount applied/modified.

### Phase 7: Refund & Void Interaction
- [ ] Implement rules for voiding statutory discounts (must reverse the specific statutory benefit).
- [ ] Implement refund logic for statutory discounts (ensure VAT-exempt portions are handled correctly).

### Phase 8: Automated Compliance Tests
- [ ] Create a comprehensive test suite for all statutory discount scenarios.
- [ ] Add specific test cases for e-journal and Z-reading output accuracy.
- [ ] Validate offline behavior: ensure statutory discounts are online-only or use securely cached rules.

## 5. Validation & Acceptance Criteria

| Criterion | Validation Method |
| :--- | :--- |
| **Identity Requirement** | Attempt to apply SC discount without ID $\rightarrow$ Blocked |
| **Pax Constraint** | Set Pax=2, SC Count=3 $\rightarrow$ Blocked |
| **VAT Exemption** | Verify `vat_exempt_amount` is correctly calculated before discount |
| **Solo Parent Eligibility** | Apply Solo Parent discount to non-eligible product $\rightarrow$ Blocked |
| **Manager Approval** | Apply discount with `requires_approval=true` $\rightarrow$ Prompt PIN |
| **Receipt Accuracy** | Verify "LESS VAT" and "Beneficiary Name" appear on printed SI |
| **Audit Trail** | Verify `sale_discount_beneficiaries` are persisted and immutable |
