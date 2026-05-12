import React from 'react';
import { AlertTriangle, Loader2, RefreshCw, Search } from 'lucide-react';

export default function StatusUncertainPanel({ mode, isCheckingStatus, onCheckStatus, onRetry }) {
    if (!['checking', 'uncertain', 'retry_available'].includes(mode)) {
        return null;
    }

    const config = {
        checking: {
            title: 'Checking Status',
            message: 'We are verifying whether the sale was committed. Keep this cart open while backend truth is checked.',
            classes: 'bg-sky-500/10 border-sky-500/20 text-sky-100',
            icon: Loader2,
        },
        uncertain: {
            title: 'Status Uncertain',
            message: 'The connection dropped or timed out before confirmation returned. Check status before retrying.',
            classes: 'bg-amber-500/10 border-amber-500/20 text-amber-100',
            icon: Search,
        },
        retry_available: {
            title: 'Retry Available',
            message: 'No confirmed sale was found for this cart. It is safe to retry using the same request ID.',
            classes: 'bg-amber-500/10 border-amber-500/20 text-amber-100',
            icon: RefreshCw,
        },
    }[mode];

    const Icon = config.icon;

    return (
        <div className={`mx-4 mt-4 p-4 border rounded-xl shadow-lg ${config.classes}`} role="status" aria-live="polite" aria-atomic="true">
            <div className="flex items-start gap-3">
                <Icon className={`w-5 h-5 shrink-0 mt-0.5 ${mode === 'checking' || isCheckingStatus ? 'animate-spin' : ''}`} />
                <div className="min-w-0 flex-1">
                    <div className="font-bold uppercase tracking-wider text-[10px]">{config.title}</div>
                    <div className="text-sm mt-1">{config.message}</div>
                </div>
            </div>

            <div className="mt-4 flex gap-2">
                <button
                    onClick={onCheckStatus}
                    disabled={isCheckingStatus}
                    className="flex-1 flex items-center justify-center gap-2 py-2.5 px-3 rounded-lg bg-slate-950/40 hover:bg-slate-950/60 disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm font-semibold"
                >
                    {isCheckingStatus ? <Loader2 className="w-4 h-4 animate-spin" /> : <Search className="w-4 h-4" />}
                    <span>{isCheckingStatus ? 'Checking...' : 'Check Status'}</span>
                </button>

                {mode === 'retry_available' && (
                    <button
                        onClick={onRetry}
                        className="flex-1 flex items-center justify-center gap-2 py-2.5 px-3 rounded-lg bg-amber-400 text-slate-950 hover:bg-amber-300 transition-colors text-sm font-bold"
                    >
                        <RefreshCw className="w-4 h-4" />
                        <span>Retry Sale</span>
                    </button>
                )}
            </div>

            {mode === 'uncertain' && (
                <div className="mt-3 text-[11px] opacity-80 flex items-center gap-1.5">
                    <AlertTriangle className="w-3.5 h-3.5" />
                    <span>Your cart and request ID are preserved until backend truth is resolved.</span>
                </div>
            )}
        </div>
    );
}