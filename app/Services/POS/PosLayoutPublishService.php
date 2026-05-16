<?php

namespace App\Services\POS;

use App\Models\PosLayout;
use App\Models\User;
use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PosLayoutPublishService
{
    /**
     * Publish a draft POS layout and deploy it to selected branches.
     *
     * @param PosLayout $layout
     * @param array $branchIds
     * @param User $publisher
     * @param Carbon|null $activeFrom
     * @return void
     * @throws \Exception
     */
    public function publish(PosLayout $layout, array $branchIds, User $publisher, ?Carbon $activeFrom = null): void
    {
        $activeFrom = $activeFrom ?? now();

        // 1. Validation
        if (!in_array($layout->status, [PosLayout::STATUS_DRAFT, PosLayout::STATUS_PUBLISHED])) {
            throw new \Exception("Only draft or published layouts can be deployed.");
        }

        if (!PosLayoutSchemaValidator::validate($layout->schema)) {
            throw new \Exception("Layout schema is invalid or contains forbidden fields.");
        }

        // 2. Branch Tenant Validation
        $branches = Branch::whereIn('id', $branchIds)->get();
        
        if ($branches->count() !== count(array_unique($branchIds))) {
            throw new \Exception("One or more selected branches are invalid or belong to a different tenant.");
        }

        foreach ($branches as $branch) {
            if ($branch->tenant_id !== $layout->tenant_id) {
                throw new \Exception("Cannot publish to branches outside the layout tenant.");
            }
        }

        // 3. Atomic Transaction
        DB::transaction(function () use ($layout, $branches, $publisher, $activeFrom) {
            foreach ($branches as $branch) {
                // Deactivate current active layouts for this branch
                $deactivatedLayouts = DB::table('branch_pos_layout')
                    ->where('branch_id', $branch->id)
                    ->where('is_active', true)
                    ->get();

                foreach ($deactivatedLayouts as $deactivated) {
                    DB::table('branch_pos_layout')
                        ->where('id', $deactivated->id)
                        ->update([
                            'is_active' => false,
                            'active_until' => $activeFrom,
                            'updated_at' => now(),
                        ]);

                    // Log branch layout replacement
                    \App\Models\AuditLog::create([
                        'tenant_id' => $layout->tenant_id,
                        'branch_id' => $branch->id,
                        'actor_user_id' => $publisher->id,
                        'actor_type' => 'user',
                        'action' => 'pos_layout_branch_replaced',
                        'auditable_type' => PosLayout::class,
                        'auditable_id' => $layout->id,
                        'metadata' => [
                            'deactivated_layout_id' => $deactivated->pos_layout_id,
                            'branch_id' => $branch->id,
                            'active_from' => $activeFrom->toDateTimeString(),
                        ],
                    ]);
                }

                // Attach new active layout
                $pivotId = Str::uuid();
                $layout->branches()->attach($branch->id, [
                    'id' => $pivotId,
                    'tenant_id' => $layout->tenant_id,
                    'is_active' => true,
                    'active_from' => $activeFrom,
                    'published_by' => $publisher->id,
                    'published_at' => now(),
                ]);

                // Log branch assignment
                \App\Models\AuditLog::create([
                    'tenant_id' => $layout->tenant_id,
                    'branch_id' => $branch->id,
                    'actor_user_id' => $publisher->id,
                    'actor_type' => 'user',
                    'action' => 'pos_layout_branch_assigned',
                    'auditable_type' => PosLayout::class,
                    'auditable_id' => $layout->id,
                    'metadata' => [
                        'branch_id' => $branch->id,
                        'layout_version' => $layout->version,
                        'active_from' => $activeFrom->toDateTimeString(),
                    ],
                ]);
            }

            // Update layout status
            $layout->update([
                'status' => PosLayout::STATUS_PUBLISHED,
                'updated_by' => $publisher->id,
            ]);

            // Log layout published event
            \App\Models\AuditLog::create([
                'tenant_id' => $layout->tenant_id,
                'actor_user_id' => $publisher->id,
                'actor_type' => 'user',
                'action' => 'pos_layout_published',
                'auditable_type' => PosLayout::class,
                'auditable_id' => $layout->id,
                'metadata' => [
                    'layout_id' => $layout->id,
                    'layout_version' => $layout->version,
                    'layout_name' => $layout->name,
                    'branch_count' => count($branches),
                ],
            ]);
        });
    }
}
