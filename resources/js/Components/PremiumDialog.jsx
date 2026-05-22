import React from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { AlertTriangle, CheckCircle, HelpCircle, Info, X } from 'lucide-react';

export default function PremiumDialog({
    isOpen = false,
    type = 'warning', // 'info', 'success', 'warning', 'danger'
    title = 'Are you sure?',
    message = 'Please confirm this action.',
    confirmLabel = 'Proceed',
    cancelLabel = 'Cancel',
    isAlert = false,
    onConfirm = () => {},
    onCancel = () => {},
}) {
    const icons = {
        info: <Info className="h-6 w-6 text-indigo-600" />,
        success: <CheckCircle className="h-6 w-6 text-emerald-600" />,
        warning: <HelpCircle className="h-6 w-6 text-amber-600" />,
        danger: <AlertTriangle className="h-6 w-6 text-rose-600" />,
    };

    const colors = {
        info: {
            bg: 'bg-indigo-50',
            border: 'border-indigo-100',
            btn: 'bg-indigo-600 hover:bg-indigo-500 hover:shadow-indigo-600/10 focus:ring-indigo-500',
        },
        success: {
            bg: 'bg-emerald-50',
            border: 'border-emerald-100',
            btn: 'bg-emerald-600 hover:bg-emerald-500 hover:shadow-emerald-600/10 focus:ring-emerald-500',
        },
        warning: {
            bg: 'bg-amber-50',
            border: 'border-amber-100',
            btn: 'bg-amber-600 hover:bg-amber-500 hover:shadow-amber-600/10 focus:ring-amber-500',
        },
        danger: {
            bg: 'bg-rose-50',
            border: 'border-rose-100',
            btn: 'bg-rose-600 hover:bg-rose-500 hover:shadow-rose-600/10 focus:ring-rose-500',
        },
    };

    return (
        <Transition show={isOpen} as={React.Fragment}>
            <Dialog as="div" className="relative z-[100]" onClose={onCancel}>
                {/* Backdrop with elegant blur */}
                <TransitionChild
                    as={React.Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" />
                </TransitionChild>

                <div className="fixed inset-0 z-10 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
                        <TransitionChild
                            as={React.Fragment}
                            enter="ease-out duration-300"
                            enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            enterTo="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200"
                            leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                            leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel className="relative transform overflow-hidden rounded-[2rem] bg-white border border-slate-100 p-8 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md">
                                {/* Close Button */}
                                <button
                                    type="button"
                                    onClick={onCancel}
                                    className="absolute right-6 top-6 rounded-lg p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-50 transition-all"
                                >
                                    <X size={16} />
                                </button>

                                <div className="flex items-start gap-4">
                                    {/* Beautiful type-specific badge */}
                                    <div className={`flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl border ${colors[type].bg} ${colors[type].border}`}>
                                        {icons[type]}
                                    </div>

                                    <div className="mt-0.5 flex-1">
                                        <h3 className="text-lg font-black text-slate-800 leading-tight tracking-tight">
                                            {title}
                                        </h3>
                                        <p className="mt-2 text-sm text-slate-500 font-medium leading-relaxed">
                                            {message}
                                        </p>
                                    </div>
                                </div>

                                {/* Dialog Actions */}
                                <div className="mt-8 flex items-center justify-end gap-3">
                                    {!isAlert && (
                                        <button
                                            type="button"
                                            onClick={onCancel}
                                            className="inline-flex items-center justify-center px-5 py-2.5 bg-slate-50 hover:bg-slate-100 text-slate-600 hover:text-slate-800 rounded-xl font-bold text-xs uppercase tracking-widest transition-all"
                                        >
                                            {cancelLabel}
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={onConfirm}
                                        className={`inline-flex items-center justify-center px-5 py-2.5 text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 ${colors[type].btn}`}
                                    >
                                        {confirmLabel}
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
