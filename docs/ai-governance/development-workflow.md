# IPOS Development Workflow: The Three-Layer Quality Approach

This document defines the mandatory development workflow for all stories in the IPOS project.

## Layer 1: Shift-Left — Test Design & ATDD
**Purpose**: Define how the story will be proven before implementation starts.
**When**: Before coding every story.
**Required Output**:
- Acceptance Criteria Checklist
- Test Scenario List
- Risk Areas
- Expected Data Boundaries
- Mutation Boundary (What should NOT change)
- Regression Scope

## Layer 2: Guardrail Automation — The Safety Net
**Purpose**: Automatic and non-negotiable quality gates.
**When**: During and after implementation.
**Required Output**:
- Story-specific tests pass
- Full regression suite passes
- Mutation boundary confirmation (Proof that no unintended changes occurred)

## Layer 3: Non-Functional Validation — The Invisible Quality
**Purpose**: Validate Security, Performance, Reliability, Data integrity, Auditability.
**When**: End of major features or sensitive stories.
**Focus Areas**:
- **Security**: Tenant/Branch isolation.
- **Reliability**: Atomic transactions, duplicate handling.
- **Data Integrity**: Server-side source of truth.
- **Auditability**: Immutable record protection.

## Standard Execution Sequence
1. **Story Intake**: Scope Lock.
2. **Shift-Left QA Plan**: AC to Test Mapping (Produced by Murat/QA layer).
3. **Implementation**: Only approved scope (Produced by Amelia/Developer).
4. **Guardrail Automation**: Story tests + Full regression.
5. **Non-Functional Validation**: Security/Reliability/Performance check.
6. **Review Gate**: Final approval by USER.

---

## Roles
- **Amelia (Developer)**: Implementation and failure fixing.
- **Winston (Architect)**: Structure, boundaries, and scope control.
- **Murat (QA/Test Architect)**: Test plans, mutation proof, and NFR validation.

## Completion Rule
No story is complete until it has:
1. Scope lock
2. Test plan
3. Implementation
4. Story-specific tests
5. Full regression
6. Acceptance coverage map
7. Mutation boundary confirmation
8. Final USER approval
