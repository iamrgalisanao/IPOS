<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Role;
use App\Models\Permission;

class RbacSeeder
{
    /**
     * Seed default roles and permissions for a specific tenant.
     */
    public function seedForTenant(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);

        $permissions = $this->getPermissions();
        $seededPermissions = [];

        foreach ($permissions as $name => $description) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['description' => $description]
            );

            if ($permission->description !== $description) {
                $permission->forceFill(['description' => $description])->save();
            }

            $seededPermissions[$name] = $permission;
        }

        $roles = $this->getRoles();

        foreach ($roles as $roleName => $roleData) {
            $role = Role::firstOrCreate(
                ['name' => $roleName],
                ['description' => $roleData['description']]
            );

            if ($role->description !== $roleData['description']) {
                $role->forceFill(['description' => $roleData['description']])->save();
            }

            $permissionIds = collect($roleData['permissions'])
                ->map(fn ($permName) => $seededPermissions[$permName]->id ?? null)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($permissionIds);
        }
        
        app(TenantContext::class)->clear();
    }

    /**
     * Predefined capability keys.
     */
    protected function getPermissions(): array
    {
        return [
            // POS Operations
            'access_pos' => 'Can access POS interface',
            'create_sale' => 'Can create sales transactions',
            'apply_discount' => 'Can apply discounts to sales',
            'pos.approve_discount' => 'Can independently approve statutory discounts',
            'open_shift' => 'Can open a new POS shift',
            'close_shift' => 'Can close an active POS shift',
            'approve_shift' => 'Can review and approve submitted POS shifts',
            'view_shift' => 'Can access shift summary and history',
            'view_all_shifts' => 'Can view shifts across all branches',
            'view_branch_shifts' => 'Can view all shifts within assigned branches',
            'view_own_shift_summary' => 'Can view own shift performance',
            'manage_cash_drawer' => 'Can record cash drawer operational events',

            // Branch Operations
            'view_branch_dashboard' => 'Can view branch level dashboard',
            'manage_branch_inventory' => 'Can manage branch inventory',
            'approve_void' => 'Can approve transaction voids',
            'approve_refund' => 'Can approve transaction refunds',
            'view_branch_reports' => 'Can view branch level reports',
            'close_branch_day' => 'Can close branch business day',

            // Owner/Admin
            'manage_users' => 'Can manage tenant users',
            'manage_roles' => 'Can manage tenant roles',
            'manage_branches' => 'Can manage tenant branches',
            'manage_products' => 'Can manage tenant product catalog',
            'manage_payment_methods' => 'Can manage payment configurations',
            'manage_tax_categories' => 'Can manage tax settings',
            'manage_receipt_settings' => 'Can manage receipt templates',
            'view_multi_branch_dashboard' => 'Can view cross-branch data',
            'view_reports' => 'Can view general reports and pulse dashboard',
            'export_reports' => 'Can export financial reports',

            // Accounting
            'connect_quickbooks' => 'Can configure QuickBooks integration',
            'manage_quickbooks_connection' => 'Can manage QuickBooks onboarding and connection state',
            'configure_accounting_mapping' => 'Can manage accounting mappings',
            'manage_accounting_mappings' => 'Can manage accounting mapping UI and status',
            'manage_settlement_periods' => 'Can manage settlement period lifecycle',
            'view_settlement_periods' => 'Can view settlement period review data',
            'view_sync_dashboard' => 'Can view integration status',
            'retry_failed_sync' => 'Can retry failed sync tasks',
            'manually_resolve_sync' => 'Can manually resolve sync issues',
            'ignore_sync_exception' => 'Can ignore sync warnings',
            'view_reconciliation_reports' => 'Can view reconciliation data',
            'export_accounting_reports' => 'Can export accounting data',

            // Sales History
            'view_sales_history' => 'Can view transactional history index',
            'view_sale_details' => 'Can view individual transaction details and reversals',
            'export_sales_history' => 'Can export transactional history as CSV for audit purposes',

            'pos-layouts.view' => 'Can view custom POS layouts',
            'pos-layouts.manage' => 'Can create and edit custom POS layouts',
            'pos-layouts.publish' => 'Can publish POS layouts to branches',

            // Inventory Operations
            'inventory.stocktake.view' => 'Can view the stocktake history',
            'inventory.stocktake.create' => 'Can initialize a new count',
            'inventory.stocktake.count' => 'Can record quantities in an active session',
            'inventory.stocktake.review' => 'Can perform supervisor review and variance analysis',
            'inventory.stocktake.approve' => 'Can final authorization for inventory posting',
            'inventory.stocktake.post' => 'Can trigger the inventory movement generation',
            'inventory.stocktake.cancel' => 'Can abort a non-posted session',
            'inventory.adjustment.view' => 'Can view manual stock adjustment history',
            'inventory.adjustment.create' => 'Can record a one-off stock adjustment',
            'inventory.adjustment.approve' => 'Can approve one-off stock adjustments',

            // Epic 17: Cashier Accountability & Shift Report Export
            'reports.cashier-accountability.view' => 'Can view cashier accountability summaries',
            'reports.cashier-accountability.export' => 'Can export cashier accountability data',
            'reports.shift-summary.view' => 'Can view Z-report style shift summaries',
            'reports.shift-summary.export' => 'Can export shift summary reports',

            // Epic 20: Supplier Directory & Procurement
            'procurement.suppliers.view' => 'Can view supplier profiles',
            'procurement.suppliers.manage' => 'Can manage supplier directory',
            'procurement.purchase-orders.view' => 'Can view purchase orders',
            'procurement.purchase-orders.create' => 'Can create and edit purchase orders',
            'procurement.purchase-orders.approve' => 'Can review and approve purchase orders',
            'procurement.purchase-orders.export' => 'Can export purchase orders',
            'procurement.receiving.view' => 'Can view goods receiving vouchers',
            'procurement.receiving.create' => 'Can create and manage goods receiving drafts',
            'procurement.receiving.post' => 'Can post and commit goods receiving vouchers',
            'procurement.receiving.export' => 'Can export goods receiving vouchers',
            'procurement.returns.view' => 'Can view supplier returns',
            'procurement.returns.create' => 'Can create and manage supplier return drafts',
            'procurement.returns.approve' => 'Can review and approve supplier returns',
            'procurement.returns.post' => 'Can post and commit supplier returns',
            'procurement.returns.export' => 'Can export supplier returns',
            
            // Epic 27: Ingredient Inventory Upgrade (UI & Admin)
            'edit_branch_policy' => 'Can manage branch inventory deduction policy',
            'manage_unit_conversions' => 'Can manage unit conversion rules',
            'view_inventory_reports' => 'Can view inventory reports',
            'audit_inventory' => 'Can view inventory variance audit logs',

            // Epic 28 Phase 2: Controlled Offline Sales
            'manage_offline_sales_settings' => 'Can manage terminal offline sales settings and sequence registry',
            'review_offline_sync_conflicts' => 'Can review and manage offline sales import conflicts',

            // Task 5: Printer Profile Schema & Admin UI
            'manage_printer_profiles' => 'Can manage printer profiles and terminal printer assignments',
            'manage_approval_rules' => 'Can manage statutory discount approval rules',
            'manage_promotions' => 'Can manage promotions and bundling configurations',
            'manage_cash_drawer_reasons' => 'Can manage cash drawer drop and top-up reasons',
        ];
    }

    /**
     * Default role templates.
     */
    protected function getRoles(): array
    {
        return [
            'Cashier' => [
                'description' => 'Standard POS operator',
                'permissions' => [
                    'access_pos', 'create_sale', 'apply_discount', 
                    'open_shift', 'close_shift', 'view_shift', 'view_own_shift_summary',
                    'manage_cash_drawer'
                ],
            ],
            'Branch Manager' => [
                'description' => 'Branch operations supervisor',
                'permissions' => [
                    'access_pos', 'create_sale', 'apply_discount', 
                    'open_shift', 'close_shift', 'approve_shift', 'view_shift', 'view_branch_shifts', 'view_own_shift_summary',
                    'manage_cash_drawer',
                    'view_branch_dashboard', 'manage_branch_inventory',
                    'pos.approve_discount',
                    'approve_void', 'approve_refund', 'view_branch_reports',
                    'close_branch_day', 'view_reports',
                    'view_sales_history', 'view_sale_details',
                    'pos-layouts.view',
                    'inventory.stocktake.view', 'inventory.stocktake.create', 'inventory.stocktake.count',                     'inventory.stocktake.review', 'inventory.stocktake.cancel', 'inventory.stocktake.post',
                    'inventory.adjustment.view', 'inventory.adjustment.create',
                    'reports.cashier-accountability.view', 'reports.cashier-accountability.export',
                    'reports.shift-summary.view', 'reports.shift-summary.export',
                    'procurement.suppliers.view',
                    'procurement.purchase-orders.view', 'procurement.purchase-orders.create', 'procurement.purchase-orders.approve', 'procurement.purchase-orders.export',
                    'procurement.receiving.view', 'procurement.receiving.create', 'procurement.receiving.post', 'procurement.receiving.export',
                    'procurement.returns.view', 'procurement.returns.create', 'procurement.returns.approve', 'procurement.returns.post',
                    
                    // Epic 27 Branch Manager permissions
                    'edit_branch_policy', 'view_inventory_reports', 'audit_inventory',
 
                    // Task 5: Printer Profile Management
                    'manage_printer_profiles',
                    'manage_promotions',
                ],
            ],
            'Owner/Admin' => [
                'description' => 'Full tenant administrative control',
                'permissions' => array_keys($this->getPermissions()),
            ],
            'Accountant' => [
                'description' => 'Financial and accounting management',
                'permissions' => [
                    'connect_quickbooks', 'manage_quickbooks_connection', 'configure_accounting_mapping', 'manage_accounting_mappings',
                    'manage_settlement_periods',
                    'view_settlement_periods',
                    'view_sync_dashboard', 'retry_failed_sync',
                    'manually_resolve_sync', 'ignore_sync_exception',
                     'view_reconciliation_reports', 'export_accounting_reports',
                    'view_branch_reports', 'export_reports', 'view_multi_branch_dashboard', 'view_reports',
                    'view_sales_history', 'view_sale_details', 'export_sales_history',
                    'reports.cashier-accountability.view', 'reports.cashier-accountability.export',
                    'reports.shift-summary.view', 'reports.shift-summary.export',
                    'procurement.suppliers.view',
                    'procurement.purchase-orders.view', 'procurement.purchase-orders.export',
                    'procurement.receiving.view', 'procurement.receiving.export',
                    'procurement.returns.view',
                    
                    // Epic 27 Accountant permissions
                    'view_inventory_reports', 'audit_inventory'
                ],
            ],
        ];
    }
}
