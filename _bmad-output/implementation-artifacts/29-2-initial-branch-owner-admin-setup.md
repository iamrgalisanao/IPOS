# Story 29.2: Initial Branch & Owner Admin Setup

**Epic:** 29 — Platform Tenant Provisioning & Compliance Onboarding
**Story Key:** 29-2
**Status:** Ready for Implementation
**Created:** 2026-05-20

---

## 1. Story Foundation & Business Context

### User Story Statement
**As a** System Administrator using the Platform Admin dashboard,
**I want to** create an initial branch and assign the first Owner/Admin user to a newly provisioned tenant,
**so that** the tenant organization can begin operational onboarding with proper governance structure and access control.

### Business Value
- Enables tenant organizations to progress from company provisioning (Story 29.1) to operational readiness
- Establishes clear ownership structure with the first Owner/Admin role
- Prevents duplicate initialization mistakes and bootstrap errors
- Provides transparent onboarding progress visibility to system administrators
- Creates audit trail of tenant initialization workflow

### Dependencies & Blocking Conditions
- **Depends On:** Story 29.1 (Platform Tenant Provisioning Foundation) — ✅ **COMPLETED**
- **Blocked By:** Story 29.1A (Feature Gate Enforcement) — ✅ **UNBLOCKED** (residual gaps deferred)
- **No Dependencies On:** Story 29.3+ (downstream stories can proceed independently)

### Success Metrics
1. Initial branch creation workflow fully functional and tested
2. Owner/Admin user creation and role assignment working end-to-end
3. Zero regression in tenant isolation or multi-tenant scoping
4. Onboarding progress accurately reflected in system state
5. All edge cases (duplicate owner, missing branch, orphaned users) handled gracefully

---

## 2. Technical Requirements & Architecture Integration

### Prerequisite Tech Stack
**Framework & Foundation:**
- Laravel 11.x (existing)
- Inertia + React 18 (existing)
- PostgreSQL 14+ (existing)
- Multi-tenant context middleware (existing, from Story 29.1)
- Permission model (RBAC + gates, existing from Epic 2)
- Subscription feature-gating (existing from Story 29.1A)

**Dependencies Not Changed:**
- Entitlement engine (unchanged from Story 29.1)
- Billing system (unchanged)
- Feature-gate engine (unchanged)
- Offline reconciliation (unchanged)

### Database Schema Requirements

#### Existing Tables (No Changes)
- `companies` — Tenant representation (created in Story 29.1)
- `branches` — Branch records (already in schema)
- `users` — User records with multi-tenant support (already in schema)
- `roles` — RBAC roles (already in schema)
- `role_user` — User-role junction table (already in schema)
- `permissions` — Permission definitions (already in schema)
- `role_permission` — Role-permission junction table (already in schema)

#### New Tables (Required)
**Onboarding State Table: `company_onboarding_state`**
```sql
CREATE TABLE company_onboarding_state (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL UNIQUE REFERENCES companies(id),
    status ENUM('provisioned', 'branch_created', 'owner_assigned', 'ready') DEFAULT 'provisioned',
    initial_branch_id BIGINT REFERENCES branches(id),
    owner_user_id BIGINT REFERENCES users(id),
    owner_email VARCHAR(255),
    bootstrap_token VARCHAR(255) UNIQUE,
    bootstrap_token_expires_at TIMESTAMP,
    bootstrap_attempts INT DEFAULT 0,
    bootstrap_locked_until TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    completed_at TIMESTAMP NULL
);
```

**Onboarding Event Log Table: `company_onboarding_events`**
```sql
CREATE TABLE company_onboarding_events (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    event_type ENUM('branch_created', 'owner_created', 'owner_assigned', 'bootstrap_token_generated', 'bootstrap_token_used', 'bootstrap_failed'),
    event_data JSON,
    created_at TIMESTAMP
);
```

#### Foreign Key & Constraints
- `company_onboarding_state.company_id` — Unique constraint ensures one onboarding state per company
- `company_onboarding_state.initial_branch_id` — Nullable initially, set when branch is created
- `company_onboarding_state.owner_user_id` — Nullable initially, set when owner is assigned
- `company_onboarding_events.company_id` — Indexed for audit trail queries

### Feature-Gate Integration
- **Gate Used:** `catalog.view` (from Story 29.1A) — does NOT impact Story 29.2
- **No New Gates Required:** Story 29.2 does not introduce new feature gates
- **No Subscription Changes:** All onboarding workflows operate at tenant/company level, not subscription level
- **Residual Gaps:** Form route gating (Slice B2), POS read gates (Slice C), POS shell gate (Slice D) remain deferred and do not impact Story 29.2

### Permission Model Integration

#### Required Roles
- **Owner** — Existing RBAC role; highest privilege within tenant
- **Admin** — Existing RBAC role; administrative privileges within tenant
- **system_admin** — Platform-level role for System Administrators (may need verification)

#### Required Permissions
- `manage_company` — Needed to view/edit company onboarding state
- `create_branches` — Needed to create initial branch
- `create_users` — Needed to create owner user
- `assign_roles` — Needed to assign Owner/Admin roles

#### Layering Strategy
- **Layer 1 (Fail-Closed):** Tenant isolation middleware ensures onboarding data scoped to target tenant
- **Layer 2 (RBAC):** Permission checks ensure only authorized users can initiate onboarding
- **Layer 3 (Feature Gates):** Subscription gates (if applicable) prevent feature access for non-entitled tenants
- **Layer 4 (Business Logic):** Domain-level validations prevent invalid state transitions

### API Patterns & Endpoint Structure

#### System Admin Dashboard Endpoints

**1. Get Onboarding Progress**
```
GET /admin/system/tenants/{company_id}/onboarding-state
Response:
{
  "company_id": 123,
  "status": "provisioned|branch_created|owner_assigned|ready",
  "initial_branch": { "id": 456, "name": "Main Branch", "created_at": "..." },
  "owner_user": { "id": 789, "email": "owner@company.com", "name": "..." },
  "progress_percentage": 0-100,
  "next_action": "Create initial branch|Assign owner user|...",
  "bootstrap_token": "token_xyz" (if not yet used),
  "can_proceed": true|false,
  "block_reason": null|"string"
}
```

**2. Create Initial Branch**
```
POST /admin/system/tenants/{company_id}/create-initial-branch
Request:
{
  "branch_name": "Main Branch",
  "branch_code": "MB-001",
  "location": "HQ" (optional)
}
Response:
{
  "success": true,
  "branch": { "id": 456, "name": "Main Branch", "company_id": 123, ... },
  "onboarding_state": { "status": "branch_created", ... }
}
Error Cases:
- 403 Forbidden: User lacks manage_company permission
- 409 Conflict: Branch already created for this company
- 422 Unprocessable: Invalid branch name or code already in use globally
```

**3. Create Owner User & Bootstrap Token**
```
POST /admin/system/tenants/{company_id}/create-owner-user
Request:
{
  "email": "owner@company.com",
  "first_name": "John",
  "last_name": "Doe",
  "phone": "+1234567890" (optional),
  "send_bootstrap_link": true (default)
}
Response:
{
  "success": true,
  "user": { "id": 789, "email": "owner@company.com", ... },
  "bootstrap_token": "bootstrap_token_xyz",
  "bootstrap_link": "https://app.ipos.local/bootstrap/token_xyz",
  "onboarding_state": { "status": "owner_assigned", ... }
}
Error Cases:
- 403 Forbidden: User lacks create_users permission
- 409 Conflict: Owner user already exists for this company
- 422 Unprocessable: Invalid email format or email already in use globally
- 429 Too Many Requests: Too many bootstrap attempts (rate limit)
```

**4. Get Bootstrap Progress**
```
GET /admin/system/tenants/{company_id}/bootstrap-progress
Response:
{
  "company_id": 123,
  "owner_email": "owner@company.com",
  "bootstrap_status": "pending|used|expired",
  "bootstrap_sent_at": "2026-05-20T10:00:00Z",
  "bootstrap_expires_at": "2026-05-27T10:00:00Z",
  "owner_activated_at": null|"2026-05-20T11:00:00Z",
  "can_resend": true|false,
  "resend_available_in": 0|N (seconds)
}
```

#### Bootstrap/Reset Link Endpoints (Public Routes)

**5. Bootstrap Page Render**
```
GET /bootstrap/{bootstrap_token}
Response: React component with:
- Owner user info (pre-filled from onboarding state)
- Password setup form
- Company info (read-only)
- Initial branch info (read-only)
Error Cases:
- 404 Not Found: Invalid token
- 410 Gone: Token expired
- 409 Conflict: Already activated
```

**6. Complete Bootstrap**
```
POST /bootstrap/{bootstrap_token}/complete
Request:
{
  "password": "new_password",
  "password_confirmation": "new_password",
  "timezone": "Asia/Manila",
  "language": "en"
}
Response:
{
  "success": true,
  "message": "Bootstrap complete. Please log in.",
  "redirect_to": "/login"
}
Error Cases:
- 404 Not Found: Invalid token
- 410 Gone: Token expired
- 409 Conflict: Already activated
- 422 Unprocessable: Password too weak, passwords don't match
```

### File & Folder Structure

#### New Files to Create

**Controllers:**
```
app/Http/Controllers/SystemAdmin/CompanyOnboardingController.php
  - getOnboardingState(company_id)
  - createInitialBranch(company_id, BranchRequest)
  - createOwnerUser(company_id, UserRequest)
  - getBootstrapProgress(company_id)
  - resendBootstrapLink(company_id)

app/Http/Controllers/Auth/BootstrapController.php
  - showBootstrapForm(token)
  - completeBootstrap(token, BootstrapRequest)
```

**Models & Services:**
```
app/Models/CompanyOnboardingState.php (new model)
app/Models/CompanyOnboardingEvent.php (new model)
app/Services/OnboardingService.php (new service)
  - initializeOnboardingState(company)
  - createInitialBranch(company, branchData)
  - createOwnerUser(company, userData)
  - generateBootstrapToken(user)
  - validateBootstrapToken(token)
  - completeBootstrap(token, passwordData)
  - recordOnboardingEvent(company, eventType, eventData)
```

**Requests (Validation):**
```
app/Http/Requests/CreateInitialBranchRequest.php
app/Http/Requests/CreateOwnerUserRequest.php
app/Http/Requests/CompleteBootstrapRequest.php
```

**Migrations:**
```
database/migrations/2026_05_20_000000_create_company_onboarding_state_table.php
database/migrations/2026_05_20_000001_create_company_onboarding_events_table.php
database/migrations/2026_05_20_000002_create_onboarding_indices.php
```

**Routes:**
```
routes/web.php — Add new routes under /admin/system prefix
routes/api.php — Add new API routes (if needed)
routes/auth.php — Add bootstrap routes (public, no auth required)
```

**Frontend Components (React):**
```
resources/js/Pages/SystemAdmin/CompanyOnboarding/Index.jsx
resources/js/Pages/SystemAdmin/CompanyOnboarding/Show.jsx
resources/js/Pages/SystemAdmin/CompanyOnboarding/CreateBranch.jsx
resources/js/Pages/SystemAdmin/CompanyOnboarding/CreateOwner.jsx
resources/js/Pages/Auth/Bootstrap.jsx
resources/js/Components/OnboardingProgress.jsx
```

**Tests:**
```
tests/Feature/SystemAdmin/CompanyOnboardingTest.php
tests/Feature/Auth/BootstrapTest.php
tests/Unit/Services/OnboardingServiceTest.php
tests/Unit/Models/CompanyOnboardingStateTest.php
```

---

## 3. Implementation Details & Acceptance Criteria

### AC 1: Initial Branch Creation Workflow

**Scenario 1A: System Admin Creates Initial Branch**
```gherkin
Given a newly provisioned company "Acme Corp" exists with no branches
  And the System Admin has permission:manage_company
When the System Admin navigates to /admin/system/tenants/123/onboarding
Then the System Admin sees:
  - Current status: "Provisioned"
  - Action button: "Create Initial Branch"
  - Form fields: branch_name, branch_code, location (optional)

When the System Admin fills branch_name="Main Branch", branch_code="MB-001"
  And clicks "Create"
Then the response is 200 OK
  And the database contains:
    - branches.id = 456 (new record)
    - branches.company_id = 123
    - branches.name = "Main Branch"
    - branches.branch_code = "MB-001"
  And company_onboarding_state.initial_branch_id = 456
  And company_onboarding_state.status = "branch_created"
  And company_onboarding_events includes event:
    - event_type = "branch_created"
    - event_data = { branch_id: 456, branch_name: "Main Branch", ... }
```

**Scenario 1B: Prevent Duplicate Branch Creation**
```gherkin
Given company "Acme Corp" already has initial_branch_id set in onboarding_state
When the System Admin tries to POST /admin/system/tenants/123/create-initial-branch
Then the response is 409 Conflict
  And the error message is: "Initial branch already created for this company"
  And no new branch record is created
```

**Scenario 1C: Prevent Invalid Branch Names**
```gherkin
Given a company "Acme Corp" with no branches
When the System Admin posts with branch_name="" or null
Then the response is 422 Unprocessable Entity
  And the error includes: "branch_name is required"

When the System Admin posts with branch_code that already exists globally
Then the response is 422 Unprocessable Entity
  And the error includes: "branch_code already in use"
```

**Scenario 1D: Tenant Isolation Verified**
```gherkin
Given Company A and Company B both in the system
  And System Admin 1 is assigned to Company A only
When System Admin 1 tries to POST /admin/system/tenants/{company_b_id}/create-initial-branch
Then the response is 403 Forbidden
  And no branch is created for Company B
```

### AC 2: Owner User Creation & Bootstrap Token Workflow

**Scenario 2A: System Admin Creates Owner User with Bootstrap Link**
```gherkin
Given company "Acme Corp" with initial_branch_id = 456 already set
  And company_onboarding_state.status = "branch_created"
When the System Admin navigates to /admin/system/tenants/123/onboarding
Then the System Admin sees:
  - Current status: "Branch Created"
  - Action button: "Create Owner User"
  - Form fields: email, first_name, last_name, phone (optional), send_bootstrap_link (checkbox, checked by default)

When the System Admin fills:
  - email = "john.doe@acme.com"
  - first_name = "John"
  - last_name = "Doe"
  - send_bootstrap_link = true
  And clicks "Create"
Then the response is 200 OK
  And the database contains:
    - users.id = 789 (new record)
    - users.email = "john.doe@acme.com"
    - users.company_id = 123
    - users.first_name = "John"
    - users.last_name = "Doe"
  And role_user contains:
    - user_id = 789
    - role_id = <Owner role id>
    - tenant_id = 123
  And company_onboarding_state.owner_user_id = 789
  And company_onboarding_state.status = "owner_assigned"
  And company_onboarding_state.owner_email = "john.doe@acme.com"
  And company_onboarding_state.bootstrap_token is generated (non-null, unique)
  And company_onboarding_state.bootstrap_token_expires_at = now + 7 days
  And company_onboarding_events includes event:
    - event_type = "owner_created"
    - event_data = { user_id: 789, email: "john.doe@acme.com", ... }
  And company_onboarding_events includes event:
    - event_type = "bootstrap_token_generated"
    - event_data = { user_id: 789, expires_at: "...", ... }
  And an email is sent to john.doe@acme.com with:
    - Subject: "Welcome to IPOS — Complete Your Setup"
    - Body contains: "https://app.ipos.local/bootstrap/{bootstrap_token}"
    - CTA: "Complete Your Setup"
```

**Scenario 2B: Prevent Duplicate Owner User**
```gherkin
Given company "Acme Corp" already has owner_user_id set in onboarding_state
When the System Admin tries to POST /admin/system/tenants/123/create-owner-user
Then the response is 409 Conflict
  And the error message is: "Owner user already assigned for this company"
  And no new user is created
```

**Scenario 2C: Prevent Email Duplicates Globally**
```gherkin
Given a user with email "john.doe@elsewhere.com" already exists in system
When the System Admin tries to create owner with email="john.doe@elsewhere.com" for Company A
Then the response is 422 Unprocessable Entity
  And the error includes: "email already in use by another user"
```

**Scenario 2D: Send Bootstrap Link Without Creating User**
```gherkin
Given company "Acme Corp" with owner_user_id = 789 already set
  And owner hasn't completed bootstrap yet
When the System Admin navigates to /admin/system/tenants/123/onboarding
Then the System Admin sees:
  - Current status: "Owner Assigned"
  - Button: "Resend Bootstrap Link"

When System Admin clicks "Resend Bootstrap Link"
Then a new bootstrap_token is generated
  And company_onboarding_state.bootstrap_token is updated
  And company_onboarding_state.bootstrap_token_expires_at is reset to now + 7 days
  And an email is sent to owner_email with new bootstrap link
  And response indicates: "Bootstrap link sent to owner@company.com"
```

**Scenario 2E: Rate Limiting on Bootstrap Attempts**
```gherkin
Given a bootstrap token for owner user
When the owner completes bootstrap with wrong password 5 times
Then after 5th attempt:
  - Response is 429 Too Many Requests
  - company_onboarding_state.bootstrap_locked_until = now + 15 minutes
  - Further bootstrap attempts are blocked until timeout

When the 15-minute timeout expires
Then bootstrap attempts counter resets
  And new attempts are allowed
```

### AC 3: Bootstrap Token Validation & Completion

**Scenario 3A: Owner Completes Bootstrap Successfully**
```gherkin
Given a valid, unexpired bootstrap_token for owner user john.doe@acme.com
When the owner navigates to /bootstrap/{bootstrap_token}
Then the page displays:
  - Pre-filled owner name (read-only): "John Doe"
  - Company name (read-only): "Acme Corp"
  - Initial branch name (read-only): "Main Branch"
  - Password setup form: password, password_confirmation, password strength indicator
  - Timezone selector (default: from env or browser detection)
  - Language selector (default: "en")

When the owner enters:
  - password = "SecurePass123!"
  - password_confirmation = "SecurePass123!"
  - timezone = "Asia/Manila"
And clicks "Complete Setup"
Then the request is posted to POST /bootstrap/{bootstrap_token}/complete
  And the response is 200 OK
  And the database is updated:
    - users.password = bcrypt("SecurePass123!")
    - users.timezone = "Asia/Manila"
    - users.email_verified_at is set to now
    - users.status = "active"
  And company_onboarding_state.bootstrap_token is cleared/nullified
  And company_onboarding_state.status = "ready"
  And company_onboarding_state.completed_at = now
  And company_onboarding_events includes event:
    - event_type = "bootstrap_token_used"
    - event_data = { user_id: 789, completed_at: "..." }
  And the response indicates: "Setup complete. Redirecting to login..."
  And user is redirected to /login

When owner logs in with email="john.doe@acme.com" password="SecurePass123!"
Then authentication succeeds
  And user has Owner role scoped to company 123
```

**Scenario 3B: Prevent Bootstrap with Invalid Token**
```gherkin
Given an invalid/non-existent bootstrap_token
When the owner navigates to /bootstrap/invalid_token
Then the page displays: "Invalid or expired bootstrap link"
  And no form is shown

When the owner navigates to POST /bootstrap/invalid_token/complete
Then the response is 404 Not Found
  And the error message is: "Bootstrap token not found"
```

**Scenario 3C: Prevent Bootstrap with Expired Token**
```gherkin
Given a bootstrap_token where bootstrap_token_expires_at < now
When the owner navigates to /bootstrap/{expired_token}
Then the page displays: "This bootstrap link has expired. Please contact your system administrator."

When the owner tries to POST /bootstrap/{expired_token}/complete
Then the response is 410 Gone
  And the error message is: "Bootstrap link has expired"
```

**Scenario 3D: Prevent Bootstrap if Already Completed**
```gherkin
Given an owner user who has already completed bootstrap (bootstrap_token is null)
  And company_onboarding_state.status = "ready"
When the owner navigates to /bootstrap/{any_token}
Then the page displays: "This company has already been set up."
  And a link to login is shown
```

**Scenario 3E: Password Validation**
```gherkin
Given the bootstrap form is displayed
When the owner enters password = "weak" (too short)
Then the error is shown: "Password must be at least 8 characters"
  And the form is not submitted

When the owner enters password = "password" (no special chars)
Then the error is shown: "Password must contain letters, numbers, and special characters"

When passwords don't match (password ≠ password_confirmation)
Then the error is shown: "Passwords do not match"
```

### AC 4: Onboarding Progress Visibility

**Scenario 4A: System Admin Sees Progress Dashboard**
```gherkin
Given company "Acme Corp" in various onboarding states
When System Admin navigates to /admin/system/onboarding-progress
Then a list of all companies is shown with status column:
  - Company A: "Provisioned" (0%)
  - Company B: "Branch Created" (33%)
  - Company C: "Owner Assigned" (66%)
  - Company D: "Ready" (100%)

When System Admin clicks on Company B
Then detailed onboarding state is displayed:
  - Company: "Acme Corp"
  - Status: "Branch Created"
  - Initial branch: "Main Branch (MB-001)"
  - Owner user: [not yet assigned]
  - Next action: "Create owner user"
  - Timeline of onboarding events shown
```

**Scenario 4B: On Tenant Detail Page**
```gherkin
Given System Admin viewing /admin/system/tenants/123
Then an "Onboarding Progress" card is displayed showing:
  - Current step indicator: "Branch Created" (step 2 of 3)
  - Branch info: "Main Branch (MB-001)"
  - Owner info: [pending]
  - Action buttons: "Resend Bootstrap Link" (if applicable)
  - Timeline: "Branch created on 2026-05-20"
```

### AC 5: Edge Cases & Error Handling

**Scenario 5A: Prevent Branch/Owner Creation Out of Order**
```gherkin
Given company with status = "provisioned"
When System Admin tries to POST /admin/system/tenants/{id}/create-owner-user (without branch first)
Then the response is 409 Conflict
  And the error message is: "Please create the initial branch first"

When System Admin creates branch (status → "branch_created")
Then create-owner-user endpoint becomes available
```

**Scenario 5B: Handle Orphaned Users**
```gherkin
Given company "Acme Corp" with owner_user_id = 789
  And user 789 is deleted from system (data corruption scenario)
When System Admin views onboarding state
Then the system gracefully displays: "Owner assignment incomplete"
  And offers option to reassign owner user

When System Admin clicks "Assign New Owner"
Then the workflow restarts (new user creation)
```

**Scenario 5C: Handle Orphaned Branches**
```gherkin
Given company "Acme Corp" with initial_branch_id = 456
  And branch 456 is deleted from system (data corruption scenario)
When System Admin views onboarding state
Then the system displays: "Initial branch missing"
  And offers option to create new branch
```

**Scenario 5D: Graceful Timeout on Email Send Failure**
```gherkin
Given email service is temporarily unavailable
When System Admin creates owner user with send_bootstrap_link=true
Then the response is 202 Accepted (partial success)
  And the error message indicates: "User created but bootstrap email could not be sent. Please resend link manually."
  And company_onboarding_state is still updated (user created)
  And a retry mechanism is available

When email service recovers
Then System Admin can click "Resend Bootstrap Link"
  And email is successfully sent
```

### AC 6: Audit & Compliance Trail

**Scenario 6A: All Onboarding Events Are Logged**
```gherkin
Given company "Acme Corp" going through onboarding
When each step is completed:
  - Branch created
  - Owner user created
  - Bootstrap token generated
  - Bootstrap token used
Then company_onboarding_events contains audit record for each:
  - event_type: (correct type)
  - event_data: (JSON with all relevant details)
  - created_at: (timestamp)

When System Admin queries /admin/system/tenants/123/onboarding-events
Then a chronological list of all onboarding events is displayed
```

**Scenario 6B: No Data Exposure in Logs**
```gherkin
Given company_onboarding_events contains bootstrap_token
When event data is logged to application logs
Then the bootstrap_token is NEVER written to logs (masked or excluded)

When event data is shown in UI
Then the bootstrap_token is NEVER shown to end users
```

---

## 4. Testing Strategy

### Test Scope & Categories

#### 4.1 Unit Tests (40% Coverage)

**CompanyOnboardingState Model Tests:**
- `test_onboarding_state_creation_with_company`
- `test_onboarding_state_status_transitions_valid`
- `test_onboarding_state_status_transitions_invalid`
- `test_initial_branch_relationship`
- `test_owner_user_relationship`
- `test_scopes_for_pending_onboarding`

**OnboardingService Tests:**
- `test_initialize_onboarding_state_for_new_company`
- `test_create_initial_branch_generates_valid_record`
- `test_create_owner_user_with_owner_role`
- `test_generate_bootstrap_token_unique`
- `test_validate_bootstrap_token_valid`
- `test_validate_bootstrap_token_expired`
- `test_complete_bootstrap_updates_user_password`
- `test_complete_bootstrap_clears_token`
- `test_record_onboarding_event_to_audit_table`

**BootstrapToken Tests:**
- `test_generate_token_length_and_uniqueness`
- `test_token_expiration_calculation`
- `test_token_invalidation_on_use`

#### 4.2 Feature Tests (50% Coverage)

**CompanyOnboardingController Tests:**
- `test_system_admin_can_view_onboarding_state`
- `test_non_system_admin_cannot_view_onboarding_state`
- `test_system_admin_can_create_initial_branch`
- `test_prevent_duplicate_branch_creation`
- `test_prevent_invalid_branch_code`
- `test_system_admin_can_create_owner_user`
- `test_prevent_duplicate_owner_user`
- `test_prevent_global_email_duplicate`
- `test_bootstrap_link_sent_on_user_creation`
- `test_resend_bootstrap_link`
- `test_rate_limiting_on_bootstrap_attempts`
- `test_tenant_isolation_on_onboarding_operations`

**BootstrapController Tests:**
- `test_valid_bootstrap_token_shows_form`
- `test_invalid_bootstrap_token_shows_error`
- `test_expired_bootstrap_token_shows_error`
- `test_owner_can_complete_bootstrap_with_valid_password`
- `test_complete_bootstrap_with_weak_password_rejected`
- `test_complete_bootstrap_with_mismatched_passwords_rejected`
- `test_owner_email_verified_on_bootstrap_completion`
- `test_owner_can_login_after_bootstrap`
- `test_prevent_bootstrap_twice_on_same_token`
- `test_timezone_persisted_on_bootstrap_completion`

**Onboarding Workflow (End-to-End):**
- `test_complete_onboarding_workflow_company_to_ready`
- `test_onboarding_fails_if_branch_not_created_first`
- `test_onboarding_fails_if_owner_not_assigned_first`
- `test_system_admin_resend_bootstrap_link_before_completion`
- `test_timeline_of_events_accurate`

#### 4.3 Integration Tests (10% Coverage)

**Multi-Tenant Scoping:**
- `test_company_a_onboarding_does_not_affect_company_b_data`
- `test_roles_assigned_correctly_scoped_to_tenant`
- `test_branches_scoped_to_correct_company`

**Email Integration:**
- `test_bootstrap_email_queued_and_sent`
- `test_bootstrap_email_contains_correct_link`
- `test_email_retry_on_failure`

**Permission Integration:**
- `test_missing_manage_company_permission_blocks_access`
- `test_missing_create_users_permission_blocks_user_creation`
- `test_missing_create_branches_permission_blocks_branch_creation`

### Test Execution Strategy

**Pest PHP (existing framework):**
```bash
# Run all tests for this story
./vendor/bin/pest tests/Feature/SystemAdmin/CompanyOnboardingTest.php
./vendor/bin/pest tests/Feature/Auth/BootstrapTest.php
./vendor/bin/pest tests/Unit/Services/OnboardingServiceTest.php

# Run with coverage
./vendor/bin/pest --coverage tests/Feature/SystemAdmin/ tests/Feature/Auth/ tests/Unit/Services/

# Run specific test
./vendor/bin/pest tests/Feature/SystemAdmin/CompanyOnboardingTest.php --filter test_system_admin_can_create_initial_branch
```

**Test Database:**
- SQLite in-memory or dedicated test PostgreSQL database
- Migrations run before test suite
- Seeders populate baseline RBAC data

**Test Data Fixtures:**
```php
// TenantContext factory
$company = Company::factory()->create();

// User factory with multi-tenant support
$user = User::factory()->for($company)->create();

// Branch factory
$branch = Branch::factory()->for($company)->create();

// Role assignment seeder
RbacSeeder::seedSystemAdminRole($user, $company);
```

---

## 5. Deliverables & Success Criteria

### Definition of Done

- [x] Story scope confirmed and documented
- [ ] Database migrations created and tested
- [ ] Models created: CompanyOnboardingState, CompanyOnboardingEvent
- [ ] Service layer created: OnboardingService
- [ ] Controllers created: CompanyOnboardingController, BootstrapController
- [ ] Routes defined and tested
- [ ] Request validation classes created
- [ ] React components created (admin UI, bootstrap form)
- [ ] Email templates created (bootstrap link email)
- [ ] Permissions verified: manage_company, create_branches, create_users
- [ ] Unit tests: 100% pass rate (12+ tests)
- [ ] Feature tests: 100% pass rate (25+ tests)
- [ ] Integration tests: 100% pass rate (8+ tests)
- [ ] Tenant isolation verified: no data leakage between companies
- [ ] Permission model integration verified
- [ ] Multi-tenant scoping verified
- [ ] Bootstrap token expiration tested
- [ ] Rate limiting tested
- [ ] Email retry/failure handling tested
- [ ] Audit trail (event logging) verified
- [ ] Documentation: Story closure artifact created
- [ ] Roadmap updated: Story 29.2 marked completed
- [ ] Task ledger updated: G-062 marked completed
- [ ] No regression in existing tests
- [ ] All acceptance criteria demonstrated

### Success Metrics

1. **Functional Completeness:** Initial branch and owner user creation workflows fully operational
2. **Test Coverage:** ≥45 tests covering all scenarios; ≥95% code coverage for new code
3. **Tenant Isolation:** Zero cross-company data leakage; all multi-tenant scoping verified
4. **Audit Trail:** All onboarding events logged to event table
5. **User Experience:** Bootstrap link works end-to-end; owner can set password and login
6. **Governance:** Permission model integration verified; no privilege escalation
7. **Backwards Compatibility:** No regression in existing features (existing route tests pass)

### Deliverable Artifacts

1. **Code Changes:**
   - 2 models, 2 controllers, 2 services, 3 request classes, 4 migration files
   - 8 React components (admin UI + bootstrap form)
   - 3 email templates (bootstrap link, resend link, error notifications)
   - 45+ test files (unit, feature, integration)

2. **Documentation:**
   - Story 29.2 closure evidence in docs/validation/
   - API endpoint documentation in docs/
   - Database schema documentation in docs/

3. **Governance:**
   - Task ledger G-062 completed
   - Roadmap updated: Story 29.2 → Completed
   - Epic 29 progress: 2/5 stories completed (29.1, 29.2)

---

## 6. Dependencies, Risks & Mitigation

### External Dependencies
- **Email Service:** Bootstrap link delivery requires functional SMTP/email provider
  - **Risk:** Email service failure blocks owner onboarding
  - **Mitigation:** Implement graceful fallback (202 response, manual resend), email retry queue

- **Database Locks:** Pessimistic locking during branch/user creation under high concurrency
  - **Risk:** Race conditions causing duplicate branches/users
  - **Mitigation:** Use database constraints (UNIQUE, FOREIGN KEY) + application-level checks

### Implementation Risks & Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Duplicate branch/user creation | HIGH | Database constraints + unique indices + application validation |
| Bootstrap token leaked in logs/email | MEDIUM | Never log tokens; mask in logs; use secure token generation |
| Email delivery failure | MEDIUM | Graceful fallback, retry queue, manual resend UI |
| Tenant isolation violation | HIGH | Fail-closed middleware on all endpoints + explicit tenant_id checks |
| Permission bypass | HIGH | Multi-layer checks: middleware → RBAC → business logic |
| Token expiration not enforced | MEDIUM | Database timestamp + validation on every token use |
| Orphaned user/branch data | LOW | Cascade delete + UI safeguards for edge cases |

### Deferred / Out of Scope

**Explicitly Deferred (Not Blocking Story 29.2):**
- Sales machine profile setup (Story 29.3)
- BIR/PTU/MIN compliance setup (Story 29.4)
- Controlled offline sales pilot (Story 29.5)
- Form route feature gating (Story 29.1A Slice B2 residual)
- POS read gating (Story 29.1A Slice C residual)
- POS shell gating (Story 29.1A Slice D residual)

---

## 7. Continuation Plan & Next Story

### Immediate Next Steps (Post-Story 29.2)

1. **Create Story 29.3:** Sales Machine Profile / Terminal Registration
   - Depends on Story 29.2 branch/owner setup
   - Scope: Assign cashier sales terminals to branches, configure terminal settings

2. **Create Story 29.4:** Compliance Profile Setup
   - Depends on Story 29.2 branch/owner setup
   - Scope: BIR registration, PTU configuration, MIN setup

3. **Execute Story 29.1A Residual Hardening**
   - Slice B Phase B2: Form route gating decisions
   - Slice C: POS checkout gating
   - Slice D: Optional POS shell gating

### Epic 29 Completion Timeline

| Story | Status | Estimated Start | Estimated Completion |
|-------|--------|-----------------|----------------------|
| 29.1 | ✅ Completed | — | 2026-05-10 |
| 29.1A | ✅ Completed (with gaps) | — | 2026-05-20 |
| 29.2 | 🔄 Ready → In Progress | 2026-05-20 | 2026-05-27 |
| 29.3 | ⏳ Planned | 2026-05-28 | 2026-06-03 |
| 29.4 | ⏳ Planned | 2026-06-04 | 2026-06-10 |
| 29.5 | ⏳ Planned | 2026-06-11 | 2026-06-17 |

---

## 8. Code Skeleton & Implementation Checklist

### Phase 1: Database & Models (Day 1)

- [ ] Create migration: company_onboarding_state table
- [ ] Create migration: company_onboarding_events table
- [ ] Create indices on foreign keys
- [ ] Create CompanyOnboardingState model with relationships
- [ ] Create CompanyOnboardingEvent model with relationships
- [ ] Verify migrations run clean

### Phase 2: Service Layer (Day 1-2)

- [ ] Create OnboardingService with all required methods
- [ ] Implement initialization logic
- [ ] Implement branch creation logic
- [ ] Implement owner user creation logic
- [ ] Implement bootstrap token generation/validation
- [ ] Implement bootstrap completion logic
- [ ] Implement event logging
- [ ] Write unit tests for service (12+ tests)

### Phase 3: Controllers & Routes (Day 2-3)

- [ ] Create CompanyOnboardingController
- [ ] Create BootstrapController
- [ ] Define routes in routes/web.php, routes/auth.php
- [ ] Add permission middleware
- [ ] Add tenant isolation middleware
- [ ] Write feature tests for controllers (20+ tests)

### Phase 4: Request Validation (Day 3)

- [ ] Create CreateInitialBranchRequest
- [ ] Create CreateOwnerUserRequest
- [ ] Create CompleteBootstrapRequest
- [ ] Add validation rules
- [ ] Test validation

### Phase 5: Frontend Components (Day 3-4)

- [ ] Create CompanyOnboarding/Index.jsx (list)
- [ ] Create CompanyOnboarding/Show.jsx (detail)
- [ ] Create CompanyOnboarding/CreateBranch.jsx (form)
- [ ] Create CompanyOnboarding/CreateOwner.jsx (form)
- [ ] Create Auth/Bootstrap.jsx (public form)
- [ ] Create OnboardingProgress.jsx (component)
- [ ] Add error handling & validation UI

### Phase 6: Email Templates & Integration (Day 4)

- [ ] Create bootstrap link email template
- [ ] Create resend link email template
- [ ] Create error notification email template
- [ ] Queue bootstrap link on user creation
- [ ] Implement retry logic

### Phase 7: Testing & Validation (Day 4-5)

- [ ] Run all unit tests (100% pass)
- [ ] Run all feature tests (100% pass)
- [ ] Run all integration tests (100% pass)
- [ ] Test tenant isolation (all passing)
- [ ] Test permission model (all passing)
- [ ] Manual end-to-end test: company → branch → owner → bootstrap → login
- [ ] Verify no regression in existing tests

### Phase 8: Documentation & Closure (Day 5)

- [ ] Create closure evidence artifact
- [ ] Update roadmap (Story 29.2 → Completed)
- [ ] Update task ledger (G-062 → Completed)
- [ ] Update Epic 29 summary
- [ ] Document any learnings or issues

---

## 9. Communication & Handoff

### Assumptions & Confirmations Needed

**Before Implementation:**
- [ ] Confirm bootstrap email template design with UX team
- [ ] Confirm password policy (minimum length, special chars, etc.)
- [ ] Confirm timezone default behavior (browser detection vs. env)
- [ ] Confirm email sender address and brand
- [ ] Confirm rate limiting thresholds (max attempts, lockout duration)

**During Implementation:**
- [ ] Daily standups to surface any blockers
- [ ] Governance check at 50% completion
- [ ] QA review of test coverage
- [ ] Security review of bootstrap token handling

**At Completion:**
- [ ] Full regression suite passes
- [ ] Story closure evidence reviewed and approved
- [ ] Task ledger updated with G-062 completion
- [ ] Story 29.3 scoped and ready for assignment

---

## 10. Notes & Learnings from Story 29.1A

### Lessons Applied to Story 29.2

1. **Fail-Closed Tenant Isolation:** Apply same multi-layer approach (middleware + explicit checks + database constraints)
2. **Feature-Gate Integration:** Verify gates don't interfere with onboarding; Story 29.1A residual gaps deferred as expected
3. **Event Logging:** Implement comprehensive audit trail like Story 29.1A did for closure evidence
4. **Email Reliability:** Build in graceful fallback and retry mechanism from the start
5. **Rate Limiting:** Implement early to prevent brute-force/spam on bootstrap attempts
6. **Test Coverage:** Maintain ≥45 tests to ensure no regressions

### Risk Management

- Previous stories showed importance of comprehensive test coverage → implement 45+ tests upfront
- Story 29.1A had residual gaps that didn't block onboarding → similar pattern expected here
- Permission model integration critical → verify at each layer (middleware, RBAC, business logic)

---

**Status:** Ready for Implementation
**Assigned To:** Dev Team (Story 29.2)
**Due Date:** 2026-05-27
**Priority:** High — Unblocks Stories 29.3, 29.4, 29.5
