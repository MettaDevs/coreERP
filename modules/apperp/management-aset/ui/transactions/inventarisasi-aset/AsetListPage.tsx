import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
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
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { api, errorMessage } from '../../api';
import { useMasterOptions } from '../../master/useMasterOptions';
import type { Aset } from './aset';
import { LIFECYCLE, bukaAset, bukaAsetUbah, money } from './aset';
import { bukaPenerimaanDaftar, izin } from './penerimaan';

/**
 * Register aset: daftar saja.
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
    const [aset, setAset] = useState<Aset[]>([]);
    const [search, setSearch] = useState('');
    const [memuat, setMemuat] = useState(true);

    const { options: groupOptions } = useMasterOptions('group-aset');
    const { options: typeOptions } = useMasterOptions('jenis-aset');
    const { options: locationOptions } = useMasterOptions('lokasi-aset');

    useEffect(() => {
        let dibatalkan = false;
        api<{ data: Aset[] }>('/aset')
            .then((result) => {
                if (!dibatalkan) {
                    setAset(result.data);
                }
            })
            .catch((caught) => {
                if (!dibatalkan) {
                    toast.error(
                        errorMessage(
                            caught,
                            'Register aset belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, []);

    const nameOf = (options: { id: string; nama: string }[], id: unknown) =>
        options.find((option) => option.id === String(id ?? ''))?.nama ?? null;

    const visible = useMemo(() => {
        const query = search.trim().toLowerCase();

        if (!query) {
            return aset;
        }

        return aset.filter(
            (aset) =>
                aset.kode.toLowerCase().includes(query) ||
                aset.nama.toLowerCase().includes(query) ||
                (aset.serial_number ?? '').toLowerCase().includes(query),
        );
    }, [aset, search]);

    const columns: DataTableColumn<Aset>[] = [
        {
            id: 'kode',
            header: 'Kode aset',
            cell: (aset) => (
                <span className="text-primary font-medium">{aset.kode}</span>
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
                    {nameOf(groupOptions, aset.group_aset_id) ?? '—'}
                </span>
            ),
            sortValue: (aset) => nameOf(groupOptions, aset.group_aset_id) ?? '',
            width: 180,
        },
        {
            id: 'jenis',
            header: 'Jenis aset',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {nameOf(typeOptions, aset.jenis_aset_id) ?? '—'}
                </span>
            ),
            sortValue: (aset) => nameOf(typeOptions, aset.jenis_aset_id) ?? '',
            width: 180,
        },
        {
            id: 'lokasi',
            header: 'Lokasi',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {nameOf(locationOptions, aset.lokasi_aset_id) ?? '—'}
                </span>
            ),
            sortValue: (aset) =>
                nameOf(locationOptions, aset.lokasi_aset_id) ?? '',
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

    const rowActions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (canUpdate) {
        rowActions.push({ id: 'edit', label: 'Ubah' });
    }

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar title="Inventarisasi aset">
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

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                <p className="text-muted-foreground text-sm">
                    {visible.length} aset ditampilkan
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

            <div className="min-h-0 flex-1 overflow-auto">
                {!memuat && !visible.length ? (
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
                        data={visible}
                        getRowKey={(aset) => aset.id}
                        getRowLabel={(aset) => aset.kode}
                        actions={rowActions}
                        onRowClick={(aset) => bukaAset(aset.id)}
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
            </div>
        </div>
    );
}
