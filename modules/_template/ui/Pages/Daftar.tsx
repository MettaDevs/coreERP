import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';

/**
 * Halaman module: sebuah halaman Inertia biasa yang ikut build shell Core.
 *
 * Tiga hal yang mengikat, dan ketiganya sudah benar di berkas ini:
 *
 * 1. **Props datang dari controller module lewat `Inertia::render`**, bukan dari `fetch`
 *    sesudah halaman tampil. Tidak ada token yang perlu ditunggu dan tidak ada permintaan
 *    kedua ke jaringan sebelum ada yang terlihat.
 * 2. **Tidak ada satu pun impor milik shell.** Yang boleh disebut hanya `@apperp/ui`, React,
 *    `@inertiajs/react`, dan berkas module sendiri. Sebuah impor `@/...` akan berhasil
 *    dibangun — folder ini ikut build yang sama — dan justru itu bahayanya: module-nya pecah
 *    begitu dipasang di runtime yang shell-nya berbeda.
 * 3. **Tidak ada `iframe`.**
 *
 * Tidak ada satu pun penanda cetakan di dalam JSX di bawah, dan itu disengaja. Berkas `ui/`
 * diperiksa Prettier, yang membungkus baris menurut panjangnya: sebuah penanda di dalam
 * atribut atau teks layar membuat panjang baris berubah begitu diganti, sehingga halaman yang
 * dihasilkan gagal `npm run format:check` untuk sebagian nama module dan lolos untuk sebagian
 * yang lain. Penanda karena itu hanya hidup di komentar, yang tidak pernah dibungkus ulang.
 *
 * Teks di layar memakai bahasa sehari-hari. Istilah internal — entitlement, placement,
 * tenant_id — tidak pernah ditampilkan kepada pengguna bisnis.
 */

type Contoh = {
    id: string;
    kode: string;
    nama: string;
};

export default function Daftar({ contoh }: { contoh: Contoh[] }) {
    return (
        <div className="space-y-4 p-6">
            <div>
                <h1 className="text-lg font-semibold">Daftar contoh</h1>
                <p className="text-muted-foreground text-sm">
                    Ganti judul, kolom, dan isi halaman ini dengan layar module
                    yang sebenarnya.
                </p>
            </div>

            {contoh.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Belum ada data yang bisa ditampilkan.
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
                        {contoh.map((item) => (
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
