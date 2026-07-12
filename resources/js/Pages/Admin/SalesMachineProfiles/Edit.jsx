import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import {
    Monitor,
    Save,
    ArrowLeft,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Info,
    ShieldAlert,
    HelpCircle
} from 'lucide-react';

export default function Edit({ auth, profile, offlineStatus, printerProfiles = [] }) {
    const latestHb = profile.latest_heartbeat;
    const hasUnsyncedSales = latestHb && latestHb.queue_count > 0;
    const [showOverride, setShowOverride] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        offline_sales_enabled: profile.offline_sales_enabled ?? false,
        offline_sequence_prefix: profile.offline_sequence_prefix ?? '',
        offline_sequence_next_value: profile.offline_sequence_next_value ?? 1,
        offline_sequence_status: profile.offline_sequence_status ?? 'active',
        printer_profile_id: profile.printer_profile_id ?? '',
        admin_override: false,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.sales-machine-profiles.update', profile.id));
    };

    const getStatusIndicator = (status) => {
        if (status.allowed) {
            return (
                <div className="p-5 rounded-2xl bg-emerald-50 border border-emerald-200/50 text-emerald-800 flex gap-3">
                    <CheckCircle2 className="text-emerald-500 shrink-0 mt-0.5" size={20} />
                    <div>
                        <h4 className="text-sm font-black uppercase tracking-wider text-emerald-950">Terminal is Offline-Ready</h4>
                        <p className="text-xs font-semibold text-emerald-800/90 mt-1">{status.message}</p>
                    </div>
                </div>
            );
        }

        return (
            <div className="p-5 rounded-2xl bg-rose-50 border border-rose-200/50 text-rose-800 flex gap-3">
                <AlertTriangle className="text-rose-500 shrink-0 mt-0.5" size={20} />
                <div>
                    <h4 className="text-sm font-black uppercase tracking-wider text-rose-950">Offline Configuration Gaps</h4>
                    <p className="text-xs font-semibold text-rose-800/90 mt-1">{status.message}</p>
                </div>
            </div>
        );
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-4">
                    <Link
                        href={route('admin.sales-machine-profiles.index')}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-slate-50 border border-slate-100 text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-colors"
                    >
                        <ArrowLeft size={18} />
                    </Link>
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Configure Terminal</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Configure sequence prefixes, status controls, and review offline parameters for terminal {profile.profile_code}.</p>
                    </div>
                </div>
            }
        >
            <Head title={`Configure Terminal ${profile.profile_code}`} />

            <div className="py-8">
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

                    {/* Offline Readiness Status */}
                    <div className="mb-8">
                        {getStatusIndicator(offlineStatus)}
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">

                        {/* Main Editor Form */}
                        <div className="lg:col-span-2 bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8">
                            <form onSubmit={submit} className="space-y-6">
                                <h3 className="text-sm font-black uppercase tracking-widest text-slate-400 mb-6">Offline Settings</h3>

                                {/* Offline Sales Enabled Toggle */}
                                <div className="flex items-start gap-3 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                    <input
                                        type="checkbox"
                                        id="offline_sales_enabled"
                                        checked={data.offline_sales_enabled}
                                        onChange={(e) => setData('offline_sales_enabled', e.target.checked)}
                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 mt-1 cursor-pointer"
                                    />
                                    <label htmlFor="offline_sales_enabled" className="cursor-pointer select-none">
                                        <span className="block text-sm font-extrabold text-slate-700">Enable Controlled Offline Sales</span>
                                        <span className="block text-xs text-slate-500 font-medium mt-0.5">Allows this specific terminal to capture sales transactions in a local buffer when server is unreachable.</span>
                                    </label>
                                </div>

                                {/* Sequence Prefix */}
                                <div>
                                    <InputLabel htmlFor="offline_sequence_prefix" value="Offline Sequence Prefix" />
                                    <TextInput
                                        id="offline_sequence_prefix"
                                        type="text"
                                        value={data.offline_sequence_prefix}
                                        onChange={(e) => setData('offline_sequence_prefix', e.target.value.toUpperCase())}
                                        className="mt-1 block w-full uppercase"
                                        placeholder="e.g. REG-01-"
                                        disabled={hasUnsyncedSales && !data.admin_override}
                                    />
                                    <p className="text-xs text-slate-400 mt-1.5 font-medium">Must be alphanumeric and hyphens only (e.g. REG-A-). Unique per terminal profile.</p>
                                    <InputError message={errors.offline_sequence_prefix} className="mt-1" />
                                </div>

                                {/* Next Sequence Value */}
                                <div>
                                    <InputLabel htmlFor="offline_sequence_next_value" value="Next Offline Sequence Value" />
                                    <TextInput
                                        id="offline_sequence_next_value"
                                        type="number"
                                        min="1"
                                        value={data.offline_sequence_next_value}
                                        onChange={(e) => setData('offline_sequence_next_value', parseInt(e.target.value) || 1)}
                                        className="mt-1 block w-full"
                                        disabled={hasUnsyncedSales && !data.admin_override}
                                    />
                                    <p className="text-xs text-slate-400 mt-1.5 font-medium">The sequence index for the next offline sale. This value cannot be decreased.</p>
                                    <InputError message={errors.offline_sequence_next_value} className="mt-1" />
                                </div>

                                {/* Sequence Status */}
                                <div>
                                    <InputLabel htmlFor="offline_sequence_status" value="Sequence Status" />
                                    <select
                                        id="offline_sequence_status"
                                        value={data.offline_sequence_status}
                                        onChange={(e) => setData('offline_sequence_status', e.target.value)}
                                        className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer"
                                    >
                                        <option value="active">Active</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="depleted">Depleted</option>
                                    </select>
                                    <InputError message={errors.offline_sequence_status} className="mt-1" />
                                </div>

                                {/* Assigned Printer Profile */}
                                <div>
                                    <InputLabel htmlFor="printer_profile_id" value="Assigned Receipt Printer Override" />
                                    <select
                                        id="printer_profile_id"
                                        value={data.printer_profile_id}
                                        onChange={(e) => setData('printer_profile_id', e.target.value)}
                                        className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer"
                                    >
                                        <option value="">Use Branch Default Printer</option>
                                        {printerProfiles.map((printer) => (
                                            <option key={printer.id} value={printer.id}>
                                                {printer.name} — {printer.connection_type.toUpperCase()} — {printer.identifier || 'Local'} — {printer.paper_width}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-slate-400 mt-1.5 font-medium">Selects a configured receipt-printer endpoint for this register. Physical device readiness is validated separately.</p>
                                    <InputError message={errors.printer_profile_id} className="mt-1" />
                                </div>

                                {/* Unsynced warning & override checkbox */}
                                {hasUnsyncedSales && (
                                    <div className="p-4 rounded-2xl bg-amber-50 border border-amber-200/60 text-amber-800 space-y-3">
                                        <div className="flex gap-2.5">
                                            <ShieldAlert className="text-amber-500 shrink-0 mt-0.5" size={18} />
                                            <div className="text-xs leading-normal">
                                                <p className="font-extrabold text-amber-950">Unsynced Terminal Queue Detected</p>
                                                <p className="mt-1 font-semibold">
                                                    This terminal currently has <strong>{latestHb.queue_count} unsynced local offline sales</strong>. Editing sequence configuration is blocked to prevent sequence conflicts or duplicate references.
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 pt-1.5 border-t border-amber-200/40">
                                            <input
                                                type="checkbox"
                                                id="admin_override"
                                                checked={data.admin_override}
                                                onChange={(e) => setData('admin_override', e.target.checked)}
                                                className="h-3.5 w-3.5 rounded border-amber-300 text-amber-600 focus:ring-amber-500 cursor-pointer"
                                            />
                                            <label htmlFor="admin_override" className="text-xs font-black uppercase tracking-wider text-amber-950 cursor-pointer">
                                                I have resolved conflicts and want to force override
                                            </label>
                                        </div>
                                    </div>
                                )}

                                <div className="pt-4 border-t border-slate-100 flex justify-end gap-3">
                                    <Link
                                        href={route('admin.sales-machine-profiles.index')}
                                        className="px-5 py-2.5 bg-slate-50 hover:bg-slate-100 text-slate-600 rounded-xl font-black text-xs uppercase tracking-widest transition-colors"
                                    >
                                        Cancel
                                    </Link>
                                    <button
                                        type="submit"
                                        disabled={processing || (hasUnsyncedSales && !data.admin_override)}
                                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-black text-xs uppercase tracking-widest transition-colors shadow-lg shadow-indigo-600/20 disabled:opacity-50 disabled:shadow-none"
                                    >
                                        <Save size={14} />
                                        Save Configuration
                                    </button>
                                </div>
                            </form>
                        </div>

                        {/* Right Column: Terminal Info Cards */}
                        <div className="space-y-6">
                            <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8">
                                <h3 className="text-xs font-black uppercase tracking-widest text-slate-400 mb-6">Hardware Profile</h3>
                                <div className="space-y-4 text-xs font-semibold text-slate-500">
                                    <div>
                                        <span className="block text-[10px] font-black uppercase tracking-widest text-slate-400">Terminal ID</span>
                                        <span className="block mt-1 font-mono text-slate-800 select-all">{profile.id}</span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <span className="block text-[10px] font-black uppercase tracking-widest text-slate-400">Serial Number</span>
                                            <span className="block mt-1 text-slate-800 truncate" title={profile.machine_serial_number}>{profile.machine_serial_number || '—'}</span>
                                        </div>
                                        <div>
                                            <span className="block text-[10px] font-black uppercase tracking-widest text-slate-400">License Number</span>
                                            <span className="block mt-1 text-slate-800 truncate" title={profile.software_license_number}>{profile.software_license_number || '—'}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <span className="block text-[10px] font-black uppercase tracking-widest text-slate-400">Permit to Use (PTU)</span>
                                        <span className="block mt-1 text-slate-800">{profile.permit_to_use_number || '—'}</span>
                                    </div>
                                    <div>
                                        <span className="block text-[10px] font-black uppercase tracking-widest text-slate-400">Device ID Binding</span>
                                        <span className="block mt-1 font-mono text-slate-800 select-all max-w-full truncate" title={profile.activated_device_id}>{profile.activated_device_id || '—'}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Help Guidance card */}
                            <div className="bg-slate-50 rounded-[2.5rem] border border-slate-100 p-8">
                                <div className="flex gap-2 text-indigo-600">
                                    <Info className="shrink-0 mt-0.5" size={18} />
                                    <h4 className="font-extrabold text-sm text-slate-800 uppercase tracking-wider">Sequence Guidelines</h4>
                                </div>
                                <p className="text-xs text-slate-500 mt-3 font-medium leading-relaxed">
                                    Offline sequence values are used to sign and validate transactions when offline. Since invoice sequence numbers are terminal-scoped, it is critical that:
                                </p>
                                <ul className="text-xs text-slate-500 mt-3 list-disc pl-4 space-y-2 font-medium">
                                    <li>Each register has a completely unique sequence prefix.</li>
                                    <li>The next sequence value is never set to a number smaller than the actual invoices recorded in the database.</li>
                                </ul>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
