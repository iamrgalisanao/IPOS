import React from 'react';
import { AlertTriangle, CheckCircle2, History, Loader2, RefreshCw, Search } from 'lucide-react';

const toneStyles = {
    blue: 'bg-sky-500/10 border-sky-500/20 text-sky-200',
    amber: 'bg-amber-500/10 border-amber-500/20 text-amber-100',
    emerald: 'bg-emerald-500/10 border-emerald-500/20 text-emerald-100',
    red: 'bg-rose-500/10 border-rose-500/20 text-rose-100',
};

const iconMap = {
    restored: History,
    checking: Loader2,
    uncertain: Search,
    retry_available: RefreshCw,
    confirmed: CheckCircle2,
    failed: AlertTriangle,
};

export default function FailureGuardianBanner({ kind, tone, title, message, announcement }) {
    if (!kind || !message) return null;

    const Icon = iconMap[kind] || AlertTriangle;
    const liveRole = tone === 'red' ? 'alert' : 'status';

    return (
        <div
            className={`px-4 py-3 rounded-xl shadow-2xl border backdrop-blur-md flex items-start gap-3 animate-in fade-in slide-in-from-top-4 ${toneStyles[tone] || toneStyles.red}`}
            role={liveRole}
            aria-live={tone === 'red' ? 'assertive' : 'polite'}
            aria-atomic="true"
        >
            <Icon className={`w-5 h-5 shrink-0 mt-0.5 ${kind === 'checking' ? 'animate-spin' : ''}`} />
            <div className="min-w-0">
                <div className="font-bold text-sm tracking-wide">{title}</div>
                <div className="text-sm opacity-90">{message}</div>
                {announcement && <span className="sr-only">{announcement}</span>}
            </div>
        </div>
    );
}