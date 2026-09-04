import { useCallback, useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { DataTable, type DataTableColumn } from '@apperp/ui/data-table';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { toast } from 'sonner';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import EditShield from '../../_shared/EditShield';
import {
    ChecklistRow,
    Context,
    EditableWorkOrder,
    JobLine,
    Option,
    StatusBadge,
    TRANSISI,
    WorkOrder,
    bukaDaftar,
    bukaWorkOrder,
    bukaChecklistJob,
    emptyJob,
    emptyWorkOrder,
    idDari,
    izin,
    labelDari,
} from './workOrder';

type Mode = 'view' | 'edit' | 'create';

function JobRow({
    job,
    index,
    canRemove,
    readOnly,
    onRequestEdit,
    assets,
    assetItems,
    jobTypes,
    trades,
    onAssetSearch,
    onChange,
    onRemove,
}: {
    job: JobLine;
    index: number;
    canRemove: boolean;
    /** Mode baca: nilai tetap tampil utuh, tetapi belum dapat diubah. */
    readOnly: boolean;
    /** Dipanggil saat pengguna menyentuh baris ini selagi mode baca. */
    onRequestEdit: () => void;
    /** Daftar penuh; dipakai untuk menampilkan aset yang sedang terpilih walau tersaring keluar. */
    assets: Option[];
    /** Daftar yang lolos pencarian; hanya mengisi pilihan dropdown. */
    assetItems: Option[];
    jobTypes: Option[];
    trades: Option[];
    onAssetSearch: (query: string) => void;
    onChange: (change: Partial<JobLine>) => void;
    onRemove: () => void;
}) {
    const [variants, setVariants] = useState<Option[]>([]);

    useEffect(() => {
        if (!job.maintenance_job_type_id) {
            setVariants([]);
            return;
        }
        api<{ data: Option[] }>(`/maintenance-job-types/${job.maintenance_job_type_id}/variants`)
            .then((result) => setVariants(result.data))
            .catch(() => setVariants([]));
    }, [job.maintenance_job_type_id]);

    const pilih = (options: Option[], id: string) => options.find((option) => option.id === id);
    const teks = (label: string) => ({
        readOnly,
        onFocus: readOnly ? onRequestEdit : undefined,
        'aria-label': label,
    });

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex justify-between">
                <span className="text-sm font-medium">Baris {index + 1}</span>
                {canRemove && !readOnly && (
                    <Button type="button" variant="ghost" size="sm" onClick={onRemove}>
                        Hapus
                    </Button>
                )}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field>
                    <EditShield active={readOnly} label="aset" onActivate={onRequestEdit}>
                        <Select
                            label="Aset"
                            required
                            items={assetItems.map((asset) => labelDari(asset) ?? '')}
                            value={labelDari(pilih(assets, job.asset_id))}
                            placeholder="Pilih aset"
                            searchPlaceholder="Cari kode atau nama aset"
                            ariaLabel={`Aset baris ${index + 1}`}
                            onSearchChange={onAssetSearch}
                            onValueChange={(value) =>
                                onChange({
                                    asset_id: idDari(assets, value),
                                    maintenance_job_type_id: '',
                                    variant_id: '',
                                })
                            }
                        />
                    </EditShield>
                </Field>
                <Field>
                    <EditShield
                        active={readOnly}
                        label="jenis pekerjaan"
                        onActivate={onRequestEdit}
                    >
                        <Select
                            label="Jenis pekerjaan"
                            required
                            items={jobTypes.map((type) => labelDari(type) ?? '')}
                            value={labelDari(pilih(jobTypes, job.maintenance_job_type_id))}
                            placeholder="Pilih jenis pekerjaan"
                            searchPlaceholder="Cari jenis pekerjaan"
                            ariaLabel={`Jenis pekerjaan baris ${index + 1}`}
                            onValueChange={(value) =>
                                onChange({
                                    maintenance_job_type_id: idDari(jobTypes, value),
                                    variant_id: '',
                                })
                            }
                        />
                    </EditShield>
                </Field>
                <Field>
                    <EditShield
                        active={readOnly}
                        label="varian pekerjaan"
                        onActivate={onRequestEdit}
                    >
                        <Select
                            label="Varian pekerjaan"
                            items={variants.map((variant) => labelDari(variant) ?? '')}
                            value={labelDari(pilih(variants, job.variant_id))}
                            placeholder={
                                job.maintenance_job_type_id
                                    ? 'Pilih varian bila diperlukan'
                                    : 'Pilih jenis pekerjaan dahulu'
                            }
                            searchPlaceholder="Cari varian pekerjaan"
                            ariaLabel={`Varian pekerjaan baris ${index + 1}`}
                            onValueChange={(value) =>
                                onChange({ variant_id: idDari(variants, value) })
                            }
                        />
                    </EditShield>
                </Field>
                <Field>
                    <EditShield
                        active={readOnly}
                        label="bidang keahlian"
                        onActivate={onRequestEdit}
                    >
                        <Select
                            label="Bidang keahlian"
                            items={trades.map((trade) => labelDari(trade) ?? '')}
                            value={labelDari(pilih(trades, job.trade_id))}
                            placeholder="Pilih bidang keahlian"
                            searchPlaceholder="Cari bidang keahlian"
                            ariaLabel={`Bidang keahlian baris ${index + 1}`}
                            onValueChange={(value) => onChange({ trade_id: idDari(trades, value) })}
                        />
                    </EditShield>
                </Field>
                <Field>
                    <Input
                        label="Estimasi jam"
                        type="number"
                        min="0"
                        step="0.25"
                        value={job.estimasi_jam}
                        onChange={(event) => onChange({ estimasi_jam: event.target.value })}
                        {...teks(`Estimasi jam baris ${index + 1}`)}
                    />
                </Field>
                <Field>
                    <Input
                        label="Jadwal mulai"
                        type="datetime-local"
                        value={job.dijadwalkan_mulai?.replace(' ', 'T').slice(0, 16) ?? ''}
                        onChange={(event) => onChange({ dijadwalkan_mulai: event.target.value })}
                        {...teks(`Jadwal mulai baris ${index + 1}`)}
                    />
                </Field>
                <Field>
                    <Input
                        label="Jadwal selesai"
                        type="datetime-local"
                        value={job.dijadwalkan_selesai?.replace(' ', 'T').slice(0, 16) ?? ''}
                        onChange={(event) => onChange({ dijadwalkan_selesai: event.target.value })}
                        {...teks(`Jadwal selesai baris ${index + 1}`)}
                    />
                </Field>
            </div>
            <Field>
                <FieldLabel htmlFor={`job-catatan-${index}`}>Catatan</FieldLabel>
                <Textarea
                    id={`job-catatan-${index}`}
                    rows={2}
                    value={job.catatan}
                    readOnly={readOnly}
                    onFocus={readOnly ? onRequestEdit : undefined}
                    onChange={(event) => onChange({ catatan: event.target.value })}
                />
            </Field>
        </div>
    );
}

/**
 * Layar pengisian checklist — inilah yang dilihat teknisi.
 *
 * Ia hanya menampilkan pertanyaan berbahasa manusia beserta cara menjawabnya. Tidak ada
 * ID, tidak ada istilah tabel, dan tidak ada apa pun berbau akuntansi.
 */
function ChecklistPanel({
    rows,
    editable,
    saving,
    onClose,
    onSave,
    onChange,
}: {
    rows: ChecklistRow[];
    editable: boolean;
    saving: boolean;
    onClose: () => void;
    onSave: () => void;
    onChange: (id: string, change: Partial<ChecklistRow>) => void;
}) {
    return (
        <div className="border-t bg-muted/20 p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold">Checklist pemeriksaan</h3>
                    <p className="text-sm text-muted-foreground">
                        Isi pemeriksaan di halaman ini sebelum pekerjaan dinyatakan selesai.
                    </p>
                </div>
                <Button type="button" variant="outline" onClick={onClose}>
                    Tutup checklist
                </Button>
            </div>
            <div className="mt-4 space-y-4">
                {!rows.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada baris pemeriksaan</EmptyTitle>
                            <EmptyDescription>
                                Susun checklist dari template sebelum pekerjaan dimulai.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    rows.map((row) => (
                        <div key={row.id} className="space-y-2 rounded-lg border p-3">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        {row.nama}
                                        {row.wajib && <span className="text-destructive"> *</span>}
                                    </p>
                                    {row.instruksi && (
                                        <p className="text-sm text-muted-foreground">
                                            {row.instruksi}
                                        </p>
                                    )}
                                    {row.tipe === 'measurement' &&
                                        row.min_value !== null &&
                                        row.max_value !== null && (
                                            <p className="text-sm text-muted-foreground">
                                                Rentang lulus: {row.min_value}–{row.max_value}
                                                {row.satuan ? ` ${row.satuan}` : ''}.
                                            </p>
                                        )}
                                    {row.tipe === 'variable' &&
                                        row.pilihan.some(
                                            (choice) => choice.result_code === 'none',
                                        ) && (
                                            <p className="text-sm text-muted-foreground">
                                                Jika memilih jawaban Tidak dinilai, isi alasan pada
                                                catatan teknisi.
                                            </p>
                                        )}
                                </div>
                                {editable && (
                                    <div className="flex shrink-0 items-center gap-2">
                                        <span className="text-sm text-muted-foreground">
                                            Tidak berlaku
                                        </span>
                                        <Switch
                                            checked={row.tidak_berlaku}
                                            onCheckedChange={(checked) =>
                                                onChange(row.id, {
                                                    tidak_berlaku: checked,
                                                    nilai: checked ? null : row.nilai,
                                                })
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                            {row.tipe === 'header' ? null : row.tidak_berlaku ? (
                                <p className="text-sm text-muted-foreground">
                                    Pemeriksaan ini ditandai tidak berlaku untuk aset ini.
                                </p>
                            ) : row.tipe === 'variable' ? (
                                editable ? (
                                    <Select
                                        label="Jawaban"
                                        items={row.pilihan.map((choice) => choice.value)}
                                        value={row.nilai}
                                        placeholder="Pilih jawaban"
                                        searchPlaceholder="Cari jawaban"
                                        ariaLabel={`Jawaban ${row.nama}`}
                                        onValueChange={(value) =>
                                            onChange(row.id, { nilai: value })
                                        }
                                    />
                                ) : (
                                    <p className="text-sm">Jawaban: {row.nilai ?? 'Belum diisi'}</p>
                                )
                            ) : editable ? (
                                <Input
                                    label={
                                        row.tipe === 'measurement'
                                            ? `Nilai${row.satuan ? ` (${row.satuan})` : ''}`
                                            : 'Jawaban'
                                    }
                                    type={row.tipe === 'measurement' ? 'number' : 'text'}
                                    step="any"
                                    value={row.nilai ?? ''}
                                    onChange={(event) =>
                                        onChange(row.id, { nilai: event.target.value })
                                    }
                                />
                            ) : (
                                <p className="text-sm">Jawaban: {row.nilai ?? 'Belum diisi'}</p>
                            )}
                            {editable ? (
                                <Field>
                                    <FieldLabel htmlFor={`catatan-${row.id}`}>
                                        Catatan teknisi
                                        {row.pilihan.some(
                                            (choice) =>
                                                choice.value === row.nilai &&
                                                choice.result_code === 'none',
                                        )
                                            ? ' (wajib)'
                                            : ''}
                                    </FieldLabel>
                                    <Textarea
                                        id={`catatan-${row.id}`}
                                        rows={2}
                                        value={row.catatan_teknisi ?? ''}
                                        onChange={(event) =>
                                            onChange(row.id, {
                                                catatan_teknisi: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            ) : (
                                row.catatan_teknisi && (
                                    <p className="text-sm text-muted-foreground">
                                        Catatan teknisi: {row.catatan_teknisi}
                                    </p>
                                )
                            )}
                        </div>
                    ))
                )}
            </div>
            <div className="mt-4 flex justify-end gap-2 border-t pt-4">
                {editable && rows.length > 0 && (
                    <Button type="button" disabled={saving} onClick={onSave}>
                        {saving ? 'Menyimpan…' : 'Simpan hasil'}
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * Rincian satu work order pada alamatnya sendiri.
 *
 * Halaman ini terbuka dalam mode baca dan berpindah ke mode sunting hanya lewat tombol
 * Ubah, sama seperti master. Yang menentukan mode dan checklist yang terbuka adalah hash
 * di alamat, bukan state internal, sehingga tombol kembali peramban membatalkan sunting
 * alih-alih melompat keluar dari aplikasi.
 */
export default function WorkOrderDetailPage({
    context,
    permissions,
    workOrderId,
    mode,
    checklistJobId,
}: {
    context: Context;
    permissions: string[];
    /** Kosong berarti work order baru. */
    workOrderId?: string;
    mode: Mode;
    checklistJobId?: string;
}) {
    const can = izin(permissions);
    const [record, setRecord] = useState<EditableWorkOrder | undefined>(
        mode === 'create' ? emptyWorkOrder() : undefined,
    );
    const [tipe, setTipe] = useState<Option[]>([]);
    const [layanan, setLayanan] = useState<Option[]>([]);
    const [assets, setAssets] = useState<Option[]>([]);
    const [jobTypesByAsset, setJobTypesByAsset] = useState<Record<string, Option[]>>({});
    const [trades, setTrades] = useState<Option[]>([]);
    const [faultCauses, setFaultCauses] = useState<Option[]>([]);
    const [repairActions, setRepairActions] = useState<Option[]>([]);
    const [assetSearch, setAssetSearch] = useState('');
    const [checklist, setChecklist] = useState<ChecklistRow[] | undefined>();
    const [saving, setSaving] = useState(false);

    const loadJobTypesForAsset = useCallback(async (assetId: string) => {
        if (!assetId) return;
        try {
            const result = await api<{ data: Option[] }>(
                `/pemeliharaan-aset/referensi/job-types?asset_id=${encodeURIComponent(assetId)}`,
            );
            setJobTypesByAsset((current) => ({ ...current, [assetId]: result.data }));
        } catch {
            toast.error('Jenis pekerjaan untuk aset belum dapat dimuat.');
        }
    }, []);

    const load = useCallback(
        async (id: string) => {
            try {
                const result = await api<{ data: WorkOrder }>(`/pemeliharaan-aset/${id}`);
                const details = (result.data.details ?? []).map((job, index) => ({
                    ...job,
                    line_number: job.line_number ?? index + 1,
                    trade_id: job.trade_id ?? '',
                    variant_id: job.variant_id ?? '',
                    ditugaskan_ke_user_id: job.ditugaskan_ke_user_id ?? '',
                    estimasi_jam: job.estimasi_jam ? String(job.estimasi_jam) : '',
                    dijadwalkan_mulai: job.dijadwalkan_mulai ?? '',
                    dijadwalkan_selesai: job.dijadwalkan_selesai ?? '',
                    sebab_kerusakan_id: job.sebab_kerusakan_id ?? '',
                    tindakan_perbaikan_id: job.tindakan_perbaikan_id ?? '',
                    sebab_kerusakan_keterangan: job.sebab_kerusakan_keterangan ?? '',
                    tindakan_perbaikan_keterangan: job.tindakan_perbaikan_keterangan ?? '',
                    catatan: job.catatan ?? '',
                }));
                await Promise.all(details.map((job) => loadJobTypesForAsset(job.asset_id)));
                setRecord({
                    ...result.data,
                    tingkat_layanan_id: result.data.tingkat_layanan_id ?? '',
                    diharapkan_mulai: result.data.diharapkan_mulai ?? '',
                    diharapkan_selesai: result.data.diharapkan_selesai ?? '',
                    details,
                });
            } catch (caught) {
                toast.error(errorMessage(caught, 'Work order belum dapat dibuka.'));
            }
        },
        [loadJobTypesForAsset],
    );

    // `mode` ikut jadi pemicu supaya kembali ke mode baca berarti membaca ulang dari
    // server. Itulah yang membuang suntingan yang batal: tidak ada salinan lama yang
    // masih menempel saat pengguna membuka work order yang sama lagi.
    useEffect(() => {
        if (!workOrderId) {
            setRecord(emptyWorkOrder());
            return;
        }
        void load(workOrderId);
    }, [load, mode, workOrderId]);

    // Referensi hanya dimuat bila pengguna memang dapat menyusun work order.
    useEffect(() => {
        if (!can('create') && !can('update') && !can('execute')) return;
        const muat = (path: string, set: (options: Option[]) => void, gagal: string) =>
            api<{ data: Option[] }>(path)
                .then((result) => set(result.data))
                .catch(() => toast.error(gagal));
        void muat(
            '/tipe-work-order?per_page=100&aktif=true',
            setTipe,
            'Tipe work order belum dapat dimuat.',
        );
        void muat(
            '/tingkat-layanan?per_page=100&aktif=true',
            setLayanan,
            'Tingkat layanan belum dapat dimuat.',
        );
        void muat(
            '/trade?per_page=100&aktif=true',
            setTrades,
            'Bidang keahlian belum dapat dimuat.',
        );
        void muat(
            '/sebab-kerusakan?per_page=100&aktif=true',
            setFaultCauses,
            'Sebab kerusakan belum dapat dimuat.',
        );
        void muat(
            '/tindakan-perbaikan?per_page=100&aktif=true',
            setRepairActions,
            'Tindakan perbaikan belum dapat dimuat.',
        );
        // Register aset dikembalikan utuh oleh `/aset` tanpa parameter pencarian, jadi
        // penyaringan dilakukan di sini. Mengirim `q` ke server hanya akan diabaikan diam-diam
        // dan membuat kotak pencarian terlihat bekerja padahal tidak.
        void muat('/aset', setAssets, 'Aset belum dapat dimuat.');
    }, [permissions.join(',')]);

    // Checklist yang terbuka ikut alamat: menutupnya berarti kembali ke alamat rincian,
    // sehingga tombol kembali peramban tidak membuka ulang checklist yang sudah ditutup.
    useEffect(() => {
        if (!workOrderId || !checklistJobId) {
            setChecklist(undefined);
            return;
        }
        let dibatalkan = false;
        api<{ data: ChecklistRow[] }>(
            `/pemeliharaan-aset/${workOrderId}/jobs/${checklistJobId}/checklist`,
        )
            .then((result) => {
                if (!dibatalkan) setChecklist(result.data);
            })
            .catch((caught) => toast.error(errorMessage(caught, 'Checklist belum dapat dibuka.')));

        return () => {
            dibatalkan = true;
        };
    }, [workOrderId, checklistJobId]);

    const assetQuery = assetSearch.trim().toLowerCase();
    const assetTersaring =
        assetQuery === ''
            ? assets
            : assets.filter((asset) =>
                  `${asset.kode} ${asset.nama ?? ''}`.toLowerCase().includes(assetQuery),
              );

    const pindahStatus = async (ke: string, label: string) => {
        if (!record?.id || !record.kode) return;
        const alasan =
            ke === 'dibatalkan' ? window.prompt(`Alasan membatalkan ${record.kode}?`) : null;
        if (ke === 'dibatalkan' && !alasan?.trim()) return;
        try {
            await api(`/pemeliharaan-aset/${record.id}/status`, {
                method: 'POST',
                body: JSON.stringify({ ke_status: ke, version: record.version, alasan }),
            });
            await load(record.id);
            toast.success(`${record.kode} — ${label.toLowerCase()} berhasil.`);
        } catch (caught) {
            toast.error(errorMessage(caught, `${record.kode} belum dapat dipindahkan statusnya.`));
        }
    };

    const simpanChecklist = async () => {
        if (!checklist || !workOrderId || !checklistJobId) return;
        setSaving(true);
        try {
            await api(`/pemeliharaan-aset/${workOrderId}/jobs/${checklistJobId}/checklist`, {
                method: 'PUT',
                body: JSON.stringify({
                    baris: checklist.map((row) => ({
                        id: row.id,
                        nilai: row.nilai,
                        tidak_berlaku: row.tidak_berlaku,
                        catatan_teknisi: row.catatan_teknisi,
                    })),
                }),
            });
            toast.success('Hasil pemeriksaan tersimpan.');
            bukaWorkOrder(workOrderId);
        } catch (caught) {
            toast.error(errorMessage(caught, 'Hasil pemeriksaan belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const simpanPelaksanaan = async (job: JobLine) => {
        if (!record?.id || !job.id) return;
        setSaving(true);
        try {
            await api(`/pemeliharaan-aset/${record.id}/jobs/${job.id}/execution`, {
                method: 'PATCH',
                body: JSON.stringify({
                    aktual_jam: job.aktual_jam ?? null,
                    sebab_kerusakan_id: job.sebab_kerusakan_id || null,
                    tindakan_perbaikan_id: job.tindakan_perbaikan_id || null,
                    sebab_kerusakan_keterangan: job.sebab_kerusakan_keterangan || null,
                    tindakan_perbaikan_keterangan: job.tindakan_perbaikan_keterangan || null,
                }),
            });
            toast.success('Hasil pekerjaan tersimpan.');
            await load(record.id);
        } catch (caught) {
            toast.error(errorMessage(caught, 'Hasil pekerjaan belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const save = async () => {
        if (!record || !context.legal_entity_id || !context.org_unit_id) {
            toast.error('Pilih entitas legal dan unit kerja aktif sebelum membuat work order.');

            return;
        }
        if (!record.tipe_work_order_id) {
            toast.error('Pilih tipe work order lebih dahulu.');

            return;
        }
        if (!record.details.every((job) => job.asset_id && job.maintenance_job_type_id)) {
            toast.error('Pilih aset dan jenis pekerjaan pada setiap baris.');

            return;
        }

        setSaving(true);
        const body = {
            legal_entity_id: context.legal_entity_id,
            responsible_org_unit_id: context.org_unit_id,
            tipe_work_order_id: record.tipe_work_order_id,
            tingkat_layanan_id: record.tingkat_layanan_id || null,
            keterangan: record.keterangan || null,
            diharapkan_mulai: record.diharapkan_mulai || null,
            diharapkan_selesai: record.diharapkan_selesai || null,
            dijadwalkan_mulai: record.dijadwalkan_mulai || null,
            dijadwalkan_selesai: record.dijadwalkan_selesai || null,
            details: record.details.map((job) => ({
                asset_id: job.asset_id,
                maintenance_job_type_id: job.maintenance_job_type_id,
                variant_id: job.variant_id || null,
                trade_id: job.trade_id || null,
                ditugaskan_ke_user_id: job.ditugaskan_ke_user_id || null,
                estimasi_jam: job.estimasi_jam ? Number(job.estimasi_jam) : null,
                dijadwalkan_mulai: job.dijadwalkan_mulai || null,
                dijadwalkan_selesai: job.dijadwalkan_selesai || null,
                catatan: job.catatan || null,
            })),
        };

        try {
            if (record.id) {
                await api(`/pemeliharaan-aset/${record.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ ...body, version: record.version }),
                });
                toast.success('Work order disimpan.');
                bukaWorkOrder(record.id);
            } else {
                const dibuat = await api<{ data: WorkOrder }>('/pemeliharaan-aset', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': newIdempotencyKey() },
                    body: JSON.stringify(body),
                });
                toast.success('Work order disimpan.');
                bukaWorkOrder(dibuat.data.id);
            }
        } catch (caught) {
            toast.error(errorMessage(caught, 'Work order belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    if (!record) {
        return (
            <div className="flex h-full items-center justify-center p-10">
                <Empty>
                    <EmptyDescription>Membuka work order…</EmptyDescription>
                </Empty>
            </div>
        );
    }

    const status = record.status;
    const draft = status === undefined || status === 'draft';
    /** Hanya draf yang boleh disunting; setelah dijadwalkan, isinya milik pelaksanaan. */
    const dapatDisunting = draft && (mode === 'create' ? can('create') : can('update'));
    // Alamat sunting yang diketik sendiri tidak memberi hak apa pun: bila record ini
    // memang tidak boleh disunting, halaman tetap terbuka dalam mode baca dan tombol
    // Simpan tidak pernah muncul untuk permintaan yang pasti ditolak server.
    const editing = mode !== 'view' && dapatDisunting;
    const readOnly = !editing;
    const canEditExecution = can('execute') && (status === 'dikerjakan' || status === 'selesai');
    const mintaSunting = () => {
        if (!dapatDisunting || editing || !record.id) return;
        window.location.hash = `#/pemeliharaan-aset/${record.id}/ubah`;
    };

    const updateJob = (jobId: string | undefined, change: Partial<JobLine>) =>
        setRecord(
            (current) =>
                current && {
                    ...current,
                    details: current.details.map((job) =>
                        job.id === jobId ? { ...job, ...change } : job,
                    ),
                },
        );

    const jobColumns: DataTableColumn<JobLine>[] = [
        {
            id: 'baris',
            header: 'Baris',
            cell: (job) => job.line_number ?? '—',
            sortValue: (job) => job.line_number ?? 0,
            align: 'center',
            width: 70,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (job) => (
                <span className="font-medium text-primary">{job.asset_kode ?? '—'}</span>
            ),
            sortValue: (job) => job.asset_kode ?? '',
            width: 150,
        },
        {
            id: 'pekerjaan',
            header: 'Jenis pekerjaan',
            cell: (job) => job.job_type_nama ?? '—',
            sortValue: (job) => job.job_type_nama ?? '',
            width: 190,
        },
        {
            id: 'varian',
            header: 'Varian',
            cell: (job) => job.variant_nama ?? '—',
            sortValue: (job) => job.variant_nama ?? '',
            width: 150,
        },
        {
            id: 'bidang',
            header: 'Bidang keahlian',
            cell: (job) => job.trade_nama ?? '—',
            sortValue: (job) => job.trade_nama ?? '',
            width: 170,
        },
        {
            id: 'mulai',
            header: 'Mulai terjadwal',
            cell: (job) => job.dijadwalkan_mulai ?? '—',
            sortValue: (job) => job.dijadwalkan_mulai ?? '',
            width: 170,
        },
        {
            id: 'aktual',
            header: 'Jam aktual',
            cell: (job) =>
                canEditExecution ? (
                    <Input
                        aria-label={`Jam aktual ${job.asset_kode ?? ''}`}
                        type="number"
                        min="0"
                        step="0.25"
                        value={job.aktual_jam ?? ''}
                        onChange={(event) =>
                            updateJob(job.id, {
                                aktual_jam:
                                    event.target.value === '' ? null : Number(event.target.value),
                            })
                        }
                    />
                ) : (
                    (job.aktual_jam ?? '—')
                ),
            sortValue: (job) => job.aktual_jam ?? 0,
            width: 130,
        },
        {
            id: 'sebab',
            header: 'Sebab kerusakan',
            cell: (job) => {
                const selected = faultCauses.find((option) => option.id === job.sebab_kerusakan_id);
                return canEditExecution ? (
                    <div className="flex flex-col gap-2">
                        <Select
                            items={faultCauses.map((option) => labelDari(option) ?? '')}
                            value={labelDari(selected)}
                            placeholder="Pilih bila ada"
                            searchPlaceholder="Cari sebab kerusakan"
                            ariaLabel={`Sebab kerusakan ${job.asset_kode ?? ''}`}
                            onValueChange={(value) => {
                                const id = idDari(faultCauses, value) || null;
                                const mintaKeterangan = faultCauses.find(
                                    (option) => option.id === id,
                                )?.minta_keterangan;
                                updateJob(job.id, {
                                    sebab_kerusakan_id: id,
                                    ...(!mintaKeterangan && { sebab_kerusakan_keterangan: null }),
                                });
                            }}
                        />
                        {selected?.minta_keterangan && (
                            <Input
                                aria-label={`Keterangan sebab kerusakan ${job.asset_kode ?? ''}`}
                                placeholder="Tulis sebab kerusakan"
                                value={job.sebab_kerusakan_keterangan ?? ''}
                                onChange={(event) =>
                                    updateJob(job.id, {
                                        sebab_kerusakan_keterangan: event.target.value,
                                    })
                                }
                            />
                        )}
                    </div>
                ) : (
                    <span>
                        {labelDari(selected) ?? job.sebab_kerusakan_nama ?? 'Belum diisi'}
                        {job.sebab_kerusakan_keterangan
                            ? ` — ${job.sebab_kerusakan_keterangan}`
                            : ''}
                    </span>
                );
            },
            width: 190,
        },
        {
            id: 'tindakan',
            header: 'Tindakan perbaikan',
            cell: (job) => {
                const selected = repairActions.find(
                    (option) => option.id === job.tindakan_perbaikan_id,
                );
                return canEditExecution ? (
                    <div className="flex flex-col gap-2">
                        <Select
                            items={repairActions.map((option) => labelDari(option) ?? '')}
                            value={labelDari(selected)}
                            placeholder="Pilih bila ada"
                            searchPlaceholder="Cari tindakan perbaikan"
                            ariaLabel={`Tindakan perbaikan ${job.asset_kode ?? ''}`}
                            onValueChange={(value) => {
                                const id = idDari(repairActions, value) || null;
                                const mintaKeterangan = repairActions.find(
                                    (option) => option.id === id,
                                )?.minta_keterangan;
                                updateJob(job.id, {
                                    tindakan_perbaikan_id: id,
                                    ...(!mintaKeterangan && {
                                        tindakan_perbaikan_keterangan: null,
                                    }),
                                });
                            }}
                        />
                        {selected?.minta_keterangan && (
                            <Input
                                aria-label={`Keterangan tindakan perbaikan ${job.asset_kode ?? ''}`}
                                placeholder="Tulis tindakan perbaikan"
                                value={job.tindakan_perbaikan_keterangan ?? ''}
                                onChange={(event) =>
                                    updateJob(job.id, {
                                        tindakan_perbaikan_keterangan: event.target.value,
                                    })
                                }
                            />
                        )}
                    </div>
                ) : (
                    <span>
                        {labelDari(selected) ?? job.tindakan_perbaikan_nama ?? 'Belum diisi'}
                        {job.tindakan_perbaikan_keterangan
                            ? ` — ${job.tindakan_perbaikan_keterangan}`
                            : ''}
                    </span>
                );
            },
            width: 200,
        },
        {
            id: 'hasil',
            header: 'Hasil',
            cell: (job) => (
                <div className="flex items-center gap-2">
                    <span>{job.hasil ?? 'Belum dihitung'}</span>
                    {canEditExecution && (
                        <Button
                            type="button"
                            size="sm"
                            disabled={saving}
                            onClick={() => void simpanPelaksanaan(job)}
                        >
                            Simpan
                        </Button>
                    )}
                </div>
            ),
            width: 190,
        },
    ];

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar
                title={record.id ? `Work order ${record.kode}` : 'Work order baru'}
                trailing={status ? <StatusBadge status={status} /> : undefined}
            >
                {editing ? (
                    <>
                        <Button type="button" disabled={saving} onClick={() => void save()}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => (record.id ? bukaWorkOrder(record.id) : bukaDaftar())}
                        >
                            Batal
                        </Button>
                    </>
                ) : (
                    <>
                        {dapatDisunting && record.id && (
                            <ActionButton action="edit" type="button" onClick={mintaSunting}>
                                Ubah
                            </ActionButton>
                        )}
                        {record.id &&
                            status &&
                            (TRANSISI[status] ?? [])
                                .filter((transisi) => can(transisi.izin))
                                .map((transisi) => (
                                    <Button
                                        key={transisi.ke}
                                        type="button"
                                        variant={
                                            transisi.ke === 'dibatalkan' ? 'destructive' : 'default'
                                        }
                                        onClick={() =>
                                            void pindahStatus(transisi.ke, transisi.label)
                                        }
                                    >
                                        {transisi.label}
                                    </Button>
                                ))}
                        <Button type="button" variant="outline" onClick={bukaDaftar}>
                            Kembali ke daftar
                        </Button>
                    </>
                )}
            </RecordActionBar>

            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="space-y-4 p-5">
                    <p className="text-sm text-muted-foreground">
                        Entitas legal dan unit penanggung jawab mengikuti konteks aktif Anda.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <EditShield
                                active={readOnly}
                                label="tipe work order"
                                onActivate={mintaSunting}
                            >
                                <Select
                                    label="Tipe work order"
                                    required
                                    items={tipe.map((option) => labelDari(option) ?? '')}
                                    value={labelDari(
                                        tipe.find(
                                            (option) => option.id === record.tipe_work_order_id,
                                        ),
                                    )}
                                    placeholder="Pilih tipe work order"
                                    searchPlaceholder="Cari tipe work order"
                                    ariaLabel="Tipe work order"
                                    onValueChange={(value) =>
                                        setRecord({
                                            ...record,
                                            tipe_work_order_id: idDari(tipe, value),
                                        })
                                    }
                                />
                            </EditShield>
                            <FieldDescription>
                                Tipe menentukan apa yang wajib diisi sebelum pekerjaan boleh
                                dinyatakan selesai.
                            </FieldDescription>
                        </Field>
                        <Field>
                            <EditShield
                                active={readOnly}
                                label="tingkat layanan"
                                onActivate={mintaSunting}
                            >
                                <Select
                                    label="Tingkat layanan"
                                    items={layanan.map((option) => labelDari(option) ?? '')}
                                    value={labelDari(
                                        layanan.find(
                                            (option) => option.id === record.tingkat_layanan_id,
                                        ),
                                    )}
                                    placeholder="Pilih tingkat layanan"
                                    searchPlaceholder="Cari tingkat layanan"
                                    ariaLabel="Tingkat layanan"
                                    onValueChange={(value) =>
                                        setRecord({
                                            ...record,
                                            tingkat_layanan_id: idDari(layanan, value),
                                        })
                                    }
                                />
                            </EditShield>
                        </Field>
                        <Field>
                            <Input
                                label="Diharapkan mulai"
                                type="datetime-local"
                                readOnly={readOnly}
                                onFocus={readOnly ? mintaSunting : undefined}
                                value={
                                    record.diharapkan_mulai?.replace(' ', 'T').slice(0, 16) ?? ''
                                }
                                onChange={(event) =>
                                    setRecord({ ...record, diharapkan_mulai: event.target.value })
                                }
                            />
                            <FieldDescription>
                                Batas waktu yang diharapkan untuk memulai pekerjaan.
                            </FieldDescription>
                        </Field>
                        <Field>
                            <Input
                                label="Diharapkan selesai"
                                type="datetime-local"
                                readOnly={readOnly}
                                onFocus={readOnly ? mintaSunting : undefined}
                                value={
                                    record.diharapkan_selesai?.replace(' ', 'T').slice(0, 16) ?? ''
                                }
                                onChange={(event) =>
                                    setRecord({ ...record, diharapkan_selesai: event.target.value })
                                }
                            />
                            <FieldDescription>
                                Batas waktu yang diharapkan untuk menyelesaikan pekerjaan.
                            </FieldDescription>
                        </Field>
                        <Field>
                            <Input
                                label="Dijadwalkan mulai"
                                type="datetime-local"
                                readOnly={readOnly}
                                onFocus={readOnly ? mintaSunting : undefined}
                                value={
                                    record.dijadwalkan_mulai?.replace(' ', 'T').slice(0, 16) ?? ''
                                }
                                onChange={(event) =>
                                    setRecord({ ...record, dijadwalkan_mulai: event.target.value })
                                }
                            />
                            <FieldDescription>
                                Harus diisi sebelum work order dapat dijadwalkan.
                            </FieldDescription>
                        </Field>
                        <Field>
                            <Input
                                label="Dijadwalkan selesai"
                                type="datetime-local"
                                readOnly={readOnly}
                                onFocus={readOnly ? mintaSunting : undefined}
                                value={
                                    record.dijadwalkan_selesai?.replace(' ', 'T').slice(0, 16) ?? ''
                                }
                                onChange={(event) =>
                                    setRecord({
                                        ...record,
                                        dijadwalkan_selesai: event.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <Field>
                        <FieldLabel htmlFor="work-order-keterangan">Keterangan</FieldLabel>
                        <Textarea
                            id="work-order-keterangan"
                            rows={4}
                            readOnly={readOnly}
                            onFocus={readOnly ? mintaSunting : undefined}
                            value={record.keterangan ?? ''}
                            onChange={(event) =>
                                setRecord({ ...record, keterangan: event.target.value })
                            }
                        />
                    </Field>
                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">Baris pekerjaan</h3>
                            {draft && editing && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setRecord({
                                            ...record,
                                            details: [...record.details, emptyJob()],
                                        })
                                    }
                                >
                                    Tambah baris
                                </Button>
                            )}
                        </div>
                        {!draft ? (
                            <DataTable
                                columns={jobColumns}
                                data={record.details}
                                getRowKey={(job) => String(job.id)}
                                getRowLabel={(job) => job.asset_kode ?? 'baris pekerjaan'}
                                actions={[{ id: 'checklist', label: 'Buka checklist' }]}
                                onRowAction={(action, job) => {
                                    if (action === 'checklist' && record.id)
                                        bukaChecklistJob(record.id, String(job.id));
                                }}
                            />
                        ) : (
                            record.details.map((job, index) => (
                                <JobRow
                                    key={index}
                                    job={job}
                                    index={index}
                                    canRemove={record.details.length > 1}
                                    readOnly={readOnly}
                                    onRequestEdit={mintaSunting}
                                    assets={assets}
                                    assetItems={assetTersaring}
                                    jobTypes={jobTypesByAsset[job.asset_id] ?? []}
                                    trades={trades}
                                    onAssetSearch={setAssetSearch}
                                    onChange={(change) => {
                                        if ('asset_id' in change && change.asset_id)
                                            void loadJobTypesForAsset(change.asset_id);
                                        setRecord({
                                            ...record,
                                            details: record.details.map((current, position) =>
                                                position === index
                                                    ? { ...current, ...change }
                                                    : current,
                                            ),
                                        });
                                    }}
                                    onRemove={() =>
                                        setRecord({
                                            ...record,
                                            details: record.details.filter(
                                                (_, position) => position !== index,
                                            ),
                                        })
                                    }
                                />
                            ))
                        )}
                    </div>
                </div>

                {checklist && (
                    <ChecklistPanel
                        rows={checklist}
                        editable={status === 'dikerjakan'}
                        saving={saving}
                        onClose={() => {
                            if (workOrderId) bukaWorkOrder(workOrderId);
                        }}
                        onSave={() => void simpanChecklist()}
                        onChange={(id, change) =>
                            setChecklist((current) =>
                                current?.map((row) =>
                                    row.id === id ? { ...row, ...change } : row,
                                ),
                            )
                        }
                    />
                )}
            </div>
        </div>
    );
}
