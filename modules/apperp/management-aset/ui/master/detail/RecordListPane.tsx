import { useEffect, useRef } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { MasterConfig, MasterRecord } from '../masters';

const STATUS_ITEMS = ['Semua status', 'Aktif', 'Tidak aktif'];

/**
 * Panel kiri: daftar record yang dipilih satu per satu, bukan tabel yang dipindai.
 *
 * Ini daftar record, bukan navigasi. Ia tinggal di dalam panel milik aplikasi, memakai
 * `aria-pressed` alih-alih `aria-current`, dan tidak pernah mengubah rute — navigasi
 * antar-modul tetap milik Shell.
 */
export default function RecordListPane({
    config,
    items,
    total,
    hasMore,
    loading,
    error,
    search,
    onSearchChange,
    activeFilter,
    onActiveFilterChange,
    selectedId,
    creating,
    onSelect,
    onLoadMore,
    onRetry,
}: {
    config: MasterConfig;
    items: MasterRecord[];
    total: number;
    hasMore: boolean;
    loading: boolean;
    error: string;
    search: string;
    onSearchChange: (value: string) => void;
    activeFilter: string;
    onActiveFilterChange: (value: string) => void;
    selectedId: string | null;
    creating: boolean;
    onSelect: (id: string) => void;
    onLoadMore: () => void;
    onRetry: () => void;
}) {
    const sentinelRef = useRef<HTMLDivElement>(null);

    /**
     * Halaman berikutnya dimuat saat dasar daftar terlihat. Sengaja bukan "sebelumnya /
     * berikutnya": mengganti halaman akan membuang record yang sedang dipilih dari daftar,
     * dan panel detail ikut kosong tanpa pengguna meminta apa pun.
     */
    useEffect(() => {
        const sentinel = sentinelRef.current;
        if (!sentinel || !hasMore || loading) return;
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) onLoadMore();
            },
            { rootMargin: '120px' },
        );
        observer.observe(sentinel);

        return () => observer.disconnect();
    }, [hasMore, loading, onLoadMore]);

    return (
        <aside
            className="flex min-h-0 flex-col border-r"
            aria-label={`Daftar ${config.singular}`}
        >
            <div className="shrink-0 space-y-2 border-b px-4 py-3">
                <p className="text-sm font-semibold">
                    Daftar {config.singular}
                </p>
                <Input
                    type="search"
                    label="Cari kode atau nama"
                    value={search}
                    onChange={(event) => onSearchChange(event.target.value)}
                />
                <Select
                    items={STATUS_ITEMS}
                    value={
                        activeFilter === 'true'
                            ? 'Aktif'
                            : activeFilter === 'false'
                              ? 'Tidak aktif'
                              : 'Semua status'
                    }
                    searchPlaceholder="Cari status"
                    emptyMessage="Status tidak ditemukan."
                    ariaLabel="Saring berdasarkan status"
                    onValueChange={(item) =>
                        onActiveFilterChange(
                            item === 'Aktif'
                                ? 'true'
                                : item === 'Tidak aktif'
                                  ? 'false'
                                  : 'semua',
                        )
                    }
                />
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {creating && (
                    <div className="border-l-primary bg-primary/10 border-b border-l-2 px-4 py-2.5">
                        <span className="text-muted-foreground block text-base font-semibold leading-tight">
                            Belum disimpan
                        </span>
                        <span className="text-muted-foreground mt-0.5 block truncate text-xs">
                            {config.title} baru
                        </span>
                    </div>
                )}

                {error ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Data belum dapat ditampilkan
                            </EmptyTitle>
                            <EmptyDescription>{error}</EmptyDescription>
                        </EmptyHeader>
                        <Button variant="outline" onClick={onRetry}>
                            Coba lagi
                        </Button>
                    </Empty>
                ) : items.length === 0 && !loading && !creating ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada {config.singular}</EmptyTitle>
                            <EmptyDescription>
                                Tambahkan data pertama agar pilihan pada bagian
                                lain sudah tersedia.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    items.map((item) => {
                        const selected = !creating && item.id === selectedId;

                        return (
                            <button
                                key={item.id}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => onSelect(item.id)}
                                className={`hover:bg-accent/50 focus-visible:ring-primary w-full border-b px-4 py-2.5 text-left transition-colors last:border-b-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset ${
                                    selected
                                        ? 'border-l-primary bg-primary/10 border-l-2'
                                        : 'border-l-2 border-l-transparent'
                                }`}
                            >
                                <span className="block truncate text-base font-semibold leading-tight">
                                    {item.kode}
                                </span>
                                <span className="text-muted-foreground mt-0.5 block truncate text-xs">
                                    {item.nama}
                                    {item.aktif ? '' : ' · Tidak aktif'}
                                </span>
                            </button>
                        );
                    })
                )}

                <div ref={sentinelRef} />
                {(hasMore || loading) && (
                    <p className="text-muted-foreground px-4 py-3 text-xs">
                        {loading
                            ? 'Memuat…'
                            : `${items.length} dari ${total} data`}
                    </p>
                )}
            </div>
        </aside>
    );
}
