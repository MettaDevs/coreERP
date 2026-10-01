import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
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
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldDescription } from '@apperp/ui/field';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import type { MasterOption } from '../../master/useMasterOptions';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import type {
    Context,
    MaintenanceRequest,
    MaintenanceRequestForm,
    Option,
} from './maintenanceRequest';
import {
    StatusBadge,
    codeName,
    emptyForm,
    openList,
    openRequest,
    openRequestEdit,
    permissionCheck,
    toForm,
    useAssets,
    useDependentOptions,
    workOrderPath,
} from './maintenanceRequest';

type Mode = 'create' | 'view' | 'edit';

type Decision = 'submit' | 'accept' | 'archive' | null;

const choices = (options: (MasterOption | Option)[]) =>
    options.map((option) => ({
        value: option.id,
        label: optionLabel(option as MasterOption),
    }));

/** Satu baris "label: isi" pada mode baca. */
function Info({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="text-sm">{children}</div>
        </div>
    );
}

/**
 * Rincian satu permintaan pemeliharaan.
 *
 * Setiap langkah adalah tombol tersendiri — Ajukan, Terima, Tolak, Buat work order — seperti
 * dokumen BC dan F&O; menyimpan isian tidak pernah memindahkan status. Isian hanya dapat diubah
 * selama draf.
 */
export default function MaintenanceRequestDetailPage({
    context,
    permissions,
    requestId,
    mode,
}: {
    context: Context;
    permissions: string[];
    requestId?: string;
    mode: Mode;
}) {
    const can = permissionCheck(permissions);
    const canCreateWorkOrder = permissions.includes(
        'management-aset.pemeliharaan-aset.create',
    );
    const formatDateTime = useDateTimeFormat();
    const readOnly = mode === 'view';
    const [saved, setSaved] = useState<MaintenanceRequest | null>(null);
    const [form, setForm] = useState<MaintenanceRequestForm>(() =>
        emptyForm(context.org_unit_id),
    );
    const [loading, setLoading] = useState(mode !== 'create');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [decision, setDecision] = useState<Decision>(null);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [ordering, setOrdering] = useState(false);
    const [order, setOrder] = useState({
        aset_id: '',
        maintenance_job_type_id: '',
        trade_id: '',
        tipe_work_order_id: '',
    });
    const panelRef = useRef<HTMLDivElement>(null);
    const orderRef = useRef<HTMLDivElement>(null);

    const units = useMasterOptions(
        readOnly ? null : 'reference-data/unit-kerja',
    );
    const types = useMasterOptions(
        readOnly ? null : 'jenis-permintaan-pemeliharaan',
    );
    const locations = useMasterOptions(readOnly ? null : 'lokasi-aset');
    const levels = useMasterOptions(readOnly ? null : 'tingkat-layanan');
    const causes = useMasterOptions(readOnly ? null : 'sebab-kerusakan');
    const workOrderTypes = useMasterOptions(
        ordering ? 'tipe-work-order' : null,
    );
    const trades = useMasterOptions(ordering ? 'trade' : null);
    const assets = useAssets(!readOnly || ordering);
    const orderAsset = saved?.aset_id ?? order.aset_id;
    const jobTypes = useDependentOptions(
        ordering ? orderAsset : '',
        (asetId) => `/pemeliharaan-aset/referensi/job-types?aset_id=${asetId}`,
        'Jenis pekerjaan untuk aset ini belum dapat dimuat.',
    );

    useEffect(() => {
        if (!requestId) {
            return;
        }

        let cancelled = false;
        api<{ data: MaintenanceRequest }>(
            `/permintaan-pemeliharaan/${requestId}`,
        )
            .then((result) => {
                if (!cancelled) {
                    setSaved(result.data);
                    setForm(toForm(result.data));
                }
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Permintaan belum dapat dimuat.'),
                ),
            )
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [requestId]);

    const message = (field: string) => errors[field]?.[0];
    const set = (patch: Partial<MaintenanceRequestForm>) =>
        setForm((current) => ({ ...current, ...patch }));

    async function save() {
        const legalEntity = saved?.legal_entity_id ?? context.legal_entity_id;

        if (!legalEntity) {
            toast.error(
                'Pilih entitas legal aktif terlebih dahulu pada header CoreERP.',
            );

            return;
        }

        setBusy(true);
        setErrors({});
        const payload = {
            legal_entity_id: legalEntity,
            responsible_org_unit_id: form.responsible_org_unit_id,
            jenis_permintaan_id: form.jenis_permintaan_id,
            aset_id: form.aset_id || null,
            lokasi_aset_id: form.lokasi_aset_id || null,
            deskripsi: form.deskripsi,
            tingkat_layanan_id: form.tingkat_layanan_id || null,
            sebab_kerusakan_id: form.sebab_kerusakan_id || null,
        };

        try {
            if (mode === 'create') {
                const result = await api<{ data: MaintenanceRequest }>(
                    '/permintaan-pemeliharaan',
                    {
                        method: 'POST',
                        headers: { 'Idempotency-Key': newIdempotencyKey() },
                        body: JSON.stringify(payload),
                    },
                );
                toast.success(
                    `Permintaan ${result.data.kode} disimpan sebagai draf.`,
                );
                openRequest(result.data.id);

                return;
            }

            await api(`/permintaan-pemeliharaan/${requestId}`, {
                method: 'PATCH',
                body: JSON.stringify({ ...payload, version: saved?.version }),
            });
            toast.success('Perubahan permintaan disimpan.');
            openRequest(String(requestId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Permintaan belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    }

    /** Satu langkah alur status; jawabannya permintaan dengan status barunya. */
    async function step(
        path: string,
        body: Record<string, unknown>,
        success: string,
        failure: string,
    ): Promise<boolean> {
        setBusy(true);
        setErrors({});

        try {
            const result = await api<{ data: MaintenanceRequest }>(
                `/permintaan-pemeliharaan/${requestId}/${path}`,
                {
                    method: 'POST',
                    body: JSON.stringify({ ...body, version: saved?.version }),
                },
            );
            setSaved(result.data);
            setForm(toForm(result.data));
            toast.success(success);

            return true;
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, failure);

            return false;
        } finally {
            setBusy(false);
        }
    }

    async function archive() {
        setDecision(null);

        try {
            await api(`/permintaan-pemeliharaan/${requestId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: saved?.version }),
            });
            toast.success('Permintaan diarsipkan.');
            openList();
        } catch (caught) {
            toastSaveError(caught, 'Permintaan belum dapat diarsipkan.');
        }
    }

    function openOrderDialog() {
        setOrder({
            aset_id: '',
            maintenance_job_type_id: '',
            trade_id: '',
            tipe_work_order_id:
                saved?.jenis_permintaan_tipe_work_order_id ?? '',
        });
        setErrors({});
        setOrdering(true);
    }

    if (loading) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat permintaan…
            </div>
        );
    }

    const status = saved?.status ?? 'draft';
    const title =
        mode === 'create'
            ? 'Permintaan pemeliharaan baru'
            : (saved?.kode ?? 'Permintaan pemeliharaan');

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar
                title={title}
                trailing={saved ? <StatusBadge status={status} /> : undefined}
            >
                {readOnly && status === 'draft' && can('update') && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => openRequestEdit(String(requestId))}
                    >
                        Ubah
                    </ActionButton>
                )}
                {readOnly && status === 'draft' && can('submit') && (
                    <Button
                        type="button"
                        disabled={busy}
                        onClick={() => setDecision('submit')}
                    >
                        Ajukan
                    </Button>
                )}
                {readOnly && status === 'diajukan' && can('review') && (
                    <>
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => setDecision('accept')}
                        >
                            Terima
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                setReason('');
                                setRejecting(true);
                            }}
                        >
                            Tolak
                        </Button>
                    </>
                )}
                {readOnly &&
                    status === 'diterima' &&
                    can('review') &&
                    canCreateWorkOrder && (
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={openOrderDialog}
                        >
                            Buat work order
                        </Button>
                    )}
                {readOnly &&
                    (status === 'draft' || status === 'ditolak') &&
                    can('archive') && (
                        <ActionButton
                            action="archive"
                            type="button"
                            onClick={() => setDecision('archive')}
                        >
                            Arsipkan
                        </ActionButton>
                    )}
                {!readOnly && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                requestId ? openRequest(requestId) : openList()
                            }
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => void save()}
                        >
                            {busy ? 'Menyimpan…' : 'Simpan draf'}
                        </Button>
                    </>
                )}
            </RecordActionBar>

            <div ref={panelRef} className="min-h-0 flex-1 overflow-y-auto">
                <div className="space-y-5 p-5">
                    {readOnly && saved ? (
                        <>
                            {status === 'ditolak' && saved.alasan_penolakan && (
                                <div className="border-destructive/40 bg-destructive/5 rounded-md border p-3 text-sm">
                                    <div className="font-medium">
                                        Ditolak
                                        {saved.diputuskan_oleh_nama
                                            ? ` oleh ${saved.diputuskan_oleh_nama}`
                                            : ''}
                                    </div>
                                    <div>{saved.alasan_penolakan}</div>
                                </div>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <Info label="Jenis permintaan">
                                    {saved.jenis_permintaan_nama ?? '—'}
                                </Info>
                                <Info label="Unit pelapor">
                                    {saved.unit_nama ?? '—'}
                                </Info>
                                <Info label="Dilaporkan oleh">
                                    {saved.dilaporkan_oleh_nama ?? '—'}
                                </Info>
                                <Info label="Aset">
                                    {saved.aset_id
                                        ? codeName(
                                              saved.aset_kode,
                                              saved.aset_nama,
                                          )
                                        : 'Belum dipilih'}
                                </Info>
                                <Info label="Lokasi">
                                    {saved.lokasi_nama ?? '—'}
                                </Info>
                                <Info label="Tingkat layanan">
                                    {saved.tingkat_layanan_nama ?? '—'}
                                </Info>
                                <Info label="Sebab kerusakan">
                                    {saved.sebab_kerusakan_nama ?? '—'}
                                </Info>
                                <Info label="Diajukan">
                                    {formatDateTime(saved.diajukan_pada)}
                                </Info>
                                <Info label="Diputuskan">
                                    {saved.diputuskan_pada
                                        ? `${formatDateTime(saved.diputuskan_pada)}${saved.diputuskan_oleh_nama ? ` · ${saved.diputuskan_oleh_nama}` : ''}`
                                        : '—'}
                                </Info>
                                <Info label="Work order">
                                    {saved.pemeliharaan_aset_id ? (
                                        <a
                                            className="text-primary font-medium hover:underline"
                                            href={workOrderPath(
                                                saved.pemeliharaan_aset_id,
                                            )}
                                        >
                                            {saved.work_order_kode}
                                        </a>
                                    ) : (
                                        '—'
                                    )}
                                </Info>
                            </div>
                            <Info label="Deskripsi">
                                <p className="whitespace-pre-line">
                                    {saved.deskripsi}
                                </p>
                            </Info>
                        </>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field>
                                <Select
                                    label="Jenis permintaan"
                                    required
                                    items={choices(types.options)}
                                    value={form.jenis_permintaan_id || null}
                                    placeholder="Pilih jenis permintaan"
                                    ariaLabel="Jenis permintaan"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        set({
                                            jenis_permintaan_id: value ?? '',
                                        })
                                    }
                                />
                                {(message('jenis_permintaan_id') ||
                                    types.error) && (
                                    <FieldDescription>
                                        {message('jenis_permintaan_id') ||
                                            types.error}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Field>
                                <Select
                                    label="Unit pelapor"
                                    required
                                    items={choices(units.options)}
                                    value={form.responsible_org_unit_id || null}
                                    placeholder="Pilih unit kerja"
                                    ariaLabel="Unit pelapor"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        set({
                                            responsible_org_unit_id:
                                                value ?? '',
                                        })
                                    }
                                />
                                {(message('responsible_org_unit_id') ||
                                    units.error) && (
                                    <FieldDescription>
                                        {message('responsible_org_unit_id') ||
                                            units.error}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Field>
                                <Select
                                    label="Aset"
                                    items={[
                                        {
                                            value: '',
                                            label: 'Tidak ada aset tertentu',
                                        },
                                        ...choices(assets.options),
                                    ]}
                                    value={form.aset_id || null}
                                    placeholder="Pilih aset yang rusak"
                                    searchPlaceholder="Cari kode atau nama aset"
                                    ariaLabel="Aset"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        set({ aset_id: value ?? '' })
                                    }
                                />
                                {(message('aset_id') || assets.error) && (
                                    <FieldDescription>
                                        {message('aset_id') || assets.error}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Field>
                                <Select
                                    label="Lokasi"
                                    items={[
                                        {
                                            value: '',
                                            label: 'Ikuti lokasi aset',
                                        },
                                        ...choices(locations.options),
                                    ]}
                                    value={form.lokasi_aset_id || null}
                                    placeholder="Ikuti lokasi aset"
                                    ariaLabel="Lokasi"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        set({ lokasi_aset_id: value ?? '' })
                                    }
                                />
                                <FieldDescription>
                                    {message('lokasi_aset_id') ||
                                        'Isi bila yang rusak bukan satu aset tertentu, misalnya ruangan.'}
                                </FieldDescription>
                            </Field>
                            <Field>
                                <Select
                                    label="Tingkat layanan"
                                    items={[
                                        {
                                            value: '',
                                            label: 'Tidak ditentukan',
                                        },
                                        ...choices(levels.options),
                                    ]}
                                    value={form.tingkat_layanan_id || null}
                                    placeholder="Tidak ditentukan"
                                    ariaLabel="Tingkat layanan"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        set({ tingkat_layanan_id: value ?? '' })
                                    }
                                />
                            </Field>
                            <Field>
                                <Select
                                    label="Sebab kerusakan"
                                    items={[
                                        { value: '', label: 'Belum diketahui' },
                                        ...choices(causes.options),
                                    ]}
                                    value={form.sebab_kerusakan_id || null}
                                    placeholder="Belum diketahui"
                                    ariaLabel="Sebab kerusakan"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        set({ sebab_kerusakan_id: value ?? '' })
                                    }
                                />
                            </Field>
                            <Field className="sm:col-span-2">
                                <Textarea
                                    label="Deskripsi"
                                    required
                                    maxLength={4000}
                                    value={form.deskripsi}
                                    onChange={(event) =>
                                        set({ deskripsi: event.target.value })
                                    }
                                />
                                {message('deskripsi') && (
                                    <FieldDescription>
                                        {message('deskripsi')}
                                    </FieldDescription>
                                )}
                            </Field>
                        </div>
                    )}
                </div>
            </div>

            <AlertDialog
                open={decision !== null}
                onOpenChange={(open) => !open && setDecision(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {decision === 'submit'
                                ? 'Ajukan permintaan ini?'
                                : decision === 'accept'
                                  ? 'Terima permintaan ini?'
                                  : 'Arsipkan permintaan ini?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {decision === 'submit'
                                ? 'Sesudah diajukan, isinya tidak dapat diubah lagi dan perencana pemeliharaan akan meninjaunya.'
                                : decision === 'accept'
                                  ? 'Permintaan yang diterima siap dibuatkan work order.'
                                  : 'Permintaan yang diarsipkan hilang dari daftar, tetapi catatannya tetap tersimpan.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                const chosen = decision;
                                setDecision(null);

                                if (chosen === 'archive') {
                                    void archive();
                                } else if (chosen === 'submit') {
                                    void step(
                                        'ajukan',
                                        {},
                                        'Permintaan diajukan.',
                                        'Permintaan belum dapat diajukan.',
                                    );
                                } else if (chosen === 'accept') {
                                    void step(
                                        'terima',
                                        {},
                                        'Permintaan diterima.',
                                        'Permintaan belum dapat diterima.',
                                    );
                                }
                            }}
                        >
                            {decision === 'submit'
                                ? 'Ajukan'
                                : decision === 'accept'
                                  ? 'Terima'
                                  : 'Arsipkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog open={rejecting} onOpenChange={setRejecting}>
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Tolak permintaan</DialogTitle>
                        <DialogDescription>
                            Alasan penolakan ditampilkan kepada pelapor.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody className="space-y-2">
                        <Textarea
                            label="Alasan penolakan"
                            required
                            maxLength={2000}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                        {message('alasan') && (
                            <p className="text-destructive text-sm">
                                {message('alasan')}
                            </p>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={busy}
                            onClick={() =>
                                void step(
                                    'tolak',
                                    { alasan: reason },
                                    'Permintaan ditolak.',
                                    'Permintaan belum dapat ditolak.',
                                ).then((done) => done && setRejecting(false))
                            }
                        >
                            Tolak
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={ordering} onOpenChange={setOrdering}>
                <DialogContent size="compact" ref={orderRef}>
                    <DialogHeader>
                        <DialogTitle>Buat work order</DialogTitle>
                        <DialogDescription>
                            Satu permintaan menjadi satu work order draf, dengan
                            nomor dan checklist bawaan jenis pekerjaannya.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody className="space-y-4">
                        {!saved?.aset_id && (
                            <Field>
                                <Select
                                    label="Aset"
                                    required
                                    items={choices(assets.options)}
                                    value={order.aset_id || null}
                                    placeholder="Pilih aset yang dikerjakan"
                                    searchPlaceholder="Cari kode atau nama aset"
                                    ariaLabel="Aset work order"
                                    portalContainer={orderRef}
                                    onValueChange={(value) =>
                                        setOrder({
                                            ...order,
                                            aset_id: value ?? '',
                                            maintenance_job_type_id: '',
                                        })
                                    }
                                />
                                <FieldDescription>
                                    {message('aset_id') ||
                                        assets.error ||
                                        'Pelapor hanya menyebut lokasinya; pilih aset yang akan dikerjakan.'}
                                </FieldDescription>
                            </Field>
                        )}
                        <Field>
                            <Select
                                label="Jenis pekerjaan"
                                required
                                items={choices(jobTypes.options)}
                                value={order.maintenance_job_type_id || null}
                                placeholder={
                                    orderAsset
                                        ? 'Pilih jenis pekerjaan'
                                        : 'Pilih aset lebih dahulu'
                                }
                                ariaLabel="Jenis pekerjaan"
                                portalContainer={orderRef}
                                onValueChange={(value) =>
                                    setOrder({
                                        ...order,
                                        maintenance_job_type_id: value ?? '',
                                    })
                                }
                            />
                            {(message('maintenance_job_type_id') ||
                                jobTypes.error) && (
                                <FieldDescription>
                                    {message('maintenance_job_type_id') ||
                                        jobTypes.error}
                                </FieldDescription>
                            )}
                        </Field>
                        <Field>
                            <Select
                                label="Tipe work order"
                                required
                                items={choices(workOrderTypes.options)}
                                value={order.tipe_work_order_id || null}
                                placeholder="Pilih tipe work order"
                                ariaLabel="Tipe work order"
                                portalContainer={orderRef}
                                onValueChange={(value) =>
                                    setOrder({
                                        ...order,
                                        tipe_work_order_id: value ?? '',
                                    })
                                }
                            />
                            {message('tipe_work_order_id') && (
                                <FieldDescription>
                                    {message('tipe_work_order_id')}
                                </FieldDescription>
                            )}
                        </Field>
                        <Field>
                            <Select
                                label="Bidang keahlian"
                                items={[
                                    { value: '', label: 'Tidak ditentukan' },
                                    ...choices(trades.options),
                                ]}
                                value={order.trade_id || null}
                                placeholder="Tidak ditentukan"
                                ariaLabel="Bidang keahlian"
                                portalContainer={orderRef}
                                onValueChange={(value) =>
                                    setOrder({
                                        ...order,
                                        trade_id: value ?? '',
                                    })
                                }
                            />
                        </Field>
                        {message('details') && (
                            <p className="text-destructive text-sm">
                                {message('details')}
                            </p>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={busy}
                            onClick={() =>
                                void step(
                                    'work-order',
                                    {
                                        aset_id: saved?.aset_id
                                            ? null
                                            : order.aset_id || null,
                                        maintenance_job_type_id:
                                            order.maintenance_job_type_id,
                                        trade_id: order.trade_id || null,
                                        tipe_work_order_id:
                                            order.tipe_work_order_id || null,
                                    },
                                    'Work order dibuat.',
                                    'Work order belum dapat dibuat.',
                                ).then((done) => done && setOrdering(false))
                            }
                        >
                            Buat work order
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
