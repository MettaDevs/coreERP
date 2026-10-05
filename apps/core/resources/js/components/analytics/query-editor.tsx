import { Field, FieldDescription } from '@apperp/ui/field';
import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import { Skeleton } from '@apperp/ui/skeleton';
import type { ReactNode, RefObject } from 'react';
import { DatasetPicker } from '@/components/analytics/dataset-picker';
import { DimensionPicker } from '@/components/analytics/dimension-picker';
import { FilterEditor } from '@/components/analytics/filter-editor';
import { FormulaEditor } from '@/components/analytics/formula-editor';
import { MeasurePicker } from '@/components/analytics/measure-picker';
import { TimeRangePicker } from '@/components/analytics/time-range-picker';
import type { FormulaError } from '@/components/analytics/use-query-preview';
import {
    COMPARE_MODES,
    dimensionField,
    emptyQuery,
    formulaKeys,
    newFormulaKey,
} from '@/lib/analytics/query';
import type {
    AnalyticsQuery,
    DatasetDescription,
    DatasetSummary,
    QueryCompare,
    QueryFormula,
} from '@/lib/analytics/types';

const NO_COMPARE = 'none';

/**
 * Langkah menyusun query, dipakai pembangun bagian dasbor dan penjelajah: Data, Nilai, Kelompokkan menurut, Saring,
 * dan Periode — urutan cara pengguna bertanya ("nilai apa, dikelompokkan menurut apa"), bukan urutan JSON. Isinya
 * dikendalikan pemanggil: pembangun memegang draf di state, penjelajah membacanya dari URL.
 *
 * Mengganti data mengosongkan langkah lain karena kolomnya milik data sebelumnya. Galat saringan, periode, dan rumus
 * dari server tampil di isiannya.
 *
 * Area 13 menambah tiga isian tanpa menambah langkah: rumus dan persen terhadap total di langkah Nilai, dan
 * perbandingan periode di langkah Periode. Kunci rumus dipilih di `measures` sesudah nilai data, dan rumus diurutkan
 * menurut kuncinya seperti di server, supaya galat `formulas.<n>` menunjuk rumus yang sama.
 */
export function QueryEditor({
    datasets,
    value,
    dataset,
    datasetLoading,
    datasetFailure,
    errors,
    onChange,
    numbered = false,
    portalContainer,
}: {
    datasets: DatasetSummary[];
    value: AnalyticsQuery;
    /** Isi data yang dipilih, atau `null` selama dimuat atau bila belum dipilih. */
    dataset: DatasetDescription | null;
    datasetLoading: boolean;
    datasetFailure: string | null;
    errors: {
        filters: Record<string, string>;
        timeRange: string | null;
        formula?: FormulaError | null;
    };
    onChange: (query: AnalyticsQuery) => void;
    /** Menomori langkah, untuk alur pembangun. */
    numbered?: boolean;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const title = (step: number, text: string) =>
        numbered ? `${step}. ${text}` : text;
    // Urutan hanya boleh menyebut pengelompokan dan nilai yang masih dipilih, persen terhadap total hanya nilai yang
    // dipilih, dan perbandingan butuh periode; sisanya dibuang di sini.
    const set = (changes: Partial<AnalyticsQuery>) => {
        const next = { ...value, ...changes };
        const keys = [
            ...(next.dimensions ?? []).map(dimensionField),
            ...next.measures,
        ];

        onChange({
            ...next,
            sort: next.sort?.filter((item) => keys.includes(item.key)),
            percent_of_total: next.percent_of_total?.filter((key) =>
                next.measures.includes(key),
            ),
            compare: next.time_range === undefined ? undefined : next.compare,
        });
    };
    // Server menormalkan rumus menurut kunci sebelum memberi path galat formulas.N.expression.
    // Urutan layar harus sama, termasuk sebelum pengguna mengubah isian apa pun.
    const formulas = [...(value.formulas ?? [])].sort((a, b) =>
        a.key < b.key ? -1 : a.key > b.key ? 1 : 0,
    );
    const ownKeys = formulaKeys(value);
    // Nilai data yang dipilih, lalu rumus: pemilih nilai hanya mengenal nilai data.
    const setMeasures = (measures: string[]) =>
        set({ measures: [...measures, ...ownKeys] });
    const setFormulas = (next: QueryFormula[], measures: string[]) =>
        set({
            formulas: [...next].sort((a, b) =>
                a.key < b.key ? -1 : a.key > b.key ? 1 : 0,
            ),
            measures,
        });
    const captionOf = (key: string) =>
        dataset?.measures.find((measure) => measure.key === key)?.caption ??
        formulas.find((formula) => formula.key === key)?.caption ??
        key;

    return (
        <div className="flex flex-col gap-5">
            <Step title={title(1, 'Data')}>
                <DatasetPicker
                    datasets={datasets}
                    value={value.dataset}
                    onChange={(code) => onChange(emptyQuery(code))}
                    portalContainer={portalContainer}
                />
            </Step>
            {value.dataset !== '' &&
                (dataset === null ? (
                    datasetLoading ? (
                        <div className="flex flex-col gap-3" aria-busy>
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                        </div>
                    ) : (
                        <p className="text-sm" role="status">
                            {datasetFailure ??
                                'Isi data ini belum dapat dimuat. Muat ulang halaman.'}
                        </p>
                    )
                ) : (
                    <>
                        <Step title={title(2, 'Nilai')}>
                            <MeasurePicker
                                measures={dataset.measures}
                                value={value.measures.filter(
                                    (key) => !ownKeys.includes(key),
                                )}
                                onChange={setMeasures}
                                portalContainer={portalContainer}
                            />
                            <FormulaEditor
                                measures={dataset.measures}
                                formulas={formulas}
                                error={errors.formula ?? null}
                                onChange={(key, changed) =>
                                    setFormulas(
                                        formulas.map((formula) =>
                                            formula.key === key
                                                ? { key, ...changed }
                                                : formula,
                                        ),
                                        value.measures,
                                    )
                                }
                                onAdd={(added) => {
                                    const key = newFormulaKey(value, [
                                        ...dataset.measures.map(
                                            (measure) => measure.key,
                                        ),
                                        ...dataset.fields.map(
                                            (field) => field.key,
                                        ),
                                    ]);

                                    setFormulas(
                                        [...formulas, { key, ...added }],
                                        [...value.measures, key],
                                    );
                                }}
                                onRemove={(key) =>
                                    setFormulas(
                                        formulas.filter(
                                            (formula) => formula.key !== key,
                                        ),
                                        value.measures.filter(
                                            (measure) => measure !== key,
                                        ),
                                    )
                                }
                                portalContainer={portalContainer}
                            />
                            {value.measures.length > 0 && (
                                <Field>
                                    <MultiSelect
                                        label="Tampilkan juga sebagai persen dari total"
                                        items={value.measures.map((key) => ({
                                            value: key,
                                            label: captionOf(key),
                                        }))}
                                        value={value.percent_of_total ?? []}
                                        onValueChange={(keys) =>
                                            set({ percent_of_total: keys })
                                        }
                                        searchPlaceholder="Cari nilai"
                                        emptyMessage="Nilai tidak ditemukan."
                                        portalContainer={portalContainer}
                                    />
                                </Field>
                            )}
                        </Step>
                        <Step title={title(3, 'Kelompokkan menurut')}>
                            <DimensionPicker
                                fields={dataset.fields}
                                value={value.dimensions ?? []}
                                onChange={(dimensions) => set({ dimensions })}
                                portalContainer={portalContainer}
                            />
                        </Step>
                        <Step title={title(4, 'Saring')}>
                            <FilterEditor
                                key={dataset.code}
                                moduleId={dataset.module_id}
                                fields={dataset.fields}
                                value={value.filters ?? {}}
                                errors={errors.filters}
                                onChange={(filters) => set({ filters })}
                                portalContainer={portalContainer}
                            />
                        </Step>
                        <Step title={title(5, 'Periode')}>
                            <TimeRangePicker
                                key={dataset.code}
                                dataset={dataset}
                                value={value.time_range}
                                error={errors.timeRange}
                                onChange={(time_range) => set({ time_range })}
                                portalContainer={portalContainer}
                            />
                            {dataset.times.length > 0 && (
                                <Field>
                                    <Select
                                        label="Bandingkan dengan"
                                        items={[
                                            {
                                                value: NO_COMPARE,
                                                label: 'Tanpa pembanding',
                                            },
                                            ...COMPARE_MODES.map((mode) => ({
                                                value: mode.value,
                                                label: mode.caption,
                                            })),
                                        ]}
                                        value={
                                            value.time_range === undefined
                                                ? NO_COMPARE
                                                : (value.compare ?? NO_COMPARE)
                                        }
                                        onValueChange={(mode) =>
                                            set({
                                                compare:
                                                    mode === null ||
                                                    mode === NO_COMPARE
                                                        ? undefined
                                                        : (mode as QueryCompare),
                                            })
                                        }
                                        searchPlaceholder="Cari pembanding"
                                        emptyMessage="Pembanding tidak ditemukan."
                                        portalContainer={portalContainer}
                                    />
                                    <FieldDescription>
                                        {value.time_range === undefined
                                            ? 'Pilih periode lebih dulu untuk membandingkannya.'
                                            : 'Setiap nilai mendapat angka pembanding, selisih, dan persen perubahannya.'}
                                    </FieldDescription>
                                </Field>
                            )}
                        </Step>
                    </>
                ))}
        </div>
    );
}

/** Satu langkah: judul kecil dan isiannya. */
export function Step({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="flex min-w-0 flex-col gap-3">
            <h3 className="text-sm font-medium">{title}</h3>
            {children}
        </section>
    );
}
