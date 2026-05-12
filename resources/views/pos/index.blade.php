@extends('layouts.pos')

@section('content')
<!-- Left Side: Product Selection (Grid/Search) -->
<div class="flex-1 flex flex-col overflow-hidden border-r border-slate-800">
    <!-- Search and Mode Toggle -->
    <div class="p-4 bg-slate-900/50 shrink-0 flex gap-4">
        <div class="relative flex-1">
            <input 
                type="text" 
                x-model="searchTerm" 
                @input.debounce.300ms="fetchProducts()"
                placeholder="Search products by name, SKU, or barcode..."
                class="w-full bg-slate-800 border-none rounded-xl py-3 pl-12 pr-4 text-slate-100 placeholder-slate-500 focus:ring-2 focus:ring-indigo-500 transition-all"
            >
            <div class="absolute left-4 top-3.5 text-slate-500">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
        </div>
        
        <div class="flex bg-slate-800 p-1 rounded-xl">
            <button class="px-4 py-2 rounded-lg text-sm font-medium transition-all" :class="!searchTerm ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' : 'text-slate-400 hover:text-slate-200'">Grid</button>
            <button class="px-4 py-2 rounded-lg text-sm font-medium transition-all" :class="searchTerm ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' : 'text-slate-400 hover:text-slate-200'">List</button>
        </div>
    </div>

    <!-- Category Strip -->
    <div class="flex overflow-x-auto p-4 gap-3 scrollbar-hide border-b border-slate-800 bg-slate-900/30">
        <button 
            @click="fetchProducts(null)"
            class="px-6 py-2 rounded-full text-sm font-semibold whitespace-nowrap transition-all border"
            :class="!activeCategoryId ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-slate-500'"
        >All Items</button>
        
        @foreach($categories as $category)
        <button 
            @click="fetchProducts('{{ $category->id }}')"
            class="px-6 py-2 rounded-full text-sm font-semibold whitespace-nowrap transition-all border"
            :class="activeCategoryId === '{{ $category->id }}' ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-slate-500'"
        >{{ $category->name }}</button>
        @endforeach
    </div>

    <!-- Product Grid -->
    <div class="flex-1 overflow-y-auto p-6 scrollbar-hide">
        <div x-show="loading" class="h-full flex items-center justify-center">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-indigo-500"></div>
        </div>
        
        <div x-show="!loading && products.length === 0" class="h-full flex flex-col items-center justify-center text-slate-500">
            <svg class="w-16 h-16 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
            <p class="text-lg font-medium">No products found</p>
            <p class="text-sm">Try a different search or category</p>
        </div>

        <div x-show="!loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            <template x-for="product in products" :key="product.product_id">
                <button 
                    @click="addToCart(product)"
                    class="group relative bg-slate-800/40 border border-slate-700/50 rounded-2xl p-4 text-left hover:bg-slate-800 hover:border-indigo-500/50 transition-all duration-300 overflow-hidden flex flex-col h-full"
                    :disabled="!product.stock_available"
                    :class="!product.stock_available ? 'opacity-50 grayscale' : ''"
                >
                    <!-- Stock Badge -->
                    <div class="absolute top-2 right-2 flex gap-1">
                        <template x-if="product.is_inventory_tracked">
                            <span 
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider"
                                :class="product.stock_available ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400'"
                                x-text="product.current_stock !== null ? product.current_stock + ' in stock' : 'Out of Stock'"
                            ></span>
                        </template>
                    </div>

                    <div class="flex-1 pt-2">
                        <p class="text-xs text-slate-500 font-bold uppercase tracking-widest mb-1" x-text="product.category_name"></p>
                        <h3 class="font-semibold text-slate-100 leading-tight mb-2 group-hover:text-indigo-400 transition-colors" x-text="product.display_name"></h3>
                        <p class="text-xs text-slate-500 font-mono" x-text="product.sku"></p>
                    </div>

                    <div class="mt-4 pt-4 border-t border-slate-700/50 flex items-center justify-between">
                        <span class="text-lg font-bold text-slate-100" x-text="formatCurrency(product.selling_price)"></span>
                        <div class="w-8 h-8 rounded-lg bg-indigo-600/10 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        </div>
                    </div>
                </button>
            </template>
        </div>
    </div>
</div>

<!-- Right Side: Sticky Cart / Transaction Hub -->
<div class="w-96 bg-slate-900 flex flex-col shrink-0 overflow-hidden relative">
    <!-- Cart Header -->
    <div class="p-6 border-b border-slate-800 flex items-center justify-between bg-slate-900/80 backdrop-blur z-10">
        <div class="flex items-center gap-3">
            <div class="relative">
                <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 11-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                <div x-show="cart.length > 0" class="absolute -top-2 -right-2 w-5 h-5 bg-indigo-600 rounded-full flex items-center justify-center text-[10px] font-bold border-2 border-slate-900" x-text="cart.length"></div>
            </div>
            <h2 class="font-bold text-lg">Transaction</h2>
        </div>
        <button 
            @click="clearCart()"
            x-show="cart.length > 0"
            class="text-xs font-bold text-rose-500 uppercase tracking-widest hover:text-rose-400 transition-colors"
        >Cancel</button>
    </div>

    <!-- Cart Items -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3 scrollbar-hide">
        <template x-show="cart.length === 0">
            <div class="h-full flex flex-col items-center justify-center text-center p-8 opacity-20">
                <svg class="w-20 h-20 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                <p class="text-xl font-bold">Cart is Empty</p>
                <p class="text-sm">Scan a barcode or select from the grid</p>
            </div>
        </template>

        <template x-for="item in cart" :key="item.product_id">
            <div class="group bg-slate-800/40 border border-slate-700/50 rounded-2xl p-4 hover:border-slate-600 transition-all">
                <div class="flex justify-between mb-3">
                    <div class="flex-1 pr-4">
                        <h4 class="font-semibold text-sm leading-tight text-slate-100" x-text="item.display_name"></h4>
                        <p class="text-[10px] text-slate-500 font-mono mt-1" x-text="item.sku"></p>
                    </div>
                    <p class="font-bold text-slate-100 whitespace-nowrap" x-text="formatCurrency(item.price_at_addition * item.quantity)"></p>
                </div>
                
                <div class="flex items-center justify-between">
                    <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700">
                        <button @click="updateQuantity(item.product_id, -1)" class="w-8 h-8 flex items-center justify-center rounded-md hover:bg-slate-800 text-slate-400 hover:text-slate-100 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                        </button>
                        <span class="w-10 flex items-center justify-center font-bold text-sm" x-text="item.quantity"></span>
                        <button @click="updateQuantity(item.product_id, 1)" class="w-8 h-8 flex items-center justify-center rounded-md hover:bg-slate-800 text-slate-400 hover:text-slate-100 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        </button>
                    </div>
                    
                    <button @click="removeFromCart(item.product_id)" class="text-slate-600 hover:text-rose-500 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </button>
                </div>
            </div>
        </template>
    </div>

    <!-- Summary Footer -->
    <div class="p-6 bg-slate-900 border-t border-slate-800 shadow-2xl">
        <div class="space-y-2 mb-6">
            <div class="flex justify-between text-sm">
                <span class="text-slate-500">Subtotal</span>
                <span class="font-medium text-slate-100" x-text="formatCurrency(subtotal)"></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-slate-500">Sales Tax (EST)</span>
                <span class="font-medium text-slate-100" x-text="formatCurrency(totalTax)"></span>
            </div>
            <div class="flex justify-between pt-4 border-t border-slate-800">
                <span class="text-lg font-bold text-slate-400 uppercase tracking-widest">Total</span>
                <span class="text-3xl font-black text-indigo-400" x-text="formatCurrency(total)"></span>
            </div>
        </div>

        <button 
            class="w-full py-4 bg-indigo-600 rounded-2xl font-bold text-lg text-white shadow-xl shadow-indigo-500/20 hover:bg-indigo-500 active:scale-[0.98] transition-all disabled:opacity-50 disabled:grayscale"
            :disabled="cart.length === 0"
        >
            Checkout Transaction
        </button>
    </div>
</div>
@endsection
