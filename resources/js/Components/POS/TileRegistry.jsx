import React, { useState } from 'react';
import { Search, Package, Folder, Plus } from 'lucide-react';

export default function TileRegistry({ products, categories, onSelect, activeSelection }) {
    const [search, setSearch] = useState('');
    const [tab, setTab] = useState('products');

    const filteredItems = (tab === 'products' ? products : categories).filter(item => 
        (item.display_name || item.name).toLowerCase().includes(search.toLowerCase())
    );

    return (
        <div className="flex flex-col h-full bg-slate-900 border-r border-slate-800 w-80">
            <div className="p-4 border-b border-slate-800">
                <h3 className="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">Tile Registry</h3>
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
                    <input 
                        type="text" 
                        placeholder="Search items..."
                        className="w-full bg-slate-800 border-slate-700 rounded-lg pl-10 pr-4 py-2 text-sm text-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
            </div>

            <div className="flex border-b border-slate-800">
                <button 
                    onClick={() => setTab('products')}
                    className={`flex-1 py-3 text-xs font-bold uppercase tracking-widest transition-colors ${
                        tab === 'products' ? 'text-indigo-400 border-b-2 border-indigo-500 bg-indigo-500/5' : 'text-slate-500 hover:text-slate-300'
                    }`}
                >
                    Products
                </button>
                <button 
                    onClick={() => setTab('categories')}
                    className={`flex-1 py-3 text-xs font-bold uppercase tracking-widest transition-colors ${
                        tab === 'categories' ? 'text-indigo-400 border-b-2 border-indigo-500 bg-indigo-500/5' : 'text-slate-500 hover:text-slate-300'
                    }`}
                >
                    Categories
                </button>
            </div>

            <div className="flex-1 overflow-y-auto p-2 space-y-1 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
                {filteredItems.map(item => {
                    const id = item.product_id || item.id;
                    const name = item.display_name || item.name;
                    const isActive = activeSelection?.id === id && activeSelection?.type === (tab === 'products' ? 'product' : 'category');

                    return (
                        <button
                            key={`${tab}-${id}`}
                            onClick={() => onSelect({ 
                                id, 
                                type: tab === 'products' ? 'product' : 'category',
                                name 
                            })}
                            className={`w-full flex items-center gap-3 p-3 rounded-xl transition-all group ${
                                isActive 
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' 
                                : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'
                            }`}
                        >
                            <div className={`p-2 rounded-lg ${isActive ? 'bg-white/20' : 'bg-slate-800 group-hover:bg-slate-700'}`}>
                                {tab === 'products' ? <Package className="w-4 h-4" /> : <Folder className="w-4 h-4" />}
                            </div>
                            <div className="flex-1 text-left">
                                <p className="text-sm font-medium leading-none mb-1">{name}</p>
                                <p className="text-[10px] opacity-50 uppercase tracking-tighter">
                                    {tab === 'products' ? item.sku || 'No SKU' : 'Browse Category'}
                                </p>
                            </div>
                            <Plus className={`w-4 h-4 transition-transform ${isActive ? 'rotate-45' : 'opacity-0 group-hover:opacity-100'}`} />
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
