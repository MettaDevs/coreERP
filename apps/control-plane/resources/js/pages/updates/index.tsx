import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardAction,
} from '@apperp/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, router, usePage } from '@inertiajs/react';
import { RefreshCw, CircleCheck, CircleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { FleetStateBadge, KindBadge } from '@/components/badges';
import Shell from '@/components/shell';
import { labelFor, operationLabels, resultLabels } from '@/lib/display';

type Operation = {
    kind: string;
    status: string;
    step: string | null;
    reason: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    requestedBy: string | null;
};

type Row = {
    id: string;
    tenant: string;
    name: string;
    slug: string;
    kind: string;
    status: string;
    database: string | null;
    fingerprint: string | null;
    state: string;
    lastOperation: Operation | null;
    queued: boolean;
};

type Counts = {
    current: number;
    behind: number;
    failed: number;
    unknown: number;
};

/**
 * Berapa lama layar ini menunggu sebelum bertanya lagi, dalam milidetik.
 *
 * Ia hanya bertanya selama ada operasi yang berjalan, jadi angkanya tidak perlu hemat — pada
 * hari-hari biasa nilainya tidak terpakai sama sekali. Delapan detik cukup pendek untuk membuat
 * kemajuan terasa hidup dan cukup panjang untuk tidak menghitung ulang sidik seluruh armada
 * sepuluh kali semenit.
 */
const POLL_MS = 8000;

/**
 * Satu tempat untuk melihat apakah seluruh armada sudah memakai skema yang berlaku.
 *
 * Tiga hal yang membentuk tata letaknya, dan ketiganya jawaban atas pertanyaan berbeda:
 *
 * 1. **Hitungan di kepala** menjawab "apakah ada yang perlu saya kerjakan hari ini". Itu satu-satunya
 *    pertanyaan pada hari biasa, dan jawabannya harus terbaca tanpa membaca satu baris tabel pun.
 * 2. **Tombol "Perbarui semua yang tertinggal"** menjawabnya dengan satu tekan. Ia mati ketika tidak
 *    ada yang tertinggal — tombol yang selalu dapat ditekan lalu selalu tidak melakukan apa-apa
 *    mengajari orang bahwa tekanannya tidak berarti.
 * 3. **Tabelnya** menjawab "yang mana, dan kenapa". Kolom terpenting di dalamnya bukan namanya
 *    melainkan operasi terakhir: itu yang menyebut alasan sebuah lingkungan berhenti di tengah.
 */
export default function Index({
    platformFingerprint,
    counts,
    environments,
    unreachable,
}: {
    platformFingerprint: string | null;
    counts: Counts;
    environments: Row[];
    unreachable: string | null;
}) {
    const { wrapRouter: wrapRouterRequest, summary: routerRequestSummary } =
        useInertiaFeedback();

    const [sending, setSending] = useState(false);
    const upgradeError = usePage().props.errors.upgrade;

    const running = environments.filter(
        (row) => row.lastOperation?.status === 'running',
    ).length;
    const queued = environments.filter((row) => row.queued).length;
    const stale = counts.behind + counts.failed;

    /*
     * Bertanya lagi selama pekerjaan menunggu atau sedang berjalan.
     *
     * Antrean tidak dapat memberi tahu layar ini kapan ia selesai — tidak ada saluran dari pekerja
     * ke peramban di sini — jadi yang tersisa adalah bertanya. Yang membuatnya tidak boros:
     * syaratnya keadaan yang berakhir sendiri. Begitu operasi terakhir ditutup, `running` menjadi
     * nol, efek ini membersihkan intervalnya, dan layar kembali diam sampai ada tombol yang
     * ditekan lagi.
     *
     * Sengaja tidak dipasang untuk seluruh halaman: memuat ulang tiap delapan detik tanpa syarat
     * berarti menghitung ulang sidik seluruh armada sepanjang hari demi tabel yang tidak berubah.
     */
    useEffect(() => {
        if (running === 0 && queued === 0) {
            return;
        }

        const timer = setInterval(() => {
            router.reload({
                only: [
                    'platformFingerprint',
                    'counts',
                    'environments',
                    'unreachable',
                ],
            });
        }, POLL_MS);

        return () => clearInterval(timer);
    }, [running, queued]);

    function upgrade(environmentId?: string) {
        router.post(
            environmentId ? `/pembaruan/${environmentId}` : '/pembaruan',
            {},
            wrapRouterRequest({
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
            }),
        );
    }

    return (
        <Shell
            title="Pembaruan"
            description="Lihat lingkungan yang perlu diperbarui dan pantau hasilnya."
        >
            {routerRequestSummary}
            <Head title="Pembaruan" />

            {/*
                Kalimatnya menyebut alamat dan sebab, jadi kotaknya dibiarkan tumbuh ke bawah.
                Pesan kegagalan yang dipotong satu baris justru menyembunyikan bagian yang
                menjelaskan apa yang harus dilakukan berikutnya.
            */}
            {(unreachable ?? upgradeError) && (
                <div
                    role="alert"
                    className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm break-words whitespace-pre-line text-destructive"
                >
                    {unreachable ?? upgradeError}
                </div>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>
                        {unreachable
                            ? 'Status belum dapat diperiksa'
                            : stale > 0
                              ? `${stale} lingkungan perlu diperbarui`
                              : counts.unknown > 0
                                ? 'Ada lingkungan yang belum terbaca'
                                : 'Tidak ada pembaruan tertunda'}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-start gap-3 text-sm">
                        {stale > 0 || unreachable || counts.unknown > 0 ? (
                            <CircleAlert className="mt-0.5 size-5 shrink-0 text-warning" />
                        ) : (
                            <CircleCheck className="mt-0.5 size-5 shrink-0 text-success" />
                        )}
                        <p className="text-muted-foreground">
                            {unreachable
                                ? 'Hubungan ke Core perlu dipulihkan sebelum pembaruan dapat dimulai.'
                                : queued > 0
                                  ? `${queued} pembaruan menunggu pekerja. Status diperbarui otomatis.`
                                  : running > 0
                                    ? `${running} operasi sedang berjalan. Status diperbarui otomatis.`
                                    : counts.failed > 0
                                      ? 'Periksa alasan kegagalan di daftar, lalu coba pembaruan kembali.'
                                      : stale > 0
                                        ? 'Mulai pembaruan untuk lingkungan yang tertinggal atau pilih satu lingkungan di daftar.'
                                        : counts.unknown > 0
                                          ? 'Periksa lingkungan yang belum terbaca sebelum menyimpulkan semua sudah mutakhir.'
                                          : 'Semua lingkungan yang terbaca telah memakai skema saat ini.'}
                        </p>
                    </div>
                    <section
                        aria-label="Ringkasan pembaruan"
                        className="grid grid-cols-2 gap-3 sm:grid-cols-4"
                    >
                        <Count label="Mutakhir" value={counts.current} />
                        <Count
                            label="Tertinggal"
                            value={counts.behind}
                            tone="amber"
                        />
                        <Count
                            label="Bermasalah"
                            value={counts.failed}
                            tone="red"
                        />
                        <Count label="Belum terbaca" value={counts.unknown} />
                    </section>
                    <details className="text-xs text-muted-foreground">
                        <summary className="cursor-pointer py-1">
                            Lihat sidik skema acuan
                        </summary>
                        <p className="mt-2 font-mono break-all">
                            {platformFingerprint ?? 'Belum terbaca'}
                        </p>
                    </details>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Lingkungan</CardTitle>
                    <CardAction>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => upgrade()}
                            disabled={
                                sending || stale === 0 || unreachable !== null
                            }
                        >
                            <RefreshCw className="size-4" />
                            {sending
                                ? 'Mengirim...'
                                : `Perbarui yang tertinggal (${stale})`}
                        </Button>
                    </CardAction>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="overflow-x-auto rounded-lg border bg-background">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Lingkungan</TableHead>
                                    <TableHead>Keadaan</TableHead>
                                    <TableHead>Operasi terakhir</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {environments.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            {unreachable
                                                ? 'Keadaan armada tidak dapat dibaca karena Core tidak menjawab.'
                                                : 'Belum ada lingkungan yang tercatat.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {environments.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="min-w-44">
                                            <p className="font-medium">
                                                {row.name}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {row.tenant} / {row.slug}
                                            </p>
                                            <div className="mt-2">
                                                <KindBadge kind={row.kind} />
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <FleetStateBadge
                                                state={row.state}
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            <LastOperation
                                                operation={row.lastOperation}
                                            />
                                            <details className="mt-2 max-w-xs text-xs text-muted-foreground">
                                                <summary className="cursor-pointer">
                                                    Sidik skema
                                                </summary>
                                                <div className="mt-1">
                                                    <Fingerprint
                                                        value={row.fingerprint}
                                                        platform={
                                                            platformFingerprint
                                                        }
                                                    />
                                                </div>
                                            </details>
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <RowAction
                                                row={row}
                                                disabled={
                                                    sending ||
                                                    unreachable !== null
                                                }
                                                onUpgrade={() =>
                                                    upgrade(row.id)
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </Shell>
    );
}

/**
 * Sidik skema sebuah baris — dan pada hari biasa ia sengaja tidak ditampilkan.
 *
 * Baris yang mutakhir menurut definisinya membawa sidik yang **sama persis** dengan yang sudah
 * tertulis di kepala halaman: nama berkas migration sepanjang empat puluh lima karakter, diulang
 * pada setiap baris, tidak menambah satu pun keterangan. Yang ditambahkannya hanya lebar — terukur
 * 231 piksel luberan pada jendela 965 piksel, dan yang terdorong keluar layar justru kolom terakhir
 * beserta tombolnya. Halaman yang menyembunyikan kata kerjanya di balik gulir mendatar adalah
 * halaman yang tombolnya tidak ditemukan orang.
 *
 * Yang berbeda tetap ditampilkan **utuh**, tidak dipotong. Bagian yang membedakan dua sidik ada di
 * ekornya, jadi potongan berujung elipsis akan membuat dua baris yang berbeda terbaca sama persis —
 * kebalikan dari satu-satunya alasan kolom ini ada.
 */
function Fingerprint({
    value,
    platform,
}: {
    value: string | null;
    platform: string | null;
}) {
    if (value === null || value === '') {
        return <span className="text-muted-foreground">Belum ada</span>;
    }

    if (platform !== null && value === platform) {
        return <span className="text-muted-foreground">Sama dengan image</span>;
    }

    return <span className="font-mono break-all">{value}</span>;
}

function Count({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'amber' | 'red';
}) {
    /*
     * Warnanya menyala hanya ketika angkanya bukan nol.
     *
     * Nol yang merah mengajari operator bahwa merah di layar ini normal, dan pada saat itu satu
     * kegagalan sungguhan kehilangan satu-satunya cara ia menonjol.
     */
    const highlight =
        value === 0
            ? 'text-foreground'
            : tone === 'red'
              ? 'text-destructive '
              : tone === 'amber'
                ? 'text-warning '
                : 'text-foreground';

    return (
        <div className="rounded-lg border bg-background px-4 py-3">
            <div className={`text-2xl font-semibold ${highlight}`}>{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

function LastOperation({ operation }: { operation: Operation | null }) {
    if (!operation) {
        return <span className="text-muted-foreground">Belum pernah ada</span>;
    }

    return (
        <div className="space-y-1">
            <div>
                {labelFor(operationLabels, operation.kind)}
                <span className="ms-2 text-xs text-muted-foreground">
                    {labelFor(resultLabels, operation.status)}
                    {operation.step && ` · ${operation.step}`}
                </span>
            </div>
            <div className="text-xs text-muted-foreground">
                {operation.finishedAt ?? operation.startedAt ?? '—'}
                {' · '}
                {/*
                    "Sistem" ditulis di sini dan bukan di sisi PHP, karena yang kosong di data
                    memang kosong: tidak ada manusia di balik operasi yang dimulai penjadwal.
                    Menuliskannya sebagai nama di lapisan data akan membuat "tidak ada orangnya"
                    dan "orangnya bernama Sistem" tidak dapat dibedakan lagi oleh siapa pun yang
                    membacanya kemudian.
                */}
                {operation.requestedBy ?? 'Sistem'}
            </div>
            {operation.reason && (
                <div className="text-xs break-words text-destructive">
                    {operation.reason}
                </div>
            )}
        </div>
    );
}

/**
 * Tombol pada satu baris, dan sebagian besar isinya adalah keadaan ketika tombolnya **tidak** ada.
 *
 * Baris yang sudah mutakhir tidak menampilkan apa pun. Baris yang sedang dikerjakan menampilkan
 * kata, bukan tombol: menekannya akan ditolak kunci operasi di sisi Core, dan penolakan itu
 * terbaca operator sebagai kegagalan pembaruannya — padahal yang ditolak justru permintaan kedua
 * atas pekerjaan yang sedang berjalan baik-baik saja.
 */
function RowAction({
    row,
    disabled,
    onUpgrade,
}: {
    row: Row;
    disabled: boolean;
    onUpgrade: () => void;
}) {
    if (row.queued) {
        return (
            <span className="text-xs text-muted-foreground">
                Menunggu pekerja
            </span>
        );
    }

    if (row.lastOperation?.status === 'running') {
        return (
            <span className="text-xs text-muted-foreground">
                Sedang dikerjakan
            </span>
        );
    }

    if (row.state === 'current') {
        return null;
    }

    return (
        <Button
            size="sm"
            variant={row.state === 'failed' ? 'default' : 'outline'}
            disabled={disabled}
            onClick={onUpgrade}
        >
            <RefreshCw className="size-3.5" />
            {row.state === 'failed' ? 'Coba lagi' : 'Perbarui'}
        </Button>
    );
}
