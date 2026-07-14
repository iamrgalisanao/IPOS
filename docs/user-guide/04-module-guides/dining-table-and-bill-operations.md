# Dining Table and Bill Operations User Guide

Status: Validated
Last Updated: 2026-07-14
System Area: POS Terminal, Back Office -> Dining Layouts
User Roles: Cashier, Branch Manager, Owner/Admin

---

## 1. Purpose

Dining Table and Bill Operations lets food-and-beverage branches manage service areas, tables, active dining tickets, ticket items, bill splits, and final dining checkout without creating a separate sales engine.

The dining workflow stages table/order activity first, then converts a payable dining ticket into the existing POS sale and payment flow. This keeps receipt, inventory, tax, Z-read, settlement, and accounting behavior consistent with ordinary POS checkout.

---

## 2. Who Can Use This Feature

* **Cashier / Server**: View the dining floor map, open dining tickets, add or adjust ticket items, assign seats, split bills, and start checkout when online and clocked in.
* **Branch Manager**: Use cashier/server actions and supervise item voids, exception handling, and daily operational review.
* **Owner / Admin**: Configure service areas, dining tables, table capacity, active/inactive state, and visual layout metadata.

---

## 3. Access Path

Back Office setup:
* **Main Menu -> Administration -> Dining Layouts**

POS terminal:
* **POS Terminal -> Floor Map**
* **POS Terminal -> Dining Ticket**
* **Dining Ticket -> Checkout**

Exact menu labels may vary slightly by deployment skin, but dining setup lives in Administration and active table operations live in the POS terminal.

---

## 4. Operational Instructions

### A. Configure Service Areas and Tables
1. Go to **Administration -> Dining Layouts**.
2. Create a service area such as `Main Dining`, `Patio`, or `Private Room`.
3. Add dining tables with a table number, capacity, shape, and position.
4. Save the layout.
5. Use activation controls to temporarily deactivate tables or service areas that should not accept new dining tickets.

Service-area and table deactivation is separate from deletion. Historical ticket and sale references remain preserved.

### B. Open a Dining Ticket
1. Open the POS terminal and go to the dining floor map.
2. Select an available active table.
3. Open a dining ticket and enter guest details when required.
4. The table becomes occupied or active according to the server-side ticket status.

The system blocks opening a second active primary ticket on the same table.

### C. Add and Manage Ticket Items
1. Open the active dining ticket.
2. Add products to the ticket.
3. Adjust quantity only while the ticket is still mutable.
4. Assign or move items between seats when needed.
5. Void an item only through the authorized dining item action.

Every successful dining mutation updates ticket revision and operational history. If another terminal changed the same ticket first, refresh the ticket before retrying.

### D. Split a Bill
1. Open the active dining ticket.
2. Choose the split mode:
   * **By Seat** for seat-based payment.
   * **By Item / Quantity** for item-level allocation.
3. Review the child tickets and totals before checkout.
4. Pay child tickets separately.

Promotion allocation, rounding allocation, and item allocation snapshots are preserved after the split. The system does not recalculate those allocations at child-ticket checkout.

### E. Checkout a Dining Ticket
1. Open the payable dining ticket or split child ticket.
2. Start checkout while the terminal is online.
3. The dining flow creates the sale through the existing POS sale authority.
4. Complete payment through the existing split-payment workflow.
5. The dining ticket closes only after sale creation and payment recording succeed.

If payment fails or the result is unknown, the ticket remains payable or settling according to the displayed state. Do not manually re-enter the sale unless support confirms the original checkout did not commit.

---

## 5. Online and Offline Rules

Dining mutations are online-only.

Blocked while offline:
* Opening dining tickets
* Adding, changing, moving, or voiding ticket items
* Saving bill splits
* Creating dining checkout sales
* Closing dining tickets

Allowed while offline:
* Viewing the cached floor map or cached ticket context when available
* Completing ordinary controlled offline cash sales outside the dining workflow, if the terminal is eligible

The cached dining floor map is read-only offline. Reconnect before changing table, ticket, split, or checkout state.

---

## 6. Expected Results

* Service areas and tables appear in the POS dining floor map.
* Active tables show server-authoritative ticket status.
* Ticket changes are auditable and protected by revision checks.
* Split child tickets preserve allocated item, promotion, and rounding snapshots.
* Dining checkout produces ordinary POS sales through the existing sale and payment pipeline.
* Existing inventory, receipt, tax, Z-read, settlement, and accounting flows remain authoritative.

---

## 7. Important Boundaries

* Dining is not a second checkout engine.
* Dining checkout does not directly create sales, sale items, payments, receipts, inventory effects, or compliance records.
* Parent split tickets are settlement containers and are not paid directly.
* Kitchen display, reservations, loyalty, store credit, QR ordering, and table merge workflows are future extensions unless separately enabled.
