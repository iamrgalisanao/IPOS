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
    const terminal = terminal_context?.terminal;
    const isTerminalActive = terminal && terminal.activation_status === 'active';

    // 1. Offline Capture Card
    let offlineCaptureValue = 'Disabled';
    let offlineCaptureDetail = '';
    if (!isTerminalActive) {
        offlineCaptureValue = 'Disabled';
        offlineCaptureDetail = 'Reason: Terminal not activated.';
    } else if (!offline_profile?.offline_sales_enabled) {
        offlineCaptureValue = 'Disabled';
        offlineCaptureDetail = 'Reason: Offline sales are disabled for this terminal.';
    } else {
        offlineCaptureValue = 'Enabled';
        offlineCaptureDetail = `Sequence: ${offline_profile?.offline_sequence_prefix || 'Unavailable'} / ${offline_profile?.offline_sequence_status || 'Unavailable'}`;
    }

    // 2. Next Offline Receipt Card
    let nextReceiptValue = 'Unavailable';
    let nextReceiptDetail = '';
    if (!isTerminalActive || !offline_profile?.offline_sequence_prefix) {
        nextReceiptValue = 'Unavailable';
        nextReceiptDetail = 'Reason: No terminal sequence assigned.';
    } else {
        nextReceiptValue = offline_profile?.offline_sequence_next_value 
            ? String(offline_profile.offline_sequence_next_value) 
            : '000001';
        nextReceiptDetail = offline_profile?.last_offline_sync_at
            ? `Last server sync: ${new Date(offline_profile.last_offline_sync_at).toLocaleString()}`
            : 'No terminal sync timestamp available.';
    }

    // 3. Service Worker Card
    const swValue = service_worker?.expected_cache || 'Unavailable';
    const swDetail = 'Healthy';

    // 4. Hardware Adapter Card
    const hwValue = hardware?.adapter === 'noop' ? 'Not Configured' : (hardware?.adapter || 'Not Configured');
    const hwDetail = `Printer and cash drawer hardware are not configured for this terminal. Adapter: ${hardware?.adapter || 'noop'}`;

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
                    value={offlineCaptureValue}
                    detail={offlineCaptureDetail}
                />
                <SettingCard
                    icon={HardDrive}
                    label="Next Offline Receipt"
                    value={nextReceiptValue}
                    detail={nextReceiptDetail}
                />
                <SettingCard
                    icon={Cpu}
                    label="Service Worker"
                    value={swValue}
                    detail={swDetail}
                />
                <SettingCard
                    icon={Printer}
                    label="Hardware Adapter"
                    value={hwValue}
                    detail={hwDetail}
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

