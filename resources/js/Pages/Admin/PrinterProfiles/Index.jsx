import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import {
    Printer,
    Plus,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Edit,
    Activity,
    Layers,
    MapPin,
    Network,
    Usb,
    Bluetooth,
    FileText,
    Settings,
    Clock,
    Trash2,
    ToggleLeft
} from 'lucide-react';

const emptyForm = {
    branch_id: '',
    name: '',
    connection_type: 'network',
    identifier: '',
    paper_width: '80mm',
    role: 'receipt',
    template_type: 'standard',
    is_active: true,
    is_default: false,
};

export default function Index({ auth, profiles, branches, filters }) {
    const [selectedBranch, setSelectedBranch] = useState(filters.branch_id || '');
    const [modalOpen, setModalOpen] = useState(false);
    const [editingProfile, setEditingProfile] = useState(null);

    const { data, setData, post, put, delete: destroy, processing, errors, reset, clearErrors } = useForm(emptyForm);

    const handleBranchFilterChange = (e) => {
        const branchId = e.target.value;
        setSelectedBranch(branchId);
        router.get(route('admin.printer-profiles.index'), { branch_id: branchId }, {
            preserveState: true,
            preserveScroll: true
        });
    };

    const openCreate = () => {
        setEditingProfile(null);
        reset();
        clearErrors();

        // Auto-select first branch if available
        if (branches.length > 0) {
            setData('branch_id', branches[0].id);
        }
        setModalOpen(true);
    };

    const openEdit = (profile) => {
        setEditingProfile(profile);
        setData({
            branch_id: profile.branch_id,
            name: profile.name,
            connection_type: profile.connection_type,
            identifier: profile.identifier || '',
            paper_width: profile.paper_width,
            role: profile.role,
            template_type: profile.template_type,
            is_active: profile.is_active,
            is_default: profile.is_default,
        });
        clearErrors();
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingProfile(null);
        reset();
        clearErrors();
    };

    const submit = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: closeModal
        };

        if (editingProfile) {
            put(route('admin.printer-profiles.update', editingProfile.id), options);
        } else {
            post(route('admin.printer-profiles.store'), options);
        }
    };

    const deactivateProfile = (profileId) => {
        if (confirm('Deactivating this printer profile will exclude it from terminal overrides and branch-default fallback. Proceed?')) {
            destroy(route('admin.printer-profiles.destroy', profileId), {
                preserveScroll: true
            });
        }
    };

    const getConnectionIcon = (type) => {
        switch (type) {
            case 'network': return <Network size={16} className="text-sky-500" />;
            case 'usb': return <Usb size={16} className="text-amber-500" />;
            case 'bluetooth': return <Bluetooth size={16} className="text-indigo-500" />;
            default: return <Printer size={16} className="text-slate-400" />;
        }
    };

    const getRoleBadge = (role) => {
        const style = role === 'receipt'
            ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
            : role === 'kitchen'
            ? 'bg-orange-50 text-orange-700 border-orange-100'
            : 'bg-slate-50 text-slate-700 border-slate-100';
        return (
            <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider border ${style}`}>
                {role}
            </span>
        );
    };

    const formatTimestamp = (ts) => {
        if (!ts) return 'N/A';
        return new Date(ts).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Receipt & Ticket Printers</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Configure printer endpoints, connection routing, paper dimensions, and default layouts.</p>
                    </div>
                    <button
                        onClick={openCreate}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95 shrink-0 self-start sm:self-auto"
                    >
                        <Plus size={18} />
                        Add Printer
                    </button>
                </div>
            }
        >
            <Head title="Receipt Printers" />

            <div className="py-8">
                <div className="max-w-[96rem] mx-auto px-4 sm:px-6 lg:px-8">

                    {/* Summary Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl">
                                <Printer size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Printers</p>
                                <p className="text-2xl font-black text-slate-800">{profiles.length}</p>
                            </div>
                        </div>
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-emerald-50 text-emerald-600 rounded-2xl">
                                <CheckCircle2 size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Active Printers</p>
                                <p className="text-2xl font-black text-slate-800">{profiles.filter(p => p.is_active).length}</p>
                            </div>
                        </div>
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-sky-50 text-sky-600 rounded-2xl">
                                <Network size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Network TCP</p>
                                <p className="text-2xl font-black text-slate-800">{profiles.filter(p => p.connection_type === 'network').length}</p>
                            </div>
                        </div>
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-amber-50 text-amber-600 rounded-2xl">
                                <Settings size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Branch Defaults</p>
                                <p className="text-2xl font-black text-slate-800">{profiles.filter(p => p.is_default && p.is_active).length}</p>
                            </div>
                        </div>
                    </div>

                    {/* Filter and Details Bar */}
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                        <div className="flex items-center gap-3 bg-white px-4 py-2.5 rounded-2xl border border-slate-100 shadow-sm w-full md:w-80">
                            <MapPin className="text-slate-400 shrink-0" size={18} />
                            <select
                                value={selectedBranch}
                                onChange={handleBranchFilterChange}
                                className="w-full bg-transparent border-none text-slate-700 text-sm font-bold focus:ring-0 p-0 focus:outline-none cursor-pointer"
                            >
                                <option value="">All Branches</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="text-xs text-slate-400 font-bold uppercase tracking-widest">
                            Showing {profiles.length} printer records
                        </div>
                    </div>

                    {/* Printer List Table */}
                    <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Printer Name</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Connection / Endpoint</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Role</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Paper Width</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Default</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Registers Assigned</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status / Updated</th>
                                        <th className="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {profiles.length === 0 ? (
                                        <tr>
                                            <td colSpan="9" className="px-8 py-16 text-center text-slate-400 font-medium italic">
                                                No printer profiles configured for this scope.
                                            </td>
                                        </tr>
                                    ) : (
                                        profiles.map((profile) => (
                                            <tr key={profile.id} className="hover:bg-slate-50/30 transition-colors group">
                                                <td className="px-8 py-6">
                                                    <div className="flex items-center gap-3">
                                                        <div className={`p-3 rounded-2xl ${profile.is_active ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-400'} group-hover:scale-105 transition-transform`}>
                                                            <Printer size={18} />
                                                        </div>
                                                        <div>
                                                            <div className="font-extrabold text-slate-800 text-sm">{profile.name}</div>
                                                            <div className="text-slate-400 text-xs mt-0.5 font-medium">{profile.template_type} template</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-6 text-sm text-slate-600 font-semibold">
                                                    {profile.branch_name || branches.find(b => b.id === profile.branch_id)?.name || 'Branch'}
                                                </td>
                                                <td className="px-6 py-6">
                                                    <div className="flex items-center gap-2 text-sm text-slate-700 font-bold">
                                                        {getConnectionIcon(profile.connection_type)}
                                                        <span>{profile.identifier || 'Browser System'}</span>
                                                    </div>
                                                    <div className="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-0.5">{profile.connection_type}</div>
                                                </td>
                                                <td className="px-6 py-6">
                                                    {getRoleBadge(profile.role)}
                                                </td>
                                                <td className="px-6 py-6 text-sm text-slate-600 font-bold">
                                                    {profile.paper_width}
                                                </td>
                                                <td className="px-6 py-6">
                                                    {profile.is_default && profile.is_active ? (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                            Branch Default
                                                        </span>
                                                    ) : (
                                                        <span className="text-xs text-slate-400">—</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-6 text-center sm:text-left">
                                                    <span className="text-sm font-extrabold text-slate-700">
                                                        {profile.sales_machine_profiles_count || 0}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-6">
                                                    <div className="flex flex-col gap-1">
                                                        <span className={`inline-flex self-start items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${
                                                            profile.is_active
                                                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-100'
                                                            : 'bg-rose-50 text-rose-700 border border-rose-100'
                                                        }`}>
                                                            {profile.is_active ? 'Active' : 'Inactive'}
                                                        </span>
                                                        <span className="text-[10px] text-slate-400 font-medium">Updated {formatTimestamp(profile.updated_at)}</span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-6 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <button
                                                            onClick={() => openEdit(profile)}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-50 border border-slate-100 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-100 transition-colors"
                                                            title="Edit Printer"
                                                        >
                                                            <Edit size={16} />
                                                        </button>

                                                        {profile.is_active && (
                                                            <button
                                                                onClick={() => deactivateProfile(profile.id)}
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 border border-rose-100 text-rose-600 hover:bg-rose-100 transition-colors"
                                                                title="Deactivate Printer"
                                                            >
                                                                <Trash2 size={16} />
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* Modal Sheet for Create/Edit */}
            {modalOpen && (
                <Modal show={modalOpen} onClose={closeModal} maxWidth="lg">
                    <form onSubmit={submit} className="p-8 space-y-6">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                            <h3 className="text-xl font-extrabold text-slate-800">
                                {editingProfile ? 'Edit Printer Profile' : 'Add Printer Profile'}
                            </h3>
                            <button
                                type="button"
                                onClick={closeModal}
                                className="text-slate-400 hover:text-slate-600 transition-colors"
                            >
                                <XCircle size={20} />
                            </button>
                        </div>

                        {/* Branch Selection */}
                        <div>
                            <InputLabel htmlFor="branch_id" value="Assigned Branch" />
                            <select
                                id="branch_id"
                                value={data.branch_id}
                                onChange={(e) => setData('branch_id', e.target.value)}
                                className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                            >
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                            <InputError message={errors.branch_id} className="mt-1" />
                        </div>

                        {/* Printer Name */}
                        <div>
                            <InputLabel htmlFor="name" value="Printer Name" />
                            <TextInput
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="mt-1 block w-full"
                                placeholder="e.g. Front Counter POS Printer"
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            {/* Connection Type */}
                            <div>
                                <InputLabel htmlFor="connection_type" value="Connection Type" />
                                <select
                                    id="connection_type"
                                    value={data.connection_type}
                                    onChange={(e) => setData('connection_type', e.target.value)}
                                    className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                                >
                                    <option value="network">Network TCP/IP</option>
                                    <option value="usb">USB Port</option>
                                    <option value="bluetooth">Bluetooth RFCOMM</option>
                                    <option value="browser_print">Browser Print Dial</option>
                                    <option value="system_default">System Print Default</option>
                                </select>
                                <InputError message={errors.connection_type} className="mt-1" />
                            </div>

                            {/* Printer Role */}
                            <div>
                                <InputLabel htmlFor="role" value="Printer Role" />
                                <select
                                    id="role"
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    disabled
                                    className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                                >
                                    <option value="receipt">Receipt Printer</option>
                                </select>
                                <InputError message={errors.role} className="mt-1" />
                            </div>
                        </div>

                        {/* Connection Identifier */}
                        {['network', 'usb', 'bluetooth'].includes(data.connection_type) && (
                            <div>
                                <InputLabel
                                    htmlFor="identifier"
                                    value={
                                        data.connection_type === 'network'
                                        ? 'IP Address / Hostname'
                                        : data.connection_type === 'bluetooth'
                                        ? 'Bluetooth MAC Address'
                                        : 'USB Vendor/Port Name'
                                    }
                                />
                                <TextInput
                                    id="identifier"
                                    value={data.identifier}
                                    onChange={(e) => setData('identifier', e.target.value)}
                                    className="mt-1 block w-full font-mono"
                                    placeholder={
                                        data.connection_type === 'network'
                                        ? '192.168.1.100'
                                        : data.connection_type === 'bluetooth'
                                        ? '00:11:22:33:FF:EE'
                                        : '/dev/usb/lp0'
                                    }
                                />
                                <InputError message={errors.identifier} className="mt-1" />
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-4">
                            {/* Paper Width */}
                            <div>
                                <InputLabel htmlFor="paper_width" value="Paper Dimensions" />
                                <select
                                    id="paper_width"
                                    value={data.paper_width}
                                    onChange={(e) => setData('paper_width', e.target.value)}
                                    className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                                >
                                    <option value="80mm">80mm thermal roll</option>
                                    <option value="58mm">58mm thermal roll</option>
                                </select>
                                <InputError message={errors.paper_width} className="mt-1" />
                            </div>

                            {/* Template Type */}
                            <div>
                                <InputLabel htmlFor="template_type" value="Print Template" />
                                <select
                                    id="template_type"
                                    value={data.template_type}
                                    onChange={(e) => setData('template_type', e.target.value)}
                                    className="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 font-bold focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                                >
                                    <option value="standard">Standard Invoice</option>
                                    <option value="custom">Custom Format</option>
                                </select>
                                <InputError message={errors.template_type} className="mt-1" />
                            </div>
                        </div>

                        {/* Defaults & Status Toggles */}
                        <div className="space-y-4 pt-4 border-t border-slate-100">
                            {data.role === 'receipt' && (
                                <div className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        id="is_default"
                                        checked={data.is_default}
                                        onChange={(e) => setData('is_default', e.target.checked)}
                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 mt-1 cursor-pointer"
                                    />
                                    <label htmlFor="is_default" className="cursor-pointer select-none">
                                        <span className="block text-sm font-extrabold text-slate-700">Set as Branch Default Receipt Printer</span>
                                        <span className="block text-xs text-slate-500 font-medium mt-0.5">If checked, registers at this branch without a custom printer override will fallback to using this configuration.</span>
                                    </label>
                                </div>
                            )}

                            <div className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    id="is_active"
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 mt-1 cursor-pointer"
                                />
                                <label htmlFor="is_active" className="cursor-pointer select-none">
                                    <span className="block text-sm font-extrabold text-slate-700">Available for Assignment</span>
                                    <span className="block text-xs text-slate-500 font-medium mt-0.5">Inactive profiles are excluded from terminal assignment and fallback while configuration history is retained.</span>
                                </label>
                            </div>
                        </div>

                        <div className="pt-6 border-t border-slate-100 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={closeModal}
                                className="px-5 py-2.5 bg-slate-50 hover:bg-slate-100 text-slate-600 rounded-xl font-black text-xs uppercase tracking-widest transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={processing}
                                className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-black text-xs uppercase tracking-widest transition-colors shadow-lg shadow-indigo-600/20 disabled:opacity-50"
                            >
                                Save Configuration
                            </button>
                        </div>
                    </form>
                </Modal>
            )}
        </AuthenticatedLayout>
    );
}
