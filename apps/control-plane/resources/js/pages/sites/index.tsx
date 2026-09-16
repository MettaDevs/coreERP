import { Button } from '@apperp/ui/button';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
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
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { InstallStateBadge } from '@/components/badges';
import CopyButton from '@/components/copy-button';
import Shell from '@/components/shell';
import { progressDetail } from '@/lib/install-progress';
import type { InstallProgress } from '@/lib/install-progress';
import { newerRelease } from '@/lib/release';
import { daysUntil, relativeTime } from '@/lib/time';
import AddServerDialog from '@/pages/sites/add-server-dialog';
import type { Candidate } from '@/pages/sites/add-server-dialog';

type SiteRow = {
    id: string;
    name: string;
    tenant: string;
    environment: { id: string; name: string } | null;
    serverAddress: string | null;
    address: string | null;
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

const WAITING_STATES = [
    'no_command',
    'awaiting_command',
    'awaiting_release',
    'connected',
    'installing',
];

function licenseUrgent(row: SiteRow): boolean {
    // Lisensi permanen tidak pernah mendesak: ia tidak punya tanggal yang dapat mendekat.
    if (row.licensePerpetual) {
        return false;
    }

    const days = daysUntil(row.licenseValidUntil);

    return (
        row.progress.state !== 'revoked' &&
        days !== null &&
        days <= LICENSE_WARNING_DAYS
    );
}

/**
 * Satu aturan penggolongan untuk kartu ringkasan dan penyaring, supaya angka di kartu selalu sama dengan
 * jumlah baris yang tampil ketika kartunya ditekan.
 */
function matches(row: SiteRow, filter: Filter): boolean {
    const state = row.progress.state;

    switch (filter) {
        case 'all':
            return state !== 'revoked';
        case 'attention':
            return (
                state === 'failed' || state === 'stale' || licenseUrgent(row)
            );
        case 'running':
            return state === 'ready';
        case 'waiting':
            return WAITING_STATES.includes(state);
        case 'outdated':
            return (
                (state === 'ready' || state === 'stale') &&
                newerRelease(row.reportedRelease, row.newestRelease) !== null
            );
        case 'revoked':
            return state === 'revoked';
    }
}

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
        slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
        emerald:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200',
        red: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-200',
        amber: 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
        sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-200',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`flex items-start gap-3 rounded-lg border bg-background p-4 text-start transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/50 ${
                active ? 'border-sky-600 ring-1 ring-sky-600' : ''
            }`}
        >
            <span
                className={`flex size-9 shrink-0 items-center justify-center rounded-md ${tones[tone]}`}
            >
                {icon}
            </span>
            <span className="min-w-0">
                <span className="block text-2xl leading-none font-semibold tabular-nums">
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
            ? 'text-red-700 dark:text-red-300'
            : days <= LICENSE_WARNING_DAYS
              ? 'text-amber-700 dark:text-amber-300'
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

function EmptyFleet({ candidates }: { candidates: Candidate[] }) {
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
                    <AddServerDialog candidates={candidates} />
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
export default function Index({
    sites,
    candidates,
}: {
    sites: SiteRow[];
    candidates: Candidate[];
}) {
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');

    usePoll(POLL_MS, { only: ['sites', 'candidates'] });

    const counts = useMemo(() => {
        const count = (which: Filter) =>
            sites.filter((row) => matches(row, which)).length;

        return {
            all: count('all'),
            attention: count('attention'),
            running: count('running'),
            waiting: count('waiting'),
            outdated: count('outdated'),
            revoked: count('revoked'),
        };
    }, [sites]);

    const needle = query.trim().toLowerCase();
    const shown = sites.filter(
        (row) =>
            matches(row, filter) &&
            (needle === '' ||
                [
                    row.name,
                    row.tenant,
                    row.environment?.name,
                    row.serverAddress,
                    row.address,
                    row.lastSeenIp,
                ].some((value) => value?.toLowerCase().includes(needle))),
    );

    function toggle(which: Filter) {
        setFilter((current) => (current === which ? 'all' : which));
    }

    return (
        <Shell
            title="Server klien"
            description="Setiap VPS milik klien yang dikelola dari sini lewat agen: alamatnya, keadaan pemasangan, rilis yang berjalan, dan masa lisensinya."
            actions={
                sites.length > 0 && <AddServerDialog candidates={candidates} />
            }
        >
            <Head title="Server klien" />

            {sites.length === 0 ? (
                <EmptyFleet candidates={candidates} />
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
                            label="Jalan"
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

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div
                            role="group"
                            aria-label="Saring server"
                            className="flex flex-wrap gap-1 rounded-lg border bg-background p-1"
                        >
                            {(
                                [
                                    ['all', 'Semua', counts.all],
                                    [
                                        'attention',
                                        'Perlu perhatian',
                                        counts.attention,
                                    ],
                                    ['running', 'Jalan', counts.running],
                                    ['waiting', 'Menunggu', counts.waiting],
                                    [
                                        'outdated',
                                        'Perlu pembaruan',
                                        counts.outdated,
                                    ],
                                    ['revoked', 'Dicabut', counts.revoked],
                                ] as [Filter, string, number][]
                            ).map(([key, label, total]) => (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={filter === key}
                                    onClick={() => setFilter(key)}
                                    className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                        filter === key
                                            ? 'bg-sky-600 text-white'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                    <span
                                        className={`ms-1.5 tabular-nums ${filter === key ? 'text-white/80' : 'text-muted-foreground'}`}
                                    >
                                        {total}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <div className="relative w-full sm:w-72">
                            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                aria-label="Cari server"
                                placeholder="Cari nama, tenant, atau alamat"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                className="ps-9"
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg border bg-background">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Server</TableHead>
                                    <TableHead>Keadaan</TableHead>
                                    <TableHead>Rilis</TableHead>
                                    <TableHead>Lisensi</TableHead>
                                    <TableHead>Terakhir terlihat</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Rincian</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shown.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            Tidak ada server yang cocok dengan
                                            penyaring ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {shown.map((row) => {
                                    const detail = progressDetail(row.progress);
                                    const newer = newerRelease(
                                        row.reportedRelease,
                                        row.newestRelease,
                                    );
                                    const seen = relativeTime(row.lastSeenIso);

                                    return (
                                        <TableRow key={row.id}>
                                            {/*
                                                Sel tabel bawaan tidak membungkus teks. Nama dan keadaan dibiarkan
                                                membungkus supaya daftar muat di layar laptop tanpa gulir ke samping;
                                                alamat tetap satu baris.

                                                Tenant tidak punya kolom sendiri: nama server lahir sebagai
                                                "<tenant> — Produksi", jadi kolom tenant hanya mengulangnya. Ia
                                                tetap tertulis di bawah nama, bersama tautan lingkungannya, untuk
                                                situs lama yang namanya bebas.
                                            */}
                                            <TableCell className="min-w-[15rem] align-top whitespace-normal">
                                                <Link
                                                    href={`/situs/${row.id}`}
                                                    className="font-medium hover:underline hover:underline-offset-4"
                                                >
                                                    {row.name}
                                                </Link>
                                                {row.serverAddress ? (
                                                    <span className="mt-0.5 flex items-center gap-1">
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {row.serverAddress}
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
                                                        className="mt-0.5 block text-xs text-amber-700 hover:underline dark:text-amber-300"
                                                    >
                                                        Alamat server belum
                                                        dicatat
                                                    </Link>
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
                                                                row.environment
                                                                    .name
                                                            }
                                                        </Link>
                                                    ) : (
                                                        'tanpa lingkungan'
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="max-w-[12rem] align-top whitespace-normal">
                                                <InstallStateBadge
                                                    state={row.progress.state}
                                                />
                                                {detail && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {detail}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <span className="font-mono text-sm">
                                                    {row.reportedRelease ?? '—'}
                                                </span>
                                                {newer && (
                                                    <span className="mt-1 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300">
                                                        <ArrowUpCircle className="size-3.5" />
                                                        {newer} tersedia
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <LicenseCell row={row} />
                                            </TableCell>
                                            <TableCell className="align-top text-sm">
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
                                                        dari {row.lastSeenIp}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="w-0 text-end align-top">
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
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                </>
            )}
        </Shell>
    );
}
