export type Country = {
    code: string;
    iso3: string | null;
    name: string;
    phone_code: string | null;
    timezone?: string | null;
    active: boolean;
};

export type Province = {
    id: string;
    country_code: string;
    code: string;
    display_code?: string | null;
    name: string;
    description?: string | null;
    timezone?: string | null;
    intrastat?: string | null;
    it_state_code?: string | null;
    state_code?: string | null;
    default_state?: boolean;
    union_territory?: boolean;
    active: boolean;
};

export type Regency = {
    id: string;
    province_id: string;
    country_code?: string | null;
    province?: Province | null;
    code: string;
    display_code?: string | null;
    name: string;
    description?: string | null;
    type: string;
    it_county_code?: string | null;
    es_county_code?: string | null;
    active: boolean;
};

export type District = {
    id: string;
    regency_id: string;
    regency?: Regency | null;
    code: string;
    display_code?: string | null;
    name: string;
    active: boolean;
};

export type Village = {
    id: string;
    district_id: string;
    code: string;
    display_code?: string | null;
    name: string;
    type: string;
    postal_code: string | null;
    active: boolean;
};

export type Street = {
    id: string;
    village_id: string;
    rt: string | null;
    rw: string | null;
    name: string | null;
    postal_code?: string | null;
    override_postal_code?: boolean;
    active: boolean;
};

export type GroupOfHouse = {
    id: string;
    village_id: string;
    code?: string | null;
    name: string;
    postal_code?: string | null;
    override_postal_code?: boolean;
    status: string;
    active: boolean;
};

export type LandPlot = {
    id: string;
    village_id: string;
    street_id?: string | null;
    group_of_houses_id?: string | null;
    plot_number: string;
    name?: string | null;
    postal_code?: string | null;
    override_postal_code?: boolean;
    status: string;
    active: boolean;
};

export type Building = {
    id: string;
    village_id: string;
    street_id: string | null;
    name: string;
    block: string | null;
    unit: string | null;
    floor: string | null;
    postal_code?: string | null;
    override_postal_code?: boolean;
    active: boolean;
};

export type PostalCode = {
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

export type Parameter = {
    country_code: string;
    use_province: boolean;
    use_regency: boolean;
    use_district: boolean;
    use_village: boolean;
    use_rt_rw: boolean;
    use_postal_code: boolean;
    use_building: boolean;
    address_format: string | null;
};

export type HierarchyLevel = {
    id: string;
    country_code: string;
    level: number;
    level_code: string;
    level_name: string;
    description?: string | null;
};

export interface ResolvedTimezone {
    timezone: string;
    offset: string;
    label: string;
    display_name: string;
    source_division_id: string;
    source_division_type: string;
    source_division_name: string;
}

export interface LineageData {
    country?: { code: string; name: string } | null;
    province?: { id: string; code: string; name: string } | null;
    regency?: { id: string; code: string; name: string; type: string } | null;
    district?: { id: string; code: string; name: string } | null;
    village?: {
        id: string;
        code: string;
        name: string;
        type: string;
        postal_code: string | null;
    } | null;
    timezone?: ResolvedTimezone | null;
    lineage?: Record<string, string>;
    formatted?: string;
}

export type ExternalCode = {
    id: string;
    division_id: string;
    system: string;
    external_code: string;
    description?: string | null;
    status: string;
};

export type TranslationItem = {
    id: string;
    division_id: string;
    locale: string;
    name: string;
    description?: string | null;
};

export type Section =
    | 'parameters'
    | 'addressFormat'
    | 'countries'
    | 'provinces'
    | 'regencies'
    | 'cities'
    | 'districts'
    | 'villages'
    | 'streets'
    | 'groupOfHouses'
    | 'landPlots'
    | 'buildings'
    | 'postalCodes';

export interface Props {
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
        countries?: Country[];
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
    filters: {
        country: string;
        province: string;
        regency: string;
        district: string;
        village: string;
    };
    selectedId?: string | null;
    flash?: {
        status?: string;
        error?: string;
        saved_id?: string;
        saved_section?: string;
    };
}
