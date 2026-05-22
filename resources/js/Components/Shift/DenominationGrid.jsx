import React, { useState, useEffect } from 'react';
import { Calculator, Wallet } from 'lucide-react';

const DENOMINATIONS = [
    { value: 1000, label: '₱1,000 Bill', type: 'bill' },
    { value: 500, label: '₱500 Bill', type: 'bill' },
    { value: 200, label: '₱200 Bill', type: 'bill' },
    { value: 100, label: '₱100 Bill', type: 'bill' },
    { value: 50, label: '₱50 Bill', type: 'bill' },
    { value: 20, label: '₱20 Bill/Coin', type: 'bill' },
    { value: 10, label: '₱10 Coin', type: 'coin' },
    { value: 5, label: '₱5 Coin', type: 'coin' },
    { value: 1, label: '₱1 Coin', type: 'coin' },
];

export default function DenominationGrid({ values = {}, onChange }) {
    const [activeDenomIndex, setActiveDenomIndex] = useState(0);

    const handleCountChange = (value, count) => {
        const newValues = { ...values, [value]: Math.max(0, parseInt(count) || 0) };
        onChange(newValues);
    };

    const handleKeypadInput = (num) => {
        const activeDenom = DENOMINATIONS[activeDenomIndex];
        if (!activeDenom) return;

        const currentCount = values[activeDenom.value] || 0;
        let newCount;
        if (currentCount === 0) {
            newCount = parseInt(num);
        } else {
            const nextStr = currentCount.toString() + num;
            // Limit count to 4 digits (max 9999) to keep layout safe
            if (nextStr.length > 4) return;
            newCount = parseInt(nextStr);
        }

        handleCountChange(activeDenom.value, newCount);
    };

    const handleKeypadAction = (action) => {
        const activeDenom = DENOMINATIONS[activeDenomIndex];
        if (!activeDenom) return;

        if (action === 'clear') {
            handleCountChange(activeDenom.value, 0);
        } else if (action === 'next') {
            const nextIndex = (activeDenomIndex + 1) % DENOMINATIONS.length;
            setActiveDenomIndex(nextIndex);
        }
    };

    // Synchronize physical keyboard inputs with the virtual keypad controls
    useEffect(() => {
        const handleKeyDown = (e) => {
            const activeDenom = DENOMINATIONS[activeDenomIndex];
            if (!activeDenom) return;

            // Intercept numeric entry keys
            if (e.key >= '0' && e.key <= '9') {
                e.preventDefault();
                handleKeypadInput(e.key);
            } 
            // Intercept Backspace (destructive single digit deletion)
            else if (e.key === 'Backspace') {
                e.preventDefault();
                const current = (values[activeDenom.value] || 0).toString();
                const nextVal = current.length > 1 ? parseInt(current.slice(0, -1)) : 0;
                handleCountChange(activeDenom.value, nextVal);
            } 
            // Intercept Enter/Tab to advance to next denomination row
            else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                const nextIndex = (activeDenomIndex + 1) % DENOMINATIONS.length;
                setActiveDenomIndex(nextIndex);
            } 
            // Intercept Clear commands
            else if (e.key === 'Escape' || e.key.toLowerCase() === 'c') {
                e.preventDefault();
                handleCountChange(activeDenom.value, 0);
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [activeDenomIndex, values]);

    const total = Object.entries(values).reduce((sum, [val, count]) => {
        return sum + (parseFloat(val) * count);
    }, 0);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-5 gap-8 min-h-[500px]">
            
            {/* Left Side: Denomination List (3 Columns relative space) */}
            <div className="lg:col-span-3 flex flex-col justify-between bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-gray-50 border-b border-gray-100 text-gray-400 text-[10px] font-black uppercase tracking-wider">
                                <th className="py-4 px-6">Denomination</th>
                                <th className="py-4 px-4 text-center">Count</th>
                                <th className="py-4 px-6 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {DENOMINATIONS.map((denom, index) => {
                                const countValue = values[denom.value] || 0;
                                const subtotal = denom.value * countValue;
                                const isActive = index === activeDenomIndex;

                                return (
                                    <tr 
                                        key={denom.value}
                                        onClick={() => setActiveDenomIndex(index)}
                                        className={`group cursor-pointer transition-all duration-150 ${
                                            isActive 
                                                ? 'bg-indigo-50/50' 
                                                : 'hover:bg-gray-50/50'
                                        }`}
                                    >
                                        {/* Denomination Value and Description */}
                                        <td className="py-4 px-6">
                                            <div className="flex flex-col">
                                                <span className={`text-sm font-bold transition-colors ${
                                                    isActive ? 'text-indigo-900' : 'text-gray-900'
                                                }`}>
                                                    {denom.label}
                                                </span>
                                                <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">
                                                    {denom.type}
                                                </span>
                                            </div>
                                        </td>

                                        {/* Custom Count Pill Target */}
                                        <td className="py-2 px-4 text-center">
                                            <div className="inline-flex justify-center w-full">
                                                <div className={`px-4 py-2 w-20 rounded-xl border text-center text-sm font-black transition-all select-none ${
                                                    isActive 
                                                        ? 'bg-white border-indigo-500 text-indigo-600 shadow-sm scale-105 ring-2 ring-indigo-500/10'
                                                        : countValue > 0
                                                            ? 'bg-gray-50 border-gray-200 text-gray-700'
                                                            : 'bg-white border-gray-100 text-gray-300 group-hover:border-gray-200'
                                                }`}>
                                                    {countValue || '0'}
                                                </div>
                                            </div>
                                        </td>

                                        {/* Row Subtotal */}
                                        <td className="py-4 px-6 text-right">
                                            <span className={`text-sm font-extrabold font-mono tracking-tight ${
                                                isActive ? 'text-indigo-700' : 'text-gray-900'
                                            }`}>
                                                {subtotal > 0 
                                                    ? `₱${subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                                                    : '—'
                                                }
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {/* Footer Total bar inside the table container */}
                <div className="bg-slate-900 p-6 flex items-center justify-between border-t border-slate-800">
                    <span className="text-sm font-bold text-slate-400 uppercase tracking-widest">
                        Grand Total:
                    </span>
                    <span className="text-2xl font-black text-white font-mono">
                        ₱{total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </span>
                </div>
            </div>

            {/* Right Side: Keyboard Numeric Pad & Summary (2 Columns relative space) */}
            <div className="lg:col-span-2 flex flex-col space-y-6">
                
                {/* Active Denomination Highlight details */}
                <div className="bg-slate-950 rounded-2xl p-6 border border-slate-800 text-white flex flex-col justify-between relative overflow-hidden">
                    <div className="absolute right-4 top-4 opacity-10">
                        <Wallet className="w-24 h-24 stroke-[1]" />
                    </div>
                    
                    <div className="space-y-1.5 relative z-10">
                        <span className="text-[10px] font-bold text-indigo-400 uppercase tracking-widest">
                            Editing Count For
                        </span>
                        <h4 className="text-xl font-black tracking-tight">
                            {DENOMINATIONS[activeDenomIndex]?.label}
                        </h4>
                    </div>

                    <div className="mt-8 pt-4 border-t border-slate-900 flex justify-between items-baseline relative z-10">
                        <span className="text-xs font-bold text-slate-400">Declared Subtotal</span>
                        <span className="text-2xl font-black text-indigo-300 font-mono">
                            ₱{((values[DENOMINATIONS[activeDenomIndex]?.value] || 0) * DENOMINATIONS[activeDenomIndex]?.value).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                        </span>
                    </div>
                </div>

                {/* Grid Touch Pad Keyboard */}
                <div className="bg-white rounded-3xl border border-gray-100 p-6 shadow-sm flex flex-col space-y-4">
                    <div className="grid grid-cols-3 gap-3">
                        {[1, 2, 3, 4, 5, 6, 7, 8, 9].map(num => (
                            <button
                                key={num}
                                type="button"
                                onClick={() => handleKeypadInput(num.toString())}
                                className="h-16 bg-gray-50 hover:bg-slate-100 active:scale-95 text-slate-800 font-extrabold text-xl rounded-2xl flex items-center justify-center transition-all border border-gray-100/50 shadow-sm cursor-pointer select-none"
                            >
                                {num}
                            </button>
                        ))}

                        {/* C Clear Button */}
                        <button
                            type="button"
                            onClick={() => handleKeypadAction('clear')}
                            className="h-16 bg-rose-50 hover:bg-rose-100 active:scale-95 text-rose-600 font-extrabold text-lg rounded-2xl flex items-center justify-center transition-all border border-rose-100/20 cursor-pointer select-none"
                        >
                            C
                        </button>

                        {/* 0 Button */}
                        <button
                            type="button"
                            onClick={() => handleKeypadInput('0')}
                            className="h-16 bg-gray-50 hover:bg-slate-100 active:scale-95 text-slate-800 font-extrabold text-xl rounded-2xl flex items-center justify-center transition-all border border-gray-100/50 shadow-sm cursor-pointer select-none"
                        >
                            0
                        </button>

                        {/* Next Navigation Button */}
                        <button
                            type="button"
                            onClick={() => handleKeypadAction('next')}
                            className="h-16 bg-indigo-600 hover:bg-indigo-500 active:scale-95 text-white font-extrabold text-lg rounded-2xl flex items-center justify-center transition-all shadow-lg shadow-indigo-600/10 cursor-pointer select-none"
                        >
                            Next
                        </button>
                    </div>

                    <div className="text-center">
                        <span className="text-[10px] text-gray-400 font-semibold tracking-wider uppercase">
                            Supports physical numpad & Enter/Tab keys
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}
