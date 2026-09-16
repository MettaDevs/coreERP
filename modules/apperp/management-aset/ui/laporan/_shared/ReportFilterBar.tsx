import { Filter, RefreshCw, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { api } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';

type AssetOption = {
    id: string;
    kode: string;
    nama?: string;
};

export type ReportFilterBarProps = {
    filters: Record<string, string>;
    onFilterChange: (key: string, value: string) => void;
    onReset: () => void;
    onRefresh?: () => void;
    loading?: boolean;
    showGroupAset?: boolean;
    showJenisAset?: boolean;
    showAsset?: boolean;
    showDateRange?: boolean;
    showSingleMonth?: boolean;
};

export function ReportFilterBar({
    filters,
    onFilterChange,
    onReset,
    onRefresh,
    loading = false,
    showGroupAset = true,
    showJenisAset = true,
    showAsset = true,
    showDateRange = true,
    showSingleMonth = false,
}: ReportFilterBarProps) {
    const groupAsetMaster = useMasterOptions('group-aset');
    const jenisAsetMaster = useMasterOptions('jenis-aset');
    const [assets, setAssets] = useState<AssetOption[]>([]);

    useEffect(() => {
        if (!showAsset) {
            return;
        }

        let cancelled = false;

        api<{ data: AssetOption[] }>('/aset?per_page=200')
            .then((res) => {
                if (!cancelled) {
                    setAssets(res.data ?? []);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setAssets([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [showAsset]);

    const activeFilterCount = useMemo(() => {
        return Object.values(filters).filter(
            (val) => val !== '' && val !== undefined && val !== null,
        ).length;
    }, [filters]);

    const groupItems = useMemo(
        () => [
            { value: '', label: 'Semua group aset' },
            ...groupAsetMaster.options.map((opt) => ({
                value: opt.id,
                label: optionLabel(opt),
            })),
        ],
        [groupAsetMaster.options],
    );

    const jenisItems = useMemo(
        () => [
            { value: '', label: 'Semua jenis aset' },
            ...jenisAsetMaster.options.map((opt) => ({
                value: opt.id,
                label: optionLabel(opt),
            })),
        ],
        [jenisAsetMaster.options],
    );

    const assetItems = useMemo(
        () => [
            { value: '', label: 'Semua aset' },
            ...assets.map((asset) => ({
                value: asset.id,
                label: `${asset.kode} — ${asset.nama ?? 'Tanpa nama'}`,
            })),
        ],
        [assets],
    );

    return (
        <div className="border-border/60 bg-muted/20 flex flex-wrap items-center gap-2.5 border-b px-5 py-2.5">
            <div className="text-muted-foreground mr-1 flex items-center gap-1.5 text-xs font-medium">
                <Filter className="text-primary size-3.5" />
                <span className="text-foreground font-semibold">Filter:</span>
            </div>

            {showGroupAset && (
                <div className="w-44 sm:w-48">
                    <Select
                        items={groupItems}
                        value={filters.group_aset_id ?? ''}
                        onValueChange={(val) =>
                            onFilterChange('group_aset_id', val ?? '')
                        }
                        searchPlaceholder="Cari group aset..."
                        ariaLabel="Group aset"
                    />
                </div>
            )}

            {showJenisAset && (
                <div className="w-44 sm:w-48">
                    <Select
                        items={jenisItems}
                        value={filters.jenis_aset_id ?? ''}
                        onValueChange={(val) =>
                            onFilterChange('jenis_aset_id', val ?? '')
                        }
                        searchPlaceholder="Cari jenis aset..."
                        ariaLabel="Jenis aset"
                    />
                </div>
            )}

            {showAsset && (
                <div className="w-52 sm:w-60">
                    <Select
                        items={assetItems}
                        value={filters.asset_id ?? ''}
                        onValueChange={(val) =>
                            onFilterChange('asset_id', val ?? '')
                        }
                        searchPlaceholder="Ketik kode / nama aset..."
                        ariaLabel="Nama aset"
                    />
                </div>
            )}

            {showSingleMonth && (
                <div className="flex items-center gap-1.5">
                    <span className="text-muted-foreground whitespace-nowrap text-xs font-medium">
                        Periode:
                    </span>
                    <Input
                        type="month"
                        value={filters.periode ?? ''}
                        onChange={(e) =>
                            onFilterChange('periode', e.target.value)
                        }
                        className="h-10 w-40 text-xs"
                        aria-label="Periode bulan"
                    />
                </div>
            )}

            {showDateRange && (
                <div className="flex items-center gap-1.5">
                    <span className="text-muted-foreground whitespace-nowrap text-xs font-medium">
                        Tanggal:
                    </span>
                    <Input
                        type="date"
                        value={filters.dari ?? ''}
                        onChange={(e) => onFilterChange('dari', e.target.value)}
                        className="h-10 w-36 text-xs"
                        aria-label="Dari tanggal"
                    />
                    <span className="text-muted-foreground text-xs">—</span>
                    <Input
                        type="date"
                        value={filters.sampai ?? ''}
                        onChange={(e) =>
                            onFilterChange('sampai', e.target.value)
                        }
                        className="h-10 w-36 text-xs"
                        aria-label="Sampai tanggal"
                    />
                </div>
            )}

            <div className="ms-auto flex items-center gap-2">
                {activeFilterCount > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onReset}
                        title="Atur ulang filter"
                        className="text-muted-foreground hover:text-foreground h-9 gap-1 px-2.5 text-xs"
                    >
                        <RotateCcw className="size-3.5" />
                        <span>Atur ulang</span>
                    </Button>
                )}
                {onRefresh && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onRefresh}
                        disabled={loading}
                        title="Segarkan data terbaru"
                        className="h-9 gap-1.5 px-3 text-xs"
                    >
                        <RefreshCw
                            className={`size-3.5 ${loading ? 'animate-spin' : ''}`}
                        />
                        <span>Segarkan</span>
                    </Button>
                )}
            </div>
        </div>
    );
}
