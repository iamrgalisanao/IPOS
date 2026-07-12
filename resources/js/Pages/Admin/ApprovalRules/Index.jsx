import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Index({ rules = [], branches = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        branch_id: '',
        always_require_approval: false,
    });
    const submit = (event) => {
        event.preventDefault();
        put(route('admin.approval-rules.update'));
    };
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Approval Rules</h2>}>
            <Head title="Approval Rules" />
            <div className="mx-auto max-w-4xl p-6">
                <div className="rounded-2xl border bg-white p-6 shadow-sm">
                    <h3 className="font-semibold text-slate-900">Statutory discount approvals</h3>
                    <p className="mt-2 text-sm text-slate-600">A rule can require manager approval for every statutory discount. It can never waive a discount type’s required approval or identity checks.</p>
                    <form onSubmit={submit} className="mt-6 space-y-4">
                        <label className="block text-sm font-medium">Scope
                            <select className="mt-1 block w-full rounded-lg border-slate-300" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                                <option value="">Tenant default</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </select>
                        </label>
                        <label className="flex items-center gap-3 text-sm">
                            <input type="checkbox" checked={data.always_require_approval} onChange={(e) => setData('always_require_approval', e.target.checked)} />
                            Require an independent manager for every statutory discount
                        </label>
                        {errors.branch_id && <p className="text-sm text-red-600">{errors.branch_id}</p>}
                        <button disabled={processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save rule</button>
                    </form>
                    <div className="mt-8 space-y-2 text-sm">
                        {rules.map((rule) => <div key={rule.id} className="rounded-lg bg-slate-50 p-3">{rule.scope_key === 'tenant' ? 'Tenant default' : branches.find((b) => b.id === rule.branch_id)?.name}: {rule.always_require_approval ? 'Always require approval' : 'Type minimum only'}</div>)}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
