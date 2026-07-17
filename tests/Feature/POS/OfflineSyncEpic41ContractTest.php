<?php

namespace Tests\Feature\POS;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncAttempt;
use App\Models\OfflineTerminalEpochQuarantine;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesMachineProfile;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncEpic41ContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $profile;
    private Product $product;
    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->profile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'MAIN',
            'machine_identification_number' => 'MIN-MAIN',
            'machine_serial_number' => 'SER-MAIN',
            'software_license_number' => 'LIC-MAIN',
            'permit_to_use_number' => 'PTU-MAIN',
            'authority_to_generate_control_number' => 'ATG-MAIN',
            'supplier_name' => 'Supplier',
            'supplier_tin' => '123-456-789-000',
            'supplier_branch_code' => '00001',
            'supplier_accreditation_number' => 'ACC-MAIN',
            'status' => 'active',
            'offline_sales_enabled' => null,
            'offline_sequence_prefix' => 'OFF-MAIN-',
            'offline_sequence_next_value' => 1,
            'offline_sequence_status' => 'active',
        ]);

        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Exempt',
            'code' => 'EXEMPT',
            'tax_type' => 'exempt',
            'rate' => 0.00,
            'status' => 'active',
            'description' => 'Exempt',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'tax_category_id' => $taxCategory->id,
            'selling_price' => 150.00,
            'status' => 'active',
            'is_inventory_tracked' => false,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'cash',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_offline_envelope_accepts_and_returns_epic_41_contract(): void
    {
        $payload = $this->batchPayload($this->importPayload());

        $response = $this->postSync($payload);

        $response->assertOk()
            ->assertJsonPath('contract_version', 'epic-41-sync-v1')
            ->assertJsonPath('imports.0.sync_status', 'accepted')
            ->assertJsonPath('imports.0.consequence_status.sale', 'committed')
            ->assertJsonPath('imports.0.consequence_status.payment', 'committed')
            ->assertJsonPath('imports.0.consequence_status.accounting_outbox', 'queued');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
        $this->assertSame(1, AccountingOutbox::where('event_type', 'sale_paid')->count());

        $import = OfflineSalesImport::firstOrFail();
        $this->assertSame('accepted', $import->server_sync_status);
        $this->assertSame('accepted', $import->original_sync_status);
        $this->assertNotNull($import->server_payload_fingerprint);
        $this->assertNotNull($import->acceptance_consequence_snapshot);
        $this->assertSame(1, OfflineSyncAttempt::where('offline_sales_import_id', $import->id)->count());
    }

    public function test_exact_replay_returns_replayed_without_duplicate_consequences(): void
    {
        $payload = $this->batchPayload($this->importPayload());

        $first = $this->postSync($payload);
        $second = $this->postSync($payload);

        $first->assertOk()->assertJsonPath('imports.0.sync_status', 'accepted');
        $second->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'replayed')
            ->assertJsonPath('imports.0.original_sync_status', 'accepted');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
        $this->assertSame(1, AccountingOutbox::where('event_type', 'sale_paid')->count());
        $this->assertSame(2, OfflineSyncAttempt::count());
    }

    public function test_same_uuid_with_fingerprint_drift_is_rejected_before_mutation(): void
    {
        $import = $this->importPayload();
        $this->postSync($this->batchPayload($import))->assertOk();

        $drifted = $import;
        $drifted['client_total'] = 999.00;
        $drifted['payments'][0]['amount'] = 999.00;
        unset($drifted['business_payload_fingerprint'], $drifted['payload_hash']);
        $drifted['business_payload_fingerprint'] = $this->fingerprint($drifted);
        $drifted['payload_hash'] = $drifted['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($drifted, 'BATCH-DRIFT'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_fingerprint_drift');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
    }

    public function test_status_lookup_returns_stable_result_without_resubmitting_payload(): void
    {
        $import = $this->importPayload();
        $this->postSync($this->batchPayload($import))->assertOk();

        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->getJson("/api/v1/pos/offline-sales/{$import['offline_transaction_uuid']}/sync-status")
            ->assertOk()
            ->assertJsonPath('sync_status', 'accepted')
            ->assertJsonPath('original_sync_status', 'accepted');
    }

    public function test_cash_collected_policy_violation_enters_review_required(): void
    {
        $card = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'card',
            'name' => 'Card',
            'type' => 'card',
            'status' => 'active',
        ]);

        $import = $this->importPayload([
            'payments' => [[
                'payment_method_id' => $card->id,
                'amount' => 300.00,
            ]],
            'cash_status' => 'collected',
        ]);

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'rejected_non_cash_tender');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_offline_uuid_is_rejected_without_creating_sale(): void
    {
        $import = $this->importPayload();
        unset($import['offline_transaction_uuid'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_missing_offline_transaction_uuid');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_terminal_binding_epoch_is_rejected_without_creating_sale(): void
    {
        $import = $this->importPayload();
        unset($import['terminal_binding_epoch'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_missing_terminal_binding_epoch');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_legacy_payload_hash_is_used_for_fingerprint_validation(): void
    {
        $import = $this->importPayload();
        unset($import['business_payload_fingerprint']);

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'accepted');

        $this->assertSame(1, Sale::count());
    }

    public function test_conflicting_fingerprint_aliases_are_rejected_without_mutation(): void
    {
        $import = $this->importPayload([
            'payload_hash' => str_repeat('f', 64),
        ]);

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_fingerprint_evidence_conflict');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_rejected_envelope_does_not_abort_accepted_sibling(): void
    {
        $accepted = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000010',
            'local_sequence' => '10',
        ]);
        $rejected = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000011',
            'local_sequence' => '11',
        ]);
        unset($rejected['terminal_binding_epoch'], $rejected['business_payload_fingerprint'], $rejected['payload_hash']);
        $rejected['business_payload_fingerprint'] = $this->fingerprint($rejected);
        $rejected['payload_hash'] = $rejected['business_payload_fingerprint'];

        $this->postSync([
            'batch_reference' => 'BATCH-MIXED',
            'imports' => [$accepted, $rejected],
        ])->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'accepted')
            ->assertJsonPath('imports.1.sync_status', 'rejected')
            ->assertJsonPath('imports.1.reason', 'rejected_missing_terminal_binding_epoch');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, SalePayment::count());
    }

    public function test_missing_payment_method_creates_no_sale(): void
    {
        $import = $this->importPayload();
        unset($import['payment_method'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'rejected_non_cash_tender');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_payments_create_no_sale_or_payment_configuration(): void
    {
        $methodCount = PaymentMethod::count();
        $import = $this->importPayload();
        unset($import['payments'], $import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'rejected_missing_payment_evidence');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame($methodCount, PaymentMethod::count());
    }

    public function test_empty_payments_create_no_sale(): void
    {
        $import = $this->importPayload();
        $import['payments'] = [];
        unset($import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertStatus(422);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_payment_total_mismatch_creates_no_sale_payment_or_config(): void
    {
        $methodCount = PaymentMethod::count();
        $import = $this->importPayload();
        $import['payments'][0]['amount'] = 250.00;
        unset($import['business_payload_fingerprint'], $import['payload_hash']);
        $import['business_payload_fingerprint'] = $this->fingerprint($import);
        $import['payload_hash'] = $import['business_payload_fingerprint'];

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'validation_failed');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame($methodCount, PaymentMethod::count());
    }

    public function test_missing_active_cash_method_does_not_auto_create_configuration(): void
    {
        $this->cashMethod->update(['status' => 'inactive']);
        $methodCount = PaymentMethod::count();
        $import = $this->importPayload();

        $this->postSync($this->batchPayload($import))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_required_cash_payment_configuration');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame($methodCount, PaymentMethod::count());
    }

    public function test_suspected_duplicate_enters_review_with_decision_evidence(): void
    {
        $first = $this->importPayload([
            'offline_sequence_number' => 'OFF-MAIN-000001',
            'local_sequence' => '1',
            'local_receipt_number' => 'LOCAL-R-100',
            'business_date' => '2026-07-17',
        ]);
        $second = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000002',
            'local_sequence' => '2',
            'local_receipt_number' => 'LOCAL-R-100',
            'business_date' => '2026-07-17',
        ]);

        $this->postSync($this->batchPayload($first, 'BATCH-DUP-1'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'accepted');

        $this->postSync($this->batchPayload($second, 'BATCH-DUP-2'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_suspected_duplicate_capture')
            ->assertJsonPath('imports.0.suggested_action_code', 'manager_review_possible_duplicate');

        $review = OfflineSalesImport::where('offline_transaction_uuid', $second['offline_transaction_uuid'])->firstOrFail();
        $this->assertSame('duplicate', $review->conflict_family);
        $this->assertSame('review_suspected_duplicate_capture', $review->reason_code);
        $this->assertSame('high', $review->review_severity);
        $this->assertSame('support_only', $review->retry_classification);
        $this->assertSame(100, $review->duplicate_score);
        $this->assertSame(90, $review->duplicate_review_threshold);
        $this->assertContains('same_local_receipt_number', $review->duplicate_rule_ids);
        $this->assertNotEmpty($review->duplicate_candidates);
        $this->assertSame('pending_support', $review->current_resolution_status);
        $this->assertSame(1, Sale::count());
    }

    public function test_sequence_gap_is_retryable_and_does_not_restart_grace_period(): void
    {
        $import = $this->importPayload([
            'offline_sequence_number' => 'OFF-MAIN-000003',
            'local_sequence' => '3',
            'predecessor_dependency' => 'strict',
        ]);

        $this->postSync($this->batchPayload($import, 'BATCH-GAP-1'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'retryable_failed')
            ->assertJsonPath('imports.0.reason', 'retry_sequence_gap_waiting')
            ->assertJsonPath('imports.0.retryable_error_code', 'retry_sequence_gap_waiting');

        $stored = OfflineSalesImport::where('offline_transaction_uuid', $import['offline_transaction_uuid'])->firstOrFail();
        $detectedAt = $stored->sequence_gap_detected_at?->toISOString();
        $expiresAt = $stored->sequence_gap_grace_expires_at?->toISOString();

        $this->assertSame('grace_period', $stored->sequence_gap_state);
        $this->assertNotNull($detectedAt);
        $this->assertNotNull($expiresAt);
        $this->assertSame(0, Sale::count());

        $this->postSync($this->batchPayload($import, 'BATCH-GAP-2'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'retryable_failed');

        $replayed = $stored->fresh();
        $this->assertSame($detectedAt, $replayed->sequence_gap_detected_at?->toISOString());
        $this->assertSame($expiresAt, $replayed->sequence_gap_grace_expires_at?->toISOString());
        $this->assertSame(0, Sale::count());
    }

    public function test_review_replay_is_idempotent_and_does_not_duplicate_review_opened_audit(): void
    {
        $this->cashMethod->update(['status' => 'inactive']);
        $import = $this->importPayload();

        $this->postSync($this->batchPayload($import, 'BATCH-REVIEW-1'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_required_cash_payment_configuration');

        $review = OfflineSalesImport::where('offline_transaction_uuid', $import['offline_transaction_uuid'])->firstOrFail();
        $diagnosticReference = 'offline-review:' . $review->id;

        $this->postSync($this->batchPayload($import, 'BATCH-REVIEW-2'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'replayed')
            ->assertJsonPath('imports.0.original_sync_status', 'review_required')
            ->assertJsonPath('imports.0.diagnostic_reference', $diagnosticReference);

        $this->assertSame(1, OfflineSalesImport::where('offline_transaction_uuid', $import['offline_transaction_uuid'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'offline_sync_review_opened')->count());
        $this->assertSame(0, Sale::count());
    }

    public function test_compromised_terminal_quarantines_epoch_and_blocks_successor_envelopes(): void
    {
        $compromised = $this->importPayload([
            'terminal_compromised' => true,
            'terminal_state' => 'compromised',
            'offline_sequence_number' => 'OFF-MAIN-000001',
            'local_sequence' => '1',
        ]);
        $successor = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000002',
            'local_sequence' => '2',
        ]);
        $this->postSync($this->batchPayload($compromised, 'BATCH-COMPROMISED'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_terminal_compromised');

        $this->assertDatabaseHas('offline_terminal_epoch_quarantines', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'terminal_binding_epoch' => 'epoch-1',
            'quarantine_reason' => 'review_terminal_compromised',
            'quarantine_status' => OfflineTerminalEpochQuarantine::STATUS_ACTIVE,
        ]);

        $this->postSync($this->batchPayload($successor, 'BATCH-QUARANTINED-SUCCESSOR'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_terminal_epoch_quarantined');

        $successorImport = OfflineSalesImport::where('offline_transaction_uuid', $successor['offline_transaction_uuid'])->firstOrFail();
        $this->assertSame('review_terminal_epoch_quarantined', $successorImport->reason_code);
        $this->assertSame('terminal_state', $successorImport->conflict_family);
        $this->assertSame(0, Sale::count());
    }

    public function test_material_policy_drift_enters_review_before_sale_creation(): void
    {
        $import = $this->importPayload([
            'policy_drift_materiality' => 'material_review',
            'policy_drift_area' => 'tax',
            'policy_drift_reason' => 'review_tax_policy_changed',
            'business_date' => '2026-07-17',
        ]);

        $this->postSync($this->batchPayload($import, 'BATCH-POLICY-DRIFT'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'review_required')
            ->assertJsonPath('imports.0.review_reason', 'review_tax_policy_changed');

        $review = OfflineSalesImport::where('offline_transaction_uuid', $import['offline_transaction_uuid'])->firstOrFail();
        $this->assertSame('policy', $review->conflict_family);
        $this->assertSame('review_tax_policy_changed', $review->reason_code);
        $this->assertSame('policy-drift-v1', $review->conflict_policy_version);
        $this->assertSame('material_review', $review->conflict_metadata['policy_drift_materiality']);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_prohibited_policy_drift_without_cash_is_rejected_before_sale_creation(): void
    {
        $import = $this->importPayload([
            'policy_drift_materiality' => 'prohibited',
            'policy_drift_area' => 'offline_sales',
            'policy_drift_reason' => 'rejected_offline_policy_revoked',
            'cash_status' => 'not_collected',
        ]);

        $this->postSync($this->batchPayload($import, 'BATCH-POLICY-REJECT'))->assertOk()
            ->assertJsonPath('imports.0.sync_status', 'rejected')
            ->assertJsonPath('imports.0.reason', 'rejected_offline_policy_revoked');

        $rejected = OfflineSalesImport::where('offline_transaction_uuid', $import['offline_transaction_uuid'])->firstOrFail();
        $this->assertSame('policy', $rejected->conflict_family);
        $this->assertSame('rejected_offline_policy_revoked', $rejected->reason_code);
        $this->assertSame('policy-drift-v1', $rejected->conflict_policy_version);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_cross_tenant_payload_is_hidden_and_does_not_create_import(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $import = $this->importPayload([
            'tenant_id' => $otherTenant->id,
        ]);

        $this->postSync($this->batchPayload($import, 'BATCH-CROSS-TENANT'))
            ->assertNotFound()
            ->assertJsonPath('error', 'NOT_FOUND');

        $this->assertSame(0, OfflineSalesImport::where('offline_transaction_uuid', $import['offline_transaction_uuid'])->count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'offline_sync_cross_tenant_blocked')->count());
    }

    public function test_sync_status_projection_is_role_safe(): void
    {
        $first = $this->importPayload([
            'offline_sequence_number' => 'OFF-MAIN-000001',
            'local_sequence' => '1',
            'local_receipt_number' => 'LOCAL-R-PROJECTION',
            'business_date' => '2026-07-17',
        ]);
        $second = $this->importPayload([
            'offline_transaction_uuid' => Str::uuid()->toString(),
            'offline_sequence_number' => 'OFF-MAIN-000002',
            'local_sequence' => '2',
            'local_receipt_number' => 'LOCAL-R-PROJECTION',
            'business_date' => '2026-07-17',
        ]);

        $this->postSync($this->batchPayload($first, 'BATCH-PROJECTION-1'))->assertOk();
        $this->postSync($this->batchPayload($second, 'BATCH-PROJECTION-2'))->assertOk();

        $this->statusLookup($this->cashier, $second['offline_transaction_uuid'])->assertOk()
            ->assertJsonPath('sync_status', 'review_required')
            ->assertJsonMissingPath('reason_code')
            ->assertJsonMissingPath('conflict_family')
            ->assertJsonMissingPath('business_payload_fingerprint')
            ->assertJsonMissingPath('duplicate_candidates');

        $manager = $this->userWithRole('Branch Manager');
        $this->statusLookup($manager, $second['offline_transaction_uuid'])->assertOk()
            ->assertJsonPath('sync_status', 'review_required')
            ->assertJsonPath('reason_code', 'review_suspected_duplicate_capture')
            ->assertJsonPath('conflict_family', 'duplicate')
            ->assertJsonMissingPath('business_payload_fingerprint')
            ->assertJsonMissingPath('duplicate_candidates');

        $owner = $this->userWithRole('Owner/Admin');
        $supportResponse = $this->statusLookup($owner, $second['offline_transaction_uuid']);
        $supportResponse->assertOk()
            ->assertJsonPath('sync_status', 'review_required')
            ->assertJsonPath('reason_code', 'review_suspected_duplicate_capture')
            ->assertJsonPath('business_payload_fingerprint', OfflineSalesImport::where('offline_transaction_uuid', $second['offline_transaction_uuid'])->firstOrFail()->server_payload_fingerprint);
        $this->assertNotEmpty($supportResponse->json('duplicate_candidates'));
    }

    private function postSync(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postSyncAs($this->cashier, $payload);
    }

    private function postSyncAs(User $user, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/v1/pos/offline-sales/sync', $payload);
    }

    private function statusLookup(User $user, string $offlineTransactionUuid): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->getJson("/api/v1/pos/offline-sales/{$offlineTransactionUuid}/sync-status");
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $user->assignRole(Role::where('name', $roleName)->firstOrFail());
        $user->assignToBranch($this->branch);

        return $user;
    }

    private function batchPayload(array $import, string $reference = 'BATCH-EPIC-41'): array
    {
        return [
            'batch_reference' => $reference,
            'imports' => [$import],
        ];
    }

    private function importPayload(array $overrides = []): array
    {
        $uuid = $overrides['offline_transaction_uuid'] ?? Str::uuid()->toString();
        $payload = array_replace_recursive([
            'offline_transaction_uuid' => $uuid,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'terminal_id' => $this->profile->id,
            'sales_machine_profile_id' => $this->profile->id,
            'terminal_binding_epoch' => 'epoch-1',
            'offline_sequence_number' => 'OFF-MAIN-000001',
            'local_sequence' => '1',
            'user_id' => $this->cashier->id,
            'cashier_id' => $this->cashier->id,
            'submitted_at' => '2026-07-17T08:00:00+08:00',
            'terminal_timestamp' => '2026-07-17T08:00:00+08:00',
            'timezone' => 'Asia/Manila',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 150.00,
            ]],
            'client_subtotal' => 300.00,
            'client_tax_total' => 0.00,
            'client_total' => 300.00,
            'payment_method' => 'cash',
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 300.00,
            ]],
            'catalog_version_hash' => str_repeat('a', 64),
            'tax_configuration_version_hash' => str_repeat('b', 64),
            'payment_methods_version_hash' => str_repeat('c', 64),
            'terminal_policy_version_hash' => str_repeat('d', 64),
            'sync_attempt_id' => Str::uuid()->toString(),
            'lease_id' => Str::uuid()->toString(),
            'attempt_generation' => 1,
            'queue_state_revision' => 1,
            'cash_status' => 'collected',
        ], $overrides);

        unset($payload['include_client_fingerprint']);
        $payload['business_payload_fingerprint'] = $this->fingerprint($payload);
        $payload['payload_hash'] = $overrides['payload_hash'] ?? $payload['business_payload_fingerprint'];

        return $payload;
    }

    private function fingerprint(array $payload): string
    {
        $material = array_intersect_key($payload, array_flip([
            'tenant_id',
            'branch_id',
            'terminal_id',
            'sales_machine_profile_id',
            'terminal_binding_epoch',
            'offline_transaction_uuid',
            'offline_sequence_number',
            'local_sequence',
            'user_id',
            'cashier_id',
            'cashier_shift_id',
            'drawer_session_id',
            'items',
            'client_subtotal',
            'client_tax_total',
            'client_total',
            'payment_method',
            'payments',
            'catalog_version_hash',
            'tax_configuration_version_hash',
            'payment_methods_version_hash',
            'terminal_policy_version_hash',
            'submitted_at',
            'terminal_timestamp',
            'timezone',
        ]));

        return hash('sha256', $this->canonicalJson($material));
    }

    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_decode($this->canonicalJson($value), true);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
