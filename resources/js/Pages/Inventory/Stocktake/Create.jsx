import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { 
    ClipboardList, 
    ArrowLeft, 
    Save,
    AlertCircle,
    Info
} from 'lucide-react';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        notes: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('inventory.stocktakes.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-4">
                    <Link
                        href={route('inventory.stocktakes.index')}
                        className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-all"
                    >
                        <ArrowLeft size={20} />
                    </Link>
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">
                            Initialize
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">New Stocktake</h2>
                    </div>
                </div>
            }
        >
            <Head title="Initialize Stocktake" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                        <form onSubmit={handleSubmit} className="p-8 space-y-8">
                            <div className="space-y-6">
                                <div className="p-4 bg-blue-50 border border-blue-100 rounded-2xl flex gap-3">
                                    <Info className="text-blue-500 shrink-0" size={20} />
                                    <div className="space-y-1">
                                        <p className="text-sm font-bold text-blue-900">Branch-Scoped Counting</p>
                                        <p className="text-xs text-blue-700 leading-relaxed">
                                            Initializing a new stocktake will create a draft session. Expected quantities will be captured from the system's "Book Stock" once you transition the session to the <strong>Counting</strong> state.
                                        </p>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-bold text-slate-700 flex items-center gap-2">
                                        Session Notes
                                        <span className="text-[10px] font-medium text-slate-400 uppercase tracking-wider">(Optional)</span>
                                    </label>
                                    <textarea
                                        className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:ring-2 focus:ring-indigo-500 transition-all min-h-[120px] resize-none"
                                        placeholder="e.g., Monthly reconciliation, Year-end audit..."
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                    />
                                    {errors.notes && <p className="text-xs text-rose-500 font-medium mt-1">{errors.notes}</p>}
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                                <Link
                                    href={route('inventory.stocktakes.index')}
                                    className="px-6 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition-all"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center gap-2 px-8 py-2.5 bg-slate-900 text-white text-sm font-bold rounded-xl hover:bg-slate-800 transition-all disabled:opacity-50 shadow-sm"
                                >
                                    <Save size={18} />
                                    Create Draft Session
                                </button>
                            </div>
                        </form>
                    </div>

                    <div className="mt-8 p-6 bg-slate-900 rounded-[28px] text-white overflow-hidden relative group">
                        <div className="absolute top-0 right-0 p-8 opacity-10 group-hover:opacity-20 transition-opacity">
                            <ClipboardList size={120} />
                        </div>
                        <div className="relative z-10 space-y-4">
                            <h3 className="text-lg font-bold">What happens next?</h3>
                            <ul className="space-y-3">
                                {[
                                    'A draft session is created with a unique stocktake number.',
                                    'You will review the session configuration before starting the count.',
                                    'Once started, the system snapshots current inventory levels.',
                                    'Authorized counters can then begin recording physical quantities.'
                                ].map((step, idx) => (
                                    <li key={idx} className="flex gap-3 text-sm text-slate-300">
                                        <div className="w-5 h-5 rounded-full bg-slate-800 flex items-center justify-center text-[10px] font-bold text-indigo-400 shrink-0">
                                            {idx + 1}
                                        </div>
                                        {step}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
