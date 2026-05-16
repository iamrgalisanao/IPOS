import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Wallet, Play, AlertCircle, Calculator } from 'lucide-react';
import DenominationGrid from '@/Components/Shift/DenominationGrid';
import { useEffect } from 'react';

export default function Create({ auth, branch_id }) {
    const { data, setData, post, processing, errors } = useForm({
        opening_cash: 0,
        opening_denominations: {},
        notes: '',
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
            opening_denominations: newDenoms,
            opening_cash: total
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('shifts.store'));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Start New Shift</h2>}
        >
            <Head title="Start Shift" />

            <div className="py-12">
                <div className="max-w-5xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                        <div className="p-8">
                            <div className="flex items-center justify-between mb-8">
                                <div className="flex items-center space-x-3">
                                    <div className="p-3 bg-indigo-50 rounded-lg">
                                        <Wallet className="w-6 h-6 text-indigo-600" />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-bold text-gray-900">Shift Opening Balance</h3>
                                        <p className="text-sm text-gray-500 font-medium">Count your starting cash by denomination</p>
                                    </div>
                                </div>
                                <div className="flex items-center space-x-2 text-indigo-600 bg-indigo-50 px-4 py-2 rounded-full">
                                    <Calculator className="w-4 h-4" />
                                    <span className="text-sm font-bold">Auto-Calculating</span>
                                </div>
                            </div>

                            <form onSubmit={submit} className="space-y-8">
                                <div className="space-y-4">
                                    <h4 className="text-sm font-bold text-gray-400 uppercase tracking-widest">Denominations</h4>
                                    <DenominationGrid 
                                        values={data.opening_denominations} 
                                        onChange={handleDenominationChange} 
                                    />
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-8 pt-8 border-t border-gray-100">
                                    <div>
                                        <label htmlFor="notes" className="block text-sm font-semibold text-gray-700 mb-2">
                                            Opening Notes (Optional)
                                        </label>
                                        <textarea
                                            id="notes"
                                            className="block w-full px-4 py-3 bg-gray-50 border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200"
                                            rows="4"
                                            placeholder="Add any details about the drawer state..."
                                            value={data.notes}
                                            onChange={(e) => setData('notes', e.target.value)}
                                        ></textarea>
                                    </div>

                                    <div className="flex flex-col justify-end space-y-6">
                                        <div className="bg-amber-50 border border-amber-100 rounded-xl p-4 flex items-start space-x-3">
                                            <AlertCircle className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                                            <p className="text-sm text-amber-700 leading-relaxed font-medium">
                                                Ensure all cash is accounted for. The system will track these denominations for audit reconciliation during shift closing.
                                            </p>
                                        </div>

                                        <button
                                            type="submit"
                                            disabled={processing || data.opening_cash < 0}
                                            className="w-full inline-flex items-center justify-center px-6 py-4 bg-indigo-600 border border-transparent rounded-xl font-bold text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-all duration-200 shadow-xl shadow-indigo-100 disabled:opacity-50"
                                        >
                                            <Play className="w-5 h-5 mr-2" />
                                            Start Shift with ₱{data.opening_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
