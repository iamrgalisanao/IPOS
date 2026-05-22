# Epic 32 Parking Note - Recursive POS Recipe Deduction

Status: PARKED FOR LATER DECISION
Date: 2026-05-23
Related: Story 31.7 - All-Products Ingredient Composition Report

## Decision

Recursive POS recipe deduction is not approved for implementation yet.

Story 31.7 introduced flattened sub-recipe visibility for planning analytics,
but live POS deduction remains direct-component only. Any future change that
makes nested recipe quantities affect checkout inventory deduction must be
planned as a separate epic or story with explicit acceptance criteria.

## Why Parked

Recursive deduction changes live stock movement semantics and therefore has a
higher operational blast radius than read-only reporting. It can affect:

1. Checkout failure behavior.
2. Inventory variance logging.
3. Unit conversion failure handling.
4. Branch stock coverage and stockout decisions.
5. Regression expectations for current direct-recipe deduction.

## Required Before Implementation

1. Confirm business need for live multi-level recipe deduction.
2. Define expected behavior when a semi-finished ingredient has both stock and
   its own recipe.
3. Decide whether deduction should consume the semi-finished product, its raw
   components, or both under a configurable production policy.
4. Create a dedicated planning lock with test coverage for checkout, refunds,
   offline sync, variance logs, and unit conversion errors.

## Current Boundary

Until this is explicitly reopened, flattened rows in Story 31.7 remain
planning-only and must not be treated as live checkout deduction behavior.
