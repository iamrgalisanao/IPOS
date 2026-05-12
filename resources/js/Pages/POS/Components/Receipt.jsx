import React from 'react';
import { Printer, X, CheckCircle2 } from 'lucide-react';

/**
 * Receipt component optimized for 80mm thermal printers.
 * Uses @media print for physical print formatting.
 */
export default function Receipt({ data, onClose }) {
    if (!data) return null;

    const handlePrint = () => {
        window.print();
    };

    return (
        <div className="receipt-overlay fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4">
            {/* Modal Container - hidden during print */}
            <div className="receipt-modal bg-slate-900 border border-slate-800 rounded-3xl shadow-2xl w-full max-w-md flex flex-col max-h-[90vh]">
                <div className="p-6 border-b border-slate-800 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-emerald-500/20 rounded-full">
                            <CheckCircle2 className="w-5 h-5 text-emerald-400" />
                        </div>
                        <h2 className="text-xl font-bold">Sale Completed</h2>
                    </div>
                    <button 
                        onClick={onClose}
                        className="p-2 hover:bg-slate-800 rounded-xl transition-colors text-slate-400"
                    >
                        <X className="w-6 h-6" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto p-8 flex justify-center bg-slate-950/50">
                    {/* The actual receipt element for visual preview */}
                    <ReceiptContent data={data} />
                </div>

                <div className="p-6 border-t border-slate-800 flex gap-3">
                    <button 
                        onClick={onClose}
                        className="flex-1 px-6 py-3.5 rounded-2xl bg-slate-800 text-slate-200 font-bold hover:bg-slate-700 transition-all active:scale-[0.98]"
                    >
                        Close
                    </button>
                    <button 
                        onClick={handlePrint}
                        className="flex-1 px-6 py-3.5 rounded-2xl bg-indigo-600 text-white font-bold hover:bg-indigo-500 transition-all shadow-lg shadow-indigo-500/20 flex items-center justify-center gap-2 active:scale-[0.98]"
                    >
                        <Printer className="w-5 h-5" />
                        Print Receipt
                    </button>
                </div>
            </div>

            {/* Hidden Print-Only Container */}
            <div className="receipt-print-area" aria-hidden="true">
                <ReceiptContent data={data} isPrint={true} />
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .receipt-print-area {
                    display: none;
                }

                @media print {
                    @page {
                        margin: 0;
                        size: 80mm auto;
                    }

                    html,
                    body {
                        margin: 0 !important;
                        padding: 0 !important;
                        background: white !important;
                    }

                    body * {
                        visibility: hidden !important;
                    }

                    .receipt-modal {
                        display: none !important;
                    }

                    .receipt-overlay {
                        position: static !important;
                        display: block !important;
                        padding: 0 !important;
                        background: white !important;
                        backdrop-filter: none !important;
                    }

                    .receipt-print-area,
                    .receipt-print-area * {
                        visibility: visible !important;
                    }

                    .receipt-print-area {
                        display: block !important;
                        position: fixed !important;
                        left: 0 !important;
                        top: 0 !important;
                        width: 80mm !important;
                        margin: 0 !important;
                        padding: 0 !important;
                        background: white !important;
                        color: black !important;
                    }

                    .receipt-content {
                        width: 80mm !important;
                        min-height: auto !important;
                        margin: 0 !important;
                        padding: 4mm !important;
                        box-shadow: none !important;
                    }
                }
            `}} />
        </div>
    );
}

function ReceiptContent({ data, isPrint = false }) {
    const { tenant, branch, items, totals, created_at, receipt_reference, cashier_name } = data;

    return (
        <div className={`
            receipt-content
            ${isPrint ? 'bg-white text-black p-0' : 'bg-white text-slate-900 p-8 shadow-inner w-[80mm] min-h-[120mm]'}
            font-mono text-[12px] leading-tight
        `}>
            {/* Header */}
            <div className="text-center mb-6">
                <h1 className="text-[16px] font-bold uppercase mb-1">{tenant.business_name}</h1>
                {tenant.business_registration_number && (
                    <div className="text-[10px]">TIN: {tenant.business_registration_number}</div>
                )}
                {tenant.receipt_header && (
                    <div className="mt-2 italic text-[10px] whitespace-pre-wrap">{tenant.receipt_header}</div>
                )}
                
                <div className="mt-4 border-t border-dashed border-slate-300 pt-2">
                    <div className="font-bold">{branch.branch_name}</div>
                    <div className="text-[10px]">{branch.branch_address}</div>
                    {branch.branch_contact_number && (
                        <div className="text-[10px]">Tel: {branch.branch_contact_number}</div>
                    )}
                </div>
            </div>

            {/* Meta */}
            <div className="mb-4 text-[10px]">
                <div className="flex justify-between">
                    <span>Reference:</span>
                    <span className="font-bold">{receipt_reference}</span>
                </div>
                <div className="flex justify-between">
                    <span>Date:</span>
                    <span>{created_at}</span>
                </div>
                <div className="flex justify-between">
                    <span>Cashier:</span>
                    <span>{cashier_name}</span>
                </div>
            </div>

            <div className="border-t border-dashed border-slate-300 my-4"></div>

            {/* Items */}
            <div className="mb-6">
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-dashed border-slate-300 text-left">
                            <th className="py-1 font-bold">Item</th>
                            <th className="py-1 font-bold text-right">Qty</th>
                            <th className="py-1 font-bold text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item, idx) => (
                            <React.Fragment key={idx}>
                                <tr className="align-top">
                                    <td className="py-1 pr-2">
                                        <div className="break-words max-w-[45mm]">{item.product_name}</div>
                                        <div className="text-[8px] text-slate-500">{item.sku}</div>
                                    </td>
                                    <td className="py-1 text-right">{Number(item.quantity).toFixed(2)}</td>
                                    <td className="py-1 text-right">{Number(item.line_total).toFixed(2)}</td>
                                </tr>
                                <tr className="text-[8px] text-slate-500 italic">
                                    <td colSpan="3" className="pb-1">
                                        {Number(item.quantity).toFixed(2)} x {Number(item.unit_price).toFixed(2)}
                                        {item.discount_amount > 0 && ` (-${Number(item.discount_amount).toFixed(2)} Disc)`}
                                    </td>
                                </tr>
                            </React.Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="border-t border-dashed border-slate-300 my-4"></div>

            {/* Totals */}
            <div className="space-y-1 mb-6">
                <div className="flex justify-between">
                    <span>Subtotal</span>
                    <span>{Number(totals.subtotal).toFixed(2)}</span>
                </div>
                {totals.discount_total > 0 && (
                    <div className="flex justify-between">
                        <span>Discount</span>
                        <span>-{Number(totals.discount_total).toFixed(2)}</span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span>Tax Total</span>
                    <span>{Number(totals.tax_total).toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-[14px] font-bold border-t border-double border-slate-900 pt-1 mt-1">
                    <span>TOTAL</span>
                    <span>₱{Number(totals.total).toFixed(2)}</span>
                </div>
            </div>

            {/* Payments Summary */}
            <div className="space-y-1 mb-6 border-t border-dashed border-slate-300 pt-4">
                <div className="text-[10px] font-bold uppercase mb-2 tracking-wider opacity-50">Payment Details</div>
                {data.payments && data.payments.map((p, idx) => (
                    <div key={idx} className="space-y-0.5">
                        <div className="flex justify-between">
                            <span className="flex items-center gap-1">
                                {p.method_name}
                                {p.reference_number && <span className="text-[8px] opacity-60">({p.reference_number})</span>}
                            </span>
                            <span>{Number(p.amount).toFixed(2)}</span>
                        </div>
                    </div>
                ))}
                
                {/* Frontend-only Change Display (passed from Index.jsx state) */}
                {data.cash_tendered > 0 && (
                    <div className="pt-2 mt-2 border-t border-dotted border-slate-200">
                        <div className="flex justify-between text-slate-500">
                            <span>Tendered (Cash)</span>
                            <span>{Number(data.cash_tendered).toFixed(2)}</span>
                        </div>
                        <div className="flex justify-between font-bold">
                            <span>Change Due</span>
                            <span>₱{Number(data.change_due).toFixed(2)}</span>
                        </div>
                    </div>
                )}
            </div>

            {/* Footer */}
            {tenant.receipt_footer && (
                <div className="text-center mt-8 border-t border-dashed border-slate-300 pt-4">
                    <div className="text-[10px] italic whitespace-pre-wrap">{tenant.receipt_footer}</div>
                </div>
            )}

            <div className="text-center mt-6 text-[8px] text-slate-400">
                Generated via IPOS Core
            </div>
        </div>
    );
}
