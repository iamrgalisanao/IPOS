# IPOS vs. StoreHub Blueprint: Comparative Gap Analysis
**Date:** 2026-05-29  
**Source Document:** `storehub_pos_research_epic_guide.md` (24-Epic Guide)  
**Target Document:** `validated-implementation-roadmap.md` (IPOS Core & Proposed Roadmap)

---

## 1. Executive Summary

This document evaluates the 24-Epic POS System Blueprint based on the public StoreHub documentation (`storehub_pos_research_epic_guide.md`) and maps it against the current and proposed **IPOS implementation roadmap**.

### **Primary Findings:**
1.  **Compliance and Tenant Core (IPOS Stronger):** IPOS contains highly mature multi-tenant isolation, cryptographically hashed e-journals (SHA-256 HMAC), and strict BIR tax recalculations. StoreHub's public docs focus on operational/commercial simplicity, while IPOS leads on corporate auditability and accounting confidence.
2.  **Core POS Cashiering (Equivalent):** Both platforms support PIN login, shift float setups, cashier shifts, drawer pay-ins/payouts, blind closing, and split payments.
3.  **Core F&B Operations (IPOS Gap):** StoreHub features visual table layouts, split/merge/move bill flows, and customer QR ordering. IPOS contains POS layout builders but lacks active dining table tracking and order manipulation.
4.  **Hardware & Peripheral Management (IPOS Gap):** StoreHub manages device licensing, printer routing by station, CFD (customer-facing displays), KDS (Kitchen Display System), and NCS (Number Calling System). IPOS is currently focused on server-side transaction ingestion and basic web-receipt printing.
5.  **Marketing & Promotions (IPOS Gap):** StoreHub integrates a loyalty point engine, cashback, store credit ledgers, and declarative "Buy X Get Y" promotions. IPOS is limited to cashier-applied manual discounts.

---

## 2. Detailed Mapping & Gap Matrix

The table below maps the 24 StoreHub Epics to their closest IPOS equivalents, identifying the functional gap.

| StoreHub Epic | Closest IPOS Epic / Status | Functional Gap / Analysis |
| :--- | :--- | :--- |
| **Epic 1: Merchant & Store Foundation** | **Epic 1 & 2 (Closed)** | Equivalent. IPOS manages tenants, branches, roles, and profiles. |
| **Epic 2: POS App Setup & Peripherals**| **Epic 13 & 22 (Closed / Proposed)** | **Gap:** IPOS lacks peripheral diagnostics, cash drawer testing, and device health monitoring interfaces. |
| **Epic 3: Product Catalog & POS Layout**| **Epic 3, 22 & 31 (Closed)** | Equivalent. Both support SKU/barcode, categories, and tile layouts. |
| **Epic 4: PIN & Shift Management** | **Epic 2 & 12 (Closed)** | Equivalent. PIN logins, shift lifecycles, and float setups are supported. |
| **Epic 5: Cart & Checkout** | **Epic 4 & 5 (Closed)** | Equivalent. Cart persistence, checkout validation, and payments are implemented. |
| **Epic 6: Integrated Payment Terminals**| **Epic 5 (Closed)** | **Gap:** IPOS lacks integrated terminal API flows (card terminal handshakes). |
| **Epic 7: Open Orders & Pre-Orders** | **Epic 4 (Closed)** | **Gap:** IPOS lacks pre-orders and F&B takeaway charge rules. |
| **Epic 8: Reversals & Refunds** | **Epic 7 (Closed)** | Equivalent. IPOS uses an append-only reversal structure (void/partial refunds). |
| **Epic 9: Printing & Receipts** | **Epic 4 & 14 (Closed)** | Equivalent for receipts; **Gap** on printer routing by station. |
| **Epic 10: Customers & Loyalty** | **Epic 15 (Closed)** | **Gap:** IPOS lacks store credit ledgers, cashback, and loyalty reward tiers. |
| **Epic 11: Discounts & Promotions** | **Epic 5 (Closed)** | **Gap:** IPOS lacks declarative "Buy X Get Y" or combo bundle promotion engines. |
| **Epic 12: Visual Table Layout** | **Epic 22 (Closed)** | **Gap:** IPOS lacks dine-in table status tracking and order move/merge/split UI. |
| **Epic 13: QR Order & Pay** | *None* | **Gap:** IPOS has no customer-facing QR menu or self-service payment app. |
| **Epic 14: Kitchen Display (KDS)** | *None* | **Gap:** IPOS lacks paired KDS screen interfaces. |
| **Epic 15: Number Calling (NCS)** | *None* | **Gap:** IPOS lacks customer-facing number pickup screens. |
| **Epic 16: Multiple Register Sync** | **Epic 36 (Proposed)** | Planned. IPOS lacks store-level local subnet register sync. |
| **Epic 17: Security & Approvals** | **Epic 13 & 17 (Closed)** | Equivalent. Manager approvals and customer details masking are built. |
| **Epic 18: Reporting & Shifts** | **Epic 11, 12 & 17 (Closed)** | Equivalent. IPOS features Z-readings, cashier counts, and manager reviews. |
| **Epic 19: Inventory Integration** | **Epic 6 (Closed)** | Equivalent. POS payment triggers automatic stock deduction. |
| **Epic 20: Delivery Channels** | *None* | **Gap:** IPOS lacks Grab/Foodpanda order channel routing. |
| **Epic 21: Subscription Licensing** | **Epic 25, 29 & 30 (Closed)** | Equivalent. Subscription feature gating is implemented. |
| **Epic 22: Sync & Offline-First** | **Epic 28 & 32 (Closed / Proposed)** | Equivalent. Checksum verification and batch sync are built. |
| **Epic 23: Observability & Support** | **Epic 13 (Closed)** | Equivalent. Support assisted mode and centralized logging are active. |
| **Epic 24: Compliance & Tax** | **Epic 14 (Closed)** | **IPOS Advantage:** Stronger BIR-CAS sequence locks and hashed e-journals. |

---

## 3. High-Priority Functional Gaps

### Gap 1: Advanced Promotion and Combo Engine (StoreHub Epic 11)
*   **StoreHub:** Marketers configure auto-applied discount rules (e.g., "Buy 2 Coffees, Get 1 Pastry at 50% Off", combo bundles, or customer-segment specific promotions).
*   **IPOS State:** Limited to item-level percentage or cash manual discounts applied by the cashier.
*   **Impact:** IPOS cannot serve retail or F&B clients running active marketing campaigns or bundled deals.

### Gap 2: Visual Table Operations & Bill Splitting (StoreHub Epic 12)
*   **StoreHub:** POS displays visual floor maps with occupied/vacant states. Waiters can transfer items between tables, merge checks, or split a single table bill across multiple payers.
*   **IPOS State:** Tracks orders but has no dining floor representation or check split/merge service.
*   **Impact:** Restaurant waiters cannot manage tables or handle split payments easily on the terminal.

### Gap 3: Store Credit & Loyalty Ledger (StoreHub Epic 10)
*   **StoreHub:** Accumulates loyalty points per PHP spent, manages customer store credit balances (e.g., for returned goods), and supports store credit as a payment method.
*   **IPOS State:** Tracks basic customer metadata for invoices but lacks points engines or balance ledgers.
*   **Impact:** Merchants cannot run customer retention programs or issue digital credit vouchers.

### Gap 4: Station Printer Routing (StoreHub Epic 9)
*   **StoreHub:** POS routes drinks to the bar printer, hot items to the kitchen printer, and invoices to the front counter printer.
*   **IPOS State:** Generates a unified print-ready receipt.
*   **Impact:** Large kitchens must manually split paper tickets, slowing down service.

---

## 5. Roadmap Expansion Opportunities

To bridge these gaps, future epics can be added to the IPOS roadmap:

### **Epic 37: Advanced Promotions & Bundling Engine**
*   **Story 37.1:** Declarative Promotion Rule Engine (Buy X Get Y, Combo packages).
*   **Story 37.2:** Automatic Promotion Application Service in Cart.
*   **Story 37.3:** Promotion Usage Reporting & Cost Analysis.

### **Epic 38: F&B Table & Bill Manipulation Operations**
*   **Story 38.1:** Dining Floor Table Status Visualizer.
*   **Story 38.2:** Split-Bill by Seat/Item Service.
*   **Story 38.3:** Merge/Move Orders between Table IDs.

### **Epic 39: Loyalty & Store Credit Ledger**
*   **Story 39.1:** Append-Only Store Credit Wallet Ledger.
*   **Story 39.2:** Customer Loyalty Points Accumulation Engine.
*   **Story 39.3:** Store Credit Wallet Payment Integration.
