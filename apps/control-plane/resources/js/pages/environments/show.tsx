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
import {
    InstallStateBadge,
    KindBadge,
    ModuleStatusBadge,
    StatusBadge,
} from '@/components/badges';
import Shell from '@/components/shell';
import { labelFor, operationLabels, resultLabels } from '@/lib/display';
import ClientServerPanel from '@/pages/environments/client-server-panel';
import type {
    InstallCommand,
    ServerClient,
} from '@/pages/environments/client-server-panel';

type Environment = {
    id: string;
    name: string;
    slug: string;
    kind: string;
    status: string;
    hosting: string;
    outboundAllowed: boolean;
    database: string;
    ownDatabase: boolean;
    url: string | null;
    expiresAt: string | null;
    tenant: string;
    createdAt: string | null;
};

type Operation = {
    id: string;
    operation: string;
    status: string;
    step: string | null;
    reason: string | null;
    startedAt: string;
    finishedAt: string | null;
    requestedBy: string;
};

type Module = {
    id: string;
    name: string;
    version: string;
    status: 'installed' | 'disabled' | 'uninstalled';
    seeded: boolean;
};

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap justify-between gap-4 border-b py-2.5 last:border-b-0">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium">{children}</dd>
        </div>
    );
}

export default function Show({
    environment,
    history,
    modules,
    canProvision,
    serverClient,
    installCommand,
}: {
    environment: Environment;
    history: Operation[];
    modules: Module[];
    canProvision: boolean;
    serverClient: ServerClient | null;
    installCommand: InstallCommand | null;
}) {
    const last = history[0];
    const [running, setRunning] = useState(false);
    const retry = environment.status === 'degraded';
    // Produksi di server klien tidak punya database di server kita. Spanduk penyiapan dan daftar
    // module membaca server kita, jadi keduanya digantikan panel "Server klien".
    const onClientServer = environment.hosting === 'client_server';

    // Penyiapan gagal dipulangkan sebagai galat validasi bernama `provision`, bukan sebagai prop
    // tersendiri. Karena `router.post` dipakai di sini alih-alih `useForm`, tidak ada objek
    // formulir yang menampungnya — ia dibaca langsung dari props halaman.
    const provisionError = usePage().props.errors.provision;

    // Spanduknya muncul juga ketika penyiapan tidak diizinkan, asalkan databasenya memang belum
    // ada. Layar yang diam pada keadaan itu memaksa operator menebak apakah ia sedang melihat
    // lingkungan yang belum siap atau lingkungan yang sudah siap tetapi kosong.
    const showBanner =
        !onClientServer && (canProvision || !environment.ownDatabase);
    const sentence = retry
        ? 'Penyiapan terakhirnya berhenti di tengah jalan. Menjalankannya lagi aman: ia melanjutkan langkah yang belum selesai, bukan memulai dari nol.'
        : environment.ownDatabase
          ? 'Databasenya sudah ada, tetapi penyiapannya belum dinyatakan selesai. Sampai itu terjadi, lingkungan ini belum dapat dirutekan.'
          : 'Lingkungan ini baru tercatat di registry. Ia belum punya database sendiri, jadi belum ada yang dapat memasukinya — hanya lingkungan berstatus Aktif yang dapat dirutekan.';

    function provision() {
        router.post(
            `/lingkungan/${environment.id}/siapkan`,
            {},
            {
                preserveScroll: true,
                onStart: () => setRunning(true),
                onFinish: () => setRunning(false),
            },
        );
    }

    return (
        <Shell
            title={environment.name}
            description={`Milik ${environment.tenant}`}
        >
            <Head title={environment.name} />

            {/*
                Kalimatnya panjang — ia menyebut alamat dan sebab — jadi kotaknya dibiarkan tumbuh
                ke bawah. Pesan kegagalan yang dipotong satu baris justru menyembunyikan bagian yang
                menjelaskan apa yang harus dilakukan berikutnya.
            */}
            {provisionError && (
                <div
                    role="alert"
                    className="rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm break-words whitespace-pre-line text-destructive dark:bg-red-950/40 dark:text-red-200"
                >
                    {provisionError}
                </div>
            )}

            {showBanner && (
                <section className="space-y-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
                    <p>{sentence}</p>

                    {canProvision && (
                        <div className="flex flex-wrap items-center gap-3">
                            {/*
                                Dulu di sini hanya ada teks `php artisan environment:provision`,
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
                                onClick={provision}
                                disabled={running}
                            >
                                {running && <Spinner />}
                                {running
                                    ? 'Sedang menyiapkan…'
                                    : retry
                                      ? 'Coba siapkan lagi'
                                      : 'Siapkan'}
                            </Button>
                            {running && (
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

            {serverClient && (
                <ClientServerPanel
                    environmentId={environment.id}
                    serverClient={serverClient}
                    installCommand={installCommand}
                />
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-lg border bg-background p-5">
                    <h2 className="mb-2 text-sm font-semibold">Keterangan</h2>
                    <dl>
                        {/*
                            Alamatnya didahulukan, di atas jenis dan status.

                            Itu satu-satunya hal di halaman ini yang perlu disalin dan dikirim ke
                            pelanggan. Sisanya keterangan untuk operator sendiri — dan sebelum baris
                            ini ada, tidak ada satu pun tempat di konsol yang menyebutkan ke mana
                            pelanggan harus diarahkan.
                        */}
                        {environment.url && (
                            <Row label="Alamat">
                                <a
                                    href={environment.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-mono text-xs break-all underline underline-offset-4"
                                >
                                    {environment.url}
                                </a>
                                {onClientServer ? (
                                    <span className="ms-2 text-xs text-muted-foreground">
                                        — terbuka setelah record DNS dan
                                        pemasangan di server klien selesai
                                    </span>
                                ) : (
                                    environment.status !== 'active' && (
                                        <span className="ms-2 text-xs text-muted-foreground">
                                            — belum dapat dibuka sampai
                                            statusnya Aktif
                                        </span>
                                    )
                                )}
                            </Row>
                        )}
                        <Row label="Jenis">
                            <KindBadge kind={environment.kind} />
                        </Row>
                        <Row label="Berjalan di">
                            {onClientServer ? (
                                <span className="text-end">
                                    Server klien
                                    {serverClient?.site?.serverAddress && (
                                        <span className="block font-mono text-xs font-normal text-muted-foreground">
                                            {serverClient.site.serverAddress}
                                        </span>
                                    )}
                                </span>
                            ) : (
                                'Server kita'
                            )}
                        </Row>
                        {/*
                            Produksi di server klien tidak pernah disiapkan di server kita, jadi status
                            registry-nya menetap "Sedang disiapkan". Keadaan yang berarti bagi operator
                            adalah keadaan pemasangannya — kata yang sama dengan panel di atas.
                        */}
                        <Row label="Status">
                            {serverClient ? (
                                <InstallStateBadge
                                    state={serverClient.progress.state}
                                />
                            ) : (
                                <StatusBadge status={environment.status} />
                            )}
                        </Row>
                        <Row label="Slug">
                            <span className="font-mono text-xs">
                                {environment.slug}
                            </span>
                        </Row>
                        {/*
                            ID ditampilkan apa adanya, bukan disembunyikan. Ia yang dipakai operator
                            untuk mencocokkan layar ini dengan log dan SigNoz, dan tanpa itu setiap
                            penelusuran dimulai dari menebak.
                        */}
                        <Row label="ID">
                            <span className="font-mono text-xs">
                                {environment.id}
                            </span>
                        </Row>
                        {/*
                            Nama database yang dikirim server untuk lingkungan tanpa `database_name`
                            adalah database pooled di server kita. Bagi produksi di server klien nama itu
                            bukan tempat datanya, dan menampilkannya mengundang orang mencarinya di sana.
                        */}
                        {onClientServer ? (
                            <Row label="Database">Di server klien</Row>
                        ) : (
                            <>
                                <Row label="Database">
                                    <span className="font-mono text-xs">
                                        {environment.database}
                                    </span>
                                </Row>
                                <Row label="Database sendiri">
                                    {environment.ownDatabase ? 'Ya' : 'Tidak'}
                                </Row>
                            </>
                        )}
                        <Row label="Kirim keluar">
                            {environment.outboundAllowed ? 'Ya' : 'Tidak'}
                        </Row>
                        <Row label="Berakhir">
                            {environment.expiresAt ?? 'Tidak berakhir'}
                        </Row>
                        <Row label="Dibuat">{environment.createdAt ?? '—'}</Row>
                    </dl>
                </section>

                <section className="rounded-lg border bg-background p-5">
                    <h2 className="mb-2 text-sm font-semibold">
                        Operasi terakhir
                    </h2>
                    {last ? (
                        <dl>
                            <Row label="Jenis">
                                {labelFor(operationLabels, last.operation)}
                            </Row>
                            <Row label="Hasil">
                                {labelFor(resultLabels, last.status)}
                            </Row>
                            <Row label="Langkah">{last.step ?? '—'}</Row>
                            <Row label="Waktu mulai">{last.startedAt}</Row>
                            <Row label="Dimulai oleh">{last.requestedBy}</Row>
                            {last.reason && (
                                <Row label="Alasan gagal">
                                    <span className="text-destructive">
                                        {last.reason}
                                    </span>
                                </Row>
                            )}
                        </dl>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Belum ada operasi yang tercatat.
                        </p>
                    )}
                </section>
            </div>

            {!onClientServer && (
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">Module terpasang</h2>
                    {modules.length === 0 ? (
                        <div className="rounded-lg border border-dashed bg-background px-4 py-8 text-center text-sm text-muted-foreground">
                            {environment.ownDatabase
                                ? 'Lingkungan ini sudah punya database sendiri, tetapi belum satu pun module dipasang di dalamnya. Yang ada di sana baru tabel milik Core; pemakainya akan masuk ke tempat kerja yang kosong.'
                                : 'Belum ada database yang dapat memuat module. Siapkan databasenya lebih dulu.'}
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border bg-background">
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
                                    {modules.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="font-mono text-xs">
                                                {item.id}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {item.name}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {item.version}
                                            </TableCell>
                                            <TableCell>
                                                <ModuleStatusBadge
                                                    status={item.status}
                                                />
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {item.seeded
                                                    ? 'Sudah terisi'
                                                    : 'Belum'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Yang terdaftar di sini adalah module di dalam database
                        lingkungan ini, bukan yang dibeli tenantnya. Keduanya
                        dapat berbeda: pembelian tercatat pada tenant,
                        pemasangan terjadi pada tiap lingkungan — dan lingkungan
                        yang baru lahir belum memuat satu pun dari yang sudah
                        dibeli.
                    </p>
                </section>
            )}

            <section className="space-y-3">
                <h2 className="text-sm font-semibold">Riwayat lengkap</h2>
                <div className="overflow-x-auto rounded-lg border bg-background">
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
                            {history.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-8 text-center text-sm text-muted-foreground"
                                    >
                                        Belum ada riwayat.
                                    </TableCell>
                                </TableRow>
                            )}
                            {history.map((operation) => (
                                <TableRow key={operation.id}>
                                    <TableCell>
                                        {labelFor(
                                            operationLabels,
                                            operation.operation,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {labelFor(
                                            resultLabels,
                                            operation.status,
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {operation.step ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {operation.startedAt}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {operation.finishedAt ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {operation.requestedBy}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </section>
        </Shell>
    );
}
