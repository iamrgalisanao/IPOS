import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { ArrowLeft, Banknote, CreditCard, Save, ShieldCheck, WifiOff } from 'lucide-react';

const toPeso = (centavos) => {
    if (centavos === null || centavos === undefined || centavos === '') return '';
    return (Number(centavos) / 100).toFixed(2);
};

const toCentavos = (value) => {
    if (value === '' || value === null || value === undefined) return null;
    return Math.max(0, Math.round(Number(value) * 100));
};

export default function PaymentSettings({ auth, branch, paymentMethods = [] }) {
    const { data, setData, post, processing, errors, recentlySuccessful } = useForm({
        settings: paymentMethods.map((method, index) => ({
            payment_method_id: method.id,
            code: method.code,
            name: method.name,
            type: method.type,
            gateway_supports_offline_capture: Boolean(method.gateway_supports_offline_capture),
            enabled: Boolean(method.enabled),
            allow_offline: Boolean(method.allow_offline),
            offline_max_limit_centavos: method.offline_max_limit_centavos,
            offline_limit_pesos: toPeso(method.offline_max_limit_centavos),
            requires_reference: Boolean(method.requires_reference),
            sort_order: Number(method.sort_order ?? index),
            offline_policy_note: method.offline_policy_note || '',
        })),
    });

    const updateMethod = (index, field, value) => {
        const settings = [...data.settings];
        const next = { ...settings[index], [field]: value };

        if (field === 'offline_limit_pesos') {
            next.offline_max_limit_centavos = toCentavos(value);
        }

        if (field === 'allow_offline' && value === true && !next.gateway_supports_offline_capture) {
            return;
        }

        settings[index] = next;
        setData('settings', settings);
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('admin.branches.payment-settings.update', branch.id), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-2xl font-extrabold tracking-tight text-slate-800">Payment Settings</h2>
                        <p className="mt-1 text-sm font-medium text-slate-500">
                            {branch.name} ({branch.branch_code})
                        </p>
                    </div>
                    <Link href={route('admin.branches.index')}>
                        <SecondaryButton type="button" className="inline-flex items-center gap-2">
                            <ArrowLeft size={16} />
                            Back to Branches
                        </SecondaryButton>
                    </Link>
                </div>
            }
        >
            <Head title={`Payment Settings - ${branch.name}`} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                            <div className="border-b border-slate-100 px-6 py-5">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                                        <CreditCard size={20} />
                                    </div>
                                    <div>
                                        <h3 className="font-black text-slate-800">Branch Payment Rules</h3>
                                        <p className="text-sm font-medium text-slate-500">Control which tenders are available and eligible for offline capture.</p>
                                    </div>
                                </div>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left">
                                    <thead className="bg-slate-50/80">
                                        <tr>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Method</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Enabled</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Offline</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Limit</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Reference</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Order</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Note</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {data.settings.map((method, index) => (
                                            <tr key={method.payment_method_id} className="align-top">
                                                <td className="px-6 py-5">
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                                                            {method.code?.toLowerCase() === 'cash' ? <Banknote size={18} /> : <CreditCard size={18} />}
                                                        </div>
                                                        <div>
                                                            <p className="font-black text-slate-800">{method.name}</p>
                                                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">{method.code} · {method.type}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-5">
                                                    <input
                                                        type="checkbox"
                                                        checked={method.enabled}
                                                        onChange={(event) => updateMethod(index, 'enabled', event.target.checked)}
                                                        className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                    />
                                                </td>
                                                <td className="px-6 py-5">
                                                    <label className={`inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-black ${
                                                        method.allow_offline ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                                                    }`}>
                                                        {method.allow_offline ? <ShieldCheck size={14} /> : <WifiOff size={14} />}
                                                        <input
                                                            type="checkbox"
                                                            checked={method.allow_offline}
                                                            disabled={!method.gateway_supports_offline_capture}
                                                            onChange={(event) => updateMethod(index, 'allow_offline', event.target.checked)}
                                                            className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500 disabled:opacity-40"
                                                        />
                                                        {method.allow_offline ? 'Allowed' : 'Disabled'}
                                                    </label>
                                                </td>
                                                <td className="px-6 py-5">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-bold text-slate-400">PHP</span>
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            value={method.offline_limit_pesos}
                                                            onChange={(event) => updateMethod(index, 'offline_limit_pesos', event.target.value)}
                                                            className="w-28 rounded-xl border-slate-200 text-sm font-bold shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                            placeholder="No limit"
                                                        />
                                                    </div>
                                                </td>
                                                <td className="px-6 py-5">
                                                    <input
                                                        type="checkbox"
                                                        checked={method.requires_reference}
                                                        onChange={(event) => updateMethod(index, 'requires_reference', event.target.checked)}
                                                        className="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                    />
                                                </td>
                                                <td className="px-6 py-5">
                                                    <input
                                                        type="number"
                                                        value={method.sort_order}
                                                        onChange={(event) => updateMethod(index, 'sort_order', Number(event.target.value))}
                                                        className="w-20 rounded-xl border-slate-200 text-sm font-bold shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    />
                                                </td>
                                                <td className="px-6 py-5">
                                                    <input
                                                        type="text"
                                                        value={method.offline_policy_note}
                                                        onChange={(event) => updateMethod(index, 'offline_policy_note', event.target.value)}
                                                        className="w-72 rounded-xl border-slate-200 text-sm font-medium shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                        placeholder="Optional cashier/admin note"
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <InputError message={errors.settings} />

                        <div className="flex items-center justify-end gap-3">
                            {recentlySuccessful && (
                                <span className="text-sm font-bold text-emerald-600">Saved</span>
                            )}
                            <PrimaryButton disabled={processing} className="inline-flex items-center gap-2">
                                <Save size={16} />
                                Save Payment Rules
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
