import { Eye, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
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
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
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
import { useRecordOptions } from '../../use-record-list';
import { PostingPreviewPanel } from '../_shared/PostingPreviewPanel';
import type { PostingPreview } from '../_shared/PostingPreviewPanel';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import type { Disposal, DisposalAmounts, DisposalResource } from './disposal';
import {
    DISPOSALS,
    StatusBadge,
    bukaDaftar as navigateClose,
    bukaDokumen as navigateView,
    izin,
    postingLabel,
    tanggalTampil,
    uang,
} from './disposal';

type AsetPilihan = {
    id: string;
    kode: string;
    nama: string | null;
    lifecycle_state: string;
    legal_entity_id: string;
    responsible_org_unit_id: string | null;
};

type Isian = {
    aset_id: string;
    tanggal: string;
    nilai: string;
    keterangan: string;
};

/** Dari jawaban server ke bentuk form: nilai kosong dijadikan string kosong yang terkendali. */
const keIsian = (data: Disposal): Isian => ({
    aset_id: data.aset_id,
    tanggal: data.tanggal?.slice(0, 10) ?? '',
    nilai: data.nilai ?? '',
    keterangan: data.keterangan ?? '',
});

const labelAset = (aset: AsetPilihan) =>
    aset.nama ? `${aset.kode} · ${aset.nama}` : aset.kode;

/**
 * Rincian satu penjualan atau pemusnahan aset, alur jurnal aset tetap Business Central: draf disimpan,
 * *Pratinjau posting* menampilkan nilai buku yang dikeluarkan dan jurnalnya, lalu *Posting* melepas aset,
 * menutup bukunya, dan mengirim jurnal pelepasan ke aplikasi finance.
 */
export default function DisposalDetailPage({
    resource,
    permissions,
    disposalId,
    onClose,
    onSaved,
    onRequestView,
    onBusyChange,
    onDirtyChange,
    portalContainer,
}: {
    resource: DisposalResource;
    permissions: string[];
    disposalId?: string;
} & RecordCardControls) {
    const embedded = usePageDialogRecord(undefined);

    const bukaDokumen = (resource: DisposalResource, id: string) =>
        onRequestView ? onRequestView(id) : navigateView(resource, id);
    const bukaDaftar = (resource: DisposalResource) =>
        onClose ? onClose() : navigateClose(resource);

    const jenis = DISPOSALS[resource];
    const can = izin(resource, permissions);
    const { date: workDate } = useWorkDate();
    const [dokumen, setDokumen] = useState<Disposal | null>(null);
    const [isian, setIsian] = useState<Isian>({
        aset_id: '',
        tanggal: workDate,
        nilai: '',
        keterangan: '',
    });
    const [memuat, setMemuat] = useState(Boolean(disposalId));
    const [menyimpan, setMenyimpan] = useState(false);
    useEffect(() => {
        onBusyChange?.(menyimpan);

        return () => onBusyChange?.(false);
    }, [menyimpan, onBusyChange]);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [pratinjau, setPratinjau] = useState<
        | (PostingPreview & {
              currency_code: string;
              amounts: DisposalAmounts | null;
          })
        | null
    >(null);
    const [konfirmasi, setKonfirmasi] = useState<'posting' | 'batal' | null>(
        null,
    );
    const panelRef = useRef<HTMLDivElement>(null);

    const baru = !disposalId;
    const draf = baru || dokumen?.status === 'draft';
    const bolehUbah = draf && can('create');

    // Aset yang boleh dilepas: yang dekomisioningnya sudah disetujui.

    const assetSource = useRecordOptions<AsetPilihan>(
        '/aset?lifecycle_state=decommissioned',
        (item) => (item.nama ? `${item.kode} \u00b7 ${item.nama}` : item.kode),
        isian.aset_id,
    );
    const aset = assetSource.options;

    useEffect(() => {
        if (!disposalId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Disposal }>(`/${resource}/${disposalId}`)
            .then((result) => {
                if (!dibatalkan) {
                    setDokumen(result.data);
                    setIsian(keIsian(result.data));
                }
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Dokumen belum dapat dimuat.'),
                ),
            )
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [resource, disposalId]);

    function terima(data: Disposal) {
        setDokumen(data);
        setIsian(keIsian(data));
    }

    const pesan = (field: string) => galat[field]?.[0];

    async function simpan() {
        setMenyimpan(true);
        setGalat({});
        setPratinjau(null);

        try {
            if (baru) {
                const dipilih = aset.find((item) => item.id === isian.aset_id);
                const hasil = await api<{ data: Disposal }>(`/${resource}`, {
                    method: 'POST',
                    headers: { 'Idempotency-Key': newIdempotencyKey() },
                    body: JSON.stringify({
                        legal_entity_id: dipilih?.legal_entity_id ?? null,
                        responsible_org_unit_id:
                            dipilih?.responsible_org_unit_id ?? null,
                        aset_id: isian.aset_id || null,
                        tanggal: isian.tanggal,
                        nilai: jenis.hasProceeds ? isian.nilai || null : null,
                        keterangan: isian.keterangan || null,
                    }),
                });
                toast.success(`Draf ${hasil.data.kode} disimpan.`);

                if (onSaved) {
                    onSaved(hasil.data.id);
                } else {
                    bukaDokumen(resource, hasil.data.id);
                }

                return;
            }

            const hasil = await api<{ data: Disposal }>(
                `/${resource}/${disposalId}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        tanggal: isian.tanggal,
                        ...(jenis.hasProceeds
                            ? { nilai: isian.nilai || null }
                            : {}),
                        keterangan: isian.keterangan || null,
                        version: dokumen?.version,
                    }),
                },
            );
            terima(hasil.data);
            toast.success('Perubahan draf disimpan.');
            onSaved?.(hasil.data.id);
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Draf belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function muatPratinjau() {
        setMenyimpan(true);

        try {
            const hasil = await api<{
                data: PostingPreview & {
                    currency_code: string;
                    amounts: DisposalAmounts | null;
                };
            }>(`/${resource}/${disposalId}/pratinjau-posting`);
            setPratinjau(hasil.data);
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Pratinjau posting belum dapat disusun.'),
            );
        } finally {
            setMenyimpan(false);
        }
    }

    async function jalankan(aksi: 'posting' | 'batal') {
        setKonfirmasi(null);
        setMenyimpan(true);
        setGalat({});

        try {
            const hasil = await api<{ data: Disposal }>(
                `/${resource}/${disposalId}/${aksi}`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: dokumen?.version }),
                },
            );
            terima(hasil.data);
            setPratinjau(null);
            toast.success(
                aksi === 'batal'
                    ? 'Draf dibatalkan.'
                    : `Aset dilepas. Jurnal: ${postingLabel(hasil.data.posting?.status).toLowerCase()}.`,
            );
            onSaved?.(hasil.data.id);
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(
                caught,
                aksi === 'batal'
                    ? 'Draf belum dapat dibatalkan.'
                    : 'Dokumen belum dapat diposting.',
            );
        } finally {
            setMenyimpan(false);
        }
    }

    useRecordCardDirty({
        value: isian,
        ready: !memuat,
        scopeKey: JSON.stringify([disposalId, dokumen?.version]),
        onDirtyChange: onDirtyChange,
    });

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat dokumen…
            </div>
        );
    }

    const dipilih = aset.find((item) => item.id === isian.aset_id);
    const jumlah = pratinjau?.amounts;

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordCardHeader
                recordTitle={dokumen?.kode ?? 'Baru'}
                editing={bolehUbah}
                recordUrl={
                    disposalId
                        ? `/management-aset/${resource}/${disposalId}`
                        : undefined
                }
            />
            <RecordActionBar
                title={
                    embedded
                        ? undefined
                        : baru
                          ? jenis.create
                          : (dokumen?.kode ?? jenis.title)
                }
                trailing={
                    dokumen ? (
                        <StatusBadge status={dokumen.status} />
                    ) : undefined
                }
            >
                {bolehUbah && (
                    <ActionButton
                        action="save"
                        type="button"
                        onClick={() => void simpan()}
                        disabled={menyimpan}
                        pending={menyimpan}
                    />
                )}
                <ActionButton
                    action="cancel"
                    disabled={menyimpan}
                    type="button"
                    onClick={() => bukaDaftar(resource)}
                />
                {!baru && draf && (can('post') || can('create')) && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => void muatPratinjau()}
                        disabled={menyimpan}
                    >
                        <Eye />
                        Pratinjau posting
                    </Button>
                )}
                {!baru && draf && can('post') && (
                    <Button
                        type="button"
                        onClick={() => setKonfirmasi('posting')}
                        disabled={menyimpan}
                    >
                        <Send />
                        Posting
                    </Button>
                )}
                {!baru && draf && can('create') && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setKonfirmasi('batal')}
                        disabled={menyimpan}
                    >
                        Batalkan draf
                    </Button>
                )}
            </RecordActionBar>

            <div
                data-page-dialog-body
                ref={panelRef}
                className="min-h-0 flex-1 overflow-y-auto"
            >
                <div className="space-y-5 p-5">
                    <CollapsibleSectionGroup defaultValue={['general']}>
                        <CollapsibleSection
                            value="general"
                            title="Umum"
                            summary={tanggalTampil(isian.tanggal)}
                        >
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field>
                                        {baru ? (
                                            <Select
                                                label="Aset"
                                                required
                                                {...assetSource.selectProps}
                                                items={aset.map(labelAset)}
                                                value={
                                                    dipilih
                                                        ? labelAset(dipilih)
                                                        : null
                                                }
                                                placeholder="Pilih aset yang sudah didekomisioning"
                                                searchPlaceholder="Cari kode atau nama aset"
                                                emptyMessage="Tidak ada aset yang sudah disetujui untuk dekomisioning."
                                                ariaLabel="Aset"
                                                portalContainer={
                                                    portalContainer ?? panelRef
                                                }
                                                onValueChange={(item) =>
                                                    setIsian({
                                                        ...isian,
                                                        aset_id:
                                                            aset.find(
                                                                (calon) =>
                                                                    labelAset(
                                                                        calon,
                                                                    ) === item,
                                                            )?.id ?? '',
                                                    })
                                                }
                                            />
                                        ) : (
                                            <Input
                                                label="Aset"
                                                readOnly
                                                value={
                                                    [
                                                        dokumen?.aset_kode,
                                                        dokumen?.aset_nama,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') || '—'
                                                }
                                            />
                                        )}
                                        {pesan('aset_id') && (
                                            <FieldDescription>
                                                {pesan('aset_id')}
                                            </FieldDescription>
                                        )}
                                    </Field>
                                    <Field>
                                        <Input
                                            label={`Tanggal ${jenis.noun}`}
                                            type="date"
                                            required
                                            readOnly={!bolehUbah}
                                            value={isian.tanggal}
                                            onChange={(event) =>
                                                setIsian({
                                                    ...isian,
                                                    tanggal: event.target.value,
                                                })
                                            }
                                        />
                                        <FieldDescription>
                                            {pesan('tanggal') ||
                                                'Tanggal jurnal dan tanggal buku aset ditutup. Penyusutan sampai tanggal ini harus sudah difinalkan.'}
                                        </FieldDescription>
                                    </Field>
                                    {jenis.hasProceeds && (
                                        <Field>
                                            <Input
                                                label="Nilai penjualan"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                readOnly={!bolehUbah}
                                                value={isian.nilai}
                                                onChange={(event) =>
                                                    setIsian({
                                                        ...isian,
                                                        nilai: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <FieldDescription>
                                                {pesan('nilai') ||
                                                    'Selisihnya dengan nilai buku menjadi laba atau rugi pelepasan.'}
                                            </FieldDescription>
                                        </Field>
                                    )}
                                </div>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="notes"
                            title="Keterangan"
                            summary={isian.keterangan || 'Belum ada keterangan'}
                        >
                            <div className="space-y-4">
                                <Field>
                                    <Textarea
                                        label="Keterangan"
                                        rows={2}
                                        readOnly={!bolehUbah}
                                        value={isian.keterangan}
                                        onChange={(event) =>
                                            setIsian({
                                                ...isian,
                                                keterangan: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                    {dokumen?.status === 'posted' && (
                        <p className="text-sm">
                            Aset ini sudah dilepas per{' '}
                            {tanggalTampil(dokumen.tanggal)}. Jurnal pelepasan:{' '}
                            {postingLabel(
                                dokumen.posting?.status,
                            ).toLowerCase()}
                            .
                        </p>
                    )}
                    {dokumen?.status === 'cancelled' && (
                        <p className="text-muted-foreground text-sm">
                            Draf ini dibatalkan. Asetnya tidak berubah.
                        </p>
                    )}
                    {pratinjau && (
                        <div className="space-y-3 rounded-md border px-4 py-3">
                            <p className="text-sm font-medium">
                                Pratinjau posting
                            </p>
                            {jumlah && (
                                <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                    <dt className="text-muted-foreground">
                                        Harga perolehan ({jumlah.book})
                                    </dt>
                                    <dd className="text-right">
                                        {uang(jumlah.acquisition_value)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Akumulasi penyusutan
                                    </dt>
                                    <dd className="text-right">
                                        {uang(jumlah.accumulated_depreciation)}
                                    </dd>
                                    {Number(jumlah.write_down_amount) !== 0 && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Penurunan nilai
                                            </dt>
                                            <dd className="text-right">
                                                {uang(jumlah.write_down_amount)}
                                            </dd>
                                        </>
                                    )}
                                    {Number(jumlah.appreciation_amount) !==
                                        0 && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Kenaikan nilai
                                            </dt>
                                            <dd className="text-right">
                                                {uang(
                                                    jumlah.appreciation_amount,
                                                )}
                                            </dd>
                                        </>
                                    )}
                                    <dt className="text-muted-foreground">
                                        Nilai buku
                                    </dt>
                                    <dd className="text-right">
                                        {uang(jumlah.net_book_value)}
                                    </dd>
                                    {jenis.hasProceeds && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Nilai penjualan
                                            </dt>
                                            <dd className="text-right">
                                                {uang(jumlah.proceeds)}
                                            </dd>
                                        </>
                                    )}
                                    <dt className="font-medium">
                                        {Number(jumlah.gain_loss) < 0
                                            ? 'Rugi pelepasan'
                                            : 'Laba pelepasan'}
                                    </dt>
                                    <dd className="text-right font-medium">
                                        {uang(
                                            String(
                                                Math.abs(
                                                    Number(jumlah.gain_loss),
                                                ),
                                            ),
                                        )}
                                    </dd>
                                </dl>
                            )}
                            <PostingPreviewPanel
                                preview={pratinjau}
                                currencyCode={
                                    pratinjau.currency_code ??
                                    dokumen?.currency_code ??
                                    'IDR'
                                }
                            />
                        </div>
                    )}
                </div>
            </div>

            <AlertDialog
                open={konfirmasi !== null}
                onOpenChange={(open) => !open && setKonfirmasi(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {konfirmasi === 'batal'
                                ? 'Batalkan draf ini?'
                                : `Posting ${jenis.noun} ini?`}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {konfirmasi === 'batal'
                                ? 'Draf tidak dapat dipakai lagi. Asetnya tidak berubah dan nomornya tidak dipakai ulang.'
                                : `Aset dilepas dan seluruh bukunya ditutup per ${tanggalTampil(isian.tanggal)}, lalu jurnal pelepasannya dikirim ke aplikasi finance. Setelah diposting, dokumen ini tidak dapat diubah.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Belum</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                konfirmasi && void jalankan(konfirmasi)
                            }
                        >
                            {konfirmasi === 'batal'
                                ? 'Batalkan draf'
                                : 'Posting'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
