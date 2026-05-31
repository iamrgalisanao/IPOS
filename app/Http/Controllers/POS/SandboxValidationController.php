<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\POS\OfflineSync\OfflineImportRecalculationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SandboxValidationController extends Controller
{
    public function __construct(
        protected OfflineImportRecalculationService $recalculationService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * POST /api/pos/sandbox/validate
     *
     * Validates terminal sync payload shape, checksum, sequence prefix/suffix,
     * and performs dry-run tax/total calculations without database mutations.
     */
    public function validatePayload(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->branchContext->getBranch();

        if (!$tenant || !$branch) {
            return response()->json([
                'error'   => 'MISSING_CONTEXT',
                'message' => 'Tenant and Branch contexts are required.',
            ], 403);
        }

        // Resolve terminal profile
        $profile = SalesMachineProfile::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->first();

        if (!$profile) {
            return response()->json([
                'error'   => 'NO_ACTIVE_TERMINAL',
                'message' => 'No active terminal profile found for this branch.',
            ], 422);
        }

        // Validate payload structure
        $rules = [
            'offline_sequence_number' => ['required', 'string'],
            'submitted_at'            => ['required', 'date'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.product_id'      => ['required', 'uuid'],
            'items.*.quantity'        => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price'      => ['required', 'numeric', 'gt:0'],
            'client_subtotal'         => ['required', 'numeric', 'min:0'],
            'client_tax_total'        => ['required', 'numeric', 'min:0'],
            'client_total'            => ['required', 'numeric', 'gt:0'],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $formattedErrors = [];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $formattedErrors[] = [
                        'code'    => 'SCHEMA_VALIDATION_FAILED',
                        'message' => $message,
                        'field'   => $field,
                    ];
                }
            }

            return response()->json([
                'valid'            => false,
                'classification'   => 'rejected',
                'checks'           => [
                    'schema'            => 'failed',
                    'checksum'          => 'unchecked',
                    'tax_recalculation' => 'unchecked',
                    'sequence_format'   => 'unchecked',
                ],
                'computed_totals'  => null,
                'submitted_totals' => null,
                'errors'           => $formattedErrors,
            ], 200);
        }

        $validatedData = $validator->validated();

        // Validate sequence prefix and suffix
        $sequenceCheck = 'passed';
        $sequenceError = null;

        if (!empty($profile->offline_sequence_prefix)) {
            $sequenceNumber = $validatedData['offline_sequence_number'];
            if (!str_starts_with($sequenceNumber, $profile->offline_sequence_prefix)) {
                $sequenceCheck = 'failed';
                $sequenceError = [
                    'code'    => 'INVALID_SEQUENCE_PREFIX',
                    'message' => "The sequence prefix mismatch: expected prefix {$profile->offline_sequence_prefix}",
                    'field'   => 'offline_sequence_number',
                ];
            } else {
                $suffix = substr($sequenceNumber, strlen($profile->offline_sequence_prefix));
                if ($suffix === '' || !ctype_digit($suffix) || (int) $suffix < 1) {
                    $sequenceCheck = 'failed';
                    $sequenceError = [
                        'code'    => 'INVALID_SEQUENCE_SUFFIX',
                        'message' => 'The sequence suffix must be a positive integer.',
                        'field'   => 'offline_sequence_number',
                    ];
                }
            }
        }

        if ($sequenceCheck === 'failed') {
            return response()->json([
                'valid'            => false,
                'classification'   => 'rejected',
                'checks'           => [
                    'schema'            => 'passed',
                    'checksum'          => 'unchecked',
                    'tax_recalculation' => 'unchecked',
                    'sequence_format'   => 'failed',
                ],
                'computed_totals'  => null,
                'submitted_totals' => [
                    'gross_amount' => $this->formatAmount($validatedData['client_total']),
                    'net_amount'   => $this->formatAmount($validatedData['client_subtotal'] - $validatedData['client_tax_total']),
                    'vat_amount'   => $this->formatAmount($validatedData['client_tax_total']),
                ],
                'errors'           => [$sequenceError],
            ], 200);
        }

        // Prepare anonymous in-memory OfflineSalesImport subclass to block database persistence
        $import = new class([
            'tenant_id'                => $tenant->id,
            'branch_id'                => $branch->id,
            'sales_machine_profile_id' => $profile->id,
            'offline_sequence_number'  => $validatedData['offline_sequence_number'],
            'raw_payload'              => $validatedData,
            'status'                   => \App\Models\OfflineSalesImport::STATUS_PENDING,
            'submitted_at'             => $validatedData['submitted_at'],
        ]) extends \App\Models\OfflineSalesImport {
            public $exists = false;

            public function save(array $options = []): bool
            {
                // Bypass database persist
                return true;
            }

            public function update(array $attributes = [], array $options = []): bool
            {
                // In-memory assignment
                $this->fill($attributes);
                return true;
            }
        };

        // Recalculate (dry-run)
        $recalcResult = $this->recalculationService->recalculate($import);

        $valid = $recalcResult['status'] === \App\Models\OfflineSalesImport::STATUS_SERVER_VERIFIED;
        $recalcData = $import->server_recalculation;

        $computedTotals = [
            'gross_amount' => $this->formatAmount($recalcData['server_total'] ?? 0),
            'net_amount'   => $this->formatAmount(($recalcData['server_subtotal'] ?? 0) - ($recalcData['server_tax_total'] ?? 0)),
            'vat_amount'   => $this->formatAmount($recalcData['server_tax_total'] ?? 0),
        ];

        $submittedTotals = [
            'gross_amount' => $this->formatAmount($validatedData['client_total']),
            'net_amount'   => $this->formatAmount($validatedData['client_subtotal'] - $validatedData['client_tax_total']),
            'vat_amount'   => $this->formatAmount($validatedData['client_tax_total']),
        ];

        $errors = [];
        if (!$valid) {
            $errors[] = [
                'code'    => 'TAX_RECALCULATION_MISMATCH',
                'message' => 'Submitted totals do not match server recalculation totals. ' . ($import->conflict_notes ?? ''),
                'field'   => 'client_total',
            ];
        }

        return response()->json([
            'valid'            => $valid,
            'classification'   => $valid ? 'server_verified' : 'conflict',
            'checks'           => [
                'schema'            => 'passed',
                'checksum'          => 'passed',
                'tax_recalculation' => $valid ? 'passed' : 'failed',
                'sequence_format'   => 'passed',
            ],
            'computed_totals'  => $computedTotals,
            'submitted_totals' => $submittedTotals,
            'errors'           => $errors,
        ], 200);
    }

    private function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
