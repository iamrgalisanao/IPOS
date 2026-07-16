# Inventory Pilot Screenshot Capture Pack

Date: 2026-07-16
Scope: Epic 40 Story 40.8 - Pilot evidence capture

## 1. Capture Rules

1. Use isolated UAT data for destructive scenarios.
2. Use controlled live branch data only after entry criteria pass.
3. Capture only required evidence.
4. Mask customer personal information where not needed.
5. Confirm no credentials, tokens, bookmarks, or unrelated tabs are visible.
6. Keep browser zoom at 100% and capture full viewport.
7. Do not edit numbers to misrepresent behavior; use realistic approved data.
8. Store evidence in the approved restricted repository.
9. Record evidence owner and retention class.

## 2. Required Screenshot Set

Capture in this order:

1. Pilot entry criteria and scope record.
2. Inventory Hub landing page.
3. Inventory Hub card grouping with role-aware visibility.
4. Unit conversion setup and historical version evidence.
5. Product/recipe setup for direct and recipe deduction scenarios.
6. Stock Card before and after sale deduction.
7. Current Stock report with revision and watermark metadata.
8. Offline sale queue/sync status, where applicable.
9. Product Composition report for recipe lineage.
10. Negative Stock Exception report row.
11. Stocktake summary and Physical Count Variance report.
12. Manual adjustment request approval/denial evidence.
13. Movement Summary report.
14. Reconciliation Exceptions or reconciled proof.
15. Usage Reconciliation report.
16. Configuration and Integrity report.
17. CSV export with matching filters.
18. Go/no-go and signoff record.
19. Hypercare daily check record.
20. Recovery drill evidence.

## 3. File Naming Convention

Use this exact pattern:

1. `epic40-uat-001-entry-scope.png`
2. `epic40-uat-002-hub-landing.png`
3. `epic40-uat-003-hub-role-visibility.png`
4. `epic40-uat-004-unit-conversion-version.png`
5. `epic40-uat-005-product-recipe-setup.png`
6. `epic40-uat-006-stock-card-sale-before-after.png`
7. `epic40-uat-007-current-stock-watermark.png`
8. `epic40-uat-008-offline-sync-status.png`
9. `epic40-uat-009-product-composition-lineage.png`
10. `epic40-uat-010-negative-stock-exception.png`
11. `epic40-uat-011-stocktake-variance.png`
12. `epic40-uat-012-adjustment-authorization.png`
13. `epic40-uat-013-movement-summary.png`
14. `epic40-uat-014-reconciliation-proof.png`
15. `epic40-uat-015-usage-reconciliation.png`
16. `epic40-uat-016-configuration-integrity.png`
17. `epic40-uat-017-csv-export.png`
18. `epic40-uat-018-go-no-go-signoff.png`
19. `epic40-uat-019-hypercare-daily-check.png`
20. `epic40-uat-020-recovery-drill.png`

## 4. Storage Path

Store files in:

1. `docs/user-enablement/assets/pilot-inventory/`

## 5. Caption Template

For every screenshot, provide:

1. Evidence ID.
2. Scenario ID.
3. Screen name.
4. Intended actor role.
5. Tenant and branch.
6. Source reference.
7. Action being demonstrated.
8. Expected success signal.
9. Boundary note.
10. Masking status.
11. Retention class.

## 6. Evidence Manifest Template

For each screenshot/export, index:

1. `evidence_id`
2. `scenario_id`
3. `artifact_type`
4. `file_name_or_location`
5. `captured_by`
6. `captured_at`
7. `tenant`
8. `branch`
9. `source_reference`
10. `contains_sensitive_data`
11. `masking_status`
12. `retention_class`
13. `checksum` optional
14. `reviewed_by`

## 7. Quality Gate Checklist

Before publishing screenshot assets:

1. All text labels are legible.
2. Branch context is visible where expected.
3. No sensitive values or identities appear.
4. Date or run context is consistent across sequence.
5. Screens map to currently implemented UI only.
6. Expected and actual values are not edited after capture.
7. Evidence IDs match the UAT scenario record.
