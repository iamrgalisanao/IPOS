import React from 'react';

export default function StatusCard({ title, icon: Icon, children, footer }) {
    return (
        <div className="flex flex-col h-full overflow-hidden bg-white shadow-sm sm:rounded-xl border border-gray-100 hover:shadow-md transition-shadow duration-200">
            <div className="p-5 border-b border-gray-50 flex items-center justify-between">
                <h4 className="font-semibold text-gray-700 flex items-center gap-2">
                    {Icon && <Icon size={18} className="text-gray-400" />}
                    {title}
                </h4>
            </div>
            <div className="p-5 flex-grow">
                {children}
            </div>
            {footer && (
                <div className="px-5 py-3 bg-gray-50/50 border-t border-gray-50 text-xs text-gray-500">
                    {footer}
                </div>
            )}
        </div>
    );
}
