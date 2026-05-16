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
        if ($layout->status !== PosLayout::STATUS_DRAFT) {
            throw new \Exception("Only draft layouts can be published.");
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
                DB::table('branch_pos_layout')
                    ->where('branch_id', $branch->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'active_until' => $activeFrom,
                        'updated_at' => now(),
                    ]);

                // Attach new active layout
                $layout->branches()->attach($branch->id, [
                    'id' => Str::uuid(),
                    'tenant_id' => $layout->tenant_id,
                    'is_active' => true,
                    'active_from' => $activeFrom,
                    'published_by' => $publisher->id,
                    'published_at' => now(),
                ]);
            }

            // Update layout status
            $layout->update([
                'status' => PosLayout::STATUS_PUBLISHED,
                'updated_by' => $publisher->id,
            ]);
        });
    }
}
