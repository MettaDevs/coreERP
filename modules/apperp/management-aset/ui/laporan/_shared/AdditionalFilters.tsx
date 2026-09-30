import { Plus, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import { MasterFilter } from './ReportFilters';
import { additionalKey, filled, getReportDataItems } from './reportOptions';
import type {
    DataItemField,
    FilterValue,
    ReportDataItem,
} from './reportOptions';
import type { AdditionalFilterState } from './useReportData';

/** Contoh sintaks per tipe kolom, ditampilkan sebagai bantuan di bawah isian. */
const HINTS: Partial<Record<DataItemField['type'], string>> = {
    text: 'Contoh: Asus|Lenovo, *laptop*, @asus*, <>Rusak',
    number: 'Contoh: >1000000, 100..500, <>0',
    date: 'Contoh: 01/09/2026..30/09/2026, >=01/01/2026, t (hari ini)',
    datetime: 'Contoh: 01/09/2026..30/09/2026, >=01/01/2026, t (hari ini)',
};

const BOOLEAN_ITEMS = [
    { value: '1', label: 'Ya' },
    { value: '0', label: 'Tidak' },
];

/**
 * Isian ekspresi filter. Nilainya baru dipakai saat pengguna menekan Enter atau meninggalkan isian,
 * supaya ekspresi yang belum selesai diketik ("100..") tidak memuat laporan dan menampilkan galat.
 */
function ExpressionInput({
    field,
    value,
    onCommit,
}: {
    field: DataItemField;
    value: string;
    onCommit: (value: string) => void;
}) {
    // Nilai dari luar (preset, atur ulang) memasang ulang isian ini lewat `key`, jadi draf cukup diisi sekali.
    const [draft, setDraft] = useState(value);

    const commit = () => {
        if (draft.trim() !== value) {
            onCommit(draft.trim());
        }
    };

    return (
        <Field className="w-full sm:w-56">
            <Input
                label={field.caption}
                value={draft}
                maxLength={250}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        commit();
                    }
                }}
            />
            {HINTS[field.type] && (
                <FieldDescription>{HINTS[field.type]}</FieldDescription>
            )}
        </Field>
    );
}

function FieldControl({
    field,
    value,
    onChange,
}: {
    field: DataItemField;
    value: FilterValue | undefined;
    onChange: (value: FilterValue) => void;
}) {
    const list = Array.isArray(value) ? value : value ? [value] : [];

    if (field.type === 'reference' && field.lookup) {
        return (
            <MasterFilter
                resource={field.lookup}
                label={field.caption}
                unavailable={`Pilihan ${field.caption.toLowerCase()} tidak dapat dimuat. Minta administrator memberi Anda akses lihat datanya.`}
                value={list}
                onChange={onChange}
            />
        );
    }

    if (field.type === 'option' || field.type === 'boolean') {
        const items =
            field.type === 'boolean' ? BOOLEAN_ITEMS : (field.options ?? []);

        return (
            <Field className="w-full sm:w-56">
                <MultiSelect
                    label={field.caption}
                    items={items}
                    value={list}
                    onValueChange={onChange}
                    searchPlaceholder={`Cari ${field.caption.toLowerCase()}`}
                    emptyMessage="Pilihan tidak ditemukan."
                />
            </Field>
        );
    }

    return (
        <ExpressionInput
            key={typeof value === 'string' ? value : ''}
            field={field}
            value={typeof value === 'string' ? value : ''}
            onCommit={onChange}
        />
    );
}

function DataItemFilters({
    item,
    state,
}: {
    item: ReportDataItem;
    state: AdditionalFilterState;
}) {
    const [added, setAdded] = useState<string[]>([]);
    const byKey = useMemo(
        () => new Map(item.fields.map((field) => [field.key, field])),
        [item.fields],
    );
    // Kolom bawaan laporan, kolom yang sudah terisi (opsi terakhir, preset), lalu kolom yang baru ditambahkan.
    const shown = useMemo(() => {
        const keys = [...item.default_fields];

        for (const field of item.fields) {
            if (filled(state.filters[additionalKey(item.key, field.key)])) {
                keys.push(field.key);
            }
        }

        keys.push(...added);

        return [...new Set(keys)].filter((key) => byKey.has(key));
    }, [item, state.filters, added, byKey]);
    const remaining = item.fields
        .filter((field) => !shown.includes(field.key))
        .map((field) => ({ value: field.key, label: field.caption }));

    const remove = (key: string) => {
        setAdded((prev) => prev.filter((entry) => entry !== key));
        state.update(additionalKey(item.key, key), '');
    };

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm font-medium">Filter: {item.caption}</p>
            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start">
                {shown.map((key) => {
                    const field = byKey.get(key)!;
                    const isDefault = item.default_fields.includes(key);

                    return (
                        <div key={key} className="flex items-start gap-1">
                            <FieldControl
                                field={field}
                                value={
                                    state.filters[
                                        additionalKey(item.key, field.key)
                                    ]
                                }
                                onChange={(next) =>
                                    state.update(
                                        additionalKey(item.key, field.key),
                                        next,
                                    )
                                }
                            />
                            {!isDefault && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Hapus filter ${field.caption}`}
                                    title={`Hapus filter ${field.caption}`}
                                    onClick={() => remove(key)}
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                    );
                })}
                {remaining.length > 0 && (
                    <div className="flex w-full items-center gap-1 sm:w-56">
                        <Plus className="text-muted-foreground size-4 shrink-0" />
                        <Select
                            label="Tambah filter"
                            items={remaining}
                            value={null}
                            onValueChange={(next) =>
                                next && setAdded((prev) => [...prev, next])
                            }
                            searchPlaceholder="Cari kolom"
                            emptyMessage="Kolom tidak ditemukan."
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Filter tambahan pengguna pada kolom tabel data item laporan (K-30), padanan "+ Filter" di request page
 * Business Central. Setiap data item — misalnya dokumen dan barisnya — punya bagiannya sendiri. Kolom
 * bawaan dari laporan langsung tampil; kolom lain ditambahkan pengguna. Laporan tanpa data item tidak
 * menampilkan apa pun.
 */
export function AdditionalFilters({ state }: { state: AdditionalFilterState }) {
    const [items, setItems] = useState<ReportDataItem[]>([]);

    useEffect(() => {
        let cancelled = false;

        getReportDataItems(state.reportCode)
            .then((next) => !cancelled && setItems(next))
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [state.reportCode]);

    if (items.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t pt-3">
            {items.map((item) => (
                <DataItemFilters key={item.key} item={item} state={state} />
            ))}
        </div>
    );
}
