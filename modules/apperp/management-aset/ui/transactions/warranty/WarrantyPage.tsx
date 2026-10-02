import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useToday } from '@/hooks/use-work-date';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { Textarea } from '@apperp/ui/textarea';
import { day, money } from '../../_shared/format';
import { useVendors } from '../../_shared/useVendors';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import type { MasterOption } from '../../master/useMasterOptions';
import { optionLabel } from '../../master/useMasterOptions';
import type { Contract, Expiring, Warranty } from './warranty';
import { WARRANTY_TYPES } from './warranty';

type WarrantyDraft = {
    id: string | null;
    version: number;
    aset_id: string;
    legal_entity_id: string | null;
    vendor_id: string;
    jenis_garansi: string;
    nomor_referensi: string;
    berlaku_mulai: string;
    berlaku_sampai: string;
    catatan: string;
};

type ContractDraft = {
    id: string | null;
    version: number;
    legal_entity_id: string | null;
    nomor_kontrak: string;
    vendor_id: string;
    berlaku_mulai: string;
    berlaku_sampai: string;
    cakupan: string;
    nilai_kontrak: string;
    keterangan: string;
    aset_ids: string[];
};

const WINDOWS = [
    { value: '30', label: '30 hari' },
    { value: '60', label: '60 hari' },
    { value: '90', label: '90 hari' },
];

/** Sisa hari sebagai teks, dengan penanda untuk yang sudah atau hampir habis. */
function remaining(row: { berlaku: boolean; sisa_hari: number }) {
    if (!row.berlaku && row.sisa_hari === 0) {
        return <Badge variant="secondary">Tidak berlaku</Badge>;
    }

    return row.sisa_hari <= 30 ? (
        <Badge variant="destructive">{row.sisa_hari} hari lagi</Badge>
    ) : (
        `${row.sisa_hari} hari lagi`
    );
}

/**
 * Garansi aset dan kontrak servis vendor; padanan *Vendor warranty* pada aset Dynamics 365 F&O.
 *
 * Garansi menempel pada satu aset, kontrak servis menanggung banyak aset dengan satu vendor dan satu
 * periode. Tab ketiga mengumpulkan keduanya yang akan berakhir dalam 30, 60, atau 90 hari.
 */
export default function WarrantyPage({
    context,
    permissions,
}: {
    context: { legal_entity_id: string | null };
    permissions: string[];
}) {
    const can = (permission: string) =>
        permissions.includes(`management-aset.${permission}`);
    const today = useToday();
    const [tab, setTab] = useState(
        can('garansi-aset.read') ? 'garansi' : 'kontrak',
    );
    const [warranties, setWarranties] = useState<Warranty[] | null>(null);
    const [contracts, setContracts] = useState<Contract[] | null>(null);
    const [expiring, setExpiring] = useState<{
        days: string;
        items: Expiring[];
    } | null>(null);
    const [days, setDays] = useState('30');
    const [reload, setReload] = useState(0);
    const [assets, setAssets] = useState<MasterOption[]>([]);
    const [warrantyDraft, setWarrantyDraft] = useState<WarrantyDraft | null>(
        null,
    );
    const [contractDraft, setContractDraft] = useState<ContractDraft | null>(
        null,
    );
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const warrantyRef = useRef<HTMLDivElement>(null);
    const contractRef = useRef<HTMLDivElement>(null);
    const warrantyVendors = useVendors(
        '/garansi-aset/vendor',
        warrantyDraft?.legal_entity_id ?? null,
    );
    const contractVendors = useVendors(
        '/garansi-aset/vendor',
        contractDraft?.legal_entity_id ?? null,
    );
    const message = (field: string) => errors[field]?.[0];
    const refresh = () => setReload((current) => current + 1);

    useEffect(() => {
        let cancelled = false;
        const fail = (caught: unknown) =>
            !cancelled &&
            toast.error(errorMessage(caught, 'Data belum dapat dimuat.'));

        if (permissions.includes('management-aset.garansi-aset.read')) {
            api<{ data: Warranty[] }>('/garansi-aset')
                .then((result) => !cancelled && setWarranties(result.data))
                .catch(fail);
        }

        if (permissions.includes('management-aset.kontrak-servis.read')) {
            api<{ data: Contract[] }>('/kontrak-servis')
                .then((result) => !cancelled && setContracts(result.data))
                .catch(fail);
        }

        return () => {
            cancelled = true;
        };
    }, [permissions, reload]);

    useEffect(() => {
        let cancelled = false;
        api<{ data: Expiring[] }>(`/garansi-aset/akan-berakhir?hari=${days}`)
            .then(
                (result) =>
                    !cancelled && setExpiring({ days, items: result.data }),
            )
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(caught, 'Daftar belum dapat dimuat.'),
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, [days, reload]);

    // Pemilih aset dibutuhkan kedua form; dimuat sekali saat salah satunya dibuka.
    const needAssets = warrantyDraft !== null || contractDraft !== null;
    useEffect(() => {
        if (!needAssets) {
            return;
        }

        let cancelled = false;
        api<{ data: MasterOption[] }>('/aset')
            .then((result) => !cancelled && setAssets(result.data))
            .catch(
                () =>
                    !cancelled &&
                    toast.error(
                        'Daftar aset belum dapat dimuat. Anda memerlukan akses lihat register aset.',
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, [needAssets]);

    const assetChoices = assets.map((asset) => ({
        value: asset.id,
        label: optionLabel(asset),
    }));
    const legalEntityOf = (asetId: string) => {
        const asset = assets.find((item) => item.id === asetId);

        return typeof asset?.legal_entity_id === 'string'
            ? asset.legal_entity_id
            : context.legal_entity_id;
    };

    async function saveWarranty() {
        if (!warrantyDraft) {
            return;
        }

        const draft = warrantyDraft;
        setBusy(true);
        setErrors({});

        try {
            const body = {
                vendor_id: draft.vendor_id || null,
                jenis_garansi: draft.jenis_garansi,
                nomor_referensi: draft.nomor_referensi || null,
                berlaku_mulai: draft.berlaku_mulai,
                berlaku_sampai: draft.berlaku_sampai,
                catatan: draft.catatan || null,
            };
            await (draft.id
                ? api(`/garansi-aset/${draft.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ ...body, version: draft.version }),
                  })
                : api('/garansi-aset', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify({ ...body, aset_id: draft.aset_id }),
                  }));
            toast.success('Garansi disimpan.');
            setWarrantyDraft(null);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Garansi belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    }

    async function saveContract() {
        if (!contractDraft) {
            return;
        }

        const draft = contractDraft;
        setBusy(true);
        setErrors({});

        try {
            const body = {
                legal_entity_id: draft.legal_entity_id,
                nomor_kontrak: draft.nomor_kontrak,
                vendor_id: draft.vendor_id,
                berlaku_mulai: draft.berlaku_mulai,
                berlaku_sampai: draft.berlaku_sampai,
                cakupan: draft.cakupan || null,
                nilai_kontrak:
                    draft.nilai_kontrak === ''
                        ? null
                        : Number(draft.nilai_kontrak),
                keterangan: draft.keterangan || null,
                aset_ids: draft.aset_ids,
            };
            await (draft.id
                ? api(`/kontrak-servis/${draft.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ ...body, version: draft.version }),
                  })
                : api('/kontrak-servis', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify(body),
                  }));
            toast.success('Kontrak servis disimpan.');
            setContractDraft(null);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Kontrak servis belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    }

    async function archive(path: string, version: number, done: string) {
        try {
            await api(path, {
                method: 'DELETE',
                body: JSON.stringify({ version }),
            });
            toast.success(done);
            refresh();
        } catch (caught) {
            toastSaveError(caught, 'Belum dapat diarsipkan.');
        }
    }

    async function openContract(contract: Contract) {
        try {
            const result = await api<{ data: Contract }>(
                `/kontrak-servis/${contract.id}`,
            );
            setErrors({});
            setContractDraft({
                id: result.data.id,
                version: result.data.version,
                legal_entity_id: result.data.legal_entity_id,
                nomor_kontrak: result.data.nomor_kontrak,
                vendor_id: result.data.vendor_id,
                berlaku_mulai: result.data.berlaku_mulai,
                berlaku_sampai: result.data.berlaku_sampai,
                cakupan: result.data.cakupan ?? '',
                nilai_kontrak: result.data.nilai_kontrak ?? '',
                keterangan: result.data.keterangan ?? '',
                aset_ids: (result.data.aset ?? []).map((line) => line.aset_id),
            });
        } catch (caught) {
            toast.error(errorMessage(caught, 'Kontrak belum dapat dibuka.'));
        }
    }

    const warrantyColumns: DataTableColumn<Warranty>[] = [
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.aset_kode,
            minWidth: 200,
        },
        {
            id: 'jenis',
            header: 'Garansi',
            cell: (row) => row.jenis_garansi_label,
            width: 110,
        },
        {
            id: 'vendor',
            header: 'Penjamin',
            cell: (row) => row.vendor?.name ?? '—',
            width: 180,
        },
        {
            id: 'referensi',
            header: 'No. kartu garansi',
            cell: (row) => row.nomor_referensi ?? '—',
            width: 150,
        },
        {
            id: 'periode',
            header: 'Masa berlaku',
            cell: (row) =>
                `${day(row.berlaku_mulai)} – ${day(row.berlaku_sampai)}`,
            sortValue: (row) => row.berlaku_sampai,
            width: 200,
        },
        {
            id: 'sisa',
            header: 'Sisa',
            cell: remaining,
            width: 140,
        },
    ];

    const contractColumns: DataTableColumn<Contract>[] = [
        { id: 'kode', header: 'No.', cell: (row) => row.kode, width: 130 },
        {
            id: 'nomor',
            header: 'Nomor kontrak',
            cell: (row) => row.nomor_kontrak,
            width: 160,
        },
        {
            id: 'vendor',
            header: 'Vendor servis',
            cell: (row) => row.vendor?.name ?? '—',
            minWidth: 180,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) => `${row.lines_count ?? 0} aset`,
            align: 'right',
            width: 100,
        },
        {
            id: 'periode',
            header: 'Masa berlaku',
            cell: (row) =>
                `${day(row.berlaku_mulai)} – ${day(row.berlaku_sampai)}`,
            sortValue: (row) => row.berlaku_sampai,
            width: 200,
        },
        {
            id: 'nilai',
            header: 'Nilai kontrak',
            cell: (row) => money(row.nilai_kontrak),
            align: 'right',
            width: 150,
        },
        { id: 'sisa', header: 'Sisa', cell: remaining, width: 140 },
    ];

    const expiringColumns: DataTableColumn<Expiring>[] = [
        {
            id: 'jenis',
            header: 'Jenis',
            cell: (row) =>
                row.jenis === 'garansi' ? 'Garansi' : 'Kontrak servis',
            width: 140,
        },
        {
            id: 'untuk',
            header: 'Untuk',
            cell: (row) =>
                row.jenis === 'garansi'
                    ? [row.aset_kode, row.aset_nama].filter(Boolean).join(' · ')
                    : `${row.referensi ?? ''} · ${row.jumlah_aset} aset`,
            minWidth: 220,
        },
        {
            id: 'vendor',
            header: 'Vendor',
            cell: (row) => row.vendor_nama ?? '—',
            width: 180,
        },
        {
            id: 'sampai',
            header: 'Berakhir',
            cell: (row) => day(row.berlaku_sampai),
            sortValue: (row) => row.berlaku_sampai,
            width: 130,
        },
        {
            id: 'sisa',
            header: 'Sisa',
            cell: (row) => `${row.sisa_hari} hari`,
            sortValue: (row) => row.sisa_hari,
            align: 'right',
            width: 100,
        },
    ];

    const expiringItems =
        expiring && expiring.days === days ? expiring.items : [];

    return (
        <Tabs value={tab} onValueChange={setTab}>
            <RecordActionBar
                title="Garansi dan kontrak servis"
                trailing={
                    <TabsList>
                        {can('garansi-aset.read') && (
                            <TabsTrigger value="garansi">Garansi</TabsTrigger>
                        )}
                        {can('kontrak-servis.read') && (
                            <TabsTrigger value="kontrak">
                                Kontrak servis
                            </TabsTrigger>
                        )}
                        <TabsTrigger value="berakhir">
                            Akan berakhir
                        </TabsTrigger>
                    </TabsList>
                }
            >
                {tab === 'garansi' && can('garansi-aset.create') && (
                    <Button
                        type="button"
                        onClick={() => {
                            setErrors({});
                            setWarrantyDraft({
                                id: null,
                                version: 0,
                                aset_id: '',
                                legal_entity_id: null,
                                vendor_id: '',
                                jenis_garansi: 'penuh',
                                nomor_referensi: '',
                                berlaku_mulai: today,
                                berlaku_sampai: '',
                                catatan: '',
                            });
                        }}
                    >
                        Catat garansi
                    </Button>
                )}
                {tab === 'kontrak' && can('kontrak-servis.create') && (
                    <Button
                        type="button"
                        disabled={!context.legal_entity_id}
                        onClick={() => {
                            setErrors({});
                            setContractDraft({
                                id: null,
                                version: 0,
                                legal_entity_id: context.legal_entity_id,
                                nomor_kontrak: '',
                                vendor_id: '',
                                berlaku_mulai: today,
                                berlaku_sampai: '',
                                cakupan: '',
                                nilai_kontrak: '',
                                keterangan: '',
                                aset_ids: [],
                            });
                        }}
                    >
                        Kontrak baru
                    </Button>
                )}
            </RecordActionBar>

            <TabsContent value="garansi">
                {warranties !== null && warranties.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada garansi tercatat</EmptyTitle>
                            <EmptyDescription>
                                Catat garansi dari pemasok atau pabrikan supaya
                                perbaikan selama masa garansi diarahkan ke
                                penjaminnya.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={warrantyColumns}
                        data={warranties ?? []}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => row.aset_kode}
                        actions={[
                            ...(can('garansi-aset.update')
                                ? [{ id: 'ubah', label: 'Ubah' }]
                                : []),
                            ...(can('garansi-aset.archive')
                                ? [
                                      {
                                          id: 'arsip',
                                          label: 'Arsipkan',
                                          destructive: true,
                                      },
                                  ]
                                : []),
                        ]}
                        onRowAction={(id, row) => {
                            if (id === 'arsip') {
                                void archive(
                                    `/garansi-aset/${row.id}`,
                                    row.version,
                                    'Garansi diarsipkan.',
                                );

                                return;
                            }

                            setErrors({});
                            setWarrantyDraft({
                                id: row.id,
                                version: row.version,
                                aset_id: row.aset_id,
                                legal_entity_id: row.legal_entity_id,
                                vendor_id: row.vendor_id ?? '',
                                jenis_garansi: row.jenis_garansi,
                                nomor_referensi: row.nomor_referensi ?? '',
                                berlaku_mulai: row.berlaku_mulai,
                                berlaku_sampai: row.berlaku_sampai,
                                catatan: row.catatan ?? '',
                            });
                        }}
                    />
                )}
            </TabsContent>

            <TabsContent value="kontrak">
                {contracts !== null && contracts.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada kontrak servis</EmptyTitle>
                            <EmptyDescription>
                                Catat kontrak pemeliharaan dengan vendor beserta
                                aset yang ditanggungnya.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={contractColumns}
                        data={contracts ?? []}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => row.kode}
                        onRowClick={(row) => void openContract(row)}
                        actions={
                            can('kontrak-servis.archive')
                                ? [
                                      {
                                          id: 'arsip',
                                          label: 'Arsipkan',
                                          destructive: true,
                                      },
                                  ]
                                : []
                        }
                        onRowAction={(id, row) =>
                            id === 'arsip' &&
                            void archive(
                                `/kontrak-servis/${row.id}`,
                                row.version,
                                'Kontrak servis diarsipkan.',
                            )
                        }
                    />
                )}
            </TabsContent>

            <TabsContent value="berakhir">
                <div className="border-b px-5 py-3">
                    <div className="w-full sm:w-48">
                        <Select
                            label="Berakhir dalam"
                            items={WINDOWS}
                            value={days}
                            ariaLabel="Rentang hari"
                            onValueChange={(value) => setDays(value ?? '30')}
                        />
                    </div>
                </div>
                {expiring !== null && expiringItems.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Tidak ada yang berakhir dalam {days} hari
                            </EmptyTitle>
                            <EmptyDescription>
                                Garansi dan kontrak servis yang akan habis
                                tampil di sini supaya sempat diperpanjang.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={expiringColumns}
                        data={expiringItems}
                        getRowKey={(row) => `${row.jenis}-${row.id}`}
                        getRowLabel={(row) => row.referensi ?? row.id}
                    />
                )}
            </TabsContent>

            <Dialog
                open={warrantyDraft !== null}
                onOpenChange={(open) => !open && setWarrantyDraft(null)}
            >
                <DialogContent size="compact" ref={warrantyRef}>
                    <DialogHeader>
                        <DialogTitle>
                            {warrantyDraft?.id
                                ? 'Ubah garansi'
                                : 'Catat garansi'}
                        </DialogTitle>
                    </DialogHeader>
                    {warrantyDraft && (
                        <DialogBody className="space-y-4">
                            <Field>
                                <Select
                                    label="Aset"
                                    required
                                    items={assetChoices}
                                    value={warrantyDraft.aset_id || null}
                                    placeholder="Pilih aset"
                                    searchPlaceholder="Cari kode atau nama aset"
                                    ariaLabel="Aset"
                                    portalContainer={warrantyRef}
                                    onValueChange={(value) =>
                                        !warrantyDraft.id &&
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            aset_id: value ?? '',
                                            legal_entity_id: value
                                                ? legalEntityOf(value)
                                                : null,
                                            vendor_id: '',
                                        })
                                    }
                                />
                                {message('aset_id') && (
                                    <FieldDescription>
                                        {message('aset_id')}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Field>
                                <Select
                                    label="Penjamin"
                                    items={warrantyVendors.map((vendor) => ({
                                        value: vendor.id,
                                        label: `${vendor.number} — ${vendor.name}`,
                                    }))}
                                    value={warrantyDraft.vendor_id || null}
                                    placeholder={
                                        warrantyDraft.aset_id
                                            ? 'Pilih vendor'
                                            : 'Pilih aset lebih dahulu'
                                    }
                                    searchPlaceholder="Cari nomor atau nama vendor"
                                    ariaLabel="Penjamin"
                                    portalContainer={warrantyRef}
                                    onValueChange={(value) =>
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            vendor_id: value ?? '',
                                        })
                                    }
                                />
                                <FieldDescription>
                                    {message('vendor_id') ??
                                        'Boleh kosong bila penjaminnya bukan vendor yang tercatat.'}
                                </FieldDescription>
                            </Field>
                            <Field>
                                <Select
                                    label="Cakupan garansi"
                                    required
                                    items={WARRANTY_TYPES}
                                    value={warrantyDraft.jenis_garansi}
                                    ariaLabel="Cakupan garansi"
                                    portalContainer={warrantyRef}
                                    onValueChange={(value) =>
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            jenis_garansi: value ?? 'penuh',
                                        })
                                    }
                                />
                                <FieldDescription>
                                    Sebagian berarti hanya bagian tertentu yang
                                    ditanggung, misalnya suku cadang tanpa jasa.
                                    Tulis rinciannya di catatan.
                                </FieldDescription>
                            </Field>
                            <Input
                                label="No. kartu garansi"
                                maxLength={80}
                                value={warrantyDraft.nomor_referensi}
                                onChange={(event) =>
                                    setWarrantyDraft({
                                        ...warrantyDraft,
                                        nomor_referensi: event.target.value,
                                    })
                                }
                            />
                            <div className="grid grid-cols-2 gap-3">
                                <Input
                                    label="Berlaku mulai"
                                    type="date"
                                    required
                                    value={warrantyDraft.berlaku_mulai}
                                    onChange={(event) =>
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            berlaku_mulai: event.target.value,
                                        })
                                    }
                                />
                                <Input
                                    label="Berlaku sampai"
                                    type="date"
                                    required
                                    value={warrantyDraft.berlaku_sampai}
                                    onChange={(event) =>
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            berlaku_sampai: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            {message('berlaku_sampai') && (
                                <p className="text-destructive text-sm">
                                    {message('berlaku_sampai')}
                                </p>
                            )}
                            <Textarea
                                rows={2}
                                maxLength={2000}
                                placeholder="Catatan, misalnya syarat klaim"
                                value={warrantyDraft.catatan}
                                onChange={(event) =>
                                    setWarrantyDraft({
                                        ...warrantyDraft,
                                        catatan: event.target.value,
                                    })
                                }
                            />
                        </DialogBody>
                    )}
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={
                                busy ||
                                !warrantyDraft?.aset_id ||
                                !warrantyDraft?.berlaku_mulai ||
                                !warrantyDraft?.berlaku_sampai
                            }
                            onClick={() => void saveWarranty()}
                        >
                            {busy ? 'Menyimpan…' : 'Simpan'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={contractDraft !== null}
                onOpenChange={(open) => !open && setContractDraft(null)}
            >
                <DialogContent ref={contractRef}>
                    <DialogHeader>
                        <DialogTitle>
                            {contractDraft?.id
                                ? 'Kontrak servis'
                                : 'Kontrak servis baru'}
                        </DialogTitle>
                    </DialogHeader>
                    {contractDraft && (
                        <DialogBody className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input
                                    label="Nomor kontrak"
                                    required
                                    maxLength={80}
                                    readOnly={
                                        !can('kontrak-servis.update') &&
                                        contractDraft.id !== null
                                    }
                                    value={contractDraft.nomor_kontrak}
                                    onChange={(event) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            nomor_kontrak: event.target.value,
                                        })
                                    }
                                />
                                <Field>
                                    <Select
                                        label="Vendor servis"
                                        required
                                        items={contractVendors.map(
                                            (vendor) => ({
                                                value: vendor.id,
                                                label: `${vendor.number} — ${vendor.name}`,
                                            }),
                                        )}
                                        value={contractDraft.vendor_id || null}
                                        placeholder="Pilih vendor"
                                        searchPlaceholder="Cari nomor atau nama vendor"
                                        ariaLabel="Vendor servis"
                                        portalContainer={contractRef}
                                        onValueChange={(value) =>
                                            setContractDraft({
                                                ...contractDraft,
                                                vendor_id: value ?? '',
                                            })
                                        }
                                    />
                                    {message('vendor_id') && (
                                        <FieldDescription>
                                            {message('vendor_id')}
                                        </FieldDescription>
                                    )}
                                </Field>
                                <Input
                                    label="Berlaku mulai"
                                    type="date"
                                    required
                                    value={contractDraft.berlaku_mulai}
                                    onChange={(event) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            berlaku_mulai: event.target.value,
                                        })
                                    }
                                />
                                <Input
                                    label="Berlaku sampai"
                                    type="date"
                                    required
                                    value={contractDraft.berlaku_sampai}
                                    onChange={(event) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            berlaku_sampai: event.target.value,
                                        })
                                    }
                                />
                                <Input
                                    label="Nilai kontrak"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={contractDraft.nilai_kontrak}
                                    onChange={(event) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            nilai_kontrak: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            {message('berlaku_sampai') && (
                                <p className="text-destructive text-sm">
                                    {message('berlaku_sampai')}
                                </p>
                            )}
                            <Field>
                                <MultiSelect
                                    label="Aset yang ditanggung"
                                    items={assetChoices}
                                    value={contractDraft.aset_ids}
                                    placeholder="Pilih aset"
                                    portalContainer={contractRef}
                                    onValueChange={(value) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            aset_ids: value,
                                        })
                                    }
                                />
                                {message('aset_ids') && (
                                    <FieldDescription>
                                        {message('aset_ids')}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Textarea
                                rows={3}
                                maxLength={4000}
                                placeholder="Cakupan, misalnya kunjungan bulanan, jasa, dan suku cadang yang ditanggung"
                                value={contractDraft.cakupan}
                                onChange={(event) =>
                                    setContractDraft({
                                        ...contractDraft,
                                        cakupan: event.target.value,
                                    })
                                }
                            />
                            <Textarea
                                rows={2}
                                maxLength={2000}
                                placeholder="Keterangan"
                                value={contractDraft.keterangan}
                                onChange={(event) =>
                                    setContractDraft({
                                        ...contractDraft,
                                        keterangan: event.target.value,
                                    })
                                }
                            />
                        </DialogBody>
                    )}
                    <DialogFooter>
                        {(contractDraft?.id
                            ? can('kontrak-servis.update')
                            : can('kontrak-servis.create')) && (
                            <DialogAction
                                type="button"
                                disabled={
                                    busy ||
                                    !contractDraft?.nomor_kontrak ||
                                    !contractDraft?.vendor_id ||
                                    !contractDraft?.berlaku_sampai
                                }
                                onClick={() => void saveContract()}
                            >
                                {busy ? 'Menyimpan…' : 'Simpan'}
                            </DialogAction>
                        )}
                        <DialogCancel type="button">Tutup</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Tabs>
    );
}
