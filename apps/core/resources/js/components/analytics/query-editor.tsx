import { Skeleton } from '@apperp/ui/skeleton';
import type { ReactNode, RefObject } from 'react';
import { DatasetPicker } from '@/components/analytics/dataset-picker';
import { DimensionPicker } from '@/components/analytics/dimension-picker';
import { FilterEditor } from '@/components/analytics/filter-editor';
import { MeasurePicker } from '@/components/analytics/measure-picker';
import { TimeRangePicker } from '@/components/analytics/time-range-picker';
import { dimensionField, emptyQuery } from '@/lib/analytics/query';
import type {
    AnalyticsQuery,
    DatasetDescription,
    DatasetField,
    DatasetSummary,
} from '@/lib/analytics/types';

/**
 * Langkah menyusun query, dipakai pembangun bagian dasbor dan penjelajah: Data, Nilai, Kelompokkan menurut, Saring,
 * dan Periode — urutan cara pengguna bertanya ("nilai apa, dikelompokkan menurut apa"), bukan urutan JSON. Isinya
 * dikendalikan pemanggil: pembangun memegang draf di state, penjelajah membacanya dari URL.
 *
 * Mengganti data mengosongkan langkah lain karena kolomnya milik data sebelumnya. Galat saringan dan periode dari
 * server tampil di isiannya.
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
    dimensionFilter,
    maxDimensions,
    dimensionEmptyMessage,
    allowTimeGranularity = true,
}: {
    datasets: DatasetSummary[];
    value: AnalyticsQuery;
    /** Isi data yang dipilih, atau `null` selama dimuat atau bila belum dipilih. */
    dataset: DatasetDescription | null;
    datasetLoading: boolean;
    datasetFailure: string | null;
    errors: { filters: Record<string, string>; timeRange: string | null };
    onChange: (query: AnalyticsQuery) => void;
    /** Menomori langkah, untuk alur pembangun. */
    numbered?: boolean;
    portalContainer?: RefObject<HTMLElement | null>;
    dimensionFilter?: (field: DatasetField) => boolean;
    maxDimensions?: number;
    dimensionEmptyMessage?: string;
    allowTimeGranularity?: boolean;
}) {
    const title = (step: number, text: string) =>
        numbered ? `${step}. ${text}` : text;
    // Urutan hanya boleh menyebut pengelompokan dan nilai yang masih dipilih; sisanya dibuang di sini.
    const set = (changes: Partial<AnalyticsQuery>) => {
        const next = { ...value, ...changes };
        const keys = [
            ...(next.dimensions ?? []).map(dimensionField),
            ...next.measures,
        ];

        onChange({
            ...next,
            sort: next.sort?.filter((item) => keys.includes(item.key)),
        });
    };

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
                                value={value.measures}
                                onChange={(measures) => set({ measures })}
                                portalContainer={portalContainer}
                            />
                        </Step>
                        <Step title={title(3, 'Kelompokkan menurut')}>
                            <DimensionPicker
                                fields={
                                    dimensionFilter
                                        ? dataset.fields.filter(dimensionFilter)
                                        : dataset.fields
                                }
                                value={value.dimensions ?? []}
                                onChange={(dimensions) => set({ dimensions })}
                                portalContainer={portalContainer}
                                maxDimensions={maxDimensions}
                                emptyMessage={dimensionEmptyMessage}
                                allowTimeGranularity={allowTimeGranularity}
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
