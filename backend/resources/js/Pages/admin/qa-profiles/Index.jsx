import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button, InlineAlert, Modal, TextField } from '@openmes/ui';
import AppLayout from '../../../layouts/AppLayout';
import { apiCall } from '../../../lib/http';
import { __ } from '../../../lib/i18n';

const SELECT_CLASS =
    'w-full text-[13px] text-om-ink bg-om-bg rounded-om-sm border border-om-line px-3 py-2.5 outline-none transition-colors focus:border-om-accent focus:ring-[3px] focus:ring-[rgba(234,90,43,.12)]';

function emptyForm() {
    return {
        product_type_id: '',
        required: true,
        active: true,
        checklist: [],
    };
}

function itemFromProfile(profile) {
    return {
        id: profile.id,
        label: profile.label ?? '',
        type: profile.type ?? 'bool',
        required: profile.required ?? true,
        min: profile.min ?? '',
        max: profile.max ?? '',
    };
}

function errorMessage(json) {
    if (json?.errors) {
        return Object.values(json.errors).flat().join(' ');
    }

    return json?.message || __('Could not save the QA profile.');
}

export default function QaProfilesIndex() {
    const { profiles = [], productTypes = [] } = usePage().props;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    const openCreate = () => {
        setEditing(null);
        setForm(emptyForm());
        setError('');
        setOpen(true);
    };

    const openEdit = (profile) => {
        setEditing(profile);
        setForm({
            product_type_id: String(profile.product_type_id),
            required: profile.required,
            active: profile.active,
            checklist: (profile.checklist_json ?? []).map(itemFromProfile),
        });
        setError('');
        setOpen(true);
    };

    const updateRow = (index, patch) => {
        setForm((current) => ({
            ...current,
            checklist: current.checklist.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        }));
    };

    const addRow = () => {
        setForm((current) => ({
            ...current,
            checklist: [...current.checklist, { id: '', label: '', type: 'bool', required: true, min: '', max: '' }],
        }));
    };

    const removeRow = (index) => {
        setForm((current) => ({
            ...current,
            checklist: current.checklist.filter((_, i) => i !== index),
        }));
    };

    const save = async () => {
        setSaving(true);
        setError('');

        const checklist = form.checklist.map((row) => {
            const item = {
                id: row.id.trim(),
                label: row.label.trim(),
                type: row.type,
                required: !!row.required,
            };

            if (row.type === 'number') {
                if (row.min !== '' && row.min !== null) item.min = Number(row.min);
                if (row.max !== '' && row.max !== null) item.max = Number(row.max);
            }

            return item;
        });

        const payload = {
            product_type_id: Number(form.product_type_id),
            required: form.required,
            active: form.active,
            checklist_json: checklist,
        };

        try {
            const response = editing
                ? await apiCall(`/api/v1/qa-profiles/${editing.id}`, 'PATCH', payload)
                : await apiCall('/api/v1/qa-profiles', 'POST', payload);

            if (!response.ok) {
                setError(errorMessage(await response.json().catch(() => null)));
                return;
            }

            setOpen(false);
            router.reload({ preserveScroll: true });
        } catch {
            setError(__('Could not save the QA profile.'));
        } finally {
            setSaving(false);
        }
    };

    const destroy = async (profile) => {
        const name = profile.product_type?.code ?? profile.product_type_code;

        if (!window.confirm(__('Delete profile ":name"?', { name }))) {
            return;
        }

        const response = await apiCall(`/api/v1/qa-profiles/${profile.id}`, 'DELETE');

        if (response.ok) {
            router.reload({ preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={__('QA Profiles')} />

            <div className="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-[-0.02em] text-om-ink">{__('QA Profiles')}</h1>
                    <p className="mt-0.5 text-[12.5px] text-om-muted">{__('Quality checklist profiles per MES product type.')}</p>
                </div>
                <Button variant="primary" onClick={openCreate}>
                    {__('New profile')}
                </Button>
            </div>

            <div className="overflow-hidden rounded-om border border-om-line bg-om-card">
                <table className="w-full text-left text-[13px]">
                    <thead className="border-b border-om-line2 bg-om-panel">
                        <tr className="font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">
                            <th className="px-4 py-3">{__('Product type')}</th>
                            <th className="px-4 py-3">{__('Name')}</th>
                            <th className="px-4 py-3">{__('Required')}</th>
                            <th className="px-4 py-3">{__('Active')}</th>
                            <th className="px-4 py-3">{__('Version')}</th>
                            <th className="px-4 py-3 text-right">{__('Actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {profiles.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-om-muted">
                                    {__('No QA profiles yet.')}
                                </td>
                            </tr>
                        )}

                        {profiles.map((profile) => (
                            <tr key={profile.id} className="border-t border-om-line2">
                                <td className="px-4 py-3 font-mono text-om-ink">{profile.product_type?.code ?? profile.product_type_code}</td>
                                <td className="px-4 py-3 text-om-muted">{profile.product_type?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-om-muted">{profile.required ? __('Yes') : __('No')}</td>
                                <td className="px-4 py-3 text-om-muted">{profile.active ? __('Yes') : __('No')}</td>
                                <td className="px-4 py-3 text-om-muted">{profile.version}</td>
                                <td className="px-4 py-3">
                                    <div className="flex justify-end gap-2">
                                        <Button variant="secondary" size="sm" onClick={() => openEdit(profile)}>
                                            {__('Edit')}
                                        </Button>
                                        <Button variant="danger" size="sm" onClick={() => destroy(profile)}>
                                            {__('Delete')}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? __('Edit profile') : __('New profile')}
                closeLabel={__('Close')}
                width={720}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            {__('Cancel')}
                        </Button>
                        <Button variant="primary" loading={saving} onClick={save}>
                            {__('Save')}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    {error && <InlineAlert severity="error" title={error} />}

                    <div>
                        <label className="mb-[7px] block font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">
                            {__('Product type')}
                        </label>
                        <select
                            className={SELECT_CLASS}
                            value={form.product_type_id}
                            onChange={(e) => setForm((current) => ({ ...current, product_type_id: e.target.value }))}
                        >
                            <option value="">{__('Select a product type')}</option>
                            {productTypes.map((productType) => (
                                <option key={productType.id} value={productType.id}>
                                    {productType.code} — {productType.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-wrap items-center gap-6">
                        <label className="flex items-center gap-2 text-[13px] text-om-ink">
                            <input
                                type="checkbox"
                                checked={form.required}
                                onChange={(e) => setForm((current) => ({ ...current, required: e.target.checked }))}
                            />
                            {__('Required')}
                        </label>
                        <label className="flex items-center gap-2 text-[13px] text-om-ink">
                            <input
                                type="checkbox"
                                checked={form.active}
                                onChange={(e) => setForm((current) => ({ ...current, active: e.target.checked }))}
                            />
                            {__('Active')}
                        </label>
                    </div>

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <span className="font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">{__('Checklist')}</span>
                            <Button variant="ghost" size="sm" onClick={addRow}>
                                {__('Add item')}
                            </Button>
                        </div>

                        <div className="space-y-3">
                            {form.checklist.length === 0 && (
                                <p className="text-[12px] text-om-muted">{__('No checklist items.')}</p>
                            )}

                            {form.checklist.map((row, index) => (
                                <div key={index} className="rounded-om-sm border border-om-line2 bg-om-panel p-3">
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <TextField
                                            label={__('ID')}
                                            mono
                                            value={row.id}
                                            onChange={(value) => updateRow(index, { id: value })}
                                        />
                                        <TextField
                                            label={__('Label')}
                                            value={row.label}
                                            onChange={(value) => updateRow(index, { label: value })}
                                        />
                                        <div>
                                            <label className="mb-[7px] block font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">
                                                {__('Type')}
                                            </label>
                                            <select
                                                className={SELECT_CLASS}
                                                value={row.type}
                                                onChange={(e) => updateRow(index, { type: e.target.value })}
                                            >
                                                <option value="bool">{__('Bool')}</option>
                                                <option value="number">{__('Number')}</option>
                                                <option value="text">{__('Text')}</option>
                                            </select>
                                        </div>
                                        <div className="flex items-end justify-between gap-2">
                                            <label className="flex items-center gap-2 text-[13px] text-om-ink">
                                                <input
                                                    type="checkbox"
                                                    checked={row.required}
                                                    onChange={(e) => updateRow(index, { required: e.target.checked })}
                                                />
                                                {__('Required')}
                                            </label>
                                            <Button variant="danger" size="sm" onClick={() => removeRow(index)}>
                                                {__('Remove')}
                                            </Button>
                                        </div>

                                        {row.type === 'number' && (
                                            <>
                                                <TextField
                                                    label={__('Min')}
                                                    type="number"
                                                    value={row.min}
                                                    onChange={(value) => updateRow(index, { min: value })}
                                                />
                                                <TextField
                                                    label={__('Max')}
                                                    type="number"
                                                    value={row.max}
                                                    onChange={(value) => updateRow(index, { max: value })}
                                                />
                                            </>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </Modal>
        </>
    );
}

QaProfilesIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
