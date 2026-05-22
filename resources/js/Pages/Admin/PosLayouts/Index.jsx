import React from 'react';
import { Link, Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { 
    LayoutGrid, 
    Plus, 
    Clock, 
    CheckCircle2, 
    FileText, 
    ChevronRight,
    Monitor,
    MoreHorizontal,
    Layers
} from 'lucide-react';

export default function Index({ auth, layouts }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">POS Register Grids</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Design and manage custom button layouts for your branch terminals.</p>
                    </div>
                    <Link
                        href={route('admin.pos-layouts.create')}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                    >
                        <Plus size={18} />
                        New Layout
                    </Link>
                </div>
            }
        >
            <Head title="POS Layouts" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Summary Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl">
                                <Layers size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Layouts</p>
                                <p className="text-2xl font-black text-slate-800">{layouts.length}</p>
                            </div>
                        </div>
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-emerald-50 text-emerald-600 rounded-2xl">
                                <CheckCircle2 size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Published</p>
                                <p className="text-2xl font-black text-slate-800">
                                    {layouts.filter(l => l.status === 'published').length}
                                </p>
                            </div>
                        </div>
                        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-4 hover:shadow-md transition-all">
                            <div className="p-4 bg-amber-50 text-amber-600 rounded-2xl">
                                <FileText size={24} />
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Drafts</p>
                                <p className="text-2xl font-black text-slate-800">
                                    {layouts.filter(l => l.status === 'draft').length}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col gap-6">
                        <div className="flex items-center justify-between px-2">
                            <h3 className="text-sm font-black text-slate-400 uppercase tracking-widest">Active Inventory</h3>
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-slate-400 font-medium italic">Sorting by Latest v.</span>
                                <div className="h-4 w-px bg-slate-200" />
                                <button className="p-2 text-slate-400 hover:text-slate-600">
                                    <MoreHorizontal size={20} />
                                </button>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {layouts.map((layout) => (
                                <div 
                                    key={layout.id} 
                                    className="group relative bg-white rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-1 transition-all duration-300 overflow-hidden flex flex-col"
                                >
                                    <div className="p-8">
                                        <div className="flex items-start justify-between mb-6">
                                            <div className={`p-4 rounded-3xl ${
                                                layout.status === 'published' 
                                                ? 'bg-emerald-50 text-emerald-600' 
                                                : 'bg-indigo-50 text-indigo-600'
                                            }`}>
                                                <LayoutGrid size={28} />
                                            </div>
                                            <div className="flex flex-col items-end gap-2">
                                                <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                    layout.status === 'published' 
                                                    ? 'bg-emerald-50 text-emerald-600 border-emerald-100' 
                                                    : layout.status === 'draft'
                                                    ? 'bg-amber-50 text-amber-600 border-amber-100'
                                                    : 'bg-slate-50 text-slate-600 border-slate-100'
                                                }`}>
                                                    {layout.status}
                                                </span>
                                                <span className="text-xs font-bold text-slate-400">v{layout.version}</span>
                                            </div>
                                        </div>

                                        <h4 className="text-xl font-extrabold text-slate-800 mb-2 group-hover:text-indigo-600 transition-colors">
                                            {layout.name}
                                        </h4>
                                        <p className="text-sm text-slate-500 font-medium leading-relaxed line-clamp-2">
                                            Configured with {layout.schema?.tiles?.length || 0} tiles across a {layout.schema?.grid?.columns || 0}x{layout.schema?.grid?.rows || 0} grid.
                                        </p>
                                    </div>

                                    <div className="mt-auto px-8 pb-8 flex items-center justify-between border-t border-slate-50 pt-6">
                                        <div className="flex items-center gap-2 text-slate-400">
                                            <Clock size={14} />
                                            <span className="text-[10px] font-bold uppercase tracking-tighter">Updated 2h ago</span>
                                        </div>
                                        
                                        <Link
                                            href={route('admin.pos-layouts.show', layout.id)}
                                            className="inline-flex items-center gap-2 px-6 py-2.5 bg-slate-50 hover:bg-indigo-600 text-slate-600 hover:text-white rounded-2xl font-black text-xs uppercase tracking-widest transition-all shadow-sm"
                                        >
                                            {layout.status === 'draft' ? 'Edit Grid' : 'View Hub'}
                                            <ChevronRight size={14} />
                                        </Link>
                                    </div>

                                    {/* Subtle pattern or indicator for active layouts */}
                                    {layout.status === 'published' && (
                                        <div className="absolute top-0 right-0 p-2">
                                            <div className="bg-emerald-500 w-2 h-2 rounded-full animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.6)]" />
                                        </div>
                                    )}
                                </div>
                            ))}

                            {/* Create New Card */}
                            <Link
                                href={route('admin.pos-layouts.create')}
                                className="group flex flex-col items-center justify-center p-12 rounded-[2.5rem] border-2 border-dashed border-slate-200 hover:border-indigo-400 hover:bg-indigo-50/30 transition-all duration-300"
                            >
                                <div className="p-5 bg-slate-50 group-hover:bg-indigo-100 rounded-3xl transition-colors mb-4">
                                    <Plus size={32} className="text-slate-400 group-hover:text-indigo-600" />
                                </div>
                                <span className="text-sm font-black text-slate-400 group-hover:text-indigo-600 uppercase tracking-widest">New Architecture</span>
                            </Link>
                        </div>

                        {layouts.length === 0 && (
                            <div className="bg-white rounded-[2.5rem] border border-slate-100 p-20 text-center shadow-sm">
                                <div className="max-w-md mx-auto">
                                    <div className="p-6 bg-slate-50 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-6">
                                        <Monitor size={48} className="text-slate-300" />
                                    </div>
                                    <h3 className="text-xl font-black text-slate-800 mb-2">No active layouts found</h3>
                                    <p className="text-slate-500 font-medium mb-8">Start by creating your first register layout to optimize your checkout speed.</p>
                                    <Link
                                        href={route('admin.pos-layouts.create')}
                                        className="inline-flex items-center gap-3 px-8 py-4 bg-indigo-600 text-white rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-indigo-600/20 hover:bg-indigo-500 transition-all"
                                    >
                                        <Plus size={20} />
                                        Initiate First Grid
                                    </Link>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
