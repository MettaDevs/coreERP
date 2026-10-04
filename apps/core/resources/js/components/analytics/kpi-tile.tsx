import { CircleCheck, CircleHelp, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { formatMeasureValue } from '@/lib/analytics/format';
import { measureColumns } from '@/lib/analytics/query';
import type {
    KpiVisual,
    ResultSet,
    ThresholdStyle,
} from '@/lib/analytics/types';
import { cn } from '@/lib/utils';

/**
 * Gaya rentang ambang dipetakan ke token tema, bukan warna mentah, supaya tema gelap ikut benar
 * (`docs/todo/analitik/dasbor-dan-visual.md`, bagian *Widget*). Warna tidak pernah menjadi satu-satunya
 * sinyal: gaya bermakna membawa ikon, dan setiap rentang membawa teks.
 */
const STYLE: Record<ThresholdStyle, { text: string; icon: LucideIcon | null }> =
    {
        favorable: { text: 'text-success', icon: CircleCheck },
        unfavorable: { text: 'text-destructive', icon: TriangleAlert },
        ambiguous: { text: 'text-warning', icon: CircleHelp },
        subordinate: { text: 'text-muted-foreground', icon: null },
        none: { text: '', icon: null },
    };

type Range = 'low' | 'middle' | 'high';

/**
 * Rentang nilai menurut ambang, persis seperti Cue Setup Business Central (`CuesAndKPIsImpl`): di bawah
 * ambang pertama rendah, di atas ambang kedua tinggi, selain itu tengah.
 */
function rangeOf(
    value: number,
    thresholds: NonNullable<KpiVisual['thresholds']>,
): Range {
    if (value < thresholds.threshold1) {
        return 'low';
    }

    return value > thresholds.threshold2 ? 'high' : 'middle';
}

/** Teks rentang untuk dibaca semua orang, termasuk yang tidak membedakan warna. */
function describeRange(
    range: Range,
    thresholds: NonNullable<KpiVisual['thresholds']>,
    limit: (value: number) => string,
): string {
    switch (range) {
        case 'low':
            return `Di bawah ambang ${limit(thresholds.threshold1)}`;
        case 'high':
            return `Di atas ambang ${limit(thresholds.threshold2)}`;
        default:
            return `Di antara ambang ${limit(thresholds.threshold1)} dan ${limit(thresholds.threshold2)}`;
    }
}

/**
 * Tile angka: satu nilai, satu baris per mata uang (uang lintas mata uang tidak pernah dijumlah). Uang
 * ditulis ringkas bawaannya ("Rp 1,3 M"); nilai lengkapnya ada di `title` dan untuk pembaca layar. Tinggi
 * tile mengikuti isinya, tidak ikut melar setinggi grafik di sebelahnya.
 */
export function KpiTile({
    result,
    visual,
}: {
    result: ResultSet;
    visual: KpiVisual;
}) {
    const measures = measureColumns(result);
    const measure =
        measures.find((column) => column.key === visual.measure) ?? measures[0];

    if (measure === undefined) {
        return null;
    }

    const compact = visual.compact ?? measure.format === 'money';
    const thresholds = visual.thresholds;

    return (
        <div className="space-y-3">
            {result.rows.map((row, index) => {
                const full = formatMeasureValue(measure, row);
                const shown = compact
                    ? formatMeasureValue(measure, row, true)
                    : full;
                const raw = row[measure.key];
                const range =
                    thresholds && raw !== null && !Number.isNaN(Number(raw))
                        ? rangeOf(Number(raw), thresholds)
                        : null;
                const style =
                    STYLE[thresholds && range ? thresholds[range] : 'none'];
                const Icon = style.icon;
                const caption =
                    thresholds && range
                        ? describeRange(range, thresholds, (value) =>
                              formatMeasureValue(measure, {
                                  ...row,
                                  [measure.key]: value,
                              }),
                          )
                        : null;

                return (
                    <div key={index} className="space-y-1">
                        <p
                            className={cn(
                                'flex items-center gap-2 text-3xl font-semibold tabular-nums',
                                style.text,
                            )}
                            title={compact ? full : undefined}
                        >
                            {Icon && (
                                <Icon className="size-6 shrink-0" aria-hidden />
                            )}
                            <span aria-hidden={compact}>{shown}</span>
                            {compact && <span className="sr-only">{full}</span>}
                        </p>
                        {caption && (
                            <p className="text-xs text-muted-foreground">
                                {caption}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
