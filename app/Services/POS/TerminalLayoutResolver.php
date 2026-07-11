<?php

namespace App\Services\POS;

use App\Models\PosLayout;
use App\Models\SalesMachineProfile;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;

/**
 * TerminalLayoutResolver
 *
 * Single source of truth for resolving which PosLayout a terminal should use.
 *
 * Resolution order:
 *   1. SalesMachineProfile.pos_layout_id, if set AND the layout is published
 *   2. Branch-active published layout from branch_pos_layout pivot
 *   3. null (no layout available)
 *
 * All consumers (POSController, CacheBootstrapService, TerminalConfigDriftService,
 * TerminalHeartbeatController, LayoutAssignmentController) must use this resolver
 * to guarantee consistent behaviour.
 */
class TerminalLayoutResolver
{
    public const SOURCE_TERMINAL_OVERRIDE = 'terminal_override';
    public const SOURCE_BRANCH_DEFAULT    = 'branch_default';
    public const SOURCE_NONE              = 'none';

    /**
     * Resolve the effective PosLayout for a terminal profile.
     *
     * @param  SalesMachineProfile  $profile
     * @return PosLayout|null
     */
    public function resolveForProfile(SalesMachineProfile $profile): ?PosLayout
    {
        // 1. Terminal-specific override (must be published and non-null)
        if ($profile->pos_layout_id) {
            $layout = PosLayout::find($profile->pos_layout_id);

            if ($layout && $layout->status === PosLayout::STATUS_PUBLISHED) {
                return $layout;
            }
            // Override layout is draft / archived / deleted → fall through to branch default
        }

        // 2. Branch-active published layout
        return $this->resolveBranchLayout($profile->branch_id, $profile->tenant_id);
    }

    /**
     * Resolve the branch-active published layout (without any terminal override).
     *
     * @param  string  $branchId
     * @param  string  $tenantId
     * @return PosLayout|null
     */
    public function resolveBranchLayout(string $branchId, string $tenantId): ?PosLayout
    {
        return PosLayout::query()
            ->join('branch_pos_layout', 'branch_pos_layout.pos_layout_id', '=', 'pos_layouts.id')
            ->where('pos_layouts.tenant_id', $tenantId)
            ->where('branch_pos_layout.tenant_id', $tenantId)
            ->where('branch_pos_layout.branch_id', $branchId)
            ->where('branch_pos_layout.is_active', true)
            ->where('pos_layouts.status', PosLayout::STATUS_PUBLISHED)
            ->orderByDesc('branch_pos_layout.published_at')
            ->orderByDesc('branch_pos_layout.created_at')
            ->select([
                'pos_layouts.*',
                'branch_pos_layout.id as assignment_id',
                'branch_pos_layout.published_at',
                'branch_pos_layout.updated_at as assignment_updated_at',
            ])
            ->first();
    }

    /**
     * Determine the resolution source for a profile.
     *
     * Returns one of the SOURCE_* constants so callers can attach it to API responses.
     *
     * @param  SalesMachineProfile  $profile
     * @return string
     */
    public function getResolutionSource(SalesMachineProfile $profile): string
    {
        if ($profile->pos_layout_id) {
            $layout = PosLayout::find($profile->pos_layout_id);
            if ($layout && $layout->status === PosLayout::STATUS_PUBLISHED) {
                return self::SOURCE_TERMINAL_OVERRIDE;
            }
        }

        $branchLayout = $this->resolveBranchLayout($profile->branch_id, $profile->tenant_id);

        return $branchLayout ? self::SOURCE_BRANCH_DEFAULT : self::SOURCE_NONE;
    }

    /**
     * Build a canonical hash string for the resolved layout of a terminal.
     *
     * Delegates to CacheBootstrapService for consistent hashing logic, but
     * passes the terminal-resolved layout directly so the hash reflects the
     * terminal-specific override rather than the generic branch-level layout.
     *
     * @param  SalesMachineProfile       $profile
     * @param  CacheBootstrapService     $bootstrapService
     * @return string
     */
    public function resolveHashForProfile(
        SalesMachineProfile $profile,
        CacheBootstrapService $bootstrapService
    ): string {
        $layout = $this->resolveForProfile($profile);
        return $bootstrapService->calculateLayoutVersionHashFromLayout($layout);
    }
}
