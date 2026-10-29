import { useCallback, useEffect, useRef, useState } from 'react';
import { api, errorMessage } from '../../api';
import { REPORT_FILTER_PROFILES } from './reportFilterProfiles';
import type { ReportCode, ReportFilterProfile } from './reportFilterProfiles';
import type { FilterProps, MultiFilterProps } from './ReportFilters';
import { normalizeReportFilters } from './reportFilterState';
import {
    archivePreset,
    createPreset,
    filled,
    fromParameters,
    getReportOptions,
    rememberLastUsed,
    toParameters,
} from './reportOptions';
import type { FilterValue, Filters, ReportPreset } from './reportOptions';

/** Jawaban `GET /laporan/{code}`: dataset yang sama dengan yang dibaca mesin cetak Core. */
export type ReportApiResponse = {
    data: {
        fields: Record<string, string | number | null>;
        tables: Record<string, Record<string, unknown>[]>;
        file_name: string;
    };
};

/** Preset laporan beserta tindakannya, untuk `ReportFilterBar`. */
export type ReportPresetState = {
    reportCode: string;
    presets: ReportPreset[];
    /** Boleh membagikan preset ke semua pengguna, dan mengubah atau mengarsipkan preset bersama. */
    canShare: boolean;
    selectedId: string | null;
    filters: Filters;
    apply: (id: string | null) => void;
    save: (name: string, parameters: Filters, shared: boolean) => Promise<void>;
    archive: (preset: ReportPreset) => Promise<void>;
};

/** Filter tambahan laporan (K-30) untuk `ReportFilterBar`. */
export type AdditionalFilterState = {
    reportCode: string;
    filters: Filters;
    update: (key: string, value: FilterValue) => void;
};

/** State filter dan profil yang dipakai engine untuk satu laporan terdaftar. */
export type ReportFilterState = {
    reportCode: ReportCode;
    profile: ReportFilterProfile;
    filters: Filters;
    hasActiveFilters: boolean;
    resetFilters: () => void;
    bindFilter: (key: string) => FilterProps;
    bindMultiFilter: (key: string) => MultiFilterProps;
    presets: ReportPresetState;
    additional: AdditionalFilterState;
    notice: string;
};

/**
 * Pilihan filter dibawa ke pratinjau sebagai `kunci=nilai`, pilihan banyak sebagai `kunci[]=nilai`, dan
 * filter tambahan sebagai `filters[data item][kolom]=nilai`.
 */
function queryString(filters: Filters): string {
    const params = new URLSearchParams();
    const append = (key: string, value: FilterValue) => {
        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, item));
        } else {
            params.append(key, value);
        }
    };

    for (const [key, value] of Object.entries(toParameters(filters))) {
        if (typeof value === 'string' || Array.isArray(value)) {
            append(key, value);

            continue;
        }

        for (const [item, columns] of Object.entries(value)) {
            for (const [column, entry] of Object.entries(columns)) {
                append(`${key}[${item}][${column}]`, entry);
            }
        }
    }

    return params.toString();
}

/**
 * Data pratinjau satu laporan beserta filternya.
 *
 * Filter dibuka dengan pilihan terakhir pengguna untuk laporan ini (K-24), dan setiap pratinjau yang
 * berhasil dicatat sebagai pilihan terakhir berikutnya — seperti "Last used options and filters" di
 * Business Central. Preset bernama (K-25) memasang filternya sekaligus; tanggal relatif ("bulan ini")
 * sudah diterjemahkan Core menurut zona pengguna.
 */
export function useReportData<T = Record<string, unknown>>(
    reportCode: ReportCode,
    initialFilters: Filters = {},
) {
    const profile: ReportFilterProfile = REPORT_FILTER_PROFILES[reportCode];
    // Isian awal hanya dibaca sekali per laporan; referensinya tidak boleh memicu muat ulang.
    const initial = useRef(
        normalizeReportFilters(profile, initialFilters).filters,
    );
    const [filters, setFilters] = useState<Filters>(
        () => normalizeReportFilters(profile, initialFilters).filters,
    );
    const [notice, setNotice] = useState('');
    const [ready, setReady] = useState(false);
    const [presets, setPresets] = useState<ReportPreset[]>([]);
    const [canShare, setCanShare] = useState(false);
    const [selectedPresetId, setSelectedPresetId] = useState<string | null>(
        null,
    );
    const [rows, setRows] = useState<T[]>([]);
    // Nilai kepala laporan (total, jumlah, nama filter) persis seperti yang tercetak, supaya
    // layar tidak menjumlah ulang teks yang sudah diformat.
    const [fields, setFields] = useState<ReportApiResponse['data']['fields']>(
        {},
    );
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [refreshKey, setRefreshKey] = useState(0);
    const lastRemembered = useRef<string | null>(null);

    // Opsi terakhir dan preset dimuat sebelum pratinjau pertama, supaya layar tidak memuat data dua kali.
    // Gagal memuatnya tidak menahan laporan: layar tetap terbuka dengan filter bawaan.
    useEffect(() => {
        let cancelled = false;

        getReportOptions(reportCode)
            .then((options) => {
                if (cancelled) {
                    return;
                }

                setPresets(options.presets);
                setCanShare(options.can_share);

                if (options.last_used) {
                    const restored = normalizeReportFilters(profile, {
                        ...initial.current,
                        ...fromParameters(options.last_used.parameters),
                    });
                    const remembered = restored.filters;
                    setNotice(
                        restored.removed.length > 0
                            ? 'Sebagian filter tersimpan sudah tidak tersedia pada laporan ini. Periksa kembali pilihan sebelum mencetak.'
                            : '',
                    );

                    lastRemembered.current = JSON.stringify(
                        toParameters(remembered),
                    );
                    setFilters(remembered);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (!cancelled) {
                    setReady(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [reportCode, profile]);

    useEffect(() => {
        if (!ready) {
            return;
        }

        let cancelled = false;

        const timer = window.setTimeout(async () => {
            setLoading(true);
            setError('');

            try {
                const query = queryString(filters);
                const url = `/laporan/${reportCode}${query ? `?${query}` : ''}`;
                const res = await api<ReportApiResponse>(url);

                if (!cancelled) {
                    setRows((res.data?.tables?.baris ?? []) as T[]);
                    setFields(res.data?.fields ?? {});

                    // Hanya pilihan yang benar-benar diterima laporan yang dicatat sebagai pilihan terakhir.
                    const parameters = toParameters(filters);
                    const remembered = JSON.stringify(parameters);

                    if (remembered !== lastRemembered.current) {
                        lastRemembered.current = remembered;
                        rememberLastUsed(reportCode, parameters).catch(
                            () => undefined,
                        );
                    }
                }
            } catch (err) {
                if (!cancelled) {
                    setError(errorMessage(err, 'Gagal memuat data laporan.'));
                    setRows([]);
                    setFields({});
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
    }, [reportCode, filters, refreshKey, ready]);

    const updateFilter = (key: string, value: FilterValue) => {
        setSelectedPresetId(null);
        setNotice('');
        setFilters(
            (prev) =>
                normalizeReportFilters(profile, { ...prev, [key]: value })
                    .filters,
        );
    };

    const resetFilters = () => {
        setSelectedPresetId(null);
        setNotice('');
        setFilters(initial.current);
    };

    /** Mengikat satu parameter laporan ke satu komponen filter. */
    const bindFilter = (key: string): FilterProps => {
        const value = filters[key];

        return {
            value: Array.isArray(value) ? value[0] : value,
            onChange: (next) => updateFilter(key, next),
        };
    };

    /** Mengikat satu parameter pilihan banyak; nilai lama yang masih satu teks dibaca sebagai daftar. */
    const bindMultiFilter = (key: string): MultiFilterProps => {
        const value = filters[key];

        return {
            value: Array.isArray(value) ? value : value ? [value] : [],
            onChange: (next) => updateFilter(key, next),
        };
    };

    const refetch = useCallback(() => {
        setRefreshKey((k) => k + 1);
    }, []);

    const presetState: ReportPresetState = {
        reportCode,
        presets,
        canShare,
        selectedId: selectedPresetId,
        filters,
        apply: (id) => {
            const preset = presets.find((item) => item.id === id);

            setSelectedPresetId(preset?.id ?? null);

            if (preset) {
                const restored = normalizeReportFilters(profile, {
                    ...initial.current,
                    ...fromParameters(preset.resolved_parameters),
                });
                setFilters(restored.filters);
                setNotice(
                    restored.removed.length > 0
                        ? 'Sebagian filter pada preset sudah tidak tersedia pada laporan ini. Periksa kembali pilihan sebelum mencetak.'
                        : '',
                );
            }
        },
        save: async (name, parameters, shared) => {
            const preset = await createPreset(
                reportCode,
                name,
                toParameters(
                    normalizeReportFilters(profile, parameters).filters,
                ),
                shared,
            );

            setPresets((prev) => [...prev, preset]);
            setSelectedPresetId(preset.id);
            // Layar langsung memakai arti preset itu: "bulan ini" menjadi bulan berjalan, bukan bulan filter tadi.
            const restored = normalizeReportFilters(profile, {
                ...initial.current,
                ...fromParameters(preset.resolved_parameters),
            });
            setFilters(restored.filters);
        },
        archive: async (preset) => {
            await archivePreset(reportCode, preset);
            setPresets((prev) => prev.filter((item) => item.id !== preset.id));
            setSelectedPresetId(null);
        },
    };

    const additionalState: AdditionalFilterState = {
        reportCode,
        filters,
        update: updateFilter,
    };

    const filterState: ReportFilterState = {
        reportCode,
        profile,
        filters,
        hasActiveFilters: Object.values(filters).some(filled),
        resetFilters,
        bindFilter,
        bindMultiFilter,
        presets: presetState,
        additional: additionalState,
        notice,
    };

    return {
        filterState,
        rows,
        fields,
        loading: loading || !ready,
        error,
        refetch,
    };
}
