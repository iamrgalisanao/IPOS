import React from 'react';
import { Package, Folder, X, Plus, Target } from 'lucide-react';

export default function LayoutEditorGrid({ schema, products, categories, onTileClick, onTileRemove, activeSelection }) {
    const { rows, columns } = schema.grid;

    // Map product/category data for quick lookup
    const productMap = products.reduce((acc, p) => ({ ...acc, [p.product_id]: p }), {});
    const categoryMap = categories.reduce((acc, c) => ({ ...acc, [c.id]: c }), {});

    const getTileAt = (x, y) => schema.tiles.find(t => t.x === x && t.y === y);

    return (
        <div 
            className="grid gap-3 h-full bg-[#0a0f1d] p-6 rounded-[2rem] border border-slate-800/40 shadow-2xl overflow-auto scrollbar-hide"
            style={{ 
                gridTemplateRows: `repeat(${rows}, 1fr)`,
                gridTemplateColumns: `repeat(${columns}, 1fr)`,
                aspectRatio: `${columns} / ${rows}`,
                minHeight: '450px'
            }}
        >
            {Array.from({ length: rows * columns }).map((_, i) => {
                const x = i % columns;
                const y = Math.floor(i / columns);
                const tile = getTileAt(x, y);
                
                let itemData = null;
                if (tile) {
                    itemData = tile.type === 'product' ? productMap[tile.id] : categoryMap[tile.id];
                }

                return (
                    <div 
                        key={`${x}-${y}`}
                        onClick={() => onTileClick(x, y)}
                        className={`relative group rounded-2xl border-2 transition-all duration-300 flex flex-col items-center justify-center p-3 text-center cursor-pointer overflow-hidden ${
                            tile 
                            ? 'bg-slate-800/50 border-slate-700/50 hover:border-indigo-500/50 hover:bg-slate-800 hover:shadow-2xl hover:shadow-indigo-500/10' 
                            : 'bg-slate-900/20 border-dashed border-slate-800/60 hover:bg-indigo-500/5 hover:border-indigo-500/30'
                        } ${activeSelection && !tile ? 'animate-pulse border-indigo-500/20' : ''}`}
                    >
                        {/* Cell Background Pattern */}
                        <div className="absolute inset-0 opacity-[0.03] pointer-events-none bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:16px_16px]" />

                        {tile ? (
                            <>
                                <div className={`p-3 rounded-xl mb-2 transition-all duration-300 ${
                                    tile.type === 'product' 
                                    ? 'bg-indigo-500/10 text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white' 
                                    : 'bg-amber-500/10 text-amber-400 group-hover:bg-amber-500 group-hover:text-white'
                                }`}>
                                    {tile.type === 'product' ? <Package className="w-5 h-5" /> : <Folder className="w-5 h-5" />}
                                </div>
                                <span className="text-[10px] font-black text-slate-200 line-clamp-2 px-1 leading-tight uppercase tracking-tight">
                                    {itemData ? (itemData.display_name || itemData.name) : 'Unknown Item'}
                                </span>
                                <div className="mt-2 flex items-center gap-1.5 opacity-40 group-hover:opacity-100 transition-opacity">
                                    <div className={`w-1 h-1 rounded-full ${tile.type === 'product' ? 'bg-indigo-500' : 'bg-amber-500'}`} />
                                    <span className="text-[8px] text-slate-500 font-black uppercase tracking-widest">
                                        {tile.type}
                                    </span>
                                </div>
                                
                                <button 
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onTileRemove(x, y);
                                    }}
                                    className="absolute top-2 right-2 p-1.5 bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white rounded-lg opacity-0 group-hover:opacity-100 transition-all shadow-lg"
                                >
                                    <X className="w-3 h-3" />
                                </button>
                            </>
                        ) : (
                            <div className="flex flex-col items-center gap-2 text-slate-700 group-hover:text-indigo-400/60 transition-all duration-500">
                                {activeSelection ? (
                                    <>
                                        <Target className="w-6 h-6 animate-spin-slow" />
                                        <span className="text-[8px] font-black uppercase tracking-[0.2em] opacity-0 group-hover:opacity-100 transition-opacity">Place Here</span>
                                    </>
                                ) : (
                                    <Plus className="w-5 h-5" />
                                )}
                            </div>
                        )}

                        {/* Active Selection Glow */}
                        {activeSelection && !tile && (
                            <div className="absolute inset-0 bg-indigo-500/5 opacity-0 group-hover:opacity-100 transition-opacity" />
                        )}
                    </div>
                );
            })}
        </div>
    );
}
