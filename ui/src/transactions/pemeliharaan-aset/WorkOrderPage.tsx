import { type RefObject, useEffect, useRef, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@apperp/ui/sheet';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { toast } from 'sonner';
import { api, errorMessage, newIdempotencyKey } from '../../api';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type Option = { id: string; kode: string; nama: string };

type JobLine = {
    id?: string;
    asset_id: string;
    maintenance_job_type_id: string;
    variant_id: string;
    trade_id: string;
    ditugaskan_ke_user_id: string;
    estimasi_jam: string;
    dijadwalkan_mulai: string;
    dijadwalkan_selesai: string;
    aktual_jam?: number | null;
    sebab_kerusakan_id?: string | null;
    tindakan_perbaikan_id?: string | null;
    hasil?: string | null;
    catatan: string;
    asset_kode?: string;
    job_type_nama?: string;
    trade_nama?: string;
    variant_nama?: string;
    sebab_kerusakan_nama?: string;
    tindakan_perbaikan_nama?: string;
};

type WorkOrder = {
    id: string;
    kode: string;
    status: string;
    version: number;
    keterangan: string | null;
    tipe_work_order_id: string;
    tingkat_layanan_id: string | null;
    tipe_work_order_nama?: string;
    tingkat_layanan_nama?: string;
    dijadwalkan_mulai: string | null;
    dijadwalkan_selesai: string | null;
    diharapkan_mulai: string | null;
    diharapkan_selesai: string | null;
    aktual_mulai: string | null;
    aktual_selesai: string | null;
    jumlah_baris?: number;
    details?: JobLine[];
};

type ChecklistRow = {
    id: string;
    line_number: string;
    nama: string;
    tipe: string;
    satuan: string | null;
    wajib: boolean;
    instruksi: string | null;
    pilihan: { value: string; result_code: string }[];
    nilai: string | null;
    tidak_berlaku: boolean;
    catatan_teknisi: string | null;
};

/**
 * Label dan warna status. Dipisahkan dari komponen supaya daftar, sheet, dan layar
 * teknisi menyebut status yang sama dengan kata yang sama.
 */
const STATUS: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive' }> = {
    draft: { label: 'Draf', variant: 'outline' },
    dijadwalkan: { label: 'Dijadwalkan', variant: 'secondary' },
    dikerjakan: { label: 'Dikerjakan', variant: 'default' },
    selesai: { label: 'Selesai', variant: 'secondary' },
    ditutup: { label: 'Ditutup', variant: 'outline' },
    dibatalkan: { label: 'Dibatalkan', variant: 'destructive' },
};

/** Tombol transisi yang ditawarkan pada tiap status, beserta hak yang menjaganya. */
const TRANSISI: Record<string, { ke: string; label: string; izin: string }[]> = {
    draft: [
        { ke: 'dijadwalkan', label: 'Jadwalkan', izin: 'schedule' },
        { ke: 'dibatalkan', label: 'Batalkan', izin: 'schedule' },
    ],
    dijadwalkan: [
        { ke: 'dikerjakan', label: 'Mulai kerjakan', izin: 'execute' },
        { ke: 'dibatalkan', label: 'Batalkan', izin: 'schedule' },
    ],
    dikerjakan: [
        { ke: 'selesai', label: 'Selesaikan', izin: 'execute' },
        { ke: 'dibatalkan', label: 'Batalkan', izin: 'schedule' },
    ],
    selesai: [{ ke: 'ditutup', label: 'Tutup', izin: 'close' }],
    ditutup: [],
    dibatalkan: [],
};

const emptyJob = (): JobLine => ({
    asset_id: '', maintenance_job_type_id: '', variant_id: '', trade_id: '',
    ditugaskan_ke_user_id: '', estimasi_jam: '', dijadwalkan_mulai: '', dijadwalkan_selesai: '', catatan: '',
});

const emptyWorkOrder = () => ({
    keterangan: '', tipe_work_order_id: '', tingkat_layanan_id: '',
    diharapkan_mulai: '', diharapkan_selesai: '',
    dijadwalkan_mulai: '', dijadwalkan_selesai: '', details: [emptyJob()],
});

function StatusBadge({ status }: { status: string }) {
    const tampilan = STATUS[status] ?? { label: status, variant: 'outline' as const };

    return <Badge variant={tampilan.variant}>{tampilan.label}</Badge>;
}

function JobRow({
    job, index, canRemove, assets, assetItems, jobTypes, trades, portalContainer, onAssetSearch, onChange, onRemove,
}: {
    job: JobLine;
    index: number;
    canRemove: boolean;
    /** Daftar penuh; dipakai untuk menampilkan aset yang sedang terpilih walau tersaring keluar. */
    assets: Option[];
    /** Daftar yang lolos pencarian; hanya mengisi pilihan dropdown. */
    assetItems: Option[];
    jobTypes: Option[];
    trades: Option[];
    portalContainer: RefObject<HTMLDivElement | null>;
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

    // Select memilih berdasarkan label, jadi id dibolak-balik ke nama di sini.
    const pilih = (options: Option[], id: string) => options.find((option) => option.id === id);
    const labelDari = (option?: Option) => (option ? `${option.kode} · ${option.nama}` : null);
    const idDari = (options: Option[], label: string | null) =>
        options.find((option) => `${option.kode} · ${option.nama}` === label)?.id ?? '';
    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex justify-between">
                <span className="text-sm font-medium">Baris {index + 1}</span>
                {canRemove && <Button type="button" variant="ghost" size="sm" onClick={onRemove}>Hapus</Button>}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field>
                    <Select
                        label="Aset"
                        required
                        items={assetItems.map((asset) => labelDari(asset) ?? '')}
                        value={labelDari(pilih(assets, job.asset_id))}
                        placeholder="Pilih aset"
                        searchPlaceholder="Cari kode atau nama aset"
                        ariaLabel={`Aset baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onSearchChange={onAssetSearch}
                        onValueChange={(value) => onChange({ asset_id: idDari(assets, value), maintenance_job_type_id: '', variant_id: '' })}
                    />
                </Field>
                <Field>
                    <Select
                        label="Jenis pekerjaan"
                        required
                        items={jobTypes.map((type) => labelDari(type) ?? '')}
                        value={labelDari(pilih(jobTypes, job.maintenance_job_type_id))}
                        placeholder="Pilih jenis pekerjaan"
                        searchPlaceholder="Cari jenis pekerjaan"
                        ariaLabel={`Jenis pekerjaan baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) => onChange({ maintenance_job_type_id: idDari(jobTypes, value), variant_id: '' })}
                    />
                </Field>
                <Field>
                    <Select
                        label="Varian pekerjaan"
                        items={variants.map((variant) => labelDari(variant) ?? '')}
                        value={labelDari(pilih(variants, job.variant_id))}
                        placeholder={job.maintenance_job_type_id ? 'Pilih varian bila diperlukan' : 'Pilih jenis pekerjaan dahulu'}
                        searchPlaceholder="Cari varian pekerjaan"
                        ariaLabel={`Varian pekerjaan baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) => onChange({ variant_id: idDari(variants, value) })}
                    />
                </Field>
                <Field>
                    <Select
                        label="Bidang keahlian"
                        items={trades.map((trade) => labelDari(trade) ?? '')}
                        value={labelDari(pilih(trades, job.trade_id))}
                        placeholder="Pilih bidang keahlian"
                        searchPlaceholder="Cari bidang keahlian"
                        ariaLabel={`Bidang keahlian baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) => onChange({ trade_id: idDari(trades, value) })}
                    />
                </Field>
                <Field>
                    <Input
                        label="Estimasi jam"
                        type="number"
                        min="0"
                        step="0.25"
                        value={job.estimasi_jam}
                        onChange={(event) => onChange({ estimasi_jam: event.target.value })}
                    />
                </Field>
                <Field>
                    <Input
                        label="Jadwal mulai"
                        type="datetime-local"
                        value={job.dijadwalkan_mulai?.replace(' ', 'T').slice(0, 16) ?? ''}
                        onChange={(event) => onChange({ dijadwalkan_mulai: event.target.value })}
                    />
                </Field>
                <Field>
                    <Input
                        label="Jadwal selesai"
                        type="datetime-local"
                        value={job.dijadwalkan_selesai?.replace(' ', 'T').slice(0, 16) ?? ''}
                        onChange={(event) => onChange({ dijadwalkan_selesai: event.target.value })}
                    />
                </Field>
            </div>
            <Field>
                <FieldLabel htmlFor={`job-catatan-${index}`}>Catatan</FieldLabel>
                <Textarea
                    id={`job-catatan-${index}`}
                    rows={2}
                    value={job.catatan}
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
function ChecklistSheet({
    open, rows, editable, saving, onClose, onSave, onChange,
}: {
    open: boolean;
    rows: ChecklistRow[];
    editable: boolean;
    saving: boolean;
    onClose: () => void;
    onSave: () => void;
    onChange: (id: string, change: Partial<ChecklistRow>) => void;
}) {
    const sheetContentRef = useRef<HTMLDivElement>(null);

    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent ref={sheetContentRef} side="right" className="overflow-y-auto sm:max-w-2xl">
                <SheetHeader>
                    <SheetTitle>Checklist pemeriksaan</SheetTitle>
                </SheetHeader>
                <div className="space-y-4 p-4">
                    {!rows.length ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Belum ada baris pemeriksaan</EmptyTitle>
                                <EmptyDescription>Susun checklist dari template sebelum pekerjaan dimulai.</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : rows.map((row) => (
                        <div key={row.id} className="space-y-2 rounded-lg border p-3">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        {row.nama}
                                        {row.wajib && <span className="text-destructive"> *</span>}
                                    </p>
                                    {row.instruksi && <p className="text-sm text-muted-foreground">{row.instruksi}</p>}
                                </div>
                                {editable && <div className="flex shrink-0 items-center gap-2">
                                    <span className="text-sm text-muted-foreground">Tidak berlaku</span>
                                    <Switch
                                        checked={row.tidak_berlaku}
                                        onCheckedChange={(checked) => onChange(row.id, { tidak_berlaku: checked, nilai: checked ? null : row.nilai })}
                                    />
                                </div>}
                            </div>
                            {row.tipe === 'header' ? null : row.tidak_berlaku ? (
                                <p className="text-sm text-muted-foreground">Pemeriksaan ini ditandai tidak berlaku untuk aset ini.</p>
                            ) : row.tipe === 'variable' ? (
                                editable ? <Select
                                    label="Jawaban"
                                    items={row.pilihan.map((choice) => choice.value)}
                                    value={row.nilai}
                                    placeholder="Pilih jawaban"
                                    searchPlaceholder="Cari jawaban"
                                    ariaLabel={`Jawaban ${row.nama}`}
                                    portalContainer={sheetContentRef}
                                    onValueChange={(value) => onChange(row.id, { nilai: value })}
                                /> : <p className="text-sm">Jawaban: {row.nilai ?? 'Belum diisi'}</p>
                            ) : (
                                editable ? <Input
                                    label={row.tipe === 'measurement' ? `Nilai${row.satuan ? ` (${row.satuan})` : ''}` : 'Jawaban'}
                                    type={row.tipe === 'measurement' ? 'number' : 'text'}
                                    step="any"
                                    value={row.nilai ?? ''}
                                    onChange={(event) => onChange(row.id, { nilai: event.target.value })}
                                /> : <p className="text-sm">Jawaban: {row.nilai ?? 'Belum diisi'}</p>
                            )}
                            {editable ? <Field>
                                <FieldLabel htmlFor={`catatan-${row.id}`}>Catatan teknisi</FieldLabel>
                                <Textarea
                                    id={`catatan-${row.id}`}
                                    rows={2}
                                    value={row.catatan_teknisi ?? ''}
                                    onChange={(event) => onChange(row.id, { catatan_teknisi: event.target.value })}
                                />
                            </Field> : row.catatan_teknisi && <p className="text-sm text-muted-foreground">Catatan teknisi: {row.catatan_teknisi}</p>}
                        </div>
                    ))}
                    <SheetFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Tutup</Button>
                        {editable && rows.length > 0 && (
                            <Button type="button" disabled={saving} onClick={onSave}>{saving ? 'Menyimpan…' : 'Simpan hasil'}</Button>
                        )}
                    </SheetFooter>
                </div>
            </SheetContent>
        </Sheet>
    );
}

export default function WorkOrderPage({ context, permissions }: { context: Context; permissions: string[] }) {
    const can = (action: string) => permissions.includes(`management-aset.pemeliharaan-aset.${action}`);
    const [workOrders, setWorkOrders] = useState<WorkOrder[]>([]);
    const [hanyaPekerjaanSaya, setHanyaPekerjaanSaya] = useState(false);
    const [pekerjaanSaya, setPekerjaanSaya] = useState<Record<string, unknown>[]>([]);
    const [tipe, setTipe] = useState<Option[]>([]);
    const [layanan, setLayanan] = useState<Option[]>([]);
    const [assets, setAssets] = useState<Option[]>([]);
    const [jobTypes, setJobTypes] = useState<Option[]>([]);
    const [jobTypesByAsset, setJobTypesByAsset] = useState<Record<string, Option[]>>({});
    const [trades, setTrades] = useState<Option[]>([]);
    const [faultCauses, setFaultCauses] = useState<Option[]>([]);
    const [repairActions, setRepairActions] = useState<Option[]>([]);
    const [assetSearch, setAssetSearch] = useState('');
    const [editing, setEditing] = useState<(Partial<WorkOrder> & { details: JobLine[] }) | undefined>();
    const [checklist, setChecklist] = useState<{ workOrderId: string; jobId: string; rows: ChecklistRow[] } | undefined>();
    const [saving, setSaving] = useState(false);
    const sheetContentRef = useRef<HTMLDivElement>(null);

    const load = async () => {
        try {
            const result = await api<{ data: WorkOrder[] }>('/pemeliharaan-aset');
            setWorkOrders(result.data);
        } catch (caught) {
            toast.error(errorMessage(caught, 'Work order belum dapat dimuat.'));
        }
    };

    const loadPekerjaanSaya = async () => {
        try {
            const result = await api<{ data: Record<string, unknown>[] }>('/pemeliharaan-aset/saya');
            setPekerjaanSaya(result.data);
        } catch (caught) {
            toast.error(errorMessage(caught, 'Daftar pekerjaan Anda belum dapat dimuat.'));
        }
    };

    useEffect(() => { void load(); }, []);
    useEffect(() => { if (hanyaPekerjaanSaya) void loadPekerjaanSaya(); }, [hanyaPekerjaanSaya]);

    // Referensi hanya dimuat bila pengguna memang dapat menyusun work order.
    useEffect(() => {
        if (!can('create') && !can('update')) return;
        const muat = (path: string, set: (options: Option[]) => void, gagal: string) =>
            api<{ data: Option[] }>(path).then((result) => set(result.data)).catch(() => toast.error(gagal));
        void muat('/tipe-work-order?per_page=100&aktif=true', setTipe, 'Tipe work order belum dapat dimuat.');
        void muat('/tingkat-layanan?per_page=100&aktif=true', setLayanan, 'Tingkat layanan belum dapat dimuat.');
        void muat('/maintenance-job-types?per_page=100&aktif=true', setJobTypes, 'Jenis pekerjaan belum dapat dimuat.');
        void muat('/trade?per_page=100&aktif=true', setTrades, 'Bidang keahlian belum dapat dimuat.');
        void muat('/sebab-kerusakan?per_page=100&aktif=true', setFaultCauses, 'Sebab kerusakan belum dapat dimuat.');
        void muat('/tindakan-perbaikan?per_page=100&aktif=true', setRepairActions, 'Tindakan perbaikan belum dapat dimuat.');
    }, [permissions.join(',')]);

    // Register aset dikembalikan utuh oleh `/aset` tanpa parameter pencarian, jadi
    // penyaringan dilakukan di sini. Mengirim `q` ke server hanya akan diabaikan diam-diam
    // dan membuat kotak pencarian terlihat bekerja padahal tidak.
    useEffect(() => {
        if (!can('create') && !can('update')) return;
        api<{ data: Option[] }>('/aset')
            .then((result) => setAssets(result.data))
            .catch(() => toast.error('Aset belum dapat dimuat.'));
    }, [permissions.join(',')]);

    const assetTerpilih = assetSearch.trim().toLowerCase();
    const assetTersaring = assetTerpilih === ''
        ? assets
        : assets.filter((asset) => `${asset.kode} ${asset.nama ?? ''}`.toLowerCase().includes(assetTerpilih));

    const loadJobTypesForAsset = async (assetId: string) => {
        if (!assetId) return;
        try {
            const result = await api<{ data: Option[] }>(`/pemeliharaan-aset/referensi/job-types?asset_id=${encodeURIComponent(assetId)}`);
            setJobTypesByAsset((current) => ({ ...current, [assetId]: result.data }));
        } catch {
            toast.error('Jenis pekerjaan untuk aset belum dapat dimuat.');
        }
    };

    const openEdit = async (id: string) => {
        try {
            const result = await api<{ data: WorkOrder }>(`/pemeliharaan-aset/${id}`);
            const details = (result.data.details ?? []).map((job) => ({
                ...job,
                trade_id: job.trade_id ?? '',
                variant_id: job.variant_id ?? '',
                ditugaskan_ke_user_id: job.ditugaskan_ke_user_id ?? '',
                estimasi_jam: job.estimasi_jam ? String(job.estimasi_jam) : '',
                dijadwalkan_mulai: job.dijadwalkan_mulai ?? '',
                dijadwalkan_selesai: job.dijadwalkan_selesai ?? '',
                sebab_kerusakan_id: job.sebab_kerusakan_id ?? '',
                tindakan_perbaikan_id: job.tindakan_perbaikan_id ?? '',
                catatan: job.catatan ?? '',
            }));
            await Promise.all(details.map((job) => loadJobTypesForAsset(job.asset_id)));
            setEditing({
                ...result.data,
                tingkat_layanan_id: result.data.tingkat_layanan_id ?? '',
                diharapkan_mulai: result.data.diharapkan_mulai ?? '',
                diharapkan_selesai: result.data.diharapkan_selesai ?? '',
                details,
            });
        } catch (caught) {
            toast.error(errorMessage(caught, 'Work order belum dapat dibuka.'));
        }
    };

    const pindahStatus = async (workOrder: WorkOrder, ke: string, label: string) => {
        const alasan = ke === 'dibatalkan' ? window.prompt(`Alasan membatalkan ${workOrder.kode}?`) : null;
        if (ke === 'dibatalkan' && !alasan?.trim()) return;
        try {
            await api(`/pemeliharaan-aset/${workOrder.id}/status`, {
                method: 'POST',
                body: JSON.stringify({ ke_status: ke, version: workOrder.version, alasan }),
            });
            await load();
            toast.success(`${workOrder.kode} — ${label.toLowerCase()} berhasil.`);
        } catch (caught) {
            toast.error(errorMessage(caught, `${workOrder.kode} belum dapat dipindahkan statusnya.`));
        }
    };

    const bukaChecklist = async (workOrderId: string, jobId: string) => {
        try {
            const result = await api<{ data: ChecklistRow[] }>(`/pemeliharaan-aset/${workOrderId}/jobs/${jobId}/checklist`);
            setChecklist({ workOrderId, jobId, rows: result.data });
        } catch (caught) {
            toast.error(errorMessage(caught, 'Checklist belum dapat dibuka.'));
        }
    };

    const simpanChecklist = async () => {
        if (!checklist) return;
        setSaving(true);
        try {
            await api(`/pemeliharaan-aset/${checklist.workOrderId}/jobs/${checklist.jobId}/checklist`, {
                method: 'PUT',
                body: JSON.stringify({
                    baris: checklist.rows.map((row) => ({
                        id: row.id,
                        nilai: row.nilai,
                        tidak_berlaku: row.tidak_berlaku,
                        catatan_teknisi: row.catatan_teknisi,
                    })),
                }),
            });
            toast.success('Hasil pemeriksaan tersimpan.');
            setChecklist(undefined);
        } catch (caught) {
            toast.error(errorMessage(caught, 'Hasil pemeriksaan belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const simpanPelaksanaan = async (job: JobLine) => {
        if (!editing?.id || !job.id) return;
        setSaving(true);
        try {
            await api(`/pemeliharaan-aset/${editing.id}/jobs/${job.id}/execution`, {
                method: 'PATCH',
                body: JSON.stringify({
                    aktual_jam: job.aktual_jam ?? null,
                    sebab_kerusakan_id: job.sebab_kerusakan_id || null,
                    tindakan_perbaikan_id: job.tindakan_perbaikan_id || null,
                }),
            });
            toast.success('Hasil pekerjaan tersimpan.');
            await openEdit(editing.id);
        } catch (caught) {
            toast.error(errorMessage(caught, 'Hasil pekerjaan belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const save = async () => {
        if (!editing || !context.legal_entity_id || !context.org_unit_id) {
            toast.error('Pilih entitas legal dan unit kerja aktif sebelum membuat work order.');

            return;
        }
        if (!editing.tipe_work_order_id) {
            toast.error('Pilih tipe work order lebih dahulu.');

            return;
        }
        if (!editing.details.every((job) => job.asset_id && job.maintenance_job_type_id)) {
            toast.error('Pilih aset dan jenis pekerjaan pada setiap baris.');

            return;
        }

        setSaving(true);
        const body = {
            legal_entity_id: context.legal_entity_id,
            responsible_org_unit_id: context.org_unit_id,
            tipe_work_order_id: editing.tipe_work_order_id,
            tingkat_layanan_id: editing.tingkat_layanan_id || null,
            keterangan: editing.keterangan || null,
            diharapkan_mulai: editing.diharapkan_mulai || null,
            diharapkan_selesai: editing.diharapkan_selesai || null,
            dijadwalkan_mulai: editing.dijadwalkan_mulai || null,
            dijadwalkan_selesai: editing.dijadwalkan_selesai || null,
            details: editing.details.map((job) => ({
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
            if (editing.id) {
                await api(`/pemeliharaan-aset/${editing.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ ...body, version: editing.version }),
                });
            } else {
                await api('/pemeliharaan-aset', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': newIdempotencyKey() },
                    body: JSON.stringify(body),
                });
            }
            setEditing(undefined);
            await load();
            toast.success('Work order disimpan.');
        } catch (caught) {
            toast.error(errorMessage(caught, 'Work order belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const labelDari = (option?: Option) => (option ? `${option.kode} · ${option.nama}` : null);
    const idDari = (options: Option[], label: string | null) =>
        options.find((option) => `${option.kode} · ${option.nama}` === label)?.id ?? '';
    const canEditExecution = editing?.status === 'dikerjakan' || editing?.status === 'selesai';

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="border-b px-5 py-3">
                <CardTitle>Pemeliharaan aset</CardTitle>
                <CardAction>
                    <div className="flex items-center gap-3">
                        {can('execute') && (
                            <label className="flex items-center gap-2 text-sm">
                                <Switch checked={hanyaPekerjaanSaya} onCheckedChange={setHanyaPekerjaanSaya} />
                                Pekerjaan saya
                            </label>
                        )}
                        {can('create') && !hanyaPekerjaanSaya && (
                            <Button onClick={() => setEditing(emptyWorkOrder())}>Buat work order</Button>
                        )}
                    </div>
                </CardAction>
            </CardHeader>
            <CardContent className="px-0">
                {hanyaPekerjaanSaya ? (
                    !pekerjaanSaya.length ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Tidak ada pekerjaan untuk Anda</EmptyTitle>
                                <EmptyDescription>Pekerjaan muncul di sini setelah dijadwalkan dan ditugaskan kepada Anda.</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="divide-y">
                            {pekerjaanSaya.map((job) => (
                                <div key={String(job.id)} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div>
                                        <p className="font-medium">{String(job.work_order_kode)} · {String(job.job_type_nama ?? '')}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {String(job.asset_kode ?? '')}
                                            {job.lokasi_nama ? ` · ${String(job.lokasi_nama)}` : ''}
                                            {job.dijadwalkan_mulai ? ` · ${String(job.dijadwalkan_mulai)}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge status={String(job.status)} />
                                        {can('read') && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => void openEdit(String(job.pemeliharaan_aset_id))}
                                            >
                                                Buka rincian
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => void bukaChecklist(String(job.pemeliharaan_aset_id), String(job.id))}
                                        >
                                            Isi checklist
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )
                ) : !workOrders.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada work order</EmptyTitle>
                            <EmptyDescription>Work order memuat baris pekerjaan per aset, sehingga satu perintah kerja dapat mencakup beberapa aset.</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="divide-y">
                        {workOrders.map((workOrder) => (
                            <div key={workOrder.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div>
                                    <p className="font-medium">{workOrder.kode} · {workOrder.tipe_work_order_nama}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {workOrder.keterangan ?? 'Tanpa keterangan'}
                                        {' · '}{workOrder.jumlah_baris ?? 0} baris pekerjaan
                                        {workOrder.tingkat_layanan_nama ? ` · ${workOrder.tingkat_layanan_nama}` : ''}
                                        {workOrder.dijadwalkan_mulai ? ` · ${workOrder.dijadwalkan_mulai}` : ''}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge status={workOrder.status} />
                                    {(TRANSISI[workOrder.status] ?? [])
                                        .filter((transisi) => can(transisi.izin))
                                        .map((transisi) => (
                                            <Button
                                                key={transisi.ke}
                                                variant={transisi.ke === 'dibatalkan' ? 'destructive' : 'default'}
                                                size="sm"
                                                onClick={() => void pindahStatus(workOrder, transisi.ke, transisi.label)}
                                            >
                                                {transisi.label}
                                            </Button>
                                        ))}
                                    {can('update') && workOrder.status === 'draft' && (
                                        <Button variant="outline" size="sm" onClick={() => void openEdit(workOrder.id)}>Ubah</Button>
                                    )}
                                    {can('read') && (
                                        <Button variant="outline" size="sm" onClick={() => void openEdit(workOrder.id)}>Rincian</Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>

            <Sheet open={editing !== undefined} onOpenChange={(open) => !open && setEditing(undefined)}>
                <SheetContent ref={sheetContentRef} side="right" className="overflow-y-auto sm:max-w-5xl">
                    <SheetHeader>
                        <SheetTitle>{editing?.id ? `Work order ${editing.kode}` : 'Buat work order'}</SheetTitle>
                    </SheetHeader>
                    {editing && (
                        <div className="space-y-4 p-4">
                            <p className="text-sm text-muted-foreground">Entitas legal dan unit penanggung jawab mengikuti konteks aktif Anda.</p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field>
                                    <Select
                                        label="Tipe work order"
                                        required
                                        items={tipe.map((option) => labelDari(option) ?? '')}
                                        value={labelDari(tipe.find((option) => option.id === editing.tipe_work_order_id))}
                                        placeholder="Pilih tipe work order"
                                        searchPlaceholder="Cari tipe work order"
                                        ariaLabel="Tipe work order"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(value) => setEditing({ ...editing, tipe_work_order_id: idDari(tipe, value) })}
                                    />
                                    <FieldDescription>Tipe menentukan apa yang wajib diisi sebelum pekerjaan boleh dinyatakan selesai.</FieldDescription>
                                </Field>
                                <Field>
                                    <Select
                                        label="Tingkat layanan"
                                        items={layanan.map((option) => labelDari(option) ?? '')}
                                        value={labelDari(layanan.find((option) => option.id === editing.tingkat_layanan_id))}
                                        placeholder="Pilih tingkat layanan"
                                        searchPlaceholder="Cari tingkat layanan"
                                        ariaLabel="Tingkat layanan"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(value) => setEditing({ ...editing, tingkat_layanan_id: idDari(layanan, value) })}
                                    />
                                </Field>
                                <Field>
                                    <Input
                                        label="Diharapkan mulai"
                                        type="datetime-local"
                                        value={editing.diharapkan_mulai?.replace(' ', 'T').slice(0, 16) ?? ''}
                                        onChange={(event) => setEditing({ ...editing, diharapkan_mulai: event.target.value })}
                                    />
                                    <FieldDescription>Batas waktu yang diharapkan untuk memulai pekerjaan.</FieldDescription>
                                </Field>
                                <Field>
                                    <Input
                                        label="Diharapkan selesai"
                                        type="datetime-local"
                                        value={editing.diharapkan_selesai?.replace(' ', 'T').slice(0, 16) ?? ''}
                                        onChange={(event) => setEditing({ ...editing, diharapkan_selesai: event.target.value })}
                                    />
                                    <FieldDescription>Batas waktu yang diharapkan untuk menyelesaikan pekerjaan.</FieldDescription>
                                </Field>
                                <Field>
                                    <Input
                                        label="Dijadwalkan mulai"
                                        type="datetime-local"
                                        value={editing.dijadwalkan_mulai?.replace(' ', 'T').slice(0, 16) ?? ''}
                                        onChange={(event) => setEditing({ ...editing, dijadwalkan_mulai: event.target.value })}
                                    />
                                    <FieldDescription>Harus diisi sebelum work order dapat dijadwalkan.</FieldDescription>
                                </Field>
                                <Field>
                                    <Input
                                        label="Dijadwalkan selesai"
                                        type="datetime-local"
                                        value={editing.dijadwalkan_selesai?.replace(' ', 'T').slice(0, 16) ?? ''}
                                        onChange={(event) => setEditing({ ...editing, dijadwalkan_selesai: event.target.value })}
                                    />
                                </Field>
                            </div>
                            <Field>
                                <FieldLabel htmlFor="work-order-keterangan">Keterangan</FieldLabel>
                                <Textarea
                                    id="work-order-keterangan"
                                    rows={4}
                                    value={editing.keterangan ?? ''}
                                    onChange={(event) => setEditing({ ...editing, keterangan: event.target.value })}
                                />
                            </Field>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-medium">Baris pekerjaan</h3>
                                    {editing.status === undefined || editing.status === 'draft' ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setEditing({ ...editing, details: [...editing.details, emptyJob()] })}
                                        >
                                            Tambah baris
                                        </Button>
                                    ) : null}
                                </div>
                                {editing.status && editing.status !== 'draft' ? (
                                    <div className="divide-y rounded-lg border">
                                        {editing.details.map((job) => (
                                            <div key={job.id} className="space-y-3 p-3">
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <p className="font-medium">{job.asset_kode} · {job.job_type_nama}</p>
                                                        <p className="text-sm text-muted-foreground">
                                                            {job.trade_nama ?? 'Tanpa bidang keahlian'}
                                                            {job.variant_nama ? ` · ${job.variant_nama}` : ''}
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => void bukaChecklist(String(editing.id), String(job.id))}
                                                    >
                                                        Checklist
                                                    </Button>
                                                </div>
                                                <div className="grid gap-3 sm:grid-cols-3">
                                                    <Field>
                                                        <Input
                                                            label="Jam aktual"
                                                            type="number"
                                                            min="0"
                                                            step="0.25"
                                                            value={job.aktual_jam ?? ''}
                                                            disabled={!canEditExecution}
                                                            onChange={(event) => setEditing({
                                                                ...editing,
                                                                details: editing.details.map((current) => current.id === job.id
                                                                    ? { ...current, aktual_jam: event.target.value === '' ? null : Number(event.target.value) }
                                                                    : current),
                                                            })}
                                                        />
                                                    </Field>
                                                    <Field>
                                                        {canEditExecution ? <Select
                                                            label="Sebab kerusakan"
                                                            items={faultCauses.map((option) => labelDari(option) ?? '')}
                                                            value={labelDari(faultCauses.find((option) => option.id === job.sebab_kerusakan_id))}
                                                            placeholder="Pilih bila ada"
                                                            searchPlaceholder="Cari sebab kerusakan"
                                                            ariaLabel={`Sebab kerusakan ${job.asset_kode ?? ''}`}
                                                            portalContainer={sheetContentRef}
                                                            onValueChange={(value) => setEditing({
                                                                ...editing,
                                                                details: editing.details.map((current) => current.id === job.id
                                                                    ? { ...current, sebab_kerusakan_id: idDari(faultCauses, value) || null }
                                                                    : current),
                                                            })}
                                                        /> : <p className="pt-2 text-sm text-muted-foreground">{labelDari(faultCauses.find((option) => option.id === job.sebab_kerusakan_id)) ?? 'Sebab belum diisi'}</p>}
                                                    </Field>
                                                    <Field>
                                                        {canEditExecution ? <Select
                                                            label="Tindakan perbaikan"
                                                            items={repairActions.map((option) => labelDari(option) ?? '')}
                                                            value={labelDari(repairActions.find((option) => option.id === job.tindakan_perbaikan_id))}
                                                            placeholder="Pilih bila ada"
                                                            searchPlaceholder="Cari tindakan perbaikan"
                                                            ariaLabel={`Tindakan perbaikan ${job.asset_kode ?? ''}`}
                                                            portalContainer={sheetContentRef}
                                                            onValueChange={(value) => setEditing({
                                                                ...editing,
                                                                details: editing.details.map((current) => current.id === job.id
                                                                    ? { ...current, tindakan_perbaikan_id: idDari(repairActions, value) || null }
                                                                    : current),
                                                            })}
                                                        /> : <p className="pt-2 text-sm text-muted-foreground">{labelDari(repairActions.find((option) => option.id === job.tindakan_perbaikan_id)) ?? 'Tindakan belum diisi'}</p>}
                                                    </Field>
                                                </div>
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="text-sm text-muted-foreground">Hasil: {job.hasil ?? 'Belum dihitung'}</p>
                                                    <div className="flex gap-2">
                                                        {canEditExecution && (
                                                        <Button type="button" size="sm" disabled={saving} onClick={() => void simpanPelaksanaan(job)}>
                                                            Simpan hasil
                                                        </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    editing.details.map((job, index) => (
                                        <JobRow
                                            key={index}
                                            job={job}
                                            index={index}
                                            canRemove={editing.details.length > 1}
                                            assets={assets}
                                            assetItems={assetTersaring}
                                            jobTypes={jobTypesByAsset[job.asset_id] ?? []}
                                            trades={trades}
                                            portalContainer={sheetContentRef}
                                            onAssetSearch={setAssetSearch}
                                            onChange={(change) => {
                                                if ('asset_id' in change && change.asset_id) void loadJobTypesForAsset(change.asset_id);
                                                setEditing({
                                                    ...editing,
                                                    details: editing.details.map((current, position) => position === index ? { ...current, ...change } : current),
                                                });
                                            }}
                                            onRemove={() => setEditing({
                                                ...editing,
                                                details: editing.details.filter((_, position) => position !== index),
                                            })}
                                        />
                                    ))
                                )}
                            </div>
                            <SheetFooter>
                                <Button type="button" variant="outline" onClick={() => setEditing(undefined)}>Tutup</Button>
                                {(editing.status === undefined || editing.status === 'draft') && (
                                    <Button type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Menyimpan…' : 'Simpan'}</Button>
                                )}
                            </SheetFooter>
                        </div>
                    )}
                </SheetContent>
            </Sheet>

            <ChecklistSheet
                open={checklist !== undefined}
                rows={checklist?.rows ?? []}
                editable={editing?.status === 'dikerjakan'}
                saving={saving}
                onClose={() => setChecklist(undefined)}
                onSave={() => void simpanChecklist()}
                onChange={(id, change) => setChecklist((current) => current && ({
                    ...current,
                    rows: current.rows.map((row) => row.id === id ? { ...row, ...change } : row),
                }))}
            />
        </Card>
    );
}
