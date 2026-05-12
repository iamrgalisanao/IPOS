<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountingMappingRequest;
use App\Http\Requests\Accounting\UpdateAccountingMappingRequest;
use App\Models\AccountingMapping;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TaxCategory;
use App\Services\Accounting\AccountingMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingMappingController extends Controller
{
    public function __construct(
        protected AccountingMappingService $mappingService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $filters = $request->only(['provider', 'mapping_type', 'status', 'branch_id']);
        $mappings = $this->queryMappings($request, $filters)
            ->paginate(25)
            ->withQueryString();

        $mappings->through(fn (AccountingMapping $mapping) => $this->mappingResource($mapping));

        return Inertia::render('Accounting/Mappings/Index', [
            'filters' => $filters,
            'mappings' => $mappings,
            'options' => $this->options($request),
            'defaults' => $this->defaultFormState($request),
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function store(StoreAccountingMappingRequest $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->assertCanWriteScope($request->user(), $request->validated('branch_id'));

        $mapping = $this->mappingService->createOrUpdate($request->validated(), $request->user());

        return redirect()
            ->route('accounting.mappings.show', $mapping)
            ->with('status', 'Accounting mapping saved.');
    }

    public function show(Request $request, string $mapping): Response
    {
        $mapping = $this->findMapping($mapping);
        $this->authorizeManage($request);
        $this->assertCanViewMapping($request->user(), $mapping);

        return Inertia::render('Accounting/Mappings/Show', [
            'mapping' => $this->mappingResource($mapping),
            'options' => $this->options($request),
            'defaults' => $this->formStateFromMapping($mapping),
            'canEdit' => $this->canWriteScope($request->user(), $mapping->branch_id),
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function update(UpdateAccountingMappingRequest $request, string $mapping): RedirectResponse
    {
        $mapping = $this->findMapping($mapping);
        $this->authorizeManage($request);
        $this->assertCanWriteMapping($request->user(), $mapping, $request->validated('branch_id'));

        $mapping = $this->mappingService->update($mapping, $request->validated(), $request->user());

        return redirect()
            ->route('accounting.mappings.show', $mapping)
            ->with('status', 'Accounting mapping updated.');
    }

    public function status(Request $request, string $mapping): RedirectResponse
    {
        $mapping = $this->findMapping($mapping);
        $this->authorizeManage($request);
        $this->assertCanWriteMapping($request->user(), $mapping, $mapping->branch_id);

        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $mapping = $this->mappingService->setStatus($mapping, $validated['status'], $request->user());

        return redirect()
            ->route('accounting.mappings.show', $mapping)
            ->with('status', 'Accounting mapping status updated.');
    }

    protected function queryMappings(Request $request, array $filters)
    {
        $query = AccountingMapping::query()->with('branch')->latest('updated_at');

        $allowedBranchIds = $this->allowedBranchIds($request->user());

        if ($allowedBranchIds !== null) {
            $query->where(function ($builder) use ($allowedBranchIds) {
                $builder->whereNull('branch_id');
                if ($allowedBranchIds !== []) {
                    $builder->orWhereIn('branch_id', $allowedBranchIds);
                }
            });
        }

        if (filled($filters['provider'] ?? null)) {
            $query->where('provider', $filters['provider']);
        }

        if (filled($filters['mapping_type'] ?? null)) {
            $query->where('mapping_type', $filters['mapping_type']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['branch_id'] ?? null)) {
            $query->where('branch_id', $filters['branch_id']);
        }

        return $query;
    }

    protected function options(Request $request): array
    {
        $branchQuery = Branch::query()->where('status', 'active')->orderBy('name');
        $allowedBranchIds = $this->allowedBranchIds($request->user());
        if ($allowedBranchIds !== null) {
            $branchQuery->whereIn('id', $allowedBranchIds);
        }

        return [
            'providers' => AccountingMappingService::supportedProviders(),
            'mappingTypes' => AccountingMappingService::supportedTypes(),
            'statuses' => AccountingMappingService::supportedStatuses(),
            'canManageTenantLevel' => $request->user()->hasPermission('view_multi_branch_dashboard'),
            'branches' => $branchQuery->get(['id', 'name'])->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->all(),
            'taxCategories' => TaxCategory::query()->active()->orderBy('name')->get(['id', 'name', 'code'])->map(fn (TaxCategory $tax) => [
                'id' => $tax->id,
                'name' => $tax->name,
                'code' => $tax->code,
            ])->all(),
            'paymentMethods' => PaymentMethod::query()->active()->orderBy('name')->get(['id', 'name', 'code'])->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
            ])->all(),
            'products' => Product::query()->active()->orderBy('name')->get(['id', 'name', 'sku'])->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ])->all(),
            'customerSupported' => false,
        ];
    }

    protected function mappingResource(AccountingMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'branch_id' => $mapping->branch_id,
            'branch_name' => $mapping->branch?->name,
            'provider' => $mapping->provider,
            'mapping_type' => $mapping->mapping_type,
            'pos_entity_type' => $mapping->pos_entity_type,
            'pos_entity_id' => $mapping->pos_entity_id,
            'pos_key' => $mapping->pos_key,
            'external_id' => $mapping->external_id,
            'external_name' => $mapping->external_name,
            'metadata' => $mapping->metadata,
            'status' => $mapping->status,
            'updated_at' => optional($mapping->updated_at)?->toIso8601String(),
            'created_at' => optional($mapping->created_at)?->toIso8601String(),
        ];
    }

    protected function defaultFormState(Request $request): array
    {
        return [
            'provider' => AccountingMappingService::PROVIDER_QUICKBOOKS,
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_entity_type' => null,
            'pos_entity_id' => null,
            'pos_key' => null,
            'external_id' => '',
            'external_name' => '',
            'metadata' => null,
            'status' => AccountingMapping::STATUS_ACTIVE,
            'branch_id' => $request->user()->hasPermission('view_multi_branch_dashboard') ? null : optional($request->user()->branches()->first())->id,
        ];
    }

    protected function formStateFromMapping(AccountingMapping $mapping): array
    {
        return [
            'provider' => $mapping->provider,
            'mapping_type' => $mapping->mapping_type,
            'pos_entity_type' => $mapping->pos_entity_type,
            'pos_entity_id' => $mapping->pos_entity_id,
            'pos_key' => $mapping->pos_key,
            'external_id' => $mapping->external_id,
            'external_name' => $mapping->external_name,
            'metadata' => $mapping->metadata,
            'status' => $mapping->status,
            'branch_id' => $mapping->branch_id,
        ];
    }

    protected function findMapping(string $mappingId): AccountingMapping
    {
        return AccountingMapping::query()->with('branch')->findOrFail($mappingId);
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('manage_accounting_mappings'), 403, 'Unauthorized. Permission required: manage_accounting_mappings');
    }

    protected function assertCanViewMapping($user, AccountingMapping $mapping): void
    {
        $allowedBranchIds = $this->allowedBranchIds($user);

        if ($allowedBranchIds === null || $mapping->branch_id === null) {
            return;
        }

        abort_unless(in_array($mapping->branch_id, $allowedBranchIds, true), 404);
    }

    protected function assertCanWriteMapping($user, AccountingMapping $mapping, ?string $targetBranchId): void
    {
        $this->assertCanViewMapping($user, $mapping);
        $this->assertCanWriteScope($user, $targetBranchId);
    }

    protected function assertCanWriteScope($user, ?string $branchId): void
    {
        abort_unless($this->canWriteScope($user, $branchId), 403, 'Branch scope access denied for mapping changes.');
    }

    protected function canWriteScope($user, ?string $branchId): bool
    {
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return true;
        }

        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, $this->allowedBranchIds($user) ?? [], true);
    }

    protected function allowedBranchIds($user): ?array
    {
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return null;
        }

        return $user->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
    }
}