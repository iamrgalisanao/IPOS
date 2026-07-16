<?php

namespace App\Services\Inventory\Reports;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class InventoryReportScopeService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BranchContext $branchContext,
    ) {}

    public function accessibleBranches(User $user): Collection
    {
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return Branch::query()
                ->active()
                ->where('tenant_id', $this->tenantContext->getTenantId())
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return $user->branches()
            ->active()
            ->where('branches.tenant_id', $this->tenantContext->getTenantId())
            ->orderBy('branches.name')
            ->get(['branches.id', 'branches.name']);
    }

    public function selectedBranchIds(User $user, ?string $branchId): array
    {
        $branches = $this->accessibleBranches($user);

        if ($branchId) {
            if (!$branches->pluck('id')->contains($branchId)) {
                abort(Response::HTTP_FORBIDDEN, 'You do not have access to the selected branch.');
            }

            return [$branchId];
        }

        $activeBranch = $this->branchContext->getBranchId();
        if ($activeBranch && $branches->pluck('id')->contains($activeBranch)) {
            return [$activeBranch];
        }

        return $branches->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function branchOptions(User $user): array
    {
        return $this->accessibleBranches($user)
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])
            ->values()
            ->all();
    }
}
