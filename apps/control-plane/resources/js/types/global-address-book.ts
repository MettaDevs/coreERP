export type PartyType = 'person' | 'organization';

export interface AddressItem {
    id: string;
    location_id?: string;
    description: string;
    purpose: string;
    country: string;
    postal_code?: string;
    street: string;
    street_number?: string;
    building_complement?: string;
    building?: string;
    post_box?: string;
    city: string;
    district?: string;
    state: string;
    county?: string;
    is_primary: boolean;
    is_private: boolean;
    is_primary_for_country_region?: boolean;
}

export type RelationshipDirection = 'a_to_b' | 'b_to_a';

export interface RelationshipItem {
    id: string;
    party_a_id: string;
    party_a_name: string;
    relationship_a_to_b: string;
    relationship_b_to_a: string;
    party_b_id: string;
    party_b_name: string;
    effective_date: string;
    expiration_date?: string;
    status: 'active' | 'expired' | 'future';
}

export type ContactType = 'phone' | 'email' | 'whatsapp' | 'url' | 'telex' | 'fax' | 'linkedin' | 'twitter';

export interface ContactItem {
    id: string;
    type: ContactType;
    value: string;
    description?: string;
    extension?: string;
    is_primary: boolean;
}

export interface PartyRoleItem {
    id: string;
    company: string;
    account_number: string;
    role: string;
}
