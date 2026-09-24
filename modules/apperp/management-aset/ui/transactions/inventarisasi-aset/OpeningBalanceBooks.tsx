import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { api } from '../../api';
import type { BarisPenerimaan, BukuGroup, SaldoAwalBuku } from './penerimaan';

/**
 * Buku setiap group yang dipakai baris saldo awal, dibaca sekali per group.
 *
 * Buku berasal dari matriks group x buku, jadi layar menanyakannya ke penerimaan — bukan ke master
 * group aset — supaya yang mencatat saldo awal tidak perlu izin mengubah master.
 */
export function useBukuGroup(groupIds: string[], aktif: boolean) {
    const [buku, setBuku] = useState<Record<string, BukuGroup[]>>({});
    const kunci = [...new Set(groupIds.filter(Boolean))].sort().join(',');

    useEffect(() => {
        if (!aktif || kunci === '') {
            return;
        }

        let dilepas = false;

        for (const groupId of kunci.split(',')) {
            api<{ data: BukuGroup[] }>(
                `/penerimaan-aset/buku?group_aset_id=${encodeURIComponent(groupId)}`,
            )
                .then(
                    (hasil) =>
                        !dilepas &&
                        setBuku((sebelumnya) => ({
                            ...sebelumnya,
                            [groupId]: hasil.data,
                        })),
                )
                .catch(() => undefined);
        }

        return () => {
            dilepas = true;
        };
    }, [aktif, kunci]);

    return buku;
}

/**
 * Angka saldo awal per buku (feed posting finance, TODO 10.1.1, K-28).
 *
 * Buku yang di-post ke finance memakai angka barisnya. Buku lain — lazimnya buku fiskal — ikut angka
 * itu sampai diisi tersendiri; begitu diubah, angkanya tersimpan untuk buku itu saja, dan "Samakan"
 * mengembalikannya ikut angka baris.
 */
export function OpeningBalanceBooks({
    details,
    books,
    readOnly,
    pesan,
    onChange,
}: {
    details: BarisPenerimaan[];
    books: Record<string, BukuGroup[] | undefined>;
    readOnly: boolean;
    pesan: (field: string) => string | undefined;
    onChange: (index: number, saldoAwalBuku: SaldoAwalBuku[]) => void;
}) {
    const berisi = details
        .map((baris, index) => ({ baris, index }))
        .filter(({ baris }) => baris.group_aset_id);

    if (berisi.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Pilih group aset di baris barang lebih dulu.
            </p>
        );
    }

    return (
        <div className="space-y-5">
            {berisi.map(({ baris, index }) => {
                const daftar = books[baris.group_aset_id];

                return (
                    <div
                        key={baris.id ?? `saldo-awal-${index}`}
                        className="space-y-2"
                    >
                        <p className="text-sm font-medium">
                            Baris {index + 1}
                            {baris.nama ? ` · ${baris.nama}` : ''}
                        </p>
                        {daftar === undefined ? (
                            <p className="text-muted-foreground text-sm">
                                Memuat buku…
                            </p>
                        ) : daftar.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Group ini belum punya buku di matriks group x
                                buku.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-56">
                                                Buku
                                            </TableHead>
                                            <TableHead className="min-w-40 text-right">
                                                Akumulasi / unit
                                            </TableHead>
                                            <TableHead className="min-w-36 text-right">
                                                Periode berjalan
                                            </TableHead>
                                            {!readOnly && (
                                                <TableHead className="w-28" />
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {daftar.map((buku) => (
                                            <BarisBuku
                                                key={buku.buku_id}
                                                baris={baris}
                                                index={index}
                                                buku={buku}
                                                readOnly={readOnly}
                                                pesan={pesan}
                                                onChange={onChange}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function BarisBuku({
    baris,
    index,
    buku,
    readOnly,
    pesan,
    onChange,
}: {
    baris: BarisPenerimaan;
    index: number;
    buku: BukuGroup;
    readOnly: boolean;
    pesan: (field: string) => string | undefined;
    onChange: (index: number, saldoAwalBuku: SaldoAwalBuku[]) => void;
}) {
    const isian = baris.saldo_awal_buku ?? [];
    const posisi = isian.findIndex((isi) => isi.buku_id === buku.buku_id);
    const isi = posisi >= 0 ? isian[posisi] : null;
    const awalan =
        posisi >= 0
            ? `details.${index}.saldo_awal_buku.${posisi}.`
            : `details.${index}.`;
    // Angka baris berlaku untuk buku yang di-post dan buku yang tidak diisi tersendiri, jadi
    // galatnya ditampilkan di buku yang di-post, atau di buku yang disebut pesannya.
    const galatBaris = [
        pesan(`details.${index}.akumulasi_per_unit`),
        pesan(`details.${index}.periode_berjalan`),
    ].filter((teks): teks is string => Boolean(teks));
    const galat =
        posisi >= 0
            ? (pesan(`${awalan}buku_id`) ??
              pesan(`${awalan}akumulasi_per_unit`) ??
              pesan(`${awalan}periode_berjalan`))
            : buku.di_post
              ? galatBaris[0]
              : galatBaris.find((teks) => teks.includes(`buku ${buku.kode} (`));

    const ubah = (patch: Partial<SaldoAwalBuku>) => {
        const berikut: SaldoAwalBuku = {
            buku_id: buku.buku_id,
            akumulasi_per_unit:
                isi?.akumulasi_per_unit ?? baris.akumulasi_per_unit,
            periode_berjalan: isi?.periode_berjalan ?? baris.periode_berjalan,
            ...patch,
        };
        onChange(
            index,
            posisi >= 0
                ? isian.map((lama, urut) => (urut === posisi ? berikut : lama))
                : [...isian, berikut],
        );
    };

    const keadaan = buku.di_post
        ? 'Di-post ke finance, memakai angka baris.'
        : isi
          ? 'Diisi tersendiri.'
          : 'Ikut angka baris.';

    return (
        <TableRow>
            <TableCell>
                <div className="font-medium">
                    {buku.kode} — {buku.nama}
                </div>
                <div className="text-muted-foreground text-xs">
                    {keadaan}
                    {buku.masa_manfaat !== null
                        ? ` Masa manfaat ${buku.masa_manfaat} periode.`
                        : ''}
                </div>
                {galat && (
                    <div className="text-destructive text-xs">{galat}</div>
                )}
            </TableCell>
            <TableCell>
                <Input
                    aria-label={`Akumulasi per unit buku ${buku.kode}`}
                    type="number"
                    min={0}
                    readOnly={readOnly || buku.di_post}
                    value={String(
                        isi?.akumulasi_per_unit ??
                            baris.akumulasi_per_unit ??
                            '',
                    )}
                    onChange={(event) =>
                        ubah({ akumulasi_per_unit: event.target.value })
                    }
                />
            </TableCell>
            <TableCell>
                <Input
                    aria-label={`Periode berjalan buku ${buku.kode}`}
                    type="number"
                    min={0}
                    step={1}
                    readOnly={readOnly || buku.di_post}
                    value={String(
                        isi?.periode_berjalan ?? baris.periode_berjalan ?? '',
                    )}
                    onChange={(event) =>
                        ubah({ periode_berjalan: event.target.value })
                    }
                />
            </TableCell>
            {!readOnly && (
                <TableCell>
                    {isi && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    index,
                                    isian.filter((_, urut) => urut !== posisi),
                                )
                            }
                        >
                            Samakan
                        </Button>
                    )}
                </TableCell>
            )}
        </TableRow>
    );
}
