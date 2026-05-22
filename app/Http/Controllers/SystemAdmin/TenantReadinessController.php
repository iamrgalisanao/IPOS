<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\TenantReadinessSignOff;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TenantReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantReadinessController extends Controller
{
    public function __construct(
        protected TenantReadinessService $tenantReadinessService,
        protected AuditLogger $auditLogger,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Read-only readiness summary for Story 29.5 Slice A.
     */
    public function show(Tenant $company): JsonResponse
    {
        return response()->json(
            $this->tenantReadinessService->getReadinessSummary($company)
        );
    }

    /**
     * Append-only readiness decision capture for Story 29.5 Slice B.
     */
    public function signOff(Request $request, Tenant $company): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:' . implode(',', TenantReadinessService::ALLOWED_DECISIONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = $validated['state'];
        $notes = $validated['notes'] ?? null;
        $evaluation = $this->tenantReadinessService->evaluateSignOffDecision($company, $decision, $notes);

        if (!$evaluation['allowed']) {
            $this->platformAudit(
                action: 'tenant_readiness_sign_off_rejected',
                company: $company,
                decision: $decision,
                evaluation: $evaluation,
                notes: $notes,
                decisionId: null
            );

            return response()->json([
                'message' => $evaluation['message'],
                'readiness_state_calculated' => $evaluation['readiness_state_calculated'],
                'blockers' => $evaluation['blockers'],
            ], 422);
        }

        $signOff = DB::transaction(function () use ($company, $decision, $notes, $evaluation) {
            $signOff = TenantReadinessSignOff::create([
                'tenant_id' => $company->id,
                'signed_off_by' => auth()->id(),
                'signed_off_state' => $decision,
                'readiness_state_calculated' => $evaluation['readiness_state_calculated'],
                'notes' => $notes,
                'readiness_snapshot' => $evaluation['snapshot'],
                'created_at' => now(),
            ]);

            $this->platformAudit(
                action: 'tenant_readiness_signed_off',
                company: $company,
                decision: $decision,
                evaluation: $evaluation,
                notes: $notes,
                decisionId: $signOff->id
            );

            return $signOff;
        });

        return response()->json([
            'success' => true,
            'decision_id' => $signOff->id,
            'signed_off_state' => $signOff->signed_off_state,
            'readiness_state_calculated' => $signOff->readiness_state_calculated,
            'signed_off_at' => $signOff->created_at->toIso8601String(),
        ]);
    }

    /**
     * Lightweight read-only export for Story 29.5 Slice C.
     */
    public function export(Request $request, Tenant $company)
    {
        $format = strtolower((string) $request->query('format', 'json'));
        abort_unless(in_array($format, ['json', 'csv', 'html'], true), 404);

        $payload = $this->buildExportPayload($company);

        return match ($format) {
            'csv' => $this->csvExport($company, $payload),
            'html' => $this->htmlExport($payload),
            default => response()->json($payload),
        };
    }

    private function platformAudit(
        string $action,
        Tenant $company,
        string $decision,
        array $evaluation,
        ?string $notes,
        ?string $decisionId
    ): void {
        $this->tenantContext->setTenant($company);

        try {
            $this->auditLogger->log(
                action: $action,
                metadata: [
                    'tenant_id' => $company->id,
                    'decision_id' => $decisionId,
                    'signed_off_state' => $decision,
                    'readiness_state_calculated' => $evaluation['readiness_state_calculated'],
                    'actor_id' => auth()->id(),
                    'blocker_count' => count($evaluation['blockers']),
                    'notes_present' => !blank($notes),
                    'outcome' => $evaluation['allowed'] ? 'accepted' : 'rejected',
                    'message' => $evaluation['message'],
                    'readiness_snapshot' => $evaluation['snapshot'],
                ]
            );
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function buildExportPayload(Tenant $company): array
    {
        $summary = $this->tenantReadinessService->getReadinessSummary($company);

        $signOffs = TenantReadinessSignOff::query()
            ->where('tenant_id', $company->id)
            ->with('signer:id,email,first_name,last_name,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TenantReadinessSignOff $signOff) {
                $signer = $signOff->signer;

                return [
                    'id' => $signOff->id,
                    'signed_off_state' => $signOff->signed_off_state,
                    'readiness_state_calculated' => $signOff->readiness_state_calculated,
                    'signed_off_at' => $signOff->created_at?->toIso8601String(),
                    'signed_off_by' => $signer ? [
                        'id' => $signer->id,
                        'email' => $signer->email,
                        'name' => trim(($signer->first_name ?? '') . ' ' . ($signer->last_name ?? '')) ?: ($signer->name ?? $signer->email),
                    ] : null,
                    'notes' => $signOff->notes,
                ];
            })
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'sign_off_history' => $signOffs,
            'non_certification_notice' => 'This readiness export is an internal operational review artifact and is not a BIR/CPA certification.',
        ];
    }

    private function csvExport(Tenant $company, array $payload)
    {
        $filename = sprintf(
            'tenant-readiness-%s-%s.csv',
            Str::slug($company->name ?: $company->id),
            now()->format('Ymd_His')
        );

        return response()->streamDownload(function () use ($payload) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            $summary = $payload['summary'];

            fputcsv($file, ['Tenant Readiness Summary']);
            fputcsv($file, ['Generated At', $payload['generated_at']]);
            fputcsv($file, ['Tenant ID', $summary['tenant_id']]);
            fputcsv($file, ['Tenant Name', $summary['tenant_name']]);
            fputcsv($file, ['Tenant Status', $summary['tenant_status']]);
            fputcsv($file, ['Subscription Plan', $summary['subscription_plan']]);
            fputcsv($file, ['Readiness State', $summary['readiness_state']]);
            fputcsv($file, []);

            fputcsv($file, ['Checks']);
            fputcsv($file, ['Key', 'Value']);
            foreach ($summary['checks'] as $key => $value) {
                fputcsv($file, [$key, $this->csvValue($value)]);
            }
            fputcsv($file, []);

            fputcsv($file, ['Blockers']);
            fputcsv($file, ['Blocker']);
            foreach ($summary['blockers'] as $blocker) {
                fputcsv($file, [$blocker]);
            }
            fputcsv($file, []);

            fputcsv($file, ['Pending Actions']);
            fputcsv($file, ['Action']);
            foreach ($summary['pending_actions'] as $action) {
                fputcsv($file, [$action]);
            }
            fputcsv($file, []);

            fputcsv($file, ['Branches']);
            fputcsv($file, ['Branch ID', 'Name', 'Status', 'Has Admin', 'Compliance Complete', 'Pilot Outcome', 'Profile Code']);
            foreach ($summary['branches'] as $branch) {
                fputcsv($file, [
                    $branch['id'],
                    $branch['name'],
                    $branch['status'],
                    $this->csvValue($branch['has_admin']),
                    $this->csvValue($branch['compliance_complete']),
                    $branch['pilot_outcome'],
                    $branch['profile']['profile_code'] ?? '',
                ]);
            }
            fputcsv($file, []);

            fputcsv($file, ['Sign-Off History']);
            fputcsv($file, ['Decision ID', 'Decision', 'Calculated State', 'Signed Off At', 'Signed Off By', 'Notes']);
            foreach ($payload['sign_off_history'] as $signOff) {
                fputcsv($file, [
                    $signOff['id'],
                    $signOff['signed_off_state'],
                    $signOff['readiness_state_calculated'],
                    $signOff['signed_off_at'],
                    $signOff['signed_off_by']['email'] ?? '',
                    $signOff['notes'],
                ]);
            }
            fputcsv($file, []);
            fputcsv($file, ['Notice', $payload['non_certification_notice']]);

            fclose($file);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function htmlExport(array $payload)
    {
        $summary = $payload['summary'];
        $branchRows = collect($summary['branches'])->map(function (array $branch) {
            return sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                e($branch['name']),
                e($branch['status']),
                e($branch['has_admin'] ? 'yes' : 'no'),
                e($branch['compliance_complete'] ? 'yes' : 'no'),
                e($branch['pilot_outcome'])
            );
        })->implode('');

        $signOffRows = collect($payload['sign_off_history'])->map(function (array $signOff) {
            return sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                e($signOff['signed_off_state']),
                e($signOff['readiness_state_calculated']),
                e($signOff['signed_off_at']),
                e($signOff['signed_off_by']['email'] ?? 'unknown')
            );
        })->implode('') ?: '<tr><td colspan="4">No sign-off history recorded.</td></tr>';

        $blockers = collect($summary['blockers'])->map(fn (string $blocker) => '<li>'.e($blocker).'</li>')->implode('')
            ?: '<li>none</li>';
        $pendingActions = collect($summary['pending_actions'])->map(fn (string $action) => '<li>'.e($action).'</li>')->implode('')
            ?: '<li>none</li>';

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Tenant Readiness Summary</title>'
            .'<style>body{font-family:Arial,sans-serif;margin:32px;color:#111827;}table{border-collapse:collapse;width:100%;margin:16px 0;}th,td{border:1px solid #d1d5db;padding:8px;text-align:left;}h1,h2{margin-bottom:8px}.notice{margin-top:24px;color:#6b7280;font-size:12px}</style>'
            .'</head><body>'
            .'<h1>Tenant Readiness Summary</h1>'
            .'<p><strong>Tenant:</strong> '.e($summary['tenant_name']).'</p>'
            .'<p><strong>Status:</strong> '.e($summary['tenant_status']).'</p>'
            .'<p><strong>Readiness:</strong> '.e($summary['readiness_state']).'</p>'
            .'<p><strong>Generated:</strong> '.e($payload['generated_at']).'</p>'
            .'<h2>Blockers</h2><ul>'.$blockers.'</ul>'
            .'<h2>Pending Actions</h2><ul>'.$pendingActions.'</ul>'
            .'<h2>Branches</h2><table><thead><tr><th>Name</th><th>Status</th><th>Admin</th><th>Compliance</th><th>Pilot</th></tr></thead><tbody>'.$branchRows.'</tbody></table>'
            .'<h2>Sign-Off History</h2><table><thead><tr><th>Decision</th><th>Calculated State</th><th>Signed Off At</th><th>Signer</th></tr></thead><tbody>'.$signOffRows.'</tbody></table>'
            .'<p class="notice">'.e($payload['non_certification_notice']).'</p>'
            .'</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function csvValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
