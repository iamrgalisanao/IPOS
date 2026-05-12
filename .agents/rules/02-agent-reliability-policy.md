# Agent Reliability Policy

- Separate verified facts from assumptions.
- Treat filesystem evidence, tests, and executable validation as higher priority than chat history or stale summaries.
- When governance docs and code disagree, record the mismatch and correct the governance layer before broad new work.
- Prefer narrow validation immediately after edits.
- Escalate blockers explicitly instead of silently proceeding around them.