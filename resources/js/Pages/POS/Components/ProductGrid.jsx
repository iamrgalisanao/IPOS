import React from 'react';
import { Plus, PackageSearch, AlertCircle } from 'lucide-react';

export default function ProductGrid({ products, loading, onSelect, activeLayout, isSearchActive }) {
    if (loading && products.length === 0) {
        return (
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                {[...Array(10)].map((_, i) => (
                    <div key={i} className="bg-slate-900 border border-slate-800 rounded-2xl h-36 animate-pulse">
                        <div className="h-16 bg-slate-800 rounded-t-2xl"></div>
                        <div className="p-3 space-y-2">
                            <div className="h-4 bg-slate-800 rounded w-3/4"></div>
                            <div className="h-4 bg-slate-800 rounded w-1/2 mt-2"></div>
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    // Determine if we should use layout mode
    const useLayoutMode = activeLayout && !isSearchActive && activeLayout.layout?.schema?.grid;

    if (useLayoutMode) {
        const { grid, tiles } = activeLayout.layout.schema;
        const layoutProducts = activeLayout.products || [];

        return (
            <div 
                className="grid gap-4"
                style={{
                    gridTemplateColumns: `repeat(${grid.columns || 1}, minmax(0, 1fr))`,
                    gridTemplateRows: `repeat(${grid.rows || 1}, minmax(0, 1fr))`,
                }}
            >
                {tiles.map((tile, index) => {
                    if (tile.type !== 'product') return null;

                    const product = layoutProducts.find(p => p.product_id === tile.id);
                    
                    if (!product) {
                        return (
                            <div 
                                key={`empty-${index}`}
                                className="bg-slate-900/40 border border-slate-800/50 border-dashed rounded-2xl h-36 flex flex-col items-center justify-center p-4 text-slate-600"
                                style={{
                                    gridColumnStart: tile.x + 1,
                                    gridRowStart: tile.y + 1,
                                }}
                            >
                                <AlertCircle className="w-5 h-5 mb-2 opacity-20" />
                                <span className="text-[10px] font-medium uppercase tracking-tighter text-center">Product Unavailable</span>
                            </div>
                        );
                    }

                    return (
                        <ProductCard 
                            key={product.product_id}
                            product={product}
                            onSelect={onSelect}
                            style={{
                                gridColumnStart: tile.x + 1,
                                gridRowStart: tile.y + 1,
                            }}
                        />
                    );
                })}
            </div>
        );
    }

    if (products.length === 0 && !loading) {
        return (
            <div className="flex flex-col items-center justify-center py-20 text-slate-500">
                <PackageSearch className="w-16 h-16 mb-4 opacity-20" />
                <p className="text-lg font-medium">No products found</p>
                <p className="text-sm">Try adjusting your search or category filter</p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            {products.map((product, index) => (
                <ProductCard 
                    key={product.product_id || `product-${index}`}
                    product={product}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}

function ProductCard({ product, onSelect, style = {} }) {
    return (
        <button
            onClick={() => onSelect(product)}
            style={style}
            className="group flex flex-col bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden hover:border-indigo-500/50 hover:shadow-xl hover:shadow-indigo-500/10 transition-all text-left relative active:scale-95 h-36"
        >
            {/* Stock Badge */}
            {product.is_inventory_tracked && (
                <div className={`absolute top-2 right-2 px-2 py-0.5 rounded-full text-[10px] font-bold z-10 ${
                    product.current_stock > 0 
                    ? 'bg-emerald-500/10 text-emerald-500' 
                    : 'bg-rose-500/10 text-rose-500'
                }`}>
                    {product.current_stock > 0 ? `${product.current_stock} In Stock` : 'Out of Stock'}
                </div>
            )}

            {/* Placeholder for Product Image */}
            <div className="h-14 bg-slate-800 flex items-center justify-center group-hover:bg-slate-700 transition-colors shrink-0">
                <span className="text-xl font-bold opacity-40">{product.display_name.charAt(0)}</span>
            </div>

            <div className="p-2.5 flex-1 flex flex-col justify-between overflow-hidden">
                <div className="min-h-0">
                    <h3 className="font-semibold text-xs text-slate-200 leading-tight group-hover:text-white transition-colors line-clamp-2">
                        {product.display_name}
                    </h3>
                </div>
                
                <div className="mt-auto flex items-center justify-between">
                    <span className="text-indigo-400 font-bold text-xs">
                        ₱{Number(product.selling_price).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </span>
                    <div className="p-1 bg-indigo-600/10 text-indigo-400 rounded-md group-hover:bg-indigo-600 group-hover:text-white transition-all shadow-sm">
                        <Plus className="w-4 h-4" />
                    </div>
                </div>
            </div>
        </button>
    );
}
