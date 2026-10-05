export type OperatingUnit = { type: string; number: string | null };
export type Organization = {
    id: string;
    name: string;
    classification: 'legal_entity' | 'operating_unit';
    status: string;
    version: number;
    legal_entity: {
        company_code: string;
        country_code: string;
        timezone: string;
    } | null;
    operating_unit: OperatingUnit | null;
};
export type Purpose = {
    code: string;
    name: string;
    description: string;
    allowed_organization_types?: { organization_type: string }[];
};
export type HierarchyNode = {
    id: string;
    organization: Pick<Organization, 'id' | 'name' | 'classification'> & {
        operating_unit?: OperatingUnit | null;
    };
    parent_node: {
        id: string;
        organization: Pick<Organization, 'id' | 'name'>;
    } | null;
};
export type Version = {
    id: string;
    version_number: number;
    status: 'draft' | 'published';
    effective_from: string;
    nodes: HierarchyNode[];
};
export type Hierarchy = {
    id: string;
    name: string;
    status: string;
    /** Versi baris hierarchy; setiap perubahan pada versi mana pun mengirimnya. */
    version: number;
    purposes: Purpose[];
    versions: Version[];
};
export type Props = {
    canManage: boolean;
    organizations: Organization[];
    hierarchies: Hierarchy[];
    purposes: Purpose[];
    operatingUnitTypes: Record<string, string>;
};
