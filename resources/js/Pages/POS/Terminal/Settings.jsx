import React from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import TerminalInfoShell from './TerminalInfoShell';
import { Cpu, HardDrive, Printer, WifiOff } from 'lucide-react';

function SettingCard({ icon: Icon, label, value, detail }) {
    return (
        <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
            <div className="mb-4 flex items-center gap-2">
                <Icon className="h-5 w-5 text-indigo-400" />
                <div className="text-xs font-black uppercase tracking-widest text-slate-500">{label}</div>
            </div>
            <div className="break-words text-lg font-black text-slate-100">{value || 'Unavailable'}</div>
            {detail && <p className="mt-2 text-xs leading-5 text-slate-400">{detail}</p>}
        </div>
    );
}

export default function Settings({ terminal_context, hardware, service_worker, offline_profile }) {
    return (
        <TerminalInfoShell
            title="Settings"
            subtitle="Terminal identity, offline readiness, shell cache, and hardware adapter state."
            terminalContext={terminal_context}
        >
            <div className="grid gap-4 md:grid-cols-2">
                <SettingCard
                    icon={WifiOff}
                    label="Offline Capture"
                    value={offline_profile?.offline_sales_enabled ? 'Enabled' : 'Disabled'}
                    detail={`Sequence: ${offline_profile?.offline_sequence_prefix || 'Unavailable'} / ${offline_profile?.offline_sequence_status || 'Unavailable'}`}
                />
                <SettingCard
                    icon={HardDrive}
                    label="Next Offline Sequence"
                    value={offline_profile?.offline_sequence_next_value ? String(offline_profile.offline_sequence_next_value) : 'Unavailable'}
                    detail={offline_profile?.last_offline_sync_at ? `Last server sync: ${new Date(offline_profile.last_offline_sync_at).toLocaleString()}` : 'No terminal sync timestamp available.'}
                />
                <SettingCard
                    icon={Cpu}
                    label="Service Worker"
                    value={service_worker?.expected_cache}
                    detail={`Health check: ${service_worker?.health_url || 'Unavailable'}`}
                />
                <SettingCard
                    icon={Printer}
                    label="Hardware Adapter"
                    value={hardware?.adapter}
                    detail={hardware?.message || hardware?.status}
                />
            </div>

            <section className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5 text-amber-100">
                <h2 className="text-sm font-black uppercase tracking-widest">Pilot Boundary</h2>
                <p className="mt-3 text-sm leading-6">
                    Offline captures remain provisional until server reconciliation. This terminal settings page does not certify local GCT, Z-read, e-journal, or official offline receipt finalization.
                </p>
            </section>
        </TerminalInfoShell>
    );
}

Settings.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;

