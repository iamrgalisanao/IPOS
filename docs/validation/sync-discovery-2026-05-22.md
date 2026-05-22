# Sync Discovery Report - 2026-05-22

**Scope:** Project alignment check against roadmap, governance ledger, routes, controllers, and database evidence.

---

## Executive Summary

The current workspace state is aligned with the closed Story 31.6 / Epic 31 posture.

No hidden catalog import write-path expansion was found. The import-related work in Story 31.6 remains limited to read-only export, template download, and validation-only preview flows. The only import-named database table discovered belongs to the offline sales sync domain, not catalog bulk import.

---

## Evidence Checked

- [docs/ROADMAP.md](../ROADMAP.md)
- [docs/roadmap/validated-implementation-roadmap.md](../roadmap/validated-implementation-roadmap.md)
- [docs/ai-governance/task-ledger.md](../ai-governance/task-ledger.md)
- [routes/web.php](../../routes/web.php)
- [app/Http/Controllers/Admin/ProductController.php](../../app/Http/Controllers/Admin/ProductController.php)
- [app/Http/Controllers/Admin/ProductCategoryController.php](../../app/Http/Controllers/Admin/ProductCategoryController.php)
- [app/Services/Catalog/CatalogImportPreviewService.php](../../app/Services/Catalog/CatalogImportPreviewService.php)
- [resources/js/Pages/Admin/Products/Index.jsx](../../resources/js/Pages/Admin/Products/Index.jsx)
- [resources/js/Pages/Admin/ProductCategories/Index.jsx](../../resources/js/Pages/Admin/ProductCategories/Index.jsx)
- Postgres schema inspection via `psql`

---

## Findings

### 1. Legacy roadmap is deprecated, but still present

The legacy roadmap clearly states that it is no longer the execution source of truth and points readers to the validated implementation roadmap. That is correct, but it remains a possible source of confusion for anyone who reads it in isolation.

- Legacy roadmap deprecation note is present in [docs/ROADMAP.md](../ROADMAP.md)
- Current execution truth is in [docs/roadmap/validated-implementation-roadmap.md](../roadmap/validated-implementation-roadmap.md)

### 2. Epic 31 is closed in the validated roadmap and governance ledger

Epic 31 is marked closed in the validated roadmap, and the governance ledger records the same final posture with Story 31.6 closed and import write-path deferred.

- Epic 31 closed state in [docs/roadmap/validated-implementation-roadmap.md](../roadmap/validated-implementation-roadmap.md)
- G-068 closed state in [docs/ai-governance/task-ledger.md](../ai-governance/task-ledger.md)

### 3. Story 31.6 import flow remains preview-only

The routes and controllers show template and preview endpoints only. The product and category controllers validate uploads, generate preview data, and redirect back with session preview state. There is no write-path import implementation in the catalog area.

- Catalog import routes are preview/template only in [routes/web.php](../../routes/web.php)
- Product preview controller path in [app/Http/Controllers/Admin/ProductController.php](../../app/Http/Controllers/Admin/ProductController.php)
- Category preview controller path in [app/Http/Controllers/Admin/ProductCategoryController.php](../../app/Http/Controllers/Admin/ProductCategoryController.php)
- Import preview service is validation-only in [app/Services/Catalog/CatalogImportPreviewService.php](../../app/Services/Catalog/CatalogImportPreviewService.php)

### 4. Database evidence does not show catalog import write tables

A schema search for tables containing `import` returned only `offline_sales_imports`. That table belongs to the offline sales sync/reconciliation domain and is not a catalog bulk import table.

- `offline_sales_imports` originates from [database/migrations/2026_05_20_015104_create_offline_sync_tables.php](../../database/migrations/2026_05_20_015104_create_offline_sync_tables.php)
- Additional references in later offline sync migrations confirm the same domain

---

## Sycophantic Confirmation Check

No sycophantic confirmation issue was found for the Story 31.6 boundary claims.

Why:
- The code path is preview/template only.
- The routes are guarded by `catalog.edit` for preview/template access.
- The database evidence shows only offline sync import tables, not catalog import tables.
- The UI explicitly presents the flow as validation-only.

---

## Alignment Verdict

**Aligned.**

The current project state matches the approved Story 31.6 / Epic 31 closure posture:
- export hardening complete
- template and validation preview complete
- actual import writes remain locked
- no protected engine/runtime boundaries were expanded

---

## Notes

- Postgres MCP was unavailable in this session, so schema verification used direct `psql` CLI evidence.
- This report is intended as a discovery artifact, not a new approval or scope expansion.
