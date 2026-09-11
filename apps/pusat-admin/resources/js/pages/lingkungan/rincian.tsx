import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Kerangka from '@/components/kerangka';
import { LencanaJenis, LencanaStatus } from '@/components/lencana';
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
}: {
    lingkungan: Lingkungan;
    riwayat: Operasi[];
}) {
    const terakhir = riwayat[0];

    return (
        <Kerangka
            judul={lingkungan.nama}
            keterangan={`Milik ${lingkungan.tenant}`}
        >
            <Head title={lingkungan.nama} />

            {!lingkungan.databaseSendiri && (
                <div className="space-y-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p>
                        Lingkungan ini baru tercatat di registry. Ia belum punya
                        database sendiri, jadi belum ada yang dapat memasukinya
                        — hanya lingkungan berstatus Aktif yang dapat dirutekan.
                    </p>
                    {/*
                        Perintahnya ditampilkan, bukan disembunyikan di balik tombol, dan itu
                        bukan kemalasan. Panggilan dari konsol ini ke Core menyeberangi batas
                        control plane ke application plane, dan jalur autentikasi untuk arah
                        itu belum ada — `internal-app` milik Core terikat pada satu app dan
                        satu tenant, jadi ia tidak cocok. Tombol yang memanggilnya lebih dulu
                        berarti memutuskan bentuk kredensialnya sambil lalu.
                    */}
                    <p>Siapkan databasenya dari Core:</p>
                    <code className="block overflow-x-auto rounded bg-amber-100 px-3 py-2 font-mono text-xs">
                        php artisan environment:siapkan {lingkungan.id}
                    </code>
                </div>
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
