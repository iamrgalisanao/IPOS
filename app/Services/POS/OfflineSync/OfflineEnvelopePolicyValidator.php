<?php

namespace App\Services\POS\OfflineSync;

use App\Models\PaymentMethod;
use App\Models\SalesMachineProfile;
use Illuminate\Support\Carbon;

class OfflineEnvelopePolicyValidator
{
    private const SUPPORTED_SCHEMA_VERSIONS = [1, 2];

    public function reasonFor(SalesMachineProfile $profile, array $rawImport): ?string
    {
        if (($rawImport['terminal_id'] ?? $rawImport['sales_machine_profile_id'] ?? $profile->id) !== $profile->id) {
            return $this->reason($rawImport, 'rejected_terminal_identity_invalid', 'review_terminal_identity_invalid_cash_collected');
        }

        if (($rawImport['tenant_id'] ?? $profile->tenant_id) !== $profile->tenant_id
            || ($rawImport['branch_id'] ?? $profile->branch_id) !== $profile->branch_id) {
            return $this->reason($rawImport, 'rejected_cross_tenant_or_branch', 'review_cross_tenant_or_branch_cash_collected');
        }

        if (!$this->hasSupportedSchemaVersion($rawImport)) {
            return $this->reason($rawImport, 'rejected_unknown_offline_payload_schema', 'review_unknown_offline_payload_schema_cash_collected');
        }

        if ($this->containsFiscalIdentity($rawImport)) {
            return $this->reason($rawImport, 'rejected_official_receipt_identity_offline', 'review_official_receipt_identity_cash_collected');
        }

        if ($this->containsApprovalEvidence($rawImport)) {
            return $this->reason($rawImport, 'rejected_offline_manager_approval', 'review_offline_manager_approval_cash_collected');
        }

        if (!empty($rawImport['statutory_discount'])) {
            return $this->reason($rawImport, 'rejected_statutory_discount_offline', 'review_statutory_discount_offline_cash_collected');
        }

        $shiftFailure = $this->validateShiftEvidence($rawImport);
        if ($shiftFailure !== null) {
            return $this->reason($rawImport, $shiftFailure['rejected'], $shiftFailure['review']);
        }

        if (($rawImport['payment_method'] ?? null) !== 'cash') {
            return $this->reason($rawImport, 'rejected_non_cash_tender', 'review_non_cash_tender_cash_collected');
        }

        $payments = $rawImport['payments'] ?? null;
        if (!is_array($payments) || empty($payments)) {
            return $this->reason($rawImport, 'rejected_missing_payment_evidence', 'review_missing_payment_evidence_cash_collected');
        }

        foreach ($payments as $payment) {
            $paymentFailure = $this->validatePaymentEvidence($profile, $payment);

            if ($paymentFailure !== null) {
                return $this->reason($rawImport, $paymentFailure['rejected'], $paymentFailure['review']);
            }
        }

        return null;
    }

    private function hasSupportedSchemaVersion(array $rawImport): bool
    {
        if (!array_key_exists('schema_version', $rawImport)) {
            return true;
        }

        return in_array((int) $rawImport['schema_version'], self::SUPPORTED_SCHEMA_VERSIONS, true);
    }

    private function validateShiftEvidence(array $rawImport): ?array
    {
        foreach ([
            'cashier_shift_id',
            'shift_authorization_id',
            'shift_authorization_policy_version',
            'shift_authorization_issued_at',
            'authorized_offline_until',
            'shift_status_snapshot',
        ] as $field) {
            if (empty($rawImport[$field])) {
                return [
                    'rejected' => 'rejected_missing_shift_authority',
                    'review' => 'review_missing_shift_authority_cash_collected',
                ];
            }
        }

        if (($rawImport['shift_status_snapshot'] ?? null) !== 'open') {
            return [
                'rejected' => 'rejected_stale_shift_authority',
                'review' => 'review_stale_shift_authority_cash_collected',
            ];
        }

        try {
            $expiresAt = Carbon::parse($rawImport['authorized_offline_until']);
        } catch (\Throwable) {
            return [
                'rejected' => 'rejected_invalid_shift_authority_timestamp',
                'review' => 'review_invalid_shift_authority_timestamp_cash_collected',
            ];
        }

        if ($expiresAt->lessThan(now())) {
            return [
                'rejected' => 'rejected_stale_shift_authority',
                'review' => 'review_stale_shift_authority_cash_collected',
            ];
        }

        return null;
    }

    private function validatePaymentEvidence(SalesMachineProfile $profile, array $payment): ?array
    {
        if (empty($payment['payment_method_id']) || !array_key_exists('amount', $payment)) {
            return [
                'rejected' => 'rejected_missing_payment_evidence',
                'review' => 'review_missing_payment_evidence_cash_collected',
            ];
        }

        foreach ([
            'payment_method_type_snapshot',
            'payment_method_name_snapshot',
            'payment_method_version',
            'payment_method_configuration_hash',
        ] as $field) {
            if (empty($payment[$field])) {
                return [
                    'rejected' => 'rejected_missing_payment_method_snapshot',
                    'review' => 'review_missing_payment_method_snapshot_cash_collected',
                ];
            }
        }

        if (strtolower((string) $payment['payment_method_type_snapshot']) !== 'cash') {
            return [
                'rejected' => 'rejected_non_cash_tender',
                'review' => 'review_non_cash_tender_cash_collected',
            ];
        }

        $method = PaymentMethod::where('tenant_id', $profile->tenant_id)
            ->where('id', $payment['payment_method_id'])
            ->active()
            ->first();

        if (!$method) {
            return [
                'rejected' => 'rejected_cash_payment_configuration_unavailable',
                'review' => 'review_required_cash_payment_configuration',
            ];
        }

        if (!$this->isCashMethod($method)) {
            return [
                'rejected' => 'rejected_non_cash_tender',
                'review' => 'review_non_cash_tender_cash_collected',
            ];
        }

        return null;
    }

    private function reason(array $rawImport, string $rejectedReason, string $reviewReason): string
    {
        $cashExposure = OfflineSyncReviewStateService::cashExposureFrom($rawImport['cash_status'] ?? null);

        return in_array($cashExposure, ['collected', 'unknown'], true) ? $reviewReason : $rejectedReason;
    }

    private function containsFiscalIdentity(array $payload): bool
    {
        return $this->containsAnyKey($payload, [
            'official_invoice_number',
            'official_receipt_number',
            'server_sale_number',
            'receipt_reference',
            'e_journal_id',
            'gct_id',
            'z_read_number',
            'bir_receipt',
            'final_posting_identity',
        ]);
    }

    private function containsApprovalEvidence(array $payload): bool
    {
        return $this->containsAnyKey($payload, [
            'manager_approval_id',
            'manager_approval',
            'approval_id',
            'approval_snapshot',
        ]);
    }

    private function containsAnyKey(array $payload, array $blockedKeys): bool
    {
        $blocked = array_fill_keys($blockedKeys, true);

        foreach ($payload as $key => $value) {
            if (isset($blocked[strtolower((string) $key)])) {
                return true;
            }

            if (is_array($value) && $this->containsAnyKey($value, $blockedKeys)) {
                return true;
            }
        }

        return false;
    }

    private function isCashMethod(PaymentMethod $method): bool
    {
        $code = strtolower((string) $method->code);
        $name = strtolower((string) $method->name);
        $type = strtolower((string) $method->type);

        return $code === 'cash' || $type === 'cash' || str_contains($name, 'cash');
    }
}
