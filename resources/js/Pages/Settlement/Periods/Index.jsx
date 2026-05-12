import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : 'N/A';
}

export default function Index({ periods, flash }) {
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

                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-left text-slate-600">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Period</th>
                                        <th className="px-4 py-3 font-medium">Scope</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        <th className="px-4 py-3 font-medium">Opened</th>
                                        <th className="px-4 py-3 font-medium">Approved</th>
                                        <th className="px-4 py-3 font-medium">Locked</th>
                                        <th className="px-4 py-3 font-medium">Latest Snapshot</th>
                                        <th className="px-4 py-3 font-medium">Variance Count</th>
                                        <th className="px-4 py-3 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {periods.data.map((period) => (
                                        <tr key={period.id}>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-slate-900">
                                                    {formatDate(period.period_start_at)} - {formatDate(period.period_end_at)}
                                                </div>
                                                <div className="text-xs text-slate-500">{period.id}</div>
                                            </td>
                                            <td className="px-4 py-3 text-slate-700">{period.scope_label}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${period.status === 'locked' ? 'bg-slate-900 text-white' : period.status === 'approved' ? 'bg-emerald-100 text-emerald-800' : period.status === 'in_review' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'}`}>
                                                    {period.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">{formatDate(period.opened_at)}</td>
                                            <td className="px-4 py-3 text-slate-600">{formatDate(period.approved_at)}</td>
                                            <td className="px-4 py-3 text-slate-600">{formatDate(period.locked_at)}</td>
                                            <td className="px-4 py-3 text-slate-600">{formatDate(period.latest_snapshot_created_at)}</td>
                                            <td className="px-4 py-3 text-slate-600">{period.latest_variance_count ?? 'N/A'}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Link className="text-sm font-medium text-slate-900 underline" href={route('settlement.periods.show', period.id)}>
                                                    Review
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="text-sm text-slate-500">
                        Showing {periods.from || 0}-{periods.to || 0} of {periods.total || 0}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
