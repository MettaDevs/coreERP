import { Button } from '@apperp/ui/button';
import { Head, router } from '@inertiajs/react';
import { ArrowUpDown, Filter, Loader2, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    AddressFieldGroup,
    AddressInput,
    AddressSelect,
    AddressToggle,
} from './components/address-controls';
import {
    DeleteConfirmDialog,
    ExternalCodesModal,
    TranslationsModal,
} from './components/address-modals';
import { AddressSidebar, navItems } from './components/address-sidebar';
import { AddressToolbar } from './components/address-toolbar';
import { TIMEZONE_DROPDOWN_OPTIONS, detectTimezone } from './timezones';
import type {
    ExternalCode,
    Parameter,
    Props,
    Section,
    TranslationItem,
} from './types';

export const isCityRegency = (r: any): boolean => {
    if (!r) {
        return false;
    }

    const t = (r.type || '').toLowerCase();
    const name = (r.name || '').toLowerCase();

    return (
        t === 'kota' ||
        t === 'city' ||
        t === 'capital_city' ||
        t === 'thanh_pho' ||
        t === 'planning_area' ||
        t === 'huc' ||
        t === 'municipality' ||
        t === 'town' ||
        t === 'khet' ||
        t === 'krong' ||
        t === 'quan' ||
        t === 'special_ward' ||
        name.startsWith('kota ')
    );
};

export const isCountyRegency = (r: any): boolean => {
    if (!r) {
        return false;
    }

    return !isCityRegency(r);
};

export default function AddressSetup({
    section,
    countries,
    provinces,
    regencies,
    districts,
    villages,
    streets,
    groupOfHouses,
    landPlots,
    buildings,
    postalCodes,
    parameters,
    dropdowns,
    context,
    filters,
    selectedId: initialSelectedId,
    flash,
}: Props) {
    const [activeSection, setActiveSection] = useState<Section>(
        section || 'countries',
    );

    const availableCountries = useMemo(
        () => dropdowns?.countries ?? countries ?? [],
        [dropdowns?.countries, countries],
    );

    const [filterCountry, setFilterCountry] = useState<string>(() => {
        if (filters?.country !== undefined && filters.country !== '') {
            return filters.country;
        }

        return '';
    });
    const [filterProvince, setFilterProvince] = useState<string>(
        filters?.province || '',
    );
    const [filterRegency, setFilterRegency] = useState<string>(
        filters?.regency || '',
    );
    const [filterDistrict, setFilterDistrict] = useState<string>(
        filters?.district || '',
    );
    const [filterVillage, setFilterVillage] = useState<string>(
        filters?.village || '',
    );

    const selectedRegency = useMemo(() => {
        if (!filterRegency) {
            return null;
        }

        const allRegs = dropdowns?.regencies ?? regencies ?? [];

        return (
            allRegs.find(
                (r: any) => r.id === filterRegency || r.code === filterRegency,
            ) ||
            (context?.regency?.id === filterRegency ? context.regency : null)
        );
    }, [filterRegency, dropdowns?.regencies, regencies, context]);

    // Sync filter states whenever filters prop is updated from server
    const [prevFilters, setPrevFilters] = useState(filters);

    if (filters !== prevFilters) {
        setPrevFilters(filters);

        if (filters) {
            if (
                filters.country !== undefined &&
                filters.country !== filterCountry
            ) {
                setFilterCountry(filters.country);
            }

            if (
                filters.province !== undefined &&
                filters.province !== filterProvince
            ) {
                setFilterProvince(filters.province);
            }

            if (
                filters.regency !== undefined &&
                filters.regency !== filterRegency
            ) {
                setFilterRegency(filters.regency);
            }

            if (
                filters.district !== undefined &&
                filters.district !== filterDistrict
            ) {
                setFilterDistrict(filters.district);
            }

            if (
                filters.village !== undefined &&
                filters.village !== filterVillage
            ) {
                setFilterVillage(filters.village);
            }
        }
    }

    const [selectedId, setSelectedId] = useState<string | null>(
        initialSelectedId || null,
    );
    const [isNew, setIsNew] = useState<boolean>(false);
    const [form, setForm] = useState<Record<string, any>>({});
    const [isSaving, setIsSaving] = useState<boolean>(false);
    const [isDeleting, setIsDeleting] = useState<boolean>(false);
    const [isFiltering, setIsFiltering] = useState<boolean>(false);
    const [searchTerm, setSearchTerm] = useState<string>('');

    const [activeModal, setActiveModal] = useState<
        'externalCodes' | 'translations' | null
    >(null);
    const [deleteConfirmTarget, setDeleteConfirmTarget] = useState<{
        id: string;
        name: string;
    } | null>(null);
    const [isFilterOpen, setIsFilterOpen] = useState<boolean>(true);

    const handleToggleFilter = () => {
        setIsFilterOpen((prev) => !prev);
    };

    // Dynamically aggregate all timezones across provinces and standards
    const allTimezoneOptions = useMemo(() => {
        const set = new Set(TIMEZONE_DROPDOWN_OPTIONS.map((o) => o.value));
        (provinces ?? []).forEach((p: any) => {
            if (p.timezone) {
                set.add(p.timezone);
            }
        });

        if (form.timezone) {
            set.add(form.timezone);
        }

        return Array.from(set)
            .sort()
            .map((tz) => ({ value: tz, label: tz }));
    }, [provinces, form.timezone]);

    // Resolved parent names for read-only hierarchy display in right-side form (empty if user has not chosen territory filter)
    const activeProvinceName = useMemo(() => {
        if (!filterProvince) {
            return '';
        }

        const provId = filterProvince;
        const found = (dropdowns?.provinces ?? provinces ?? []).find(
            (p: { id?: string; code?: string; name?: string }) =>
                p.id === provId || p.code === provId,
        );

        return found?.name || context?.province?.name || '';
    }, [filterProvince, dropdowns?.provinces, provinces, context?.province]);

    const activeRegencyName = useMemo(() => {
        if (!filterRegency) {
            return '';
        }

        const regId = filterRegency;
        const found = (dropdowns?.regencies ?? regencies ?? []).find(
            (r: { id?: string; code?: string; name?: string }) =>
                r.id === regId || r.code === regId,
        );

        return found?.name || context?.regency?.name || '';
    }, [filterRegency, dropdowns?.regencies, regencies, context?.regency]);

    const activeDistrictName = useMemo(() => {
        if (!filterDistrict) {
            return '';
        }

        const distId = filterDistrict;
        const found = (dropdowns?.districts ?? districts ?? []).find(
            (d: { id?: string; code?: string; name?: string }) =>
                d.id === distId || d.code === distId,
        );

        return found?.name || context?.district?.name || '';
    }, [filterDistrict, dropdowns?.districts, districts, context?.district]);

    const activeVillageName = useMemo(() => {
        if (!filterVillage) {
            return '';
        }

        const villId = filterVillage;
        const found = (dropdowns?.villages ?? villages ?? []).find(
            (v: { id?: string; code?: string; name?: string }) =>
                v.id === villId || v.code === villId,
        );

        return found?.name || '';
    }, [filterVillage, dropdowns?.villages, villages]);

    const getCsrfToken = (): string => {
        if (typeof document === 'undefined') {
            return '';
        }

        const meta = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        );

        return meta?.content || '';
    };

    // External Codes State
    const [externalCodesList, setExternalCodesList] = useState<ExternalCode[]>(
        [],
    );
    const [isLoadingExtCodes, setIsLoadingExtCodes] = useState<boolean>(false);

    // Translations State
    const [translationsList, setTranslationsList] = useState<TranslationItem[]>(
        [],
    );
    const [isLoadingTrans, setIsLoadingTrans] = useState<boolean>(false);

    // Helper to get active entity identifier and label
    const getActiveEntityId = () => {
        if (activeSection === 'countries') {
            return form.code || form.iso3 || selectedId || filterCountry || '';
        }

        return form.id || selectedId || form.code || '';
    };

    const getActiveEntityLabel = () => {
        return (
            form.name ||
            form.description ||
            form.code ||
            form.plot_number ||
            selectedId ||
            ''
        );
    };

    // Fetch External Codes (Available on all sections)
    const openExternalCodesModal = async () => {
        if (isNew) {
            showToast(
                'Please save this record first before managing external codes.',
                'error',
            );

            return;
        }

        const divId = getActiveEntityId();

        if (!divId) {
            showToast('Please select a record first.', 'error');

            return;
        }

        setActiveModal('externalCodes');
        setIsLoadingExtCodes(true);

        try {
            const res = await fetch(
                `/settings/address-setup/external-codes?division_id=${encodeURIComponent(divId)}`,
            );

            if (res.ok) {
                const data = await res.json();
                setExternalCodesList(Array.isArray(data) ? data : []);
            }
        } catch {
            setExternalCodesList([]);
        } finally {
            setIsLoadingExtCodes(false);
        }
    };

    const handleSaveExternalCode = async (
        system: string,
        code: string,
        desc: string,
    ) => {
        const divId = getActiveEntityId();

        if (!divId) {
            showToast('Please select a record first.', 'error');

            return;
        }

        try {
            const res = await fetch('/settings/address-setup/external-codes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    division_id: divId,
                    system: system.trim(),
                    external_code: code.trim(),
                    description: desc.trim(),
                }),
            });

            if (res.ok) {
                const result = await res.json();

                if (result.data) {
                    setExternalCodesList((prev) => [
                        ...prev.filter((x) => x.id !== result.data.id),
                        result.data,
                    ]);
                }

                showToast('External code mapping saved.', 'success');
            } else {
                showToast('Failed to save external code.', 'error');
            }
        } catch {
            showToast('Network error while saving external code.', 'error');
        }
    };

    // Delete External Code
    const handleDeleteExternalCode = async (id: string) => {
        try {
            const res = await fetch(
                `/settings/address-setup/external-codes/${id}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                },
            );

            if (res.ok) {
                setExternalCodesList((prev) => prev.filter((x) => x.id !== id));
                showToast('External code mapping deleted.', 'success');
            } else {
                showToast('Failed to delete external code.', 'error');
            }
        } catch {
            showToast('Failed to delete external code.', 'error');
        }
    };

    // Fetch Translations
    const openTranslationsModal = async () => {
        if (isNew) {
            showToast(
                'Please save this record first before managing translations.',
                'error',
            );

            return;
        }

        const divId = getActiveEntityId();

        if (!divId) {
            showToast('Please select a record first.', 'error');

            return;
        }

        setActiveModal('translations');
        setIsLoadingTrans(true);

        try {
            const res = await fetch(
                `/settings/address-setup/translations?division_id=${encodeURIComponent(divId)}`,
            );

            if (res.ok) {
                const data = await res.json();
                setTranslationsList(Array.isArray(data) ? data : []);
            }
        } catch {
            setTranslationsList([]);
        } finally {
            setIsLoadingTrans(false);
        }
    };

    const handleSaveTranslation = async (
        locale: string,
        name: string,
        desc: string,
    ) => {
        const divId = getActiveEntityId();

        if (!divId) {
            showToast('Please select a record first.', 'error');

            return;
        }

        try {
            const res = await fetch('/settings/address-setup/translations', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    division_id: divId,
                    locale,
                    name: name.trim(),
                    description: desc.trim(),
                }),
            });

            if (res.ok) {
                const result = await res.json();

                if (result.data) {
                    setTranslationsList((prev) => [
                        ...prev.filter((x) => x.id !== result.data.id),
                        result.data,
                    ]);
                }

                showToast('Translation saved.', 'success');
            } else {
                const errData = await res.json().catch(() => ({}));
                const msg =
                    errData?.message ||
                    Object.values(errData?.errors || {})[0] ||
                    'Failed to save translation.';
                showToast(String(msg), 'error');
            }
        } catch {
            showToast('Network error while saving translation.', 'error');
        }
    };

    // Delete Translation
    const handleDeleteTranslation = async (id: string) => {
        try {
            const res = await fetch(
                `/settings/address-setup/translations/${id}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                },
            );

            if (res.ok) {
                setTranslationsList((prev) => prev.filter((x) => x.id !== id));
                showToast('Translation deleted.', 'success');
            } else {
                showToast('Failed to delete translation.', 'error');
            }
        } catch {
            showToast('Failed to delete translation.', 'error');
        }
    };

    // Address parameter configuration helper
    const isParamTrue = (val: any): boolean =>
        val === true || val === 1 || val === '1' || val === 'true';

    const normalizeParameter = (raw: any, countryCode = ''): Parameter => ({
        country_code: raw?.country_code || countryCode,
        use_province:
            raw?.use_province !== undefined
                ? isParamTrue(raw.use_province)
                : Boolean(countryCode),
        use_regency:
            raw?.use_regency !== undefined
                ? isParamTrue(raw.use_regency)
                : Boolean(countryCode),
        use_district:
            raw?.use_district !== undefined
                ? isParamTrue(raw.use_district)
                : Boolean(countryCode),
        use_village:
            raw?.use_village !== undefined
                ? isParamTrue(raw.use_village)
                : Boolean(countryCode),
        use_rt_rw:
            raw?.use_rt_rw !== undefined
                ? isParamTrue(raw.use_rt_rw)
                : Boolean(countryCode),
        use_postal_code:
            raw?.use_postal_code !== undefined
                ? isParamTrue(raw.use_postal_code)
                : Boolean(countryCode),
        use_building:
            raw?.use_building !== undefined
                ? isParamTrue(raw.use_building)
                : Boolean(countryCode),
        address_format:
            raw?.address_format !== undefined && raw?.address_format !== null
                ? String(raw.address_format)
                : '',
    });

    const [paramState, setParamState] = useState<Parameter>(() => {
        const initialCountry = filterCountry || '';
        const found = initialCountry
            ? parameters?.find((p) => p.country_code === initialCountry)
            : null;

        return normalizeParameter(found, initialCountry);
    });

    // Sync paramState whenever filterCountry or parameters prop updates
    const [prevParamCountry, setPrevParamCountry] = useState(filterCountry);
    const [prevParams, setPrevParams] = useState(parameters);

    if (filterCountry !== prevParamCountry || parameters !== prevParams) {
        setPrevParamCountry(filterCountry);
        setPrevParams(parameters);
        const targetCountry = filterCountry || '';
        const found = targetCountry
            ? parameters?.find((p) => p.country_code === targetCountry)
            : null;
        setParamState(normalizeParameter(found, targetCountry));
    }

    // Toast notifications using Sonner in bottom-right
    const showToast = (text?: string, type?: 'success' | 'error') => {
        if (!text) {
            return;
        }

        if (type === 'error') {
            toast.error(text);
        } else {
            toast.success(text);
        }
    };

    // Update active section when props change
    const [prevSection, setPrevSection] = useState(section);

    if (section !== prevSection) {
        setPrevSection(section);

        if (section) {
            setActiveSection(section);
        }
    }

    // Keep activeSection valid if its parameter is deactivated
    const activeNavItem = navItems.find((n) => n.key === activeSection);
    const isCurrentSectionDisabled = Boolean(
        activeNavItem?.paramKey &&
        paramState?.country_code &&
        (!paramState[activeNavItem.paramKey] ||
            paramState[activeNavItem.paramKey] === '0' ||
            paramState[activeNavItem.paramKey] === 'false'),
    );

    if (isCurrentSectionDisabled && activeSection !== 'parameters') {
        setActiveSection('parameters');
    }

    // Prevent parent window scrolling so toolbar never detaches or floats over the shell header
    useEffect(() => {
        const htmlOverflow = document.documentElement.style.overflow;
        const bodyOverflow = document.body.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';

        return () => {
            document.documentElement.style.overflow = htmlOverflow;
            document.body.style.overflow = bodyOverflow;
        };
    }, []);

    // Compute active dataset for grid view
    const currentDataset = useMemo(() => {
        switch (activeSection) {
            case 'countries': {
                const list = countries ?? [];

                if (filters?.country && activeSection === 'countries') {
                    return list.filter(
                        (c: any) =>
                            c.code === filters.country ||
                            c.iso3 === filters.country,
                    );
                }

                return list;
            }
            case 'provinces':
                return provinces ?? [];
            case 'regencies': {
                // County: Kabupaten, regional counties, and non-city divisions
                const all = (regencies ?? []).filter(isCountyRegency);

                if (filters?.regency && activeSection === 'regencies') {
                    const matched = all.filter(
                        (r: any) =>
                            r.id === filters.regency ||
                            r.code === filters.regency,
                    );

                    if (matched.length > 0) {
                        return matched;
                    }
                }

                return all;
            }
            case 'cities': {
                // City: Kota, municipalities, and urban cities
                const all = (regencies ?? []).filter(isCityRegency);

                if (filters?.regency && activeSection === 'cities') {
                    const matched = all.filter(
                        (r: any) =>
                            r.id === filters.regency ||
                            r.code === filters.regency,
                    );

                    if (matched.length > 0) {
                        return matched;
                    }
                }

                return all;
            }
            case 'districts': {
                const list = districts ?? [];

                if (filters?.district && activeSection === 'districts') {
                    const matched = list.filter(
                        (d: any) =>
                            d.id === filters.district ||
                            d.code === filters.district,
                    );

                    if (matched.length > 0) {
                        return matched;
                    }
                }

                return list;
            }
            case 'villages': {
                const list = villages ?? [];

                if (filters?.village && activeSection === 'villages') {
                    const matched = list.filter(
                        (v: any) =>
                            v.id === filters.village ||
                            v.code === filters.village,
                    );

                    if (matched.length > 0) {
                        return matched;
                    }
                }

                return list;
            }
            case 'streets':
                return streets ?? [];
            case 'groupOfHouses':
                return groupOfHouses ?? [];
            case 'landPlots':
                return landPlots ?? [];
            case 'buildings':
                return buildings ?? [];
            case 'postalCodes':
                return postalCodes ?? [];
            default:
                return [];
        }
    }, [
        activeSection,
        countries,
        provinces,
        regencies,
        districts,
        villages,
        streets,
        groupOfHouses,
        landPlots,
        buildings,
        postalCodes,
        filters,
    ]);

    // Filter dataset by search term
    const filteredDataset = useMemo(() => {
        if (!searchTerm.trim()) {
            return currentDataset;
        }

        const q = searchTerm.toLowerCase().trim();

        return currentDataset.filter((item: any) => {
            const name = (item.name || '').toLowerCase();
            const code = (
                item.code ||
                item.postal_code ||
                item.plot_number ||
                ''
            ).toLowerCase();
            const desc = (
                item.description ||
                item.area_name ||
                item.iso3 ||
                ''
            ).toLowerCase();

            return name.includes(q) || code.includes(q) || desc.includes(q);
        });
    }, [currentDataset, searchTerm]);

    // Helper: generate blank/default form state for any section
    const getBlankForm = useCallback(
        (sec?: Section) => {
            const targetSec = sec || activeSection;
            const blank: Record<string, any> = { active: true };
            const activeProvId = filterProvince || '';
            const activeRegId = filterRegency || '';
            const activeDistId = filterDistrict || '';
            const activeVillId = filterVillage || '';

            if (targetSec === 'countries') {
                blank.code = '';
                blank.iso3 = '';
                blank.name = '';
                blank.phone_code = '';
                blank.timezone = '';
                blank.active = true;
            } else if (targetSec === 'provinces') {
                blank.country_code = filterCountry || '';
                blank.code = '';
                blank.state_code = '';
                blank.name = '';
                blank.description = '';
                blank.default_state = false;
                blank.union_territory = false;
                blank.timezone = '';
                blank.active = true;
            } else if (targetSec === 'regencies') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.code = '';
                blank.name = '';
                blank.description = '';
                blank.type = 'kabupaten';
                blank.it_county_code = '';
                blank.es_county_code = '';
                blank.active = true;
            } else if (targetSec === 'cities') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.code = '';
                blank.name = '';
                blank.description = '';
                blank.type = 'kota';
                blank.it_county_code = '';
                blank.es_county_code = '';
                blank.active = true;
            } else if (targetSec === 'districts') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.code = '';
                blank.name = '';
                blank.active = true;
            } else if (targetSec === 'villages') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.district_id = activeDistId;
                blank.code = '';
                blank.name = '';
                blank.postal_code = '';
                blank.type = 'kelurahan';
                blank.active = true;
            } else if (targetSec === 'streets') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.district_id = activeDistId;
                blank.village_id = activeVillId;
                blank.name = '';
                blank.rt = '';
                blank.rw = '';
                blank.postal_code = '';
                blank.active = true;
            } else if (targetSec === 'buildings') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.district_id = activeDistId;
                blank.village_id = activeVillId;
                blank.name = '';
                blank.block = '';
                blank.unit = '';
                blank.floor = '';
                blank.postal_code = '';
                blank.active = true;
            } else if (targetSec === 'groupOfHouses') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.district_id = activeDistId;
                blank.village_id = activeVillId;
                blank.code = '';
                blank.name = '';
                blank.postal_code = '';
                blank.status = 'active';
                blank.active = true;
            } else if (targetSec === 'landPlots') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.district_id = activeDistId;
                blank.village_id = activeVillId;
                blank.plot_number = '';
                blank.name = '';
                blank.postal_code = '';
                blank.status = 'active';
                blank.active = true;
            } else if (targetSec === 'postalCodes') {
                blank.country_code = filterCountry || '';
                blank.province_id = activeProvId;
                blank.regency_id = activeRegId;
                blank.district_id = activeDistId;
                blank.village_id = activeVillId;
                blank.postal_code = '';
                blank.area_name = '';
                blank.active = true;
            }

            return blank;
        },
        [
            activeSection,
            filterCountry,
            filterProvince,
            filterRegency,
            filterDistrict,
            filterVillage,
        ],
    );

    // Sync selected record with filteredDataset when flash saved_id arrives or dataset changes
    const [prevSavedId, setPrevSavedId] = useState(flash?.saved_id);

    if (flash?.saved_id && flash.saved_id !== prevSavedId) {
        setPrevSavedId(flash.saved_id);
        const currentItem = filteredDataset.find(
            (item: any) =>
                item.id === flash.saved_id || item.code === flash.saved_id,
        ) as any;

        if (currentItem) {
            setSelectedId(currentItem.id || currentItem.code);
            setForm({ ...currentItem });
        }
    }

    const [prevDataset, setPrevDataset] = useState(filteredDataset);

    if (filteredDataset !== prevDataset) {
        setPrevDataset(filteredDataset);

        if (selectedId && !isNew) {
            const currentItem = filteredDataset.find(
                (item: any) =>
                    item.id === selectedId || item.code === selectedId,
            ) as any;

            if (!currentItem) {
                setSelectedId(null);
                setForm(getBlankForm(activeSection));
            } else {
                setForm((prev) => {
                    if (
                        (prev.id === currentItem.id ||
                            prev.code === currentItem.code) &&
                        prev.name === currentItem.name &&
                        prev.province_id === currentItem.province_id
                    ) {
                        return prev;
                    }

                    return { ...currentItem };
                });
            }
        }
    }

    // Synchronize top filter dropdowns with the clicked record in any hierarchy table
    const syncFiltersWithItem = (item: any, sec: Section) => {
        if (!item) {
            return;
        }

        if (sec === 'countries') {
            const countryCode = item.code || item.iso3;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            setFilterProvince('');
            setFilterRegency('');
            setFilterDistrict('');
            setFilterVillage('');
        } else if (sec === 'provinces') {
            const countryCode = item.country_code || filterCountry;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            if (item.id) {
                setFilterProvince(item.id);
            }

            setFilterRegency('');
            setFilterDistrict('');
            setFilterVillage('');
        } else if (sec === 'regencies' || sec === 'cities') {
            const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                (p: any) => p.id === item.province_id,
            );
            const countryCode =
                item.province?.country_code ||
                prov?.country_code ||
                item.country_code ||
                filterCountry;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            if (item.province_id) {
                setFilterProvince(item.province_id);
            }

            if (item.id) {
                setFilterRegency(item.id);
            }

            setFilterDistrict('');
            setFilterVillage('');
        } else if (sec === 'districts') {
            const reg = (dropdowns?.regencies ?? regencies ?? []).find(
                (r: any) => r.id === item.regency_id,
            );
            const provId =
                item.regency?.province_id || reg?.province_id || filterProvince;
            const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                (p: any) => p.id === provId,
            );
            const countryCode =
                item.regency?.province?.country_code ||
                prov?.country_code ||
                item.country_code ||
                filterCountry;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            if (provId) {
                setFilterProvince(provId);
            }

            if (item.regency_id) {
                setFilterRegency(item.regency_id);
            }

            if (item.id) {
                setFilterDistrict(item.id);
            }

            setFilterVillage('');
        } else if (sec === 'villages') {
            const dist = (dropdowns?.districts ?? districts ?? []).find(
                (d: any) => d.id === item.district_id,
            );
            const regId =
                item.district?.regency_id || dist?.regency_id || filterRegency;
            const reg = (dropdowns?.regencies ?? regencies ?? []).find(
                (r: any) => r.id === regId,
            );
            const provId =
                item.district?.regency?.province_id ||
                reg?.province_id ||
                filterProvince;
            const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                (p: any) => p.id === provId,
            );
            const countryCode =
                item.district?.regency?.province?.country_code ||
                prov?.country_code ||
                item.country_code ||
                filterCountry;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            if (provId) {
                setFilterProvince(provId);
            }

            if (regId) {
                setFilterRegency(regId);
            }

            if (item.district_id) {
                setFilterDistrict(item.district_id);
            }

            if (item.id) {
                setFilterVillage(item.id);
            }
        } else if (
            ['streets', 'groupOfHouses', 'landPlots', 'buildings'].includes(sec)
        ) {
            const vill = (dropdowns?.villages ?? villages ?? []).find(
                (v: any) => v.id === item.village_id,
            );
            const distId =
                item.village?.district_id ||
                vill?.district_id ||
                filterDistrict;
            const dist = (dropdowns?.districts ?? districts ?? []).find(
                (d: any) => d.id === distId,
            );
            const regId =
                item.village?.district?.regency_id ||
                dist?.regency_id ||
                filterRegency;
            const reg = (dropdowns?.regencies ?? regencies ?? []).find(
                (r: any) => r.id === regId,
            );
            const provId =
                item.village?.district?.regency?.province_id ||
                reg?.province_id ||
                filterProvince;
            const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                (p: any) => p.id === provId,
            );
            const countryCode =
                item.village?.district?.regency?.province?.country_code ||
                prov?.country_code ||
                item.country_code ||
                filterCountry;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            if (provId) {
                setFilterProvince(provId);
            }

            if (regId) {
                setFilterRegency(regId);
            }

            if (distId) {
                setFilterDistrict(distId);
            }

            if (item.village_id) {
                setFilterVillage(item.village_id);
            }
        } else if (sec === 'postalCodes') {
            const countryCode =
                item.country_code || item.country?.code || filterCountry;
            const provId =
                item.province_id || item.province?.id || filterProvince;
            const regId = item.regency_id || item.regency?.id || filterRegency;
            const distId =
                item.district_id || item.district?.id || filterDistrict;
            const villId = item.village_id || item.village?.id || filterVillage;

            if (countryCode) {
                setFilterCountry(countryCode);
            }

            if (provId) {
                setFilterProvince(provId);
            }

            if (regId) {
                setFilterRegency(regId);
            }

            if (distId) {
                setFilterDistrict(distId);
            }

            if (villId) {
                setFilterVillage(villId);
            }
        }
    };

    // Options for Country/region filter dropdown
    const countryOptions = useMemo(() => {
        const list = [{ value: '', label: '— All Countries —' }];
        const existing = new Set<string>();
        const allList = dropdowns?.countries ?? countries ?? [];
        allList.forEach((c) => {
            existing.add(c.code);
            list.push({
                value: c.code,
                label: `${c.iso3 || c.code} - ${c.name}`,
            });
        });

        if (filterCountry && !existing.has(filterCountry)) {
            list.push({ value: filterCountry, label: filterCountry });
        }

        return list;
    }, [dropdowns?.countries, countries, filterCountry]);

    // Options for State/province filter dropdown
    const provinceOptions = useMemo(() => {
        const list = [{ value: '', label: '— All States —' }];
        const existing = new Set<string>();
        const allProvs = dropdowns?.provinces ?? provinces ?? [];
        allProvs
            .filter((p) => !filterCountry || p.country_code === filterCountry)
            .forEach((p) => {
                existing.add(p.id);
                list.push({ value: p.id, label: p.name });
            });

        if (filterProvince && !existing.has(filterProvince)) {
            const found =
                allProvs.find((p) => p.id === filterProvince) ||
                (context?.province?.id === filterProvince
                    ? context.province
                    : null);
            list.push({
                value: filterProvince,
                label: found?.name || activeProvinceName || 'Selected State',
            });
        }

        return list;
    }, [
        dropdowns?.provinces,
        provinces,
        filterCountry,
        filterProvince,
        context,
        activeProvinceName,
    ]);

    // Options for County filter dropdown (Kabupaten only)
    const countyOptions = useMemo(() => {
        const list = [{ value: '', label: '— All Counties —' }];
        const existing = new Set<string>();
        const allRegs = dropdowns?.regencies ?? regencies ?? [];
        allRegs
            .filter((r) => {
                if (filterProvince && r.province_id !== filterProvince) {
                    return false;
                }

                if (filterCountry) {
                    const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                        (p: any) => p.id === r.province_id,
                    );
                    const c =
                        (r as any).country_code ||
                        (r as any).province?.country_code ||
                        prov?.country_code;

                    if (c && c !== filterCountry) {
                        return false;
                    }
                }

                return isCountyRegency(r);
            })
            .forEach((r) => {
                existing.add(r.id);
                list.push({ value: r.id, label: r.name });
            });

        if (filterRegency && !existing.has(filterRegency)) {
            const found =
                allRegs.find((r) => r.id === filterRegency) ||
                (context?.regency?.id === filterRegency
                    ? context.regency
                    : null);

            if (found && isCountyRegency(found)) {
                list.push({
                    value: filterRegency,
                    label: found.name || activeRegencyName || 'Selected County',
                });
            }
        }

        return list;
    }, [
        dropdowns?.regencies,
        regencies,
        dropdowns?.provinces,
        provinces,
        filterCountry,
        filterProvince,
        filterRegency,
        context,
        activeRegencyName,
    ]);

    // Options for City filter dropdown (Kota only)
    const cityOptions = useMemo(() => {
        const list = [{ value: '', label: '— All Cities —' }];
        const existing = new Set<string>();
        const allRegs = dropdowns?.regencies ?? regencies ?? [];
        allRegs
            .filter((r) => {
                if (filterProvince && r.province_id !== filterProvince) {
                    return false;
                }

                if (filterCountry) {
                    const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                        (p: any) => p.id === r.province_id,
                    );
                    const c =
                        (r as any).country_code ||
                        (r as any).province?.country_code ||
                        prov?.country_code;

                    if (c && c !== filterCountry) {
                        return false;
                    }
                }

                return isCityRegency(r);
            })
            .forEach((r) => {
                existing.add(r.id);
                list.push({ value: r.id, label: r.name });
            });

        if (filterRegency && !existing.has(filterRegency)) {
            const found =
                allRegs.find((r) => r.id === filterRegency) ||
                (context?.regency?.id === filterRegency
                    ? context.regency
                    : null);

            if (found && isCityRegency(found)) {
                list.push({
                    value: filterRegency,
                    label: found.name || activeRegencyName || 'Selected City',
                });
            }
        }

        return list;
    }, [
        dropdowns?.regencies,
        regencies,
        dropdowns?.provinces,
        provinces,
        filterCountry,
        filterProvince,
        filterRegency,
        context,
        activeRegencyName,
    ]);

    // Options for County/city filter dropdown (Both Kabupaten and Kota)
    const countyCityOptions = useMemo(() => {
        const list = [{ value: '', label: '— All Counties/Cities —' }];
        const existing = new Set<string>();
        const allRegs = dropdowns?.regencies ?? regencies ?? [];
        allRegs
            .filter((r) => {
                if (filterProvince && r.province_id !== filterProvince) {
                    return false;
                }

                if (filterCountry) {
                    const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                        (p: any) => p.id === r.province_id,
                    );
                    const c =
                        (r as any).country_code ||
                        (r as any).province?.country_code ||
                        prov?.country_code;

                    if (c && c !== filterCountry) {
                        return false;
                    }
                }

                return true;
            })
            .forEach((r) => {
                existing.add(r.id);
                list.push({ value: r.id, label: r.name });
            });

        if (filterRegency && !existing.has(filterRegency)) {
            const found =
                allRegs.find((r) => r.id === filterRegency) ||
                (context?.regency?.id === filterRegency
                    ? context.regency
                    : null);
            list.push({
                value: filterRegency,
                label:
                    found?.name || activeRegencyName || 'Selected County/City',
            });
        }

        return list;
    }, [
        dropdowns?.regencies,
        regencies,
        dropdowns?.provinces,
        provinces,
        filterCountry,
        filterProvince,
        filterRegency,
        context,
        activeRegencyName,
    ]);

    // Options for District filter dropdown
    const districtOptions = useMemo(() => {
        const list = [{ value: '', label: '— All Districts —' }];
        const existing = new Set<string>();
        const allDists = dropdowns?.districts ?? districts ?? [];
        allDists
            .filter((d) => {
                if (filterRegency && d.regency_id !== filterRegency) {
                    return false;
                }

                if (filterProvince) {
                    const reg = (dropdowns?.regencies ?? regencies ?? []).find(
                        (r: any) => r.id === d.regency_id,
                    );

                    if (
                        reg?.province_id &&
                        reg.province_id !== filterProvince
                    ) {
                        return false;
                    }
                }

                if (filterCountry) {
                    const reg = (dropdowns?.regencies ?? regencies ?? []).find(
                        (r: any) => r.id === d.regency_id,
                    );
                    const prov = (dropdowns?.provinces ?? provinces ?? []).find(
                        (p: any) => p.id === reg?.province_id,
                    );
                    const c =
                        (reg as any)?.country_code ||
                        (reg as any)?.province?.country_code ||
                        prov?.country_code;

                    if (c && c !== filterCountry) {
                        return false;
                    }
                }

                return true;
            })
            .forEach((d) => {
                existing.add(d.id);
                list.push({ value: d.id, label: d.name });
            });

        if (filterDistrict && !existing.has(filterDistrict)) {
            const found =
                allDists.find((d) => d.id === filterDistrict) ||
                (context?.district?.id === filterDistrict
                    ? context.district
                    : null);
            list.push({
                value: filterDistrict,
                label: found?.name || activeDistrictName || 'Selected District',
            });
        }

        return list;
    }, [
        dropdowns?.districts,
        districts,
        dropdowns?.regencies,
        regencies,
        dropdowns?.provinces,
        provinces,
        filterCountry,
        filterProvince,
        filterRegency,
        filterDistrict,
        context,
        activeDistrictName,
    ]);

    // Options for Village filter dropdown
    const villageOptions = useMemo(() => {
        const list = [{ value: '', label: '— All Villages —' }];
        const existing = new Set<string>();
        const allVills = dropdowns?.villages ?? villages ?? [];
        allVills
            .filter((v) => !filterDistrict || v.district_id === filterDistrict)
            .forEach((v) => {
                existing.add(v.id);
                list.push({ value: v.id, label: v.name });
            });

        if (filterVillage && !existing.has(filterVillage)) {
            const found = allVills.find((v) => v.id === filterVillage);
            list.push({
                value: filterVillage,
                label: found?.name || activeVillageName || 'Selected Village',
            });
        }

        return list;
    }, [
        dropdowns?.villages,
        villages,
        filterDistrict,
        filterVillage,
        activeVillageName,
    ]);

    // Execute server filter reload with strict section-scoping
    const executeFilter = (overrides: Record<string, any> = {}) => {
        const targetSection = (overrides.section ?? activeSection) as Section;

        const targetCountry =
            overrides.country !== undefined ? overrides.country : filterCountry;
        let targetProvince =
            overrides.province_id !== undefined
                ? overrides.province_id
                : filterProvince;
        let targetRegency =
            overrides.regency_id !== undefined
                ? overrides.regency_id
                : filterRegency;
        let targetDistrict =
            overrides.district_id !== undefined
                ? overrides.district_id
                : filterDistrict;
        let targetVillage =
            overrides.village_id !== undefined
                ? overrides.village_id
                : filterVillage;

        // Strip child parameters that do not belong to the target section
        if (targetSection === 'countries') {
            targetProvince = '';
            targetRegency = '';
            targetDistrict = '';
            targetVillage = '';
        } else if (targetSection === 'provinces') {
            targetRegency = '';
            targetDistrict = '';
            targetVillage = '';
        } else if (
            targetSection === 'regencies' ||
            targetSection === 'cities'
        ) {
            targetDistrict = '';
            targetVillage = '';
        } else if (targetSection === 'districts') {
            targetVillage = '';
        }

        const queryParams: Record<string, any> = {
            section: targetSection,
            country: targetCountry,
            province_id: targetProvince,
            regency_id: targetRegency,
            district_id: targetDistrict,
            village_id: targetVillage,
        };

        setIsFiltering(true);
        router.get('/settings/address-setup', queryParams, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                setIsFiltering(false);
            },
        });
    };

    const isFormDisabled = true;

    // Update form field
    const updateFormField = (field: string, val: any) => {
        setForm((prev) => ({ ...prev, [field]: val }));
    };

    // Page header title
    const pageHeaderTitle = useMemo(() => {
        switch (activeSection) {
            case 'parameters':
                return 'Parameters';
            case 'addressFormat':
                return 'Address Format';
            case 'countries':
                return 'Country / Region';
            case 'provinces':
                return 'State / Province';
            case 'regencies':
                return 'County';
            case 'cities':
                return 'City';
            case 'districts':
                return 'District';
            case 'villages':
                return 'Village';
            case 'streets':
                return 'Street';
            case 'groupOfHouses':
                return 'Group of Houses';
            case 'landPlots':
                return 'Land Plots';
            case 'buildings':
                return 'Group of Flats';
            case 'postalCodes':
                return 'ZIP / Postal Codes';
            default:
                return 'Address Setup';
        }
    }, [activeSection]);

    // New button label
    const newButtonLabel = useMemo(() => {
        switch (activeSection) {
            case 'countries':
                return 'Tambah Country / Region';
            case 'provinces':
                return 'Tambah State / Province';
            case 'regencies':
                return 'Tambah County';
            case 'cities':
                return 'Tambah City';
            case 'districts':
                return 'Tambah District';
            case 'villages':
                return 'Tambah Village';
            case 'streets':
                return 'Tambah Street';
            case 'groupOfHouses':
                return 'Tambah Group of Houses';
            case 'landPlots':
                return 'Tambah Land Plot';
            case 'buildings':
                return 'Tambah Group of Flats';
            case 'postalCodes':
                return 'Tambah ZIP / Postal Code';
            default:
                return 'Tambah Data';
        }
    }, [activeSection]);

    // Handle New Record
    const handleNew = () => {
        if (
            activeSection === 'parameters' ||
            activeSection === 'addressFormat'
        ) {
            return;
        }

        setIsNew(true);
        setSelectedId(null);
        setForm(getBlankForm(activeSection));
    };

    // Handle Delete Record / Discard New
    const handleDelete = () => {
        if (
            activeSection === 'parameters' ||
            activeSection === 'addressFormat'
        ) {
            showToast(
                'System configuration parameters cannot be deleted.',
                'error',
            );

            return;
        }

        if (isNew) {
            setIsNew(false);
            setSelectedId(null);
            setForm(getBlankForm(activeSection));
            showToast('Creation discarded.', 'success');

            return;
        }

        if (!selectedId) {
            return;
        }

        const record = currentDataset.find(
            (r: any) => r.id === selectedId || r.code === selectedId,
        ) as any;
        setDeleteConfirmTarget({
            id: selectedId,
            name: record?.name || record?.code || selectedId,
        });
    };

    const executeDelete = () => {
        if (!deleteConfirmTarget) {
            return;
        }

        setIsDeleting(true);

        const routeMap: Record<Section, string> = {
            parameters: '',
            addressFormat: '',
            countries: `/settings/address-setup/countries/${deleteConfirmTarget.id}`,
            provinces: `/settings/address-setup/provinces/${deleteConfirmTarget.id}`,
            regencies: `/settings/address-setup/regencies/${deleteConfirmTarget.id}`,
            cities: `/settings/address-setup/regencies/${deleteConfirmTarget.id}`,
            districts: `/settings/address-setup/districts/${deleteConfirmTarget.id}`,
            villages: `/settings/address-setup/villages/${deleteConfirmTarget.id}`,
            streets: `/settings/address-setup/streets/${deleteConfirmTarget.id}`,
            groupOfHouses: `/settings/address-setup/group-of-houses/${deleteConfirmTarget.id}`,
            landPlots: `/settings/address-setup/land-plots/${deleteConfirmTarget.id}`,
            buildings: `/settings/address-setup/buildings/${deleteConfirmTarget.id}`,
            postalCodes: `/settings/address-setup/postal-codes/${deleteConfirmTarget.id}`,
        };

        const targetUrl = routeMap[activeSection];

        if (!targetUrl) {
            return;
        }

        router.delete(targetUrl, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page: any) => {
                setIsDeleting(false);
                setDeleteConfirmTarget(null);
                setSelectedId(null);

                if (!page?.props?.flash?.status) {
                    showToast('Data berhasil dihapus.', 'success');
                }
            },
            onError: (errs) => {
                setIsDeleting(false);
                const firstErr = Object.values(errs)[0] as string;
                showToast(firstErr || 'Gagal menghapus data.', 'error');
            },
        });
    };

    // Handle Refresh per Section / Page
    const handleRefresh = () => {
        setSelectedId(null);
        setIsNew(false);
        setSearchTerm('');
        setForm(getBlankForm(activeSection));

        if (
            activeSection === 'parameters' ||
            activeSection === 'addressFormat'
        ) {
            setFilterCountry('');
            setParamState(normalizeParameter(null, ''));
            executeFilter({
                section: activeSection,
                country: '',
            });
            showToast('Pilihan negara berhasil di-refresh.', 'success');

            return;
        }

        if (activeSection === 'countries') {
            setFilterCountry('');
            executeFilter({
                section: 'countries',
                country: '',
            });
            showToast('Daftar negara berhasil di-refresh.', 'success');

            return;
        }

        if (activeSection === 'provinces') {
            // Keep country filter, reset province sub-filter
            setFilterProvince('');
            executeFilter({
                section: 'provinces',
                country: filterCountry,
                province_id: '',
            });
            showToast('Daftar provinsi berhasil di-refresh.', 'success');

            return;
        }

        if (activeSection === 'regencies' || activeSection === 'cities') {
            // Keep country and province filters, reset regency sub-filter
            setFilterRegency('');
            executeFilter({
                section: activeSection,
                country: filterCountry,
                province_id: filterProvince,
                regency_id: '',
            });
            showToast(
                activeSection === 'cities'
                    ? 'Daftar kota berhasil di-refresh.'
                    : 'Daftar kabupaten berhasil di-refresh.',
                'success',
            );

            return;
        }

        if (activeSection === 'districts') {
            // Keep country, province, and regency filters, reset district sub-filter
            setFilterDistrict('');
            executeFilter({
                section: 'districts',
                country: filterCountry,
                province_id: filterProvince,
                regency_id: filterRegency,
                district_id: '',
            });
            showToast('Daftar kecamatan berhasil di-refresh.', 'success');

            return;
        }

        if (activeSection === 'villages') {
            // Keep country, province, regency, and district filters, reset village sub-filter
            setFilterVillage('');
            executeFilter({
                section: 'villages',
                country: filterCountry,
                province_id: filterProvince,
                regency_id: filterRegency,
                district_id: filterDistrict,
                village_id: '',
            });
            showToast('Daftar kelurahan/desa berhasil di-refresh.', 'success');

            return;
        }

        if (
            ['streets', 'groupOfHouses', 'landPlots', 'buildings'].includes(
                activeSection,
            )
        ) {
            // Keep parent filters up to village
            executeFilter({
                section: activeSection,
                country: filterCountry,
                province_id: filterProvince,
                regency_id: filterRegency,
                district_id: filterDistrict,
                village_id: filterVillage,
            });
            showToast('Data berhasil di-refresh.', 'success');

            return;
        }

        if (activeSection === 'postalCodes') {
            // Keep parent filters
            executeFilter({
                section: 'postalCodes',
                country: filterCountry,
                province_id: filterProvince,
                regency_id: filterRegency,
                district_id: filterDistrict,
                village_id: filterVillage,
            });
            showToast('Daftar kode pos berhasil di-refresh.', 'success');

            return;
        }
    };

    // Handle Save Record (Simpan / Ubah)
    const handleSave = () => {
        setIsSaving(true);

        const storeRouteMap: Partial<Record<Section, string>> = {
            parameters: '/settings/address-setup/parameters',
            addressFormat: '/settings/address-setup/parameters',
            countries: '/settings/address-setup/countries',
            provinces: '/settings/address-setup/provinces',
            regencies: '/settings/address-setup/regencies',
            cities: '/settings/address-setup/regencies',
            districts: '/settings/address-setup/districts',
            villages: '/settings/address-setup/villages',
            streets: '/settings/address-setup/streets',
            groupOfHouses: '/settings/address-setup/group-of-houses',
            landPlots: '/settings/address-setup/land-plots',
            buildings: '/settings/address-setup/buildings',
            postalCodes: '/settings/address-setup/postal-codes',
        };

        const targetUrl = storeRouteMap[activeSection];

        if (!targetUrl) {
            setIsSaving(false);

            return;
        }

        let payload: Record<string, any> = { ...form };

        // Clean out nested relation objects if present
        delete payload.country;
        delete payload.province;
        delete payload.regency;
        delete payload.district;
        delete payload.village;

        if (
            activeSection === 'parameters' ||
            activeSection === 'addressFormat'
        ) {
            const targetCountry = filterCountry;

            if (!targetCountry) {
                setIsSaving(false);
                showToast('Pilih Negara / Wilayah terlebih dahulu.', 'error');

                return;
            }

            payload = {
                country_code: targetCountry,
                use_province: Boolean(paramState.use_province),
                use_regency: Boolean(paramState.use_regency),
                use_district: Boolean(paramState.use_district),
                use_village: Boolean(paramState.use_village),
                use_rt_rw: Boolean(paramState.use_rt_rw),
                use_postal_code: Boolean(paramState.use_postal_code),
                use_building: Boolean(paramState.use_building),
                address_format: paramState.address_format || null,
            };
        }

        // Countries section: ensure uppercase and valid code
        if (activeSection === 'countries') {
            if (payload.code && typeof payload.code === 'string') {
                payload.code = payload.code.toUpperCase();
            }

            if (payload.iso3 && typeof payload.iso3 === 'string') {
                payload.iso3 = payload.iso3.toUpperCase();
            }

            if (!payload.code && selectedId) {
                payload.code = String(selectedId).toUpperCase();
            }

            if (!payload.code) {
                setIsSaving(false);
                showToast('Kode negara wajib diisi (3 huruf).', 'error');

                return;
            }

            if (!payload.name) {
                setIsSaving(false);
                showToast('Nama negara wajib diisi.', 'error');

                return;
            }

            const countryTz = payload.timezone || form.timezone;

            if (!countryTz) {
                setIsSaving(false);
                showToast('Time Zone wajib dipilih.', 'error');

                return;
            }

            payload.timezone = countryTz;
        }

        if (activeSection === 'provinces') {
            if (!payload.code && payload.state_code) {
                payload.code = payload.state_code;
            }

            if (!payload.state_code && payload.code) {
                payload.state_code = payload.code;
            }

            if (!payload.name && payload.description) {
                payload.name = payload.description;
            }

            if (!payload.description && payload.name) {
                payload.description = payload.name;
            }

            const provTz = payload.timezone || form.timezone;

            if (!provTz) {
                setIsSaving(false);
                showToast('Time Zone wajib dipilih.', 'error');

                return;
            }

            payload.timezone = provTz;
        }

        if (activeSection === 'regencies' || activeSection === 'cities') {
            if (!payload.name && payload.description) {
                payload.name = payload.description;
            }

            if (!payload.description && payload.name) {
                payload.description = payload.name;
            }
        }

        // Assign ID or code for updates
        if (!isNew && selectedId) {
            if (activeSection === 'countries') {
                payload.code = (form.code || selectedId || '').toUpperCase();
            } else {
                payload.id = form.id || selectedId;
            }
        }

        // Guarantee parent references for BOTH new records and edits
        if (!payload.country_code && (form.country_code || filterCountry)) {
            payload.country_code = form.country_code || filterCountry;
        }

        if (payload.country_code && typeof payload.country_code === 'string') {
            payload.country_code = payload.country_code.toUpperCase();
        }

        if (!payload.province_id && (form.province_id || filterProvince)) {
            payload.province_id = form.province_id || filterProvince;
        }

        if (!payload.regency_id && (form.regency_id || filterRegency)) {
            payload.regency_id = form.regency_id || filterRegency;
        }

        if (!payload.district_id && (form.district_id || filterDistrict)) {
            payload.district_id = form.district_id || filterDistrict;
        }

        if (!payload.village_id && (form.village_id || filterVillage)) {
            payload.village_id = form.village_id || filterVillage;
        }

        // Default country for postal codes
        if (
            activeSection === 'postalCodes' &&
            !payload.country_code &&
            filterCountry
        ) {
            payload.country_code = filterCountry;
        }

        // Validations for required parents
        if (
            (activeSection === 'regencies' || activeSection === 'cities') &&
            !payload.province_id
        ) {
            setIsSaving(false);
            showToast('Pilih provinsi terlebih dahulu pada filter.', 'error');

            return;
        }

        if (activeSection === 'districts' && !payload.regency_id) {
            setIsSaving(false);
            showToast(
                'Pilih kabupaten / kota terlebih dahulu pada filter.',
                'error',
            );

            return;
        }

        if (activeSection === 'villages' && !payload.district_id) {
            setIsSaving(false);
            showToast('Pilih kecamatan terlebih dahulu pada filter.', 'error');

            return;
        }

        if (
            ['streets', 'buildings', 'groupOfHouses', 'landPlots'].includes(
                activeSection,
            ) &&
            !payload.village_id
        ) {
            setIsSaving(false);
            showToast(
                'Pilih kelurahan / desa terlebih dahulu pada filter.',
                'error',
            );

            return;
        }

        // Guarantee active flag
        if (payload.active === undefined) {
            payload.active = '1';
        }

        // Guarantee type for regencies / cities / villages
        if (activeSection === 'regencies' && !payload.type) {
            payload.type = 'kabupaten';
        } else if (activeSection === 'cities' && !payload.type) {
            payload.type = 'kota';
        } else if (activeSection === 'villages' && !payload.type) {
            payload.type = 'kelurahan';
        }

        const wasNew = isNew;

        router.post(targetUrl, payload, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page: any) => {
                setIsSaving(false);
                setIsNew(false);

                if (
                    activeSection === 'parameters' ||
                    activeSection === 'addressFormat'
                ) {
                    if (!page?.props?.flash?.status) {
                        showToast(
                            activeSection === 'parameters'
                                ? 'Pengaturan parameter berhasil disimpan.'
                                : 'Format template alamat berhasil disimpan.',
                            'success',
                        );
                    }

                    return;
                }

                const newlySavedId =
                    page?.props?.flash?.saved_id || payload.id || payload.code;

                if (newlySavedId) {
                    setSelectedId(newlySavedId);
                }

                if (!page?.props?.flash?.status) {
                    showToast(
                        wasNew
                            ? 'Data berhasil disimpan.'
                            : 'Data berhasil diubah.',
                        'success',
                    );
                }
            },
            onError: (errs) => {
                setIsSaving(false);
                const firstErr = Object.values(errs)[0] as string;
                showToast(firstErr || 'Gagal menyimpan data.', 'error');
            },
        });
    };

    // Grid column definitions
    const gridColumns = useMemo(() => {
        switch (activeSection) {
            case 'countries':
                return { col1: 'Country Code', col2: 'Country Name' };
            case 'provinces':
                return { col1: 'State / Province', col2: 'Description' };
            case 'regencies':
                return { col1: 'County', col2: 'Description' };
            case 'cities':
                return { col1: 'City', col2: 'Description' };
            case 'districts':
                return { col1: 'District', col2: 'Description' };
            case 'villages':
                return { col1: 'Village', col2: 'Description' };
            case 'streets':
                return { col1: 'Street', col2: 'Description' };
            case 'groupOfHouses':
                return { col1: 'Group of Houses', col2: 'Description' };
            case 'landPlots':
                return { col1: 'Plot Number', col2: 'Name' };
            case 'buildings':
                return { col1: 'Group of Flats', col2: 'Block / Unit' };
            case 'postalCodes':
                return { col1: 'ZIP / Postal Codes', col2: 'Area Name' };
            default:
                return { col1: 'Code', col2: 'Description' };
        }
    }, [activeSection]);

    return (
        <div className="flex h-[calc(100svh-5rem)] w-full max-w-full min-w-0 overflow-hidden bg-white font-sans text-xs text-[#242424] antialiased">
            <Head title="Address Setup" />

            {/* 1. LEFT SIDEBAR (ENGLISH TAB BAR) */}
            <AddressSidebar
                activeSection={activeSection}
                parameterConfig={paramState}
                onSelectSection={(sec) => {
                    setActiveSection(sec);
                    setSelectedId(null);
                    setIsNew(false);
                    setForm(getBlankForm(sec));
                    setSearchTerm('');

                    // Carry over the active country filter:
                    const selectedCountry = filterCountry;

                    let targetProvince = filterProvince;
                    let targetRegency = filterRegency;
                    let targetDistrict = filterDistrict;
                    let targetVillage = filterVillage;

                    if (sec === 'countries') {
                        targetProvince = '';
                        targetRegency = '';
                        targetDistrict = '';
                        targetVillage = '';
                    } else if (sec === 'provinces') {
                        targetRegency = '';
                        targetDistrict = '';
                        targetVillage = '';
                    } else if (sec === 'regencies' || sec === 'cities') {
                        targetDistrict = '';
                        targetVillage = '';
                    } else if (sec === 'districts') {
                        targetDistrict = '';
                        targetVillage = '';
                    } else if (sec === 'villages') {
                        targetVillage = '';
                    }

                    setFilterCountry(selectedCountry);
                    setFilterProvince(targetProvince);
                    setFilterRegency(targetRegency);
                    setFilterDistrict(targetDistrict);
                    setFilterVillage(targetVillage);

                    executeFilter({
                        section: sec,
                        country: selectedCountry,
                        province_id: targetProvince,
                        regency_id: targetRegency,
                        district_id: targetDistrict,
                        village_id: targetVillage,
                    });
                }}
            />

            {/* 2. MAIN CONTENT AREA */}
            <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-white">
                {/* TOOLBAR — Read-only mode: semua aksi CRUD disembunyikan sementara */}
                <AddressToolbar
                    title={pageHeaderTitle}
                    newLabel={newButtonLabel}
                    onNew={handleNew}
                    onDelete={handleDelete}
                    onSave={handleSave}
                    onExternalCodes={openExternalCodesModal}
                    onTranslations={openTranslationsModal}
                    onFilterToggle={
                        ['parameters', 'addressFormat'].includes(activeSection)
                            ? undefined
                            : handleToggleFilter
                    }
                    isFilterActive={isFilterOpen}
                    searchTerm={searchTerm}
                    onSearchChange={setSearchTerm}
                    showNew={false}
                    showSave={false}
                    showDelete={false}
                    showTranslations={false}
                    showSearch={false}
                    canDelete={false}
                    canManageRelations={false}
                    isSaving={isSaving}
                    isDeleting={isDeleting}
                />

                {/* FILTER BAR (Below Toolbar for hierarchy entities) */}
                {isFilterOpen &&
                    !['parameters', 'addressFormat'].includes(
                        activeSection,
                    ) && (
                        <div className="relative z-30 flex shrink-0 flex-wrap items-center gap-x-2 gap-y-4 border-b border-[#edebe9] bg-white px-3 pt-5 pb-3 xl:flex-nowrap">
                            {/* Country filter */}
                            <AddressFieldGroup
                                label="Country / Region"
                                className="w-[130px] shrink-0 sm:w-[135px] xl:w-[145px]"
                                size="sm"
                                alwaysFloating
                            >
                                <AddressSelect
                                    value={filterCountry}
                                    onChange={(val) => {
                                        setFilterCountry(val);
                                        setFilterProvince('');
                                        setFilterRegency('');
                                        setFilterDistrict('');
                                        setFilterVillage('');

                                        if (activeSection === 'countries') {
                                            setSelectedId(val || null);

                                            if (val) {
                                                const c = (
                                                    dropdowns?.countries ??
                                                    countries ??
                                                    []
                                                ).find(
                                                    (x) =>
                                                        x.code === val ||
                                                        x.iso3 === val,
                                                );

                                                if (c) {
                                                    setForm({ ...c });
                                                }
                                            }

                                            executeFilter({ country: val });
                                        } else {
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                country: val,
                                                province_id: '',
                                                regency_id: '',
                                                district_id: '',
                                                village_id: '',
                                            });
                                        }
                                    }}
                                    options={countryOptions}
                                />
                            </AddressFieldGroup>

                            {/* State/Province filter */}
                            {activeSection !== 'countries' && (
                                <AddressFieldGroup
                                    label="State / Province"
                                    className="w-[130px] shrink-0 sm:w-[135px] xl:w-[145px]"
                                    size="sm"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={filterProvince}
                                        onChange={(val) => {
                                            setFilterProvince(val);
                                            setFilterRegency('');
                                            setFilterDistrict('');
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                province_id: val,
                                                regency_id: '',
                                                district_id: '',
                                                village_id: '',
                                            });
                                        }}
                                        options={provinceOptions}
                                    />
                                </AddressFieldGroup>
                            )}

                            {/* County filter (only on County section) */}
                            {activeSection === 'regencies' && (
                                <AddressFieldGroup
                                    label="County"
                                    className="w-[130px] shrink-0 sm:w-[135px] xl:w-[145px]"
                                    size="sm"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={
                                            selectedRegency &&
                                            isCountyRegency(selectedRegency)
                                                ? filterRegency
                                                : ''
                                        }
                                        onChange={(val) => {
                                            setFilterRegency(val);
                                            setFilterDistrict('');
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                regency_id: val,
                                                district_id: '',
                                                village_id: '',
                                            });
                                        }}
                                        options={countyOptions}
                                    />
                                </AddressFieldGroup>
                            )}

                            {/* City filter (only on City section) */}
                            {activeSection === 'cities' && (
                                <AddressFieldGroup
                                    label="City"
                                    className="w-[130px] shrink-0 sm:w-[135px] xl:w-[145px]"
                                    size="sm"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={
                                            selectedRegency &&
                                            isCityRegency(selectedRegency)
                                                ? filterRegency
                                                : ''
                                        }
                                        onChange={(val) => {
                                            setFilterRegency(val);
                                            setFilterDistrict('');
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                regency_id: val,
                                                district_id: '',
                                                village_id: '',
                                            });
                                        }}
                                        options={cityOptions}
                                    />
                                </AddressFieldGroup>
                            )}

                            {/* County/city filter (on District and downstream sections) */}
                            {[
                                'districts',
                                'villages',
                                'streets',
                                'groupOfHouses',
                                'landPlots',
                                'buildings',
                                'postalCodes',
                            ].includes(activeSection) && (
                                <AddressFieldGroup
                                    label="County / City"
                                    className="w-[135px] shrink-0 sm:w-[140px] xl:w-[150px]"
                                    size="sm"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={filterRegency}
                                        onChange={(val) => {
                                            setFilterRegency(val);
                                            setFilterDistrict('');
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                regency_id: val,
                                                district_id: '',
                                                village_id: '',
                                            });
                                        }}
                                        options={countyCityOptions}
                                    />
                                </AddressFieldGroup>
                            )}

                            {/* District filter */}
                            {[
                                'districts',
                                'villages',
                                'streets',
                                'groupOfHouses',
                                'landPlots',
                                'buildings',
                                'postalCodes',
                            ].includes(activeSection) && (
                                <AddressFieldGroup
                                    label="District"
                                    className="w-[125px] shrink-0 sm:w-[130px] xl:w-[140px]"
                                    size="sm"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={filterDistrict}
                                        onChange={(val) => {
                                            setFilterDistrict(val);
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                district_id: val,
                                                village_id: '',
                                            });
                                        }}
                                        options={districtOptions}
                                    />
                                </AddressFieldGroup>
                            )}

                            {/* Village filter */}
                            {[
                                'villages',
                                'streets',
                                'groupOfHouses',
                                'landPlots',
                                'buildings',
                                'postalCodes',
                            ].includes(activeSection) && (
                                <AddressFieldGroup
                                    label="Village"
                                    className="w-[125px] shrink-0 sm:w-[130px] xl:w-[140px]"
                                    size="sm"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={filterVillage}
                                        onChange={(val) => {
                                            setFilterVillage(val);
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter({
                                                village_id: val,
                                            });
                                        }}
                                        options={villageOptions}
                                    />
                                </AddressFieldGroup>
                            )}

                            {/* Apply Filter & Reset Buttons */}
                            <div className="flex shrink-0 items-center gap-1.5">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    type="button"
                                    disabled={isFiltering}
                                    onClick={() => {
                                        if (activeSection === 'countries') {
                                            if (filterCountry) {
                                                setSelectedId(filterCountry);
                                                const c = (
                                                    dropdowns?.countries ??
                                                    countries ??
                                                    []
                                                ).find(
                                                    (x) =>
                                                        x.code ===
                                                            filterCountry ||
                                                        x.iso3 ===
                                                            filterCountry,
                                                );

                                                if (c) {
                                                    setForm({ ...c });
                                                }
                                            }

                                            executeFilter({
                                                country: filterCountry,
                                            });
                                        } else {
                                            setSelectedId(null);
                                            setIsNew(false);
                                            executeFilter();
                                        }
                                    }}
                                    className="flex h-9 shrink-0 cursor-pointer items-center gap-1.5 border-0 bg-[#0078d4] px-3 text-xs font-medium text-white shadow-xs hover:bg-[#106ebe] disabled:opacity-50"
                                >
                                    {isFiltering ? (
                                        <>
                                            <Loader2
                                                size={12}
                                                className="animate-spin"
                                            />
                                            <span>Applying...</span>
                                        </>
                                    ) : (
                                        <>
                                            <Filter size={12} />
                                            <span>Apply Filter</span>
                                        </>
                                    )}
                                </Button>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    type="button"
                                    disabled={isFiltering}
                                    onClick={handleRefresh}
                                    className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center border border-[#d9dfe7] bg-white p-0 text-xs text-[#605e5c] shadow-xs hover:bg-[#f3f4f6] hover:text-[#1f2937]"
                                    title="Reset Filters to Default"
                                >
                                    <RotateCcw size={13} />
                                </Button>
                            </div>
                        </div>
                    )}

                {/* CONTENT PANELS */}
                <div className="flex min-h-0 flex-1 overflow-hidden">
                    {/* SECTION: PARAMETERS */}
                    {activeSection === 'parameters' && (
                        <div className="max-w-4xl flex-1 space-y-6 overflow-y-auto p-6 lg:p-8">
                            {/* Country Selector Header */}
                            <div className="flex shrink-0 flex-nowrap items-center gap-2 border-b border-[#edebe9] pb-4">
                                <AddressFieldGroup
                                    label="Country / Region"
                                    className="w-[280px] shrink-0"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={filterCountry}
                                        onChange={(val) => {
                                            setFilterCountry(val);
                                            const found = parameters?.find(
                                                (p) => p.country_code === val,
                                            );
                                            setParamState(
                                                normalizeParameter(found, val),
                                            );
                                            executeFilter({ country: val });
                                        }}
                                        placeholder="— Select Country / Region —"
                                        options={[
                                            {
                                                value: '',
                                                label: '— Select Country / Region —',
                                            },
                                            ...availableCountries.map((c) => ({
                                                value: c.code,
                                                label: `${c.iso3 || c.code} - ${c.name}`,
                                            })),
                                        ]}
                                    />
                                </AddressFieldGroup>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    type="button"
                                    disabled={isFiltering}
                                    onClick={handleRefresh}
                                    className="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center border border-[#d9dfe7] bg-white p-0 text-xs text-[#605e5c] shadow-xs hover:bg-[#f3f4f6] hover:text-[#1f2937]"
                                    title="Reset Pilihan Negara"
                                >
                                    <RotateCcw size={14} />
                                </Button>
                            </div>

                            {/* Parameters Toggles Grid */}
                            <div className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 md:grid-cols-3">
                                <AddressFieldGroup
                                    label="Use State / Province"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(
                                            paramState.use_province,
                                        )}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_province: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup
                                    label="Use County"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(
                                            paramState.use_regency,
                                        )}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_regency: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup
                                    label="Use District"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(
                                            paramState.use_district,
                                        )}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_district: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup
                                    label="Use Village / Sub-district"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(
                                            paramState.use_village,
                                        )}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_village: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup
                                    label="Use Street / RT-RW"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(paramState.use_rt_rw)}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_rt_rw: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup
                                    label="Use ZIP / Postal Code"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(
                                            paramState.use_postal_code,
                                        )}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_postal_code: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup
                                    label="Use Building / Flats"
                                    floating={false}
                                >
                                    <AddressToggle
                                        checked={Boolean(
                                            paramState.use_building,
                                        )}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                use_building: val,
                                            }))
                                        }
                                    />
                                </AddressFieldGroup>
                            </div>
                        </div>
                    )}

                    {/* SECTION: ADDRESS FORMAT */}
                    {activeSection === 'addressFormat' && (
                        <div className="max-w-4xl flex-1 space-y-6 overflow-y-auto p-6 lg:p-8">
                            {/* Country Selector Header */}
                            <div className="flex shrink-0 flex-nowrap items-center gap-2 border-b border-[#edebe9] pb-4">
                                <AddressFieldGroup
                                    label="Country / Region"
                                    className="w-[280px] shrink-0"
                                    alwaysFloating
                                >
                                    <AddressSelect
                                        value={filterCountry}
                                        onChange={(val) => {
                                            setFilterCountry(val);
                                            const found = parameters?.find(
                                                (p) => p.country_code === val,
                                            );
                                            setParamState(
                                                normalizeParameter(found, val),
                                            );
                                            executeFilter({ country: val });
                                        }}
                                        placeholder="— Select Country / Region —"
                                        options={[
                                            {
                                                value: '',
                                                label: '— Select Country / Region —',
                                            },
                                            ...availableCountries.map((c) => ({
                                                value: c.code,
                                                label: `${c.iso3 || c.code} - ${c.name}`,
                                            })),
                                        ]}
                                    />
                                </AddressFieldGroup>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    type="button"
                                    disabled={isFiltering}
                                    onClick={handleRefresh}
                                    className="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center border border-[#d9dfe7] bg-white p-0 text-xs text-[#605e5c] shadow-xs hover:bg-[#f3f4f6] hover:text-[#1f2937]"
                                    title="Reset Pilihan Negara"
                                >
                                    <RotateCcw size={14} />
                                </Button>
                            </div>

                            <div className="space-y-4">
                                <AddressFieldGroup label="Address Format Template">
                                    <AddressInput
                                        value={paramState.address_format || ''}
                                        disabled={true}
                                        onChange={(val) =>
                                            setParamState((prev) => ({
                                                ...prev,
                                                address_format: val,
                                            }))
                                        }
                                        placeholder={
                                            filterCountry
                                                ? 'Ketik format alamat secara manual atau klik tag di bawah...'
                                                : 'Pilih Negara / Wilayah terlebih dahulu'
                                        }
                                    />
                                    <div className="flex flex-wrap items-center gap-1.5 pt-1">
                                        <span className="text-[11px] font-medium text-[#605e5c]">
                                            Available Tags:
                                        </span>
                                        {[
                                            '{street}',
                                            '{rt}',
                                            '{rw}',
                                            '{village}',
                                            '{district}',
                                            '{regency}',
                                            '{province}',
                                            '{postal_code}',
                                            '{country}',
                                            '{building}',
                                            '{block}',
                                            '{unit}',
                                            '{floor}',
                                        ].map((tag) => (
                                            <button
                                                key={tag}
                                                type="button"
                                                disabled={true}
                                                onClick={() => {
                                                    setParamState((prev) => ({
                                                        ...prev,
                                                        address_format:
                                                            prev.address_format
                                                                ? `${prev.address_format} ${tag}`
                                                                : tag,
                                                    }));
                                                }}
                                                className="cursor-pointer rounded border border-[#e5e7eb] bg-[#f9fafb] px-1.5 py-0.5 font-mono text-[11px] text-[#374151] transition-colors hover:border-[#0284c7] hover:bg-[#f0f9ff] hover:text-[#0284c7] disabled:cursor-not-allowed disabled:opacity-50"
                                                title={`Click to append ${tag}`}
                                            >
                                                {tag}
                                            </button>
                                        ))}
                                    </div>
                                </AddressFieldGroup>

                                {/* LIVE ADDRESS PREVIEW CARD */}
                                <div className="rounded-md border border-[#d9dfe7] bg-[#fcfdfe] p-4 shadow-2xs">
                                    <div className="flex items-center justify-between border-b border-[#edebe9] pb-2">
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-semibold tracking-wide text-[#1f2937] uppercase">
                                                Live Address Preview
                                            </span>
                                            <span className="rounded bg-[#dbeafe] px-2 py-0.5 text-[11px] font-medium text-[#1d4ed8]">
                                                Format Preview
                                            </span>
                                        </div>
                                        <span className="text-[11px] text-[#605e5c]">
                                            Pratinjau otomatis sesuai format
                                            template
                                        </span>
                                    </div>

                                    <div className="mt-3 rounded border border-[#e5e7eb] bg-white p-3 font-sans text-sm text-[#111827] shadow-2xs">
                                        {(() => {
                                            const tpl =
                                                paramState.address_format;

                                            if (!tpl || !tpl.trim()) {
                                                return (
                                                    <span className="text-[#8a8886] italic">
                                                        (Template format alamat
                                                        masih kosong - ketik
                                                        manual atau klik tag di
                                                        atas)
                                                    </span>
                                                );
                                            }

                                            const sampleMap: Record<
                                                string,
                                                string
                                            > = {
                                                '{street}':
                                                    'Jl. Jenderal Sudirman No. 45',
                                                '{rt}': '03',
                                                '{rw}': '05',
                                                '{village}': 'Senayan',
                                                '{district}': 'Kebayoran Baru',
                                                '{regency}': 'Jakarta Selatan',
                                                '{province}': 'DKI Jakarta',
                                                '{postal_code}': '12190',
                                                '{country}': 'Indonesia',
                                                '{building}': 'Gedung Sentral',
                                                '{block}': 'Blok A',
                                                '{unit}': 'Unit 102',
                                                '{floor}': 'Lantai 3',
                                            };
                                            let res = tpl;

                                            for (const [
                                                tag,
                                                val,
                                            ] of Object.entries(sampleMap)) {
                                                res = res.split(tag).join(val);
                                            }

                                            return res;
                                        })()}
                                    </div>

                                    <div className="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-[#6b7280]">
                                        <span className="font-medium">
                                            Active tags in template:
                                        </span>
                                        {[
                                            'street',
                                            'rt',
                                            'rw',
                                            'village',
                                            'district',
                                            'regency',
                                            'province',
                                            'postal_code',
                                            'country',
                                            'building',
                                            'block',
                                            'unit',
                                            'floor',
                                        ]
                                            .filter((tag) =>
                                                (
                                                    paramState.address_format ||
                                                    ''
                                                ).includes(`{${tag}}`),
                                            )
                                            .map((tag) => (
                                                <span
                                                    key={tag}
                                                    className="rounded bg-[#eff6ff] px-1.5 py-0.5 font-mono text-[10px] text-[#2563eb]"
                                                >
                                                    {`{${tag}}`}
                                                </span>
                                            ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TWO-PANEL DATA GRID & FLAT DETAIL FORM (For Master Data Sections) */}
                    {!['parameters', 'addressFormat'].includes(
                        activeSection,
                    ) && (
                        <>
                            {/* LEFT DATA GRID */}
                            <div className="flex w-[320px] shrink-0 flex-col overflow-hidden border-r border-[#edebe9] bg-white">
                                <div className="flex h-[28px] shrink-0 items-center border-b border-[#edebe9] bg-[#f8f9fa] px-3 text-[11.5px] font-semibold text-[#605e5c] select-none">
                                    <div className="flex w-2/5 items-center gap-1 truncate">
                                        <span>{gridColumns.col1}</span>
                                        <ArrowUpDown
                                            size={11}
                                            className="text-[#8a8886]"
                                        />
                                    </div>
                                    <div className="w-3/5 truncate">
                                        {gridColumns.col2}
                                    </div>
                                </div>

                                <div className="flex-1 divide-y divide-[#f3f2f1] overflow-y-auto">
                                    {filteredDataset.length === 0 ? (
                                        <div className="p-4 text-center text-xs text-[#8a8886]">
                                            No records found.
                                        </div>
                                    ) : (
                                        filteredDataset.map((item: any) => {
                                            const itemId = item.id || item.code;
                                            const isSelected =
                                                selectedId === itemId && !isNew;

                                            return (
                                                <button
                                                    key={itemId}
                                                    type="button"
                                                    onClick={() => {
                                                        setSelectedId(itemId);
                                                        setIsNew(false);
                                                        setForm({ ...item });
                                                        syncFiltersWithItem(
                                                            item,
                                                            activeSection,
                                                        );
                                                    }}
                                                    className={`flex w-full cursor-pointer items-center px-3 py-1.5 text-left text-[12px] transition-colors ${
                                                        isSelected
                                                            ? 'bg-[#dbe8f9] font-medium text-[#242424]'
                                                            : 'text-[#242424] hover:bg-[#f3f7fd]'
                                                    }`}
                                                >
                                                    {(() => {
                                                        const isPostalSection =
                                                            activeSection ===
                                                            'postalCodes';
                                                        let col1Val = '';
                                                        let col2Val = '';

                                                        if (
                                                            activeSection ===
                                                            'countries'
                                                        ) {
                                                            col1Val =
                                                                item.iso3 ||
                                                                item.code;
                                                            col2Val =
                                                                item.name ||
                                                                '—';
                                                        } else if (
                                                            activeSection ===
                                                            'provinces'
                                                        ) {
                                                            col1Val =
                                                                item.name ||
                                                                item.code;
                                                            col2Val =
                                                                item.description ||
                                                                item.name ||
                                                                '—';
                                                        } else if (
                                                            activeSection ===
                                                                'regencies' ||
                                                            activeSection ===
                                                                'cities'
                                                        ) {
                                                            col1Val =
                                                                item.code ||
                                                                item.display_code ||
                                                                item.name;
                                                            col2Val =
                                                                item.name ||
                                                                item.description ||
                                                                '—';
                                                        } else if (
                                                            activeSection ===
                                                                'districts' ||
                                                            activeSection ===
                                                                'villages'
                                                        ) {
                                                            col1Val =
                                                                item.code ||
                                                                item.display_code ||
                                                                item.name;
                                                            col2Val =
                                                                item.name ||
                                                                '—';
                                                        } else if (
                                                            activeSection ===
                                                            'streets'
                                                        ) {
                                                            col1Val =
                                                                item.name ||
                                                                'Street';
                                                            col2Val =
                                                                item.rt ||
                                                                item.rw
                                                                    ? `RT ${item.rt || '-'}/RW ${item.rw || '-'}`
                                                                    : item.name ||
                                                                      '—';
                                                        } else if (
                                                            activeSection ===
                                                            'groupOfHouses'
                                                        ) {
                                                            col1Val =
                                                                item.name ||
                                                                item.code ||
                                                                '—';
                                                            col2Val =
                                                                item.description ||
                                                                item.status ||
                                                                '—';
                                                        } else if (
                                                            activeSection ===
                                                            'landPlots'
                                                        ) {
                                                            col1Val =
                                                                item.plot_number ||
                                                                item.code ||
                                                                '—';
                                                            col2Val =
                                                                item.name ||
                                                                '—';
                                                        } else if (
                                                            activeSection ===
                                                            'buildings'
                                                        ) {
                                                            col1Val =
                                                                item.name ||
                                                                item.building_name ||
                                                                '—';
                                                            col2Val = item.unit
                                                                ? `Unit ${item.unit}`
                                                                : item.block ||
                                                                  '—';
                                                        } else if (
                                                            isPostalSection
                                                        ) {
                                                            col1Val =
                                                                item.postal_code ||
                                                                item.code ||
                                                                '—';
                                                            col2Val =
                                                                item.area_name ||
                                                                item.name ||
                                                                '—';
                                                        } else {
                                                            col1Val =
                                                                item.display_code ||
                                                                item.code ||
                                                                item.name ||
                                                                item.id;
                                                            col2Val =
                                                                item.name ||
                                                                item.description ||
                                                                '—';
                                                        }

                                                        return (
                                                            <>
                                                                <div className="w-2/5 truncate font-mono text-xs">
                                                                    {col1Val}
                                                                </div>
                                                                <div className="w-3/5 truncate text-xs text-[#605e5c]">
                                                                    {col2Val}
                                                                </div>
                                                            </>
                                                        );
                                                    })()}
                                                </button>
                                            );
                                        })
                                    )}
                                </div>
                            </div>

                            {/* RIGHT FLAT DETAIL FORM */}
                            <div className="min-w-0 flex-1 overflow-y-auto bg-white p-6 lg:p-8">
                                <div className="max-w-5xl space-y-4">
                                    {/* COUNTRY FORM */}
                                    {activeSection === 'countries' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup
                                                    label="Country Code"
                                                    required
                                                >
                                                    <AddressInput
                                                        value={
                                                            form.code ||
                                                            form.iso3 ||
                                                            ''
                                                        }
                                                        onChange={(val) => {
                                                            const v = val
                                                                .toUpperCase()
                                                                .slice(0, 3);
                                                            updateFormField(
                                                                'code',
                                                                v,
                                                            );

                                                            if (
                                                                !form.iso3 ||
                                                                form.iso3
                                                                    .length <= 3
                                                            ) {
                                                                updateFormField(
                                                                    'iso3',
                                                                    v,
                                                                );
                                                            }
                                                        }}
                                                        disabled={
                                                            isFormDisabled ||
                                                            !isNew
                                                        }
                                                        placeholder=""
                                                        required
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup
                                                    label="Country Name"
                                                    required
                                                >
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder=""
                                                        required
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="ISO3 Code">
                                                    <AddressInput
                                                        value={
                                                            form.iso3 ||
                                                            form.code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'iso3',
                                                                val
                                                                    .toUpperCase()
                                                                    .slice(
                                                                        0,
                                                                        3,
                                                                    ),
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder=""
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Phone Code">
                                                    <AddressInput
                                                        value={
                                                            form.phone_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'phone_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder=""
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup
                                                    label="Time Zone"
                                                    required
                                                >
                                                    <AddressSelect
                                                        value={
                                                            form.timezone ||
                                                            (selectedId &&
                                                            form.code
                                                                ? detectTimezone(
                                                                      form.code,
                                                                      form.name ||
                                                                          '',
                                                                  )
                                                                : '')
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'timezone',
                                                                val,
                                                            )
                                                        }
                                                        options={
                                                            allTimezoneOptions
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Select Time Zone"
                                                        required
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* PROVINCE FORM */}
                                    {activeSection === 'provinces' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Country / Region">
                                                    {isNew ? (
                                                        <AddressSelect
                                                            value={
                                                                form.country_code ||
                                                                filterCountry ||
                                                                ''
                                                            }
                                                            onChange={(val) => {
                                                                updateFormField(
                                                                    'country_code',
                                                                    val,
                                                                );
                                                                setFilterCountry(
                                                                    val,
                                                                );
                                                                executeFilter({
                                                                    country:
                                                                        val,
                                                                });
                                                            }}
                                                            options={(
                                                                countries ?? []
                                                            ).map((c) => ({
                                                                value: c.code,
                                                                label: `${c.iso3 || c.code} - ${c.name}`,
                                                            }))}
                                                            disabled={
                                                                isFormDisabled
                                                            }
                                                        />
                                                    ) : (
                                                        <AddressInput
                                                            value={
                                                                form.country_code ||
                                                                filterCountry ||
                                                                ''
                                                            }
                                                            disabled
                                                        />
                                                    )}
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State / Province">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="State Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State Code">
                                                    <AddressInput
                                                        value={
                                                            form.code ||
                                                            form.state_code ||
                                                            ''
                                                        }
                                                        onChange={(val) => {
                                                            updateFormField(
                                                                'code',
                                                                val,
                                                            );
                                                            updateFormField(
                                                                'state_code',
                                                                val,
                                                            );
                                                        }}
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="State Code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Description">
                                                    <AddressInput
                                                        value={
                                                            form.description ||
                                                            form.name ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'description',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Description"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup
                                                    label="Time Zone"
                                                    required
                                                >
                                                    <AddressSelect
                                                        value={
                                                            form.timezone ||
                                                            (selectedId
                                                                ? detectTimezone(
                                                                      form.country_code ||
                                                                          filterCountry,
                                                                      form.name ||
                                                                          form.code,
                                                                  )
                                                                : '')
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'timezone',
                                                                val,
                                                            )
                                                        }
                                                        options={
                                                            allTimezoneOptions
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Select Time Zone"
                                                        required
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="IT State Code">
                                                    <AddressInput
                                                        value={
                                                            form.it_state_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'it_state_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Intrastat Code">
                                                    <AddressInput
                                                        value={
                                                            form.intrastat || ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'intrastat',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Union Territory">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : Boolean(
                                                                      form.union_territory,
                                                                  )
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'union_territory',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Default State / Province">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : Boolean(
                                                                      form.default_state,
                                                                  )
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'default_state',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* COUNTY FORM */}
                                    {activeSection === 'regencies' && (
                                        <div className="grid max-w-3xl grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                            <AddressFieldGroup label="Country / Region">
                                                <AddressInput
                                                    value={
                                                        form.country_code ||
                                                        filterCountry ||
                                                        ''
                                                    }
                                                    disabled
                                                />
                                            </AddressFieldGroup>
                                            <AddressFieldGroup label="State / Province">
                                                <AddressInput
                                                    value={
                                                        activeProvinceName || ''
                                                    }
                                                    disabled
                                                />
                                            </AddressFieldGroup>
                                            <AddressFieldGroup label="County">
                                                <AddressInput
                                                    value={form.code || ''}
                                                    onChange={(val) =>
                                                        updateFormField(
                                                            'code',
                                                            val,
                                                        )
                                                    }
                                                    disabled={isFormDisabled}
                                                    placeholder="County Code"
                                                />
                                            </AddressFieldGroup>
                                            <AddressFieldGroup label="Description">
                                                <AddressInput
                                                    value={
                                                        form.name ||
                                                        form.description ||
                                                        ''
                                                    }
                                                    onChange={(val) => {
                                                        updateFormField(
                                                            'name',
                                                            val,
                                                        );
                                                        updateFormField(
                                                            'description',
                                                            val,
                                                        );
                                                    }}
                                                    disabled={isFormDisabled}
                                                    placeholder="Description"
                                                />
                                            </AddressFieldGroup>
                                            <AddressFieldGroup label="IT County Code">
                                                <AddressInput
                                                    value={
                                                        form.it_county_code ||
                                                        ''
                                                    }
                                                    onChange={(val) =>
                                                        updateFormField(
                                                            'it_county_code',
                                                            val,
                                                        )
                                                    }
                                                    disabled={isFormDisabled}
                                                />
                                            </AddressFieldGroup>
                                            <AddressFieldGroup label="ES County Code">
                                                <AddressInput
                                                    value={
                                                        form.es_county_code ||
                                                        ''
                                                    }
                                                    onChange={(val) =>
                                                        updateFormField(
                                                            'es_county_code',
                                                            val,
                                                        )
                                                    }
                                                    disabled={isFormDisabled}
                                                />
                                            </AddressFieldGroup>
                                        </div>
                                    )}

                                    {/* CITY FORM */}
                                    {activeSection === 'cities' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Country / Region">
                                                    <AddressInput
                                                        value={
                                                            form.country_code ||
                                                            filterCountry ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State / Province">
                                                    <AddressInput
                                                        value={
                                                            activeProvinceName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="City">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="City Code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Description">
                                                    <AddressInput
                                                        value={
                                                            form.name ||
                                                            form.description ||
                                                            ''
                                                        }
                                                        onChange={(val) => {
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            );
                                                            updateFormField(
                                                                'description',
                                                                val,
                                                            );
                                                        }}
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Description"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* DISTRICT FORM */}
                                    {activeSection === 'districts' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Country / Region">
                                                    <AddressInput
                                                        value={
                                                            form.country_code ||
                                                            filterCountry ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State / Province">
                                                    <AddressInput
                                                        value={
                                                            activeProvinceName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="County / City">
                                                    <AddressInput
                                                        value={
                                                            activeRegencyName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="District">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="District Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="District Code">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="District Code"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* VILLAGE FORM */}
                                    {activeSection === 'villages' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="District">
                                                    <AddressInput
                                                        value={
                                                            activeDistrictName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Village Code">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Village Code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Village Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Village Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal Code">
                                                    <AddressInput
                                                        value={
                                                            form.postal_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'postal_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Postal Code"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* STREET FORM */}
                                    {activeSection === 'streets' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={
                                                            activeVillageName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Street Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Street Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="RT">
                                                    <AddressInput
                                                        value={form.rt || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'rt',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="RT"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="RW">
                                                    <AddressInput
                                                        value={form.rw || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'rw',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="RW"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal Code">
                                                    <AddressInput
                                                        value={
                                                            form.postal_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'postal_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Postal Code"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* GROUP OF HOUSES */}
                                    {activeSection === 'groupOfHouses' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={
                                                            activeVillageName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Code">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal Code">
                                                    <AddressInput
                                                        value={
                                                            form.postal_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'postal_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Postal Code"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* LAND PLOTS */}
                                    {activeSection === 'landPlots' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={
                                                            activeVillageName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Plot Number">
                                                    <AddressInput
                                                        value={
                                                            form.plot_number ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'plot_number',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Plot Number"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal Code">
                                                    <AddressInput
                                                        value={
                                                            form.postal_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'postal_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Postal Code"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* GROUP OF FLATS */}
                                    {activeSection === 'buildings' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={
                                                            activeVillageName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Building / Flat Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Building Name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Block">
                                                    <AddressInput
                                                        value={form.block || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'block',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Block"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Unit">
                                                    <AddressInput
                                                        value={form.unit || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'unit',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Unit"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Floor">
                                                    <AddressInput
                                                        value={form.floor || ''}
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'floor',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Floor"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal Code">
                                                    <AddressInput
                                                        value={
                                                            form.postal_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'postal_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Postal Code"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}

                                    {/* ZIP / POSTAL CODES */}
                                    {activeSection === 'postalCodes' && (
                                        <div className="max-w-3xl space-y-6">
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Country / Region">
                                                    <AddressInput
                                                        value={
                                                            form.country_code ||
                                                            filterCountry ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State / Province">
                                                    <AddressInput
                                                        value={
                                                            activeProvinceName ||
                                                            ''
                                                        }
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal Code">
                                                    <AddressInput
                                                        value={
                                                            form.postal_code ||
                                                            ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'postal_code',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Postal Code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Area Name">
                                                    <AddressInput
                                                        value={
                                                            form.area_name || ''
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'area_name',
                                                                val,
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                        placeholder="Area Name"
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                            <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={
                                                            !selectedId
                                                                ? false
                                                                : form.active ===
                                                                      '1' ||
                                                                  form.active ===
                                                                      true
                                                        }
                                                        onChange={(val) =>
                                                            updateFormField(
                                                                'active',
                                                                val ? '1' : '0',
                                                            )
                                                        }
                                                        disabled={
                                                            isFormDisabled
                                                        }
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>

            <DeleteConfirmDialog
                open={Boolean(deleteConfirmTarget)}
                onOpenChange={(open) => !open && setDeleteConfirmTarget(null)}
                targetName={deleteConfirmTarget?.name || ''}
                isDeleting={isDeleting}
                onConfirm={executeDelete}
            />

            <ExternalCodesModal
                open={activeModal === 'externalCodes'}
                onOpenChange={(open) => !open && setActiveModal(null)}
                entityLabel={getActiveEntityLabel()}
                entityId={getActiveEntityId()}
                list={externalCodesList}
                isLoading={isLoadingExtCodes}
                onDelete={handleDeleteExternalCode}
                onAdd={handleSaveExternalCode}
            />

            <TranslationsModal
                open={activeModal === 'translations'}
                onOpenChange={(open) => !open && setActiveModal(null)}
                entityLabel={getActiveEntityLabel()}
                defaultName={form.name || form.description || ''}
                list={translationsList}
                isLoading={isLoadingTrans}
                onDelete={handleDeleteTranslation}
                onAdd={handleSaveTranslation}
            />
        </div>
    );
}
