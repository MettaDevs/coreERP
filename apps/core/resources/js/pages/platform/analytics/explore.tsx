import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field } from '@apperp/ui/field';
import { Select } from '@apperp/ui/select';
import { Head, router, usePage } from '@inertiajs/react';
import { LayoutDashboard, Link2, Save, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { QueryEditor } from '@/components/analytics/query-editor';
import { QueryResult } from '@/components/analytics/query-result';
import { SavedQuerySheet } from '@/components/analytics/saved-query-sheet';
import { useDatasetDescription } from '@/components/analytics/use-dataset-description';
import {
    queryErrorPlacement,
    useQueryPreview,
} from '@/components/analytics/use-query-preview';
import { VisualPicker } from '@/components/analytics/visual-picker';
import { WidgetBuilder } from '@/components/analytics/widget-builder';
import { fetchSavedQueries } from '@/lib/analytics/api';
import {
    buildQuery,
    emptyQuery,
    exploreUrl,
    readExploreUrl,
} from '@/lib/analytics/query';
import type {
    AnalyticsQuery,
    DashboardAbilities,
    DatasetSummary,
    SavedQuery,
} from '@/lib/analytics/types';
import type { DataWidgetType } from '@/lib/analytics/visual';
import {
    defaultVisual,
    isDataWidgetType,
    suggestedTitle,
    visualUnavailableReason,
} from '@/lib/analytics/visual';
import { CoreApiError } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    datasets: DatasetSummary[];
    abilities: DashboardAbilities;
};

/**
 * Analisis data (`/analytics/explore`, area 8.5–8.6): penjelajah satu data, padanan *Data analysis mode* Business
 * Central (KA-21). Pengguna memilih data, nilai, pengelompokan, saringan, dan periode; hasilnya tabel dengan total
 * atau grafik, dan dapat disimpan ke dasbor atau sebagai analisis tersimpan.
 *
 * Query dan jenis tampilannya milik URL (`?q=…&view=…`): dibaca dari `usePage().url` setiap render dan ditulis lewat
 * kunjungan sisi peramban (`router.replace`), tidak pernah disalin ke state. Karena itu tautan halaman ini membuka
 * analisis yang sama, dan kembali/maju di peramban berjalan seperti biasa. Hasil dihitung server sebagai pengguna
 * yang membuka, jadi tautan yang sama dapat memberi angka berbeda bagi orang dengan akses berbeda.
 */
export default function AnalyticsExplore({ datasets, abilities }: Props) {
    const { url } = usePage();
    const state = readExploreUrl(url);
    const query = state.query ?? emptyQuery();
    const description = useDatasetDescription(
        query.dataset === '' ? null : query.dataset,
    );
    const dataset = description.description;
    const times = dataset?.times ?? [];
    const view: DataWidgetType =
        isDataWidgetType(state.view) &&
        visualUnavailableReason(state.view, query, times) === null
            ? state.view
            : 'table';
    // Tabel penjelajah selalu membawa total bila ada pengelompokan; query di URL tidak perlu menyebutnya.
    const run = buildQuery({
        ...query,
        totals: (query.dimensions ?? []).length > 0 ? true : undefined,
    });
    const ready = dataset !== null && run.measures.length > 0;
    const result = useQueryPreview(ready ? run : null);
    const placement = queryErrorPlacement(result.error);
    // Query yang ditolak server (saringan tidak terbaca, batas) tidak ditawarkan untuk disimpan.
    const rejected =
        result.error instanceof CoreApiError && result.error.status === 422;
    const title = suggestedTitle(run, dataset);
    const saved = useSavedQueries();
    const [building, setBuilding] = useState(false);
    const [saving, setSaving] = useState(false);

    const navigate = (next: AnalyticsQuery, nextView: string | null) =>
        router.replace({
            url: exploreUrl(next, nextView),
            preserveScroll: true,
            preserveState: true,
        });

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            toast.success('Tautan analisis disalin.');
        } catch {
            toast.error(
                'Tautan belum dapat disalin. Salin alamat dari bilah alamat peramban.',
            );
        }
    };

    return (
        <>
            <Head title="Analisis data" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 p-6">
                <h1 className="text-xl font-semibold tracking-tight">
                    Analisis data
                </h1>
                {state.unreadable && (
                    <Alert>
                        <TriangleAlert />
                        <AlertTitle>Tautan ini tidak dapat dibaca</AlertTitle>
                        <AlertDescription>
                            Isi analisisnya mungkin terpotong saat disalin.
                            Mulailah dari pilihan data di bawah.
                        </AlertDescription>
                    </Alert>
                )}
                {datasets.length === 0 ? (
                    <Card>
                        <CardContent>
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada data yang dapat dianalisis
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Data muncul di sini setelah aplikasi
                                        yang menyediakannya terpasang dan Anda
                                        mendapat akses untuk melihatnya.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                        <Card className="min-w-0 self-start lg:col-span-4">
                            <CardHeader>
                                <CardTitle>Susun analisis</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-5">
                                {saved.items.length > 0 && (
                                    <Field>
                                        <Select
                                            label="Buka analisis tersimpan"
                                            items={saved.items.map((item) => ({
                                                value: item.id,
                                                label: item.name,
                                            }))}
                                            value={null}
                                            onValueChange={(id) => {
                                                const picked = saved.items.find(
                                                    (item) => item.id === id,
                                                );

                                                if (picked) {
                                                    router.push({
                                                        url: exploreUrl(
                                                            picked.query,
                                                            state.view,
                                                        ),
                                                        preserveState: true,
                                                    });
                                                }
                                            }}
                                            searchPlaceholder="Cari analisis"
                                            emptyMessage="Analisis tidak ditemukan."
                                        />
                                    </Field>
                                )}
                                <QueryEditor
                                    datasets={datasets}
                                    value={query}
                                    dataset={dataset}
                                    datasetLoading={description.loading}
                                    datasetFailure={description.failure}
                                    errors={placement}
                                    onChange={(next) =>
                                        navigate(next, state.view)
                                    }
                                />
                            </CardContent>
                        </Card>
                        <Card className="min-w-0 lg:col-span-8">
                            <CardHeader>
                                <CardTitle className="break-words">
                                    {title === '' ? 'Hasil' : title}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                {dataset !== null && (
                                    <div className="flex flex-col gap-3">
                                        <VisualPicker
                                            query={run}
                                            times={times}
                                            value={view}
                                            onChange={(next) =>
                                                navigate(query, next)
                                            }
                                        />
                                        {ready && (
                                            <div className="flex flex-wrap gap-2">
                                                {abilities.create &&
                                                    !rejected && (
                                                        <>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setBuilding(
                                                                        true,
                                                                    )
                                                                }
                                                            >
                                                                <LayoutDashboard />
                                                                Simpan ke dasbor
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setSaving(
                                                                        true,
                                                                    )
                                                                }
                                                            >
                                                                <Save />
                                                                Simpan analisis
                                                            </Button>
                                                        </>
                                                    )}
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        void copyLink()
                                                    }
                                                >
                                                    <Link2 />
                                                    Salin tautan
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                )}
                                <QueryResult
                                    ready={ready}
                                    loading={result.loading}
                                    result={result.result}
                                    previous={result.previous}
                                    message={placement.message}
                                    type={view}
                                    visual={defaultVisual(view, run, times)}
                                    title={title}
                                    height={3}
                                    onReload={result.reload}
                                />
                            </CardContent>
                        </Card>
                    </div>
                )}
            </main>
            {building && (
                <WidgetBuilder
                    dashboard={null}
                    initial={{ query: run, type: view }}
                    datasets={datasets}
                    onClose={() => setBuilding(false)}
                    onSaved={(widget) => {
                        setBuilding(false);
                        toast.success(
                            `"${widget.title}" ditambahkan ke dasbor.`,
                            {
                                action: {
                                    label: 'Buka dasbor',
                                    onClick: () =>
                                        router.visit(
                                            `/analytics/dashboards/${widget.dashboard_id}`,
                                        ),
                                },
                            },
                        );
                    }}
                />
            )}
            {saving && (
                <SavedQuerySheet
                    query={run}
                    suggestedName={title}
                    abilities={abilities}
                    onClose={() => setSaving(false)}
                    onSaved={(item) => {
                        setSaving(false);
                        saved.reload();
                        toast.success(`Analisis "${item.name}" disimpan.`);
                    }}
                />
            )}
        </>
    );
}

AnalyticsExplore.layout = {
    breadcrumbs: [
        { title: 'Analisis data', href: '/analytics/explore' },
    ] satisfies BreadcrumbItem[],
};

/** Analisis tersimpan milik sendiri dan yang dibagikan, untuk dibuka ulang di penjelajah. */
function useSavedQueries() {
    const [items, setItems] = useState<SavedQuery[]>([]);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        let cancelled = false;

        // Daftar ini pelengkap: bila gagal dimuat, penjelajah tetap dapat dipakai tanpa pilihan membuka.
        fetchSavedQueries()
            .then((next) => !cancelled && setItems(next))
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [attempt]);

    return { items, reload: () => setAttempt((value) => value + 1) };
}
