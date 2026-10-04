import { Button } from '@apperp/ui/button';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import { Plus, X } from 'lucide-react';
import type { RefObject } from 'react';
import { useEffect, useMemo, useState } from 'react';
import type { DatasetField } from '@/lib/analytics/types';
import { apiJson } from '@/lib/core-api';

/**
 * Saringan pengguna pada kolom data analitik: versi Core dari pola filter tambahan laporan K-30
 * (`AdditionalFilters` di UI module aset, padanan "+ Filter" request page Business Central), dengan sintaks yang
 * sama karena server menjalankannya lewat `FieldFilterExpression` yang sama (KA-08).
 *
 * - Data analitik tidak menyatakan kolom saringan bawaan, jadi yang tampil adalah kolom yang sudah berisi (dari
 *   tautan atau bagian yang diubah) lalu kolom yang ditambahkan lewat "Tambah saringan".
 * - Teks, angka, dan tanggal memakai ekspresi dengan contoh sintaks di bawah isian; nilainya baru dipakai saat
 *   Enter atau saat isian ditinggalkan, supaya ekspresi yang belum selesai ("100..") tidak dijalankan.
 * - Pilihan dan ya/tidak memakai daftar nilainya. Rujukan memakai daftar dari endpoint lookup module yang disebut
 *   data (`/api/modules/<module>/v1/<lookup>`), kecuali entitas legal dan unit kerja yang dibaca dari Core.
 * - Ekspresi yang tidak terbaca ditolak server dengan pesan yang menyebut kolom dan isiannya; pesan itu tampil di
 *   bawah isiannya (`errors`).
 */

type FilterValue = string | string[];

type Option = { value: string; label: string };

/** Contoh sintaks per tipe kolom, sama dengan filter tambahan laporan. */
const HINTS: Partial<Record<DatasetField['type'], string>> = {
    text: 'Contoh: Asus|Lenovo, *laptop*, @asus*, <>Rusak',
    number: 'Contoh: >1000000, 100..500, <>0',
    date: 'Contoh: 01/09/2026..30/09/2026, >=01/01/2026, t (hari ini)',
    datetime: 'Contoh: 01/09/2026..30/09/2026, >=01/01/2026, t (hari ini)',
};

const BOOLEAN_ITEMS: Option[] = [
    { value: '1', label: 'Ya' },
    { value: '0', label: 'Tidak' },
];

/** Dimensi bersama milik Core yang pilihannya dibaca dari daftar organisasi tenant, menurut klasifikasinya. */
const ORGANIZATION_DIMENSIONS: Record<string, string> = {
    'core.legal-entity': 'legal_entity',
    'core.operating-unit': 'operating_unit',
};

/** Nama lookup K-30 berupa path relatif di bawah API module; selain bentuk itu tidak dipanggil. */
const LOOKUP_PATTERN = /^[a-z0-9][a-z0-9_-]*(\/[a-z0-9][a-z0-9_-]*)*$/;

function filled(value: FilterValue | undefined): boolean {
    return Array.isArray(value)
        ? value.length > 0
        : (value ?? '').trim() !== '';
}

/** Pilihan satu sumber dimuat sekali selama halaman terbuka, dipakai bersama semua isian yang memintanya. */
const optionSources = new Map<string, Promise<Option[]>>();

function referenceSource(
    field: DatasetField,
    moduleId: string,
): { key: string; load: () => Promise<Option[]> } | null {
    const classification =
        field.shared_dimension === undefined
            ? undefined
            : ORGANIZATION_DIMENSIONS[field.shared_dimension];

    if (classification !== undefined) {
        return {
            key: `organizations:${classification}`,
            load: async () =>
                (
                    await apiJson<{
                        data: Array<{
                            id: string;
                            name: string;
                            classification: string;
                        }>;
                    }>('/api/v1/organizations')
                ).data
                    .filter((item) => item.classification === classification)
                    .map((item) => ({ value: item.id, label: item.name })),
        };
    }

    if (field.lookup === undefined || !LOOKUP_PATTERN.test(field.lookup)) {
        return null;
    }

    const url = `/api/modules/${encodeURIComponent(moduleId)}/v1/${field.lookup}?per_page=100&aktif=true`;

    return {
        key: url,
        load: async () =>
            (
                await apiJson<{
                    data: Array<{
                        id: string;
                        kode?: string;
                        nama?: string;
                        display_label?: string;
                    }>;
                }>(url)
            ).data.map((item) => ({
                value: item.id,
                label:
                    item.display_label ??
                    [item.kode, item.nama].filter(Boolean).join(' — '),
            })),
    };
}

function useReferenceOptions(field: DatasetField, moduleId: string) {
    const key = referenceSource(field, moduleId)?.key ?? null;
    const [loaded, setLoaded] = useState<{
        key: string;
        options: Option[];
        failed: boolean;
    } | null>(null);

    useEffect(() => {
        const source = referenceSource(field, moduleId);

        if (source === null) {
            return;
        }

        let cancelled = false;
        let pending = optionSources.get(source.key);

        if (pending === undefined) {
            pending = source.load();
            optionSources.set(source.key, pending);
            // Kegagalan tidak disimpan: membuka ulang isian mencoba lagi.
            pending.catch(() => optionSources.delete(source.key));
        }

        pending
            .then((options) => {
                if (!cancelled) {
                    setLoaded({ key: source.key, options, failed: false });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoaded({ key: source.key, options: [], failed: true });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [field, moduleId]);

    const current = loaded !== null && loaded.key === key ? loaded : null;

    return {
        options: current?.options ?? [],
        failed: current?.failed ?? false,
    };
}

/**
 * Isian ekspresi. Draf diketik bebas; nilainya dipakai saat Enter (yang tidak mengirim form di sekitarnya) atau
 * saat isian ditinggalkan.
 */
function ExpressionInput({
    label,
    hint,
    value,
    error,
    onCommit,
}: {
    label: string;
    hint?: string;
    value: string;
    error?: string;
    onCommit: (value: string) => void;
}) {
    // Nilai dari luar (tautan, bagian yang diubah) memasang ulang isian ini lewat `key`, jadi draf cukup diisi sekali.
    const [draft, setDraft] = useState(value);

    const commit = () => {
        if (draft.trim() !== value) {
            onCommit(draft.trim());
        }
    };

    return (
        <Field className="w-full" data-invalid={error ? true : undefined}>
            <Input
                label={label}
                value={draft}
                maxLength={250}
                aria-invalid={error ? true : undefined}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        commit();
                    }
                }}
            />
            {error ? (
                <FieldError>{error}</FieldError>
            ) : (
                hint && <FieldDescription>{hint}</FieldDescription>
            )}
        </Field>
    );
}

function ReferenceControl({
    field,
    moduleId,
    value,
    onChange,
    portalContainer,
}: {
    field: DatasetField;
    moduleId: string;
    value: string[];
    onChange: (value: string[]) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const { options, failed } = useReferenceOptions(field, moduleId);

    return (
        <Field className="w-full">
            <MultiSelect
                label={field.caption}
                items={options}
                value={value}
                onValueChange={onChange}
                searchPlaceholder={`Cari ${field.caption.toLowerCase()}`}
                emptyMessage={`${field.caption} tidak ditemukan.`}
                portalContainer={portalContainer}
            />
            {failed && (
                <FieldDescription>
                    {`Pilihan ${field.caption.toLowerCase()} tidak dapat dimuat. Minta administrator memberi Anda akses lihat datanya.`}
                </FieldDescription>
            )}
        </Field>
    );
}

function FieldControl({
    field,
    moduleId,
    value,
    error,
    onChange,
    portalContainer,
}: {
    field: DatasetField;
    moduleId: string;
    value: FilterValue | undefined;
    error?: string;
    onChange: (value: FilterValue) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const list = Array.isArray(value) ? value : value ? [value] : [];

    if (field.type === 'reference' && referenceSource(field, moduleId)) {
        return (
            <ReferenceControl
                field={field}
                moduleId={moduleId}
                value={list}
                onChange={onChange}
                portalContainer={portalContainer}
            />
        );
    }

    if (field.type === 'option' || field.type === 'boolean') {
        return (
            <Field className="w-full">
                <MultiSelect
                    label={field.caption}
                    items={
                        field.type === 'boolean'
                            ? BOOLEAN_ITEMS
                            : (field.options ?? [])
                    }
                    value={list}
                    onValueChange={onChange}
                    searchPlaceholder={`Cari ${field.caption.toLowerCase()}`}
                    emptyMessage="Pilihan tidak ditemukan."
                    portalContainer={portalContainer}
                />
                {error && <FieldError>{error}</FieldError>}
            </Field>
        );
    }

    const text = typeof value === 'string' ? value : '';

    return (
        <ExpressionInput
            key={text}
            label={field.caption}
            hint={HINTS[field.type]}
            value={text}
            error={error}
            onCommit={onChange}
        />
    );
}

export function FilterEditor({
    moduleId,
    fields,
    value,
    errors = {},
    onChange,
    portalContainer,
}: {
    /** Module pemilik data, untuk endpoint lookup rujukannya. */
    moduleId: string;
    fields: DatasetField[];
    value: Record<string, FilterValue>;
    /** Pesan galat per kunci kolom, dari server. */
    errors?: Record<string, string>;
    onChange: (filters: Record<string, FilterValue>) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const [added, setAdded] = useState<string[]>([]);
    const byKey = useMemo(
        () => new Map(fields.map((field) => [field.key, field])),
        [fields],
    );
    // Kolom yang sudah berisi lalu kolom yang ditambahkan, urut kemunculan; kolom yang tidak dikenal data lagi
    // tetap tampil supaya dapat dihapus.
    const shown = [
        ...new Set([
            ...Object.keys(value).filter((key) => filled(value[key])),
            ...added,
        ]),
    ];
    const remaining = fields
        .filter((field) => !shown.includes(field.key))
        .map((field) => ({ value: field.key, label: field.caption }));

    const update = (key: string, next: FilterValue) => {
        // Kolom yang diubah tetap tampil walau isinya dikosongkan, sampai dihapus.
        setAdded((prev) => (prev.includes(key) ? prev : [...prev, key]));
        const copy = { ...value };

        if (filled(next)) {
            copy[key] = next;
        } else {
            delete copy[key];
        }

        onChange(copy);
    };

    const remove = (key: string) => {
        setAdded((prev) => prev.filter((entry) => entry !== key));
        const copy = { ...value };
        delete copy[key];
        onChange(copy);
    };

    return (
        <div className="flex flex-col gap-3">
            {shown.map((key) => {
                const field: DatasetField = byKey.get(key) ?? {
                    key,
                    caption: key,
                    type: 'text',
                    time: false,
                };

                return (
                    <div key={key} className="flex items-start gap-1">
                        <div className="min-w-0 flex-1">
                            <FieldControl
                                field={field}
                                moduleId={moduleId}
                                value={value[key]}
                                error={errors[key]}
                                onChange={(next) => update(key, next)}
                                portalContainer={portalContainer}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="mt-0.5"
                            aria-label={`Hapus saringan ${field.caption}`}
                            title={`Hapus saringan ${field.caption}`}
                            onClick={() => remove(key)}
                        >
                            <X />
                        </Button>
                    </div>
                );
            })}
            {remaining.length > 0 && (
                <div className="flex items-center gap-1">
                    <Plus
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <Select
                        label="Tambah saringan"
                        items={remaining}
                        value={null}
                        onValueChange={(next) => {
                            if (next !== null) {
                                setAdded((prev) => [...prev, next]);
                            }
                        }}
                        searchPlaceholder="Cari kolom"
                        emptyMessage="Kolom tidak ditemukan."
                        portalContainer={portalContainer}
                    />
                </div>
            )}
        </div>
    );
}
