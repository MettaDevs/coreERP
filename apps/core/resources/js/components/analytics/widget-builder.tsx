import { Button } from '@apperp/ui/button';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { QueryEditor, Step } from '@/components/analytics/query-editor';
import { QueryResult } from '@/components/analytics/query-result';
import { useDatasetDescription } from '@/components/analytics/use-dataset-description';
import {
    queryErrorPlacement,
    useQueryPreview,
} from '@/components/analytics/use-query-preview';
import { VisualPicker } from '@/components/analytics/visual-picker';
import {
    createWidget,
    fetchDashboards,
    fetchDatasets,
    updateWidget,
} from '@/lib/analytics/api';
import { buildQuery, dimensionField, emptyQuery } from '@/lib/analytics/query';
import type {
    AnalyticsQuery,
    BlendQuery,
    DashboardSummary,
    DashboardWidget,
    DatasetDescription,
    DatasetSummary,
    TableVisual,
    WidgetType,
} from '@/lib/analytics/types';
import type { DataWidgetType } from '@/lib/analytics/visual';
import {
    defaultVisual,
    effectiveVisual,
    isDataWidgetType,
    suggestedTitle,
} from '@/lib/analytics/visual';
import { CoreApiError, toastSaveError } from '@/lib/core-api';

/** Baris terbanyak pratinjau; bagian yang disimpan tetap menghitung semua baris dalam batas server. */
const PREVIEW_ROWS = 50;

function selectedSharedDimension(
    query: AnalyticsQuery,
    dataset: DatasetDescription | null,
): string | null {
    const dimensions = query.dimensions ?? [];

    if (
        dataset === null ||
        query.dataset !== dataset.code ||
        dimensions.length !== 1
    ) {
        return null;
    }

    const dimension = dimensions[0];

    if (dimension === undefined) {
        return null;
    }

    if (typeof dimension !== 'string' && dimension.granularity !== undefined) {
        return null;
    }

    return (
        dataset.fields.find((field) => field.key === dimensionField(dimension))
            ?.shared_dimension || null
    );
}

/**
 * Pembangun bagian dasbor: query satu data atau dua data yang digabungkan, tetap di `Sheet` sisi kanan dengan isi
 * bergulir dan Simpan/Batal di bawah. Setiap `Select` di dalamnya menerima `portalContainer` isi `Sheet`.
 *
 * Dipakai untuk menambah dan mengubah bagian di halaman dasbor, dan untuk menyimpan hasil penjelajah ke dasbor
 * (`dashboard` kosong: pengguna memilih dasbor yang boleh ia ubah). Pratinjau menjalankan query yang sama dengan
 * yang disimpan, dengan batas baris kecil, sebagai pengguna yang menyusun. Server memeriksa ulang query dan
 * tampilannya saat disimpan, dan galatnya ditampilkan apa adanya.
 */
export function WidgetBuilder({
    dashboard,
    widget = null,
    initial,
    datasets: given,
    onClose,
    onSaved,
}: {
    /** Dasbor tujuan; kosong berarti pengguna memilihnya di langkah pertama. */
    dashboard: { id: string } | null;
    /** Bagian yang diubah; kosong untuk bagian baru. */
    widget?: DashboardWidget | null;
    /** Isi awal bagian baru, misalnya dari penjelajah. */
    initial?: { query: AnalyticsQuery; type: DataWidgetType };
    /** Katalog data bila pemanggil sudah memegangnya; tanpa ini dimuat sendiri. */
    datasets?: DatasetSummary[];
    onClose: () => void;
    onSaved: (widget: DashboardWidget) => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    const [query, setQuery] = useState<AnalyticsQuery>(() => {
        const saved = widget?.query;

        return saved !== null && saved !== undefined && !('queries' in saved)
            ? saved
            : (initial?.query ?? emptyQuery());
    });
    const [blendQueries, setBlendQueries] = useState<
        [AnalyticsQuery, AnalyticsQuery]
    >(() => {
        const saved = widget?.query;

        return widget?.type === 'blend' &&
            saved !== null &&
            saved !== undefined &&
            'queries' in saved
            ? saved.queries
            : [emptyQuery(), emptyQuery()];
    });
    const [mode, setMode] = useState<'single' | 'blend'>(() =>
        widget?.type === 'blend' ? 'blend' : 'single',
    );
    const [chosenType, setChosenType] = useState<DataWidgetType | null>(() =>
        widget !== null && isDataWidgetType(widget.type)
            ? widget.type
            : (initial?.type ?? null),
    );
    // `null` berarti judul mengikuti saran dari isi query sampai pengguna mengetik sendiri.
    const [title, setTitle] = useState<string | null>(widget?.title ?? null);
    const [dashboardId, setDashboardId] = useState<string | null>(
        dashboard?.id ?? null,
    );
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const catalog = useDatasets(given);
    const targets = useEditableDashboards(dashboard === null);
    const description = useDatasetDescription(
        query.dataset === '' ? null : query.dataset,
    );
    const dataset = description.description;
    const firstBlendDescription = useDatasetDescription(
        blendQueries[0].dataset === '' ? null : blendQueries[0].dataset,
    );
    const secondBlendDescription = useDatasetDescription(
        blendQueries[1].dataset === '' ? null : blendQueries[1].dataset,
    );
    const blendDescriptions = [
        firstBlendDescription,
        secondBlendDescription,
    ] as const;
    const blendDatasets = blendDescriptions.map((item) => item.description);
    const sharedDimension = selectedSharedDimension(
        blendQueries[0],
        blendDatasets[0],
    );
    const secondDatasets =
        sharedDimension === null
            ? []
            : catalog.datasets.filter(
                  (item) =>
                      item.code !== blendQueries[0].dataset &&
                      item.shared_dimensions.includes(sharedDimension),
              );
    const times = dataset?.times ?? [];
    const type = effectiveVisual(chosenType, query, times);
    const activeType: WidgetType = mode === 'blend' ? 'blend' : type;
    const grouped = (query.dimensions ?? []).length > 0;
    const stored = buildQuery({
        ...query,
        totals: type === 'table' && grouped ? true : undefined,
    });
    const ready = dataset !== null && stored.measures.length > 0;
    const preview = useQueryPreview(
        ready
            ? {
                  ...stored,
                  limit: Math.min(stored.limit ?? PREVIEW_ROWS, PREVIEW_ROWS),
              }
            : null,
    );
    const storedBlend: BlendQuery = {
        queries: [buildQuery(blendQueries[0]), buildQuery(blendQueries[1])],
    };
    const secondSharedDimension = selectedSharedDimension(
        blendQueries[1],
        blendDatasets[1],
    );
    const blendReady =
        sharedDimension !== null &&
        secondSharedDimension === sharedDimension &&
        blendQueries[0].dataset !== blendQueries[1].dataset &&
        secondDatasets.some((item) => item.code === blendQueries[1].dataset) &&
        blendDatasets[0] !== null &&
        blendDatasets[1] !== null &&
        storedBlend.queries[0].measures.length > 0 &&
        storedBlend.queries[1].measures.length > 0;
    const blendPreview = useQueryPreview(
        blendReady
            ? {
                  queries: storedBlend.queries.map((item) => ({
                      ...item,
                      limit: Math.min(item.limit ?? PREVIEW_ROWS, PREVIEW_ROWS),
                  })) as [AnalyticsQuery, AnalyticsQuery],
              }
            : null,
    );
    const blendPlacement = queryErrorPlacement(blendPreview.error);
    const placement = queryErrorPlacement(preview.error);
    const visual = defaultVisual(
        type,
        stored,
        times,
        widget !== null && widget.type === type ? widget.visual : undefined,
    );
    const suggested = suggestedTitle(stored, dataset);
    const blendSuggested = blendDatasets
        .map((item) => item?.caption)
        .filter((caption): caption is string => caption !== undefined)
        .join(' + ');
    const shownTitle = title ?? (mode === 'blend' ? blendSuggested : suggested);
    const blendVisual: TableVisual = {
        columns:
            blendPreview.result?.columns
                .filter((column) => !column.implicit)
                .map((column) => column.key) ?? [],
        show_totals: true,
    };
    // Saringan yang tidak terbaca baru ditolak saat dihitung, bukan saat disimpan; bagian seperti itu tidak disimpan.
    const rejected =
        preview.error instanceof CoreApiError && preview.error.status === 422;
    const blendRejected =
        blendPreview.error instanceof CoreApiError &&
        blendPreview.error.status === 422;
    const canSaveSingle =
        ready &&
        !rejected &&
        dashboardId !== null &&
        shownTitle.trim() !== '' &&
        !saving;
    const canSaveBlend =
        blendReady &&
        blendPreview.result !== null &&
        !blendRejected &&
        dashboardId !== null &&
        shownTitle.trim() !== '' &&
        !saving;
    const canSave = mode === 'blend' ? canSaveBlend : canSaveSingle;

    const save = async () => {
        if (!canSave || dashboardId === null) {
            return;
        }

        setSaving(true);
        setErrors({});

        const input = {
            title: shownTitle.trim(),
            type: activeType,
            query: mode === 'blend' ? storedBlend : stored,
            visual: mode === 'blend' ? blendVisual : visual,
        };

        try {
            onSaved(
                widget === null
                    ? await createWidget(dashboardId, input)
                    : await updateWidget(widget, input),
            );
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toastSaveError(caught, 'Bagian belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    const startBlend = () => {
        const shared = (query.dimensions ?? []).find((dimension) =>
            selectedSharedDimension(
                { ...query, dimensions: [dimension] },
                dataset,
            ),
        );
        const first = buildQuery({
            ...query,
            dimensions: shared === undefined ? [] : [shared],
        });

        setBlendQueries([first, emptyQuery()]);
        setMode('blend');
    };

    const updateBlendQuery = (index: 0 | 1, next: AnalyticsQuery) => {
        if (index === 0) {
            const nextSharedDimension = selectedSharedDimension(
                next,
                blendDatasets[0],
            );

            setBlendQueries((current) => [
                next,
                nextSharedDimension === sharedDimension
                    ? current[1]
                    : emptyQuery(),
            ]);

            return;
        }

        setBlendQueries((current) => [current[0], next]);
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={contentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-2xl"
                // Escape di daftar pilihan yang terbuka hanya menutup daftarnya; tanpa ini seluruh pembangun beserta
                // isiannya ikut tertutup.
                onEscapeKeyDown={(event) => {
                    if (
                        event.target instanceof Element &&
                        event.target.closest('[data-slot="combobox-content"]')
                    ) {
                        event.preventDefault();
                    }
                }}
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {widget === null ? 'Bagian baru' : 'Ubah bagian'}
                    </SheetTitle>
                    <SheetDescription>
                        {mode === 'blend'
                            ? 'Pilih dua data dengan satu kolom bersama, lalu pilih nilai yang ingin dibandingkan.'
                            : 'Pilih data dan nilai yang dihitung, lalu atur pengelompokan, saringan, periode, dan tampilannya.'}{' '}
                        Pratinjau mengikuti setiap perubahan.
                    </SheetDescription>
                </SheetHeader>
                <form
                    id="widget-builder-form"
                    className="min-h-0 flex-1 overflow-y-auto px-6 py-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <div className="flex flex-col gap-5">
                        {dashboard === null && (
                            <Step title="Dasbor tujuan">
                                <Field>
                                    <Select
                                        label="Dasbor"
                                        required
                                        items={targets.dashboards.map(
                                            (item) => ({
                                                value: item.id,
                                                label: item.name,
                                            }),
                                        )}
                                        value={dashboardId}
                                        onValueChange={setDashboardId}
                                        placeholder="Pilih dasbor"
                                        searchPlaceholder="Cari dasbor"
                                        emptyMessage="Dasbor tidak ditemukan."
                                        portalContainer={contentRef}
                                    />
                                    {targets.failed ? (
                                        <FieldDescription>
                                            Daftar dasbor belum dapat dimuat.
                                            Tutup lalu coba lagi.
                                        </FieldDescription>
                                    ) : (
                                        !targets.loading &&
                                        targets.dashboards.length === 0 && (
                                            <FieldDescription>
                                                Belum ada dasbor yang dapat Anda
                                                ubah.{' '}
                                                <Link href="/analytics">
                                                    Buat dasbor
                                                </Link>{' '}
                                                lebih dulu.
                                            </FieldDescription>
                                        )
                                    )}
                                </Field>
                            </Step>
                        )}
                        <div
                            className="flex flex-wrap gap-2"
                            role="group"
                            aria-label="Jenis bagian"
                        >
                            <Button
                                type="button"
                                variant={
                                    mode === 'single' ? 'default' : 'outline'
                                }
                                aria-pressed={mode === 'single'}
                                disabled={saving}
                                onClick={() => setMode('single')}
                            >
                                Satu data
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    mode === 'blend' ? 'default' : 'outline'
                                }
                                aria-pressed={mode === 'blend'}
                                disabled={saving}
                                onClick={() => {
                                    if (mode === 'single') {
                                        startBlend();
                                    }
                                }}
                            >
                                Gabungkan dua data
                            </Button>
                        </div>
                        {catalog.failed ? (
                            <p className="text-sm" role="status">
                                Daftar data belum dapat dimuat. Tutup lalu coba
                                lagi.
                            </p>
                        ) : mode === 'single' ? (
                            <QueryEditor
                                numbered
                                datasets={catalog.datasets}
                                value={query}
                                dataset={dataset}
                                datasetLoading={description.loading}
                                datasetFailure={description.failure}
                                errors={placement}
                                onChange={setQuery}
                                portalContainer={contentRef}
                            />
                        ) : (
                            <div className="flex flex-col gap-5">
                                <section className="flex min-w-0 flex-col gap-3">
                                    <h3 className="text-sm font-medium">
                                        Data pertama
                                    </h3>
                                    <QueryEditor
                                        datasets={catalog.datasets}
                                        value={blendQueries[0]}
                                        dataset={blendDatasets[0]}
                                        datasetLoading={
                                            blendDescriptions[0].loading
                                        }
                                        datasetFailure={
                                            blendDescriptions[0].failure
                                        }
                                        errors={blendPlacement}
                                        onChange={(next) =>
                                            updateBlendQuery(0, next)
                                        }
                                        portalContainer={contentRef}
                                        dimensionFilter={(field) =>
                                            field.shared_dimension !==
                                                undefined &&
                                            blendDatasets[0]?.shared_dimensions.includes(
                                                field.shared_dimension,
                                            ) === true
                                        }
                                        maxDimensions={1}
                                        dimensionEmptyMessage="Data ini belum menyediakan kolom bersama."
                                        allowTimeGranularity={false}
                                    />
                                </section>
                                {sharedDimension === null ? (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        role="status"
                                    >
                                        Pilih tepat satu kolom bersama pada data
                                        pertama untuk melihat data yang cocok.
                                    </p>
                                ) : secondDatasets.length === 0 ? (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        role="status"
                                    >
                                        Belum ada data lain yang menyediakan
                                        kolom bersama ini.
                                    </p>
                                ) : (
                                    <section className="flex min-w-0 flex-col gap-3">
                                        <h3 className="text-sm font-medium">
                                            Data kedua
                                        </h3>
                                        <QueryEditor
                                            datasets={secondDatasets}
                                            value={blendQueries[1]}
                                            dataset={blendDatasets[1]}
                                            datasetLoading={
                                                blendDescriptions[1].loading
                                            }
                                            datasetFailure={
                                                blendDescriptions[1].failure
                                            }
                                            errors={blendPlacement}
                                            onChange={(next) =>
                                                updateBlendQuery(1, next)
                                            }
                                            portalContainer={contentRef}
                                            dimensionFilter={(field) =>
                                                field.shared_dimension ===
                                                sharedDimension
                                            }
                                            maxDimensions={1}
                                            dimensionEmptyMessage="Data ini belum memiliki kolom untuk gabungan yang dipilih."
                                            allowTimeGranularity={false}
                                        />
                                    </section>
                                )}
                            </div>
                        )}
                        {(mode === 'blend' || dataset !== null) && (
                            <Step
                                title={
                                    mode === 'blend'
                                        ? 'Tampilan'
                                        : '6. Tampilan'
                                }
                            >
                                {mode === 'single' && (
                                    <VisualPicker
                                        query={stored}
                                        times={times}
                                        value={type}
                                        onChange={setChosenType}
                                    />
                                )}
                                <Field data-invalid={Boolean(errors.title)}>
                                    <Input
                                        label="Judul"
                                        required
                                        maxLength={120}
                                        value={shownTitle}
                                        onChange={(event) =>
                                            setTitle(event.target.value)
                                        }
                                        aria-invalid={Boolean(errors.title)}
                                    />
                                    <FieldError>{errors.title?.[0]}</FieldError>
                                    {mode === 'blend' && (
                                        <FieldDescription>
                                            Hasil gabungan akan ditampilkan
                                            sebagai tabel.
                                        </FieldDescription>
                                    )}
                                </Field>
                            </Step>
                        )}
                        {mode === 'single' && query.dataset !== '' && (
                            <Step title="7. Pratinjau">
                                <QueryResult
                                    ready={ready}
                                    loading={preview.loading}
                                    result={preview.result}
                                    previous={preview.previous}
                                    message={placement.message}
                                    type={type}
                                    visual={visual}
                                    title={shownTitle}
                                    note={
                                        preview.result !== null &&
                                        preview.result.rows.length >=
                                            PREVIEW_ROWS && (
                                            <span>
                                                Pratinjau menampilkan paling
                                                banyak {PREVIEW_ROWS} baris;
                                                bagian yang disimpan menghitung
                                                semuanya.
                                            </span>
                                        )
                                    }
                                    onReload={preview.reload}
                                />
                            </Step>
                        )}
                        {mode === 'blend' && (
                            <Step title="Pratinjau">
                                <QueryResult
                                    ready={blendReady}
                                    loading={blendPreview.loading}
                                    result={blendPreview.result}
                                    previous={blendPreview.previous}
                                    message={blendPlacement.message}
                                    type="blend"
                                    visual={blendVisual}
                                    title={shownTitle}
                                    emptyMessage="Pilih dua data, satu kolom bersama, dan sedikitnya satu nilai pada masing-masing data."
                                    onReload={blendPreview.reload}
                                />
                            </Step>
                        )}
                    </div>
                </form>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" type="button" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        form="widget-builder-form"
                        disabled={!canSave}
                    >
                        {widget === null ? 'Tambahkan ke dasbor' : 'Simpan'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

/** Katalog data dari pemanggil, atau dimuat sekali bila pemanggil tidak memegangnya. */
function useDatasets(given: DatasetSummary[] | undefined) {
    const [fetched, setFetched] = useState<DatasetSummary[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (given !== undefined) {
            return;
        }

        const controller = new AbortController();

        fetchDatasets(controller.signal)
            .then(setFetched)
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [given]);

    return {
        datasets: given ?? fetched ?? [],
        failed: given === undefined && failed,
    };
}

/** Dasbor yang boleh diubah pengguna, untuk menyimpan hasil penjelajah. */
function useEditableDashboards(enabled: boolean) {
    const [dashboards, setDashboards] = useState<DashboardSummary[] | null>(
        null,
    );
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;

        fetchDashboards()
            .then((all) => {
                if (!cancelled) {
                    setDashboards(all.filter((item) => item.can_edit));
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [enabled]);

    return {
        dashboards: dashboards ?? [],
        loading: enabled && dashboards === null && !failed,
        failed,
    };
}
