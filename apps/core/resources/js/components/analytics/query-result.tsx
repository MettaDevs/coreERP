import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Skeleton } from '@apperp/ui/skeleton';
import { CircleSlash, RotateCw, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    CHART_HEIGHT,
    WidgetContent,
} from '@/components/analytics/widget-content';
import { formatComputedAt } from '@/lib/analytics/format';
import type { ResultSet, WidgetVisual } from '@/lib/analytics/types';
import type { DataWidgetType } from '@/lib/analytics/visual';
import { cn } from '@/lib/utils';

/**
 * Hasil query yang sedang disusun, untuk pratinjau pembangun bagian dasbor dan hasil penjelajah: kerangka saat
 * memuat pertama kali, hasil sebelumnya yang diredupkan saat menghitung ulang, kalimat galat dengan Muat ulang,
 * keadaan kosong, lalu tampilan yang dipilih lewat `WidgetContent` area 7 dan kapan angkanya dihitung.
 */
export function QueryResult({
    ready,
    loading,
    result,
    previous,
    message,
    type,
    visual,
    title,
    height = 2,
    note,
    onReload,
}: {
    /** Query sudah lengkap (data dan sedikitnya satu nilai). */
    ready: boolean;
    loading: boolean;
    result: ResultSet | null;
    previous: ResultSet | null;
    /** Kalimat galat untuk area hasil, atau `null`. */
    message: string | null;
    type: DataWidgetType;
    visual: WidgetVisual;
    title: string;
    height?: number;
    /** Catatan tambahan di bawah hasil, misalnya batas baris pratinjau. */
    note?: ReactNode;
    onReload: () => void;
}) {
    if (!ready) {
        return (
            <ResultState icon={CircleSlash}>
                Pilih data dan sedikitnya satu nilai untuk melihat hasilnya.
            </ResultState>
        );
    }

    if (message !== null && !loading) {
        return (
            <ResultState
                icon={TriangleAlert}
                tone="warning"
                action={
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={onReload}
                    >
                        <RotateCw />
                        Muat ulang
                    </Button>
                }
            >
                {message}
            </ResultState>
        );
    }

    const shown = result ?? (loading ? previous : null);

    if (shown === null) {
        return (
            <div aria-busy className="flex flex-col gap-2">
                <Skeleton
                    className={cn(
                        'w-full',
                        CHART_HEIGHT[height] ?? CHART_HEIGHT[2],
                    )}
                />
                <span className="sr-only">Menghitung</span>
            </div>
        );
    }

    return (
        <div
            className="flex min-w-0 flex-col gap-3"
            aria-busy={loading || undefined}
        >
            {shown.rows.length === 0 ? (
                <ResultState icon={CircleSlash}>
                    Tidak ada catatan yang cocok dengan pilihan ini. Longgarkan
                    saringan atau periodenya.
                </ResultState>
            ) : (
                <div
                    className={cn(
                        'min-w-0',
                        loading && 'opacity-60 transition-opacity',
                    )}
                >
                    <WidgetContent
                        type={type}
                        visual={visual}
                        result={shown}
                        title={title}
                        height={height}
                    />
                </div>
            )}
            <div
                className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                role="status"
            >
                <span>
                    {loading
                        ? 'Menghitung ulang…'
                        : formatComputedAt(shown.meta)}
                </span>
                {shown.meta.truncated && (
                    <Badge variant="outline" className="font-normal">
                        Hanya {shown.rows.length} baris pertama
                    </Badge>
                )}
                {note}
            </div>
        </div>
    );
}

function ResultState({
    icon: Icon,
    tone = 'muted',
    action,
    children,
}: {
    icon: typeof CircleSlash;
    tone?: 'warning' | 'muted';
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col items-start gap-3" role="status">
            <p className="flex items-start gap-2 text-sm">
                <Icon
                    className={cn(
                        'mt-0.5 size-4 shrink-0',
                        tone === 'warning'
                            ? 'text-warning'
                            : 'text-muted-foreground',
                    )}
                    aria-hidden
                />
                <span>{children}</span>
            </p>
            {action && <div className="flex flex-wrap gap-2">{action}</div>}
        </div>
    );
}
