import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow, parseISO } from 'date-fns';
import {
    FileDown,
    RefreshCw,
    CheckCircle2,
    XCircle,
    Clock,
} from 'lucide-react';

const statusConfig = {
    pending: { icon: Clock, color: 'text-gray-500', bg: 'bg-gray-100', label: 'Pending' },
    processing: { icon: RefreshCw, color: 'text-blue-500', bg: 'bg-blue-100', label: 'Processing' },
    completed: { icon: CheckCircle2, color: 'text-green-500', bg: 'bg-green-100', label: 'Completed' },
    failed: { icon: XCircle, color: 'text-red-500', bg: 'bg-red-100', label: 'Failed' },
    expired: { icon: Clock, color: 'text-orange-500', bg: 'bg-orange-100', label: 'Expired' },
};

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

export default function Index({ auth, exports }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Data Exports</h2>}
        >
            <Head title="Data Exports" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            
                            <div className="flex justify-between items-center mb-6">
                                <div>
                                    <h3 className="text-lg font-medium">Recent Exports</h3>
                                    <p className="text-sm text-gray-500">View and download your recently requested asynchronous reports.</p>
                                </div>
                                <div>
                                    <button 
                                        onClick={() => window.location.reload()}
                                        className="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                                    >
                                        <RefreshCw className="w-4 h-4 mr-2" />
                                        Refresh
                                    </button>
                                </div>
                            </div>

                            {exports.data.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                                    <FileDown className="mx-auto h-12 w-12 text-gray-400" />
                                    <h3 className="mt-2 text-sm font-semibold text-gray-900">No exports found</h3>
                                    <p className="mt-1 text-sm text-gray-500">You haven't requested any data exports recently.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-300">
                                        <thead>
                                            <tr>
                                                <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0">Type</th>
                                                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Requested</th>
                                                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Size</th>
                                                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Status</th>
                                                <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-0">
                                                    <span className="sr-only">Actions</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 bg-white">
                                            {exports.data.map((item) => {
                                                const StatusIcon = statusConfig[item.status]?.icon || Clock;
                                                return (
                                                    <tr key={item.id}>
                                                        <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-0 uppercase">
                                                            {item.type}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                            <div className="flex flex-col">
                                                                <span>{new Date(item.requested_at).toLocaleString()}</span>
                                                                <span className="text-xs text-gray-400">
                                                                    {formatDistanceToNow(parseISO(item.requested_at), { addSuffix: true })}
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                            {item.file_size ? formatBytes(item.file_size) : '-'}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                            <span className={`inline-flex items-center gap-x-1.5 rounded-full px-2 py-1 text-xs font-medium ${statusConfig[item.status]?.bg} ${statusConfig[item.status]?.color}`}>
                                                                <StatusIcon className="h-4 w-4" />
                                                                {statusConfig[item.status]?.label}
                                                            </span>
                                                            {item.error_message && (
                                                                <p className="text-xs text-red-500 mt-1 max-w-xs truncate" title={item.error_message}>
                                                                    {item.error_message}
                                                                </p>
                                                            )}
                                                            {item.expires_at && item.can_download && (
                                                                <p className="text-xs text-gray-400 mt-1">
                                                                    Expires {formatDistanceToNow(parseISO(item.expires_at), { addSuffix: true })}
                                                                </p>
                                                            )}
                                                        </td>
                                                        <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-0">
                                                            {item.can_download && (
                                                                <a
                                                                    href={route('reports.exports.download', item.id)}
                                                                    className="text-indigo-600 hover:text-indigo-900"
                                                                >
                                                                    Download<span className="sr-only">, {item.type}</span>
                                                                </a>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
