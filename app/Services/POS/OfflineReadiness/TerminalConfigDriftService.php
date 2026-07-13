<?php

namespace App\Services\POS\OfflineReadiness;

use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Services\POS\TerminalLayoutResolver;
use Illuminate\Support\Carbon;

class TerminalConfigDriftService
{
    public function __construct(
        protected CacheBootstrapService $bootstrapService,
        protected TerminalLayoutResolver $layoutResolver
    ) {}

    /**
     * Build the expected server-side configuration snapshot for a terminal.
     */
    public function buildServerSnapshot(SalesMachineProfile $profile): array
    {
        $tenantId = $profile->tenant_id;
        $branchId = $profile->branch_id;

        $tenant = $profile->tenant()->withoutGlobalScopes()->first()
            ?: Tenant::withoutGlobalScopes()->find($tenantId);
        $branch = $profile->branch()->withoutGlobalScopes()->first()
            ?: Branch::withoutGlobalScopes()->find($branchId);

        $layout = $this->layoutResolver->resolveHashForProfile($profile, $this->bootstrapService);
        $catalog = $this->bootstrapService->calculateCatalogVersionHash($tenantId, $branchId);
        $tax = $this->bootstrapService->calculateTaxConfigHash($tenantId, $branchId);
        $discounts = $this->bootstrapService->calculateDiscountRulesVersionHash($tenantId);
        $paymentMethods = $this->bootstrapService->calculatePaymentMethodsVersionHash($tenantId, $branchId);
        $terminalPolicy = ($tenant && $branch)
            ? $this->bootstrapService->calculateTerminalPolicyVersionHash($tenant, $branch, $profile)
            : null;
        $printerProfile = $this->bootstrapService->calculatePrinterProfileVersionHash($tenantId, $branchId, $profile->id);
        $cashDrawerReasons = $this->bootstrapService->calculateCashDrawerReasonsVersionHash($tenantId, $branchId);

        $snapshot = [
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'sales_machine_profile_id' => $profile->id,
            'layout_version_hash' => $layout,
            'catalog_version_hash' => $catalog,
            'tax_configuration_version_hash' => $tax,
            'discount_rules_version_hash' => $discounts,
            'payment_methods_version_hash' => $paymentMethods,
            'terminal_policy_version_hash' => $terminalPolicy,
            'printer_profile_version_hash' => $printerProfile,
            'cash_drawer_reasons_version_hash' => $cashDrawerReasons,
        ];

        $snapshotHash = $this->hashCanonical($snapshot);
        $snapshot['config_snapshot_hash'] = $snapshotHash;

        return [
            'layout' => $layout,
            'catalog' => $catalog,
            'tax' => $tax,
            'discounts' => $discounts,
            'payment_methods' => $paymentMethods,
            'terminal_policy' => $terminalPolicy,
            'printer_profile' => $printerProfile,
            'cash_drawer_reasons' => $cashDrawerReasons,
            'config_snapshot_hash' => $snapshotHash,
        ];
    }

    /**
     * Safely extract client-reported configuration snapshot from import payload with fallback paths.
     */
    public function extractClientSnapshot(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        // If the payload is not an array (e.g. malformed or empty array), we handle it
        if (empty($payload)) {
            return [];
        }

        $configSnapshotHash = data_get($payload, 'config_snapshot.config_snapshot_hash')
            ?? data_get($payload, 'config_snapshot_hash')
            ?? data_get($payload, 'offline.config_snapshot_hash');

        $layout = data_get($payload, 'config_snapshot.layout_version_hash')
            ?? data_get($payload, 'layout_version_hash')
            ?? data_get($payload, 'offline.layout_version_hash');

        $catalog = data_get($payload, 'config_snapshot.catalog_version_hash')
            ?? data_get($payload, 'offline.catalog_version_hash')
            ?? data_get($payload, 'catalog_version_hash');

        $tax = data_get($payload, 'config_snapshot.tax_configuration_version_hash')
            ?? data_get($payload, 'offline.tax_configuration_version_hash')
            ?? data_get($payload, 'tax_configuration_version_hash');

        $discounts = data_get($payload, 'config_snapshot.discount_rules_version_hash')
            ?? data_get($payload, 'offline.discount_rules_version_hash')
            ?? data_get($payload, 'discount_rules_version_hash');

        $paymentMethods = data_get($payload, 'config_snapshot.payment_methods_version_hash')
            ?? data_get($payload, 'offline.payment_methods_version_hash')
            ?? data_get($payload, 'payment_methods_version_hash');

        $terminalPolicy = data_get($payload, 'config_snapshot.terminal_policy_version_hash')
            ?? data_get($payload, 'offline.terminal_policy_version_hash')
            ?? data_get($payload, 'terminal_policy_version_hash');

        $printerProfile = data_get($payload, 'config_snapshot.printer_profile_version_hash')
            ?? data_get($payload, 'offline.printer_profile_version_hash')
            ?? data_get($payload, 'printer_profile_version_hash');

        $cashDrawerReasons = data_get($payload, 'config_snapshot.cash_drawer_reasons_version_hash')
            ?? data_get($payload, 'offline.cash_drawer_reasons_version_hash')
            ?? data_get($payload, 'cash_drawer_reasons_version_hash');

        $configSnapshot = data_get($payload, 'config_snapshot') ?: [];
        $offlinePayload = data_get($payload, 'offline') ?: [];

        $cashDrawerReasonsExists = array_key_exists('cash_drawer_reasons_version_hash', $configSnapshot)
            || array_key_exists('cash_drawer_reasons_version_hash', $offlinePayload)
            || array_key_exists('cash_drawer_reasons_version_hash', $payload ?: []);

        return [
            'layout' => $layout,
            'catalog' => $catalog,
            'tax' => $tax,
            'discounts' => $discounts,
            'payment_methods' => $paymentMethods,
            'terminal_policy' => $terminalPolicy,
            'printer_profile' => $printerProfile,
            'cash_drawer_reasons' => $cashDrawerReasons,
            'cash_drawer_reasons_exists' => $cashDrawerReasonsExists,
            'config_snapshot_hash' => $configSnapshotHash,
        ];
    }

    /**
     * Compare server and client snapshots to evaluate drift, status, and staleness.
     */
    public function compare(array $server, ?array $client, ?Carbon $submittedAt): array
    {
        if ($client === null) {
            return [
                'config_status' => 'no_sync_log',
                'has_config_drift' => false,
                'is_stale_report' => false,
                'last_config_reported_at' => null,
                'server_snapshot' => $server,
                'client_snapshot' => null,
                'drifted_components' => [],
                'not_reported_components' => [],
                'components' => [],
            ];
        }

        // If client array is empty or missing key identifiers, flag as invalid payload
        if (empty($client)) {
            return [
                'config_status' => 'invalid_payload',
                'has_config_drift' => null,
                'is_stale_report' => false,
                'last_config_reported_at' => $submittedAt?->toIso8601String(),
                'server_snapshot' => $server,
                'client_snapshot' => [],
                'drifted_components' => [],
                'not_reported_components' => [],
                'components' => [],
            ];
        }

        $componentMap = [
            'layout' => 'Layout',
            'catalog' => 'Catalog',
            'tax' => 'Tax',
            'discounts' => 'Discounts',
            'payment_methods' => 'Payment Methods',
            'terminal_policy' => 'Terminal Policy',
            'printer_profile' => 'Printer Profile',
            'cash_drawer_reasons' => 'Cash Drawer Reasons',
        ];

        $components = [];
        $driftedComponents = [];
        $notReportedComponents = [];

        foreach ($componentMap as $key => $label) {
            $serverVal = $server[$key] ?? null;
            $clientVal = $client[$key] ?? null;

            if ($key === 'printer_profile') {
                $status = 'placeholder';
            } elseif ($key === 'cash_drawer_reasons' && !($client['cash_drawer_reasons_exists'] ?? false)) {
                $status = 'placeholder';
            } elseif ($clientVal === null) {
                $status = 'not_reported';
                $notReportedComponents[] = $key;
            } elseif ($clientVal === $serverVal) {
                $status = 'synced';
            } else {
                $status = 'drifted';
                $driftedComponents[] = $key;
            }

            $components[] = [
                'key' => $key,
                'label' => $label,
                'server_hash' => $serverVal,
                'client_hash' => $clientVal,
                'status' => $status,
            ];
        }

        $hasDrift = !empty($driftedComponents);
        $isStale = false;

        if ($submittedAt !== null) {
            $isStale = $submittedAt->diffInHours(now()) >= 24;
        }

        // Determine main status
        // If all component values (except printer) are null/not reported, mark status as not_reported
        $reportedCount = 0;
        foreach ($client as $k => $v) {
            if ($k !== 'printer_profile' && $k !== 'config_snapshot_hash' && $k !== 'cash_drawer_reasons_exists' && $v !== null) {
                $reportedCount++;
            }
        }

        if ($reportedCount === 0) {
            $configStatus = 'not_reported';
            $hasDrift = null; // matching the refinement table
        } elseif ($hasDrift) {
            $configStatus = 'drifted';
        } elseif ($isStale) {
            $configStatus = 'stale_report';
        } else {
            $configStatus = 'synced';
        }

        return [
            'config_status' => $configStatus,
            'has_config_drift' => $hasDrift,
            'is_stale_report' => $isStale,
            'last_config_reported_at' => $submittedAt?->toIso8601String(),
            'server_snapshot' => $server,
            'client_snapshot' => $client,
            'drifted_components' => $driftedComponents,
            'not_reported_components' => $notReportedComponents,
            'components' => $components,
        ];
    }

    private function hashCanonical(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        return $value;
    }
}
