<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subscription Tiers and Feature Matrix
    |--------------------------------------------------------------------------
    |
    | Here you may define all application subscription tiers, along with their
    | enabled features and resource limits. Custom overrides can be stored
    | in individual tenant metadata columns for bespoke contracts.
    |
    */

    'tiers' => [
        'basic' => [
            'name' => 'Basic Plan',
            'features' => [
                'sales.pos' => true,
                'catalog.view' => true,
                'reports.basic' => true,
            ],
            'limits' => [
                'max_branches' => 1,
                'max_users' => 3,
            ],
        ],

        'professional' => [
            'name' => 'Professional Plan',
            'features' => [
                'sales.pos' => true,
                'catalog.view' => true,
                'catalog.edit' => true,
                'reports.basic' => true,
                'reports.advanced' => true,
                'procurement.basic' => true,   // Epic 20: Supplier & PO
                'layout.custom' => true,       // Epic 22: Visual POS layouts
            ],
            'limits' => [
                'max_branches' => 5,
                'max_users' => 15,
            ],
        ],

        'enterprise' => [
            'name' => 'Enterprise Plan',
            'features' => [
                'sales.pos' => true,
                'catalog.view' => true,
                'catalog.edit' => true,
                'reports.basic' => true,
                'reports.advanced' => true,
                'procurement.basic' => true,
                'procurement.advanced' => true, // Epic 26: Expiry/RMA/AP
                'quickbooks.sync' => true,       // Epic 8: QuickBooks
                'layout.custom' => true,
            ],
            'limits' => [
                'max_branches' => PHP_INT_MAX,
                'max_users' => PHP_INT_MAX,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Subscription Fallback
    |--------------------------------------------------------------------------
    |
    | If a tenant has no subscription plan configured, or if the metadata
    | is corrupted/empty, the system will fail-closed to this basic tier.
    |
    */
    'default_tier' => 'basic',
];
