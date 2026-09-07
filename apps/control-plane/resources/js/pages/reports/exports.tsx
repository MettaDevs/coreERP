import { Head } from '@inertiajs/react';
import { Download, Trash2 } from 'lucide-react';
import { useEffect, useSyncExternalStore } from 'react';
import { toast } from 'sonner';

import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Progress } from '@apperp/ui/progress';
import type { BreadcrumbItem } from '@/types/navigation';
import {
    deleteExport,
    downloadExport,
    FORMAT_LABEL,
    formatBytes,
    formatTime,
    isActive,
    STATUS_LABEL,
} from '@/lib/reports';
import type { ReportExport } from '@/lib/reports';
import {
    getExports,
    refreshExports,
    subscribeExports,
} from '@/lib/export-watch';
import Heading from '@/components/heading';

type Props = { exports: ReportExport[] };

/**
 * Riwayat ekspor milik pengguna dari semua app: yang sedang dikerjakan, yang siap
 * diunduh sampai masa simpannya lewat, dan yang gagal beserta alasannya. Daftar ini
 * dibagikan dengan tray di header, jadi keduanya selalu menunjukkan hal yang sama.
 */
export default function ReportExports({ exports: initial }: Props) {
    const live = useSyncExternalStore(subscribeExports, getExports, () => []);
    const exports = live.length > 0 ? live : initial;

    useEffect(() => {
        void refreshExports();
    }, []);

    const remove = async (item: ReportExport) => {
        try {
            await deleteExport(item.id);
            await refreshExports();
        } catch (caught) {
            toast.error(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Ekspor belum dapat dihapus.',
            );
        }
    };

    const columns: DataTableColumn<ReportExport>[] = [
        {
            id: 'report',
            header: 'Laporan',
            cell: (item) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">{item.report_name}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {item.layout_name}
                    </p>
                </div>
            ),
            sortValue: (item) => item.report_name,
            width: 240,
        },
        {
            id: 'format',
            header: 'Format',
            cell: (item) => FORMAT_LABEL[item.format],
            width: 90,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (item) =>
                isActive(item) ? (
                    <div className="space-y-1">
                        <span className="text-sm">
                            {STATUS_LABEL[item.status]}
                        </span>
                        <Progress
                            value={item.progress}
                            aria-label="Kemajuan ekspor"
                        />
                    </div>
                ) : (
                    <Badge
                        variant={
                            item.status === 'failed'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {STATUS_LABEL[item.status]}
                    </Badge>
                ),
            width: 150,
        },
        {
            id: 'requested',
            header: 'Diminta',
            cell: (item) => formatTime(item.created_at),
            sortValue: (item) => item.created_at,
            width: 170,
        },
        {
            id: 'size',
            header: 'Ukuran',
            cell: (item) => formatBytes(item.file_size),
            align: 'right',
            width: 90,
        },
        {
            id: 'expires',
            header: 'Tersedia sampai',
            cell: (item) => formatTime(item.expires_at),
            width: 170,
        },
        {
            id: 'actions',
            header: '',
            cell: (item) => (
                <div className="flex justify-end gap-2">
                    {item.status === 'done' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                void downloadExport(item).catch(
                                    (caught: Error) =>
                                        toast.error(
                                            caught.message ||
                                                'Berkas belum dapat diunduh.',
                                        ),
                                )
                            }
                        >
                            <Download />
                            Unduh
                        </Button>
                    )}
                    {item.status === 'failed' && item.failure_message && (
                        <span className="max-w-72 text-xs text-destructive">
                            {item.failure_message}
                        </span>
                    )}
                    {!isActive(item) && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            aria-label="Hapus ekspor"
                            onClick={() => void remove(item)}
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            ),
            width: 260,
        },
    ];

    return (
        <>
            <Head title="Ekspor laporan" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Ekspor laporan"
                    description="Dokumen yang Anda cetak atau ekspor dari aplikasi mana pun, terbaru lebih dulu."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Riwayat ekspor</CardTitle>
                        <CardDescription>
                            Berkas dihapus otomatis setelah masa simpannya
                            lewat; ekspor ulang kapan saja untuk data terbaru.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {exports.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>Belum ada ekspor</EmptyTitle>
                                    <EmptyDescription>
                                        Tekan Cetak atau Ekspor di dalam
                                        aplikasi; hasilnya muncul di sini.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <DataTable
                                columns={columns}
                                data={exports}
                                getRowKey={(item) => item.id}
                            />
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

ReportExports.layout = {
    breadcrumbs: [
        { title: 'Ekspor laporan', href: '/reports/exports' },
    ] satisfies BreadcrumbItem[],
};
