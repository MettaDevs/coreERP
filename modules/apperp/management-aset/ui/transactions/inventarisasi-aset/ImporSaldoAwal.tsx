import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { FieldError, FieldLabel } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { RecordLookup } from '../../_shared/RecordLookup';

import { api, ApiError, newIdempotencyKey } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { useSaveFeedback } from '../../use-save-feedback';
import type { Context, LaporanImpor } from './penerimaan';
import { tanggalTampil } from './penerimaan';

const TEMPLAT =
    '/api/modules/management-aset/v1/penerimaan-aset/impor-saldo-awal/templat';

const rupiah = (nilai?: string) =>
    nilai === undefined
        ? '—'
        : Number(nilai).toLocaleString('id-ID', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          });

/**
 * Impor saldo awal aset lama dari CSV (feed posting finance, TODO 10.6).
 *
 * Berkas diperiksa lebih dulu: pratinjau menyebut draf yang akan lahir — satu per tanggal perolehan,
 * tanggal siap pakai, dan lokasi — atau baris yang ditolak beserta alasannya. Draf baru dibuat
 * setelah pratinjaunya bersih, semuanya atau tidak sama sekali, dan tetap draf: jurnal saldo awalnya
 * terbit saat tiap draf diselesaikan.
 */
export function ImporSaldoAwal({
    context,
    onClose,
    onApplied,
}: {
    context: Context;
    onClose: () => void;
    onApplied: () => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    const { feedbackSummary, reportSaveError, clearSaveFeedback, fieldError } =
        useSaveFeedback(contentRef);
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const [unit, setUnit] = useState(context.org_unit_id ?? '');
    const [penerima, setPenerima] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [laporan, setLaporan] = useState<LaporanImpor | null>(null);
    const [galat, setGalat] = useState('');
    const [sibuk, setSibuk] = useState(false);
    // Satu kunci per berkas yang diperiksa: menekan "Buat draf" dua kali tidak membuat draf dobel.
    const [kunci, setKunci] = useState(newIdempotencyKey);

    const bersih =
        laporan?.status === 'preview' &&
        laporan.rejected.length === 0 &&
        laporan.receipts.length > 0;

    const kirim = async (apply: boolean) => {
        clearSaveFeedback();

        if (!file || !context.legal_entity_id || !unit) {
            const errors: Record<string, string[]> = {};

            if (!file) {
                errors.file = ['Pilih berkas CSV yang akan diperiksa.'];
            }

            if (!unit) {
                errors.responsible_org_unit_id = ['Pilih unit pengguna aset.'];
            }

            reportSaveError(
                new ApiError(
                    !context.legal_entity_id
                        ? 'Pilih perusahaan aktif terlebih dahulu pada header aplikasi.'
                        : 'Lengkapi isian impor.',
                    errors,
                    422,
                ),
                'Impor belum dapat dilanjutkan.',
            );

            return;
        }

        const body = new FormData();
        body.append('file', file);
        body.append('legal_entity_id', context.legal_entity_id);
        body.append('responsible_org_unit_id', unit);

        if (penerima) {
            body.append('receiving_org_unit_id', penerima);
        }

        body.append('apply', apply ? '1' : '0');
        setSibuk(true);
        setGalat('');

        try {
            const hasil = await api<{ data: LaporanImpor }>(
                '/penerimaan-aset/impor-saldo-awal',
                {
                    method: 'POST',
                    body,
                    headers: apply ? { 'Idempotency-Key': kunci } : {},
                },
            );
            setLaporan(hasil.data);

            if (hasil.data.status === 'applied') {
                toast.success(
                    `${hasil.data.receipts.length} draf saldo awal dibuat. Periksa jurnalnya lalu selesaikan tiap draf dari daftar ini.`,
                );
                onApplied();
                onClose();
            }
        } catch (caught) {
            reportSaveError(
                caught,
                apply
                    ? 'Draf saldo awal belum dapat dibuat.'
                    : 'Berkas belum dapat diperiksa.',
            );
        } finally {
            setSibuk(false);
        }
    };

    const ganti = (berkas: File | null) => {
        setFile(berkas);
        setLaporan(null);
        setKunci(newIdempotencyKey());
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={contentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-2xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>Impor saldo awal aset</SheetTitle>
                    <SheetDescription>
                        Aset lama dari sistem sebelumnya, satu baris satu jenis
                        barang. Baris bertanggal perolehan, tanggal siap pakai,
                        dan lokasi yang sama menjadi satu draf penerimaan saldo
                        awal.
                    </SheetDescription>
                </SheetHeader>
                {feedbackSummary}
                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    {!context.legal_entity_id ? (
                        <p className="text-destructive text-sm">
                            Pilih perusahaan aktif terlebih dahulu pada header
                            CoreERP.
                        </p>
                    ) : (
                        <FormGrid columns={1}>
                            <FormControl
                                data-error-field="responsible_org_unit_id"
                                error={fieldError('responsible_org_unit_id')}
                                hint={
                                    <>
                                        Unit yang menanggung seluruh aset dari
                                        berkas ini. Impor per unit bila asetnya
                                        tersebar.
                                    </>
                                }
                            >
                                <RecordLookup
                                    source={unitKerja}
                                    label="Unit pengguna"
                                    required
                                    items={[
                                        ...unitKerja.options.map((record) => ({
                                            value: record.id,
                                            label: optionLabel(record),
                                        })),
                                    ]}
                                    value={unit || null}
                                    placeholder="Pilih unit"
                                    portalContainer={contentRef}
                                    onValueChange={(value) => {
                                        setUnit(value ?? '');
                                        setLaporan(null);
                                    }}
                                />
                            </FormControl>
                            <FormControl
                                data-error-field="receiving_org_unit_id"
                                error={fieldError('receiving_org_unit_id')}
                            >
                                <RecordLookup
                                    source={unitKerja}
                                    label="Unit penerima"
                                    items={[
                                        {
                                            value: '',
                                            label: 'Tanpa unit penerima',
                                        },
                                        ...unitKerja.options.map((record) => ({
                                            value: record.id,
                                            label: optionLabel(record),
                                        })),
                                    ]}
                                    value={penerima || null}
                                    placeholder="Tanpa unit penerima"
                                    portalContainer={contentRef}
                                    onValueChange={(value) => {
                                        setPenerima(value ?? '');
                                    }}
                                />
                            </FormControl>
                            <FormControl
                                data-invalid={Boolean(fieldError('file'))}
                                data-error-field="file"
                                error={fieldError('file')}
                                hint={
                                    <>
                                        Pemisah koma atau titik koma, paling
                                        besar 2 MB. Akumulasi buku selain buku
                                        yang di-post ditulis di kolom{' '}
                                        <code>
                                            akumulasi_per_unit:KODE BUKU
                                        </code>{' '}
                                        dan{' '}
                                        <code>periode_berjalan:KODE BUKU</code>.{' '}
                                        <a
                                            className="text-primary underline-offset-4 hover:underline"
                                            href={TEMPLAT}
                                        >
                                            Unduh templat
                                        </a>
                                    </>
                                }
                            >
                                <FieldLabel htmlFor="berkas-saldo-awal">
                                    Berkas CSV
                                </FieldLabel>
                                <Input
                                    id="berkas-saldo-awal"
                                    type="file"
                                    accept=".csv,.txt,text/csv"
                                    onChange={(event) =>
                                        ganti(event.target.files?.[0] ?? null)
                                    }
                                />

                                <FieldError>{galat}</FieldError>
                            </FormControl>
                        </FormGrid>
                    )}

                    {laporan && laporan.rejected.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-destructive text-sm font-medium">
                                {laporan.rejected.length} masalah; belum ada
                                draf yang dibuat.
                            </p>
                            {(() => {
                                const sdkRows = laporan.rejected.map(
                                    (masalah, urut) => {
                                        return { masalah, urut };
                                    },
                                );
                                const sdkColumns: DataTableColumn<
                                    (typeof sdkRows)[number]
                                >[] = [
                                    {
                                        id: 'column-0',
                                        header: 'Baris',
                                        width: 80,
                                        cell: ({ masalah }) => (
                                            <>{masalah.line}</>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-1',
                                        header: 'Kolom',
                                        width: 176,
                                        cell: ({ masalah }) => (
                                            <>{masalah.field ?? '—'}</>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-2',
                                        header: 'Masalah',
                                        cell: ({ masalah }) => (
                                            <div className="whitespace-normal">
                                                {masalah.reason}
                                            </div>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                ];

                                return (
                                    <DataTable
                                        scrollMode="inline"
                                        showRowNumbers={false}
                                        serverSort
                                        data={sdkRows}
                                        columns={sdkColumns}
                                        getRowKey={({ masalah, urut }) =>
                                            `${masalah.line}-${masalah.field}-${urut}`
                                        }
                                    />
                                );
                            })()}
                        </div>
                    )}

                    {bersih && (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">
                                {laporan.receipts.length} draf dari{' '}
                                {laporan.rows} baris siap dibuat.
                            </p>
                            {(() => {
                                const sdkRows = laporan.receipts.map(
                                    (draf, urut) => {
                                        return { draf, urut };
                                    },
                                );
                                const sdkColumns: DataTableColumn<
                                    (typeof sdkRows)[number]
                                >[] = [
                                    {
                                        id: 'column-0',
                                        header: 'Tanggal perolehan',
                                        cell: ({ draf }) => (
                                            <>{tanggalTampil(draf.tanggal)}</>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-1',
                                        header: 'Lokasi',
                                        cell: ({ draf }) => (
                                            <>{draf.lokasi ?? 'Tanpa lokasi'}</>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-2',
                                        header: 'Baris berkas',
                                        cell: ({ draf }) => (
                                            <>{(draf.lines ?? []).join(', ')}</>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-3',
                                        header: 'Aset',
                                        align: 'right',
                                        cell: ({ draf }) => (
                                            <div className="text-right">
                                                {draf.jumlah_aset}
                                            </div>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-4',
                                        header: 'Nilai',
                                        align: 'right',
                                        cell: ({ draf }) => (
                                            <div className="text-right">
                                                {rupiah(draf.nilai)}
                                            </div>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                    {
                                        id: 'column-5',
                                        header: 'Akumulasi',
                                        align: 'right',
                                        cell: ({ draf }) => (
                                            <div className="text-right">
                                                {rupiah(draf.akumulasi)}
                                            </div>
                                        ),
                                    } satisfies DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >,
                                ];

                                return (
                                    <DataTable
                                        scrollMode="inline"
                                        showRowNumbers={false}
                                        serverSort
                                        data={sdkRows}
                                        columns={sdkColumns}
                                        getRowKey={({ urut }) => urut}
                                    />
                                );
                            })()}
                        </div>
                    )}
                </div>
                <SheetFooter className="border-t px-6 py-4">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={sibuk}
                        onClick={() => kirim(false)}
                    >
                        {sibuk && !bersih ? 'Memeriksa…' : 'Periksa berkas'}
                    </Button>
                    <Button
                        type="button"
                        disabled={!bersih || sibuk}
                        onClick={() => kirim(true)}
                    >
                        {sibuk && bersih ? 'Membuat draf…' : 'Buat draf'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
