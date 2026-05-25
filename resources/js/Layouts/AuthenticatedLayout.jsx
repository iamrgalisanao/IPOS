import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    LayoutDashboard,
    Clock,
    Receipt,
    Layers,
    Calculator,
    PieChart,
    Menu,
    X,
    LogOut,
    User as UserIcon,
    Settings,
    LayoutGrid,
    Package,
    Users,
    FileText,
    Truck,
    RotateCcw,
    ChevronLeft,
    ChevronRight,
    Scale,
    AlertTriangle
} from 'lucide-react';

export default function AuthenticatedLayout({ header, children }) {
    const page = usePage();
    const user = page.props.auth.user;
    const sidebarNavRef = useRef(null);
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
        if (typeof window !== 'undefined') {
            return localStorage.getItem('sidebar_collapsed') === 'true';
        }
        return false;
    });

    const toggleSidebar = () => {
        setSidebarCollapsed(prev => {
            const next = !prev;
            localStorage.setItem('sidebar_collapsed', String(next));
            return next;
        });
    };

    const persistSidebarScroll = () => {
        if (typeof window === 'undefined' || !sidebarNavRef.current) {
            return;
        }

        localStorage.setItem('sidebar_scroll_top', String(sidebarNavRef.current.scrollTop));
    };

    useEffect(() => {
        if (typeof window === 'undefined' || sidebarCollapsed || !sidebarNavRef.current) {
            return;
        }

        const savedScrollTop = Number(localStorage.getItem('sidebar_scroll_top') || 0);
        if (!Number.isFinite(savedScrollTop)) {
            return;
        }

        requestAnimationFrame(() => {
            if (sidebarNavRef.current) {
                sidebarNavRef.current.scrollTop = savedScrollTop;
            }
        });
    }, [page.url, sidebarCollapsed]);

    const permissions = usePage().props.auth?.permissions || [];
    const entitledFeatures = usePage().props.auth?.tenant?.subscription?.features || [];
    const hasFeature = (featureKey) => entitledFeatures.includes(featureKey);
    const hasAccountabilityView = permissions.includes('reports.cashier-accountability.view') || permissions.includes('reports.shift-summary.view');

    // Dynamically filter Core Items
    const coreItems = [];
    if (permissions.includes('view_reports') || permissions.includes('view_branch_dashboard') || permissions.includes('view_multi_branch_dashboard')) {
        coreItems.push({ name: 'Dashboard', href: route('dashboard'), icon: LayoutDashboard, active: route().current('dashboard') });
    }

    // Dynamically filter Operations Items
    const operationsItems = [];
    if (permissions.includes('access_pos') && hasFeature('sales.pos')) {
        operationsItems.push({ name: 'POS Terminal', href: route('pos.index'), icon: LayoutDashboard, active: route().current('pos.*') });
    }
    if (permissions.includes('view_shift') || permissions.includes('open_shift') || permissions.includes('close_shift')) {
        operationsItems.push({ name: 'Shift Operations', href: route('shifts.index'), icon: Clock, active: route().current('shifts.*') });
    }
    if ((permissions.includes('pos-layouts.view') || permissions.includes('pos-layouts.manage')) && hasFeature('layout.custom')) {
        operationsItems.push({ name: 'POS Layouts', href: route('admin.pos-layouts.index'), icon: LayoutGrid, active: route().current('admin.pos-layouts.*') });
    }

    // Dynamically filter Catalog & Stock Items
    const catalogAndStockItems = [];
    const hasInventoryHubAccess = [
        'view_branch_inventory',
        'inventory.stocktake.view',
        'view_inventory_reports',
        'audit_inventory',
        'manage_products',
        'manage_unit_conversions',
        'procurement.suppliers.view',
        'procurement.purchase-orders.view',
        'procurement.receiving.view',
        'procurement.returns.view',
    ].some((permission) => permissions.includes(permission));

    if (hasInventoryHubAccess) {
        catalogAndStockItems.push({
            name: 'Inventory Hub',
            href: route('inventory.hub.index'),
            icon: Layers,
            active: route().current('inventory.hub.*'),
        });
    }

    if (permissions.includes('manage_products') && hasFeature('catalog.view')) {
        catalogAndStockItems.push({ name: 'Product Catalog', href: route('admin.products.index'), icon: Package, active: route().current('admin.products.*') || route().current('admin.product-categories.*') });
    }
    if (permissions.includes('inventory.stocktake.view') || permissions.includes('manage_branch_inventory')) {
        catalogAndStockItems.push({ name: 'Stocktake', href: route('inventory.stocktakes.index'), icon: Layers, active: route().current('inventory.stocktakes.*') });
    }
    if (permissions.includes('view_branch_inventory') || permissions.includes('inventory.stocktake.view')) {
        catalogAndStockItems.push({ name: 'Stock Visibility', href: route('inventory.dashboard.index'), icon: LayoutDashboard, active: route().current('inventory.dashboard.*') });
    }
    if (permissions.includes('manage_unit_conversions') || permissions.includes('manage_inventory')) {
        catalogAndStockItems.push({ name: 'Unit Conversions', href: route('inventory.unit-conversions.index'), icon: Scale, active: route().current('inventory.unit-conversions.*') });
    }

    // Dynamically filter Procurement Items
    const procurementItems = [];
    if (permissions.includes('procurement.suppliers.view') && hasFeature('procurement.basic')) {
        procurementItems.push({
            name: 'Suppliers',
            href: route('procurement.suppliers.index'),
            icon: Users,
            active: route().current('procurement.suppliers.*')
        });
    }
    if (permissions.includes('procurement.purchase-orders.view') && hasFeature('procurement.basic')) {
        procurementItems.push({
            name: 'Purchase Orders',
            href: route('procurement.purchase-orders.index'),
            icon: FileText,
            active: route().current('procurement.purchase-orders.*')
        });
    }
    if (permissions.includes('procurement.receiving.view') && hasFeature('procurement.basic')) {
        procurementItems.push({
            name: 'Goods Receiving',
            href: route('procurement.receivings.index'),
            icon: Truck,
            active: route().current('procurement.receivings.*')
        });
    }
    if (permissions.includes('procurement.returns.view') && hasFeature('procurement.advanced')) {
        procurementItems.push({
            name: 'Supplier Returns',
            href: route('procurement.returns.index'),
            icon: RotateCcw,
            active: route().current('procurement.returns.*')
        });
    }

    // Dynamically filter Sales & Finance Items
    const salesAndFinanceItems = [];
    if (permissions.includes('view_sales_history')) {
        salesAndFinanceItems.push({ name: 'Sales Summary', href: route('reports.sales-summary.index'), icon: PieChart, active: route().current('reports.sales-summary.*') });
        salesAndFinanceItems.push({ name: 'Sales History', href: route('sales.history.index'), icon: Receipt, active: route().current('sales.history.*') });
    }
    if (permissions.includes('view_settlement_periods')) {
        salesAndFinanceItems.push({ name: 'Settlement', href: route('settlement.periods.index'), icon: Layers, active: route().current('settlement.*') });
    }
    if (permissions.includes('view_sync_dashboard')) {
        salesAndFinanceItems.push({ name: 'Accounting', href: route('accounting.outbox.index'), icon: Calculator, active: route().current('accounting.*') });
    }
    if (permissions.includes('manage_tax_categories') && hasFeature('reports.basic')) {
        salesAndFinanceItems.push({ name: 'Tax Reports', href: route('reports.tax.index'), icon: PieChart, active: route().current('reports.tax.*') });
    }
    if (hasAccountabilityView && hasFeature('reports.advanced')) {
        salesAndFinanceItems.push({
            name: 'Cashier Accountability',
            href: route('reports.cashier-accountability.index'),
            icon: PieChart,
            active: route().current('reports.cashier-accountability.*')
        });
    }
    if (permissions.includes('view_inventory_reports') || permissions.includes('audit_inventory')) {
        salesAndFinanceItems.push({
            name: 'Variance Logs',
            href: route('inventory.reports.variance-logs.index'),
            icon: AlertTriangle,
            active: route().current('inventory.reports.variance-logs.*')
        });
    }

    // Only render groups containing at least one item
    const navigationGroups = [
        {
            label: 'Core',
            items: coreItems
        },
        {
            label: 'Operations',
            items: operationsItems
        },
        {
            label: 'Catalog & Stock',
            items: catalogAndStockItems
        },
        {
            label: 'Procurement',
            items: procurementItems
        },
        {
            label: 'Sales & Finance',
            items: salesAndFinanceItems
        }
    ].filter(group => group.items.length > 0);

    return (
        <div className="min-h-screen bg-gray-50 flex">
            {/* Sidebar Overlay for mobile */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 z-40 bg-gray-900/50 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar */}
            <div className={`fixed inset-y-0 left-0 z-50 bg-slate-950 text-white transform transition-all duration-300 ease-in-out lg:translate-x-0 lg:static lg:block ${
                sidebarCollapsed ? 'w-20 overflow-visible' : 'w-64'
            } ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className={`flex items-center ${
                    sidebarCollapsed ? 'justify-center px-4' : 'justify-between px-6'
                } h-16 border-b border-slate-900 transition-all duration-300`}>
                    <Link href={route('dashboard')} className="flex items-center gap-3">
                        <ApplicationLogo className="block h-8 w-auto fill-current text-cyan-400 drop-shadow-[0_0_8px_rgba(6,182,212,0.6)]" />
                        {!sidebarCollapsed && (
                            <span className="text-xl font-bold tracking-tight text-white uppercase italic transition-all duration-300">IPOS</span>
                        )}
                    </Link>
                    {!sidebarCollapsed && (
                        <button onClick={() => setSidebarOpen(false)} className="lg:hidden text-slate-400 hover:text-white">
                            <X size={20} />
                        </button>
                    )}
                </div>

                <div className={`flex flex-col flex-grow ${sidebarCollapsed ? 'overflow-visible' : 'h-[calc(100vh-4rem)]'}`}>
                    <nav
                        ref={sidebarNavRef}
                        onScroll={persistSidebarScroll}
                        className={`flex-1 ${sidebarCollapsed ? 'px-2 py-6 overflow-visible' : 'px-4 py-8 overflow-y-auto custom-scrollbar'} space-y-6`}
                    >
                        {navigationGroups.map((group) => (
                            <div key={group.label} className="transition-all duration-300">
                                {!sidebarCollapsed ? (
                                    <h3 className="px-3 mb-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 whitespace-nowrap transition-all duration-300">
                                        {group.label}
                                    </h3>
                                ) : (
                                    <div className="h-px bg-slate-900 my-4 mx-2 transition-all duration-300" />
                                )}
                                <div className="space-y-1">
                                    {group.items.map((item) => (
                                        <Link
                                            key={item.name}
                                            href={item.href}
                                            onMouseDown={persistSidebarScroll}
                                            className={`flex items-center ${
                                                sidebarCollapsed ? 'justify-center px-0 py-3 rounded-xl' : 'gap-3 px-3 py-2.5 rounded-xl'
                                            } text-xs font-bold uppercase tracking-widest transition-all duration-300 relative group ${
                                                item.active 
                                                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20' 
                                                    : 'text-slate-400 hover:text-white hover:bg-slate-900'
                                            }`}
                                        >
                                            <item.icon 
                                                size={18} 
                                                className={`transition-all duration-300 ${
                                                    item.active 
                                                        ? 'text-white scale-110 drop-shadow-[0_0_6px_rgba(255,255,255,0.6)]' 
                                                        : 'text-slate-500 group-hover:text-cyan-400 group-hover:scale-110 group-hover:drop-shadow-[0_0_8px_rgba(6,182,212,0.8)]'
                                                }`} 
                                            />
                                            {!sidebarCollapsed ? (
                                                <span className="transition-all duration-300 whitespace-nowrap">{item.name}</span>
                                            ) : (
                                                /* Teardrop-shaped Cyber Tooltip for collapsed states. Highly z-indexed and rendered strictly when collapsed. */
                                                <div className="absolute left-[calc(100%+0.75rem)] top-1/2 -translate-y-1/2 px-3.5 py-2 bg-slate-950 border border-slate-800 text-cyan-400 rounded-tr-xl rounded-br-xl rounded-bl-xl rounded-tl-none text-[10px] font-black uppercase tracking-[0.15em] shadow-[0_10px_30px_rgba(0,0,0,0.8)] opacity-0 scale-95 translate-x-2 group-hover:opacity-100 group-hover:scale-100 group-hover:translate-x-0 transition-all duration-300 pointer-events-none whitespace-nowrap z-[9999] flex items-center gap-2 border-l-2 border-l-cyan-400 border-r-2 border-r-cyan-400/80">
                                                    <div className="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse" />
                                                    <span>{item.name}</span>
                                                    {/* Custom Teardrop Arrow extending from top-left */}
                                                    <div className="absolute right-full top-0 w-0 h-0 border-r-[8px] border-r-slate-950 border-b-[8px] border-b-transparent" />
                                                    <div className="absolute right-full top-0 w-0 h-0 border-r-[8px] border-r-slate-800/50 border-b-[8px] border-b-transparent -z-10 blur-[1px]" />
                                                </div>
                                            )}
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>

                    {/* Bottom Profile Widget & Collapse State Handling */}
                    <div className={`p-4 bg-slate-950 border-t border-slate-900 transition-all duration-300 ${
                        sidebarCollapsed ? 'flex flex-col items-center gap-4 overflow-visible' : ''
                    }`}>
                        {sidebarCollapsed ? (
                            /* Collapsed User Avatar Profile */
                            <div className="relative group flex flex-col items-center gap-3 overflow-visible">
                                <div className="w-9 h-9 rounded-full bg-slate-900 border-2 border-indigo-500/50 p-1 flex items-center justify-center shadow-[0_0_12px_rgba(99,102,241,0.3)] hover:border-cyan-400 transition-all duration-300 cursor-pointer">
                                    <ApplicationLogo className="w-full h-full text-cyan-400" />
                                </div>
                                {/* User Info Card Hover Popover */}
                                <div className="absolute left-[calc(100%+0.75rem)] top-1/2 -translate-y-1/2 px-4 py-3 bg-slate-950 border border-slate-800 text-white rounded-tr-2xl rounded-br-2xl rounded-bl-2xl rounded-tl-none shadow-2xl opacity-0 scale-95 translate-x-2 group-hover:opacity-100 group-hover:scale-100 group-hover:translate-x-0 transition-all duration-300 pointer-events-none whitespace-nowrap z-[9999] flex flex-col gap-1 border-l-2 border-l-indigo-500 border-r-2 border-r-indigo-500/80 min-w-[150px]">
                                    <div className="text-[10px] font-black uppercase tracking-wider text-indigo-400">Current User</div>
                                    <div className="text-xs font-bold text-white">{user.name}</div>
                                    <div className="text-[10px] font-semibold text-slate-400">{user.email}</div>
                                    {/* Custom Teardrop Arrow extending from top-left */}
                                    <div className="absolute right-full top-0 w-0 h-0 border-r-[8px] border-r-slate-950 border-b-[8px] border-b-transparent" />
                                </div>

                                {/* Stacked Profile Actions with Individual Tooltips */}
                                <div className="flex flex-col gap-2 w-full items-center overflow-visible">
                                    <Link 
                                        href={route('profile.edit')} 
                                        className="p-2.5 text-slate-400 hover:text-cyan-400 hover:bg-slate-900 rounded-xl transition-all duration-200 relative group/btn border border-transparent hover:border-slate-800"
                                    >
                                        <Settings size={16} />
                                        <div className="absolute left-[calc(100%+0.75rem)] top-1/2 -translate-y-1/2 px-2.5 py-1.5 bg-slate-950 border border-slate-800 text-cyan-400 rounded-tr-xl rounded-br-xl rounded-bl-xl rounded-tl-none text-[9px] font-black uppercase tracking-wider shadow-xl opacity-0 scale-95 translate-x-2 group-hover/btn:opacity-100 group-hover/btn:scale-100 group-hover/btn:translate-x-0 transition-all duration-200 pointer-events-none whitespace-nowrap z-[9999] flex items-center gap-1 border-l-2 border-l-cyan-400 border-r-2 border-r-cyan-400/80">
                                            <span>Settings</span>
                                            {/* Custom Teardrop Arrow extending from top-left */}
                                            <div className="absolute right-full top-0 w-0 h-0 border-r-[6px] border-r-slate-950 border-b-[6px] border-b-transparent" />
                                        </div>
                                    </Link>
                                    <Link 
                                        href={route('logout')} 
                                        method="post" 
                                        as="button" 
                                        className="p-2.5 text-slate-400 hover:text-rose-400 hover:bg-slate-900 rounded-xl transition-all duration-200 relative group/btn border border-transparent hover:border-slate-800"
                                    >
                                        <LogOut size={16} />
                                        <div className="absolute left-[calc(100%+0.75rem)] top-1/2 -translate-y-1/2 px-2.5 py-1.5 bg-slate-950 border border-slate-800 text-rose-400 rounded-tr-xl rounded-br-xl rounded-bl-xl rounded-tl-none text-[9px] font-black uppercase tracking-wider shadow-xl opacity-0 scale-95 translate-x-2 group-hover/btn:opacity-100 group-hover/btn:scale-100 group-hover/btn:translate-x-0 transition-all duration-200 pointer-events-none whitespace-nowrap z-[9999] flex items-center gap-1 border-l-2 border-l-rose-400 border-r-2 border-r-rose-400/80">
                                            <span>Log Out</span>
                                            {/* Custom Teardrop Arrow extending from top-left */}
                                            <div className="absolute right-full top-0 w-0 h-0 border-r-[6px] border-r-slate-950 border-b-[6px] border-b-transparent" />
                                        </div>
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            /* Expanded Standard User Profile details */
                            <>
                                <div className="flex items-center gap-3 mb-4">
                                    <div className="w-8 h-8 rounded-full bg-slate-900 border border-slate-800/80 p-1 flex items-center justify-center relative overflow-hidden group shadow-[0_0_8px_rgba(6,182,212,0.15)] shrink-0">
                                        <ApplicationLogo className="w-full h-full text-cyan-400 drop-shadow-[0_0_3px_rgba(6,182,212,0.8)]" />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-white truncate">{user.name}</p>
                                        <p className="text-xs text-slate-400 truncate">{user.email}</p>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between gap-2">
                                    <Link href={route('profile.edit')} className="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors flex-1 flex justify-center" title="Profile Settings">
                                        <Settings size={18} />
                                    </Link>
                                    <Link href={route('logout')} method="post" as="button" className="p-2 text-slate-400 hover:text-rose-400 hover:bg-slate-800 rounded-lg transition-colors flex-1 flex justify-center" title="Log Out">
                                        <LogOut size={18} />
                                    </Link>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

            {/* Main Content */}
            <div className="flex-1 flex flex-col min-w-0 max-h-screen overflow-hidden">
                {/* Top Header */}
                <header className="bg-white border-b border-gray-200 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8 shrink-0">
                    <div className="flex items-center">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="mr-4 lg:hidden text-gray-500 hover:text-gray-700"
                        >
                            <Menu size={24} />
                        </button>

                        {/* Desktop Sidebar Collapse Toggle */}
                        <button
                            onClick={toggleSidebar}
                            className="hidden lg:flex items-center justify-center p-2 rounded-lg text-gray-500 hover:text-cyan-600 hover:bg-gray-100 transition-all duration-200 mr-4"
                            title={sidebarCollapsed ? "Expand Sidebar" : "Collapse Sidebar"}
                        >
                            {sidebarCollapsed ? <ChevronRight size={20} /> : <ChevronLeft size={20} />}
                        </button>

                        <div className="flex-1 text-xl font-semibold text-gray-800 lg:block hidden">
                            IPOS Admin
                        </div>
                    </div>

                    <div className="flex items-center gap-4">
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button className="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors bg-gray-50 hover:bg-gray-100/80 px-2.5 py-1.5 rounded-full border border-gray-200/80 shadow-sm">
                                    <div className="w-5 h-5 rounded-full bg-slate-950 p-0.5 border border-slate-800 shadow-[0_0_4px_rgba(6,182,212,0.15)] flex items-center justify-center">
                                        <ApplicationLogo className="w-full h-full text-cyan-400 drop-shadow-[0_0_1.5px_rgba(6,182,212,0.8)]" />
                                    </div>
                                    <span>{user.name}</span>
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                <Dropdown.Link href={route('logout')} method="post" as="button" className="text-rose-600 hover:text-rose-700">Log Out</Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                {/* Page Content */}
                <main className="flex-1 overflow-y-auto bg-gray-50 relative">
                    {header && (
                        <header className="bg-white shadow-sm border-b border-gray-200">
                            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                                {header}
                            </div>
                        </header>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
