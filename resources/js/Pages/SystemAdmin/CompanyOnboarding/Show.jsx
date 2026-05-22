import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';

export default function Show({ company, onboarding_state, progress_percentage, status, is_complete, initial_branch, owner_user, timeline }) {
    const [creating_branch, setCreatingBranch] = useState(false);
    const [creating_owner, setCreatingOwner] = useState(false);

    const branchForm = useForm({
        branch_name: '',
        branch_code: '',
        location: '',
    });

    const ownerForm = useForm({
        email: '',
        first_name: '',
        last_name: '',
        phone: '',
        send_bootstrap_link: true,
    });

    const handleCreateBranch = (e) => {
        e.preventDefault();
        setCreatingBranch(true);

        branchForm.post(route('system-admin.onboarding.create-branch', company), {
            onSuccess: () => {
                branchForm.reset();
                setCreatingBranch(false);
                // Refresh page
                router.visit(window.location.href);
            },
            onError: () => {
                setCreatingBranch(false);
            },
        });
    };

    const handleCreateOwner = (e) => {
        e.preventDefault();
        setCreatingOwner(true);

        ownerForm.post(route('system-admin.onboarding.create-owner', company), {
            onSuccess: () => {
                ownerForm.reset();
                setCreatingOwner(false);
                // Refresh page
                router.visit(window.location.href);
            },
            onError: () => {
                setCreatingOwner(false);
            },
        });
    };

    return (
        <AuthenticatedLayout header="Company Onboarding">
            <Head title="Company Onboarding" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Header */}
                    <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h1 className="text-2xl font-bold mb-2">{company.name}</h1>
                        <p className="text-gray-600 dark:text-gray-400">Onboarding Progress</p>
                    </div>

                    {/* Progress Bar */}
                    <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div className="mb-4">
                            <div className="flex justify-between items-center mb-2">
                                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {status}
                                </span>
                                <span className="text-sm font-bold text-gray-900 dark:text-gray-100">
                                    {progress_percentage}%
                                </span>
                            </div>
                            <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4">
                                <div
                                    className="bg-blue-600 h-4 rounded-full transition-all duration-300"
                                    style={{ width: `${progress_percentage}%` }}
                                ></div>
                            </div>
                        </div>

                        {is_complete ? (
                            <div className="text-green-600 dark:text-green-400 text-sm font-semibold">
                                ✓ Onboarding Complete
                            </div>
                        ) : (
                            <div className="text-blue-600 dark:text-blue-400 text-sm">
                                Next: {status === 'provisioned' ? 'Create initial branch' : 'Create owner user'}
                            </div>
                        )}
                    </div>

                    {/* Step 1: Initial Branch */}
                    <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Step 1: Create Initial Branch
                                </h2>
                                <p className="text-sm text-gray-600 dark:text-gray-400">
                                    {initial_branch ? 'Branch created' : 'Pending'}
                                </p>
                            </div>
                            {initial_branch && (
                                <div className="text-green-600">
                                    <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" />
                                    </svg>
                                </div>
                            )}
                        </div>

                        {!initial_branch ? (
                            <form onSubmit={handleCreateBranch} className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="branch_name" value="Branch Name" />
                                    <TextInput
                                        id="branch_name"
                                        name="branch_name"
                                        value={branchForm.data.branch_name}
                                        onChange={(e) => branchForm.setData('branch_name', e.target.value)}
                                        placeholder="e.g., Main Branch"
                                        disabled={creating_branch}
                                        required
                                    />
                                    <InputError message={branchForm.errors.branch_name} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="branch_code" value="Branch Code" />
                                    <TextInput
                                        id="branch_code"
                                        name="branch_code"
                                        value={branchForm.data.branch_code}
                                        onChange={(e) => branchForm.setData('branch_code', e.target.value)}
                                        placeholder="e.g., MB-001"
                                        disabled={creating_branch}
                                        required
                                    />
                                    <InputError message={branchForm.errors.branch_code} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="location" value="Location (Optional)" />
                                    <TextInput
                                        id="location"
                                        name="location"
                                        value={branchForm.data.location}
                                        onChange={(e) => branchForm.setData('location', e.target.value)}
                                        placeholder="e.g., Headquarters"
                                        disabled={creating_branch}
                                    />
                                    <InputError message={branchForm.errors.location} className="mt-2" />
                                </div>

                                <div className="flex justify-end">
                                    <PrimaryButton disabled={creating_branch}>
                                        {creating_branch ? 'Creating...' : 'Create Branch'}
                                    </PrimaryButton>
                                </div>
                            </form>
                        ) : (
                            <div className="bg-gray-50 dark:bg-gray-700 p-4 rounded">
                                <p className="font-semibold text-gray-900 dark:text-gray-100">{initial_branch.name}</p>
                                <p className="text-sm text-gray-600 dark:text-gray-400">Code: {initial_branch.branch_code}</p>
                            </div>
                        )}
                    </div>

                    {/* Step 2: Create Owner User */}
                    <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Step 2: Create Owner User
                                </h2>
                                <p className="text-sm text-gray-600 dark:text-gray-400">
                                    {owner_user ? 'Owner assigned' : 'Pending'}
                                </p>
                            </div>
                            {owner_user && (
                                <div className="text-green-600">
                                    <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" />
                                    </svg>
                                </div>
                            )}
                        </div>

                        {!initial_branch ? (
                            <p className="text-amber-600 dark:text-amber-400">
                                Please create the initial branch first
                            </p>
                        ) : !owner_user ? (
                            <form onSubmit={handleCreateOwner} className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="email" value="Email" />
                                    <TextInput
                                        id="email"
                                        name="email"
                                        type="email"
                                        value={ownerForm.data.email}
                                        onChange={(e) => ownerForm.setData('email', e.target.value)}
                                        placeholder="owner@company.com"
                                        disabled={creating_owner}
                                        required
                                    />
                                    <InputError message={ownerForm.errors.email} className="mt-2" />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel htmlFor="first_name" value="First Name" />
                                        <TextInput
                                            id="first_name"
                                            name="first_name"
                                            value={ownerForm.data.first_name}
                                            onChange={(e) => ownerForm.setData('first_name', e.target.value)}
                                            disabled={creating_owner}
                                            required
                                        />
                                        <InputError message={ownerForm.errors.first_name} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="last_name" value="Last Name" />
                                        <TextInput
                                            id="last_name"
                                            name="last_name"
                                            value={ownerForm.data.last_name}
                                            onChange={(e) => ownerForm.setData('last_name', e.target.value)}
                                            disabled={creating_owner}
                                            required
                                        />
                                        <InputError message={ownerForm.errors.last_name} className="mt-2" />
                                    </div>
                                </div>

                                <div>
                                    <InputLabel htmlFor="phone" value="Phone (Optional)" />
                                    <TextInput
                                        id="phone"
                                        name="phone"
                                        value={ownerForm.data.phone}
                                        onChange={(e) => ownerForm.setData('phone', e.target.value)}
                                        placeholder="+1234567890"
                                        disabled={creating_owner}
                                    />
                                    <InputError message={ownerForm.errors.phone} className="mt-2" />
                                </div>

                                <div className="flex justify-end">
                                    <PrimaryButton disabled={creating_owner}>
                                        {creating_owner ? 'Creating...' : 'Create Owner User'}
                                    </PrimaryButton>
                                </div>
                            </form>
                        ) : (
                            <div className="bg-gray-50 dark:bg-gray-700 p-4 rounded">
                                <p className="font-semibold text-gray-900 dark:text-gray-100">
                                    {owner_user.first_name} {owner_user.last_name}
                                </p>
                                <p className="text-sm text-gray-600 dark:text-gray-400">{owner_user.email}</p>
                            </div>
                        )}
                    </div>

                    {/* Timeline */}
                    {timeline.length > 0 && (
                        <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Onboarding Timeline
                            </h3>
                            <div className="space-y-3">
                                {timeline.map((event, index) => (
                                    <div key={index} className="flex items-start">
                                        <div className="flex-shrink-0">
                                            <div className="flex items-center justify-center h-8 w-8 rounded-full bg-blue-100 dark:bg-blue-900">
                                                <svg className="h-4 w-4 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" />
                                                </svg>
                                            </div>
                                        </div>
                                        <div className="ml-3">
                                            <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {event.description}
                                            </p>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                {new Date(event.created_at).toLocaleString()}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
