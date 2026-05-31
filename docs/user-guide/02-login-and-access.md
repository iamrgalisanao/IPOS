# 02. Login and Access Control

Status: Validated  
Last Updated: 2026-05-30  
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
| Manage Own Cash Drawer | ✅ | ✅ | ❌ | ✅ |
| Record Surprise Spot Audits | ❌ | ✅ | ❌ | ✅ |
| Approve Shifts & Reconcile Deposits | ❌ | ✅ | ❌ | ✅ |
| Initialize & Post Stocktakes | ❌ | ✅ | ❌ | ✅ |
| Capture Expiry Lots on Intake | ❌ | ✅ | ❌ | ✅ |
| Authorize Inter-Branch Transfers | ❌ | ✅ | ❌ | ✅ |
| Seal Daily Settlement Periods | ❌ | ❌ | ✅ | ✅ |
| Match AP Invoices (3-Way AP) | ❌ | ❌ | ✅ | ✅ |
| Manage QuickBooks Integration | ❌ | ❌ | ✅ | ✅ |
| Configure Products & Suppliers | ❌ | ❌ | ❌ | ✅ |
| Provision Tenant Users & RBAC | ❌ | ❌ | ❌ | ✅ |

---

## 4. User Onboarding (Admins Only)

To add new staff members to the platform:
1. Go to **Administration → User Management**.
2. Click **[Invite User]**.
3. Enter their name, corporate email address, and select their primary **Branch** assignment.
4. Choose their assigned **Role Profile** (Cashier, Branch Manager, Accountant, Owner/Admin).
5. Click **[Send Invite]**. The user will receive an email containing credentials setup instructions.
