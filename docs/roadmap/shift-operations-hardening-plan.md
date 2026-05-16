# Implementation Plan: Shift Operations Hardening (Slice 2) - Cash Drawer Events

## 1. Objective
Implement mid-shift cash drawer operational events (Cash Drops and Cash Top-ups) to ensure drawer integrity without requiring shift closure.

## 2. Scope
- **Cash Drop event**: Record cash removal from drawer (e.g., skim to safe).
- **Cash Top-up event**: Record cash addition to drawer (e.g., change replenishment).
- **Threshold-based Overrides**: Require manager authentication/override for drops exceeding a defined limit.
- **Audit Traceability**: Link events to active shifts, cashiers, and managers.
- **Reporting**: Display event history in the Shift Summary view.

## 3. Out of Scope
- Z-Report generation.
- POS Shift HUD.
- Session pinning / multi-tab enforcement.
- Automatic shift closure/approval.
- Accounting/QuickBooks synchronization for drawer events.

## 4. Existing Architecture Review
- **Model**: `CashDrawerEvent` exists with `event_type`, `amount`, `reason_code`, and immutability guards.
- **Service**: `ShiftService@recordDrawerEvent` implemented with tenant/branch isolation and active shift validation.
- **RBAC**: `manage_cash_drawer` permission defined in `RbacSeeder`.
- **UI**: `Shift/Show.jsx` and `POS/Index.jsx` are ready for integration.

## 5. Technical Strategy

### 5.1 Data Model
`CashDrawerEvent` will be used as is.
- `event_type`: `cash_drop` or `cash_top_up`.
- `amount`: Positive decimal.
- `reason_code`: e.g., `SKIM`, `REPLENISH`, `CORRECTION`.
- `created_by`: The user performing the action (Manager in case of override).

### 5.2 Business Rules
1. **Active Shift Requirement**: Events can only be recorded against shifts with `status = 'open'`.
2. **Impact on Reconciliation**: 
    - `cash_drop` decreases `expected_cash_amount`.
    - `cash_top_up` increases `expected_cash_amount`.
3. **Threshold Guard**: Cash drops > ₱5,000.00 (configurable) require a user with `approve_shift` permission to be the `created_by` or authorize the request.
4. **Immutability**: Once recorded, events cannot be edited or deleted (enforced by model boots).

### 5.3 Route Strategy
- `POST /shifts/drawer-events`: Submit a new event.
  - Required: `shift_id`, `event_type`, `amount`, `reason_code`.
  - Optional: `reason_notes`, `manager_id`.

### 5.4 UI/UX Strategy
- **Component**: `RecordCashEventModal.jsx`
  - Field: Event Type (Toggle: Drop / Top-up).
  - Field: Amount (Numerical input).
  - Field: Reason Code (Dropdown: Skim, replenishment, etc.).
  - Field: Notes (Textarea).
  - Validation: Real-time check against threshold for manager warning.
- **POS Integration**: Add a "Cash Drawer" action button in the POS header.
- **Shift Dashboard**: Add a "Cash Events" table to `Shift/Show.jsx` displaying the audit trail.

## 6. Security & Isolation
- **Tenant Scope**: Enforced by `BelongsToTenant` and `IdentifyBranchContext`.
- **Branch Scope**: Enforced by `IdentifyBranchContext` and `ShiftService` validation.
- **RBAC**: Only users with `manage_cash_drawer` can initiate; only `approve_shift` users can override thresholds.

## 7. Implementation Steps
1. **Controller**: Add `recordDrawerEvent` to `ShiftController.php`.
2. **Service Expansion**: Update `ShiftService@calculateExpectedCash` to include events (already partially implemented).
3. **UI Components**:
   - Create `RecordCashEventModal.jsx`.
   - Update `Shift/Show.jsx` to include the event list.
   - Update `POS/Index.jsx` to include the drawer event trigger.
4. **Validation**: Add focused tests for thresholds and isolation.

## 8. Test Strategy
- **Functional**: Authorized cashier records top-up.
- **Security**: Tenant A cannot record event for Tenant B.
- **Logic**: Drop > Threshold fails without manager.
- **Consistency**: Drop affects reconciliation total correctly.

## 9. Risks & Open Questions
- **Manager PIN**: Do we need a PIN system now or is the current session sufficient if a manager is logged in? (Assumption: Current session role-check is sufficient for Slice 2).
- **Multiple Drops**: Should we track "Skim #"? (Assumption: Timestamp is sufficient).

## 10. Implementation Status
- **Slice 1**: COMPLETED (Denomination-based counting & RBAC).
- **Slice 2**: COMPLETED (Cash Drawer Events & Threshold Guard).
- **Next Slice**: Slice 3 (Shift Dashboard & HUD).

**Slice 2 Validated Logic:**
- Threshold: ₱5,000.00.
- Self-approval blocked.
- Immutable audit trail.
- Mutation-safe reconciliation.
