import { Head, router } from '@inertiajs/react';
import {
    ArrowUpDown,
    Check,
    ChevronRight,
    Copy,
    Filter,
    Globe,
    Loader2,
    MapPin,
    MoreVertical,
    RotateCcw,
    Search,
    X,
} from 'lucide-react';
import React, { useEffect, useMemo, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';

import type {
    Country,
    Province,
    Regency,
    District,
    Village,
    Street,
    GroupOfHouse,
    LandPlot,
    Building,
    PostalCode,
    Parameter,
    HierarchyLevel,
    ExternalCode,
    TranslationItem,
    Section,
    Props,
} from './types';
import { TIMEZONE_DROPDOWN_OPTIONS, detectTimezone } from './timezones';
import { AddressFieldGroup, AddressInput, AddressSelect, AddressToggle } from './components/address-controls';
import { AddressSidebar } from './components/address-sidebar';
import { AddressPageHeader, AddressToolbar } from './components/address-toolbar';
import { DeleteConfirmDialog, ExternalCodesModal, TranslationsModal } from './components/address-modals';

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
    hierarchyLevels,
    activeTimezone,
    dropdowns,
    context,
    filters,
    selectedId: initialSelectedId,
    flash,
}: Props) {
    const [activeSection, setActiveSection] = useState<Section>(section || 'countries');

    const [filterCountry, setFilterCountry] = useState<string>(filters?.country || 'IDN');
    const [filterProvince, setFilterProvince] = useState<string>(filters?.province || '');
    const [filterRegency, setFilterRegency] = useState<string>(filters?.regency || '');
    const [filterDistrict, setFilterDistrict] = useState<string>(filters?.district || '');
    const [filterVillage, setFilterVillage] = useState<string>(filters?.village || '');

    // Sync filter states whenever filters prop is updated from server
    useEffect(() => {
        if (filters) {
            if (filters.country && filters.country !== filterCountry) setFilterCountry(filters.country);
            if (filters.province !== undefined && filters.province !== filterProvince) setFilterProvince(filters.province);
            if (filters.regency !== undefined && filters.regency !== filterRegency) setFilterRegency(filters.regency);
            if (filters.district !== undefined && filters.district !== filterDistrict) setFilterDistrict(filters.district);
            if (filters.village !== undefined && filters.village !== filterVillage) setFilterVillage(filters.village);
        }
    }, [filters]);

    const [selectedId, setSelectedId] = useState<string | null>(initialSelectedId || null);
    const [isNew, setIsNew] = useState<boolean>(false);
    const [form, setForm] = useState<Record<string, any>>({});
    const [isSaving, setIsSaving] = useState<boolean>(false);
    const [isDeleting, setIsDeleting] = useState<boolean>(false);
    const [isFiltering, setIsFiltering] = useState<boolean>(false);
    const [searchTerm, setSearchTerm] = useState<string>('');

    const [activeModal, setActiveModal] = useState<'externalCodes' | 'translations' | null>(null);
    const [deleteConfirmTarget, setDeleteConfirmTarget] = useState<{ id: string; name: string } | null>(null);
    const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);
    const [isFilterOpen, setIsFilterOpen] = useState<boolean>(true);

    const handleToggleFilter = () => {
        setIsFilterOpen((prev) => !prev);
    };

    // Dynamically aggregate all timezones across provinces and standards
    const allTimezoneOptions = useMemo(() => {
        const set = new Set(TIMEZONE_DROPDOWN_OPTIONS.map((o) => o.value));
        (provinces ?? []).forEach((p: any) => {
            if (p.timezone) set.add(p.timezone);
        });
        if (form.timezone) set.add(form.timezone);
        return Array.from(set).sort().map((tz) => ({ value: tz, label: tz }));
    }, [provinces, form.timezone]);

    // Resolved parent names for read-only hierarchy display in right-side form
    const activeProvinceName = useMemo(() => {
        const provId = form.province_id || filterProvince;
        if (!provId) return context?.province?.name || '';
        const found = ((dropdowns?.provinces ?? provinces) ?? []).find((p: any) => p.id === provId || p.code === provId);
        return found?.name || form.province?.name || (context?.province?.name) || '';
    }, [form.province_id, filterProvince, dropdowns?.provinces, provinces, form.province, context?.province]);

    const activeRegencyName = useMemo(() => {
        const regId = form.regency_id || filterRegency;
        if (!regId) return context?.regency?.name || '';
        const found = ((dropdowns?.regencies ?? regencies) ?? []).find((r: any) => r.id === regId || r.code === regId);
        return found?.name || form.regency?.name || (context?.regency?.name) || '';
    }, [form.regency_id, filterRegency, dropdowns?.regencies, regencies, form.regency, context?.regency]);

    const activeDistrictName = useMemo(() => {
        const distId = form.district_id || filterDistrict;
        if (!distId) return context?.district?.name || '';
        const found = ((dropdowns?.districts ?? districts) ?? []).find((d: any) => d.id === distId || d.code === distId);
        return found?.name || form.district?.name || (context?.district?.name) || '';
    }, [form.district_id, filterDistrict, dropdowns?.districts, districts, form.district, context?.district]);

    const activeVillageName = useMemo(() => {
        const villId = form.village_id || filterVillage;
        if (!villId) return '';
        const found = ((dropdowns?.villages ?? villages) ?? []).find((v: any) => v.id === villId || v.code === villId);
        return found?.name || form.village?.name || '';
    }, [form.village_id, filterVillage, dropdowns?.villages, villages, form.village]);

    // External Codes State
    const [externalCodesList, setExternalCodesList] = useState<ExternalCode[]>([]);
    const [isLoadingExtCodes, setIsLoadingExtCodes] = useState<boolean>(false);

    // Translations State
    const [translationsList, setTranslationsList] = useState<TranslationItem[]>([]);
    const [isLoadingTrans, setIsLoadingTrans] = useState<boolean>(false);

    // Fetch External Codes (Available on all sections)
    const openExternalCodesModal = async () => {
        const divId = form.code || form.id || selectedId || filterCountry;
        if (!divId) {
            showToast('Please select a record first.', 'error');
            return;
        }
        setActiveModal('externalCodes');
        setIsLoadingExtCodes(true);
        try {
            const res = await fetch(`/settings/address-setup/external-codes?division_id=${encodeURIComponent(divId)}`);
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

    const handleSaveExternalCode = async (system: string, code: string, desc: string) => {
        const divId = form.code || form.id || selectedId;
        try {
            const res = await fetch('/settings/address-setup/external-codes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content || '',
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
                    setExternalCodesList((prev) => [...prev.filter((x) => x.id !== result.data.id), result.data]);
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
            const res = await fetch(`/settings/address-setup/external-codes/${id}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content || '',
                },
            });
            if (res.ok) {
                setExternalCodesList((prev) => prev.filter((x) => x.id !== id));
                showToast('External code mapping deleted.', 'success');
            }
        } catch {
            showToast('Failed to delete external code.', 'error');
        }
    };

    // Fetch Translations
    const openTranslationsModal = async () => {
        const divId = form.code || form.id || selectedId || filterCountry;
        if (!divId) {
            showToast('Please select a record first.', 'error');
            return;
        }
        setActiveModal('translations');
        setIsLoadingTrans(true);
        try {
            const res = await fetch(`/settings/address-setup/translations?division_id=${encodeURIComponent(divId)}`);
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

    const handleSaveTranslation = async (locale: string, name: string, desc: string) => {
        const divId = form.code || form.id || selectedId;
        try {
            const res = await fetch('/settings/address-setup/translations', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content || '',
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
                    setTranslationsList((prev) => [...prev.filter((x) => x.id !== result.data.id), result.data]);
                }
                showToast('Translation saved.', 'success');
            } else {
                showToast('Failed to save translation.', 'error');
            }
        } catch {
            showToast('Network error while saving translation.', 'error');
        }
    };

    // Delete Translation
    const handleDeleteTranslation = async (id: string) => {
        try {
            const res = await fetch(`/settings/address-setup/translations/${id}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content || '',
                },
            });
            if (res.ok) {
                setTranslationsList((prev) => prev.filter((x) => x.id !== id));
                showToast('Translation deleted.', 'success');
            }
        } catch {
            showToast('Failed to delete translation.', 'error');
        }
    };

    // Address parameter configuration
    const [paramState, setParamState] = useState<Parameter>(
        parameters?.find((p) => p.country_code === filterCountry) || {
            country_code: filterCountry,
            use_province: true,
            use_regency: true,
            use_district: true,
            use_village: true,
            use_rt_rw: true,
            use_postal_code: true,
            use_building: true,
            address_format: '{street}, {village}, {district}, {regency}, {province} {postal_code}, {country}',
        }
    );

    // Show toast helper
    const showToast = (text: string, type: 'success' | 'error' = 'success') => {
        setToastMessage({ text, type });
        setTimeout(() => setToastMessage(null), 3500);
    };

    // Update active section when props change
    useEffect(() => {
        if (section) setActiveSection(section);
    }, [section]);

    // Update filters when props change
    useEffect(() => {
        if (filters) {
            setFilterCountry(filters.country || 'IDN');
            setFilterProvince(filters.province || '');
            setFilterRegency(filters.regency || '');
            setFilterDistrict(filters.district || '');
            setFilterVillage(filters.village || '');
        }
    }, [filters]);

    // Compute active dataset for grid view
    const currentDataset = useMemo(() => {
        switch (activeSection) {
            case 'countries': return countries ?? [];
            case 'provinces': return provinces ?? [];
            case 'regencies': {
                // County: Kabupaten, regional counties, and non-city divisions
                return (regencies ?? []).filter((r: any) => {
                    const t = (r.type || '').toLowerCase();
                    return t === 'kabupaten' || t === 'daerah' || t === 'county' || t === 'khayaing' || t === 'amphoe' || t === 'postu' || t === 'muang' || t === 'srok' || t === 'jajahan' || (!['kota', 'city', 'capital_city', 'thanh_pho', 'planning_area', 'huc', 'municipality', 'town', 'khet', 'krong', 'quan', 'special_ward'].includes(t) && !r.name.toLowerCase().startsWith('kota '));
                });
            }
            case 'cities': {
                // City: Kota, municipalities, and urban cities
                return (regencies ?? []).filter((r: any) => {
                    const t = (r.type || '').toLowerCase();
                    return t === 'kota' || t === 'city' || t === 'capital_city' || t === 'thanh_pho' || t === 'planning_area' || t === 'huc' || t === 'municipality' || t === 'town' || t === 'khet' || t === 'krong' || t === 'quan' || t === 'special_ward' || r.name.toLowerCase().startsWith('kota ');
                });
            }
            case 'districts': return districts ?? [];
            case 'villages': return villages ?? [];
            case 'streets': return streets ?? [];
            case 'groupOfHouses': return groupOfHouses ?? [];
            case 'landPlots': return landPlots ?? [];
            case 'buildings': return buildings ?? [];
            case 'postalCodes': return postalCodes ?? [];
            default: return [];
        }
    }, [activeSection, countries, provinces, regencies, districts, villages, streets, groupOfHouses, landPlots, buildings, postalCodes]);

    // Filter dataset by search term
    const filteredDataset = useMemo(() => {
        if (!searchTerm.trim()) return currentDataset;
        const q = searchTerm.toLowerCase().trim();
        return currentDataset.filter((item: any) => {
            const name = (item.name || '').toLowerCase();
            const code = (item.code || item.postal_code || item.plot_number || '').toLowerCase();
            const desc = (item.description || item.area_name || item.iso3 || '').toLowerCase();
            return name.includes(q) || code.includes(q) || desc.includes(q);
        });
    }, [currentDataset, searchTerm]);

    // Select first record if none selected OR if currently selected record does not belong to filteredDataset
    useEffect(() => {
        if (isNew) return;

        if (filteredDataset.length > 0) {
            const currentItem = selectedId
                ? (filteredDataset.find((item: any) => (item.id === selectedId || item.code === selectedId)) as any)
                : null;

            if (currentItem) {
                const itemId = currentItem.id || currentItem.code;
                if (selectedId !== itemId) {
                    setSelectedId(itemId);
                }
                setForm((prev) => {
                    if ((prev.id === currentItem.id || prev.code === currentItem.code) && prev.name === currentItem.name && prev.province_id === currentItem.province_id) {
                        return prev;
                    }
                    return { ...currentItem };
                });
            } else {
                // Out of sync (e.g. user selected another state/country in the filter)!
                // Immediately select the first item of this new territory!
                const first = filteredDataset[0] as any;
                const newId = first.id || first.code;
                setSelectedId(newId);
                setForm({ ...first });
            }
        } else {
            setSelectedId(null);
            setForm({
                country_code: filterCountry,
                province_id: filterProvince,
                regency_id: filterRegency,
                district_id: filterDistrict,
                village_id: filterVillage,
                active: true,
            });
        }
    }, [filteredDataset, selectedId, isNew, filterCountry, filterProvince, filterRegency, filterDistrict, filterVillage]);

    // Execute server filter reload with strict section-scoping
    const executeFilter = (overrides: Record<string, any> = {}) => {
        const targetSection = (overrides.section ?? activeSection) as Section;

        const targetCountry = overrides.country !== undefined ? overrides.country : filterCountry;
        let targetProvince = overrides.province_id !== undefined ? overrides.province_id : filterProvince;
        let targetRegency = overrides.regency_id !== undefined ? overrides.regency_id : filterRegency;
        let targetDistrict = overrides.district_id !== undefined ? overrides.district_id : filterDistrict;
        let targetVillage = overrides.village_id !== undefined ? overrides.village_id : filterVillage;

        // Strip child parameters that do not belong to the target section
        if (targetSection === 'countries' || targetSection === 'provinces') {
            targetProvince = '';
            targetRegency = '';
            targetDistrict = '';
            targetVillage = '';
        } else if (targetSection === 'regencies' || targetSection === 'cities') {
            targetRegency = '';
            targetDistrict = '';
            targetVillage = '';
        } else if (targetSection === 'districts') {
            targetDistrict = '';
            targetVillage = '';
        } else if (targetSection === 'villages') {
            targetVillage = '';
        } else if (targetSection === 'postalCodes') {
            targetRegency = '';
            targetDistrict = '';
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

    // Form field updater
    const updateFormField = (field: string, val: any) => {
        setForm((prev) => ({ ...prev, [field]: val }));
    };

    // Page header title
    const pageHeaderTitle = useMemo(() => {
        switch (activeSection) {
            case 'parameters': return 'Enter parameter information for address setup';
            case 'addressFormat': return 'Enter address format information for address setup';
            case 'countries': return 'Enter country/region information for address setup';
            case 'provinces': return 'Enter state/province information for address setup';
            case 'regencies': return 'Enter county information for address setup';
            case 'cities': return 'Enter city information for address setup';
            case 'districts': return 'Enter district information for address setup';
            case 'streets': return 'Enter street information for address setup';
            case 'groupOfHouses': return 'Enter group of houses information for address setup';
            case 'landPlots': return 'Enter land plot information for address setup';
            case 'buildings': return 'Enter group of flats information for address setup';
            case 'postalCodes': return 'Enter ZIP/postal code information for address setup';
            default: return 'Enter address information for address setup';
        }
    }, [activeSection]);

    // Handle New Record
    const handleNew = () => {
        if (activeSection === 'parameters' || activeSection === 'addressFormat') {
            setParamState({
                country_code: filterCountry,
                use_province: true,
                use_regency: true,
                use_district: true,
                use_village: true,
                use_rt_rw: true,
                use_postal_code: true,
                use_building: true,
                address_format: '{street}, {village}, {district}, {regency}, {province} {postal_code}, {country}',
            });
            showToast('Form reset to default configuration.', 'success');
            return;
        }
        setIsNew(true);
        setSelectedId(null);
        const blank: Record<string, any> = { active: true };
        blank.country_code = filterCountry;

        const firstItem = (filteredDataset[0] || {}) as any;
        const activeProvId = filterProvince || firstItem.province_id || (provinces?.[0]?.id ?? '');
        const activeRegId = filterRegency || firstItem.regency_id || (regencies?.[0]?.id ?? '');
        const activeDistId = filterDistrict || firstItem.district_id || (districts?.[0]?.id ?? '');
        const activeVillId = filterVillage || firstItem.village_id || (villages?.[0]?.id ?? '');

        if (activeSection === 'provinces') {
            blank.country_code = filterCountry;
            blank.default_state = false;
            blank.union_territory = false;
            blank.timezone = detectTimezone(filterCountry, '');
        } else if (activeSection === 'regencies') {
            blank.country_code = filterCountry;
            blank.province_id = activeProvId;
            blank.type = 'kabupaten';
        } else if (activeSection === 'cities') {
            blank.country_code = filterCountry;
            blank.province_id = activeProvId;
            blank.type = 'kota';
        } else if (activeSection === 'districts') {
            blank.country_code = filterCountry;
            blank.province_id = activeProvId;
            blank.regency_id = activeRegId;
        } else if (['villages', 'streets', 'groupOfHouses', 'landPlots', 'buildings'].includes(activeSection)) {
            blank.country_code = filterCountry;
            blank.province_id = activeProvId;
            blank.regency_id = activeRegId;
            blank.district_id = activeDistId;
            blank.village_id = activeVillId;
        } else if (activeSection === 'postalCodes') {
            blank.country_code = filterCountry;
            blank.province_id = activeProvId;
            blank.regency_id = activeRegId;
            blank.district_id = activeDistId;
        }
        setForm(blank);
    };

    // Handle Delete Record
    const handleDelete = () => {
        if (activeSection === 'parameters' || activeSection === 'addressFormat') {
            showToast('System configuration parameters cannot be deleted.', 'error');
            return;
        }
        if (!selectedId) return;
        const record = currentDataset.find((r: any) => (r.id === selectedId || r.code === selectedId)) as any;
        setDeleteConfirmTarget({ id: selectedId, name: record?.name || record?.code || selectedId });
    };

    const executeDelete = () => {
        if (!deleteConfirmTarget) return;
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
        if (!targetUrl) return;

        router.delete(targetUrl, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsDeleting(false);
                setDeleteConfirmTarget(null);
                setSelectedId(null);
                showToast('Record successfully deleted.', 'success');
            },
            onError: (errs) => {
                setIsDeleting(false);
                const firstErr = Object.values(errs)[0] as string;
                showToast(firstErr || 'Failed to delete record.', 'error');
            },
        });
    };

    // Handle Save Record
    const handleSave = () => {
        setIsSaving(true);

        const storeRouteMap: Record<Section, string> = {
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

        if (activeSection === 'parameters' || activeSection === 'addressFormat') {
            payload = {
                ...paramState,
                country_code: filterCountry,
            };
        }

        if (activeSection === 'provinces') {
            if (!payload.code && payload.state_code) payload.code = payload.state_code;
            if (!payload.state_code && payload.code) payload.state_code = payload.code;
            if (!payload.name && payload.description) payload.name = payload.description;
            if (!payload.description && payload.name) payload.description = payload.name;
        }

        if (!isNew && selectedId) {
            if (activeSection === 'countries') {
                payload.code = form.code || selectedId;
            } else {
                payload.id = form.id || selectedId;
            }
        }

        // Guarantee parent references if creating new
        if (isNew) {
            if (!payload.country_code && filterCountry) payload.country_code = filterCountry;
            if (!payload.province_id && filterProvince) payload.province_id = filterProvince;
            if (!payload.regency_id && filterRegency) payload.regency_id = filterRegency;
            if (!payload.district_id && filterDistrict) payload.district_id = filterDistrict;
            if (!payload.village_id && filterVillage) payload.village_id = filterVillage;
        }

        // Guarantee active flag
        if (payload.active === undefined) payload.active = '1';

        // Guarantee type for regencies / cities
        if (activeSection === 'regencies' && !payload.type) {
            payload.type = 'kabupaten';
        } else if (activeSection === 'cities' && !payload.type) {
            payload.type = 'kota';
        }

        router.post(targetUrl, payload, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsSaving(false);
                setIsNew(false);
                showToast('Record successfully saved.', 'success');
            },
            onError: (errs) => {
                setIsSaving(false);
                const firstErr = Object.values(errs)[0] as string;
                showToast(firstErr || 'Failed to save record.', 'error');
            },
        });
    };

    // Grid column definitions
    const gridColumns = useMemo(() => {
        switch (activeSection) {
            case 'countries': return { col1: 'Country/region', col2: 'Short name' };
            case 'provinces': return { col1: 'State', col2: 'Description' };
            case 'regencies': return { col1: 'County', col2: 'Description' };
            case 'cities': return { col1: 'City', col2: 'Description' };
            case 'districts': return { col1: 'District', col2: 'Description' };
            case 'villages': return { col1: 'Village', col2: 'Description' };
            case 'streets': return { col1: 'Street', col2: 'Description' };
            case 'groupOfHouses': return { col1: 'Group of houses', col2: 'Description' };
            case 'landPlots': return { col1: 'Plot number', col2: 'Name' };
            case 'buildings': return { col1: 'Group of flats', col2: 'Block / Unit' };
            case 'postalCodes': return { col1: 'Postal code', col2: 'Area name' };
            default: return { col1: 'Code', col2: 'Description' };
        }
    }, [activeSection]);

    return (
        <div className="flex h-screen w-full min-w-0 max-w-full overflow-hidden bg-white text-[#242424] font-sans text-xs antialiased">
            <Head title="Address Setup" />

            {/* Toast notification */}
            {toastMessage && (
                <div className={`fixed top-4 right-4 z-50 px-3.5 py-2 rounded-[2px] shadow-md text-xs flex items-center gap-2 border transition-all ${
                    toastMessage.type === 'error' ? 'bg-red-50 text-red-800 border-red-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200'
                }`}>
                    {toastMessage.type === 'error' ? <X size={13} /> : <Check size={13} />}
                    <span>{toastMessage.text}</span>
                </div>
            )}

            {/* 1. LEFT SIDEBAR (ENGLISH TAB BAR) */}
            <AddressSidebar
                activeSection={activeSection}
                onSelectSection={(sec) => {
                    setActiveSection(sec);
                    setSelectedId(null);
                    setIsNew(false);
                    setForm({});
                    setSearchTerm('');
                    if (sec === 'countries' || sec === 'provinces') {
                        setFilterProvince('');
                        setFilterRegency('');
                        setFilterDistrict('');
                        setFilterVillage('');
                    } else if (sec === 'regencies' || sec === 'cities') {
                        setFilterRegency('');
                        setFilterDistrict('');
                        setFilterVillage('');
                    } else if (sec === 'districts') {
                        setFilterDistrict('');
                        setFilterVillage('');
                    } else if (sec === 'villages') {
                        setFilterVillage('');
                    } else if (sec === 'postalCodes') {
                        setFilterRegency('');
                        setFilterDistrict('');
                        setFilterVillage('');
                    }
                    executeFilter({ section: sec });
                }}
            />

            {/* 2. MAIN CONTENT AREA */}
            <div className="flex-1 min-w-0 flex flex-col bg-white overflow-hidden">
                {/* PAGE HEADER */}
                <AddressPageHeader title={pageHeaderTitle} />

                {/* TOOLBAR (Screenshot 2: identical 6 buttons applied to all sidebar items) */}
                <AddressToolbar
                    onNew={handleNew}
                    onDelete={handleDelete}
                    onSave={handleSave}
                    onExternalCodes={openExternalCodesModal}
                    onTranslations={openTranslationsModal}
                    onFilterToggle={handleToggleFilter}
                    searchTerm={searchTerm}
                    onSearchChange={setSearchTerm}
                    isNew={isNew}
                    isSaving={isSaving}
                    isDeleting={isDeleting}
                    canDelete={Boolean(selectedId) || isNew || activeSection === 'parameters' || activeSection === 'addressFormat'}
                    showTranslations={true}
                    showNew={true}
                    showDelete={true}
                    showSave={true}
                    showSearch={false}
                />

                {/* FILTER BAR (Below Toolbar for hierarchy entities) */}
                {isFilterOpen && !['parameters', 'addressFormat', 'countries'].includes(activeSection) && (
                    <div className="px-4 py-2 border-b border-[#edebe9] flex items-end gap-3 shrink-0 bg-white flex-wrap">
                        {/* Country filter */}
                        <AddressFieldGroup label="Country/region" className="w-[140px]">
                            <AddressSelect
                                value={filterCountry}
                                onChange={(val) => {
                                    setFilterCountry(val);
                                    setFilterProvince('');
                                    setFilterRegency('');
                                    setFilterDistrict('');
                                    setFilterVillage('');
                                }}
                                options={(countries ?? []).map((c) => ({ value: c.code, label: `${c.iso3 || c.code} - ${c.name}` }))}
                            />
                        </AddressFieldGroup>

                        {/* State/Province filter */}
                        {!['provinces'].includes(activeSection) && (
                            <AddressFieldGroup label="State/province" className="w-[150px]">
                                <AddressSelect
                                    value={filterProvince}
                                    onChange={(val) => {
                                        setFilterProvince(val);
                                        setFilterRegency('');
                                        setFilterDistrict('');
                                        setFilterVillage('');
                                    }}
                                    options={[
                                        { value: '', label: '— All States —' },
                                        ...((dropdowns?.provinces ?? provinces) ?? [])
                                            .filter((p) => !filterCountry || p.country_code === filterCountry)
                                            .map((p) => ({ value: p.id, label: p.name })),
                                    ]}
                                />
                            </AddressFieldGroup>
                        )}

                        {/* County filter */}
                        {!['provinces', 'regencies', 'cities'].includes(activeSection) && (
                            <AddressFieldGroup label="County" className="w-[150px]">
                                <AddressSelect
                                    value={filterRegency}
                                    onChange={(val) => {
                                        setFilterRegency(val);
                                        setFilterDistrict('');
                                        setFilterVillage('');
                                    }}
                                    options={[
                                        { value: '', label: '— All Counties —' },
                                        ...((dropdowns?.regencies ?? regencies) ?? [])
                                            .filter((r) => !filterProvince || r.province_id === filterProvince)
                                            .map((r) => ({ value: r.id, label: r.name })),
                                    ]}
                                />
                            </AddressFieldGroup>
                        )}

                        {/* District filter */}
                        {['villages', 'streets', 'groupOfHouses', 'landPlots', 'buildings'].includes(activeSection) && (
                            <AddressFieldGroup label="District" className="w-[140px]">
                                <AddressSelect
                                    value={filterDistrict}
                                    onChange={(val) => {
                                        setFilterDistrict(val);
                                        setFilterVillage('');
                                    }}
                                    options={[
                                        { value: '', label: '— All Districts —' },
                                        ...((dropdowns?.districts ?? districts) ?? [])
                                            .filter((d) => !filterRegency || d.regency_id === filterRegency)
                                            .map((d) => ({ value: d.id, label: d.name })),
                                    ]}
                                />
                            </AddressFieldGroup>
                        )}

                        {/* Village filter */}
                        {['streets', 'groupOfHouses', 'landPlots', 'buildings'].includes(activeSection) && (
                            <AddressFieldGroup label="Village" className="w-[140px]">
                                <AddressSelect
                                    value={filterVillage}
                                    onChange={(val) => setFilterVillage(val)}
                                    options={[
                                        { value: '', label: '— All Villages —' },
                                        ...((dropdowns?.villages ?? villages) ?? [])
                                            .filter((v) => !filterDistrict || v.district_id === filterDistrict)
                                            .map((v) => ({ value: v.id, label: v.name })),
                                    ]}
                                />
                            </AddressFieldGroup>
                        )}

                        {/* Apply Filter & Reset Buttons */}
                        <div className="flex items-center gap-2 self-end">
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                disabled={isFiltering}
                                onClick={() => {
                                    setSelectedId(null);
                                    setIsNew(false);
                                    executeFilter();
                                }}
                                className="h-9 px-3 text-xs bg-[#0078d4] text-white hover:bg-[#106ebe] font-medium border-0 cursor-pointer shadow-xs flex items-center gap-1.5 disabled:opacity-50"
                            >
                                {isFiltering ? (
                                    <>
                                        <Loader2 size={13} className="animate-spin" />
                                        <span>Applying...</span>
                                    </>
                                ) : (
                                    <>
                                        <Filter size={13} />
                                        <span>Apply filter</span>
                                    </>
                                )}
                            </Button>

                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                disabled={isFiltering}
                                onClick={() => {
                                    setFilterProvince('');
                                    setFilterRegency('');
                                    setFilterDistrict('');
                                    setFilterVillage('');
                                    setSelectedId(null);
                                    setIsNew(false);
                                    executeFilter({
                                        province_id: '',
                                        regency_id: '',
                                        district_id: '',
                                        village_id: '',
                                    });
                                }}
                                className="h-9 px-2.5 text-xs bg-white border border-[#d9dfe7] text-[#605e5c] hover:text-[#1f2937] hover:bg-[#f3f4f6] cursor-pointer shadow-xs"
                                title="Reset filters to default"
                            >
                                <RotateCcw size={13} />
                            </Button>
                        </div>
                    </div>
                )}

                {/* CONTENT PANELS */}
                <div className="flex-1 min-h-0 flex overflow-hidden">
                    {/* SECTION: PARAMETERS */}
                    {activeSection === 'parameters' && (
                        <div className="flex-1 overflow-y-auto p-4 space-y-6 max-w-4xl">
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                                <AddressFieldGroup label="Country/region">
                                    <AddressSelect
                                        value={filterCountry}
                                        onChange={(val) => {
                                            setFilterCountry(val);
                                            executeFilter({ country: val });
                                        }}
                                        options={(countries ?? []).map((c) => ({ value: c.code, label: `${c.iso3 || c.code} - ${c.name}` }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use state/province">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_province)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_province: val }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use county">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_regency)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_regency: val }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use district">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_district)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_district: val }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use village / sub-district">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_village)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_village: val }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use street / RT-RW">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_rt_rw)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_rt_rw: val }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use ZIP / postal code">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_postal_code)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_postal_code: val }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Use building / flats">
                                    <AddressToggle
                                        checked={Boolean(paramState.use_building)}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, use_building: val }))}
                                    />
                                </AddressFieldGroup>
                            </div>
                        </div>
                    )}

                    {/* SECTION: ADDRESS FORMAT */}
                    {activeSection === 'addressFormat' && (
                        <div className="flex-1 overflow-y-auto p-4 space-y-6 max-w-4xl">
                            <div className="space-y-4">
                                <AddressFieldGroup label="Country/region">
                                    <AddressSelect
                                        value={filterCountry}
                                        onChange={(val) => {
                                            setFilterCountry(val);
                                            executeFilter({ country: val });
                                        }}
                                        options={(countries ?? []).map((c) => ({ value: c.code, label: `${c.iso3 || c.code} - ${c.name}` }))}
                                    />
                                </AddressFieldGroup>

                                <AddressFieldGroup label="Address format template">
                                    <AddressInput
                                        value={paramState.address_format || '{street}, RT {rt} / RW {rw}, {village}, {district}, {regency}, {province} {postal_code}, {country}'}
                                        onChange={(val) => setParamState((prev) => ({ ...prev, address_format: val }))}
                                        placeholder="{street}, RT {rt} / RW {rw}, {village}, {district}, {regency}, {province} {postal_code}, {country}"
                                    />
                                    <span className="text-[11px] text-[#605e5c]">
                                        Available tags: {'{street}'}, {'{rt}'}, {'{rw}'}, {'{village}'}, {'{district}'}, {'{regency}'}, {'{province}'}, {'{postal_code}'}, {'{country}'}, {'{building}'}, {'{unit}'}, {'{floor}'}
                                    </span>
                                </AddressFieldGroup>
                            </div>
                        </div>
                    )}

                    {/* TWO-PANEL DATA GRID & FLAT DETAIL FORM (For Master Data Sections) */}
                    {!['parameters', 'addressFormat'].includes(activeSection) && (
                        <>
                            {/* LEFT DATA GRID */}
                            <div className="w-[320px] shrink-0 border-r border-[#edebe9] flex flex-col bg-white overflow-hidden">
                                {/* Search input directly at the top of the list for Country/region ("di tempat milih") */}
                                {activeSection === 'countries' && (
                                    <div className="p-1.5 border-b border-[#edebe9] bg-[#faf9f8] shrink-0">
                                        <div className="relative flex items-center">
                                            <Search size={12} className="absolute left-2 text-[#8a8886] pointer-events-none" />
                                            <input
                                                type="text"
                                                value={searchTerm}
                                                onChange={(e) => setSearchTerm(e.target.value)}
                                                placeholder="Search in Country/region..."
                                                className="w-full h-[24px] pl-6 pr-6 text-[11.5px] bg-white border border-[#8a8886] rounded-[2px] text-[#242424] placeholder-[#8a8886] focus:outline-none focus:border-[#0078d4]"
                                            />
                                            {searchTerm && (
                                                <button
                                                    type="button"
                                                    onClick={() => setSearchTerm('')}
                                                    className="absolute right-1.5 text-[#8a8886] hover:text-[#242424] cursor-pointer"
                                                    title="Clear search"
                                                >
                                                    <X size={11} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div className="h-[28px] border-b border-[#edebe9] bg-[#f8f9fa] flex items-center px-3 text-[11.5px] font-semibold text-[#605e5c] select-none shrink-0">
                                    <div className="w-2/5 flex items-center gap-1 truncate">
                                        <span>{gridColumns.col1}</span>
                                        <ArrowUpDown size={11} className="text-[#8a8886]" />
                                    </div>
                                    <div className="w-3/5 truncate">{gridColumns.col2}</div>
                                </div>

                                <div className="flex-1 overflow-y-auto divide-y divide-[#f3f2f1]">
                                    {filteredDataset.length === 0 ? (
                                        <div className="p-4 text-center text-[#8a8886] text-xs">
                                            No records found.
                                        </div>
                                    ) : (
                                        filteredDataset.map((item: any) => {
                                            const itemId = item.id || item.code;
                                            const isSelected = selectedId === itemId && !isNew;
                                            return (
                                                <button
                                                    key={itemId}
                                                    type="button"
                                                    onClick={() => {
                                                        setSelectedId(itemId);
                                                        setIsNew(false);
                                                        setForm({ ...item });
                                                    }}
                                                    className={`w-full text-left px-3 py-1.5 flex items-center text-[12px] transition-colors cursor-pointer ${
                                                        isSelected
                                                            ? 'bg-[#dbe8f9] text-[#242424] font-medium'
                                                            : 'hover:bg-[#f3f7fd] text-[#242424]'
                                                    }`}
                                                >
                                                    {(() => {
                                                        const isPostalSection = activeSection === 'postalCodes';
                                                        let col1Val = '';
                                                        let col2Val = '';

                                                        if (activeSection === 'countries') {
                                                            col1Val = item.iso3 || item.code;
                                                            col2Val = item.name || '—';
                                                        } else if (activeSection === 'provinces') {
                                                            col1Val = item.name || item.code;
                                                            col2Val = item.description || item.name || '—';
                                                        } else if (activeSection === 'regencies' || activeSection === 'cities') {
                                                            col1Val = item.code || item.display_code || item.name;
                                                            col2Val = item.name || item.description || '—';
                                                        } else if (activeSection === 'districts' || activeSection === 'villages') {
                                                            col1Val = item.code || item.display_code || item.name;
                                                            col2Val = item.name || '—';
                                                        } else if (activeSection === 'streets') {
                                                            col1Val = item.name || 'Street';
                                                            col2Val = (item.rt || item.rw) ? `RT ${item.rt || '-'}/RW ${item.rw || '-'}` : (item.name || '—');
                                                        } else if (activeSection === 'groupOfHouses') {
                                                            col1Val = item.name || item.code || '—';
                                                            col2Val = item.description || item.status || '—';
                                                        } else if (activeSection === 'landPlots') {
                                                            col1Val = item.plot_number || item.code || '—';
                                                            col2Val = item.name || '—';
                                                        } else if (activeSection === 'buildings') {
                                                            col1Val = item.name || item.building_name || '—';
                                                            col2Val = item.unit ? `Unit ${item.unit}` : (item.block || '—');
                                                        } else if (isPostalSection) {
                                                            col1Val = item.postal_code || item.code || '—';
                                                            col2Val = item.area_name || item.name || '—';
                                                        } else {
                                                            col1Val = item.display_code || item.code || item.name || item.id;
                                                            col2Val = item.name || item.description || '—';
                                                        }

                                                        return (
                                                            <>
                                                                <div className="w-2/5 truncate font-mono text-xs">
                                                                    {col1Val}
                                                                </div>
                                                                <div className="w-3/5 truncate text-[#605e5c] text-xs">
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
                            <div className="flex-1 min-w-0 overflow-y-auto p-4 bg-white">
                                {selectedId || isNew ? (
                                    <div className="space-y-4 max-w-5xl">
                                        {/* COUNTRY FORM */}
                                        {activeSection === 'countries' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Country/region">
                                                    <AddressInput
                                                        value={form.code || form.iso3 || ''}
                                                        onChange={(val) => {
                                                            const v = val.toUpperCase().slice(0, 3);
                                                            updateFormField('code', v);
                                                            updateFormField('iso3', v);
                                                        }}
                                                        disabled={!isNew}
                                                        placeholder="IDN"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Description" className="md:col-span-2">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                        placeholder="Indonesia"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="ISO3 code">
                                                    <AddressInput
                                                        value={form.iso3 || form.code || ''}
                                                        onChange={(val) => updateFormField('iso3', val.toUpperCase().slice(0, 3))}
                                                        placeholder="IDN"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Phone code">
                                                    <AddressInput
                                                        value={form.phone_code || ''}
                                                        onChange={(val) => updateFormField('phone_code', val)}
                                                        placeholder="+62"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Time zone">
                                                    <AddressInput
                                                        value={form.timezone || detectTimezone(form.code, form.name)}
                                                        onChange={(val) => updateFormField('timezone', val)}
                                                        placeholder="Asia/Jakarta"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* PROVINCE FORM */}
                                        {activeSection === 'provinces' && (
                                            <div className="space-y-3.5">
                                                <div className="grid grid-cols-2 md:grid-cols-5 gap-x-4 gap-y-3.5 items-end">
                                                    <AddressFieldGroup label="Country/region">
                                                        <AddressInput
                                                            value={form.country_code || filterCountry}
                                                            disabled
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="Description">
                                                        <AddressInput
                                                            value={form.description || form.name || ''}
                                                            onChange={(val) => {
                                                                updateFormField('description', val);
                                                            }}
                                                            placeholder="Description"
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="Intrastat code">
                                                        <AddressInput
                                                            value={form.intrastat || ''}
                                                            onChange={(val) => updateFormField('intrastat', val)}
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="Default state/province">
                                                        <AddressToggle
                                                            checked={Boolean(form.default_state)}
                                                            onChange={(val) => updateFormField('default_state', val)}
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="Union territory">
                                                        <AddressToggle
                                                            checked={Boolean(form.union_territory)}
                                                            onChange={(val) => updateFormField('union_territory', val)}
                                                        />
                                                    </AddressFieldGroup>
                                                </div>

                                                <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                    <AddressFieldGroup label="State">
                                                        <AddressInput
                                                            value={form.name || ''}
                                                            onChange={(val) => {
                                                                updateFormField('name', val);
                                                            }}
                                                            placeholder="State name"
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="Time zone">
                                                        <AddressSelect
                                                            value={form.timezone || detectTimezone(form.country_code || filterCountry, form.name || form.code)}
                                                            onChange={(val) => updateFormField('timezone', val)}
                                                            options={allTimezoneOptions}
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="IT state code">
                                                        <AddressInput
                                                            value={form.it_state_code || ''}
                                                            onChange={(val) => updateFormField('it_state_code', val)}
                                                        />
                                                    </AddressFieldGroup>
                                                    <AddressFieldGroup label="State code">
                                                        <AddressInput
                                                            value={form.code || form.state_code || ''}
                                                            onChange={(val) => {
                                                                updateFormField('code', val);
                                                                updateFormField('state_code', val);
                                                            }}
                                                            placeholder="Code"
                                                        />
                                                    </AddressFieldGroup>
                                                </div>
                                            </div>
                                        )}

                                        {/* COUNTY FORM */}
                                        {activeSection === 'regencies' && (
                                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Country/region">
                                                    <AddressInput
                                                        value={form.country_code || filterCountry}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State">
                                                    <AddressInput
                                                        value={activeProvinceName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="County">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) => updateFormField('code', val)}
                                                        placeholder="County"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Description">
                                                    <AddressInput
                                                        value={form.name || form.description || ''}
                                                        onChange={(val) => {
                                                            updateFormField('name', val);
                                                            updateFormField('description', val);
                                                        }}
                                                        placeholder="Description"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="IT county code">
                                                    <AddressInput
                                                        value={form.it_county_code || ''}
                                                        onChange={(val) => updateFormField('it_county_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="ES county code">
                                                    <AddressInput
                                                        value={form.es_county_code || ''}
                                                        onChange={(val) => updateFormField('es_county_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* CITY FORM */}
                                        {activeSection === 'cities' && (
                                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Country/region">
                                                    <AddressInput
                                                        value={form.country_code || filterCountry}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State/province">
                                                    <AddressInput
                                                        value={activeProvinceName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="City">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) => updateFormField('code', val)}
                                                        placeholder="City code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Description" className="md:col-span-2">
                                                    <AddressInput
                                                        value={form.name || form.description || ''}
                                                        onChange={(val) => {
                                                            updateFormField('name', val);
                                                            updateFormField('description', val);
                                                        }}
                                                        placeholder="Description"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* DISTRICT FORM */}
                                        {activeSection === 'districts' && (
                                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Country/region">
                                                    <AddressInput
                                                        value={form.country_code || filterCountry}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State/province">
                                                    <AddressInput
                                                        value={activeProvinceName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="County">
                                                    <AddressInput
                                                        value={activeRegencyName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="District">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                        placeholder="District name"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="District code">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) => updateFormField('code', val)}
                                                        placeholder="District code"
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* VILLAGE FORM */}
                                        {activeSection === 'villages' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="District">
                                                    <AddressInput
                                                        value={activeDistrictName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Village code">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) => updateFormField('code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Village name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal code">
                                                    <AddressInput
                                                        value={form.postal_code || ''}
                                                        onChange={(val) => updateFormField('postal_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* STREET FORM */}
                                        {activeSection === 'streets' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={activeVillageName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Street name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="RT">
                                                    <AddressInput
                                                        value={form.rt || ''}
                                                        onChange={(val) => updateFormField('rt', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="RW">
                                                    <AddressInput
                                                        value={form.rw || ''}
                                                        onChange={(val) => updateFormField('rw', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal code">
                                                    <AddressInput
                                                        value={form.postal_code || ''}
                                                        onChange={(val) => updateFormField('postal_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* GROUP OF HOUSES */}
                                        {activeSection === 'groupOfHouses' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={activeVillageName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Code">
                                                    <AddressInput
                                                        value={form.code || ''}
                                                        onChange={(val) => updateFormField('code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal code">
                                                    <AddressInput
                                                        value={form.postal_code || ''}
                                                        onChange={(val) => updateFormField('postal_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* LAND PLOTS */}
                                        {activeSection === 'landPlots' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={activeVillageName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Plot number">
                                                    <AddressInput
                                                        value={form.plot_number || ''}
                                                        onChange={(val) => updateFormField('plot_number', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal code">
                                                    <AddressInput
                                                        value={form.postal_code || ''}
                                                        onChange={(val) => updateFormField('postal_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* GROUP OF FLATS */}
                                        {activeSection === 'buildings' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Village">
                                                    <AddressInput
                                                        value={activeVillageName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Building / Flat name">
                                                    <AddressInput
                                                        value={form.name || ''}
                                                        onChange={(val) => updateFormField('name', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Block">
                                                    <AddressInput
                                                        value={form.block || ''}
                                                        onChange={(val) => updateFormField('block', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Unit">
                                                    <AddressInput
                                                        value={form.unit || ''}
                                                        onChange={(val) => updateFormField('unit', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Floor">
                                                    <AddressInput
                                                        value={form.floor || ''}
                                                        onChange={(val) => updateFormField('floor', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal code">
                                                    <AddressInput
                                                        value={form.postal_code || ''}
                                                        onChange={(val) => updateFormField('postal_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}

                                        {/* ZIP / POSTAL CODES */}
                                        {activeSection === 'postalCodes' && (
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3.5 items-end">
                                                <AddressFieldGroup label="Country/region">
                                                    <AddressInput
                                                        value={form.country_code || filterCountry}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Postal code">
                                                    <AddressInput
                                                        value={form.postal_code || ''}
                                                        onChange={(val) => updateFormField('postal_code', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Area name">
                                                    <AddressInput
                                                        value={form.area_name || ''}
                                                        onChange={(val) => updateFormField('area_name', val)}
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="State/province">
                                                    <AddressInput
                                                        value={activeProvinceName}
                                                        disabled
                                                    />
                                                </AddressFieldGroup>
                                                <AddressFieldGroup label="Active">
                                                    <AddressToggle
                                                        checked={form.active === '1' || form.active === true}
                                                        onChange={(val) => updateFormField('active', val ? '1' : '0')}
                                                    />
                                                </AddressFieldGroup>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div className="h-full flex items-center justify-center text-gray-400 text-xs select-none">
                                        Select a record from the list to view or edit details.
                                    </div>
                                )}
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
                entityLabel={form.name || form.code || selectedId || ''}
                entityId={selectedId || form.code || ''}
                list={externalCodesList}
                isLoading={isLoadingExtCodes}
                onDelete={handleDeleteExternalCode}
                onAdd={handleSaveExternalCode}
            />

            <TranslationsModal
                open={activeModal === 'translations'}
                onOpenChange={(open) => !open && setActiveModal(null)}
                entityLabel={form.name || form.code || selectedId || ''}
                defaultName={form.name || ''}
                list={translationsList}
                isLoading={isLoadingTrans}
                onDelete={handleDeleteTranslation}
                onAdd={handleSaveTranslation}
            />
        </div>
    );
}
