import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import Kerangka from '@/components/kerangka';
import {
    LencanaJenis,
    LencanaStatus,
    LencanaStatusModul,
} from '@/components/lencana';
import { namaHasil, namaOperasi, sebut } from '@/lib/tampilan';

type Lingkungan = {
    id: string;
    nama: string;
    slug: string;
    jenis: string;
    status: string;
    keluar: boolean;
    database: string;
    databaseSendiri: boolean;
    berakhir: string | null;
    tenant: string;
    dibuat: string | null;
};

type Operasi = {
    id: string;
    operasi: string;
    status: string;
    langkah: string | null;
    alasan: string | null;
    mulai: string;
    selesai: string | null;
    oleh: string;
};

type Modul = {
    id: string;
    nama: string;
    versi: string;
    status: 'installed' | 'disabled' | 'uninstalled';
    disemai: boolean;
};

function Baris({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap justify-between gap-4 border-b py-2.5 last:border-b-0">
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="text-sm font-medium">{children}</dd>
        </div>
    );
}

export default function Rincian({
    lingkungan,
    riwayat,
    modul,
    bisaDisiapkan,
}: {
    lingkungan: Lingkungan;
    riwayat: Operasi[];
    modul: Modul[];
    bisaDisiapkan: boolean;
}) {
    const terakhir = riwayat[0];
    const [berjalan, setBerjalan] = useState(false);
    const ulang = lingkungan.status === 'degraded';

    // Penyiapan gagal dipulangkan sebagai galat validasi bernama `siapkan`, bukan sebagai prop
    // tersendiri. Karena `router.post` dipakai di sini alih-alih `useForm`, tidak ada objek
    // formulir yang menampungnya — ia dibaca langsung dari props halaman.
    const galatSiapkan = usePage().props.errors.siapkan;

    // Spanduknya muncul juga ketika penyiapan tidak diizinkan, asalkan databasenya memang belum
    // ada. Layar yang diam pada keadaan itu memaksa operator menebak apakah ia sedang melihat
    // lingkungan yang belum siap atau lingkungan yang sudah siap tetapi kosong.
    const tampilkanSpanduk = bisaDisiapkan || !lingkungan.databaseSendiri;
    const kalimat = ulang
        ? 'Penyiapan terakhirnya berhenti di tengah jalan. Menjalankannya lagi aman: ia melanjutkan langkah yang belum selesai, bukan memulai dari nol.'
        : lingkungan.databaseSendiri
          ? 'Databasenya sudah ada, tetapi penyiapannya belum dinyatakan selesai. Sampai itu terjadi, lingkungan ini belum dapat dirutekan.'
          : 'Lingkungan ini baru tercatat di registry. Ia belum punya database sendiri, jadi belum ada yang dapat memasukinya — hanya lingkungan berstatus Aktif yang dapat dirutekan.';

    function siapkan() {
        router.post(
            `/lingkungan/${lingkungan.id}/siapkan`,
            {},
            {
                preserveScroll: true,
                onStart: () => setBerjalan(true),
                onFinish: () => setBerjalan(false),
            },
        );
    }

    return (
        <Kerangka
            judul={lingkungan.nama}
            keterangan={`Milik ${lingkungan.tenant}`}
        >
            <Head title={lingkungan.nama} />

            {/*
                Kalimatnya panjang — ia menyebut alamat dan sebab — jadi kotaknya dibiarkan tumbuh
                ke bawah. Pesan kegagalan yang dipotong satu baris justru menyembunyikan bagian yang
                menjelaskan apa yang harus dilakukan berikutnya.
            */}
            {galatSiapkan && (
                <div
                    role="alert"
                    className="border-destructive/40 text-destructive rounded-md border bg-red-50 px-4 py-3 text-sm break-words whitespace-pre-line dark:bg-red-950/40 dark:text-red-200"
                >
                    {galatSiapkan}
                </div>
            )}

            {tampilkanSpanduk && (
                <section className="space-y-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
                    <p>{kalimat}</p>

                    {bisaDisiapkan && (
                        <div className="flex flex-wrap items-center gap-3">
                            {/*
                                Dulu di sini hanya ada teks `php artisan environment:siapkan`,
                                karena jalur autentikasi dari konsol ke Core belum ada. Jalur itu
                                sekarang ada dan sudah dipakai pembuatan pelanggan, jadi alasannya
                                habis.

                                Tombolnya berubah selama penyiapan berjalan — mati, berganti kata,
                                dan berputar — bukan demi hiasan. Penyiapan menjalankan migration
                                setiap module dan sanggup berjalan puluhan detik; tombol yang diam
                                selama itu akan ditekan lagi, dan tekanan kedua tiba saat yang
                                pertama belum selesai.
                            */}
                            <Button
                                type="button"
                                onClick={siapkan}
                                disabled={berjalan}
                            >
                                {berjalan && <Spinner />}
                                {berjalan
                                    ? 'Sedang menyiapkan…'
                                    : ulang
                                      ? 'Coba siapkan lagi'
                                      : 'Siapkan'}
                            </Button>
                            {berjalan && (
                                <p className="text-xs">
                                    Migration setiap module dijalankan satu per
                                    satu; ini dapat memakan puluhan detik.
                                    Biarkan halaman ini terbuka.
                                </p>
                            )}
                        </div>
                    )}
                </section>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <section className="bg-background rounded-lg border p-5">
                    <h2 className="mb-2 text-sm font-semibold">Keterangan</h2>
                    <dl>
                        <Baris label="Jenis">
                            <LencanaJenis jenis={lingkungan.jenis} />
                        </Baris>
                        <Baris label="Status">
                            <LencanaStatus status={lingkungan.status} />
                        </Baris>
                        <Baris label="Slug">
                            <span className="font-mono text-xs">
                                {lingkungan.slug}
                            </span>
                        </Baris>
                        {/*
                            ID ditampilkan apa adanya, bukan disembunyikan. Ia yang dipakai operator
                            untuk mencocokkan layar ini dengan log dan SigNoz, dan tanpa itu setiap
                            penelusuran dimulai dari menebak.
                        */}
                        <Baris label="ID">
                            <span className="font-mono text-xs">
                                {lingkungan.id}
                            </span>
                        </Baris>
                        <Baris label="Database">
                            <span className="font-mono text-xs">
                                {lingkungan.database}
                            </span>
                        </Baris>
                        <Baris label="Database sendiri">
                            {lingkungan.databaseSendiri ? 'Ya' : 'Tidak'}
                        </Baris>
                        <Baris label="Kirim keluar">
                            {lingkungan.keluar ? 'Ya' : 'Tidak'}
                        </Baris>
                        <Baris label="Berakhir">
                            {lingkungan.berakhir ?? 'Tidak berakhir'}
                        </Baris>
                        <Baris label="Dibuat">{lingkungan.dibuat ?? '—'}</Baris>
                    </dl>
                </section>

                <section className="bg-background rounded-lg border p-5">
                    <h2 className="mb-2 text-sm font-semibold">
                        Operasi terakhir
                    </h2>
                    {terakhir ? (
                        <dl>
                            <Baris label="Jenis">
                                {sebut(namaOperasi, terakhir.operasi)}
                            </Baris>
                            <Baris label="Hasil">
                                {sebut(namaHasil, terakhir.status)}
                            </Baris>
                            <Baris label="Langkah">
                                {terakhir.langkah ?? '—'}
                            </Baris>
                            <Baris label="Waktu mulai">{terakhir.mulai}</Baris>
                            <Baris label="Dimulai oleh">{terakhir.oleh}</Baris>
                            {terakhir.alasan && (
                                <Baris label="Alasan gagal">
                                    <span className="text-destructive">
                                        {terakhir.alasan}
                                    </span>
                                </Baris>
                            )}
                        </dl>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Belum ada operasi yang tercatat.
                        </p>
                    )}
                </section>
            </div>

            <section className="space-y-3">
                <h2 className="text-sm font-semibold">Module terpasang</h2>
                {modul.length === 0 ? (
                    <div className="text-muted-foreground bg-background rounded-lg border border-dashed px-4 py-8 text-center text-sm">
                        {lingkungan.databaseSendiri
                            ? 'Lingkungan ini sudah punya database sendiri, tetapi belum satu pun module dipasang di dalamnya. Yang ada di sana baru tabel milik Core; pemakainya akan masuk ke tempat kerja yang kosong.'
                            : 'Belum ada database yang dapat memuat module. Siapkan databasenya lebih dulu.'}
                    </div>
                ) : (
                    <div className="bg-background overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Versi</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Data awal</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {modul.map((satu) => (
                                    <TableRow key={satu.id}>
                                        <TableCell className="font-mono text-xs">
                                            {satu.id}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {satu.nama}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {satu.versi}
                                        </TableCell>
                                        <TableCell>
                                            <LencanaStatusModul
                                                status={satu.status}
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {satu.disemai
                                                ? 'Sudah terisi'
                                                : 'Belum'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                <p className="text-muted-foreground text-xs">
                    Yang terdaftar di sini adalah module di dalam database
                    lingkungan ini, bukan yang dibeli tenantnya. Keduanya dapat
                    berbeda: pembelian tercatat pada tenant, pemasangan terjadi
                    pada tiap lingkungan — dan lingkungan yang baru lahir belum
                    memuat satu pun dari yang sudah dibeli.
                </p>
            </section>

            <section className="space-y-3">
                <h2 className="text-sm font-semibold">Riwayat lengkap</h2>
                <div className="bg-background overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Operasi</TableHead>
                                <TableHead>Hasil</TableHead>
                                <TableHead>Langkah</TableHead>
                                <TableHead>Mulai</TableHead>
                                <TableHead>Selesai</TableHead>
                                <TableHead>Oleh</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {riwayat.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground py-8 text-center text-sm"
                                    >
                                        Belum ada riwayat.
                                    </TableCell>
                                </TableRow>
                            )}
                            {riwayat.map((operasi) => (
                                <TableRow key={operasi.id}>
                                    <TableCell>
                                        {sebut(namaOperasi, operasi.operasi)}
                                    </TableCell>
                                    <TableCell>
                                        {sebut(namaHasil, operasi.status)}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {operasi.langkah ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {operasi.mulai}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {operasi.selesai ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {operasi.oleh}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </section>
        </Kerangka>
    );
}
