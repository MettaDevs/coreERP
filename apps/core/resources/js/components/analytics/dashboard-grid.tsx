import { Button } from '@apperp/ui/button';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import type {
    DashboardLayoutItem,
    DashboardWidget,
} from '@/lib/analytics/types';
import { cn } from '@/lib/utils';

/**
 * Grid 12 kolom dasbor (`docs/todo/analitik/dasbor-dan-visual.md`, bagian *Grid*). Lebar 3, 4, 6, 8, atau 12
 * kolom dan tinggi 1–3 baris, sama dengan yang diterima `PATCH dashboards/{id}`.
 *
 * Kelas Tailwind ditulis lengkap di peta di bawah, **tidak disusun dari string**: `lg:col-span-${w}` tidak
 * pernah dipindai Tailwind dan kelasnya tidak ada di CSS hasil build. Di bawah `lg` setiap widget selebar
 * layar, urut menurut letaknya.
 *
 * Mode ubah fase 1 memindah widget dengan tombol geser dan memilih lebar serta tinggi dari daftar; letak
 * tetap disimpan sebagai `x, y, w, h`, supaya seret-lepas di fase 2 tidak butuh migrasi data.
 */

export const WIDTHS = [3, 4, 6, 8, 12] as const;

export const HEIGHTS = [1, 2, 3] as const;

const COLUMNS = 12;

const WIDTH_CLASS: Record<number, string> = {
    3: 'lg:col-span-3',
    4: 'lg:col-span-4',
    6: 'lg:col-span-6',
    8: 'lg:col-span-8',
    12: 'lg:col-span-12',
};

/** Kolom awal dari `x`, supaya letak yang tersimpan dengan celah tetap dihormati. */
const START_CLASS: Record<number, string> = {
    0: 'lg:col-start-1',
    1: 'lg:col-start-2',
    2: 'lg:col-start-3',
    3: 'lg:col-start-4',
    4: 'lg:col-start-5',
    5: 'lg:col-start-6',
    6: 'lg:col-start-7',
    7: 'lg:col-start-8',
    8: 'lg:col-start-9',
    9: 'lg:col-start-10',
    10: 'lg:col-start-11',
    11: 'lg:col-start-12',
};

const WIDTH_LABEL: Record<number, string> = {
    3: 'Seperempat',
    4: 'Sepertiga',
    6: 'Setengah',
    8: 'Dua pertiga',
    12: 'Penuh',
};

const HEIGHT_LABEL: Record<number, string> = {
    1: 'Pendek',
    2: 'Sedang',
    3: 'Tinggi',
};

/** Letak urut baca: baris demi baris, kiri ke kanan. */
export function sortLayout(
    layout: DashboardLayoutItem[],
): DashboardLayoutItem[] {
    return [...layout].sort((a, b) => a.y - b.y || a.x - b.x);
}

/**
 * Menyusun ulang letak menurut urutannya: kiri ke kanan, pindah baris bila tidak muat dalam 12 kolom.
 * `y` dihitung dalam satuan tinggi, jadi baris berikutnya mulai di bawah widget tertinggi baris itu.
 */
export function reflowLayout(
    ordered: DashboardLayoutItem[],
): DashboardLayoutItem[] {
    let x = 0;
    let y = 0;
    let rowHeight = 0;

    return ordered.map((item) => {
        const w = Math.min(item.w, COLUMNS);

        if (x + w > COLUMNS) {
            y += rowHeight;
            x = 0;
            rowHeight = 0;
        }

        const placed = { ...item, x, y, w };
        x += w;
        rowHeight = Math.max(rowHeight, item.h);

        return placed;
    });
}

/** Menggeser satu widget satu langkah lebih awal (-1) atau lebih akhir (1) dalam urutan baca. */
export function moveInLayout(
    layout: DashboardLayoutItem[],
    widgetId: string,
    step: -1 | 1,
): DashboardLayoutItem[] {
    const ordered = sortLayout(layout);
    const index = ordered.findIndex((item) => item.widget_id === widgetId);
    const target = index + step;

    if (index < 0 || target < 0 || target >= ordered.length) {
        return layout;
    }

    [ordered[index], ordered[target]] = [ordered[target], ordered[index]];

    return reflowLayout(ordered);
}

export function resizeInLayout(
    layout: DashboardLayoutItem[],
    widgetId: string,
    size: Partial<Pick<DashboardLayoutItem, 'w' | 'h'>>,
): DashboardLayoutItem[] {
    return reflowLayout(
        sortLayout(layout).map((item) =>
            item.widget_id === widgetId ? { ...item, ...size } : item,
        ),
    );
}

export function DashboardGrid({
    widgets,
    layout,
    editing = false,
    onLayoutChange,
    renderWidget,
}: {
    widgets: DashboardWidget[];
    layout: DashboardLayoutItem[];
    editing?: boolean;
    onLayoutChange?: (layout: DashboardLayoutItem[]) => void;
    renderWidget: (
        widget: DashboardWidget,
        place: DashboardLayoutItem,
    ) => ReactNode;
}) {
    const byId = new Map(widgets.map((widget) => [widget.id, widget]));
    const ordered = sortLayout(layout).flatMap((place) => {
        const widget = byId.get(place.widget_id);

        return widget ? [{ place, widget }] : [];
    });

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {ordered.map(({ place, widget }, index) => {
                // Tile dan teks setinggi isinya; grafik dan tabel ikut tinggi barisnya supaya sejajar.
                const natural = widget.type === 'kpi' || widget.type === 'text';

                return (
                    <div
                        key={widget.id}
                        className={cn(
                            'flex min-w-0 flex-col gap-2',
                            WIDTH_CLASS[place.w] ?? WIDTH_CLASS[6],
                            START_CLASS[place.x] ?? '',
                            natural && 'self-start',
                        )}
                    >
                        {editing && onLayoutChange && (
                            <EditControls
                                widget={widget}
                                place={place}
                                first={index === 0}
                                last={index === ordered.length - 1}
                                onChange={(next) =>
                                    onLayoutChange(next(layout))
                                }
                            />
                        )}
                        {renderWidget(widget, place)}
                    </div>
                );
            })}
        </div>
    );
}

/** Tombol geser serta pilihan lebar dan tinggi satu widget di mode ubah. */
function EditControls({
    widget,
    place,
    first,
    last,
    onChange,
}: {
    widget: DashboardWidget;
    place: DashboardLayoutItem;
    first: boolean;
    last: boolean;
    onChange: (
        next: (layout: DashboardLayoutItem[]) => DashboardLayoutItem[],
    ) => void;
}) {
    const sized = widget.type !== 'kpi' && widget.type !== 'text';

    return (
        <div
            className="flex flex-wrap items-center gap-2 rounded-md border border-dashed p-2"
            role="group"
            aria-label={`Letak ${widget.title}`}
        >
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-8"
                aria-label={`Geser ${widget.title} ke kiri`}
                title="Geser ke kiri"
                disabled={first}
                onClick={() =>
                    onChange((layout) => moveInLayout(layout, widget.id, -1))
                }
            >
                <ChevronLeft />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-8"
                aria-label={`Geser ${widget.title} ke kanan`}
                title="Geser ke kanan"
                disabled={last}
                onClick={() =>
                    onChange((layout) => moveInLayout(layout, widget.id, 1))
                }
            >
                <ChevronRight />
            </Button>
            <div className="w-36">
                <NativeSelect
                    size="sm"
                    label="Lebar"
                    value={place.w}
                    onChange={(event) =>
                        onChange((layout) =>
                            resizeInLayout(layout, widget.id, {
                                w: Number(event.target.value),
                            }),
                        )
                    }
                >
                    {WIDTHS.map((width) => (
                        <NativeSelectOption key={width} value={width}>
                            {WIDTH_LABEL[width]}
                        </NativeSelectOption>
                    ))}
                </NativeSelect>
            </div>
            {sized && (
                <div className="w-28">
                    <NativeSelect
                        size="sm"
                        label="Tinggi"
                        value={place.h}
                        onChange={(event) =>
                            onChange((layout) =>
                                resizeInLayout(layout, widget.id, {
                                    h: Number(event.target.value),
                                }),
                            )
                        }
                    >
                        {HEIGHTS.map((height) => (
                            <NativeSelectOption key={height} value={height}>
                                {HEIGHT_LABEL[height]}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                </div>
            )}
        </div>
    );
}
