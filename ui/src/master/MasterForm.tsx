import { FormEvent, ReactNode, useMemo, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage } from '../api';
import DynamicField from './DynamicField';
import { FieldValue, isVisible, payloadValue, valueFrom } from './fields';
import { MasterConfig, MasterParentConfig, MasterRecord, ParentSummary, parentIdOf, parentSummaryOf } from './masters';

type FormValue = { nama: string; keterangan: string; aktif: boolean };

/** Label pilihan induk; satu bentuk untuk seluruh master agar tidak ada varian pemisah. */
function optionLabel(option: ParentSummary): string {
    return `${option.kode} — ${option.nama}`;
}

export default function MasterForm({
    config,
    value,
    parentOptions,
    parentOptionsError,
    onClose,
    onSaved,
    extraSection,
}: {
    config: MasterConfig;
    value: MasterRecord | null;
    /** Pilihan induk per kolom foreign key. */
    parentOptions: Record<string, ParentSummary[]>;
    /** Pesan kegagalan pemuatan pilihan, per kolom foreign key. */
    parentOptionsError: Record<string, string>;
    onClose: () => void;
    onSaved: () => void;
    /** Bagian tambahan di bawah field, misalnya matriks yang disunting di dalam form ini. */
    extraSection?: ReactNode;
}) {
    const parents = useMemo(() => config.parents ?? [], [config.parents]);
    const [form, setForm] = useState<FormValue>(() => ({
        nama: value?.nama ?? '',
        keterangan: value?.keterangan ?? '',
        aktif: value?.aktif ?? true,
    }));
    const [parentIds, setParentIds] = useState<Record<string, string>>(() =>
        Object.fromEntries(parents.map((parent) => [parent.field, value ? parentIdOf(value, parent) : ''])),
    );
    const extraFields = useMemo(() => config.extraFields ?? [], [config.extraFields]);
    const [extra, setExtra] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(extraFields.map((field) => [field.name, valueFrom(value, field)])),
    );
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const creationKey = useRef(crypto.randomUUID());
    const dialogRef = useRef<HTMLDivElement>(null);

    /**
     * Induk yang sedang dipakai record ini tetap dapat dipilih meski sudah diarsipkan,
     * supaya menyunting field lain tidak diam-diam memutus tautan induknya.
     */
    const optionsOf = useMemo(() => {
        const resolved: Record<string, ParentSummary[]> = {};
        for (const parent of parents) {
            const available = parentOptions[parent.field] ?? [];
            const current = value ? parentSummaryOf(value, parent) : null;
            resolved[parent.field] = !current || available.some((option) => option.id === current.id)
                ? available
                : [current, ...available];
        }
        return resolved;
    }, [parents, parentOptions, value]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const missing = parents.find((parent) => parent.required !== false && !parentIds[parent.field]);
        if (missing) {
            setError(`Pilih ${missing.label.toLowerCase()} terlebih dahulu.`);
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
                    ...Object.fromEntries(parents.map((parent) => [parent.field, parentIds[parent.field] || null])),
                    // Field yang sedang tersembunyi tidak dikirim, supaya mengganti metode
                    // tidak diam-diam menyimpan nilai milik metode sebelumnya.
                    ...Object.fromEntries(extraFields
                        .filter((field) => isVisible(field, extra))
                        .map((field) => [field.name, payloadValue(field, extra[field.name])])),
                }),
            });
            onSaved();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    function parentField(parent: MasterParentConfig) {
        const options = optionsOf[parent.field] ?? [];
        const selected = options.find((option) => option.id === parentIds[parent.field]);
        const loadError = parentOptionsError[parent.field] ?? '';

        return (
            <Field key={parent.field} data-invalid={Boolean(loadError)}>
                <Select
                    label={parent.required === false ? parent.label : `${parent.label} *`}
                    required={parent.required !== false}
                    items={options.map(optionLabel)}
                    value={selected ? optionLabel(selected) : undefined}
                    placeholder={`Pilih ${parent.label.toLowerCase()}`}
                    searchPlaceholder={`Cari ${parent.label.toLowerCase()}`}
                    emptyMessage={`${parent.label} tidak ditemukan.`}
                    ariaLabel={`Pilih ${parent.label.toLowerCase()}`}
                    portalContainer={dialogRef}
                    onValueChange={(item) => setParentIds({
                        ...parentIds,
                        [parent.field]: options.find((option) => optionLabel(option) === item)?.id ?? '',
                    })}
                />
                {loadError && <FieldDescription>{loadError}</FieldDescription>}
            </Field>
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent 
                size="compact" 
                showCloseButton 
                className="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-50 w-full max-w-2xl max-h-[85vh] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl rounded-2xl overflow-hidden flex flex-col p-0"
            >
                {/* Header Modal Terpusat */}
                <DialogHeader className="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-5">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400 border border-blue-100 dark:border-blue-900/50">
                            {value ? (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                            )}
                        </div>
                        <div>
                            <DialogTitle className="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                                {value ? 'Ubah' : 'Tambah'} {config.singular}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                {value ? `Perbarui informasi ${config.singular}` : `Isi formulir untuk menambahkan ${config.singular} baru`}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <form className="flex min-h-0 flex-1 flex-col justify-between overflow-hidden" onSubmit={submit}>
                    <DialogBody ref={dialogRef} className="min-h-0 flex-1 overflow-y-auto px-6 py-6 space-y-5 bg-slate-50/50 dark:bg-slate-950/20">
                        {/* Kartu Section 1: Informasi Utama */}
                        <div className="rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-5">
                            <div className="flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
                                <svg className="h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                    Informasi Utama
                                </h3>
                            </div>

                            <FieldGroup className="space-y-4">
                                {/* Kode Master (Auto-generated / Disabled) */}
                                <Field data-disabled="true">
                                    <div className="flex items-center justify-between mb-1.5">
                                        <FieldLabel className="text-xs font-medium text-slate-700 dark:text-slate-300">
                                            {config.kodeLabel}
                                        </FieldLabel>
                                        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                            <svg className="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                            </svg>
                                            Otomatis oleh Sistem
                                        </span>
                                    </div>
                                    <Input 
                                        id="code" 
                                        value={value?.kode ?? 'Dibuat otomatis saat disimpan'} 
                                        disabled 
                                        className="bg-slate-50 dark:bg-slate-950 font-mono text-xs text-slate-500 border-slate-200 dark:border-slate-800" 
                                    />
                                </Field>

                                {/* Nama Master */}
                                <Field>
                                    <Input 
                                        id="name" 
                                        label={`${config.namaLabel} *`} 
                                        autoFocus 
                                        required 
                                        maxLength={150} 
                                        placeholder={`Masukkan ${config.namaLabel.toLowerCase()}`}
                                        value={form.nama} 
                                        onChange={(event) => setForm({ ...form, nama: event.target.value })} 
                                    />
                                </Field>

                                {/* Dropdown Induk */}
                                {parents.map(parentField)}

                                {/* Field Dinamis Tambahan */}
                                {extraFields.filter((field) => isVisible(field, extra)).map((field) => (
                                    <DynamicField
                                        key={field.name}
                                        config={field}
                                        value={extra[field.name]}
                                        onChange={(next) => setExtra((current) => ({ ...current, [field.name]: next }))}
                                        portalContainer={dialogRef}
                                    />
                                ))}
                            </FieldGroup>
                        </div>

                        {/* Kartu Section 2: Keterangan & Deskripsi */}
                        <div className="rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900 space-y-4">
                            <div className="flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
                                <svg className="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h7" />
                                </svg>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                    Catatan Tambahan
                                </h3>
                            </div>
                            <Field>
                                <FieldLabel htmlFor="description" className="text-xs font-medium text-slate-700 dark:text-slate-300">
                                    Keterangan
                                </FieldLabel>
                                <Textarea 
                                    id="description" 
                                    rows={3} 
                                    maxLength={2000} 
                                    placeholder="Tambahkan keterangan atau rincian opsional…"
                                    className="resize-none text-sm"
                                    value={form.keterangan} 
                                    onChange={(event) => setForm({ ...form, keterangan: event.target.value })} 
                                />
                            </Field>
                        </div>

                        {/* Kartu Section 3: Status Aktif Card */}
                        <div className={`rounded-xl border p-4 transition-all duration-200 ${
                            form.aktif 
                                ? 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900/50 dark:bg-emerald-950/20' 
                                : 'border-slate-200 bg-slate-100/50 dark:border-slate-800 dark:bg-slate-900/50'
                        }`}>
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                                        form.aktif 
                                            ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' 
                                            : 'bg-slate-200 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                    }`}>
                                        {form.aktif ? (
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        ) : (
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        )}
                                    </div>
                                    <div>
                                        <p className="text-xs font-semibold text-slate-900 dark:text-slate-100">
                                            Status Aktif Data
                                        </p>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                            {form.aktif ? 'Data ini aktif dan dapat dipilih dalam transaksi.' : 'Data dalam status diarsipkan / non-aktif.'}
                                        </p>
                                    </div>
                                </div>
                                <Switch 
                                    id="active" 
                                    checked={form.aktif} 
                                    onCheckedChange={(checked) => setForm({ ...form, aktif: checked })} 
                                />
                            </div>
                        </div>

                        {/* Pesan Error Validasi */}
                        {error && (
                            <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-600 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-400 flex items-center gap-2">
                                <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>{error}</span>
                            </div>
                        )}
                    </DialogBody>

                    {extraSection}

                    {/* Footer Form Modal */}
                    <DialogFooter className="border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4 flex flex-row items-center justify-end gap-3">
                        <Button 
                            variant="outline" 
                            type="button" 
                            onClick={onClose}
                            className="text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            Batal
                        </Button>
                        <Button 
                            disabled={saving}
                            className="bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium shadow-xs px-5 flex items-center gap-1.5 transition-all"
                        >
                            {saving ? (
                                <>
                                    <svg className="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    <span>Menyimpan…</span>
                                </>
                            ) : (
                                <span>Simpan</span>
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}


