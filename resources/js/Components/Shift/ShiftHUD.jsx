import React, { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { 
    Clock, 
    User, 
    Wallet, 
    ChevronDown, 
    ArrowDownCircle, 
    FileText, 
    LogOut,
    Eye,
    EyeOff,
    Lock
} from 'lucide-react';

export default function ShiftHUD({ shift, onRecordEvent, onSpotAudit, onCloseShift, onLockTerminal }) {
    const [duration, setDuration] = useState('');
    const [showActions, setShowActions] = useState(false);
    const [showFinancials, setShowFinancials] = useState(false);

    useEffect(() => {
        if (!shift?.opened_at) return;

        const timer = setInterval(() => {
            const start = new Date(shift.opened_at);
            const now = new Date();
            const diff = Math.floor((now - start) / 1000);

            const hours = Math.floor(diff / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;

            setDuration(
                `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
            );
        }, 1000);

        return () => clearInterval(timer);
    }, [shift?.opened_at]);

    if (!shift || !shift.id) {
        return (
            <div className="bg-slate-900/80 backdrop-blur-md border-b border-slate-800 px-4 py-2 flex items-center justify-between">
                <div className="flex items-center gap-3 text-slate-400">
                    <Clock className="w-4 h-4 animate-pulse" />
                    <span className="text-xs font-medium uppercase tracking-wider">No Active Shift</span>
                </div>
                <Link
                    href={route('shifts.open')}
                    className="text-xs font-bold text-indigo-400 hover:text-indigo-300 transition-colors uppercase tracking-widest"
                >
                    Open Shift
                </Link>
            </div>
        );
    }

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(val || 0);
    };

    return (
        <div className="bg-slate-900/90 backdrop-blur-xl border-b border-slate-800 px-4 py-1.5 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-20 shadow-2xl">
            {/* Left: Identity & Duration */}
            <div className="flex items-center gap-6">
                <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-full bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center">
                        <User className="w-3.5 h-3.5 text-indigo-400" />
                    </div>
                    <div>
                        <p className="text-[10px] uppercase tracking-tighter text-slate-500 font-bold leading-none mb-0.5">Cashier</p>
                        <p className="text-xs font-bold text-slate-200 leading-none">{shift.cashier_name}</p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                        <Clock className="w-3.5 h-3.5 text-emerald-400" />
                    </div>
                    <div>
                        <p className="text-[10px] uppercase tracking-tighter text-slate-500 font-bold leading-none mb-0.5">Duration</p>
                        <p className="text-xs font-mono font-bold text-slate-200 leading-none">{duration}</p>
                    </div>
                </div>
            </div>

            {/* Middle: Financials (Conditional) */}
            <div className="flex items-center gap-6">
                <div className="flex items-center gap-2">
                    <div className="w-7 h-7 rounded-full bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                        <Wallet className="w-3.5 h-3.5 text-amber-400" />
                    </div>
                    <div>
                        <p className="text-[10px] uppercase tracking-tighter text-slate-500 font-bold leading-none mb-0.5">Opening</p>
                        <p className="text-xs font-bold text-slate-200 leading-none">{formatCurrency(shift.opening_cash_amount)}</p>
                    </div>
                </div>

                {shift.expected_cash_amount !== undefined && (
                    <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-full bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center">
                            {showFinancials ? (
                                <EyeOff 
                                    className="w-3.5 h-3.5 text-indigo-400 cursor-pointer" 
                                    onClick={() => setShowFinancials(false)}
                                />
                            ) : (
                                <Eye 
                                    className="w-3.5 h-3.5 text-indigo-400 cursor-pointer" 
                                    onClick={() => setShowFinancials(true)}
                                />
                            )}
                        </div>
                        <div>
                            <p className="text-[10px] uppercase tracking-tighter text-slate-500 font-bold leading-none mb-0.5">Expected Drawer</p>
                            <p className="text-xs font-bold text-slate-200 leading-none">
                                {showFinancials ? formatCurrency(shift.expected_cash_amount) : '••••••'}
                            </p>
                        </div>
                    </div>
                )}
            </div>

            {/* Right: Quick Actions */}
            <div className="flex items-center gap-2 relative">
                <button
                    onClick={() => setShowActions(!showActions)}
                    className="flex items-center gap-2 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg border border-slate-700 transition-all text-xs font-bold uppercase tracking-widest"
                >
                    Shift Actions
                    <ChevronDown className={`w-3 h-3 transition-transform ${showActions ? 'rotate-180' : ''}`} />
                </button>

                {showActions && (
                    <div className="absolute right-0 top-full mt-2 w-48 bg-slate-900 border border-slate-800 rounded-xl shadow-2xl overflow-hidden py-1 z-50">
                        <button
                            onClick={() => {
                                onRecordEvent();
                                setShowActions(false);
                            }}
                            className="w-full flex items-center gap-3 px-4 py-2 text-xs font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
                        >
                            <ArrowDownCircle className="w-4 h-4 text-rose-400" />
                            Record Cash Event
                        </button>

                        <button
                            onClick={() => {
                                onSpotAudit();
                                setShowActions(false);
                            }}
                            className="w-full flex items-center gap-3 px-4 py-2 text-xs font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
                        >
                            <Eye className="w-4 h-4 text-indigo-400" />
                            Perform Spot Audit
                        </button>
                        
                        {shift?.id && (
                            <Link
                                href={route('shifts.show', shift.id)}
                                className="w-full flex items-center gap-3 px-4 py-2 text-xs font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
                            >
                                <FileText className="w-4 h-4 text-indigo-400" />
                                View Summary
                            </Link>
                        )}

                        <button
                            onClick={() => {
                                onLockTerminal();
                                setShowActions(false);
                            }}
                            className="w-full flex items-center gap-3 px-4 py-2 text-xs font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors border-t border-slate-850"
                        >
                            <Lock className="w-4 h-4 text-amber-400" />
                            Lock Terminal
                        </button>

                        <div className="my-1 border-t border-slate-800"></div>

                        <button
                            onClick={() => {
                                onCloseShift();
                                setShowActions(false);
                            }}
                            className="w-full flex items-center gap-3 px-4 py-2 text-xs font-medium text-rose-400 hover:bg-rose-500/10 transition-colors"
                        >
                            <LogOut className="w-4 h-4" />
                            Close Shift
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
