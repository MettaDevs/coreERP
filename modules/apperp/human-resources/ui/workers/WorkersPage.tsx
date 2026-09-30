import { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
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
import { api, errorMessage } from '../api';
import type { Worker } from './types';
import { PERMISSION_CREATE_WORKER, PERMISSION_LINK_ACCOUNT } from './types';
import WorkerSheet from './WorkerSheet';

/**
 * Daftar pekerja dan akun pengguna yang tertaut (TODO analisa gap BC 9.1).
 *
 * Layar pertama module ini, dan sengaja hanya sebesar yang dibutuhkan tautan akun: daftar pekerja dan aksi
 * baris untuk mengatur akun penggunanya.
 */
export default function WorkersPage({
    permissions,
}: {
    permissions: string[];
}) {
    // Mengubah tautan memakai hak yang sama dengan menambah pekerja beserta akunnya.
    const canLink =
        permissions.includes(PERMISSION_CREATE_WORKER) &&
        permissions.includes(PERMISSION_LINK_ACCOUNT);
    const [workers, setWorkers] = useState<Worker[] | null>(null);
    const [loadError, setLoadError] = useState('');
    const [editing, setEditing] = useState<Worker | null>(null);

    // Dinaikkan untuk memuat ulang daftar sesudah menyimpan.
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        let cancelled = false;
        api<{ data: Worker[] }>('/workers')
            .then((result) => {
                if (!cancelled) {
                    setWorkers(result.data);
                    setLoadError('');
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    setLoadError(
                        errorMessage(
                            caught,
                            'Daftar pekerja belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [reloadKey]);

    const columns: DataTableColumn<Worker>[] = [
        {
            id: 'personnel-number',
            header: 'Nomor pegawai',
            width: 160,
            cell: (worker) => worker.personnel_number,
            sortValue: (worker) => worker.personnel_number,
        },
        {
            id: 'name',
            header: 'Nama',
            cell: (worker) => worker.name,
            sortValue: (worker) => worker.name,
        },
        {
            id: 'email',
            header: 'Email',
            cell: (worker) =>
                worker.email ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            id: 'account',
            header: 'Akun pengguna',
            cell: (worker) =>
                worker.account ? (
                    <div className="flex min-w-0 flex-col">
                        <span className="truncate">{worker.account.name}</span>
                        <span className="text-muted-foreground truncate text-xs">
                            {worker.account.email}
                        </span>
                    </div>
                ) : (
                    <span className="text-muted-foreground">
                        {worker.core_membership_id
                            ? 'Akun tidak aktif'
                            : 'Belum tertaut'}
                    </span>
                ),
            sortValue: (worker) => worker.account?.name ?? '',
        },
    ];
    const actions: DataTableRowAction[] = canLink
        ? [{ id: 'account', label: 'Atur akun pengguna' }]
        : [];

    return (
        <main>
            <Card>
                <CardHeader>
                    <CardTitle>Pekerja</CardTitle>
                </CardHeader>
                <CardContent>
                    {loadError ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Daftar pekerja belum tampil
                                </EmptyTitle>
                                <EmptyDescription>{loadError}</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : workers === null ? (
                        <Empty>
                            <EmptyDescription>Memuat pekerja…</EmptyDescription>
                        </Empty>
                    ) : workers.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Belum ada pekerja</EmptyTitle>
                                <EmptyDescription>
                                    Pekerja yang tercatat, atau yang sedang
                                    memegang posisi di unit kerja Anda, tampil
                                    di sini.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            columns={columns}
                            data={workers}
                            getRowKey={(worker) => worker.id}
                            getRowLabel={(worker) => worker.name}
                            actions={actions}
                            onRowAction={(action, worker) => {
                                if (action === 'account') {
                                    setEditing(worker);
                                }
                            }}
                        />
                    )}
                </CardContent>
            </Card>
            {editing && (
                <WorkerSheet
                    key={editing.id}
                    worker={editing}
                    onClose={() => setEditing(null)}
                    onSaved={(saved) => {
                        setWorkers((current) =>
                            (current ?? []).map((worker) =>
                                worker.id === saved.id ? saved : worker,
                            ),
                        );
                        setEditing(null);
                        setReloadKey((key) => key + 1);
                    }}
                />
            )}
        </main>
    );
}
