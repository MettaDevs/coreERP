import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import {
    assertListBatch,
    useIncrementalList,
} from '@apperp/ui/use-incremental-list';
import { Head, Link } from '@inertiajs/react';
import { Cloud, Server } from 'lucide-react';
import { useCallback, useState } from 'react';
import { InstallStateBadge, KindBadge, StatusBadge } from '@/components/badges';
import Shell from '@/components/shell';
import { progressDetail } from '@/lib/install-progress';
import type { InstallProgress } from '@/lib/install-progress';
import CreateDialog from '@/pages/environments/create-dialog';

type Row = {
    id: string;
    name: string;
    slug: string;
    kind: string;
    status: string;
    hosting: string;
    outboundAllowed: boolean;
    database: string;
    url: string | null;
    expiresAt: string | null;
    tenant: string;
    serverClient: InstallProgress | null;
    site: {
        id: string;
        serverAddress: string | null;
    } | null;
};

export default function Index({
    hasTenants,
    kinds,
}: {
    hasTenants: boolean;
    kinds: string[];
}) {
    const [search, setSearch] = useState('');
    const loadItems = useCallback(
        async ({
            query,
            cursor,
            signal,
        }: {
            query: string;
            cursor: string | null;
            signal: AbortSignal;
        }) => {
            const params = new URLSearchParams({ search: query });

            if (cursor) {
                params.set('cursor', cursor);
            }

            const response = await fetch(`/lingkungan?${params}`, {
                signal,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(
                    'Daftar lingkungan belum dapat dimuat. Periksa koneksi lalu coba lagi.',
                );
            }

            const body: unknown = await response.json();

            if (
                !body ||
                typeof body !== 'object' ||
                !('data' in body) ||
                !('next_cursor' in body)
            ) {
                throw new Error(
                    'Jawaban daftar lingkungan tidak lengkap. Muat ulang daftar.',
                );
            }

            const batch = { items: body.data, nextCursor: body.next_cursor };
            assertListBatch<Row>(batch);

            return batch;
        },
        [],
    );
    const list = useIncrementalList<Row>({
        loadItems,
        query: search,
        getKey: (row) => row.id,
    });

    return (
        <Shell
            title="Lingkungan"
            actions={
                <CreateDialog
                    hasTenants={hasTenants}
                    kinds={kinds}
                    onCreated={list.reload}
                />
            }
        >
            <Head title="Lingkungan" />

            <Card>
                <CardHeader>
                    <CardTitle>Tempat kerja terdaftar</CardTitle>
                    <span className="text-xs text-muted-foreground">
                        {list.items.length} lingkungan dimuat
                    </span>
                </CardHeader>
                <CardContent className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Buka nama lingkungan untuk melihat kesiapan, aplikasi
                        yang terpasang, dan riwayatnya.
                    </p>
                    <Input
                        label="Cari lingkungan atau tenant"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    {!hasTenants && (
                        <Link
                            href="/tenant"
                            className="inline-flex text-sm text-primary underline underline-offset-4"
                        >
                            Daftarkan tenant terlebih dahulu
                        </Link>
                    )}
                    <div className="h-[min(32rem,60vh)] min-h-40">
                        <DataTable
                            scrollMode="viewport"
                            data={list.items}
                            incremental={list}
                            getRowKey={(row) => row.id}
                            showRowNumbers={false}
                            emptyMessage={
                                !hasTenants
                                    ? 'Lingkungan memerlukan tenant. Daftarkan tenant melalui tautan di atas.'
                                    : 'Belum ada lingkungan. Pilih Baru untuk membuat tempat kerja produksi, demo, atau sandbox.'
                            }
                            columns={[
                                {
                                    id: 'name',
                                    header: 'Lingkungan / tenant',
                                    minWidth: 220,
                                    cell: (row) => (
                                        <div className="space-y-1 py-1">
                                            <Link
                                                href={`/lingkungan/${row.id}`}
                                                className="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {row.name}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {row.tenant}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'kind',
                                    header: 'Jenis',
                                    minWidth: 120,
                                    cell: (row) => (
                                        <KindBadge kind={row.kind} />
                                    ),
                                },
                                {
                                    id: 'status',
                                    header: 'Kesiapan',
                                    minWidth: 220,
                                    cell: (row) =>
                                        row.serverClient ? (
                                            <div className="space-y-1">
                                                <InstallStateBadge
                                                    state={
                                                        row.serverClient.state
                                                    }
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    {progressDetail(
                                                        row.serverClient,
                                                    )}
                                                </p>
                                            </div>
                                        ) : (
                                            <StatusBadge status={row.status} />
                                        ),
                                },
                                {
                                    id: 'address',
                                    header: 'Alamat akses',
                                    minWidth: 220,
                                    cell: (row) => (
                                        <div className="space-y-1">
                                            {row.url ? (
                                                <a
                                                    href={row.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="break-all text-primary underline-offset-4 hover:underline"
                                                >
                                                    {row.url.replace(
                                                        'https://',
                                                        '',
                                                    )}
                                                </a>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Belum tersedia
                                                </span>
                                            )}
                                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                {row.hosting ===
                                                'client_server' ? (
                                                    <>
                                                        <Server
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        Server klien
                                                    </>
                                                ) : (
                                                    <>
                                                        <Cloud
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        Server kita
                                                    </>
                                                )}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'expires',
                                    header: 'Berlaku sampai',
                                    minWidth: 150,
                                    cell: (row) =>
                                        row.expiresAt ?? 'Tanpa batas waktu',
                                },
                            ]}
                        />
                    </div>
                </CardContent>
            </Card>
        </Shell>
    );
}
