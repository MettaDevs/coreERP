import {
    Check,
    CheckCircle2,
    Clock,
    FileText,
    MapPin,
    Package,
    User,
    X,
    XCircle,
} from 'lucide-react';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { TableCell, TableRow } from '@apperp/ui/table';
import type { VendorPrequalificationDetail } from './VendorPrequalificationFormDialog';

const docColumns = [
    { id: 'no', header: 'No.', cell: () => null },
    { id: 'nama_dokumen', header: 'Uraian Dokumen', cell: () => null },
    { id: 'status', header: 'Status', cell: () => null },
    { id: 'keterangan', header: 'Keterangan', cell: () => null },
];

const itemColumns = [
    { id: 'no', header: 'No.', cell: () => null },
    { id: 'jenis_item', header: 'Jenis Item', cell: () => null },
    { id: 'kode_item', header: 'Kode Item', cell: () => null },
    { id: 'nama_item', header: 'Nama Item', cell: () => null },
    { id: 'satuan', header: 'Satuan', cell: () => null },
    { id: 'kesediaan', header: 'Kesediaan', cell: () => null },
];

type Props = {
    open: boolean;
    onClose: () => void;
    data: VendorPrequalificationDetail | null;
    loading?: boolean;
    error?: string | null;
    onRetry?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
};

function formatDisplayDate(dateStr: string): string {
    if (!dateStr) {
        return '—';
    }

    if (dateStr.includes('/')) {
        return dateStr;
    }

    const parts = dateStr.split('-');

    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    return dateStr;
}

export function VendorPrequalificationDetailDialog({
    open,
    onClose,
    data,
    loading = false,
    error = null,
    onRetry,
    onEdit,
    onDelete,
}: Props) {
    if (!open) {
        return null;
    }

    const getStatusBadge = (status: string) => {
        if (status === 'Lolos') {
            return (
                <Badge className="bg-emerald-600 font-medium text-white hover:bg-emerald-600/90">
                    <CheckCircle2 className="mr-1 h-3 w-3" />
                    Lolos
                </Badge>
            );
        }

        if (status === 'Tidak Lolos') {
            return (
                <Badge variant="destructive" className="font-medium">
                    <XCircle className="mr-1 h-3 w-3" />
                    Tidak Lolos
                </Badge>
            );
        }

        return (
            <Badge
                variant="secondary"
                className="border-amber-500/30 bg-amber-500/15 font-medium text-amber-700 dark:text-amber-400"
            >
                <Clock className="mr-1 h-3 w-3" />
                {status || 'Dalam Proses'}
            </Badge>
        );
    };

    const documents = data?.documents ?? [];
    const items = data?.items ?? [];

    return (
        <PageDialog
            open={open}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <PageDialogContent
                aria-describedby={undefined}
                title="Prakualifikasi Vendor"
                recordTitle={
                    data
                        ? `${data.nomor_prakualifikasi} · ${data.nama_vendor}`
                        : 'Memuat…'
                }
                onEdit={data ? onEdit : undefined}
                onDelete={data ? onDelete : undefined}
            >
                <PageDialogToolbar>
                    {data && onEdit && (
                        <ActionButton
                            action="edit"
                            type="button"
                            onClick={onEdit}
                        />
                    )}
                    {data && onDelete && (
                        <ActionButton
                            action="delete"
                            type="button"
                            onClick={onDelete}
                        />
                    )}
                    <ActionButton
                        action="cancel"
                        type="button"
                        onClick={onClose}
                    />
                </PageDialogToolbar>

                <PageDialogBody className="space-y-6 text-sm">
                    {loading ? (
                        <div className="text-muted-foreground py-8 text-center text-xs">
                            Memuat rincian prakualifikasi vendor…
                        </div>
                    ) : error ? (
                        <div className="border-destructive/40 bg-destructive/10 text-destructive space-y-2 rounded-md border p-4 text-xs">
                            <p className="font-semibold">{error}</p>
                            {onRetry && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    type="button"
                                    onClick={onRetry}
                                >
                                    Coba lagi
                                </Button>
                            )}
                        </div>
                    ) : data ? (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
                                <div className="text-muted-foreground font-mono text-xs">
                                    Nomor Dokumen:{' '}
                                    <strong className="text-foreground">
                                        {data.nomor_prakualifikasi}
                                    </strong>{' '}
                                    · Tanggal:{' '}
                                    {formatDisplayDate(
                                        data.tanggal_prakualifikasi,
                                    )}
                                </div>
                                <div>
                                    {getStatusBadge(data.status_kualifikasi)}
                                </div>
                            </div>

                            <div className="bg-muted/20 grid grid-cols-1 gap-4 rounded-lg border p-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <div className="text-muted-foreground text-xs font-semibold uppercase tracking-wider">
                                        Identitas Vendor/Supplier
                                    </div>
                                    <div className="text-foreground text-base font-bold">
                                        {data.nama_vendor}
                                    </div>
                                    <div className="text-muted-foreground font-mono text-xs">
                                        Kode: {data.kode_vendor}
                                        {data.jenis_vendor
                                            ? ` · ${data.jenis_vendor}`
                                            : ''}
                                    </div>
                                    <div className="text-muted-foreground mt-2 flex items-center gap-2 text-xs">
                                        <MapPin className="h-3.5 w-3.5 shrink-0" />
                                        <span>{data.alamat || '—'}</span>
                                    </div>
                                    <div className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <User className="h-3.5 w-3.5 shrink-0" />
                                        <span>
                                            Nama PIC Vendor/Supp:{' '}
                                            {data.nama_pic || '—'}
                                        </span>
                                    </div>
                                </div>

                                <div className="space-y-2 border-t pt-2 md:border-l md:border-t-0 md:pl-4 md:pt-0">
                                    <div className="text-muted-foreground text-xs font-semibold uppercase tracking-wider">
                                        Legalitas & Status Rekanan
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <span className="text-muted-foreground">
                                                Status Vendor:
                                            </span>
                                            <div className="text-foreground font-semibold">
                                                {data.status_vendor ||
                                                    'Vendor Resmi'}
                                            </div>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">
                                                Badan Hukum:
                                            </span>
                                            <div className="text-foreground font-medium">
                                                {data.status_badan_hukum || '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">
                                                NPWP/KTP:
                                            </span>
                                            <div className="text-foreground font-mono">
                                                {data.npwp_ktp || '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">
                                                Telepon/HP:
                                            </span>
                                            <div className="text-foreground font-mono">
                                                {data.telepon || '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">
                                                Email:
                                            </span>
                                            <div className="text-foreground">
                                                {data.email || '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">
                                                Waktu Kesediaan Item:
                                            </span>
                                            <div className="text-foreground font-medium">
                                                {data.waktu_kesediaan_item ||
                                                    '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">
                                                Jumlah Karyawan:
                                            </span>
                                            <div className="text-foreground font-medium">
                                                {data.jumlah_karyawan !==
                                                    null &&
                                                data.jumlah_karyawan !==
                                                    undefined
                                                    ? `${data.jumlah_karyawan} Orang`
                                                    : '—'}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <h4 className="flex items-center gap-2 text-sm font-semibold">
                                        <FileText className="text-primary h-4 w-4" />
                                        Kelengkapan Dokumen
                                    </h4>
                                    <span className="text-muted-foreground text-xs">
                                        {documents.filter((d) => d.ada).length}{' '}
                                        dari {documents.length} Dokumen
                                        Terpenuhi
                                    </span>
                                </div>

                                <div className="overflow-hidden rounded-md border">
                                    <DataTable
                                        scrollMode="inline"
                                        showRowNumbers={false}
                                        columns={docColumns}
                                        data={documents}
                                        getRowKey={(doc) =>
                                            doc.id ||
                                            `${doc.kategori}-${doc.urutan}-${doc.nama_dokumen}`
                                        }
                                        renderRows={() => (
                                            <>
                                                {documents.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell
                                                            colSpan={4}
                                                            className="text-muted-foreground py-4 text-center text-xs"
                                                        >
                                                            Tidak ada catatan
                                                            dokumen.
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    documents.map(
                                                        (doc, idx) => (
                                                            <TableRow
                                                                key={
                                                                    doc.id ||
                                                                    idx
                                                                }
                                                                className="h-8 text-xs"
                                                            >
                                                                <TableCell className="text-center font-mono font-medium">
                                                                    {idx + 1}
                                                                </TableCell>
                                                                <TableCell className="font-medium">
                                                                    {
                                                                        doc.nama_dokumen
                                                                    }
                                                                </TableCell>
                                                                <TableCell className="text-center">
                                                                    {doc.ada ? (
                                                                        <span className="inline-flex items-center rounded bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                                            <Check className="mr-1 h-3 w-3" />{' '}
                                                                            Ada
                                                                        </span>
                                                                    ) : (
                                                                        <span className="inline-flex items-center rounded bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                                                                            <X className="mr-1 h-3 w-3" />{' '}
                                                                            Tidak
                                                                            Ada
                                                                        </span>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-muted-foreground">
                                                                    {doc.keterangan ||
                                                                        '—'}
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )
                                                )}
                                            </>
                                        )}
                                    />
                                </div>
                            </div>

                            {data.catatan && (
                                <div className="bg-muted/10 rounded-md border p-3">
                                    <div className="text-muted-foreground mb-1 text-xs font-semibold">
                                        Catatan Evaluasi:
                                    </div>
                                    <p className="text-foreground whitespace-pre-wrap text-xs">
                                        {data.catatan}
                                    </p>
                                </div>
                            )}

                            <div className="space-y-3">
                                <h4 className="flex items-center gap-2 text-sm font-semibold">
                                    <Package className="text-primary h-4 w-4" />
                                    Daftar Item yang Tersedia
                                </h4>
                                <div className="overflow-hidden rounded-md border">
                                    <DataTable
                                        scrollMode="inline"
                                        showRowNumbers={false}
                                        columns={itemColumns}
                                        data={items}
                                        getRowKey={(item) =>
                                            item.id ||
                                            `${item.urutan}-${item.nama_item}`
                                        }
                                        renderRows={() => (
                                            <>
                                                {items.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell
                                                            colSpan={6}
                                                            className="text-muted-foreground py-4 text-center text-xs"
                                                        >
                                                            Tidak ada data item
                                                            barang/jasa.
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    items.map((item, idx) => (
                                                        <TableRow
                                                            key={item.id || idx}
                                                            className="h-8 text-xs"
                                                        >
                                                            <TableCell className="text-center font-mono font-medium">
                                                                {idx + 1}
                                                            </TableCell>
                                                            <TableCell className="text-center">
                                                                {item.jenis_item ||
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground text-center font-mono">
                                                                {item.kode_item ||
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell className="text-foreground text-center font-semibold">
                                                                {item.nama_item}
                                                            </TableCell>
                                                            <TableCell className="text-center">
                                                                {item.satuan ||
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell className="text-center font-mono">
                                                                {item.kesediaan ||
                                                                    '—'}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))
                                                )}
                                            </>
                                        )}
                                    />
                                </div>
                            </div>
                        </>
                    ) : null}
                </PageDialogBody>
            </PageDialogContent>
        </PageDialog>
    );
}
