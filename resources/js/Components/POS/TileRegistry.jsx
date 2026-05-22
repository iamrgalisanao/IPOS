import React, { useState } from 'react';
import { Search, Package, Folder, Plus, Layers, Zap } from 'lucide-react';

export default function TileRegistry({ products, categories, onSelect, activeSelection }) {
    const [search, setSearch] = useState('');
    const [tab, setTab] = useState('products');

    const filteredItems = (tab === 'products' ? products : categories).filter(item => 
        (item.display_name || item.name).toLowerCase().includes(search.toLowerCase())
    );

    return (
        <div className="flex flex-col h-full bg-[#0a0f1d] border-r border-slate-800/60 w-80 shadow-[10px_0_30px_-15px_rgba(0,0,0,0.5)] z-10">
            <div className="p-6 border-b border-slate-800/60 bg-gradient-to-b from-slate-900/50 to-transparent">
                <div className="flex items-center gap-2 mb-4">
                    <div className="p-1.5 bg-indigo-500/20 rounded-lg">
                        <Layers size={16} className="text-indigo-400" />
                    </div>
                    <h3 className="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">Blueprint Registry</h3>
                </div>
                
                <div className="relative group">
                    <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 group-focus-within:text-indigo-400 transition-colors" />
                    <input 
                        type="text" 
                        placeholder="Search register items..."
                        className="w-full bg-slate-900/80 border-slate-800 rounded-[1rem] pl-11 pr-4 py-3 text-sm text-slate-200 placeholder:text-slate-600 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 transition-all border shadow-inner"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
            </div>

            <div className="flex px-4 pt-4 gap-1">
                <button 
                    onClick={() => setTab('products')}
                    className={`flex-1 flex items-center justify-center gap-2 py-3 rounded-t-2xl text-[10px] font-black uppercase tracking-widest transition-all ${
                        tab === 'products' 
                        ? 'text-indigo-400 bg-slate-900 border-x border-t border-slate-800 shadow-[0_-5px_15px_-5px_rgba(0,0,0,0.3)]' 
                        : 'text-slate-500 hover:text-slate-300 hover:bg-slate-900/40'
                    }`}
                >
                    <Package size={14} />
                    Products
                </button>
                <button 
                    onClick={() => setTab('categories')}
                    className={`flex-1 flex items-center justify-center gap-2 py-3 rounded-t-2xl text-[10px] font-black uppercase tracking-widest transition-all ${
                        tab === 'categories' 
                        ? 'text-indigo-400 bg-slate-900 border-x border-t border-slate-800 shadow-[0_-5px_15px_-5px_rgba(0,0,0,0.3)]' 
                        : 'text-slate-500 hover:text-slate-300 hover:bg-slate-900/40'
                    }`}
                >
                    <Folder size={14} />
                    Categories
                </button>
            </div>

            <div className="flex-1 overflow-y-auto bg-slate-900 p-4 space-y-2 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
                {filteredItems.length > 0 ? (
                    filteredItems.map(item => {
                        const id = item.product_id || item.id;
                        const name = item.display_name || item.name;
                        const type = tab === 'products' ? 'product' : 'category';
                        const isActive = activeSelection?.id === id && activeSelection?.type === type;

                        return (
                            <button
                                key={`${tab}-${id}`}
                                onClick={() => onSelect({ id, type, name })}
                                className={`w-full flex items-center gap-4 p-3.5 rounded-2xl transition-all group relative border ${
                                    isActive 
                                    ? 'bg-indigo-600 border-indigo-400 text-white shadow-xl shadow-indigo-600/20' 
                                    : 'bg-slate-800/30 border-slate-800/50 text-slate-400 hover:bg-slate-800 hover:border-slate-700 hover:text-slate-200'
                                }`}
                            >
                                <div className={`p-2.5 rounded-xl transition-colors ${
                                    isActive 
                                    ? 'bg-white/20' 
                                    : 'bg-slate-900 border border-slate-800 group-hover:bg-slate-800'
                                }`}>
                                    {tab === 'products' ? <Package className="w-4 h-4" /> : <Folder className="w-4 h-4" />}
                                </div>
                                <div className="flex-1 text-left min-w-0">
                                    <p className={`text-sm font-bold truncate ${isActive ? 'text-white' : 'text-slate-300'}`}>{name}</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <p className="text-[9px] font-black uppercase tracking-widest opacity-40">
                                            {tab === 'products' ? (item.sku || 'No SKU') : 'Collection'}
                                        </p>
                                        {tab === 'products' && (
                                            <div className="flex items-center gap-1">
                                                <div className="w-1 h-1 rounded-full bg-slate-700" />
                                                <p className="text-[9px] font-bold text-indigo-400/60">₱{Number(item.selling_price || 0).toFixed(2)}</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                                
                                {isActive && (
                                    <div className="absolute right-3 top-1/2 -translate-y-1/2">
                                        <Zap size={14} className="text-white animate-pulse" />
                                    </div>
                                )}
                            </button>
                        );
                    })
                ) : (
                    <div className="flex flex-col items-center justify-center py-12 px-4 text-center opacity-40">
                        <div className="p-4 bg-slate-800 rounded-full mb-4">
                            <Search size={32} />
                        </div>
                        <p className="text-xs font-bold uppercase tracking-widest">No Matches Found</p>
                    </div>
                )}
            </div>
            
            <div className="p-6 bg-slate-950/50 border-t border-slate-800/60">
                <div className="bg-slate-900 rounded-2xl p-4 border border-slate-800">
                    <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 flex items-center gap-2">
                        <Zap size={12} className="text-amber-500" /> Active Brush
                    </p>
                    <p className="text-[11px] text-slate-400 leading-relaxed italic">
                        {activeSelection 
                            ? `Selected: ${activeSelection.name}` 
                            : 'Select a product to begin mapping cells on the grid.'}
                    </p>
                </div>
            </div>
        </div>
    );
}
