import type {
    CrossFilter,
    DashboardSlicer,
    DashboardWidget,
    DatasetDescription,
    DatasetField,
    ResultValue,
} from '@/lib/analytics/types';

export type SlicerValues = Record<string, string | string[]>;

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
): DatasetField[] {
    return widget.dataset_code === null
        ? []
        : (datasets[widget.dataset_code]?.fields ?? []);
}

export function slicerField(
    slicer: DashboardSlicer,
    widget: DashboardWidget,
    fields: DatasetField[],
): DatasetField | null {
    const source = slicer.source;

    if (source.type === 'field') {
        return source.dataset === widget.dataset_code
            ? (fields.find((field) => field.key === source.field) ?? null)
            : null;
    }

    const matches = fields.filter(
        (field) => field.shared_dimension === source.dimension,
    );

    if (matches.length <= 1) {
        return matches[0] ?? null;
    }

    const used = queryFields(widget);
    const selected = matches.filter((field) => used.has(field.key));

    return selected.length === 1 ? selected[0] : null;
}

export function notApplicableSlicers(
    slicers: DashboardSlicer[],
    widget: DashboardWidget,
    fields: DatasetField[],
): string[] {
    return slicers
        .filter((slicer) => slicerField(slicer, widget, fields) === null)
        .map((slicer) => slicer.title);
}

export function crossFilterValues(
    widget: DashboardWidget,
    fields: DatasetField[],
    slicers: DashboardSlicer[],
    values: SlicerValues,
    crossFilters: CrossFilter[],
): Record<string, string | string[]> {
    const filters: Record<string, string | string[]> = {};
    const slicerFields = new Set<string>();

    for (const slicer of slicers) {
        const field = slicerField(slicer, widget, fields);
        const value = values[slicer.key];

        if (field !== null && value !== undefined && !empty(value)) {
            slicerFields.add(field.key);
        }
    }

    for (const item of crossFilters) {
        if (item.origin_widget_id === widget.id) {
            continue;
        }

        const matched = item.source.shared_dimension
            ? fields.filter(
                  (field) =>
                      field.shared_dimension === item.source.shared_dimension,
              )
            : fields.filter(
                  (field) =>
                      widget.dataset_code === item.source.dataset &&
                      field.key === item.source.field,
              );
        const candidates =
            matched.length > 1
                ? matched.filter((field) => queryFields(widget).has(field.key))
                : matched;
        const field = candidates.length === 1 ? candidates[0] : null;

        if (field === null || slicerFields.has(field.key)) {
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

function queryFields(widget: DashboardWidget): Set<string> {
    const fields = new Set<string>();

    for (const dimension of widget.query?.dimensions ?? []) {
        fields.add(typeof dimension === 'string' ? dimension : dimension.field);
    }

    Object.keys(widget.query?.filters ?? {}).forEach((key) => fields.add(key));

    if (widget.query?.time_range?.field) {
        fields.add(widget.query.time_range.field);
    }

    return fields;
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
    field: DatasetField,
    value: ResultValue,
    label: string,
    granularity?: CrossFilter['source']['granularity'],
): CrossFilter | null {
    if (value === null || value === undefined || widget.dataset_code === null) {
        return null;
    }

    return {
        id: `${widget.id}:${field.key}:${String(value)}:${granularity ?? ''}`,
        origin_widget_id: widget.id,
        title: field.caption,
        source: {
            dataset: widget.dataset_code,
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
