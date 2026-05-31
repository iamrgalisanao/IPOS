<?php

namespace Tests\Feature\Reports;

use App\Jobs\Reports\ProcessDataExportJob;
use App\Models\DataExport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaxReportingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_export_ejournal_dispatches_async_job()
    {
        Queue::fake();

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional']
        ]);
        app(\App\Services\RbacSeeder::class)->seedForTenant($tenant);
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = \App\Models\Role::where('name', 'Owner/Admin')->where('tenant_id', $tenant->id)->first();
        $user->assignRole($role);

        $response = $this->actingAs($user)->get(route('reports.tax.export.ejournal', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertRedirect(route('reports.exports.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseCount('data_exports', 1);

        Queue::assertPushed(ProcessDataExportJob::class, function ($job) use ($tenant) {
            return $job->export->tenant_id === (string) $tenant->id
                && $job->export->type === 'ejournal';
        });
    }

    public function test_duplicate_active_export_is_prevented()
    {
        Queue::fake();

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional']
        ]);
        app(\App\Services\RbacSeeder::class)->seedForTenant($tenant);
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = \App\Models\Role::where('name', 'Owner/Admin')->where('tenant_id', $tenant->id)->first();
        $user->assignRole($role);

        $parameters = [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'branch_id' => null,
            'sales_machine_profile_id' => null,
        ];
        $parametersHash = md5(json_encode($parameters));

        DataExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'ejournal',
            'status' => DataExport::STATUS_PENDING,
            'parameters' => $parameters,
            'parameters_hash' => $parametersHash,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('reports.tax.export.ejournal', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertRedirect(route('reports.exports.index'));
        $response->assertSessionHas('info');

        $this->assertDatabaseCount('data_exports', 1);
        Queue::assertNotPushed(ProcessDataExportJob::class);
    }
}
