import { Button } from '@apperp/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, Link } from '@inertiajs/react';
import Kerangka from '@/components/kerangka';
import { LencanaJenis, LencanaStatus } from '@/components/lencana';
import DialogBuat from '@/pages/lingkungan/dialog-buat';

type Baris = {
    id: string;
    nama: string;
    slug: string;
    jenis: string;
    status: string;
    keluar: boolean;
    database: string;
    berakhir: string | null;
    tenant: string;
};

export default function Daftar({
    daftar,
    tenant,
    jenis,
}: {
    daftar: Baris[];
    tenant: { id: string; nama: string }[];
    jenis: string[];
}) {
    return (
        <Kerangka
            judul="Lingkungan"
            keterangan="Setiap tempat kerja yang dikenal sistem, beserta jenis dan tempat datanya berada."
            aksi={<DialogBuat tenant={tenant} jenis={jenis} />}
        >
            <Head title="Lingkungan" />

            <div className="bg-background overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama</TableHead>
                            <TableHead>Pelanggan</TableHead>
                            <TableHead>Jenis</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Database</TableHead>
                            <TableHead>Berakhir</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {daftar.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={7}
                                    className="text-muted-foreground py-10 text-center text-sm"
                                >
                                    Belum ada lingkungan yang tercatat.
                                </TableCell>
                            </TableRow>
                        )}
                        {daftar.map((baris) => (
                            <TableRow key={baris.id}>
                                <TableCell className="font-medium">
                                    {baris.nama}
                                    <span className="text-muted-foreground ms-2 text-xs">
                                        {baris.slug}
                                    </span>
                                </TableCell>
                                <TableCell>{baris.tenant}</TableCell>
                                <TableCell>
                                    <LencanaJenis jenis={baris.jenis} />
                                </TableCell>
                                <TableCell>
                                    <LencanaStatus status={baris.status} />
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {baris.database}
                                </TableCell>
                                <TableCell className="text-muted-foreground text-sm">
                                    {baris.berakhir ?? 'Tidak berakhir'}
                                </TableCell>
                                <TableCell className="text-end">
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={`/lingkungan/${baris.id}`}>
                                            Rincian
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Kerangka>
    );
}
