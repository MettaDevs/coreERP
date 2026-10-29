export type ReferenceValue = { code: string; name: string };

export function partyTypeLabel(code: string, types: ReferenceValue[]): string {
    return code === 'legal_entity'
        ? 'Perusahaan'
        : (types.find((type) => type.code === code)?.name ?? code);
}
export type RelationshipType = ReferenceValue & {
    inverse_name: string;
    from_type: string;
    to_type: string;
};
export type AddressBook = {
    id: string;
    name: string;
    version: number;
    deleted_at?: string | null;
};
export type PartyProfile = {
    tax_number: string | null;
    known_as: string | null;
    language: string | null;
    personal_title: string | null;
    personal_suffix: string | null;
    first_name: string | null;
    middle_name: string | null;
    last_name: string | null;
    initials: string | null;
    professional_title: string | null;
    professional_suffix: string | null;
    gender: string | null;
    marital_status: string | null;
    organization_number: string | null;
    duns_number: string | null;
    abc_code: string | null;
    birth_date: string | null;
    anniversary_date: string | null;
    number_of_children: number | null;
    number_of_employees: number | null;
    interests: string | null;
    memo: string | null;
};
export type Party = PartyProfile & {
    id: string;
    number: string | null;
    type: string;
    name: string;
    search_name: string;
    status: string;
    version: number;
    deleted_at: string | null;
    city: string | null;
    address: string | null;
    roles: { code: string; company: string | null; legal_entity_id: string }[];
};
export type PartyRelationship = {
    id: string;
    version: number;
    from_party_id: string;
    to_party_id: string;
    from_name: string;
    to_name: string;
    relationship_type_code: string;
    valid_from: string;
    valid_to: string | null;
};
export type PartyDetail = Party & {
    address_book_ids: string[];
    address_books: AddressBook[];
    organization_id: string | null;
    relationships: PartyRelationship[];
    vendors: {
        id: string;
        number: string;
        company: string | null;
        legal_entity_id: string;
        status: string;
    }[];
};
export type DirectoryMetadata = {
    partyTypes: ReferenceValue[];
    referenceValues: Record<string, ReferenceValue[]>;
    relationshipTypes: RelationshipType[];
    canManage: boolean;
    canManageVendors: boolean;
    canReadVendors: boolean;
};
