import React, { useState, useEffect } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    Layout, 
    Save, 
    Trash2, 
    ChevronLeft, 
    Grid, 
    Eye, 
    Edit3, 
    AlertCircle,
    CheckCircle2,
    Monitor,
    History,
    RotateCcw,
    Calendar,
    User
} from 'lucide-react';
import TileRegistry from '@/Components/POS/TileRegistry';
import LayoutEditorGrid from '@/Components/POS/LayoutEditorGrid';
import ProductGrid from '@/Pages/POS/Components/ProductGrid';

export default function Show({ layout, registry, history }) {
    const [isDesignMode, setIsDesignMode] = useState(layout.status === 'draft');
    const [activeSelection, setActiveSelection] = useState(null);
    const [successMessage, setSuccessMessage] = useState('');
    const [rollbackConfirmOpen, setRollbackConfirmOpen] = useState(false);
    const [pendingRollbackBranchId, setPendingRollbackBranchId] = useState(null);

    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        name: layout.name,
        schema: layout.schema || {
            grid: { rows: 4, columns: 4 },
            tiles: []
        }
    });

    const [isPublishModalOpen, setIsPublishModalOpen] = useState(false);
    const [selectedBranches, setSelectedBranches] = useState([]);

    const publishForm = useForm({
        branch_ids: [],
        active_from: null,
    });

    const rollbackForm = useForm({
        branch_id: null,
    });

    useEffect(() => {
        if (recentlySuccessful) {
            setSuccessMessage('Layout saved successfully.');
            setTimeout(() => setSuccessMessage(''), 3000);
        }
    }, [recentlySuccessful]);

    const handleGridResize = (axis, value) => {
        const val = parseInt(value);
        if (isNaN(val) || val <= 0 || val > 10) return;

        const newGrid = { ...data.schema.grid, [axis]: val };
        
        // Filter out tiles that would be out of bounds
        const newTiles = data.schema.tiles.filter(t => 
            t.x < newGrid.columns && t.y < newGrid.rows
        );

        setData('schema', {
            ...data.schema,
            grid: newGrid,
            tiles: newTiles
        });
    };

    const handleTileClick = (x, y) => {
        if (!isDesignMode) return;
        if (!activeSelection) return;

        // Replace or add tile
        const otherTiles = data.schema.tiles.filter(t => t.x !== x || t.y !== y);
        const newTile = {
            id: activeSelection.id,
            type: activeSelection.type,
            x,
            y
        };

        setData('schema', {
            ...data.schema,
            tiles: [...otherTiles, newTile]
        });
        
        // Don't clear selection, allow multiple placements
    };

    const handleTileRemove = (x, y) => {
        setData('schema', {
            ...data.schema,
            tiles: data.schema.tiles.filter(t => t.x !== x || t.y !== y)
        });
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.pos-layouts.update', layout.id));
    };

    const handlePublish = (e) => {
        e.preventDefault();
        publishForm.setData('branch_ids', selectedBranches);
        publishForm.post(route('admin.pos-layouts.publish', layout.id), {
            onSuccess: () => setIsPublishModalOpen(false),
        });
    };

    const handleRollback = (branchId) => {
        setPendingRollbackBranchId(branchId);
        setRollbackConfirmOpen(true);
    };

    const handleConfirmRollback = () => {
        if (pendingRollbackBranchId) {
            rollbackForm.post(route('admin.pos-layouts.rollback', { 
                posLayout: layout.id,
                branch_id: pendingRollbackBranchId 
            }));
        }
        setRollbackConfirmOpen(false);
        setPendingRollbackBranchId(null);
    };

    const toggleBranch = (id) => {
        setSelectedBranches(prev => 
            prev.includes(id) ? prev.filter(b => b !== id) : [...prev, id]
        );
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-200 flex flex-col font-sans selection:bg-indigo-500/30">
            <Head title={`Editor: ${layout.name}`} />

            {/* Header */}
            <header className="h-16 border-b border-slate-800 bg-slate-900/50 backdrop-blur-md flex items-center justify-between px-6 sticky top-0 z-50">
                <div className="flex items-center gap-6">
                    <Link 
                        href={route('admin.pos-layouts.index')}
                        className="p-2 rounded-xl bg-slate-800 text-slate-400 hover:text-white hover:bg-slate-700 transition-all border border-slate-700/50"
                    >
                        <ChevronLeft className="w-5 h-5" />
                    </Link>
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-lg font-bold tracking-tight">{layout.name}</h1>
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-widest border ${
                                layout.status === 'draft' 
                                ? 'bg-amber-500/10 text-amber-500 border-amber-500/20' 
                                : layout.status === 'published'
                                ? 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20'
                                : 'bg-slate-500/10 text-slate-500 border-slate-500/20'
                            }`}>
                                {layout.status}
                            </span>
                        </div>
                        <p className="text-[10px] text-slate-500 font-medium uppercase tracking-tighter mt-0.5">
                            Version {layout.version} • Last updated by {layout.updated_by_name || 'Admin'}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    {successMessage && (
                        <div className="flex items-center gap-2 text-emerald-400 text-xs font-medium animate-in fade-in slide-in-from-right-4">
                            <CheckCircle2 className="w-4 h-4" />
                            {successMessage}
                        </div>
                    )}
                    
                    <div className="flex bg-slate-800 p-1 rounded-xl border border-slate-700">
                        <button 
                            onClick={() => setIsDesignMode(false)}
                            className={`flex items-center gap-2 px-4 py-1.5 rounded-lg text-xs font-bold transition-all ${
                                !isDesignMode ? 'bg-slate-700 text-white shadow-lg' : 'text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            <Eye className="w-3.5 h-3.5" /> Preview
                        </button>
                        <button 
                            onClick={() => setIsDesignMode(true)}
                            disabled={layout.status !== 'draft'}
                            className={`flex items-center gap-2 px-4 py-1.5 rounded-lg text-xs font-bold transition-all ${
                                isDesignMode ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' : 'text-slate-400 hover:text-slate-200 disabled:opacity-50 disabled:cursor-not-allowed'
                            }`}
                        >
                            <Edit3 className="w-3.5 h-3.5" /> Design
                        </button>
                    </div>

                    {layout.status === 'draft' && (
                        <>
                            <button 
                                onClick={submit}
                                disabled={processing}
                                className="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-200 px-5 py-2.5 rounded-xl text-sm font-bold border border-slate-700 transition-all disabled:opacity-50"
                            >
                                <Save className="w-4 h-4" />
                                {processing ? 'Saving...' : 'Save Draft'}
                            </button>
                            <button 
                                onClick={() => setIsPublishModalOpen(true)}
                                className="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-emerald-500/20 transition-all"
                            >
                                <Monitor className="w-4 h-4" />
                                Publish to Branches
                            </button>
                        </>
                    )}
                </div>
            </header>

            <main className="flex-1 flex overflow-hidden">
                {/* Left Sidebar: Registry */}
                {isDesignMode && (
                    <TileRegistry 
                        products={registry.products}
                        categories={registry.categories}
                        onSelect={setActiveSelection}
                        activeSelection={activeSelection}
                    />
                )}

                {/* Main Viewport */}
                <div className="flex-1 bg-slate-950 flex flex-col items-center justify-center p-8 overflow-y-auto">
                    {/* Device Frame Mockup */}
                    <div className="w-full max-w-5xl bg-slate-900 rounded-[2.5rem] p-4 shadow-2xl border border-slate-800 relative ring-8 ring-slate-900/50">
                        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-32 h-6 bg-slate-900 rounded-b-2xl border-x border-b border-slate-800 z-10 flex items-center justify-center gap-1">
                            <div className="w-1.5 h-1.5 rounded-full bg-slate-800"></div>
                            <div className="w-8 h-1 rounded-full bg-slate-800"></div>
                        </div>

                        <div className="bg-black rounded-[1.8rem] overflow-hidden aspect-[16/10] flex flex-col shadow-inner">
                            {/* POS UI Mockup Header */}
                            <div className="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <Monitor className="w-4 h-4 text-indigo-400" />
                                    <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">POS Terminal Preview</span>
                                </div>
                                <div className="w-20 h-1.5 rounded-full bg-slate-800"></div>
                            </div>

                            <div className="flex-1 bg-[#0f172a] p-6 overflow-hidden">
                                {isDesignMode ? (
                                    <LayoutEditorGrid 
                                        schema={data.schema}
                                        products={registry.products}
                                        categories={registry.categories}
                                        onTileClick={handleTileClick}
                                        onTileRemove={handleTileRemove}
                                        activeSelection={activeSelection}
                                    />
                                ) : (
                                    <div className="h-full overflow-y-auto pr-2 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
                                        <ProductGrid 
                                            products={registry.products}
                                            activeLayout={{ schema: data.schema }}
                                            onSelect={() => {}}
                                            isSearchActive={false}
                                            loading={false}
                                        />
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {isDesignMode && (
                        <div className="mt-8 flex items-center gap-8 bg-slate-900/50 backdrop-blur-sm px-8 py-4 rounded-2xl border border-slate-800 shadow-xl">
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                    <Grid className="w-3 h-3" /> Grid Columns
                                </label>
                                <input 
                                    type="number" 
                                    min="1" 
                                    max="10" 
                                    value={data.schema.grid.columns}
                                    onChange={(e) => handleGridResize('columns', e.target.value)}
                                    className="bg-slate-800 border-slate-700 rounded-lg text-sm text-center w-24 focus:ring-indigo-500 focus:border-indigo-500"
                                />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                    <Grid className="w-3 h-3" /> Grid Rows
                                </label>
                                <input 
                                    type="number" 
                                    min="1" 
                                    max="10" 
                                    value={data.schema.grid.rows}
                                    onChange={(e) => handleGridResize('rows', e.target.value)}
                                    className="bg-slate-800 border-slate-700 rounded-lg text-sm text-center w-24 focus:ring-indigo-500 focus:border-indigo-500"
                                />
                            </div>
                            <div className="h-10 w-px bg-slate-800"></div>
                            <div className="text-[10px] text-slate-400 leading-relaxed max-w-xs italic">
                                Tip: Select a product from the left and click any cell to place it.
                                Resizing to a smaller grid will remove out-of-bounds tiles.
                            </div>
                        </div>
                    )}
                </div>

                {/* Right Sidebar: Status & History */}
                <div className="w-80 bg-slate-900 border-l border-slate-800 flex flex-col p-6 overflow-y-auto">
                    <h3 className="text-sm font-bold text-slate-400 uppercase tracking-wider mb-6">Configuration</h3>
                    
                    <div className="space-y-6">
                        <div>
                            <label className="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Layout Name</label>
                            <input 
                                type="text" 
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                disabled={layout.status !== 'draft'}
                                className="w-full bg-slate-800 border-slate-700 rounded-xl text-sm text-slate-200 focus:ring-indigo-500 focus:border-indigo-500 disabled:opacity-50"
                            />
                            {errors.name && <p className="text-rose-500 text-xs mt-1">{errors.name}</p>}
                        </div>

                        <div className="bg-slate-800/50 rounded-2xl p-4 border border-slate-800">
                            <h4 className="text-xs font-bold text-slate-300 mb-4 flex items-center gap-2">
                                <AlertCircle className="w-3.5 h-3.5 text-indigo-400" /> Stats
                            </h4>
                            <div className="space-y-3">
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500">Total Tiles</span>
                                    <span className="text-indigo-400 font-bold">{data.schema.tiles.length}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500">Occupancy</span>
                                    <span className="text-indigo-400 font-bold">
                                        {Math.round((data.schema.tiles.length / (data.schema.grid.rows * data.schema.grid.columns)) * 100)}%
                                    </span>
                                </div>
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500">Grid Size</span>
                                    <span className="text-indigo-400 font-bold">{data.schema.grid.columns} x {data.schema.grid.rows}</span>
                                </div>
                            </div>
                        </div>

                        {/* Deployment History */}
                        <div className="space-y-4">
                            <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                <History className="w-3.5 h-3.5" /> Deployment History
                            </h4>
                            
                            <div className="space-y-3">
                                {history?.length > 0 ? (
                                    history.map((entry) => (
                                        <div 
                                            key={entry.id} 
                                            className={`p-3 rounded-xl border transition-all ${
                                                entry.is_active 
                                                ? 'bg-emerald-500/5 border-emerald-500/20' 
                                                : 'bg-slate-800/50 border-slate-800'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between mb-2">
                                                <div className="flex items-center gap-2">
                                                    <span className={`w-2 h-2 rounded-full ${entry.is_active ? 'bg-emerald-500 animate-pulse' : 'bg-slate-700'}`}></span>
                                                    <span className="text-[11px] font-bold text-slate-200">{entry.branch_name}</span>
                                                </div>
                                                {!entry.is_active && layout.status === 'published' && (
                                                    <button 
                                                        onClick={() => handleRollback(entry.branch_id)}
                                                        disabled={rollbackForm.processing}
                                                        className="p-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 hover:text-white transition-all disabled:opacity-50"
                                                        title="Rollback/Redeploy this layout to this branch"
                                                    >
                                                        <RotateCcw className="w-3 h-3" />
                                                    </button>
                                                )}
                                            </div>
                                            
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2 text-[10px] text-slate-500">
                                                    <Calendar className="w-3 h-3" />
                                                    <span>{new Date(entry.published_at).toLocaleString()}</span>
                                                </div>
                                                <div className="flex items-center gap-2 text-[10px] text-slate-500">
                                                    <User className="w-3 h-3" />
                                                    <span>Published by Admin</span>
                                                </div>
                                                {entry.active_until && (
                                                    <div className="text-[9px] text-slate-600 mt-1 italic">
                                                        Active until {new Date(entry.active_until).toLocaleString()}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="text-center py-6 px-4 bg-slate-800/30 rounded-2xl border border-slate-800/50">
                                        <p className="text-[10px] text-slate-500 italic">No deployment history found.</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {layout.status !== 'draft' && (
                            <div className="bg-amber-500/10 border border-amber-500/20 rounded-2xl p-4">
                                <div className="flex items-start gap-3">
                                    <AlertCircle className="w-4 h-4 text-amber-500 mt-0.5" />
                                    <div>
                                        <p className="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1">Read Only</p>
                                        <p className="text-[10px] text-amber-500/70 leading-relaxed">
                                            This layout is {layout.status}. You must create a new draft to make changes.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mt-auto pt-6">
                        <div className="p-4 bg-indigo-500/5 rounded-2xl border border-indigo-500/10 text-center">
                            <p className="text-[10px] font-bold text-indigo-400 uppercase tracking-widest mb-1">Deployment Security</p>
                            <p className="text-[9px] text-slate-500 leading-tight">
                                Rollbacks create a new audit entry. 
                                Only one active layout per branch is permitted.
                            </p>
                        </div>
                    </div>
                </div>
            </main>

            {/* Publish Modal */}
            {isPublishModalOpen && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-950/80 backdrop-blur-sm animate-in fade-in duration-300">
                    <div className="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden flex flex-col animate-in zoom-in-95 duration-300">
                        <div className="px-8 py-6 border-b border-slate-800 flex items-center justify-between">
                            <div>
                                <h3 className="text-xl font-bold text-white tracking-tight">Deploy Layout</h3>
                                <p className="text-xs text-slate-500 mt-1 uppercase tracking-widest font-bold">Select target branches</p>
                            </div>
                            <button 
                                onClick={() => setIsPublishModalOpen(false)}
                                className="p-2 rounded-xl hover:bg-slate-800 transition-all"
                            >
                                <ChevronLeft className="w-5 h-5 rotate-90" />
                            </button>
                        </div>
                        
                        <div className="p-8 space-y-6">
                            <div className="bg-indigo-500/5 border border-indigo-500/10 p-4 rounded-2xl">
                                <div className="flex gap-3">
                                    <AlertCircle className="w-5 h-5 text-indigo-400 mt-0.5" />
                                    <p className="text-[11px] text-slate-400 leading-relaxed font-medium">
                                        Publishing will make this layout <span className="text-indigo-400 font-bold uppercase tracking-widest">Active</span> for selected branches. 
                                        Previous active layouts for these branches will be deactivated.
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-2 max-h-60 overflow-y-auto pr-2 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
                                {registry.branches?.length > 0 ? (
                                    registry.branches.map(branch => (
                                        <button
                                            key={branch.id}
                                            onClick={() => toggleBranch(branch.id)}
                                            className={`w-full flex items-center justify-between p-4 rounded-2xl border transition-all ${
                                                selectedBranches.includes(branch.id)
                                                ? 'bg-indigo-600/10 border-indigo-600 shadow-inner'
                                                : 'bg-slate-800/50 border-slate-700/50 hover:border-slate-600'
                                            }`}
                                        >
                                            <div className="text-left">
                                                <p className={`text-sm font-bold ${selectedBranches.includes(branch.id) ? 'text-white' : 'text-slate-300'}`}>
                                                    {branch.name}
                                                </p>
                                                <p className="text-[10px] text-slate-500 font-medium uppercase tracking-tighter">{branch.id.split('-')[0]}</p>
                                            </div>
                                            <div className={`w-6 h-6 rounded-lg border flex items-center justify-center transition-all ${
                                                selectedBranches.includes(branch.id)
                                                ? 'bg-indigo-600 border-indigo-500'
                                                : 'bg-slate-900 border-slate-700'
                                            }`}>
                                                {selectedBranches.includes(branch.id) && <CheckCircle2 className="w-4 h-4 text-white" />}
                                            </div>
                                        </button>
                                    ))
                                ) : (
                                    <p className="text-center text-xs text-slate-500 py-8 italic">No branches available for deployment.</p>
                                )}
                            </div>
                        </div>

                        <div className="p-8 bg-slate-800/50 border-t border-slate-800 flex gap-3">
                            <button 
                                onClick={() => setIsPublishModalOpen(false)}
                                className="flex-1 px-6 py-3.5 rounded-2xl bg-slate-800 text-slate-300 font-bold text-sm border border-slate-700 hover:bg-slate-700 transition-all"
                            >
                                Cancel
                            </button>
                            <button 
                                onClick={handlePublish}
                                disabled={selectedBranches.length === 0 || publishForm.processing}
                                className="flex-[2] px-6 py-3.5 rounded-2xl bg-indigo-600 text-white font-bold text-sm shadow-lg shadow-indigo-600/20 hover:bg-indigo-500 transition-all disabled:opacity-50"
                            >
                                {publishForm.processing ? 'Deploying...' : `Deploy to ${selectedBranches.length} Branch${selectedBranches.length === 1 ? '' : 'es'}`}
                            </button>
                        </div>
                    </div>
                </div>
            )}
            {rollbackConfirmOpen && (
                <PremiumDialog
                    isOpen={rollbackConfirmOpen}
                    type="warning"
                    title="Rollback Branch POS Layout"
                    message="Are you sure you want to rollback and reactivate this layout version for this branch? This will replace the branch's active layout immediately."
                    confirmLabel="Rollback Layout"
                    onConfirm={handleConfirmRollback}
                    onCancel={() => {
                        setRollbackConfirmOpen(false);
                        setPendingRollbackBranchId(null);
                    }}
                />
            )}
        </div>
    );
}
