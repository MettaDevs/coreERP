import { Printer } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DateTimePicker } from '@apperp/ui/date-time-picker';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogToolbar,
} from '@apperp/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../../_shared/EditShield';
import { RecordLookup } from '../../_shared/RecordLookup';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import { requestPrint } from '../../print';
import { useRecordOptions } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import WarrantyNotice from '../warranty/WarrantyNotice';
import type {
    ChecklistRow,
    Context,
    EditableWorkOrder,
    JobLine,
    Option,
    WorkOrder,
} from './workOrder';
import {
    StatusBadge,
    TRANSISI,
    bukaDaftar as navigateClose,
    bukaWorkOrder as navigateView,
    bukaWorkOrderUbah as navigateEdit,
    emptyJob,
    emptyWorkOrder,
    izin,
    labelDari,
} from './workOrder';

type Mode = 'view' | 'edit' | 'create';

/** Dipakai bersama saat varian jenis pekerjaan belum termuat, agar identitasnya tetap. */

function JobRow({
    job,
    index,
    canRemove,
    readOnly,
    onRequestEdit,
    portalContainer,
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
    portalContainer?: RecordCardControls['portalContainer'];
    /** Daftar penuh; dipakai untuk menampilkan aset yang sedang terpilih walau tersaring keluar. */
    /** Daftar yang lolos pencarian; hanya mengisi pilihan dropdown. */
    onChange: (change: Partial<JobLine>) => void;
    onRemove: () => void;
}) {
    const assetSource = useRecordOptions<Option>(
        '/aset',
        (item) => labelDari(item) ?? '',
        job.aset_id,
    );
    const jobSource = useRecordOptions<Option>(
        job.aset_id
            ? `/pemeliharaan-aset/referensi/job-types?aset_id=${encodeURIComponent(job.aset_id)}`
            : null,
        (item) => labelDari(item) ?? '',
        job.maintenance_job_type_id,
    );
    const variantSource = useRecordOptions<Option>(
        job.maintenance_job_type_id
            ? `/maintenance-job-types/${job.maintenance_job_type_id}/variants`
            : null,
        (item) => labelDari(item) ?? '',
        job.variant_id,
    );
    const tradeSource = useRecordOptions<Option>(
        '/trade?aktif=true',
        (item) => labelDari(item) ?? '',
        job.trade_id,
    );
    const aset = assetSource.options,
        asetItems = aset,
        jobTypes = jobSource.options,
        variants = variantSource.options,
        trades = tradeSource.options;
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
                    <ActionButton
                        action="delete"
                        type="button"
                        onClick={onRemove}
                    />
                )}
            </div>
            <FormGrid>
                <FormControl>
                    <EditShield
                        active={readOnly}
                        label="aset"
                        onActivate={onRequestEdit}
                    >
                        <RecordLookup
                            portalContainer={portalContainer}
                            label="Aset"
                            required
                            source={assetSource}
                            items={asetItems.map((option) => ({
                                value: option.id,
                                label: labelDari(option) ?? '',
                            }))}
                            value={job.aset_id || null}
                            placeholder="Pilih aset"
                            searchPlaceholder="Cari kode atau nama aset"
                            ariaLabel={`Aset baris ${index + 1}`}
                            onValueChange={(value) =>
                                onChange({
                                    aset_id: value ?? '',
                                    maintenance_job_type_id: '',
                                    variant_id: '',
                                })
                            }
                        />
                    </EditShield>
                </FormControl>
                <FormControl>
                    <EditShield
                        active={readOnly}
                        label="jenis pekerjaan"
                        onActivate={onRequestEdit}
                    >
                        <RecordLookup
                            portalContainer={portalContainer}
                            label="Jenis pekerjaan"
                            required
                            source={jobSource}
                            items={jobTypes.map((option) => ({
                                value: option.id,
                                label: labelDari(option) ?? '',
                            }))}
                            value={job.maintenance_job_type_id || null}
                            placeholder="Pilih jenis pekerjaan"
                            searchPlaceholder="Cari jenis pekerjaan"
                            ariaLabel={`Jenis pekerjaan baris ${index + 1}`}
                            onValueChange={(value) =>
                                onChange({
                                    maintenance_job_type_id: value ?? '',
                                    variant_id: '',
                                })
                            }
                        />
                    </EditShield>
                </FormControl>
                <FormControl>
                    <EditShield
                        active={readOnly}
                        label="varian pekerjaan"
                        onActivate={onRequestEdit}
                    >
                        <RecordLookup
                            portalContainer={portalContainer}
                            label="Varian pekerjaan"
                            source={variantSource}
                            items={variants.map((option) => ({
                                value: option.id,
                                label: labelDari(option) ?? '',
                            }))}
                            value={job.variant_id || null}
                            placeholder={
                                job.maintenance_job_type_id
                                    ? 'Pilih varian bila diperlukan'
                                    : 'Pilih jenis pekerjaan dahulu'
                            }
                            searchPlaceholder="Cari varian pekerjaan"
                            ariaLabel={`Varian pekerjaan baris ${index + 1}`}
                            onValueChange={(value) =>
                                onChange({
                                    variant_id: value ?? '',
                                })
                            }
                        />
                    </EditShield>
                </FormControl>
                <FormControl>
                    <EditShield
                        active={readOnly}
                        label="bidang keahlian"
                        onActivate={onRequestEdit}
                    >
                        <RecordLookup
                            portalContainer={portalContainer}
                            label="Bidang keahlian"
                            source={tradeSource}
                            items={trades.map((option) => ({
                                value: option.id,
                                label: labelDari(option) ?? '',
                            }))}
                            value={job.trade_id || null}
                            placeholder="Pilih bidang keahlian"
                            searchPlaceholder="Cari bidang keahlian"
                            ariaLabel={`Bidang keahlian baris ${index + 1}`}
                            onValueChange={(value) =>
                                onChange({ trade_id: value ?? '' })
                            }
                        />
                    </EditShield>
                </FormControl>
                <FormControl>
                    <Input
                        label="Estimasi jam"
                        type="number"
                        min="0"
                        step="0.25"
                        value={job.estimasi_jam}
                        onChange={(event) =>
                            onChange({ estimasi_jam: event.target.value })
                        }
                        {...teks(`Estimasi jam baris ${index + 1}`)}
                    />
                </FormControl>
                <FormControl>
                    <DateTimePicker
                        portalContainer={portalContainer}
                        label="Jadwal mulai"

                        value={
                            job.dijadwalkan_mulai
                                ?.replace(' ', 'T')
                                .slice(0, 16) ?? ''
                        }
                        onValueChange={(value) =>
                            onChange({ dijadwalkan_mulai: value })
                        }
                        {...teks(`Jadwal mulai baris ${index + 1}`)}
                    />
                </FormControl>
                <FormControl>
                    <DateTimePicker
                        portalContainer={portalContainer}
                        label="Jadwal selesai"

                        value={
                            job.dijadwalkan_selesai
                                ?.replace(' ', 'T')
                                .slice(0, 16) ?? ''
                        }
                        onValueChange={(value) =>
                            onChange({
                                dijadwalkan_selesai: value,
                            })
                        }
                        {...teks(`Jadwal selesai baris ${index + 1}`)}
                    />
                </FormControl>
            </FormGrid>
            <FormControl>
                <FieldLabel htmlFor={`job-catatan-${index}`}>
                    Catatan
                </FieldLabel>
                <Textarea
                    id={`job-catatan-${index}`}
                    rows={2}
                    value={job.catatan}
                    readOnly={readOnly}
                    onFocus={readOnly ? onRequestEdit : undefined}
                    onChange={(event) =>
                        onChange({ catatan: event.target.value })
                    }
                />
            </FormControl>
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
    onClose,
    portalContainer,
    onChange,
}: {
    rows: ChecklistRow[];
    editable: boolean;
    onClose: () => void;
    portalContainer?: RecordCardControls['portalContainer'];
    onChange: (id: string, change: Partial<ChecklistRow>) => void;
}) {
    return (
        <div className="bg-muted/20 border-t p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold">
                        Checklist pemeriksaan
                    </h3>
                    <p className="text-muted-foreground text-sm">
                        Isi pemeriksaan di halaman ini sebelum pekerjaan
                        dinyatakan selesai.
                    </p>
                </div>
            </div>
            <DialogToolbar className="px-0">
                <Button type="button" variant="outline" onClick={onClose}>
                    Tutup checklist
                </Button>
            </DialogToolbar>
            <div className="mt-4 space-y-4">
                {!rows.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada baris pemeriksaan</EmptyTitle>
                            <EmptyDescription>
                                Susun checklist dari template sebelum pekerjaan
                                dimulai.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    rows.map((row) => (
                        <div
                            key={row.id}
                            className="space-y-2 rounded-lg border p-3"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        {row.nama}
                                        {row.wajib && (
                                            <span className="text-destructive">
                                                {' '}
                                                *
                                            </span>
                                        )}
                                    </p>
                                    {row.instruksi && (
                                        <p className="text-muted-foreground text-sm">
                                            {row.instruksi}
                                        </p>
                                    )}
                                    {row.tipe === 'measurement' &&
                                        row.min_value !== null &&
                                        row.max_value !== null && (
                                            <p className="text-muted-foreground text-sm">
                                                Rentang lulus: {row.min_value}–
                                                {row.max_value}
                                                {row.satuan
                                                    ? ` ${row.satuan}`
                                                    : ''}
                                                .
                                            </p>
                                        )}
                                    {row.tipe === 'variable' &&
                                        row.pilihan.some(
                                            (choice) =>
                                                choice.result_code === 'none',
                                        ) && (
                                            <p className="text-muted-foreground text-sm">
                                                Jika memilih jawaban Tidak
                                                dinilai, isi alasan pada catatan
                                                teknisi.
                                            </p>
                                        )}
                                </div>
                                {editable && (
                                    <div className="flex shrink-0 items-center gap-2">
                                        <span className="text-muted-foreground text-sm">
                                            Tidak berlaku
                                        </span>
                                        <Switch
                                            checked={row.tidak_berlaku}
                                            onCheckedChange={(checked) =>
                                                onChange(row.id, {
                                                    tidak_berlaku: checked,
                                                    nilai: checked
                                                        ? null
                                                        : row.nilai,
                                                })
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                            {row.tipe ===
                            'header' ? null : row.tidak_berlaku ? (
                                <p className="text-muted-foreground text-sm">
                                    Pemeriksaan ini ditandai tidak berlaku untuk
                                    aset ini.
                                </p>
                            ) : row.tipe === 'variable' ? (
                                editable ? (
                                    <FormControl>
                                        <Select
                                            portalContainer={portalContainer}
                                            label="Jawaban"
                                            items={row.pilihan.map(
                                                (choice) => choice.value,
                                            )}
                                            value={row.nilai}
                                            placeholder="Pilih jawaban"
                                            searchPlaceholder="Cari jawaban"
                                            ariaLabel={`Jawaban ${row.nama}`}
                                            onValueChange={(value) =>
                                                onChange(row.id, {
                                                    nilai: value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                ) : (
                                    <p className="text-sm">
                                        Jawaban: {row.nilai ?? 'Belum diisi'}
                                    </p>
                                )
                            ) : editable ? (
                                <FormControl>
                                    <Input
                                        label={
                                            row.tipe === 'measurement'
                                                ? `Nilai${row.satuan ? ` (${row.satuan})` : ''}`
                                                : 'Jawaban'
                                        }
                                        type={
                                            row.tipe === 'measurement'
                                                ? 'number'
                                                : 'text'
                                        }
                                        step="any"
                                        value={row.nilai ?? ''}
                                        onChange={(event) =>
                                            onChange(row.id, {
                                                nilai: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                            ) : (
                                <p className="text-sm">
                                    Jawaban: {row.nilai ?? 'Belum diisi'}
                                </p>
                            )}
                            {editable ? (
                                <FormControl>
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
                                                catatan_teknisi:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                            ) : (
                                row.catatan_teknisi && (
                                    <p className="text-muted-foreground text-sm">
                                        Catatan teknisi: {row.catatan_teknisi}
                                    </p>
                                )
                            )}
                        </div>
                    ))
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
    checklistJobId: routedChecklistJobId,
    onClose,
    onSaved,
    onRequestView,
    onRequestEdit,
    onBusyChange,
    onDirtyChange,
    portalContainer,
}: {
    context: Context;
    permissions: string[];
    /** Kosong berarti work order baru. */
    workOrderId?: string;
    mode: Mode;
    checklistJobId?: string;
} & RecordCardControls) {
    const [feedbackSections, setFeedbackSections] = useState<string[]>([
        'general',
        'lines',
    ]);

    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
    } = useSaveFeedback(undefined, workOrderId, () =>
        setFeedbackSections(['general', 'lines', 'notes']),
    );

    const embedded = usePageDialogRecord(undefined);

    const [localChecklistJobId, setLocalChecklistJobId] = useState<
        string | null | undefined
    >(undefined);
    const checklistJobId =
        localChecklistJobId === undefined
            ? routedChecklistJobId
            : localChecklistJobId;
    const bukaChecklistJob = (_id: string, jobId: string) => {
        setLocalChecklistJobId(jobId);
    };
    const bukaWorkOrder = (id: string) => {
        setLocalChecklistJobId(null);
        (onRequestView ?? navigateView)(id);
    };
    const bukaWorkOrderUbah = (id: string) =>
        (onRequestEdit ?? navigateEdit)(id);
    const bukaDaftar = () => (onClose ?? navigateClose)();

    const can = useMemo(() => izin(permissions), [permissions]);
    /**
     * Referensi hanya diperlukan bila pengguna memang dapat menyusun work order.
     * Dipadatkan menjadi satu boolean supaya effect pemuatnya bergantung pada nilai yang
     * stabil, bukan pada daftar izin yang dirangkai ulang setiap render.
     */
    const bolehSusun = can('create') || can('update') || can('execute');
    const [record, setRecord] = useState<EditableWorkOrder | undefined>(
        mode === 'create' ? emptyWorkOrder() : undefined,
    );
    const typeSource = useRecordOptions<Option>(
        bolehSusun ? '/tipe-work-order?aktif=true' : null,
        (item) => labelDari(item) ?? '',
        record?.tipe_work_order_id ?? '',
    );
    const serviceSource = useRecordOptions<Option>(
        bolehSusun ? '/tingkat-layanan?aktif=true' : null,
        (item) => labelDari(item) ?? '',
        record?.tingkat_layanan_id ?? '',
    );
    const faultSource = useRecordOptions<Option>(
        bolehSusun ? '/sebab-kerusakan?aktif=true' : null,
        (item) => labelDari(item) ?? '',
        record?.details
            .map((job) => job.sebab_kerusakan_id ?? '')
            .filter(Boolean) ?? [],
    );
    const repairSource = useRecordOptions<Option>(
        bolehSusun ? '/tindakan-perbaikan?aktif=true' : null,
        (item) => labelDari(item) ?? '',
        record?.details
            .map((job) => job.tindakan_perbaikan_id ?? '')
            .filter(Boolean) ?? [],
    );
    const tipe = typeSource.options,
        layanan = serviceSource.options,
        faultCauses = faultSource.options,
        repairActions = repairSource.options;
    // Checklist dicatat bersama job yang alamatnya membukanya. Menutup checklist berarti
    // `checklistJobId` hilang dari alamat, dan isinya ikut hilang saat render itu juga —
    // tanpa effect yang perlu mengosongkannya lebih dulu.
    const [checklistMuatan, setChecklistMuatan] = useState<{
        jobId: string;
        rows: ChecklistRow[];
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const [cancellationLabel, setCancellationLabel] = useState<string | null>(
        null,
    );
    const [cancellationReason, setCancellationReason] = useState('');
    const [cancellationFieldError, setCancellationFieldError] = useState('');
    const cancellationReasonErrorId = useId();
    const {
        scopeRef: cancellationScopeRef,
        feedbackSummary: cancellationFeedback,
        reportSaveError: reportCancellationError,
        clearSaveFeedback: clearCancellationFeedback,
    } = useSaveFeedback(undefined, workOrderId);
    const [mainDirty, setMainDirty] = useState(false);
    const [checklistDrafts, setChecklistDrafts] = useState<
        Record<string, ChecklistRow[]>
    >({});
    const auxiliaryDirty = Object.keys(checklistDrafts).length > 0;
    useEffect(() => {
        onBusyChange?.(saving);

        return () => onBusyChange?.(false);
    }, [saving, onBusyChange]);

    // Effect di bawah adalah satu-satunya pembaca work order dari server. Setelah pindah
    // status atau simpan hasil pekerjaan, pembacaan ulang dinyatakan dengan menaikkan
    // penanda ini — bukan dengan memanggil pemuatnya dari luar.
    const [versiMuat, setVersiMuat] = useState(0);
    const muatUlang = () => setVersiMuat((versi) => versi + 1);

    // `mode` ikut jadi pemicu supaya kembali ke mode baca berarti membaca ulang dari
    // server. Itulah yang membuang suntingan yang batal: tidak ada salinan lama yang
    // masih menempel saat pengguna membuka work order yang sama lagi.
    // Tanpa `workOrderId` tidak ada yang perlu dibaca: record kosongnya sudah lahir dari
    // penginisialisasi `useState` di atas, dan WorkOrderPage memberi cabang "baru" `key`
    // tersendiri sehingga tidak mungkin berganti menjadi rincian tanpa dipasang ulang.
    useEffect(() => {
        if (!workOrderId) {
            return;
        }

        let dibatalkan = false;

        // Pembacaan lahir di dalam effect: state baru disetel setelah jawaban server
        // tiba, bukan pada commit render yang sama, dan jawaban yang telat datang setelah
        // layar ditutup dibuang lewat `dibatalkan`.
        const muat = async () => {
            try {
                const result = await api<{ data: WorkOrder }>(
                    `/pemeliharaan-aset/${workOrderId}`,
                );
                const details = (result.data.details ?? []).map(
                    (job, index) => ({
                        ...job,
                        line_number: job.line_number ?? index + 1,
                        trade_id: job.trade_id ?? '',
                        variant_id: job.variant_id ?? '',
                        ditugaskan_ke_user_id: job.ditugaskan_ke_user_id ?? '',
                        estimasi_jam: job.estimasi_jam
                            ? String(job.estimasi_jam)
                            : '',
                        dijadwalkan_mulai: job.dijadwalkan_mulai ?? '',
                        dijadwalkan_selesai: job.dijadwalkan_selesai ?? '',
                        sebab_kerusakan_id: job.sebab_kerusakan_id ?? '',
                        tindakan_perbaikan_id: job.tindakan_perbaikan_id ?? '',
                        sebab_kerusakan_keterangan:
                            job.sebab_kerusakan_keterangan ?? '',
                        tindakan_perbaikan_keterangan:
                            job.tindakan_perbaikan_keterangan ?? '',
                        catatan: job.catatan ?? '',
                    }),
                );

                if (dibatalkan) {
                    return;
                }

                setChecklistDrafts({});
                setRecord({
                    ...result.data,
                    tingkat_layanan_id: result.data.tingkat_layanan_id ?? '',
                    diharapkan_mulai: result.data.diharapkan_mulai ?? '',
                    diharapkan_selesai: result.data.diharapkan_selesai ?? '',
                    details,
                });
            } catch (caught) {
                if (dibatalkan) {
                    return;
                }

                toast.error(
                    errorMessage(caught, 'Work order belum dapat dibuka.'),
                );
            }
        };

        void muat();

        return () => {
            dibatalkan = true;
        };
    }, [mode, versiMuat, workOrderId]);

    useEffect(() => {
        if (!workOrderId || !checklistJobId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: ChecklistRow[] }>(
            `/pemeliharaan-aset/${workOrderId}/jobs/${checklistJobId}/checklist`,
        )
            .then((result) => {
                if (!dibatalkan) {
                    setChecklistMuatan({
                        jobId: checklistJobId,
                        rows: result.data,
                    });
                }
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Checklist belum dapat dibuka.'),
                ),
            );

        return () => {
            dibatalkan = true;
        };
    }, [workOrderId, checklistJobId, versiMuat]);

    const checklist =
        checklistJobId && checklistMuatan?.jobId === checklistJobId
            ? (checklistDrafts[checklistJobId] ?? checklistMuatan.rows)
            : undefined;

    useRecordCardDirty({
        value: record,
        ready: Boolean(record),
        scopeKey: JSON.stringify([workOrderId, mode, record?.version]),
        onDirtyChange: setMainDirty,
    });
    useEffect(() => {
        onDirtyChange?.(mainDirty || auxiliaryDirty);

        return () => onDirtyChange?.(false);
    }, [mainDirty, auxiliaryDirty, onDirtyChange]);

    const pindahStatus = async (
        ke: string,
        label: string,
        providedReason?: string,
    ) => {
        if (!record?.id || !record.kode || saving) {
            return;
        }

        if (ke === 'dibatalkan' && providedReason === undefined) {
            clearCancellationFeedback();
            setCancellationReason('');
            setCancellationFieldError('');
            setCancellationLabel(label);

            return;
        }

        const alasan = providedReason?.trim() || null;

        if (ke === 'dibatalkan' && !alasan?.trim()) {
            setCancellationFieldError('Isi alasan pembatalan.');
            reportCancellationError(
                new ApiError(
                    'Isi alasan pembatalan.',
                    { alasan: ['Isi alasan pembatalan.'] },
                    422,
                ),
                'Pembatalan belum dapat diproses.',
            );

            return;
        }

        clearCancellationFeedback();
        setCancellationFieldError('');
        setSaving(true);

        try {
            await api(`/pemeliharaan-aset/${record.id}/status`, {
                method: 'POST',
                body: JSON.stringify({
                    ke_status: ke,
                    version: record.version,
                    alasan,
                }),
            });
            muatUlang();
            clearSaveFeedback();
            setCancellationLabel(null);
            toast.success(`${record.kode} — ${label.toLowerCase()} berhasil.`);
        } catch (caught) {
            const reportFailure =
                ke === 'dibatalkan' ? reportCancellationError : reportSaveError;

            if (ke === 'dibatalkan' && caught instanceof ApiError) {
                setCancellationFieldError(
                    caught.validationErrors.alasan?.[0] ?? '',
                );
            }

            reportFailure(
                caught,
                `${record.kode} belum dapat dipindahkan statusnya.`,
            );
        } finally {
            setSaving(false);
        }
    };

    const saveExecution = async () => {
        if (!record?.id) {
            return;
        }

        setSaving(true);

        try {
            await api(`/pemeliharaan-aset/${record.id}/execution`, {
                method: 'PUT',
                body: JSON.stringify({
                    version: record.version,
                    jobs: record.details.map((job) => ({
                        id: job.id,
                        aktual_jam: job.aktual_jam ?? null,
                        sebab_kerusakan_id: job.sebab_kerusakan_id || null,
                        tindakan_perbaikan_id:
                            job.tindakan_perbaikan_id || null,
                        sebab_kerusakan_keterangan:
                            job.sebab_kerusakan_keterangan || null,
                        tindakan_perbaikan_keterangan:
                            job.tindakan_perbaikan_keterangan || null,
                        ...(job.id && checklistDrafts[job.id]
                            ? {
                                  checklist: checklistDrafts[job.id].map(
                                      (row) => ({
                                          id: row.id,
                                          nilai: row.nilai,
                                          tidak_berlaku: row.tidak_berlaku,
                                          catatan_teknisi: row.catatan_teknisi,
                                      }),
                                  ),
                              }
                            : {}),
                    })),
                }),
            });
            clearSaveFeedback();
            setChecklistDrafts({});
            setChecklistMuatan(null);
            setLocalChecklistJobId(null);
            muatUlang();
            toast.success('Hasil pekerjaan disimpan.');
        } catch (caught) {
            reportSaveError(caught, 'Hasil pekerjaan belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    };

    const save = async () => {
        if (!record || !context.legal_entity_id || !context.org_unit_id) {
            reportSaveError(
                null,
                'Pilih perusahaan dan unit kerja aktif sebelum membuat work order.',
            );

            return;
        }

        if (!record.tipe_work_order_id) {
            reportSaveError(null, 'Pilih tipe work order lebih dahulu.');

            return;
        }

        if (
            !record.details.every(
                (job) => job.aset_id && job.maintenance_job_type_id,
            )
        ) {
            reportSaveError(
                null,
                'Pilih aset dan jenis pekerjaan pada setiap baris.',
            );

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
                aset_id: job.aset_id,
                maintenance_job_type_id: job.maintenance_job_type_id,
                variant_id: job.variant_id || null,
                trade_id: job.trade_id || null,
                ditugaskan_ke_user_id: job.ditugaskan_ke_user_id || null,
                estimasi_jam: job.estimasi_jam
                    ? Number(job.estimasi_jam)
                    : null,
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
                clearSaveFeedback();
                toast.success('Work order disimpan.');
                (onSaved ?? bukaWorkOrder)(record.id);
            } else {
                const dibuat = await api<{ data: WorkOrder }>(
                    '/pemeliharaan-aset',
                    {
                        method: 'POST',
                        headers: { 'Idempotency-Key': newIdempotencyKey() },
                        body: JSON.stringify(body),
                    },
                );
                clearSaveFeedback();
                toast.success('Work order disimpan.');
                (onSaved ?? bukaWorkOrder)(dibuat.data.id);
            }
        } catch (caught) {
            reportSaveError(caught, 'Work order belum dapat disimpan.');
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
    const dapatDisunting =
        draft && (mode === 'create' ? can('create') : can('update'));
    // Alamat sunting yang diketik sendiri tidak memberi hak apa pun: bila record ini
    // memang tidak boleh disunting, halaman tetap terbuka dalam mode baca dan tombol
    // Simpan tidak pernah muncul untuk permintaan yang pasti ditolak server.
    const editing = mode !== 'view' && dapatDisunting;
    const readOnly = !editing || saving;
    const canEditExecution =
        can('execute') && (status === 'dikerjakan' || status === 'selesai');
    const mintaSunting = () => {
        if (!dapatDisunting || editing || !record.id) {
            return;
        }

        bukaWorkOrderUbah(record.id);
    };

    const updateJob = (jobId: string | undefined, change: Partial<JobLine>) => {
        if (saving) {
            return;
        }

        setRecord(
            (current) =>
                current && {
                    ...current,
                    details: current.details.map((job) =>
                        job.id === jobId ? { ...job, ...change } : job,
                    ),
                },
        );
    };

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
                <span className="text-primary font-medium">
                    {job.aset_kode ?? '—'}
                </span>
            ),
            sortValue: (job) => job.aset_kode ?? '',
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
                    <FormControl>
                        <Input
                            disabled={saving}
                            aria-label={`Jam aktual ${job.aset_kode ?? ''}`}
                            type="number"
                            min="0"
                            step="0.25"
                            value={job.aktual_jam ?? ''}
                            onChange={(event) =>
                                updateJob(job.id, {
                                    aktual_jam:
                                        event.target.value === ''
                                            ? null
                                            : Number(event.target.value),
                                })
                            }
                        />
                    </FormControl>
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
                const selected = faultCauses.find(
                    (option) => option.id === job.sebab_kerusakan_id,
                );

                return canEditExecution ? (
                    <div className="flex flex-col gap-2">
                        <FormControl>
                            <RecordLookup
                                disabled={saving}
                                portalContainer={portalContainer}
                                source={faultSource}
                                items={faultCauses.map((option) => ({
                                    value: option.id,
                                    label: labelDari(option) ?? '',
                                }))}
                                value={selected?.id ?? null}
                                placeholder="Pilih bila ada"
                                searchPlaceholder="Cari sebab kerusakan"
                                ariaLabel={`Sebab kerusakan ${job.aset_kode ?? ''}`}
                                onValueChange={(value) => {
                                    const id = (value ?? '') || null;
                                    const mintaKeterangan = faultCauses.find(
                                        (option) => option.id === id,
                                    )?.minta_keterangan;
                                    updateJob(job.id, {
                                        sebab_kerusakan_id: id,
                                        ...(!mintaKeterangan && {
                                            sebab_kerusakan_keterangan: null,
                                        }),
                                    });
                                }}
                            />
                        </FormControl>
                        {selected?.minta_keterangan && (
                            <FormControl>
                                <Input
                                    disabled={saving}
                                    aria-label={`Keterangan sebab kerusakan ${job.aset_kode ?? ''}`}
                                    placeholder="Tulis sebab kerusakan"
                                    value={job.sebab_kerusakan_keterangan ?? ''}
                                    onChange={(event) =>
                                        updateJob(job.id, {
                                            sebab_kerusakan_keterangan:
                                                event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                        )}
                    </div>
                ) : (
                    <span>
                        {labelDari(selected) ??
                            job.sebab_kerusakan_nama ??
                            'Belum diisi'}
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
                        <FormControl>
                            <RecordLookup
                                disabled={saving}
                                portalContainer={portalContainer}
                                source={repairSource}
                                items={repairActions.map((option) => ({
                                    value: option.id,
                                    label: labelDari(option) ?? '',
                                }))}
                                value={selected?.id ?? null}
                                placeholder="Pilih bila ada"
                                searchPlaceholder="Cari tindakan perbaikan"
                                ariaLabel={`Tindakan perbaikan ${job.aset_kode ?? ''}`}
                                onValueChange={(value) => {
                                    const id = (value ?? '') || null;
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
                        </FormControl>
                        {selected?.minta_keterangan && (
                            <FormControl>
                                <Input
                                    disabled={saving}
                                    aria-label={`Keterangan tindakan perbaikan ${job.aset_kode ?? ''}`}
                                    placeholder="Tulis tindakan perbaikan"
                                    value={
                                        job.tindakan_perbaikan_keterangan ?? ''
                                    }
                                    onChange={(event) =>
                                        updateJob(job.id, {
                                            tindakan_perbaikan_keterangan:
                                                event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                        )}
                    </div>
                ) : (
                    <span>
                        {labelDari(selected) ??
                            job.tindakan_perbaikan_nama ??
                            'Belum diisi'}
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
                </div>
            ),
            width: 190,
        },
    ];

    return (
        <div
            ref={feedbackScopeRef}
            className="flex h-full min-h-0 flex-col overflow-hidden"
        >
            <Dialog
                open={cancellationLabel !== null}
                onOpenChange={(open) => {
                    if (!open && !saving) {
                        setCancellationLabel(null);
                    }
                }}
            >
                <DialogContent
                    ref={cancellationScopeRef}
                    size="compact"
                    onEscapeKeyDown={(event) => {
                        if (saving) {
                            event.preventDefault();
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Batalkan work order {record.kode}
                        </DialogTitle>
                        <DialogDescription>
                            Isi alasan pembatalan. Work order baru dibatalkan
                            setelah kamu memilih Batalkan work order.
                        </DialogDescription>
                    </DialogHeader>
                    {cancellationFeedback}
                    <DialogBody>
                        <FormControl
                            data-error-field="alasan"
                            error={
                                cancellationFieldError ? (
                                    <span id={cancellationReasonErrorId}>
                                        {cancellationFieldError}
                                    </span>
                                ) : undefined
                            }
                        >
                            <Textarea
                                label="Alasan pembatalan"
                                required
                                value={cancellationReason}
                                readOnly={saving}
                                aria-invalid={!!cancellationFieldError}
                                aria-describedby={
                                    cancellationFieldError
                                        ? cancellationReasonErrorId
                                        : undefined
                                }
                                onChange={(event) =>
                                    setCancellationReason(event.target.value)
                                }
                            />
                        </FormControl>
                    </DialogBody>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={() => setCancellationLabel(null)}
                        >
                            Kembali
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={saving}
                            onClick={() => {
                                if (cancellationLabel) {
                                    void pindahStatus(
                                        'dibatalkan',
                                        cancellationLabel,
                                        cancellationReason,
                                    );
                                }
                            }}
                        >
                            {saving ? 'Memproses…' : 'Batalkan work order'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <RecordCardHeader
                recordTitle={record.kode ?? 'Baru'}
                editing={editing}
                recordUrl={
                    workOrderId
                        ? `/management-aset/pemeliharaan-aset/${workOrderId}`
                        : undefined
                }
                onEdit={
                    dapatDisunting && record.id && !editing
                        ? mintaSunting
                        : undefined
                }
            />
            <RecordActionBar
                feedback={feedbackSummary}
                title={
                    embedded
                        ? undefined
                        : record.id
                          ? `Work order ${record.kode}`
                          : 'Work order baru'
                }
                trailing={status ? <StatusBadge status={status} /> : undefined}
            >
                {(editing || canEditExecution) && (
                    <>
                        <ActionButton
                            action="save"
                            type="button"
                            disabled={saving}
                            onClick={() =>
                                void (canEditExecution
                                    ? saveExecution()
                                    : save())
                            }
                            pending={saving}
                        />
                        <ActionButton
                            action="cancel"
                            disabled={saving}
                            type="button"
                            onClick={() => {
                                if (canEditExecution) {
                                    setChecklistDrafts({});
                                    setChecklistMuatan(null);
                                    setLocalChecklistJobId(null);
                                    clearSaveFeedback();
                                    muatUlang();
                                } else if (record.id) {
                                    bukaWorkOrder(record.id);
                                } else {
                                    bukaDaftar();
                                }
                            }}
                        />
                    </>
                )}
                {!editing && (
                    <>
                        {dapatDisunting && record.id && !embedded && (
                            <ActionButton
                                action="edit"
                                type="button"
                                onClick={mintaSunting}
                            />
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
                                            transisi.ke === 'dibatalkan'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        disabled={
                                            saving ||
                                            mainDirty ||
                                            auxiliaryDirty
                                        }
                                        onClick={() =>
                                            void pindahStatus(
                                                transisi.ke,
                                                transisi.label,
                                            )
                                        }
                                    >
                                        {transisi.label}
                                    </Button>
                                ))}
                        {record.id && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    requestPrint({
                                        report: 'work-order',
                                        title: `Cetak work order ${record.kode ?? ''}`,
                                        parameters: { id: record.id },
                                    })
                                }
                            >
                                <Printer />
                                Cetak
                            </Button>
                        )}
                        {!embedded && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={bukaDaftar}
                            >
                                Kembali ke daftar
                            </Button>
                        )}
                    </>
                )}
            </RecordActionBar>

            <div
                data-page-dialog-body
                inert={saving || undefined}
                className="min-h-0 flex-1 overflow-y-auto"
            >
                <div className="space-y-4 p-5">
                    <p className="text-muted-foreground text-sm">
                        Perusahaan dan unit penanggung jawab mengikuti konteks
                        aktif Anda.
                    </p>
                    <CollapsibleSectionGroup
                        value={feedbackSections}
                        onValueChange={setFeedbackSections}
                    >
                        <CollapsibleSection
                            value="general"
                            title="Umum"
                            summary={
                                record.tipe_work_order_nama ??
                                tipe.find(
                                    (option) =>
                                        option.id === record.tipe_work_order_id,
                                )?.nama ??
                                'Tipe work order belum dipilih'
                            }
                        >
                            <div className="space-y-4">
                                <FormGrid>
                                    <FormControl
                                        data-error-field="tipe_work_order_id"
                                        hint={
                                            <>
                                                Tipe menentukan apa yang wajib
                                                diisi sebelum pekerjaan boleh
                                                dinyatakan selesai.
                                            </>
                                        }
                                    >
                                        <EditShield
                                            active={readOnly}
                                            label="tipe work order"
                                            onActivate={mintaSunting}
                                        >
                                            <RecordLookup
                                                portalContainer={
                                                    portalContainer
                                                }
                                                label="Tipe work order"
                                                required
                                                source={typeSource}
                                                items={tipe.map((option) => ({
                                                    value: option.id,
                                                    label:
                                                        labelDari(option) ?? '',
                                                }))}
                                                value={
                                                    record.tipe_work_order_id ||
                                                    null
                                                }
                                                placeholder="Pilih tipe work order"
                                                searchPlaceholder="Cari tipe work order"
                                                ariaLabel="Tipe work order"
                                                onValueChange={(value) =>
                                                    setRecord({
                                                        ...record,
                                                        tipe_work_order_id:
                                                            value ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                    </FormControl>
                                    <FormControl data-error-field="tingkat_layanan_id">
                                        <EditShield
                                            active={readOnly}
                                            label="tingkat layanan"
                                            onActivate={mintaSunting}
                                        >
                                            <RecordLookup
                                                portalContainer={
                                                    portalContainer
                                                }
                                                label="Tingkat layanan"
                                                source={serviceSource}
                                                items={layanan.map(
                                                    (option) => ({
                                                        value: option.id,
                                                        label:
                                                            labelDari(option) ??
                                                            '',
                                                    }),
                                                )}
                                                value={
                                                    record.tingkat_layanan_id ||
                                                    null
                                                }
                                                placeholder="Pilih tingkat layanan"
                                                searchPlaceholder="Cari tingkat layanan"
                                                ariaLabel="Tingkat layanan"
                                                onValueChange={(value) =>
                                                    setRecord({
                                                        ...record,
                                                        tingkat_layanan_id:
                                                            value ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                    </FormControl>
                                    <FormControl
                                        data-error-field="diharapkan_mulai"
                                        hint={
                                            <>
                                                Batas waktu yang diharapkan
                                                untuk memulai pekerjaan.
                                            </>
                                        }
                                    >
                                        <DateTimePicker
                                            portalContainer={portalContainer}
                                            label="Diharapkan mulai"

                                            readOnly={readOnly}
                                            onFocus={
                                                readOnly
                                                    ? mintaSunting
                                                    : undefined
                                            }
                                            value={
                                                record.diharapkan_mulai
                                                    ?.replace(' ', 'T')
                                                    .slice(0, 16) ?? ''
                                            }
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    diharapkan_mulai: value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                    <FormControl
                                        data-error-field="diharapkan_selesai"
                                        hint={
                                            <>
                                                Batas waktu yang diharapkan
                                                untuk menyelesaikan pekerjaan.
                                            </>
                                        }
                                    >
                                        <DateTimePicker
                                            portalContainer={portalContainer}
                                            label="Diharapkan selesai"

                                            readOnly={readOnly}
                                            onFocus={
                                                readOnly
                                                    ? mintaSunting
                                                    : undefined
                                            }
                                            value={
                                                record.diharapkan_selesai
                                                    ?.replace(' ', 'T')
                                                    .slice(0, 16) ?? ''
                                            }
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    diharapkan_selesai: value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                    <FormControl
                                        data-error-field="dijadwalkan_mulai"
                                        hint={
                                            <>
                                                Harus diisi sebelum work order
                                                dapat dijadwalkan.
                                            </>
                                        }
                                    >
                                        <DateTimePicker
                                            portalContainer={portalContainer}
                                            label="Dijadwalkan mulai"

                                            readOnly={readOnly}
                                            onFocus={
                                                readOnly
                                                    ? mintaSunting
                                                    : undefined
                                            }
                                            value={
                                                record.dijadwalkan_mulai
                                                    ?.replace(' ', 'T')
                                                    .slice(0, 16) ?? ''
                                            }
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    dijadwalkan_mulai: value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                    <FormControl data-error-field="dijadwalkan_selesai">
                                        <DateTimePicker
                                            portalContainer={portalContainer}
                                            label="Dijadwalkan selesai"

                                            readOnly={readOnly}
                                            onFocus={
                                                readOnly
                                                    ? mintaSunting
                                                    : undefined
                                            }
                                            value={
                                                record.dijadwalkan_selesai
                                                    ?.replace(' ', 'T')
                                                    .slice(0, 16) ?? ''
                                            }
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    dijadwalkan_selesai: value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                </FormGrid>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="lines"
                            title="Baris pekerjaan"
                            summary={`${record.details.length} baris`}
                        >
                            <div className="space-y-4">
                                <div className="space-y-3">
                                    {/* Pemberitahuan garansi dan kontrak servis pada tanggal mulai work
                            order; informasi saja, tidak menahan apa pun. */}
                                    <WarrantyNotice
                                        asetIds={record.details.map(
                                            (job) => job.aset_id,
                                        )}
                                        date={(
                                            record.dijadwalkan_mulai ||
                                            record.diharapkan_mulai ||
                                            ''
                                        ).slice(0, 10)}
                                        labelOf={(asetId) =>
                                            record.details.find(
                                                (job) => job.aset_id === asetId,
                                            )?.aset_kode ?? 'Aset'
                                        }
                                    />
                                    <div className="flex items-center justify-end">
                                        {draft && editing && (
                                            <ActionButton
                                                action="add"
                                                type="button"
                                                onClick={() =>
                                                    setRecord({
                                                        ...record,
                                                        details: [
                                                            ...record.details,
                                                            emptyJob(),
                                                        ],
                                                    })
                                                }
                                            />
                                        )}
                                    </div>
                                    {!draft ? (
                                        <DataTable
                                            columns={jobColumns}
                                            data={record.details}
                                            getRowKey={(job) => String(job.id)}
                                            getRowLabel={(job) =>
                                                job.aset_kode ??
                                                'baris pekerjaan'
                                            }
                                            actions={[
                                                {
                                                    id: 'checklist',
                                                    label: 'Buka checklist',
                                                },
                                            ]}
                                            onRowAction={(action, job) => {
                                                if (
                                                    action === 'checklist' &&
                                                    record.id
                                                ) {
                                                    bukaChecklistJob(
                                                        record.id,
                                                        String(job.id),
                                                    );
                                                }
                                            }}
                                        />
                                    ) : (
                                        record.details.map((job, index) => (
                                            <JobRow
                                                portalContainer={
                                                    portalContainer
                                                }
                                                key={index}
                                                job={job}
                                                index={index}
                                                canRemove={
                                                    record.details.length > 1
                                                }
                                                readOnly={readOnly}
                                                onRequestEdit={mintaSunting}
                                                onChange={(change) => {
                                                    setRecord({
                                                        ...record,
                                                        details:
                                                            record.details.map(
                                                                (
                                                                    current,
                                                                    position,
                                                                ) =>
                                                                    position ===
                                                                    index
                                                                        ? {
                                                                              ...current,
                                                                              ...change,
                                                                          }
                                                                        : current,
                                                            ),
                                                    });
                                                }}
                                                onRemove={() =>
                                                    setRecord({
                                                        ...record,
                                                        details:
                                                            record.details.filter(
                                                                (_, position) =>
                                                                    position !==
                                                                    index,
                                                            ),
                                                    })
                                                }
                                            />
                                        ))
                                    )}
                                </div>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="notes"
                            title="Keterangan"
                            summary={
                                record.keterangan || 'Belum ada keterangan'
                            }
                        >
                            <div className="space-y-4">
                                <FormControl data-error-field="keterangan">
                                    <FieldLabel htmlFor="work-order-keterangan">
                                        Keterangan
                                    </FieldLabel>
                                    <Textarea
                                        id="work-order-keterangan"
                                        rows={4}
                                        readOnly={readOnly}
                                        onFocus={
                                            readOnly ? mintaSunting : undefined
                                        }
                                        value={record.keterangan ?? ''}
                                        onChange={(event) =>
                                            setRecord({
                                                ...record,
                                                keterangan: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </div>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                </div>

                {checklist && (
                    <ChecklistPanel
                        portalContainer={portalContainer}
                        rows={checklist}
                        editable={
                            can('execute') && status === 'dikerjakan' && !saving
                        }
                        onClose={() => setLocalChecklistJobId(null)}
                        onChange={(id, change) => {
                            if (saving || !checklistJobId) {
                                return;
                            }

                            setChecklistDrafts((current) => ({
                                ...current,
                                [checklistJobId]: (
                                    current[checklistJobId] ?? checklist
                                ).map((row) =>
                                    row.id === id ? { ...row, ...change } : row,
                                ),
                            }));
                        }}
                    />
                )}
            </div>
        </div>
    );
}
