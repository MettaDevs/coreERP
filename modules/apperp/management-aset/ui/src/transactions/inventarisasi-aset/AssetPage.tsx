import { useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import {
    DataTable,
    type DataTableColumn,
    type DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import DynamicField from '../../master/DynamicField';
import {
    FieldConfig,
    FieldValue,
    emptyValue,
    payloadValue,
} from '../../master/fields';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { AttributeDefinition, toFieldConfig } from './attributes';

type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    user_id: string | number | null;
};
type Asset = {
    id: string;
    kode: string;
    nama: string;
    serial_number: string | null;
    acquisition_value: string;
    currency_code: string;
    lifecycle_state: string;
    group_aset_id?: string | null;
    jenis_aset_id?: string | null;
    asset_location_id?: string | null;
};
type AssetDetail = Asset &
    Record<string, unknown> & {
        atribut: { tipe_atribut_id: string; nama: string; nilai: FieldValue }[];
    };
type Placement = {
    id: string;
    effective_on: string;
    reason: string | null;
    receiving_org_unit_id: string | null;
    usage_org_unit_id: string | null;
    received_by_user_id: string | null;
    custodian_user_id: string | null;
    asset_location_id: string | null;
};

/** Satu baris matriks group x buku, apa adanya seperti yang dikirim server. */
type MatrixRow = {
    buku_id: string;
    depreciation_profile_id: string | null;
    useful_life_periods: number | null;
    convention: string | null;
    depreciate: boolean | number;
};

/**
 * Kolom penunjuk master pada penerimaan aset.
 *
 * Group dan jenis adalah dua sumbu yang sejajar dan sama-sama wajib: group membawa
 * perlakuan finansial, jenis membawa perlakuan teknis. Tidak ada yang menyaring yang
 * lain, jadi keduanya dirender berdampingan tanpa urutan pengisian.
 */
const CLASSIFICATION: FieldConfig[] = [
    {
        name: 'group_aset_id',
        label: 'Group aset',
        type: 'reference',
        resource: 'group-aset',
        required: true,
        help: 'Menentukan buku penyusutan mana yang dibuat untuk aset ini.',
    },
    {
        name: 'jenis_aset_id',
        label: 'Jenis aset',
        type: 'reference',
        resource: 'jenis-aset',
        required: true,
        help: 'Menentukan atribut tambahan yang harus diisi.',
    },
    {
        name: 'kondisi_aset_id',
        label: 'Kondisi aset',
        type: 'reference',
        resource: 'kondisi-aset',
    },
];

/**
 * Pabrikan dan model dipisahkan dari klasifikasi.
 *
 * Keduanya menjelaskan unit fisiknya, bukan perlakuannya, dan sering dibiarkan kosong.
 * Halaman All assets di Dynamics 365 memisahkannya dengan alasan yang sama.
 */
const MANUFACTURER: FieldConfig[] = [
    {
        name: 'pabrikan_aset_id',
        label: 'Pabrikan',
        type: 'reference',
        resource: 'pabrikan-aset',
    },
    {
        name: 'model_aset_id',
        label: 'Model aset',
        type: 'reference',
        resource: 'model-aset',
        help: 'Katalog model per pabrikan. Kosongkan bila modelnya belum terdaftar.',
    },
];

const PLACEMENT: FieldConfig[] = [
    {
        name: 'asset_location_id',
        label: 'Lokasi aset',
        type: 'reference',
        resource: 'lokasi-aset',
        help: 'Lokasi yang dipetakan ke unit organisasi menentukan dimensi keuangan aset.',
    },
];

const REFERENCES = [...CLASSIFICATION, ...MANUFACTURER, ...PLACEMENT];

/**
 * Yang boleh dikoreksi setelah aset diterima.
 *
 * Group aset tidak ada di sini: buku penyusutan sudah dibentuk dari matriksnya, jadi
 * menggantinya akan membuat buku yang berjalan tidak lagi cocok dengan groupnya.
 */
const EDITABLE = REFERENCES.filter(
    (field) =>
        field.name !== 'group_aset_id' && field.name !== 'asset_location_id',
);

/** Nilai awal seluruh isian teks pada form penerimaan. */
const textDefaults = (context: Context): Record<string, string> => ({
    nama: '',
    serial_number: '',
    model_number: '',
    acquired_on: '',
    placed_in_service_on: '',
    acquisition_value: '',
    residual_value: '',
    currency_code: 'IDR',
    receiving_org_unit_id: context.org_unit_id ?? '',
    usage_org_unit_id: context.org_unit_id ?? '',
    received_by_user_id:
        context.user_id === null ? '' : String(context.user_id),
    custodian_user_id: '',
    keterangan: '',
});

/**
 * Nilai uang dibaca manusia, bukan mesin.
 *
 * Server mengirim desimal sebagai string supaya presisinya tidak hilang di float, dan
 * bentuk mentahnya — `8500000000.00` — praktis tidak terbaca pada kolom yang rata kanan.
 * Pemisah ribuan memakai locale Indonesia; nol di belakang koma dibuang karena mayoritas
 * nilai perolehan bulat dan `,00` hanya menambah panjang tanpa menambah arti.
 */
function money(value: string, currency: string): string {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return `${currency} ${value}`;
    const formatted = new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(amount);

    return `${currency} ${formatted}`;
}

const LIFECYCLE: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    received: { label: 'Diterima', variant: 'outline' },
    in_use: { label: 'Digunakan', variant: 'default' },
    decommissioned: { label: 'Didekomisioning', variant: 'secondary' },
    disposed: { label: 'Dilepas', variant: 'secondary' },
};

const CONVENTION_LABEL: Record<string, string> = {
    full_month: 'bulan perolehan penuh',
    mid_month_1st: 'tengah bulan (awal bulan)',
    mid_month_15th: 'tengah bulan (tanggal 15)',
    mid_quarter: 'tengah kuartal',
    half_year: 'setengah tahun',
    half_year_start_of_year: 'setengah tahun (mulai awal tahun)',
    half_year_next_year: 'setengah tahun (mulai tahun depan)',
};

/**
 * Ringkasan buku penyusutan yang akan terbentuk, dibaca dari matriks group x buku.
 *
 * Sengaja hanya menampilkan, tidak meminta apa pun. Buku aset tidak pernah diisi tangan:
 * ia lahir dari matriks saat aset diterima. Yang perlu dilihat petugas hanyalah akibat
 * dari group yang barusan dipilihnya, sebelum ia menyimpan.
 *
 * Kegagalan memuat tidak ditampilkan sebagai kesalahan. Pengguna boleh memiliki akses
 * mencatat aset tanpa akses melihat konfigurasi group, dan itu tidak boleh membuat
 * bagian ini berteriak tentang sesuatu yang bukan urusannya.
 */
function useGroupBooks(groupId: string) {
    const [rows, setRows] = useState<MatrixRow[] | null>(null);

    useEffect(() => {
        if (!groupId) {
            setRows(null);
            return;
        }
        let cancelled = false;
        api<{ data: MatrixRow[] }>(`/group-aset/${groupId}/buku-penyusutan`)
            .then((result) => {
                if (!cancelled) setRows(result.data);
            })
            .catch(() => {
                if (!cancelled) setRows(null);
            });
        return () => {
            cancelled = true;
        };
    }, [groupId]);

    return rows;
}

function DepreciationPreview({ groupId }: { groupId: string }) {
    const rows = useGroupBooks(groupId);
    const { options: books } = useMasterOptions(
        groupId ? 'buku-penyusutan' : null,
    );
    const { options: profiles } = useMasterOptions(
        groupId ? 'profil-penyusutan' : null,
    );

    if (!groupId) {
        return (
            <p className="text-muted-foreground text-sm">
                Pilih group aset terlebih dahulu untuk melihat buku yang akan
                terbentuk.
            </p>
        );
    }
    if (rows === null) {
        return (
            <p className="text-muted-foreground text-sm">
                Konfigurasi buku group ini belum dapat dibaca.
            </p>
        );
    }
    if (rows.length === 0) {
        return (
            <p className="text-destructive text-sm">
                Group ini belum memiliki baris pada matriks group x buku. Aset
                masih dapat dicatat, tetapi belum dapat ditempatkan sampai
                matriksnya diisi.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {rows.map((row) => {
                const book = books.find((option) => option.id === row.buku_id);
                const profile = profiles.find(
                    (option) => option.id === row.depreciation_profile_id,
                );
                const life =
                    row.useful_life_periods ??
                    (profile?.useful_life_periods as number | undefined);
                const convention = row.convention
                    ? (CONVENTION_LABEL[row.convention] ?? row.convention)
                    : null;
                const detail = row.depreciate
                    ? [
                          profile?.nama,
                          life ? `${life} periode` : null,
                          convention,
                      ]
                          .filter(Boolean)
                          .join(' · ')
                    : 'Tidak disusutkan';

                return (
                    <div
                        key={row.buku_id}
                        className="flex items-baseline justify-between gap-3 rounded-md border px-3 py-2"
                    >
                        <span className="text-sm font-medium">
                            {book ? book.nama : 'Buku penyusutan'}
                        </span>
                        <span className="text-muted-foreground truncate text-sm">
                            {detail || 'Aturan diambil dari buku'}
                        </span>
                    </div>
                );
            })}
            <FieldDescription>
                Buku dibentuk otomatis saat aset disimpan. Aturannya disalin,
                jadi perubahan matriks kelak tidak mengubah aset ini.
            </FieldDescription>
        </div>
    );
}

export default function AssetPage({
    context,
    canUpdate,
}: {
    context: Context;
    canUpdate: boolean;
}) {
    const [assets, setAssets] = useState<Asset[]>([]);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [search, setSearch] = useState('');
    const [history, setHistory] = useState<{
        asset: Asset;
        placements: Placement[];
    } | null>(null);
    const [editing, setEditing] = useState<AssetDetail | null>(null);
    const [references, setReferences] = useState<Record<string, FieldValue>>(
        () => Object.fromEntries(REFERENCES.map((field) => [field.name, ''])),
    );
    // Seluruh isian dikendalikan state, bukan dibaca dari FormData saat submit. Bagian
    // yang terlipat dilepas dari DOM oleh accordion, sehingga isian tak terkendali akan
    // hilang begitu penggunanya menutup bagiannya.
    const [values, setValues] = useState<Record<string, string>>(() =>
        textDefaults(context),
    );
    const [parentAssetId, setParentAssetId] = useState('');
    // Atribut diwarisi dari jenis aset, jadi definisinya dibaca ulang tiap jenis berubah.
    const [attributes, setAttributes] = useState<AttributeDefinition[]>([]);
    const [attributeValues, setAttributeValues] = useState<
        Record<string, FieldValue>
    >({});
    const sheetContentRef = useRef<HTMLDivElement>(null);
    const editSheetRef = useRef<HTMLDivElement>(null);
    const typeId = String(references.jenis_aset_id ?? '');
    const groupId = String(references.group_aset_id ?? '');

    const { options: groupOptions } = useMasterOptions('group-aset');
    const { options: typeOptions } = useMasterOptions('jenis-aset');
    const { options: locationOptions } = useMasterOptions('lokasi-aset');

    const load = () =>
        api<{ data: Asset[] }>('/aset')
            .then((result) => setAssets(result.data))
            .catch((caught) =>
                setError(
                    errorMessage(caught, 'Register aset belum dapat dimuat.'),
                ),
            );

    useEffect(() => {
        void load();
    }, []);

    useEffect(() => {
        if (!typeId) {
            setAttributes([]);
            setAttributeValues({});
            return;
        }
        let cancelled = false;
        api<{ data: AttributeDefinition[] }>(
            `/jenis-aset/${typeId}/atribut-definisi`,
        )
            .then((result) => {
                if (cancelled) return;
                setAttributes(result.data);
                // Nilai yang sudah ada dipertahankan, bukan ditimpa kosong. Saat mengoreksi
                // aset, definisinya baru selesai dimuat setelah nilainya dipasang; menimpa
                // di sini akan menghapus isian yang barusan dibaca dari server.
                setAttributeValues((current) =>
                    Object.fromEntries(
                        result.data.map((definition) => [
                            definition.tipe_atribut_id,
                            current[definition.tipe_atribut_id] ??
                                emptyValue(toFieldConfig(definition)),
                        ]),
                    ),
                );
            })
            .catch(() => {
                if (!cancelled) setAttributes([]);
            });
        return () => {
            cancelled = true;
        };
    }, [typeId]);

    const nameOf = (options: { id: string; nama: string }[], id: unknown) =>
        options.find((option) => option.id === String(id ?? ''))?.nama ?? null;

    const parentOptions = useMemo(
        () =>
            assets.map((asset) => ({
                id: asset.id,
                label: `${asset.kode} — ${asset.nama}`,
            })),
        [assets],
    );

    const visible = useMemo(() => {
        const query = search.trim().toLowerCase();
        if (!query) return assets;
        return assets.filter(
            (asset) =>
                asset.kode.toLowerCase().includes(query) ||
                asset.nama.toLowerCase().includes(query) ||
                (asset.serial_number ?? '').toLowerCase().includes(query),
        );
    }, [assets, search]);

    function resetForm() {
        setReferences(
            Object.fromEntries(REFERENCES.map((field) => [field.name, ''])),
        );
        setValues(textDefaults(context));
        setParentAssetId('');
        setAttributeValues({});
    }

    const setValue = (name: string, next: string) =>
        setValues((current) => ({ ...current, [name]: next }));

    /**
     * Membuka koreksi satu aset. Nilainya dibaca dari detail, bukan dari daftar: daftar
     * hanya membawa ringkasan, sedangkan yang perlu disunting termasuk atribut.
     */
    async function edit(asset: Asset) {
        try {
            const detail = (
                await api<{ data: AssetDetail }>(`/aset/${asset.id}`)
            ).data;
            setReferences(
                Object.fromEntries(
                    REFERENCES.map((field) => [
                        field.name,
                        String(detail[field.name] ?? ''),
                    ]),
                ),
            );
            setValues({
                ...textDefaults(context),
                nama: detail.nama,
                serial_number: String(detail.serial_number ?? ''),
                model_number: String(detail.model_number ?? ''),
                placed_in_service_on: String(
                    detail.placed_in_service_on ?? '',
                ).slice(0, 10),
                keterangan: String(detail.keterangan ?? ''),
            });
            setParentAssetId(String(detail.parent_asset_id ?? ''));
            setAttributeValues(
                Object.fromEntries(
                    detail.atribut.map((row) => [
                        row.tipe_atribut_id,
                        row.nilai ?? '',
                    ]),
                ),
            );
            setEditing(detail);
        } catch (caught) {
            setError(errorMessage(caught, 'Aset belum dapat dibuka.'));
        }
    }

    const attributePayload = () =>
        attributes.map((definition) => ({
            tipe_atribut_id: definition.tipe_atribut_id,
            nilai: payloadValue(
                toFieldConfig(definition),
                attributeValues[definition.tipe_atribut_id],
            ),
        }));

    async function saveEdit() {
        if (!editing) return;
        setSaving(true);
        setError('');
        try {
            await api(`/aset/${editing.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...Object.fromEntries(
                        EDITABLE.map((field) => [
                            field.name,
                            payloadValue(field, references[field.name]),
                        ]),
                    ),
                    nama: values.nama,
                    parent_asset_id: parentAssetId || null,
                    serial_number: values.serial_number || null,
                    model_number: values.model_number || null,
                    placed_in_service_on: values.placed_in_service_on || null,
                    keterangan: values.keterangan || null,
                    atribut: attributePayload(),
                }),
            });
            setEditing(null);
            resetForm();
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Koreksi belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    async function showHistory(asset: Asset) {
        try {
            setHistory(
                (
                    await api<{
                        data: { asset: Asset; placements: Placement[] };
                    }>(`/aset/${asset.id}/history`)
                ).data,
            );
        } catch (caught) {
            setError(errorMessage(caught, 'Riwayat aset belum dapat dimuat.'));
        }
    }

    async function receive() {
        if (!context.legal_entity_id) {
            setError(
                'Pilih entitas legal aktif di CoreERP sebelum menerima aset.',
            );
            return;
        }
        const missing = REFERENCES.find(
            (field) => field.required && !references[field.name],
        );
        if (missing) {
            setError(`Pilih ${missing.label.toLowerCase()} terlebih dahulu.`);
            return;
        }
        if (!values.nama || !values.acquired_on || !values.acquisition_value) {
            setError(
                'Nama aset, tanggal perolehan, dan nilai perolehan wajib diisi.',
            );
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api('/aset', {
                method: 'POST',
                headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: JSON.stringify({
                    legal_entity_id: context.legal_entity_id,
                    ...Object.fromEntries(
                        REFERENCES.map((field) => [
                            field.name,
                            payloadValue(field, references[field.name]),
                        ]),
                    ),
                    parent_asset_id: parentAssetId || null,
                    nama: values.nama,
                    acquired_on: values.acquired_on,
                    // Penyusutan dihitung dari tanggal aset mulai digunakan, bukan tanggal
                    // perolehan. Dikosongkan berarti keduanya dianggap sama.
                    placed_in_service_on: values.placed_in_service_on || null,
                    acquisition_value: values.acquisition_value,
                    currency_code: values.currency_code,
                    serial_number: values.serial_number || null,
                    model_number: values.model_number || null,
                    receiving_org_unit_id:
                        values.receiving_org_unit_id || context.org_unit_id,
                    usage_org_unit_id:
                        values.usage_org_unit_id || context.org_unit_id,
                    received_by_user_id:
                        values.received_by_user_id ||
                        (context.user_id === null
                            ? null
                            : String(context.user_id)),
                    custodian_user_id: values.custodian_user_id || null,
                    residual_value: values.residual_value || null,
                    keterangan: values.keterangan || null,
                    atribut: attributePayload(),
                }),
            });
            setOpen(false);
            resetForm();
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Aset belum dapat diterima.'));
        } finally {
            setSaving(false);
        }
    }

    const referenceField = (
        field: FieldConfig,
        portal: typeof sheetContentRef,
    ) => (
        <DynamicField
            key={field.name}
            config={field}
            value={references[field.name]}
            onChange={(next) =>
                setReferences((current) => ({ ...current, [field.name]: next }))
            }
            portalContainer={portal}
        />
    );

    const textField = (
        name: string,
        label: string,
        extra: Record<string, unknown> = {},
    ) => (
        <Field>
            <Input
                label={label}
                value={values[name] ?? ''}
                onChange={(event) => setValue(name, event.target.value)}
                {...extra}
            />
        </Field>
    );

    const attributeFields = (portal: typeof sheetContentRef) =>
        attributes.map((definition) => (
            <DynamicField
                key={definition.tipe_atribut_id}
                config={toFieldConfig(definition)}
                value={attributeValues[definition.tipe_atribut_id]}
                onChange={(next) =>
                    setAttributeValues((current) => ({
                        ...current,
                        [definition.tipe_atribut_id]: next,
                    }))
                }
                portalContainer={portal}
            />
        ));

    const parentField = (portal: typeof sheetContentRef) => (
        <Field>
            <Select
                label="Aset induk"
                items={parentOptions.map((option) => option.label)}
                value={
                    parentOptions.find((option) => option.id === parentAssetId)
                        ?.label
                }
                placeholder="Tanpa induk"
                searchPlaceholder="Cari aset induk"
                emptyMessage="Aset tidak ditemukan."
                ariaLabel="Pilih aset induk"
                portalContainer={portal}
                onValueChange={(item) =>
                    setParentAssetId(
                        parentOptions.find((option) => option.label === item)
                            ?.id ?? '',
                    )
                }
            />
            <FieldDescription>
                Isi bila aset ini bagian dari aset lain, misalnya mesin yang
                terpasang pada satu gedung.
            </FieldDescription>
        </Field>
    );

    const columns: DataTableColumn<Asset>[] = [
        {
            id: 'kode',
            header: 'Kode aset',
            cell: (asset) => <span className="code">{asset.kode}</span>,
            sortValue: (asset) => asset.kode,
            width: 160,
        },
        {
            id: 'nama',
            header: 'Nama aset',
            cell: (asset) => asset.nama,
            sortValue: (asset) => asset.nama,
            width: 240,
        },
        {
            id: 'serial',
            header: 'Nomor seri',
            cell: (asset) => (
                <span className="muted">{asset.serial_number || '—'}</span>
            ),
            width: 160,
        },
        {
            id: 'group',
            header: 'Group aset',
            cell: (asset) => (
                <span className="muted">
                    {nameOf(groupOptions, asset.group_aset_id) ?? '—'}
                </span>
            ),
            width: 180,
        },
        {
            id: 'jenis',
            header: 'Jenis aset',
            cell: (asset) => (
                <span className="muted">
                    {nameOf(typeOptions, asset.jenis_aset_id) ?? '—'}
                </span>
            ),
            width: 180,
        },
        {
            id: 'lokasi',
            header: 'Lokasi',
            cell: (asset) => (
                <span className="muted">
                    {nameOf(locationOptions, asset.asset_location_id) ?? '—'}
                </span>
            ),
            width: 180,
        },
        {
            id: 'nilai',
            header: 'Nilai perolehan',
            cell: (asset) =>
                money(asset.acquisition_value, asset.currency_code),
            sortValue: (asset) => Number(asset.acquisition_value),
            align: 'right',
            width: 170,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (asset) => {
                const state = LIFECYCLE[asset.lifecycle_state] ?? {
                    label: asset.lifecycle_state,
                    variant: 'secondary' as const,
                };
                return <Badge variant={state.variant}>{state.label}</Badge>;
            },
            width: 140,
        },
    ];

    const rowActions: DataTableRowAction[] = [
        { id: 'history', label: 'Riwayat' },
    ];
    if (canUpdate) rowActions.unshift({ id: 'edit', label: 'Ubah' });

    const summaryOf = (options: { id: string; nama: string }[], id: unknown) =>
        nameOf(options, id) ?? undefined;

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="min-h-0 border-b px-5 py-3">
                <CardTitle className="text-base">Inventarisasi aset</CardTitle>
                <CardAction>
                    <Button onClick={() => setOpen(true)}>
                        ＋ Terima aset
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="px-0">
                {error && (
                    <div className="text-destructive px-5 py-3 text-sm">
                        {error}
                    </div>
                )}
                <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-1">
                        <p className="font-semibold">Register aset</p>
                        <p className="text-muted-foreground text-sm">
                            {visible.length} aset ditampilkan
                        </p>
                    </div>
                    <Input
                        className="sm:w-70 w-full"
                        type="search"
                        placeholder="Cari kode, nama, atau nomor seri"
                        aria-label="Cari aset"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                {!visible.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                {assets.length
                                    ? 'Tidak ada aset yang cocok'
                                    : 'Belum ada aset'}
                            </EmptyTitle>
                            <EmptyDescription>
                                {assets.length
                                    ? 'Ubah kata kunci pencarian untuk menemukan aset lain.'
                                    : 'Catat penerimaan aset pertama untuk mulai memantau lokasi, pengguna, dan penyusutannya.'}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        data={visible}
                        getRowKey={(asset) => asset.id}
                        getRowLabel={(asset) => asset.kode}
                        actions={rowActions}
                        onRowAction={(action, asset) => {
                            if (
                                action === 'edit' &&
                                asset.lifecycle_state !== 'disposed'
                            )
                                void edit(asset);
                            if (action === 'history') void showHistory(asset);
                        }}
                    />
                )}
            </CardContent>

            <Sheet
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (!next) resetForm();
                }}
            >
                <SheetContent
                    ref={sheetContentRef}
                    side="right"
                    className="w-full sm:max-w-2xl"
                >
                    <SheetHeader>
                        <SheetTitle>Terima aset</SheetTitle>
                    </SheetHeader>
                    <form
                        className="space-y-4 overflow-y-auto p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void receive();
                        }}
                    >
                        <CollapsibleSectionGroup
                            defaultValue={['klasifikasi', 'perolehan']}
                        >
                            <CollapsibleSection
                                value="klasifikasi"
                                title="Identitas dan klasifikasi"
                                summary={summaryOf(
                                    groupOptions,
                                    references.group_aset_id,
                                )}
                            >
                                <div className="space-y-4">
                                    {textField('nama', 'Nama aset', {
                                        maxLength: 150,
                                        required: true,
                                    })}
                                    {CLASSIFICATION.map((field) =>
                                        referenceField(field, sheetContentRef),
                                    )}
                                </div>
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="pabrikan"
                                title="Pabrikan dan unit"
                                summary={values.serial_number || undefined}
                            >
                                <div className="space-y-4">
                                    {MANUFACTURER.map((field) =>
                                        referenceField(field, sheetContentRef),
                                    )}
                                    {textField('serial_number', 'Nomor seri')}
                                    {textField('model_number', 'Nomor model')}
                                </div>
                            </CollapsibleSection>

                            {attributes.length > 0 && (
                                <CollapsibleSection
                                    value="atribut"
                                    title="Atribut jenis aset"
                                    summary={`${attributes.length} atribut`}
                                >
                                    <div className="space-y-4">
                                        {attributeFields(sheetContentRef)}
                                    </div>
                                </CollapsibleSection>
                            )}

                            <CollapsibleSection
                                value="perolehan"
                                title="Perolehan dan nilai"
                                summary={
                                    values.acquisition_value
                                        ? money(
                                              values.acquisition_value,
                                              values.currency_code,
                                          )
                                        : undefined
                                }
                            >
                                <div className="space-y-4">
                                    {textField(
                                        'acquired_on',
                                        'Tanggal perolehan',
                                        {
                                            type: 'date',
                                            required: true,
                                        },
                                    )}
                                    <Field>
                                        <Input
                                            label="Tanggal mulai digunakan"
                                            type="date"
                                            value={values.placed_in_service_on}
                                            onChange={(event) =>
                                                setValue(
                                                    'placed_in_service_on',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <FieldDescription>
                                            Dasar perhitungan awal penyusutan.
                                            Kosong berarti sama dengan tanggal
                                            perolehan.
                                        </FieldDescription>
                                    </Field>
                                    {textField(
                                        'acquisition_value',
                                        'Nilai perolehan',
                                        {
                                            type: 'number',
                                            min: '0',
                                            step: '0.01',
                                            required: true,
                                        },
                                    )}
                                    {textField(
                                        'residual_value',
                                        'Nilai residu',
                                        {
                                            type: 'number',
                                            min: '0',
                                            step: '0.01',
                                        },
                                    )}
                                    {textField('currency_code', 'Mata uang', {
                                        maxLength: 3,
                                        required: true,
                                    })}
                                </div>
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="penyusutan"
                                title="Penyusutan"
                            >
                                <DepreciationPreview groupId={groupId} />
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="penempatan"
                                title="Penempatan"
                                summary={summaryOf(
                                    locationOptions,
                                    references.asset_location_id,
                                )}
                            >
                                <div className="space-y-4">
                                    {PLACEMENT.map((field) =>
                                        referenceField(field, sheetContentRef),
                                    )}
                                    {textField(
                                        'receiving_org_unit_id',
                                        'ID unit penerima',
                                    )}
                                    {textField(
                                        'usage_org_unit_id',
                                        'ID unit pengguna',
                                    )}
                                    {textField(
                                        'received_by_user_id',
                                        'ID penerima',
                                    )}
                                    {textField(
                                        'custodian_user_id',
                                        'ID PIC aset',
                                    )}
                                </div>
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="struktur"
                                title="Struktur"
                                summary={
                                    parentOptions.find(
                                        (option) => option.id === parentAssetId,
                                    )?.label
                                }
                            >
                                {parentField(sheetContentRef)}
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="catatan"
                                title="Keterangan"
                            >
                                <Field>
                                    <Textarea
                                        rows={3}
                                        maxLength={2000}
                                        placeholder="Keterangan"
                                        value={values.keterangan}
                                        onChange={(event) =>
                                            setValue(
                                                'keterangan',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </CollapsibleSection>
                        </CollapsibleSectionGroup>

                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Menyimpan…' : 'Simpan penerimaan'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <Sheet
                open={editing !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setEditing(null);
                        resetForm();
                    }
                }}
            >
                <SheetContent
                    ref={editSheetRef}
                    side="right"
                    className="w-full sm:max-w-2xl"
                >
                    <SheetHeader>
                        <SheetTitle>Koreksi aset {editing?.kode}</SheetTitle>
                    </SheetHeader>
                    <form
                        className="space-y-4 overflow-y-auto p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void saveEdit();
                        }}
                    >
                        <p className="text-muted-foreground text-sm">
                            Group aset dan lokasi tidak dapat diganti di sini.
                            Buku penyusutannya sudah terbentuk dari matriks
                            group, dan perpindahan lokasi dicatat sebagai
                            penempatan, bukan koreksi.
                        </p>
                        <CollapsibleSectionGroup defaultValue={['klasifikasi']}>
                            <CollapsibleSection
                                value="klasifikasi"
                                title="Identitas dan klasifikasi"
                            >
                                <div className="space-y-4">
                                    {textField('nama', 'Nama aset', {
                                        maxLength: 150,
                                        required: true,
                                    })}
                                    {EDITABLE.filter((field) =>
                                        CLASSIFICATION.some(
                                            (item) => item.name === field.name,
                                        ),
                                    ).map((field) =>
                                        referenceField(field, editSheetRef),
                                    )}
                                </div>
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="pabrikan"
                                title="Pabrikan dan unit"
                                summary={values.serial_number || undefined}
                            >
                                <div className="space-y-4">
                                    {EDITABLE.filter((field) =>
                                        MANUFACTURER.some(
                                            (item) => item.name === field.name,
                                        ),
                                    ).map((field) =>
                                        referenceField(field, editSheetRef),
                                    )}
                                    {textField('serial_number', 'Nomor seri')}
                                    {textField('model_number', 'Nomor model')}
                                </div>
                            </CollapsibleSection>

                            {attributes.length > 0 && (
                                <CollapsibleSection
                                    value="atribut"
                                    title="Atribut jenis aset"
                                    summary={`${attributes.length} atribut`}
                                >
                                    <div className="space-y-4">
                                        {attributeFields(editSheetRef)}
                                    </div>
                                </CollapsibleSection>
                            )}

                            <CollapsibleSection
                                value="perolehan"
                                title="Perolehan dan nilai"
                            >
                                <Field>
                                    <Input
                                        label="Tanggal mulai digunakan"
                                        type="date"
                                        value={values.placed_in_service_on}
                                        onChange={(event) =>
                                            setValue(
                                                'placed_in_service_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <FieldDescription>
                                        Menggeser awal penyusutan selama buku
                                        aset belum punya periode berjalan.
                                    </FieldDescription>
                                </Field>
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="struktur"
                                title="Struktur"
                                summary={
                                    parentOptions.find(
                                        (option) => option.id === parentAssetId,
                                    )?.label
                                }
                            >
                                {parentField(editSheetRef)}
                            </CollapsibleSection>

                            <CollapsibleSection
                                value="catatan"
                                title="Keterangan"
                            >
                                <Field>
                                    <Textarea
                                        rows={3}
                                        maxLength={2000}
                                        placeholder="Keterangan"
                                        value={values.keterangan}
                                        onChange={(event) =>
                                            setValue(
                                                'keterangan',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </CollapsibleSection>
                        </CollapsibleSectionGroup>

                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setEditing(null);
                                    resetForm();
                                }}
                            >
                                Batal
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Menyimpan…' : 'Simpan koreksi'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <Sheet
                open={history !== null}
                onOpenChange={(next) => {
                    if (!next) setHistory(null);
                }}
            >
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>
                            Riwayat aset {history?.asset.kode}
                        </SheetTitle>
                    </SheetHeader>
                    <div className="space-y-3 overflow-y-auto p-4">
                        {history?.placements.map((placement) => (
                            <div
                                className="rounded border p-3"
                                key={placement.id}
                            >
                                <p className="font-medium">
                                    {placement.effective_on}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {placement.reason || 'Penempatan aset'}
                                </p>
                                <p className="text-sm">
                                    Unit pengguna:{' '}
                                    {placement.usage_org_unit_id ||
                                        'Belum dipilih'}
                                </p>
                                <p className="text-sm">
                                    PIC:{' '}
                                    {placement.custodian_user_id ||
                                        'Belum dipilih'}
                                </p>
                                <p className="text-sm">
                                    Lokasi:{' '}
                                    {nameOf(
                                        locationOptions,
                                        placement.asset_location_id,
                                    ) ??
                                        placement.asset_location_id ??
                                        'Belum dipilih'}
                                </p>
                            </div>
                        ))}
                    </div>
                </SheetContent>
            </Sheet>
        </Card>
    );
}
