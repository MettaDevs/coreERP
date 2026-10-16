import { Button } from '@apperp/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { IncrementalListStatus } from '@apperp/ui/incremental-list-status';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import type { MasterConfig, MasterRecord } from '../masters';

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
    return (
        <aside
            className="flex h-full min-h-0 min-w-0 flex-col"
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

                {error && !items.length ? (
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
                                    {item.nama}
                                </span>
                                <span className="text-muted-foreground mt-0.5 block truncate text-xs">
                                    {item.kode}
                                    {item.aktif ? '' : ' · Tidak aktif'}
                                </span>
                            </button>
                        );
                    })
                )}

                <IncrementalListStatus
                    state={{
                        hasMore,
                        loading,
                        error,
                        loadMore: onLoadMore,
                        retry: onRetry,
                    }}
                    endMessage={`${items.length} data dimuat`}
                />
            </div>
        </aside>
    );
}
