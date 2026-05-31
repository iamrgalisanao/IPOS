# Data Exports & Tax Reporting User Guide

Status: Validated  
Last Updated: 2026-05-31  
System Area: Back Office → Reports  
User Roles: Owner/Admin, Accountant

---

## 1. Purpose

The Data Exports module is designed for enterprise-scale report generation, specifically for compliance and tax reporting such as the BIR Electronic Journal (E-Journal). Because these reports often span large date ranges and massive amounts of data, they are processed asynchronously in the background.

---

## 2. Access Path

Go to:
* **Main Menu → Reports → Tax Reporting** (to generate reports)
* **Main Menu → Reports → Data Exports** (to monitor and download generated reports)

---

## 3. Screen Overview

* **Tax Reporting Dashboard**: Allows users to filter and request specific compliance reports like the E-Journal.
* **Data Exports Dashboard**: Displays a list of all requested exports. It shows the status of each export (Pending, Processing, Completed, Failed, Expired), when it was requested, and when it will expire.

---

## 4. Operational Instructions

### A. Generating a Tax Report (E-Journal)
1. Navigate to **Reports → Tax Reporting**.
2. Select the desired date range and optional filters (Branch, Terminal).
3. Click **[Export E-Journal]**.
4. The system will dispatch a background job to compile the data and redirect you to the Data Exports dashboard. You will see a flash message indicating the report is being processed.
*Note: You cannot request the exact same report if one is already pending or processing.*

### B. Monitoring and Downloading Exports
1. Navigate to **Reports → Data Exports**.
2. Review the list of your requested exports. The status will update as the system processes the file.
3. Once the status is **Completed**, a **[Download]** button will appear.
4. Click **[Download]** to securely save the generated CSV file to your local machine.

### C. Export Retention Policy
For data privacy and storage management, generated exports are securely stored on private cloud storage but are subject to a **48-hour retention policy**.
* After 48 hours, the physical file is automatically deleted.
* The export record will remain on your dashboard with an **Expired** status. If you need the file again, you must generate a new export.

---

## 5. Expected Results

* Large reports are processed in the background without causing browser timeouts.
* Only authorized users (e.g., Owner/Admin, Accountant) can access and download these sensitive compliance exports.
* The system protects data integrity with HMAC-SHA-256 tamper-evident hashing on E-Journal rows.
* Storage is automatically managed by the 48-hour retention policy.
