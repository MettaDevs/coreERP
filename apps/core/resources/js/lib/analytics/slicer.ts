import type {
    CrossFilter,
    DashboardSlicer,
    DashboardWidget,
    AnalyticsQuery,
    DatasetDescription,
    DatasetField,
    ResultValue,
} from '@/lib/analytics/types';

export type SlicerValues = Record<string, string | string[]>;

/** Field yang dikaitkan ke dataset asalnya, supaya blend tetap dapat memetakan slicer per sumber. */
export type WidgetDatasetField = DatasetField & { source_dataset: string };

/** Nilai slicer dimuat dari alamat pada setiap render; alamat tetap satu-satunya sumber kebenaran. */
export function readSlicerUrl(
    url: string,
    slicers: DashboardSlicer[],
): SlicerValues {
    const params = new URL(url, 'http://localhost').searchParams;
    const values: SlicerValues = {};

    for (const slicer of slicers) {
        const listKey = `s[${slicer.key}][]`;
        const key = `s[${slicer.key}]`;

        if (params.has(listKey)) {
            values[slicer.key] = params.getAll(listKey);
        } else if (params.has(key)) {
            values[slicer.key] = params.get(key) ?? '';
        }
    }

    return values;
}

export function slicerUrl(
    url: string,
    slicers: DashboardSlicer[],
    values: SlicerValues,
): string {
    const parsed = new URL(url, 'http://localhost');

    for (const key of [...parsed.searchParams.keys()]) {
        if (/^s\[[a-z][a-z0-9_]*\](?:\[\])?$/.test(key)) {
            parsed.searchParams.delete(key);
        }
    }

    for (const slicer of slicers) {
        const value = values[slicer.key] ?? slicer.default_value ?? undefined;

        if (value === undefined) {
            continue;
        }

        if (Array.isArray(value)) {
            if (value.length === 0) {
                parsed.searchParams.set(`s[${slicer.key}]`, '');
            } else {
                value.forEach((item) =>
                    parsed.searchParams.append(`s[${slicer.key}][]`, item),
                );
            }
        } else {
            parsed.searchParams.set(`s[${slicer.key}]`, value);
        }
    }

    const query = parsed.searchParams.toString();

    return query === '' ? parsed.pathname : `${parsed.pathname}?${query}`;
}

export function fieldsForWidget(
    widget: DashboardWidget,
    datasets: Record<string, DatasetDescription>,
): WidgetDatasetField[] {
    return querySources(widget).flatMap(({ dataset }) =>
        (datasets[dataset]?.fields ?? []).map((field) => ({
            ...field,
            source_dataset: dataset,
        })),
    );
}

export function slicerField(
    slicer: DashboardSlicer,
    widget: DashboardWidget,
    fields: WidgetDatasetField[],
): WidgetDatasetField | null {
    return slicerFieldsForWidget(slicer, widget, fields)[0] ?? null;
}

function slicerFieldsForWidget(
    slicer: DashboardSlicer,
    widget: DashboardWidget,
    fields: WidgetDatasetField[],
): WidgetDatasetField[] {
    const source = slicer.source;
    const queries = querySources(widget);

    if (source.type === 'field') {
        if (!queries.some((query) => query.dataset === source.dataset)) {
            return [];
        }

        const field = fields.find(
            (item) =>
                item.source_dataset === source.dataset &&
                item.key === source.field,
        );

        return field === undefined ? [] : [field];
    }

    const matches = fields.filter((field) =>
        field.shared_dimension === source.dimension,
    );

    if (widget.type === 'blend') {
        const selected = queries.map(({ dataset, query }) => {
            const candidates = matches.filter(
                (field) =>
                    field.source_dataset === dataset &&
                    queryFields(query).has(field.key),
            );

            return candidates.length === 1 ? candidates[0] : null;
        });

        return selected.every((field) => field !== null)
            ? selected.filter((field): field is WidgetDatasetField => field !== null)
            : [];
    }

    if (matches.length <= 1) {
        return matches;
    }

    const used = queryFields(queries[0]?.query);
    const selected = matches.filter((field) => used.has(field.key));

    return selected.length === 1 ? selected : [];
}

export function notApplicableSlicers(
    slicers: DashboardSlicer[],
    widget: DashboardWidget,
    fields: WidgetDatasetField[],
): string[] {
    return slicers
        .filter((slicer) => slicerField(slicer, widget, fields) === null)
        .map((slicer) => slicer.title);
}

export function crossFilterValues(
    widget: DashboardWidget,
    fields: WidgetDatasetField[],
    slicers: DashboardSlicer[],
    values: SlicerValues,
    crossFilters: CrossFilter[],
): Record<string, string | string[]> {
    const filters: Record<string, string | string[]> = {};
    const slicerFields = new Set<string>();

    for (const slicer of slicers) {
        const value = values[slicer.key];

        if (
            value !== undefined &&
            !empty(value) &&
            (widget.type !== 'blend' || slicer.source.type === 'shared')
        ) {
            for (const field of slicerFieldsForWidget(slicer, widget, fields)) {
                slicerFields.add(fieldIdentity(field));
            }
        }
    }

    for (const item of crossFilters) {
        if (item.origin_widget_id === widget.id) {
            continue;
        }

        if (widget.type === 'blend' && item.source.shared_dimension === undefined) {
            continue;
        }

        let field: WidgetDatasetField | null = null;
        if (widget.type === 'blend') {
            const first = querySources(widget)[0];
            if (first !== undefined && item.source.shared_dimension !== undefined) {
                const candidates = fields.filter(
                    (candidate) =>
                        candidate.source_dataset === first.dataset &&
                        candidate.shared_dimension === item.source.shared_dimension &&
                        queryFields(first.query).has(candidate.key),
                );
                field = candidates.length === 1 ? candidates[0] : null;
            }
        } else {
            const matched = item.source.shared_dimension
                ? fields.filter(
                      (candidate) =>
                          candidate.shared_dimension ===
                          item.source.shared_dimension,
                  )
                : fields.filter(
                      (candidate) =>
                          candidate.source_dataset === item.source.dataset &&
                          candidate.key === item.source.field,
                  );
            const candidates =
                matched.length > 1
                    ? matched.filter((candidate) =>
                          queryFields(querySources(widget)[0]?.query).has(
                              candidate.key,
                          ),
                      )
                    : matched;
            field = candidates.length === 1 ? candidates[0] : null;
        }

        if (field === null || slicerFields.has(fieldIdentity(field))) {
            continue;
        }

        const value = filterValue(field, item);

        if (value === null) {
            continue;
        }

        filters[field.key] = mergeFilter(filters[field.key], value);
    }

    return filters;
}

function filterValue(
    field: DatasetField,
    item: CrossFilter,
): string | string[] | null {
    if (field.time && item.source.granularity) {
        return periodRange(item.value, item.source.granularity);
    }

    if (['option', 'boolean', 'reference'].includes(field.type)) {
        return [item.value];
    }

    if (field.type === 'text') {
        return `'${item.value.replaceAll("'", "''")}'`;
    }

    return item.value;
}

function mergeFilter(
    previous: string | string[] | undefined,
    value: string | string[],
): string | string[] {
    if (previous === undefined) {
        return value;
    }

    if (Array.isArray(previous) && Array.isArray(value)) {
        return [...new Set([...previous, ...value])];
    }

    if (typeof previous === 'string' && typeof value === 'string') {
        return `${previous}|${value}`;
    }

    return previous;
}

function periodRange(
    value: string,
    granularity: NonNullable<CrossFilter['source']['granularity']>,
): string {
    const [year, month = '01', day = '01'] = value.split('-');
    const start = new Date(
        Date.UTC(Number(year), Number(month) - 1, Number(day)),
    );
    const end = new Date(start);

    if (granularity === 'week') {
        end.setUTCDate(end.getUTCDate() + 6);
    } else if (granularity === 'month') {
        end.setUTCMonth(end.getUTCMonth() + 1, 0);
    } else if (granularity === 'quarter') {
        end.setUTCMonth(end.getUTCMonth() + 3, 0);
    } else if (granularity === 'year') {
        end.setUTCFullYear(end.getUTCFullYear() + 1);
        end.setUTCDate(end.getUTCDate() - 1);
    }

    const iso = (date: Date) => date.toISOString().slice(0, 10);

    return `${iso(start)}..${iso(end)}`;
}

function empty(value: string | string[]): boolean {
    return Array.isArray(value) ? value.length === 0 : value.trim() === '';
}

export function crossFilterFromRow(
    widget: DashboardWidget,
    field: WidgetDatasetField,
    value: ResultValue,
    label: string,
    granularity?: CrossFilter['source']['granularity'],
): CrossFilter | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (widget.dataset_code === null && widget.type !== 'blend') {
        return null;
    }
    if (widget.type === 'blend' && field.shared_dimension === undefined) {
        return null;
    }

    return {
        id: `${widget.id}:${field.key}:${String(value)}:${granularity ?? ''}`,
        origin_widget_id: widget.id,
        title: field.caption,
        source: {
            dataset: field.source_dataset,
            field: field.key,
            type: field.type,
            shared_dimension: field.shared_dimension,
            time: field.time,
            granularity,
        },
        value: String(value),
        label,
    };
}

function querySources(
    widget: DashboardWidget,
): Array<{ dataset: string; query: AnalyticsQuery }> {
    if (widget.query === null) {
        return [];
    }

    return 'queries' in widget.query ? widget.query.queries : [widget.query];
}

function queryFields(query: AnalyticsQuery | undefined): Set<string> {
    const fields = new Set<string>();
    if (query === undefined) {
        return fields;
    }
    for (const dimension of query.dimensions ?? []) {
        fields.add(typeof dimension === 'string' ? dimension : dimension.field);
    }
    Object.keys(query.filters ?? {}).forEach((key) => fields.add(key));
    if (query.time_range?.field) {
        fields.add(query.time_range.field);
    }

    return fields;
}

function fieldIdentity(field: WidgetDatasetField): string {
    return `${field.source_dataset}:${field.key}`;
}
