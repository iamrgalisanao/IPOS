import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import axios from 'axios';

export default function Dashboard({ auth }) {
    const [summaryData, setSummaryData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        axios.get('/api/system-admin/dashboard/summary')
            .then(response => {
                setSummaryData(response.data);
                setLoading(false);
            })
            .catch(err => {
                console.error(err);
                setError('Failed to load dashboard summary.');
                setLoading(false);
            });
    }, []);

    const renderMetricCard = (title, value, colorClass = 'text-gray-900') => (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div className="text-sm font-medium text-gray-500 truncate">{title}</div>
            <div className={`mt-1 text-3xl font-semibold ${colorClass}`}>{value}</div>
        </div>
    );

    const urgencyBandClasses = {
        low: 'bg-green-100 text-green-800 border-green-200',
        caution: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        critical: 'bg-red-100 text-red-800 border-red-200',
    };

    const formatSignalLabel = (key) => {
        const labels = {
            readiness_state: 'Readiness State',
            blocker_count: 'Blocker Count',
            pending_action_count: 'Pending Action Count',
            days_since_creation: 'Days Since Creation',
            days_since_last_sign_off: 'Days Since Last Sign-Off',
        };

        return labels[key] || key;
    };

    const formatSignalValue = (value) => {
        if (value === null || value === undefined) {
            return 'N/A';
        }

        return String(value);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">System Admin Dashboard</h2>}
        >
            <Head title="System Admin Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
                    
                    {loading && (
                        <div className="text-center py-10">
                            <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div>
                            <p className="mt-2 text-gray-500">Loading operational intelligence...</p>
                        </div>
                    )}

                    {error && (
                        <div className="bg-red-50 p-4 rounded-md">
                            <div className="flex">
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-red-800">Error</h3>
                                    <div className="mt-2 text-sm text-red-700">
                                        <p>{error}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {!loading && !error && summaryData && (
                        <>
                            {/* Readiness Section */}
                            <section>
                                <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4">Tenant Readiness Overview</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                                    {renderMetricCard('Ready for Operations', summaryData.readiness_counts?.ready_for_operations || 0, 'text-green-600')}
                                    {renderMetricCard('Ready for Pilot', summaryData.readiness_counts?.ready_for_pilot || 0, 'text-blue-600')}
                                    {renderMetricCard('Blocked', summaryData.readiness_counts?.blocked || 0, 'text-red-600')}
                                </div>
                            </section>

                            {/* Pilot Eligibility Section */}
                            <section>
                                <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4">Pilot Eligibility (Branches)</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                                    {renderMetricCard('Branches Ready', summaryData.pilot_counts?.branches_ready || 0, 'text-green-600')}
                                    {renderMetricCard('Branches Pending', summaryData.pilot_counts?.branches_pending || 0, 'text-yellow-600')}
                                    {renderMetricCard('Branches Blocked', summaryData.pilot_counts?.branches_blocked || 0, 'text-red-600')}
                                </div>
                            </section>

                            {/* Urgency Advisory Section */}
                            <section>
                                <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4">Tenant Advisory Urgency</h3>

                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-6">
                                    {renderMetricCard('Low', summaryData.urgency_counts?.low || 0, 'text-green-600')}
                                    {renderMetricCard('Caution', summaryData.urgency_counts?.caution || 0, 'text-yellow-600')}
                                    {renderMetricCard('Critical', summaryData.urgency_counts?.critical || 0, 'text-red-600')}
                                </div>

                                <div className="bg-white shadow overflow-hidden sm:rounded-md">
                                    {(!summaryData.tenant_urgency || summaryData.tenant_urgency.length === 0) ? (
                                        <div className="px-6 py-4 text-sm text-gray-500 text-center">No tenant urgency advisories found.</div>
                                    ) : (
                                        <ul role="list" className="divide-y divide-gray-200">
                                            {summaryData.tenant_urgency.map((tenantUrgency) => {
                                                const urgencyBand = (tenantUrgency.urgency_band || 'low').toLowerCase();
                                                const bandClass = urgencyBandClasses[urgencyBand] || 'bg-gray-100 text-gray-800 border-gray-200';
                                                const reasons = tenantUrgency.reasons || [];
                                                const signals = tenantUrgency.signals || {};

                                                return (
                                                    <li key={`${tenantUrgency.tenant_id}-${urgencyBand}`}>
                                                        <div className="px-4 py-4 sm:px-6">
                                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                                <div className="min-w-0">
                                                                    <p className="text-sm font-semibold text-gray-900 truncate">{tenantUrgency.tenant_name}</p>
                                                                    <p className="mt-1 text-xs text-gray-500">Tenant ID: {tenantUrgency.tenant_id}</p>
                                                                </div>

                                                                <div className="flex items-center gap-2">
                                                                    <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wide ${bandClass}`}>
                                                                        {urgencyBand}
                                                                    </span>
                                                                    <span className="text-xs font-semibold text-gray-700 bg-gray-100 rounded-full px-2.5 py-1">
                                                                        Score {tenantUrgency.score ?? 0}
                                                                    </span>
                                                                </div>
                                                            </div>

                                                            <div className="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                                                <div>
                                                                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Reasons</p>
                                                                    {reasons.length === 0 ? (
                                                                        <p className="text-sm text-gray-500">No advisory reasons.</p>
                                                                    ) : (
                                                                        <ul className="list-disc list-inside space-y-1">
                                                                            {reasons.map((reason, index) => (
                                                                                <li key={`${tenantUrgency.tenant_id}-reason-${index}`} className="text-sm text-gray-700">
                                                                                    {reason}
                                                                                </li>
                                                                            ))}
                                                                        </ul>
                                                                    )}
                                                                </div>

                                                                <div>
                                                                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Signals</p>
                                                                    <dl className="space-y-1 text-sm">
                                                                        {Object.entries(signals).map(([key, value]) => (
                                                                            <div key={`${tenantUrgency.tenant_id}-${key}`} className="flex justify-between gap-4">
                                                                                <dt className="text-gray-500">{formatSignalLabel(key)}</dt>
                                                                                <dd className="text-gray-800 font-medium">{formatSignalValue(value)}</dd>
                                                                            </div>
                                                                        ))}
                                                                    </dl>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </div>
                            </section>

                            {/* Compliance Section */}
                            <section>
                                <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4">Compliance & Configuration Issues</h3>
                                <div className="bg-white shadow overflow-hidden sm:rounded-lg">
                                    <ul role="list" className="divide-y divide-gray-200">
                                        <li className="px-6 py-4 flex justify-between items-center">
                                            <div className="text-sm font-medium text-gray-900">Tenants Missing Profile</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.tenants_missing_profile || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center">
                                            <div className="text-sm font-medium text-gray-900">Tenants Missing Plan</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.tenants_missing_plan || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center">
                                            <div className="text-sm font-medium text-gray-900">Tenants with Mismatched Features</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.tenants_mismatched_features || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center">
                                            <div className="text-sm font-medium text-gray-900">Tenants with No Branches</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.tenants_no_branches || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center bg-gray-50">
                                            <div className="text-sm font-medium text-gray-900">Inactive Branches</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.branches_inactive || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center bg-gray-50">
                                            <div className="text-sm font-medium text-gray-900">Branches Missing Admin</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.branches_missing_admin || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center bg-gray-50">
                                            <div className="text-sm font-medium text-gray-900">Branches Missing Machine Profile</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.branches_missing_profile || 0}</div>
                                        </li>
                                        <li className="px-6 py-4 flex justify-between items-center bg-gray-50">
                                            <div className="text-sm font-medium text-gray-900">Branches Failing Profile Compliance</div>
                                            <div className="text-sm text-gray-500 font-bold">{summaryData.compliance_counts?.branches_incomplete_compliance || 0}</div>
                                        </li>
                                    </ul>
                                </div>
                            </section>

                            {/* Recent Sign-offs */}
                            <section>
                                <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4">Recent Readiness Sign-Offs</h3>
                                <div className="bg-white shadow overflow-hidden sm:rounded-md">
                                    {(!summaryData.recent_sign_offs || summaryData.recent_sign_offs.length === 0) ? (
                                        <div className="px-6 py-4 text-sm text-gray-500 text-center">No recent sign-offs found.</div>
                                    ) : (
                                        <ul role="list" className="divide-y divide-gray-200">
                                            {summaryData.recent_sign_offs.map((signOff) => (
                                                <li key={signOff.id}>
                                                    <div className="px-4 py-4 sm:px-6 flex items-center justify-between">
                                                        <div className="flex-1 min-w-0 pr-4">
                                                            <p className="text-sm font-medium text-indigo-600 truncate">
                                                                {signOff.tenant_name}
                                                            </p>
                                                            <p className="mt-2 flex items-center text-sm text-gray-500">
                                                                <span className="truncate">Signed off as {signOff.signed_off_state} by {signOff.signer_name}</span>
                                                            </p>
                                                            {signOff.notes && (
                                                                <p className="mt-1 text-sm text-gray-400 italic">"{signOff.notes}"</p>
                                                            )}
                                                        </div>
                                                        <div className="ml-4 flex-shrink-0 flex space-x-2">
                                                            <Link 
                                                                href={`/system-admin/tenants/${signOff.tenant_id}/onboarding`} 
                                                                className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                            >
                                                                Tenant Onboarding
                                                            </Link>
                                                            <Link 
                                                                href={`/system-admin/tenants`} 
                                                                className="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded-full shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                            >
                                                                Tenant List
                                                            </Link>
                                                        </div>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </section>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
