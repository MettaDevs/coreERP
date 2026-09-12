import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head } from '@inertiajs/react';
import Kerangka from '@/components/kerangka';
import DialogBuat from '@/pages/pelanggan/dialog-buat';
import KartuKredensial from '@/pages/pelanggan/kartu-kredensial';
import type { Kredensial } from '@/pages/pelanggan/kartu-kredensial';

type Baris = {
    id: string;
    nama: string;
    lingkungan: number;
    dibuat: string | null;
};

export default function Daftar({
    daftar,
    app,
    kredensial,
}: {
    daftar: Baris[];
    app: { id: string; nama: string }[];
    kredensial: Kredensial | null;
}) {
    return (
        <Kerangka
            judul="Pelanggan"
            keterangan="Setiap perusahaan yang punya tempatnya sendiri di sistem ini, beserta jumlah lingkungannya."
            aksi={<DialogBuat app={app} />}
        >
            <Head title="Pelanggan" />

            {/*
                Kartunya di atas tabel, bukan di bawahnya. Ia hanya muncul beberapa detik setelah
                seorang pelanggan lahir, dan pada detik-detik itu ia satu-satunya hal di halaman
                ini yang benar-benar mendesak: kata sandinya tidak akan muncul lagi.
            */}
            {kredensial && <KartuKredensial kredensial={kredensial} />}

            <div className="bg-background overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama badan hukum</TableHead>
                            <TableHead>Lingkungan</TableHead>
                            <TableHead>Dibuat</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {daftar.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={3}
                                    className="text-muted-foreground py-10 text-center text-sm"
                                >
                                    Belum ada pelanggan. Sampai ada, layar
                                    Lingkungan tidak punya siapa pun untuk
                                    dibuatkan tempat kerja.
                                </TableCell>
                            </TableRow>
                        )}
                        {daftar.map((baris) => (
                            <TableRow key={baris.id}>
                                <TableCell className="font-medium">
                                    {baris.nama}
                                    <span className="text-muted-foreground ms-2 font-mono text-xs">
                                        {baris.id}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    {baris.lingkungan === 0 ? (
                                        <span className="text-muted-foreground text-sm">
                                            Belum ada
                                        </span>
                                    ) : (
                                        baris.lingkungan
                                    )}
                                </TableCell>
                                <TableCell className="text-muted-foreground text-sm">
                                    {baris.dibuat ?? 'Tidak tercatat'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Kerangka>
    );
}
