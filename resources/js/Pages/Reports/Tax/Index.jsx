import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function formatValue(value) {
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value ?? '0.0000');
}

function valueTone(key, value) {
    if ((key === 'has_reviewed_period' || key === 'has_locked_period') && value === true) {
        return 'text-amber-700';
    }

    return 'text-slate-900';
}

export default function Index({ filters, branches, canViewAllBranches, summary, sections }) {
    const [form, setForm] = useState({
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        branch_id: filters.branch_id || '',
    });

    function submit(event) {
        event.preventDefault();
        router.get(route('reports.tax.index'), form, { preserveState: true, replace: true });
    }

    function reset() {
        const next = {
            date_from: filters.date_from || '',
            date_to: filters.date_to || '',
            branch_id: canViewAllBranches ? '' : (branches[0]?.id || ''),
        };

        setForm(next);
        router.get(route('reports.tax.index'), next, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">BIR Tax Reporting</h2>}
        >
            <Head title="BIR Tax Reporting" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded-xl bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-start justify-between gap-4">
                            <div>
                                <h1 className="text-lg font-semibold text-slate-900">Tax Summary</h1>
                                <p className="mt-1 text-sm text-slate-600">Read-only BIR summary totals sourced directly from the reporting query service.</p>
                            </div>
                        </div>

                        <form className="grid gap-4 md:grid-cols-2 xl:grid-cols-4" onSubmit={submit}>
                            <label className="space-y-1 text-sm text-slate-700">
                                <span>Date from</span>
                                <input
                                    className="w-full rounded-lg border-gray-300 text-sm"
                                    type="date"
                                    value={form.date_from}
                                    onChange={(event) => setForm({ ...form, date_from: event.target.value })}
                                />
                            </label>
                            <label className="space-y-1 text-sm text-slate-700">
                                <span>Date to</span>
                                <input
                                    className="w-full rounded-lg border-gray-300 text-sm"
                                    type="date"
                                    value={form.date_to}
                                    onChange={(event) => setForm({ ...form, date_to: event.target.value })}
                                />
                            </label>
                            <label className="space-y-1 text-sm text-slate-700">
                                <span>Branch</span>
                                <select
                                    className="w-full rounded-lg border-gray-300 text-sm"
                                    value={form.branch_id}
                                    onChange={(event) => setForm({ ...form, branch_id: event.target.value })}
                                >
                                    {canViewAllBranches && <option value="">All branches</option>}
                                    {branches.map((branch) => (
                                        <option key={branch.id} value={branch.id}>{branch.name}</option>
                                    ))}
                                </select>
                            </label>
                            <div className="flex items-end gap-2">
                                <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">
                                    Apply filters
                                </button>
                                <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700" type="button" onClick={reset}>
                                    Reset
                                </button>
                                <a
                                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                    href={route('reports.tax.export.csv', form)}
                                >
                                    Export CSV
                                </a>
                            </div>
                        </form>
                    </div>

                    <div className="space-y-6">
                        {sections.map((section) => (
                            <section key={section.id} className="rounded-xl bg-white p-5 shadow-sm">
                                <div className="mb-4 border-b border-slate-100 pb-4">
                                    <h3 className="text-base font-semibold text-slate-900">{section.title}</h3>
                                    <p className="mt-1 text-sm text-slate-600">{section.description}</p>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    {section.items.map((item) => (
                                        <article key={item.key} className="rounded-lg border border-slate-100 bg-slate-50/70 p-4">
                                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{item.label}</p>
                                            <p className={`mt-2 text-2xl font-semibold ${valueTone(item.key, summary[item.key])}`}>
                                                {formatValue(summary[item.key])}
                                            </p>
                                        </article>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}