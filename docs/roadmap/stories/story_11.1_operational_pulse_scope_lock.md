# Story 11.1: Operational Pulse Scope Lock and Query Contract

## 1. Goal and Purpose
The Operational Pulse Dashboard is a live operational command center designed to answer: **“What is happening now?”** 

It provides real-time visibility into sales, payments, and stock levels for the current day, whereas the Settlement Review process handles the closing and validation of historical periods.

## 2. Dashboard Time Mode (Asia/Manila)
- **Timezone**: `Asia/Manila` (UTC+8).
- **"Today" Window**: Enforced using a **half-open interval** for query safety:
    - `start_at` <= `record_time` < `tomorrow_00:00:00` (Asia/Manila).
- **Yesterday/Context**: A contextual link to "Yesterday's Settlement" (latest approved/locked period).

## 3. Role Visibility and Permission Rules

### Owner / Admin
- **Mode**: Default is `tenant` (aggregated across all branches).
- **Filtering**: With `view_multi_branch_dashboard`, can optionally filter by a specific branch (mode becomes `branch` but remains tenant-authorized).
- **Access**: Requires `view_reports`.

### Branch Manager
- **Mode**: Default is `branch`.
- **Constraint**: May view only **one assigned branch at a time** unless granted `view_multi_branch_dashboard`.
- **Logic**: If assigned to multiple branches without tenant-wide permission, the UI requires explicit branch selection or defaults to the first active assigned branch.
- **Access**: Requires `view_reports`.

### Cashier
- **Mode**: None.
- **Access**: **NO dashboard access by default** (403 Forbidden). 
- **Note**: Any cashier-safe mini-dashboard requires a separate, explicitly approved story.

## 4. Source-of-Truth Rules
- **Truth Source**: Sales, SalePayments, SaleRefunds, SaleVoids, AccountingOutbox, and Branch Inventories.
- **Prohibited Sources**:
    - **QuickBooks**: Must not use QBO as the financial truth for live operations.
    - **Settlement Snapshots**: Must not use snapshots for "Today's" live pulse (snapshots are for historical evidence only).

## 5. Read-Only Boundaries (Strict Guardrails)
The dashboard is an **Observation Layer** only. It must NOT:
- Approve, Lock, or Reopen settlement periods.
- Create Settlement Snapshots or Export records.
- Create Accounting Outbox records or trigger QuickBooks sync/retries.
- Mark alerts/exceptions as resolved.
- Mutate Sales, Payments, Inventory, or Reversal records.
- Mutate Inventory Thresholds.
- **Viewing Audit**: No audit log is created for normal dashboard viewing (unless future sensitive policy is added).

## 6. DashboardPayload Contract

```json
{
  "scope": {
    "mode": "tenant|branch",
    "tenant_id": "uuid",
    "branch_id": "uuid|null",
    "label": "Tenant Pulse / Branch Pulse"
  },
  "window": {
    "type": "today",
    "start_at": "ISO8601",
    "end_at": "ISO8601",
    "timezone": "Asia/Manila"
  },
  "sales": {
    "gross_sales_total": "decimal-string",
    "net_sales_total": "decimal-string",
    "refund_total": "decimal-string",
    "void_total": "decimal-string",
    "sale_count": 0
  },
  "payments": {
    "total": "decimal-string",
    "by_method": [
      { "code": "cash", "name": "Cash", "total": "0.0000", "count": 0 }
    ]
  },
  "accounting_sync": {
    "pending": 0,
    "processing": 0,
    "synced": 0,
    "failed": 0
  },
  "inventory": {
    "low_stock_count": 0,
    "critical_items": [
      {
        "product_id": "uuid",
        "sku": "string|null",
        "name": "string",
        "branch_id": "uuid",
        "current_stock": "decimal-string",
        "reorder_level": "decimal-string"
      }
    ]
  },
  "settlement": {
    "latest_locked_period_id": "uuid|null",
    "yesterday_status": "locked|approved|in_review|open|null"
  },
  "freshness": {
    "generated_at": "timestamp",
    "source": "live_query",
    "cache_status": "fresh|cached|null"
  }
}
```

## 7. Explicit Non-Goals
- Real-time charting or graphing (Deferred).
- Multi-branch comparison views (Deferred).
- Financial posting or journal generation.
- Operational actions (Voiding/Refunding) from the dashboard.

## 8. Future Story Split
- **Story 11.2**: Dashboard Query Service Foundation.
- **Story 11.3**: Owner Tenant-Wide Pulse Dashboard.
- **Story 11.4**: Branch Manager Pulse Dashboard.
- **Story 11.5**: Payment Mix and Sync Health Widgets.
- **Story 11.6**: Inventory Health and Low-Stock Widget.
- **Story 11.7**: Reporting Freshness and Performance Guardrails.
- **Story 11.8**: Dashboard Isolation and Accounting-Silent Tests.
