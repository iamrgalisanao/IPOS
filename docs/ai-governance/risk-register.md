# Risk Register

Last updated: 2026-05-12

| ID | Risk | Level | Impact | Mitigation | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| R-003 | Governance drift between roadmap/planning artifacts and implemented code | High | Future planning or reviews may make incorrect stage and scope decisions | Reconcile `docs/ROADMAP.md`, supersede stale planning notes, and keep the task ledger current | Mitigated |
| R-004 | Missing active-focus and rules artifacts weakens change control | Medium | Agents may operate without a current bounded objective or explicit tool-governance anchors | Add a current-focus document or equivalent lightweight rule set before major new work | Mitigated |
| R-005 | Stale documentation can hide actual release readiness and integration scope | Low | Teams may under-test or over-test the wrong slice of the system if roadmap and planning artifacts are not refreshed after major changes | Re-run sync-discovery before major decisions, keep governance artifacts current after significant changes, cite the alignment note when older planning documents are referenced, and record validated story evidence for settlement review/action/reopen workflows | Mitigated |
| R-006 | Missing formal code-review and security-review artifacts for accounting and integration surfaces | Medium | Release or readiness decisions may be made without explicit review evidence on financial and external-system changes | Require review artifacts before release-readiness decisions or high-risk production changes | Mitigated |
| R-007 | Hardcoded Hermes API token in compose configuration | High | Repository disclosure exposes a reusable credential and weakens secrets governance | Remove the committed token, rotate it, and inject the value from environment or secret storage | Mitigated |
| R-008 | Previously committed MCP and Hermes credentials may still be valid outside the repository | High | Historical exposure remains exploitable even after config remediation if the same credentials are still active | Rotate exposed credentials and re-enter them through secure prompts or environment variables | Open |
| R-009 | Published layout mutation causing terminal instability | Medium | Directly editing a layout used by active terminals could cause frontend crashes if schema changes mid-session | Enforce read-only status for published layouts in Slice B; require new versioning for updates. | Mitigated |
| R-010 | Invalid schema injection via admin CRUD | Low | Malicious or malformed JSON could crash the terminal or bypass security | Strict schema validation via `PosLayoutSchemaValidator` in Slice B backend. | Mitigated |
| R-011 | Rendering lag on low-end tablets from complex layout grids | Medium | UI performance degradation during busy shifts | Limit max grid dimensions in validator; optimize React rendering in Slice C. | Mitigated |
| R-012 | Missing product data for layout tiles (deactivated/deleted items) | Low | Tiles rendering as empty or causing frontend errors | `ProductGrid` must handle missing product lookups gracefully by rendering "Unavailable" state. | Mitigated |
| R-013 | Concurrent layout resolution conflicts | Low | POS might fetch inconsistent versions during a mid-shift transition | Terminal fetches layout on load or after transaction; resolution logic uses `latest()` active layout. | Mitigated |
| R-014 | Coordinate drift/orphaned tiles on grid resize | Low | UI rendering tiles that are outside the current grid dimensions | Frontend validation and backend coordinate stripping during dimension decrease. | Open |
| R-015 | Drag-and-drop accessibility or Z-index conflicts | Low | Editor becomes unusable on specific browsers or small admin screens | Standardize on dnd-kit and use portal-based overlays for drag previews. | Open |
