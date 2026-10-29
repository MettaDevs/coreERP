import type {
    ReportFilterDefinition,
    ReportFilterProfile,
    ReportFilterSection,
} from './reportFilterProfiles';
import type { Filters } from './reportOptions';

const active = (value: Filters[string] | undefined) =>
    Array.isArray(value) ? value.length > 0 : Boolean(value);

/** Satu kolom hanya boleh mempunyai satu isian aktif, baik Opsi maupun filter data item. */
export function filterTarget(
    filter: ReportFilterDefinition,
    section?: ReportFilterSection,
): string | undefined {
    const item = filter.dataItem ?? section?.dataItem;

    return item ? `filters.${item}.${filter.field ?? filter.key}` : undefined;
}

export function reportControls(profile: ReportFilterProfile) {
    return [
        ...profile.options.map((filter) => ({
            filter,
            target: filterTarget(filter),
        })),
        ...profile.sections.flatMap((section) =>
            section.filters.map((filter) => ({
                filter,
                target: filterTarget(filter, section),
            })),
        ),
    ];
}

/**
 * Preset, pratinjau, dan cetak memakai state yang sama. Filter kolom tersimpan menggantikan
 * pilihan tetap pada kolom yang sama; filter pada bagian yang tidak dibuka laporan dibuang
 * dengan pemberitahuan, sehingga tidak ada syarat tersembunyi yang mempersempit hasil.
 */
export function normalizeReportFilters(
    profile: ReportFilterProfile,
    source: Filters,
): { filters: Filters; removed: string[] } {
    const controls = reportControls(profile);
    const parameters = new Set(controls.map(({ filter }) => filter.key));
    const items = new Set(
        profile.sections.flatMap((section) =>
            section.dataItem ? [section.dataItem] : [],
        ),
    );
    const filters: Filters = {};
    const redirected: Filters = {};
    const removed: string[] = [];

    for (const [key, value] of Object.entries(source)) {
        const parts = key.split('.');

        if (
            parameters.has(key) ||
            (parts.length === 3 &&
                parts[0] === 'filters' &&
                items.has(parts[1]))
        ) {
            filters[key] = value;
        } else if (active(value)) {
            const owner = controls.find(({ target }) => target === key)?.filter;

            if (
                owner?.kind === 'depreciation-book' &&
                (!Array.isArray(value) || value.length === 1)
            ) {
                redirected[owner.key] = Array.isArray(value) ? value[0] : value;
            } else {
                removed.push(key);
            }
        }
    }

    Object.assign(filters, redirected);

    for (const { filter, target } of controls) {
        if (target && active(filters[target])) {
            delete filters[filter.key];
        }
    }

    return { filters, removed };
}

/** Kolom dengan kontrol tetap tidak ditawarkan ulang; ekspresi tersimpan tetap dapat diedit. */
export function reservedReportFields(
    profile: ReportFilterProfile,
    filters: Filters,
    item: string,
): Set<string> {
    const prefix = `filters.${item}.`;

    return new Set(
        reportControls(profile).flatMap(({ filter, target }) =>
            target?.startsWith(prefix) &&
            (!filter.onlyWhenActive || active(filters[filter.key]))
                ? [target.slice(prefix.length)]
                : [],
        ),
    );
}

export function showReportControl(
    filter: ReportFilterDefinition,
    filters: Filters,
    section?: ReportFilterSection,
): boolean {
    const target = filterTarget(filter, section);

    return (
        (!filter.onlyWhenActive || active(filters[filter.key])) &&
        !(target && active(filters[target]))
    );
}
