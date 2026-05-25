import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
  Search,
  RefreshCw,
  Download,
  Printer,
  AlertTriangle,
  GitBranch,
  Package,
  Layers,
  ArrowUpDown,
} from 'lucide-react';

export default function ProductCompositionIndex({
  auth,
  rows,
  filters,
  branches,
  categories,
  semantics,
  permissions,
}) {
  const [search, setSearch] = useState(filters.search || '');
  const [categoryId, setCategoryId] = useState(filters.category_id || '');
  const [productType, setProductType] = useState(filters.product_type || '');
  const [branchId, setBranchId] = useState(filters.branch_id || '');
  const [expansionMode, setExpansionMode] = useState(filters.expansion_mode || 'direct_only');
  const [maxDepth, setMaxDepth] = useState(String(filters.max_depth || 5));

  const branchSelected = !!branchId;
  const canViewCosts = !!permissions?.can_view_costs;

  const queryParams = useMemo(() => ({
    search: search || undefined,
    category_id: categoryId || undefined,
    product_type: productType || undefined,
    branch_id: branchId || undefined,
    expansion_mode: expansionMode || undefined,
    max_depth: maxDepth || undefined,
  }), [search, categoryId, productType, branchId, expansionMode, maxDepth]);

  const applyFilters = (e) => {
    e.preventDefault();

    router.get(route('inventory.reports.product-composition.index'), queryParams, {
      preserveScroll: true,
      preserveState: true,
      replace: true,
    });
  };

  const resetFilters = () => {
    setSearch('');
    setCategoryId('');
    setProductType('');
    setBranchId('');
    setExpansionMode('direct_only');
    setMaxDepth('5');

    router.get(route('inventory.reports.product-composition.index'), {}, {
      preserveScroll: true,
      preserveState: true,
      replace: true,
    });
  };

  const goToPage = (url) => {
    if (!url) {
      return;
    }

    router.visit(url, {
      preserveScroll: true,
      preserveState: true,
      replace: true,
    });
  };

  const exportCsv = () => {
    const params = new URLSearchParams();
    Object.entries(queryParams).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.append(key, String(value));
      }
    });

    const url = `${route('inventory.reports.product-composition.export')}?${params.toString()}`;
    window.location.href = url;
  };

  const printReport = () => {
    window.print();
  };

  const formatNumber = (value, decimals = 4) => {
    if (value === null || value === undefined || value === '') {
      return '—';
    }

    return Number.parseFloat(value).toFixed(decimals);
  };

  const formatWarning = (warning) => warning
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

  const data = rows?.data || [];

  return (
    <AuthenticatedLayout
      user={auth.user}
      header={
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Product Ingredient Composition</h2>
            <p className="text-sm text-slate-500 font-medium mt-1">
              Read-only composition report with optional branch stock and cost context.
            </p>
          </div>
          <div className="flex items-center gap-2 print:hidden">
            <button
              onClick={printReport}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-700 rounded-xl font-bold text-sm hover:bg-slate-50 transition-all"
            >
              <Printer size={16} />
              Print View
            </button>
            <button
              onClick={exportCsv}
              className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
            >
              <Download size={16} />
              Export CSV
            </button>
          </div>
        </div>
      }
    >
      <Head title="Product Ingredient Composition" />

      <div className="py-8 print:py-0">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
          <section className="hidden print:block border-b border-slate-300 pb-3">
            <h1 className="text-lg font-black text-slate-900">Product Ingredient Composition</h1>
            <p className="text-xs text-slate-500 mt-1">
              Generated {new Date().toLocaleString()} • Expansion mode: {expansionMode === 'flatten_subrecipes' ? 'Flatten Sub-Recipes' : 'Direct Only'}
            </p>
            {!canViewCosts && (
              <p className="text-xs text-slate-500 mt-1">
                Cost fields are masked for this role in both on-screen and printed views.
              </p>
            )}
          </section>

          {semantics?.mode === 'flatten_subrecipes' && semantics?.banner && (
            <section className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
              <div className="flex items-start gap-2">
                <AlertTriangle size={16} className="mt-0.5 text-amber-600" />
                <p className="text-sm font-semibold text-amber-800">{semantics.banner}</p>
              </div>
            </section>
          )}

          <section className="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm print:hidden">
            <form onSubmit={applyFilters} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div className="lg:col-span-2">
                  <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Search</label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none">
                      <Search size={14} />
                    </div>
                    <input
                      type="text"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder="Parent or ingredient name/SKU"
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
                  <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Product Type</label>
                  <select
                    value={productType}
                    onChange={(e) => setProductType(e.target.value)}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                  >
                    <option value="">All Types</option>
                    <option value="finished_good">Finished Good</option>
                    <option value="semi_finished">Semi-Finished</option>
                    <option value="raw_material">Raw Material</option>
                  </select>
                </div>

                <div>
                  <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Branch</label>
                  <select
                    value={branchId}
                    onChange={(e) => setBranchId(e.target.value)}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                  >
                    <option value="">All Branches</option>
                    {branches.map((branch) => (
                      <option key={branch.id} value={branch.id}>{branch.name}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Expansion Mode</label>
                  <select
                    value={expansionMode}
                    onChange={(e) => setExpansionMode(e.target.value)}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                  >
                    <option value="direct_only">Direct Only</option>
                    <option value="flatten_subrecipes">Flatten Sub-Recipes</option>
                  </select>
                </div>

                <div>
                  <label className="mb-2 block text-[10px] font-black uppercase tracking-widest text-slate-400">Max Depth</label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={maxDepth}
                    onChange={(e) => setMaxDepth(e.target.value)}
                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-semibold text-slate-700 focus:border-indigo-400 focus:ring-indigo-200"
                  />
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
                  onClick={resetFilters}
                  className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 text-[10px] font-black uppercase tracking-widest text-slate-600 hover:bg-slate-50"
                >
                  <RefreshCw size={12} />
                  Reset
                </button>
              </div>
            </form>
          </section>

          <section className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden print:rounded-none print:border-slate-300 print:shadow-none">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead className="sticky top-0 z-10 bg-slate-50/95 backdrop-blur">
                  <tr>
                    <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Parent Product</th>
                    <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Ingredient</th>
                    <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Direct Qty/Unit</th>
                    <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Effective Base Qty/Unit</th>
                    <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Path/Depth</th>
                    <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Warnings</th>
                    {branchSelected && (
                      <>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Branch Stock</th>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Reorder</th>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Coverage</th>
                      </>
                    )}
                    {branchSelected && canViewCosts && (
                      <>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Branch Avg Cost</th>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Fallback Cost</th>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Effective Cost/Parent Unit</th>
                        <th className="px-4 py-3 font-black uppercase tracking-widest text-slate-400">Cost Status</th>
                      </>
                    )}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {data.length > 0 ? data.map((row, idx) => (
                    <tr key={`${row.parent_product_id}-${row.ingredient_id}-${idx}`} className="hover:bg-slate-50/50 print:break-inside-avoid">
                      <td className="px-4 py-3 align-top">
                        <div className="flex items-center gap-2 font-bold text-slate-700">
                          <Package size={13} className="text-slate-400" />
                          <span>{row.parent_product_name}</span>
                        </div>
                        <div className="text-[10px] text-slate-400 uppercase tracking-wider">{row.parent_product_sku}</div>
                      </td>
                      <td className="px-4 py-3 align-top">
                        <div className="flex items-center gap-2 font-bold text-slate-700">
                          <Layers size={13} className="text-slate-400" />
                          <span>{row.ingredient_name}</span>
                        </div>
                        <div className="text-[10px] text-slate-400 uppercase tracking-wider">{row.ingredient_sku} • {row.ingredient_product_type}</div>
                      </td>
                      <td className="px-4 py-3 align-top font-semibold text-slate-700">
                        {formatNumber(row.direct_quantity)} {row.direct_unit}
                      </td>
                      <td className="px-4 py-3 align-top font-semibold text-slate-700">
                        {formatNumber(row.effective_quantity_base)} {row.ingredient_base_unit || ''}
                      </td>
                      <td className="px-4 py-3 align-top">
                        <div className="font-semibold text-slate-700">{row.path_signature || '—'}</div>
                        <div className="mt-1 inline-flex items-center gap-1 rounded-full border border-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                          <ArrowUpDown size={10} />
                          Depth {row.depth}
                        </div>
                      </td>
                      <td className="px-4 py-3 align-top">
                        <div className="flex flex-wrap gap-1.5">
                          {row.conversion_status === 'missing_rule' && (
                            <span className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-700">
                              Conversion Missing
                            </span>
                          )}
                          {row.recursion_status !== 'ok' && (
                            <span className="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-rose-700">
                              {formatWarning(row.recursion_status)}
                            </span>
                          )}
                          {(row.row_warnings || []).map((warning) => (
                            <span
                              key={warning}
                              className="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-600"
                            >
                              {formatWarning(warning)}
                            </span>
                          ))}
                          {row.conversion_status !== 'missing_rule' && row.recursion_status === 'ok' && (row.row_warnings || []).length === 0 && (
                            <span className="text-xs font-semibold text-slate-400">None</span>
                          )}
                        </div>
                      </td>

                      {branchSelected && (
                        <>
                          <td className="px-4 py-3 align-top font-semibold text-slate-700">{formatNumber(row.branch_current_stock)}</td>
                          <td className="px-4 py-3 align-top font-semibold text-slate-700">{formatNumber(row.branch_reorder_level)}</td>
                          <td className="px-4 py-3 align-top font-semibold text-slate-700">{formatNumber(row.coverage_ingredient_parent_units, 2)}</td>
                        </>
                      )}

                      {branchSelected && canViewCosts && (
                        <>
                          <td className="px-4 py-3 align-top font-semibold text-slate-700">{formatNumber(row.branch_average_cost)}</td>
                          <td className="px-4 py-3 align-top font-semibold text-slate-700">{formatNumber(row.fallback_cost_price)}</td>
                          <td className="px-4 py-3 align-top font-semibold text-slate-700">{formatNumber(row.effective_cost_per_parent_unit)}</td>
                          <td className="px-4 py-3 align-top">
                            {row.cost_status ? (
                              <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-600">
                                {formatWarning(row.cost_status)}
                              </span>
                            ) : (
                              <span className="text-xs font-semibold text-slate-400">—</span>
                            )}
                          </td>
                        </>
                      )}
                    </tr>
                  )) : (
                    <tr>
                      <td colSpan={branchSelected && canViewCosts ? 13 : branchSelected ? 9 : 6} className="px-6 py-20 text-center">
                        <div className="flex flex-col items-center justify-center">
                          <div className="p-4 bg-slate-50 rounded-full mb-3 text-slate-300">
                            <GitBranch size={36} />
                          </div>
                          <h3 className="text-sm font-bold text-slate-700">No composition rows found</h3>
                          <p className="text-slate-400 text-xs mt-1">Try adjusting filters or expansion mode.</p>
                        </div>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <div className="flex flex-col gap-3 border-t border-slate-100 px-4 py-3 text-xs sm:flex-row sm:items-center sm:justify-between print:hidden">
              <p className="font-semibold text-slate-500">
                Showing {rows.from || 0} to {rows.to || 0} of {rows.total || 0} rows
              </p>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => goToPage(rows.prev_page_url)}
                  disabled={!rows.prev_page_url}
                  className="rounded-lg border border-slate-200 px-3 py-1.5 font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  Previous
                </button>
                <span className="rounded-lg bg-slate-100 px-3 py-1.5 font-black text-slate-600">Page {rows.current_page || 1}</span>
                <button
                  type="button"
                  onClick={() => goToPage(rows.next_page_url)}
                  disabled={!rows.next_page_url}
                  className="rounded-lg border border-slate-200 px-3 py-1.5 font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            </div>
          </section>

          <p className="hidden print:block text-[10px] text-slate-500">
            Rows shown: {rows.from || 0} to {rows.to || 0} of {rows.total || 0}
          </p>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
