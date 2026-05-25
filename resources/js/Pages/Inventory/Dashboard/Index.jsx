import React, { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    LayoutDashboard,
    Filter,
    AlertTriangle,
    ArrowDownCircle,
    Archive,
    Building2,
    Search,
    ArrowRight,
    Activity,
    Scale,
    ClipboardList,
    Boxes,
} from 'lucide-react';

export default function Index({
    auth,
    branches = [],
    categories = [],
    filters = {},
    summary = {},
    branchSummaries = [],
    productVisibility = [],
    reorderPriorities = [],
    movementSummary = {},
    permissions = {},
}) {
    const canViewMovements = auth.permissions.includes('view_branch_inventory');
    const canViewStocktakes = auth.permissions.includes('inventory.stocktake.view');
    const canViewVariance = auth.permissions.includes('view_inventory_reports') || auth.permissions.includes('audit_inventory');
    const canViewUnitConversions = auth.permissions.includes('manage_unit_conversions') || auth.permissions.includes('manage_inventory');
    const canViewCosts = !!permissions.can_view_costs;

    const [branchId, setBranchId] = useState(filters.branch_id || '');
    const [product, setProduct] = useState(filters.product || '');
    const [categoryId, setCategoryId] = useState(filters.category_id || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [priority, setPriority] = useState(filters.priority || 'all');
    const [days, setDays] = useState(String(filters.days || 30));

    const applyFilters = (e) => {
        e.preventDefault();

        router.get(
            route('inventory.dashboard.index'),
            {
                branch_id: branchId || undefined,
                product: product || undefined,
                category_id: categoryId || undefined,
                status,
                priority,
                days,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        );
    };

    const clearFilters = () => {
        setProduct('');
        setCategoryId('');
        setStatus('all');
        setPriority('all');
        setDays('30');

        router.get(
            route('inventory.dashboard.index'),
            {
                branch_id: branchId || undefined,
                category_id: undefined,
                status: 'all',
                priority: 'all',
                days: 30,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        );
    };

    const negativeRows = useMemo(
        () => productVisibility.filter((row) => row.stock_state === 'negative'),
        [productVisibility]
    );

    const selectedBranchName = useMemo(() => {
        const selected = branches.find((b) => b.id === (filters.branch_id || branchId));
        return selected ? selected.name : 'Selected Branch';
    }, [branches, filters.branch_id, branchId]);

    const formatQty = (value) => Number.parseFloat(value || 0).toFixed(2);
    const formatCurrency = (value) => Number.parseFloat(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">
                            <LayoutDashboard size={12} />
                            Inventory Visibility
                        </div>
                        <h2 className="mt-2 text-2xl font-bold leading-tight text-slate-900">Inventory Overview Dashboard</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Read-only summary view from existing inventory surfaces. No inventory mutation controls are enabled.
                        </p>
                    </div>
                </div>
            }
        >
            <Head title="Inventory Overview" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                    <section className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                        <form onSubmit={applyFilters} className="space-y-4">
                            <div className="flex items-center gap-2">
                                <Filter size={16} className="text-indigo-600" />
                                <h3 className="text-sm font-black uppercase tracking-widest text-slate-700">Stock Visibility Filters</h3>
                            </div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Read-only filters use existing data paths and never trigger stock updates.
                            </p>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-6">
                                <div>
                                    <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Branch</label>
                                    <div className="relative">
                                        <Building2 size={14} className="absolute left-3 top-3.5 text-slate-400" />
                                        <select
                                            value={branchId}
                                            onChange={(e) => setBranchId(e.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                                        >
                                            {branches.map((branch) => (
                                                <option key={branch.id} value={branch.id}>{branch.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Product</label>
                                    <div className="relative">
                                        <Search size={14} className="absolute left-3 top-3.5 text-slate-400" />
                                        <input
                                            value={product}
                                            onChange={(e) => setProduct(e.target.value)}
                                            placeholder="Name or SKU"
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Category</label>
                                    <select
                                        value={categoryId}
                                        onChange={(e) => setCategoryId(e.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                                    >
                                        <option value="">All Categories</option>
                                        {categories.map((category) => (
                                            <option key={category.id} value={category.id}>{category.name}</option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Stock Status</label>
                                    <select
                                        value={status}
                                        onChange={(e) => setStatus(e.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                                    >
                                        <option value="all">All</option>
                                        <option value="low">Low Stock</option>
                                        <option value="negative">Negative Stock</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Reorder Priority</label>
                                    <select
                                        value={priority}
                                        onChange={(e) => setPriority(e.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                                    >
                                        <option value="all">All Priorities</option>
                                        <option value="critical">Critical (Negative)</option>
                                        <option value="high">High (At/Below Reorder)</option>
                                        <option value="normal">Normal</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Movement Range</label>
                                    <select
                                        value={days}
                                        onChange={(e) => setDays(e.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                                    >
                                        <option value="7">Last 7 Days</option>
                                        <option value="30">Last 30 Days</option>
                                        <option value="90">Last 90 Days</option>
                                    </select>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <button
                                    type="submit"
                                    className="h-10 rounded-xl bg-indigo-600 px-4 text-[10px] font-black uppercase tracking-widest text-white hover:bg-indigo-500"
                                >
                                    Apply Filters
                                </button>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="h-10 rounded-xl border border-slate-200 bg-white px-4 text-[10px] font-black uppercase tracking-widest text-slate-600 hover:bg-slate-50"
                                >
                                    Reset
                                </button>
                            </div>
                        </form>
                    </section>

                    <section className="grid grid-cols-1 gap-6 md:grid-cols-5">
                        <article className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Tracked Inventory Items</p>
                            <p className="mt-2 text-3xl font-black text-slate-800">{summary.tracked_items ?? 0}</p>
                            <p className="mt-2 text-xs text-slate-500">Items from existing tracked inventory records.</p>
                        </article>

                        <article className="rounded-[28px] border border-amber-200 bg-amber-50/50 p-6 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-amber-700">Low Stock Count</p>
                            <p className="mt-2 text-3xl font-black text-amber-800">{summary.low_stock_count ?? 0}</p>
                            <p className="mt-2 text-xs text-amber-800">At or below configured reorder level.</p>
                        </article>

                        <article className="rounded-[28px] border border-rose-200 bg-rose-50/50 p-6 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-rose-700">Negative Stock Count</p>
                            <p className="mt-2 text-3xl font-black text-rose-800">{summary.negative_stock_count ?? 0}</p>
                            <p className="mt-2 text-xs text-rose-800">Below zero and needs investigation.</p>
                        </article>

                        <article className="rounded-[28px] border border-indigo-200 bg-indigo-50/60 p-6 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-indigo-700">Suggested Reorder Units</p>
                            <p className="mt-2 text-3xl font-black text-indigo-800">{formatQty(summary.suggested_reorder_units ?? 0)}</p>
                            <p className="mt-2 text-xs text-indigo-800">Advisory quantity only. No purchase order is generated.</p>
                        </article>

                        <article className="rounded-[28px] border border-slate-200 bg-slate-50 p-6 shadow-sm">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-600">Estimated Reorder Value</p>
                            {canViewCosts ? (
                                <>
                                    <p className="mt-2 text-3xl font-black text-slate-800">{formatCurrency(summary.estimated_reorder_value ?? 0)}</p>
                                    <p className="mt-2 text-xs text-slate-600">Based on branch average cost and advisory reorder quantity.</p>
                                </>
                            ) : (
                                <>
                                    <p className="mt-2 text-lg font-black text-slate-500">Masked</p>
                                    <p className="mt-2 text-xs text-slate-500">Cost visibility requires inventory audit permission.</p>
                                </>
                            )}
                        </article>
                    </section>

                    <section className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-black uppercase tracking-widest text-slate-700">Reorder Priority Queue (Read-Only)</h3>
                        <p className="mt-2 text-xs text-slate-500">
                            Advisory queue from current stock and reorder levels. No procurement automation or stock mutation is triggered.
                        </p>

                        {reorderPriorities.length > 0 ? (
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                                            <th className="py-2">Priority</th>
                                            <th className="py-2">Product</th>
                                            <th className="py-2">Category</th>
                                            <th className="py-2">Branch</th>
                                            <th className="py-2">Current</th>
                                            <th className="py-2">Reorder</th>
                                            <th className="py-2">Suggest Qty</th>
                                            {canViewCosts && <th className="py-2">Est. Value</th>}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {reorderPriorities.map((row, idx) => (
                                            <tr key={`${row.sku}-priority-${idx}`} className="border-b border-slate-50">
                                                <td className="py-2">
                                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider ${
                                                        row.priority_class === 'critical'
                                                            ? 'bg-rose-100 text-rose-700'
                                                            : 'bg-amber-100 text-amber-700'
                                                    }`}>
                                                        {row.priority_class}
                                                    </span>
                                                </td>
                                                <td className="py-2 font-bold text-slate-700">{row.product_name} <span className="text-slate-400">({row.sku})</span></td>
                                                <td className="py-2 text-slate-600">{row.category_name || 'Uncategorized'}</td>
                                                <td className="py-2 text-slate-600">{row.branch_name}</td>
                                                <td className="py-2 text-slate-700">{formatQty(row.current_stock)}</td>
                                                <td className="py-2 text-slate-500">{formatQty(row.reorder_level)}</td>
                                                <td className="py-2 font-semibold text-indigo-700">{formatQty(row.recommended_reorder_units)}</td>
                                                {canViewCosts && <td className="py-2 text-slate-700">{formatCurrency(row.estimated_reorder_value)}</td>}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-xs font-semibold text-slate-500">
                                No products currently qualify for reorder priority under active filters.
                            </div>
                        )}
                    </section>

                    <section className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <article className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                            <div className="flex items-center gap-2">
                                <Archive size={16} className="text-slate-700" />
                                <h3 className="text-sm font-black uppercase tracking-widest text-slate-700">Stock By Branch</h3>
                            </div>
                            <p className="mt-2 text-xs text-slate-500">Read-only branch visibility using existing inventory records.</p>

                            {branchSummaries.length > 0 ? (
                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                                                <th className="py-2">Branch</th>
                                                <th className="py-2">Items</th>
                                                <th className="py-2">Low</th>
                                                <th className="py-2">Negative</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {branchSummaries.map((row) => (
                                                <tr key={row.branch_id} className="border-b border-slate-50">
                                                    <td className="py-2 font-bold text-slate-700">{row.branch_name}</td>
                                                    <td className="py-2 text-slate-600">{row.item_count}</td>
                                                    <td className="py-2 text-amber-700 font-semibold">{row.low_count}</td>
                                                    <td className="py-2 text-rose-700 font-semibold">{row.negative_count}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-xs font-semibold text-slate-500">
                                    No branch inventory rows matched the current filters.
                                </div>
                            )}
                        </article>

                        <article className="rounded-[28px] border border-amber-200 bg-amber-50/50 p-6 shadow-sm">
                            <div className="flex items-center gap-2">
                                <AlertTriangle size={16} className="text-amber-600" />
                                <h3 className="text-sm font-black uppercase tracking-widest text-amber-800">Low Stock Legend</h3>
                            </div>
                            <p className="mt-3 text-xs text-amber-800">
                                Stock states are read-only indicators sourced from current inventory rows.
                            </p>
                            <ul className="mt-3 space-y-2 text-xs font-semibold text-amber-900">
                                <li className="rounded-xl bg-white/70 px-3 py-2">Low Stock: at or below reorder level</li>
                                <li className="rounded-xl bg-white/70 px-3 py-2">Negative Stock: current stock is below zero</li>
                            </ul>
                        </article>
                    </section>

                    <section className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <article className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                            <div className="flex items-center gap-2">
                                <Boxes size={16} className="text-slate-700" />
                                <h3 className="text-sm font-black uppercase tracking-widest text-slate-700">Product Stock Visibility</h3>
                            </div>
                            <p className="mt-2 text-xs text-slate-500">
                                Showing filtered products for {selectedBranchName}. Read-only snapshot from existing inventory records.
                            </p>

                            {productVisibility.length > 0 ? (
                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                                                <th className="py-2">Product</th>
                                                <th className="py-2">Category</th>
                                                <th className="py-2">SKU</th>
                                                <th className="py-2">Current</th>
                                                <th className="py-2">Reorder</th>
                                                <th className="py-2">Suggest Qty</th>
                                                {canViewCosts && <th className="py-2">Est. Value</th>}
                                                <th className="py-2">State</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {productVisibility.map((row, idx) => (
                                                <tr key={`${row.sku}-${idx}`} className="border-b border-slate-50">
                                                    <td className="py-2 font-bold text-slate-700">{row.product_name}</td>
                                                    <td className="py-2 text-slate-500">{row.category_name || 'Uncategorized'}</td>
                                                    <td className="py-2 text-slate-500">{row.sku}</td>
                                                    <td className="py-2 text-slate-700">{formatQty(row.current_stock)}</td>
                                                    <td className="py-2 text-slate-500">{formatQty(row.reorder_level)}</td>
                                                    <td className="py-2 font-semibold text-indigo-700">{formatQty(row.recommended_reorder_units)}</td>
                                                    {canViewCosts && <td className="py-2 text-slate-700">{formatCurrency(row.estimated_reorder_value)}</td>}
                                                    <td className="py-2">
                                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider ${
                                                            row.stock_state === 'negative'
                                                                ? 'bg-rose-100 text-rose-700'
                                                                : row.stock_state === 'low'
                                                                    ? 'bg-amber-100 text-amber-700'
                                                                    : 'bg-emerald-100 text-emerald-700'
                                                        }`}>
                                                            {row.stock_state}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-xs font-semibold text-slate-500">
                                    No product stock rows matched the current filters.
                                </div>
                            )}
                        </article>

                        <article className="rounded-[28px] border border-rose-200 bg-rose-50/50 p-6 shadow-sm">
                            <div className="flex items-center gap-2">
                                <ArrowDownCircle size={16} className="text-rose-600" />
                                <h3 className="text-sm font-black uppercase tracking-widest text-rose-800">Negative Stock Spotlight</h3>
                            </div>
                            <p className="mt-2 text-xs text-rose-800">Products currently below zero stock under active filters.</p>

                            {negativeRows.length > 0 ? (
                                <ul className="mt-4 space-y-2">
                                    {negativeRows.slice(0, 5).map((row, idx) => (
                                        <li key={`${row.sku}-neg-${idx}`} className="rounded-xl border border-rose-200 bg-white/80 px-3 py-2">
                                            <p className="text-[11px] font-black text-rose-800">{row.product_name}</p>
                                            <p className="text-[10px] font-semibold text-rose-700">{row.sku} • {formatQty(row.current_stock)}</p>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <div className="mt-4 rounded-2xl border border-dashed border-rose-200 bg-white/80 px-3 py-4 text-xs font-semibold text-rose-700">
                                    No negative-stock rows for current filters.
                                </div>
                            )}
                        </article>
                    </section>

                    <section className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-black uppercase tracking-widest text-slate-700">Movement Summary</h3>
                        <p className="mt-2 text-xs text-slate-500">
                            Existing read-path movement summary for the selected branch over the last {movementSummary.period_days ?? 30} days.
                        </p>

                        {canViewMovements ? (
                            <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Movement Rows</p>
                                    <p className="mt-1 text-2xl font-black text-slate-800">{movementSummary.total_count ?? 0}</p>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 md:col-span-2">
                                    <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Top Movement Types</p>
                                    {movementSummary.type_counts && movementSummary.type_counts.length > 0 ? (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {movementSummary.type_counts.map((row) => (
                                                <span key={row.movement_type} className="rounded-full bg-white px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-700 border border-slate-200">
                                                    {row.movement_type} ({row.total})
                                                </span>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="mt-2 text-xs font-semibold text-slate-500">No movement rows in this period.</p>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-xs font-semibold text-slate-500">
                                Movement summary requires inventory movement view permission.
                            </div>
                        )}
                    </section>

                    <section className="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-black uppercase tracking-widest text-slate-700">Movement And Inventory Surfaces</h3>
                        <p className="mt-2 text-xs text-slate-500">
                            Existing read-path links are provided for operator navigation. No stock mutation actions are included.
                        </p>

                        <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            {canViewStocktakes && (
                                <Link href={route('inventory.stocktakes.index')} className="group flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 hover:bg-indigo-50 hover:border-indigo-200 transition-all">
                                    <span className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-700">
                                        <ClipboardList size={14} className="text-slate-500 group-hover:text-indigo-600" />
                                        Stocktakes
                                    </span>
                                    <ArrowRight size={14} className="text-slate-400 group-hover:text-indigo-600" />
                                </Link>
                            )}

                            {canViewVariance && (
                                <Link href={route('inventory.reports.variance-logs.index')} className="group flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 hover:bg-indigo-50 hover:border-indigo-200 transition-all">
                                    <span className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-700">
                                        <AlertTriangle size={14} className="text-slate-500 group-hover:text-indigo-600" />
                                        Variance Logs
                                    </span>
                                    <ArrowRight size={14} className="text-slate-400 group-hover:text-indigo-600" />
                                </Link>
                            )}

                            {canViewVariance && (
                                <Link href={route('inventory.reports.product-composition.index')} className="group flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 hover:bg-indigo-50 hover:border-indigo-200 transition-all">
                                    <span className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-700">
                                        <Boxes size={14} className="text-slate-500 group-hover:text-indigo-600" />
                                        Product Composition
                                    </span>
                                    <ArrowRight size={14} className="text-slate-400 group-hover:text-indigo-600" />
                                </Link>
                            )}

                            {canViewUnitConversions && (
                                <Link href={route('inventory.unit-conversions.index')} className="group flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 hover:bg-indigo-50 hover:border-indigo-200 transition-all">
                                    <span className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-700">
                                        <Scale size={14} className="text-slate-500 group-hover:text-indigo-600" />
                                        Unit Conversions
                                    </span>
                                    <ArrowRight size={14} className="text-slate-400 group-hover:text-indigo-600" />
                                </Link>
                            )}

                            {canViewMovements && (
                                <a href={route('inventory.movements.index')} className="group flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 hover:bg-indigo-50 hover:border-indigo-200 transition-all">
                                    <span className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-700">
                                        <Activity size={14} className="text-slate-500 group-hover:text-indigo-600" />
                                        Inventory Movements
                                    </span>
                                    <ArrowRight size={14} className="text-slate-400 group-hover:text-indigo-600" />
                                </a>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
