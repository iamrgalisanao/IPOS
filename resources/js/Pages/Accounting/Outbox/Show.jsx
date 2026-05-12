import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Show({ record, canRetry, flash }) {
    function retry() {
        router.post(route('accounting.outbox.retry', record.id));
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Accounting Outbox Detail</h2>}
        >
            <Head title="Accounting Outbox Detail" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(flash?.status || flash?.error) && (
                        <div className={`rounded-lg border px-4 py-3 text-sm ${flash.status ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                            {flash.status || flash.error}
                        </div>
                    )}

                    <div className="flex items-center justify-between">
                        <Link className="text-sm font-medium text-slate-700 underline" href={route('accounting.outbox.index')}>Back to dashboard</Link>
                        {canRetry && (
                            <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="button" onClick={retry}>
                                Retry failed sync
                            </button>
                        )}
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[1.2fr,0.8fr]">
                        <div className="space-y-6">
                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Record</h3>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <div><dt className="text-xs uppercase text-slate-500">Event</dt><dd className="mt-1 text-sm text-slate-900">{record.event_type}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Status</dt><dd className="mt-1 text-sm text-slate-900">{record.sync_status}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Source</dt><dd className="mt-1 text-sm text-slate-900">{record.source_type}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Attempts</dt><dd className="mt-1 text-sm text-slate-900">{record.attempt_count}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">External Reference</dt><dd className="mt-1 text-sm text-slate-900">{record.external_reference || 'N/A'}</dd></div>
                                    <div><dt className="text-xs uppercase text-slate-500">Created</dt><dd className="mt-1 text-sm text-slate-900">{record.created_at ? new Date(record.created_at).toLocaleString() : 'N/A'}</dd></div>
                                </dl>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Safe Payload</h3>
                                <pre className="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{JSON.stringify(record.payload, null, 2)}</pre>
                            </div>
                        </div>

                        <div className="space-y-6">
                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Safe Error</h3>
                                <p className="text-sm text-slate-700">{record.sync_error || 'No error recorded.'}</p>
                            </div>

                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Attempts</h3>
                                <div className="space-y-3">
                                    {(record.attempts || []).map((attempt) => (
                                        <div key={attempt.id} className="rounded-lg border border-slate-200 p-3">
                                            <div className="flex items-center justify-between text-sm text-slate-900">
                                                <span>Attempt #{attempt.attempt_number}</span>
                                                <span>{attempt.status}</span>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">{attempt.error_category || 'No category'}</div>
                                            <div className="mt-2 text-xs text-slate-700">{attempt.error_message || 'No error message'}</div>
                                        </div>
                                    ))}
                                    {(!record.attempts || record.attempts.length === 0) && (
                                        <p className="text-sm text-slate-500">No attempts recorded.</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}