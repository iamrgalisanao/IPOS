# IPOS User Guide

Welcome to the **IPOS User Guide**. This documentation provides store staff, branch managers, accountants, and administrators with clear, step-by-step instructions to operate the IPOS platform, maintain cash drawer accountability, and reconcile store-level operations.

---

## 📖 Table of Contents

### 1. [Getting Started](01-getting-started.md)
* Learn about the IPOS zero-loss checkout philosophy, core architectural highlights, and the offline-tolerant POS terminal.

### 2. [Login and Access Control](02-login-and-access.md)
* Accessing the system, role assignments, multi-branch switching, and the RBAC permissions matrix.

### 3. [Dashboard Overview](03-dashboard-overview.md)
* Navigating the Owner Tenant-Wide Pulse Dashboard, Branch Manager Scoped Dashboards, and real-time operational KPI indicators.

### 4. Module Guides
* **[Data Exports & Tax Reporting](04-module-guides/data-exports-and-tax.md)**: Generate asynchronous BIR E-Journal reports, manage exports, and review 48-hour retention policies.
* **[Sales History & Prior-Period Reports](04-module-guides/sales-report.md)**: Search transactions, reprint receipts with reasons, and handle late offline sales prior-period adjustments.
* **[Product Catalog & Unit Conversions](04-module-guides/product-management.md)**: Dynamic pricing, product categories, and tenant unit conversion controls.
* **[Inventory Stocktake & Adjustments](04-module-guides/inventory.md)**: Initializing counts, recording physical stock, posting adjustments, and tracking WAC valuations.
* **[Terminal Sync Diagnostics & Reliability](04-module-guides/terminal-sync-monitor.md)**: Monitoring POS terminal synchronization payload diagnostics, sequence verification, session/terminal recovery states, and sync status checks.
* **[Cash Drawer Audit & Shift Reconciliation](04-module-guides/cash-drawer-audit-shift-reconciliation.md)**: Open/close cashier shifts, record surprise spot audits, monitor warning limits, verify high-value drops, and approve bank deposits.

### 4A. UAT and Validation References
* **[POS Terminal Offline Checkout and Sync UAT](../validation/pos-terminal-offline-uat-2026-07-11.md)**: Cashier and admin acceptance checklist for offline checkout, reconnect, sync queue, service-worker shell rollover, and hardware-deferred validation notes.

### 4B. Planning References
* **[POS Admin Configuration and Terminal Capability Backlog](../roadmap/pos-admin-configuration-terminal-capability-backlog.md)**: Planning reference for Back Office configuration, terminal capabilities, config snapshots, and future admin-controlled POS settings.

### 5. [Common Errors and Troubleshooting](05-common-errors-and-troubleshooting.md)
* Explanations and actions for common error codes, QBO mapping issues, tolerance mismatches, offline warning indicators, and POS terminal session/sync recovery messages.

### 6. [Frequently Asked Questions (FAQ)](06-faq.md)
* Quick answers about shift locks, IBT status, receipt reprinted duplicate watermarks, and VAT compliance details.

### 7. [Changelog](changelog.md)
* Tracking updates, version improvements, and newly validated features.

---

## 🛠 Documentation Governance

Every document in this guide uses the following status indicator to maintain truthfulness with the codebase:
* **Validated**: The behavior has been fully implemented, feature-tested, and compiled in the release branch.
* **In Development**: The backend service or schema is coded but the final user interfaces or integrations are pending closure.
* **Draft**: The feature is proposed or currently under active design.
