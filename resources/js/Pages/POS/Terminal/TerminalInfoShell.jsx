import React, { useState } from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Monitor, Store, UserRound } from 'lucide-react';
import ActivationModal from '@/Pages/POS/Components/ActivationModal';

function Field({ label, value, valueClass = '' }) {
    return (
        <div className="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
            <div className="text-[10px] font-black uppercase tracking-widest text-slate-500">{label}</div>
            <div className={`mt-2 break-words text-sm font-semibold ${valueClass || 'text-slate-100'}`}>{value}</div>
        </div>
    );
}

export default function TerminalInfoShell({ title, subtitle, terminalContext, children }) {
    const tenant = terminalContext?.tenant;
    const branch = terminalContext?.branch;
    const terminal = terminalContext?.terminal;
    const user = terminalContext?.user;

    const [showActivation, setShowActivation] = useState(false);

    const deviceId = typeof window !== 'undefined' ? localStorage.getItem('ipos_device_id') : null;
    const isDeviceMismatch = Boolean(terminal?.activated_device_id && deviceId && terminal.activated_device_id !== deviceId);

    // Renamed & clarified terminal context fields
    const terminalProfileValue = terminal 
        ? (terminal.profile_code || terminal.terminal_identifier || terminal.id) 
        : 'Not Activated';

    let activationStatusValue = 'Terminal Context Invalid';
    let statusClass = 'text-rose-400';

    if (terminal) {
        if (isDeviceMismatch) {
            activationStatusValue = 'Device Mismatch';
            statusClass = 'text-rose-400';
        } else {
            const statusMap = {
                active: 'Active',
                pending_activation: 'Pending Activation',
                suspended: 'Suspended',
                revoked: 'Revoked',
                expired: 'Expired',
            };
            activationStatusValue = statusMap[terminal.activation_status] || terminal.activation_status || 'Unknown';
            statusClass = terminal.activation_status === 'active' 
                ? 'text-emerald-400' 
                : (terminal.activation_status === 'suspended' ? 'text-amber-400' : 'text-rose-400');
        }
    }

    const machineIdValue = terminal?.machine_identification_number || 'Not assigned';
    const deviceIdValue = deviceId || 'Not assigned';

    return (
        <div className="flex h-full min-h-0 flex-col overflow-y-auto bg-slate-950 text-slate-100">
            <div className="border-b border-slate-800 bg-slate-900/70 px-5 py-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <Link
                            href={route('pos.terminal.checkout')}
                            className="rounded-xl border border-slate-700 bg-slate-800 p-2 text-slate-300 transition hover:border-indigo-400 hover:text-white"
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <div className="min-w-0">
                            <h1 className="truncate text-xl font-black tracking-tight text-white">{title}</h1>
                            <p className="mt-1 text-xs font-medium text-slate-400">{subtitle}</p>
                        </div>
                    </div>
                    <Link
                        href={route('pos.terminal.checkout')}
                        className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-black uppercase tracking-widest text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-500"
                    >
                        Return to Checkout
                    </Link>
                </div>
            </div>

            <div className="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                <main className="min-w-0 space-y-5">{children}</main>

                <aside className="space-y-4">
                    <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
                        <div className="mb-4 flex items-center gap-2">
                            <Monitor className="h-5 w-5 text-indigo-400" />
                            <h2 className="text-sm font-black uppercase tracking-widest text-slate-200">Terminal Identity</h2>
                        </div>
                        <div className="space-y-3">
                            <Field label="Terminal Profile" value={terminalProfileValue} />
                            <Field label="Activation Status" value={activationStatusValue} valueClass={statusClass} />
                            <Field label="Device ID" value={deviceIdValue} />
                            <Field label="Machine ID" value={machineIdValue} />
                        </div>

                        {/* Recovery / Activation Actions */}
                        {isDeviceMismatch && (
                            <div className="mt-4 rounded-xl border border-rose-500/30 bg-rose-950/45 p-3 text-xs leading-5 text-rose-100 font-semibold">
                                This device does not match the registered terminal.
                            </div>
                        )}

                        {!terminal || (terminal.activation_status !== 'active' && terminal.activation_status !== 'suspended') || isDeviceMismatch ? (
                            <div className="mt-4">
                                <button
                                    onClick={() => setShowActivation(true)}
                                    className="w-full rounded-xl bg-indigo-600 py-3 text-xs font-black uppercase tracking-widest text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-500"
                                >
                                    Activate Terminal
                                </button>
                            </div>
                        ) : terminal.activation_status === 'suspended' ? (
                            <div className="mt-4 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-center text-xs font-semibold text-amber-200">
                                This terminal is suspended. Please contact your administrator.
                            </div>
                        ) : null}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <div className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                            <div className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-400">
                                <Store className="h-4 w-4 text-indigo-400" />
                                Branch
                            </div>
                            <div className="text-sm font-semibold text-slate-100">{branch?.name || branch?.id || 'Unavailable'}</div>
                            <div className="mt-1 truncate text-xs text-slate-500">{tenant?.name || tenant?.id}</div>
                        </div>

                        <div className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                            <div className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-400">
                                <UserRound className="h-4 w-4 text-indigo-400" />
                                Operator
                            </div>
                            <div className="text-sm font-semibold text-slate-100">{user?.name || 'Unavailable'}</div>
                            <div className="mt-1 truncate text-xs text-slate-500">{user?.id}</div>
                        </div>
                    </div>
                </aside>
            </div>

            {showActivation && (
                <ActivationModal
                    onActivated={() => {
                        setShowActivation(false);
                        window.location.reload();
                    }}
                />
            )}
        </div>
    );
}

TerminalInfoShell.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;

