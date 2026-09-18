import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';

/**
 * Halaman module pertama yang berjalan di dalam build shell, bukan di dalam iframe.
 *
 * Tiga hal yang disengaja di sini, dan ketiganya adalah bentuk yang akan disalin module
 * sungguhan saat dipindahkan:
 *
 * 1. **Ia halaman Inertia biasa.** Props datang dari controller module lewat
 *    `Inertia::render`, bukan dari `fetch` sesudah halaman tampil. Tidak ada token yang
 *    perlu ditunggu dan tidak ada permintaan kedua ke jaringan sebelum ada yang terlihat.
 * 2. **Ia tidak mengimpor apa pun milik shell.** Yang boleh disebut hanya `@apperp/ui` dan
 *    React. Sebuah impor `@/...` akan berhasil dibangun — folder ini ikut build yang sama —
 *    dan justru itu bahayanya: module-nya akan pecah saat dicabut dari repo lain.
 * 3. **Tidak ada `iframe`.** Itu kriteria selesai F2-11 dan dijaga test.
 */

type Barang = {
    id: string;
    kode: string;
    nama: string;
};

export default function Daftar({ barang }: { barang: Barang[] }) {
    return (
        <div className="space-y-4 p-6" data-halaman-module="contoh-a::Daftar">
            <div>
                <h1 className="text-lg font-semibold">Daftar barang</h1>
                <p className="text-muted-foreground text-sm">
                    Isi tabel <code>contoh_a_m_barang</code> untuk tenant yang
                    sedang aktif.
                </p>
            </div>

            {barang.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Belum ada barang pada tenant ini.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-40">Kode</TableHead>
                            <TableHead>Nama</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {barang.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="font-mono">
                                    {item.kode}
                                </TableCell>
                                <TableCell>{item.nama}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </div>
    );
}
