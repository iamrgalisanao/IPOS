# Coding Standards

- Preserve existing Laravel and React/Inertia patterns.
- Favor fail-closed tenant and branch isolation.
- Keep financial and audit behavior append-only and traceable.
- Avoid unrelated refactors during governance or feature corrections.
- Update adjacent documentation when code changes materially affect project state.
- **User Guide Rule**: Every completed epic or feature slice must produce or update the relevant user-facing guide under `docs/user-guide/` before the task is considered complete. Ensure steps, system roles, and access paths reflect only confirmed working behavior. Quality and completeness are validated automatically by `tests/Feature/Compliance/UserGuideQualityTest.php`.