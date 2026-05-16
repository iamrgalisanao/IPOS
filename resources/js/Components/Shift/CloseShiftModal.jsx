import React from 'react';
import { useForm } from '@inertiajs/react';
import DenominationGrid from '@/Components/Shift/DenominationGrid';
import { X, Save, AlertCircle } from 'lucide-react';

export default function CloseShiftModal({ shift, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        actual_cash: 0,
        closing_denominations: {},
        closing_notes: '',
    });

    const calculateTotal = (denoms) => {
        return Object.entries(denoms).reduce((sum, [val, count]) => {
            return sum + (parseFloat(val) * count);
        }, 0);
    };

    const handleDenominationChange = (newDenoms) => {
        const total = calculateTotal(newDenoms);
        setData((prev) => ({
            ...prev,
            closing_denominations: newDenoms,
            actual_cash: total
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('shifts.submit-closing', shift.id), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div className="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div className="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>

                <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div className="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                    <div className="bg-white px-4 pt-5 pb-4 sm:p-8 sm:pb-4">
                        <div className="flex items-center justify-between mb-6">
                            <h3 className="text-xl font-bold text-gray-900">Close Shift & Final Count</h3>
                            <button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-full transition-colors">
                                <X size={20} className="text-gray-400" />
                            </button>
                        </div>

                        <form onSubmit={submit} className="space-y-8">
                            <div className="space-y-4">
                                <h4 className="text-sm font-bold text-gray-400 uppercase tracking-widest">Closing Denominations</h4>
                                <DenominationGrid 
                                    values={data.closing_denominations} 
                                    onChange={handleDenominationChange} 
                                />
                            </div>

                            <div className="space-y-4 pt-6 border-t border-gray-100">
                                <div>
                                    <label htmlFor="closing_notes" className="block text-sm font-semibold text-gray-700 mb-2">
                                        Closing Notes (Optional)
                                    </label>
                                    <textarea
                                        id="closing_notes"
                                        className="block w-full px-4 py-3 bg-gray-50 border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200"
                                        rows="3"
                                        placeholder="Add any details about today's collection or variances..."
                                        value={data.closing_notes}
                                        onChange={(e) => setData('closing_notes', e.target.value)}
                                    ></textarea>
                                </div>
                            </div>

                            <div className="bg-amber-50 border border-amber-100 rounded-xl p-4 flex items-start space-x-3 mt-4">
                                <AlertCircle className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                                <p className="text-sm text-amber-700 font-medium">
                                    Once submitted, the shift will be locked for manager review. Ensure your count is accurate.
                                </p>
                            </div>

                            <div className="mt-8 flex flex-col sm:flex-row-reverse gap-3 pb-4">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3 bg-indigo-600 border border-transparent rounded-xl font-bold text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-all duration-200 shadow-lg shadow-indigo-100 disabled:opacity-50"
                                >
                                    <Save className="w-4 h-4 mr-2" />
                                    Submit Closing (₱{data.actual_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })})
                                </button>
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3 bg-white border border-gray-200 rounded-xl font-bold text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none transition-all duration-200"
                                >
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );
}
