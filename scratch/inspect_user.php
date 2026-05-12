<?php
require __DIR__ . '/../vendor/autoload.php';
\$app = require_once __DIR__ . '/../bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Tenant;
use App\Services\TenantContext;

\$user = User::withoutGlobalScopes()->where('email', 'admin@bmad.coffee')->first();
if (!\$user) {
    echo "User not found\n";
    exit(1);
}

\$tenant = Tenant::find(\$user->tenant_id);
if (!\$tenant) {
    echo "Tenant not found\n";
} else {
    app(TenantContext::class)->setTenant(\$tenant);
}

\$roles = \$user->roles->pluck('name')->toArray();
\$data = [
    'actor_type' => \$user->actor_type ?? 'N/A',
    'tenant_id' => \$user->tenant_id ?? 'N/A',
    'roles' => \$roles,
    'has_view_reports' => \$user->can('view_reports'),
    'has_view_multi_branch_dashboard' => \$user->can('view_multi_branch_dashboard')
];

echo json_encode(\$data, JSON_PRETTY_PRINT) . "\n";
