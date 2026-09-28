export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    /* @chisel-2fa */
    two_factor_enabled?: boolean;
    /* @end-chisel-2fa */
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    membership?: {
        id: string;
        system_role: string;
        tenant_id: string;
        tenant_name: string;
    } | null;
    provider_admin?: boolean;
};

export type WorkspaceMembership = {
    id: string;
    tenant_id: string;
    tenant_name: string;
    system_role: string;
};

export type WorkspaceOrganization = {
    id: string;
    name: string;
    classification: 'legal_entity' | 'operating_unit';
};

export type Workspace = {
    memberships: WorkspaceMembership[];
    active_legal_entity: WorkspaceOrganization | null;
    active_org_unit: WorkspaceOrganization | null;
    legal_entities: WorkspaceOrganization[];
    org_units: WorkspaceOrganization[];
};

export type EntitledProduct = {
    id: string;
    name: string;
    description: string;
    href: string;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

/* @chisel-2fa */
export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
/* @end-chisel-2fa */
