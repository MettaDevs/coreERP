import { Head, router } from '@inertiajs/react';
import {
    ArrowUpDown,
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Filter,
    Globe,
    Home,
    Info,
    Languages,
    Layers,
    Loader2,
    Mail,
    MapPin,
    Plus,
    RefreshCw,
    Save,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import React, { useEffect, useMemo, useRef, useState } from 'react';

/* ===== TYPES ===== */
type Country = { code: string; iso3: string | null; name: string; phone_code: string | null; timezone?: string | null; active: boolean };
type Province = { id: string; country_code: string; code: string; display_code?: string | null; name: string; description?: string | null; timezone?: string | null; intrastat?: string | null; it_state_code?: string | null; state_code?: string | null; default_state?: boolean; union_territory?: boolean; active: boolean };
type Regency = { id: string; province_id: string; code: string; display_code?: string | null; name: string; description?: string | null; type: string; it_county_code?: string | null; es_county_code?: string | null; active: boolean };
type District = { id: string; regency_id: string; code: string; display_code?: string | null; name: string; active: boolean };
type Village = { id: string; district_id: string; code: string; display_code?: string | null; name: string; type: string; postal_code: string | null; active: boolean };
type Street = { id: string; village_id: string; rt: string | null; rw: string | null; name: string | null; postal_code?: string | null; override_postal_code?: boolean; active: boolean };
type GroupOfHouse = { id: string; village_id: string; code?: string | null; name: string; postal_code?: string | null; override_postal_code?: boolean; status: string; active: boolean };
type LandPlot = { id: string; village_id: string; street_id?: string | null; group_of_houses_id?: string | null; plot_number: string; name?: string | null; postal_code?: string | null; override_postal_code?: boolean; status: string; active: boolean };
type Building = { id: string; village_id: string; street_id: string | null; name: string; block: string | null; unit: string | null; floor: string | null; postal_code?: string | null; override_postal_code?: boolean; active: boolean };
type PostalCode = {
    id: string;
    country_code: string;
    postal_code: string;
    area_name?: string | null;
    source?: string | null;
    source_reference?: string | null;
    status?: string | null;
    province_id: string | null;
    regency_id: string | null;
    district_id: string | null;
    village_id: string | null;
    active: boolean;
    country?: Country | null;
    province?: Province | null;
    regency?: Regency | null;
    district?: District | null;
    village?: Village | null;
};
type Parameter = { country_code: string; use_province: boolean; use_regency: boolean; use_district: boolean; use_village: boolean; use_rt_rw: boolean; use_postal_code: boolean; use_building: boolean; address_format: string | null };
type HierarchyLevel = { id: string; country_code: string; level: number; level_code: string; level_name: string; description?: string | null };

export interface ResolvedTimezone {
    timezone: string;
    offset: string;
    label: string;
    display_name: string;
    source_division_id: string;
    source_division_type: string;
    source_division_name: string;
}

interface LineageData {
    country?: { code: string; name: string } | null;
    province?: { id: string; code: string; name: string } | null;
    regency?: { id: string; code: string; name: string; type: string } | null;
    district?: { id: string; code: string; name: string } | null;
    village?: { id: string; code: string; name: string; type: string; postal_code: string | null } | null;
    timezone?: ResolvedTimezone | null;
    lineage?: Record<string, string>;
    formatted?: string;
}

type ExternalCode = { id: string; division_id: string; system: string; external_code: string; description?: string | null; status: string };
type TranslationItem = { id: string; division_id: string; locale: string; name: string; description?: string | null };

type Section =
    | 'parameters' | 'addressFormat'
    | 'countries' | 'provinces' | 'regencies' | 'districts' | 'villages'
    | 'streets' | 'groupOfHouses' | 'landPlots' | 'buildings' | 'postalCodes';

interface Props {
    section: Section;
    countries: Country[];
    provinces: Province[];
    regencies: Regency[];
    districts: District[];
    villages: Village[];
    streets: Street[];
    groupOfHouses?: GroupOfHouse[];
    landPlots?: LandPlot[];
    buildings: Building[];
    postalCodes: PostalCode[];
    parameters: Parameter[];
    hierarchyLevels?: HierarchyLevel[];
    activeTimezone?: ResolvedTimezone | null;
    dropdowns?: {
        provinces: Province[];
        regencies: Regency[];
        districts: District[];
        villages: Village[];
    };
    context?: {
        country: { code: string; name: string } | null;
        province: { id: string; name: string; code: string } | null;
        regency: { id: string; name: string; code: string } | null;
        district: { id: string; name: string; code: string } | null;
    };
    filters: { country: string; province: string; regency: string; district: string; village: string };
    selectedId?: string | null;
    flash?: { status?: string; error?: string; saved_id?: string; saved_section?: string };
}

/* Helper to format official Indonesian codes with canonical dot notation for metadata */
function formatOfficialCode(code: string, sec: Section): string {
    if (!code) return '';
    const clean = code.replace(/\./g, '');
    if (sec === 'provinces') return clean;
    if (sec === 'regencies') {
        return clean.length >= 4 ? `${clean.slice(0, 2)}.${clean.slice(2, 4)}` : clean;
    }
    if (sec === 'districts') {
        return clean.length >= 6 ? `${clean.slice(0, 2)}.${clean.slice(2, 4)}.${clean.slice(4, 6)}` : clean;
    }
    if (sec === 'villages') {
        return clean.length >= 10 ? `${clean.slice(0, 2)}.${clean.slice(2, 4)}.${clean.slice(4, 6)}.${clean.slice(6)}` : clean;
    }
    return code;
}

/* Helper to get XSRF-TOKEN cookie for secure fetch operations */
function getXsrfToken(): string {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/* Auto-detect official timezone from country and province official code / name */
function detectTimezone(countryCode: string, codeOrName: string): string {
    if (countryCode === 'MY') {
        return 'Asia/Kuala_Lumpur';
    }
    if (countryCode === 'ID') {
        const raw = (codeOrName || '').trim();
        const upper = raw.toUpperCase();
        const digits = raw.replace(/\D/g, '');

        // WIT (UTC+09:00): Maluku (81), Maluku Utara (82), Papua (91), Papua Barat (92), Papua Selatan (93), Papua Tengah (94), Papua Pegunungan (95), Papua Barat Daya (96)
        if (['81', '82', '91', '92', '93', '94', '95', '96'].includes(digits)
            || upper.includes('PAPUA')
            || upper.includes('MALUKU')) {
            return 'Asia/Jayapura';
        }
        // WITA (UTC+08:00): Bali (51), NTB (52), NTT (53), Kalsel (63), Kaltim (64), Kaltara (65), Sulut (71), Sulteng (72), Sulsel (73), Sultra (74), Gorontalo (75), Sulbar (76)
        if (['51', '52', '53', '63', '64', '65', '71', '72', '73', '74', '75', '76'].includes(digits)
            || upper.includes('BALI')
            || upper.includes('NUSA TENGGARA')
            || upper.includes('NTB')
            || upper.includes('NTT')
            || upper.includes('SULAWESI')
            || upper.includes('GORONTALO')
            || upper.includes('KALIMANTAN SELATAN')
            || upper.includes('KALIMANTAN TIMUR')
            || upper.includes('KALIMANTAN UTARA')) {
            return 'Asia/Makassar';
        }
        // WIB (UTC+07:00): Default for Java, Sumatra, West/Central Kalimantan
        return 'Asia/Jakarta';
    }
    return 'UTC';
}

/* ===== MAIN COMPONENT ===== */
export default function AddressSetupPage({
    section: initialSection = 'countries',
    countries = [],
    provinces = [],
    regencies = [],
    districts = [],
    villages = [],
    streets = [],
    groupOfHouses = [],
    landPlots = [],
    buildings = [],
    postalCodes = [],
    parameters = [],
    hierarchyLevels = [],
    activeTimezone = null,
    dropdowns = { provinces: [], regencies: [], districts: [], villages: [] },
    context = { country: null, province: null, regency: null, district: null },
    filters = { country: 'ID', province: '', regency: '', district: '', village: '' },
    selectedId: initialSelectedId = null,
    flash = {},
}: Props) {
    const [activeSection, setActiveSection] = useState<Section>(initialSection);
    const [selectedId, setSelectedId] = useState<string | null>(initialSelectedId || flash?.saved_id || null);
    const [isNew, setIsNew] = useState(false);
    const [form, setForm] = useState<Record<string, any>>({});
    const [initialForm, setInitialForm] = useState<Record<string, any>>({});
    const [lineageInfo, setLineageInfo] = useState<LineageData | null>(null);
    const [resolvedTz, setResolvedTz] = useState<ResolvedTimezone | null>(activeTimezone || null);
    const [isLoading, setIsLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [sortAsc, setSortAsc] = useState(true);
    const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);
    const [deleteConfirmTarget, setDeleteConfirmTarget] = useState<{ id: string; name: string } | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Modals state
    const [showExternalCodesModal, setShowExternalCodesModal] = useState(false);
    const [showTranslationsModal, setShowTranslationsModal] = useState(false);
    const [externalCodesList, setExternalCodesList] = useState<ExternalCode[]>([]);
    const [translationsList, setTranslationsList] = useState<TranslationItem[]>([]);
    const [isModalLoading, setIsModalLoading] = useState(false);

    // Cascading filter state
    const [filterCountry, setFilterCountry] = useState(filters.country || 'ID');
    const [filterProvince, setFilterProvince] = useState(filters.province || '');
    const [filterRegency, setFilterRegency] = useState(filters.regency || '');
    const [filterDistrict, setFilterDistrict] = useState(filters.district || '');
    const [filterVillage, setFilterVillage] = useState(filters.village || '');

    // Synchronize local filter state when server props update
    useEffect(() => {
        setFilterCountry(filters.country || 'ID');
        setFilterProvince(filters.province || '');
        setFilterRegency(filters.regency || '');
        setFilterDistrict(filters.district || '');
        setFilterVillage(filters.village || '');
    }, [filters.country, filters.province, filters.regency, filters.district, filters.village]);

    // Server-side paginated villages
    const [villagePage, setVillagePage] = useState(1);
    const [paginatedVillages, setPaginatedVillages] = useState<{
        data: Village[];
        current_page: number;
        last_page: number;
        total: number;
    } | null>(null);
    const [isVillageLoading, setIsVillageLoading] = useState(false);

    // Show toast helper
    const showToast = (text: string, type: 'success' | 'error' = 'success') => {
        setToastMessage({ text, type });
        setTimeout(() => setToastMessage(null), 4000);
    };

    /* Dynamic source of truth for active country code */
    const selectedCountryCode = form.country_code || filterCountry || 'ID';

    /* Active country object synchronized with selectedCountryCode */
    const currentActiveCountry = useMemo(() => {
        return (countries ?? []).find(c => c.code === selectedCountryCode) || (countries ?? [])[0] || { code: 'ID', name: 'Indonesia', iso3: 'IDN', phone_code: '+62', timezone: '(UTC+07:00) WIB - Jakarta', active: true };
    }, [countries, selectedCountryCode]);

    /* Indonesian Hierarchy Level Labels */
    const levelLabels: Record<string, string> = useMemo(() => ({
        parameters: 'Parameter',
        addressFormat: 'Format Alamat',
        countries: 'Negara',
        provinces: 'Provinsi',
        regencies: 'Kabupaten/Kota',
        districts: 'Kecamatan',
        villages: 'Desa/Kelurahan',
        streets: 'RT / RW',
        groupOfHouses: 'Group of Houses',
        landPlots: 'Land Plot',
        buildings: 'Gedung / Bangunan',
        postalCodes: 'Master Kode Pos',
        province: 'Provinsi',
        regency: 'Kabupaten/Kota',
        district: 'Kecamatan',
        village: 'Desa/Kelurahan',
        street: 'RT / RW',
        building: 'Gedung / Unit / Lantai',
        postalCode: 'ZIP/postal codes',
    }), []);

    /* Active Parent Village for Auto-Inherited Postal Code */
    const activeParentVillage = useMemo(() => {
        const vId = form.village_id || filterVillage;
        if (!vId) return null;
        return ((dropdowns?.villages ?? villages) ?? []).find(v => v.id === vId) || null;
    }, [form.village_id, filterVillage, dropdowns, villages]);

    /* Sidebar Navigation Items with Fixed Indonesian Labels */
    const navItems: { key: Section; label: string }[] = useMemo(() => [
        { key: 'parameters', label: 'Parameters' },
        { key: 'addressFormat', label: 'Address format' },
        { key: 'countries', label: 'Country/region' },
        { key: 'provinces', label: 'Provinsi' },
        { key: 'regencies', label: 'Kabupaten/Kota' },
        { key: 'districts', label: 'Kecamatan' },
        { key: 'villages', label: 'Desa/Kelurahan' },
        { key: 'streets', label: 'RT / RW' },
        { key: 'groupOfHouses', label: 'Group of houses' },
        { key: 'landPlots', label: 'Land plots' },
        { key: 'buildings', label: 'Gedung / Unit / Lantai' },
        { key: 'postalCodes', label: 'ZIP/postal codes' },
    ], []);

    /* Section Subheading */
    const sectionTitle = useMemo(() => {
        switch (activeSection) {
            case 'parameters': return 'Enter parameter information for address setup';
            case 'addressFormat': return 'Enter address format information for address setup';
            case 'countries': return 'Enter country/region information for address setup';
            case 'provinces': return `Enter ${levelLabels.province.toLowerCase()} information for address setup`;
            case 'regencies': return `Enter ${levelLabels.regency.toLowerCase()} information for address setup`;
            case 'districts': return `Enter ${levelLabels.district.toLowerCase()} information for address setup`;
            case 'villages': return `Enter ${levelLabels.village.toLowerCase()} information for address setup`;
            case 'streets': return `Enter ${levelLabels.street.toLowerCase()} information for address setup`;
            case 'groupOfHouses': return 'Enter group of houses information for address setup';
            case 'landPlots': return 'Enter land plots information for address setup';
            case 'buildings': return `Enter ${levelLabels.building.toLowerCase()} information for address setup`;
            case 'postalCodes': return 'Enter ZIP/postal code information for address setup';
            default: return 'Enter address setup information';
        }
    }, [activeSection, levelLabels]);

    /* Fetch paginated villages when district or search or page changes */
    useEffect(() => {
        if (activeSection === 'villages') {
            setIsVillageLoading(true);
            setPaginatedVillages(null);
            const params = new URLSearchParams({
                country: filterCountry,
                province_id: filterProvince,
                regency_id: filterRegency,
                district_id: filterDistrict,
                search: searchTerm,
                page: String(villagePage),
                per_page: '50',
            });
            fetch(`/settings/address-setup/villages-paginated?${params.toString()}`)
                .then((res) => res.json())
                .then((data) => {
                    setPaginatedVillages(data);
                    setIsVillageLoading(false);
                })
                .catch(() => setIsVillageLoading(false));
        } else {
            setPaginatedVillages(null);
        }
    }, [activeSection, filterCountry, filterProvince, filterRegency, filterDistrict, searchTerm, villagePage]);

    /* Bottom-up & Timezone lookup */
    const fetchLineageAndTimezone = async (id: string, sec: Section) => {
        if (!id || sec === 'parameters' || sec === 'addressFormat') return;
        setIsLoading(true);
            const typeMap: Record<string, string> = {
                provinces: 'province',
                regencies: 'regency',
                districts: 'district',
                villages: 'village',
                streets: 'street',
                buildings: 'building',
                groupOfHouses: 'groupOfHouses',
                landPlots: 'landPlot',
                postalCodes: 'postalCode',
            };
            const secType = typeMap[sec] || sec;
            try {
                const linRes = await fetch(`/settings/address-setup/lookup/bottom-up?type=${secType}&id=${id}`);
            if (linRes.ok) {
                const data = await linRes.json();
                setLineageInfo(data);
                if (data.timezone) {
                    setResolvedTz(data.timezone);
                }
                setForm(prev => ({
                    ...prev,
                    country_code: data.country?.code || prev.country_code || 'ID',
                    province_id: data.province?.id || prev.province_id,
                    province_name: data.province?.name || prev.province_name,
                    regency_id: data.regency?.id || prev.regency_id,
                    regency_name: data.regency?.name || prev.regency_name,
                    district_id: data.district?.id || prev.district_id,
                    district_name: data.district?.name || prev.district_name,
                    village_id: data.village?.id || prev.village_id,
                    village_name: data.village?.name || prev.village_name,
                }));
            }
        } catch {
            // non-fatal
        } finally {
            setIsLoading(false);
        }
    };

    /* External Codes & Translations fetchers */
    const fetchExternalCodes = async (divisionId: string) => {
        if (!divisionId) return;
        setIsModalLoading(true);
        try {
            const res = await fetch(`/settings/address-setup/external-codes?division_id=${divisionId}`);
            if (res.ok) {
                const data = await res.json();
                setExternalCodesList(data);
            }
        } finally {
            setIsModalLoading(false);
        }
    };

    const fetchTranslations = async (divisionId: string) => {
        if (!divisionId) return;
        setIsModalLoading(true);
        try {
            const res = await fetch(`/settings/address-setup/translations?division_id=${divisionId}`);
            if (res.ok) {
                const data = await res.json();
                setTranslationsList(data);
            }
        } finally {
            setIsModalLoading(false);
        }
    };

    /* Build Rows for Left Data Grid: CLEAN NAMES AS PRIMARY CONTENT (MEMOIZED TO PREVENT RERENDER LOOPS) */
    const rows = useMemo(() => {
        const search = searchTerm.toLowerCase().trim();
        let rawRows: { id: string; col1: string; col2: string; col3?: string; raw: Record<string, any> }[] = [];

        switch (activeSection) {
            case 'countries':
                rawRows = (countries ?? []).map((c) => ({
                    id: c.code,
                    col1: c.name,
                    col2: c.timezone || '—',
                    raw: { code: c.code, iso3: c.iso3 ?? '', name: c.name, phone_code: c.phone_code ?? '', timezone: c.timezone ?? '', active: c.active ? '1' : '0' },
                }));
                break;
            case 'provinces':
                rawRows = (provinces ?? []).map((p) => ({
                    id: p.id,
                    col1: p.name,
                    col2: p.timezone ? `${p.timezone}` : '—',
                    raw: {
                        id: p.id,
                        country_code: p.country_code,
                        code: p.code,
                        name: p.name,
                        description: p.description ?? '',
                        timezone: p.timezone ?? 'Asia/Jakarta',
                        intrastat: p.intrastat ?? '',
                        state_code: p.state_code ?? p.code ?? '',
                        it_state_code: p.it_state_code ?? '',
                        default_state: p.default_state ? '1' : '0',
                        union_territory: p.union_territory ? '1' : '0',
                        active: p.active ? '1' : '0',
                    },
                }));
                break;
            case 'regencies':
                rawRows = (regencies ?? []).map((r) => {
                    const provObj = (r as any).province;
                    return {
                        id: r.id,
                        col1: r.name,
                        col2: r.type === 'kota' ? 'Kota' : 'Kabupaten',
                        raw: {
                            id: r.id,
                            province_id: r.province_id,
                            country_code: provObj?.country_code || filterCountry || 'ID',
                            province_name: provObj?.name ?? '',
                            code: r.code,
                            name: r.name,
                            description: r.description ?? '',
                            type: r.type,
                            it_county_code: r.it_county_code ?? '',
                            es_county_code: r.es_county_code ?? '',
                            active: r.active ? '1' : '0',
                        },
                    };
                });
                break;
            case 'districts':
                rawRows = (districts ?? []).map((d) => {
                    const regObj = (d as any).regency;
                    const provObj = regObj?.province;
                    return {
                        id: d.id,
                        col1: d.name,
                        col2: d.active ? 'Active' : 'Inactive',
                        raw: {
                            id: d.id,
                            regency_id: d.regency_id,
                            province_id: regObj?.province_id ?? provObj?.id ?? '',
                            country_code: provObj?.country_code || filterCountry || 'ID',
                            regency_name: regObj?.name ?? '',
                            province_name: provObj?.name ?? '',
                            code: d.code,
                            name: d.name,
                            active: d.active ? '1' : '0',
                        },
                    };
                });
                break;
            case 'villages':
                if (isVillageLoading) {
                    rawRows = [];
                } else {
                    const villageList = (paginatedVillages && paginatedVillages.data) ? paginatedVillages.data : (villages ?? []);
                    rawRows = villageList.map((v) => {
                        const distObj = (v as any).district;
                        const regObj = distObj?.regency;
                        const provObj = regObj?.province;
                        return {
                            id: v.id,
                            col1: v.name,
                            col2: v.type === 'kelurahan' ? 'Kelurahan' : 'Desa',
                            col3: v.postal_code || 'Belum tersedia',
                            raw: {
                                id: v.id,
                                district_id: v.district_id,
                                regency_id: distObj?.regency_id ?? regObj?.id ?? '',
                                province_id: regObj?.province_id ?? provObj?.id ?? '',
                                country_code: provObj?.country_code || filterCountry || 'ID',
                                district_name: distObj?.name ?? '',
                                regency_name: regObj?.name ?? '',
                                province_name: provObj?.name ?? '',
                                code: v.code,
                                name: v.name,
                                type: v.type,
                                postal_code: v.postal_code ?? '',
                                active: v.active ? '1' : '0',
                            },
                        };
                    });
                }
                break;
            case 'streets':
                rawRows = (streets ?? []).map((s) => ({
                    id: s.id,
                    col1: s.name || `RT ${s.rt ?? '-'} / RW ${s.rw ?? '-'}`,
                    col2: `RT ${s.rt ?? '-'} / RW ${s.rw ?? '-'}`,
                    col3: s.postal_code || activeParentVillage?.postal_code || '-',
                    raw: { id: s.id, village_id: s.village_id, rt: s.rt ?? '', rw: s.rw ?? '', name: s.name ?? '', postal_code: s.postal_code ?? '', override_postal_code: s.override_postal_code ? '1' : '0', active: s.active ? '1' : '0' },
                }));
                break;
            case 'groupOfHouses':
                rawRows = (groupOfHouses ?? []).map((g) => ({
                    id: g.id,
                    col1: g.name,
                    col2: g.status || 'Active',
                    col3: g.postal_code || activeParentVillage?.postal_code || '-',
                    raw: { id: g.id, village_id: g.village_id, code: g.code ?? '', name: g.name, postal_code: g.postal_code ?? '', override_postal_code: g.override_postal_code ? '1' : '0', status: g.status ?? 'active', active: g.active ? '1' : '0' },
                }));
                break;
            case 'landPlots':
                rawRows = (landPlots ?? []).map((l) => ({
                    id: l.id,
                    col1: `Plot ${l.plot_number}`,
                    col2: l.name || l.status || 'Active',
                    col3: l.postal_code || activeParentVillage?.postal_code || '-',
                    raw: { id: l.id, village_id: l.village_id, street_id: l.street_id ?? '', group_of_houses_id: l.group_of_houses_id ?? '', plot_number: l.plot_number, name: l.name ?? '', postal_code: l.postal_code ?? '', override_postal_code: l.override_postal_code ? '1' : '0', status: l.status ?? 'active', active: l.active ? '1' : '0' },
                }));
                break;
            case 'buildings':
                rawRows = (buildings ?? []).map((b) => ({
                    id: b.id,
                    col1: b.name,
                    col2: `Blok ${b.block ?? '-'} Unit ${b.unit ?? '-'} Lt ${b.floor ?? '-'}`,
                    col3: b.postal_code || activeParentVillage?.postal_code || '-',
                    raw: { id: b.id, village_id: b.village_id, street_id: b.street_id ?? '', name: b.name, block: b.block ?? '', unit: b.unit ?? '', floor: b.floor ?? '', postal_code: b.postal_code ?? '', override_postal_code: b.override_postal_code ? '1' : '0', active: b.active ? '1' : '0' },
                }));
                break;
            case 'postalCodes':
                rawRows = (postalCodes ?? []).map((p) => {
                    const villageName = p.village?.name || (villages ?? []).find(v => v.id === p.village_id)?.name || p.area_name || '-';
                    const districtName = p.district?.name || (districts ?? []).find(d => d.id === p.district_id)?.name || '-';
                    return {
                        id: p.id,
                        col1: p.postal_code,
                        col2: `${villageName} (${districtName})`,
                        col3: p.source || 'POS_INDONESIA',
                        raw: {
                            id: p.id,
                            country_code: p.country_code,
                            postal_code: p.postal_code,
                            area_name: p.area_name ?? '',
                            source: p.source ?? 'POS_INDONESIA',
                            source_reference: p.source_reference ?? '',
                            status: p.status ?? 'active',
                            province_id: p.province_id ?? '',
                            regency_id: p.regency_id ?? '',
                            district_id: p.district_id ?? '',
                            village_id: p.village_id ?? '',
                            active: p.active ? '1' : '0',
                        },
                    };
                });
                break;
            case 'parameters':
                rawRows = (parameters ?? []).map((p) => ({
                    id: p.country_code,
                    col1: (countries ?? []).find((c) => c.code === p.country_code)?.name ?? p.country_code,
                    col2: p.country_code,
                    raw: { country_code: p.country_code, use_province: p.use_province ? '1' : '0', use_regency: p.use_regency ? '1' : '0', use_district: p.use_district ? '1' : '0', use_village: p.use_village ? '1' : '0', use_rt_rw: p.use_rt_rw ? '1' : '0', use_postal_code: p.use_postal_code ? '1' : '0', use_building: p.use_building ? '1' : '0', address_format: p.address_format ?? '' },
                }));
                break;
            case 'addressFormat':
                rawRows = (parameters ?? []).map((p) => ({
                    id: p.country_code,
                    col1: (countries ?? []).find((c) => c.code === p.country_code)?.name ?? p.country_code,
                    col2: p.address_format || '{street}, RT {rt}/RW {rw}, Kel. {village}, Kec. {district}, {regency}, {province} {postal_code}, {country}',
                    raw: { country_code: p.country_code, use_province: p.use_province ? '1' : '0', use_regency: p.use_regency ? '1' : '0', use_district: p.use_district ? '1' : '0', use_village: p.use_village ? '1' : '0', use_rt_rw: p.use_rt_rw ? '1' : '0', use_postal_code: p.use_postal_code ? '1' : '0', use_building: p.use_building ? '1' : '0', address_format: p.address_format ?? '' },
                }));
                break;
            default:
                rawRows = [];
        }

        let filtered = rawRows;
        if (search && activeSection !== 'villages') {
            filtered = rawRows.filter(r => r.col1.toLowerCase().includes(search) || r.col2.toLowerCase().includes(search) || (r.col3 && r.col3.toLowerCase().includes(search)));
        }

        return filtered.sort((a, b) => {
            return sortAsc ? a.col1.localeCompare(b.col1) : b.col1.localeCompare(a.col1);
        });
    }, [activeSection, searchTerm, sortAsc, countries, provinces, regencies, districts, villages, paginatedVillages, isVillageLoading, streets, groupOfHouses, landPlots, buildings, postalCodes, parameters, activeParentVillage]);

    // Check if form is dirty
    const isDirty = useMemo(() => {
        if (isNew) return true;
        return JSON.stringify(form) !== JSON.stringify(initialForm);
    }, [form, initialForm, isNew]);

    const initialSelectedAppliedRef = useRef<string | null>(null);

    // Synchronize selectedId and form with current rows without blocking search or user clicks
    useEffect(() => {
        if (isNew) return;

        // Apply server-selected ID (e.g. after save or page load) once
        const serverTarget = flash?.saved_id || initialSelectedId;
        if (serverTarget && initialSelectedAppliedRef.current !== serverTarget) {
            initialSelectedAppliedRef.current = serverTarget;
            if (rows.some(r => r.id === serverTarget)) {
                selectRow(serverTarget);
                return;
            }
        }

        if (rows.length === 0) {
            if (selectedId !== null) {
                setSelectedId(null);
                setForm({});
                setInitialForm({});
                setLineageInfo(null);
                setResolvedTz(null);
            }
            return;
        }

        // If the user's currently selected item is in the current search/filtered rows, preserve it!
        const isCurrentlySelected = selectedId && rows.some(r => r.id === selectedId);

        if (!isCurrentlySelected) {
            const firstRow = rows[0];
            selectRow(firstRow.id);
        }
    }, [rows, isNew, initialSelectedId, flash?.saved_id]);

    const selectRow = (id: string) => {
        const row = rows.find((r) => r.id === id);
        if (row) {
            setSelectedId(id);
            setIsNew(false);
            const rawData = { ...row.raw };

            // Bottom-up parent enrichment & active hierarchy tracking
            if (activeSection === 'provinces') {
                rawData.country_code = rawData.country_code || filterCountry || 'ID';
                setFilterProvince(id);
            } else if (activeSection === 'regencies') {
                const prov = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === rawData.province_id);
                if (prov) {
                    rawData.country_code = prov.country_code || rawData.country_code || filterCountry || 'ID';
                    rawData.province_name = prov.name;
                }
                setFilterRegency(id);
                if (rawData.province_id) setFilterProvince(rawData.province_id);
            } else if (activeSection === 'districts') {
                const reg = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === rawData.regency_id);
                if (reg) {
                    rawData.regency_name = reg.name;
                    rawData.province_id = reg.province_id || rawData.province_id;
                    const prov = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === (reg.province_id || rawData.province_id));
                    if (prov) {
                        rawData.province_name = prov.name;
                        rawData.country_code = prov.country_code || rawData.country_code || filterCountry || 'ID';
                    }
                }
                setFilterDistrict(id);
                if (rawData.regency_id) setFilterRegency(rawData.regency_id);
                if (rawData.province_id) setFilterProvince(rawData.province_id);
            } else if (activeSection === 'villages') {
                const dist = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === rawData.district_id);
                if (dist) {
                    rawData.district_name = dist.name;
                    rawData.regency_id = dist.regency_id || rawData.regency_id;
                    const reg = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === (dist.regency_id || rawData.regency_id));
                    if (reg) {
                        rawData.regency_name = reg.name;
                        rawData.province_id = reg.province_id || rawData.province_id;
                        const prov = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === (reg.province_id || rawData.province_id));
                        if (prov) {
                            rawData.province_name = prov.name;
                            rawData.country_code = prov.country_code || rawData.country_code || filterCountry || 'ID';
                        }
                    }
                }
                setFilterVillage(id);
            }

            setForm(rawData);
            setInitialForm(rawData);
            fetchLineageAndTimezone(id, activeSection);
        }
    };

    /* Cancel Create Mode and revert to selection */
    const handleCancel = () => {
        setIsNew(false);
        if (rows.length > 0) {
            selectRow(rows[0].id);
        } else {
            setSelectedId(null);
            setForm({});
            setInitialForm({});
            setLineageInfo(null);
            setResolvedTz(null);
        }
    };

    /* Inline Create: strictly bound to active context */
    const handleNew = () => {
        setSelectedId(null);
        setIsNew(true);
        setLineageInfo(null);
        setResolvedTz(null);

        let defaultCountry = filterCountry || form.country_code || 'ID';
        let defaultProv = filterProvince || form.province_id || '';
        let defaultReg = filterRegency || form.regency_id || (activeSection === 'regencies' ? '' : (form.id || ''));
        let defaultDist = filterDistrict || form.district_id || (activeSection === 'districts' ? '' : (form.id || ''));
        let defaultVill = filterVillage || form.village_id || '';

        if (defaultDist) {
            const d = ((dropdowns?.districts ?? districts) ?? []).find(x => x.id === defaultDist);
            if (d?.regency_id) {
                defaultReg = d.regency_id;
                const r = ((dropdowns?.regencies ?? regencies) ?? []).find(x => x.id === d.regency_id);
                if (r?.province_id) {
                    defaultProv = r.province_id;
                    const p = ((dropdowns?.provinces ?? provinces) ?? []).find(x => x.id === r.province_id);
                    if (p?.country_code) defaultCountry = p.country_code;
                }
            }
        } else if (defaultReg) {
            const r = ((dropdowns?.regencies ?? regencies) ?? []).find(x => x.id === defaultReg);
            if (r?.province_id) {
                defaultProv = r.province_id;
                const p = ((dropdowns?.provinces ?? provinces) ?? []).find(x => x.id === r.province_id);
                if (p?.country_code) defaultCountry = p.country_code;
            }
        } else if (defaultProv) {
            const p = ((dropdowns?.provinces ?? provinces) ?? []).find(x => x.id === defaultProv);
            if (p?.country_code) defaultCountry = p.country_code;
        }

        const autoTz = detectTimezone(defaultCountry, defaultProv ? (((dropdowns?.provinces ?? provinces) ?? []).find(x => x.id === defaultProv)?.code || '') : '');

        const defaults: Record<string, any> = {
            country_code: defaultCountry,
            province_id: defaultProv,
            regency_id: defaultReg,
            district_id: defaultDist,
            village_id: defaultVill,
            name: '',
            code: '',
            state_code: '',
            description: '',
            type: activeSection === 'regencies' ? 'kabupaten' : (activeSection === 'villages' ? 'desa' : ''),
            status: 'active',
            postal_code: '',
            source: 'POS_INDONESIA',
            override_postal_code: '0',
            default_state: '0',
            union_territory: '0',
            timezone: activeSection === 'provinces' ? autoTz : '',
            active: '1',
        };
        setForm(defaults);
        setInitialForm(defaults);
    };

    const handleDelete = () => {
        if (!selectedId) return;
        const currentName = form.name || form.plot_number || form.postal_code || form.code || selectedId;
        setDeleteConfirmTarget({ id: selectedId, name: currentName });
    };

    const executeDelete = () => {
        if (!deleteConfirmTarget) return;

        const urls: Record<string, string> = {
            countries: `/settings/address-setup/countries/${deleteConfirmTarget.id}`,
            provinces: `/settings/address-setup/provinces/${deleteConfirmTarget.id}`,
            regencies: `/settings/address-setup/regencies/${deleteConfirmTarget.id}`,
            districts: `/settings/address-setup/districts/${deleteConfirmTarget.id}`,
            villages: `/settings/address-setup/villages/${deleteConfirmTarget.id}`,
            streets: `/settings/address-setup/streets/${deleteConfirmTarget.id}`,
            groupOfHouses: `/settings/address-setup/group-of-houses/${deleteConfirmTarget.id}`,
            landPlots: `/settings/address-setup/land-plots/${deleteConfirmTarget.id}`,
            buildings: `/settings/address-setup/buildings/${deleteConfirmTarget.id}`,
            postalCodes: `/settings/address-setup/postal-codes/${deleteConfirmTarget.id}`,
        };

        const url = urls[activeSection];
        if (!url) return;

        setIsDeleting(true);
        router.delete(url, {
            preserveState: true,
            onSuccess: () => {
                setIsDeleting(false);
                setDeleteConfirmTarget(null);
                setSelectedId(null);
                setIsNew(false);
                setForm({});
                showToast('Data berhasil dihapus');
            },
            onError: (errs) => {
                setIsDeleting(false);
                setDeleteConfirmTarget(null);
                const msg = errs.error || 'Data tidak dapat dihapus karena masih memiliki data turunan.';
                showToast(msg, 'error');
            },
        });
    };

    const handleSave = () => {
        // Validation checks
        if (activeSection === 'provinces') {
            if (!form.name?.trim()) {
                showToast('Lengkapi nama provinsi terlebih dahulu.', 'error');
                return;
            }
            if (!form.code?.trim() && !form.state_code?.trim()) {
                showToast('Lengkapi State code (kode provinsi) terlebih dahulu.', 'error');
                return;
            }
        }
        if (activeSection === 'regencies') {
            if (!form.province_id) {
                showToast('Pilih provinsi terlebih dahulu.', 'error');
                return;
            }
            if (!form.name?.trim()) {
                showToast('Lengkapi nama kabupaten/kota terlebih dahulu.', 'error');
                return;
            }
            if (!form.code?.trim()) {
                showToast('Lengkapi kode kabupaten/kota terlebih dahulu.', 'error');
                return;
            }
        }
        if (activeSection === 'districts') {
            if (!form.regency_id) {
                showToast('Pilih kabupaten/kota terlebih dahulu.', 'error');
                return;
            }
            if (!form.name?.trim()) {
                showToast('Lengkapi nama kecamatan terlebih dahulu.', 'error');
                return;
            }
            if (!form.code?.trim()) {
                showToast('Lengkapi kode kecamatan terlebih dahulu.', 'error');
                return;
            }
        }
        if (activeSection === 'villages') {
            if (!form.district_id) {
                showToast('Pilih kecamatan terlebih dahulu.', 'error');
                return;
            }
            if (!form.name?.trim()) {
                showToast('Lengkapi nama desa/kelurahan terlebih dahulu.', 'error');
                return;
            }
            if (!form.code?.trim()) {
                showToast('Lengkapi kode desa/kelurahan terlebih dahulu.', 'error');
                return;
            }
            if (form.postal_code && (form.country_code || filterCountry) === 'ID' && !/^[0-9]{5}$/.test(form.postal_code.trim())) {
                showToast('Kode pos Indonesia harus berupa 5 digit angka (misal: 80361).', 'error');
                return;
            }
        }
        if (activeSection === 'streets') {
            if (!form.village_id) {
                showToast('Pilih desa/kelurahan terlebih dahulu.', 'error');
                return;
            }
            if (!form.rt?.trim() && !form.name?.trim()) {
                showToast('Lengkapi RT/RW atau nama jalan terlebih dahulu.', 'error');
                return;
            }
        }
        if (activeSection === 'postalCodes') {
            if (!form.postal_code?.trim()) {
                showToast('Lengkapi kode pos terlebih dahulu.', 'error');
                return;
            }
            if ((form.country_code || filterCountry) === 'ID' && !/^[0-9]{5}$/.test(form.postal_code.trim())) {
                showToast('Kode pos Indonesia harus berupa 5 digit angka (misal: 80361).', 'error');
                return;
            }
        }
        if (activeSection === 'landPlots' && !form.plot_number?.trim()) {
            showToast('Lengkapi nomor plot terlebih dahulu.', 'error');
            return;
        }

        const urls: Record<string, string> = {
            parameters: '/settings/address-setup/parameters',
            addressFormat: '/settings/address-setup/parameters',
            countries: '/settings/address-setup/countries',
            provinces: '/settings/address-setup/provinces',
            regencies: '/settings/address-setup/regencies',
            districts: '/settings/address-setup/districts',
            villages: '/settings/address-setup/villages',
            streets: '/settings/address-setup/streets',
            groupOfHouses: '/settings/address-setup/group-of-houses',
            landPlots: '/settings/address-setup/land-plots',
            buildings: '/settings/address-setup/buildings',
            postalCodes: '/settings/address-setup/postal-codes',
        };

        const url = urls[activeSection];
        if (!url) return;

        setIsSaving(true);
        const payload: Record<string, any> = { ...form };
        if (!isNew && selectedId) {
            payload.id = selectedId;
        }
        if (payload.active !== undefined) {
            payload.active = payload.active === '1' || payload.active === true;
        }
        if (payload.override_postal_code !== undefined) {
            payload.override_postal_code = payload.override_postal_code === '1' || payload.override_postal_code === true;
        }
        if (activeSection === 'provinces') {
            const cleanCode = (payload.code || payload.state_code || '').toString().trim();
            payload.code = cleanCode;
            payload.state_code = cleanCode;
            payload.country_code = form.country_code || filterCountry || 'ID';
            payload.default_state = payload.default_state === '1' || payload.default_state === true;
            payload.union_territory = payload.union_territory === '1' || payload.union_territory === true;
            if (!payload.timezone) {
                payload.timezone = detectTimezone(payload.country_code, cleanCode);
            }
        }
        if (activeSection === 'regencies') {
            payload.code = (payload.code || '').toString().replace(/\./g, '');
        }
        if (activeSection === 'districts') {
            payload.code = (payload.code || '').toString().replace(/\./g, '');
        }
        if (activeSection === 'villages') {
            payload.code = (payload.code || '').toString().replace(/\./g, '');
        }

        router.post(url, payload, {
            preserveState: false,
            onSuccess: (page: any) => {
                const wasNew = isNew;
                const savedId = page?.props?.flash?.saved_id || page?.props?.selectedId;
                setIsNew(false);
                setIsSaving(false);
                if (savedId) {
                    setSelectedId(savedId);
                }
                showToast(wasNew ? 'Data berhasil disimpan.' : 'Data berhasil diperbarui.');
            },
            onError: (errs) => {
                setIsSaving(false);
                const firstErr = Object.values(errs)[0] || 'Gagal menyimpan data';
                showToast(`Data gagal disimpan: ${String(firstErr)}`, 'error');
            },
        });
    };


    const handleCountrySwitch = (countryCode: string) => {
        setFilterCountry(countryCode);
        setFilterProvince('');
        setFilterRegency('');
        setFilterDistrict('');
        setFilterVillage('');
        setSelectedId(null);
        setIsNew(false);
        setForm({});
        setInitialForm({});
        setSearchTerm('');
        setVillagePage(1);

        router.get('/settings/address-setup', {
            section: activeSection,
            country: countryCode,
            province_id: '',
            regency_id: '',
            district_id: '',
            village_id: '',
        }, {
            preserveState: false,
            replace: true,
        });
    };

    const executeFilter = (newFilters: {
        section?: Section;
        country?: string;
        province_id?: string;
        regency_id?: string;
        district_id?: string;
        village_id?: string;
    }) => {
        const sec = newFilters.section ?? activeSection;
        const c = newFilters.country ?? filterCountry;
        const p = newFilters.province_id !== undefined ? newFilters.province_id : filterProvince;
        const r = newFilters.regency_id !== undefined ? newFilters.regency_id : filterRegency;
        const d = newFilters.district_id !== undefined ? newFilters.district_id : filterDistrict;
        const v = newFilters.village_id !== undefined ? newFilters.village_id : filterVillage;

        setSelectedId(null);
        setIsNew(false);
        setForm({});
        setInitialForm({});
        setSearchTerm('');
        setVillagePage(1);
        setPaginatedVillages(null);
        setIsVillageLoading(true);

        setFilterProvince(p);
        setFilterRegency(r);
        setFilterDistrict(d);
        setFilterVillage(v);

        router.get('/settings/address-setup', {
            section: sec,
            country: c,
            province_id: p,
            regency_id: r,
            district_id: d,
            village_id: v,
        }, {
            preserveState: false,
            replace: true,
        });
    };

    const f = (k: string) => form[k] ?? '';
    const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
        setForm((prev) => ({ ...prev, [k]: e.target.value }));
    };

    /* Table column headers */
    const { h1, h2, h3 } = useMemo(() => {
        switch (activeSection) {
            case 'parameters': return { h1: 'Country/region', h2: 'ISO Code', h3: undefined };
            case 'addressFormat': return { h1: 'Country/region', h2: 'Format Alamat', h3: undefined };
            case 'countries': return { h1: 'Country/region', h2: 'Timezone', h3: undefined };
            case 'provinces': return { h1: levelLabels.province, h2: 'Timezone', h3: undefined };
            case 'regencies': return { h1: levelLabels.regency, h2: 'Tipe', h3: undefined };
            case 'districts': return { h1: levelLabels.district, h2: 'Status', h3: undefined };
            case 'villages': return { h1: levelLabels.village, h2: 'Tipe', h3: 'Kode Pos' };
            case 'streets': return { h1: levelLabels.street, h2: 'RW / Blok', h3: 'Kode Pos' };
            case 'groupOfHouses': return { h1: 'Nama Group of Houses', h2: 'Status', h3: 'Kode Pos' };
            case 'landPlots': return { h1: 'Nomor Plot', h2: 'Keterangan', h3: 'Kode Pos' };
            case 'buildings': return { h1: 'Gedung / Bangunan', h2: 'Unit / Lantai', h3: 'Kode Pos' };
            case 'postalCodes': return { h1: 'Kode Pos', h2: 'Wilayah (Desa / Area)', h3: 'Sumber' };
            default: return { h1: 'Nama', h2: 'Keterangan', h3: undefined };
        }
    }, [activeSection, levelLabels]);

    const needsProvince = ['regencies', 'districts', 'villages', 'streets', 'groupOfHouses', 'landPlots', 'buildings', 'postalCodes'].includes(activeSection);
    const needsRegency = ['districts', 'villages', 'streets', 'groupOfHouses', 'landPlots', 'buildings', 'postalCodes'].includes(activeSection);
    const needsDistrict = ['villages', 'streets', 'groupOfHouses', 'landPlots', 'buildings', 'postalCodes'].includes(activeSection);
    const needsVillage = ['streets', 'groupOfHouses', 'landPlots', 'buildings', 'postalCodes'].includes(activeSection);

    /* Compute strictly scoped Context / Breadcrumb text (Clean Names Only) */
    const contextText = useMemo(() => {
        if (lineageInfo?.formatted && selectedId) {
            return lineageInfo.formatted;
        }

        const countryObj = currentActiveCountry || (countries ?? []).find(c => c.code === 'ID');
        const parts: string[] = [countryObj?.name || 'Indonesia'];

        const provName = ((dropdowns?.provinces ?? provinces) ?? []).find(x => x.id === (filterProvince || form.province_id))?.name || (activeSection === 'provinces' && (form.name || form.col1) ? (form.name || form.col1) : '');
        if (provName && !parts.includes(provName)) parts.push(provName);

        const regName = ((dropdowns?.regencies ?? regencies) ?? []).find(x => x.id === (filterRegency || (activeSection === 'regencies' ? (selectedId || form.id) : form.regency_id)))?.name || (activeSection === 'regencies' && (form.name || form.col1) ? (form.name || form.col1) : '');
        if (regName && !parts.includes(regName)) parts.push(regName);

        const distName = ((dropdowns?.districts ?? districts) ?? []).find(x => x.id === (filterDistrict || (activeSection === 'districts' ? (selectedId || form.id) : form.district_id)))?.name || (activeSection === 'districts' && (form.name || form.col1) ? (form.name || form.col1) : '');
        if (distName && !parts.includes(distName)) parts.push(distName);

        const villName = ((dropdowns?.villages ?? villages) ?? []).find(x => x.id === (filterVillage || (activeSection === 'villages' ? (selectedId || form.id) : form.village_id)))?.name || (activeSection === 'villages' && (form.name || form.col1) ? (form.name || form.col1) : '');
        if (villName && !parts.includes(villName)) parts.push(villName);

        return parts.join(' > ');
    }, [lineageInfo, selectedId, currentActiveCountry, countries, filterProvince, filterRegency, filterDistrict, filterVillage, dropdowns, provinces, regencies, districts, villages, form, activeSection]);

    /* Dynamic Live Address Preview computed from real hierarchy context */
    const liveAddressPreview = useMemo(() => {
        if (activeSection !== 'addressFormat' && activeSection !== 'parameters') {
            return null;
        }
        const template = form.address_format || ((parameters ?? []).find(p => p.country_code === selectedCountryCode)?.address_format) || '{street}, RT {rt}/RW {rw}, Kel. {village}, Kec. {district}, {regency}, {province} {postal_code}, {country}';

        const countryObj = currentActiveCountry || (countries ?? []).find(c => c.code === selectedCountryCode);
        if (!countryObj) {
            return 'Preview belum lengkap karena data wilayah belum dipilih.';
        }

        const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === filterProvince) || (selectedCountryCode === 'ID' ? (provinces ?? []).find(p => p.code === '51' || p.name === 'Bali') : null);
        const rObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === filterRegency) || (pObj ? (regencies ?? []).find(r => r.province_id === pObj.id) : null);
        const dObj = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === filterDistrict) || (rObj ? (districts ?? []).find(d => d.regency_id === rObj.id) : null);
        const vObj = ((dropdowns?.villages ?? villages) ?? []).find(v => v.id === filterVillage) || (dObj ? (villages ?? []).find(v => v.district_id === dObj.id) : null);

        const sampleStreet = 'Jl. Bypass Ngurah Rai';
        const sampleRt = '01';
        const sampleRw = '02';
        const sampleVillage = vObj?.name || (selectedCountryCode === 'ID' ? 'Benoa' : 'Downtown');
        const sampleDistrict = dObj?.name || (selectedCountryCode === 'ID' ? 'Kuta Selatan' : 'Central District');
        const sampleRegency = rObj?.name || (selectedCountryCode === 'ID' ? 'Kabupaten Badung' : 'Badung Regency');
        const sampleProvince = pObj?.name || (selectedCountryCode === 'ID' ? 'Bali' : 'Bali Province');
        const samplePostal = vObj?.postal_code || (selectedCountryCode === 'ID' ? '80361' : '10000');
        const sampleCountry = countryObj.name;

        let rendered = template
            .replace(/\{street\}/g, sampleStreet)
            .replace(/\{rt\}/g, sampleRt)
            .replace(/\{rw\}/g, sampleRw)
            .replace(/\{village\}/g, sampleVillage)
            .replace(/\{district\}/g, sampleDistrict)
            .replace(/\{regency\}/g, sampleRegency)
            .replace(/\{province\}/g, sampleProvince)
            .replace(/\{postal_code\}/g, samplePostal)
            .replace(/\{country\}/g, sampleCountry);

        return rendered;
    }, [activeSection, form.address_format, parameters, selectedCountryCode, currentActiveCountry, countries, filterProvince, filterRegency, filterDistrict, filterVillage, dropdowns, provinces, regencies, districts, villages]);

    return (
        <div className="flex min-h-[calc(100svh-4.5rem)] h-[calc(100svh-4.5rem)] w-full min-w-0 max-w-full overflow-hidden bg-[#f3f4f6] text-gray-800 text-xs font-sans rounded-b-2xl">
            <Head title="Address Setup" />

            {/* Toast notification */}
            {toastMessage && (
                <div className={`fixed top-4 right-4 z-50 px-4 py-2.5 rounded shadow-lg text-xs flex items-center gap-2 border transition-all ${toastMessage.type === 'error' ? 'bg-red-50 text-red-800 border-red-300' : 'bg-emerald-50 text-emerald-800 border-emerald-300'}`}>
                    {toastMessage.type === 'error' ? <X size={14} /> : <Check size={14} />}
                    <span>{toastMessage.text}</span>
                </div>
            )}

            {/* ===== COMPACT LEFT SIDEBAR (176px) ===== */}
            <aside className="flex flex-col w-44 shrink-0 border-r border-gray-200 bg-white select-none">
                <div className="p-3 border-b border-gray-100 flex items-center gap-2">
                    <MapPin size={15} className="text-[#0078d4] shrink-0" />
                    <span className="font-semibold text-gray-800 text-xs tracking-tight truncate">Address setup</span>
                </div>

                <nav className="flex-1 overflow-y-auto py-1">
                    {navItems.map((item) => {
                        const isActive = activeSection === item.key;
                        return (
                            <button
                                key={item.key}
                                onClick={() => {
                                    // Smart Context Preservation across Hierarchy Levels (e.g. Bali -> Regencies/Districts/Villages in Bali)
                                    let targetProvId = filterProvince;
                                    let targetRegId = filterRegency;
                                    let targetDistId = filterDistrict;
                                    let targetVillId = filterVillage;

                                    if (activeSection === 'provinces') {
                                        targetProvId = selectedId || form.id || filterProvince || '';
                                        targetRegId = '';
                                        targetDistId = '';
                                        targetVillId = '';
                                    } else if (activeSection === 'regencies') {
                                        targetProvId = form.province_id || filterProvince || '';
                                        targetRegId = selectedId || form.id || filterRegency || '';
                                        targetDistId = '';
                                        targetVillId = '';
                                    } else if (activeSection === 'districts') {
                                        targetProvId = form.province_id || filterProvince || '';
                                        targetRegId = form.regency_id || filterRegency || '';
                                        targetDistId = selectedId || form.id || filterDistrict || '';
                                        targetVillId = '';
                                    } else if (activeSection === 'villages') {
                                        targetProvId = form.province_id || filterProvince || '';
                                        targetRegId = form.regency_id || filterRegency || '';
                                        targetDistId = form.district_id || filterDistrict || '';
                                        targetVillId = selectedId || form.id || filterVillage || '';
                                    } else if (['streets', 'buildings', 'groupOfHouses', 'landPlots'].includes(activeSection)) {
                                        targetProvId = form.province_id || filterProvince || '';
                                        targetRegId = form.regency_id || filterRegency || '';
                                        targetDistId = form.district_id || filterDistrict || '';
                                        targetVillId = form.village_id || filterVillage || '';
                                    }

                                    // Clear non-applicable sub-filters for top-level or target sections
                                    if (['parameters', 'addressFormat', 'countries'].includes(item.key)) {
                                        targetProvId = '';
                                        targetRegId = '';
                                        targetDistId = '';
                                        targetVillId = '';
                                    } else if (item.key === 'provinces') {
                                        targetRegId = '';
                                        targetDistId = '';
                                        targetVillId = '';
                                    } else if (item.key === 'regencies') {
                                        targetRegId = '';
                                        targetDistId = '';
                                        targetVillId = '';
                                    } else if (item.key === 'districts') {
                                        targetDistId = '';
                                        targetVillId = '';
                                    } else if (item.key === 'villages') {
                                        targetVillId = '';
                                    }

                                    setActiveSection(item.key);
                                    setSelectedId(null);
                                    setIsNew(false);
                                    setForm({});
                                    setInitialForm({});
                                    setSearchTerm('');
                                    setVillagePage(1);

                                    setFilterProvince(targetProvId);
                                    setFilterRegency(targetRegId);
                                    setFilterDistrict(targetDistId);
                                    setFilterVillage(targetVillId);

                                    executeFilter({
                                        section: item.key,
                                        country: filterCountry,
                                        province_id: targetProvId,
                                        regency_id: targetRegId,
                                        district_id: targetDistId,
                                        village_id: targetVillId,
                                    });
                                }}
                                className={[
                                    'w-full text-left px-3.5 py-1.5 text-xs transition-colors flex items-center justify-between cursor-pointer',
                                    isActive
                                        ? 'bg-[#e8f0fe] text-[#0078d4] font-medium border-l-2 border-[#0078d4]'
                                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900',
                                ].join(' ')}
                            >
                                <span className="truncate">{item.label}</span>
                                {isActive && <ChevronRight size={11} className="text-[#0078d4] shrink-0" />}
                            </button>
                        );
                    })}
                </nav>
            </aside>

            {/* ===== MAIN CONTENT AREA ===== */}
            <div className="flex flex-col flex-1 min-w-0 overflow-hidden bg-white">

                {/* ===== ACTION BAR ===== */}
                <div className="flex items-center gap-1.5 px-3 py-1.5 border-b border-gray-200 bg-[#fafafa] shrink-0 flex-wrap">
                    {isNew ? (
                        <button
                            onClick={handleCancel}
                            title="Batal membuat baris baru"
                            aria-label="Cancel"
                            className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 active:bg-gray-100 shadow-2xs transition-colors cursor-pointer"
                        >
                            <X size={13} className="text-gray-500" />
                            <span>Batal</span>
                        </button>
                    ) : (
                        <button
                            onClick={handleNew}
                            title="Create new row"
                            aria-label="New"
                            className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 hover:border-gray-400 active:bg-gray-100 shadow-2xs transition-colors cursor-pointer"
                        >
                            <Plus size={13} className="text-[#0078d4]" />
                            <span>New</span>
                        </button>
                    )}

                    <button
                        onClick={handleDelete}
                        disabled={!selectedId || isNew || isSaving}
                        title="Delete selected row"
                        aria-label="Delete"
                        className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-red-50 hover:text-red-700 hover:border-red-300 active:bg-red-100 disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs transition-colors cursor-pointer"
                    >
                        <Trash2 size={13} className="text-red-600" />
                        <span>Delete</span>
                    </button>

                    <button
                        onClick={handleSave}
                        disabled={(!isNew && !isDirty) || isSaving}
                        title="Save changes"
                        aria-label="Save"
                        className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-white bg-[#0078d4] border border-[#0078d4] rounded hover:bg-[#106ebe] active:bg-[#005a9e] disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs transition-colors cursor-pointer"
                    >
                        {isSaving ? <Loader2 size={13} className="animate-spin" /> : <Save size={13} />}
                        <span>Save</span>
                    </button>

                    <div className="h-4 w-px bg-gray-300 mx-1" />

                    <button
                        onClick={() => {
                            if (!selectedId) return;
                            fetchExternalCodes(selectedId);
                            setShowExternalCodesModal(true);
                        }}
                        disabled={!selectedId || isNew}
                        title="Manage external codes (Kemendagri, BPS, ISO)"
                        aria-label="External codes"
                        className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 active:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs transition-colors cursor-pointer"
                    >
                        <ExternalLink size={13} className="text-gray-500" />
                        <span>External codes</span>
                    </button>

                    <button
                        onClick={() => {
                            if (!selectedId) return;
                            fetchTranslations(selectedId);
                            setShowTranslationsModal(true);
                        }}
                        disabled={!selectedId || isNew}
                        title="Manage translations"
                        aria-label="Translations"
                        className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 active:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs transition-colors cursor-pointer"
                    >
                        <Languages size={13} className="text-gray-500" />
                        <span>Translations</span>
                    </button>

                    <button
                        onClick={() => executeFilter({})}
                        title="Refresh data"
                        aria-label="Refresh"
                        className="p-1 text-gray-500 hover:text-gray-700 border border-gray-300 bg-white rounded ml-auto hover:bg-gray-50 cursor-pointer"
                    >
                        <RefreshCw size={12} />
                    </button>
                </div>

                {/* ===== CONTENT AREA ===== */}
                <div className="flex flex-col flex-1 min-w-0 overflow-hidden">

                    {/* Page Subheading & Context Banner */}
                    <div className="px-4 pt-2.5 pb-2 border-b border-gray-100 shrink-0">
                        <div className="text-xs font-semibold text-gray-900 capitalize">
                            {navItems.find(i => i.key === activeSection)?.label ?? activeSection}
                        </div>
                        <p className="text-gray-500 text-[11px] mt-0.5">{sectionTitle}</p>

                        <div className="flex items-center gap-1.5 mt-1.5 text-[11px] text-gray-600 bg-gray-50 px-2 py-0.5 rounded border border-gray-200">
                            <span className="font-semibold text-gray-700 shrink-0">Context:</span>
                            <span className="text-[#0078d4] font-medium truncate">{contextText}</span>
                        </div>
                    </div>

                    {/* ===== CASCADING DROPDOWN FILTER TOOLBAR (NAMES ONLY) ===== */}
                    {activeSection !== 'parameters' && activeSection !== 'addressFormat' && (
                        <div className="flex flex-wrap items-end gap-2 px-4 py-2 bg-[#f8f9fa] border-b border-gray-200 shrink-0">

                            {/* Country / Region select */}
                            <div className="flex flex-col gap-0.5">
                                <label className="text-[10px] text-gray-500 font-normal">Country/region</label>
                                <select
                                    value={filterCountry}
                                    onChange={(e) => handleCountrySwitch(e.target.value)}
                                    className="h-6.5 text-xs border border-gray-300 rounded px-1.5 bg-white max-w-[140px] focus:border-[#0078d4] focus:outline-none font-medium truncate"
                                >
                                    {(countries ?? []).map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Province / State select */}
                            {needsProvince && (
                                <div className="flex flex-col gap-0.5">
                                    <label className="text-[10px] text-gray-500 font-normal">{levelLabels.province}</label>
                                    <select
                                        value={filterProvince}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === val);
                                            const targetCountry = pObj?.country_code || filterCountry;
                                            setFilterCountry(targetCountry);
                                            setFilterProvince(val);
                                            setFilterRegency('');
                                            setFilterDistrict('');
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            executeFilter({ section: activeSection, country: targetCountry, province_id: val, regency_id: '', district_id: '', village_id: '' });
                                        }}
                                        className="h-6.5 text-xs border border-gray-300 rounded px-1.5 bg-white max-w-[150px] focus:border-[#0078d4] focus:outline-none truncate"
                                    >
                                        <option value="">-- All {levelLabels.province} --</option>
                                        {((dropdowns?.provinces ?? provinces) ?? [])
                                            .filter(p => !filterCountry || p.country_code === filterCountry)
                                            .map((p) => (
                                                <option key={p.id} value={p.id}>{p.name}</option>
                                            ))}
                                    </select>
                                </div>
                            )}

                            {/* Regency / County select */}
                            {needsRegency && (
                                <div className="flex flex-col gap-0.5">
                                    <label className="text-[10px] text-gray-500 font-normal">{levelLabels.regency}</label>
                                    <select
                                        value={filterRegency}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            const regObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === val);
                                            const targetProvId = regObj?.province_id || filterProvince;
                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === targetProvId);
                                            const targetCountry = pObj?.country_code || filterCountry;
                                            setFilterCountry(targetCountry);
                                            setFilterProvince(targetProvId);
                                            setFilterRegency(val);
                                            setFilterDistrict('');
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            executeFilter({ section: activeSection, country: targetCountry, province_id: targetProvId, regency_id: val, district_id: '', village_id: '' });
                                        }}
                                        className="h-6.5 text-xs border border-gray-300 rounded px-1.5 bg-white max-w-[160px] focus:border-[#0078d4] focus:outline-none truncate"
                                    >
                                        <option value="">-- All {levelLabels.regency} --</option>
                                        {((dropdowns?.regencies ?? regencies) ?? [])
                                            .filter(r => !filterProvince || r.province_id === filterProvince)
                                            .map((r) => (
                                                <option key={r.id} value={r.id}>{r.name}</option>
                                            ))}
                                    </select>
                                </div>
                            )}

                            {/* District / Kecamatan select */}
                            {needsDistrict && (
                                <div className="flex flex-col gap-0.5">
                                    <label className="text-[10px] text-gray-500 font-normal">{levelLabels.district}</label>
                                    <select
                                        value={filterDistrict}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            const distObj = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === val);
                                            const targetRegId = distObj?.regency_id || filterRegency;
                                            const regObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === targetRegId);
                                            const targetProvId = regObj?.province_id || filterProvince;
                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === targetProvId);
                                            const targetCountry = pObj?.country_code || filterCountry;
                                            setFilterCountry(targetCountry);
                                            setFilterProvince(targetProvId);
                                            setFilterRegency(targetRegId);
                                            setFilterDistrict(val);
                                            setFilterVillage('');
                                            setSelectedId(null);
                                            setVillagePage(1);
                                            executeFilter({ section: activeSection, country: targetCountry, province_id: targetProvId, regency_id: targetRegId, district_id: val, village_id: '' });
                                        }}
                                        className="h-6.5 text-xs border border-gray-300 rounded px-1.5 bg-white max-w-[150px] focus:border-[#0078d4] focus:outline-none truncate"
                                    >
                                        <option value="">-- All {levelLabels.district} --</option>
                                        {((dropdowns?.districts ?? districts) ?? [])
                                            .filter(d => !filterRegency || d.regency_id === filterRegency)
                                            .map((d) => (
                                                <option key={d.id} value={d.id}>{d.name}</option>
                                            ))}
                                    </select>
                                </div>
                            )}

                            {/* Village / Desa select */}
                            {needsVillage && (
                                <div className="flex flex-col gap-0.5">
                                    <label className="text-[10px] text-gray-500 font-normal">{levelLabels.village}</label>
                                    <select
                                        value={filterVillage}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            const villObj = ((dropdowns?.villages ?? villages) ?? []).find(v => v.id === val);
                                            const targetDistId = villObj?.district_id || filterDistrict;
                                            const distObj = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === targetDistId);
                                            const targetRegId = distObj?.regency_id || filterRegency;
                                            const regObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === targetRegId);
                                            const targetProvId = regObj?.province_id || filterProvince;
                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === targetProvId);
                                            const targetCountry = pObj?.country_code || filterCountry;
                                            setFilterCountry(targetCountry);
                                            setFilterProvince(targetProvId);
                                            setFilterRegency(targetRegId);
                                            setFilterDistrict(targetDistId);
                                            setFilterVillage(val);
                                            setSelectedId(null);
                                            executeFilter({ section: activeSection, country: targetCountry, province_id: targetProvId, regency_id: targetRegId, district_id: targetDistId, village_id: val });
                                        }}
                                        className="h-6.5 text-xs border border-gray-300 rounded px-1.5 bg-white max-w-[150px] focus:border-[#0078d4] focus:outline-none truncate"
                                    >
                                        <option value="">-- All {levelLabels.village} --</option>
                                        {((dropdowns?.villages ?? villages) ?? []).map((v) => (
                                            <option key={v.id} value={v.id}>{v.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            {/* Scoped Search Input */}
                            <div className="flex items-center border border-gray-300 rounded px-2 h-6.5 bg-white ml-auto w-44">
                                <Search size={11} className="text-gray-400 mr-1 shrink-0" />
                                <input
                                    type="text"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder={`Search...`}
                                    className="text-xs bg-transparent border-none outline-none w-full"
                                />
                                {searchTerm && (
                                    <button onClick={() => setSearchTerm('')} className="text-gray-400 hover:text-gray-600 cursor-pointer">
                                        <X size={11} />
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Split View: Left Data Grid + Right Form Panels */}
                    <div className="flex flex-1 min-w-0 overflow-hidden relative">

                        {/* Loading Overlay */}
                        {(isLoading || isVillageLoading) && (
                            <div className="absolute inset-0 bg-white/70 backdrop-blur-xs flex items-center justify-center z-10">
                                <div className="flex items-center gap-2 text-gray-600 bg-white px-3 py-1.5 rounded shadow-md border">
                                    <Loader2 size={15} className="animate-spin text-[#0078d4]" />
                                    <span>Memuat data wilayah...</span>
                                </div>
                            </div>
                        )}

                        {/* ===== LEFT DATA GRID — BALANCED WIDTH (~380px) ===== */}
                        <div className="flex flex-col w-[40%] max-w-[380px] min-w-[260px] border-r border-gray-200 overflow-hidden bg-white shrink-0">
                            <div className="flex-1 overflow-auto">
                                <table className="w-full text-left text-xs border-collapse">
                                    <thead>
                                        <tr className="border-b bg-[#f3f4f6] text-gray-600 select-none sticky top-0 z-1">
                                            <th
                                                onClick={() => setSortAsc(s => !s)}
                                                className="py-2 px-3 font-semibold text-[11px] border-r border-gray-200 cursor-pointer hover:text-[#0078d4] flex items-center justify-between"
                                            >
                                                <span>{h1}</span>
                                                <ArrowUpDown size={11} className="text-gray-400 shrink-0" />
                                            </th>
                                            <th className="py-2 px-2.5 font-semibold text-[11px] w-28">{h2}</th>
                                            {h3 && (
                                                <th className="py-2 px-2 font-semibold text-[11px] w-20">{h3}</th>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {/* Inline editable draft row */}
                                        {isNew && (
                                            <tr className="bg-[#e8f0fe] border-b border-[#0078d4]">
                                                <td className="py-1 px-2 border-r border-gray-200">
                                                    <input
                                                        value={form.name ?? form.plot_number ?? form.postal_code ?? ''}
                                                        onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value, plot_number: e.target.value, postal_code: e.target.value }))}
                                                        placeholder={activeSection === 'postalCodes' ? 'Kode pos (5 digit)...' : `Nama ${navItems.find(i => i.key === activeSection)?.label}...`}
                                                        className="h-6 w-full text-xs px-1.5 border border-[#0078d4] bg-white rounded font-medium"
                                                        autoFocus
                                                        onKeyDown={(e) => { if (e.key === 'Enter') handleSave(); }}
                                                    />
                                                </td>
                                                <td className="py-1 px-1.5">
                                                    {activeSection === 'regencies' ? (
                                                        <select
                                                            value={form.type || 'kabupaten'}
                                                            onChange={set('type')}
                                                            className="h-6 w-full text-xs px-1 border border-[#0078d4] bg-white rounded"
                                                        >
                                                            <option value="kabupaten">Kabupaten</option>
                                                            <option value="kota">Kota</option>
                                                        </select>
                                                    ) : activeSection === 'villages' ? (
                                                        <select
                                                            value={form.type || 'desa'}
                                                            onChange={set('type')}
                                                            className="h-6 w-full text-xs px-1 border border-[#0078d4] bg-white rounded"
                                                        >
                                                            <option value="desa">Desa</option>
                                                            <option value="kelurahan">Kelurahan</option>
                                                        </select>
                                                    ) : activeSection === 'postalCodes' ? (
                                                        <span className="text-gray-700 font-medium text-[11px] truncate">{activeParentVillage?.name || 'Area baru'}</span>
                                                    ) : (
                                                        <span className="text-gray-400 italic text-[11px]">Draft</span>
                                                    )}
                                                </td>
                                                {h3 && (
                                                    <td className="py-1 px-1.5">
                                                        {activeSection === 'postalCodes' ? (
                                                            <span className="text-[10px] text-gray-500 font-mono">RESMI</span>
                                                        ) : (
                                                            <input
                                                                value={form.postal_code ?? (activeParentVillage?.postal_code || '')}
                                                                onChange={(e) => setForm((prev) => ({ ...prev, postal_code: e.target.value, override_postal_code: '1' }))}
                                                                placeholder="Kode Pos..."
                                                                className="h-6 w-full text-xs px-1 border border-[#0078d4] bg-white rounded font-mono"
                                                            />
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        )}

                                        {isVillageLoading && activeSection === 'villages' ? (
                                            <tr>
                                                <td colSpan={h3 ? 3 : 2} className="py-12 text-center text-gray-400 text-xs">
                                                    <div className="flex items-center justify-center gap-2">
                                                        <Loader2 size={14} className="animate-spin text-[#0078d4]" />
                                                        <span>Memuat data desa/kelurahan...</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        ) : rows.length === 0 && !isNew ? (
                                            <tr>
                                                <td colSpan={h3 ? 3 : 2} className="py-12 text-center text-gray-400 text-xs">
                                                    No data available for this selection.
                                                </td>
                                            </tr>
                                        ) : rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                onClick={() => selectRow(row.id)}
                                                onDoubleClick={() => {
                                                    if (activeSection === 'countries') {
                                                        setFilterCountry(row.id);
                                                        setFilterProvince('');
                                                        setFilterRegency('');
                                                        setActiveSection('provinces');
                                                        executeFilter({ section: 'provinces', country: row.id, province_id: '', regency_id: '', district_id: '', village_id: '' });
                                                    } else if (activeSection === 'provinces') {
                                                        setFilterProvince(row.id);
                                                        setFilterRegency('');
                                                        setActiveSection('regencies');
                                                        executeFilter({ section: 'regencies', country: filterCountry, province_id: row.id, regency_id: '', district_id: '', village_id: '' });
                                                    } else if (activeSection === 'regencies') {
                                                        const regObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === row.id);
                                                        const targetProvId = regObj?.province_id || filterProvince;
                                                        setFilterProvince(targetProvId);
                                                        setFilterRegency(row.id);
                                                        setFilterDistrict('');
                                                        setFilterVillage('');
                                                        setActiveSection('districts');
                                                        executeFilter({ section: 'districts', country: filterCountry, province_id: targetProvId, regency_id: row.id, district_id: '', village_id: '' });
                                                    } else if (activeSection === 'districts') {
                                                        const distObj = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === row.id);
                                                        const targetRegId = distObj?.regency_id || filterRegency;
                                                        const regObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === targetRegId);
                                                        const targetProvId = regObj?.province_id || filterProvince;
                                                        setFilterProvince(targetProvId);
                                                        setFilterRegency(targetRegId);
                                                        setFilterDistrict(row.id);
                                                        setFilterVillage('');
                                                        setActiveSection('villages');
                                                        executeFilter({ section: 'villages', country: filterCountry, province_id: targetProvId, regency_id: targetRegId, district_id: row.id, village_id: '' });
                                                    } else if (activeSection === 'villages') {
                                                        const villObj = ((dropdowns?.villages ?? villages) ?? []).find(v => v.id === row.id);
                                                        const targetDistId = villObj?.district_id || filterDistrict;
                                                        const distObj = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === targetDistId);
                                                        const targetRegId = distObj?.regency_id || filterRegency;
                                                        const regObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === targetRegId);
                                                        const targetProvId = regObj?.province_id || filterProvince;
                                                        setFilterProvince(targetProvId);
                                                        setFilterRegency(targetRegId);
                                                        setFilterDistrict(targetDistId);
                                                        setFilterVillage(row.id);
                                                        setActiveSection('streets');
                                                        executeFilter({ section: 'streets', country: filterCountry, province_id: targetProvId, regency_id: targetRegId, district_id: targetDistId, village_id: row.id });
                                                    }
                                                }}
                                                className={[
                                                    'border-b border-gray-100 cursor-pointer h-7 transition-colors select-none',
                                                    selectedId === row.id
                                                        ? 'bg-[#deecf9] text-gray-900 font-medium'
                                                        : 'hover:bg-gray-50 text-gray-700',
                                                ].join(' ')}
                                            >
                                                <td className="py-1 px-3 border-r border-gray-100 text-[11px] font-medium text-gray-900 truncate font-mono">
                                                    {row.col1}
                                                </td>
                                                <td className="py-1 px-2.5 text-[11px] text-gray-600 truncate">
                                                    {row.col2}
                                                </td>
                                                {h3 && (
                                                    <td className="py-1 px-2 font-mono text-[11px] text-gray-500">
                                                        {row.col3 || '-'}
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination Controls for Villages */}
                            {activeSection === 'villages' && paginatedVillages && (
                                <div className="px-3 py-1.5 border-t border-gray-200 bg-[#f9fafb] flex items-center justify-between text-[11px] text-gray-500 select-none">
                                    <span>
                                        Total: <strong className="text-gray-700">{paginatedVillages.total}</strong> records
                                    </span>
                                    <div className="flex items-center gap-1">
                                        <button
                                            onClick={() => setVillagePage(p => Math.max(1, p - 1))}
                                            disabled={paginatedVillages.current_page <= 1}
                                            className="p-1 rounded hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer"
                                        >
                                            <ChevronLeft size={13} />
                                        </button>
                                        <span>
                                            {paginatedVillages.current_page} / {paginatedVillages.last_page || 1}
                                        </span>
                                        <button
                                            onClick={() => setVillagePage(p => Math.min(paginatedVillages.last_page, p + 1))}
                                            disabled={paginatedVillages.current_page >= paginatedVillages.last_page}
                                            className="p-1 rounded hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer"
                                        >
                                            <ChevronRight size={13} />
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* ===== RIGHT DETAIL FORM PANEL — NO HORIZONTAL SCROLL ===== */}
                        <div className="flex flex-1 flex-col min-w-0 overflow-y-auto overflow-x-hidden bg-white p-5">
                            {(selectedId || isNew) ? (
                                <div className="flex flex-col gap-5 max-w-full">
                                    {isNew && (
                                        <div className="flex items-center justify-between bg-blue-50/90 border border-blue-200 px-3.5 py-2 rounded shrink-0 shadow-2xs">
                                            <div className="flex items-center gap-2">
                                                <span className="relative flex h-2 w-2">
                                                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                                                    <span className="relative inline-flex rounded-full h-2 w-2 bg-[#0078d4]"></span>
                                                </span>
                                                <span className="font-semibold text-xs text-blue-900">
                                                    Mode Tambah: {levelLabels[activeSection] || activeSection} Baru
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={handleCancel}
                                                className="text-xs font-medium text-blue-700 hover:text-blue-900 underline cursor-pointer"
                                            >
                                                Batal
                                            </button>
                                        </div>
                                    )}

                                    {/* PARAMETERS FORM */}
                                    {activeSection === 'parameters' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="flex items-center justify-between border-b pb-2">
                                                <span className="font-semibold text-gray-800 text-xs">Pengaturan Parameter Wilayah</span>
                                                <select
                                                    value={f('country_code') || selectedId || 'ID'}
                                                    onChange={(e) => {
                                                        const cCode = e.target.value;
                                                        const param = (parameters ?? []).find(p => p.country_code === cCode);
                                                        if (param) {
                                                            setForm({
                                                                country_code: param.country_code,
                                                                use_province: param.use_province ? '1' : '0',
                                                                use_regency: param.use_regency ? '1' : '0',
                                                                use_district: param.use_district ? '1' : '0',
                                                                use_village: param.use_village ? '1' : '0',
                                                                use_rt_rw: param.use_rt_rw ? '1' : '0',
                                                                use_postal_code: param.use_postal_code ? '1' : '0',
                                                                use_building: param.use_building ? '1' : '0',
                                                                address_format: param.address_format ?? '',
                                                            });
                                                            setSelectedId(cCode);
                                                        }
                                                    }}
                                                    className="h-6.5 text-xs border border-gray-300 rounded px-1.5 bg-white font-medium"
                                                >
                                                    {(countries ?? []).map(c => (
                                                        <option key={c.code} value={c.code}>{c.name} ({c.code})</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs max-w-xl">
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_province') === '1' || f('use_province') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_province: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan Provinsi</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_regency') === '1' || f('use_regency') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_regency: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan Kabupaten / Kota</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_district') === '1' || f('use_district') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_district: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan Kecamatan</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_village') === '1' || f('use_village') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_village: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan Desa / Kelurahan</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_rt_rw') === '1' || f('use_rt_rw') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_rt_rw: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan RT / RW / Street</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_postal_code') === '1' || f('use_postal_code') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_postal_code: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan Kode Pos</span>
                                                </label>
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" checked={f('use_building') === '1' || f('use_building') === 'true'} onChange={(e) => setForm(prev => ({ ...prev, use_building: e.target.checked ? '1' : '0' }))} />
                                                    <span>Gunakan Gedung / Bangunan</span>
                                                </label>
                                            </div>
                                        </div>
                                    )}

                                    {/* ADDRESS FORMAT FORM WITH LIVE PREVIEW */}
                                    {activeSection === 'addressFormat' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="font-semibold text-gray-800 text-xs border-b pb-2">Pengaturan Format Alamat</div>

                                            <div className="grid grid-cols-1 gap-3 max-w-xl">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Country/region</label>
                                                    <select
                                                        value={f('country_code') || selectedId || 'ID'}
                                                        onChange={(e) => {
                                                            const cCode = e.target.value;
                                                            const param = (parameters ?? []).find(p => p.country_code === cCode);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                country_code: cCode,
                                                                address_format: param?.address_format || prev.address_format,
                                                            }));
                                                            setSelectedId(cCode);
                                                        }}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium text-gray-800 w-full"
                                                    >
                                                        {(countries ?? []).map(c => (
                                                            <option key={c.code} value={c.code}>{c.name} ({c.code})</option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Template Format Alamat</label>
                                                    <textarea
                                                        rows={3}
                                                        value={f('address_format') || '{street}, RT {rt}/RW {rw}, Kel. {village}, Kec. {district}, {regency}, {province} {postal_code}, {country}'}
                                                        onChange={(e) => setForm(prev => ({ ...prev, address_format: e.target.value }))}
                                                        className="text-xs border border-gray-300 rounded p-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                    <span className="text-[10px] text-gray-500 mt-0.5">
                                                        <strong>Variable yang didukung:</strong> {'{street}'}, {'{rt}'}, {'{rw}'}, {'{village}'}, {'{district}'}, {'{regency}'}, {'{province}'}, {'{postal_code}'}, {'{country}'}
                                                    </span>
                                                </div>

                                                {/* Live Address Preview Card */}
                                                <div className="border border-blue-200 bg-[#f0f7ff] rounded-lg p-3.5 flex flex-col gap-1.5 mt-2">
                                                    <span className="font-semibold text-[#0078d4] text-[11px]">Preview Alamat:</span>
                                                    <p className="text-gray-800 text-xs font-medium leading-relaxed bg-white border border-blue-100 rounded p-2.5 shadow-2xs">
                                                        {liveAddressPreview}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* COUNTRIES FORM */}
                                    {activeSection === 'countries' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3 max-w-2xl items-start">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Country Code</label>
                                                    <input
                                                        value={f('code') || selectedId || ''}
                                                        onChange={set('code')}
                                                        readOnly={!isNew}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-mono w-full ${!isNew ? 'bg-gray-50 cursor-not-allowed text-gray-700' : 'bg-white'}`}
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama Negara</label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">ISO3 Code</label>
                                                    <input
                                                        value={f('iso3')}
                                                        onChange={set('iso3')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Default Timezone</label>
                                                    <input
                                                        value={f('timezone') || 'Asia/Jakarta'}
                                                        onChange={set('timezone')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono w-full"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* PROVINCE / STATE FORM — MATCHING REFERENCE ARCHITECTURE */}
                                    {activeSection === 'provinces' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="font-semibold text-gray-800 text-xs border-b pb-2">
                                                {isNew ? `Tambah ${levelLabels.province} Baru` : `Informasi ${levelLabels.province} (State / Province)`}
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-3xl items-start">
                                                {/* Row 1 fields */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Country/region <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('country_code') || filterCountry || 'ID'}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const newCountry = e.target.value;
                                                            const autoTz = detectTimezone(newCountry, f('code') || f('state_code'));
                                                            setForm(prev => ({
                                                                ...prev,
                                                                country_code: newCountry,
                                                                timezone: autoTz,
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white text-gray-800 focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        {(countries ?? []).map((c) => (
                                                            <option key={c.code} value={c.code}>{c.name}</option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Description</label>
                                                    <input
                                                        value={f('description')}
                                                        onChange={set('description')}
                                                        placeholder="Deskripsi wilayah provinsi..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Intrastat code</label>
                                                    <input
                                                        value={f('intrastat')}
                                                        onChange={set('intrastat')}
                                                        placeholder="Kode intrastat..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex items-center gap-2 pt-2 sm:pt-4">
                                                    <label className="flex items-center gap-2 cursor-pointer select-none text-[11px] font-medium text-gray-700">
                                                        <input
                                                            type="checkbox"
                                                            checked={f('default_state') === '1' || f('default_state') === true}
                                                            onChange={(e) => setForm(prev => ({ ...prev, default_state: e.target.checked ? '1' : '0' }))}
                                                            className="rounded text-[#0078d4] focus:ring-[#0078d4]"
                                                        />
                                                        <span>Default state/province</span>
                                                    </label>
                                                </div>

                                                <div className="flex items-center gap-2 pt-2 sm:pt-4">
                                                    <label className="flex items-center gap-2 cursor-pointer select-none text-[11px] font-medium text-gray-700">
                                                        <input
                                                            type="checkbox"
                                                            checked={f('union_territory') === '1' || f('union_territory') === true}
                                                            onChange={(e) => setForm(prev => ({ ...prev, union_territory: e.target.checked ? '1' : '0' }))}
                                                            className="rounded text-[#0078d4] focus:ring-[#0078d4]"
                                                        />
                                                        <span>Union territory</span>
                                                    </label>
                                                </div>

                                                {/* Row 2 fields */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">State (Nama {levelLabels.province}) <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={(e) => {
                                                            const val = e.target.value;
                                                            setForm(prev => ({ ...prev, name: val }));
                                                        }}
                                                        placeholder="Contoh: Bali, Jawa Barat, Selangor..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Time zone</label>
                                                    <select
                                                        value={f('timezone') || detectTimezone(f('country_code') || filterCountry || 'ID', f('code') || f('state_code'))}
                                                        onChange={set('timezone')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none w-full font-mono text-gray-800"
                                                    >
                                                        <optgroup label="Indonesia">
                                                            <option value="Asia/Jakarta">(UTC+07:00) WIB — Asia/Jakarta</option>
                                                            <option value="Asia/Makassar">(UTC+08:00) WITA — Asia/Makassar</option>
                                                            <option value="Asia/Jayapura">(UTC+09:00) WIT — Asia/Jayapura</option>
                                                        </optgroup>
                                                        <optgroup label="Malaysia">
                                                            <option value="Asia/Kuala_Lumpur">(UTC+08:00) MYT — Asia/Kuala_Lumpur</option>
                                                        </optgroup>
                                                        <optgroup label="Lainnya">
                                                            <option value="UTC">(UTC+00:00) UTC</option>
                                                        </optgroup>
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">IT state code</label>
                                                    <input
                                                        value={f('it_state_code')}
                                                        onChange={set('it_state_code')}
                                                        placeholder="IT code..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">State code (Kemendagri / Resmi) <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={f('code') || f('state_code')}
                                                        onChange={(e) => {
                                                            const val = e.target.value;
                                                            const currentCountry = f('country_code') || filterCountry || 'ID';
                                                            const autoTz = detectTimezone(currentCountry, val);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                code: val,
                                                                state_code: val,
                                                                timezone: autoTz,
                                                            }));
                                                        }}
                                                        placeholder="Contoh: 51 atau 10"
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono font-medium text-gray-800 focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>
                                            </div>

                                            <div className="border-t border-gray-100 pt-3 flex flex-col gap-1.5">
                                                <div className="flex items-center gap-2 text-gray-500 text-[11px]">
                                                    <Info size={13} className="text-gray-400 shrink-0" />
                                                    <span>Time zone akan otomatis ditentukan berdasarkan State code resmi provinsi (WIB / WITA / WIT).</span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* REGENCY / COUNTY FORM — MATCHING REFERENCE ARCHITECTURE */}
                                    {activeSection === 'regencies' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="font-semibold text-gray-800 text-xs border-b pb-2">
                                                {isNew ? `Tambah ${levelLabels.regency} Baru` : `Informasi ${levelLabels.regency} (County / Regency)`}
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-3xl items-start">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Country/region <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('country_code') || filterCountry || 'ID'}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const newCountry = e.target.value;
                                                            setForm(prev => ({
                                                                ...prev,
                                                                country_code: newCountry,
                                                                province_id: '',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white text-gray-800 focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        {(countries ?? []).map((c) => (
                                                            <option key={c.code} value={c.code}>{c.name}</option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">State / {levelLabels.province} <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('province_id')}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const pId = e.target.value;
                                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === pId);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                province_id: pId,
                                                                country_code: pObj?.country_code || prev.country_code || 'ID',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium truncate w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        <option value="">-- Pilih {levelLabels.province} --</option>
                                                        {f('province_id') && !((dropdowns?.provinces ?? provinces) ?? []).some(p => p.id === f('province_id')) && (
                                                            <option value={f('province_id')}>{f('province_name') || lineageInfo?.province?.name || 'Provinsi Terkait'}</option>
                                                        )}
                                                        {((dropdowns?.provinces ?? provinces) ?? [])
                                                            .filter(p => !f('country_code') || p.country_code === f('country_code'))
                                                            .map((p) => (
                                                                <option key={p.id} value={p.id}>{p.name}</option>
                                                            ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">County / Nama {levelLabels.regency} <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        placeholder="Contoh: Kabupaten Badung, Kota Denpasar..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Description</label>
                                                    <input
                                                        value={f('description')}
                                                        onChange={set('description')}
                                                        placeholder="Keterangan / deskripsi wilayah..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Tipe</label>
                                                    <select
                                                        value={f('type') || 'kabupaten'}
                                                        onChange={set('type')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none w-full font-medium"
                                                    >
                                                        <option value="kabupaten">Kabupaten</option>
                                                        <option value="kota">Kota</option>
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Official Code (Kemendagri) <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={formatOfficialCode(f('code'), 'regencies')}
                                                        onChange={(e) => {
                                                            const val = e.target.value.replace(/[^\d.]/g, '');
                                                            setForm(prev => ({ ...prev, code: val.replace(/\./g, '') }));
                                                        }}
                                                        placeholder="Contoh: 5103 atau 51.03"
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono text-gray-800 focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">IT county code</label>
                                                    <input
                                                        value={f('it_county_code')}
                                                        onChange={set('it_county_code')}
                                                        placeholder="IT county code..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">ES county code</label>
                                                    <input
                                                        value={f('es_county_code')}
                                                        onChange={set('es_county_code')}
                                                        placeholder="ES county code..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* DISTRICT / KECAMATAN FORM — NEATLY ALIGNED GRID */}
                                    {activeSection === 'districts' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="font-semibold text-gray-800 text-xs border-b pb-2">
                                                {isNew ? `Tambah ${levelLabels.district} Baru` : `Informasi ${levelLabels.district} (District / Kecamatan)`}
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-3xl items-start">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Country/region <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('country_code') || filterCountry || 'ID'}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const newCountry = e.target.value;
                                                            setForm(prev => ({
                                                                ...prev,
                                                                country_code: newCountry,
                                                                province_id: '',
                                                                regency_id: '',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white text-gray-800 focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        {(countries ?? []).map((c) => (
                                                            <option key={c.code} value={c.code}>{c.name}</option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">State / {levelLabels.province} <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('province_id')}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const pId = e.target.value;
                                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === pId);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                province_id: pId,
                                                                country_code: pObj?.country_code || prev.country_code || 'ID',
                                                                regency_id: '',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium truncate w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        <option value="">-- Pilih {levelLabels.province} --</option>
                                                        {f('province_id') && !((dropdowns?.provinces ?? provinces) ?? []).some(p => p.id === f('province_id')) && (
                                                            <option value={f('province_id')}>{f('province_name') || lineageInfo?.province?.name || 'Provinsi Terkait'}</option>
                                                        )}
                                                        {((dropdowns?.provinces ?? provinces) ?? [])
                                                            .filter(p => !f('country_code') || p.country_code === f('country_code'))
                                                            .map((p) => (
                                                                <option key={p.id} value={p.id}>{p.name}</option>
                                                            ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">{levelLabels.regency} <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('regency_id')}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const rId = e.target.value;
                                                            const rObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === rId);
                                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === rObj?.province_id);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                regency_id: rId,
                                                                province_id: rObj?.province_id || prev.province_id,
                                                                country_code: pObj?.country_code || prev.country_code || 'ID',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium truncate w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        <option value="">-- Pilih {levelLabels.regency} --</option>
                                                        {f('regency_id') && !((dropdowns?.regencies ?? regencies) ?? []).some(r => r.id === f('regency_id')) && (
                                                            <option value={f('regency_id')}>{f('regency_name') || lineageInfo?.regency?.name || 'Kabupaten/Kota Terkait'}</option>
                                                        )}
                                                        {((dropdowns?.regencies ?? regencies) ?? [])
                                                            .filter(r => !f('province_id') || r.province_id === f('province_id'))
                                                            .map((r) => (
                                                                <option key={r.id} value={r.id}>{r.name}</option>
                                                            ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama {levelLabels.district} <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        placeholder="Contoh: Abiansemal, Kuta Selatan..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Official Code (Kemendagri) <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={formatOfficialCode(f('code'), 'districts')}
                                                        onChange={(e) => {
                                                            const val = e.target.value.replace(/[^\d.]/g, '');
                                                            setForm(prev => ({ ...prev, code: val.replace(/\./g, '') }));
                                                        }}
                                                        placeholder="Contoh: 510303 atau 51.03.03"
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono text-gray-800 focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* VILLAGE / DESA / KELURAHAN FORM — NEATLY ALIGNED GRID */}
                                    {activeSection === 'villages' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="font-semibold text-gray-800 text-xs border-b pb-2">
                                                {isNew ? `Tambah ${levelLabels.village} Baru` : `Informasi ${levelLabels.village} (Village / Desa)`}
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-3xl items-start">
                                                {/* Country Select */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Country/region <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('country_code') || filterCountry || 'ID'}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const newCountry = e.target.value;
                                                            setForm(prev => ({
                                                                ...prev,
                                                                country_code: newCountry,
                                                                province_id: '',
                                                                regency_id: '',
                                                                district_id: '',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white text-gray-800 focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        {(countries ?? []).map((c) => (
                                                            <option key={c.code} value={c.code}>{c.name}</option>
                                                        ))}
                                                    </select>
                                                </div>

                                                {/* Province Select */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">State / {levelLabels.province} <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('province_id')}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const pId = e.target.value;
                                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === pId);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                province_id: pId,
                                                                country_code: pObj?.country_code || prev.country_code || 'ID',
                                                                regency_id: '',
                                                                district_id: '',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium truncate w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        <option value="">-- Pilih {levelLabels.province} --</option>
                                                        {f('province_id') && !((dropdowns?.provinces ?? provinces) ?? []).some(p => p.id === f('province_id')) && (
                                                            <option value={f('province_id')}>{f('province_name') || lineageInfo?.province?.name || 'Provinsi Terkait'}</option>
                                                        )}
                                                        {((dropdowns?.provinces ?? provinces) ?? [])
                                                            .filter(p => !f('country_code') || p.country_code === f('country_code'))
                                                            .map((p) => (
                                                                <option key={p.id} value={p.id}>{p.name}</option>
                                                            ))}
                                                    </select>
                                                </div>

                                                {/* Regency Select */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">{levelLabels.regency} <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('regency_id')}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const rId = e.target.value;
                                                            const rObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === rId);
                                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === rObj?.province_id);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                regency_id: rId,
                                                                province_id: rObj?.province_id || prev.province_id,
                                                                country_code: pObj?.country_code || prev.country_code || 'ID',
                                                                district_id: '',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium truncate w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        <option value="">-- Pilih {levelLabels.regency} --</option>
                                                        {f('regency_id') && !((dropdowns?.regencies ?? regencies) ?? []).some(r => r.id === f('regency_id')) && (
                                                            <option value={f('regency_id')}>{f('regency_name') || lineageInfo?.regency?.name || 'Kabupaten/Kota Terkait'}</option>
                                                        )}
                                                        {((dropdowns?.regencies ?? regencies) ?? [])
                                                            .filter(r => !f('province_id') || r.province_id === f('province_id'))
                                                            .map((r) => (
                                                                <option key={r.id} value={r.id}>{r.name}</option>
                                                            ))}
                                                    </select>
                                                </div>

                                                {/* District Select */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">{levelLabels.district} <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('district_id')}
                                                        disabled={!isNew}
                                                        onChange={(e) => {
                                                            const dId = e.target.value;
                                                            const dObj = ((dropdowns?.districts ?? districts) ?? []).find(d => d.id === dId);
                                                            const rObj = ((dropdowns?.regencies ?? regencies) ?? []).find(r => r.id === dObj?.regency_id);
                                                            const pObj = ((dropdowns?.provinces ?? provinces) ?? []).find(p => p.id === rObj?.province_id);
                                                            setForm(prev => ({
                                                                ...prev,
                                                                district_id: dId,
                                                                regency_id: dObj?.regency_id || prev.regency_id,
                                                                province_id: rObj?.province_id || prev.province_id,
                                                                country_code: pObj?.country_code || prev.country_code || 'ID',
                                                            }));
                                                        }}
                                                        className={`h-7 text-xs border border-gray-300 rounded px-2 font-medium truncate w-full ${!isNew ? 'bg-gray-50 text-gray-700 cursor-not-allowed' : 'bg-white focus:border-[#0078d4] focus:outline-none'}`}
                                                    >
                                                        <option value="">-- Pilih {levelLabels.district} --</option>
                                                        {f('district_id') && !((dropdowns?.districts ?? districts) ?? []).some(d => d.id === f('district_id')) && (
                                                            <option value={f('district_id')}>{f('district_name') || lineageInfo?.district?.name || 'Kecamatan Terkait'}</option>
                                                        )}
                                                        {((dropdowns?.districts ?? districts) ?? [])
                                                            .filter(d => !f('regency_id') || d.regency_id === f('regency_id'))
                                                            .map((d) => (
                                                                <option key={d.id} value={d.id}>{d.name}</option>
                                                            ))}
                                                    </select>
                                                </div>

                                                {/* Village Name */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama {levelLabels.village} <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        placeholder="Contoh: Benoa, Jimbaran, Ampel..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>

                                                {/* Village Type */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Tipe <span className="text-red-500">*</span></label>
                                                    <select
                                                        value={f('type') || 'desa'}
                                                        onChange={set('type')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    >
                                                        <option value="desa">Desa</option>
                                                        <option value="kelurahan">Kelurahan</option>
                                                        <option value="mukim">Mukim</option>
                                                        <option value="kampung">Kampung</option>
                                                        <option value="bandar">Bandar</option>
                                                    </select>
                                                </div>

                                                {/* Official Code */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Official Code (Kemendagri) <span className="text-red-500">*</span></label>
                                                    <input
                                                        value={formatOfficialCode(f('code'), 'villages')}
                                                        onChange={(e) => {
                                                            const val = e.target.value.replace(/[^\d.]/g, '');
                                                            setForm(prev => ({ ...prev, code: val.replace(/\./g, '') }));
                                                        }}
                                                        placeholder="Contoh: 51.03.05.1001 atau 5103051001"
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono text-gray-800 focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                {/* Postal Code (ONLY on Village level!) */}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-700 font-semibold flex items-center gap-1.5">
                                                        <Mail size={12} className="text-[#0078d4]" />
                                                        <span>Kode Pos Resmi (5 Digit) <span className="text-red-500">*</span></span>
                                                    </label>
                                                    <input
                                                        value={f('postal_code')}
                                                        onChange={(e) => {
                                                            const val = e.target.value.replace(/\D/g, '').slice(0, 5);
                                                            setForm(prev => ({ ...prev, postal_code: val }));
                                                        }}
                                                        placeholder="Contoh: 80361"
                                                        maxLength={5}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono font-semibold text-gray-900 focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                    <span className="text-[9px] text-gray-400">5 digit angka resmi Pos Indonesia</span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* STREETS / RT / RW FORM — NEATLY ALIGNED GRID */}
                                    {activeSection === 'streets' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-2xl items-start">
                                                <div className="flex flex-col gap-1 sm:col-span-2">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama Jalan / Blok</label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">RT</label>
                                                    <input
                                                        value={f('rt')}
                                                        onChange={set('rt')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">RW</label>
                                                    <input
                                                        value={f('rw')}
                                                        onChange={set('rw')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono w-full"
                                                    />
                                                </div>
                                            </div>

                                        </div>
                                    )}

                                    {/* GROUP OF HOUSES FORM */}
                                    {activeSection === 'groupOfHouses' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-2xl items-start">
                                                <div className="flex flex-col gap-1 sm:col-span-2">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama Group of Houses</label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Kode Group</label>
                                                    <input
                                                        value={f('code')}
                                                        onChange={set('code')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Status</label>
                                                    <select value={f('status') || 'active'} onChange={set('status')} className="h-7 text-xs border border-gray-300 rounded px-2 bg-white w-full">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* LAND PLOTS FORM */}
                                    {activeSection === 'landPlots' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-2xl items-start">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nomor Plot</label>
                                                    <input
                                                        value={f('plot_number')}
                                                        onChange={set('plot_number')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1 sm:col-span-2">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama / Keterangan</label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Status</label>
                                                    <select value={f('status') || 'active'} onChange={set('status')} className="h-7 text-xs border border-gray-300 rounded px-2 bg-white w-full">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* BUILDINGS FORM */}
                                    {activeSection === 'buildings' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-4 gap-y-3.5 max-w-2xl items-start">
                                                <div className="flex flex-col gap-1 sm:col-span-2 lg:col-span-4">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama Gedung / Bangunan</label>
                                                    <input
                                                        value={f('name')}
                                                        onChange={set('name')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none font-medium w-full"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Blok</label>
                                                    <input value={f('block')} onChange={set('block')} className="h-7 text-xs border border-gray-300 rounded px-2 bg-white w-full" />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Unit</label>
                                                    <input value={f('unit')} onChange={set('unit')} className="h-7 text-xs border border-gray-300 rounded px-2 bg-white w-full" />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Lantai</label>
                                                    <input value={f('floor')} onChange={set('floor')} className="h-7 text-xs border border-gray-300 rounded px-2 bg-white w-full" />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* POSTAL CODES MASTER FORM */}
                                    {activeSection === 'postalCodes' && (
                                        <div className="flex flex-col gap-4 max-w-full">
                                            <div className="font-semibold text-gray-800 text-xs border-b pb-2 flex items-center gap-2">
                                                <Mail size={14} className="text-[#0078d4]" />
                                                <span>Detail Master Kode Pos</span>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-3.5 max-w-2xl items-start">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Kode Pos</label>
                                                    <input
                                                        value={f('postal_code')}
                                                        onChange={set('postal_code')}
                                                        placeholder="80361"
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono focus:border-[#0078d4] focus:outline-none font-semibold text-gray-900 w-full"
                                                    />
                                                    <span className="text-[9px] text-gray-400">Standar resmi (5 digit ID)</span>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Desa / Kelurahan</label>
                                                    <select
                                                        value={f('village_id')}
                                                        onChange={set('village_id')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none truncate font-medium w-full"
                                                    >
                                                        <option value="">-- Pilih Desa/Kelurahan --</option>
                                                        {((dropdowns?.villages ?? villages) ?? []).map((v) => (
                                                            <option key={v.id} value={v.id}>{v.name}</option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Nama Area / Cakupan</label>
                                                    <input
                                                        value={f('area_name')}
                                                        onChange={set('area_name')}
                                                        placeholder="Area/Wilayah..."
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white focus:border-[#0078d4] focus:outline-none w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Sumber Data Resmi</label>
                                                    <input
                                                        value={f('source') || 'POS_INDONESIA'}
                                                        onChange={set('source')}
                                                        className="h-7 text-xs border border-gray-300 rounded px-2 bg-white font-mono text-gray-700 w-full"
                                                    />
                                                </div>

                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[11px] text-gray-500 font-medium">Status</label>
                                                    <select value={f('status') || 'active'} onChange={set('status')} className="h-7 text-xs border border-gray-300 rounded px-2 bg-white w-full">
                                                        <option value="active">Active</option>
                                                        <option value="inactive">Inactive</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* GENERAL HIERARCHY LINEAGE & TIMEZONE RESOLVER CARD */}
                                    {lineageInfo && (
                                        <div className="border border-gray-200 rounded-lg p-3.5 bg-[#f8f9fa] max-w-full overflow-hidden">
                                            <div className="font-semibold text-gray-700 text-xs mb-2 flex items-center gap-1.5">
                                                <Layers size={13} className="text-[#0078d4] shrink-0" />
                                                <span>Hierarchy Lineage & Metadata</span>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[11px]">
                                                <div>
                                                    <span className="text-gray-500 block text-[10px]">Hierarchy Path:</span>
                                                    <span className="text-gray-900 font-medium break-words">{lineageInfo.formatted}</span>
                                                </div>
                                                <div>
                                                    <span className="text-gray-500 block text-[10px]">Timezone Otomatis:</span>
                                                    <span className="text-emerald-700 font-medium font-mono">
                                                        {resolvedTz?.display_name || 'Asia/Jakarta'}
                                                    </span>
                                                </div>
                                                {form.code && (
                                                    <div>
                                                        <span className="text-gray-500 block text-[10px]">Official Code (Kemendagri / BPS):</span>
                                                        <span className="text-gray-700 font-mono font-medium">
                                                            {formatOfficialCode(form.code, activeSection)}
                                                        </span>
                                                    </div>
                                                )}
                                                {activeSection === 'villages' && (
                                                    <div>
                                                        <span className="text-gray-500 block text-[10px]">Kode Pos Terintegrasi:</span>
                                                        <span className="text-gray-900 font-mono font-semibold">
                                                            {form.postal_code || lineageInfo.village?.postal_code || activeParentVillage?.postal_code || 'Belum tersedia'}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center flex-1 text-gray-400 gap-2">
                                    <MapPin size={28} className="stroke-1 text-gray-300" />
                                    <span>Pilih baris pada tabel di sebelah kiri untuk melihat dan mengedit detail.</span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* ===== EXTERNAL CODES MODAL ===== */}
            {showExternalCodesModal && (
                <div className="fixed inset-0 bg-black/40 backdrop-blur-xs flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-lg overflow-hidden border">
                        <div className="px-4 py-3 border-b flex items-center justify-between bg-[#fafafa]">
                            <div className="flex items-center gap-2">
                                <ExternalLink size={15} className="text-[#0078d4]" />
                                <span className="font-semibold text-xs text-gray-800 truncate">
                                    External Codes — {form.name || form.code || form.postal_code}
                                </span>
                            </div>
                            <button onClick={() => setShowExternalCodesModal(false)} className="text-gray-400 hover:text-gray-600 cursor-pointer">
                                <X size={15} />
                            </button>
                        </div>
                        <div className="p-4 flex flex-col gap-4 max-h-[60vh] overflow-auto">
                            {isModalLoading ? (
                                <div className="py-8 text-center text-gray-400 flex items-center justify-center gap-2">
                                    <Loader2 size={16} className="animate-spin text-[#0078d4]" />
                                    <span>Memuat external codes...</span>
                                </div>
                            ) : (
                                <>
                                    <table className="w-full text-xs text-left border-collapse">
                                        <thead>
                                            <tr className="border-b bg-gray-50 text-gray-500">
                                                <th className="p-2">System</th>
                                                <th className="p-2">External Code</th>
                                                <th className="p-2">Description</th>
                                                <th className="p-2 w-12">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {externalCodesList.length === 0 ? (
                                                <tr>
                                                    <td colSpan={4} className="py-6 text-center text-gray-400">
                                                        Belum ada external code. Tambahkan di bawah.
                                                    </td>
                                                </tr>
                                            ) : externalCodesList.map((item) => (
                                                <tr key={item.id} className="border-b hover:bg-gray-50">
                                                    <td className="p-2 font-medium">{item.system}</td>
                                                    <td className="p-2 font-mono">{item.external_code}</td>
                                                    <td className="p-2 text-gray-500">{item.description || '-'}</td>
                                                    <td className="p-2">
                                                        <button
                                                            onClick={async () => {
                                                                try {
                                                                    const res = await fetch(`/settings/address-setup/external-codes/${item.id}`, {
                                                                        method: 'DELETE',
                                                                        headers: {
                                                                            'Accept': 'application/json',
                                                                            'X-Requested-With': 'XMLHttpRequest',
                                                                            'X-XSRF-TOKEN': getXsrfToken(),
                                                                        },
                                                                    });
                                                                    if (res.ok) {
                                                                        showToast('External code berhasil dihapus.');
                                                                        if (selectedId) fetchExternalCodes(selectedId);
                                                                    } else {
                                                                        showToast('Gagal menghapus external code.', 'error');
                                                                    }
                                                                } catch {
                                                                    showToast('Gagal terhubung ke server.', 'error');
                                                                }
                                                            }}
                                                            className="text-red-500 hover:text-red-700 cursor-pointer"
                                                        >
                                                            <Trash2 size={12} />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>

                                    {/* Add new external code form */}
                                    <div className="border-t pt-3 flex flex-col gap-2 bg-gray-50 p-3 rounded">
                                        <span className="font-semibold text-[11px] text-gray-700">Tambah External Code:</span>
                                        <div className="flex gap-2">
                                            <select id="ext_system" className="h-7 text-xs border rounded px-2 bg-white w-32">
                                                <option value="KEMENDAGRI">KEMENDAGRI</option>
                                                <option value="BPS">BPS</option>
                                                <option value="ISO">ISO</option>
                                                <option value="POSTAL">POSTAL</option>
                                            </select>
                                            <input id="ext_code" placeholder="Kode Eksternal..." className="h-7 text-xs border rounded px-2 bg-white flex-1 font-mono" />
                                            <input id="ext_desc" placeholder="Keterangan..." className="h-7 text-xs border rounded px-2 bg-white flex-1" />
                                            <button
                                                onClick={async () => {
                                                    const sys = (document.getElementById('ext_system') as HTMLSelectElement).value;
                                                    const code = (document.getElementById('ext_code') as HTMLInputElement).value?.trim();
                                                    const desc = (document.getElementById('ext_desc') as HTMLInputElement).value?.trim();
                                                    if (!code || !selectedId) {
                                                        showToast('Lengkapi kode eksternal terlebih dahulu.', 'error');
                                                        return;
                                                    }
                                                    try {
                                                        const res = await fetch('/settings/address-setup/external-codes', {
                                                            method: 'POST',
                                                            headers: {
                                                                'Content-Type': 'application/json',
                                                                'Accept': 'application/json',
                                                                'X-Requested-With': 'XMLHttpRequest',
                                                                'X-XSRF-TOKEN': getXsrfToken(),
                                                            },
                                                            body: JSON.stringify({ division_id: selectedId, system: sys, external_code: code, description: desc }),
                                                        });
                                                        if (res.ok) {
                                                            const inputCode = document.getElementById('ext_code') as HTMLInputElement;
                                                            const inputDesc = document.getElementById('ext_desc') as HTMLInputElement;
                                                            if (inputCode) inputCode.value = '';
                                                            if (inputDesc) inputDesc.value = '';
                                                            showToast('External code berhasil disimpan.');
                                                            fetchExternalCodes(selectedId);
                                                        } else {
                                                            const errData = await res.json().catch(() => ({}));
                                                            showToast(errData.message || 'Gagal menyimpan external code.', 'error');
                                                        }
                                                    } catch {
                                                        showToast('Gagal terhubung ke server.', 'error');
                                                    }
                                                }}
                                                className="px-3 h-7 bg-[#0078d4] text-white rounded text-xs font-medium hover:bg-[#106ebe] cursor-pointer"
                                            >
                                                Tambah
                                            </button>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                        <div className="px-4 py-2.5 bg-gray-50 border-t flex justify-end">
                            <button
                                onClick={() => setShowExternalCodesModal(false)}
                                className="px-4 py-1 border rounded bg-white text-xs font-medium hover:bg-gray-50 text-gray-700 cursor-pointer"
                            >
                                Tutup
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ===== TRANSLATIONS MODAL ===== */}
            {showTranslationsModal && (
                <div className="fixed inset-0 bg-black/40 backdrop-blur-xs flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-lg overflow-hidden border">
                        <div className="px-4 py-3 border-b flex items-center justify-between bg-[#fafafa]">
                            <div className="flex items-center gap-2">
                                <Languages size={15} className="text-[#0078d4]" />
                                <span className="font-semibold text-xs text-gray-800 truncate">
                                    Translations — {form.name || form.code || form.postal_code}
                                </span>
                            </div>
                            <button onClick={() => setShowTranslationsModal(false)} className="text-gray-400 hover:text-gray-600 cursor-pointer">
                                <X size={15} />
                            </button>
                        </div>
                        <div className="p-4 flex flex-col gap-4 max-h-[60vh] overflow-auto">
                            {isModalLoading ? (
                                <div className="py-8 text-center text-gray-400 flex items-center justify-center gap-2">
                                    <Loader2 size={16} className="animate-spin text-[#0078d4]" />
                                    <span>Memuat translations...</span>
                                </div>
                            ) : (
                                <>
                                    <table className="w-full text-xs text-left border-collapse">
                                        <thead>
                                            <tr className="border-b bg-gray-50 text-gray-500">
                                                <th className="p-2 w-20">Locale</th>
                                                <th className="p-2">Translated Name</th>
                                                <th className="p-2">Description</th>
                                                <th className="p-2 w-12">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {translationsList.length === 0 ? (
                                                <tr>
                                                    <td colSpan={4} className="py-6 text-center text-gray-400">
                                                        Belum ada terjemahan. Tambahkan di bawah.
                                                    </td>
                                                </tr>
                                            ) : translationsList.map((item) => (
                                                <tr key={item.id} className="border-b hover:bg-gray-50">
                                                    <td className="p-2 font-mono font-medium uppercase">{item.locale}</td>
                                                    <td className="p-2 font-medium">{item.name}</td>
                                                    <td className="p-2 text-gray-500">{item.description || '-'}</td>
                                                    <td className="p-2">
                                                        <button
                                                            onClick={async () => {
                                                                try {
                                                                    const res = await fetch(`/settings/address-setup/translations/${item.id}`, {
                                                                        method: 'DELETE',
                                                                        headers: {
                                                                            'Accept': 'application/json',
                                                                            'X-Requested-With': 'XMLHttpRequest',
                                                                            'X-XSRF-TOKEN': getXsrfToken(),
                                                                        },
                                                                    });
                                                                    if (res.ok) {
                                                                        showToast('Translation berhasil dihapus.');
                                                                        if (selectedId) fetchTranslations(selectedId);
                                                                    } else {
                                                                        showToast('Gagal menghapus translation.', 'error');
                                                                    }
                                                                } catch {
                                                                    showToast('Gagal terhubung ke server.', 'error');
                                                                }
                                                            }}
                                                            className="text-red-500 hover:text-red-700 cursor-pointer"
                                                        >
                                                            <Trash2 size={12} />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>

                                    {/* Add new translation form */}
                                    <div className="border-t pt-3 flex flex-col gap-2 bg-gray-50 p-3 rounded">
                                        <span className="font-semibold text-[11px] text-gray-700">Tambah Terjemahan:</span>
                                        <div className="flex gap-2">
                                            <select id="tr_locale" className="h-7 text-xs border rounded px-2 bg-white w-24">
                                                <option value="en">EN (English)</option>
                                                <option value="id">ID (Bahasa)</option>
                                                <option value="ms">MS (Melayu)</option>
                                            </select>
                                            <input id="tr_name" placeholder="Nama Terjemahan..." className="h-7 text-xs border rounded px-2 bg-white flex-1 font-medium" />
                                            <input id="tr_desc" placeholder="Keterangan..." className="h-7 text-xs border rounded px-2 bg-white flex-1" />
                                            <button
                                                onClick={async () => {
                                                    const loc = (document.getElementById('tr_locale') as HTMLSelectElement).value;
                                                    const name = (document.getElementById('tr_name') as HTMLInputElement).value?.trim();
                                                    const desc = (document.getElementById('tr_desc') as HTMLInputElement).value?.trim();
                                                    if (!name || !selectedId) {
                                                        showToast('Lengkapi nama terjemahan terlebih dahulu.', 'error');
                                                        return;
                                                    }
                                                    try {
                                                        const res = await fetch('/settings/address-setup/translations', {
                                                            method: 'POST',
                                                            headers: {
                                                                'Content-Type': 'application/json',
                                                                'Accept': 'application/json',
                                                                'X-Requested-With': 'XMLHttpRequest',
                                                                'X-XSRF-TOKEN': getXsrfToken(),
                                                            },
                                                            body: JSON.stringify({ division_id: selectedId, locale: loc, name, description: desc }),
                                                        });
                                                        if (res.ok) {
                                                            const inputName = document.getElementById('tr_name') as HTMLInputElement;
                                                            const inputDesc = document.getElementById('tr_desc') as HTMLInputElement;
                                                            if (inputName) inputName.value = '';
                                                            if (inputDesc) inputDesc.value = '';
                                                            showToast('Translation berhasil disimpan.');
                                                            fetchTranslations(selectedId);
                                                        } else {
                                                            const errData = await res.json().catch(() => ({}));
                                                            showToast(errData.message || 'Gagal menyimpan translation.', 'error');
                                                        }
                                                    } catch {
                                                        showToast('Gagal terhubung ke server.', 'error');
                                                    }
                                                }}
                                                className="px-3 h-7 bg-[#0078d4] text-white rounded text-xs font-medium hover:bg-[#106ebe] cursor-pointer"
                                            >
                                                Tambah
                                            </button>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                        <div className="px-4 py-2.5 bg-gray-50 border-t flex justify-end">
                            <button
                                onClick={() => setShowTranslationsModal(false)}
                                className="px-4 py-1 border rounded bg-white text-xs font-medium hover:bg-gray-50 text-gray-700 cursor-pointer"
                            >
                                Tutup
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ===== DELETE CONFIRMATION MODAL ===== */}
            {deleteConfirmTarget && (
                <div className="fixed inset-0 bg-black/40 backdrop-blur-xs flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-sm overflow-hidden border border-gray-200">
                        <div className="px-4 py-3 border-b flex items-center justify-between bg-red-50/50">
                            <div className="flex items-center gap-2 text-red-700">
                                <Trash2 size={16} />
                                <span className="font-semibold text-xs">Konfirmasi Hapus Data</span>
                            </div>
                            <button
                                onClick={() => setDeleteConfirmTarget(null)}
                                disabled={isDeleting}
                                className="text-gray-400 hover:text-gray-600 cursor-pointer"
                            >
                                <X size={15} />
                            </button>
                        </div>
                        <div className="p-4 flex flex-col gap-2.5">
                            <p className="text-xs text-gray-700">
                                Apakah Anda yakin ingin menghapus data <strong>"{deleteConfirmTarget.name}"</strong>?
                            </p>
                            <div className="text-[11px] text-amber-800 bg-amber-50 p-2.5 rounded border border-amber-200 flex items-start gap-1.5">
                                <Info size={14} className="shrink-0 text-amber-600 mt-0.5" />
                                <span>Data yang masih memiliki relasi anak/turunan akan dilindungi dan tidak dapat dihapus secara sembarangan.</span>
                            </div>
                        </div>
                        <div className="px-4 py-2.5 bg-gray-50 border-t flex justify-end gap-2">
                            <button
                                onClick={() => setDeleteConfirmTarget(null)}
                                disabled={isDeleting}
                                className="px-3 py-1 border border-gray-300 rounded bg-white text-xs font-medium hover:bg-gray-50 text-gray-700 cursor-pointer"
                            >
                                Batal
                            </button>
                            <button
                                onClick={executeDelete}
                                disabled={isDeleting}
                                className="flex items-center gap-1.5 px-3 py-1 bg-red-600 text-white rounded text-xs font-medium hover:bg-red-700 active:bg-red-800 disabled:opacity-40 cursor-pointer"
                            >
                                {isDeleting ? <Loader2 size={12} className="animate-spin" /> : <Trash2 size={12} />}
                                <span>Ya, Hapus</span>
                            </button>
                        </div>
                    </div>
                </div>
            )}

        </div>
    );
}
