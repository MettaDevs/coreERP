import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';
import {
    ChartArea,
    ChartBar,
    ChartColumn,
    ChartLine,
    ChartPie,
    Hash,
    Table2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import type { AnalyticsQuery } from '@/lib/analytics/types';
import type { DataWidgetType } from '@/lib/analytics/visual';
import { VISUAL_TYPES, visualUnavailableReason } from '@/lib/analytics/visual';

const ICONS: Record<DataWidgetType, LucideIcon> = {
    table: Table2,
    kpi: Hash,
    column: ChartColumn,
    bar: ChartBar,
    line: ChartLine,
    area: ChartArea,
    donut: ChartPie,
};

/**
 * Langkah Tampilan (area 8.3): jenis tampilan hasil. Jenis yang tidak cocok dengan pilihan nilai dan pengelompokan
 * dinonaktifkan, dan alasannya ditulis di bawahnya — bukan hanya di tooltip — supaya pengguna tahu apa yang harus
 * diubah. Aturannya sama dengan pemeriksaan server saat bagian disimpan.
 */
export function VisualPicker({
    query,
    times,
    value,
    onChange,
}: {
    query: AnalyticsQuery;
    /** Kolom tanggal data, untuk grafik garis dan area. */
    times: string[];
    value: DataWidgetType;
    onChange: (type: DataWidgetType) => void;
}) {
    const reasonsId = useId();
    const options = VISUAL_TYPES.map((option) => ({
        ...option,
        reason: visualUnavailableReason(option.type, query, times),
    }));
    const unavailable = options.filter((option) => option.reason !== null);

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <ToggleGroup
                type="single"
                variant="outline"
                size="sm"
                spacing={1}
                className="flex-wrap"
                value={value}
                onValueChange={(next) => {
                    const picked = options.find(
                        (option) => option.type === next,
                    );

                    if (picked && picked.reason === null) {
                        onChange(picked.type);
                    }
                }}
                aria-label="Tampilan"
                aria-describedby={
                    unavailable.length > 0 ? reasonsId : undefined
                }
            >
                {options.map((option) => {
                    const Icon = ICONS[option.type];

                    return (
                        <ToggleGroupItem
                            key={option.type}
                            value={option.type}
                            disabled={option.reason !== null}
                            aria-label={
                                option.reason === null
                                    ? option.caption
                                    : `${option.caption}, tidak tersedia: ${option.reason}`
                            }
                            title={option.reason ?? option.caption}
                        >
                            <Icon aria-hidden />
                            {option.caption}
                        </ToggleGroupItem>
                    );
                })}
            </ToggleGroup>
            {query.measures.length === 0 ? (
                <p id={reasonsId} className="text-xs text-muted-foreground">
                    Pilih sedikitnya satu nilai lebih dulu.
                </p>
            ) : (
                unavailable.length > 0 && (
                    <ul
                        id={reasonsId}
                        className="space-y-0.5 text-xs text-muted-foreground"
                    >
                        {unavailable.map((option) => (
                            <li key={option.type}>
                                <span className="font-medium">
                                    {option.caption}:
                                </span>{' '}
                                {option.reason}
                            </li>
                        ))}
                    </ul>
                )
            )}
        </div>
    );
}
