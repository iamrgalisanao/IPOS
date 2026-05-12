---
name: sync-discovery
description: Performs a comprehensive alignment check of the current project state against the defined roadmap and governance ledgers.
---

# Sync Discovery (IPOS Project)

**Mandatory Discovery**: Before proposing any major architectural changes or starting a new epic, you must invoke the filesystem and/or Postgres MCPs to verify the current "Ground Truth" of the IPOS application.

**Context Alignment**: Compare the current workspace files against:
1. `docs/ROADMAP.md`
2. `docs/ai-governance/task-ledger.md` (if applicable)

This process ensures that the AI context detects any hidden expansions, scope creep, or misalignment from the planned zero-loss POS requirements.

**Failure Detection**: Specifically audit for **Sycophantic Confirmation**. Do not blindly accept prior agent assumptions. Require concrete evidence (e.g., actual database schema logs, existing React components) before proceeding with code changes.
