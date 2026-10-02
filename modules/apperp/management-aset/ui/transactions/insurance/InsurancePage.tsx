import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useToday } from '@/hooks/use-work-date';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
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
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { Textarea } from '@apperp/ui/textarea';
import { day, money } from '../../_shared/format';
import type { Vendor } from '../../_shared/useVendors';
import { useVendors } from '../../_shared/useVendors';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import type { MasterOption } from '../../master/useMasterOptions';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import type { Coverage, Policy } from './insurance';
import InsuranceSummary from './InsuranceSummary';

type PolicyDraft = {
    nama: string;
    nomor_polis: string;
    jenis_asuransi_id: string;
    vendor_id: string;
    berlaku_mulai: string;
    berlaku_sampai: string;
    premi_tahunan: string;
    nilai_pertanggungan: string;
    diblokir: boolean;
    keterangan: string;
};

/** Tindakan atas satu pertanggungan, atau penambahan baru bila `coverage` kosong. */
type CoverageAction = {
    kind: 'tambah' | 'akhiri' | 'ganti-nilai';
    coverage: Coverage | null;
    aset_id: string;
    nilai: string;
    mulai: string;
    sampai: string;
    keterangan: string;
};

const emptyPolicy = (today: string): PolicyDraft => ({
    nama: '',
    nomor_polis: '',
    jenis_asuransi_id: '',
    vendor_id: '',
    berlaku_mulai: today,
    berlaku_sampai: '',
    premi_tahunan: '',
    nilai_pertanggungan: '',
    diblokir: false,
    keterangan: '',
});

/**
 * Asuransi aset; padanan *Insurance* Business Central.
 *
 * Tab pertama memuat polis beserta pertanggungan asetnya, tab kedua aset yang belum atau kurang
 * diasuransikan. Pertanggungan adalah riwayat: nilai yang berubah mengakhiri baris lama dan membuat
 * baris baru, jadi tidak ada tombol ubah nilai di tempat.
 */
export default function InsurancePage({
    context,
    permissions,
}: {
    context: { legal_entity_id: string | null };
    permissions: string[];
}) {
    const canCreate = permissions.includes(
        'management-aset.polis-asuransi.create',
    );
    const canUpdate = permissions.includes(
        'management-aset.polis-asuransi.update',
    );
    const canArchive = permissions.includes(
        'management-aset.polis-asuransi.archive',
    );
    const today = useToday();
    const [policies, setPolicies] = useState<Policy[]>([]);
    const [loading, setLoading] = useState(true);
    const [reload, setReload] = useState(0);
    const [openId, setOpenId] = useState<string | null>(null);
    const [opened, setOpened] = useState<Policy | null>(null);
    const [editing, setEditing] = useState<{
        id: string | null;
        draft: PolicyDraft;
    } | null>(null);
    const [action, setAction] = useState<CoverageAction | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [assets, setAssets] = useState<MasterOption[]>([]);
    const types = useMasterOptions('jenis-asuransi');
    const vendors = useVendors(
        '/polis-asuransi/vendor',
        editing ? context.legal_entity_id : null,
    );
    const editRef = useRef<HTMLDivElement>(null);
    const actionRef = useRef<HTMLDivElement>(null);
    const message = (field: string) => errors[field]?.[0];

    useEffect(() => {
        let cancelled = false;
        api<{ data: Policy[] }>('/polis-asuransi')
            .then((result) => !cancelled && setPolicies(result.data))
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(caught, 'Polis belum dapat dimuat.'),
                    ),
            )
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [reload]);

    useEffect(() => {
        if (!openId) {
            return;
        }

        let cancelled = false;
        api<{ data: Policy }>(`/polis-asuransi/${openId}`)
            .then((result) => !cancelled && setOpened(result.data))
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(caught, 'Polis belum dapat dibuka.'),
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, [openId]);

    // Pemilih aset hanya dibutuhkan saat menambah pertanggungan.
    const needAssets = action?.kind === 'tambah';
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

    const refresh = () => setReload((current) => current + 1);
    const shown = opened && opened.id === openId ? opened : null;

    function startEdit(policy: Policy | null) {
        setErrors({});
        setEditing({
            id: policy?.id ?? null,
            draft: policy
                ? {
                      nama: policy.nama,
                      nomor_polis: policy.nomor_polis,
                      jenis_asuransi_id: policy.jenis_asuransi_id ?? '',
                      vendor_id: policy.vendor_id ?? '',
                      berlaku_mulai: policy.berlaku_mulai,
                      berlaku_sampai: policy.berlaku_sampai ?? '',
                      premi_tahunan: policy.premi_tahunan,
                      nilai_pertanggungan: policy.nilai_pertanggungan,
                      diblokir: policy.diblokir,
                      keterangan: policy.keterangan ?? '',
                  }
                : emptyPolicy(today),
        });
    }

    async function savePolicy() {
        if (!editing) {
            return;
        }

        const { id, draft } = editing;
        const legalEntity = id
            ? shown?.legal_entity_id
            : context.legal_entity_id;
        setBusy(true);
        setErrors({});

        try {
            const body = {
                legal_entity_id: legalEntity,
                nama: draft.nama,
                nomor_polis: draft.nomor_polis,
                jenis_asuransi_id: draft.jenis_asuransi_id || null,
                vendor_id: draft.vendor_id || null,
                berlaku_mulai: draft.berlaku_mulai,
                berlaku_sampai: draft.berlaku_sampai || null,
                premi_tahunan:
                    draft.premi_tahunan === ''
                        ? null
                        : Number(draft.premi_tahunan),
                nilai_pertanggungan:
                    draft.nilai_pertanggungan === ''
                        ? null
                        : Number(draft.nilai_pertanggungan),
                diblokir: draft.diblokir,
                keterangan: draft.keterangan || null,
            };
            const result = id
                ? await api<{ data: Policy }>(`/polis-asuransi/${id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({
                          ...body,
                          version: shown?.version,
                      }),
                  })
                : await api<{ data: Policy }>('/polis-asuransi', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify(body),
                  });
            toast.success(id ? 'Polis disimpan.' : 'Polis dibuat.');
            setEditing(null);
            setOpened(result.data);
            setOpenId(result.data.id);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Polis belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    }

    async function archivePolicy(policy: Policy) {
        try {
            await api(`/polis-asuransi/${policy.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: policy.version }),
            });
            toast.success('Polis diarsipkan.');
            setOpenId(null);
            refresh();
        } catch (caught) {
            toastSaveError(caught, 'Polis belum dapat diarsipkan.');
        }
    }

    async function runAction() {
        if (!action || !shown) {
            return;
        }

        const base = `/polis-asuransi/${shown.id}/pertanggungan`;
        const request: {
            path: string;
            headers: Record<string, string>;
            body: Record<string, unknown>;
        } =
            action.kind === 'tambah'
                ? {
                      path: base,
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: {
                          aset_id: action.aset_id,
                          nilai_pertanggungan: Number(action.nilai),
                          berlaku_mulai: action.mulai,
                          berlaku_sampai: action.sampai || null,
                          keterangan: action.keterangan || null,
                      },
                  }
                : action.kind === 'akhiri'
                  ? {
                        path: `${base}/${action.coverage?.id}/akhiri`,
                        headers: {},
                        body: { berlaku_sampai: action.sampai },
                    }
                  : {
                        path: `${base}/${action.coverage?.id}/ganti-nilai`,
                        headers: {},
                        body: {
                            nilai_pertanggungan: Number(action.nilai),
                            berlaku_mulai: action.mulai,
                            keterangan: action.keterangan || null,
                        },
                    };

        setBusy(true);
        setErrors({});

        try {
            const result = await api<{ data: Policy }>(request.path, {
                method: 'POST',
                headers: request.headers,
                body: JSON.stringify({
                    ...request.body,
                    version: shown.version,
                }),
            });
            setOpened(result.data);
            setAction(null);
            refresh();
            toast.success('Pertanggungan disimpan.');
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Pertanggungan belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    }

    async function archiveCoverage(coverage: Coverage) {
        if (!shown) {
            return;
        }

        try {
            const result = await api<{ data: Policy }>(
                `/polis-asuransi/${shown.id}/pertanggungan/${coverage.id}`,
                {
                    method: 'DELETE',
                    body: JSON.stringify({ version: shown.version }),
                },
            );
            setOpened(result.data);
            refresh();
            toast.success('Pertanggungan diarsipkan.');
        } catch (caught) {
            toastSaveError(caught, 'Pertanggungan belum dapat diarsipkan.');
        }
    }

    const policyColumns: DataTableColumn<Policy>[] = [
        { id: 'kode', header: 'No.', cell: (row) => row.kode, width: 130 },
        {
            id: 'nama',
            header: 'Polis',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    {row.nama}
                    {row.diblokir && <Badge variant="outline">Diblokir</Badge>}
                    {!row.berlaku && (
                        <Badge variant="secondary">Tidak berlaku</Badge>
                    )}
                </span>
            ),
            minWidth: 200,
        },
        {
            id: 'nomor',
            header: 'Nomor polis',
            cell: (row) => row.nomor_polis,
            width: 160,
        },
        {
            id: 'penanggung',
            header: 'Penanggung',
            cell: (row) => row.vendor?.name ?? '—',
            width: 180,
        },
        {
            id: 'periode',
            header: 'Masa berlaku',
            cell: (row) =>
                `${day(row.berlaku_mulai)} – ${row.berlaku_sampai ? day(row.berlaku_sampai) : 'tanpa batas'}`,
            sortValue: (row) => row.berlaku_mulai,
            width: 200,
        },
        {
            id: 'plafon',
            header: 'Nilai pertanggungan polis',
            cell: (row) => money(row.nilai_pertanggungan),
            align: 'right',
            width: 170,
        },
        {
            id: 'tertanggung',
            header: 'Ditanggung hari ini',
            cell: (row) => money(row.total_nilai_tertanggung),
            sortValue: (row) => Number(row.total_nilai_tertanggung),
            align: 'right',
            width: 170,
        },
    ];

    const coverageColumns: DataTableColumn<Coverage>[] = [
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            minWidth: 180,
        },
        {
            id: 'nilai',
            header: 'Nilai pertanggungan',
            cell: (row) => money(row.nilai_pertanggungan),
            align: 'right',
            width: 160,
        },
        {
            id: 'periode',
            header: 'Periode',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    {`${day(row.berlaku_mulai)} – ${row.berlaku_sampai ? day(row.berlaku_sampai) : 'ikut polis'}`}
                    {row.berjalan && <Badge variant="outline">Berjalan</Badge>}
                </span>
            ),
            width: 260,
        },
        {
            id: 'keterangan',
            header: 'Keterangan',
            cell: (row) => row.keterangan ?? '—',
            width: 180,
        },
    ];

    const vendorChoices = vendors.map((vendor: Vendor) => ({
        value: vendor.id,
        label: `${vendor.number} — ${vendor.name}`,
    }));

    // Penanggung yang sudah tercatat tetap tampil walau sudah nonaktif.
    if (
        editing?.draft.vendor_id &&
        shown?.vendor &&
        !vendorChoices.some((choice) => choice.value === shown.vendor?.id)
    ) {
        vendorChoices.push({
            value: shown.vendor.id,
            label: `${shown.vendor.number} — ${shown.vendor.name}`,
        });
    }

    const setDraft = (change: Partial<PolicyDraft>) =>
        editing &&
        setEditing({ ...editing, draft: { ...editing.draft, ...change } });

    return (
        <Tabs defaultValue="polis">
            <RecordActionBar
                title="Asuransi aset"
                trailing={
                    <TabsList>
                        <TabsTrigger value="polis">Polis</TabsTrigger>
                        <TabsTrigger value="ringkasan">
                            Belum dan kurang diasuransikan
                        </TabsTrigger>
                    </TabsList>
                }
            >
                {canCreate && (
                    <Button
                        type="button"
                        disabled={!context.legal_entity_id}
                        onClick={() => startEdit(null)}
                    >
                        Polis baru
                    </Button>
                )}
            </RecordActionBar>

            <TabsContent value="polis">
                {!loading && policies.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada polis asuransi</EmptyTitle>
                            <EmptyDescription>
                                Catat polis dari perusahaan asuransi, lalu
                                tambahkan aset yang ditanggungnya beserta
                                nilainya.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={policyColumns}
                        data={policies}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => `${row.kode} ${row.nama}`}
                        onRowClick={(row) => setOpenId(row.id)}
                    />
                )}
            </TabsContent>

            <TabsContent value="ringkasan">
                <InsuranceSummary />
            </TabsContent>

            <Dialog
                open={openId !== null}
                onOpenChange={(open) => !open && setOpenId(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {shown ? `${shown.kode} · ${shown.nama}` : 'Polis'}
                        </DialogTitle>
                    </DialogHeader>
                    {shown && (
                        <DialogBody className="space-y-4">
                            <dl className="grid gap-3 sm:grid-cols-3">
                                {[
                                    ['Nomor polis', shown.nomor_polis],
                                    ['Penanggung', shown.vendor?.name ?? '—'],
                                    [
                                        'Jenis asuransi',
                                        shown.jenis_asuransi_nama ?? '—',
                                    ],
                                    [
                                        'Masa berlaku',
                                        `${day(shown.berlaku_mulai)} – ${shown.berlaku_sampai ? day(shown.berlaku_sampai) : 'tanpa batas'}`,
                                    ],
                                    [
                                        'Premi tahunan',
                                        money(shown.premi_tahunan),
                                    ],
                                    [
                                        'Nilai pertanggungan polis',
                                        money(shown.nilai_pertanggungan),
                                    ],
                                    [
                                        'Ditanggung hari ini',
                                        money(shown.total_nilai_tertanggung),
                                    ],
                                    [
                                        Number(shown.selisih_plafon) < 0
                                            ? 'Melebihi nilai polis'
                                            : 'Sisa nilai polis',
                                        money(
                                            Math.abs(
                                                Number(shown.selisih_plafon),
                                            ),
                                        ),
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-xs">
                                            {label}
                                        </dt>
                                        <dd className="text-sm">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                            {shown.keterangan && (
                                <p className="text-sm">{shown.keterangan}</p>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="mr-auto font-medium">
                                    Aset yang ditanggung
                                </span>
                                {canUpdate && !shown.diblokir && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={() => {
                                            setErrors({});
                                            setAction({
                                                kind: 'tambah',
                                                coverage: null,
                                                aset_id: '',
                                                nilai: '',
                                                mulai:
                                                    today > shown.berlaku_mulai
                                                        ? today
                                                        : shown.berlaku_mulai,
                                                sampai: '',
                                                keterangan: '',
                                            });
                                        }}
                                    >
                                        Tambah aset
                                    </Button>
                                )}
                            </div>
                            {(shown.pertanggungan ?? []).length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Polis ini belum menanggung aset.
                                </p>
                            ) : (
                                <DataTable
                                    columns={coverageColumns}
                                    data={shown.pertanggungan ?? []}
                                    getRowKey={(row) => row.id}
                                    getRowLabel={(row) => row.aset_kode}
                                    actions={
                                        canUpdate
                                            ? [
                                                  {
                                                      id: 'ganti-nilai',
                                                      label: 'Ganti nilai mulai tanggal',
                                                  },
                                                  {
                                                      id: 'akhiri',
                                                      label: 'Akhiri pertanggungan',
                                                  },
                                                  {
                                                      id: 'arsip',
                                                      label: 'Arsipkan (salah catat)',
                                                      destructive: true,
                                                  },
                                              ]
                                            : []
                                    }
                                    onRowAction={(id, row) => {
                                        setErrors({});

                                        if (id === 'arsip') {
                                            void archiveCoverage(row);

                                            return;
                                        }

                                        setAction({
                                            kind:
                                                id === 'akhiri'
                                                    ? 'akhiri'
                                                    : 'ganti-nilai',
                                            coverage: row,
                                            aset_id: row.aset_id,
                                            nilai: '',
                                            mulai: today,
                                            sampai: today,
                                            keterangan: '',
                                        });
                                    }}
                                />
                            )}
                        </DialogBody>
                    )}
                    <DialogFooter>
                        {shown && canUpdate && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => startEdit(shown)}
                            >
                                Ubah polis
                            </Button>
                        )}
                        {shown && canArchive && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void archivePolicy(shown)}
                            >
                                Arsipkan
                            </Button>
                        )}
                        <DialogCancel type="button">Tutup</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent size="compact" ref={editRef}>
                    <DialogHeader>
                        <DialogTitle>
                            {editing?.id ? 'Ubah polis' : 'Polis baru'}
                        </DialogTitle>
                    </DialogHeader>
                    {editing && (
                        <DialogBody className="space-y-4">
                            <Input
                                label="Nama polis"
                                required
                                maxLength={150}
                                value={editing.draft.nama}
                                onChange={(event) =>
                                    setDraft({ nama: event.target.value })
                                }
                            />
                            <Field>
                                <Input
                                    label="Nomor polis"
                                    required
                                    maxLength={60}
                                    value={editing.draft.nomor_polis}
                                    onChange={(event) =>
                                        setDraft({
                                            nomor_polis: event.target.value,
                                        })
                                    }
                                />
                                <FieldDescription>
                                    {message('nomor_polis') ??
                                        'Nomor yang tertera pada dokumen polis dari perusahaan asuransi.'}
                                </FieldDescription>
                            </Field>
                            <Field>
                                <Select
                                    label="Penanggung"
                                    items={vendorChoices}
                                    value={editing.draft.vendor_id || null}
                                    placeholder="Pilih perusahaan asuransi"
                                    searchPlaceholder="Cari nomor atau nama vendor"
                                    ariaLabel="Penanggung"
                                    portalContainer={editRef}
                                    onValueChange={(value) =>
                                        setDraft({ vendor_id: value ?? '' })
                                    }
                                />
                                {message('vendor_id') && (
                                    <FieldDescription>
                                        {message('vendor_id')}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Select
                                label="Jenis asuransi"
                                items={types.options.map((option) => ({
                                    value: option.id,
                                    label: optionLabel(option),
                                }))}
                                value={editing.draft.jenis_asuransi_id || null}
                                placeholder="Pilih jenis asuransi"
                                ariaLabel="Jenis asuransi"
                                portalContainer={editRef}
                                onValueChange={(value) =>
                                    setDraft({ jenis_asuransi_id: value ?? '' })
                                }
                            />
                            <div className="grid grid-cols-2 gap-3">
                                <Input
                                    label="Berlaku mulai"
                                    type="date"
                                    required
                                    value={editing.draft.berlaku_mulai}
                                    onChange={(event) =>
                                        setDraft({
                                            berlaku_mulai: event.target.value,
                                        })
                                    }
                                />
                                <Input
                                    label="Berlaku sampai"
                                    type="date"
                                    value={editing.draft.berlaku_sampai}
                                    onChange={(event) =>
                                        setDraft({
                                            berlaku_sampai: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            {(message('berlaku_mulai') ??
                                message('berlaku_sampai')) && (
                                <p className="text-destructive text-sm">
                                    {message('berlaku_mulai') ??
                                        message('berlaku_sampai')}
                                </p>
                            )}
                            <div className="grid grid-cols-2 gap-3">
                                <Input
                                    label="Premi tahunan"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={editing.draft.premi_tahunan}
                                    onChange={(event) =>
                                        setDraft({
                                            premi_tahunan: event.target.value,
                                        })
                                    }
                                />
                                <Field>
                                    <Input
                                        label="Nilai pertanggungan polis"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={
                                            editing.draft.nilai_pertanggungan
                                        }
                                        onChange={(event) =>
                                            setDraft({
                                                nilai_pertanggungan:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    <FieldDescription>
                                        Batas tanggungan polis, dibandingkan
                                        dengan jumlah nilai aset yang
                                        ditanggungnya.
                                    </FieldDescription>
                                </Field>
                            </div>
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    checked={editing.draft.diblokir}
                                    onCheckedChange={(checked) =>
                                        setDraft({ diblokir: checked === true })
                                    }
                                />
                                <span>
                                    Blokir polis
                                    <span className="text-muted-foreground block">
                                        Polis yang diblokir tidak dapat
                                        menanggung aset baru. Aset yang sudah
                                        ditanggung tetap ditanggung.
                                    </span>
                                </span>
                            </label>
                            <Textarea
                                rows={2}
                                maxLength={2000}
                                placeholder="Keterangan"
                                value={editing.draft.keterangan}
                                onChange={(event) =>
                                    setDraft({ keterangan: event.target.value })
                                }
                            />
                        </DialogBody>
                    )}
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={
                                busy ||
                                !editing?.draft.nama ||
                                !editing?.draft.nomor_polis ||
                                !editing?.draft.berlaku_mulai
                            }
                            onClick={() => void savePolicy()}
                        >
                            {busy ? 'Menyimpan…' : 'Simpan'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={action !== null}
                onOpenChange={(open) => !open && setAction(null)}
            >
                <DialogContent size="compact" ref={actionRef}>
                    <DialogHeader>
                        <DialogTitle>
                            {action?.kind === 'tambah'
                                ? 'Tambah aset ke polis'
                                : action?.kind === 'akhiri'
                                  ? 'Akhiri pertanggungan'
                                  : 'Ganti nilai pertanggungan'}
                        </DialogTitle>
                    </DialogHeader>
                    {action && (
                        <DialogBody className="space-y-4">
                            {action.kind === 'tambah' ? (
                                <Field>
                                    <Select
                                        label="Aset"
                                        required
                                        items={assets.map((asset) => ({
                                            value: asset.id,
                                            label: optionLabel(asset),
                                        }))}
                                        value={action.aset_id || null}
                                        placeholder="Pilih aset"
                                        searchPlaceholder="Cari kode atau nama aset"
                                        ariaLabel="Aset"
                                        portalContainer={actionRef}
                                        onValueChange={(value) =>
                                            setAction({
                                                ...action,
                                                aset_id: value ?? '',
                                            })
                                        }
                                    />
                                    {message('aset_id') && (
                                        <FieldDescription>
                                            {message('aset_id')}
                                        </FieldDescription>
                                    )}
                                </Field>
                            ) : (
                                <p className="text-sm">
                                    {action.coverage?.aset_kode} ·{' '}
                                    {money(
                                        action.coverage?.nilai_pertanggungan,
                                    )}{' '}
                                    sejak {day(action.coverage?.berlaku_mulai)}
                                </p>
                            )}
                            {action.kind !== 'akhiri' && (
                                <Input
                                    label={
                                        action.kind === 'tambah'
                                            ? 'Nilai pertanggungan'
                                            : 'Nilai baru'
                                    }
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    required
                                    value={action.nilai}
                                    onChange={(event) =>
                                        setAction({
                                            ...action,
                                            nilai: event.target.value,
                                        })
                                    }
                                />
                            )}
                            <div className="grid grid-cols-2 gap-3">
                                {action.kind !== 'akhiri' && (
                                    <Field>
                                        <Input
                                            label={
                                                action.kind === 'tambah'
                                                    ? 'Berlaku mulai'
                                                    : 'Nilai baru berlaku mulai'
                                            }
                                            type="date"
                                            required
                                            value={action.mulai}
                                            onChange={(event) =>
                                                setAction({
                                                    ...action,
                                                    mulai: event.target.value,
                                                })
                                            }
                                        />
                                        {action.kind === 'ganti-nilai' && (
                                            <FieldDescription>
                                                Nilai lama berakhir sehari
                                                sebelumnya dan tetap tercatat.
                                            </FieldDescription>
                                        )}
                                    </Field>
                                )}
                                {action.kind !== 'ganti-nilai' && (
                                    <Field>
                                        <Input
                                            label="Berlaku sampai"
                                            type="date"
                                            required={action.kind === 'akhiri'}
                                            value={action.sampai}
                                            onChange={(event) =>
                                                setAction({
                                                    ...action,
                                                    sampai: event.target.value,
                                                })
                                            }
                                        />
                                        {action.kind === 'tambah' && (
                                            <FieldDescription>
                                                Kosongkan untuk mengikuti masa
                                                berlaku polis.
                                            </FieldDescription>
                                        )}
                                    </Field>
                                )}
                            </div>
                            {(message('berlaku_mulai') ??
                                message('berlaku_sampai') ??
                                message('nilai_pertanggungan')) && (
                                <p className="text-destructive text-sm">
                                    {message('berlaku_mulai') ??
                                        message('berlaku_sampai') ??
                                        message('nilai_pertanggungan')}
                                </p>
                            )}
                            {action.kind !== 'akhiri' && (
                                <Input
                                    label="Keterangan"
                                    maxLength={2000}
                                    value={action.keterangan}
                                    onChange={(event) =>
                                        setAction({
                                            ...action,
                                            keterangan: event.target.value,
                                        })
                                    }
                                />
                            )}
                        </DialogBody>
                    )}
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={
                                busy ||
                                (action?.kind === 'tambah' &&
                                    (!action.aset_id || action.nilai === '')) ||
                                (action?.kind === 'ganti-nilai' &&
                                    action.nilai === '') ||
                                (action?.kind === 'akhiri' && !action.sampai)
                            }
                            onClick={() => void runAction()}
                        >
                            {busy ? 'Menyimpan…' : 'Simpan'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Tabs>
    );
}
