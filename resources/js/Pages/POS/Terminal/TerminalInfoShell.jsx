import React from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Monitor, Store, UserRound } from 'lucide-react';

function Field({ label, value }) {
    return (
        <div className="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
            <div className="text-[10px] font-black uppercase tracking-widest text-slate-500">{label}</div>
            <div className="mt-2 break-words text-sm font-semibold text-slate-100">{value || 'Unavailable'}</div>
        </div>
    );
}

export default function TerminalInfoShell({ title, subtitle, terminalContext, children }) {
    const tenant = terminalContext?.tenant;
    const branch = terminalContext?.branch;
    const terminal = terminalContext?.terminal;
    const user = terminalContext?.user;

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
                            <h2 className="text-sm font-black uppercase tracking-widest text-slate-200">Terminal Context</h2>
                        </div>
                        <div className="space-y-3">
                            <Field label="Terminal" value={terminal?.profile_code || terminal?.terminal_identifier || terminal?.id} />
                            <Field label="Status" value={terminal?.status} />
                            <Field label="MIN" value={terminal?.machine_identification_number} />
                        </div>
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
        </div>
    );
}

TerminalInfoShell.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;

