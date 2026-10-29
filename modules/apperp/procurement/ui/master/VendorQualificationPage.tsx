import { CheckCircle2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { api, saveErrorMessage } from '../api';
import type { ModulePageProps } from '../App';
import { useRecordList } from '../use-record-list';
import { VendorPrequalificationDetailDialog } from './prakualifikasi/VendorPrequalificationDetailDialog';
import type { VendorPrequalificationDetail } from './prakualifikasi/VendorPrequalificationFormDialog';
import type { VendorPrequalificationSummaryRow } from './VendorPrequalificationPage';

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

export default function VendorQualificationPage(
    _props?: Partial<ModulePageProps>,
) {
    void _props;
    const [search, setSearch] = useState('');

    const list = useRecordList<VendorPrequalificationSummaryRow>(
        '/kualifikasi',
        search,
    );
    const { items, loading } = list;

    const [detailDialogOpen, setDetailDialogOpen] = useState(false);
    const [detailRecord, setDetailRecord] =
        useState<VendorPrequalificationDetail | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState<string | null>(null);
    const [activeDetailId, setActiveDetailId] = useState<string | null>(null);
    const detailAbortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        return () => {
            detailAbortRef.current?.abort();
        };
    }, []);

    const load = useCallback(() => {
        list.reload();
    }, [list]);

    const fetchDetail = useCallback(
        async (
            id: string,
        ): Promise<
            | { status: 'ok'; data: VendorPrequalificationDetail }
            | { status: 'aborted' }
            | { status: 'error'; message: string }
        > => {
            detailAbortRef.current?.abort();
            const controller = new AbortController();
            detailAbortRef.current = controller;

            setDetailLoading(true);
            setDetailError(null);

            try {
                const res = await api<{
                    status?: string;
                    data: VendorPrequalificationDetail;
                }>(`/kualifikasi/${encodeURIComponent(id)}`, {
                    signal: controller.signal,
                });

                if (controller.signal.aborted) {
                    return { status: 'aborted' };
                }

                return { status: 'ok', data: res.data };
            } catch (caught) {
                if (controller.signal.aborted) {
                    return { status: 'aborted' };
                }

                const message = saveErrorMessage(
                    caught,
                    'Rincian rekanan terkualifikasi belum dapat dimuat.',
                );
                setDetailError(message);

                return { status: 'error', message };
            } finally {
                if (!controller.signal.aborted) {
                    setDetailLoading(false);
                }
            }
        },
        [],
    );

    const handleView = async (row: VendorPrequalificationSummaryRow) => {
        setActiveDetailId(row.id);
        setDetailRecord(null);
        setDetailDialogOpen(true);

        const result = await fetchDetail(row.id);

        if (result.status === 'ok') {
            setDetailRecord(result.data);
        }
    };

    const handleRetryDetail = async () => {
        if (!activeDetailId) {
            return;
        }

        const result = await fetchDetail(activeDetailId);

        if (result.status === 'ok') {
            setDetailRecord(result.data);
        }
    };

    const columns: DataTableColumn<VendorPrequalificationSummaryRow>[] =
        useMemo(
            () => [
                {
                    id: 'info_prakualifikasi',
                    header: 'Info Prakualifikasi',
                    cell: (row) => (
                        <div className="space-y-0.5">
                            <div className="text-muted-foreground text-xs font-medium">
                                {formatDisplayDate(row.tanggal_prakualifikasi)}
                            </div>
                            <span className="font-mono text-xs font-semibold">
                                {row.nomor_prakualifikasi}
                            </span>
                        </div>
                    ),
                    width: 180,
                },
                {
                    id: 'kode_vendor',
                    header: 'Kode Vendor/Supplier',
                    cell: (row) => (
                        <span className="font-mono text-xs font-semibold">
                            {row.kode_vendor}
                        </span>
                    ),
                    width: 160,
                },
                {
                    id: 'nama_vendor',
                    header: 'Nama Vendor/Supplier',
                    cell: (row) => (
                        <span className="text-xs font-semibold">
                            {row.nama_vendor}
                        </span>
                    ),
                    width: 220,
                },
                {
                    id: 'nama_pic',
                    header: 'Contact Person',
                    cell: (row) => (
                        <span className="text-xs">{row.nama_pic || '—'}</span>
                    ),
                    width: 150,
                },
                {
                    id: 'telepon',
                    header: 'Telp/HP',
                    cell: (row) => (
                        <span className="font-mono text-xs">
                            {row.telepon || '—'}
                        </span>
                    ),
                    width: 150,
                },
                {
                    id: 'alamat',
                    header: 'Alamat Vendor/Supplier',
                    cell: (row) => (
                        <span className="text-muted-foreground line-clamp-2 text-xs">
                            {row.alamat || '—'}
                        </span>
                    ),
                    width: 240,
                },
                {
                    id: 'status_vendor',
                    header: 'Status Vendor/Supplier',
                    cell: (row) => (
                        <Badge variant="secondary" className="text-[11px]">
                            {row.status_vendor || 'Vendor Resmi'}
                        </Badge>
                    ),
                    width: 160,
                },
                {
                    id: 'status_kualifikasi',
                    header: 'Status Kualifikasi',
                    cell: (row) => (
                        <Badge className="bg-emerald-600 text-[11px] font-medium text-white hover:bg-emerald-600/90">
                            <CheckCircle2 className="mr-1 h-3 w-3" />
                            {row.status_kualifikasi || 'Lolos'}
                        </Badge>
                    ),
                    width: 150,
                },
            ],
            [],
        );

    return (
        <div className="h-full min-h-0 overflow-hidden">
            <Card
                aria-labelledby="list-title"
                className="flex h-full min-h-0 flex-col overflow-hidden rounded-none border-0 shadow-none"
            >
                <CardHeader className="min-h-0 shrink-0 border-b">
                    <CardTitle id="list-title">Kualifikasi Vendor</CardTitle>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Rekanan Terkualifikasi
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {items.length} rekanan lolos dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari nomor, kode, atau vendor..."
                                aria-label="Cari rekanan terkualifikasi"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    {list.error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Data kualifikasi vendor belum dapat
                                    ditampilkan
                                </EmptyTitle>
                                <EmptyDescription>
                                    {list.error}
                                </EmptyDescription>
                            </EmptyHeader>
                            <Button variant="outline" onClick={load}>
                                Coba lagi
                            </Button>
                        </Empty>
                    ) : loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat daftar rekanan terkualifikasi…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada rekanan lolos kualifikasi
                                </EmptyTitle>
                                <EmptyDescription>
                                    Rekanan akan otomatis terdaftar di sini
                                    setelah dievaluasi pada menu Prakualifikasi
                                    dengan status Lolos.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            scrollMode="viewport"
                            className="min-h-0 flex-1"
                            columns={columns}
                            incremental={list}
                            sort={list.sort}
                            onSortChange={list.onSortChange}
                            data={items}
                            getRowKey={(row) => row.id}
                            getRowLabel={(row) => row.nomor_prakualifikasi}
                            onRowClick={(row) => void handleView(row)}
                        />
                    )}
                </CardContent>
            </Card>

            <VendorPrequalificationDetailDialog
                open={detailDialogOpen}
                onClose={() => {
                    detailAbortRef.current?.abort();
                    setDetailDialogOpen(false);
                }}
                data={detailRecord}
                loading={detailLoading}
                error={detailError}
                onRetry={() => void handleRetryDetail()}
            />
        </div>
    );
}
