import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
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
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { Head, router } from '@inertiajs/react';
import { MoreHorizontal, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import {
    apiJson,
    CoreApiError,
    errorText,
    toastSaveError,
} from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

/*
 * Layar Publikasi data (engine analitik area 15): data analisis yang dibuka untuk dibaca sistem lain lewat
 * klien integrasi. Publikasi dihitung dengan hak pemiliknya, jadi hanya pemilik yang mengubah isinya dan
 * melihat contoh datanya; pemegang hak publikasi lain dapat menghentikan, melanjutkan, mencabut, atau
 * mengambil alih. Semua pilihan di dalam Sheet memakai NativeSelect, jadi tidak ada menu yang perlu
 * `portalContainer`.
 */

type FilterValue = string | string[];
type FieldType =
    | 'text'
    | 'number'
    | 'date'
    | 'datetime'
    | 'option'
    | 'boolean'
    | 'reference';
type Choice = { value: string; label: string };
type DatasetField = {
    key: string;
    caption: string;
    type: FieldType;
    options?: Choice[];
};
type DatasetInfo = {
    code: string;
    caption: string;
    fields: DatasetField[];
    can_hide_small_groups: boolean;
};
type Health = {
    state: 'ok' | 'suspended' | 'unavailable' | 'revoked';
    message: string | null;
};
type Status = 'active' | 'paused' | 'revoked';
type Publication = {
    id: string;
    version: number;
    code: string;
    name: string;
    description: string | null;
    status: Status;
    owner: { id: number; name: string | null };
    is_owner: boolean;
    saved_query: {
        id: string;
        name: string;
        archived: boolean;
        changed: boolean;
    } | null;
    dataset: { code: string; caption: string } | null;
    locked_filters: Record<string, FilterValue>;
    clients: { id: string; name: string | null; active: boolean }[];
    formats: string[];
    min_group_size: number | null;
    timezone: string;
    health: Health;
    last_used_at: string | null;
    updated_at: string | null;
    can_manage: boolean;
    can_edit: boolean;
};
type SavedQueryOption = {
    id: string;
    name: string;
    code: string;
    dataset_code: string;
};
type ClientOption = { id: string; name: string; can_read: boolean };
type Props = {
    publications: Publication[];
    savedQueries: SavedQueryOption[];
    datasets: Record<string, DatasetInfo>;
    clients: ClientOption[];
    canManage: boolean;
    endpoint: string;
    guideUrl: string;
};
type LockedRow = { key: string; value: FilterValue };
type Form = {
    name: string;
    code: string;
    description: string;
    saved_query_id: string;
    apply_latest: boolean;
    locked: LockedRow[];
    client_ids: string[];
    formats: string[];
    min_group_size: string;
};
type Preview = {
    columns: { key: string; caption: string; label_key?: string }[];
    rows: Record<string, string | number | boolean | null>[];
    meta: { generated_at: string; small_groups_hidden: number };
};

const STATUS_LABEL: Record<Status, string> = {
    active: 'Aktif',
    paused: 'Dihentikan sementara',
    revoked: 'Dicabut',
};

const FORMAT_LABEL: Record<string, string> = { json: 'JSON', csv: 'CSV' };

const CHOICE_TYPES: FieldType[] = ['option', 'boolean', 'reference'];

const isChoice = (field: DatasetField | undefined) =>
    field !== undefined && CHOICE_TYPES.includes(field.type);

function toForm(publication: Publication | null): Form {
    return {
        name: publication?.name ?? '',
        code: publication?.code ?? '',
        description: publication?.description ?? '',
        saved_query_id: publication?.saved_query?.id ?? '',
        apply_latest: false,
        locked: Object.entries(publication?.locked_filters ?? {}).map(
            ([key, value]) => ({ key, value }),
        ),
        client_ids: (publication?.clients ?? [])
            .filter((client) => client.active)
            .map((client) => client.id),
        formats: publication?.formats ?? ['json'],
        min_group_size:
            publication?.min_group_size === null ||
            publication?.min_group_size === undefined
                ? ''
                : String(publication.min_group_size),
    };
}

/** Pilihan nilai satu kolom saringan terkunci, dibaca dari data yang boleh dibaca penyusunnya. */
function useChoices(dataset: string, field: DatasetField | undefined) {
    const [choices, setChoices] = useState<Choice[] | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const local = field?.type === 'option' ? (field.options ?? null) : null;

    useEffect(() => {
        if (!field || field.type !== 'reference') {
            return;
        }

        let alive = true;
        const query = new URLSearchParams({ dataset, field: field.key });
        apiJson<{ data: Choice[] }>(
            `/api/v1/analytics/publications/field-values?${query.toString()}`,
        )
            .then((result) => {
                if (alive) {
                    setChoices(result.data);
                    setFailure(null);
                }
            })
            .catch((caught: unknown) => {
                if (alive) {
                    setFailure(
                        errorText(caught, 'Pilihan nilai belum dapat dimuat.'),
                    );
                }
            });

        return () => {
            alive = false;
        };
    }, [dataset, field]);

    if (field?.type === 'boolean') {
        return {
            choices: [
                { value: '1', label: 'Ya' },
                { value: '0', label: 'Tidak' },
            ],
            failure: null,
        };
    }

    return { choices: local ?? choices, failure };
}

function LockedFilterRow({
    dataset,
    fields,
    row,
    taken,
    error,
    onChange,
    onRemove,
}: {
    dataset: string;
    fields: DatasetField[];
    row: LockedRow;
    taken: string[];
    error: string | undefined;
    onChange: (row: LockedRow) => void;
    onRemove: () => void;
}) {
    const field = fields.find((item) => item.key === row.key);
    const { choices, failure } = useChoices(dataset, field);
    const selected = Array.isArray(row.value) ? row.value : [];

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <NativeSelect
                        label="Kolom"
                        required
                        value={row.key}
                        onChange={(event) => {
                            const next = fields.find(
                                (item) => item.key === event.target.value,
                            );
                            onChange({
                                key: event.target.value,
                                value: isChoice(next) ? [] : '',
                            });
                        }}
                    >
                        <NativeSelectOption value="">
                            Pilih kolom
                        </NativeSelectOption>
                        {fields
                            .filter(
                                (item) =>
                                    item.key === row.key ||
                                    !taken.includes(item.key),
                            )
                            .map((item) => (
                                <NativeSelectOption
                                    key={item.key}
                                    value={item.key}
                                >
                                    {item.caption}
                                </NativeSelectOption>
                            ))}
                    </NativeSelect>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="mt-1"
                    aria-label={`Hapus saringan ${field?.caption ?? ''}`}
                    onClick={onRemove}
                >
                    <X />
                </Button>
            </div>
            {field && isChoice(field) && (
                <Field data-invalid={Boolean(error)}>
                    <FieldLabel>Nilai yang dibuka</FieldLabel>
                    {failure ? (
                        <FieldDescription className="text-destructive">
                            {failure}
                        </FieldDescription>
                    ) : choices === null ? (
                        <FieldDescription>Memuat pilihanâ€¦</FieldDescription>
                    ) : choices.length === 0 ? (
                        <FieldDescription>
                            Belum ada nilai pada data yang boleh Anda baca.
                        </FieldDescription>
                    ) : (
                        <div className="flex max-h-48 flex-col gap-2 overflow-y-auto rounded-md border p-2">
                            {choices.map((choice) => (
                                <Field
                                    key={choice.value}
                                    orientation="horizontal"
                                >
                                    <Checkbox
                                        id={`locked-${row.key}-${choice.value}`}
                                        checked={selected.includes(
                                            choice.value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            onChange({
                                                key: row.key,
                                                value: checked
                                                    ? [
                                                          ...selected,
                                                          choice.value,
                                                      ]
                                                    : selected.filter(
                                                          (value) =>
                                                              value !==
                                                              choice.value,
                                                      ),
                                            })
                                        }
                                    />
                                    <FieldLabel
                                        htmlFor={`locked-${row.key}-${choice.value}`}
                                        className="font-normal"
                                    >
                                        {choice.label}
                                    </FieldLabel>
                                </Field>
                            ))}
                        </div>
                    )}
                    <FieldError>{error}</FieldError>
                </Field>
            )}
            {field && !isChoice(field) && (
                <Field data-invalid={Boolean(error)}>
                    <Input
                        label="Saringan"
                        required
                        maxLength={250}
                        placeholder={
                            field.type === 'date' || field.type === 'datetime'
                                ? 'Contoh: 01/01/2026..31/12/2026'
                                : field.type === 'number'
                                  ? 'Contoh: >=1.000.000'
                                  : 'Contoh: RS*|Klinik*'
                        }
                        value={typeof row.value === 'string' ? row.value : ''}
                        onChange={(event) =>
                            onChange({
                                key: row.key,
                                value: event.target.value,
                            })
                        }
                    />
                    <FieldDescription>
                        Tulis seperti saringan di daftar: | berarti atau, ..
                        berarti rentang, * berarti sembarang huruf.
                    </FieldDescription>
                    <FieldError>{error}</FieldError>
                </Field>
            )}
        </div>
    );
}

function PublicationSheet({
    publication,
    savedQueries,
    datasets,
    clients,
    onClose,
}: {
    publication: Publication | null;
    savedQueries: SavedQueryOption[];
    datasets: Record<string, DatasetInfo>;
    clients: ClientOption[];
    onClose: () => void;
}) {
    const [form, setForm] = useState<Form>(toForm(publication));
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const error = (key: string) =>
        errors[key]?.[0] ??
        Object.entries(errors).find(([name]) =>
            name.startsWith(`${key}.`),
        )?.[1][0];

    const query = savedQueries.find((item) => item.id === form.saved_query_id);
    const datasetCode = query?.dataset_code ?? publication?.dataset?.code ?? '';
    const dataset = datasets[datasetCode];
    const switching =
        publication !== null &&
        form.saved_query_id !== (publication.saved_query?.id ?? '');
    const takeQuery = publication === null || switching || form.apply_latest;

    const save = async () => {
        setSaving(true);
        setErrors({});
        const locked = Object.fromEntries(
            form.locked
                .filter((row) => row.key !== '')
                .map((row) => [row.key, row.value]),
        );
        const body: Record<string, unknown> = {
            name: form.name,
            description: form.description === '' ? null : form.description,
            locked_filters: locked,
            client_ids: form.client_ids,
            formats: form.formats,
            min_group_size:
                form.min_group_size === '' ? null : Number(form.min_group_size),
        };

        if (takeQuery) {
            body.saved_query_id = form.saved_query_id;
        }

        if (publication === null && form.code !== '') {
            body.code = form.code;
        }

        if (publication !== null) {
            body.version = publication.version;
        }

        try {
            await apiJson(
                publication
                    ? `/api/v1/analytics/publications/${publication.id}`
                    : '/api/v1/analytics/publications',
                {
                    method: publication ? 'PATCH' : 'POST',
                    body: JSON.stringify(body),
                },
            );
            router.reload({ only: ['publications'] });
            toast.success('Publikasi disimpan.');
            onClose();
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toastSaveError(caught, 'Publikasi belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-xl">
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {publication ? 'Ubah publikasi' : 'Publikasi baru'}
                    </SheetTitle>
                </SheetHeader>
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <FieldGroup>
                        {publication ? (
                            <Field>
                                <Input
                                    label="Kode"
                                    value={publication.code}
                                    readOnly
                                />
                                <FieldDescription>
                                    Bagian alamat yang dipakai sistem lain. Kode
                                    tidak dapat diganti.
                                </FieldDescription>
                            </Field>
                        ) : null}
                        <Field data-invalid={Boolean(error('name'))}>
                            <Input
                                label="Nama"
                                required
                                maxLength={120}
                                placeholder="Contoh: Aset per unit"
                                value={form.name}
                                onChange={(event) =>
                                    set('name', event.target.value)
                                }
                            />
                            <FieldError>{error('name')}</FieldError>
                        </Field>
                        {!publication && (
                            <Field data-invalid={Boolean(error('code'))}>
                                <Input
                                    label="Kode"
                                    maxLength={80}
                                    placeholder="Kosongkan untuk dibuat dari nama"
                                    value={form.code}
                                    onChange={(event) =>
                                        set('code', event.target.value)
                                    }
                                />
                                <FieldDescription>
                                    Bagian alamat yang dipakai sistem lain:
                                    huruf kecil, angka, dan tanda hubung. Tidak
                                    dapat diganti sesudah disimpan.
                                </FieldDescription>
                                <FieldError>{error('code')}</FieldError>
                            </Field>
                        )}
                        <Field data-invalid={Boolean(error('description'))}>
                            <Textarea
                                label="Keterangan"
                                maxLength={1000}
                                value={form.description}
                                onChange={(event) =>
                                    set('description', event.target.value)
                                }
                            />
                            <FieldError>{error('description')}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(error('saved_query_id'))}>
                            <NativeSelect
                                label="Analisis tersimpan"
                                required
                                value={form.saved_query_id}
                                onChange={(event) =>
                                    setForm((current) => ({
                                        ...current,
                                        saved_query_id: event.target.value,
                                        locked: [],
                                        min_group_size: '',
                                    }))
                                }
                            >
                                <NativeSelectOption value="">
                                    Pilih analisis tersimpan
                                </NativeSelectOption>
                                {publication?.saved_query &&
                                    !savedQueries.some(
                                        (item) =>
                                            item.id ===
                                            publication.saved_query?.id,
                                    ) && (
                                        <NativeSelectOption
                                            value={publication.saved_query.id}
                                        >
                                            {publication.saved_query.name}
                                        </NativeSelectOption>
                                    )}
                                {savedQueries.map((item) => (
                                    <NativeSelectOption
                                        key={item.id}
                                        value={item.id}
                                    >
                                        {item.name}
                                        {datasets[item.dataset_code]
                                            ? ` Â· ${datasets[item.dataset_code].caption}`
                                            : ''}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldDescription>
                                Yang dibaca sistem lain adalah isi analisis saat
                                dipublikasikan. Perubahan analisis tersimpan
                                baru ikut setelah Anda menerapkannya di sini.
                            </FieldDescription>
                            <FieldError>{error('saved_query_id')}</FieldError>
                        </Field>
                        {publication?.saved_query?.changed && !switching && (
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="apply-latest"
                                    checked={form.apply_latest}
                                    onCheckedChange={(checked) =>
                                        set('apply_latest', checked === true)
                                    }
                                />
                                <FieldLabel
                                    htmlFor="apply-latest"
                                    className="font-normal"
                                >
                                    Analisis tersimpan ini sudah berubah.
                                    Terapkan isi terbarunya saat disimpan.
                                </FieldLabel>
                            </Field>
                        )}
                        {dataset && (
                            <FieldSet
                                data-invalid={Boolean(error('locked_filters'))}
                            >
                                <FieldLegend>Saringan terkunci</FieldLegend>
                                <FieldDescription>
                                    Batasi data yang dibuka, misalnya hanya satu
                                    unit. Sistem lain tidak dapat melepas
                                    saringan ini; tanpa saringan, yang dibuka
                                    adalah semua data yang boleh Anda baca.
                                </FieldDescription>
                                {form.locked.map((row, index) => (
                                    <LockedFilterRow
                                        key={`${index}-${row.key}`}
                                        dataset={dataset.code}
                                        fields={dataset.fields}
                                        row={row}
                                        taken={form.locked.map(
                                            (item) => item.key,
                                        )}
                                        error={
                                            row.key
                                                ? error(
                                                      `locked_filters.${row.key}`,
                                                  )
                                                : undefined
                                        }
                                        onChange={(next) =>
                                            set(
                                                'locked',
                                                form.locked.map((item, i) =>
                                                    i === index ? next : item,
                                                ),
                                            )
                                        }
                                        onRemove={() =>
                                            set(
                                                'locked',
                                                form.locked.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            set('locked', [
                                                ...form.locked,
                                                { key: '', value: '' },
                                            ])
                                        }
                                    >
                                        <Plus />
                                        Tambah saringan
                                    </Button>
                                </div>
                                <FieldError>
                                    {errors.locked_filters?.[0]}
                                </FieldError>
                            </FieldSet>
                        )}
                        <FieldSet data-invalid={Boolean(error('client_ids'))}>
                            <FieldLegend>Klien yang boleh membaca</FieldLegend>
                            {clients.length === 0 ? (
                                <FieldDescription>
                                    Belum ada klien integrasi aktif. Tambahkan
                                    di Klien integrasi, dengan scope
                                    analytics.read.
                                </FieldDescription>
                            ) : (
                                clients.map((client) => (
                                    <Field
                                        key={client.id}
                                        orientation="horizontal"
                                    >
                                        <Checkbox
                                            id={`client-${client.id}`}
                                            disabled={!client.can_read}
                                            checked={form.client_ids.includes(
                                                client.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                set(
                                                    'client_ids',
                                                    checked
                                                        ? [
                                                              ...form.client_ids,
                                                              client.id,
                                                          ]
                                                        : form.client_ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  client.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <FieldLabel
                                            htmlFor={`client-${client.id}`}
                                            className="font-normal"
                                        >
                                            {client.name}
                                            {!client.can_read && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    Â· belum punya scope
                                                    analytics.read
                                                </span>
                                            )}
                                        </FieldLabel>
                                    </Field>
                                ))
                            )}
                            <FieldError>{error('client_ids')}</FieldError>
                        </FieldSet>
                        <FieldSet data-invalid={Boolean(error('formats'))}>
                            <FieldLegend>Format</FieldLegend>
                            {Object.entries(FORMAT_LABEL).map(
                                ([format, label]) => (
                                    <Field
                                        key={format}
                                        orientation="horizontal"
                                    >
                                        <Checkbox
                                            id={`format-${format}`}
                                            checked={form.formats.includes(
                                                format,
                                            )}
                                            onCheckedChange={(checked) =>
                                                set(
                                                    'formats',
                                                    checked
                                                        ? [
                                                              ...form.formats,
                                                              format,
                                                          ]
                                                        : form.formats.filter(
                                                              (item) =>
                                                                  item !==
                                                                  format,
                                                          ),
                                                )
                                            }
                                        />
                                        <FieldLabel
                                            htmlFor={`format-${format}`}
                                            className="font-normal"
                                        >
                                            {label}
                                        </FieldLabel>
                                    </Field>
                                ),
                            )}
                            <FieldError>{error('formats')}</FieldError>
                        </FieldSet>
                        {dataset?.can_hide_small_groups && (
                            <Field
                                data-invalid={Boolean(error('min_group_size'))}
                            >
                                <Input
                                    label="Sembunyikan kelompok kecil"
                                    type="number"
                                    min={2}
                                    max={1000}
                                    placeholder="Kosong: tampilkan semua"
                                    value={form.min_group_size}
                                    onChange={(event) =>
                                        set(
                                            'min_group_size',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldDescription>
                                    Baris yang dihitung dari kurang dari angka
                                    ini tidak dikirim, supaya angka kecil tidak
                                    dapat menunjuk orang tertentu.
                                </FieldDescription>
                                <FieldError>
                                    {error('min_group_size')}
                                </FieldError>
                            </Field>
                        )}
                    </FieldGroup>
                </div>
                <SheetFooter className="border-t px-6 py-4">
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="button" disabled={saving} onClick={save}>
                            {saving ? 'Menyimpanâ€¦' : 'Simpan'}
                        </Button>
                    </div>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function PreviewDialog({
    publication,
    onClose,
}: {
    publication: Publication;
    onClose: () => void;
}) {
    const formatDateTime = useDateTimeFormat();
    const [preview, setPreview] = useState<Preview | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    useEffect(() => {
        let alive = true;
        apiJson<Preview>(
            `/api/v1/analytics/publications/${publication.id}/preview`,
        )
            .then((result) => {
                if (alive) {
                    setPreview(result);
                }
            })
            .catch((caught: unknown) => {
                if (alive) {
                    setFailure(
                        errorText(caught, 'Contoh data belum dapat dihitung.'),
                    );
                }
            });

        return () => {
            alive = false;
        };
    }, [publication.id]);

    const keys = (preview?.columns ?? []).map((column) => ({
        caption: column.caption,
        key: column.label_key ?? column.key,
    }));

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size="wide">
                <DialogHeader>
                    <DialogTitle>Contoh data {publication.name}</DialogTitle>
                    <DialogDescription>
                        Paling banyak 50 baris pertama, dihitung persis seperti
                        yang dibaca sistem lain: dengan hak Anda, saringan
                        terkunci, dan kelompok kecil disembunyikan.
                    </DialogDescription>
                </DialogHeader>
                <DialogBody>
                    {failure ? (
                        <p className="text-sm text-destructive">{failure}</p>
                    ) : preview === null ? (
                        <p className="text-sm text-muted-foreground">
                            Menghitungâ€¦
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {keys.map((column) => (
                                                <TableHead key={column.key}>
                                                    {column.caption}
                                                </TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {preview.rows.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={keys.length || 1}
                                                    className="text-muted-foreground"
                                                >
                                                    Tidak ada baris.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            preview.rows.map((row, index) => (
                                                <TableRow key={index}>
                                                    {keys.map((column) => (
                                                        <TableCell
                                                            key={column.key}
                                                        >
                                                            {String(
                                                                row[
                                                                    column.key
                                                                ] ?? '',
                                                            )}
                                                        </TableCell>
                                                    ))}
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Dihitung{' '}
                                {formatDateTime(preview.meta.generated_at)}
                                {preview.meta.small_groups_hidden > 0 &&
                                    ` Â· ${preview.meta.small_groups_hidden} kelompok kecil disembunyikan`}
                            </p>
                        </div>
                    )}
                </DialogBody>
            </DialogContent>
        </Dialog>
    );
}

function HealthNote({ publication }: { publication: Publication }) {
    if (publication.health.state === 'suspended') {
        return (
            <span className="block text-xs text-destructive">
                Tertahan: pemiliknya tidak lagi berhak membagikan data ini.
                Ambil alih supaya sistem lain dapat membacanya lagi.
            </span>
        );
    }

    if (publication.health.state === 'unavailable') {
        return (
            <span className="block text-xs text-destructive">
                Perlu diperbaiki: {publication.health.message}
            </span>
        );
    }

    if (publication.saved_query?.changed && publication.status !== 'revoked') {
        return (
            <span className="block text-xs text-muted-foreground">
                Analisis tersimpannya sudah berubah; sistem lain masih membaca
                isi lama sampai pemiliknya menerapkannya.
            </span>
        );
    }

    return null;
}

export default function AnalyticsPublications({
    publications,
    savedQueries,
    datasets,
    clients,
    canManage,
    endpoint,
    guideUrl,
}: Props) {
    const formatDateTime = useDateTimeFormat();
    const [editing, setEditing] = useState<Publication | 'new' | null>(null);
    const [previewing, setPreviewing] = useState<Publication | null>(null);
    const [revoking, setRevoking] = useState<Publication | null>(null);

    const action = async (
        publication: Publication,
        path: string,
        success: string,
    ) => {
        try {
            await apiJson(
                `/api/v1/analytics/publications/${publication.id}/${path}`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: publication.version }),
                },
            );
            router.reload({ only: ['publications'] });
            toast.success(success);
        } catch (caught) {
            toastSaveError(caught, 'Tindakan belum berhasil.');
        }
    };

    return (
        <>
            <Head title="Publikasi data" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Publikasi data"
                    description="Data analisis yang dibuka untuk dibaca sistem lain, misalnya n8n, Google Sheets, atau aplikasi milik pelanggan."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Publikasi</CardTitle>
                        <CardDescription>
                            Sistem lain membaca lewat {endpoint} dengan token
                            klien integrasi, dan hanya publikasi yang menyebut
                            kliennya.{' '}
                            <a
                                href={guideUrl}
                                className="underline underline-offset-4"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Panduan untuk developer
                            </a>
                        </CardDescription>
                        {canManage && (
                            <CardAction>
                                <ActionButton
                                    action="create"
                                    size="sm"
                                    onClick={() => setEditing('new')}
                                >
                                    Tambah publikasi
                                </ActionButton>
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent>
                        {publications.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyTitle>Belum ada publikasi</EmptyTitle>
                                    <EmptyDescription>
                                        Publikasikan analisis tersimpan supaya
                                        sistem lain dapat membaca angkanya tanpa
                                        membuka CoreERP.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nama</TableHead>
                                            <TableHead>Data</TableHead>
                                            <TableHead>Pemilik</TableHead>
                                            <TableHead>Klien</TableHead>
                                            <TableHead>
                                                Terakhir dibaca
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Aksi
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {publications.map((publication) => {
                                            const locked = Object.keys(
                                                publication.locked_filters,
                                            ).length;

                                            return (
                                                <TableRow key={publication.id}>
                                                    <TableCell className="font-medium">
                                                        {publication.name}
                                                        <span className="block font-mono text-xs font-normal text-muted-foreground">
                                                            {publication.code}
                                                        </span>
                                                        <HealthNote
                                                            publication={
                                                                publication
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        {publication.saved_query
                                                            ?.name ?? 'â€”'}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {publication.dataset
                                                                ?.caption ?? ''}
                                                            {locked > 0 &&
                                                                ` Â· ${locked} saringan terkunci`}
                                                            {publication.min_group_size !==
                                                                null &&
                                                                ` Â· kelompok di bawah ${publication.min_group_size} disembunyikan`}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        {publication.owner
                                                            .name ?? 'â€”'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {publication.clients
                                                            .length === 0
                                                            ? 'Belum ada'
                                                            : publication.clients
                                                                  .map(
                                                                      (
                                                                          client,
                                                                      ) =>
                                                                          client.name ??
                                                                          'â€”',
                                                                  )
                                                                  .join(', ')}
                                                    </TableCell>
                                                    <TableCell>
                                                        {publication.last_used_at
                                                            ? formatDateTime(
                                                                  publication.last_used_at,
                                                              )
                                                            : 'Belum pernah'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={
                                                                publication.status ===
                                                                'active'
                                                                    ? 'secondary'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                STATUS_LABEL[
                                                                    publication
                                                                        .status
                                                                ]
                                                            }
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        {(publication.can_manage ||
                                                            publication.is_owner) && (
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger
                                                                    asChild
                                                                >
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        aria-label={`Aksi untuk ${publication.name}`}
                                                                    >
                                                                        <MoreHorizontal />
                                                                    </Button>
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    {publication.can_edit && (
                                                                        <DropdownMenuItem
                                                                            onSelect={() =>
                                                                                setEditing(
                                                                                    publication,
                                                                                )
                                                                            }
                                                                        >
                                                                            Ubah
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {publication.is_owner && (
                                                                        <DropdownMenuItem
                                                                            onSelect={() =>
                                                                                setPreviewing(
                                                                                    publication,
                                                                                )
                                                                            }
                                                                        >
                                                                            Lihat
                                                                            contoh
                                                                            data
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {publication.can_manage &&
                                                                        publication.status ===
                                                                            'active' && (
                                                                            <DropdownMenuItem
                                                                                onSelect={() =>
                                                                                    action(
                                                                                        publication,
                                                                                        'pause',
                                                                                        'Publikasi dihentikan sementara.',
                                                                                    )
                                                                                }
                                                                            >
                                                                                Hentikan
                                                                                sementara
                                                                            </DropdownMenuItem>
                                                                        )}
                                                                    {publication.can_manage &&
                                                                        publication.status ===
                                                                            'paused' && (
                                                                            <DropdownMenuItem
                                                                                onSelect={() =>
                                                                                    action(
                                                                                        publication,
                                                                                        'resume',
                                                                                        'Publikasi dilanjutkan.',
                                                                                    )
                                                                                }
                                                                            >
                                                                                Lanjutkan
                                                                            </DropdownMenuItem>
                                                                        )}
                                                                    {publication.can_manage &&
                                                                        !publication.is_owner && (
                                                                            <DropdownMenuItem
                                                                                onSelect={() =>
                                                                                    action(
                                                                                        publication,
                                                                                        'take-over',
                                                                                        'Anda sekarang pemilik publikasi ini.',
                                                                                    )
                                                                                }
                                                                            >
                                                                                Ambil
                                                                                alih
                                                                            </DropdownMenuItem>
                                                                        )}
                                                                    {publication.can_manage && (
                                                                        <>
                                                                            <DropdownMenuSeparator />
                                                                            <DropdownMenuItem
                                                                                variant="destructive"
                                                                                onSelect={() =>
                                                                                    setRevoking(
                                                                                        publication,
                                                                                    )
                                                                                }
                                                                            >
                                                                                Cabut
                                                                            </DropdownMenuItem>
                                                                        </>
                                                                    )}
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
            {editing && (
                <PublicationSheet
                    key={editing === 'new' ? 'new' : editing.id}
                    publication={editing === 'new' ? null : editing}
                    savedQueries={savedQueries}
                    datasets={datasets}
                    clients={clients}
                    onClose={() => setEditing(null)}
                />
            )}
            {previewing && (
                <PreviewDialog
                    publication={previewing}
                    onClose={() => setPreviewing(null)}
                />
            )}
            <AlertDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cabut {revoking?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Sistem lain langsung tidak dapat membaca publikasi
                            ini, dan publikasi yang dicabut tidak dapat
                            dihidupkan lagi. Kodenya tetap terpakai. Pilih
                            Hentikan sementara bila Anda hanya ingin menahannya.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (revoking) {
                                    action(
                                        revoking,
                                        'revoke',
                                        'Publikasi dicabut.',
                                    );
                                }

                                setRevoking(null);
                            }}
                        >
                            Cabut publikasi
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

AnalyticsPublications.layout = {
    breadcrumbs: [
        { title: 'Publikasi data', href: '/analytics/publications' },
    ] satisfies BreadcrumbItem[],
};
