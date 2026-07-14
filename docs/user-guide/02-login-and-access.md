# 02. Login and Access Control

Status: Validated
Last Updated: 2026-07-14
System Area: Identity, Security, Access Settings
User Roles: All Roles

---

## 1. Authentication Paths

IPOS uses multi-tenant secure isolation rules. Accessing the system depends on your role:

* **Back Office Portal**: Access via the web application using your corporate email and password.
* **POS Checkout Register**: Access via the POS Terminal screen. Cashiers sign in using their unique cashier pin.

---

## 2. Multi-Branch Context Switching

If you are assigned to multiple branch locations (e.g., an Accountant, Area Manager, or Admin):
1. Log in to the Back Office.
2. Click on the **Branch Selector** dropdown located at the top-right corner of the navigation bar.
3. Select your target branch.
4. *Access Scope*: The data displayed in reporting dashboards, inventories, and shifts will filter immediately to reflect only the selected branch.

> [!IMPORTANT]
> If you do not select a branch, the system will apply your default assigned branch context. Cashiers are restricted to their active terminal's branch and cannot switch branch contexts.

---

## 3. System Roles & Permissions Matrix

The IPOS system enforces strict capability gating. The table below outlines what functions each role is authorized to perform:

| Feature / Capability | Cashier | Branch Manager | Accountant | Owner/Admin |
| :--- | :---: | :---: | :---: | :---: |
| Access POS Terminal & Sales | ✅ | ✅ | ❌ | ✅ |
| Manage Dining Tickets & Tables at POS | ✅ | ✅ | ❌ | ✅ |
| Manage Own Cash Drawer | ✅ | ✅ | ❌ | ✅ |
| Record Surprise Spot Audits | ❌ | ✅ | ❌ | ✅ |
| Approve Shifts & Reconcile Deposits | ❌ | ✅ | ❌ | ✅ |
| Initialize & Post Stocktakes | ❌ | ✅ | ❌ | ✅ |
| Capture Expiry Lots on Intake | ❌ | ✅ | ❌ | ✅ |
| Authorize Inter-Branch Transfers | ❌ | ✅ | ❌ | ✅ |
| Seal Daily Settlement Periods | ❌ | ❌ | ✅ | ✅ |
| Match AP Invoices (3-Way AP) | ❌ | ❌ | ✅ | ✅ |
| Manage QuickBooks Integration | ❌ | ❌ | ✅ | ✅ |
| Manage Promotions | ❌ | ❌ | ❌ | ✅ |
| Configure Service Areas & Dining Layouts | ❌ | ✅ | ❌ | ✅ |
| Configure Terminal Profiles & Config Snapshots | ❌ | ❌ | ❌ | ✅ |
| Configure Products & Suppliers | ❌ | ❌ | ❌ | ✅ |
| Provision Tenant Users & RBAC | ❌ | ❌ | ❌ | ✅ |

---

## 4. User Onboarding (Admins Only)

To add or update staff members:
1. Go to **Administration → User Management**.
2. Click **New User** to create a staff profile, or **Edit** beside an existing user.
3. Enter their first name, last name, email address, password information, and account status.
4. Assign at least one **Role** and one **Branch**.
5. For POS cashiers, set a 4-6 digit **POS PIN** so they can use the terminal lock screen and timecard clock.
6. Save the profile. The **POS Ready** column shows whether the user can sell and whether a PIN is set.

---

## 5. POS Lock Screen & Employee Timecards

To ensure strict labor compliance and cash drawer accountability, cashier registers enforce timecard clock-in status:
* **Lock Screen PIN Toggling**: Any employee can verify their PIN directly on the POS register lock screen (switching to the **Timecard Clock** tab) to clock in or clock out.
  - This action does *not* require authenticating a full cashier user session.
  - The register will record the IP address, terminal identifier, and timestamps for HR audit trail purposes.
  - The terminal must be a valid registered sales machine for the active tenant and branch.
* **Enforced Clock-In**: A cashier must have an active, clocked-in timecard before they can perform cashier-controlled operations:
  - Opening a cashier shift
  - Validating checkouts or completing sales
  - Opening or mutating dining tickets
  - Voiding items or issuing refunds
  - Recording cash drawer pay-in/out events
* **Security Lockouts**: To prevent brute-force attacks, entering an incorrect PIN repeatedly triggers a temporary lockout block:
  - 5 failed attempts locks the terminal for 1 minute.
  - 10 failed attempts locks the terminal for 15 minutes.
* **Shift Closing Blocker**: A cashier cannot clock out of their timecard if they still have an active, open cash drawer shift. The open shift must be fully closed and reconciled first (or overridden by a manager).
