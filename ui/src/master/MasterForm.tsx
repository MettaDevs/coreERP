import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@apperp/ui/sheet';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage } from '../api';
import { MasterConfig, MasterRecord, ParentSummary, parentIdOf, parentSummaryOf } from './masters';

type FormValue = { nama: string; keterangan: string; aktif: boolean; parentId: string };

export default function MasterForm({
    config,
    value,
    parentOptions,
    parentOptionsError,
    onClose,
    onSaved,
}: {
    config: MasterConfig;
    value: MasterRecord | null;
    parentOptions: ParentSummary[];
    parentOptionsError: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const parent = config.parent;
    const [form, setForm] = useState<FormValue>(() => ({
        nama: value?.nama ?? '',
        keterangan: value?.keterangan ?? '',
        aktif: value?.aktif ?? true,
        parentId: value && parent ? parentIdOf(value, parent) : '',
    }));
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [groups, setGroups] = useState<MasterRecord[]>([]);
    const [categories, setCategories] = useState<MasterRecord[]>([]);
    const [types, setTypes] = useState<MasterRecord[]>([]);
    const [groupId, setGroupId] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const creationKey = useRef(crypto.randomUUID());
    const sheetContentRef = useRef<HTMLDivElement>(null);
    const isEntitasAset = config.resource === 'entitas-aset';

    const options = useMemo(() => {
        const current = value && parent ? parentSummaryOf(value, parent) : null;
        return !current || parentOptions.some((option) => option.id === current.id)
            ? parentOptions
            : [current, ...parentOptions];
    }, [parentOptions, value, parent]);
    const categoryItems = categories.filter((category) => category.group_aset_id === groupId);
    const typeItems = types.filter((type) => type.kategori_aset_id === categoryId);

    useEffect(() => {
        if (!isEntitasAset) return;
        let cancelled = false;
        Promise.all([
            api<{ data: MasterRecord[] }>('/group-aset?per_page=100&aktif=true'),
            api<{ data: MasterRecord[] }>('/kategori-aset?per_page=100&aktif=true'),
            api<{ data: MasterRecord[] }>('/jenis-aset?per_page=100&aktif=true'),
        ]).then(([groupResult, categoryResult, typeResult]) => {
            if (cancelled) return;
            setGroups(groupResult.data);
            setCategories(categoryResult.data);
            setTypes(typeResult.data);
            const selectedType = typeResult.data.find((type) => type.id === form.parentId);
            const selectedCategoryId = typeof selectedType?.kategori_aset_id === 'string' ? selectedType.kategori_aset_id : '';
            const selectedCategory = categoryResult.data.find((category) => category.id === selectedCategoryId);
            setCategoryId(selectedCategoryId);
            setGroupId(typeof selectedCategory?.group_aset_id === 'string' ? selectedCategory.group_aset_id : '');
        }).catch(() => {
            if (!cancelled) setError('Pilihan group, kategori, atau jenis aset belum dapat dimuat.');
        });
        return () => { cancelled = true; };
    }, [isEntitasAset]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (parent && parent.required !== false && !form.parentId) {
            setError(`Pilih ${parent.label.toLowerCase()} terlebih dahulu.`);
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api(`/${config.resource}${value ? `/${value.id}` : ''}`, {
                method: value ? 'PATCH' : 'POST',
                headers: value ? undefined : { 'Idempotency-Key': creationKey.current },
                body: JSON.stringify({
                    nama: form.nama,
                    keterangan: form.keterangan,
                    aktif: form.aktif,
                    ...(parent ? { [parent.field]: form.parentId || null } : {}),
                }),
            });
            onSaved();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent ref={sheetContentRef} side="right" className="w-full gap-0 p-0 sm:max-w-xl">
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>{value ? 'Ubah' : 'Tambah'} {config.singular}</SheetTitle>
                </SheetHeader>
                <form className="flex min-h-0 flex-1 flex-col" onSubmit={submit}>
                    <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <FieldGroup>
                        <Field data-disabled="true">
                            <Input id="code" label={config.kodeLabel} value={value?.kode ?? 'Dibuat otomatis saat disimpan'} disabled />
                        </Field>
                        <Field>
                            <Input id="name" label={`${config.namaLabel} *`} autoFocus required maxLength={150} value={form.nama} onChange={(event) => setForm({ ...form, nama: event.target.value })} />
                        </Field>
                        {parent && isEntitasAset ? (
                            <>
                                <Field>
                                    <Select
                                        label="Group aset"
                                        required
                                        items={groups.map((group) => `${group.kode} - ${group.nama}`)}
                                        value={groups.find((group) => group.id === groupId) ? `${groups.find((group) => group.id === groupId)?.kode} - ${groups.find((group) => group.id === groupId)?.nama}` : undefined}
                                        placeholder="Pilih group aset"
                                        searchPlaceholder="Cari group aset"
                                        emptyMessage="Group aset tidak ditemukan."
                                        ariaLabel="Pilih group aset"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(item) => {
                                            setGroupId(groups.find((group) => `${group.kode} - ${group.nama}` === item)?.id ?? '');
                                            setCategoryId('');
                                            setForm({ ...form, parentId: '' });
                                        }}
                                    />
                                </Field>
                                <Field>
                                    <Select
                                        label="Kategori aset"
                                        required
                                        key={groupId}
                                        items={categoryItems.map((category) => `${category.kode} - ${category.nama}`)}
                                        value={categoryItems.find((category) => category.id === categoryId) ? `${categoryItems.find((category) => category.id === categoryId)?.kode} - ${categoryItems.find((category) => category.id === categoryId)?.nama}` : undefined}
                                        placeholder={groupId ? 'Pilih kategori aset' : 'Pilih group aset lebih dahulu'}
                                        searchPlaceholder="Cari kategori aset"
                                        emptyMessage="Kategori aset tidak ditemukan."
                                        ariaLabel="Pilih kategori aset"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(item) => {
                                            setCategoryId(categoryItems.find((category) => `${category.kode} - ${category.nama}` === item)?.id ?? '');
                                            setForm({ ...form, parentId: '' });
                                        }}
                                    />
                                </Field>
                                <Field>
                                    <Select
                                        label="Jenis aset"
                                        required
                                        key={categoryId}
                                        items={typeItems.map((type) => `${type.kode} - ${type.nama}`)}
                                        value={typeItems.find((type) => type.id === form.parentId) ? `${typeItems.find((type) => type.id === form.parentId)?.kode} - ${typeItems.find((type) => type.id === form.parentId)?.nama}` : undefined}
                                        placeholder={categoryId ? 'Pilih jenis aset' : 'Pilih kategori aset lebih dahulu'}
                                        searchPlaceholder="Cari jenis aset"
                                        emptyMessage="Jenis aset tidak ditemukan."
                                        ariaLabel="Pilih jenis aset"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(item) => setForm({ ...form, parentId: typeItems.find((type) => `${type.kode} - ${type.nama}` === item)?.id ?? '' })}
                                    />
                                </Field>
                            </>
                        ) : parent ? (
                            <Field data-invalid={Boolean(parentOptionsError)}>
                                <Select
                                    label={parent.label}
                                    required={parent.required !== false}
                                    items={options.map((option) => `${option.kode} — ${option.nama}`)}
                                    value={options.find((option) => option.id === form.parentId) ? `${options.find((option) => option.id === form.parentId)?.kode} — ${options.find((option) => option.id === form.parentId)?.nama}` : undefined}
                                    placeholder={`Pilih ${parent.label.toLowerCase()}`}
                                    searchPlaceholder={`Cari ${parent.label.toLowerCase()}`}
                                    emptyMessage={`${parent.label} tidak ditemukan.`}
                                    ariaLabel={`Pilih ${parent.label.toLowerCase()}`}
                                    portalContainer={sheetContentRef}
                                    onValueChange={(item) => setForm({ ...form, parentId: options.find((option) => `${option.kode} — ${option.nama}` === item)?.id ?? '' })}
                                />
                                {parentOptionsError && <FieldDescription>{parentOptionsError}</FieldDescription>}
                            </Field>
                        ) : null}
                        <Field>
                            <FieldLabel htmlFor="description">Keterangan</FieldLabel>
                            <Textarea id="description" rows={4} maxLength={2000} value={form.keterangan} onChange={(event) => setForm({ ...form, keterangan: event.target.value })} />
                        </Field>
                        <Field orientation="horizontal">
                            <Switch id="active" checked={form.aktif} onCheckedChange={(checked) => setForm({ ...form, aktif: checked })} />
                            <FieldLabel htmlFor="active">Data aktif dan dapat dipilih</FieldLabel>
                        </Field>
                        {error && <FieldError>{error}</FieldError>}
                    </FieldGroup>
                    </div>
                    <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                        <Button variant="outline" type="button" onClick={onClose}>Batal</Button>
                        <Button disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan'}</Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
