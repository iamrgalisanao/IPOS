<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\DataExport;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DataExportController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? (string) $request->user()->tenant_id;
        
        // MVP: Users only see their own exports. 
        // If they have `manage_report_exports`, they could see all.
        $query = DataExport::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc');

        if (!$request->user()->hasPermission('manage_report_exports')) {
            $query->where('user_id', $request->user()->id);
        }

        $exports = $query->paginate(20)->through(function ($export) {
            return [
                'id' => $export->id,
                'type' => $export->type,
                'status' => $export->status,
                'file_size' => $export->file_size,
                'requested_at' => $export->requested_at?->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
                'can_download' => $export->canBeDownloaded(),
                'error_message' => $export->error_message,
            ];
        });

        return Inertia::render('Reports/Exports/Index', [
            'exports' => $exports,
        ]);
    }

    public function download(Request $request, DataExport $export)
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? (string) $request->user()->tenant_id;

        if ($export->tenant_id !== $tenantId) {
            abort(404);
        }

        if (!$request->user()->hasPermission('manage_report_exports') && $export->user_id !== $request->user()->id) {
            abort(404);
        }

        if (!$export->canBeDownloaded()) {
            abort(404);
        }

        if (!$export->file_disk || !$export->file_path || !Storage::disk($export->file_disk)->exists($export->file_path)) {
            abort(404);
        }

        $export->increment('download_count');
        $export->update(['downloaded_at' => now()]);

        return Storage::disk($export->file_disk)->download(
            $export->file_path, 
            basename($export->file_path),
            ['Content-Type' => $export->mime_type ?? 'application/octet-stream']
        );
    }
}
