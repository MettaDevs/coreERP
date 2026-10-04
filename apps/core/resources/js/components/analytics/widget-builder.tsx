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
import { buildQuery, emptyQuery } from '@/lib/analytics/query';
import type {
    AnalyticsQuery,
    DashboardSummary,
    DashboardWidget,
    DatasetSummary,
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

/**
 * Pembangun bagian dasbor (area 8.1): `Sheet` sisi kanan dengan tujuh langkah — Data, Nilai, Kelompokkan menurut,
 * Saring, Periode, Tampilan, Pratinjau — isi bergulir, dan Simpan/Batal tetap di bawah. Setiap `Select` di dalamnya
 * menerima `portalContainer` isi `Sheet`, supaya menunya dapat diklik di atas lapisan ini.
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
    const [query, setQuery] = useState<AnalyticsQuery>(
        () => widget?.query ?? initial?.query ?? emptyQuery(),
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
    const times = dataset?.times ?? [];
    const type = effectiveVisual(chosenType, query, times);
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
    const placement = queryErrorPlacement(preview.error);
    const visual = defaultVisual(
        type,
        stored,
        times,
        widget !== null && widget.type === type ? widget.visual : undefined,
    );
    const suggested = suggestedTitle(stored, dataset);
    const shownTitle = title ?? suggested;
    // Saringan yang tidak terbaca baru ditolak saat dihitung, bukan saat disimpan; bagian seperti itu tidak disimpan.
    const rejected =
        preview.error instanceof CoreApiError && preview.error.status === 422;
    const canSave =
        ready &&
        !rejected &&
        dashboardId !== null &&
        shownTitle.trim() !== '' &&
        !saving;

    const save = async () => {
        if (!canSave || dashboardId === null) {
            return;
        }

        setSaving(true);
        setErrors({});

        const input = {
            title: shownTitle.trim(),
            type,
            query: stored,
            visual,
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
                        Pilih data dan nilai yang dihitung, lalu atur
                        pengelompokan, saringan, periode, dan tampilannya.
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
                        {catalog.failed ? (
                            <p className="text-sm" role="status">
                                Daftar data belum dapat dimuat. Tutup lalu coba
                                lagi.
                            </p>
                        ) : (
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
                        )}
                        {dataset !== null && (
                            <Step title="6. Tampilan">
                                <VisualPicker
                                    query={stored}
                                    times={times}
                                    value={type}
                                    onChange={setChosenType}
                                />
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
                                </Field>
                            </Step>
                        )}
                        {query.dataset !== '' && (
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
