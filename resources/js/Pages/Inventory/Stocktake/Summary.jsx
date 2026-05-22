import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { 
    Printer, 
    ArrowLeft, 
    Package, 
    Calendar, 
    User, 
    MapPin,
    AlertTriangle,
    TrendingUp,
    TrendingDown,
    Activity
} from 'lucide-react';

export default function Summary({ session, lines, stats }) {
    const handlePrint = () => {
        window.print();
    };

    const formatQuantity = (val) => {
        return parseFloat(val || 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 4
        });
    };

    const formatDate = (date) => {
        return date ? new Date(date).toLocaleString() : 'N/A';
    };

    return (
        <div className="min-h-screen bg-slate-50 font-sans print:bg-white">
            <Head title={`Summary - ${session.stocktake_number}`} />

            {/* Toolbar - Hidden during print */}
            <div className="sticky top-0 z-10 bg-white/80 backdrop-blur-md border-b border-slate-200 px-4 py-3 print:hidden">
                <div className="max-w-5xl mx-auto flex items-center justify-between">
                    <Link
                        href={route('inventory.stocktakes.show', session.id)}
                        className="inline-flex items-center gap-2 text-slate-500 hover:text-slate-900 transition-colors text-sm font-bold"
                    >
                        <ArrowLeft size={18} />
                        Back to Session
                    </Link>

                    <button
                        onClick={handlePrint}
                        className="inline-flex items-center gap-2 bg-slate-900 text-white px-4 py-2 rounded-xl hover:bg-slate-800 transition-all text-sm font-bold shadow-sm"
                    >
                        <Printer size={18} />
                        Print Report
                    </button>
                </div>
            </div>

            <div className="max-w-5xl mx-auto p-8 print:p-0">
                {/* Header Card */}
                <div className="bg-white rounded-[32px] border border-slate-200 shadow-sm p-10 print:shadow-none print:border-none print:p-0 mb-8">
                    <div className="flex flex-col md:flex-row md:items-start justify-between gap-8 mb-12">
                        <div>
                            <div className="inline-flex items-center px-3 py-1 bg-indigo-50 text-indigo-700 rounded-full text-[10px] font-bold uppercase tracking-wider mb-4">
                                Stocktake Summary Report
                            </div>
                            <h1 className="text-4xl font-black text-slate-900 tracking-tight mb-2">
                                {session.stocktake_number}
                            </h1>
                            <div className="flex flex-wrap items-center gap-4 text-slate-500 text-sm font-medium">
                                <span className="flex items-center gap-1.5">
                                    <MapPin size={14} className="text-slate-400" />
                                    {session.branch.name}
                                </span>
                                <span className="flex items-center gap-1.5 uppercase font-bold tracking-widest text-[10px]">
                                    <Activity size={14} className="text-slate-400" />
                                    Status: {session.status}
                                </span>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-x-8 gap-y-4 text-sm bg-slate-50 p-6 rounded-2xl border border-slate-100 print:bg-white print:border-slate-200">
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Started By</p>
                                <p className="font-bold text-slate-900">{session.started_by_user?.name}</p>
                            </div>
                            <div>
                                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Started At</p>
                                <p className="font-bold text-slate-900">{formatDate(session.started_at)}</p>
                            </div>
                            {session.posted_at && (
                                <>
                                    <div>
                                        <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Posted By</p>
                                        <p className="font-bold text-slate-900">{session.poster?.name}</p>
                                    </div>
                                    <div>
                                        <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Posted At</p>
                                        <p className="font-bold text-slate-900">{formatDate(session.posted_at)}</p>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>

                    {/* Stats Overview */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-12">
                        <div className="p-5 rounded-2xl bg-slate-50 border border-slate-100 print:border-slate-200">
                            <Package className="text-slate-400 mb-3" size={20} />
                            <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Items</p>
                            <p className="text-2xl font-black text-slate-900">{stats.total_lines}</p>
                        </div>
                        <div className="p-5 rounded-2xl bg-slate-50 border border-slate-100 print:border-slate-200">
                            <AlertTriangle className="text-amber-500 mb-3" size={20} />
                            <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Variances</p>
                            <p className="text-2xl font-black text-slate-900">{stats.positive_variance + stats.negative_variance}</p>
                        </div>
                        <div className="p-5 rounded-2xl bg-emerald-50 border border-emerald-100 print:bg-white print:border-slate-200">
                            <TrendingUp className="text-emerald-600 mb-3" size={20} />
                            <p className="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Pos. Adjustment</p>
                            <p className="text-2xl font-black text-emerald-700">+{formatQuantity(stats.total_positive_adjustment)}</p>
                        </div>
                        <div className="p-5 rounded-2xl bg-rose-50 border border-rose-100 print:bg-white print:border-slate-200">
                            <TrendingDown className="text-rose-600 mb-3" size={20} />
                            <p className="text-[10px] font-bold text-rose-600 uppercase tracking-widest">Neg. Adjustment</p>
                            <p className="text-2xl font-black text-rose-700">{formatQuantity(stats.total_negative_adjustment)}</p>
                        </div>
                    </div>

                    {/* Line Items */}
                    <div>
                        <h2 className="text-lg font-black text-slate-900 mb-6 flex items-center gap-2">
                            <Package size={20} className="text-slate-400" />
                            Stocktake Line Details
                        </h2>
                        
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="border-b-2 border-slate-900">
                                        <th className="py-4 px-2 text-[10px] font-bold uppercase tracking-widest text-slate-500">Product</th>
                                        <th className="py-4 px-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 text-right">Expected</th>
                                        <th className="py-4 px-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 text-right">Counted</th>
                                        <th className="py-4 px-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 text-right">Variance</th>
                                        <th className="py-4 px-2 text-[10px] font-bold uppercase tracking-widest text-slate-500">Reason</th>
                                        <th className="py-4 px-2 text-[10px] font-bold uppercase tracking-widest text-slate-500">Counter</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {lines.map(line => (
                                        <tr key={line.id} className="text-sm print:break-inside-avoid">
                                            <td className="py-4 px-2">
                                                <div className="font-bold text-slate-900">{line.product.name}</div>
                                                <div className="text-[10px] font-mono text-slate-400 uppercase">{line.product.sku}</div>
                                            </td>
                                            <td className="py-4 px-2 text-right text-slate-500 font-medium">{formatQuantity(line.expected_quantity)}</td>
                                            <td className="py-4 px-2 text-right font-bold text-slate-900">{formatQuantity(line.counted_quantity)}</td>
                                            <td className={`py-4 px-2 text-right font-black ${
                                                line.variance_quantity > 0.0001 ? 'text-emerald-600' :
                                                (line.variance_quantity < -0.0001 ? 'text-rose-600' : 'text-slate-400')
                                            }`}>
                                                {line.variance_quantity > 0.0001 ? '+' : ''}{formatQuantity(line.variance_quantity)}
                                            </td>
                                            <td className="py-4 px-2">
                                                {line.reason_code ? (
                                                    <div className="text-[10px] font-bold uppercase tracking-tight px-2 py-0.5 bg-slate-100 rounded inline-block">
                                                        {line.reason_code}
                                                    </div>
                                                ) : (
                                                    <span className="text-slate-300 italic text-xs">No variance</span>
                                                )}
                                                {line.remarks && (
                                                    <div className="text-[10px] text-slate-500 mt-1 italic">{line.remarks}</div>
                                                )}
                                            </td>
                                            <td className="py-4 px-2">
                                                <div className="text-xs font-medium text-slate-600">{line.counter?.name}</div>
                                                <div className="text-[10px] text-slate-400">{formatDate(line.counted_at)}</div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-slate-900 bg-slate-50 print:bg-white">
                                        <td className="py-4 px-2 font-black text-slate-900">NET ADJUSTMENT</td>
                                        <td colSpan={2}></td>
                                        <td className={`py-4 px-2 text-right font-black text-lg ${
                                            stats.net_adjustment > 0 ? 'text-emerald-700' :
                                            (stats.net_adjustment < 0 ? 'text-rose-700' : 'text-slate-900')
                                        }`}>
                                            {stats.net_adjustment > 0 ? '+' : ''}{formatQuantity(stats.net_adjustment)}
                                        </td>
                                        <td colSpan={2}></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    {/* Footer - Only visible during print */}
                    <div className="hidden print:block mt-12 pt-8 border-t border-slate-200 text-[10px] text-slate-400 font-medium">
                        <div className="flex justify-between items-center">
                            <div>IPOS Inventory System • Generated on {new Date().toLocaleString()}</div>
                            <div>Ref: {session.stocktake_number} • Page 1 of 1</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
