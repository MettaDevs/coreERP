import { InfiniteScroll, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DataTable } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';

/**
 * Halaman module: sebuah halaman Inertia biasa yang ikut build shell Core.
 *
 * Tiga hal yang mengikat, dan ketiganya sudah benar di berkas ini:
 *
 * 1. **Props datang dari controller module lewat `Inertia::render`**, bukan dari `fetch`
 *    sesudah halaman tampil. Tidak ada token yang perlu ditunggu dan tidak ada permintaan
 *    kedua ke jaringan sebelum ada yang terlihat.
 * 2. **Tidak ada satu pun impor milik shell.** Yang boleh disebut hanya `@apperp/ui`, React,
 *    `@inertiajs/react`, dan berkas module sendiri. Sebuah impor `@/...` akan berhasil
 *    dibangun — folder ini ikut build yang sama — dan justru itu bahayanya: module-nya pecah
 *    begitu dipasang di runtime yang shell-nya berbeda.
 * 3. **Tidak ada `iframe`.**
 *
 * Tidak ada satu pun penanda cetakan di dalam JSX di bawah, dan itu disengaja. Berkas `ui/`
 * diperiksa Prettier, yang membungkus baris menurut panjangnya: sebuah penanda di dalam
 * atribut atau teks layar membuat panjang baris berubah begitu diganti, sehingga halaman yang
 * dihasilkan gagal `npm run format:check` untuk sebagian nama module dan lolos untuk sebagian
 * yang lain. Penanda karena itu hanya hidup di komentar, yang tidak pernah dibungkus ulang.
 *
 * Teks di layar memakai bahasa sehari-hari. Istilah internal — entitlement, placement,
 * tenant_id — tidak pernah ditampilkan kepada pengguna bisnis.
 */

type RecordRow = { id: string; kode: string; nama: string };
type ListFilters = { q?: string; sort?: string; direction?: 'asc' | 'desc' };

export default function RecordList({
    contoh,
    filters,
}: {
    contoh: { data: RecordRow[] };
    filters: ListFilters;
}) {
    const [search, setSearch] = useState(filters.q ?? '');
    useEffect(() => {
        if (search === (filters.q ?? '')) {
            return;
        }

        const timer = setTimeout(
            () =>
                router.get(
                    window.location.pathname,
                    { ...filters, q: search },
                    {
                        reset: ['contoh'],
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            250,
        );

        return () => clearTimeout(timer);
    }, [search, filters]);

    return (
        <div className="space-y-4 p-6">
            <h1 className="text-lg font-semibold">Daftar contoh</h1>
            <Input
                type="search"
                label="Cari kode atau nama"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
            />
            <InfiniteScroll
                data="contoh"
                itemsElement="tbody"
                onlyNext
                preserveUrl
                buffer={160}
                loading={<p role="status">Memuat data...</p>}
            >
                <DataTable
                    columns={[
                        {
                            id: 'kode',
                            header: 'Kode',
                            cell: (row) => row.kode,
                            sortValue: (row) => row.kode,
                        },
                        {
                            id: 'nama',
                            header: 'Nama',
                            cell: (row) => row.nama,
                            sortValue: (row) => row.nama,
                        },
                    ]}
                    data={contoh.data}
                    getRowKey={(row) => row.id}
                    serverSort
                    sort={
                        filters.sort
                            ? {
                                  columnId: filters.sort,
                                  direction: filters.direction ?? 'asc',
                              }
                            : null
                    }
                    onSortChange={(sort) =>
                        router.get(
                            window.location.pathname,
                            {
                                q: search,
                                sort: sort?.columnId,
                                direction: sort?.direction,
                            },
                            {
                                reset: ['contoh'],
                                preserveState: true,
                                preserveScroll: true,
                                replace: true,
                            },
                        )
                    }
                />
            </InfiniteScroll>
        </div>
    );
}
