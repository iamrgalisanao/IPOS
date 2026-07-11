import React from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import TerminalInfoShell from './TerminalInfoShell';
import { AlertTriangle, Database, History, ShieldCheck } from 'lucide-react';

export default function SyncStatus({ terminal_context, sync_guidance }) {
    return (
        <TerminalInfoShell
            title="Sync Status"
            subtitle="Operational queue and reconciliation guidance for this terminal."
            terminalContext={terminal_context}
        >
            <div className="grid gap-4 md:grid-cols-3">
                <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                    <Database className="mb-4 h-6 w-6 text-indigo-400" />
                    <div className="text-xs font-black uppercase tracking-widest text-slate-500">Local Queue</div>
                    <div className="mt-2 text-lg font-black text-slate-100">IndexedDB</div>
                    <p className="mt-2 text-xs leading-5 text-slate-400">Pending, failed, and review records remain local until synchronized or reviewed.</p>
                </div>
                <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                    <History className="mb-4 h-6 w-6 text-indigo-400" />
                    <div className="text-xs font-black uppercase tracking-widest text-slate-500">Retry Path</div>
                    <div className="mt-2 text-lg font-black text-slate-100">Checkout Drawer</div>
                    <p className="mt-2 text-xs leading-5 text-slate-400">Use the checkout queue drawer for cashier-visible retry and queue inspection.</p>
                </div>
                <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5">
                    <AlertTriangle className="mb-4 h-6 w-6 text-amber-300" />
                    <div className="text-xs font-black uppercase tracking-widest text-amber-200">Review Required</div>
                    <div className="mt-2 text-lg font-black text-amber-100">Admin Only</div>
                    <p className="mt-2 text-xs leading-5 text-amber-100/80">Sequence conflicts and rejected imports must be handled through admin review.</p>
                </div>
            </div>

            <section className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <div className="mb-4 flex items-center gap-2">
                    <ShieldCheck className="h-5 w-5 text-emerald-400" />
                    <h2 className="text-sm font-black uppercase tracking-widest text-slate-300">Support Guidance</h2>
                </div>
                <div className="space-y-3 text-sm leading-6 text-slate-300">
                    <p>{sync_guidance?.cashier_message}</p>
                    <p>
                        Admin review surface:{' '}
                        <span className="font-mono text-indigo-300">{sync_guidance?.admin_review_route || 'Unavailable'}</span>
                    </p>
                    <p className="text-slate-400">
                        This screen is intentionally read-only for this hardening slice. Queue mutations remain in checkout and admin review workflows.
                    </p>
                </div>
            </section>
        </TerminalInfoShell>
    );
}

SyncStatus.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;

