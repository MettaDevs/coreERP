import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
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
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Field, FieldError, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import EditShield from '../../_shared/EditShield';
import { api, ApiError, errorMessage, newIdempotencyKey } from '../../api';

type Classification = {
    id: string;
    label: string;
    jurisdiction: string;
    effective_from: string;
    effective_to: string | null;
    useful_life_years: number | null;
    straight_line_rate_percent: string | null;
    reducing_balance_rate_percent: string | null;
    allow_reducing_balance: boolean;
    allow_straight_line: boolean;
    depreciable: boolean;
    aktif: boolean;
    version: number;
};

export default function AssetClassificationPage({
    permissions,
}: {
    permissions: string[];
}) {
    const [items, setItems] = useState<Classification[]>([]);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [reload, setReload] = useState(0);
    const [editing, setEditing] = useState(false);
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formNonce, setFormNonce] = useState(0);
    const [dirty, setDirty] = useState(false);
    const [pendingChange, setPendingChange] = useState<
        { id: string } | { search: string } | { create: true } | null
    >(null);
    const [archiving, setArchiving] = useState(false);
    const [actionError, setActionError] = useState('');
    const selected = creating
        ? null
        : (items.find((item) => item.id === selectedId) ?? items[0]);
    const canCreate = permissions.includes(
        'management-aset.kelompok-harta.create',
    );
    const canUpdate = permissions.includes(
        'management-aset.kelompok-harta.update',
    );
    const canArchive = permissions.includes(
        'management-aset.kelompok-harta.archive',
    );

    function commitChange(
        change: { id: string } | { search: string } | { create: true },
    ) {
        setCreating('create' in change);

        if ('id' in change) {
            setSelectedId(change.id);
        } else if ('search' in change) {
            setSearch(change.search);
            setPage(1);
            setSelectedId(null);
        }

        setEditing('create' in change);
        setDirty(false);
        setFormNonce((value) => value + 1);
        setPendingChange(null);
        setActionError('');
    }

    function requestChange(
        change: { id: string } | { search: string } | { create: true },
    ) {
        if (saving || ('id' in change && change.id === selected?.id)) {
            return;
        }

        if (editing && dirty) {
            setPendingChange(change);

            return;
        }

        commitChange(change);
    }

    async function archive() {
        if (!selected || saving) {
            return;
        }

        setSaving(true);
        setActionError('');

        try {
            await api(`/kelompok-harta/${selected.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: selected.version }),
            });
            setItems((current) =>
                current.filter((item) => item.id !== selected.id),
            );
            setSelectedId(null);
            setPage(1);
            setReload((value) => value + 1);
            toast.success('Kelompok Aset diarsipkan.');
        } catch (caught) {
            setActionError(
                errorMessage(caught, 'Kelompok Aset belum dapat diarsipkan.'),
            );
        } finally {
            setSaving(false);
            setArchiving(false);
        }
    }

    useEffect(() => {
        let cancelled = false;
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const result = await api<{
                    data: Classification[];
                    meta: { last_page: number };
                }>(
                    `/kelompok-harta?${new URLSearchParams({ q: search, page: String(page) })}`,
                );

                if (!cancelled) {
                    setItems((current) =>
                        page === 1 ? result.data : [...current, ...result.data],
                    );
                    setLastPage(result.meta.last_page);
                    setLoadError('');
                }
            } catch (caught) {
                if (!cancelled) {
                    setLoadError(
                        errorMessage(
                            caught,
                            'Kelompok Aset belum dapat dimuat.',
                        ),
                    );
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        }, 250);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [search, page, reload]);

    return (
        <div className="flex h-full min-h-0 flex-col">
            <RecordActionBar title="Kelompok Aset">
                {editing ? (
                    <>
                        <Button
                            type="submit"
                            form="asset-classification-form"
                            disabled={saving}
                        >
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                        <Button
                            variant="outline"
                            disabled={saving}
                            onClick={() => {
                                setEditing(false);
                                setCreating(false);
                                setDirty(false);
                                setFormNonce((value) => value + 1);
                            }}
                        >
                            Batal
                        </Button>
                    </>
                ) : (
                    <>
                        {canCreate && (
                            <ActionButton
                                action="create"
                                disabled={saving || loading || !!loadError}
                                onClick={() => requestChange({ create: true })}
                            >
                                Tambah data
                            </ActionButton>
                        )}
                        {canUpdate && (
                            <ActionButton
                                action="edit"
                                disabled={!selected || loading || !!loadError}
                                onClick={() => setEditing(true)}
                            >
                                Ubah
                            </ActionButton>
                        )}
                        {canArchive && (
                            <ActionButton
                                action="archive"
                                disabled={
                                    !selected ||
                                    loading ||
                                    !!loadError ||
                                    saving
                                }
                                onClick={() => {
                                    setActionError('');
                                    setArchiving(true);
                                }}
                            >
                                Arsipkan
                            </ActionButton>
                        )}
                    </>
                )}
            </RecordActionBar>
            {actionError && (
                <p role="alert" className="text-destructive px-4 py-2 text-sm">
                    {actionError}
                </p>
            )}
            <div className="grid min-h-0 flex-1 grid-cols-1 md:grid-cols-[16rem_minmax(0,1fr)] lg:grid-cols-[20rem_minmax(0,1fr)]">
                <aside
                    aria-label="Daftar kelompok aset"
                    className="flex min-h-0 flex-col border-r"
                >
                    <div className="space-y-2 border-b px-4 py-3">
                        <p className="text-sm font-semibold">
                            Daftar kelompok aset
                        </p>
                        <Input
                            type="search"
                            label="Cari nama"
                            value={search}
                            readOnly={saving}
                            onChange={(event) => {
                                requestChange({ search: event.target.value });
                            }}
                        />
                    </div>
                    <div className="min-h-0 overflow-y-auto">
                        {loadError && (
                            <div role="alert" className="space-y-2 p-4">
                                <p>{loadError}</p>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setReload((value) => value + 1)
                                    }
                                >
                                    Coba lagi
                                </Button>
                            </div>
                        )}
                        {items.map((item) => (
                            <button
                                type="button"
                                key={item.id}
                                aria-pressed={selected?.id === item.id}
                                disabled={saving || loading || !!loadError}
                                onClick={() => requestChange({ id: item.id })}
                                className="hover:bg-accent aria-pressed:bg-accent block w-full border-b px-4 py-3 text-left disabled:cursor-default"
                            >
                                <span className="block truncate font-semibold">
                                    {item.label}
                                </span>
                                <span className="text-muted-foreground block text-xs">
                                    {item.aktif ? 'Aktif' : 'Tidak aktif'}
                                </span>
                            </button>
                        ))}
                        {loading && (
                            <p role="status" className="p-4 text-sm">
                                Memuat kelompok aset…
                            </p>
                        )}
                        {!loading && !loadError && items.length === 0 && (
                            <p className="text-muted-foreground p-4 text-sm">
                                {search
                                    ? 'Tidak ada kelompok yang cocok dengan pencarian.'
                                    : 'Belum ada kelompok aset.'}
                            </p>
                        )}
                        {page < lastPage && (
                            <Button
                                variant="ghost"
                                disabled={loading || saving}
                                onClick={() => setPage((value) => value + 1)}
                            >
                                Lihat lainnya
                            </Button>
                        )}
                    </div>
                </aside>
                <div className="min-h-0 overflow-y-auto p-4">
                    {(selected || creating) && (
                        <ClassificationForm
                            key={`${selected?.id ?? 'new'}:${selected?.version ?? 0}:${formNonce}`}
                            record={selected ?? null}
                            editing={editing}
                            saving={saving}
                            canEdit={
                                (creating ? canCreate : canUpdate) &&
                                !loading &&
                                !loadError
                            }
                            onRequestEdit={() => setEditing(true)}
                            onDirtyChange={setDirty}
                            onSaving={setSaving}
                            onSaved={(record) => {
                                setItems((current) =>
                                    current.some(
                                        (item) => item.id === record.id,
                                    )
                                        ? current.map((item) =>
                                              item.id === record.id
                                                  ? record
                                                  : item,
                                          )
                                        : [record, ...current],
                                );
                                setSelectedId(record.id);
                                setCreating(false);
                                setEditing(false);
                                setDirty(false);
                            }}
                        />
                    )}
                </div>
            </div>
            <AlertDialog
                open={pendingChange !== null}
                onOpenChange={(open) => !open && setPendingChange(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Perubahan belum disimpan
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Pindah ke kelompok aset lain akan membuang perubahan
                            yang belum Anda simpan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Tetap di sini</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                pendingChange && commitChange(pendingChange)
                            }
                        >
                            Buang perubahan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AlertDialog
                open={archiving}
                onOpenChange={(open) => !saving && setArchiving(open)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan {selected?.label}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Data ini tidak lagi tampil pada daftar pilihan.
                            Record yang sudah memakainya tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={saving}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={saving}
                            onClick={(event) => {
                                event.preventDefault();
                                void archive();
                            }}
                        >
                            {saving ? 'Mengarsipkan…' : 'Arsipkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

function ClassificationForm({
    record,
    editing,
    saving,
    canEdit,
    onRequestEdit,
    onDirtyChange,
    onSaving,
    onSaved,
}: {
    record: Classification | null;
    editing: boolean;
    saving: boolean;
    canEdit: boolean;
    onRequestEdit: () => void;
    onDirtyChange: (value: boolean) => void;
    onSaving: (value: boolean) => void;
    onSaved: (record: Classification) => void;
}) {
    const [active, setActive] = useState(record?.aktif ?? true);
    const [depreciable, setDepreciable] = useState(record?.depreciable ?? true);
    const [reducingBalance, setReducingBalance] = useState(
        record?.allow_reducing_balance ?? false,
    );
    const [straightLine, setStraightLine] = useState(
        record?.allow_straight_line ?? true,
    );
    const creationKey = useRef(newIdempotencyKey());
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const formRef = useRef<HTMLFormElement>(null);
    const pendingFocusRef = useRef<string | null>(null);

    function enterEdit(name: string) {
        if (editing || !canEdit || saving) {
            return;
        }

        pendingFocusRef.current = name;
        onRequestEdit();
    }

    useEffect(() => {
        const name = pendingFocusRef.current;

        if (!editing || !name) {
            return;
        }

        pendingFocusRef.current = null;
        formRef.current
            ?.querySelector<HTMLElement>(
                `[data-field-name="${CSS.escape(name)}"] input:not([type="hidden"]), [data-field-name="${CSS.escape(name)}"] button`,
            )
            ?.focus();
    }, [editing]);
    const fields = [
        {
            name: 'useful_life_years',
            label: 'Masa manfaat (tahun)',
            type: 'number',
            min: 1,
            max: 1000,
            step: '1',
            required: depreciable,
        },
        {
            name: 'straight_line_rate_percent',
            label: 'Tarif garis lurus (%)',
            type: 'number',
            min: 0,
            max: 100,
            step: '0.0001',
            required: depreciable && straightLine,
        },
        {
            name: 'reducing_balance_rate_percent',
            label: 'Tarif saldo menurun (%)',
            type: 'number',
            min: 0,
            max: 100,
            step: '0.0001',
            required: depreciable && reducingBalance,
        },
    ] as const;

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!editing || saving) {
            return;
        }

        const values = new FormData(event.currentTarget);
        onSaving(true);
        setError('');
        setErrors({});

        try {
            const result = await api<{ data: Classification }>(
                `/kelompok-harta${record ? `/${record.id}` : ''}`,
                {
                    method: record ? 'PATCH' : 'POST',
                    headers: record
                        ? undefined
                        : { 'Idempotency-Key': creationKey.current },
                    body: JSON.stringify({
                        ...Object.fromEntries(
                            ['label', ...fields.map((field) => field.name)].map(
                                (name) => [name, values.get(name) || null],
                            ),
                        ),
                        aktif: active,
                        depreciable,
                        allow_reducing_balance: reducingBalance,
                        allow_straight_line: straightLine,
                        ...(record ? { version: record.version } : {}),
                    }),
                },
            );
            onSaved(result.data);
            toast.success('Kelompok Aset disimpan.');
        } catch (caught) {
            setError(
                errorMessage(caught, 'Kelompok Aset belum dapat disimpan.'),
            );

            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }
        } finally {
            onSaving(false);
        }
    }

    return (
        <form
            ref={formRef}
            id="asset-classification-form"
            onSubmit={(event) => void save(event)}
            className="space-y-4"
            onChange={() => editing && onDirtyChange(true)}
        >
            <div className="flex items-center gap-3">
                <Field className="flex-1" data-field-name="label">
                    <Input
                        name="label"
                        label="Nama kelompok aset"
                        defaultValue={record?.label ?? ''}
                        readOnly={!editing || saving}
                        onFocus={() => enterEdit('label')}
                        required
                        maxLength={150}
                        aria-invalid={!!errors.label}
                    />
                    <FieldError>{errors.label?.join(' ')}</FieldError>
                </Field>
                <Badge variant={active ? 'default' : 'secondary'}>
                    {active ? 'Aktif' : 'Tidak aktif'}
                </Badge>
            </div>
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            <CollapsibleSectionGroup defaultValue={['depreciation']}>
                <CollapsibleSection
                    value="depreciation"
                    title="Aturan penyusutan"
                    summary={`${depreciable ? 'Disusutkan' : 'Tidak disusutkan'} · ${active ? 'Aktif' : 'Tidak aktif'}`}
                >
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field data-field-name="depreciable">
                                <FieldLabel htmlFor="classification-depreciable">
                                    Disusutkan
                                </FieldLabel>
                                <EditShield
                                    active={!editing}
                                    label="Disusutkan"
                                    onActivate={() => enterEdit('depreciable')}
                                >
                                    <Switch
                                        id="classification-depreciable"
                                        checked={depreciable}
                                        onCheckedChange={(value) => {
                                            if (editing && !saving) {
                                                onDirtyChange(true);
                                                setDepreciable(value);
                                            }
                                        }}
                                    />
                                </EditShield>
                            </Field>
                            <Field data-field-name="allow_reducing_balance">
                                <FieldLabel htmlFor="classification-reducing">
                                    Boleh memakai saldo menurun
                                </FieldLabel>
                                <EditShield
                                    active={!editing}
                                    label="Boleh memakai saldo menurun"
                                    onActivate={() =>
                                        enterEdit('allow_reducing_balance')
                                    }
                                >
                                    <Switch
                                        id="classification-reducing"
                                        checked={reducingBalance}
                                        onCheckedChange={(value) => {
                                            if (editing && !saving) {
                                                onDirtyChange(true);
                                                setReducingBalance(value);
                                            }
                                        }}
                                    />
                                </EditShield>
                            </Field>
                            <Field data-field-name="allow_straight_line">
                                <FieldLabel htmlFor="classification-straight-line">
                                    Boleh memakai garis lurus
                                </FieldLabel>
                                <EditShield
                                    active={!editing}
                                    label="Boleh memakai garis lurus"
                                    onActivate={() =>
                                        enterEdit('allow_straight_line')
                                    }
                                >
                                    <Switch
                                        id="classification-straight-line"
                                        checked={straightLine}
                                        onCheckedChange={(value) => {
                                            if (editing && !saving) {
                                                onDirtyChange(true);
                                                setStraightLine(value);
                                            }
                                        }}
                                    />
                                </EditShield>
                            </Field>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {fields.map((field) => (
                                <Field
                                    key={field.name}
                                    data-field-name={field.name}
                                >
                                    <Input
                                        name={field.name}
                                        label={field.label}
                                        type={field.type}
                                        defaultValue={
                                            record?.[field.name] ?? ''
                                        }
                                        readOnly={!editing || saving}
                                        onFocus={() => enterEdit(field.name)}
                                        required={field.required}
                                        {...('max' in field
                                            ? {
                                                  min: field.min,
                                                  max: field.max,
                                                  step: field.step,
                                              }
                                            : {})}
                                        aria-invalid={!!errors[field.name]}
                                    />
                                    <FieldError>
                                        {errors[field.name]?.join(' ')}
                                    </FieldError>
                                </Field>
                            ))}
                            <Field data-field-name="aktif">
                                <FieldLabel htmlFor="classification-active">
                                    Aktif
                                </FieldLabel>
                                <EditShield
                                    active={!editing}
                                    label="Aktif"
                                    onActivate={() => enterEdit('aktif')}
                                >
                                    <Switch
                                        id="classification-active"
                                        checked={active}
                                        onCheckedChange={(value) => {
                                            if (editing && !saving) {
                                                onDirtyChange(true);
                                                setActive(value);
                                            }
                                        }}
                                    />
                                </EditShield>
                            </Field>
                        </div>
                        <p className="text-muted-foreground text-sm">
                            Perubahan di sini tidak otomatis mengubah metode
                            penyusutan yang sudah dibuat. Periksa metode yang
                            digunakan sebelum menghitung penyusutan.
                        </p>
                    </div>
                </CollapsibleSection>
            </CollapsibleSectionGroup>
        </form>
    );
}
