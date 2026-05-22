import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Alert, AlertDescription } from '@/Components/Alert';

export default function Bootstrap({ valid, token, error, owner_name, company_name, initial_branch_name }) {
    const [submitting, setSubmitting] = useState(false);
    const [passwordStrength, setPasswordStrength] = useState(0);

    const form = useForm({
        password: '',
        password_confirmation: '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Manila',
        language: 'en',
    });

    const checkPasswordStrength = (password) => {
        let strength = 0;
        if (password.length >= 8) strength++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
        if (/\d/.test(password)) strength++;
        if (/[!@#$%^&*]/.test(password)) strength++;
        setPasswordStrength(strength);
    };

    const handlePasswordChange = (e) => {
        const password = e.target.value;
        form.setData('password', password);
        checkPasswordStrength(password);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setSubmitting(true);

        form.post(route('auth.bootstrap.complete', token), {
            onSuccess: () => {
                setSubmitting(false);
                // Redirect to login
                window.location.href = route('login');
            },
            onError: () => {
                setSubmitting(false);
            },
        });
    };

    if (!valid) {
        return (
            <GuestLayout>
                <Head title="Bootstrap" />

                <div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                </div>

                <div className="text-center">
                    <p className="text-gray-600 dark:text-gray-400">
                        If you believe this is an error, please contact your system administrator.
                    </p>
                </div>
            </GuestLayout>
        );
    }

    const getPasswordStrengthColor = (strength) => {
        if (strength === 0) return 'bg-gray-200 dark:bg-gray-700';
        if (strength === 1) return 'bg-red-500';
        if (strength === 2) return 'bg-yellow-500';
        if (strength === 3) return 'bg-blue-500';
        return 'bg-green-500';
    };

    const getPasswordStrengthLabel = (strength) => {
        if (strength === 0) return 'Weak';
        if (strength === 1) return 'Weak';
        if (strength === 2) return 'Fair';
        if (strength === 3) return 'Good';
        return 'Strong';
    };

    return (
        <GuestLayout>
            <Head title="Complete Setup" />

            <div className="mb-6">
                <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">
                    Welcome to IPOS!
                </h1>
                <p className="text-gray-600 dark:text-gray-400">
                    Complete your setup to get started
                </p>
            </div>

            {/* Summary Card */}
            <div className="mb-6 bg-gray-50 dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <div className="space-y-2 text-sm">
                    <div>
                        <span className="font-semibold text-gray-700 dark:text-gray-300">Company:</span>
                        <span className="ml-2 text-gray-900 dark:text-gray-100">{company_name}</span>
                    </div>
                    {initial_branch_name && (
                        <div>
                            <span className="font-semibold text-gray-700 dark:text-gray-300">Primary Branch:</span>
                            <span className="ml-2 text-gray-900 dark:text-gray-100">{initial_branch_name}</span>
                        </div>
                    )}
                    {owner_name && (
                        <div>
                            <span className="font-semibold text-gray-700 dark:text-gray-300">Name:</span>
                            <span className="ml-2 text-gray-900 dark:text-gray-100">{owner_name}</span>
                        </div>
                    )}
                </div>
            </div>

            {/* Form */}
            <form onSubmit={handleSubmit} className="space-y-6">
                {/* Password */}
                <div>
                    <InputLabel htmlFor="password" value="Set Your Password" />
                    <TextInput
                        id="password"
                        name="password"
                        type="password"
                        value={form.data.password}
                        onChange={handlePasswordChange}
                        placeholder="••••••••"
                        disabled={submitting}
                        required
                        autoComplete="new-password"
                    />
                    <InputError message={form.errors.password} className="mt-2" />

                    {form.data.password && (
                        <div className="mt-2">
                            <div className="flex items-center justify-between text-xs mb-1">
                                <span className="text-gray-600 dark:text-gray-400">Password Strength</span>
                                <span className={`font-semibold ${
                                    passwordStrength === 1 ? 'text-red-600' :
                                    passwordStrength === 2 ? 'text-yellow-600' :
                                    passwordStrength === 3 ? 'text-blue-600' :
                                    'text-green-600'
                                }`}>
                                    {getPasswordStrengthLabel(passwordStrength)}
                                </span>
                            </div>
                            <div className="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                <div
                                    className={`h-full transition-all ${getPasswordStrengthColor(passwordStrength)}`}
                                    style={{ width: `${(passwordStrength / 4) * 100}%` }}
                                ></div>
                            </div>
                            <ul className="mt-2 text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <li className={/^.{8,}$/.test(form.data.password) ? 'text-green-600' : ''}>
                                    {/^.{8,}$/.test(form.data.password) ? '✓' : '○'} At least 8 characters
                                </li>
                                <li className={/[a-z]/.test(form.data.password) && /[A-Z]/.test(form.data.password) ? 'text-green-600' : ''}>
                                    {/[a-z]/.test(form.data.password) && /[A-Z]/.test(form.data.password) ? '✓' : '○'} Mix of uppercase and lowercase
                                </li>
                                <li className={/\d/.test(form.data.password) ? 'text-green-600' : ''}>
                                    {/\d/.test(form.data.password) ? '✓' : '○'} At least one number
                                </li>
                                <li className={/[!@#$%^&*]/.test(form.data.password) ? 'text-green-600' : ''}>
                                    {/[!@#$%^&*]/.test(form.data.password) ? '✓' : '○'} At least one special character (!@#$%^&*)
                                </li>
                            </ul>
                        </div>
                    )}
                </div>

                {/* Confirm Password */}
                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirm Password" />
                    <TextInput
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                        placeholder="••••••••"
                        disabled={submitting}
                        required
                        autoComplete="new-password"
                    />
                    <InputError message={form.errors.password_confirmation} className="mt-2" />
                </div>

                {/* Timezone */}
                <div>
                    <InputLabel htmlFor="timezone" value="Timezone" />
                    <select
                        id="timezone"
                        name="timezone"
                        value={form.data.timezone}
                        onChange={(e) => form.setData('timezone', e.target.value)}
                        disabled={submitting}
                        className="block w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-800 dark:text-white"
                    >
                        <option value="Asia/Manila">Asia/Manila (PHT)</option>
                        <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
                        <option value="America/New_York">America/New_York (EST)</option>
                        <option value="America/Los_Angeles">America/Los_Angeles (PST)</option>
                        <option value="Europe/London">Europe/London (GMT)</option>
                        <option value="Europe/Paris">Europe/Paris (CET)</option>
                    </select>
                    <InputError message={form.errors.timezone} className="mt-2" />
                </div>

                {/* Language */}
                <div>
                    <InputLabel htmlFor="language" value="Language" />
                    <select
                        id="language"
                        name="language"
                        value={form.data.language}
                        onChange={(e) => form.setData('language', e.target.value)}
                        disabled={submitting}
                        className="block w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-800 dark:text-white"
                    >
                        <option value="en">English</option>
                        <option value="fil">Filipino (Tagalog)</option>
                    </select>
                    <InputError message={form.errors.language} className="mt-2" />
                </div>

                {/* Submit */}
                <div className="flex items-center justify-end mt-4">
                    <PrimaryButton disabled={submitting || !form.data.password}>
                        {submitting ? 'Completing Setup...' : 'Complete Setup'}
                    </PrimaryButton>
                </div>
            </form>

            {/* Help Text */}
            <p className="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Once you complete this setup, you'll be able to log in and configure your POS system.
            </p>
        </GuestLayout>
    );
}
