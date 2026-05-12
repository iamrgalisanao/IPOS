import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

function formatDateTime(value) {
    if (!value) {
        return 'N/A';
    }

    return new Date(value).toLocaleString();
}

export default function Connection({ connection, flash }) {
    function connect() {
        router.post(route('accounting.quickbooks.connect'));
    }

    function disconnect() {
        router.post(route('accounting.quickbooks.disconnect'), { reason: 'manual disconnect' });
    }

    const needsReconnect = connection.status !== 'connected';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">QuickBooks Connection</h2>}>
            <Head title="QuickBooks Connection" />

            <div className="py-10">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(flash?.status || flash?.error) && (
                        <div className={`rounded-lg border px-4 py-3 text-sm ${flash.status ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                            {flash.status || flash.error}
                        </div>
                    )}

                    <div className="grid gap-6 lg:grid-cols-[1.15fr,0.85fr]">
                        <div className="rounded-xl bg-white p-6 shadow-sm">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">Connection status</p>
                                    <h3 className="mt-1 text-2xl font-semibold text-slate-900">{connection.connected ? 'Connected to QuickBooks' : 'Not connected'}</h3>
                                    <p className="mt-2 text-sm text-slate-600">This page stores QuickBooks company connection state for the active tenant only. It does not create mappings, imports, sync jobs, or cashier-visible indicators.</p>
                                </div>
                                <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${connection.status === 'connected' ? 'bg-emerald-100 text-emerald-800' : connection.status === 'error' ? 'bg-rose-100 text-rose-800' : connection.status === 'expired' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'}`}>
                                    {connection.status}
                                </span>
                            </div>

                            <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs uppercase text-slate-500">Environment</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{connection.environment}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs uppercase text-slate-500">Realm ID</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{connection.realm_id || 'N/A'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs uppercase text-slate-500">Connected at</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{formatDateTime(connection.connected_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs uppercase text-slate-500">Disconnected at</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{formatDateTime(connection.disconnected_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs uppercase text-slate-500">Access token expiry</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{formatDateTime(connection.access_token_expires_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs uppercase text-slate-500">Refresh token expiry</dt>
                                    <dd className="mt-1 text-sm text-slate-900">{formatDateTime(connection.refresh_token_expires_at)}</dd>
                                </div>
                            </dl>

                            {connection.last_error && (
                                <div className="mt-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                                    {connection.last_error}
                                </div>
                            )}
                        </div>

                        <div className="rounded-xl bg-white p-6 shadow-sm">
                            <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">Actions</p>
                            <div className="mt-4 space-y-3">
                                <button className="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="button" onClick={connect}>
                                    {needsReconnect ? 'Connect QuickBooks' : 'Reconnect QuickBooks'}
                                </button>
                                <button className="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50" disabled={!connection.realm_id && !connection.connected} type="button" onClick={disconnect}>
                                    Disconnect
                                </button>
                            </div>

                            <div className="mt-6 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                                <p className="font-medium text-slate-900">Safety boundary</p>
                                <p className="mt-2">This flow only stores or clears the tenant QuickBooks connection. It does not import chart of accounts, create accounting mappings, trigger sync attempts, or expose tokens to the browser.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}