import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@apperp/ui/resizable';
import { Select } from '@apperp/ui/select';

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { router, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { analysisColumnTools } from '@/components/analytics/analysis-column-tools';
import { AnalysisDragSettings } from '@/components/analytics/analysis-drag-settings';
import { AnalysisTreeGrid } from '@/components/analytics/analysis-tree-grid';
import { AnalysisViewTabs } from '@/components/analytics/analysis-view-tabs';
import { FilterEditor } from '@/components/analytics/filter-editor';
import { ResultTable } from '@/components/analytics/result-table';
import { useDatasetDescription } from '@/components/analytics/use-dataset-description';
import {
    queryErrorPlacement,
    useQueryPreview,
} from '@/components/analytics/use-query-preview';
import { FormErrorHeader } from '@/components/form-error-header';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    formatDimensionValue,
    formatMeasureValue,
} from '@/lib/analytics/format';
import type {
    AnalyticsQuery,
    DatasetDescription,
    ResultColumn,
    ResultSet,
    ResultValue,
} from '@/lib/analytics/types';

type Layout = {
    mode: 'records' | 'analysis';
    columns: string[];
    column_order?: string[];
    groups: string[];
    measures: string[];
    filters: Record<string, string | string[]>;
    pivot: string;
    sort?: AnalyticsQuery['sort'];
};
type Row = Record<string, ResultValue>;
type SavedView = { name: string; layout: Layout };

function partitionFields(
    dataset: DatasetDescription,
    layout: Layout,
): string[] {
    return [
        ...dataset.partitions,
        ...dataset.measures
            .filter((measure) => layout.measures.includes(measure.key))
            .flatMap((measure) => [measure.currency_key, measure.unit_key])
            .filter((key): key is string => key !== undefined),
    ];
}

function subscribeViews(onChange: () => void) {
    window.addEventListener('storage', onChange);

    return () => window.removeEventListener('storage', onChange);
}

function storedViews(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return '!unavailable';
    }
}

function decodeViews(raw: string | null): {
    views: SavedView[];
    failed: boolean;
    activeName?: string;
} {
    try {
        const value: unknown = raw === null ? [] : JSON.parse(raw);
        const entries = Array.isArray(value)
            ? value
            : value !== null && typeof value === 'object' && 'views' in value
              ? value.views
              : null;
        const activeName =
            value !== null &&
            typeof value === 'object' &&
            'activeName' in value &&
            typeof value.activeName === 'string'
                ? value.activeName
                : undefined;

        return {
            views: Array.isArray(entries)
                ? entries
                      .filter(
                          (entry): entry is SavedView =>
                              entry !== null &&
                              typeof entry === 'object' &&
                              typeof entry.name === 'string' &&
                              entry.name.length > 0 &&
                              entry.name.length <= 80 &&
                              typeof entry.layout === 'object',
                      )
                      .slice(-20)
                : [],
            failed: !Array.isArray(entries),
            activeName,
        };
    } catch {
        return { views: [], failed: true };
    }
}

/** Membaca susunan awal dan tautan lama; interaksi selanjutnya dimiliki workspace di layar. */
function readLayout(url: string, fallback: Layout): Layout {
    const raw = new URL(url, 'http://localhost').searchParams.get('analysis');

    if (raw === null || raw.length > 20000) {
        return fallback;
    }

    try {
        const value: unknown = JSON.parse(raw);

        if (value === null || typeof value !== 'object') {
            return fallback;
        }

        const candidate = value as Partial<Layout>;

        if (
            candidate.column_order !== undefined &&
            (!Array.isArray(candidate.column_order) ||
                !candidate.column_order.every((key) => typeof key === 'string'))
        ) {
            return fallback;
        }

        if (
            candidate.sort !== undefined &&
            (!Array.isArray(candidate.sort) ||
                candidate.sort.length > 3 ||
                !candidate.sort.every(
                    (sort) =>
                        sort !== null &&
                        typeof sort === 'object' &&
                        typeof sort.key === 'string' &&
                        (sort.direction === 'asc' || sort.direction === 'desc'),
                ))
        ) {
            return fallback;
        }

        if (
            (candidate.mode !== 'records' && candidate.mode !== 'analysis') ||
            !['columns', 'groups', 'measures'].every((key) => {
                const items =
                    candidate[key as 'columns' | 'groups' | 'measures'];

                return (
                    Array.isArray(items) &&
                    items.every((item) => typeof item === 'string')
                );
            }) ||
            typeof candidate.pivot !== 'string' ||
            candidate.filters === null ||
            typeof candidate.filters !== 'object' ||
            !Object.values(candidate.filters).every(
                (value) =>
                    typeof value === 'string' ||
                    (Array.isArray(value) &&
                        value.every((item) => typeof item === 'string')),
            )
        ) {
            return fallback;
        }

        return candidate as Layout;
    } catch {
        return fallback;
    }
}

function baseQuery(
    dataset: string,
    layout: Layout,
    columns: string[] = [],
    aggregate = false,
): AnalyticsQuery {
    return {
        dataset,
        filters: layout.filters,
        fill_gaps: false,
        measures: [],
        sort: (layout.sort ?? []).filter(
            (sort) =>
                columns.includes(sort.key) ||
                (aggregate && layout.measures.includes(sort.key)),
        ),
    };
}

function valueText(column: ResultColumn, row: Row): string {
    return column.kind === 'measure'
        ? formatMeasureValue(column, row)
        : formatDimensionValue(column, row);
}

function QueryNotice({
    error,
    result,
}: {
    error: unknown;
    result: ResultSet | null;
}) {
    const placement = queryErrorPlacement(error);
    const message =
        Object.values(placement.filters).join(' ') ||
        placement.timeRange ||
        placement.message;

    if (!message && !result?.meta.truncated) {
        return null;
    }

    return (
        <Alert variant={message ? 'destructive' : 'default'}>
            <AlertTitle>
                {message
                    ? 'Data belum dapat ditampilkan'
                    : 'Sebagian baris belum ditampilkan'}
            </AlertTitle>
            <AlertDescription>
                {message ??
                    'Persempit filter untuk melihat seluruh baris. Angka ringkasan dihitung server atas seluruh data yang sesuai filter.'}
            </AlertDescription>
        </Alert>
    );
}

/** Pivot memindahkan hasil agregat server ke kolom; tidak menghitung ulang sum/avg/count di peramban. */
function AnalysisPivot({
    dataset,
    layout,
    refresh = false,
    onChange,
    onResetColumns,
}: {
    dataset: DatasetDescription;
    layout: Layout;
    refresh?: boolean;
    onChange: (patch: Partial<Layout>) => void;
    onResetColumns: () => void;
}) {
    const blocked = partitionFields(dataset, layout).includes(layout.pivot);
    const query =
        !blocked && layout.measures.length
            ? {
                  ...baseQuery(
                      dataset.code,
                      layout,
                      [...layout.groups, layout.pivot],
                      true,
                  ),
                  dimensions: [...layout.groups, layout.pivot],
                  measures: layout.measures,
                  totals: true,
              }
            : null;
    const preview = useQueryPreview(query, refresh);
    const rowPreview = useQueryPreview(
        !blocked && layout.measures.length
            ? {
                  ...baseQuery(dataset.code, layout, layout.groups, true),
                  dimensions: layout.groups,
                  measures: layout.measures,
                  totals: true,
              }
            : null,
        refresh,
    );
    const result = preview.result;
    const rowsResult = rowPreview.result;

    if (blocked) {
        return (
            <Alert>
                <AlertTitle>Pilih kolom pivot lain</AlertTitle>
                <AlertDescription>
                    Kolom pemisah nilai harus tetap menjadi baris agar total
                    tidak menggabungkan buku, mata uang, atau satuan yang
                    berbeda.
                </AlertDescription>
            </Alert>
        );
    }

    const dimensions =
        result?.columns.filter(
            (column) =>
                column.kind === 'dimension' && column.key !== layout.pivot,
        ) ?? [];
    const measures =
        result?.columns.filter((column) => column.kind === 'measure') ?? [];
    const pivotColumn = result?.columns.find(
        (column) => column.key === layout.pivot,
    );
    const pivotValues = [
        ...new Map(
            (result?.rows ?? []).map((row) => [
                JSON.stringify(row[layout.pivot]),
                row,
            ]),
        ).entries(),
    ];
    const keyFor = (row: Row) =>
        JSON.stringify(dimensions.map((column) => row[column.key]));
    const cells = new Map(
        (result?.rows ?? []).map((row) => [
            `${keyFor(row)}:${JSON.stringify(row[layout.pivot])}`,
            row,
        ]),
    );

    if (pivotValues.length > 50 || result?.meta.truncated) {
        return (
            <Alert>
                <AlertTitle>Pivot terlalu besar</AlertTitle>
                <AlertDescription>
                    Persempit filter atau kurangi kelompok. Maksimal 50 nilai
                    kolom pivot; hasil yang terpotong tidak ditampilkan sebagai
                    pivot lengkap.
                </AlertDescription>
            </Alert>
        );
    }

    const tools = analysisColumnTools(dataset, layout, onChange);

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-3">
            <QueryNotice
                error={preview.error ?? rowPreview.error}
                result={rowsResult}
            />
            {result && rowsResult && (
                <DataTable
                    allowColumnFreeze
                    showColumnMenu
                    onResetColumns={onResetColumns}
                    onResetFilters={() => onChange({ filters: {} })}
                    showRowNumbers={false}
                    data={rowsResult.rows}
                    getRowKey={keyFor}
                    columns={[
                        ...dimensions.map((column) => ({
                            ...tools[column.key],
                            id: column.key,
                            header: column.caption,
                            cell: (row: Row) => valueText(column, row),
                        })),
                        ...pivotValues.flatMap(([key, value]) =>
                            measures.map((column) => ({
                                id: `${key}:${column.key}`,
                                header: `${pivotColumn ? valueText(pivotColumn, value) : key} · ${column.caption}`,
                                align: 'right' as const,
                                cell: (row: Row) => {
                                    const cell = cells.get(
                                        `${keyFor(row)}:${key}`,
                                    );

                                    return cell ? valueText(column, cell) : '—';
                                },
                            })),
                        ),
                        ...measures.map((column) => ({
                            id: `total:${column.key}`,
                            header: `Total ${column.caption}`,
                            align: 'right' as const,
                            cell: (row: Row) => valueText(column, row),
                        })),
                    ]}
                    emptyMessage="Tidak ada transaksi yang sesuai filter."
                />
            )}
        </div>
    );
}

/** Engine layar bersama. Pemakai hanya mendaftarkan dataset dan memilih susunan awal. */
export function AdHocAnalysis({
    datasetCode,
    title,
    defaults,
}: {
    datasetCode: string;
    title: string;
    defaults?: Partial<Layout>;
}) {
    const page = usePage();
    const loaded = useDatasetDescription(datasetCode);
    const dataset = loaded.description;
    const fallback: Layout = {
        mode: 'records',
        columns: dataset?.fields.slice(0, 12).map((field) => field.key) ?? [],
        groups: [],
        measures:
            dataset?.measures.slice(0, 1).map((measure) => measure.key) ?? [],
        filters: {},
        pivot: '',
        ...defaults,
    };

    const [settingsOpen, setSettingsOpen] = useState(true);
    const [localSaveError, setLocalSaveError] = useState<string | null>(null);
    const isMobile = useIsMobile();
    const [refresh, setRefresh] = useState(0);
    type Workspace = {
        scope: string;
        source: string;
        activeName: string;
        tabs: SavedView[];
    };
    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const storageKey = `analysis:${page.props.auth.membership?.tenant_id}:${page.props.auth.user.id}:${datasetCode}`;
    const saved = decodeViews(
        useSyncExternalStore(
            subscribeViews,
            () => storedViews(storageKey),
            () => null,
        ),
    );
    const imported = readLayout(page.url, fallback);
    const restored = saved.views.map((view) => ({
        ...view,
        layout: readLayout(
            `/?analysis=${encodeURIComponent(JSON.stringify(view.layout))}`,
            fallback,
        ),
    }));
    const hasImportedLayout = new URL(
        page.url,
        'http://localhost',
    ).searchParams.has('analysis');
    const tabs = hasImportedLayout
        ? [
              { name: 'Analisis 1', layout: imported },
              ...restored.filter((view) => view.name !== 'Analisis 1'),
          ].slice(0, 20)
        : restored.length
          ? restored
          : [{ name: 'Analisis 1', layout: imported }];
    const current: Workspace =
        workspace?.scope === storageKey && workspace.source === page.url
            ? workspace
            : {
                  scope: storageKey,
                  source: page.url,
                  activeName:
                      !hasImportedLayout &&
                      tabs.some((tab) => tab.name === saved.activeName)
                          ? saved.activeName!
                          : tabs[0].name,
                  tabs,
              };
    const layout =
        current.tabs.find((tab) => tab.name === current.activeName)?.layout ??
        fallback;
    const resetColumns = () =>
        change({
            ...layout,
            columns: fallback.columns,
            column_order: undefined,
        });
    const patchLayout = (patch: Partial<Layout>) =>
        change({
            ...layout,
            ...patch,
            pivot: patch.groups?.includes(layout.pivot) ? '' : layout.pivot,
        });
    const tools = dataset
        ? analysisColumnTools(dataset, layout, patchLayout)
        : undefined;
    const commit = (next: Workspace) => {
        setWorkspace(next);
        // Tautan lama tetap dapat dibaca; tindakan berikutnya memakai state layar, tanpa navigasi.
        const url = new URL(window.location.href);

        if (url.searchParams.has('analysis')) {
            url.searchParams.delete('analysis');
            router.replace({
                url: `${url.pathname}${url.search}${url.hash}`,
                preserveState: true,
                preserveScroll: true,
            });
        }

        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify({
                    activeName: next.activeName,
                    views: next.tabs,
                }),
            );
            window.dispatchEvent(new Event('storage'));
            setLocalSaveError(null);
        } catch {
            setLocalSaveError(
                'Tampilan belum dapat disimpan di peramban ini. Perubahan masih terlihat selama halaman terbuka, tetapi belum tersimpan untuk kunjungan berikutnya. Coba simpan tampilan lagi.',
            );
        }
    };
    const change = (next: Layout) =>
        commit({
            ...current,
            tabs: current.tabs.map((tab) =>
                tab.name === current.activeName
                    ? { ...tab, layout: next }
                    : tab,
            ),
        });
    const freshName = (prefix = 'Analisis') => {
        let index = 1;
        let name = `${prefix} ${index}`;

        while (current.tabs.some((tab) => tab.name === name)) {
            name = `${prefix} ${++index}`;
        }

        return name;
    };
    const query =
        layout.mode === 'records' && layout.columns.length
            ? {
                  ...baseQuery(datasetCode, layout, layout.columns),
                  records: true,
                  dimensions: layout.columns,
                  measures: [],
              }
            : null;
    const preview = useQueryPreview(query);
    const records =
        preview.result ?? (preview.loading ? preview.previous : null);

    return (
        <ListPageLayout
            header={
                <div className="flex flex-wrap items-center gap-2 border-b px-5 py-2">
                    <h1 className="text-xl font-semibold">{title}</h1>
                    <Button
                        size="sm"
                        variant={
                            layout.mode === 'records' ? 'default' : 'outline'
                        }
                        onClick={() =>
                            change({ ...layout, mode: 'records', sort: [] })
                        }
                    >
                        Daftar
                    </Button>
                    <Button
                        size="sm"
                        variant={
                            layout.mode === 'analysis' ? 'default' : 'outline'
                        }
                        onClick={() =>
                            change({ ...layout, mode: 'analysis', sort: [] })
                        }
                    >
                        Analisis
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setSettingsOpen(!settingsOpen)}
                        aria-expanded={settingsOpen}
                    >
                        Kolom dan filter
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            preview.reload();
                            setRefresh((value) => value + 1);
                        }}
                    >
                        <RefreshCw />
                        Muat ulang
                    </Button>
                </div>
            }
            filters={
                <div className="flex flex-col gap-2 px-5">
                    <FormErrorHeader
                        message={localSaveError}
                        operation="Tampilan belum disimpan"
                    />
                    {localSaveError && (
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => commit(current)}
                            >
                                Simpan tampilan lagi
                            </Button>
                        </div>
                    )}
                    {layout.mode === 'analysis' && (
                        <AnalysisViewTabs
                            names={current.tabs.map((tab) => tab.name)}
                            active={current.activeName}
                            onSelect={(activeName) =>
                                commit({ ...current, activeName })
                            }
                            onAdd={() => {
                                const name = freshName();
                                commit({
                                    ...current,
                                    activeName: name,
                                    tabs: [
                                        ...current.tabs,
                                        {
                                            name,
                                            layout: {
                                                ...fallback,
                                                mode: 'analysis',
                                            },
                                        },
                                    ],
                                });
                            }}
                            onRename={(name, next) => {
                                if (
                                    !next ||
                                    next.length > 80 ||
                                    current.tabs.some(
                                        (tab) =>
                                            tab.name === next &&
                                            tab.name !== name,
                                    )
                                ) {
                                    return false;
                                }

                                commit({
                                    ...current,
                                    activeName:
                                        current.activeName === name
                                            ? next
                                            : current.activeName,
                                    tabs: current.tabs.map((tab) =>
                                        tab.name === name
                                            ? { ...tab, name: next }
                                            : tab,
                                    ),
                                });

                                return true;
                            }}
                            onDuplicate={(name) => {
                                const source = current.tabs.find(
                                    (tab) => tab.name === name,
                                );

                                if (source) {
                                    const next = freshName(`${name} salinan`);
                                    commit({
                                        ...current,
                                        activeName: next,
                                        tabs: [
                                            ...current.tabs,
                                            {
                                                name: next,
                                                layout: structuredClone(
                                                    source.layout,
                                                ),
                                            },
                                        ],
                                    });
                                }
                            }}
                            onRemove={(name) => {
                                const tabs = current.tabs.filter(
                                    (tab) => tab.name !== name,
                                );

                                if (tabs.length) {
                                    commit({
                                        ...current,
                                        tabs,
                                        activeName:
                                            current.activeName === name
                                                ? tabs[0].name
                                                : current.activeName,
                                    });
                                }
                            }}
                        />
                    )}
                    {loaded.failure && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {loaded.failure}
                            </AlertDescription>
                        </Alert>
                    )}
                    {saved.failed && (
                        <Alert>
                            <AlertDescription>
                                Tampilan tersimpan tidak dapat dibaca. Daftar
                                tetap dapat dipakai; simpan ulang setelah
                                penyimpanan peramban tersedia.
                            </AlertDescription>
                        </Alert>
                    )}
                    {layout === fallback &&
                        new URL(page.url, 'http://localhost').searchParams.has(
                            'analysis',
                        ) && (
                            <Alert>
                                <AlertDescription>
                                    Susunan tampilan tidak dapat dibaca. Pilihan
                                    bawaan digunakan.
                                </AlertDescription>
                            </Alert>
                        )}
                    <QueryNotice
                        error={preview.error}
                        result={preview.result}
                    />
                </div>
            }
        >
            <ResizablePanelGroup
                orientation={isMobile ? 'vertical' : 'horizontal'}
                key={isMobile ? 'mobile' : 'desktop'}
            >
                <ResizablePanel
                    id="results"
                    minSize="25%"
                    className="flex min-h-0 min-w-0 flex-col overflow-hidden"
                >
                    {loaded.loading && (
                        <p className="p-5 text-muted-foreground">
                            Memuat kolom…
                        </p>
                    )}
                    {layout.mode === 'records' &&
                        layout.columns.length === 0 && (
                            <p className="p-5 text-muted-foreground">
                                Pilih sedikitnya satu kolom untuk menampilkan
                                daftar.
                            </p>
                        )}
                    {layout.mode === 'records' && preview.loading && (
                        <p className="p-5 text-muted-foreground">
                            Memuat transaksi…
                        </p>
                    )}
                    {layout.mode === 'records' &&
                        layout.columns.length > 0 &&
                        records && (
                            <ResultTable
                                columnTools={tools}
                                onResetColumns={resetColumns}
                                onResetFilters={() =>
                                    change({ ...layout, filters: {} })
                                }
                                columns={layout.columns}
                                result={records}
                                showTotals={false}
                                scrollMode="viewport"
                            />
                        )}
                    {layout.mode === 'analysis' && dataset && (
                        <div
                            className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden p-3"
                            scroll-region="true"
                        >
                            {layout.measures.length === 0 ? (
                                <p>Pilih nilai yang ingin dihitung.</p>
                            ) : layout.pivot ? (
                                <AnalysisPivot
                                    key={`${refresh}:${current.activeName}:${JSON.stringify([layout.groups, layout.pivot])}`}
                                    dataset={dataset}
                                    layout={layout}
                                    onChange={patchLayout}
                                    onResetColumns={resetColumns}
                                    refresh={refresh > 0}
                                />
                            ) : (
                                <AnalysisTreeGrid
                                    key={`${refresh}:${current.activeName}:${JSON.stringify([layout.groups, layout.pivot])}`}
                                    dataset={dataset}
                                    layout={layout}
                                    onChange={patchLayout}
                                    onResetColumns={resetColumns}
                                    refresh={refresh > 0}
                                />
                            )}
                        </div>
                    )}
                </ResizablePanel>
                {settingsOpen && dataset && (
                    <>
                        <ResizableHandle
                            withHandle
                            aria-label="Ubah ukuran panel kolom dan filter"
                        />
                        <ResizablePanel
                            id="settings"
                            defaultSize={isMobile ? '45%' : 340}
                            minSize={isMobile ? '25%' : 260}
                            maxSize={isMobile ? '65%' : '55%'}
                            className="min-h-0 min-w-0 overflow-hidden bg-background"
                        >
                            <aside
                                aria-label="Pengaturan analisis"
                                className="h-full min-h-0"
                            >
                                <Tabs
                                    defaultValue="columns"
                                    className="h-full min-h-0 gap-0"
                                >
                                    <TabsList className="m-3 shrink-0">
                                        <TabsTrigger value="columns">
                                            Kolom
                                        </TabsTrigger>
                                        <TabsTrigger value="filters">
                                            Filter
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent
                                        value="columns"
                                        className="flex min-h-0 flex-col overflow-hidden"
                                    >
                                        <div className="shrink-0 px-3 py-2">
                                            <Select
                                                label="Kolom pivot"
                                                value={
                                                    layout.pivot || '__none__'
                                                }
                                                items={[
                                                    {
                                                        value: '__none__',
                                                        label: 'Tanpa pivot',
                                                    },
                                                    ...dataset.fields
                                                        .filter(
                                                            (field) =>
                                                                !layout.groups.includes(
                                                                    field.key,
                                                                ) &&
                                                                !partitionFields(
                                                                    dataset,
                                                                    layout,
                                                                ).includes(
                                                                    field.key,
                                                                ),
                                                        )
                                                        .map((field) => ({
                                                            value: field.key,
                                                            label: field.caption,
                                                        })),
                                                ]}
                                                onValueChange={(pivot) =>
                                                    change({
                                                        ...layout,
                                                        pivot:
                                                            pivot &&
                                                            pivot !== '__none__'
                                                                ? pivot
                                                                : '',
                                                    })
                                                }
                                            />
                                        </div>
                                        <AnalysisDragSettings
                                            key={current.activeName}
                                            dataset={dataset}
                                            selection={layout}
                                            onChange={(patch) =>
                                                change({
                                                    ...layout,
                                                    ...patch,
                                                })
                                            }
                                        />
                                    </TabsContent>
                                    <TabsContent
                                        value="filters"
                                        className="min-h-0 overflow-auto px-4 pb-4"
                                    >
                                        <FilterEditor
                                            moduleId={dataset.module_id}
                                            fields={[
                                                ...dataset.fields,
                                                ...(
                                                    dataset.filter_fields ?? []
                                                ).filter((field) =>
                                                    Object.prototype.hasOwnProperty.call(
                                                        layout.filters,
                                                        field.key,
                                                    ),
                                                ),
                                            ]}
                                            value={layout.filters}
                                            errors={
                                                queryErrorPlacement(
                                                    preview.error,
                                                ).filters
                                            }
                                            onChange={(filters) =>
                                                change({ ...layout, filters })
                                            }
                                        />
                                    </TabsContent>
                                </Tabs>
                            </aside>
                        </ResizablePanel>
                    </>
                )}
            </ResizablePanelGroup>{' '}
        </ListPageLayout>
    );
}
