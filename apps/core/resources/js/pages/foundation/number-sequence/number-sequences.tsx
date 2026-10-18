import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldHint,
    FieldLabel,
} from '@apperp/ui/field';
import { IncrementalListStatus } from '@apperp/ui/incremental-list-status';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import { NativeSelect } from '@apperp/ui/native-select';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import {
    RecordEditBoundary,
    RecordEditControl,
} from '@apperp/ui/record-edit-boundary';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';
import { Head, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CircleHelp } from 'lucide-react';
import { useId, useState } from 'react';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type Segment = {
    type:
        | 'constant'
        | 'number'
        | 'year'
        | 'scope'
        | 'fiscal_year'
        | 'fiscal_period'
        | 'context';
    name?: string;
    value?: string;
    length?: number;
};
type Sequence = {
    id: string;
    reference_code: string;
    reference_name: string;
    app_name: string;
    allowed_scopes: string[];
    profile_code: string;
    scope_type: string;
    status: string;
    is_continuous: boolean;
    allow_manual: boolean;
    allow_automatic: boolean;
    reset_period: string;
    preallocation_enabled: boolean;
    preallocation_quantity: number;
    minimum_number: number;
    maximum_number: number | null;
    segments: Segment[];
    context_fields: Segment[];
    version: number;
};
type Props = {
    canManage: boolean;
    tenant: { id: string; name: string };
    profiles: { code: string; name: string }[];
};

function numberPreview(segments: Segment[], number: number): string {
    return segments
        .map((segment) =>
            segment.type === 'constant'
                ? segment.value
                : segment.type === 'context'
                  ? `[${segment.name ?? 'Bagian kode'}]`
                  : segment.type === 'number'
                    ? String(number).padStart(segment.length ?? 5, '0')
                    : segment.type === 'year'
                      ? String(new Date().getFullYear())
                      : '[Organisasi atau periode]',
        )
        .join('');
}

function SegmentEditor({
    value,
    onChange,
    contextFields,
    disabled,
}: {
    value: Segment[];
    onChange: (segments: Segment[]) => void;
    contextFields: Segment[];
    disabled: boolean;
}) {
    const update = (index: number, patch: Partial<Segment>) =>
        onChange(
            value.map((segment, current) =>
                current === index ? { ...segment, ...patch } : segment,
            ),
        );
    const move = (index: number, offset: number) => {
        const next = [...value];
        const [segment] = next.splice(index, 1);
        next.splice(index + offset, 0, segment);
        onChange(next);
    };

    return (
        <div className="space-y-4">
            {value.map((segment, index) => (
                <fieldset
                    key={index}
                    className="min-w-0 space-y-4 rounded-lg border p-4"
                    disabled={disabled}
                >
                    <legend className="px-1 text-sm font-medium">
                        Bagian {index + 1}
                    </legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <NativeSelect
                            label="Jenis bagian"
                            value={segment.type}
                            onChange={(event) => {
                                const type = event.target.value;

                                if (
                                    type !== 'constant' &&
                                    type !== 'number' &&
                                    type !== 'context' &&
                                    type !== 'year' &&
                                    type !== 'scope' &&
                                    type !== 'fiscal_year' &&
                                    type !== 'fiscal_period'
                                ) {
                                    return;
                                }

                                update(index, {
                                    type,
                                    value: '',
                                    name: undefined,
                                    length:
                                        type === 'context'
                                            ? 20
                                            : type === 'number'
                                              ? 5
                                              : type === 'fiscal_period'
                                                ? 2
                                                : undefined,
                                });
                            }}
                        >
                            <option value="constant">Teks tetap</option>
                            <option value="number">Nomor urut</option>
                            {contextFields.length > 0 && (
                                <option value="context">
                                    Data dari formulir
                                </option>
                            )}
                            <option value="year">Tahun kalender</option>
                            <option value="scope">Kode organisasi</option>
                            <option value="fiscal_year">Tahun fiskal</option>
                            <option value="fiscal_period">
                                Periode fiskal
                            </option>
                        </NativeSelect>
                        {segment.type === 'constant' && (
                            <Input
                                label="Teks tetap"
                                value={segment.value ?? ''}
                                onChange={(event) =>
                                    update(index, { value: event.target.value })
                                }
                            />
                        )}
                        {segment.type === 'context' && (
                            <NativeSelect
                                label="Bagian kode"
                                value={segment.value ?? ''}
                                onChange={(event) => {
                                    const field = contextFields.find(
                                        (item) =>
                                            item.value === event.target.value,
                                    );
                                    update(index, {
                                        value: field?.value ?? '',
                                        name: field?.name,
                                        length: field?.length ?? 20,
                                    });
                                }}
                            >
                                <option value="">Pilih bagian kode</option>
                                {contextFields.map((field) => (
                                    <option
                                        key={field.value}
                                        value={field.value}
                                    >
                                        {field.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        {(segment.type === 'number' ||
                            segment.type === 'context' ||
                            segment.type === 'fiscal_period') && (
                            <Input
                                label={
                                    segment.type === 'context'
                                        ? 'Panjang maksimal kode'
                                        : 'Jumlah digit'
                                }
                                type="number"
                                min={1}
                                max={
                                    segment.type === 'context'
                                        ? 50
                                        : segment.type === 'fiscal_period'
                                          ? 4
                                          : 18
                                }
                                value={
                                    segment.length ??
                                    (segment.type === 'fiscal_period' ? 2 : 5)
                                }
                                onChange={(event) =>
                                    update(index, {
                                        length: Number(event.target.value),
                                    })
                                }
                            />
                        )}
                        {(segment.type === 'year' ||
                            segment.type === 'scope' ||
                            segment.type === 'fiscal_year') && (
                            <Input
                                label="Nilai otomatis"
                                readOnly
                                editActivation={false}
                                value={
                                    segment.type === 'year'
                                        ? String(new Date().getFullYear())
                                        : segment.type === 'scope'
                                          ? 'Kode organisasi saat dipakai'
                                          : 'Tahun fiskal saat dipakai'
                                }
                            />
                        )}
                    </div>
                    {!disabled && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={index === 0}
                                onClick={() => move(index, -1)}
                                aria-label={`Pindahkan bagian ${index + 1} ke atas`}
                            >
                                <ArrowUp /> Naik
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={index === value.length - 1}
                                onClick={() => move(index, 1)}
                                aria-label={`Pindahkan bagian ${index + 1} ke bawah`}
                            >
                                <ArrowDown /> Turun
                            </Button>
                            <ActionButton
                                action="delete"
                                type="button"
                                onClick={() =>
                                    onChange(
                                        value.filter(
                                            (_, current) => current !== index,
                                        ),
                                    )
                                }
                            />
                        </div>
                    )}
                </fieldset>
            ))}
            {!disabled && (
                <ActionButton
                    action="create"
                    type="button"
                    onClick={() =>
                        onChange([...value, { type: 'constant', value: '' }])
                    }
                />
            )}
        </div>
    );
}

function SequenceCheckbox({
    label,
    checked,
    disabled,
    onCheckedChange,
    hint,
}: {
    label: string;
    checked: boolean;
    disabled: boolean;
    onCheckedChange: (checked: boolean) => void;
    hint?: string;
}) {
    const id = useId();

    return (
        <RecordEditControl label={label}>
            <Field orientation="horizontal">
                <Checkbox
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={(value) => onCheckedChange(value === true)}
                />
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                {hint && (
                    <FieldHint hint={hint}>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Bantuan ${label}`}
                        >
                            <CircleHelp />
                        </Button>
                    </FieldHint>
                )}
            </Field>
        </RecordEditControl>
    );
}

function SequenceForm({
    sequence,
    profiles,
    canManage,
    onSaved,
}: {
    sequence: Sequence;
    profiles: Props['profiles'];
    canManage: boolean;
    onSaved: (sequence: Sequence) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const form = useForm({
        profile_code: sequence.profile_code,
        scope_type: sequence.scope_type,
        status: sequence.status,
        is_continuous: sequence.is_continuous,
        allow_manual: sequence.allow_manual,
        allow_automatic: sequence.allow_automatic,
        reset_period: sequence.reset_period,
        preallocation_enabled: sequence.preallocation_enabled,
        preallocation_quantity: sequence.preallocation_quantity,
        minimum_number: sequence.minimum_number,
        maximum_number: sequence.maximum_number?.toString() ?? '',
        segments: sequence.segments,
    });
    const unavailable = !canManage || saving;
    async function save() {
        if (!editing || !canManage || saving) {
            return;
        }

        setSaving(true);
        setSaveError('');
        setErrors({});

        try {
            const result = await apiJson<{ data: Sequence }>(
                `/api/v1/number-sequences/${sequence.id}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...form.data,
                        maximum_number:
                            form.data.maximum_number === ''
                                ? null
                                : Number(form.data.maximum_number),
                        version: sequence.version,
                    }),
                },
            );
            onSaved(result.data);
        } catch (caught) {
            setSaveError(
                errorText(
                    caught,
                    'Pengaturan nomor belum dapat disimpan. Coba lagi.',
                ),
            );

            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <RecordEditBoundary
            editing={editing}
            canEdit={canManage}
            disabled={saving}
            onEdit={() => setEditing(true)}
            className="flex h-full min-h-0 min-w-0 flex-col"
        >
            <form
                className="flex min-h-0 flex-1 flex-col"
                onSubmit={(event) => {
                    event.preventDefault();
                    void save();
                }}
            >
                <RecordActionBar
                    title={sequence.reference_name}
                    trailing={
                        <Badge variant="outline">{sequence.app_name}</Badge>
                    }
                >
                    {canManage && !editing && (
                        <ActionButton
                            action="edit"
                            onClick={() => setEditing(true)}
                            type="button"
                        />
                    )}
                    {editing && (
                        <>
                            <ActionButton
                                action="save"
                                type="submit"
                                disabled={!form.isDirty || saving}
                            />
                            <ActionButton
                                action="cancel"
                                type="button"
                                disabled={saving}
                                onClick={() => {
                                    form.reset();
                                    setErrors({});
                                    setSaveError('');
                                    setEditing(false);
                                }}
                            />
                        </>
                    )}
                </RecordActionBar>
                <div
                    className="min-h-0 flex-1 overflow-auto p-4"
                    scroll-region=""
                >
                    <CollapsibleSectionGroup
                        defaultValue={['rules', 'range', 'format']}
                    >
                        <CollapsibleSection
                            value="rules"
                            title="Aturan nomor"
                            summary="Mode, perusahaan, status, dan pengulangan nomor"
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <RecordEditControl label="Mode dasar">
                                    <Field>
                                        <NativeSelect
                                            label="Mode dasar"
                                            value={form.data.profile_code}
                                            disabled={unavailable}
                                            onChange={(event) =>
                                                form.setData(
                                                    'profile_code',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {profiles.map((profile) => (
                                                <option
                                                    key={profile.code}
                                                    value={profile.code}
                                                >
                                                    {profile.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <FieldError>
                                            {errors.profile_code?.[0]}
                                        </FieldError>
                                    </Field>
                                </RecordEditControl>
                                <RecordEditControl label="Berlaku untuk">
                                    <Field>
                                        <NativeSelect
                                            label="Berlaku untuk"
                                            value={form.data.scope_type}
                                            disabled={unavailable}
                                            onChange={(event) =>
                                                form.setData(
                                                    'scope_type',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {sequence.allowed_scopes.map(
                                                (scope) => (
                                                    <option
                                                        key={scope}
                                                        value={scope}
                                                    >
                                                        {scope === 'tenant'
                                                            ? 'Seluruh bisnis'
                                                            : scope ===
                                                                'legal_entity'
                                                              ? 'Perusahaan'
                                                              : 'Unit operasi'}
                                                    </option>
                                                ),
                                            )}
                                        </NativeSelect>
                                        <FieldError>
                                            {errors.scope_type?.[0]}
                                        </FieldError>
                                    </Field>
                                </RecordEditControl>
                                <RecordEditControl label="Status">
                                    <Field>
                                        <NativeSelect
                                            label="Status"
                                            value={form.data.status}
                                            disabled={unavailable}
                                            onChange={(event) =>
                                                form.setData(
                                                    'status',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="draft">
                                                Belum aktif
                                            </option>
                                            <option value="active">
                                                Aktif
                                            </option>
                                            <option value="stopped">
                                                Dihentikan
                                            </option>
                                        </NativeSelect>
                                        <FieldError>
                                            {errors.status?.[0]}
                                        </FieldError>
                                    </Field>
                                </RecordEditControl>
                                <RecordEditControl label="Mulai ulang nomor">
                                    <Field>
                                        <NativeSelect
                                            label="Mulai ulang nomor"
                                            value={form.data.reset_period}
                                            disabled={unavailable}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reset_period',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="never">
                                                Tidak pernah
                                            </option>
                                            <option value="calendar_year">
                                                Setiap tahun
                                            </option>
                                            <option value="fiscal_year">
                                                Setiap tahun fiskal
                                            </option>
                                            <option value="fiscal_period">
                                                Setiap periode fiskal
                                            </option>
                                        </NativeSelect>
                                        <FieldError>
                                            {errors.reset_period?.[0]}
                                        </FieldError>
                                    </Field>
                                </RecordEditControl>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="range"
                            title="Rentang dan pengambilan nomor"
                            summary="Batas nomor dan pilihan manual atau otomatis"
                        >
                            <div className="space-y-6">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field>
                                        <Input
                                            label="Nomor awal"
                                            type="number"
                                            min={0}
                                            required
                                            readOnly={!editing || !canManage}
                                            disabled={saving}
                                            value={form.data.minimum_number}
                                            onChange={(event) =>
                                                form.setData(
                                                    'minimum_number',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                        <FieldError>
                                            {errors.minimum_number?.[0]}
                                        </FieldError>
                                    </Field>
                                    <Field>
                                        <Input
                                            label="Batas akhir"
                                            type="number"
                                            min={0}
                                            readOnly={!editing || !canManage}
                                            disabled={saving}
                                            value={form.data.maximum_number}
                                            onChange={(event) =>
                                                form.setData(
                                                    'maximum_number',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <FieldError>
                                            {errors.maximum_number?.[0]}
                                        </FieldError>
                                    </Field>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <SequenceCheckbox
                                        label="Nomor berkelanjutan"
                                        checked={form.data.is_continuous}
                                        disabled={unavailable}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'is_continuous',
                                                checked,
                                            )
                                        }
                                        hint="Nomor dilacak sampai dikonfirmasi atau dibatalkan. Pilihan ini tidak dapat digabungkan dengan nomor manual."
                                    />
                                    <SequenceCheckbox
                                        label="Izinkan nomor manual"
                                        checked={form.data.allow_manual}
                                        disabled={
                                            unavailable ||
                                            form.data.is_continuous
                                        }
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'allow_manual',
                                                checked,
                                            )
                                        }
                                    />
                                    <SequenceCheckbox
                                        label="Buat nomor otomatis"
                                        checked={form.data.allow_automatic}
                                        disabled={unavailable}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'allow_automatic',
                                                checked,
                                            )
                                        }
                                    />
                                    <SequenceCheckbox
                                        label="Siapkan nomor lebih awal"
                                        checked={
                                            form.data.preallocation_enabled
                                        }
                                        disabled={unavailable}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'preallocation_enabled',
                                                checked,
                                            )
                                        }
                                        hint="Menyiapkan beberapa nomor sekaligus untuk mengurangi waktu pengambilan nomor berikutnya."
                                    />
                                </div>
                                <div className="max-w-sm">
                                    <Field>
                                        <Input
                                            label="Jumlah nomor disiapkan"
                                            type="number"
                                            min={1}
                                            max={1000}
                                            required
                                            disabled={
                                                unavailable ||
                                                !form.data.preallocation_enabled
                                            }
                                            readOnly={!editing || !canManage}
                                            value={
                                                form.data.preallocation_quantity
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'preallocation_quantity',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                        <FieldError>
                                            {errors.preallocation_quantity?.[0]}
                                        </FieldError>
                                    </Field>
                                </div>
                                {form.data.allow_manual && (
                                    <FieldDescription>
                                        Kode manual boleh berbeda dari format
                                        otomatis, tetapi harus unik.
                                    </FieldDescription>
                                )}
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="format"
                            title="Format nomor"
                            summary="Urutan teks, angka, dan bagian kode"
                        >
                            <div className="space-y-4">
                                <RecordEditControl label="Susunan nomor">
                                    <SegmentEditor
                                        value={form.data.segments}
                                        contextFields={sequence.context_fields}
                                        disabled={unavailable || !editing}
                                        onChange={(segments) =>
                                            form.setData('segments', segments)
                                        }
                                    />
                                </RecordEditControl>
                                <FieldDescription>
                                    Contoh:{' '}
                                    {numberPreview(
                                        form.data.segments,
                                        form.data.minimum_number,
                                    )}
                                    {form.data.maximum_number !== '' && (
                                        <>
                                            {' '}
                                            sampai{' '}
                                            {numberPreview(
                                                form.data.segments,
                                                Number(
                                                    form.data.maximum_number,
                                                ),
                                            )}
                                        </>
                                    )}
                                </FieldDescription>
                            </div>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                    <FieldError className="mt-4">{saveError}</FieldError>
                </div>
            </form>
        </RecordEditBoundary>
    );
}

const loadSequences: ListLoader<Sequence> = async ({
    query,
    cursor,
    sort,
    signal,
}) => {
    const params = new URLSearchParams({ incremental: '1', q: query });

    if (cursor) {
        params.set('cursor', cursor);
    }

    if (sort) {
        params.set('sort', sort.columnId);
        params.set('direction', sort.direction);
    }

    const result = await apiJson<{
        data: Sequence[];
        next_cursor: string | null;
    }>(`/settings/number-sequences?${params}`, { signal });

    return { items: result.data, nextCursor: result.next_cursor };
};

export default function NumberSequences(props: Props) {
    return <NumberSequencesPage key={props.tenant.id} {...props} />;
}

function NumberSequencesPage({ canManage, profiles }: Props) {
    const [sequence, setSequence] = useState<Sequence | null>(null);
    const [showDetail, setShowDetail] = useState(false);
    const [search, setSearch] = useState('');
    const list = useIncrementalList({
        loadItems: loadSequences,
        query: search,
        getKey: (item) => item.id,
    });

    return (
        <>
            <Head title="Nomor dokumen" />
            <ListPageLayout header={<RecordActionBar title="Nomor dokumen" />}>
                <MasterDetailLayout
                    detailOpen={showDetail}
                    onDetailOpenChange={setShowDetail}
                    list={
                        <div className="flex h-full min-h-0 flex-col">
                            <div className="shrink-0 p-4">
                                <Input
                                    type="search"
                                    label="Cari jenis nomor"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </div>
                            <div
                                className="min-h-0 flex-1 overflow-y-auto"
                                scroll-region=""
                            >
                                {list.items.map((item) => (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        variant={
                                            item.id === sequence?.id
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        className="h-auto w-full justify-start rounded-none px-4 py-3 text-left"
                                        aria-pressed={item.id === sequence?.id}
                                        onClick={() => {
                                            setSequence(item);
                                            setShowDetail(true);
                                        }}
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate">
                                                {item.reference_name}
                                            </span>
                                            <span className="block truncate text-xs font-normal text-muted-foreground">
                                                {item.app_name}
                                            </span>
                                        </span>
                                    </Button>
                                ))}
                                {!list.loading &&
                                    !list.error &&
                                    list.items.length === 0 && (
                                        <Empty>
                                            <EmptyHeader>
                                                <EmptyTitle>
                                                    {search
                                                        ? 'Tidak ada nomor yang cocok'
                                                        : 'Belum ada jenis nomor'}
                                                </EmptyTitle>
                                                <EmptyDescription>
                                                    {search
                                                        ? 'Coba nama atau aplikasi lain.'
                                                        : 'Jenis nomor muncul setelah aplikasi yang memakainya siap digunakan.'}
                                                </EmptyDescription>
                                            </EmptyHeader>
                                        </Empty>
                                    )}
                                <IncrementalListStatus state={list} />
                            </div>
                        </div>
                    }
                >
                    {sequence ? (
                        <SequenceForm
                            key={`${sequence.id}-${sequence.version}`}
                            sequence={sequence}
                            profiles={profiles}
                            canManage={canManage}
                            onSaved={(saved) => {
                                setSequence(saved);
                                list.setItems((current) =>
                                    current.map((item) =>
                                        item.id === saved.id ? saved : item,
                                    ),
                                );
                                list.reload();
                            }}
                        />
                    ) : (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Pilih jenis nomor</EmptyTitle>
                                <EmptyDescription>
                                    Pilih dari daftar untuk melihat format dan
                                    aturan nomornya.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    )}
                </MasterDetailLayout>
            </ListPageLayout>
        </>
    );
}

NumberSequences.layout = {
    breadcrumbs: [
        { title: 'Nomor dokumen', href: '/settings/number-sequences' },
    ] satisfies BreadcrumbItem[],
};
