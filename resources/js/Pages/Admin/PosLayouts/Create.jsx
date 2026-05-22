import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { 
    LayoutGrid, 
    ArrowLeft, 
    Sparkles,
    CheckCircle2
} from 'lucide-react';

export default function Create({ auth }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.pos-layouts.store'));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-4">
                    <Link 
                        href={route('admin.pos-layouts.index')}
                        className="p-2.5 rounded-2xl bg-white border border-slate-200 text-slate-400 hover:text-slate-600 hover:border-slate-300 transition-all shadow-sm"
                    >
                        <ArrowLeft size={20} />
                    </Link>
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Create POS Layout</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Initialize a new register architecture.</p>
                    </div>
                </div>
            }
        >
            <Head title="Create POS Layout" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="max-w-2xl mx-auto">
                        <div className="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden">
                            <div className="bg-indigo-600 p-12 text-white relative overflow-hidden">
                                <div className="relative z-10">
                                    <div className="p-4 bg-white/20 backdrop-blur-md rounded-3xl w-fit mb-6">
                                        <LayoutGrid size={32} />
                                    </div>
                                    <h3 className="text-3xl font-black tracking-tight mb-2">New Layout Draft</h3>
                                    <p className="text-indigo-100 font-medium opacity-90 leading-relaxed">
                                        Give your layout a name. You'll be able to design the visual grid and assign it to branches in the next step.
                                    </p>
                                </div>
                                {/* Decorative elements */}
                                <div className="absolute -top-24 -right-24 w-64 h-64 bg-indigo-500 rounded-full blur-3xl opacity-50" />
                                <div className="absolute -bottom-24 -left-24 w-64 h-64 bg-white rounded-full blur-3xl opacity-10" />
                            </div>

                            <form onSubmit={submit} className="p-12 space-y-8">
                                <div className="space-y-4">
                                    <label htmlFor="name" className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 ml-1">
                                        Layout Identity
                                    </label>
                                    <div className="relative">
                                        <input
                                            id="name"
                                            type="text"
                                            name="name"
                                            value={data.name}
                                            className="w-full px-6 py-4 bg-slate-50 border-transparent rounded-[1.5rem] text-lg font-bold text-slate-800 placeholder:text-slate-300 focus:bg-white focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                            autoFocus
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="e.g., Q4 Festive Grid v1"
                                            required
                                        />
                                        <div className="absolute right-6 top-1/2 -translate-y-1/2 text-slate-200">
                                            <Sparkles size={24} />
                                        </div>
                                    </div>
                                    {errors.name && <p className="text-rose-500 text-xs font-bold ml-1">{errors.name}</p>}
                                </div>

                                <div className="space-y-4 bg-slate-50 p-6 rounded-[2rem] border border-slate-100">
                                    <h4 className="text-[10px] font-black uppercase tracking-widest text-slate-400 flex items-center gap-2">
                                        <CheckCircle2 size={14} className="text-indigo-500" /> What's Next?
                                    </h4>
                                    <ul className="space-y-3">
                                        {[
                                            'Design a 4x4 or custom sized grid',
                                            'Drag and drop products onto tiles',
                                            'Preview the cashier experience live',
                                            'Deploy to specific branch locations'
                                        ].map((step, i) => (
                                            <li key={i} className="flex items-center gap-3 text-sm text-slate-600 font-medium">
                                                <div className="w-1.5 h-1.5 rounded-full bg-indigo-400" />
                                                {step}
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                <div className="flex items-center gap-4 pt-4">
                                    <button 
                                        type="submit"
                                        disabled={processing}
                                        className="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white py-4 px-8 rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-indigo-600/20 transition-all active:scale-[0.98] disabled:opacity-50"
                                    >
                                        {processing ? 'Initializing...' : 'Initialize Grid Architecture'}
                                    </button>
                                    <Link
                                        href={route('admin.pos-layouts.index')}
                                        className="px-8 py-4 bg-white border border-slate-200 text-slate-500 rounded-2xl font-black text-sm uppercase tracking-widest hover:bg-slate-50 transition-all"
                                    >
                                        Cancel
                                    </Link>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
