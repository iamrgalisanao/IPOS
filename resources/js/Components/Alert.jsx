import React from 'react';
import { AlertCircle } from 'lucide-react';

export function Alert({ children, variant = 'default', className = '' }) {
    const baseClasses = 'rounded-md p-4 border';
    const variantClasses = {
        default: 'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-900 dark:border-blue-700 dark:text-blue-100',
        destructive: 'bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-900 dark:border-rose-700 dark:text-rose-100',
        warning: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-900 dark:border-amber-700 dark:text-amber-100',
        success: 'bg-green-50 border-green-200 text-green-900 dark:bg-green-900 dark:border-green-700 dark:text-green-100',
    };

    return (
        <div className={`${baseClasses} ${variantClasses[variant] || variantClasses.default} ${className}`}>
            {children}
        </div>
    );
}

export function AlertDescription({ children, className = '' }) {
    return (
        <div className={`text-sm font-medium ${className}`}>
            {children}
        </div>
    );
}

export function AlertTitle({ children, className = '' }) {
    return (
        <h5 className={`mb-1 font-semibold ${className}`}>
            {children}
        </h5>
    );
}
