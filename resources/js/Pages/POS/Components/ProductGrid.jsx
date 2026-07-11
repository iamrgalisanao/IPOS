import React from 'react';
import { Plus, PackageSearch, AlertCircle, Ban, Clock3, TriangleAlert } from 'lucide-react';

const formatQuantity = (value) => {
    const quantity = Number(value);
    if (!Number.isFinite(quantity)) return '0';
    return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(2).replace(/\.?0+$/, '');
};

const getStockPresentation = (product, qtyInCart = 0) => {
    if (!product.is_inventory_tracked) {
        return {
            label: null,
            tone: 'normal',
            disabled: false,
            icon: null,
            detail: null,
        };
    }

    const currentStock = Number(product.current_stock ?? 0);
    const availableToSell = Number(product.available_to_sell ?? currentStock);
    const state = product.stock_state || (availableToSell > 0 ? 'normal' : 'out_of_stock');
    const noMoreAvailable = Number.isFinite(availableToSell) && availableToSell > 0 && qtyInCart >= availableToSell;

    if (state === 'expired') {
        return {
            label: 'Expired',
            tone: 'blocked',
            disabled: true,
            icon: Ban,
            detail: 'Blocked from sale',
        };
    }

    if (state === 'out_of_stock' || availableToSell <= 0) {
        return {
            label: 'Out of Stock',
            tone: 'blocked',
            disabled: true,
            icon: Ban,
            detail: 'Unavailable',
        };
    }

    if (noMoreAvailable) {
        return {
            label: `${formatQuantity(availableToSell)} Available`,
            tone: 'blocked',
            disabled: true,
            icon: Ban,
            detail: 'All available units are in cart',
        };
    }

    if (state === 'near_expiry') {
        return {
            label: 'Near Expiry',
            tone: 'warning',
            disabled: false,
            icon: Clock3,
            detail: product.next_expiry_date ? `Expires ${product.next_expiry_date}` : null,
        };
    }

    if (state === 'critical_stock') {
        return {
            label: `Last ${formatQuantity(availableToSell)}`,
            tone: 'critical',
            disabled: false,
            icon: TriangleAlert,
            detail: `${formatQuantity(availableToSell)} left`,
        };
    }

    if (state === 'low_stock') {
        return {
            label: `Only ${formatQuantity(availableToSell)} left`,
            tone: 'warning',
            disabled: false,
            icon: TriangleAlert,
            detail: `${formatQuantity(availableToSell)} in stock`,
        };
    }

    return {
        label: `${formatQuantity(availableToSell)} In Stock`,
        tone: 'healthy',
        disabled: false,
        icon: null,
        detail: null,
    };
};

const badgeClassByTone = {
    healthy: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    warning: 'bg-amber-500/15 text-amber-300 border-amber-500/25',
    critical: 'bg-orange-500/15 text-orange-300 border-orange-500/25',
    blocked: 'bg-rose-500/15 text-rose-300 border-rose-500/25',
    normal: 'bg-slate-700/60 text-slate-300 border-slate-600/60',
};

export default function ProductGrid({ products, loading, onSelect, activeLayout, isSearchActive, cart = [], gridColsClass = "grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" }) {
    if (loading && products.length === 0) {
        return (
            <div className={`grid ${gridColsClass} gap-4`}>
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

                    const pId = product.id || product.product_id;
                    const cartItem = cart.find(i => (i.id || i.product_id) === pId);
                    const qtyInCart = cartItem ? cartItem.quantity : 0;

                    return (
                        <ProductCard 
                            key={product.product_id}
                            product={product}
                            onSelect={onSelect}
                            qtyInCart={qtyInCart}
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
        <div className={`grid ${gridColsClass} gap-4`}>
            {products.map((product, index) => {
                const pId = product.id || product.product_id;
                const cartItem = cart.find(i => (i.id || i.product_id) === pId);
                const qtyInCart = cartItem ? cartItem.quantity : 0;
                return (
                    <ProductCard 
                        key={product.product_id || `product-${index}`}
                        product={product}
                        onSelect={onSelect}
                        qtyInCart={qtyInCart}
                    />
                );
            })}
        </div>
    );
}

function ProductCard({ product, onSelect, qtyInCart = 0, style = {} }) {
    const stockPresentation = getStockPresentation(product, qtyInCart);
    const StockIcon = stockPresentation.icon;
    const productName = product.display_name || product.name || 'Product';

    return (
        <button
            onClick={() => {
                if (!stockPresentation.disabled) {
                    onSelect(product);
                }
            }}
            disabled={stockPresentation.disabled}
            style={style}
            title={stockPresentation.detail || undefined}
            className={`group flex flex-col bg-slate-900/35 backdrop-blur-md border border-white/5 rounded-2xl overflow-hidden transition-all text-left relative h-36 ${
                stockPresentation.disabled
                    ? 'opacity-60 cursor-not-allowed grayscale-[0.35]'
                    : 'hover:border-indigo-500/50 hover:bg-slate-900/55 hover:shadow-xl hover:shadow-indigo-500/10 active:scale-95'
            }`}
        >
            {/* Quantity Badge for instant visual feedback */}
            {qtyInCart > 0 && (
                <div className="absolute top-2 left-2 bg-indigo-600 text-white text-[10px] font-black w-6 h-6 rounded-full flex items-center justify-center border border-indigo-400 shadow-lg animate-in zoom-in-50 duration-200 z-10">
                    {qtyInCart}
                </div>
            )}

            {stockPresentation.label && (
                <div className={`absolute top-2 right-2 inline-flex max-w-[72%] items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold leading-none z-10 ${badgeClassByTone[stockPresentation.tone] || badgeClassByTone.normal}`}>
                    {StockIcon && <StockIcon className="h-3 w-3 shrink-0" />}
                    <span className="truncate">{stockPresentation.label}</span>
                </div>
            )}

            {/* Placeholder for Product Image */}
            <div className={`h-14 flex items-center justify-center transition-colors shrink-0 border-b border-slate-800/40 ${
                stockPresentation.disabled ? 'bg-slate-900/60' : 'bg-slate-800/30 group-hover:bg-slate-700/40'
            }`}>
                <span className="text-xl font-bold opacity-30 group-hover:opacity-50 transition-opacity">{productName.charAt(0)}</span>
            </div>

            <div className="p-2.5 flex-1 flex flex-col justify-between overflow-hidden">
                <div className="min-h-0">
                    <h3 className="font-semibold text-xs text-slate-200 leading-tight group-hover:text-white transition-colors line-clamp-2">
                        {productName}
                    </h3>
                    {stockPresentation.detail && (
                        <p className="mt-1 truncate text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                            {stockPresentation.detail}
                        </p>
                    )}
                </div>
                
                <div className="mt-auto flex items-center justify-between">
                    <span className={`font-bold text-xs ${stockPresentation.disabled ? 'text-slate-500' : 'text-indigo-400'}`}>
                        ₱{Number(product.selling_price).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </span>
                    {stockPresentation.disabled ? (
                        <div className="p-1 bg-slate-800/70 text-slate-500 rounded-md shadow-sm">
                            <Ban className="w-4 h-4" />
                        </div>
                    ) : (
                        <div className="p-1 bg-indigo-600/10 text-indigo-400 rounded-md group-hover:bg-indigo-600 group-hover:text-white transition-all shadow-sm">
                            <Plus className="w-4 h-4" />
                        </div>
                    )}
                </div>
            </div>
        </button>
    );
}
