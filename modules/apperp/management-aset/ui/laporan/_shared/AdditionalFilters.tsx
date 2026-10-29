import { Plus, RotateCcw, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import type {
    ReportFilterDefinition,
    ReportFilterProfile,
    ReportFilterSection,
} from './reportFilterProfiles';
import { MasterFilter } from './ReportFilters';
import { reservedReportFields, showReportControl } from './reportFilterState';
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
        <Field className="w-full">
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
            <Field className="w-full">
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
    excludedFields,
    section,
    renderControl,
    catalogError,
}: {
    item: ReportDataItem;
    state: AdditionalFilterState;
    excludedFields: Set<string>;
    section: ReportFilterSection;
    renderControl: (filter: ReportFilterDefinition) => ReactNode;
    catalogError: string;
}) {
    const [added, setAdded] = useState<string[]>([]);
    const byKey = useMemo(
        () => new Map(item.fields.map((field) => [field.key, field])),
        [item.fields],
    );
    // Kolom bawaan laporan, kolom yang sudah terisi (opsi terakhir, preset), lalu kolom yang baru ditambahkan.
    const shown = useMemo(() => {
        const keys = [...(section.defaultFields ?? item.default_fields)];

        for (const field of item.fields) {
            if (filled(state.filters[additionalKey(item.key, field.key)])) {
                keys.push(field.key);
            }
        }

        keys.push(...added);

        return [...new Set(keys)].filter(
            (key) =>
                byKey.has(key) &&
                (!excludedFields.has(key) ||
                    filled(state.filters[additionalKey(item.key, key)])),
        );
    }, [
        item,
        section.defaultFields,
        state.filters,
        added,
        byKey,
        excludedFields,
    ]);
    const remaining = item.fields
        .filter(
            (field) =>
                !shown.includes(field.key) && !excludedFields.has(field.key),
        )
        .map((field) => ({ value: field.key, label: field.caption }));
    const controls = section.filters.filter((filter) =>
        showReportControl(filter, state.filters, section),
    );
    const hasActiveFilter =
        section.filters.some((filter) => filled(state.filters[filter.key])) ||
        item.fields.some((field) =>
            filled(state.filters[additionalKey(item.key, field.key)]),
        );

    const remove = (key: string) => {
        setAdded((prev) => prev.filter((entry) => entry !== key));
        state.update(additionalKey(item.key, key), '');
    };

    return (
        <CollapsibleSection
            value={section.key}
            title={`Filter: ${section.caption}`}
        >
            <div className="flex flex-col gap-3">
                {catalogError && section.dataItem && (
                    <FieldDescription role="alert">
                        {catalogError}
                    </FieldDescription>
                )}
                {hasActiveFilter && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="self-start"
                        onClick={() => {
                            section.filters.forEach((filter) =>
                                state.update(filter.key, ''),
                            );
                            item.fields.forEach((field) =>
                                state.update(
                                    additionalKey(item.key, field.key),
                                    '',
                                ),
                            );
                        }}
                    >
                        <RotateCcw aria-hidden="true" />
                        Bersihkan filter
                    </Button>
                )}
                {shown.map((key) => {
                    const field = byKey.get(key)!;
                    const isDefault = item.default_fields.includes(key);

                    return (
                        <div key={key} className="flex items-start gap-1">
                            <div className="min-w-0 flex-1">
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
                            </div>
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
                {controls.map(renderControl)}
                {remaining.length > 0 && (
                    <div className="flex w-full items-center gap-1">
                        <Plus className="text-muted-foreground size-4 shrink-0" />
                        <Select
                            label="Filter..."
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
        </CollapsibleSection>
    );
}

/**
 * Filter per data item (K-30), mengikuti request page Business Central. Setiap bagian punya kolom
 * bawaan, tindakan untuk membersihkan filter, dan pemilih Filter... untuk kolom lainnya.
 */
export function AdditionalFilters({
    state,
    profile,
    renderControl,
}: {
    state: AdditionalFilterState;
    profile: ReportFilterProfile;
    renderControl: (filter: ReportFilterDefinition) => ReactNode;
}) {
    const [catalog, setCatalog] = useState<{
        reportCode: string;
        items: ReportDataItem[];
        error: string;
    } | null>(null);
    const items = catalog?.reportCode === state.reportCode ? catalog.items : [];
    const catalogError =
        catalog?.reportCode === state.reportCode ? catalog.error : '';

    useEffect(() => {
        let cancelled = false;

        getReportDataItems(state.reportCode)
            .then(
                (next) =>
                    !cancelled &&
                    setCatalog({
                        reportCode: state.reportCode,
                        items: next,
                        error: '',
                    }),
            )
            .catch(
                () =>
                    !cancelled &&
                    setCatalog({
                        reportCode: state.reportCode,
                        items: [],
                        error: 'Daftar filter tambahan tidak dapat dimuat. Coba buka kembali laporan.',
                    }),
            );

        return () => {
            cancelled = true;
        };
    }, [state.reportCode]);

    if (profile.sections.length === 0) {
        return null;
    }

    return (
        <CollapsibleSectionGroup
            defaultValue={profile.sections.map((section) => section.key)}
        >
            {profile.sections.map((section) => (
                <DataItemFilters
                    key={section.key}
                    item={
                        items.find((item) => item.key === section.dataItem) ?? {
                            key: section.dataItem ?? section.key,
                            caption: section.caption,
                            fields: [],
                            default_fields: [],
                        }
                    }
                    state={state}
                    section={section}
                    excludedFields={reservedReportFields(
                        profile,
                        state.filters,
                        section.dataItem ?? '',
                    )}
                    renderControl={renderControl}
                    catalogError={catalogError}
                />
            ))}
        </CollapsibleSectionGroup>
    );
}
