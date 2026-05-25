import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Lock, ShieldCheck } from 'lucide-react';

export default function InventoryHubIndex({ auth, sections = [], meta = {} }) {
    const totalLinks = sections.reduce((sum, section) => sum + (section.items?.length || 0), 0);
    const availableLinks = sections.reduce(
        (sum, section) => sum + (section.items?.filter((item) => item.available).length || 0),
        0,
    );

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-black leading-tight text-slate-900">Inventory Hub</h2>
                        <p className="mt-1 text-sm font-medium text-slate-500">
                            Read-only navigation surface for inventory operations, reports, setup, and inbound workflows.
                        </p>
                    </div>
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold uppercase tracking-wider text-emerald-700">
                        Read Only
                    </div>
                </div>
            }
        >
            <Head title="Inventory Hub" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <section className="grid gap-4 md:grid-cols-3">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Sections</p>
                            <p className="mt-2 text-2xl font-black text-slate-900">{sections.length}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Available Links</p>
                            <p className="mt-2 text-2xl font-black text-slate-900">{availableLinks}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Unavailable Links</p>
                            <p className="mt-2 text-2xl font-black text-slate-900">{Math.max(totalLinks - availableLinks, 0)}</p>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4">
                        <div className="flex items-start gap-2">
                            <ShieldCheck size={16} className="mt-0.5 text-indigo-600" />
                            <div>
                                <p className="text-sm font-bold text-indigo-900">Read-only scope lock respected</p>
                                <p className="mt-1 text-xs font-medium text-indigo-700">
                                    This hub links to existing flows only. It does not create or mutate inventory, stocktake, procurement, or recipe records.
                                </p>
                                <p className="mt-1 text-xs font-medium text-indigo-700">
                                    Cost visibility mode: <span className="font-black uppercase">{meta?.cost_visibility || 'masked'}</span>
                                </p>
                            </div>
                        </div>
                    </section>

                    {sections.map((section) => (
                        <section key={section.key} className="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm">
                            <div className="mb-4 border-b border-slate-100 pb-4">
                                <h3 className="text-lg font-black text-slate-900">{section.title}</h3>
                                <p className="mt-1 text-sm font-medium text-slate-500">{section.description}</p>
                            </div>

                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {(section.items || []).map((item) => (
                                    <article
                                        key={`${section.key}-${item.route_name}`}
                                        className={`rounded-2xl border p-4 transition-all ${
                                            item.available
                                                ? 'border-slate-200 bg-white hover:border-indigo-200 hover:bg-indigo-50/30'
                                                : 'border-slate-200 bg-slate-50/70'
                                        }`}
                                    >
                                        <p className="text-xs font-black uppercase tracking-wider text-slate-700">{item.label}</p>
                                        <p className="mt-1 text-xs font-medium text-slate-500">{item.description}</p>

                                        {!item.available ? (
                                            <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-700">
                                                <div className="flex items-start gap-1.5">
                                                    <Lock size={12} className="mt-0.5" />
                                                    <span>{item.unavailable_reason || 'Not available in current role or feature scope.'}</span>
                                                </div>
                                            </div>
                                        ) : null}

                                        {item.available && item.url ? (
                                            <Link
                                                href={item.url}
                                                className="mt-4 inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-slate-700 hover:border-indigo-300 hover:text-indigo-700"
                                            >
                                                Open
                                                <ArrowRight size={12} />
                                            </Link>
                                        ) : (
                                            <p className="mt-4 text-[10px] font-black uppercase tracking-wider text-slate-400">
                                                Link unavailable
                                            </p>
                                        )}
                                    </article>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
