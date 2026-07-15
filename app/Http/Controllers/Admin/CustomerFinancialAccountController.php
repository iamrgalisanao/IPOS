<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Customers\CustomerFinancialAccountAlreadyExistsException;
use App\Exceptions\Customers\CustomerFinancialAccountCurrencyImmutableException;
use App\Exceptions\Customers\CustomerFinancialAccountOwnershipImmutableException;
use App\Exceptions\Customers\CustomerFinancialAccountStateConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\AnonymizeCustomerRequest;
use App\Http\Requests\Customers\StoreCustomerFinancialAccountRequest;
use App\Http\Requests\Customers\UpdateCustomerFinancialAccountStatusRequest;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Services\Customers\CustomerFinancialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerFinancialAccountController extends Controller
{
    public function __construct(
        private readonly CustomerFinancialAccountService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('customer-accounts.view'), 403);

        $query = CustomerFinancialAccount::query()
            ->with('customer')
            ->latest();

        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));
            $query->whereHas('customer', function ($customerQuery) use ($search) {
                $customerQuery->where('normalized_display_name', 'like', '%' . $this->service->normalizeName($search) . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('external_reference', 'like', '%' . $search . '%');
            });
        }

        return response()->json([
            'customer_financial_accounts' => $query->limit(100)->get(),
        ]);
    }

    public function store(StoreCustomerFinancialAccountRequest $request): JsonResponse
    {
        try {
            $account = $this->service->createAccount($request->validated(), $request->user());
        } catch (
            CustomerFinancialAccountAlreadyExistsException
            | CustomerFinancialAccountCurrencyImmutableException
            | CustomerFinancialAccountStateConflictException
            | CustomerFinancialAccountOwnershipImmutableException $exception
        ) {
            return $this->conflict($exception);
        }

        return response()->json(['customer_financial_account' => $account], 201);
    }

    public function show(Request $request, CustomerFinancialAccount $customerFinancialAccount): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('customer-accounts.view'), 403);

        return response()->json([
            'customer_financial_account' => $customerFinancialAccount->load('customer'),
        ]);
    }

    public function status(
        UpdateCustomerFinancialAccountStatusRequest $request,
        CustomerFinancialAccount $customerFinancialAccount
    ): JsonResponse {
        try {
            $account = $this->service->updateStatus(
                $customerFinancialAccount,
                (string) $request->validated('status'),
                $request->validated('reason'),
                $request->user()
            );
        } catch (CustomerFinancialAccountStateConflictException $exception) {
            return $this->conflict($exception);
        }

        return response()->json(['customer_financial_account' => $account]);
    }

    public function anonymize(AnonymizeCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->service->anonymizeCustomer(
            $customer,
            $request->validated('reason'),
            $request->user()
        );

        return response()->json(['customer' => $customer]);
    }

    private function conflict(\Throwable $exception): JsonResponse
    {
        return response()->json([
            'code' => class_basename($exception::class),
            'message' => $exception->getMessage(),
        ], 409);
    }
}
