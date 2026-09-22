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
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, router } from '@inertiajs/react';
import { Download, FileUp, Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type AccountType = 'balance_sheet' | 'profit_loss';
type Account = {
    id: string;
    external_id: string;
    code: string;
    name: string;
    type: AccountType;
    active: boolean;
    legal_entity_id: string | null;
    synced_at: string | null;
};
type LegalEntity = { id: string; name: string; company_code: string | null };
type RejectedRow = { line: number; external_id: string | null; reason: string };
type ImportRecord = {
    id: string;
    file_name: string;
    legal_entity_id: string | null;
    status: 'applied' | 'rejected';
    created_count: number;
    updated_count: number;
    unchanged_count: number;
    missing_count: number;
    rejected_count: number;
    rejected_rows: RejectedRow[];
    imported_by: string | null;
    created_at: string | null;
};
type Report = {
    status: 'preview' | 'applied' | 'rejected';
    import_id: string | null;
    created: {
        external_id: string;
        code: string;
        name: string;
        type: AccountType;
    }[];
    updated: {
        external_id: string;
        code: string;
        changes: Record<string, { from: unknown; to: unknown }>;
    }[];
    unchanged_count: number;
    missing: {
        id: string;
        external_id: string;
        code: string;
        name: string;
        active: boolean;
    }[];
    rejected: RejectedRow[];
};
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
type Props = {
    canManage: boolean;
    filters: { q: string; scope: string | null; status: string | null };
    legalEntities: LegalEntity[];
    accounts: Paginated<Account>;
    imports: ImportRecord[];
    header: string[];
};

const TYPE_LABEL: Record<AccountType, string> = {
    balance_sheet: 'Neraca',
    profit_loss: 'Laba rugi',
};

const FIELD_LABEL: Record<string, string> = {
    code: 'Nomor',
    name: 'Nama',
    type: 'Jenis',
    active: 'Aktif',
};

function waktu(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat('id-ID', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function nilai(value: unknown): string {
    if (typeof value === 'boolean') {
        return value ? 'ya' : 'tidak';
    }

    if (value === 'balance_sheet' || value === 'profit_loss') {
        return TYPE_LABEL[value];
    }

    return String(value ?? '—');
}

function Filters({
    filters,
    legalEntities,
}: Pick<Props, 'filters' | 'legalEntities'>) {
    const [q, setQ] = useState(filters.q);
    const go = (next: Partial<Props['filters']>) =>
        router.get(
            '/settings/finance-accounts',
            { ...filters, q, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <form
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
            onSubmit={(event) => {
                event.preventDefault();
                go({ q });
            }}
        >
            <div className="relative w-full sm:max-w-xs">
                <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input
                    aria-label="Cari nomor, nama, atau external_id"
                    placeholder="Cari nomor, nama, atau external_id"
                    className="pl-8"
                    value={q}
                    onChange={(event) => setQ(event.target.value)}
                />
            </div>
            <div className="w-full sm:w-56">
                <NativeSelect
                    label="Cakupan"
                    value={filters.scope ?? ''}
                    onChange={(event) =>
                        go({ scope: event.target.value || null })
                    }
                >
                    <NativeSelectOption value="">
                        Semua cakupan
                    </NativeSelectOption>
                    <NativeSelectOption value="all">
                        Semua entitas legal
                    </NativeSelectOption>
                    {legalEntities.map((entity) => (
                        <NativeSelectOption key={entity.id} value={entity.id}>
                            Khusus {entity.name}
                        </NativeSelectOption>
                    ))}
                </NativeSelect>
            </div>
            <div className="w-full sm:w-40">
                <NativeSelect
                    label="Status"
                    value={filters.status ?? ''}
                    onChange={(event) =>
                        go({ status: event.target.value || null })
                    }
                >
                    <NativeSelectOption value="">Semua</NativeSelectOption>
                    <NativeSelectOption value="active">
                        Aktif
                    </NativeSelectOption>
                    <NativeSelectOption value="inactive">
                        Nonaktif
                    </NativeSelectOption>
                </NativeSelect>
            </div>
            <Button type="submit" variant="outline">
                Cari
            </Button>
        </form>
    );
}

function ReportView({ report }: { report: Report }) {
    const summary: [string, number, string][] = [
        ['Baru', report.created.length, ''],
        ['Berubah', report.updated.length, ''],
        ['Tetap', report.unchanged_count, ''],
        ['Tidak ada di berkas', report.missing.length, ''],
        [
            'Ditolak',
            report.rejected.length,
            report.rejected.length
                ? 'border-destructive/40 text-destructive'
                : '',
        ],
    ];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                {summary.map(([label, count, className]) => (
                    <Badge key={label} variant="outline" className={className}>
                        {label}: {count}
                    </Badge>
                ))}
            </div>

            {report.rejected.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium text-destructive">
                        Berkas ditolak seluruhnya. Perbaiki baris berikut lalu
                        periksa ulang; tidak ada akun yang berubah.
                    </p>
                    <ul className="space-y-1 text-sm">
                        {report.rejected.map((row, index) => (
                            <li
                                key={`${row.line}-${index}`}
                                className="rounded-md border px-3 py-2"
                            >
                                <span className="font-medium">
                                    {row.line > 0
                                        ? `Baris ${row.line}`
                                        : 'Berkas'}
                                    {row.external_id
                                        ? ` · ${row.external_id}`
                                        : ''}
                                </span>
                                <span className="block text-muted-foreground">
                                    {row.reason}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {report.updated.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Akun yang berubah</p>
                    <ul className="space-y-1 text-sm">
                        {report.updated.map((row) => (
                            <li
                                key={row.external_id}
                                className="rounded-md border px-3 py-2"
                            >
                                <span className="font-medium">
                                    {row.code} · {row.external_id}
                                </span>
                                {Object.entries(row.changes).map(
                                    ([field, change]) => (
                                        <span
                                            key={field}
                                            className="block text-muted-foreground"
                                        >
                                            {FIELD_LABEL[field] ?? field}:{' '}
                                            {nilai(change.from)} →{' '}
                                            {nilai(change.to)}
                                        </span>
                                    ),
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {report.missing.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        Akun yang tidak ada di berkas
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Tidak dinonaktifkan otomatis. Bila akun ini memang sudah
                        tidak dipakai, nonaktifkan dari daftar setelah impor.
                    </p>
                    <ul className="space-y-1 text-sm">
                        {report.missing.map((row) => (
                            <li
                                key={row.id}
                                className="rounded-md border px-3 py-2"
                            >
                                {row.code} · {row.name}
                                {!row.active && ' (sudah nonaktif)'}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function ImportSheet({
    legalEntities,
    header,
    onClose,
}: {
    legalEntities: LegalEntity[];
    header: string[];
    onClose: () => void;
}) {
    const [scope, setScope] = useState('all');
    const [file, setFile] = useState<File | null>(null);
    const [report, setReport] = useState<Report | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const send = async (apply: boolean) => {
        if (!file) {
            return;
        }

        const body = new FormData();
        body.append('file', file);
        body.append('scope', scope);
        body.append('apply', apply ? '1' : '0');
        setBusy(true);
        setError('');

        try {
            const result = await apiJson<{ data: Report }>(
                '/api/v1/finance-reference-accounts/imports',
                { method: 'POST', body },
            );
            setReport(result.data);

            if (result.data.status === 'applied') {
                toast.success('Daftar akun diperbarui.');
                router.reload({ only: ['accounts', 'imports'] });
                onClose();
            }
        } catch (caught) {
            setError(
                caught instanceof CoreApiError
                    ? caught.message
                    : errorText(caught, 'Berkas belum dapat diperiksa.'),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-xl">
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>Impor daftar akun</SheetTitle>
                    <SheetDescription>
                        Berkas CSV ekspor aplikasi finance dengan kolom{' '}
                        {header.join(', ')}. Akun dicocokkan lewat external_id,
                        jadi nomor dan nama boleh berubah.
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    <FieldGroup>
                        <Field>
                            <NativeSelect
                                label="Berlaku untuk"
                                value={scope}
                                onChange={(event) => {
                                    setScope(event.target.value);
                                    setReport(null);
                                }}
                            >
                                <NativeSelectOption value="all">
                                    Semua entitas legal
                                </NativeSelectOption>
                                {legalEntities.map((entity) => (
                                    <NativeSelectOption
                                        key={entity.id}
                                        value={entity.id}
                                    >
                                        Khusus {entity.name}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldDescription>
                                Pilih entitas tertentu hanya bila entitas itu
                                memakai daftar akun sendiri di aplikasi finance.
                            </FieldDescription>
                        </Field>
                        <Field data-invalid={Boolean(error)}>
                            <FieldLabel htmlFor="berkas-akun">
                                Berkas CSV
                            </FieldLabel>
                            <Input
                                id="berkas-akun"
                                type="file"
                                accept=".csv,.txt,text/csv"
                                onChange={(event) => {
                                    setFile(event.target.files?.[0] ?? null);
                                    setReport(null);
                                }}
                            />
                            <FieldDescription>
                                Pemisah koma atau titik koma, paling besar 2 MB.
                            </FieldDescription>
                            <FieldError>{error}</FieldError>
                        </Field>
                    </FieldGroup>
                    {report && <ReportView report={report} />}
                </div>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" type="button" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!file || busy}
                        onClick={() => send(false)}
                    >
                        Periksa berkas
                    </Button>
                    <Button
                        type="button"
                        disabled={
                            !file ||
                            busy ||
                            report === null ||
                            report.rejected.length > 0
                        }
                        onClick={() => send(true)}
                    >
                        Terapkan impor
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

export default function FinanceAccounts({
    canManage,
    filters,
    legalEntities,
    accounts,
    imports,
    header,
}: Props) {
    const [importing, setImporting] = useState(false);
    const scopeName = (id: string | null) =>
        id === null
            ? 'Semua entitas'
            : (legalEntities.find((entity) => entity.id === id)?.name ?? id);

    const toggle = async (account: Account) => {
        try {
            await apiJson(`/api/v1/finance-reference-accounts/${account.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ active: !account.active }),
            });
            toast.success(
                account.active ? 'Akun dinonaktifkan.' : 'Akun diaktifkan.',
            );
            router.reload({ only: ['accounts'] });
        } catch (caught) {
            toast.error(errorText(caught, 'Status akun belum dapat diubah.'));
        }
    };

    return (
        <>
            <Head title="Daftar akun" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Daftar akun"
                    description="Akun milik aplikasi finance, dipakai untuk memetakan posting dari CoreERP."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Akun referensi</CardTitle>
                        <CardDescription>
                            Diimpor dari ekspor aplikasi finance. Pemetaan
                            menunjuk external_id, jadi mengganti nomor atau nama
                            akun di aplikasi finance tidak memutus pemetaan.
                        </CardDescription>
                        {canManage && (
                            <CardAction className="flex gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <a href="/settings/finance-accounts/template">
                                        <Download />
                                        Templat CSV
                                    </a>
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setImporting(true)}
                                >
                                    <FileUp />
                                    Impor CSV
                                </Button>
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Filters
                            key={JSON.stringify(filters)}
                            filters={filters}
                            legalEntities={legalEntities}
                        />
                        {accounts.data.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyTitle>Belum ada akun</EmptyTitle>
                                    <EmptyDescription>
                                        {filters.q ||
                                        filters.scope ||
                                        filters.status
                                            ? 'Tidak ada akun yang cocok dengan saringan ini.'
                                            : 'Impor ekspor daftar akun dari aplikasi finance untuk mulai memetakan posting.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nomor</TableHead>
                                            <TableHead>Nama</TableHead>
                                            <TableHead>Jenis</TableHead>
                                            <TableHead>Cakupan</TableHead>
                                            <TableHead>external_id</TableHead>
                                            <TableHead>Status</TableHead>
                                            {canManage && (
                                                <TableHead className="w-32 text-right">
                                                    Aksi
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {accounts.data.map((account) => (
                                            <TableRow key={account.id}>
                                                <TableCell className="font-mono">
                                                    {account.code}
                                                </TableCell>
                                                <TableCell>
                                                    {account.name}
                                                </TableCell>
                                                <TableCell>
                                                    {TYPE_LABEL[account.type]}
                                                </TableCell>
                                                <TableCell>
                                                    {scopeName(
                                                        account.legal_entity_id,
                                                    )}
                                                </TableCell>
                                                <TableCell className="font-mono text-muted-foreground">
                                                    {account.external_id}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            account.active
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {account.active
                                                            ? 'Aktif'
                                                            : 'Nonaktif'}
                                                    </Badge>
                                                </TableCell>
                                                {canManage && (
                                                    <TableCell className="text-right">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                toggle(account)
                                                            }
                                                        >
                                                            {account.active
                                                                ? 'Nonaktifkan'
                                                                : 'Aktifkan'}
                                                        </Button>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                    {accounts.last_page > 1 && (
                        <CardFooter className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                            <span>
                                {accounts.from}–{accounts.to} dari{' '}
                                {accounts.total} akun
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!accounts.prev_page_url}
                                    onClick={() =>
                                        accounts.prev_page_url &&
                                        router.visit(accounts.prev_page_url, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!accounts.next_page_url}
                                    onClick={() =>
                                        accounts.next_page_url &&
                                        router.visit(accounts.next_page_url, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </div>
                        </CardFooter>
                    )}
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Riwayat impor</CardTitle>
                        <CardDescription>
                            Sepuluh impor terakhir. Impor yang ditolak tidak
                            mengubah akun apa pun.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {imports.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum pernah ada impor.
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Waktu</TableHead>
                                            <TableHead>Berkas</TableHead>
                                            <TableHead>Cakupan</TableHead>
                                            <TableHead>Hasil</TableHead>
                                            <TableHead>Oleh</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {imports.map((record) => (
                                            <TableRow key={record.id}>
                                                <TableCell>
                                                    {waktu(record.created_at)}
                                                </TableCell>
                                                <TableCell>
                                                    {record.file_name}
                                                </TableCell>
                                                <TableCell>
                                                    {scopeName(
                                                        record.legal_entity_id,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {record.status ===
                                                    'applied' ? (
                                                        <span>
                                                            {
                                                                record.created_count
                                                            }{' '}
                                                            baru,{' '}
                                                            {
                                                                record.updated_count
                                                            }{' '}
                                                            berubah,{' '}
                                                            {
                                                                record.missing_count
                                                            }{' '}
                                                            tidak ada di berkas
                                                        </span>
                                                    ) : (
                                                        <span className="text-destructive">
                                                            Ditolak,{' '}
                                                            {
                                                                record.rejected_count
                                                            }{' '}
                                                            baris bermasalah
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {record.imported_by ?? '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
            {importing && (
                <ImportSheet
                    legalEntities={legalEntities}
                    header={header}
                    onClose={() => setImporting(false)}
                />
            )}
        </>
    );
}

FinanceAccounts.layout = {
    breadcrumbs: [
        { title: 'Daftar akun', href: '/settings/finance-accounts' },
    ] satisfies BreadcrumbItem[],
};
