import React from 'react';
import { Search, ScanBarcode, Loader2 } from 'lucide-react';

export default function SearchBar({ value, onChange, onScan, loading, disabled }) {
    return (
        <div className={`relative group ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}>
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                {loading ? (
                    <Loader2 className="h-5 w-5 text-indigo-400 animate-spin" />
                ) : (
                    <Search className={`h-5 w-5 ${disabled ? 'text-slate-600' : 'text-slate-500 group-focus-within:text-indigo-400'} transition-colors`} />
                )}
            </div>
            <input
                type="text"
                value={value}
                onChange={(e) => !disabled && onChange(e.target.value)}
                placeholder={disabled ? "Open shift to search products..." : "Search name, SKU, or scan barcode..."}
                disabled={disabled}
                aria-disabled={disabled}
                className={`block w-full pl-10 pr-14 py-2.5 bg-slate-800 border rounded-xl leading-5 text-slate-200 placeholder-slate-550 focus:outline-none sm:text-sm transition-all ${
                    disabled 
                        ? 'border-slate-800 bg-slate-850 cursor-not-allowed text-slate-500' 
                        : 'border-slate-700 focus:ring-2 focus:ring-indigo-500 focus:border-transparent'
                }`}
                autoFocus={!disabled}
            />
            <div className="absolute inset-y-0 right-0 pr-2 flex items-center">
                <div className="h-6 w-px bg-slate-700 mr-2"></div>
                <button
                    type="button"
                    disabled={disabled}
                    aria-disabled={disabled}
                    className={`p-1.5 rounded-lg transition-colors border border-transparent ${
                        disabled
                            ? 'text-slate-600 bg-transparent cursor-not-allowed'
                            : 'bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-indigo-400 focus:outline-none hover:border-slate-600'
                    }`}
                    title={disabled ? "Disabled until shift is open" : "Simulate Barcode Scan"}
                    onClick={() => {
                        if (disabled) return;
                        // For demo/dev purposes, we can trigger a manual scan logic
                        const mockBarcode = prompt('Enter barcode to simulate scan:');
                        if (mockBarcode) onScan(mockBarcode);
                    }}
                >
                    <ScanBarcode className="h-5 w-5" />
                </button>
            </div>
        </div>
    );
}
