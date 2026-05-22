# Multi-Tenant SaaS Provisioning: Quick Reference Comparison

## Comparison Table: UTAK vs iRipple vs Mosaic vs Ansi

| Aspect | UTAK POS | iRipple Barter Suite | Mosaic | Ansi |
|--------|----------|-----------|--------|------|
| **Platform Type** | ✅ Multi-Tenant Retail SaaS | ✅ Enterprise Multi-Tenant RMS | ❌ Data API (not SaaS) | ❌ Not Applicable |
| **Primary Market** | Philippine SMBs | SE Asian Enterprise Retailers | Crypto Research | N/A |
| **Scale** | 10,000+ businesses | 400+ brands, 10,000+ POS | Institutional APIs | N/A |
| **Deployment Model** | Cloud (AWS) | Hybrid (Cloud + On-Prem) | SaaS APIs | N/A |

---

## 1. Tenant Provisioning Approach

| Dimension | UTAK POS | iRipple |
|-----------|----------|---------|
| **Flow** | Self-serve signup → AWS account | Sales call → Custom provisioning |
| **Speed** | Hours to first transaction | Days to weeks (hardware delivery) |
| **Automation** | High (instant account creation) | Medium (sales-driven) |
| **Tenant Isolation** | Account-level (logical) | Account-level (logical) |
| **Multi-Store Support** | Native (dropdown selector) | Native (hierarchy) |
| **Hardware Coupling** | Soft (works on any tablet) | Tight (via Hanrio subsidiary) |

---

## 2. Feature Gating Mechanism

| Mechanism | UTAK POS | iRipple |
|-----------|----------|---------|
| **Model** | Plan + Role + Hardware | Plan License + Role |
| **Plans** | 6 pricing tiers (Software only to Premium bundles) | Custom (à la carte: POS, RMS, BI, Loyalty, Inventory) |
| **Role-Based** | Yes (Owner, Manager, Cashier) | Yes (5+ levels: Cashier → HQ Admin) |
| **Offline Gates** | Limited features in offline mode | Full features if cached |
| **Module Toggles** | Discounts, Service Charges, Inventory, Loyalty (implicit) | Explicit per-product licensing |
| **VAT Handling** | Configurable (affects calculations) | Per-country rules |

---

## 3. Readiness & Compliance Tracking

| Tracking Type | UTAK POS | iRipple |
|---------------|----------|---------|
| **Onboarding Phases** | 4 (Device setup, Config, Compliance, Ready) | 6 (Sales, Config, Hardware, Installation, Training, Go-Live) |
| **Compliance Gates** | BIR accreditation, VAT registration | Multi-country tax rules, PCI-DSS |
| **Training Validation** | UTAK Academy self-paced courses | Dedicated support + training staff |
| **State Visibility** | Dashboard → Settings shows setup status | Inferred (support portal required) |
| **Readiness Blockers** | At least 1 product, 1 user required | Store hierarchy, user roles, hardware online |
| **Documentation** | Public (UTAK Academy) | Support portal (login required) |

---

## 4. Admin Dashboard Features

| Metric | UTAK POS | iRipple |
|--------|----------|---------|
| **Real-Time View** | ✅ Real-time sync via AWS | ✅ Real-time Retina BI |
| **Primary Metrics** | Sales, Transactions, Profit, Inventory | Sales, Inventory, Customer behavior, Profitability |
| **Multi-Store Comparison** | Dashboard view (summary) | Dedicated "Store Performance Comparison" |
| **Drill-Down Depth** | Dashboard → Reports → Transaction Detail | Store → Metrics → Transaction history |
| **Custom Reports** | Limited (predefined + export) | Custom Report Builder (Retina BI) |
| **Visibility per Role** | Implicit | Explicit role-based UI |
| **Hardware Status** | Online/Offline indicator | Last sync timestamp |
| **Expense Tracking** | Yes (Cash drawer monitoring) | Implicit (via transfers) |
| **Staff Oversight** | Attendance + Cashier Summary | Implicit (user access logs) |

---

## 5. Audit & Governance Controls

| Control | UTAK POS | iRipple |
|---------|----------|---------|
| **Transaction Audit Trail** | ✅ Explicit (Audit Trail feature) | ✅ Barter POS core functionality |
| **Granularity** | Receipt-level (transaction ID, items, amounts) | Transaction + Transfer + User access |
| **Tax Compliance** | BIR-specific (Z-Reading, VAT breakdown) | Multi-country (Malaysia, Thailand, PNG, PH) |
| **Staff Tracking** | Selfie-verified attendance + Cashier summary | User access control + role logs |
| **State Change History** | Z-Reading (daily snapshot) | Store-to-store transfers (approval workflow) |
| **Reversal Tracking** | "Cancelled Tx" count + amount | Transfer reversals (inferred) |
| **Data Privacy** | AWS bank-level + Data Privacy Officer | Inferred (25-year history) |
| **Supplier Management** | ❌ Not applicable (SMB) | ✅ Vendor performance, PO tracking |

---

## 6. Comparison: Strengths & Gaps

### UTAK POS
**Strengths:**
- ✅ Rapid provisioning (cloud-native)
- ✅ Built-in BIR compliance (audit-ready)
- ✅ Offline-first architecture
- ✅ Transparent tax calculations
- ✅ Self-service training

**Gaps:**
- ❌ No public API documentation
- ❌ Limited customization
- ❌ Single country (Philippines)
- ❌ No data export options documented
- ❌ No third-party integrations

---

### iRipple Barter Suite
**Strengths:**
- ✅ Enterprise-proven (25 years, 400+ brands)
- ✅ Multi-country compliance built-in
- ✅ Integrated ecosystem (5 products)
- ✅ Granular audit trails
- ✅ Hardware supply chain managed (Hanrio)

**Gaps:**
- ❌ No public API documentation
- ❌ Sales-driven provisioning (slower onboarding)
- ❌ Support portal behind login
- ❌ Limited architecture transparency
- ❌ No white-label options

---

### Mosaic (Not Applicable)
- Type: Data API, not multi-tenant provisioning platform
- Use case: Cryptocurrency research data delivery
- Not suitable for this comparison

---

### Ansi (Not Found)
- Status: No public SaaS platform identified
- Possible confusion: Cisco ACI (network fabric) or ANSI standards org
- Recommendation: Clarify platform name

---

## Implementation Insights for IPOS

### 1. Provisioning Strategy
**Recommendation:** Hybrid approach
- Fast-track: Self-serve signup for SMB tier (like UTAK)
- Enterprise: Sales-driven for custom configs (like iRipple)

### 2. Feature Gating
**Recommendation:** Layered model
```
Tier Determination (Plan)
  ↓
Role Determination (User)
  ↓
Subscription Status Check (Active/Expired)
  ↓
Feature Access Decision
```

### 3. Onboarding Readiness
**Recommendation:** Visible state machine
- Track: Device Setup → Business Config → Compliance → Ready
- Show progress to user
- Block advanced features until prerequisites met

### 4. Admin Dashboard
**Recommendation:** Role-based visibility
- HQ: All stores, all metrics, compliance focus
- Regional: Region stores only, performance focus
- Store: Own location only, operational focus
- Real-time preferred over batch reporting

### 5. Audit Trails
**Recommendation:** Multi-layered approach
- Minimal: Transaction ID, timestamp, user, amount
- Enhanced: State changes, approvals, reversals
- Enterprise: Full workflow audit with context

---

## Key Statistics (from research)

| Stat | UTAK | iRipple |
|------|------|---------|
| Years Operating | 50+ combined | 25 years |
| Businesses Served | 10,000+ | 400+ brands |
| Countries | 1 (Philippines) | 4 (PH, MY, TH, PNG) |
| POS Deployed | Implicit (10K+ businesses) | 10,000+ |
| Daily Transactions | Implied high-volume | 1M+ receipts/day |
| Subscription Cost | From ₱1,500/month | Custom pricing |
| Products | 1 (core POS) | 5 (POS, RMS, BI, Loyalty, Inventory) |
| Support Model | Community + Chat | Dedicated enterprise support |

---

## Quick Decision Matrix

**Choose UTAK Pattern If:**
- ✅ SMB market focus (high volume, lower complexity)
- ✅ Self-service provisioning preferred
- ✅ Single country, single tax regime
- ✅ Fast onboarding critical
- ✅ Cloud-only deployment

**Choose iRipple Pattern If:**
- ✅ Enterprise customers (lower volume, high complexity)
- ✅ Multi-country/multi-regulatory requirements
- ✅ Integrated ecosystem required
- ✅ Deep audit trails needed
- ✅ Hardware supply chain important

**Hybrid Approach If:**
- ✅ Serving both SMB and Enterprise
- ✅ Need flexibility in provisioning
- ✅ Multi-country expansion planned
- ✅ Ecosystem partnerships needed

---

## References

1. **UTAK POS** - https://www.utak.io/
2. **iRipple** - https://www.iripple.com/
3. **iRipple About** - https://www.iripple.com/about
4. **iRipple Products** - https://www.iripple.com/products
5. **iRipple Barter RMS** - https://www.iripple.com/products/barter-rms
6. **Mosaic** - https://mosaic.io/ (Data API platform, not applicable)

---

**Last Updated:** 2026-05-21  
**Research Status:** Complete  
**Accuracy:** Based on public documentation + inferred patterns
