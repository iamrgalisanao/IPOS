import React, { useState, useEffect } from 'react';
import { Printer, X, CheckCircle2, RefreshCw, Sparkles } from 'lucide-react';

/**
 * Receipt component optimized for 80mm thermal printers.
 * Implements a premium BDO-style physical terminal spooling animation when printing.
 */
export default function Receipt({ data, tenantId, branchId, onClose }) {
    if (!data) return null;

    const [printStatus, setPrintStatus] = useState('idle'); // 'idle' | 'printing' | 'completed'
    const [progress, setProgress] = useState(0);
    const [localData, setLocalData] = useState(data);
    const [reprintReason, setReprintReason] = useState('');
    const [showReasonPrompt, setShowReasonPrompt] = useState(false);
    const [isSubmittingReprint, setIsSubmittingReprint] = useState(false);

    useEffect(() => {
        setLocalData(data);
    }, [data]);

    const handlePrint = () => {
        if (printStatus === 'completed' || localData.receipt_print_count >= 1) {
            setShowReasonPrompt(true);
        } else {
            setPrintStatus('printing');
            setProgress(0);
        }
    };

    const handleReprintSubmit = async (e) => {
        e.preventDefault();
        const reason = reprintReason.trim();
        if (!reason) return;

        setIsSubmittingReprint(true);
        try {
            const response = await fetch(`/pos/sales/${localData.sale_id}/receipt?reprint_reason=${encodeURIComponent(reason)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenantId,
                    'X-Branch-ID': branchId
                }
            });

            if (!response.ok) {
                const errData = await response.json();
                alert(errData.message || 'Failed to authorize reprint.');
                setIsSubmittingReprint(false);
                return;
            }

            const updatedData = await response.json();
            // Preserve frontend-only values like cash_tendered and change_due
            setLocalData({
                ...updatedData,
                cash_tendered: localData.cash_tendered,
                change_due: localData.change_due
            });
            setReprintReason('');
            setShowReasonPrompt(false);
            setPrintStatus('printing');
            setProgress(0);
        } catch (error) {
            console.error('Reprint failed:', error);
            alert('Failed to process reprint. Please try again.');
        } finally {
            setIsSubmittingReprint(false);
        }
    };

    // Simulate print head spooling speed and state progression
    useEffect(() => {
        if (printStatus !== 'printing') return;

        const duration = 3200; // 3.2s print time
        const intervalTime = 40;
        const totalSteps = duration / intervalTime;
        let step = 0;

        const timer = setInterval(() => {
            step++;
            const nextProgress = Math.min(100, Math.round((step / totalSteps) * 100));
            setProgress(nextProgress);

            if (nextProgress >= 100) {
                clearInterval(timer);
                // Deliberate 800ms delay to let the receipt exit completely before checking in
                setTimeout(() => {
                    setPrintStatus('completed');
                }, 800);
            }
        }, intervalTime);

        return () => clearInterval(timer);
    }, [printStatus]);

    // Auto-close modal after 2.5s once completed to speed up cashier checkout cycle
    useEffect(() => {
        if (printStatus !== 'completed') return;
        const autoClose = setTimeout(() => {
            onClose();
        }, 2500);
        return () => clearTimeout(autoClose);
    }, [printStatus, onClose]);

    return (
        <div className="receipt-overlay fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 animate-in fade-in duration-200">
            
            {/* Modal Container */}
            <div className="receipt-modal bg-slate-900 border border-slate-800 rounded-3xl shadow-2xl w-full max-w-md flex flex-col max-h-[92vh] overflow-hidden animate-in zoom-in-95 duration-300 relative">
                
                {/* Header */}
                <div className="p-6 border-b border-slate-800 flex items-center justify-between bg-slate-900">
                    <div className="flex items-center gap-3">
                        <div className="p-2.5 bg-indigo-500/10 border border-indigo-500/20 rounded-xl">
                            <Sparkles className="w-5 h-5 text-indigo-400" />
                        </div>
                        <div>
                            <h2 className="text-lg font-black text-white leading-tight">Terminal Printing</h2>
                            <p className="text-xs font-bold text-gray-400 mt-0.5">FOH Receipt Spooler</p>
                        </div>
                    </div>
                    <button 
                        onClick={onClose}
                        className="p-2 bg-slate-800 hover:bg-slate-700 rounded-xl transition-all active:scale-90 text-slate-400 hover:text-slate-200"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Body Content - Displays physical terminal printer housing */}
                <div className="flex-1 bg-slate-950/40 p-8 flex flex-col items-center justify-center overflow-hidden min-h-[440px] relative">
                    
                    {/* Metal Printer Output Bar / Head Slot */}
                    <div className="w-[82mm] h-6 bg-gradient-to-r from-slate-800 via-slate-700 to-slate-800 rounded-t-2xl border-b-[6px] border-slate-950 shadow-md relative z-30 flex items-center justify-between px-4 select-none">
                        <span className="text-[7px] font-black text-slate-400 tracking-wider">IPOS PRINT HEAD</span>
                        <div className="flex items-center gap-1.5">
                            <span className="text-[7px] font-black text-slate-500">ONLINE</span>
                            <div className={`w-2 h-2 rounded-full transition-all duration-300 ${
                                printStatus === 'printing'
                                    ? 'bg-amber-400 shadow-[0_0_8px_rgba(251,191,36,0.8)] animate-pulse'
                                    : printStatus === 'completed'
                                        ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]'
                                        : 'bg-indigo-500 shadow-[0_0_8px_rgba(99,102,241,0.8)]'
                            }`} />
                        </div>
                    </div>

                    {/* Paper Viewing Area */}
                    <div className="relative overflow-hidden w-[82mm] h-[340px] bg-slate-950/70 border border-slate-900 border-t-0 rounded-b-2xl shadow-inner flex justify-center z-10">
                        
                        {/* Thermal Print Head Laser Sweep line (only visible when active printing) */}
                        {printStatus === 'printing' && (
                            <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-amber-400 to-transparent blur-[1px] animate-pulse z-20 pointer-events-none" />
                        )}

                        {/* Scrolling Receipt Paper Spool */}
                        <div className={`transition-all duration-150 ${
                            printStatus === 'printing' 
                                ? 'pointer-events-none'
                                : 'overflow-y-auto scrollbar-hide'
                        }`}>
                            <div 
                                style={{
                                    transform: printStatus === 'printing'
                                        ? `translateY(calc(-${(progress / 100) * 100}% - ${(progress / 100) * 32}px))`
                                        : printStatus === 'completed'
                                            ? 'translateY(calc(-100% - 32px))'
                                            : 'none',
                                    transition: printStatus === 'printing' ? 'transform 0.15s linear' : 'transform 0.8s ease-out'
                                }}
                                className="origin-top"
                            >
                                <ReceiptContent data={localData} />
                            </div>
                        </div>

                        {/* completed Success Banner Overlay */}
                        {printStatus === 'completed' && !showReasonPrompt && (
                            <div className="absolute inset-0 bg-slate-950/90 backdrop-blur-xs z-30 flex flex-col items-center justify-center p-6 text-center animate-in fade-in duration-300">
                                <div className="p-4 bg-emerald-500/20 rounded-full border border-emerald-500/30 animate-bounce">
                                    <CheckCircle2 className="w-12 h-12 text-emerald-400 stroke-[2.5]" />
                                </div>
                                <h3 className="text-lg font-black text-white mt-4 tracking-tight">Receipt Spooled</h3>
                                <p className="text-xs font-semibold text-slate-400 mt-1">Transaction recorded & drawer opened</p>
                                <span className="text-[9px] font-bold text-emerald-400 bg-emerald-950/50 border border-emerald-900/40 px-2.5 py-1 rounded-md mt-4 select-none">
                                    AUTO-CLOSING...
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Reprint Reason Prompt Overlay (Glassmorphic) */}
                    {showReasonPrompt && (
                        <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-md z-40 flex flex-col justify-end p-6 animate-in slide-in-from-bottom duration-300">
                            <form onSubmit={handleReprintSubmit} className="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl w-full flex flex-col gap-4">
                                <div className="flex flex-col gap-1">
                                    <h4 className="text-sm font-black text-rose-400 uppercase tracking-wider">Reprint Authorization</h4>
                                    <p className="text-xs text-slate-400 font-bold">This is a duplicate rendering. Provide a reason to continue.</p>
                                </div>
                                <textarea
                                    value={reprintReason}
                                    onChange={(e) => setReprintReason(e.target.value)}
                                    placeholder="Enter rationale for invoice reprint (e.g. Printer Jam, Customer Request)..."
                                    required
                                    rows={3}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs font-mono text-slate-100 placeholder-slate-600 focus:outline-none focus:border-rose-500/50 focus:ring-1 focus:ring-rose-500/50 transition-all resize-none"
                                />
                                <div className="flex gap-2.5">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowReasonPrompt(false);
                                            setReprintReason('');
                                        }}
                                        className="flex-1 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-all active:scale-95"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={!reprintReason.trim() || isSubmittingReprint}
                                        className="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 disabled:opacity-50 text-white font-extrabold text-xs transition-all shadow-lg shadow-rose-600/10 hover:shadow-rose-600/20 active:scale-95"
                                    >
                                        {isSubmittingReprint ? 'Authorizing...' : 'Authorize Print'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}
                </div>

                {/* Footer Drawer Action Options */}
                <div className="p-6 border-t border-slate-800 flex gap-3 bg-slate-900 relative z-40">
                    <button 
                        onClick={onClose}
                        disabled={printStatus === 'printing'}
                        className="flex-1 px-6 py-3.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold transition-all active:scale-95 disabled:opacity-50 disabled:pointer-events-none"
                    >
                        Close
                    </button>
                    
                    {printStatus === 'printing' ? (
                        <div className="flex-1 bg-slate-800 border border-slate-700 text-slate-300 rounded-2xl flex items-center justify-center gap-3 font-bold select-none px-4">
                            <RefreshCw className="w-4 h-4 text-amber-400 animate-spin" />
                            <span>Printing {progress}%</span>
                        </div>
                    ) : (
                        <button 
                            onClick={handlePrint}
                            className="flex-1 px-6 py-3.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold transition-all shadow-lg shadow-indigo-600/10 hover:shadow-indigo-600/20 flex items-center justify-center gap-2 active:scale-95"
                        >
                            <Printer className="w-5 h-5 stroke-[2.5]" />
                            {printStatus === 'completed' || localData.receipt_print_count >= 1 ? 'Reprint Receipt' : 'Print Receipt'}
                        </button>
                    )}
                </div>
            </div>
            
            {/* Custom Spooler Scrollbar Suppression CSS */}
            <style dangerouslySetInnerHTML={{ __html: `
                .scrollbar-hide::-webkit-scrollbar {
                    display: none;
                }
                .scrollbar-hide {
                    -ms-overflow-style: none;
                    scrollbar-width: none;
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
            font-mono text-[12px] leading-tight select-none
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

            {data.is_reprint && (
                <div className="text-center my-3 p-2 border border-rose-500 bg-rose-50 border-dashed text-rose-600 font-bold uppercase tracking-wider text-[11px]">
                    {data.reprint_watermark || '*** REPRINT / DUPLICATE ***'}
                    {data.last_reprint_reason && (
                        <div className="text-[9px] text-rose-500 mt-1 italic font-normal">
                            Reason: {data.last_reprint_reason}
                        </div>
                    )}
                </div>
            )}

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
                {data.contains_statutory_discount && data.statutory_discount && (
                    <>
                        <div className="flex justify-between text-[10px] text-slate-500 italic">
                            <span>Less: VAT Exempt</span>
                            <span>-{Number(data.statutory_discount.vat_exempt_amount).toFixed(2)}</span>
                        </div>
                        <div className="flex justify-between font-bold text-emerald-700">
                            <span>{data.statutory_discount.discount_type?.name || 'Statutory Discount'}</span>
                            <span>-{Number(data.statutory_discount.discount_amount).toFixed(2)}</span>
                        </div>
                        {data.statutory_discount.beneficiaries && data.statutory_discount.beneficiaries.length > 0 && (
                            <div className="text-[9px] text-slate-600 italic mt-1 border-t border-dotted border-slate-300 pt-1">
                                {data.statutory_discount.beneficiaries.map((b, idx) => (
                                    <div key={idx} className="flex justify-between">
                                        <span>Beneficiary: {b.beneficiary_name}{b.id_number ? ` (ID: ${b.id_number})` : ''}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                )}
                {totals.discount_total > 0 && (!data.contains_statutory_discount || totals.discount_total > Number(data.statutory_discount?.discount_amount || 0)) && (
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

            {data.is_reprint && (
                <div className="text-center my-3 p-2 border border-rose-500 bg-rose-50 border-dashed text-rose-600 font-bold uppercase tracking-wider text-[11px]">
                    {data.reprint_watermark || '*** REPRINT / DUPLICATE ***'}
                    {data.last_reprint_reason && (
                        <div className="text-[9px] text-rose-500 mt-1 italic font-normal">
                            Reason: {data.last_reprint_reason}
                        </div>
                    )}
                </div>
            )}

            <div className="text-center mt-6 text-[8px] text-slate-400">
                Generated via IPOS Core
            </div>
        </div>
    );
}
