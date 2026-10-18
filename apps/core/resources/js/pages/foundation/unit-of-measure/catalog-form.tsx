import { Button } from '@apperp/ui/button';
import { DataTableText } from '@apperp/ui/data-table';
import { Field, FieldError, FieldHint, FieldLabel } from '@apperp/ui/field';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogBody,
    PageDialogDescription,
    PageDialogFooter,
    PageDialogHeader,
    PageDialogTitle,
} from '@apperp/ui/page-dialog';
import { Switch } from '@apperp/ui/switch';
import { Info, Save, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { FormEvent, RefObject } from 'react';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import { catalogLoader, endpoint } from './types';
import type { Catalog, Choice, Conversion, Draft, Unit } from './types';

export function CatalogForm({
    draft,
    onClose,
    onSaved,
}: {
    draft: Draft;
    onClose: () => void;
    onSaved: (record: Unit | Choice | Conversion) => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    const record = draft.kind === 'units' ? draft.record : null;
    const [code, setCode] = useState(record?.code ?? '');
    const [name, setName] = useState(record?.name ?? '');
    const [symbol, setSymbol] = useState(record?.symbol ?? '');
    const [precision, setPrecision] = useState(
        String(record?.decimal_places ?? 2),
    );
    const [active, setActive] = useState(record?.active ?? true);
    const [unitClass, setUnitClass] = useState<Choice | null>(null);
    const [system, setSystem] = useState<Choice | null>(null);
    const [source, setSource] = useState<Unit | null>(null);
    const [target, setTarget] = useState<Unit | null>(null);
    const [factor, setFactor] = useState('');
    const [offset, setOffset] = useState('0');
    const [rounding, setRounding] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const targetFilters = useMemo(
        () => ({
            active: '1',
            ...(source
                ? { uom_class_id: source.uom_class_id, exclude_id: source.id }
                : {}),
        }),
        [source],
    );
    const title =
        draft.kind === 'conversions'
            ? 'Atur konversi'
            : record
              ? 'Ubah satuan'
              : draft.kind === 'units'
                ? 'Tambah satuan'
                : draft.kind === 'classes'
                  ? 'Tambah kelas'
                  : 'Tambah sistem';
    const fieldError = (field: string) =>
        errors[field]?.length ? (
            <FieldError>{errors[field].join(' ')}</FieldError>
        ) : null;
    async function submit(event: FormEvent) {
        event.preventDefault();

        if (saving) {
            return;
        }

        setError('');
        setErrors({});

        if (draft.kind === 'units' && !record && !unitClass) {
            setError('Pilih kelas satuan terlebih dahulu.');

            return;
        }

        if (draft.kind === 'conversions' && (!source || !target)) {
            setError('Pilih satuan asal dan tujuan terlebih dahulu.');

            return;
        }

        const path = record
            ? `${endpoint}/${record.id}`
            : draft.kind === 'units'
              ? endpoint
              : `${endpoint}/${draft.kind}`;
        const body =
            draft.kind === 'conversions'
                ? {
                      from_unit_id: source?.id,
                      to_unit_id: target?.id,
                      factor,
                      offset: offset || null,
                      rounding_scale: rounding === '' ? null : Number(rounding),
                  }
                : draft.kind === 'units'
                  ? record
                      ? {
                            name,
                            symbol: symbol || null,
                            decimal_places: Number(precision),
                            active,
                            version: record.version,
                        }
                      : {
                            code,
                            name,
                            symbol: symbol || null,
                            decimal_places: Number(precision),
                            uom_class_id: unitClass?.id,
                            uom_system_id: system?.id ?? null,
                        }
                  : { code, name };
        setSaving(true);

        try {
            const response = await apiJson<{
                data: Unit | Choice | Conversion;
            }>(path, {
                method: record ? 'PATCH' : 'POST',
                body: JSON.stringify(body),
            });
            onSaved(response.data);
        } catch (caught) {
            setError(
                errorText(caught, 'Perubahan belum dapat disimpan. Coba lagi.'),
            );

            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open && !saving) {
                    onClose();
                }
            }}
        >
            <PageDialogContent
                ref={contentRef}
                onEscapeKeyDown={(event) => {
                    if (
                        contentRef.current?.querySelector(
                            '[role="combobox"][aria-expanded="true"]',
                        )
                    ) {
                        event.preventDefault();
                    }
                }}
                onInteractOutside={(event) => event.preventDefault()}
            >
                <PageDialogHeader>
                    <PageDialogTitle>{title}</PageDialogTitle>
                    <PageDialogDescription>
                        {draft.kind === 'conversions'
                            ? 'Atur perbandingan dua satuan aktif dalam kelas yang sama. Menyimpan pasangan yang sudah ada memperbarui aturannya.'
                            : record
                              ? 'Kode, kelas, dan sistem satuan tetap mengikuti saat dibuat.'
                              : `${draft.kind === 'units' ? 'Satuan' : draft.kind === 'classes' ? 'Kelas' : 'Sistem'} baru langsung aktif dan dapat dipilih.`}
                    </PageDialogDescription>
                </PageDialogHeader>
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col overflow-hidden"
                >
                    <PageDialogBody className="space-y-4" scroll-region="true">
                        {error && (
                            <div
                                role="alert"
                                className="rounded-md border border-destructive p-3 text-sm text-destructive"
                            >
                                {error}
                            </div>
                        )}
                        {draft.kind === 'conversions' ? (
                            <>
                                <Field>
                                    <RecordLookup<Unit>
                                        label="Dari satuan"
                                        resource="units"
                                        filters={{ active: '1' }}
                                        value={source}
                                        onChange={(item) => {
                                            setSource(item);

                                            if (
                                                target &&
                                                (target.uom_class_id !==
                                                    item.uom_class_id ||
                                                    target.id === item.id)
                                            ) {
                                                setTarget(null);
                                            }
                                        }}
                                        contentRef={contentRef}
                                        disabled={saving}
                                        required
                                    />
                                    {fieldError('from_unit_id')}
                                </Field>
                                <Field>
                                    <RecordLookup<Unit>
                                        label="Ke satuan"
                                        resource="units"
                                        filters={targetFilters}
                                        value={target}
                                        onChange={setTarget}
                                        contentRef={contentRef}
                                        disabled={saving || !source}
                                        required
                                    />
                                    {fieldError('to_unit_id')}
                                </Field>
                                <Field>
                                    <Input
                                        label="Faktor konversi"
                                        aria-invalid={Boolean(errors.factor)}
                                        required
                                        type="number"
                                        min="0.000000000001"
                                        step="any"
                                        value={factor}
                                        onChange={(event) =>
                                            setFactor(event.target.value)
                                        }
                                        disabled={saving}
                                    />
                                    {fieldError('factor')}
                                </Field>
                                <Field>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            label="Pergeseran"
                                            aria-invalid={Boolean(
                                                errors.offset,
                                            )}
                                            type="number"
                                            step="any"
                                            value={offset}
                                            onChange={(event) =>
                                                setOffset(event.target.value)
                                            }
                                            disabled={saving}
                                        />
                                        <FieldHint hint="Hasil konversi = nilai asal x faktor + pergeseran.">
                                            <button
                                                type="button"
                                                aria-label="Penjelasan pergeseran"
                                                className="inline-flex"
                                            >
                                                <Info className="size-4" />
                                            </button>
                                        </FieldHint>
                                    </div>
                                    {fieldError('offset')}
                                </Field>
                                <Field>
                                    <Input
                                        label="Pembulatan desimal"
                                        aria-invalid={Boolean(
                                            errors.rounding_scale,
                                        )}
                                        type="number"
                                        min={0}
                                        max={12}
                                        value={rounding}
                                        onChange={(event) =>
                                            setRounding(event.target.value)
                                        }
                                        disabled={saving}
                                    />
                                    {fieldError('rounding_scale')}
                                </Field>
                            </>
                        ) : (
                            <>
                                <Field>
                                    <Input
                                        label="Kode"
                                        aria-invalid={Boolean(errors.code)}
                                        required
                                        value={code}
                                        onChange={(event) =>
                                            setCode(event.target.value)
                                        }
                                        readOnly={Boolean(record)}
                                        disabled={saving}
                                        maxLength={50}
                                    />
                                    {fieldError('code')}
                                </Field>
                                <Field>
                                    <Input
                                        label="Nama"
                                        aria-invalid={Boolean(errors.name)}
                                        required
                                        value={name}
                                        onChange={(event) =>
                                            setName(event.target.value)
                                        }
                                        disabled={saving}
                                        maxLength={150}
                                    />
                                    {fieldError('name')}
                                </Field>
                                {draft.kind === 'units' && (
                                    <>
                                        <Field>
                                            {record ? (
                                                <Input
                                                    label="Kelas"
                                                    value={record.class_name}
                                                    readOnly
                                                />
                                            ) : (
                                                <RecordLookup<Choice>
                                                    label="Kelas"
                                                    resource="classes"
                                                    value={unitClass}
                                                    onChange={setUnitClass}
                                                    contentRef={contentRef}
                                                    disabled={saving}
                                                    required
                                                />
                                            )}
                                            {fieldError('uom_class_id')}
                                        </Field>
                                        <Field>
                                            {record ? (
                                                <Input
                                                    label="Sistem"
                                                    value={
                                                        record.system_name ??
                                                        'Tidak ditentukan'
                                                    }
                                                    readOnly
                                                />
                                            ) : (
                                                <RecordLookup<Choice>
                                                    label="Sistem"
                                                    resource="systems"
                                                    value={system}
                                                    onChange={setSystem}
                                                    contentRef={contentRef}
                                                    disabled={saving}
                                                />
                                            )}
                                            {fieldError('uom_system_id')}
                                        </Field>
                                        <Field>
                                            <Input
                                                label="Simbol"
                                                aria-invalid={Boolean(
                                                    errors.symbol,
                                                )}
                                                value={symbol}
                                                onChange={(event) =>
                                                    setSymbol(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={saving}
                                                maxLength={30}
                                            />
                                            {fieldError('symbol')}
                                        </Field>
                                        <Field>
                                            <Input
                                                label="Jumlah angka desimal"
                                                aria-invalid={Boolean(
                                                    errors.decimal_places,
                                                )}
                                                required
                                                type="number"
                                                min={0}
                                                max={12}
                                                value={precision}
                                                onChange={(event) =>
                                                    setPrecision(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={saving}
                                            />
                                            {fieldError('decimal_places')}
                                        </Field>
                                        {record && (
                                            <Field orientation="horizontal">
                                                <Switch
                                                    id="unit-active"
                                                    checked={active}
                                                    onCheckedChange={setActive}
                                                    disabled={saving}
                                                />
                                                <FieldLabel htmlFor="unit-active">
                                                    Aktif
                                                </FieldLabel>
                                                {fieldError('active')}
                                            </Field>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </PageDialogBody>
                    <PageDialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={onClose}
                        >
                            <X />
                            Batal
                        </Button>
                        <Button type="submit" disabled={saving}>
                            <Save />
                            {saving ? 'Menyimpan...' : 'Simpan'}
                        </Button>
                    </PageDialogFooter>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

function RecordLookup<T extends Choice>({
    label,
    resource,
    filters,
    value,
    onChange,
    contentRef,
    disabled,
    required,
}: {
    label: string;
    resource: Catalog;
    filters?: Record<string, string>;
    value: T | null;
    onChange: (item: T) => void;
    contentRef: RefObject<HTMLDivElement | null>;
    disabled?: boolean;
    required?: boolean;
}) {
    const active = filters?.active ?? '1';
    const classId = filters?.uom_class_id;
    const excludeId = filters?.exclude_id;
    const loadItems = useMemo<LookupLoader<T>>(() => {
        const load = catalogLoader<T>(resource, {
            active,
            ...(classId ? { uom_class_id: classId } : {}),
            ...(excludeId ? { exclude_id: excludeId } : {}),
        });

        return (request) => load({ ...request, sort: null });
    }, [resource, active, classId, excludeId]);

    return (
        <InlineLookup
            label={label}
            required={required}
            value={value}
            loadItems={loadItems}
            onValueChange={onChange}
            getItemValue={(item) => item.id}
            getItemLabel={(item) =>
                resource === 'units' ? `${item.name} (${item.code})` : item.name
            }
            portalContainer={contentRef}
            disabled={disabled}
            columns={[
                {
                    id: 'code',
                    header: 'Kode',
                    cell: (item) => <DataTableText>{item.code}</DataTableText>,
                    sortValue: (item) => item.code,
                    width: 130,
                },
                {
                    id: 'name',
                    header: 'Nama',
                    cell: (item) => <DataTableText>{item.name}</DataTableText>,
                    sortValue: (item) => item.name,
                    width: 240,
                },
            ]}
        />
    );
}
