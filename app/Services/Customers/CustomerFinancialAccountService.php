<?php

namespace App\Services\Customers;

use App\Exceptions\Customers\CustomerFinancialAccountAlreadyExistsException;
use App\Exceptions\Customers\CustomerFinancialAccountCurrencyImmutableException;
use App\Exceptions\Customers\CustomerFinancialAccountStateConflictException;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerFinancialAccountService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function createAccount(array $data, User $actor): CustomerFinancialAccount
    {
        return DB::transaction(function () use ($data, $actor) {
            $tenant = $this->tenantContext->getTenant();
            $currency = strtoupper($data['currency_code'] ?? $tenant->currency ?? 'PHP');

            if ($currency !== strtoupper($tenant->currency ?? 'PHP')) {
                throw new CustomerFinancialAccountCurrencyImmutableException('Customer financial account currency must match the tenant currency.');
            }

            $customer = isset($data['customer_id'])
                ? $this->resolveCustomer((string) $data['customer_id'])
                : $this->createCustomer($data, $actor);

            if ($customer->financialAccount()->exists()) {
                throw new CustomerFinancialAccountAlreadyExistsException('Customer already has a financial account.');
            }

            $account = CustomerFinancialAccount::create([
                'customer_id' => $customer->id,
                'status' => CustomerFinancialAccount::STATUS_ACTIVE,
                'currency_code' => $currency,
                'opened_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->auditLogger->log(
                'CUSTOMER_FINANCIAL_ACCOUNT_CREATED',
                $account,
                null,
                $this->accountAuditPayload($account),
                metadata: ['event_version' => 1]
            );

            return $account->load('customer');
        });
    }

    public function updateStatus(CustomerFinancialAccount $account, string $status, ?string $reason, User $actor): CustomerFinancialAccount
    {
        $before = $this->accountAuditPayload($account);

        return DB::transaction(function () use ($account, $status, $reason, $actor, $before) {
            match ($status) {
                CustomerFinancialAccount::STATUS_ACTIVE => $this->reactivate($account),
                CustomerFinancialAccount::STATUS_SUSPENDED => $this->suspend($account),
                CustomerFinancialAccount::STATUS_CLOSED => $this->close($account),
                default => throw new CustomerFinancialAccountStateConflictException('Unsupported customer financial account status.'),
            };

            $account->updated_by = $actor->id;
            $account->save();

            $this->auditLogger->log(
                $this->statusAuditAction($status),
                $account,
                $before,
                $this->accountAuditPayload($account),
                reason: $reason,
                metadata: ['event_version' => 1]
            );

            return $account->fresh('customer');
        });
    }

    public function anonymizeCustomer(Customer $customer, ?string $reason, User $actor): Customer
    {
        $before = $this->customerAuditPayload($customer);

        return DB::transaction(function () use ($customer, $reason, $actor, $before) {
            $customer->forceFill([
                'display_name' => 'Anonymized Customer ' . Str::of((string) $customer->id)->substr(0, 8),
                'normalized_display_name' => 'anonymized customer ' . Str::of((string) $customer->id)->substr(0, 8),
                'email' => null,
                'phone' => null,
                'external_reference' => null,
                'metadata' => array_filter([
                    'anonymized' => true,
                    'previous_fields_removed' => ['display_name', 'email', 'phone', 'external_reference', 'metadata'],
                ]),
                'status' => Customer::STATUS_ANONYMIZED,
                'anonymized_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->auditLogger->log(
                'CUSTOMER_ANONYMIZED',
                $customer,
                $before,
                $this->customerAuditPayload($customer),
                reason: $reason,
                metadata: [
                    'event_version' => 1,
                    'removed_fields' => ['display_name', 'email', 'phone', 'external_reference', 'metadata'],
                ]
            );

            return $customer->fresh('financialAccount');
        });
    }

    public function assertCustomerCanBeHardDeleted(Customer $customer): void
    {
        if ($customer->financialAccount()->exists()) {
            throw new CustomerFinancialAccountStateConflictException('Customers with financial accounts cannot be physically deleted.');
        }
    }

    private function createCustomer(array $data, User $actor): Customer
    {
        $name = trim((string) $data['display_name']);

        $customer = Customer::create([
            'display_name' => $name,
            'normalized_display_name' => $this->normalizeName($name),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'status' => Customer::STATUS_ACTIVE,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->auditLogger->log(
            'CUSTOMER_CREATED',
            $customer,
            null,
            $this->customerAuditPayload($customer),
            metadata: ['event_version' => 1]
        );

        return $customer;
    }

    private function resolveCustomer(string $customerId): Customer
    {
        $customer = Customer::query()->whereKey($customerId)->first();

        if (!$customer) {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected customer is invalid.'],
            ]);
        }

        return $customer;
    }

    private function suspend(CustomerFinancialAccount $account): void
    {
        if ($account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
            throw new CustomerFinancialAccountStateConflictException('Only active customer financial accounts can be suspended.');
        }

        $account->status = CustomerFinancialAccount::STATUS_SUSPENDED;
        $account->suspended_at = now();
    }

    private function reactivate(CustomerFinancialAccount $account): void
    {
        if ($account->status !== CustomerFinancialAccount::STATUS_SUSPENDED) {
            throw new CustomerFinancialAccountStateConflictException('Only suspended customer financial accounts can be reactivated.');
        }

        $account->status = CustomerFinancialAccount::STATUS_ACTIVE;
        $account->suspended_at = null;
    }

    private function close(CustomerFinancialAccount $account): void
    {
        if (!in_array($account->status, [CustomerFinancialAccount::STATUS_ACTIVE, CustomerFinancialAccount::STATUS_SUSPENDED], true)) {
            throw new CustomerFinancialAccountStateConflictException('Only active or suspended customer financial accounts can be closed.');
        }

        $account->status = CustomerFinancialAccount::STATUS_CLOSED;
        $account->closed_at = now();
    }

    private function statusAuditAction(string $status): string
    {
        return match ($status) {
            CustomerFinancialAccount::STATUS_ACTIVE => 'CUSTOMER_FINANCIAL_ACCOUNT_REACTIVATED',
            CustomerFinancialAccount::STATUS_SUSPENDED => 'CUSTOMER_FINANCIAL_ACCOUNT_SUSPENDED',
            CustomerFinancialAccount::STATUS_CLOSED => 'CUSTOMER_FINANCIAL_ACCOUNT_CLOSED',
            default => 'CUSTOMER_FINANCIAL_ACCOUNT_UPDATED',
        };
    }

    public function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    private function accountAuditPayload(CustomerFinancialAccount $account): array
    {
        return [
            'id' => $account->id,
            'customer_id' => $account->customer_id,
            'status' => $account->status,
            'currency_code' => $account->currency_code,
            'opened_at' => $account->opened_at?->toISOString(),
            'suspended_at' => $account->suspended_at?->toISOString(),
            'closed_at' => $account->closed_at?->toISOString(),
        ];
    }

    private function customerAuditPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'status' => $customer->status,
            'has_email' => filled($customer->email),
            'has_phone' => filled($customer->phone),
            'has_external_reference' => filled($customer->external_reference),
            'anonymized_at' => $customer->anonymized_at?->toISOString(),
        ];
    }
}
