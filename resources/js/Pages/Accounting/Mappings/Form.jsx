import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function metadataToText(metadata) {
    return metadata ? JSON.stringify(metadata, null, 2) : '';
}

export default function Form({ action, method = 'post', options, initialValues, submitLabel, disabled = false }) {
    const [form, setForm] = useState({
        provider: initialValues.provider || 'quickbooks',
        mapping_type: initialValues.mapping_type || 'account',
        branch_id: initialValues.branch_id || '',
        pos_entity_type: initialValues.pos_entity_type || '',
        pos_entity_id: initialValues.pos_entity_id || '',
        pos_key: initialValues.pos_key || '',
        external_id: initialValues.external_id || '',
        external_name: initialValues.external_name || '',
        metadata: metadataToText(initialValues.metadata),
        status: initialValues.status || 'active',
    });

    useEffect(() => {
        setForm({
            provider: initialValues.provider || 'quickbooks',
            mapping_type: initialValues.mapping_type || 'account',
            branch_id: initialValues.branch_id || '',
            pos_entity_type: initialValues.pos_entity_type || '',
            pos_entity_id: initialValues.pos_entity_id || '',
            pos_key: initialValues.pos_key || '',
            external_id: initialValues.external_id || '',
            external_name: initialValues.external_name || '',
            metadata: metadataToText(initialValues.metadata),
            status: initialValues.status || 'active',
        });
    }, [initialValues]);

    const entityOptions = form.mapping_type === 'tax_code'
        ? options.taxCategories
        : form.mapping_type === 'payment_method'
            ? options.paymentMethods
            : form.mapping_type === 'product'
                ? options.products
                : [];

    const needsEntity = ['tax_code', 'payment_method', 'product', 'customer'].includes(form.mapping_type);
    const entityType = form.mapping_type === 'tax_code'
        ? 'tax_category'
        : form.mapping_type === 'payment_method'
            ? 'payment_method'
            : form.mapping_type === 'product'
                ? 'product'
                : form.mapping_type === 'customer'
                    ? 'customer'
                    : '';

    function submit(event) {
        event.preventDefault();

        const payload = {
            ...form,
            branch_id: form.branch_id || null,
            pos_entity_type: entityType || null,
            pos_entity_id: needsEntity ? (form.pos_entity_id || null) : null,
            pos_key: form.mapping_type === 'account' ? form.pos_key : null,
            metadata: form.metadata,
        };

        if (method === 'put') {
            router.put(action, payload);

            return;
        }

        router.post(action, payload);
    }

    return (
        <form className="space-y-4" onSubmit={submit}>
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label className="mb-1 block text-sm font-medium text-slate-700">Provider</label>
                    <select className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.provider} onChange={(event) => setForm({ ...form, provider: event.target.value })}>
                        {options.providers.map((provider) => <option key={provider} value={provider}>{provider}</option>)}
                    </select>
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-slate-700">Mapping type</label>
                    <select className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.mapping_type} onChange={(event) => setForm({ ...form, mapping_type: event.target.value, pos_entity_id: '', pos_key: '' })}>
                        {options.mappingTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                    </select>
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-slate-700">Branch scope</label>
                    <select className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled || !options.canManageTenantLevel} value={form.branch_id} onChange={(event) => setForm({ ...form, branch_id: event.target.value })}>
                        <option value="">Tenant-wide</option>
                        {options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </select>
                </div>
                {form.mapping_type === 'account' ? (
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">POS key</label>
                        <input className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.pos_key} onChange={(event) => setForm({ ...form, pos_key: event.target.value })} placeholder="sales" />
                    </div>
                ) : (
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">POS entity</label>
                        {form.mapping_type === 'customer' && !options.customerSupported ? (
                            <input className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.pos_entity_id} onChange={(event) => setForm({ ...form, pos_entity_id: event.target.value })} placeholder="Customer UUID" />
                        ) : (
                            <select className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.pos_entity_id} onChange={(event) => setForm({ ...form, pos_entity_id: event.target.value })}>
                                <option value="">Select entity</option>
                                {entityOptions.map((entity) => <option key={entity.id} value={entity.id}>{entity.name}{entity.code ? ` (${entity.code})` : entity.sku ? ` (${entity.sku})` : ''}</option>)}
                            </select>
                        )}
                    </div>
                )}
                <div>
                    <label className="mb-1 block text-sm font-medium text-slate-700">External ID</label>
                    <input className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.external_id} onChange={(event) => setForm({ ...form, external_id: event.target.value })} />
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-slate-700">External name</label>
                    <input className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.external_name} onChange={(event) => setForm({ ...form, external_name: event.target.value })} />
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-slate-700">Status</label>
                    <select className="w-full rounded-lg border-gray-300 text-sm" disabled={disabled} value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
                        {options.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
                    </select>
                </div>
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Metadata JSON</label>
                <textarea className="min-h-32 w-full rounded-lg border-gray-300 font-mono text-sm" disabled={disabled} value={form.metadata} onChange={(event) => setForm({ ...form, metadata: event.target.value })} placeholder="{&quot;memo&quot;:&quot;Optional&quot;}" />
                <p className="mt-1 text-xs text-slate-500">Secret-like keys are stripped on the server before save.</p>
            </div>

            <div className="flex justify-end">
                <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:bg-slate-400" disabled={disabled} type="submit">
                    {submitLabel}
                </button>
            </div>
        </form>
    );
}