import React from 'react';

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
    const handleCountChange = (value, count) => {
        const newValues = { ...values, [value]: parseInt(count) || 0 };
        onChange(newValues);
    };

    const total = Object.entries(values).reduce((sum, [val, count]) => {
        return sum + (parseFloat(val) * count);
    }, 0);

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {DENOMINATIONS.map((denom) => (
                    <div 
                        key={denom.value}
                        className={`flex items-center justify-between p-4 rounded-xl border transition-all duration-200 ${
                            values[denom.value] > 0 
                                ? 'bg-indigo-50 border-indigo-200 shadow-sm' 
                                : 'bg-white border-gray-100 hover:border-gray-200'
                        }`}
                    >
                        <div className="flex flex-col">
                            <span className="text-sm font-bold text-gray-900">{denom.label}</span>
                            <span className="text-xs text-gray-400 uppercase tracking-wider">{denom.type}</span>
                        </div>
                        <div className="flex items-center space-x-2">
                            <span className="text-xs text-gray-400 font-bold">×</span>
                            <input
                                type="number"
                                min="0"
                                className="w-20 px-2 py-1 text-right bg-white border border-gray-200 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 font-bold"
                                value={values[denom.value] || ''}
                                onChange={(e) => handleCountChange(denom.value, e.target.value)}
                                placeholder="0"
                            />
                        </div>
                    </div>
                ))}
            </div>

            <div className="bg-gray-900 rounded-2xl p-6 flex items-center justify-between shadow-xl">
                <div className="flex items-center space-x-3 text-white">
                    <div className="p-2 bg-white/10 rounded-lg">
                        <span className="text-xl font-bold">₱</span>
                    </div>
                    <div>
                        <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">Calculated Total</p>
                        <p className="text-2xl font-black">{total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                    </div>
                </div>
                <div className="text-right">
                    <p className="text-xs text-gray-400 font-medium">Auto-synced to declaration</p>
                </div>
            </div>
        </div>
    );
}
