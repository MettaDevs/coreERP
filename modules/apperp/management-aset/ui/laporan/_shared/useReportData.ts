import { useCallback, useEffect, useState } from 'react';
import { api, errorMessage } from '../../api';

export type ReportApiResponse = {
    data: {
        fields: Record<string, string | number | null>;
        tables: Record<string, Record<string, unknown>[]>;
        file_name: string;
        row_count: number;
    };
};

export function useReportData<T = Record<string, unknown>>(
    reportCode: string,
    initialFilters: Record<string, string> = {},
) {
    const [filters, setFilters] =
        useState<Record<string, string>>(initialFilters);
    const [rows, setRows] = useState<T[]>([]);
    const [fields, setFields] = useState<
        Record<string, string | number | null>
    >({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        let cancelled = false;

        const timer = window.setTimeout(async () => {
            setLoading(true);
            setError('');

            try {
                const searchParams = new URLSearchParams();

                for (const [key, value] of Object.entries(filters)) {
                    if (value !== undefined && value !== null && value !== '') {
                        searchParams.append(key, String(value));
                    }
                }

                const query = searchParams.toString();
                const url = `/laporan/${reportCode}${query ? `?${query}` : ''}`;
                const res = await api<ReportApiResponse>(url);

                if (!cancelled) {
                    const tableData = (res.data?.tables?.baris ?? []) as T[];

                    setRows(tableData);
                    setFields(res.data?.fields ?? {});
                }
            } catch (err) {
                if (!cancelled) {
                    setError(errorMessage(err, 'Gagal memuat data laporan.'));
                    setRows([]);
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        }, 150);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [reportCode, filters, refreshKey]);

    const updateFilter = (key: string, value: string) => {
        setFilters((prev) => ({ ...prev, [key]: value }));
    };

    const resetFilters = () => {
        setFilters(initialFilters);
    };

    const refetch = useCallback(() => {
        setRefreshKey((k) => k + 1);
    }, []);

    return {
        filters,
        setFilters,
        updateFilter,
        resetFilters,
        rows,
        fields,
        loading,
        error,
        refetch,
    };
}
