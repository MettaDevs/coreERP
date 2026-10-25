import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import { Head, router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import CreateDialog from './create-dialog';
import { parseTenantBatch, statusLabels } from './types';
import type { Product, ProductTenant } from './types';
import Shell from '@/components/shell';

export default function Index({
    products,
    catalogError,
}: {
    products: Product[];
    catalogError: string | null;
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

            const response = await fetch(`/produk/tenant?${params}`, {
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

            return parseTenantBatch(body);
        },
        [],
    );
    const list = useIncrementalList<ProductTenant>({
        loadItems,
        query: search,
        getKey: (row) => row.id,
    });

    return (
        <Shell
            title="Tenant produk"
            actions={<CreateDialog products={products} />}
        >
            <Head title="Tenant produk" />
            {catalogError && (
                <p role="alert" className="text-sm text-destructive">
                    {catalogError}
                </p>
            )}
            <ListPageLayout
                filters={
                    <div className="flex items-center gap-3 py-3">
                        <Input
                            label="Cari tenant"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <Button variant="outline" onClick={list.reload}>
                            Perbarui status
                        </Button>
                    </div>
                }
            >
                <DataTable
                    data={list.items}
                    getRowKey={(row) => row.id}
                    incremental={list}
                    emptyMessage="Belum ada tenant produk. Pilih Baru untuk membuat fasilitas."
                    actions={[{ id: 'show', label: 'Lihat rincian' }]}
                    onRowAction={(_, row) =>
                        router.visit(`/produk/tenant/${row.id}`)
                    }
                    onRowClick={(row) =>
                        router.visit(`/produk/tenant/${row.id}`)
                    }
                    columns={[
                        {
                            id: 'name',
                            header: 'Nama fasilitas',
                            minWidth: 220,
                            cell: (row) => row.name,
                        },
                        {
                            id: 'profile',
                            header: 'Jenis fasilitas',
                            minWidth: 160,
                            cell: (row) =>
                                products
                                    .find((item) => item.code === row.product)
                                    ?.profiles.find(
                                        (item) => item.code === row.profile,
                                    )?.name ?? row.profile,
                        },
                        {
                            id: 'slug',
                            header: 'Alamat tenant',
                            minWidth: 180,
                            cell: (row) => row.slug,
                        },
                        {
                            id: 'status',
                            header: 'Status',
                            minWidth: 180,
                            cell: (row) => statusLabels[row.status],
                        },
                        {
                            id: 'owner',
                            header: 'Admin fasilitas',
                            minWidth: 220,
                            cell: (row) => row.owner_email,
                        },
                    ]}
                />
            </ListPageLayout>
        </Shell>
    );
}
