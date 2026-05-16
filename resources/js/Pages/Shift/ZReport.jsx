import React, { useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

export default function ZReport({ report, can_see_sensitivity }) {
    
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('print') === 'true') {
            window.print();
        }
    }, []);

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2
        }).format(parseFloat(val || 0));
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '---';
        return new Date(dateStr).toLocaleString('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <div className="min-h-screen bg-gray-50 flex flex-col items-center py-8 px-4 print:p-0 print:bg-white">
            <Head title={`Z-Report - Shift ${report.shift.id.split('-')[0].toUpperCase()}`} />

            {/* Header / Navigation (Hidden when printing) */}
            <div className="w-full max-w-md flex items-center justify-between mb-6 print:hidden">
                <Link 
                    href={route('shifts.show', report.shift.id)}
                    className="flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-800 transition-colors"
                >
                    <ArrowLeft size={16} />
                    Back to Summary
                </Link>
                <button 
                    onClick={() => window.print()}
                    className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm font-bold shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all"
                >
                    <Printer size={16} />
                    Print Report
                </button>
            </div>

            {/* The actual Report (Thermal optimized) */}
            <div className="w-full max-w-[80mm] bg-white border border-gray-100 shadow-sm p-6 font-mono text-[11px] leading-tight print:shadow-none print:border-none print:p-0 print:max-w-none print:w-full">
                
                {/* Header Section */}
                <div className="text-center space-y-1 mb-6">
                    <h1 className="text-sm font-bold uppercase tracking-widest">{report.shift.branch_name}</h1>
                    <p className="uppercase">Shift Reconciliation Report</p>
                    <p className="text-[10px] font-bold text-gray-400">NON-CERTIFIED REPORT</p>
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Shift Details */}
                <div className="space-y-1">
                    <div className="flex justify-between">
                        <span>SHIFT ID:</span>
                        <span className="font-bold">{report.shift.id.split('-')[0].toUpperCase()}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>CASHIER:</span>
                        <span className="font-bold">{report.shift.cashier_name}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>OPENED:</span>
                        <span>{formatDate(report.shift.opened_at)}</span>
                    </div>
                    {report.shift.closed_at && (
                        <div className="flex justify-between">
                            <span>CLOSED:</span>
                            <span>{formatDate(report.shift.closed_at)}</span>
                        </div>
                    )}
                    <div className="flex justify-between">
                        <span>STATUS:</span>
                        <span className="uppercase font-bold">{report.shift.status.replace('_', ' ')}</span>
                    </div>
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Sales Summary */}
                <div className="space-y-1">
                    <p className="font-bold uppercase mb-2">Sales Summary</p>
                    <div className="flex justify-between">
                        <span>GROSS SALES:</span>
                        <span>{formatCurrency(report.sales.gross_sales)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>DISCOUNTS:</span>
                        <span>({formatCurrency(report.sales.discount_breakdown.total)})</span>
                    </div>
                    <div className="flex justify-between border-t border-gray-100 pt-1 mt-1 font-bold">
                        <span>NET TOTAL:</span>
                        <span>{formatCurrency(report.sales.net_total)}</span>
                    </div>
                    <div className="flex justify-between text-[10px] text-gray-500 mt-1">
                        <span>TXN COUNT:</span>
                        <span>{report.sales.transaction_count}</span>
                    </div>
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Tax Breakdown */}
                <div className="space-y-1">
                    <p className="font-bold uppercase mb-2">Tax Breakdown</p>
                    <div className="flex justify-between">
                        <span>VATABLE:</span>
                        <span>{formatCurrency(report.sales.tax_breakdown.vatable)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>VAT AMOUNT:</span>
                        <span>{formatCurrency(report.sales.tax_breakdown.vat_amount)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>VAT EXEMPT:</span>
                        <span>{formatCurrency(report.sales.tax_breakdown.exempt)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>ZERO RATED:</span>
                        <span>{formatCurrency(report.sales.tax_breakdown.zero_rated)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>NON-VAT:</span>
                        <span>{formatCurrency(report.sales.tax_breakdown.non_vat)}</span>
                    </div>
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Payment Breakdown */}
                <div className="space-y-1">
                    <p className="font-bold uppercase mb-2">Payment Breakdown</p>
                    {report.payments.map((p) => (
                        <div key={p.method} className="flex justify-between">
                            <span className="uppercase">{p.method}:</span>
                            <span>{formatCurrency(p.total)} ({p.count})</span>
                        </div>
                    ))}
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Drawer Activity */}
                <div className="space-y-1">
                    <p className="font-bold uppercase mb-2">Drawer Activity</p>
                    <div className="flex justify-between">
                        <span>CASH DROPS:</span>
                        <span>({formatCurrency(report.drawer_activity.cash_drops.total)}) [{report.drawer_activity.cash_drops.count}]</span>
                    </div>
                    <div className="flex justify-between">
                        <span>TOP UPS:</span>
                        <span>{formatCurrency(report.drawer_activity.cash_top_ups.total)} [{report.drawer_activity.cash_top_ups.count}]</span>
                    </div>
                    <div className="flex justify-between">
                        <span>CASH IN:</span>
                        <span>{formatCurrency(report.drawer_activity.cash_in.total)} [{report.drawer_activity.cash_in.count}]</span>
                    </div>
                    <div className="flex justify-between">
                        <span>CASH OUT:</span>
                        <span>({formatCurrency(report.drawer_activity.cash_out.total)}) [{report.drawer_activity.cash_out.count}]</span>
                    </div>
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Reconciliation */}
                <div className="space-y-1">
                    <p className="font-bold uppercase mb-2">Reconciliation</p>
                    <div className="flex justify-between">
                        <span>OPENING CASH:</span>
                        <span>{formatCurrency(report.shift.opening_cash)}</span>
                    </div>
                    <div className="flex justify-between font-bold text-[12px] pt-1">
                        <span>COUNTED CASH:</span>
                        <span>{formatCurrency(report.shift.counted_cash)}</span>
                    </div>
                    
                    {can_see_sensitivity && report.reconciliation && (
                        <>
                            <div className="flex justify-between mt-2">
                                <span>EXPECTED CASH:</span>
                                <span>{formatCurrency(report.reconciliation.expected_cash)}</span>
                            </div>
                            <div className={`flex justify-between font-bold ${parseFloat(report.reconciliation.variance) !== 0 ? 'text-red-600' : ''}`}>
                                <span>VARIANCE:</span>
                                <span>{formatCurrency(report.reconciliation.variance)}</span>
                            </div>
                        </>
                    )}
                </div>

                <hr className="border-t border-dashed border-gray-300 my-4" />

                {/* Denominations (Optional) */}
                {report.shift.closing_denominations && (
                    <div className="space-y-1 mb-4">
                        <p className="font-bold uppercase mb-2">Closing Denominations</p>
                        {Object.entries(report.shift.closing_denominations).filter(([_, count]) => count > 0).map(([val, count]) => (
                            <div key={val} className="flex justify-between text-[10px]">
                                <span>₱{val} x {count}</span>
                                <span>{formatCurrency(parseFloat(val) * count)}</span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Footer */}
                <div className="text-center space-y-2 mt-8 border-t border-gray-100 pt-6">
                    <p className="text-[9px] text-gray-400 italic px-4">
                        {report.meta.disclaimer}
                    </p>
                    <div className="text-[10px] space-y-0.5">
                        <p>Printed by {report.meta.generated_at.split(' ')[0]}</p>
                        <p>{report.meta.generated_at}</p>
                    </div>
                </div>

                <div className="mt-12 text-center">
                    <div className="border-t border-black w-32 mx-auto mb-1"></div>
                    <p className="uppercase text-[9px]">Authorized Signature</p>
                </div>

            </div>
        </div>
    );
}
