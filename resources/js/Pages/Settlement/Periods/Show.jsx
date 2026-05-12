import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : 'N/A';
}

function money(value) {
    return value ?? '0.0000';
}

export default function Show({ period, summary, variance, snapshots, lockReadiness, actions, flash }) {
    const { errors } = usePage().props;
    const paymentTotals = summary?.payments?.by_method || [];
    const syncCounts = summary?.accounting_sync || {};
    const varianceSummary = variance?.summary || {};
    const varianceItems = variance?.items || [];
    const approveVisible = Boolean(actions?.can_approve);
    const lockVisible = Boolean(actions?.can_lock);
    const reopenVisible = Boolean(actions?.can_reopen);
    const lockDisabled = Boolean(actions?.lock_requires_snapshot);
    const canExportReports = Boolean(usePage().props.permissions?.can_export_reports);
    const canExportAccounting = Boolean(usePage().props.permissions?.can_export_accounting);

    function approvePeriod() {
        router.post(route('settlement.periods.approve', period.id));
    }

    function lockPeriod() {
        router.post(route('settlement.periods.lock', period.id));
    }

    function reopenPeriod() {
        const reason = window.prompt('Enter a reason to reopen this settlement period.');

        if (!reason || !reason.trim()) {
            return;
        }

        router.post(route('settlement.periods.reopen', period.id), {
            reason: reason.trim(),
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Settlement Review</h2>}>
            <Head title="Settlement Review" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(flash?.status || flash?.error) && (
                        <div className={`rounded-lg border px-4 py-3 text-sm ${flash.status ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                            {flash.status || flash.error}
                        </div>
                    )}

                    {(errors?.snapshot || errors?.status) && (
                        <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            {errors.snapshot || errors.status}
                        </div>
                    )}

                    <div className="flex items-center justify-between">
                        <Link className="text-sm font-medium text-slate-700 underline" href={route('settlement.periods.index')}>
                            Back to periods
                        </Link>
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-3">
                                {approveVisible && (
                                    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800" type="button" onClick={approvePeriod}>
                                        Approve period
                                    </button>
                                )}
                                {lockVisible && (
                                    <button
                                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                        disabled={lockDisabled}
                                        type="button"
                                        onClick={lockPeriod}
                                    >
                                        {lockDisabled ? 'Snapshot required before locking' : 'Lock period'}
                                    </button>
                                )}
                                {reopenVisible && (
                                    <button className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100" type="button" onClick={reopenPeriod}>
                                        Reopen period
                                    </button>
                                )}
                            </div>

                            {(canExportReports || canExportAccounting) && (
                                <div className="ml-2 flex items-center gap-2 border-l border-slate-200 pl-3">
                                    {canExportReports && (
                                        <a
                                            href={route('settlement.periods.export.summary.pdf', period.id)}
                                            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                                        >
                                            Export PDF
                                        </a>
                                    )}
                                    <div className="group relative">
                                        <button className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                            More Exports
                                        </button>
                                        <div className="pointer-events-none absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-lg border border-slate-200 bg-white py-1 shadow-lg opacity-0 transition-opacity group-hover:pointer-events-auto group-hover:opacity-100">
                                            {canExportReports && (
                                                <a href={route('settlement.periods.export.summary.csv', period.id)} className="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                                    Summary CSV
                                                </a>
                                            )}
                                            {canExportAccounting && (
                                                <>
                                                    <a href={route('settlement.periods.export.variance.csv', period.id)} className="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                                        Variance Ledger CSV
                                                    </a>
                                                    <a href={route('settlement.periods.export.sync-status.csv', period.id)} className="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                                        Accounting Sync Log CSV
                                                    </a>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-4">
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="text-xs uppercase tracking-wide text-slate-500">Status</div>
                            <div className="mt-2 text-lg font-semibold text-slate-900">{period.status}</div>
                        </div>
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="text-xs uppercase tracking-wide text-slate-500">Approval</div>
                            <div className="mt-2 text-sm text-slate-900">{period.approved_at ? `Approved ${formatDate(period.approved_at)}` : 'Not approved yet'}</div>
                        </div>
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="text-xs uppercase tracking-wide text-slate-500">Lock</div>
                            <div className="mt-2 text-sm text-slate-900">{period.locked_at ? `Locked ${formatDate(period.locked_at)}` : 'Not locked yet'}</div>
                        </div>
                        <div className={`rounded-xl border p-5 shadow-sm ${lockReadiness?.can_lock ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'}`}>
                            <div className="text-xs uppercase tracking-wide text-slate-500">Lock Readiness</div>
                            <div className="mt-2 text-sm font-semibold text-slate-900">{lockReadiness?.can_lock ? 'Ready to lock' : 'Not ready to lock'}</div>
                            <p className="mt-2 text-sm text-slate-700">{lockReadiness?.reason || 'No lock readiness data.'}</p>
                        </div>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[1.2fr,0.8fr]">
                        <div className="space-y-6">
                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Period</h3>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <div><dt className="text-xs uppercase text-slate-500">Scope</dt><dd className="mt-1 text-sm text-slate-900">{period.scope_label}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Range</dt><dd className="mt-1 text-sm text-slate-900">{formatDate(period.period_start_at)} - {formatDate(period.period_end_at)}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Opened At</dt><dd className="mt-1 text-sm text-slate-900">{formatDate(period.opened_at)}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Latest Snapshot</dt><dd className="mt-1 text-sm text-slate-900">{formatDate(period.latest_snapshot_created_at)}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Approved By</dt><dd className="mt-1 text-sm text-slate-900">{period.approved_by || 'N/A'}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Locked By</dt><dd className="mt-1 text-sm text-slate-900">{period.locked_by || 'N/A'}</dd></div>
                                </dl>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Summary Totals</h3>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <div className="rounded-lg border border-slate-200 p-4">
                                        <div className="text-xs uppercase text-slate-500">Gross Sales</div>
                                        <div className="mt-2 text-lg font-semibold text-slate-900">{money(summary?.sales?.gross_sales_total)}</div>
                                    </div>
                                    <div className="rounded-lg border border-slate-200 p-4">
                                        <div className="text-xs uppercase text-slate-500">Net Sales</div>
                                        <div className="mt-2 text-lg font-semibold text-slate-900">{money(summary?.sales?.net_sales_total)}</div>
                                    </div>
                                    <div className="rounded-lg border border-slate-200 p-4">
                                        <div className="text-xs uppercase text-slate-500">Refunds</div>
                                        <div className="mt-2 text-lg font-semibold text-slate-900">{money(summary?.sales?.refund_total)}</div>
                                    </div>
                                    <div className="rounded-lg border border-slate-200 p-4">
                                        <div className="text-xs uppercase text-slate-500">Voids</div>
                                        <div className="mt-2 text-lg font-semibold text-slate-900">{money(summary?.sales?.void_total)}</div>
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Payment Totals</h3>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                                        <thead className="bg-slate-50 text-left text-slate-600">
                                            <tr>
                                                <th className="px-4 py-3 font-medium">Method</th>
                                                <th className="px-4 py-3 font-medium">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {paymentTotals.map((payment) => (
                                                <tr key={payment.payment_method_id}>
                                                    <td className="px-4 py-3 text-slate-700">{payment.name || payment.code || payment.payment_method_id}</td>
                                                    <td className="px-4 py-3 text-slate-700">{money(payment.total)}</td>
                                                </tr>
                                            ))}
                                            {paymentTotals.length === 0 && (
                                                <tr><td className="px-4 py-3 text-slate-500" colSpan="2">No payment totals available.</td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Accounting Sync Counts</h3>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    {Object.entries(syncCounts).map(([status, count]) => (
                                        <div key={status} className="rounded-lg border border-slate-200 p-4">
                                            <div className="text-xs uppercase text-slate-500">{status}</div>
                                            <div className="mt-2 text-lg font-semibold text-slate-900">{count}</div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Variance Summary</h3>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <div className="rounded-lg border border-slate-200 p-4">
                                        <div className="text-xs uppercase text-slate-500">Total Variances</div>
                                        <div className="mt-2 text-lg font-semibold text-slate-900">{varianceSummary.total_variance_count ?? 0}</div>
                                    </div>
                                    {Object.entries(varianceSummary.by_category || {}).map(([category, count]) => (
                                        <div key={category} className="rounded-lg border border-slate-200 p-4">
                                            <div className="text-xs uppercase text-slate-500">{category}</div>
                                            <div className="mt-2 text-lg font-semibold text-slate-900">{count}</div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Variance Details</h3>
                                <div className="space-y-3">
                                    {varianceItems.map((item) => (
                                        <div key={`${item.category}-${item.source_type}-${item.source_id}`} className="rounded-lg border border-slate-200 p-4">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="text-sm font-medium text-slate-900">{item.title}</div>
                                                    <div className="mt-1 text-xs uppercase tracking-wide text-slate-500">{item.category}</div>
                                                </div>
                                                <div className="text-sm font-semibold text-slate-900">{item.amount}</div>
                                            </div>
                                            <p className="mt-2 text-sm text-slate-700">{item.message}</p>
                                        </div>
                                    ))}
                                    {varianceItems.length === 0 && (
                                        <p className="text-sm text-slate-500">No variances found.</p>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="space-y-6">
                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Snapshots</h3>
                                <div className="space-y-3">
                                    {snapshots.map((snapshot) => (
                                        <div key={snapshot.id} className="rounded-lg border border-slate-200 p-4">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="text-sm font-medium text-slate-900">{snapshot.snapshot_type}</div>
                                                    <div className="mt-1 text-xs text-slate-500">{formatDate(snapshot.created_at)}</div>
                                                </div>
                                                <div className="text-xs text-slate-500">{snapshot.created_by_name || snapshot.created_by || 'N/A'}</div>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-700">
                                                Variances: {snapshot.summary_total_variance_count ?? 0}
                                            </div>
                                        </div>
                                    ))}
                                    {snapshots.length === 0 && (
                                        <p className="text-sm text-slate-500">No snapshots recorded.</p>
                                    )}
                                </div>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Lock Readiness Details</h3>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <div><dt className="text-xs uppercase text-slate-500">Has Snapshot</dt><dd className="mt-1 text-sm text-slate-900">{lockReadiness?.has_snapshot ? 'Yes' : 'No'}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Status Approved</dt><dd className="mt-1 text-sm text-slate-900">{lockReadiness?.status_is_approved ? 'Yes' : 'No'}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Manage Permission</dt><dd className="mt-1 text-sm text-slate-900">{lockReadiness?.has_manage_permission ? 'Yes' : 'No'}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Scope Access</dt><dd className="mt-1 text-sm text-slate-900">{lockReadiness?.has_scope_access ? 'Yes' : 'No'}</dd></div>
                                </dl>
                                {lockReadiness?.reason && (
                                    <p className="mt-3 text-sm text-slate-700">{lockReadiness.reason}</p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
