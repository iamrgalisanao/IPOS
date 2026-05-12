import React from 'react';
import { router } from '@inertiajs/react';
import { Store, Globe } from 'lucide-react';

export default function BranchSelector({ branches, currentBranchId, hasMultiBranchPermission }) {
    const handleBranchChange = (e) => {
        const branchId = e.target.value;
        router.get(route('dashboard'), { branch_id: branchId || undefined }, { 
            preserveState: true,
            preserveScroll: true 
        });
    };

    if (!hasMultiBranchPermission && branches.length <= 1) {
        return null;
    }

    return (
        <div className="flex items-center gap-2 w-full sm:w-auto">
            <div className="relative w-full sm:w-auto">
                <select
                    value={currentBranchId || ''}
                    onChange={handleBranchChange}
                    className="w-full sm:w-auto pl-9 pr-10 py-2 sm:py-1.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 appearance-none font-medium text-gray-700 shadow-sm transition-all"
                >
                    {hasMultiBranchPermission && (
                        <option value="">All Branches (Tenant Wide)</option>
                    )}
                    {branches.map((branch) => (
                        <option key={branch.id} value={branch.id}>
                            {branch.name}
                        </option>
                    ))}
                </select>
                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                    {currentBranchId ? <Store size={14} /> : <Globe size={14} />}
                </div>
                <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
                    <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>
            </div>
        </div>
    );
}
