<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Accounting\AccountingOutboxSyncStateService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingOutboxSyncStateTest extends TestCase
{
    use RefreshDatabase;

    protected AccountingOutboxSyncStateService $stateService;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateService = app(AccountingOutboxSyncStateService::class);
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
    }

    protected function createOutbox(string $status = 'pending'): AccountingOutbox
    {
        $branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        return AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => ['total' => '100.00'],
            'sync_status' => $status,
            'attempt_count' => 0
        ]);
    }

    /** AC 1, 2: Pending -> Processing increments attempt_count */
    public function test_pending_can_be_marked_processing(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $this->stateService->markAsProcessing($outbox);
        
        $outbox->refresh();
        $this->assertEquals('processing', $outbox->sync_status);
        $this->assertEquals(1, $outbox->attempt_count);
    }

    /** AC 3, 4: Processing -> Synced stores synced_at */
    public function test_processing_can_be_marked_synced(): void
    {
        $outbox = $this->createOutbox('processing');
        
        $this->stateService->markAsSynced($outbox);
        
        $outbox->refresh();
        $this->assertEquals('synced', $outbox->sync_status);
        $this->assertNotNull($outbox->synced_at);
    }

    /** AC 5, 6: Processing -> Failed stores sync_error */
    public function test_processing_can_be_marked_failed(): void
    {
        $outbox = $this->createOutbox('processing');
        
        $this->stateService->markAsFailed($outbox, 'API error 500');
        
        $outbox->refresh();
        $this->assertEquals('failed', $outbox->sync_status);
        $this->assertEquals('API error 500', $outbox->sync_error);
    }

    /** AC 7: Failed -> Processing (Retry) */
    public function test_failed_can_be_retried(): void
    {
        $outbox = $this->createOutbox('failed');
        $outbox->update(['attempt_count' => 1]);

        $this->stateService->markAsProcessing($outbox);

        $outbox->refresh();
        $this->assertEquals('processing', $outbox->sync_status);
        $this->assertEquals(2, $outbox->attempt_count);
        $this->assertNull($outbox->sync_error);
    }

    /** AC 8, 9, 10: Invalid Transitions */
    public function test_invalid_transitions_are_rejected(): void
    {
        // AC 8: Synced cannot be retried
        $synced = $this->createOutbox('synced');
        try {
            $this->stateService->markAsProcessing($synced);
            $this->fail('Synced -> Processing should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid transition', $e->getMessage());
        }

        // AC 9: Pending cannot be synced directly
        $pending = $this->createOutbox('pending');
        try {
            $this->stateService->markAsSynced($pending);
            $this->fail('Pending -> Synced should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid transition', $e->getMessage());
        }

        // AC 10: Pending cannot be failed directly
        try {
            $this->stateService->markAsFailed($pending, 'error');
            $this->fail('Pending -> Failed should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid transition', $e->getMessage());
        }
    }

    /** AC 11, 12: Identity/Payload Immutability */
    public function test_payload_remains_immutable_during_transitions(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $this->stateService->markAsProcessing($outbox);
        
        $this->assertDatabaseHas('accounting_outbox', [
            'id' => $outbox->id,
            'payload' => json_encode(['total' => '100.00'])
        ]);
    }

    /** AC 13, 14: No-Mutation Boundary (Business Records) */
    public function test_transitions_do_not_create_business_records(): void
    {
        $outbox = $this->createOutbox('pending');
        
        $countsBefore = [
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'outbox' => AccountingOutbox::count(),
        ];

        $this->stateService->markAsProcessing($outbox);
        $this->stateService->markAsSynced($outbox);

        $this->assertEquals($countsBefore['sale'], Sale::count());
        $this->assertEquals($countsBefore['payment'], SalePayment::count());
        $this->assertEquals($countsBefore['outbox'], AccountingOutbox::count());
    }
}
