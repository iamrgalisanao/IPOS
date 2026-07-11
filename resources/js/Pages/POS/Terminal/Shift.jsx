import React from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import TerminalInfoShell from './TerminalInfoShell';
import { Clock3, LockKeyhole, WalletCards } from 'lucide-react';

function StatusBlock({ icon: Icon, label, value, tone = 'slate' }) {
    const toneClass = tone === 'green'
        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200'
        : tone === 'amber'
            ? 'border-amber-500/30 bg-amber-500/10 text-amber-100'
            : 'border-slate-800 bg-slate-900/70 text-slate-200';

    return (
        <div className={`rounded-2xl border p-5 ${toneClass}`}>
            <div className="mb-3 flex items-center gap-2 text-xs font-black uppercase tracking-widest opacity-80">
                <Icon className="h-4 w-4" />
                {label}
            </div>
            <div className="text-lg font-black">{value}</div>
        </div>
    );
}

export default function Shift({ terminal_context, active_shift, active_timecard }) {
    return (
        <TerminalInfoShell
            title="Shift"
            subtitle="Current cashier shift and timecard state for this terminal."
            terminalContext={terminal_context}
        >
            <div className="grid gap-4 md:grid-cols-3">
                <StatusBlock
                    icon={Clock3}
                    label="Timecard"
                    value={active_timecard ? 'Clocked In' : 'Not Clocked In'}
                    tone={active_timecard ? 'green' : 'amber'}
                />
                <StatusBlock
                    icon={WalletCards}
                    label="Cashier Shift"
                    value={active_shift ? 'Open' : 'No Active Shift'}
                    tone={active_shift ? 'green' : 'amber'}
                />
                <StatusBlock
                    icon={LockKeyhole}
                    label="Checkout Gate"
                    value={active_shift && active_timecard ? 'Ready' : 'Action Required'}
                    tone={active_shift && active_timecard ? 'green' : 'amber'}
                />
            </div>

            <section className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <h2 className="text-sm font-black uppercase tracking-widest text-slate-300">Shift Details</h2>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    <div className="rounded-xl bg-slate-950/70 p-4">
                        <div className="text-[10px] font-black uppercase tracking-widest text-slate-500">Shift ID</div>
                        <div className="mt-2 break-words text-sm text-slate-100">{active_shift?.id || 'No open shift'}</div>
                    </div>
                    <div className="rounded-xl bg-slate-950/70 p-4">
                        <div className="text-[10px] font-black uppercase tracking-widest text-slate-500">Opened At</div>
                        <div className="mt-2 text-sm text-slate-100">
                            {active_shift?.opened_at ? new Date(active_shift.opened_at).toLocaleString() : 'Unavailable'}
                        </div>
                    </div>
                    <div className="rounded-xl bg-slate-950/70 p-4">
                        <div className="text-[10px] font-black uppercase tracking-widest text-slate-500">Opening Cash</div>
                        <div className="mt-2 text-sm text-slate-100">
                            {active_shift ? `₱${Number(active_shift.opening_cash_amount || 0).toFixed(2)}` : 'Unavailable'}
                        </div>
                    </div>
                    <div className="rounded-xl bg-slate-950/70 p-4">
                        <div className="text-[10px] font-black uppercase tracking-widest text-slate-500">Clocked In At</div>
                        <div className="mt-2 text-sm text-slate-100">
                            {active_timecard?.clocked_in_at ? new Date(active_timecard.clocked_in_at).toLocaleString() : 'Unavailable'}
                        </div>
                    </div>
                </div>
            </section>
        </TerminalInfoShell>
    );
}

Shift.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;

