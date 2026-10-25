export type Product = {
    code: string;
    name: string;
    profiles: { code: string; name: string; description: string }[];
};

export type ProductTenant = {
    id: string;
    product: string;
    profile: string;
    name: string;
    slug: string;
    owner_name: string;
    owner_email: string;
    status: 'queued' | 'provisioning' | 'ready' | 'failed';
    public_url: string | null;
    failure_message: string | null;
    created_at: string;
    expires_at: string | null;
    credentials?: {
        username: string;
        password: string;
        operator_username: string;
        operator_password: string;
    } | null;
};

export const statusLabels = {
    queued: 'Menunggu penyiapan',
    provisioning: 'Sedang disiapkan',
    ready: 'Siap digunakan',
    failed: 'Perlu diperiksa',
} satisfies Record<ProductTenant['status'], string>;

function isTenant(value: unknown): value is ProductTenant {
    return (
        typeof value === 'object' &&
        value !== null &&
        'id' in value &&
        typeof value.id === 'string' &&
        'name' in value &&
        typeof value.name === 'string' &&
        'profile' in value &&
        typeof value.profile === 'string' &&
        'slug' in value &&
        typeof value.slug === 'string' &&
        'product' in value &&
        typeof value.product === 'string' &&
        'owner_name' in value &&
        typeof value.owner_name === 'string' &&
        'owner_email' in value &&
        typeof value.owner_email === 'string' &&
        'created_at' in value &&
        typeof value.created_at === 'string' &&
        'expires_at' in value &&
        (value.expires_at === null || typeof value.expires_at === 'string') &&
        'public_url' in value &&
        (value.public_url === null || typeof value.public_url === 'string') &&
        'failure_message' in value &&
        (value.failure_message === null ||
            typeof value.failure_message === 'string') &&
        'status' in value &&
        (value.status === 'queued' ||
            value.status === 'provisioning' ||
            value.status === 'ready' ||
            value.status === 'failed')
    );
}

export function parseTenantBatch(value: unknown): {
    items: ProductTenant[];
    nextCursor: string | null;
} {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('data' in value) ||
        !Array.isArray(value.data) ||
        !value.data.every(isTenant) ||
        !('next_cursor' in value) ||
        (value.next_cursor !== null && typeof value.next_cursor !== 'string')
    ) {
        throw new Error(
            'Daftar tenant belum dapat dibaca. Coba muat ulang daftar.',
        );
    }
    return { items: value.data, nextCursor: value.next_cursor };
}
