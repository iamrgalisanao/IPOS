# Multi-Tenant SaaS Provisioning & Administration Research
## Comparative Analysis: UTAK, iRipple, Mosaic, and Ansi

**Research Date:** May 21, 2026  
**Status:** Comprehensive Documentation with Implementation Details  
**Applicable Platforms:** UTAK POS, iRipple Retail Management

---

## Executive Summary

This research analyzes multi-tenant SaaS provisioning patterns across industry-specific platforms serving Southeast Asian markets. Two platforms (UTAK and iRipple) implement substantive multi-tenant architectures for retail/POS operations, while Mosaic operates as a data API platform and Ansi is not applicable to SaaS provisioning contexts.

**Key Finding:** Both UTAK and iRipple emphasize **operational control**, **role-based access**, and **real-time compliance** over infrastructure-level tenant isolation, reflecting their positioning as operational systems rather than platform-as-a-service offerings.

---

## Platform Profiles

### 1. **UTAK POS** ✅ Multi-Tenant SaaS
- **Market:** Philippine SMBs (restaurants, cafes, retail)
- **Model:** Cloud-based subscription SaaS
- **Deployment:** 10,000+ businesses, 50+ years combined experience
- **Pricing:** From ₱1,500/month (software only)
- **Infrastructure:** AWS (bank-level security)

### 2. **iRipple** ✅ Enterprise Multi-Tenant SaaS
- **Market:** SE Asian enterprise retailers (Thailand, Malaysia, PNG, Philippines)
- **Model:** Platform suite with role-based provisioning
- **Scale:** 400+ retail brands, 10,000+ POS deployed, 1M+ receipts/day
- **Established:** 2000 (25 years retail tech), went public 2009, currently private
- **Product Suite:** 5 products (POS, RMS, BI, Loyalty, Mobile)

### 3. **Mosaic** ❌ Not Applicable
- **Type:** Cryptocurrency data/analytics API platform
- **Focus:** Blockchain research data delivery, not multi-tenant provisioning
- **Offering:** Tailored APIs for institutional cryptoasset analysis
- *Not suitable for this research*

### 4. **Ansi** ❌ Not Applicable
- **Context:** Either Cisco ACI (network fabric) or ANSI standards organization
- **Finding:** No public SaaS platform matching "Ansi" for tenant provisioning
- *Not suitable for this research*

---

## Comparative Analysis Matrix

| Dimension | **UTAK POS** | **iRipple Barter Suite** | Relevance |
|-----------|-----------|-----------|-----------|
| **Tenant Provisioning Approach** | Quick signup → cloud account creation | Sales-driven account provisioning + hardware deployment | UTAK: immediate, iRipple: enterprise-paced |
| **Architecture Pattern** | Single logical tenant per account | Single logical tenant per enterprise/brand | Both use account-level isolation, not DB-level |
| **Data Isolation** | AWS-backed cloud storage by account | Centralized multi-tenant DB, row-level filtering | iRipple more tightly coupled |
| **Feature Gating** | Plan-based (pricing tiers) + module toggles | Role/store-level permissions + feature assignment | Permission-driven model |
| **Readiness/Compliance** | Onboarding guides + manual setup | Dedicated support + compliance validation | iRipple more structured |
| **Admin Dashboard** | Single-store + multi-store view in back office | Barter RMS centralized control panel | Both provide HQ dashboards |
| **Audit/Governance** | Audit trails in transactions, staff attendance tracking | Store-to-store transfer audit trails + user access logs | iRipple richer audit surface |

---

## 1. TENANT MANAGEMENT UI

### **UTAK POS**

#### Account Creation Flow
- **Signup Process:** Web form → email verification → immediate account access
- **Back Office Portal:** Cloud-accessible dashboard at login.utak.io
- **Store Registration:** Add multiple stores during setup or post-signup
- **Display Elements:**
  - Store selector dropdown (multi-store navigation)
  - Dashboard showing: Sales, Transactions, Profit, Inventory, Expenses
  - Real-time sync status indicator (online/offline mode)

#### Multi-Store Capabilities
- **Multiple-Store Setup** explicitly listed as a feature
- **Centralized Control:** Unified reporting across branches
- **Store-Level Isolation:** Each store has independent transaction history
- **Features per Store:**
  - Separate inventory by location
  - Store-specific staff attendance
  - Configurable pricing/discounts per store

#### UI Pattern
```
UTAK Back Office Structure:
├── Dashboard (aggregated KPIs)
├── Reports (Transactions, Inventory, Staff)
├── Inventory Management
├── Sales Analytics
├── Staff Attendance
├── Settings
│   ├── Store Configuration
│   ├── Users & Roles
│   └── Hardware Management
└── Online Store (UTAK Online)
```

---

### **iRipple Barter RMS**

#### Tenant/Brand Management UI
- **Primary Interface:** "Complete Operational Control" dashboard
- **Navigation Pattern:** 
  - Brand/Enterprise selector (top-level)
  - Store hierarchy (HQ → Region → Store)
  - Role-based menu visibility

#### Store Network Display
- **Store Listing:** Centralized view of all locations with status indicators
- **Performance Dashboard:** Compare metrics across locations
- **Map View:** (inferred from retail UX patterns) Geographic visualization of stores
- **Store Details Panel:**
  - Store name, location, manager
  - Current inventory count
  - Today's sales total
  - Last sync timestamp

#### Hierarchical Access Control
- **HQ Level:** Brand-wide settings, bulk operations
- **Region Level:** (implied) Regional manager oversight
- **Store Level:** Local manager daily operations

#### UI Features
```
Barter RMS Structure:
├── Store Network View
│   ├── Store Performance Metrics
│   ├── Geographic Distribution
│   └── Store Status Indicators
├── Centralized Inventory
├── Price Management
├── Promotion Planning
├── Supplier Management
├── Store-to-Store Transfers
├── User Access Control
└── Reporting & Analytics
```

---

## 2. FEATURE GATING

### **UTAK POS**

#### Plan-Based Gating
**Pricing Tiers with Bundled Features:**

| Plan | Hardware | Software | Features Included |
|------|----------|----------|------------------|
| Software Only | ❌ | ✅ | Full POS, back office |
| Tablet | ✅ Lenovo + stand | ✅ | + tablet bundle |
| Full Set | ✅ + printer + drawer | ✅ | + hardware bundle |
| Lifetime | ✅ (all) | ✅ | + BIR permitting |
| MPOS | ✅ Mobile hardware | ✅ | Mobile-optimized |
| UTAK+ | ✅ Tablet + customer screen | ✅ | Premium bundle |

#### Module-Level Feature Toggles
**From documentation:**
- Discount controls (SNR/PWD buttons, regular discounts)
- Service charges (VAT vs non-VAT modes)
- Inventory tracking (enabled/disabled)
- Staff attendance (with selfie verification)
- Online ordering integration (UTAK Online)
- Customer loyalty tracking

#### Feature Availability by Context
- **Offline Mode:** Reduced features (transactions only, full sync on reconnect)
- **VAT Registration:** Alters tax calculation modes system-wide
- **Hardware Configuration:** Features scale with hardware (tablet-only vs full POS)

#### Implementation Pattern
```
UTAK Feature Gating:
├── Subscription Plan
│   ├── Hardware bundle (determines capabilities)
│   └── Software module enablement
├── Account Configuration
│   ├── VAT registration status
│   ├── Tax rules
│   └── Discount types available
└── Runtime Flags (session-based)
    ├── Offline mode (graceful degradation)
    └── Hardware availability (printer, drawer, scanner)
```

---

### **iRipple Barter Suite**

#### Role-Based Feature Access

**User Access Control Model** (from Barter RMS):
- **Define roles and permissions for different user levels**
- **Control what each team member can access and modify across the system**

#### Specific Feature Gates

**Barter POS:**
- Multiple payment methods (gated by payment processor integration)
- Promotion engine (rules-based, can be disabled)
- Offline mode (all POS, optional retention)
- Returns/exchanges (enabled by policy)

**Barter RMS:**
- Centralized inventory (enterprise feature)
- Price management (by-store or chain-wide)
- Store-to-store transfers (approval workflow)
- Promotion planning (campaign scheduling)
- Supplier management (PO workflow)

**Retina BI:**
- Real-time dashboard (requires data sync)
- Custom report builder (advanced)
- Profitability analysis (depends on cost data entry)
- Store performance comparison (multi-store only)

**MyRewards:**
- Points-based rewards (always-on)
- Tier-based benefits (configuration-driven)
- Personalized offers (requires member data)
- Campaign management (time-limited toggles)
- Member analytics (aggregate, privacy-compliant)

#### Feature Gating Implementation
```
iRipple Feature Gates:
├── User Role Permissions
│   ├── Cashier (POS operations only)
│   ├── Store Manager (store operations + reporting)
│   ├── Regional Manager (multi-store visibility)
│   └── Brand HQ (all features + compliance)
├── Product License
│   ├── Barter POS (always included)
│   ├── Barter RMS (enterprise tier)
│   ├── Retina BI (optional add-on)
│   ├── MyRewards (optional add-on)
│   └── Atlas App (optional add-on)
└── Integration Flags
    ├── Payment processors
    ├── Payment terminal support
    └── Third-party API integrations
```

---

## 3. ONBOARDING & READINESS TRACKING

### **UTAK POS**

#### Onboarding Checklist (Inferred from Help System)
**Setup Phases:**
1. **Device Setup** (via guided videos)
   - Tablet POS configuration
   - Printer setup
   - Scanner pairing
   - MPOS/hardware initialization

2. **Business Configuration**
   - Store name, location, business type
   - VAT registration status
   - Staff member creation
   - Payment methods

3. **Inventory Setup**
   - Product/menu item creation
   - Cost tracking
   - Category organization
   - Barcode assignment (optional)

4. **Hardware Installation**
   - Cash drawer connection
   - Receipt printer testing
   - Barcode scanner testing
   - Payment terminal pairing

#### Readiness Indicators
- **Device Status:** "Setup Complete" or "Awaiting Configuration"
- **Data Validation:** 
  - Required fields: Store name, at least 1 item, 1 user
- **System Health:** 
  - Internet connectivity (with graceful offline fallback)
  - AWS backend reachability
  - SIM card validity (if provided with UTAK tablet)

#### Compliance Tracking
- **BIR (Bureau of Internal Revenue) Accreditation**
  - "BIR Accredited" badge on dashboard
  - Lifetime plan includes "BIR Permit to Use Processing"
  - Audit trail generation for tax filing

#### Support Resources
- **UTAK Academy:** Self-paced training modules
- **Industry-specific courses** (F&B, Retail, Service)
- **1-paged guides** for common operations
- **Video tutorials** for hardware setup
- **Exercise quizzes** to validate learning (in Taglish)

---

### **iRipple Barter Suite**

#### Enterprise Onboarding Process
**Sales to Deployment (inferred from scale 25 years, 400+ brands):**

1. **Sales Phase**
   - Demo and consultation
   - Hardware specification matching
   - Custom configurations discussion
   - Pricing & contract negotiation

2. **Account Provisioning**
   - Brand creation in system
   - Store hierarchy setup
   - User role templates
   - Data migration (if applicable)

3. **Hardware Deployment**
   - POS terminal shipment (via Hanrio subsidiary)
   - Store-level installation
   - Network configuration
   - Payment terminal integration

4. **Training & Ramp**
   - Staff training (POS operations)
   - Manager training (RMS features)
   - HQ user training (Retina BI, analytics)
   - Loyalty program setup (if MyRewards enabled)

#### Readiness Gates (Concrete Examples from Product Descriptions)

| Readiness Aspect | Implementation |
|-----------|-----------|
| **Inventory Data** | "Track stock levels across all locations in real-time" → requires receiving/count data entry |
| **Pricing Consistency** | "Update pricing across all stores or specific locations" → centralized validation before deployment |
| **User Training** | Role-based access implies training completion tracking (implied) |
| **Payment Integration** | Multiple payment methods require terminal setup validation |
| **Store Network** | "Request, approve, and track transfers with full audit trails" → store status validation |

#### Compliance Checkpoints (Inferred)
- **Malaysia, Thailand, PNG, Philippines** → Each has tax/compliance requirements
- **Store-to-Store Transfers** audit trail (inventory accountability)
- **Payment Processing** audit trails (PCI compliance)
- **User Access Logs** (data governance)

#### Support Infrastructure
- **iRipple Support Portal:** https://support.iripple.com/
- **Hanrio Hardware Support** (subsidiary for equipment issues)
- **25 years of operations** → mature support playbooks
- **After-sales service** team for ongoing operations

---

## 4. ADMIN DASHBOARDS

### **UTAK POS Back Office**

#### Primary Admin Dashboard

**Real-Time Metrics Displayed:**
```
Dashboard Layout:
┌─────────────────────────────────┐
│ Store Selector   [Store A ▼]    │
├─────────────────────────────────┤
│ Total Net Sales        ₱45,230  │
│ Gross Sales           ₱52,000  │
│ Total Discounts        ₱6,770  │
│ No. of Transactions      145    │
│ Profit               ₱18,530  │
│ Total Refunds         ₱1,240  │
├─────────────────────────────────┤
│ [Date Range Picker]  [Export]   │
└─────────────────────────────────┘
```

#### Visibility Layers

**By Role (inferred from "Owner's Manual" distinction):**
- **Owner/Manager:** Full dashboard + expense tracking + staff attendance
- **Cashier:** Transaction-only view (if allowed)
- **Accountant:** Reports & analytics access

#### Key Report Categories

| Report | Granularity | Frequency |
|--------|-----------|-----------|
| **Dashboard Computations** | Daily summary | Real-time |
| **Transactions (HDM)** | Hourly, Daily, Monthly | Hourly |
| **Z-Reading** | Daily detailed breakdown | Per shift/day |
| **Product Mix** | By category, by item | Daily |
| **Cash Drawer** | Cash flow reconciliation | Shift-level |
| **Category & Item Summary** | SKU-level performance | Daily |
| **Service Charges** | VAT and non-VAT breakdown | Transaction-level |
| **Pax Discounts** | Discount analysis by type | Daily |

#### Admin-Specific Features

**Expense Tracking:**
- "Monitor expenses directly from the cash drawer"
- Cash added, cash out transactions tracked
- Separates cash vs. non-cash expenses

**Staff Oversight:**
- "Track staff attendance accurately with selfie-based time-in and time-out"
- Per-cashier transaction tracking
- Cashier summary reports

**System Health:**
- Device status (online/offline indicator)
- Last sync timestamp
- Data freshness indicator

---

### **iRipple Barter RMS Admin Dashboard**

#### Primary Admin Interface

**Centralized Control Panels:**

1. **Store Network View**
   - List/grid of all stores
   - Status indicators (online/offline, last sync)
   - Quick-access store metrics
   - Drill-down to detailed store view

2. **Real-Time Sales Dashboard** (Retina BI integration)
   ```
   Displays:
   - Daily/weekly/monthly sales trends
   - Sales by category
   - Sales by store
   - Comparison to targets/previous period
   - Top-performing products
   - Slow-moving inventory alerts
   ```

3. **Inventory Analytics**
   - Across-location stock levels
   - Turnover rates by product
   - Low-stock alerts
   - Overstock identification
   - Reorder point automation

4. **Store Performance Comparison**
   - Side-by-side metrics (sales, avg ticket, transactions)
   - Identify top/underperforming stores
   - Benchmark against chain averages
   - Regional comparison

#### Admin Capabilities

**Centralized Operations Control:**

| Capability | Admin Tools |
|-----------|-----------|
| **Price Management** | "Update pricing across all stores or specific locations" + "Schedule price changes" |
| **Inventory Control** | Centralized visibility + transfers approval + reorder automation |
| **Promotion Execution** | "Create and schedule promotional campaigns across stores" + "Set up discount rules, bundle offers" |
| **User Management** | "Define roles and permissions for different user levels" |
| **Supplier Oversight** | "Track vendor performance, manage purchase orders, monitor delivery schedules" |
| **Store-to-Store Transfers** | "Request, approve, and track transfers with full audit trails" |

#### Metrics & KPIs Exposed

**From Retina BI description:**
- Real-time sales dashboard (daily/weekly/monthly trends)
- Inventory metrics (turnover, slow-moving items)
- Customer analytics (patterns, basket size, repeat rates)
- Store comparison (performance vs. peers)
- Profitability analysis (by product, category, store)
- Custom report builder (self-service)

#### Data Governance

**Visibility Model:**
- **HQ Level:** Full chain visibility, all stores, all time periods
- **Region Manager:** Region stores only, limited historical depth
- **Store Manager:** Own store only, detailed intraday data
- **Cashier:** Transaction entry only (no dashboard access implied)

---

## 5. AUDIT & GOVERNANCE

### **UTAK POS**

#### Audit Trail Implementation

**Transaction-Level Auditing:**
- **Explicit Feature:** "Audit Trail - Monitor all system activities with detailed logs for transparency, accountability, and security"
- **Captured Data:**
  - Transaction ID, timestamp, user, amount
  - Items sold (with original price, discount applied, final price)
  - Payment method
  - Refund status (if applicable)

**Computation Audit Trail** (from documentation):
```
Transaction Tracking includes:
- Column A: Transaction ID (receipt number)
- Column B: Timestamp
- Column C: User/Cashier ID
- Column D-N: Item details (SKU, qty, price, discounts)
- Column O: VAT calculation breakdown
- Column P: Payment method
- Column Q: Refund indicator

Raw Data Export: Available via "Transaction Detail" page
Downloadable format for external audit
```

**Staff Tracking:**
- "Staff Attendance - Record staff time-in and time-out using selfie verification"
- Implications: Attendance audit trail with biometric verification
- Report: "Cashier Summary" includes per-cashier transaction volume

#### Compliance Tracking

**BIR (Bureau of Internal Revenue) Compliance:**
- "BIR accredited" - system pre-configured for Philippine tax requirements
- Z-Reading reports designed for tax audit readiness
- VAT calculations auditable (Inclusive VAT, VAT-Exempt, VATable breakdowns)
- Lifetime plan includes "BIR Permit to Use Processing"
- Receipts auto-formatted for regulatory compliance

**Data Privacy:**
- Data Privacy Officer contact provided
- AWS infrastructure provides bank-level security
- Cloud-based = automated backups (audit trail preservation)

#### State Change Tracking

**Implicit via Computations:**
- **Previous Reading** timestamp tracks when last Z-Reading occurred
- **Running Total** indicates cumulative state changes
- **Cancelled Tx** tracks reversals (refund audit trail)

#### Administrative Controls

**Account-Level:**
- User creation/deletion (implicit audit)
- Store configuration changes (implicit audit)
- Settings modifications (implicit, not documented)

---

### **iRipple Barter Suite**

#### Audit Trail Capabilities

**Store-to-Store Transfers - Explicit Audit Model:**
- **Feature:** "Request, approve, and track transfers with full audit trails and documentation"
- **Audit Events Captured:**
  - Transfer initiation (from store, timestamp, user)
  - Items included (SKU, quantity, cost)
  - Approval event (approver, timestamp)
  - Execution event (completion timestamp)
  - Reversal/adjustment events (if any)

**User Access Control Audit:**
- "Define roles and permissions for different user levels"
- "Control what each team member can access and modify across the system"
- Implication: Access logs per user action

**Financial Audit Trails (Inferred from Retina BI):**
- **Profitability Analysis** requires cost tracking
- All transactions routed through Barter POS (centralized logging)
- Cost of goods data linked to inventory movements

#### Compliance Tracking

**Multi-Country Compliance:**
- **Operating in:** Malaysia, Thailand, Papua New Guinea, Philippines
- **Each country** has distinct tax/compliance requirements
- **Centralized system** must enforce per-country rules
- **Evidence:** Promotion and pricing are store-configurable → likely per-country compliance

**Inventory Accountability:**
- "Cycle Counts Perform regular inventory counts without disrupting sales floor operations"
- "Compare actual counts against system records and reconcile discrepancies"
- **Audit Trail:** Variance tracking between system and physical counts

#### State Change Governance

**Promotion Management State Machine:**
```
Promotion Lifecycle (Inferred):
Draft → Scheduled → Active → Completed → Archived
                    ↓
                 Paused/Disabled

Audit Points:
- Creation: timestamp, creator, rules
- Scheduling: deployment window
- Activation: store list, time
- Completion: sales under promotion
- Reversal: if applicable
```

**Pricing Change Governance:**
- "Update pricing across all stores or specific locations"
- "Schedule price changes" → implies approval workflow (inferred)
- Audit trail requirements for financial controls

#### Administrative Controls

**Supplier Management State Tracking:**
- Track vendor performance (quality, on-time delivery)
- Monitor purchase orders (submitted, confirmed, fulfilled, invoiced)
- Delivery schedule tracking
- Implications: State machine for procurement process

**Store Status Management:**
- Store operational status (open, closed, holiday)
- Manager assignment changes
- Hardware status changes (POS online/offline tracking)

#### Data Governance & Privacy

**Inferred from 25-year operational history:**
- **GDPR-adjacent** (iRipple serves international retailers)
- **PCI-DSS** (payment data handling)
- **Customer Privacy** (MyRewards member data)
- **Employee Privacy** (staff access logs)

---

## Concrete Implementation Patterns

### Pattern 1: Tiered Admin Access

**UTAK Pattern:**
```
UTAK User Hierarchy:
Owner
├── Manager (Full dashboard access)
│   ├── Assistant Manager (Reports only)
│   └── Accountant (Financial reports only)
└── Cashier (Transaction entry only)
```

**iRipple Pattern:**
```
iRipple User Hierarchy:
Brand Owner
├── HQ Admin
│   ├── Retina BI Admin (analytics)
│   ├── Inventory Manager (transfers, reorders)
│   └── Promotion Manager (campaign planning)
├── Regional Manager
│   └── Regional Inventory Oversight
└── Store Manager
    ├── POS Manager
    ├── Inventory Manager (local)
    └── Cashier
```

---

### Pattern 2: Feature Gating by Subscription & Role

**UTAK Gating:**
```
Hardware Selection → Feature Capability
- Tablet only       → POS + inventory (no print)
- Full set         → POS + inventory + receipts + drawers
- UTAK+            → POS + dual screen + customer display

Role → Report Access
- Cashier          → Own transactions only
- Manager          → Store dashboard + expense tracker
- Owner            → Multi-store comparison + profit analysis
```

**iRipple Gating:**
```
License Purchase → Product Availability
Base: Barter POS (required)
+ Optional: Barter RMS, Retina BI, MyRewards, Atlas

Role → Feature Access
Cashier
  ├── POS operations
  └── Own shift metrics

Store Manager
  ├── Store-level RMS (inventory, transfers, pricing local)
  ├── Store metrics (Retina BI read-only)
  └── MyRewards management (local)

Regional Manager
  ├── Multi-store visibility (Retina BI)
  ├── Cross-store transfers (approve/reject)
  └── Regional promotions

HQ
  ├── All features
  ├── Compliance reporting
  └── Supplier management
```

---

### Pattern 3: Readiness & Onboarding State Machines

**UTAK Readiness:**
```
Signup
  ↓
[Device Setup Required]
  ├─ Tablet configuration (video guide)
  ├─ Hardware pairing (printer, scanner)
  └─ SIM activation (if applicable)
  ↓
[Business Configuration Required]
  ├─ Store details
  ├─ Staff creation
  └─ Product catalog (at least 1 item)
  ↓
[Compliance Setup]
  ├─ VAT registration status
  └─ BIR accreditation (optional)
  ↓
Ready to Process Transactions
  ↓
[Ongoing: Real-time data sync via AWS]
```

**iRipple Readiness:**
```
Sales Conversation
  ↓
[Custom Configuration]
  ├─ Store hierarchy
  ├─ Hardware specification
  ├─ User roles
  └─ Integration selection
  ↓
[Hardware Manufacturing & Deployment]
  ├─ POS terminal preparation
  └─ Hanrio logistics
  ↓
[Onsite Installation]
  ├─ Hardware setup
  ├─ Network configuration
  ├─ POS initialization
  └─ Payment processor link
  ↓
[Staff Training]
  ├─ Cashier training (POS)
  ├─ Manager training (RMS)
  └─ HQ training (Retina BI)
  ↓
[Go Live]
  ├─ Pilot period (usually 1-2 stores)
  └─ Phased rollout across chain
  ↓
[Ongoing Support]
```

---

## Notable Strengths & Gaps

### **UTAK POS**

#### Strengths
✅ **Rapid Provisioning:** Signup to first transaction in hours (cloud-based SaaS)  
✅ **Built-in Compliance:** BIR-accredited system, tax calculations pre-validated  
✅ **Offline Resilience:** Transactions continue even without internet (auto-sync on reconnect)  
✅ **Transparent Computations:** Every calculation documented (VAT formulas, discount rules)  
✅ **Multi-Store Centralization:** Unified dashboard for business owners with multiple locations  
✅ **Self-Service Training:** UTAK Academy with industry-specific courses  

#### Gaps
❌ **Limited API Documentation:** No public API reference (cloud features not exposed)  
❌ **Audit Trail Details:** Documented at high level, not detailed API specification  
❌ **Feature Customization:** Limited ability to customize report formats or workflows  
❌ **User Provisioning:** No self-service user management (must contact support for new staff)  
❌ **Data Export:** Limited export formats (implicit: likely CSV/Excel only)  
❌ **International Expansion:** BIR-focused, not multi-country compliant out-of-box  

---

### **iRipple Barter Suite**

#### Strengths
✅ **Enterprise-Ready:** 25-year track record, 400+ brands, proven at scale  
✅ **Multi-Country Compliance:** Operating in 4 countries, locally adapted  
✅ **Integrated Ecosystem:** 5-product suite (POS, RMS, BI, Loyalty, Inventory)  
✅ **Comprehensive Audit Trails:** Store-to-store transfers, user access, supplier tracking  
✅ **Role-Based Governance:** Granular permissions per user level and store  
✅ **Hardware Integration:** Subsidiary (Hanrio) ensures seamless device supply & support  

#### Gaps
❌ **Public API Unavailable:** No documented APIs for integrations  
❌ **Onboarding Documentation:** Support portal requires login (no public readiness checklist)  
❌ **GitHub Presence:** Only 1 public repo (RadOutRNG), no architectural documentation  
❌ **White-Label Options:** Designed for B2B2C, not white-label deployment  
❌ **Tenant Isolation Architecture:** No documentation on data isolation strategy  
❌ **Customization Scope:** Limited flexibility for non-standard workflows  

---

### **Mosaic (Cryptocurrency Data)**

#### Why Not Applicable
- **Not a multi-tenant provisioning platform** → Data API delivery
- **No tenant concept** → Institutions consume data, not run operations
- **No admin dashboard** → Research/analysis interfaces only
- **No feature gating** → Data modules are API-based, not UI-based

---

### **Ansi (Not Found/Not Applicable)**

#### Research Outcome
- **No SaaS platform identified** matching "Ansi" for multi-tenant provisioning
- **Possible references:**
  - Cisco ACI (network fabric, not SaaS)
  - ANSI standards organization (not applicable)
  - Internal platform not publicly documented
- **Recommendation:** Clarify platform name/context if pursuing further research

---

## Key Takeaways for IPOS Implementation

### 1. **Provisioning Model Selection**
- **UTAK Pattern:** Rapid self-service → suitable for SMBs, high volume
- **iRipple Pattern:** Sales-driven provisioning → suitable for enterprise accounts
- **Hybrid:** Fast signup for trials, sales-driven for premium tiers

### 2. **Feature Gating Strategy**
- **Both platforms use:** Plan-based + role-based gating
- **Concrete implementation:**
  - Plan tier determines available products/modules
  - Role determines UI/API access within available features
  - Subscription status gates all features (not just premium ones)

### 3. **Audit Trail Requirements**
- **Minimum viable:** Transaction ID, timestamp, user, amount, payment method
- **Enhanced:** Previous state tracking, approval workflows, reversal tracking
- **Enterprise:** Multi-step workflows with audit at each step

### 4. **Admin Dashboard Principles**
- **Real-time metrics** over historical reporting (both platforms emphasize "real-time")
- **Drill-down capability** from summary to detail (store → transaction)
- **Role-based visibility** (not just role-based features)
- **Compliance-first reporting** (audit trails, tax calculations, approval status)

### 5. **Onboarding Readiness**
- **Success metric:** First transaction within hours (UTAK) or days (iRipple)
- **Required states:** Device ready, business configured, compliance validated, users trained
- **State tracking:** Make readiness visible, block advanced features until prerequisites met

---

## Appendix: Reference Links

### UTAK POS
- Website: https://www.utak.io/
- Help/Training: https://www.utak.io/help
- Support: connect@utak.io, +63-927-436-0918
- Back Office Login: https://login.utak.io/

### iRipple
- Website: https://www.iripple.com/
- Products: https://www.iripple.com/products
- Support: https://support.iripple.com/
- Sales: sales@iripple.com

### Mosaic (Reference Only)
- Website: https://mosaic.io/
- Focus: Cryptoasset data research, not multi-tenant SaaS

---

## Research Methodology

**Data Sources:**
1. Public websites (UTAK, iRipple, Mosaic)
2. Product documentation & help systems
3. GitHub organization profiles (UTAK, iRipple)
4. Support portal access (login required for iRipple)
5. Company history & press releases

**Limitations:**
- No API-level access to either platform
- Onboarding flows inferred from UI descriptions (not direct testing)
- Feature gating mechanisms documented but not tested
- Enterprise workflows inferred from marketing descriptions

**Confidence Levels:**
- ⚠️ **High (Published Documentation):** Admin dashboards, feature lists, core workflows
- ⚠️ **Medium (Inferred):** Onboarding state machines, audit trail depth, custom integration options
- ⚠️ **Low (Speculative):** Exact API schemas, tenant isolation architecture, provisioning automation

---

**Document Created:** 2026-05-21  
**Last Updated:** 2026-05-21  
**Status:** Complete Research & Documented
