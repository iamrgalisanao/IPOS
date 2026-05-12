import React from 'react';
import { Link } from '@inertiajs/react';
import { ShieldCheck, Calendar, Clock, ExternalLink, FileCheck } from 'lucide-react';

export default function SettlementEvidenceCard({ settlement }) {
    const formatDate = (dateStr) => {
        if (!dateStr) return 'N/A';
        return new Date(dateStr).toLocaleString('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    if (!settlement.latest_locked_period_id) {
        return (
            <div className="bg-white p-6 rounded-xl border border-gray-100 shadow-sm">
                <div className="flex items-center gap-3 text-amber-600 mb-4">
                    <ShieldCheck size={20} strokeWidth={2.5} />
                    <h4 className="font-bold uppercase tracking-wider text-xs">Latest Settlement Evidence</h4>
                </div>
                <p className="text-sm text-gray-500 italic">No locked settlement periods found.</p>
                <div className="mt-6 pt-6 border-t border-gray-50">
                    <Link
                        href={route('settlement.periods.index')}
                        className="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1.5 transition-colors"
                    >
                        Review Pending Settlements <ExternalLink size={12} />
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className="bg-white p-6 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow duration-200">
            <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-3 text-emerald-600">
                    <ShieldCheck size={20} strokeWidth={2.5} />
                    <h4 className="font-bold uppercase tracking-wider text-xs">Latest Settlement Evidence</h4>
                </div>
                <span className="px-2.5 py-1 text-[10px] font-bold bg-emerald-50 text-emerald-700 rounded-full border border-emerald-100 uppercase tracking-tight">
                    Locked & Verified
                </span>
            </div>

            <div className="space-y-4">
                <div className="flex items-start gap-3">
                    <div className="mt-0.5 text-gray-400">
                        <Calendar size={16} />
                    </div>
                    <div>
                        <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Period Coverage</p>
                        <p className="text-sm font-semibold text-gray-800">{settlement.latest_locked_label}</p>
                    </div>
                </div>

                <div className="flex items-start gap-3">
                    <div className="mt-0.5 text-gray-400">
                        <Clock size={16} />
                    </div>
                    <div>
                        <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Locked At</p>
                        <p className="text-sm font-semibold text-gray-800">{formatDate(settlement.locked_at)}</p>
                    </div>
                </div>

                <div className="flex items-start gap-3">
                    <div className="mt-0.5 text-gray-400">
                        <FileCheck size={16} />
                    </div>
                    <div>
                        <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Data Integrity</p>
                        <p className="text-sm font-semibold text-gray-800 flex items-center gap-1.5">
                            {settlement.has_snapshot ? (
                                <>
                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Snapshot Archived
                                </>
                            ) : (
                                <>
                                    <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                    Live Evidence
                                </>
                            )}
                        </p>
                    </div>
                </div>
            </div>

            <div className="mt-6 pt-6 border-t border-gray-50">
                <Link
                    href={route('settlement.periods.show', settlement.latest_locked_period_id)}
                    className="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1.5 transition-colors group"
                >
                    View Full Evidence Details 
                    <ExternalLink size={12} className="group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" />
                </Link>
            </div>
        </div>
    );
}
