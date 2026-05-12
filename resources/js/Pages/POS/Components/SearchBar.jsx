import React from 'react';
import { Search, ScanBarcode, Loader2 } from 'lucide-react';

export default function SearchBar({ value, onChange, onScan, loading }) {
    return (
        <div className="relative group">
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                {loading ? (
                    <Loader2 className="h-5 w-5 text-indigo-400 animate-spin" />
                ) : (
                    <Search className="h-5 w-5 text-slate-500 group-focus-within:text-indigo-400 transition-colors" />
                )}
            </div>
            <input
                type="text"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Search name, SKU, or scan barcode..."
                className="block w-full pl-10 pr-14 py-2.5 bg-slate-800 border border-slate-700 rounded-xl leading-5 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent sm:text-sm transition-all"
                autoFocus
            />
            <div className="absolute inset-y-0 right-0 pr-2 flex items-center">
                <div className="h-6 w-px bg-slate-700 mr-2"></div>
                <button
                    type="button"
                    className="p-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-400 hover:text-indigo-400 focus:outline-none transition-colors border border-transparent hover:border-slate-600"
                    title="Simulate Barcode Scan"
                    onClick={() => {
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
