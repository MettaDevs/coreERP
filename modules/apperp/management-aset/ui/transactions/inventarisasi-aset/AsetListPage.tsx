import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { usePage } from '@inertiajs/react';
import { FileSpreadsheet, QrCode } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { RecordFactBox } from '@/components/record-fact-box';
import type { Workspace } from '@/types';

import { requestListExport } from '../../listExport';
import { useRecordList } from '../../use-record-list';
import type { Aset } from './aset';
import { LIFECYCLE, bukaAset, bukaAsetUbah, money } from './aset';
import AssetPicture from './AssetPicture';
import { printAssetLabels } from './labels';
import { bukaPenerimaanDaftar, izin } from './penerimaan';

/**
 * Register aset dengan FactBox untuk aset yang dipilih.
 *
 * Menerima dan mengoreksi aset adalah urusan halaman rincian yang punya alamat sendiri,
 * bentuk yang sama dengan work order dan mutasi. Sebelum 18 September 2026 keduanya
 * dikerjakan di dalam `Sheet` pada halaman ini.
 */
export default function AsetListPage({
    canUpdate,
    permissions,
}: {
    canUpdate: boolean;
    permissions: string[];
}) {
    const { workspace } = usePage<{ workspace: Workspace }>().props;
    const legalEntityNames = new Map(
        workspace.legal_entities.map((entity) => [entity.id, entity.name]),
    );
    const [search, setSearch] = useState('');
    // Urutan yang sedang tampil, supaya berkas ekspor berurutan sama dengan layar.
    // Aset yang dicentang untuk dicetak labelnya. Tanpa centang, yang dicetak semua yang tampil.
    const [selected, setSelected] = useState<string[]>([]);
    const [activeRowId, setActiveRowId] = useState<string | null>(null);

    const list = useRecordList<Aset>('/aset', search);
    const aset = list.items;
    const visible = aset;
    const memuat = list.loading;
    const sort = list.sort;
    const setSort = list.onSortChange;

    const activeAsset = visible.find((asset) => asset.id === activeRowId);

    const columns: DataTableColumn<Aset>[] = [
        {
            id: 'kode',
            header: 'Kode aset',
            cell: (aset) => (
                <button
                    type="button"
                    className="text-primary font-medium hover:underline"
                    onClick={() => bukaAset(aset.id)}
                >
                    {aset.kode}
                </button>
            ),
            sortValue: (aset) => aset.kode,
            width: 160,
        },
        {
            id: 'nama',
            header: 'Nama aset',
            cell: (aset) => aset.nama,
            sortValue: (aset) => aset.nama,
            width: 240,
        },
        {
            id: 'legal_entity',
            header: 'Perusahaan',
            cell: (asset) =>
                asset.legal_entity_nama ??
                legalEntityNames.get(asset.legal_entity_id) ??
                'Perusahaan tidak dikenal',
            sortValue: (asset) =>
                asset.legal_entity_nama ??
                legalEntityNames.get(asset.legal_entity_id) ??
                '',
            width: 220,
        },
        {
            id: 'serial',
            header: 'Nomor seri',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.serial_number || '—'}
                </span>
            ),
            sortValue: (aset) => aset.serial_number ?? '',
            width: 160,
        },
        {
            id: 'group',
            header: 'Group aset',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.group_nama ?? '—'}
                </span>
            ),
            sortValue: (aset) => aset.group_nama ?? '',
            width: 180,
        },
        {
            id: 'jenis',
            header: 'Jenis aset',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.type_nama ?? '—'}
                </span>
            ),
            sortValue: (aset) => aset.type_nama ?? '',
            width: 180,
        },
        {
            id: 'lokasi',
            header: 'Lokasi',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.location_nama ?? '—'}
                </span>
            ),
            sortValue: (aset) => aset.location_nama ?? '',
            width: 180,
        },
        {
            id: 'nilai',
            header: 'Nilai perolehan',
            cell: (aset) => money(aset.acquisition_value, aset.currency_code),
            sortValue: (aset) => Number(aset.acquisition_value),
            align: 'right',
            width: 170,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (aset) => {
                const state = LIFECYCLE[aset.lifecycle_state] ?? {
                    label: aset.lifecycle_state,
                    variant: 'secondary' as const,
                };

                return <Badge variant={state.variant}>{state.label}</Badge>;
            },
            sortValue: (aset) =>
                LIFECYCLE[aset.lifecycle_state]?.label ?? aset.lifecycle_state,
            width: 140,
        },
    ];

    /**
     * Ekspor daftar yang sedang tampil (K-27): kolom dan judulnya, urutan, dan pencarian yang sama, untuk
     * semua aset yang cocok. Dikerjakan antrean ekspor Core; hasilnya muncul di ikon Ekspor pada header.
     */
    const exportList = () =>
        requestListExport({
            list: 'aset',
            columns: columns.map((column) => ({
                key: column.id,
                header: column.header,
            })),
            sort: sort
                ? { column: sort.columnId, direction: sort.direction }
                : null,
            filters: search.trim() ? { q: search.trim() } : {},
        });

    const printLabels = () =>
        printAssetLabels({
            ids: selected.length ? selected : visible.map((asset) => asset.id),
        });

    const rowActions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (canUpdate) {
        rowActions.push({ id: 'edit', label: 'Ubah' });
    }

    return (
        <ListPageLayout
            header={
                <RecordActionBar title="Inventarisasi aset">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={exportList}
                        disabled={memuat}
                        title="Ekspor semua aset yang cocok dengan pencarian, dengan kolom dan urutan seperti di layar"
                    >
                        <FileSpreadsheet />
                        Ekspor ke Excel
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={printLabels}
                        disabled={memuat || !visible.length}
                        title={
                            selected.length
                                ? 'Cetak label berkode QR untuk aset yang dicentang'
                                : 'Cetak label untuk aset yang sudah tampil di daftar. Geser daftar ke bawah untuk menampilkan aset lainnya, atau centang aset yang ingin dicetak.'
                        }
                    >
                        <QrCode />
                        {selected.length
                            ? `Cetak label (${selected.length})`
                            : `Cetak label (${visible.length})`}
                    </Button>
                    {/*
                     * Satu pintu. Aset hanya lahir dari dokumen penerimaan sejak 18 September
                     * 2026 — juga yang datang satuan, yang menjadi dokumen berbaris satu.
                     */}
                    {izin(permissions)('read') && (
                        <ActionButton
                            action="create"
                            type="button"
                            onClick={bukaPenerimaanDaftar}
                        >
                            Penerimaan aset
                        </ActionButton>
                    )}
                </RecordActionBar>
            }
            filters={
                <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                    <p className="text-muted-foreground text-sm">
                        {visible.length} aset ditampilkan
                        {selected.length
                            ? `, ${selected.length} dicentang`
                            : ''}
                    </p>
                    <div className="w-full sm:w-72">
                        <Input
                            label="Cari"
                            type="search"
                            placeholder="Kode, nama, atau nomor seri"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                </div>
            }
        >
            <RecordFactBox
                fillHeight
                className="px-5 pb-3"
                recordType="aset_tr_aset"
                recordId={activeAsset?.id}
                details={
                    activeAsset ? (
                        <AssetPicture assetId={activeAsset.id} />
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Pilih aset untuk melihat fotonya.
                        </p>
                    )
                }
            >
                {!memuat && !visible.length && !list.error ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                {aset.length
                                    ? 'Tidak ada aset yang cocok'
                                    : 'Belum ada aset'}
                            </EmptyTitle>
                            <EmptyDescription>
                                {aset.length
                                    ? 'Ubah kata kunci pencarian untuk menemukan aset lain.'
                                    : 'Catat penerimaan aset pertama untuk mulai memantau lokasi, pengguna, dan penyusutannya.'}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        incremental={list}
                        data={visible}
                        getRowKey={(aset) => aset.id}
                        getRowLabel={(aset) => aset.kode}
                        sort={sort}
                        onSortChange={setSort}
                        selection={{
                            selectedKeys: selected,
                            onSelectedKeysChange: (keys) => {
                                const ids = keys.map(String);
                                setSelected(ids);
                                setActiveRowId(ids.at(-1) ?? null);
                            },
                        }}
                        actions={rowActions}
                        activeRowKey={activeAsset?.id}
                        rowActivation="single-click"
                        onRowClick={(asset) => {
                            setSelected([asset.id]);
                            setActiveRowId(asset.id);
                        }}
                        onRowAction={(action, aset) => {
                            if (action === 'detail') {
                                bukaAset(aset.id);
                            }

                            if (action === 'edit') {
                                // Aset yang sudah dilepas tidak dapat dikoreksi; membuka
                                // mode sunting hanya untuk ditolak server membuang waktu
                                // orangnya dan menyembunyikan alasannya di balik toast.
                                if (aset.lifecycle_state === 'disposed') {
                                    toast.error(
                                        'Aset yang sudah dilepas tidak dapat diubah.',
                                    );

                                    return;
                                }

                                bukaAsetUbah(aset.id);
                            }
                        }}
                    />
                )}
            </RecordFactBox>
        </ListPageLayout>
    );
}
