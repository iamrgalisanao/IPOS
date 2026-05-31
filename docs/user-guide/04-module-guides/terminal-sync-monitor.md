# Terminal Sync Diagnostics & Reliability User Guide

Status: Validated  
Last Updated: 2026-05-30  
System Area: Back Office → Admin Settings  
User Roles: Owner/Admin, Support Team

---

## 1. Purpose

The Terminal Sync Diagnostics module provides support teams and administrators with deep visibility into the synchronization logs between the local POS terminal cache and the IPOS Core server. It prevents data loss by exposing sync latency, missing terminal sequence ranges, and format errors.

---

## 2. Who Can Use This Feature

This feature contains sensitive operational and diagnostic configurations. Access is restricted to:
* **Owner / Admin**
* **Technical Support / System Engineers**

---

## 3. Access Path

Go to:
* **Main Menu → Administration → Sync Diagnostics**
* **Main Menu → Sandbox → Payload Validation**

---

## 4. Operational Instructions

### A. Monitoring Terminal Sync Statuses
1. Navigate to **Administration → Sync Diagnostics**.
2. Review the list of active terminals. Each row displays:
   * **Terminal ID** & **Assigned Branch**
   * **Last Heartbeat / Contact Timestamp**
   * **Sync Latency** (Time elapsed since the last transaction was pushed)
   * **Connection Status** (Green = Online/Sync Current, Amber = Sync Lagging, Red = Offline)

### B. Validating Terminal Sequence Registry
Terminal-bound sequence numbering prevents offline sales from overwriting invoice sequences:
1. Open the details page for a target POS terminal.
2. Review the **Sequence Registry Ledger**.
3. If the terminal sequence registry shows missing sequences (e.g. gap between sequence #104 and #106), the dashboard will flag a warning.
4. *Action*: Check the terminal's local queue buffer to trigger an manual re-upload of sequence #105.

### C. Utilizing the Sandbox Payload Validator
Before registering a new terminal build or troubleshooting a payload structure error:
1. Navigate to **Sandbox → Payload Validation**.
2. Paste the raw terminal JSON payload.
3. Click **[Validate Payload]**.
4. The validator will check the structure, tax models, currency precision, and UUID formats.
5. If validation passes, the screen displays a success indicator; if it fails, it highlights the exact JSON path and schema mismatch reason.
