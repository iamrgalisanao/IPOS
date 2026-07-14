import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Check,
    Circle,
    EyeOff,
    Grid3X3,
    MousePointer2,
    Plus,
    RotateCw,
    Save,
    Square,
    Trash2,
    Undo2,
} from 'lucide-react';

const DEFAULT_LAYOUT = {
    version: 1,
    canvas_width: 1600,
    canvas_height: 900,
    grid_size: 10,
    background: { type: 'none', image_url: null },
};

const DEFAULT_POSITION = {
    x: 0,
    y: 0,
    width: 120,
    height: 80,
    rotation: 0,
    shape: 'rectangle',
    label_position: 'center',
    z_index: 1,
};

function numeric(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function TableNode({ table, selected, scale, onSelect, onDrag }) {
    const meta = { ...DEFAULT_POSITION, ...(table.position_metadata || {}) };
    const style = {
        left: meta.x * scale,
        top: meta.y * scale,
        width: Math.max(meta.width * scale, 24),
        height: Math.max(meta.height * scale, 24),
        transform: `rotate(${meta.rotation}deg)`,
        zIndex: meta.z_index,
        borderRadius: meta.shape === 'circle' || meta.shape === 'oval' ? '9999px' : meta.shape === 'square' ? '6px' : '4px',
    };

    const handlePointerDown = (event) => {
        event.preventDefault();
        const startX = event.clientX;
        const startY = event.clientY;
        const originalX = meta.x;
        const originalY = meta.y;

        const move = (moveEvent) => {
            const deltaX = Math.round((moveEvent.clientX - startX) / scale);
            const deltaY = Math.round((moveEvent.clientY - startY) / scale);
            onDrag(table.id, {
                ...meta,
                x: Math.max(0, originalX + deltaX),
                y: Math.max(0, originalY + deltaY),
            });
        };

        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    };

    return (
        <button
            type="button"
            onPointerDown={handlePointerDown}
            onClick={() => onSelect(table.id)}
            style={style}
            className={[
                'absolute flex items-center justify-center border text-xs font-bold shadow-sm transition',
                table.is_active ? 'bg-white border-slate-300 text-slate-700' : 'bg-slate-100 border-dashed border-slate-300 text-slate-400',
                selected ? 'ring-2 ring-indigo-500 border-indigo-500' : '',
            ].join(' ')}
            title={`Table ${table.table_number}`}
        >
            {table.is_active ? table.table_number : <EyeOff size={16} />}
        </button>
    );
}

export default function Index({ auth, branches = [], selectedBranchId, selectedAreaId: initialSelectedAreaId, serviceAreas = [], defaults = {} }) {
    const initialArea = serviceAreas.find((area) => area.id === initialSelectedAreaId) || serviceAreas[0] || null;
    const [selectedAreaId, setSelectedAreaId] = useState(initialArea?.id || '');
    const selectedArea = serviceAreas.find((area) => area.id === selectedAreaId) || initialArea;
    const [selectedTableId, setSelectedTableId] = useState(selectedArea?.tables?.[0]?.id || '');
    const [draftTables, setDraftTables] = useState(() => selectedArea?.tables || []);
    const [draftLayout, setDraftLayout] = useState(() => selectedArea?.layout_metadata || defaults.layout_metadata || DEFAULT_LAYOUT);
    const [dirty, setDirty] = useState(false);

    const areaForm = useForm({
        branch_id: selectedBranchId || branches[0]?.id || '',
        name: '',
        layout_metadata: defaults.layout_metadata || DEFAULT_LAYOUT,
    });

    const tableForm = useForm({
        table_number: '',
        capacity: 2,
        operational_state: 'available',
        position_metadata: defaults.position_metadata || DEFAULT_POSITION,
    });

    const selectedTable = draftTables.find((table) => table.id === selectedTableId);
    const scale = useMemo(() => {
        const width = numeric(draftLayout.canvas_width || 1600);
        return Math.min(1, 920 / Math.max(width, 1));
    }, [draftLayout.canvas_width]);

    const selectArea = (areaId) => {
        const next = serviceAreas.find((area) => area.id === areaId);
        setSelectedAreaId(areaId);
        setDraftTables(next?.tables || []);
        setDraftLayout(next?.layout_metadata || defaults.layout_metadata || DEFAULT_LAYOUT);
        setSelectedTableId(next?.tables?.[0]?.id || '');
        setDirty(false);
    };

    useEffect(() => {
        const nextArea = serviceAreas.find((area) => area.id === initialSelectedAreaId)
            || serviceAreas.find((area) => area.id === selectedAreaId)
            || serviceAreas[0]
            || null;

        setSelectedAreaId(nextArea?.id || '');
        setDraftTables(nextArea?.tables || []);
        setDraftLayout(nextArea?.layout_metadata || defaults.layout_metadata || DEFAULT_LAYOUT);
        setSelectedTableId((currentTableId) => {
            const tables = nextArea?.tables || [];
            return tables.some((table) => table.id === currentTableId)
                ? currentTableId
                : (tables[0]?.id || '');
        });
        setDirty(false);
    }, [serviceAreas, initialSelectedAreaId, selectedBranchId, defaults.layout_metadata]);

    const updateTablePosition = (tableId, position) => {
        setDraftTables((tables) => tables.map((table) => table.id === tableId ? {
            ...table,
            position_metadata: position,
        } : table));
        setDirty(true);
    };

    const saveLayout = () => {
        if (!selectedArea) return;

        router.put(route('admin.service-areas.layout.update', selectedArea.id), {
            expected_layout_revision: selectedArea.layout_revision,
            layout_metadata: draftLayout,
            tables: draftTables.map((table) => ({
                id: table.id,
                position_metadata: table.position_metadata || DEFAULT_POSITION,
            })),
        }, {
            preserveScroll: true,
            onSuccess: () => setDirty(false),
        });
    };

    const discardChanges = () => {
        setDraftTables(selectedArea?.tables || []);
        setDraftLayout(selectedArea?.layout_metadata || defaults.layout_metadata || DEFAULT_LAYOUT);
        setDirty(false);
    };

    const submitArea = (event) => {
        event.preventDefault();
        areaForm.post(route('admin.service-areas.store'), {
            preserveScroll: true,
            onSuccess: () => areaForm.reset('name'),
        });
    };

    const submitTable = (event) => {
        event.preventDefault();
        if (!selectedArea) return;

        tableForm.post(route('admin.service-areas.tables.store', selectedArea.id), {
            preserveScroll: true,
            onSuccess: () => tableForm.reset('table_number'),
        });
    };

    const updateSelectedTable = (field, value) => {
        if (!selectedTable || !selectedArea) return;

        const payload = {
            table_number: selectedTable.table_number,
            capacity: selectedTable.capacity,
            operational_state: selectedTable.operational_state,
            position_metadata: selectedTable.position_metadata || DEFAULT_POSITION,
            [field]: value,
        };

        router.put(route('admin.service-areas.tables.update', [selectedArea.id, selectedTable.id]), payload, {
            preserveScroll: true,
        });
    };

    const toggleTable = (table) => {
        if (!selectedArea) return;

        router.patch(route('admin.service-areas.tables.activation', [selectedArea.id, table.id]), {
            is_active: !table.is_active,
        }, { preserveScroll: true });
    };

    const deleteTable = (table) => {
        if (!selectedArea) return;

        router.delete(route('admin.service-areas.tables.destroy', [selectedArea.id, table.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-bold text-slate-800">Dining Layouts</h2>
                        <p className="mt-1 text-sm text-slate-500">Configure branch service areas and table placement.</p>
                    </div>
                    <Link href={route('admin.pos-layouts.index')} className="text-sm font-semibold text-slate-500 hover:text-indigo-600">
                        POS layouts
                    </Link>
                </div>
            }
        >
            <Head title="Dining Layouts" />

            <div className="py-6">
                <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center gap-3">
                        <select
                            value={selectedBranchId || ''}
                            onChange={(event) => router.get(route('admin.service-areas.index'), { branch_id: event.target.value })}
                            className="rounded-md border-slate-300 text-sm"
                        >
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>{branch.name}</option>
                            ))}
                        </select>

                        <select
                            value={selectedArea?.id || ''}
                            onChange={(event) => selectArea(event.target.value)}
                            className="rounded-md border-slate-300 text-sm"
                        >
                            {serviceAreas.map((area) => (
                                <option key={area.id} value={area.id}>{area.name}</option>
                            ))}
                        </select>

                        <button
                            type="button"
                            onClick={saveLayout}
                            disabled={!selectedArea || !dirty}
                            className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                        >
                            <Save size={16} />
                            Save
                        </button>
                        <button
                            type="button"
                            onClick={discardChanges}
                            disabled={!dirty}
                            className="inline-flex items-center gap-2 rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Undo2 size={16} />
                            Discard
                        </button>
                        {dirty && <span className="text-xs font-semibold text-amber-600">Unsaved layout changes</span>}
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[280px_1fr_320px]">
                        <section className="rounded-lg border border-slate-200 bg-white p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-slate-700">
                                <Plus size={16} />
                                Add service area
                            </h3>
                            <form onSubmit={submitArea} className="space-y-3">
                                <input
                                    value={areaForm.data.name}
                                    onChange={(event) => areaForm.setData('name', event.target.value)}
                                    placeholder="Dining Room"
                                    className="w-full rounded-md border-slate-300 text-sm"
                                />
                                {areaForm.errors.name && <p className="text-xs text-red-600">{areaForm.errors.name}</p>}
                                <button className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white">
                                    Create area
                                </button>
                            </form>

                            <div className="my-5 border-t border-slate-100" />

                            <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-slate-700">
                                <Plus size={16} />
                                Add table
                            </h3>
                            <form onSubmit={submitTable} className="space-y-3">
                                <input
                                    value={tableForm.data.table_number}
                                    onChange={(event) => tableForm.setData('table_number', event.target.value)}
                                    placeholder="T1"
                                    className="w-full rounded-md border-slate-300 text-sm"
                                />
                                <input
                                    type="number"
                                    min="1"
                                    max="999"
                                    value={tableForm.data.capacity}
                                    onChange={(event) => tableForm.setData('capacity', numeric(event.target.value))}
                                    className="w-full rounded-md border-slate-300 text-sm"
                                />
                                <select
                                    value={tableForm.data.operational_state}
                                    onChange={(event) => tableForm.setData('operational_state', event.target.value)}
                                    className="w-full rounded-md border-slate-300 text-sm"
                                >
                                    <option value="available">Available</option>
                                    <option value="reserved">Reserved</option>
                                    <option value="cleaning">Cleaning</option>
                                </select>
                                {tableForm.errors.table_number && <p className="text-xs text-red-600">{tableForm.errors.table_number}</p>}
                                <button disabled={!selectedArea} className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white disabled:bg-slate-300">
                                    Create table
                                </button>
                            </form>
                        </section>

                        <section className="overflow-auto rounded-lg border border-slate-200 bg-white p-4">
                            <div className="mb-3 flex items-center justify-between">
                                <h3 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                                    <Grid3X3 size={16} />
                                    Canvas
                                </h3>
                                <span className="text-xs text-slate-500">
                                    v{selectedArea?.layout_revision || 0}
                                </span>
                            </div>
                            <div
                                className="relative overflow-hidden rounded-md border border-slate-200 bg-slate-50"
                                style={{
                                    width: Math.max(numeric(draftLayout.canvas_width || 1600) * scale, 320),
                                    height: Math.max(numeric(draftLayout.canvas_height || 900) * scale, 240),
                                    backgroundSize: `${numeric(draftLayout.grid_size || 10) * scale}px ${numeric(draftLayout.grid_size || 10) * scale}px`,
                                    backgroundImage: 'linear-gradient(to right, rgba(148,163,184,.18) 1px, transparent 1px), linear-gradient(to bottom, rgba(148,163,184,.18) 1px, transparent 1px)',
                                }}
                            >
                                {draftTables.map((table) => (
                                    <TableNode
                                        key={table.id}
                                        table={table}
                                        selected={table.id === selectedTableId}
                                        scale={scale}
                                        onSelect={setSelectedTableId}
                                        onDrag={updateTablePosition}
                                    />
                                ))}
                                {draftTables.length === 0 && (
                                    <div className="absolute inset-0 flex items-center justify-center text-sm font-semibold text-slate-400">
                                        Add a table to start designing this area.
                                    </div>
                                )}
                            </div>
                        </section>

                        <section className="rounded-lg border border-slate-200 bg-white p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-slate-700">
                                <MousePointer2 size={16} />
                                Table properties
                            </h3>
                            {selectedTable ? (
                                <div className="space-y-3">
                                    <label className="block text-xs font-bold uppercase text-slate-500">Table number</label>
                                    <input
                                        key={`table-number-${selectedTable.id}`}
                                        defaultValue={selectedTable.table_number}
                                        onBlur={(event) => updateSelectedTable('table_number', event.target.value)}
                                        className="w-full rounded-md border-slate-300 text-sm"
                                    />
                                    <label className="block text-xs font-bold uppercase text-slate-500">Capacity</label>
                                    <input
                                        key={`capacity-${selectedTable.id}`}
                                        type="number"
                                        min="1"
                                        max="999"
                                        defaultValue={selectedTable.capacity}
                                        onBlur={(event) => updateSelectedTable('capacity', numeric(event.target.value))}
                                        className="w-full rounded-md border-slate-300 text-sm"
                                    />
                                    <label className="block text-xs font-bold uppercase text-slate-500">Operational state</label>
                                    <select
                                        key={`operational-state-${selectedTable.id}`}
                                        defaultValue={selectedTable.operational_state}
                                        onChange={(event) => updateSelectedTable('operational_state', event.target.value)}
                                        className="w-full rounded-md border-slate-300 text-sm"
                                    >
                                        <option value="available">Available</option>
                                        <option value="reserved">Reserved</option>
                                        <option value="cleaning">Cleaning</option>
                                    </select>

                                    <div className="grid grid-cols-2 gap-2 pt-2">
                                        <button
                                            type="button"
                                            onClick={() => toggleTable(selectedTable)}
                                            className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-600"
                                        >
                                            {selectedTable.is_active ? <EyeOff size={16} /> : <Check size={16} />}
                                            {selectedTable.is_active ? 'Deactivate' : 'Activate'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => deleteTable(selectedTable)}
                                            className="inline-flex items-center justify-center gap-2 rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-600"
                                        >
                                            <Trash2 size={16} />
                                            Delete
                                        </button>
                                    </div>

                                    <div className="flex items-center gap-2 pt-3 text-xs text-slate-500">
                                        {selectedTable.position_metadata?.shape === 'circle' ? <Circle size={14} /> : <Square size={14} />}
                                        <span>{selectedTable.position_metadata?.shape || 'rectangle'}</span>
                                        <RotateCw size={14} />
                                        <span>{selectedTable.position_metadata?.rotation || 0} deg</span>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">Select a table to edit properties.</p>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
