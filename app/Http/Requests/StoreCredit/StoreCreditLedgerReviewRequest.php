<?php

namespace App\Http\Requests\StoreCredit;

use App\Models\StoreCreditLedgerEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditLedgerReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user
            && $user->hasPermission('customer-accounts.view')
            && $user->hasPermission('store-credit.review');
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'uuid'],
            'entry_type' => [
                'nullable',
                'string',
                Rule::in([
                    StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
                    StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
                    StoreCreditLedgerEntry::TYPE_ADMIN_CREDIT_ADJUSTMENT,
                    StoreCreditLedgerEntry::TYPE_ADMIN_DEBIT_ADJUSTMENT,
                    StoreCreditLedgerEntry::TYPE_REVERSAL_CREDIT,
                    StoreCreditLedgerEntry::TYPE_REVERSAL_DEBIT,
                    StoreCreditLedgerEntry::TYPE_EXPIRATION_DEBIT,
                    StoreCreditLedgerEntry::TYPE_FORFEITURE_DEBIT,
                ]),
            ],
            'ledger_category' => [
                'nullable',
                'string',
                Rule::in([
                    StoreCreditLedgerEntry::CATEGORY_CREDIT,
                    StoreCreditLedgerEntry::CATEGORY_DEBIT,
                    StoreCreditLedgerEntry::CATEGORY_ADJUSTMENT,
                    StoreCreditLedgerEntry::CATEGORY_REVERSAL,
                    StoreCreditLedgerEntry::CATEGORY_EXPIRATION,
                ]),
            ],
            'direction' => [
                'nullable',
                'string',
                Rule::in([
                    StoreCreditLedgerEntry::DIRECTION_CREDIT,
                    StoreCreditLedgerEntry::DIRECTION_DEBIT,
                ]),
            ],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'posted_by' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        return $this->validated();
    }
}
