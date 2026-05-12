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
            $seededPermissions[$name] = Permission::create([
                'name' => $name,
                'description' => $description,
            ]);
        }

        $roles = $this->getRoles();

        foreach ($roles as $roleName => $roleData) {
            $role = Role::create([
                'name' => $roleName,
                'description' => $roleData['description'],
            ]);

            foreach ($roleData['permissions'] as $permName) {
                if (isset($seededPermissions[$permName])) {
                    $role->permissions()->attach($seededPermissions[$permName]->id);
                }
            }
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
            'open_shift' => 'Can open a new POS shift',
            'close_shift' => 'Can close an active POS shift',
            'view_own_shift_summary' => 'Can view own shift performance',

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
            'export_reports' => 'Can export financial reports',

            // Accounting
            'connect_quickbooks' => 'Can configure QuickBooks integration',
            'configure_accounting_mapping' => 'Can manage accounting mappings',
            'manage_accounting_mappings' => 'Can manage accounting mapping UI and status',
            'view_sync_dashboard' => 'Can view integration status',
            'retry_failed_sync' => 'Can retry failed sync tasks',
            'manually_resolve_sync' => 'Can manually resolve sync issues',
            'ignore_sync_exception' => 'Can ignore sync warnings',
            'view_reconciliation_reports' => 'Can view reconciliation data',
            'export_accounting_reports' => 'Can export accounting data',
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
                    'open_shift', 'close_shift', 'view_own_shift_summary'
                ],
            ],
            'Branch Manager' => [
                'description' => 'Branch operations supervisor',
                'permissions' => [
                    'access_pos', 'create_sale', 'apply_discount', 
                    'open_shift', 'close_shift', 'view_own_shift_summary',
                    'view_branch_dashboard', 'manage_branch_inventory',
                    'approve_void', 'approve_refund', 'view_branch_reports',
                    'close_branch_day'
                ],
            ],
            'Owner/Admin' => [
                'description' => 'Full tenant administrative control',
                'permissions' => array_keys($this->getPermissions()),
            ],
            'Accountant' => [
                'description' => 'Financial and accounting management',
                'permissions' => [
                    'connect_quickbooks', 'configure_accounting_mapping', 'manage_accounting_mappings',
                    'view_sync_dashboard', 'retry_failed_sync',
                    'manually_resolve_sync', 'ignore_sync_exception',
                    'view_reconciliation_reports', 'export_accounting_reports',
                    'view_branch_reports', 'export_reports', 'view_multi_branch_dashboard'
                ],
            ],
        ];
    }
}
