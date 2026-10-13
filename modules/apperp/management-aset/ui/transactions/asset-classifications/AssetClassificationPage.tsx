import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Field, FieldError, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { api, ApiError, errorMessage } from '../../api';

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
    const [saving, setSaving] = useState(false);
    const [formNonce, setFormNonce] = useState(0);
    const selected = items.find((item) => item.id === selectedId) ?? items[0];
    const canUpdate = permissions.includes(
        'management-aset.kelompok-harta.update',
    );

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
                            'Kelompok harta belum dapat dimuat.',
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
            <RecordActionBar title="Kelompok harta">
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
                                setFormNonce((value) => value + 1);
                            }}
                        >
                            Batal
                        </Button>
                    </>
                ) : (
                    canUpdate && (
                        <ActionButton
                            action="edit"
                            disabled={!selected || loading || !!loadError}
                            onClick={() => setEditing(true)}
                        >
                            Ubah
                        </ActionButton>
                    )
                )}
            </RecordActionBar>
            <div className="grid min-h-0 flex-1 grid-cols-1 md:grid-cols-[16rem_minmax(0,1fr)] lg:grid-cols-[20rem_minmax(0,1fr)]">
                <aside
                    aria-label="Daftar kelompok harta"
                    className="flex min-h-0 flex-col border-r"
                >
                    <div className="space-y-2 border-b px-4 py-3">
                        <p className="text-sm font-semibold">
                            Daftar kelompok harta
                        </p>
                        <Input
                            type="search"
                            label="Cari nama"
                            value={search}
                            readOnly={editing}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                                setSelectedId(null);
                            }}
                        />
                        {editing && (
                            <p className="text-muted-foreground text-sm">
                                Simpan atau batalkan perubahan sebelum memilih
                                kelompok lain.
                            </p>
                        )}
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
                                disabled={editing || loading || !!loadError}
                                onClick={() => setSelectedId(item.id)}
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
                                Memuat kelompok harta…
                            </p>
                        )}
                        {!loading && !loadError && items.length === 0 && (
                            <p className="text-muted-foreground p-4 text-sm">
                                {search
                                    ? 'Tidak ada kelompok yang cocok dengan pencarian.'
                                    : 'Belum ada kelompok harta.'}
                            </p>
                        )}
                        {page < lastPage && (
                            <Button
                                variant="ghost"
                                disabled={loading || editing}
                                onClick={() => setPage((value) => value + 1)}
                            >
                                Lihat lainnya
                            </Button>
                        )}
                    </div>
                </aside>
                <div className="min-h-0 overflow-y-auto p-4">
                    {selected && (
                        <ClassificationForm
                            key={`${selected.id}:${selected.version}:${formNonce}`}
                            record={selected}
                            editing={editing}
                            onSaving={setSaving}
                            onSaved={(record) => {
                                setItems((current) =>
                                    current.map((item) =>
                                        item.id === record.id ? record : item,
                                    ),
                                );
                                setEditing(false);
                            }}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

function ClassificationForm({
    record,
    editing,
    onSaving,
    onSaved,
}: {
    record: Classification;
    editing: boolean;
    onSaving: (value: boolean) => void;
    onSaved: (record: Classification) => void;
}) {
    const [active, setActive] = useState(record.aktif);
    const [depreciable, setDepreciable] = useState(record.depreciable);
    const [reducingBalance, setReducingBalance] = useState(
        record.allow_reducing_balance,
    );
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const fields = [
        {
            name: 'useful_life_years',
            label: 'Masa manfaat (tahun)',
            type: 'number',
            max: 1000,
            step: '1',
            required: depreciable,
        },
        {
            name: 'straight_line_rate_percent',
            label: 'Tarif garis lurus (%)',
            type: 'number',
            max: 100,
            step: '0.0001',
            required: depreciable,
        },
        {
            name: 'reducing_balance_rate_percent',
            label: 'Tarif saldo menurun (%)',
            type: 'number',
            max: 100,
            step: '0.0001',
            required: depreciable && reducingBalance,
        },
        {
            name: 'effective_from',
            label: 'Berlaku mulai',
            type: 'date',
            required: true,
        },
        {
            name: 'effective_to',
            label: 'Berlaku sampai',
            type: 'date',
            required: false,
        },
    ] as const;

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!editing) {
            return;
        }

        const values = new FormData(event.currentTarget);
        onSaving(true);
        setError('');
        setErrors({});

        try {
            const result = await api<{ data: Classification }>(
                `/kelompok-harta/${record.id}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...Object.fromEntries(
                            ['label', ...fields.map((field) => field.name)].map(
                                (name) => [name, values.get(name) || null],
                            ),
                        ),
                        aktif: active,
                        depreciable,
                        allow_reducing_balance: reducingBalance,
                        version: record.version,
                    }),
                },
            );
            onSaved(result.data);
            toast.success('Kelompok harta disimpan.');
        } catch (caught) {
            setError(
                errorMessage(caught, 'Kelompok harta belum dapat disimpan.'),
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
            id="asset-classification-form"
            onSubmit={(event) => void save(event)}
            className="space-y-4"
        >
            <div className="flex items-center gap-3">
                <Field className="flex-1">
                    <Input
                        name="label"
                        label="Nama kelompok harta"
                        defaultValue={record.label}
                        readOnly={!editing}
                        required
                        maxLength={150}
                        aria-invalid={!!errors.label}
                    />
                    <FieldError>{errors.label?.join(' ')}</FieldError>
                </Field>
                <Badge variant={record.aktif ? 'default' : 'secondary'}>
                    {record.aktif ? 'Aktif' : 'Tidak aktif'}
                </Badge>
            </div>
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            <Card>
                <CardHeader>
                    <CardTitle>Aturan penyusutan</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <FieldLabel htmlFor="classification-depreciable">
                                Disusutkan
                            </FieldLabel>
                            <Switch
                                id="classification-depreciable"
                                checked={depreciable}
                                onCheckedChange={(value) =>
                                    editing && setDepreciable(value)
                                }
                            />
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="classification-reducing">
                                Boleh memakai saldo menurun
                            </FieldLabel>
                            <Switch
                                id="classification-reducing"
                                checked={reducingBalance}
                                onCheckedChange={(value) =>
                                    editing && setReducingBalance(value)
                                }
                            />
                        </Field>
                        {fields.map((field) => (
                            <Field key={field.name}>
                                <Input
                                    name={field.name}
                                    label={field.label}
                                    type={field.type}
                                    defaultValue={record[field.name] ?? ''}
                                    readOnly={!editing}
                                    required={field.required}
                                    {...('max' in field
                                        ? {
                                              min: field.step,
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
                        <Field>
                            <FieldLabel htmlFor="classification-active">
                                Aktif
                            </FieldLabel>
                            <Switch
                                id="classification-active"
                                checked={active}
                                onCheckedChange={(value) =>
                                    editing && setActive(value)
                                }
                            />
                        </Field>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Perubahan di sini tidak otomatis mengubah profil
                        penyusutan yang sudah dibuat. Periksa profil yang
                        digunakan sebelum menghitung penyusutan.
                    </p>
                </CardContent>
            </Card>
        </form>
    );
}
