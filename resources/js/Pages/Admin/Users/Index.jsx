import React, { useMemo, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { CheckCircle2, Edit2, KeyRound, Plus, Search, ShieldCheck, Users } from 'lucide-react';

const emptyForm = {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
    status: 'active',
    role_ids: [],
    branch_ids: [],
    pos_pin: '',
};

export default function Index({ auth, users, roles, branches }) {
    const [query, setQuery] = useState('');
    const [editingUser, setEditingUser] = useState(null);
    const [modalOpen, setModalOpen] = useState(false);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(emptyForm);

    const filteredUsers = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return users;

        return users.filter((user) => {
            const roleText = user.roles.map((role) => role.name).join(' ');
            const branchText = user.branches.map((branch) => branch.name).join(' ');
            return [user.name, user.email, user.status, roleText, branchText]
                .join(' ')
                .toLowerCase()
                .includes(q);
        });
    }, [query, users]);

    const openCreate = () => {
        setEditingUser(null);
        reset();
        clearErrors();
        setModalOpen(true);
    };

    const openEdit = (user) => {
        setEditingUser(user);
        setData({
            ...emptyForm,
            first_name: user.first_name || user.name?.split(' ')[0] || '',
            last_name: user.last_name || user.name?.split(' ').slice(1).join(' ') || '',
            email: user.email,
            status: user.status || 'active',
            role_ids: user.role_ids || [],
            branch_ids: user.branch_ids || [],
        });
        clearErrors();
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingUser(null);
        reset();
        clearErrors();
    };

    const toggleArrayValue = (field, value) => {
        const current = data[field] || [];
        setData(field, current.includes(value)
            ? current.filter((item) => item !== value)
            : [...current, value]
        );
    };

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: closeModal };

        if (editingUser) {
            put(route('admin.users.update', editingUser.id), options);
            return;
        }

        post(route('admin.users.store'), options);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-2xl font-extrabold tracking-tight text-slate-800">User Management</h2>
                        <p className="mt-1 text-sm font-medium text-slate-500">Manage tenant users, POS roles, branch assignments, and cashier PIN readiness.</p>
                    </div>
                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-500"
                    >
                        <Plus size={18} />
                        New User
                    </button>
                </div>
            }
        >
            <Head title="User Management" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6 grid gap-4 sm:grid-cols-3">
                        <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Users</p>
                            <p className="mt-2 text-3xl font-black text-slate-800">{users.length}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Roles</p>
                            <p className="mt-2 text-3xl font-black text-slate-800">{roles.length}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Branches</p>
                            <p className="mt-2 text-3xl font-black text-slate-800">{branches.length}</p>
                        </div>
                    </div>

                    <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="relative max-w-md flex-1">
                            <Search size={18} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search users, roles, branches..."
                                className="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-11 pr-4 text-sm font-medium shadow-sm transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            />
                        </div>
                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Only users in the active tenant are shown.</p>
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <thead className="bg-slate-50/80">
                                    <tr>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">User</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Roles</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Branches</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">POS Ready</th>
                                        <th className="px-6 py-4 text-right text-[10px] font-black uppercase tracking-widest text-slate-400">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {filteredUsers.map((user) => (
                                        <tr key={user.id} className="hover:bg-slate-50/70">
                                            <td className="px-6 py-5">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                                                        <Users size={20} />
                                                    </div>
                                                    <div>
                                                        <p className="font-black text-slate-800">{user.name}</p>
                                                        <p className="text-sm font-medium text-slate-500">{user.email}</p>
                                                        <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest ${
                                                            user.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'
                                                        }`}>
                                                            {user.status}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-5">
                                                <div className="flex flex-wrap gap-2">
                                                    {user.roles.map((role) => (
                                                        <span key={role.id} className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">{role.name}</span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-6 py-5">
                                                <div className="flex flex-wrap gap-2">
                                                    {user.branches.map((branch) => (
                                                        <span key={branch.id} className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{branch.branch_code || branch.name}</span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-6 py-5">
                                                <div className="flex flex-col gap-2">
                                                    <span className={`inline-flex items-center gap-2 text-sm font-bold ${user.permissions.includes('create_sale') ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                        <ShieldCheck size={16} />
                                                        {user.permissions.includes('create_sale') ? 'Can sell' : 'No sale permission'}
                                                    </span>
                                                    <span className={`inline-flex items-center gap-2 text-sm font-bold ${user.has_pos_pin ? 'text-emerald-600' : 'text-slate-400'}`}>
                                                        <KeyRound size={16} />
                                                        {user.has_pos_pin ? 'PIN set' : 'No PIN'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-5 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(user)}
                                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 shadow-sm transition hover:bg-slate-50"
                                                >
                                                    <Edit2 size={16} />
                                                    Edit
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={modalOpen} onClose={closeModal} maxWidth="4xl">
                <form onSubmit={submit} className="p-6">
                    <div className="mb-6 flex items-center gap-3">
                        <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                            {editingUser ? <Edit2 size={20} /> : <Plus size={20} />}
                        </div>
                        <div>
                            <h3 className="text-lg font-black text-slate-800">{editingUser ? 'Edit User' : 'Create User'}</h3>
                            <p className="text-sm font-medium text-slate-500">Assign roles and branches before opening POS access.</p>
                        </div>
                    </div>

                    <div className="grid gap-5 md:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="first_name" value="First Name" />
                            <TextInput id="first_name" value={data.first_name} onChange={(event) => setData('first_name', event.target.value)} className="mt-1 block w-full" />
                            <InputError message={errors.first_name} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="last_name" value="Last Name" />
                            <TextInput id="last_name" value={data.last_name} onChange={(event) => setData('last_name', event.target.value)} className="mt-1 block w-full" />
                            <InputError message={errors.last_name} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="email" value="Email" />
                            <TextInput id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className="mt-1 block w-full" />
                            <InputError message={errors.email} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="status" value="Status" />
                            <select id="status" value={data.status} onChange={(event) => setData('status', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending_activation">Pending Activation</option>
                            </select>
                            <InputError message={errors.status} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="password" value={editingUser ? 'New Password' : 'Password'} />
                            <TextInput id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} className="mt-1 block w-full" />
                            <InputError message={errors.password} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="password_confirmation" value="Confirm Password" />
                            <TextInput id="password_confirmation" type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} className="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel htmlFor="pos_pin" value="POS PIN" />
                            <TextInput id="pos_pin" value={data.pos_pin} onChange={(event) => setData('pos_pin', event.target.value)} className="mt-1 block w-full" placeholder="4 to 6 digits" />
                            <InputError message={errors.pos_pin} className="mt-2" />
                        </div>
                    </div>

                    <div className="mt-6 grid gap-5 md:grid-cols-2">
                        <div>
                            <p className="text-xs font-black uppercase tracking-widest text-slate-500">Roles</p>
                            <div className="mt-3 space-y-2 rounded-2xl border border-slate-100 p-4">
                                {roles.map((role) => (
                                    <label key={role.id} className="flex cursor-pointer items-start gap-3 rounded-xl p-2 hover:bg-slate-50">
                                        <input type="checkbox" checked={data.role_ids.includes(role.id)} onChange={() => toggleArrayValue('role_ids', role.id)} className="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                        <span>
                                            <span className="block text-sm font-black text-slate-700">{role.name}</span>
                                            <span className="block text-xs font-medium text-slate-500">{role.description}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.role_ids} className="mt-2" />
                        </div>
                        <div>
                            <p className="text-xs font-black uppercase tracking-widest text-slate-500">Branches</p>
                            <div className="mt-3 space-y-2 rounded-2xl border border-slate-100 p-4">
                                {branches.map((branch) => (
                                    <label key={branch.id} className="flex cursor-pointer items-center gap-3 rounded-xl p-2 hover:bg-slate-50">
                                        <input type="checkbox" checked={data.branch_ids.includes(branch.id)} onChange={() => toggleArrayValue('branch_ids', branch.id)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                        <span className="text-sm font-bold text-slate-700">{branch.name} <span className="text-slate-400">({branch.branch_code})</span></span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.branch_ids} className="mt-2" />
                        </div>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
                        <SecondaryButton type="button" onClick={closeModal}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={processing}>
                            <CheckCircle2 size={16} className="mr-2" />
                            {editingUser ? 'Save User' : 'Create User'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
