# Compliance Register

Last updated: 2026-05-12

## Data Privacy Act Alignment

- Tenant-scoped accounting and QuickBooks data paths were reviewed for tenant isolation controls.
- QuickBooks tokens are encrypted at rest in the application model layer.
- QuickBooks connect and disconnect actions generate audit log records.
- Repository-managed MCP and compose configuration now use environment injection or secure input variables instead of committed secrets.
- Previously committed credentials should still be rotated because historical exposure remains a compliance concern.

## Current Status

- Status: Proceed with Caution
- Follow-up item: rotate any credentials that were previously committed before relying on these configurations in shared environments.