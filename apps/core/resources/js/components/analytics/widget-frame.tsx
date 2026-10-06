import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import { Skeleton } from '@apperp/ui/skeleton';
import {
    ChartColumn,
    CircleSlash,
    Ellipsis,
    LockKeyhole,
    RotateCw,
    Table2,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { useWidgetData } from '@/components/analytics/use-widget-data';
import {
    CHART_HEIGHT,
    isChartType,
    WidgetContent,
} from '@/components/analytics/widget-content';
import type { WidgetFailure } from '@/lib/analytics/api';
import { formatComputedAt } from '@/lib/analytics/format';
import type {
    AnalyticsResult,
    DashboardWidget,
    TextVisual,
} from '@/lib/analytics/types';
import { cn } from '@/lib/utils';

type WidgetFrameProps = {
    widget: DashboardWidget;
    /** Tinggi di grid, 1–3 baris. */
    height?: number;
    /** Membuka pembangun widget (area 8); tanpa ini tidak ada tombol Ubah. */
    onEdit?: (widget: DashboardWidget) => void;
    /** Mengganti judul (atau isi widget teks). */
    onRename?: (widget: DashboardWidget) => void;
    onArchive?: (widget: DashboardWidget) => void;
    className?: string;
};

/**
 * Bingkai satu widget dasbor (`docs/todo/analitik/dasbor-dan-visual.md`, butir 7.3): judul dan menu, data
 * yang dimuat saat widget terlihat, kerangka saat memuat, dan setiap keadaan yang tidak menghasilkan angka
 * dengan alasannya — kolom yang hilang, data yang tidak tersedia, tanpa akses, data pribadi, perhitungan
 * terlalu berat, kosong — beserta tindakan yang dapat dilakukan. Satu widget yang gagal tidak menjatuhkan
 * dasbor. Di bawahnya: kapan angka dihitung (zona engine), tanda bila hasilnya dipotong, dan Muat ulang.
 *
 * Tombol hanya tampil bila pemanggil memberinya tindakan: area 7 memberi Ganti judul dan Arsipkan, area 8
 * menambah Ubah lewat `onEdit` tanpa mengubah bingkai ini.
 */
export function WidgetFrame({
    widget,
    height = 2,
    onEdit,
    onRename,
    onArchive,
    className,
}: WidgetFrameProps) {
    const hasData =
        widget.type !== 'text' &&
        widget.query !== null &&
        widget.status === 'ok';
    const { ref, loading, result, failure, previous, reload } = useWidgetData(
        widget.id,
        hasData,
    );
    const [asTable, setAsTable] = useState(false);
    const chart = isChartType(widget.type);
    const shown = result ?? (loading ? previous : null);
    const menu: Array<
        | {
              label: string;
              icon?: LucideIcon;
              onSelect: () => void;
              destructive?: boolean;
          }
        | 'separator'
    > = [
        ...(chart && hasData
            ? [
                  {
                      label: asTable
                          ? 'Lihat sebagai grafik'
                          : 'Lihat sebagai tabel',
                      icon: asTable ? ChartColumn : Table2,
                      onSelect: () => setAsTable((value) => !value),
                  },
              ]
            : []),
        ...(hasData
            ? [
                  {
                      label: 'Muat ulang',
                      icon: RotateCw,
                      onSelect: reload,
                  },
              ]
            : []),
        ...((onEdit || onRename || onArchive) && hasData
            ? (['separator'] as const)
            : []),
        ...(onEdit && widget.type !== 'text'
            ? [{ label: 'Ubah', onSelect: () => onEdit(widget) }]
            : []),
        ...(onRename
            ? [
                  {
                      label:
                          widget.type === 'text' ? 'Ubah teks' : 'Ganti judul',
                      onSelect: () => onRename(widget),
                  },
              ]
            : []),
        ...(onArchive
            ? [
                  {
                      label: 'Arsipkan',
                      destructive: true,
                      onSelect: () => onArchive(widget),
                  },
              ]
            : []),
    ];

    return (
        <Card
            ref={ref}
            className={cn('min-w-0', className)}
            aria-busy={loading || undefined}
        >
            <CardHeader>
                <CardTitle className="min-w-0 truncate" title={widget.title}>
                    {widget.title}
                </CardTitle>
                {menu.length > 0 && (
                    <CardAction>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    aria-label={`Pilihan untuk ${widget.title}`}
                                >
                                    <Ellipsis />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {menu.map((item, index) =>
                                    item === 'separator' ? (
                                        <DropdownMenuSeparator key={index} />
                                    ) : (
                                        <DropdownMenuItem
                                            key={item.label}
                                            variant={
                                                item.destructive
                                                    ? 'destructive'
                                                    : 'default'
                                            }
                                            onSelect={item.onSelect}
                                        >
                                            {item.icon && <item.icon />}
                                            {item.label}
                                        </DropdownMenuItem>
                                    ),
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="min-h-0 flex-1">
                <FrameBody
                    widget={widget}
                    height={height}
                    hasData={hasData}
                    loading={loading}
                    result={shown}
                    failure={failure}
                    asTable={asTable}
                    onReload={reload}
                    onEdit={onEdit}
                />
            </CardContent>
            {hasData && shown !== null && (
                <CardFooter className="flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span>{formatComputedAt(shown.meta)}</span>
                    {shown.meta.truncated && (
                        <Badge variant="outline" className="font-normal">
                            Hanya {shown.rows.length} baris pertama
                        </Badge>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="ms-auto size-7"
                        aria-label={`Muat ulang ${widget.title}`}
                        title="Muat ulang"
                        disabled={loading}
                        onClick={reload}
                    >
                        <RotateCw className={cn(loading && 'animate-spin')} />
                    </Button>
                </CardFooter>
            )}
        </Card>
    );
}

function FrameBody({
    widget,
    height,
    hasData,
    loading,
    result,
    failure,
    asTable,
    onReload,
    onEdit,
}: {
    widget: DashboardWidget;
    height: number;
    hasData: boolean;
    loading: boolean;
    result: AnalyticsResult | null;
    failure: WidgetFailure | null;
    asTable: boolean;
    onReload: () => void;
    onEdit?: (widget: DashboardWidget) => void;
}) {
    const edit = onEdit && (
        <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => onEdit(widget)}
        >
            Ubah
        </Button>
    );

    if (widget.type === 'text') {
        // Teks biasa dengan baris baru; tidak pernah dibaca sebagai HTML atau Markdown.
        return (
            <p className="text-sm break-words whitespace-pre-line">
                {(widget.visual as TextVisual).text}
            </p>
        );
    }

    if (widget.status === 'field_removed') {
        return (
            <FrameState icon={TriangleAlert} action={edit}>
                {`Kolom ${widget.missing_fields.map((field) => `"${field}"`).join(', ')} sudah tidak tersedia di data ini, jadi bagian ini tidak dapat dihitung.`}
            </FrameState>
        );
    }

    if (widget.status === 'dataset_unavailable' || !hasData) {
        return (
            <FrameState icon={CircleSlash}>
                Data bagian ini tidak tersedia lagi. Aplikasinya mungkin sudah
                tidak terpasang.
            </FrameState>
        );
    }

    if (failure !== null && !loading) {
        const locked =
            failure.kind === 'forbidden' || failure.kind === 'personal_data';
        const retry = ['other', 'busy', 'timeout'].includes(failure.kind);
        const editable =
            edit &&
            ['timeout', 'field_removed', 'invalid'].includes(failure.kind);

        return (
            <FrameState
                icon={locked ? LockKeyhole : TriangleAlert}
                tone={locked ? 'muted' : 'warning'}
                action={
                    retry || editable ? (
                        <>
                            {retry && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={onReload}
                                >
                                    <RotateCw />
                                    Muat ulang
                                </Button>
                            )}
                            {editable && edit}
                        </>
                    ) : undefined
                }
            >
                {failure.message}
            </FrameState>
        );
    }

    if (result === null) {
        return widget.type === 'kpi' ? (
            <Skeleton className="h-9 w-40" />
        ) : (
            <Skeleton
                className={cn(
                    'w-full',
                    CHART_HEIGHT[height] ?? CHART_HEIGHT[2],
                )}
            />
        );
    }

    if (result.rows.length === 0) {
        return (
            <FrameState icon={CircleSlash} tone="muted">
                Belum ada data untuk ditampilkan. Angkanya muncul setelah ada
                catatan yang cocok dengan saringan bagian ini.
            </FrameState>
        );
    }

    return (
        <div className={cn(loading && 'opacity-60 transition-opacity')}>
            <WidgetContent
                type={widget.type}
                visual={widget.visual}
                result={result}
                title={widget.title}
                height={height}
                asTable={asTable}
            />
        </div>
    );
}

/** Keadaan tanpa angka: ikon, alasannya, dan tindakan bila ada. Warna bukan satu-satunya sinyal. */
function FrameState({
    icon: Icon,
    tone = 'warning',
    action,
    children,
}: {
    icon: LucideIcon;
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
