import { Button } from '@apperp/ui/button';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Plus, SlidersHorizontal, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { FilterEditor } from '@/components/analytics/filter-editor';
import type { SlicerValues } from '@/lib/analytics/slicer';
import type {
    CrossFilter,
    DashboardDetail,
    DashboardSlicer,
    DatasetDescription,
    DatasetField,
    SlicerControl,
} from '@/lib/analytics/types';

type FieldOption = {
    dataset: DatasetDescription;
    field: DatasetField;
};

type Value = string | string[];

const ORGANIZATION_DIMENSIONS = new Set([
    'core.legal-entity',
    'core.operating-unit',
]);

/** Saringan yang disimpan di dasbor dan nilainya di alamat halaman, agar tautan menyimpan pilihan pengguna. */
export function SlicerBar({
    dashboard,
    values,
    canEdit,
    onValuesChange,
    onSave,
}: {
    dashboard: DashboardDetail;
    values: SlicerValues;
    canEdit: boolean;
    onValuesChange: (values: SlicerValues) => void;
    onSave: (slicers: DashboardSlicer[]) => Promise<void>;
}) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<DashboardSlicer | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fields = useMemo(
        () =>
            Object.values(dashboard.dataset_fields).flatMap((dataset) =>
                dataset.fields.map((field) => ({ dataset, field })),
            ),
        [dashboard.dataset_fields],
    );

    const save = async (slicer: DashboardSlicer) => {
        const next =
            editing === null
                ? [...dashboard.slicers, slicer]
                : dashboard.slicers.map((item) =>
                      item.key === editing.key ? slicer : item,
                  );
        setSaving(true);
        setError(null);

        try {
            await onSave(next);
            setOpen(false);
            setEditing(null);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'Saringan belum disimpan.',
            );
        } finally {
            setSaving(false);
        }
    };

    const remove = async (key: string) => {
        setSaving(true);
        setError(null);

        try {
            await onSave(
                dashboard.slicers.filter((slicer) => slicer.key !== key),
            );
            const next = { ...values };
            delete next[key];
            onValuesChange(next);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'Saringan belum dihapus.',
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <section
            className="flex flex-col gap-3 rounded-md border p-3"
            aria-label="Saringan dasbor"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <SlidersHorizontal className="size-4" aria-hidden />
                    Saringan dasbor
                </div>
                {canEdit && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            setEditing(null);
                            setOpen(true);
                        }}
                        disabled={
                            dashboard.slicers.length >= 12 ||
                            fields.length === 0
                        }
                    >
                        <Plus />
                        Tambah saringan
                    </Button>
                )}
            </div>
            {dashboard.slicers.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {canEdit
                        ? 'Tambahkan saringan agar orang lain dapat membuka dasbor dengan pilihan yang sama.'
                        : 'Dasbor ini belum memiliki saringan.'}
                </p>
            ) : (
                <div className="flex flex-wrap items-end gap-3">
                    {dashboard.slicers.map((slicer) => {
                        const option = fieldForSlicer(slicer, fields);

                        if (option === null) {
                            return (
                                <p
                                    key={slicer.key}
                                    className="text-sm text-muted-foreground"
                                >
                                    {`Saringan ${slicer.title} tidak tersedia pada data yang dapat Anda baca.`}
                                </p>
                            );
                        }

                        const value = Object.prototype.hasOwnProperty.call(
                            values,
                            slicer.key,
                        )
                            ? values[slicer.key]
                            : (slicer.default_value ??
                              emptyValue(slicer.control));

                        return (
                            <div
                                key={slicer.key}
                                className="flex min-w-48 items-start gap-1"
                            >
                                <div className="min-w-0 flex-1">
                                    <SlicerValue
                                        slicer={slicer}
                                        option={option}
                                        value={value}
                                        onChange={(next) =>
                                            onValuesChange({
                                                ...values,
                                                [slicer.key]: next,
                                            })
                                        }
                                    />
                                </div>
                                {canEdit && (
                                    <div className="flex shrink-0">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="mt-1 size-8"
                                            aria-label={`Ubah saringan ${slicer.title}`}
                                            title={`Ubah saringan ${slicer.title}`}
                                            onClick={() => {
                                                setEditing(slicer);
                                                setOpen(true);
                                            }}
                                        >
                                            <SlidersHorizontal />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="mt-1 size-8"
                                            aria-label={`Hapus saringan ${slicer.title}`}
                                            title={`Hapus saringan ${slicer.title}`}
                                            disabled={saving}
                                            onClick={() =>
                                                void remove(slicer.key)
                                            }
                                        >
                                            <X />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
            {error && (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            )}
            {open && (
                <SlicerEditor
                    key={editing?.key ?? 'new'}
                    fields={fields}
                    slicer={editing}
                    saving={saving}
                    onClose={() => {
                        setOpen(false);
                        setError(null);
                    }}
                    onSave={save}
                />
            )}
        </section>
    );
}

/** Penanda per widget bahwa saringan tidak cocok tidak menyembunyikan kegagalan memuat data. */
export function SlicerApplicability({ titles }: { titles: string[] }) {
    if (titles.length === 0) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground" role="status">
            {titles
                .map((title) => `Saringan ${title} tidak berlaku di sini`)
                .join(' · ')}
        </p>
    );
}

function SlicerEditor({
    fields,
    slicer,
    saving,
    onClose,
    onSave,
}: {
    fields: FieldOption[];
    slicer: DashboardSlicer | null;
    saving: boolean;
    onClose: () => void;
    onSave: (slicer: DashboardSlicer) => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    const [title, setTitle] = useState(slicer?.title ?? '');
    const [sourceType, setSourceType] = useState<'field' | 'shared'>(
        slicer?.source.type ?? 'field',
    );
    const [sourceValue, setSourceValue] = useState(sourceKey(slicer));
    const [control, setControl] = useState<SlicerControl>(
        slicer?.control ?? 'expression',
    );
    const [defaultValue, setDefaultValue] = useState<Value>(
        slicer?.default_value ?? emptyValue(slicer?.control ?? 'expression'),
    );

    const sharedOptions = useMemo(() => {
        const byCode = new Map<string, string>();

        for (const { field } of fields) {
            if (field.shared_dimension !== undefined) {
                byCode.set(field.shared_dimension, field.caption);
            }
        }

        return [...byCode].map(([value, label]) => ({ value, label }));
    }, [fields]);
    const choices =
        sourceType === 'shared'
            ? sharedOptions
            : fields.map(({ dataset, field }) => ({
                  value: `${dataset.code}|${field.key}`,
                  label: `${dataset.caption} · ${field.caption}`,
              }));
    const sourceOption =
        sourceType === 'field'
            ? (fields.find(
                  ({ dataset, field }) =>
                      `${dataset.code}|${field.key}` === sourceValue,
              ) ?? null)
            : (fields.find(
                  ({ field }) => field.shared_dimension === sourceValue,
              ) ?? null);
    const controls =
        sourceOption === null ? [] : controlsFor(sourceOption.field);
    const availableControls = controls.map((value) => ({
        value,
        label: controlLabel(value),
    }));

    const changeSource = (value: string | null) => {
        if (value === null) {
            return;
        }

        setSourceValue(value);
        const selected =
            sourceType === 'shared'
                ? fields.find(({ field }) => field.shared_dimension === value)
                : fields.find(
                      ({ dataset, field }) =>
                          `${dataset.code}|${field.key}` === value,
                  );
        const nextControls =
            selected === undefined ? [] : controlsFor(selected.field);
        const nextControl = nextControls[0] ?? 'expression';
        setControl(nextControl);
        setDefaultValue(emptyValue(nextControl));
    };

    const setControlValue = (value: SlicerControl) => {
        setControl(value);
        setDefaultValue(emptyValue(value));
    };

    const save = () => {
        if (sourceOption === null || title.trim() === '') {
            return;
        }

        const source =
            sourceType === 'field'
                ? {
                      type: 'field' as const,
                      dataset: sourceOption.dataset.code,
                      field: sourceOption.field.key,
                  }
                : { type: 'shared' as const, dimension: sourceValue };
        const key = slicer?.key ?? makeKey(title);
        onSave({
            key,
            title: title.trim(),
            source,
            control,
            default_value: empty(defaultValue) ? null : defaultValue,
        });
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={contentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {slicer === null ? 'Saringan baru' : 'Ubah saringan'}
                    </SheetTitle>
                    <SheetDescription>
                        Pilih kolom dan cara orang mengisinya. Pilihan aktif
                        tersimpan di tautan dasbor.
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <Field className="w-full">
                        <Input
                            label="Nama saringan"
                            required
                            value={title}
                            maxLength={80}
                            onChange={(event) => setTitle(event.target.value)}
                        />
                    </Field>
                    <Field className="w-full">
                        <Select
                            label="Sumber"
                            required
                            items={[
                                {
                                    value: 'field',
                                    label: 'Kolom dari satu data',
                                },
                                {
                                    value: 'shared',
                                    label: 'Kolom yang dipakai bersama',
                                },
                            ]}
                            value={sourceType}
                            onValueChange={(next) => {
                                if (next === 'field' || next === 'shared') {
                                    setSourceType(next);
                                    setSourceValue('');
                                    setDefaultValue('');
                                }
                            }}
                            portalContainer={contentRef}
                        />
                    </Field>
                    <Field className="w-full">
                        <Select
                            label={
                                sourceType === 'field'
                                    ? 'Data dan kolom'
                                    : 'Kolom bersama'
                            }
                            required
                            items={choices}
                            value={sourceValue || null}
                            onValueChange={changeSource}
                            searchPlaceholder="Cari kolom"
                            emptyMessage="Kolom tidak ditemukan."
                            portalContainer={contentRef}
                        />
                    </Field>
                    {sourceOption !== null && (
                        <>
                            <Field className="w-full">
                                <Select
                                    label="Cara mengisi"
                                    required
                                    items={availableControls}
                                    value={control}
                                    onValueChange={(next) =>
                                        next &&
                                        setControlValue(next as SlicerControl)
                                    }
                                    portalContainer={contentRef}
                                />
                            </Field>
                            <Field className="w-full">
                                <SlicerValue
                                    slicer={{
                                        key: slicer?.key ?? 'saringan',
                                        title: 'Nilai bawaan',
                                        source:
                                            sourceType === 'field'
                                                ? {
                                                      type: 'field',
                                                      dataset:
                                                          sourceOption.dataset
                                                              .code,
                                                      field: sourceOption.field
                                                          .key,
                                                  }
                                                : {
                                                      type: 'shared',
                                                      dimension: sourceValue,
                                                  },
                                        control,
                                        default_value: null,
                                    }}
                                    option={sourceOption}
                                    value={defaultValue}
                                    onChange={setDefaultValue}
                                    portalContainer={contentRef}
                                />
                                <FieldDescription>
                                    Kosongkan bila saringan tidak perlu aktif
                                    saat dasbor dibuka.
                                </FieldDescription>
                            </Field>
                        </>
                    )}
                </div>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" type="button" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        disabled={
                            saving ||
                            title.trim() === '' ||
                            sourceOption === null
                        }
                        onClick={save}
                    >
                        {slicer === null ? 'Tambah saringan' : 'Simpan'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function SlicerValue({
    slicer,
    option,
    value,
    onChange,
    portalContainer,
}: {
    slicer: DashboardSlicer;
    option: FieldOption;
    value: Value;
    onChange: (value: Value) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    if (slicer.control === 'date_range') {
        const [from, to] =
            typeof value === 'string' ? value.split('..') : ['', ''];
        const update = (start: string, end: string) =>
            onChange(start === '' && end === '' ? '' : `${start}..${end}`);

        return (
            <div className="grid w-full grid-cols-2 gap-2">
                <Input
                    label={slicer.title}
                    type="date"
                    value={from ?? ''}
                    onChange={(event) => update(event.target.value, to ?? '')}
                />
                <Input
                    label="Sampai"
                    type="date"
                    value={to ?? ''}
                    onChange={(event) => update(from ?? '', event.target.value)}
                />
            </div>
        );
    }

    if (slicer.control === 'multi_select') {
        const list = Array.isArray(value) ? value : value ? [value] : [];
        const fields = [option.field];
        const moduleId = option.dataset.module_id;

        return (
            <FilterEditor
                moduleId={moduleId}
                fields={fields}
                value={{ [option.field.key]: list }}
                onChange={(filters) =>
                    onChange(filters[option.field.key] ?? [])
                }
                alwaysShowFields={[option.field.key]}
                portalContainer={portalContainer}
            />
        );
    }

    return (
        <Input
            label={slicer.title}
            value={typeof value === 'string' ? value : ''}
            maxLength={250}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

export function CrossFilterChips({
    filters,
    onRemove,
    onClear,
}: {
    filters: CrossFilter[];
    onRemove: (id: string) => void;
    onClear: () => void;
}) {
    if (filters.length === 0) {
        return null;
    }

    return (
        <div
            className="flex flex-wrap items-center gap-2"
            role="group"
            aria-label="Saringan sementara"
        >
            <span className="text-sm text-muted-foreground">
                Pilihan sementara:
            </span>
            {filters.map((filter) => (
                <span
                    key={filter.id}
                    className="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-xs"
                >
                    {`${filter.title}: ${filter.label}`}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-5"
                        aria-label={`Hapus pilihan ${filter.title}: ${filter.label}`}
                        onClick={() => onRemove(filter.id)}
                    >
                        <X />
                    </Button>
                </span>
            ))}
            <Button type="button" size="sm" variant="ghost" onClick={onClear}>
                Hapus semua pilihan
            </Button>
        </div>
    );
}

function fieldForSlicer(
    slicer: DashboardSlicer,
    fields: FieldOption[],
): FieldOption | null {
    const source = slicer.source;

    if (source.type === 'field') {
        return (
            fields.find(
                ({ dataset, field }) =>
                    dataset.code === source.dataset &&
                    field.key === source.field,
            ) ?? null
        );
    }

    return (
        fields.find(
            ({ field }) => field.shared_dimension === source.dimension,
        ) ?? null
    );
}

function sourceKey(slicer: DashboardSlicer | null): string {
    if (slicer === null) {
        return '';
    }

    return slicer.source.type === 'field'
        ? `${slicer.source.dataset}|${slicer.source.field}`
        : slicer.source.dimension;
}

function controlsFor(field: DatasetField): SlicerControl[] {
    const controls: SlicerControl[] = [];

    if (
        field.type === 'option' ||
        field.type === 'boolean' ||
        (field.type === 'reference' &&
            (field.lookup !== undefined ||
                ORGANIZATION_DIMENSIONS.has(field.shared_dimension ?? '')))
    ) {
        controls.push('multi_select');
    }

    if (field.time && (field.type === 'date' || field.type === 'datetime')) {
        controls.push('date_range');
    }

    if (['text', 'number', 'date', 'datetime'].includes(field.type)) {
        controls.push('expression');
    }

    return controls;
}

function controlLabel(control: SlicerControl): string {
    return control === 'multi_select'
        ? 'Pilih beberapa nilai'
        : control === 'date_range'
          ? 'Rentang tanggal'
          : 'Saringan bebas';
}

function emptyValue(control: SlicerControl): Value {
    return control === 'multi_select' ? [] : '';
}

function empty(value: Value): boolean {
    return Array.isArray(value) ? value.length === 0 : value.trim() === '';
}

function makeKey(title: string): string {
    const key = title
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return /^[a-z]/.test(key)
        ? key.slice(0, 64)
        : `saringan_${key || 'baru'}`.slice(0, 64);
}
