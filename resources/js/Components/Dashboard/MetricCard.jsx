import React from 'react';

export default function MetricCard({ title, value, icon: Icon, color = 'blue', subtitle }) {
    const colorClasses = {
        blue: 'text-blue-600 bg-blue-100',
        green: 'text-emerald-600 bg-emerald-100',
        red: 'text-rose-600 bg-rose-100',
        yellow: 'text-amber-600 bg-amber-100',
        purple: 'text-purple-600 bg-purple-100',
    };

    const iconColor = colorClasses[color] || colorClasses.blue;

    return (
        <div className="overflow-hidden bg-white shadow-sm sm:rounded-xl border border-gray-100 hover:shadow-md transition-shadow duration-200">
            <div className="p-4 sm:p-6">
                <div className="flex items-center justify-between gap-4">
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] sm:text-xs font-bold text-gray-500 uppercase tracking-widest truncate">
                            {title}
                        </p>
                        <h3 className="mt-1 text-2xl sm:text-3xl font-extrabold text-gray-900 leading-none truncate">
                            {value}
                        </h3>
                        {subtitle && (
                            <p className="mt-1.5 text-xs text-gray-500 truncate">
                                {subtitle}
                            </p>
                        )}
                    </div>
                    {Icon && (
                        <div className={`p-2.5 sm:p-3 rounded-xl flex-shrink-0 ${iconColor}`}>
                            <Icon size={24} className="sm:w-7 sm:h-7" strokeWidth={2.5} />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
