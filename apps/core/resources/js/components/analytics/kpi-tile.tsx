import {
    CircleCheck,
    CircleHelp,
    Minus,
    TrendingDown,
    TrendingUp,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { formatMeasureValue } from '@/lib/analytics/format';
import { derivedColumns, measureColumns } from '@/lib/analytics/query';
import type {
    KpiVisual,
    ResultColumn,
    ResultDerivation,
    ResultSet,
    ResultValue,
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
 *
 * Dengan perbandingan periode (area 13), di bawah nilainya tertulis naik atau turun berapa persen dan nilai
 * pembandingnya. Arah ditulis dengan kata, bukan hanya warna atau panah, dan tidak diberi warna baik atau buruk:
 * naiknya biaya tidak sama dengan naiknya penjualan.
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
    const derived = derivedColumns(result, measure.key);

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
                        {derived.previous && (
                            <Change
                                row={row}
                                derived={derived}
                                compact={compact}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/** "Naik 12,5% · sebelumnya Rp 1,2 M": arah dan besar perubahan, lalu nilai pembandingnya. */
function Change({
    row,
    derived,
    compact,
}: {
    row: Record<string, ResultValue>;
    derived: Partial<Record<ResultDerivation, ResultColumn>>;
    compact: boolean;
}) {
    const previous = derived.previous;

    if (previous === undefined) {
        return null;
    }

    const changeValue = derived.change ? row[derived.change.key] : null;
    const change =
        changeValue === null || changeValue === undefined
            ? null
            : Number(changeValue);
    const percentColumn = derived.change_pct;
    const percentValue = percentColumn ? row[percentColumn.key] : null;
    const direction =
        change === null || Number.isNaN(change)
            ? null
            : change > 0
              ? { text: 'Naik', icon: TrendingUp }
              : change < 0
                ? { text: 'Turun', icon: TrendingDown }
                : { text: 'Tetap', icon: Minus };
    // Persen dari nol tidak ada; arah tetap ditulis bersama selisihnya.
    const amount =
        percentColumn &&
        percentValue !== null &&
        percentValue !== undefined &&
        change !== 0
            ? formatMeasureValue(percentColumn, {
                  [percentColumn.key]: String(Math.abs(Number(percentValue))),
              })
            : derived.change && change !== null && change !== 0
              ? formatMeasureValue(
                    derived.change,
                    {
                        ...row,
                        [derived.change.key]: String(Math.abs(change)),
                    },
                    compact,
                )
              : null;
    const Icon = direction?.icon;

    return (
        <p
            className="flex items-center gap-1 text-xs text-muted-foreground"
            title={previous.caption}
        >
            {Icon && <Icon className="size-3.5 shrink-0" aria-hidden />}
            <span>
                {direction &&
                    `${direction.text}${amount ? ` ${amount}` : ''} · `}
                sebelumnya {formatMeasureValue(previous, row, compact)}
            </span>
        </p>
    );
}
