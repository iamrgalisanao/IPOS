import React from 'react';
import { Package, Folder, X, Plus } from 'lucide-react';

export default function LayoutEditorGrid({ schema, products, categories, onTileClick, onTileRemove, activeSelection }) {
    const { rows, columns } = schema.grid;

    // Map product/category data for quick lookup
    const productMap = products.reduce((acc, p) => ({ ...acc, [p.product_id]: p }), {});
    const categoryMap = categories.reduce((acc, c) => ({ ...acc, [c.id]: c }), {});

    const getTileAt = (x, y) => schema.tiles.find(t => t.x === x && t.y === y);

    return (
        <div 
            className="grid gap-2 h-full bg-slate-900/50 p-4 rounded-2xl border border-slate-800/50 overflow-auto"
            style={{ 
                gridTemplateRows: `repeat(${rows}, 1fr)`,
                gridTemplateColumns: `repeat(${columns}, 1fr)`,
                aspectRatio: `${columns} / ${rows}`,
                minHeight: '400px'
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
                        className={`relative group rounded-xl border-2 transition-all flex flex-col items-center justify-center p-2 text-center cursor-pointer ${
                            tile 
                            ? 'bg-slate-800 border-slate-700 hover:border-indigo-500' 
                            : 'bg-slate-900/30 border-dashed border-slate-800 hover:bg-slate-800/40 hover:border-slate-700'
                        } ${activeSelection && !tile ? 'hover:border-indigo-500/50' : ''}`}
                    >
                        {tile ? (
                            <>
                                <div className="p-3 bg-slate-900 rounded-xl mb-2 text-indigo-400">
                                    {tile.type === 'product' ? <Package className="w-5 h-5" /> : <Folder className="w-5 h-5" />}
                                </div>
                                <span className="text-[10px] font-bold text-slate-200 line-clamp-2 px-1 leading-tight">
                                    {itemData ? (itemData.display_name || itemData.name) : 'Unknown Item'}
                                </span>
                                <span className="text-[9px] text-slate-500 mt-1 uppercase tracking-tighter">
                                    {tile.type}
                                </span>
                                
                                <button 
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onTileRemove(x, y);
                                    }}
                                    className="absolute -top-2 -right-2 w-6 h-6 bg-rose-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-lg shadow-rose-500/20"
                                >
                                    <X className="w-3 h-3" />
                                </button>
                            </>
                        ) : (
                            <div className="text-slate-700 group-hover:text-slate-500 transition-colors">
                                <Plus className="w-5 h-5" />
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
