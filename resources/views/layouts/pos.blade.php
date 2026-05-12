<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPOS - POS Terminal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
        .glass {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="h-full text-slate-200 overflow-hidden">
    <div id="app" class="h-full flex flex-col" x-data="posApp()">
        <!-- Header -->
        <header class="h-16 flex items-center justify-between px-6 glass shrink-0">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center font-bold text-xl shadow-lg shadow-indigo-500/20">i</div>
                <h1 class="text-xl font-bold tracking-tight">IPOS <span class="text-indigo-400 font-medium">Terminal</span></h1>
            </div>
            
            <div class="flex items-center gap-6">
                <div class="text-right">
                    <div class="text-sm font-medium text-slate-100" x-text="tenantName"></div>
                    <div class="text-xs text-slate-400" x-text="currentTime"></div>
                </div>
                <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                </div>
            </div>
        </header>

        <main class="flex-1 flex overflow-hidden">
            @yield('content')
        </main>
    </div>

    <script>
        function posApp() {
            return {
                tenantName: '{{ $tenant->name }}',
                currentTime: '',
                cart: [],
                searchTerm: '',
                products: [],
                categories: [],
                activeCategoryId: null,
                loading: false,
                
                init() {
                    this.updateTime();
                    setInterval(() => this.updateTime(), 1000);
                    this.fetchProducts();
                },

                updateTime() {
                    const now = new Date();
                    this.currentTime = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                },

                async fetchProducts(categoryId = null) {
                    this.loading = true;
                    if (categoryId !== undefined) this.activeCategoryId = categoryId;
                    
                    let url = '/pos/search';
                    const params = new URLSearchParams();
                    if (this.searchTerm) params.append('q', this.searchTerm);
                    if (this.activeCategoryId) params.append('category_id', this.activeCategoryId);
                    
                    // Propagate test_tenant_id for verification convenience
                    const currentParams = new URLSearchParams(window.location.search);
                    if (currentParams.has('test_tenant_id')) {
                        params.append('test_tenant_id', currentParams.get('test_tenant_id'));
                    }
                    
                    if (params.toString()) url += '?' + params.toString();

                    try {
                        const response = await fetch(url);
                        this.products = await response.json();
                    } catch (e) {
                        console.error('Failed to fetch products', e);
                    } finally {
                        this.loading = false;
                    }
                },

                addToCart(product) {
                    const existing = this.cart.find(item => item.product_id === product.product_id);
                    if (existing) {
                        existing.quantity++;
                    } else {
                        this.cart.push({
                            ...product,
                            quantity: 1,
                            price_at_addition: product.selling_price
                        });
                    }
                },

                updateQuantity(productId, delta) {
                    const item = this.cart.find(item => item.product_id === productId);
                    if (item) {
                        item.quantity += delta;
                        if (item.quantity <= 0) {
                            this.removeFromCart(productId);
                        }
                    }
                },

                removeFromCart(productId) {
                    this.cart = this.cart.filter(item => item.product_id !== productId);
                },

                clearCart() {
                    if (confirm('Are you sure you want to clear the current transaction?')) {
                        this.cart = [];
                    }
                },

                get subtotal() {
                    return this.cart.reduce((sum, item) => sum + (item.price_at_addition * item.quantity), 0);
                },

                get totalTax() {
                    return this.cart.reduce((sum, item) => {
                        const itemSubtotal = item.price_at_addition * item.quantity;
                        return sum + (itemSubtotal * (item.tax_rate / 100));
                    }, 0);
                },

                get total() {
                    return this.subtotal + this.totalTax;
                },

                formatCurrency(value) {
                    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
                }
            }
        }
    </script>
</body>
</html>
