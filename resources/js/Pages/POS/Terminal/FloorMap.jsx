import React, { useEffect, useMemo, useState } from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import TerminalInfoShell from './TerminalInfoShell';
import { AlertTriangle, Armchair, CloudOff, RefreshCw, Utensils } from 'lucide-react';

const STATUS_STYLES = {
    vacant: 'border-emerald-400 bg-emerald-500/15 text-emerald-50',
    occupied: 'border-sky-400 bg-sky-500/20 text-sky-50',
    reserved: 'border-amber-400 bg-amber-500/20 text-amber-50',
    cleaning: 'border-fuchsia-400 bg-fuchsia-500/20 text-fuchsia-50',
    inactive: 'border-slate-600 bg-slate-800/70 text-slate-300',
    unavailable: 'border-rose-400 bg-rose-500/20 text-rose-50',
};

const STATUS_LABELS = {
    vacant: 'Vacant',
    occupied: 'Occupied',
    reserved: 'Reserved',
    cleaning: 'Cleaning',
    inactive: 'Inactive',
    unavailable: 'Unavailable',
};

function storageKey(terminalContext) {
    const tenantId = terminalContext?.tenant?.id || 'tenant';
    const branchId = terminalContext?.branch?.id || 'branch';
    const terminalId = terminalContext?.terminal?.id || 'terminal';

    return `ipos:dining-floor-map:v1:${tenantId}:${branchId}:${terminalId}`;
}

function tableDuration(openedAt, now) {
    if (!openedAt) {
        return null;
    }

    const minutes = Math.max(0, Math.floor((now.getTime() - new Date(openedAt).getTime()) / 60000));
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return hours > 0 ? `${hours}h ${remainder}m` : `${remainder}m`;
}

function canvasStyle(area) {
    const metadata = area?.layout_metadata || {};
    const width = Number(metadata.canvas_width || 1600);
    const height = Number(metadata.canvas_height || 900);

    return {
        aspectRatio: `${width} / ${height}`,
    };
}

function tableStyle(table, area) {
    const metadata = table.position_metadata || {};
    const canvas = area.layout_metadata || {};
    const canvasWidth = Number(canvas.canvas_width || 1600);
    const canvasHeight = Number(canvas.canvas_height || 900);
    const x = Number(metadata.x || 0);
    const y = Number(metadata.y || 0);
    const width = Number(metadata.width || 120);
    const height = Number(metadata.height || 80);
    const rotation = Number(metadata.rotation || 0);
    const zIndex = Number(metadata.z_index || 1);
    const shape = metadata.shape || 'rectangle';

    return {
        left: `${(x / canvasWidth) * 100}%`,
        top: `${(y / canvasHeight) * 100}%`,
        width: `${(width / canvasWidth) * 100}%`,
        height: `${(height / canvasHeight) * 100}%`,
        transform: `rotate(${rotation}deg)`,
        zIndex,
        borderRadius: shape === 'circle' || shape === 'oval' ? '9999px' : shape === 'square' ? '8px' : '10px',
    };
}

function TableNode({ table, area, now }) {
    const activeTicket = table.active_ticket;
    const duration = tableDuration(activeTicket?.opened_at, now);

    return (
        <div
            className={`absolute flex min-h-12 min-w-16 flex-col items-center justify-center overflow-hidden border-2 px-2 text-center shadow-lg shadow-black/20 ${STATUS_STYLES[table.status] || STATUS_STYLES.unavailable}`}
            style={tableStyle(table, area)}
            title={`${table.table_number} - ${STATUS_LABELS[table.status] || table.status}`}
        >
            <div className="max-w-full truncate text-sm font-black">{table.table_number}</div>
            <div className="mt-0.5 max-w-full truncate text-[10px] font-bold uppercase tracking-wide opacity-90">
                {STATUS_LABELS[table.status] || table.status}
            </div>
            {activeTicket && (
                <div className="mt-1 max-w-full truncate text-[10px] font-semibold opacity-90">
                    {activeTicket.ticket_number}{duration ? ` · ${duration}` : ''}
                </div>
            )}
        </div>
    );
}

function StatusLegend({ areas }) {
    const counts = useMemo(() => {
        return areas.flatMap((area) => area.tables || []).reduce((carry, table) => {
            carry[table.status] = (carry[table.status] || 0) + 1;
            return carry;
        }, {});
    }, [areas]);

    return (
        <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
            {Object.entries(STATUS_LABELS).map(([status, label]) => (
                <div key={status} className={`flex items-center justify-between rounded-lg border px-3 py-2 text-xs font-black ${STATUS_STYLES[status]}`}>
                    <span>{label}</span>
                    <span>{counts[status] || 0}</span>
                </div>
            ))}
        </div>
    );
}

export default function FloorMap({ terminal_context }) {
    const [floorMap, setFloorMap] = useState(null);
    const [activeAreaId, setActiveAreaId] = useState(null);
    const [isOffline, setIsOffline] = useState(typeof navigator !== 'undefined' ? !navigator.onLine : false);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const [now, setNow] = useState(new Date());

    const cacheKey = useMemo(() => storageKey(terminal_context), [terminal_context]);

    useEffect(() => {
        const tick = window.setInterval(() => setNow(new Date()), 60000);

        return () => window.clearInterval(tick);
    }, []);

    useEffect(() => {
        const markOnline = () => setIsOffline(false);
        const markOffline = () => setIsOffline(true);

        window.addEventListener('online', markOnline);
        window.addEventListener('offline', markOffline);

        return () => {
            window.removeEventListener('online', markOnline);
            window.removeEventListener('offline', markOffline);
        };
    }, []);

    useEffect(() => {
        let ignore = false;

        async function loadFloorMap() {
            setIsLoading(true);
            setError(null);

            const cached = localStorage.getItem(cacheKey);

            if (!navigator.onLine && cached) {
                const parsed = JSON.parse(cached);
                setFloorMap(parsed);
                setActiveAreaId((current) => current || parsed.service_areas?.[0]?.id || null);
                setIsLoading(false);
                return;
            }

            try {
                const response = await fetch(route('pos.dining.floor-map.index'), {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(terminal_context?.tenant?.id ? { 'X-Tenant-ID': terminal_context.tenant.id } : {}),
                        ...(terminal_context?.branch?.id ? { 'X-Branch-ID': terminal_context.branch.id } : {}),
                        ...(terminal_context?.terminal?.id ? { 'X-Terminal-ID': terminal_context.terminal.id } : {}),
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`Floor map request failed with ${response.status}`);
                }

                const payload = await response.json();
                if (ignore) {
                    return;
                }

                setFloorMap(payload.data);
                setActiveAreaId((current) => current || payload.data.service_areas?.[0]?.id || null);
                localStorage.setItem(cacheKey, JSON.stringify(payload.data));
            } catch (requestError) {
                if (cached) {
                    const parsed = JSON.parse(cached);
                    setFloorMap(parsed);
                    setActiveAreaId((current) => current || parsed.service_areas?.[0]?.id || null);
                    setError('Showing cached floor map');
                } else {
                    setError(requestError.message || 'Floor map unavailable');
                }
            } finally {
                if (!ignore) {
                    setIsLoading(false);
                }
            }
        }

        loadFloorMap();

        return () => {
            ignore = true;
        };
    }, [cacheKey]);

    const areas = floorMap?.service_areas || [];
    const activeArea = areas.find((area) => area.id === activeAreaId) || areas[0] || null;

    return (
        <TerminalInfoShell
            title="Floor Map"
            subtitle="Read-only dining table status for the active terminal."
            terminalContext={terminal_context}
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-800 bg-slate-900/80 p-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-cyan-500/15 text-cyan-200">
                            <Utensils className="h-5 w-5" />
                        </div>
                        <div className="min-w-0">
                            <div className="truncate text-sm font-black text-slate-100">{terminal_context?.branch?.name || 'Dining Floor'}</div>
                            <div className="mt-1 truncate text-xs text-slate-500">
                                Layout {floorMap?.layout_revision?.slice?.(0, 10) || 'pending'} · Occupancy {floorMap?.occupancy_revision?.slice?.(0, 10) || 'pending'}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {(isOffline || error) && (
                            <div className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs font-bold text-amber-100">
                                {isOffline ? <CloudOff className="h-4 w-4" /> : <AlertTriangle className="h-4 w-4" />}
                                {isOffline ? 'Offline' : error}
                            </div>
                        )}
                        <button
                            type="button"
                            onClick={() => window.location.reload()}
                            className="rounded-lg border border-slate-700 bg-slate-800 p-2 text-slate-200 transition hover:border-cyan-400 hover:text-white"
                            title="Refresh floor map"
                        >
                            <RefreshCw className="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <StatusLegend areas={areas} />

                <div className="flex gap-2 overflow-x-auto pb-1">
                    {areas.map((area) => (
                        <button
                            key={area.id}
                            type="button"
                            onClick={() => setActiveAreaId(area.id)}
                            className={`shrink-0 rounded-lg border px-4 py-2 text-xs font-black uppercase tracking-widest transition ${
                                activeArea?.id === area.id
                                    ? 'border-cyan-400 bg-cyan-500/15 text-cyan-50'
                                    : 'border-slate-800 bg-slate-900 text-slate-400 hover:border-slate-600 hover:text-slate-100'
                            }`}
                        >
                            {area.name}
                        </button>
                    ))}
                </div>

                <section className="min-h-[480px] rounded-lg border border-slate-800 bg-slate-950 p-4">
                    {isLoading ? (
                        <div className="flex h-[420px] items-center justify-center text-sm font-bold text-slate-500">Loading floor map</div>
                    ) : !activeArea ? (
                        <div className="flex h-[420px] flex-col items-center justify-center gap-3 text-center text-slate-500">
                            <Armchair className="h-10 w-10" />
                            <div className="text-sm font-bold">No dining tables available</div>
                        </div>
                    ) : (
                        <div className="relative w-full overflow-hidden rounded-lg border border-slate-800 bg-[linear-gradient(rgba(148,163,184,0.08)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,0.08)_1px,transparent_1px)] bg-[size:24px_24px]" style={canvasStyle(activeArea)}>
                            {(activeArea.tables || []).map((table) => (
                                <TableNode key={table.id} table={table} area={activeArea} now={now} />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </TerminalInfoShell>
    );
}

FloorMap.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;
