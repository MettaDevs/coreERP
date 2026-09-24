import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
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
        if (!file || !context.legal_entity_id || !unit) {
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
            setGalat(errorMessage(caught, 'Berkas belum dapat diperiksa.'));
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
                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    {!context.legal_entity_id ? (
                        <p className="text-destructive text-sm">
                            Pilih entitas legal aktif terlebih dahulu pada
                            header CoreERP.
                        </p>
                    ) : (
                        <FieldGroup>
                            <Field>
                                <NativeSelect
                                    label="Unit pengguna"
                                    required
                                    value={unit}
                                    onChange={(event) => {
                                        setUnit(event.target.value);
                                        setLaporan(null);
                                    }}
                                >
                                    <NativeSelectOption value="">
                                        Pilih unit
                                    </NativeSelectOption>
                                    {unitKerja.options.map((pilihan) => (
                                        <NativeSelectOption
                                            key={pilihan.id}
                                            value={pilihan.id}
                                        >
                                            {optionLabel(pilihan)}
                                        </NativeSelectOption>
                                    ))}
                                </NativeSelect>
                                <FieldDescription>
                                    Unit yang menanggung seluruh aset dari
                                    berkas ini. Impor per unit bila asetnya
                                    tersebar.
                                </FieldDescription>
                            </Field>
                            <Field>
                                <NativeSelect
                                    label="Unit penerima"
                                    value={penerima}
                                    onChange={(event) =>
                                        setPenerima(event.target.value)
                                    }
                                >
                                    <NativeSelectOption value="">
                                        Tanpa unit penerima
                                    </NativeSelectOption>
                                    {unitKerja.options.map((pilihan) => (
                                        <NativeSelectOption
                                            key={pilihan.id}
                                            value={pilihan.id}
                                        >
                                            {optionLabel(pilihan)}
                                        </NativeSelectOption>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field data-invalid={Boolean(galat)}>
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
                                <FieldDescription>
                                    Pemisah koma atau titik koma, paling besar 2
                                    MB. Akumulasi buku selain buku yang di-post
                                    ditulis di kolom{' '}
                                    <code>akumulasi_per_unit:KODE BUKU</code>{' '}
                                    dan <code>periode_berjalan:KODE BUKU</code>.{' '}
                                    <a
                                        className="text-primary underline-offset-4 hover:underline"
                                        href={TEMPLAT}
                                    >
                                        Unduh templat
                                    </a>
                                </FieldDescription>
                                <FieldError>{galat}</FieldError>
                            </Field>
                        </FieldGroup>
                    )}

                    {laporan && laporan.rejected.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-destructive text-sm font-medium">
                                {laporan.rejected.length} masalah; belum ada
                                draf yang dibuat.
                            </p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-20">
                                            Baris
                                        </TableHead>
                                        <TableHead className="w-44">
                                            Kolom
                                        </TableHead>
                                        <TableHead>Masalah</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {laporan.rejected.map((masalah, urut) => (
                                        <TableRow
                                            key={`${masalah.line}-${masalah.field}-${urut}`}
                                        >
                                            <TableCell>
                                                {masalah.line}
                                            </TableCell>
                                            <TableCell>
                                                {masalah.field ?? '—'}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {masalah.reason}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}

                    {bersih && (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">
                                {laporan.receipts.length} draf dari{' '}
                                {laporan.rows} baris siap dibuat.
                            </p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tanggal perolehan</TableHead>
                                        <TableHead>Lokasi</TableHead>
                                        <TableHead>Baris berkas</TableHead>
                                        <TableHead className="text-right">
                                            Aset
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Nilai
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Akumulasi
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {laporan.receipts.map((draf, urut) => (
                                        <TableRow key={urut}>
                                            <TableCell>
                                                {tanggalTampil(draf.tanggal)}
                                            </TableCell>
                                            <TableCell>
                                                {draf.lokasi ?? 'Tanpa lokasi'}
                                            </TableCell>
                                            <TableCell>
                                                {(draf.lines ?? []).join(', ')}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {draf.jumlah_aset}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {rupiah(draf.nilai)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {rupiah(draf.akumulasi)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>
                <SheetFooter className="border-t px-6 py-4">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!file || !unit || sibuk}
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
