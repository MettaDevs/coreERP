import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { Skeleton } from '@apperp/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { Head, router } from '@inertiajs/react';
import {
    MoreHorizontal,
    NotebookPen,
    RefreshCw,
    Search,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { toast } from 'sonner';
import { PostingCheck } from '@/components/finance/posting-check';
import type {
    PostingCheckLine,
    PostingCheckProblem,
} from '@/components/finance/posting-check';
import Heading from '@/components/heading';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

/**
 * Layar pantau posting finance (TODO 7.1–7.3): daftar posting dengan saringan dari server,
 * rinciannya saat satu baris dipilih, dan dua tindak lanjut — validasi ulang posting yang
 * tertahan, dan tandai manual dengan alasan wajib.
 *
 * Sengaja tidak ada aksi mengubah tanggal atau nilai (TODO 7.3.3, K-17): posting yang keliru
 * dikoreksi dari dokumen sumbernya, bukan disunting di sini.
 */

type Status = 'held' | 'pending' | 'posted' | 'rejected' | 'manual';
type ManualReason = 'before_cutover' | 'feed_disabled' | 'user';
type Posting = {
    id: string;
    posting_id: string;
    posting_type: string;
    status: Status;
    manual_reason: ManualReason | null;
    legal_entity: { id: string; code: string | null; name: string | null };
    posting_date: string;
    published_at: string;
    currency_code: string;
    currency_decimals: number;
    total_debit: string;
    source: {
        module: string;
        type: string;
        number: string | null;
        id: string | null;
    };
    problem_count: number;
    served_count: number;
    last_served_at: string | null;
    acknowledged_at: string | null;
    external_reference: string | null;
    reason_code: string | null;
    reason: string | null;
};
type PostingEvent = {
    event: string;
    from_status: string | null;
    to_status: string | null;
    actor: string | null;
    created_at: string;
    data: Record<string, unknown>;
};
type Delivery = {
    client: string;
    status: string;
    attempts: number;
    last_status_code: number | null;
    last_error: string | null;
    last_attempt_at: string | null;
    next_attempt_at: string | null;
    delivered_at: string | null;
};
type SourceDocument = {
    module: string;
    app_name: string | null;
    type: string;
    number: string | null;
    description: string | null;
    id: string | null;
    url: string | null;
};
type PostingDetail = Posting & {
    lines: PostingCheckLine[];
    problems: PostingCheckProblem[];
    events: PostingEvent[];
    deliveries: Delivery[];
    source_document: SourceDocument;
};
type LegalEntity = { id: string; code: string | null; name: string };
type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Filters = {
    q: string | null;
    status: string | null;
    posting_type: string | null;
    legal_entity_id: string | null;
    from: string | null;
    to: string | null;
};
type Props = {
    canManage: boolean;
    filters: Filters;
    statuses: string[];
    postingTypes: string[];
    legalEntities: LegalEntity[];
    counts: Record<string, number>;
    postings: Paginated<Posting>;
};
type BadgeVariant = NonNullable<ComponentProps<typeof Badge>['variant']>;

const PAGE_URL = '/settings/finance-postings';

const NO_FILTERS: Filters = {
    q: null,
    status: null,
    posting_type: null,
    legal_entity_id: null,
    from: null,
    to: null,
};

// Label disusun sebagai `Record` supaya setiap status wajib punya label, lalu dicari lewat `Map`:
// nilai dari server bisa saja bukan salah satu kuncinya, dan `Map` tidak ikut mencari di
// prototipe objek seperti `in` atau pengindeksan biasa.
const STATUS = new Map<string, { label: string; variant: BadgeVariant }>(
    Object.entries({
        held: { label: 'Tertahan', variant: 'destructive' },
        pending: { label: 'Menunggu ditarik', variant: 'secondary' },
        posted: { label: 'Sudah dibukukan', variant: 'default' },
        rejected: { label: 'Ditolak', variant: 'destructive' },
        manual: { label: 'Manual', variant: 'outline' },
    } satisfies Record<Status, { label: string; variant: BadgeVariant }>),
);

// Sama dengan yang diterima server. `posted` tidak termasuk: aplikasi finance sudah
// membukukannya, jadi menandainya manual berarti jurnal kedua.
const MARKABLE: readonly string[] = ['held', 'pending', 'rejected'];

const MANUAL_REASON = new Map<string, { short: string; long: string }>(
    Object.entries({
        before_cutover: {
            short: 'Sebelum cutover',
            long: 'Tanggal posting jatuh sebelum tanggal cutover entitas legal ini. Transaksi sebelum cutover sudah dijurnal manual di aplikasi finance, jadi posting ini tidak dikirim. Bila tanggal cutover diubah, posting ini diperiksa ulang dengan sendirinya.',
        },
        feed_disabled: {
            short: 'Posting ke finance dimatikan',
            long: 'Pengiriman posting ke aplikasi finance dimatikan untuk entitas legal ini, jadi posting ini tidak dikirim. Bila pengirimannya dinyalakan lagi, posting ini diperiksa ulang dengan sendirinya.',
        },
        user: {
            short: 'Ditandai pengguna',
            long: 'Pengguna menandai posting ini untuk dibukukan sendiri di aplikasi finance, jadi posting ini tidak dikirim lagi.',
        },
    } satisfies Record<ManualReason, { short: string; long: string }>),
);

// Kode alasan penolakan dari aplikasi finance, sesuai kontrak konfirmasinya.
const REJECTION = new Map([
    ['PERIOD_CLOSED', 'Periode akuntansinya sudah ditutup'],
    ['UNKNOWN_ACCOUNT', 'Akunnya tidak dikenal aplikasi finance'],
    ['UNKNOWN_DIMENSION', 'Dimensinya tidak dikenal aplikasi finance'],
    ['UNKNOWN_VENDOR', 'Vendornya tidak dikenal aplikasi finance'],
    ['UNKNOWN_LEGAL_ENTITY', 'Entitas legalnya tidak dikenal aplikasi finance'],
    ['INVALID', 'Isinya tidak diterima aplikasi finance'],
]);

const EVENT = new Map([
    ['published', 'Diterbitkan'],
    ['revalidated', 'Divalidasi ulang'],
    ['cutover_reevaluated', 'Diperiksa ulang karena setelan posting berubah'],
    ['marked_manual', 'Ditandai manual'],
    ['acknowledged_posted', 'Dibukukan aplikasi finance'],
    ['acknowledged_rejected', 'Ditolak aplikasi finance'],
    ['push_delivered', 'Terkirim ke aplikasi finance'],
    ['push_retrying', 'Gagal terkirim, akan dicoba lagi'],
    ['push_failed', 'Gagal terkirim'],
]);

const DELIVERY = new Map<string, { label: string; variant: BadgeVariant }>([
    ['delivered', { label: 'Terkirim', variant: 'secondary' }],
    ['retrying', { label: 'Dicoba lagi', variant: 'outline' }],
    ['failed', { label: 'Gagal', variant: 'destructive' }],
]);

const DATE = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });
const DATE_TIME = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const COUNT = new Intl.NumberFormat('id-ID');
const AMOUNT = /^(-?)(\d+)(?:\.(\d+))?$/;

function statusLabel(value: string): string {
    return STATUS.get(value)?.label ?? value;
}

function statusVariant(value: string): BadgeVariant {
    return STATUS.get(value)?.variant ?? 'outline';
}

function isMarkable(status: string): boolean {
    return MARKABLE.includes(status);
}

function manualReason(value: string | null) {
    return value === null ? null : (MANUAL_REASON.get(value) ?? null);
}

function rejectionLabel(code: string): string {
    return REJECTION.get(code) ?? 'Alasan lain';
}

function deliveryStatus(value: string): {
    label: string;
    variant: BadgeVariant;
} {
    return DELIVERY.get(value) ?? { label: 'Lainnya', variant: 'outline' };
}

function formatDate(value: string): string {
    return DATE.format(new Date(`${value}T00:00:00`));
}

function formatDateTime(value: string | null): string {
    return value ? DATE_TIME.format(new Date(value)) : '—';
}

/**
 * Nilai dari server berupa string desimal. Diformat tanpa lewat Number, seperti komponen
 * pemeriksaan posting, supaya nilai miliaran tidak pernah dibulatkan float. String yang tidak
 * terbaca, atau yang lebih halus dari presisi mata uangnya, ditampilkan apa adanya.
 */
function formatAmount(value: string, decimals: number): string {
    const match = AMOUNT.exec(value.trim());

    if (!match) {
        return value;
    }

    const [, sign, whole, fraction = ''] = match;

    if (/[^0]/.test(fraction.slice(decimals))) {
        return value;
    }

    const grouped = COUNT.format(BigInt(whole));

    return decimals > 0
        ? `${sign}${grouped},${fraction.slice(0, decimals).padEnd(decimals, '0')}`
        : `${sign}${grouped}`;
}

function formatAge(since: string, now: number): string {
    const minutes = Math.floor((now - new Date(since).getTime()) / 60_000);

    if (minutes < 1) {
        return 'kurang dari 1 menit';
    }

    if (minutes < 60) {
        return `${minutes} menit`;
    }

    const hours = Math.floor(minutes / 60);

    return hours < 24 ? `${hours} jam` : `${Math.floor(hours / 24)} hari`;
}

function entityName(entity: Posting['legal_entity']): string {
    return entity.name ?? entity.code ?? '—';
}

function readText(data: Record<string, unknown>, key: string): string | null {
    const value = data[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

function readNumber(data: Record<string, unknown>, key: string): number | null {
    const value = data[key];

    return typeof value === 'number' ? value : null;
}

/** Saringan kosong tidak ikut ke alamat, supaya tautan yang dibagikan tetap pendek. */
function toQuery(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value) {
            query[key] = value;
        }
    }

    return query;
}

function actionError(caught: unknown, fallback: string): string {
    return caught instanceof CoreApiError && caught.status === 403
        ? 'Anda tidak punya izin untuk menindaklanjuti posting.'
        : errorText(caught, fallback);
}

function currentTime(): number {
    return Date.now();
}

/** Jam yang maju tiap menit, supaya kolom umur tidak membeku selama halaman terbuka. */
function useNow(): number {
    const [now, setNow] = useState(currentTime);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(currentTime()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

function statusNote(posting: Posting): string | null {
    if (posting.status === 'held') {
        return posting.problem_count > 0
            ? `${COUNT.format(posting.problem_count)} masalah`
            : null;
    }

    if (posting.status === 'rejected') {
        return posting.reason_code ? rejectionLabel(posting.reason_code) : null;
    }

    if (posting.status === 'manual') {
        return manualReason(posting.manual_reason)?.short ?? null;
    }

    return null;
}

function StatusBadge({ posting }: { posting: Posting }) {
    const note = statusNote(posting);

    return (
        <span className="flex flex-col items-start gap-1">
            <Badge variant={statusVariant(posting.status)}>
                {statusLabel(posting.status)}
            </Badge>
            {note && (
                <span className="text-xs text-muted-foreground">{note}</span>
            )}
        </span>
    );
}

function PostingFilters({
    filters,
    statuses,
    postingTypes,
    legalEntities,
    counts,
    onApply,
}: Pick<
    Props,
    'filters' | 'statuses' | 'postingTypes' | 'legalEntities' | 'counts'
> & { onApply: (next: Filters) => void }) {
    // Teks dan tanggal baru dikirim saat "Cari": mengirim tiap perubahan kolom tanggal akan
    // memuat ulang daftar untuk setiap angka tahun yang diketik.
    const [q, setQ] = useState(filters.q ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [rangeError, setRangeError] = useState('');
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const filtered = Object.values(filters).some(Boolean);

    const apply = (next: Partial<Filters> = {}) => {
        if (from && to && from > to) {
            setRangeError('Tanggal akhir tidak boleh sebelum tanggal awal.');

            return;
        }

        onApply({
            ...filters,
            q: q.trim() || null,
            from: from || null,
            to: to || null,
            ...next,
        });
    };

    return (
        <div className="space-y-2">
            <form
                className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply();
                }}
            >
                <div className="relative w-full sm:max-w-xs">
                    <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input
                        aria-label="Cari nomor posting atau nomor dokumen"
                        placeholder="Cari nomor posting atau nomor dokumen"
                        className="pl-8"
                        maxLength={120}
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-52">
                    <NativeSelect
                        label="Status"
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            apply({ status: event.target.value || null })
                        }
                    >
                        <NativeSelectOption value="">
                            {`Semua status (${COUNT.format(total)})`}
                        </NativeSelectOption>
                        {statuses.map((status) => (
                            <NativeSelectOption key={status} value={status}>
                                {`${statusLabel(status)} (${COUNT.format(counts[status] ?? 0)})`}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                </div>
                <div className="w-full sm:w-52">
                    <NativeSelect
                        label="Jenis posting"
                        value={filters.posting_type ?? ''}
                        onChange={(event) =>
                            apply({ posting_type: event.target.value || null })
                        }
                    >
                        <NativeSelectOption value="">
                            Semua jenis
                        </NativeSelectOption>
                        {postingTypes.map((type) => (
                            <NativeSelectOption key={type} value={type}>
                                {type}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                </div>
                <div className="w-full sm:w-56">
                    <NativeSelect
                        label="Entitas legal"
                        value={filters.legal_entity_id ?? ''}
                        onChange={(event) =>
                            apply({
                                legal_entity_id: event.target.value || null,
                            })
                        }
                    >
                        <NativeSelectOption value="">
                            Semua entitas legal
                        </NativeSelectOption>
                        {legalEntities.map((entity) => (
                            <NativeSelectOption
                                key={entity.id}
                                value={entity.id}
                            >
                                {entity.name}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                </div>
                <div className="w-full sm:w-44">
                    <Input
                        type="date"
                        label="Tanggal posting dari"
                        value={from}
                        max={to || undefined}
                        aria-invalid={Boolean(rangeError)}
                        onChange={(event) => {
                            setFrom(event.target.value);
                            setRangeError('');
                        }}
                    />
                </div>
                <div className="w-full sm:w-44">
                    <Input
                        type="date"
                        label="Tanggal posting sampai"
                        value={to}
                        min={from || undefined}
                        aria-invalid={Boolean(rangeError)}
                        onChange={(event) => {
                            setTo(event.target.value);
                            setRangeError('');
                        }}
                    />
                </div>
                <div className="flex gap-2">
                    <Button type="submit" variant="outline">
                        Cari
                    </Button>
                    {filtered && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onApply(NO_FILTERS)}
                        >
                            Hapus saringan
                        </Button>
                    )}
                </div>
            </form>
            {rangeError && (
                <p role="alert" className="text-sm text-destructive">
                    {rangeError}
                </p>
            )}
        </div>
    );
}

function PostingsEmpty({
    total,
    filtered,
    onReset,
}: {
    total: number;
    filtered: boolean;
    onReset: () => void;
}) {
    if (total === 0) {
        return (
            <Empty className="py-12">
                <EmptyHeader>
                    <EmptyTitle>Belum ada posting</EmptyTitle>
                    <EmptyDescription>
                        Posting muncul di sini setiap kali sebuah app
                        menyelesaikan dokumen yang perlu dijurnal di aplikasi
                        finance. Pengirimannya diatur per entitas legal di
                        halaman Organisasi.
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <Empty className="py-12">
            <EmptyHeader>
                <EmptyTitle>
                    {filtered
                        ? 'Tidak ada posting yang cocok'
                        : 'Halaman ini kosong'}
                </EmptyTitle>
                <EmptyDescription>
                    {filtered
                        ? 'Tidak ada posting yang cocok dengan saringan ini. Longgarkan atau hapus saringannya untuk melihat posting lain.'
                        : 'Tidak ada posting di halaman ini.'}
                </EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onReset}
                >
                    {filtered ? 'Hapus saringan' : 'Kembali ke halaman pertama'}
                </Button>
            </EmptyContent>
        </Empty>
    );
}

function PostingRow({
    posting,
    selected,
    canManage,
    busy,
    now,
    onSelect,
    onRevalidate,
    onMarkManual,
}: {
    posting: Posting;
    selected: boolean;
    canManage: boolean;
    busy: boolean;
    now: number;
    onSelect: (id: string) => void;
    onRevalidate: (posting: Posting) => void;
    onMarkManual: (posting: Posting) => void;
}) {
    return (
        <TableRow
            tabIndex={0}
            aria-selected={selected}
            data-state={selected ? 'selected' : undefined}
            className="cursor-pointer"
            onClick={() => onSelect(posting.id)}
            onKeyDown={(event) => {
                // Hanya saat fokus berada di baris itu sendiri. Enter atau spasi pada tombol aksi
                // di dalam baris tetap menjalankan tombolnya.
                if (event.target !== event.currentTarget) {
                    return;
                }

                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect(posting.id);
                }
            }}
        >
            <TableCell>
                <span className="font-mono">{posting.posting_id}</span>
                {posting.source.number && (
                    <span className="block text-xs text-muted-foreground">
                        Dokumen {posting.source.number}
                    </span>
                )}
            </TableCell>
            <TableCell className="font-mono text-xs">
                {posting.posting_type}
            </TableCell>
            <TableCell>{entityName(posting.legal_entity)}</TableCell>
            <TableCell>{formatDate(posting.posting_date)}</TableCell>
            <TableCell className="text-right tabular-nums">
                <span className="text-muted-foreground">
                    {posting.currency_code}
                </span>{' '}
                {formatAmount(posting.total_debit, posting.currency_decimals)}
            </TableCell>
            <TableCell>
                {posting.status === 'pending' ? (
                    formatAge(posting.published_at, now)
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell>
                <StatusBadge posting={posting} />
            </TableCell>
            {canManage && (
                <TableCell className="text-right">
                    {isMarkable(posting.status) && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Aksi untuk posting ${posting.posting_id}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {posting.status === 'held' && (
                                    <DropdownMenuItem
                                        disabled={busy}
                                        onSelect={() => onRevalidate(posting)}
                                    >
                                        Validasi ulang
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                    onSelect={() => onMarkManual(posting)}
                                >
                                    Tandai manual
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </TableCell>
            )}
        </TableRow>
    );
}

function DetailItem({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-0 space-y-1">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

function PostingSummary({ posting, now }: { posting: Posting; now: number }) {
    return (
        <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
            <DetailItem label="Status">
                <StatusBadge posting={posting} />
            </DetailItem>
            <DetailItem label="Jenis posting">
                <span className="font-mono text-xs">
                    {posting.posting_type}
                </span>
            </DetailItem>
            <DetailItem label="Entitas legal">
                {entityName(posting.legal_entity)}
            </DetailItem>
            <DetailItem label="Tanggal posting">
                {formatDate(posting.posting_date)}
            </DetailItem>
            <DetailItem label="Nilai">
                <span className="tabular-nums">
                    <span className="text-muted-foreground">
                        {posting.currency_code}
                    </span>{' '}
                    {formatAmount(
                        posting.total_debit,
                        posting.currency_decimals,
                    )}
                </span>
            </DetailItem>
            <DetailItem label="Diterbitkan">
                {formatDateTime(posting.published_at)}
                {posting.status === 'pending' && (
                    <span className="block text-xs text-muted-foreground">
                        Menunggu {formatAge(posting.published_at, now)}
                    </span>
                )}
            </DetailItem>
            {posting.external_reference && (
                <DetailItem label="Nomor di aplikasi finance">
                    <span className="font-mono">
                        {posting.external_reference}
                    </span>
                </DetailItem>
            )}
            {posting.acknowledged_at && (
                <DetailItem label="Dikonfirmasi aplikasi finance">
                    {formatDateTime(posting.acknowledged_at)}
                </DetailItem>
            )}
        </dl>
    );
}

/** Alasan yang diketik pengguna tersimpan di riwayat, bukan di posting-nya. */
function manualNote(detail: PostingDetail | null): string | null {
    const marks = (detail?.events ?? []).filter(
        (item) => item.event === 'marked_manual',
    );

    return marks.length > 0
        ? readText(marks[marks.length - 1].data, 'reason')
        : null;
}

function StatusNote({
    posting,
    detail,
    canManage,
}: {
    posting: Posting;
    detail: PostingDetail | null;
    canManage: boolean;
}) {
    if (posting.status === 'held') {
        return (
            <Alert variant="destructive">
                <TriangleAlert />
                <AlertTitle>Tertahan di CoreERP</AlertTitle>
                <AlertDescription>
                    <p>
                        {posting.problem_count > 0
                            ? `${COUNT.format(posting.problem_count)} masalah di pemeriksaan posting harus diperbaiki`
                            : 'Masalah di pemeriksaan posting harus diperbaiki'}{' '}
                        sebelum posting ini dapat ditarik aplikasi finance.
                        {canManage &&
                            ' Setelah diperbaiki, pilih Validasi ulang.'}
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    if (posting.status === 'rejected') {
        return (
            <Alert variant="destructive">
                <TriangleAlert />
                <AlertTitle>Ditolak aplikasi finance</AlertTitle>
                <AlertDescription>
                    {posting.reason_code && (
                        <p className="font-medium">
                            {rejectionLabel(posting.reason_code)}
                            <span className="ml-2 font-mono text-xs font-normal">
                                {posting.reason_code}
                            </span>
                        </p>
                    )}
                    {posting.reason && <p>{posting.reason}</p>}
                    <p>
                        Posting yang ditolak tidak dikirim ulang, dan tanggal
                        serta nilainya tidak dapat diubah di sini. Bukukan
                        transaksinya di aplikasi finance pada periode yang masih
                        terbuka, lalu tandai posting ini manual.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    const manual =
        posting.status === 'manual'
            ? manualReason(posting.manual_reason)
            : null;

    if (manual) {
        const note =
            posting.manual_reason === 'user' ? manualNote(detail) : null;

        return (
            <Alert>
                <NotebookPen />
                <AlertTitle>Manual: {manual.short.toLowerCase()}</AlertTitle>
                <AlertDescription>
                    <p>{manual.long}</p>
                    {note && <p>Alasan: {note}</p>}
                </AlertDescription>
            </Alert>
        );
    }

    return null;
}

// Jenis dokumen dikirim app sebagai kode (`penerimaan-aset`); belum ada daftar nama tampilnya, jadi
// kodenya dibaca sebagai kalimat.
function documentType(type: string): string {
    const text = type.replace(/[-_]+/g, ' ').trim();

    return text === '' ? type : text.charAt(0).toUpperCase() + text.slice(1);
}

function SourceDocumentSection({ source }: { source: SourceDocument }) {
    return (
        <section className="space-y-3">
            <h3 className="text-sm font-semibold">Dokumen sumber</h3>
            <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                <DetailItem label="App">
                    {source.app_name ?? source.module}
                </DetailItem>
                <DetailItem label="Jenis dokumen">
                    {documentType(source.type)}
                </DetailItem>
                <DetailItem label="Nomor">
                    {source.number === null ? (
                        '—'
                    ) : source.url === null ? (
                        <span className="font-mono">{source.number}</span>
                    ) : (
                        // Tautan biasa, bukan navigasi Inertia: layar dokumen milik app belum
                        // tentu halaman Inertia. Alamatnya sudah dibatasi server ke jalur relatif.
                        <a
                            href={source.url}
                            className="font-mono text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current dark:decoration-neutral-500"
                        >
                            {source.number}
                        </a>
                    )}
                </DetailItem>
                <DetailItem label="Keterangan">
                    {source.description ?? '—'}
                </DetailItem>
            </dl>
        </section>
    );
}

function eventLabel(event: PostingEvent): string {
    return (
        EVENT.get(event.event) ??
        (event.from_status !== event.to_status ? 'Status berubah' : 'Catatan')
    );
}

function transition(event: PostingEvent): string {
    const from = event.from_status ? statusLabel(event.from_status) : null;
    const to = event.to_status ? statusLabel(event.to_status) : null;

    if (from && to && from !== to) {
        return `${from} → ${to}`;
    }

    return to ?? from ?? '—';
}

function eventNote(event: PostingEvent): string {
    const reason = readText(event.data, 'reason');
    const manual = manualReason(readText(event.data, 'manual_reason'));
    const reference = readText(event.data, 'external_reference');
    const reasonCode = readText(event.data, 'reason_code');
    const problems = readNumber(event.data, 'problems');
    const attempts = readNumber(event.data, 'attempts');
    const statusCode = readNumber(event.data, 'status_code');
    const retryIn = readNumber(event.data, 'retry_in_minutes');

    return [
        reason && `Alasan: ${reason}`,
        manual?.short,
        reference && `Nomor di aplikasi finance: ${reference}`,
        reasonCode && rejectionLabel(reasonCode),
        problems !== null &&
            problems > 0 &&
            `${COUNT.format(problems)} masalah`,
        attempts !== null && `Percobaan ke-${attempts}`,
        statusCode !== null && `Kode jawaban ${statusCode}`,
        retryIn !== null && `Dicoba lagi dalam ${retryIn} menit`,
    ]
        .filter(
            (part): part is string => typeof part === 'string' && part !== '',
        )
        .join(' · ');
}

function servedSummary(posting: Posting): string {
    const served =
        posting.served_count > 0
            ? `Ditarik aplikasi finance ${COUNT.format(posting.served_count)} kali, terakhir ${formatDateTime(posting.last_served_at)}.`
            : 'Belum pernah ditarik aplikasi finance.';

    return posting.acknowledged_at
        ? `${served} Hasilnya dikonfirmasi ${formatDateTime(posting.acknowledged_at)}.`
        : served;
}

function DeliveryTable({ deliveries }: { deliveries: Delivery[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Klien integrasi</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Percobaan</TableHead>
                        <TableHead>Kode jawaban</TableHead>
                        <TableHead>Terakhir dicoba</TableHead>
                        <TableHead>Keterangan</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {deliveries.map((delivery, index) => {
                        const status = deliveryStatus(delivery.status);

                        return (
                            <TableRow key={`${delivery.client}-${index}`}>
                                <TableCell className="font-medium">
                                    {delivery.client}
                                </TableCell>
                                <TableCell>
                                    <Badge variant={status.variant}>
                                        {status.label}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {delivery.attempts}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {delivery.last_status_code ?? '—'}
                                </TableCell>
                                <TableCell>
                                    {formatDateTime(delivery.last_attempt_at)}
                                </TableCell>
                                <TableCell className="min-w-64 whitespace-normal">
                                    {delivery.status === 'delivered' ? (
                                        `Diterima ${formatDateTime(delivery.delivered_at)}`
                                    ) : (
                                        <>
                                            {delivery.next_attempt_at && (
                                                <span className="block">
                                                    Dicoba lagi{' '}
                                                    {formatDateTime(
                                                        delivery.next_attempt_at,
                                                    )}
                                                </span>
                                            )}
                                            {delivery.last_error && (
                                                <span className="block text-destructive">
                                                    {delivery.last_error}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

function EventTable({ events }: { events: PostingEvent[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Waktu</TableHead>
                        <TableHead>Peristiwa</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Pelaku</TableHead>
                        <TableHead>Keterangan</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {events.map((event, index) => (
                        <TableRow key={`${event.created_at}-${index}`}>
                            <TableCell className="text-muted-foreground">
                                {formatDateTime(event.created_at)}
                            </TableCell>
                            <TableCell className="font-medium">
                                {eventLabel(event)}
                            </TableCell>
                            <TableCell>{transition(event)}</TableCell>
                            <TableCell>{event.actor ?? 'Sistem'}</TableCell>
                            <TableCell className="min-w-64 whitespace-normal">
                                {eventNote(event) || '—'}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function PostingHistory({ detail }: { detail: PostingDetail }) {
    return (
        <section className="space-y-4">
            <div className="space-y-1">
                <h3 className="text-sm font-semibold">Riwayat</h3>
                <p className="text-sm text-muted-foreground">
                    {servedSummary(detail)}
                </p>
            </div>
            {detail.deliveries.length > 0 && (
                <div className="space-y-2">
                    <h4 className="text-sm font-medium">
                        Pengiriman ke aplikasi finance
                    </h4>
                    <DeliveryTable deliveries={detail.deliveries} />
                </div>
            )}
            <div className="space-y-2">
                <h4 className="text-sm font-medium">Peristiwa</h4>
                {detail.events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada peristiwa yang tercatat.
                    </p>
                ) : (
                    <EventTable events={detail.events} />
                )}
            </div>
        </section>
    );
}

function DetailSkeleton() {
    return (
        <div
            className="space-y-3"
            role="status"
            aria-label="Memuat rincian posting"
        >
            <Skeleton className="h-4 w-40" />
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-40 w-full" />
        </div>
    );
}

function PostingDetailCard({
    posting,
    detail,
    error,
    canManage,
    busy,
    now,
    onRetry,
    onClose,
    onRevalidate,
    onMarkManual,
}: {
    posting: Posting | null;
    detail: PostingDetail | null;
    error: string;
    canManage: boolean;
    busy: boolean;
    now: number;
    onRetry: () => void;
    onClose: () => void;
    onRevalidate: (posting: Posting) => void;
    onMarkManual: (posting: Posting) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {posting
                        ? `Posting ${posting.posting_id}`
                        : 'Rincian posting'}
                </CardTitle>
                <CardAction className="flex items-center gap-2">
                    {posting && canManage && isMarkable(posting.status) && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onMarkManual(posting)}
                        >
                            <NotebookPen />
                            Tandai manual
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Tutup rincian"
                        onClick={onClose}
                    >
                        <X />
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-6">
                {posting && <PostingSummary posting={posting} now={now} />}
                {posting && (
                    <StatusNote
                        posting={posting}
                        detail={detail}
                        canManage={canManage}
                    />
                )}
                {error && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>Rincian belum dapat dimuat</AlertTitle>
                        <AlertDescription>
                            <p>{error}</p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onRetry}
                            >
                                Muat ulang
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                {detail ? (
                    <>
                        <SourceDocumentSection
                            source={detail.source_document}
                        />
                        <section className="space-y-3">
                            <h3 className="text-sm font-semibold">
                                Pemeriksaan posting
                            </h3>
                            <PostingCheck
                                lines={detail.lines}
                                problems={detail.problems}
                                currencyCode={detail.currency_code}
                                currencyDecimals={detail.currency_decimals}
                                action={
                                    canManage && detail.status === 'held' ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={busy}
                                            onClick={() => onRevalidate(detail)}
                                        >
                                            <RefreshCw />
                                            {busy
                                                ? 'Memvalidasi ulang…'
                                                : 'Validasi ulang'}
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </section>
                        <PostingHistory detail={detail} />
                    </>
                ) : (
                    !error && <DetailSkeleton />
                )}
            </CardContent>
        </Card>
    );
}

function MarkManualDialog({
    posting,
    detail,
    onClose,
    onDone,
    onConflict,
}: {
    posting: Posting;
    detail: PostingDetail | null;
    onClose: () => void;
    onDone: (updated: Posting) => void;
    onConflict: () => void;
}) {
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const deliveries =
        detail !== null && detail.id === posting.id
            ? detail.deliveries.length
            : 0;
    // Posting `pending` yang sudah sampai ke aplikasi finance tetap boleh ditandai, tetapi
    // mungkin sudah dibukukan di sana walau belum dikonfirmasi.
    const reached =
        posting.status === 'pending' &&
        (posting.served_count > 0 || deliveries > 0);

    const save = async () => {
        const text = reason.trim();

        if (text === '') {
            return;
        }

        setSaving(true);
        setErrors({});

        try {
            const result = await apiJson<{ data: Posting }>(
                `/api/v1/finance-postings/${posting.id}/mark-manual`,
                { method: 'POST', body: JSON.stringify({ reason: text }) },
            );
            onDone(result.data);
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);

                // Selain alasan yang belum sah, 403 dan 422 berarti posting ini tidak dapat
                // ditandai dari dialog ini: izinnya tidak ada, atau statusnya sudah berubah.
                if (
                    caught.status === 403 ||
                    (caught.status === 422 && !caught.errors.reason)
                ) {
                    onConflict();
                }
            }

            toast.error(actionError(caught, 'Posting belum ditandai manual.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size="compact">
                <DialogHeader>
                    <DialogTitle>Tandai posting manual</DialogTitle>
                    <DialogDescription>
                        Posting {posting.posting_id} tidak akan dikirim ke
                        aplikasi finance; jurnalnya dibuat sendiri di sana.
                        Tanggal dan nilai posting tidak berubah, dan tanda ini
                        tidak dapat dibatalkan.
                    </DialogDescription>
                </DialogHeader>
                <DialogBody className="space-y-4">
                    {reached ? (
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertTitle>Mungkin sudah dibukukan</AlertTitle>
                            <AlertDescription>
                                <p>
                                    {posting.served_count > 0
                                        ? `Aplikasi finance sudah menarik posting ini ${COUNT.format(posting.served_count)} kali, terakhir ${formatDateTime(posting.last_served_at)}, tetapi belum mengonfirmasi hasilnya.`
                                        : 'Posting ini sudah dikirim ke aplikasi finance, tetapi hasilnya belum dikonfirmasi.'}{' '}
                                    Periksa di aplikasi finance lebih dulu
                                    supaya jurnalnya tidak tercatat dua kali.
                                </p>
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {posting.status === 'held'
                                ? 'Bila masalahnya dapat diperbaiki, misalnya akun yang belum dipetakan, perbaiki lalu pilih Validasi ulang supaya posting ini tetap dikirim otomatis.'
                                : posting.status === 'rejected'
                                  ? 'Aplikasi finance sudah menolak posting ini. Setelah ditandai manual, bukukan transaksinya sendiri di aplikasi finance pada periode yang masih terbuka.'
                                  : 'Aplikasi finance belum pernah menarik posting ini. Setelah ditandai manual, posting ini tidak akan disajikan kepadanya.'}
                        </p>
                    )}
                    <Field data-invalid={Boolean(errors.reason)}>
                        <Textarea
                            label="Alasan"
                            required
                            rows={4}
                            maxLength={500}
                            value={reason}
                            aria-invalid={Boolean(errors.reason)}
                            onChange={(event) => setReason(event.target.value)}
                        />
                        <FieldDescription>
                            Dicatat di riwayat posting bersama nama Anda,
                            misalnya nomor jurnal yang dibuat di aplikasi
                            finance.
                        </FieldDescription>
                        <FieldError>{errors.reason?.[0]}</FieldError>
                    </Field>
                </DialogBody>
                <DialogFooter>
                    <DialogAction
                        type="button"
                        disabled={saving || reason.trim() === ''}
                        onClick={save}
                    >
                        {saving ? 'Menyimpan…' : 'Tandai manual'}
                    </DialogAction>
                    <DialogCancel />
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function FinancePostings({
    canManage,
    filters,
    statuses,
    postingTypes,
    legalEntities,
    counts,
    postings,
}: Props) {
    const now = useNow();
    // Tidak ada baris terpilih saat halaman dibuka; rincian muncul setelah pengguna memilih.
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [detail, setDetail] = useState<PostingDetail | null>(null);
    const [detailError, setDetailError] = useState<{
        id: string;
        message: string;
    } | null>(null);
    const [detailVersion, setDetailVersion] = useState(0);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [marking, setMarking] = useState<Posting | null>(null);

    useEffect(() => {
        if (selectedId === null) {
            return;
        }

        const controller = new AbortController();

        apiJson<{ data: PostingDetail }>(
            `/api/v1/finance-postings/${selectedId}`,
            { signal: controller.signal },
        )
            .then((result) => {
                setDetail(result.data);
                setDetailError(null);
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setDetailError({
                        id: selectedId,
                        message: errorText(
                            caught,
                            'Rincian posting belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => controller.abort();
    }, [selectedId, detailVersion]);

    // Rincian dan galat hanya berlaku untuk baris yang sedang dipilih; sisa pilihan sebelumnya
    // tidak pernah tampil di bawah baris lain.
    const current = detail !== null && detail.id === selectedId ? detail : null;
    const currentError =
        detailError !== null && detailError.id === selectedId
            ? detailError.message
            : '';
    const selected =
        current ??
        postings.data.find((posting) => posting.id === selectedId) ??
        null;
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const filtered = Object.values(filters).some(Boolean);

    const applyFilters = (next: Filters) => {
        setSelectedId(null);
        router.get(PAGE_URL, toQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? 'Saringan belum dapat dipakai.',
                ),
        });
    };

    const visitPage = (url: string | null) => {
        if (url === null) {
            return;
        }

        setSelectedId(null);
        router.visit(url, { preserveScroll: true });
    };

    const refresh = (updated?: Posting) => {
        if (updated) {
            setDetail((existing) =>
                existing !== null && existing.id === updated.id
                    ? { ...existing, ...updated }
                    : existing,
            );
        }

        setDetailVersion((version) => version + 1);
        router.reload({ only: ['postings', 'counts'] });
    };

    const revalidate = async (posting: Posting) => {
        setSelectedId(posting.id);
        setBusyId(posting.id);

        try {
            const result = await apiJson<{ data: Posting }>(
                `/api/v1/finance-postings/${posting.id}/revalidate`,
                { method: 'POST' },
            );
            refresh(result.data);

            if (result.data.status === 'held') {
                toast.warning(
                    `Posting ${result.data.posting_id} masih tertahan: ${COUNT.format(result.data.problem_count)} masalah belum selesai.`,
                );
            } else {
                toast.success(
                    `Posting ${result.data.posting_id} divalidasi ulang. Status sekarang: ${statusLabel(result.data.status)}.`,
                );
            }
        } catch (caught) {
            // 422 berarti statusnya sudah berubah di tempat lain, misalnya konfirmasi aplikasi
            // finance tiba lebih dulu; tampilkan keadaan terbarunya.
            if (caught instanceof CoreApiError && caught.status === 422) {
                refresh();
            }

            toast.error(actionError(caught, 'Validasi ulang belum berhasil.'));
        } finally {
            setBusyId(null);
        }
    };

    const openMarkManual = (posting: Posting) => {
        setSelectedId(posting.id);
        setMarking(posting);
    };

    return (
        <>
            <Head title="Pantau posting" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Pantau posting"
                    description="Posting jurnal yang diterbitkan app untuk aplikasi finance: statusnya, masalahnya, dan tindak lanjutnya."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Daftar posting</CardTitle>
                        <CardDescription>
                            Posting tertahan diperbaiki di CoreERP lalu
                            divalidasi ulang. Posting yang ditolak dibukukan di
                            aplikasi finance lalu ditandai manual. Tanggal dan
                            nilai posting tidak diubah di layar ini.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <PostingFilters
                            key={JSON.stringify(filters)}
                            filters={filters}
                            statuses={statuses}
                            postingTypes={postingTypes}
                            legalEntities={legalEntities}
                            counts={counts}
                            onApply={applyFilters}
                        />
                        {postings.data.length === 0 ? (
                            <PostingsEmpty
                                total={total}
                                filtered={filtered}
                                onReset={() => applyFilters(NO_FILTERS)}
                            />
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nomor posting</TableHead>
                                            <TableHead>Jenis</TableHead>
                                            <TableHead>Entitas legal</TableHead>
                                            <TableHead>
                                                Tanggal posting
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Nilai
                                            </TableHead>
                                            <TableHead>Umur</TableHead>
                                            <TableHead>Status</TableHead>
                                            {canManage && (
                                                <TableHead className="w-12">
                                                    <span className="sr-only">
                                                        Aksi
                                                    </span>
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {postings.data.map((posting) => (
                                            <PostingRow
                                                key={posting.id}
                                                posting={posting}
                                                selected={
                                                    posting.id === selectedId
                                                }
                                                canManage={canManage}
                                                busy={busyId === posting.id}
                                                now={now}
                                                onSelect={setSelectedId}
                                                onRevalidate={revalidate}
                                                onMarkManual={openMarkManual}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                    {postings.last_page > 1 && (
                        <CardFooter className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                            <span>
                                {postings.from}–{postings.to} dari{' '}
                                {postings.total} posting
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!postings.prev_page_url}
                                    onClick={() =>
                                        visitPage(postings.prev_page_url)
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!postings.next_page_url}
                                    onClick={() =>
                                        visitPage(postings.next_page_url)
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </div>
                        </CardFooter>
                    )}
                </Card>
                {selectedId !== null && (
                    <PostingDetailCard
                        posting={selected}
                        detail={current}
                        error={currentError}
                        canManage={canManage}
                        busy={busyId === selectedId}
                        now={now}
                        onRetry={() =>
                            setDetailVersion((version) => version + 1)
                        }
                        onClose={() => setSelectedId(null)}
                        onRevalidate={revalidate}
                        onMarkManual={openMarkManual}
                    />
                )}
            </main>
            {marking && (
                <MarkManualDialog
                    key={marking.id}
                    posting={marking}
                    detail={current}
                    onClose={() => setMarking(null)}
                    onDone={(updated) => {
                        setMarking(null);
                        refresh(updated);
                        toast.success(
                            `Posting ${updated.posting_id} ditandai manual.`,
                        );
                    }}
                    onConflict={() => {
                        setMarking(null);
                        refresh();
                    }}
                />
            )}
        </>
    );
}

FinancePostings.layout = {
    breadcrumbs: [
        { title: 'Pantau posting', href: PAGE_URL },
    ] satisfies BreadcrumbItem[],
};
