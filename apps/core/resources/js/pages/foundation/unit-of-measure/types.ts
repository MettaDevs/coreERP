import type { ListLoader, ListRequest } from '@apperp/ui/use-incremental-list';
import { apiJson } from '@/lib/core-api';

export type Choice = {
    id: string;
    code: string;
    name: string;
    active: boolean;
};
export type Unit = Choice & {
    version: number;
    symbol: string | null;
    decimal_places: number;
    uom_class_id: string;
    uom_system_id: string | null;
    class_name: string;
    system_name: string | null;
};
export type Conversion = {
    id: string;
    from_unit_id: string;
    to_unit_id: string;
    from_code: string;
    from_name: string;
    to_code: string;
    to_name: string;
    factor: string | number;
    offset: string | number;
    rounding_scale: number | null;
};
export type Catalog = 'units' | 'classes' | 'systems' | 'conversions';
export const labels: Record<Catalog, string> = {
    units: 'Satuan',
    classes: 'Kelas',
    systems: 'Sistem',
    conversions: 'Konversi',
};
export const endpoint = '/api/v1/units-of-measure';
export function catalogLoader<T>(
    resource: Catalog,
    filters: Record<string, string> = {},
): ListLoader<T> {
    return async ({ query, cursor, sort, signal }: ListRequest) => {
        const params = new URLSearchParams({
            incremental: '1',
            resource,
            q: query,
            ...filters,
        });

        if (cursor) {
            params.set('cursor', cursor);
        }

        if (sort) {
            params.set('sort', sort.columnId);
            params.set('direction', sort.direction);
        }

        const result = await apiJson<{ data: T[]; next_cursor: string | null }>(
            `${endpoint}?${params}`,
            { signal },
        );

        return { items: result.data, nextCursor: result.next_cursor };
    };
}
export type Draft =
    | { kind: 'units'; record: Unit | null }
    | { kind: 'classes' | 'systems' | 'conversions' };
