import { Button } from '@apperp/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardFooter,
} from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import {
    assertListBatch,
    useIncrementalList,
} from '@apperp/ui/use-incremental-list';
import { Head, Link, usePoll } from '@inertiajs/react';
import {
    ArrowUpCircle,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    Hourglass,
    Search,
    Server,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { InstallStateBadge } from '@/components/badges';
import CopyButton from '@/components/copy-button';
import Shell from '@/components/shell';
import { progressDetail } from '@/lib/install-progress';
import type { InstallProgress } from '@/lib/install-progress';
import { newerRelease } from '@/lib/release';
import { daysUntil, relativeTime } from '@/lib/time';
import AddServerDialog from '@/pages/sites/add-server-dialog';

type SiteRow = {
    id: string;
    name: string;
    tenant: string;
    environment: { id: string; name: string } | null;
    serverAddress: string | null;
    appUrl: string | null;
    dns: { state: string; name: string | null; target: string | null };
    lastSeenIp: string | null;
    reportedRelease: string | null;
    newestRelease: string | null;
    lastSeenAt: string | null;
    lastSeenIso: string | null;
    licenseValidUntil: string | null;
    licensePerpetual: boolean;
    licenseSuspended: boolean;
    progress: InstallProgress;
};

type Filter =
    'all' | 'attention' | 'running' | 'waiting' | 'outdated' | 'revoked';

/** Lisensi dianggap mendesak sejak tujuh hari sebelum habis — sama dengan peringatan di server klien. */
const LICENSE_WARNING_DAYS = 7;

/** Selang muat ulang parsial daftar. Laporan agen datang tiap menit; lebih rapat dari ini tidak menambah apa pun. */
const POLL_MS = 30_000;

function StatCard({
    label,
    value,
    hint,
    icon,
    tone,
    active,
    onClick,
}: {
    label: string;
    value: number;
    hint: string;
    icon: ReactNode;
    tone: 'slate' | 'emerald' | 'red' | 'amber' | 'sky';
    active: boolean;
    onClick: () => void;
}) {
    const tones = {
        slate: 'bg-muted text-muted-foreground  ',
        emerald: 'bg-success/10 text-success  ',
        red: 'bg-destructive/10 text-destructive  ',
        amber: 'bg-warning/10 text-warning  ',
        sky: 'bg-primary/10 text-primary  ',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`flex items-start gap-3 rounded-lg border bg-background p-4 text-start transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/50 ${
                active ? 'border-primary/30 ring-1 ring-ring' : ''
            }`}
        >
            <span
                className={`flex size-9 shrink-0 items-center justify-center rounded-md ${tones[tone]}`}
            >
                {icon}
            </span>
            <span className="min-w-0">
                <span className="block text-lg leading-none font-semibold tabular-nums">
                    {value}
                </span>
                <span className="mt-1 block text-sm font-medium">{label}</span>
                <span className="block text-xs text-muted-foreground">
                    {hint}
                </span>
            </span>
        </button>
    );
}

function LicenseCell({ row }: { row: SiteRow }) {
    // Lisensi permanen tidak punya tanggal, jadi baris ini menyebut apa adanya alih-alih memakai jalur
    // "belum diterbitkan" di bawahnya, yang sama-sama tidak bertanggal tetapi berarti sebaliknya.
    if (row.licensePerpetual) {
        return (
            <div className="text-sm">
                Permanen
                <span className="block text-xs text-muted-foreground">
                    tanpa tanggal berakhir
                </span>
            </div>
        );
    }

    if (!row.licenseValidUntil) {
        return (
            <span className="text-xs text-muted-foreground">
                Belum diterbitkan
            </span>
        );
    }

    const days = daysUntil(row.licenseValidUntil) ?? 0;
    const tone =
        days < 0
            ? 'text-destructive '
            : days <= LICENSE_WARNING_DAYS
              ? 'text-warning '
              : '';

    return (
        <div className={`text-sm ${tone}`}>
            {row.licenseValidUntil}
            <span className="block text-xs text-muted-foreground">
                {days < 0
                    ? `habis ${Math.abs(days)} hari lalu`
                    : days === 0
                      ? 'habis hari ini'
                      : `${days} hari lagi`}
                {row.licenseSuspended && ' · perpanjangan dihentikan'}
            </span>
        </div>
    );
}

function EmptyFleet({ hasCandidates }: { hasCandidates: boolean }) {
    return (
        <Empty className="border bg-background">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <Server />
                </EmptyMedia>
                <EmptyTitle>Belum ada server klien</EmptyTitle>
                <EmptyDescription>
                    Server klien adalah VPS milik klien yang menjalankan
                    produksinya dan dikelola dari sini lewat agen. Alamatnya,
                    keadaan pemasangan, rilis, dan lisensinya akan terkumpul di
                    daftar ini.
                </EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
                <ol className="list-decimal space-y-1 ps-5 text-start text-sm text-muted-foreground">
                    <li>
                        Buat lingkungan produksi dengan pilihan Server klien.
                    </li>
                    <li>Tambah server kliennya dan catat alamat VPS-nya.</li>
                    <li>Buat perintah pasang dan jalankan di VPS itu.</li>
                </ol>
                <div className="flex flex-wrap justify-center gap-2">
                    <AddServerDialog hasCandidates={hasCandidates} />
                    <Button asChild variant="outline">
                        <Link href="/lingkungan">Ke Lingkungan</Link>
                    </Button>
                </div>
            </EmptyContent>
        </Empty>
    );
}

/**
 * Daftar server klien: setiap VPS milik klien yang dikelola dari admin.erp.
 *
 * Halaman ini menjawab tiga pertanyaan yang tidak terjawab dari halaman lingkungan tenant satu per satu:
 * server mana saja yang kita pegang dan di alamat mana, mana yang harus didatangi hari ini, dan mana yang
 * tertinggal rilis. Kartu di atas menghitung ketiganya; menekannya menyaring tabel dengan aturan yang sama.
 *
 * Daftarnya dimuat ulang sebagian setiap tiga puluh detik, supaya "terakhir terlihat" dan keadaan
 * pemasangan tidak basi selama halamannya dibiarkan terbuka.
 */
export default function Index({ hasCandidates }: { hasCandidates: boolean }) {
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');

    const [summary, setSummary] = useState<Record<Filter, number> | null>(null);
    const counts = summary ?? {
        all: 0,
        attention: 0,
        running: 0,
        waiting: 0,
        outdated: 0,
        revoked: 0,
    };
    const loadItems = useCallback(
        async ({
            query: search,
            cursor,
            signal,
        }: {
            query: string;
            cursor: string | null;
            signal: AbortSignal;
        }) => {
            const params = new URLSearchParams({ search, filter });

            if (cursor) {
                params.set('cursor', cursor);
            }

            const response = await fetch(`/situs?${params}`, {
                signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(
                    'Daftar server belum dapat dimuat. Periksa koneksi lalu coba lagi.',
                );
            }

            const body: unknown = await response.json();

            if (
                !body ||
                typeof body !== 'object' ||
                !('data' in body) ||
                !('next_cursor' in body) ||
                !('counts' in body)
            ) {
                throw new Error(
                    'Jawaban daftar server tidak lengkap. Muat ulang daftar.',
                );
            }

            const nextCounts = body.counts;

            if (
                !nextCounts ||
                typeof nextCounts !== 'object' ||
                ![
                    'all',
                    'attention',
                    'running',
                    'waiting',
                    'outdated',
                    'revoked',
                ].every(
                    (key) =>
                        key in nextCounts &&
                        typeof Reflect.get(nextCounts, key) === 'number',
                )
            ) {
                throw new Error(
                    'Ringkasan server belum dapat dibaca. Muat ulang daftar.',
                );
            }

            const batch = { items: body.data, nextCursor: body.next_cursor };
            assertListBatch<SiteRow>(batch);

            if (!signal.aborted) {
                setSummary(nextCounts as Record<Filter, number>);
            }

            return batch;
        },
        [filter],
    );
    const list = useIncrementalList<SiteRow>({
        loadItems,
        query,
        getKey: (row) => row.id,
    });
    usePoll(POLL_MS, { only: ['hasCandidates'], onSuccess: list.reload });

    function toggle(which: Filter) {
        setFilter((current) => (current === which ? 'all' : which));
    }

    return (
        <Shell
            title="Server klien"
            description="Pantau server milik klien, selesaikan pemasangan, dan tindak lanjuti server yang perlu perhatian."
            actions={
                counts.all + counts.revoked > 0 && (
                    <AddServerDialog hasCandidates={hasCandidates} />
                )
            }
        >
            <Head title="Server klien" />

            {summary !== null &&
            counts.all + counts.revoked === 0 &&
            !list.error ? (
                <EmptyFleet hasCandidates={hasCandidates} />
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Server dikelola"
                            value={counts.all}
                            hint={
                                counts.revoked > 0
                                    ? `${counts.revoked} dicabut tidak dihitung`
                                    : 'Semua yang belum dicabut'
                            }
                            icon={<Server className="size-4" />}
                            tone="slate"
                            active={filter === 'all'}
                            onClick={() => setFilter('all')}
                        />
                        <StatCard
                            label="Terhubung"
                            value={counts.running}
                            hint="Terpasang dan melapor"
                            icon={<CircleCheck className="size-4" />}
                            tone="emerald"
                            active={filter === 'running'}
                            onClick={() => toggle('running')}
                        />
                        <StatCard
                            label="Perlu perhatian"
                            value={counts.attention}
                            hint={`Gagal, tidak melapor, atau lisensi ≤ ${LICENSE_WARNING_DAYS} hari`}
                            icon={<CircleAlert className="size-4" />}
                            tone={counts.attention > 0 ? 'red' : 'slate'}
                            active={filter === 'attention'}
                            onClick={() => toggle('attention')}
                        />
                        <StatCard
                            label="Menunggu pemasangan"
                            value={counts.waiting}
                            hint={
                                counts.outdated > 0
                                    ? `Ditambah ${counts.outdated} yang perlu pembaruan rilis`
                                    : 'Perintah, agen, atau rilis'
                            }
                            icon={<Hourglass className="size-4" />}
                            tone="amber"
                            active={filter === 'waiting'}
                            onClick={() => toggle('waiting')}
                        />
                    </div>

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Daftar server</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {' '}
                            <div className="flex flex-col gap-3">
                                <div className="relative w-full sm:w-72">
                                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        type="search"
                                        aria-label="Cari server"
                                        placeholder="Cari nama, tenant, atau alamat"
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                        className="ps-9"
                                    />
                                </div>
                                <div
                                    role="group"
                                    aria-label="Saring server"
                                    className="flex flex-wrap gap-1"
                                >
                                    {(
                                        [
                                            ['all', 'Semua', counts.all],
                                            [
                                                'attention',
                                                'Perlu perhatian',
                                                counts.attention,
                                            ],
                                            [
                                                'running',
                                                'Terhubung',
                                                counts.running,
                                            ],
                                            [
                                                'waiting',
                                                'Menunggu',
                                                counts.waiting,
                                            ],
                                            [
                                                'outdated',
                                                'Perlu pembaruan',
                                                counts.outdated,
                                            ],
                                            [
                                                'revoked',
                                                'Dicabut',
                                                counts.revoked,
                                            ],
                                        ] as [Filter, string, number][]
                                    ).map(([key, label, total]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={filter === key}
                                            onClick={() => setFilter(key)}
                                            className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                                filter === key
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                            }`}
                                        >
                                            {label}
                                            <span
                                                className={`ms-1.5 tabular-nums ${filter === key ? 'text-primary-foreground/80' : 'text-muted-foreground'}`}
                                            >
                                                {total}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                        <div className="h-[min(40rem,65vh)] min-h-64 border-y">
                            <DataTable
                                scrollMode="viewport"
                                data={list.items}
                                incremental={list}
                                getRowKey={(row) => row.id}
                                showRowNumbers={false}
                                emptyMessage="Tidak ada server yang cocok dengan penyaring ini."
                                columns={[
                                    {
                                        id: 'name',
                                        header: 'Server',
                                        minWidth: 240,
                                        cell: (row) => {
                                            return (
                                                <div className="whitespace-normal">
                                                    <Link
                                                        href={`/situs/${row.id}`}
                                                        className="font-medium hover:underline hover:underline-offset-4"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                    {row.serverAddress ? (
                                                        <span className="mt-0.5 flex items-center gap-1">
                                                            <span className="font-mono text-xs text-muted-foreground">
                                                                {
                                                                    row.serverAddress
                                                                }
                                                            </span>
                                                            <CopyButton
                                                                text={
                                                                    row.serverAddress
                                                                }
                                                                label="Salin alamat server"
                                                                iconOnly
                                                            />
                                                        </span>
                                                    ) : (
                                                        <Link
                                                            href={`/situs/${row.id}#setelan`}
                                                            className="mt-0.5 block text-xs text-warning hover:underline"
                                                        >
                                                            Alamat server belum
                                                            dicatat
                                                        </Link>
                                                    )}
                                                    {row.appUrl && (
                                                        <a
                                                            href={row.appUrl}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="mt-0.5 block font-mono text-xs break-all text-muted-foreground hover:underline"
                                                        >
                                                            {row.appUrl.replace(
                                                                /^https?:\/\//,
                                                                '',
                                                            )}
                                                        </a>
                                                    )}
                                                    {row.dns.state ===
                                                        'outdated' && (
                                                        <span className="mt-0.5 block text-xs text-warning">
                                                            DNS belum mengikuti
                                                            alamat server
                                                        </span>
                                                    )}
                                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                                        {row.tenant} ·{' '}
                                                        {row.environment ? (
                                                            <Link
                                                                href={`/lingkungan/${row.environment.id}`}
                                                                className="underline-offset-4 hover:underline"
                                                            >
                                                                lingkungan{' '}
                                                                {
                                                                    row
                                                                        .environment
                                                                        .name
                                                                }
                                                            </Link>
                                                        ) : (
                                                            'tanpa lingkungan'
                                                        )}
                                                    </span>
                                                </div>
                                            );
                                        },
                                    },
                                    {
                                        id: 'progress',
                                        header: 'Keadaan',
                                        minWidth: 180,
                                        cell: (row) => {
                                            const detail = progressDetail(
                                                row.progress,
                                            );

                                            return (
                                                <div className="whitespace-normal">
                                                    <InstallStateBadge
                                                        state={
                                                            row.progress.state
                                                        }
                                                    />
                                                    {detail && (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {detail}
                                                        </p>
                                                    )}
                                                </div>
                                            );
                                        },
                                    },
                                    {
                                        id: 'release',
                                        header: 'Rilis',
                                        minWidth: 120,
                                        cell: (row) => {
                                            const newer = newerRelease(
                                                row.reportedRelease,
                                                row.newestRelease,
                                            );

                                            return (
                                                <div className="whitespace-normal">
                                                    <span className="font-mono text-sm">
                                                        {row.reportedRelease ??
                                                            '—'}
                                                    </span>
                                                    {newer && (
                                                        <span className="mt-1 flex items-center gap-1 text-xs text-warning">
                                                            <ArrowUpCircle className="size-3.5" />
                                                            {newer} tersedia
                                                        </span>
                                                    )}
                                                </div>
                                            );
                                        },
                                    },
                                    {
                                        id: 'license',
                                        header: 'Lisensi',
                                        minWidth: 150,
                                        cell: (row) => {
                                            return (
                                                <div className="whitespace-normal">
                                                    <LicenseCell row={row} />
                                                </div>
                                            );
                                        },
                                    },
                                    {
                                        id: 'lastSeen',
                                        header: 'Terakhir terlihat',
                                        minWidth: 160,
                                        cell: (row) => {
                                            const seen = relativeTime(
                                                row.lastSeenIso,
                                            );

                                            return (
                                                <div className="whitespace-normal">
                                                    {seen ? (
                                                        <span
                                                            title={
                                                                row.lastSeenAt ??
                                                                undefined
                                                            }
                                                        >
                                                            {seen}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Belum pernah
                                                        </span>
                                                    )}
                                                    {row.lastSeenIp && (
                                                        <span className="block font-mono text-xs text-muted-foreground">
                                                            dari{' '}
                                                            {row.lastSeenIp}
                                                        </span>
                                                    )}
                                                </div>
                                            );
                                        },
                                    },
                                    {
                                        id: 'details',
                                        header: 'Rincian',
                                        minWidth: 60,
                                        cell: (row) => {
                                            return (
                                                <div className="whitespace-normal">
                                                    <Button
                                                        asChild
                                                        size="icon-sm"
                                                        variant="ghost"
                                                    >
                                                        <Link
                                                            href={`/situs/${row.id}`}
                                                            aria-label={`Rincian ${row.name}`}
                                                            title="Rincian"
                                                        >
                                                            <ChevronRight />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            );
                                        },
                                    },
                                ]}
                            />
                        </div>
                        <CardFooter className="text-xs text-muted-foreground">
                            Laporan diperbarui otomatis setiap 30 detik.
                        </CardFooter>
                    </Card>
                </>
            )}
        </Shell>
    );
}
