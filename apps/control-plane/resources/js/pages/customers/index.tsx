import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import {
    assertListBatch,
    useIncrementalList,
} from '@apperp/ui/use-incremental-list';
import { Head } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import Shell from '@/components/shell';
import CreateDialog from '@/pages/customers/create-dialog';
import CredentialsCard from '@/pages/customers/credentials-card';
import type { Credentials } from '@/pages/customers/credentials-card';

type Row = {
    id: string;
    name: string;
    environments: number;
    createdAt: string | null;
};

export default function Index({
    app,
    credentials,
}: {
    app: { id: string; name: string }[];
    credentials: Credentials | null;
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

            const response = await fetch(`/tenant?${params}`, {
                signal,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(
                    'Daftar tenant belum dapat dimuat. Periksa koneksi lalu coba lagi.',
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
                    'Jawaban daftar tenant tidak lengkap. Muat ulang daftar.',
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
            title="Tenant"
            actions={<CreateDialog app={app} onCreated={list.reload} />}
        >
            <Head title="Tenant" />

            {credentials && <CredentialsCard credentials={credentials} />}
            <Card>
                <CardHeader>
                    <CardTitle>Perusahaan dan grup</CardTitle>
                    <span className="text-xs text-muted-foreground">
                        {list.items.length} tenant dimuat
                    </span>
                </CardHeader>
                <CardContent className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Setiap tenant memiliki akun admin dan lingkungan
                        kerjanya sendiri.
                    </p>
                    <Input
                        label="Cari tenant"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <div className="h-[min(32rem,60vh)] min-h-40">
                        <DataTable
                            scrollMode="viewport"
                            data={list.items}
                            incremental={list}
                            getRowKey={(row) => row.id}
                            showRowNumbers={false}
                            emptyMessage="Belum ada tenant. Pilih Baru untuk mendaftarkan perusahaan beserta akun admin pertamanya."
                            columns={[
                                {
                                    id: 'name',
                                    header: 'Nama tenant',
                                    minWidth: 240,
                                    cell: (row) => (
                                        <div className="py-1">
                                            <p className="font-medium">
                                                {row.name}
                                            </p>
                                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                {row.id}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'environments',
                                    header: 'Lingkungan kerja',
                                    minWidth: 180,
                                    cell: (row) =>
                                        row.environments > 0 ? (
                                            `${row.environments} lingkungan`
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Belum dibuat
                                            </span>
                                        ),
                                },
                                {
                                    id: 'createdAt',
                                    header: 'Terdaftar pada',
                                    minWidth: 180,
                                    cell: (row) =>
                                        row.createdAt ?? 'Tidak tercatat',
                                },
                            ]}
                        />
                    </div>
                </CardContent>
            </Card>
        </Shell>
    );
}
