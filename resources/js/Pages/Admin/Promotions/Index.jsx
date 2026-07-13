import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import {
    BadgePercent,
    CalendarClock,
    CheckCircle2,
    Edit2,
    Gift,
    Layers,
    PackageCheck,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-react';

const emptyForm = {
    name: '',
    description: '',
    rule_type: 'discount_tier',
    priority: 0,
    starts_at: '',
    ends_at: '',
    is_active: true,
    currency: 'PHP',
    timezone: 'Asia/Manila',
    branch_ids: [],
    schema_version: 'v1',
    condition_type: 'minimum_spend',
    reward_type: 'percent_off',
    conditions: {
        min_spend_centavos: 0,
        eligible_product_ids: [],
        eligible_category_ids: [],
    },
    rewards: {
        percent: 10,
    },
    stackable: false,
    min_spend_centavos: 0,
    max_applications_per_sale: '',
    max_discount_centavos: '',
    exclusive_group: '',
};

const ruleTypes = [
    { value: 'discount_tier', label: 'Minimum Spend' },
    { value: 'bogo', label: 'Buy X Get Y' },
    { value: 'combo_package', label: 'Combo Bundle' },
];

const rewardTypes = [
    { value: 'percent_off', label: 'Percent Off' },
    { value: 'amount_off', label: 'Amount Off' },
    { value: 'free_item', label: 'Free Item' },
    { value: 'fixed_bundle_price', label: 'Fixed Bundle Price' },
];

const toLocalDatetime = (value) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const offset = date.getTimezoneOffset();
    const local = new Date(date.getTime() - offset * 60000);
    return local.toISOString().slice(0, 16);
};

const selectedValues = (event) => Array.from(event.target.selectedOptions).map((option) => option.value);

const normalizeNullableInteger = (value) => {
    if (value === '' || value === null || value === undefined) return null;
    return Number.parseInt(value, 10);
};

const normalizeConditions = (data) => {
    if (data.condition_type === 'minimum_spend') {
        return {
            ...data.conditions,
            min_spend_centavos: Number.parseInt(data.conditions.min_spend_centavos || 0, 10),
        };
    }

    if (data.condition_type === 'buy_x_get_y') {
        return {
            ...data.conditions,
            buy_qty: Number.parseInt(data.conditions.buy_qty || 1, 10),
            reward_qty: Number.parseInt(data.conditions.reward_qty || 1, 10),
        };
    }

    if (data.condition_type === 'bundle_match') {
        return {
            ...data.conditions,
            required_items: (data.conditions.required_items || []).map((item) => ({
                ...item,
                qty: Number.parseFloat(item.qty || 1),
            })),
        };
    }

    return data.conditions;
};

const normalizeRewards = (data) => {
    if (data.reward_type === 'amount_off') {
        return {
            ...data.rewards,
            amount_centavos: Number.parseInt(data.rewards.amount_centavos || 0, 10),
        };
    }

    if (data.reward_type === 'fixed_bundle_price') {
        return {
            ...data.rewards,
            bundle_price_centavos: Number.parseInt(data.rewards.bundle_price_centavos || 0, 10),
        };
    }

    if (data.reward_type === 'free_item') {
        return {
            ...data.rewards,
            quantity: Number.parseInt(data.rewards.quantity || 1, 10),
        };
    }

    return data.rewards;
};

const normalizePayload = (data) => ({
    ...data,
    priority: Number.parseInt(data.priority || 0, 10),
    min_spend_centavos: Number.parseInt(data.min_spend_centavos || 0, 10),
    conditions: normalizeConditions(data),
    rewards: normalizeRewards(data),
    max_applications_per_sale: normalizeNullableInteger(data.max_applications_per_sale),
    max_discount_centavos: normalizeNullableInteger(data.max_discount_centavos),
    exclusive_group: data.exclusive_group || null,
    branch_ids: data.branch_ids || [],
});

export default function Index({ auth, promotions, branches, products, categories }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingPromotion, setEditingPromotion] = useState(null);

    const { data, setData, post, put, delete: destroy, processing, errors, reset, clearErrors, transform } = useForm(emptyForm);

    const setConditionType = (conditionType) => {
        if (conditionType === 'buy_x_get_y') {
            setData({
                ...data,
                rule_type: 'bogo',
                condition_type: conditionType,
                reward_type: data.reward_type === 'fixed_bundle_price' ? 'percent_off' : data.reward_type,
                conditions: {
                    buy_qty: 1,
                    reward_qty: 1,
                    buy_product_ids: [],
                    buy_category_ids: [],
                    reward_product_ids: [],
                    reward_category_ids: [],
                },
                rewards: data.reward_type === 'amount_off' ? { amount_centavos: 100 } : { percent: 100 },
                min_spend_centavos: 0,
            });
            return;
        }

        if (conditionType === 'bundle_match') {
            setData({
                ...data,
                rule_type: 'combo_package',
                condition_type: conditionType,
                reward_type: 'fixed_bundle_price',
                conditions: {
                    required_items: [{ product_id: '', category_id: '', qty: 1 }],
                },
                rewards: {
                    bundle_price_centavos: 0,
                },
                min_spend_centavos: 0,
            });
            return;
        }

        setData({
            ...data,
            rule_type: 'discount_tier',
            condition_type: 'minimum_spend',
            reward_type: data.reward_type === 'free_item' || data.reward_type === 'fixed_bundle_price' ? 'percent_off' : data.reward_type,
            conditions: {
                min_spend_centavos: 0,
                eligible_product_ids: [],
                eligible_category_ids: [],
            },
            rewards: data.reward_type === 'amount_off' ? { amount_centavos: 100 } : { percent: 10 },
            min_spend_centavos: 0,
        });
    };

    const setRewardType = (rewardType) => {
        let rewards = {};

        if (rewardType === 'percent_off') {
            rewards = { percent: data.rewards.percent || 10 };
        } else if (rewardType === 'amount_off') {
            rewards = { amount_centavos: data.rewards.amount_centavos || 100 };
        } else if (rewardType === 'free_item') {
            rewards = { product_id: data.rewards.product_id || '', quantity: data.rewards.quantity || 1 };
        } else if (rewardType === 'fixed_bundle_price') {
            rewards = { bundle_price_centavos: data.rewards.bundle_price_centavos || 0 };
        }

        setData('reward_type', rewardType);
        setData('rewards', rewards);
    };

    const openCreate = () => {
        setEditingPromotion(null);
        reset();
        clearErrors();
        setModalOpen(true);
    };

    const openEdit = (promotion) => {
        const rule = promotion.rules?.[0] || {};

        setEditingPromotion(promotion);
        clearErrors();
        setData({
            name: promotion.name || '',
            description: promotion.description || '',
            rule_type: promotion.rule_type || 'discount_tier',
            priority: promotion.priority ?? 0,
            starts_at: toLocalDatetime(promotion.starts_at),
            ends_at: toLocalDatetime(promotion.ends_at),
            is_active: !!promotion.is_active,
            currency: promotion.currency || 'PHP',
            timezone: promotion.timezone || 'Asia/Manila',
            branch_ids: (promotion.branches || []).map((branch) => branch.id),
            schema_version: rule.schema_version || 'v1',
            condition_type: rule.condition_type || 'minimum_spend',
            reward_type: rule.reward_type || 'percent_off',
            conditions: rule.conditions || emptyForm.conditions,
            rewards: rule.rewards || emptyForm.rewards,
            stackable: !!rule.stackable,
            min_spend_centavos: rule.min_spend_centavos ?? 0,
            max_applications_per_sale: rule.max_applications_per_sale ?? '',
            max_discount_centavos: rule.max_discount_centavos ?? '',
            exclusive_group: rule.exclusive_group || '',
        });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingPromotion(null);
        reset();
        clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();

        transform(normalizePayload);

        const options = {
            preserveScroll: true,
            onSuccess: closeModal,
        };

        if (editingPromotion) {
            put(route('admin.promotions.update', editingPromotion.id), options);
        } else {
            post(route('admin.promotions.store'), options);
        }
    };

    const deactivatePromotion = (promotion) => {
        if (confirm(`Deactivate ${promotion.name}? Historical sale snapshots remain unchanged.`)) {
            destroy(route('admin.promotions.destroy', promotion.id), {
                preserveScroll: true,
            });
        }
    };

    const updateCondition = (key, value) => {
        const nextConditions = { ...data.conditions, [key]: value };
        setData('conditions', nextConditions);
        if (key === 'min_spend_centavos') {
            setData('min_spend_centavos', Number.parseInt(value || 0, 10));
        }
    };

    const updateReward = (key, value) => {
        setData('rewards', { ...data.rewards, [key]: value });
    };

    const updateBundleItem = (index, key, value) => {
        const requiredItems = [...(data.conditions.required_items || [])];
        requiredItems[index] = { ...requiredItems[index], [key]: value };
        if (key === 'product_id' && value) {
            requiredItems[index].category_id = '';
        }
        if (key === 'category_id' && value) {
            requiredItems[index].product_id = '';
        }
        setData('conditions', { ...data.conditions, required_items: requiredItems });
    };

    const addBundleItem = () => {
        const requiredItems = [...(data.conditions.required_items || []), { product_id: '', category_id: '', qty: 1 }];
        setData('conditions', { ...data.conditions, required_items: requiredItems });
    };

    const removeBundleItem = (index) => {
        const requiredItems = (data.conditions.required_items || []).filter((_, itemIndex) => itemIndex !== index);
        setData('conditions', { ...data.conditions, required_items: requiredItems.length ? requiredItems : [{ product_id: '', category_id: '', qty: 1 }] });
    };

    const branchLabel = (promotion) => {
        if (!promotion.branches?.length) return 'All branches';
        return promotion.branches.map((branch) => branch.name).join(', ');
    };

    const ruleLabel = (promotion) => {
        const rule = promotion.rules?.[0];
        if (!rule) return 'No rule';

        if (rule.condition_type === 'buy_x_get_y') return 'Buy X Get Y';
        if (rule.condition_type === 'bundle_match') return 'Combo bundle';
        return 'Minimum spend';
    };

    const rewardLabel = (promotion) => {
        const rule = promotion.rules?.[0];
        if (!rule) return 'Pending';

        if (rule.reward_type === 'percent_off') return `${rule.rewards?.percent || 0}% off`;
        if (rule.reward_type === 'amount_off') return `PHP ${(Number(rule.rewards?.amount_centavos || 0) / 100).toFixed(2)} off`;
        if (rule.reward_type === 'fixed_bundle_price') return `Bundle PHP ${(Number(rule.rewards?.bundle_price_centavos || 0) / 100).toFixed(2)}`;
        if (rule.reward_type === 'free_item') return 'Free item';
        return rule.reward_type;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 className="flex items-center gap-2 text-2xl font-extrabold leading-tight text-slate-800">
                            <BadgePercent className="h-6 w-6 text-emerald-600" />
                            Promotions
                        </h2>
                        <p className="mt-1 text-sm font-medium text-slate-500">
                            Configure automatic cart promotions, branch scope, and non-stacking rules.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-sm transition hover:bg-emerald-500"
                    >
                        <Plus size={16} />
                        Add promotion
                    </button>
                </div>
            }
        >
            <Head title="Promotions" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-500">Promotion</th>
                                        <th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-500">Rule</th>
                                        <th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-500">Reward</th>
                                        <th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-500">Scope</th>
                                        <th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-500">Window</th>
                                        <th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-500">Status</th>
                                        <th className="px-5 py-4 text-right text-[11px] font-black uppercase tracking-wider text-slate-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {promotions.length > 0 ? promotions.map((promotion) => (
                                        <tr key={promotion.id} className="hover:bg-slate-50/70">
                                            <td className="px-5 py-4">
                                                <div className="font-extrabold text-slate-800">{promotion.name}</div>
                                                <div className="mt-1 text-xs font-semibold text-slate-500">Priority {promotion.priority}</div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <span className="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                    <Layers size={13} />
                                                    {ruleLabel(promotion)}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-sm font-bold text-slate-700">{rewardLabel(promotion)}</td>
                                            <td className="max-w-xs px-5 py-4 text-sm font-medium text-slate-600">{branchLabel(promotion)}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                                    <CalendarClock size={14} />
                                                    <span>{toLocalDatetime(promotion.starts_at).replace('T', ' ')} to {toLocalDatetime(promotion.ends_at).replace('T', ' ')}</span>
                                                </div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-black uppercase tracking-wider ${
                                                    promotion.is_active
                                                        ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                        : 'border-slate-200 bg-slate-50 text-slate-500'
                                                }`}>
                                                    {promotion.is_active ? <CheckCircle2 size={12} /> : <XCircle size={12} />}
                                                    {promotion.is_active ? 'Active' : 'Inactive'}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(promotion)}
                                                        className="rounded-lg p-2 text-slate-500 transition hover:bg-emerald-50 hover:text-emerald-700"
                                                        title="Edit promotion"
                                                    >
                                                        <Edit2 size={16} />
                                                    </button>
                                                    {promotion.is_active && (
                                                        <button
                                                            type="button"
                                                            onClick={() => deactivatePromotion(promotion)}
                                                            className="rounded-lg p-2 text-slate-500 transition hover:bg-rose-50 hover:text-rose-700"
                                                            title="Deactivate promotion"
                                                        >
                                                            <Trash2 size={16} />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="7" className="px-5 py-12 text-center text-sm font-semibold text-slate-500">
                                                No promotions configured yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={modalOpen} onClose={closeModal} maxWidth="5xl">
                <form onSubmit={submit} className="max-h-[85vh] overflow-y-auto p-6">
                    <div className="mb-6 flex items-start justify-between gap-4">
                        <div>
                            <h3 className="flex items-center gap-2 text-lg font-extrabold text-slate-800">
                                <Gift className="h-5 w-5 text-emerald-600" />
                                {editingPromotion ? 'Edit promotion' : 'Add promotion'}
                            </h3>
                            <p className="mt-1 text-sm font-medium text-slate-500">Promotion math remains server-authoritative at checkout.</p>
                        </div>
                        <button type="button" onClick={closeModal} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                            <XCircle size={18} />
                        </button>
                    </div>

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <InputLabel value="Promotion name" />
                            <TextInput value={data.name} onChange={(e) => setData('name', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Priority" />
                            <TextInput type="number" min="0" value={data.priority} onChange={(e) => setData('priority', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.priority} className="mt-1" />
                        </div>

                        <div className="lg:col-span-3">
                            <InputLabel value="Description" />
                            <textarea
                                value={data.description || ''}
                                onChange={(e) => setData('description', e.target.value)}
                                className="mt-1 min-h-20 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="Starts at" />
                            <TextInput type="datetime-local" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.starts_at} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Ends at" />
                            <TextInput type="datetime-local" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.ends_at} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Timezone" />
                            <TextInput value={data.timezone} onChange={(e) => setData('timezone', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.timezone} className="mt-1" />
                        </div>

                        <div className="lg:col-span-3">
                            <InputLabel value="Branch scope" />
                            <select
                                multiple
                                value={data.branch_ids}
                                onChange={(e) => setData('branch_ids', selectedValues(e))}
                                className="mt-1 h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>{branch.name}</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs font-medium text-slate-500">Leave unselected to apply to all branches available to this tenant.</p>
                            <InputError message={errors.branch_ids || errors['branch_ids.0']} className="mt-1" />
                        </div>
                    </div>

                    <div className="my-6 border-t border-slate-200" />

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <div>
                            <InputLabel value="Rule condition" />
                            <select
                                value={data.condition_type}
                                onChange={(e) => setConditionType(e.target.value)}
                                className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="minimum_spend">Minimum spend</option>
                                <option value="buy_x_get_y">Buy X Get Y</option>
                                <option value="bundle_match">Combo bundle</option>
                            </select>
                            <InputError message={errors.condition_type} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Promotion type" />
                            <select
                                value={data.rule_type}
                                disabled
                                className="mt-1 w-full rounded-md border-gray-300 bg-slate-50 shadow-sm"
                            >
                                {ruleTypes.map((type) => (
                                    <option key={type.value} value={type.value}>{type.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Reward" />
                            <select
                                value={data.reward_type}
                                onChange={(e) => setRewardType(e.target.value)}
                                className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                {rewardTypes
                                    .filter((type) => data.condition_type !== 'minimum_spend' || ['percent_off', 'amount_off'].includes(type.value))
                                    .filter((type) => data.condition_type !== 'bundle_match' || ['fixed_bundle_price', 'amount_off'].includes(type.value))
                                    .map((type) => (
                                        <option key={type.value} value={type.value}>{type.label}</option>
                                    ))}
                            </select>
                            <InputError message={errors.reward_type} className="mt-1" />
                        </div>
                    </div>

                    {data.condition_type === 'minimum_spend' && (
                        <div className="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                            <div>
                                <InputLabel value="Minimum spend centavos" />
                                <TextInput type="number" min="0" value={data.conditions.min_spend_centavos || 0} onChange={(e) => updateCondition('min_spend_centavos', e.target.value)} className="mt-1 w-full" />
                                <InputError message={errors['conditions.min_spend_centavos']} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Eligible products" />
                                <select multiple value={data.conditions.eligible_product_ids || []} onChange={(e) => updateCondition('eligible_product_ids', selectedValues(e))} className="mt-1 h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    {products.map((product) => (
                                        <option key={product.id} value={product.id}>{product.name} {product.sku ? `(${product.sku})` : ''}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Eligible categories" />
                                <select multiple value={data.conditions.eligible_category_ids || []} onChange={(e) => updateCondition('eligible_category_ids', selectedValues(e))} className="mt-1 h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>{category.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}

                    {data.condition_type === 'buy_x_get_y' && (
                        <div className="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-4">
                            <div>
                                <InputLabel value="Buy quantity" />
                                <TextInput type="number" min="1" value={data.conditions.buy_qty || 1} onChange={(e) => updateCondition('buy_qty', Number.parseInt(e.target.value || 1, 10))} className="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel value="Reward quantity" />
                                <TextInput type="number" min="1" value={data.conditions.reward_qty || 1} onChange={(e) => updateCondition('reward_qty', Number.parseInt(e.target.value || 1, 10))} className="mt-1 w-full" />
                            </div>
                            <div>
                                <InputLabel value="Buy products" />
                                <select multiple value={data.conditions.buy_product_ids || []} onChange={(e) => updateCondition('buy_product_ids', selectedValues(e))} className="mt-1 h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    {products.map((product) => (
                                        <option key={product.id} value={product.id}>{product.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Reward products" />
                                <select multiple value={data.conditions.reward_product_ids || []} onChange={(e) => updateCondition('reward_product_ids', selectedValues(e))} className="mt-1 h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    {products.map((product) => (
                                        <option key={product.id} value={product.id}>{product.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="lg:col-span-2">
                                <InputLabel value="Buy categories" />
                                <select multiple value={data.conditions.buy_category_ids || []} onChange={(e) => updateCondition('buy_category_ids', selectedValues(e))} className="mt-1 h-24 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>{category.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="lg:col-span-2">
                                <InputLabel value="Reward categories" />
                                <select multiple value={data.conditions.reward_category_ids || []} onChange={(e) => updateCondition('reward_category_ids', selectedValues(e))} className="mt-1 h-24 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>{category.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}

                    {data.condition_type === 'bundle_match' && (
                        <div className="mt-5 space-y-3">
                            {(data.conditions.required_items || []).map((item, index) => (
                                <div key={index} className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 p-3 lg:grid-cols-12">
                                    <div className="lg:col-span-5">
                                        <InputLabel value="Product" />
                                        <select value={item.product_id || ''} onChange={(e) => updateBundleItem(index, 'product_id', e.target.value)} className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                            <option value="">Any product in category</option>
                                            {products.map((product) => (
                                                <option key={product.id} value={product.id}>{product.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="lg:col-span-5">
                                        <InputLabel value="Category" />
                                        <select value={item.category_id || ''} onChange={(e) => updateBundleItem(index, 'category_id', e.target.value)} className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                            <option value="">Specific product selected</option>
                                            {categories.map((category) => (
                                                <option key={category.id} value={category.id}>{category.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="lg:col-span-1">
                                        <InputLabel value="Qty" />
                                        <TextInput type="number" min="0.001" step="0.001" value={item.qty || 1} onChange={(e) => updateBundleItem(index, 'qty', Number.parseFloat(e.target.value || 1))} className="mt-1 w-full" />
                                    </div>
                                    <div className="flex items-end lg:col-span-1">
                                        <button type="button" onClick={() => removeBundleItem(index)} className="rounded-lg p-2 text-slate-500 hover:bg-rose-50 hover:text-rose-700" title="Remove bundle item">
                                            <Trash2 size={16} />
                                        </button>
                                    </div>
                                </div>
                            ))}
                            <button type="button" onClick={addBundleItem} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-black uppercase tracking-wider text-slate-600 hover:bg-slate-50">
                                <PackageCheck size={14} />
                                Add bundle item
                            </button>
                        </div>
                    )}

                    <div className="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                        {data.reward_type === 'percent_off' && (
                            <div>
                                <InputLabel value="Percent off" />
                                <TextInput type="number" min="0.01" max="100" step="0.01" value={data.rewards.percent || ''} onChange={(e) => updateReward('percent', Number.parseFloat(e.target.value || 0))} className="mt-1 w-full" />
                                <InputError message={errors['rewards.percent']} className="mt-1" />
                            </div>
                        )}
                        {data.reward_type === 'amount_off' && (
                            <div>
                                <InputLabel value="Amount off centavos" />
                                <TextInput type="number" min="1" value={data.rewards.amount_centavos || ''} onChange={(e) => updateReward('amount_centavos', Number.parseInt(e.target.value || 0, 10))} className="mt-1 w-full" />
                                <InputError message={errors['rewards.amount_centavos']} className="mt-1" />
                            </div>
                        )}
                        {data.reward_type === 'fixed_bundle_price' && (
                            <div>
                                <InputLabel value="Bundle price centavos" />
                                <TextInput type="number" min="0" value={data.rewards.bundle_price_centavos || 0} onChange={(e) => updateReward('bundle_price_centavos', Number.parseInt(e.target.value || 0, 10))} className="mt-1 w-full" />
                                <InputError message={errors['rewards.bundle_price_centavos']} className="mt-1" />
                            </div>
                        )}
                        {data.reward_type === 'free_item' && (
                            <>
                                <div>
                                    <InputLabel value="Free product" />
                                    <select value={data.rewards.product_id || ''} onChange={(e) => updateReward('product_id', e.target.value)} className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                        <option value="">Select product</option>
                                        {products.map((product) => (
                                            <option key={product.id} value={product.id}>{product.name}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Free quantity" />
                                    <TextInput type="number" min="1" value={data.rewards.quantity || 1} onChange={(e) => updateReward('quantity', Number.parseInt(e.target.value || 1, 10))} className="mt-1 w-full" />
                                </div>
                            </>
                        )}
                        <div>
                            <InputLabel value="Max applications" />
                            <TextInput type="number" min="1" value={data.max_applications_per_sale} onChange={(e) => setData('max_applications_per_sale', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.max_applications_per_sale} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Max discount centavos" />
                            <TextInput type="number" min="0" value={data.max_discount_centavos} onChange={(e) => setData('max_discount_centavos', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.max_discount_centavos} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Exclusive group" />
                            <TextInput value={data.exclusive_group || ''} onChange={(e) => setData('exclusive_group', e.target.value)} className="mt-1 w-full" />
                            <InputError message={errors.exclusive_group} className="mt-1" />
                        </div>
                    </div>

                    <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className="flex items-center gap-3 rounded-lg border border-slate-200 p-3 text-sm font-bold text-slate-700">
                            <input type="checkbox" checked={!!data.stackable} onChange={(e) => setData('stackable', e.target.checked)} className="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500" />
                            Stackable with later eligible promotions
                        </label>
                        <label className="flex items-center gap-3 rounded-lg border border-slate-200 p-3 text-sm font-bold text-slate-700">
                            <input type="checkbox" checked={!!data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500" />
                            Active
                        </label>
                    </div>

                    <div className="mt-6 flex justify-end gap-3 border-t border-slate-200 pt-5">
                        <SecondaryButton type="button" onClick={closeModal}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={processing}>
                            {editingPromotion ? 'Save promotion' : 'Create promotion'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
