import { useEffect, useRef } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
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
        <aside className="flex min-h-0 flex-col border-r" aria-label={`Daftar ${config.singular}`}>
            <div className="shrink-0 space-y-2 border-b px-4 py-3">
                <p className="text-sm font-semibold">Daftar {config.singular}</p>
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
                            item === 'Aktif' ? 'true' : item === 'Tidak aktif' ? 'false' : 'semua',
                        )
                    }
                />
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {creating && (
                    <div className="border-b border-l-2 border-l-primary bg-primary/10 px-4 py-2.5">
                        <span className="block text-base leading-tight font-semibold text-muted-foreground">
                            Belum disimpan
                        </span>
                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                            {config.title} baru
                        </span>
                    </div>
                )}

                {error ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Data belum dapat ditampilkan</EmptyTitle>
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
                                Tambahkan data pertama agar pilihan pada bagian lain sudah tersedia.
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
                                className={`w-full border-b px-4 py-2.5 text-left transition-colors last:border-b-0 hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset focus-visible:outline-none ${
                                    selected
                                        ? 'border-l-2 border-l-primary bg-primary/10'
                                        : 'border-l-2 border-l-transparent'
                                }`}
                            >
                                <span className="block truncate text-base leading-tight font-semibold">
                                    {item.kode}
                                </span>
                                <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                    {item.nama}
                                    {item.aktif ? '' : ' · Tidak aktif'}
                                </span>
                            </button>
                        );
                    })
                )}

                <div ref={sentinelRef} />
                {(hasMore || loading) && (
                    <p className="px-4 py-3 text-xs text-muted-foreground">
                        {loading ? 'Memuat…' : `${items.length} dari ${total} data`}
                    </p>
                )}
            </div>
        </aside>
    );
}
