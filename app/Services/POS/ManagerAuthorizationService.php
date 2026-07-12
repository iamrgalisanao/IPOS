<?php

namespace App\Services\POS;

use App\Models\DiscountType;
use App\Models\ManagerApproval;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ManagerAuthorizationService
{
    public const CONTEXT_VERSION = 'statutory-approval-v1';

    public function __construct(
        protected StatutoryDiscountService $discountService,
        protected ApprovalRuleResolver $ruleResolver,
        protected AuditLogger $auditLogger,
    ) {}

    public function issue(User $cashier, string $tenantId, string $branchId, SalesMachineProfile $terminal, DiscountType $type, array $items, array $options, string $email, string $password): ManagerApproval
    {
        if ($terminal->tenant_id !== $tenantId || $terminal->branch_id !== $branchId || $terminal->status !== 'active') {
            throw new \RuntimeException('Verified terminal context is required.');
        }

        $manager = User::where('tenant_id', $tenantId)->where('email', $email)->where('status', 'active')->first();
        $valid = $manager
            && Hash::check($password, $manager->password)
            && $manager->id !== $cashier->id
            && $manager->branches()->where('branches.id', $branchId)->exists()
            && $manager->hasPermission('pos.approve_discount');

        if (!$valid) {
            $this->auditLogger->log('statutory_discount_approval_rejected', null, metadata: [
                'cashier_id' => $cashier->id, 'discount_type_id' => $type->id,
                'terminal_id' => $terminal->id, 'reason_code' => 'invalid_manager_authorization',
            ]);
            throw new \RuntimeException('Manager authorization could not be verified.');
        }

        $context = $this->buildContext($tenantId, $branchId, $cashier->id, $terminal->id, $type, $items, $options);
        if (!$context['calculation']['is_valid']) {
            throw new \RuntimeException(implode(' ', $context['calculation']['errors']));
        }
        $rule = $this->ruleResolver->resolve($tenantId, $branchId, $type);
        if (!$rule['required']) {
            throw new \RuntimeException('Manager approval is not required for this statutory discount.');
        }

        $approval = ManagerApproval::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $manager->id,
            'requesting_user_id' => $cashier->id,
            'approvable_type' => 'SaleStatutoryDiscount',
            'approvable_id' => (string) Str::uuid(),
            'action' => 'approve',
            'metadata' => ['rule_source' => $rule['source'], 'rule_version' => $rule['rule_version']],
            'sales_machine_profile_id' => $terminal->id,
            'discount_type_id' => $type->id,
            'approval_rule_id' => $rule['rule_id'],
            'context_version' => self::CONTEXT_VERSION,
            'context_hmac' => $this->hmac($context['payload']),
            'status' => 'issued',
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->auditLogger->log('statutory_discount_approval_issued', $approval, metadata: [
            'manager_id' => $manager->id, 'cashier_id' => $cashier->id,
            'discount_type_id' => $type->id, 'terminal_id' => $terminal->id,
        ]);
        return $approval;
    }

    public function consume(string $approvalId, string $tenantId, string $branchId, string $cashierId, ?SalesMachineProfile $terminal, DiscountType $type, array $items, array $options, Sale $sale): ManagerApproval
    {
        if (!$terminal) {
            throw new \RuntimeException('A verified terminal is required for manager approval.');
        }
        $approval = ManagerApproval::where('id', $approvalId)->lockForUpdate()->first();
        $context = $this->buildContext(
            $tenantId, $branchId, $cashierId, $terminal->id, $type, $items, $options,
            $approval?->metadata['rule_version'] ?? null,
        );
        $valid = $approval
            && $approval->tenant_id === $tenantId
            && $approval->branch_id === $branchId
            && $approval->requesting_user_id === $cashierId
            && $approval->sales_machine_profile_id === $terminal->id
            && $approval->discount_type_id === $type->id
            && $approval->status === 'issued'
            && $approval->expires_at?->isFuture()
            && hash_equals((string) $approval->context_hmac, $this->hmac($context['payload']));
        if (!$valid) {
            throw new \RuntimeException('Statutory discount approval is invalid, expired, already used, or does not match this sale.');
        }
        $approval->forceFill([
            'status' => 'consumed', 'consumed_at' => now(), 'consumed_by_sale_id' => $sale->id,
            'approvable_id' => $sale->statutoryDiscounts()->firstOrFail()->id,
        ])->save();
        $this->auditLogger->log('statutory_discount_approval_consumed', $approval, metadata: [
            'sale_id' => $sale->id, 'discount_type_id' => $type->id,
        ]);
        return $approval;
    }

    public function buildContext(string $tenantId, string $branchId, string $cashierId, string $terminalId, DiscountType $type, array $items, array $options, ?string $capturedRuleVersion = null): array
    {
        $products = Product::where('tenant_id', $tenantId)->whereIn('id', collect($items)->pluck('product_id'))->with('taxCategory')->get()->keyBy('id');
        $normalized = collect($items)->map(function ($item) use ($products) {
            $product = $products->get($item['product_id']);
            if (!$product) throw new \RuntimeException('Invalid product in approval context.');
            $snapshot = $product->getSaleSnapshotBase();
            return [
                'product_id' => $product->id,
                'quantity' => number_format((float) $item['quantity'], 4, '.', ''),
                'line_subtotal' => number_format((float) $snapshot['selling_price'] * (float) $item['quantity'], 4, '.', ''),
                'tax_bucket' => $this->taxBucket($snapshot['tax_type'] ?? null),
            ];
        })->sortBy('product_id')->values();
        $calculation = $this->discountService->calculate($normalized, $type, $options);
        $beneficiaries = collect($options['beneficiaries'] ?? [])->map(fn ($b) => [
            'beneficiary_name' => trim((string) ($b['beneficiary_name'] ?? '')),
            'id_number' => trim((string) ($b['id_number'] ?? '')),
            'tin' => trim((string) ($b['tin'] ?? '')),
            'spic_number' => trim((string) ($b['spic_number'] ?? '')),
        ])->values()->all();
        $rule = $this->ruleResolver->resolve($tenantId, $branchId, $type);
        return ['calculation' => $calculation, 'payload' => [
            'version' => self::CONTEXT_VERSION, 'tenant_id' => $tenantId, 'branch_id' => $branchId,
            'terminal_id' => $terminalId, 'cashier_id' => $cashierId, 'discount_type_id' => $type->id,
            'rule_version' => $capturedRuleVersion ?? $rule['rule_version'], 'items' => $normalized->all(),
            'options' => [
                'application_mode' => $options['application_mode'] ?? 'standard',
                'eligible_person_count' => (int) ($options['eligible_person_count'] ?? 1),
                'total_pax_count' => isset($options['total_pax_count']) ? (int) $options['total_pax_count'] : null,
                'memc_base_value' => number_format((float) ($options['memc_base_value'] ?? 0), 4, '.', ''),
                'beneficiaries' => $beneficiaries,
            ],
            'calculation' => collect($calculation)->except(['calculation_snapshot'])->all(),
        ]];
    }

    protected function hmac(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), (string) config('app.key'));
    }

    protected function taxBucket(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'vat', 'vatable' => \App\Models\SaleItem::TAX_BUCKET_VATABLE,
            'exempt', 'exm' => \App\Models\SaleItem::TAX_BUCKET_VAT_EXEMPT,
            'zero-rated', 'zero_rated', 'zro' => \App\Models\SaleItem::TAX_BUCKET_ZERO_RATED,
            default => \App\Models\SaleItem::TAX_BUCKET_NON_VAT,
        };
    }
}
