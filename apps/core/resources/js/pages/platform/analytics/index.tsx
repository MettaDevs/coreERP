import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import {
    Card,
    CardAction,
    CardContent,
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
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DashboardFormSheet } from '@/components/analytics/dashboard-form-sheet';
import Heading from '@/components/heading';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import type {
    DashboardAbilities,
    DashboardSummary,
} from '@/lib/analytics/types';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    dashboards: DashboardSummary[];
    abilities: DashboardAbilities;
};

/**
 * Daftar dasbor (`/analytics`, area 7.1): dasbor milik pengguna dan yang dibagikan kepadanya, urut nama dari
 * server, dengan aksi Buat dasbor bagi yang boleh membuat. Mengubah dan mengarsipkan dilakukan di halaman
 * dasbornya, tempat hak ubah tiap dasbor diketahui.
 */
export default function AnalyticsDashboards({ dashboards, abilities }: Props) {
    const formatTime = useDateTimeFormat();
    const [creating, setCreating] = useState(false);

    const columns: DataTableColumn<DashboardSummary>[] = [
        {
            id: 'name',
            header: 'Nama',
            width: 320,
            minWidth: 160,
            sortValue: (dashboard) => dashboard.name.toLowerCase(),
            cell: (dashboard) => (
                <div className="min-w-0">
                    <Link
                        href={`/analytics/dashboards/${dashboard.id}`}
                        className="block truncate font-medium underline-offset-4 hover:underline focus-visible:underline"
                    >
                        {dashboard.name}
                    </Link>
                    {dashboard.description && (
                        <p className="truncate text-xs text-muted-foreground">
                            {dashboard.description}
                        </p>
                    )}
                </div>
            ),
        },
        {
            id: 'shared',
            header: 'Dibagikan',
            width: 120,
            cell: (dashboard) =>
                dashboard.shared ? (
                    <Badge variant="secondary">Bersama</Badge>
                ) : (
                    <Badge variant="outline">Pribadi</Badge>
                ),
        },
        {
            id: 'owner',
            header: 'Pemilik',
            width: 180,
            sortValue: (dashboard) =>
                dashboard.mine ? '' : (dashboard.owner_name ?? ''),
            cell: (dashboard) =>
                dashboard.mine ? 'Anda' : (dashboard.owner_name ?? '—'),
        },
        {
            id: 'widgets',
            header: 'Isi',
            width: 100,
            align: 'right',
            sortValue: (dashboard) => dashboard.widget_count,
            cell: (dashboard) => (
                <span className="tabular-nums">
                    {dashboard.widget_count} bagian
                </span>
            ),
        },
        {
            id: 'updated',
            header: 'Diubah',
            width: 180,
            sortValue: (dashboard) => dashboard.updated_at ?? '',
            cell: (dashboard) => formatTime(dashboard.updated_at),
        },
    ];

    return (
        <>
            <Head title="Dasbor" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Dasbor"
                    description="Halaman berisi angka, grafik, dan tabel yang Anda pantau. Angka di setiap dasbor dihitung sesuai akses orang yang membukanya."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Daftar dasbor</CardTitle>
                        {abilities.create && (
                            <CardAction>
                                <ActionButton
                                    action="create"
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    Buat dasbor
                                </ActionButton>
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent>
                        {dashboards.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyTitle>Belum ada dasbor</EmptyTitle>
                                    <EmptyDescription>
                                        {abilities.create
                                            ? 'Buat dasbor untuk mengumpulkan angka yang sering Anda pantau di satu halaman.'
                                            : 'Belum ada dasbor yang dibagikan kepada Anda.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            // Lebar kolom ditambah kolom nomor baris: di layar sempit tabel menggulir, tidak terpotong.
                            <div className="overflow-x-auto">
                                <div
                                    style={{
                                        minWidth: columns.reduce(
                                            (sum, column) =>
                                                sum + (column.width ?? 160),
                                            44,
                                        ),
                                    }}
                                >
                                    <DataTable
                                        columns={columns}
                                        data={dashboards}
                                        getRowKey={(dashboard) => dashboard.id}
                                        emptyMessage="Belum ada dasbor."
                                    />
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
            {creating && (
                <DashboardFormSheet
                    dashboard={null}
                    abilities={abilities}
                    onClose={() => setCreating(false)}
                    onSaved={(dashboard) => {
                        toast.success(`Dasbor "${dashboard.name}" dibuat.`);
                        router.visit(`/analytics/dashboards/${dashboard.id}`);
                    }}
                />
            )}
        </>
    );
}

AnalyticsDashboards.layout = {
    breadcrumbs: [
        { title: 'Dasbor', href: '/analytics' },
    ] satisfies BreadcrumbItem[],
};
