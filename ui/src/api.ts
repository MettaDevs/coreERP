let contextToken = '';

/** Token konteks hanya berasal dari Web Shell; UI tidak pernah menyusun tenant sendiri. */
export function setContextToken(token: string): void {
    contextToken = token;
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(`/api/v1${path}`, {
        ...init,
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${contextToken}`,
            ...init?.headers,
        },
    });
    if (!response.ok) {
        const body = await response.json().catch(() => null);
        throw new Error(body?.error?.message ?? body?.message ?? 'Permintaan belum berhasil.');
    }
    return response.status === 204 ? (undefined as T) : response.json();
}

export function errorMessage(caught: unknown, fallback: string): string {
    return caught instanceof Error ? caught.message : fallback;
}
