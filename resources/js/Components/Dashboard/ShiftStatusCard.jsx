import React from 'react';
import { Link } from '@inertiajs/react';
import StatusCard from './StatusCard';
import { Sun, AlertCircle, CheckCircle2, ArrowRight } from 'lucide-react';

export default function ShiftStatusCard({ shiftContext }) {
    const { active_shift_id, active_shift_status, active_shift_opened_at, pending_review_count, is_pos_user } = shiftContext;

    const formatTime = (dateStr) => {
        return new Date(dateStr).toLocaleTimeString('en-PH', {
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <StatusCard 
            title="Shift Operational Status" 
            icon={Sun}
            footer={pending_review_count > 0 ? `${pending_review_count} shifts awaiting manager approval.` : "All shifts finalized."}
        >
            <div className="space-y-6">
                {/* Active Shift Status */}
                <div className="flex items-start gap-4">
                    <div className={`p-3 rounded-lg ${active_shift_id ? 'bg-emerald-50 text-emerald-600' : (is_pos_user ? 'bg-rose-50 text-rose-600' : 'bg-gray-50 text-gray-400')}`}>
                        {active_shift_id ? <CheckCircle2 size={24} /> : <AlertCircle size={24} />}
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-gray-500 uppercase tracking-wider">Your Active Shift</p>
                        {active_shift_id ? (
                            <div>
                                <p className="text-lg font-bold text-gray-900 capitalize">{active_shift_status}</p>
                                <p className="text-xs text-gray-400 mt-0.5">Opened at {formatTime(active_shift_opened_at)}</p>
                            </div>
                        ) : (
                            <div>
                                <p className="text-lg font-bold text-gray-900">{is_pos_user ? 'No Active Shift' : 'N/A'}</p>
                                {is_pos_user && (
                                    <p className="text-xs text-rose-500 mt-0.5 font-medium">Open a shift in the POS to begin.</p>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* Manager Insights (if applicable) */}
                {pending_review_count > 0 && (
                    <div className="p-3 bg-amber-50 rounded-lg border border-amber-100">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold text-amber-900">Review Required</span>
                            <span className="px-2 py-0.5 bg-amber-200 text-amber-800 text-[10px] font-bold rounded-full uppercase tracking-tighter">Action</span>
                        </div>
                        <p className="text-xs text-amber-700 mt-1">
                            {pending_review_count} {pending_review_count === 1 ? 'shift is' : 'shifts are'} submitted and awaiting reconciliation.
                        </p>
                    </div>
                )}

                {/* Quick Action */}
                <Link 
                    href={route('shifts.index')} 
                    className="flex items-center justify-between w-full px-4 py-2.5 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors group"
                >
                    View All Shifts
                    <ArrowRight size={16} className="group-hover:translate-x-1 transition-transform" />
                </Link>
            </div>
        </StatusCard>
    );
}
