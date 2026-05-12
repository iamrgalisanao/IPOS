import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import MetricCard from '@/Components/Dashboard/MetricCard';
import StatusCard from '@/Components/Dashboard/StatusCard';
import BranchSelector from '@/Components/Dashboard/BranchSelector';
import SettlementEvidenceCard from '@/Components/Dashboard/SettlementEvidenceCard';
import ShiftStatusCard from '@/Components/Dashboard/ShiftStatusCard';
import { 
    TrendingUp, 
    ShoppingCart, 
    RefreshCcw, 
    PackageSearch, 
    CreditCard, 
    AlertTriangle, 
    CheckCircle2,
    Clock,
    History,
    Store,
    Globe,
    Layers3
} from 'lucide-react';

export default function Dashboard({ auth, pulse, branches }) {
    const permissions = Array.isArray(auth?.permissions) ? auth.permissions : [];
    const hasMultiBranchPermission = permissions.includes('view_multi_branch_dashboard');
    const isTenantScope = pulse.scope.mode === 'tenant';
    const paymentMix = Array.isArray(pulse.payments.by_method) ? pulse.payments.by_method : [];
    const criticalItems = Array.isArray(pulse.inventory.critical_items) ? pulse.inventory.critical_items : [];
    const salesCount = Number(pulse.sales.sale_count || 0);
    const grossSales = parseFloat(pulse.sales.gross_sales_total || 0);
    const refundTotal = parseFloat(pulse.sales.refund_total || 0);
    const voidTotal = parseFloat(pulse.sales.void_total || 0);
    const paymentTotal = parseFloat(pulse.payments.total || 0);
    const syncPending = Number(pulse.accounting_sync.pending || 0) + Number(pulse.accounting_sync.processing || 0);

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2
        }).format(parseFloat(val || 0));
    };

    const formatCompactCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(parseFloat(val || 0));
    };

    const formatPercent = (val) => {
        return `${(Number(val || 0) * 100).toFixed(1)}%`;
    };

    const formatDate = (dateStr) => {
        return new Date(dateStr).toLocaleString('en-PH', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    // Helper for sync health badge
    const getSyncStatusColor = (counts) => {
        if (counts.failed > 0) return 'text-rose-600';
        if (counts.pending > 0 || counts.processing > 0) return 'text-amber-600';
        return 'text-emerald-600';
    };

    const refundRate = grossSales > 0 ? refundTotal / grossSales : 0;
    const voidRate = grossSales > 0 ? voidTotal / grossSales : 0;

    const watchlist = [
        pulse.accounting_sync.failed > 0
            ? {
                tone: 'rose',
                title: 'Accounting sync failures',
                detail: `${pulse.accounting_sync.failed} ${pulse.accounting_sync.failed === 1 ? 'event needs' : 'events need'} attention`,
            }
            : null,
        syncPending > 0
            ? {
                tone: 'amber',
                title: 'Syncs still in flight',
                detail: `${syncPending} ${syncPending === 1 ? 'event is' : 'events are'} pending or processing`,
            }
            : null,
        pulse.inventory.low_stock_count > 0
            ? {
                tone: 'amber',
                title: 'Low-stock items',
                detail: `${pulse.inventory.low_stock_count} SKU${pulse.inventory.low_stock_count === 1 ? '' : 's'} below threshold`,
            }
            : null,
        pulse.shift.pending_review_count > 0
            ? {
                tone: 'blue',
                title: 'Submitted shifts awaiting review',
                detail: `${pulse.shift.pending_review_count} ${pulse.shift.pending_review_count === 1 ? 'shift' : 'shifts'} ready for reconciliation`,
            }
            : null,
        pulse.shift.is_pos_user && !pulse.shift.active_shift_id
            ? {
                tone: 'slate',
                title: 'No active shift for current user',
                detail: 'POS activity cannot begin until a shift is opened',
            }
            : null,
        pulse.settlement.yesterday_status && pulse.settlement.yesterday_status !== 'locked'
            ? {
                tone: 'slate',
                title: 'Previous settlement still open',
                detail: `Yesterday closed as ${pulse.settlement.yesterday_status}`,
            }
            : null,
    ].filter(Boolean);

    const toneClasses = {
        rose: 'border-rose-200 bg-rose-50 text-rose-700',
        amber: 'border-amber-200 bg-amber-50 text-amber-700',
        blue: 'border-blue-200 bg-blue-50 text-blue-700',
        slate: 'border-slate-200 bg-slate-50 text-slate-700',
    };

    const heroStats = [
        {
            label: 'Net Sales Today',
            value: formatCompactCurrency(pulse.sales.net_sales_total),
            hint: `${salesCount} recorded ${salesCount === 1 ? 'sale' : 'sales'}`,
        },
        {
            label: 'Payments Captured',
            value: formatCompactCurrency(paymentTotal),
            hint: paymentMix.length > 0 ? `${paymentMix.length} payment mix ${paymentMix.length === 1 ? 'source' : 'sources'}` : 'No payment mix yet',
        },
        {
            label: 'Pulse Health',
            value: pulse.accounting_sync.failed > 0 ? 'At Risk' : (syncPending > 0 ? 'Monitoring' : 'Healthy'),
            hint: pulse.accounting_sync.failed > 0 ? 'Sync failures need intervention' : (syncPending > 0 ? 'Events still processing' : 'No blocking issues'),
        },
    ];

    const scopeChips = [
        {
            icon: isTenantScope ? Globe : Store,
            label: isTenantScope ? 'Tenant-wide owner view' : 'Branch-specific pulse',
        },
        {
            icon: Layers3,
            label: `${branches.length} accessible ${branches.length === 1 ? 'branch' : 'branches'}`,
        },
        {
            icon: History,
            label: `Updated ${formatDate(pulse.freshness.generated_at)}`,
        },
    ];

    const pageSummary = isTenantScope
        ? 'This view summarizes sales, operational risk, and settlement readiness across the tenant so owners can spot exceptions before drilling into branches.'
        : 'This branch pulse emphasizes day-of-trade performance and the operational issues that need follow-up in the selected branch.';

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-3">
                        <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">
                            <TrendingUp size={12} />
                            {isTenantScope ? 'Owner Overview' : 'Branch Overview'}
                        </div>
                        <div>
                            <h2 className="text-2xl font-semibold leading-tight text-slate-900 sm:text-3xl">
                                {pulse.scope.label}
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                {pageSummary}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {scopeChips.map((chip) => {
                                const Icon = chip.icon;

                                return (
                                    <span
                                        key={chip.label}
                                        className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm"
                                    >
                                        <Icon size={13} className="text-slate-400" />
                                        {chip.label}
                                    </span>
                                );
                            })}
                        </div>
                    </div>
                    <BranchSelector 
                        branches={branches} 
                        currentBranchId={pulse.scope.branch_id}
                        hasMultiBranchPermission={hasMultiBranchPermission}
                    />
                </div>
            }
        >
            <Head title="Operational Pulse" />

            <div className="py-6 sm:py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6 sm:space-y-8">
                    <section className="relative overflow-hidden rounded-[28px] bg-slate-950 text-white shadow-xl shadow-slate-900/10">
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(56,189,248,0.18),_transparent_30%),radial-gradient(circle_at_bottom_left,_rgba(52,211,153,0.14),_transparent_30%)]" />
                        <div className="relative grid gap-6 p-6 sm:p-8 lg:grid-cols-[1.4fr_0.9fr] lg:items-start">
                            <div className="space-y-4">
                                <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-200">
                                    <Clock size={12} />
                                    Live operating pulse
                                </div>
                                <div className="space-y-2">
                                    <h3 className="text-2xl font-semibold leading-tight sm:text-3xl">
                                        Owner priorities, exceptions, and daily trade performance in one view.
                                    </h3>
                                    <p className="max-w-2xl text-sm leading-6 text-slate-300">
                                        Lead with top-line performance, then move directly into risks that need review before they impact cash flow, inventory, or settlement hygiene.
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-3 pt-2">
                                    {watchlist.length > 0 ? watchlist.slice(0, 3).map((item) => (
                                        <div
                                            key={item.title}
                                            className="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-100"
                                        >
                                            <p className="font-semibold">{item.title}</p>
                                            <p className="mt-1 text-xs text-slate-300">{item.detail}</p>
                                        </div>
                                    )) : (
                                        <div className="rounded-2xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100">
                                            <p className="font-semibold">No urgent owner exceptions right now</p>
                                            <p className="mt-1 text-xs text-emerald-200/90">Sales, sync health, inventory, and settlement checks are currently stable.</p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                                {heroStats.map((stat) => (
                                    <div
                                        key={stat.label}
                                        className="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur"
                                    >
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-300">
                                            {stat.label}
                                        </p>
                                        <p className="mt-3 text-2xl font-semibold text-white">
                                            {stat.value}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-300">
                                            {stat.hint}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6">
                        <MetricCard 
                            title="Gross Sales" 
                            value={formatCurrency(pulse.sales.gross_sales_total)}
                            icon={TrendingUp}
                            color="blue"
                            subtitle={`${salesCount} ${salesCount === 1 ? 'sale' : 'sales'} recorded today`}
                        />
                        <MetricCard 
                            title="Net Sales" 
                            value={formatCurrency(pulse.sales.net_sales_total)}
                            icon={ShoppingCart}
                            color="green"
                            subtitle={`${formatCurrency(paymentTotal)} collected across all methods`}
                        />
                        <MetricCard 
                            title="Refunds" 
                            value={formatCurrency(pulse.sales.refund_total)}
                            icon={RefreshCcw}
                            color="yellow"
                            subtitle={`${formatPercent(refundRate)} of gross sales`}
                        />
                        <MetricCard 
                            title="Voids" 
                            value={formatCurrency(pulse.sales.void_total)}
                            icon={AlertTriangle}
                            color="red"
                            subtitle={`${formatPercent(voidRate)} of gross sales`}
                        />
                    </div>

                    <div className="grid gap-6 xl:grid-cols-[1.6fr_1fr] xl:gap-8 items-start">
                        <div className="space-y-6">
                            <StatusCard
                                title="Owner Watchlist"
                                icon={AlertTriangle}
                                footer={watchlist.length > 0 ? 'Prioritized items that may require owner or manager follow-up.' : 'No active exceptions across the monitored areas.'}
                            >
                                {watchlist.length > 0 ? (
                                    <div className="space-y-3">
                                        {watchlist.map((item) => (
                                            <div
                                                key={item.title}
                                                className={`rounded-2xl border px-4 py-3 ${toneClasses[item.tone]}`}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-semibold">{item.title}</p>
                                                        <p className="mt-1 text-xs leading-5 opacity-90">{item.detail}</p>
                                                    </div>
                                                    <span className="rounded-full bg-white/60 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.18em]">
                                                        Watch
                                                    </span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-emerald-700">
                                        <CheckCircle2 size={20} className="mt-0.5 shrink-0" />
                                        <div>
                                            <p className="text-sm font-semibold">Everything currently reads healthy</p>
                                            <p className="mt-1 text-xs leading-5 text-emerald-700/90">
                                                No failed syncs, no low-stock flags, and no settlement or shift exceptions are demanding immediate attention.
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </StatusCard>

                            <div className="grid gap-6 lg:grid-cols-2 items-start">
                                <StatusCard
                                    title="Payment Method Mix"
                                    icon={CreditCard}
                                    footer={paymentMix.length > 0 ? 'Use this to spot channel concentration before it becomes a reconciliation issue.' : 'Payment distribution will appear once today has recorded transactions.'}
                                >
                                    <div className="space-y-4">
                                        {paymentMix.length > 0 ? (
                                            paymentMix.map((method) => {
                                                const percentage = paymentTotal > 0
                                                    ? (parseFloat(method.total) / paymentTotal) * 100
                                                    : 0;

                                                return (
                                                    <div key={method.code || method.name} className="space-y-2">
                                                        <div className="flex items-center justify-between gap-3 text-sm">
                                                            <span className="font-medium text-gray-700">{method.name || method.code || 'Unknown method'}</span>
                                                            <span className="text-gray-500">{formatCurrency(method.total)} ({percentage.toFixed(1)}%)</span>
                                                        </div>
                                                        <div className="h-2 w-full rounded-full bg-gray-100">
                                                            <div
                                                                className="h-2 rounded-full bg-blue-500"
                                                                style={{ width: `${Math.min(percentage, 100)}%` }}
                                                            />
                                                        </div>
                                                        <p className="text-xs text-gray-400">{method.count} {method.count === 1 ? 'payment' : 'payments'}</p>
                                                    </div>
                                                );
                                            })
                                        ) : (
                                            <div className="rounded-2xl border border-dashed border-gray-200 bg-gray-50/70 px-4 py-8 text-center text-gray-500">
                                                <CreditCard size={24} className="mx-auto mb-3 text-gray-300" />
                                                <p className="text-sm font-medium text-gray-600">No payment data for today</p>
                                                <p className="mt-1 text-xs text-gray-400">As sales are recorded, this card should reveal payment concentration and collection mix.</p>
                                            </div>
                                        )}
                                    </div>
                                </StatusCard>

                                <StatusCard 
                                    title="Low Stock Alerts" 
                                    icon={PackageSearch}
                                    footer={`${pulse.inventory.low_stock_count} ${pulse.inventory.low_stock_count === 1 ? 'item is' : 'items are'} currently below reorder threshold.`}
                                >
                                    <div className="space-y-3">
                                        {criticalItems.length > 0 ? (
                                            criticalItems.map((item) => (
                                                <div key={`${item.branch_id}-${item.product_id}`} className="flex items-center justify-between rounded-2xl border border-gray-100 bg-gray-50/70 px-4 py-3">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-medium text-gray-900 truncate">{item.name}</p>
                                                        <p className="mt-1 text-xs text-gray-500 truncate">{item.sku || 'No SKU'} • Reorder at {parseFloat(item.reorder_level).toFixed(0)}</p>
                                                    </div>
                                                    <div className="ml-4 text-right">
                                                        <p className="text-lg font-bold text-rose-600">{parseFloat(item.current_stock).toFixed(0)}</p>
                                                        <p className="text-[10px] uppercase tracking-[0.18em] text-gray-400">Stock left</p>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-8 text-center text-emerald-700">
                                                <CheckCircle2 size={24} className="mx-auto mb-3 text-emerald-500" />
                                                <p className="text-sm font-medium">Inventory levels are currently healthy</p>
                                                <p className="mt-1 text-xs text-emerald-700/80">No branch inventory records are below reorder threshold in this scope.</p>
                                            </div>
                                        )}
                                    </div>
                                </StatusCard>
                            </div>
                        </div>

                        <div className="space-y-6">
                            <StatusCard 
                                title="Accounting Sync Health" 
                                icon={CheckCircle2}
                                footer="Live outbox status for today's events."
                            >
                                <div className="space-y-4">
                                    <div className="flex items-center gap-4 rounded-2xl border border-gray-100 bg-gray-50/70 p-4">
                                        <div className={`rounded-2xl p-4 ${pulse.accounting_sync.failed > 0 ? 'bg-rose-100 text-rose-600' : (syncPending > 0 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600')}`}>
                                            <CheckCircle2 size={30} />
                                        </div>
                                        <div>
                                            <p className={`text-2xl font-bold ${getSyncStatusColor(pulse.accounting_sync)}`}>
                                                {pulse.accounting_sync.failed > 0 ? 'Attention Required' : (syncPending > 0 ? 'Monitoring Queue' : 'Healthy Pulse')}
                                            </p>
                                            <p className="mt-1 text-sm text-gray-500">
                                                {pulse.accounting_sync.failed > 0
                                                    ? 'Failed events are blocking clean accounting handoff.'
                                                    : (syncPending > 0 ? 'Events are still moving through the sync pipeline.' : 'No blocked accounting events detected.')}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="rounded-2xl border border-gray-100 bg-white p-3">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-400">Synced</p>
                                            <p className="mt-2 text-xl font-semibold text-slate-900">{pulse.accounting_sync.synced}</p>
                                        </div>
                                        <div className="rounded-2xl border border-gray-100 bg-white p-3">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-400">Failed</p>
                                            <p className="mt-2 text-xl font-semibold text-rose-600">{pulse.accounting_sync.failed}</p>
                                        </div>
                                        <div className="rounded-2xl border border-gray-100 bg-white p-3">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-400">Pending</p>
                                            <p className="mt-2 text-xl font-semibold text-amber-600">{syncPending}</p>
                                        </div>
                                    </div>
                                </div>
                            </StatusCard>

                            <ShiftStatusCard shiftContext={pulse.shift} />

                            <SettlementEvidenceCard settlement={pulse.settlement} />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
