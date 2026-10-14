import { usePage } from '@inertiajs/react';

/** Cache layar tidak boleh membawa hasil/pilihan milik tenant atau hak pengguna sebelumnya. */
export function useAnalyticsScope(): string {
    const { auth } = usePage().props;

    return JSON.stringify([
        auth.user.id,
        auth.membership?.id,
        auth.membership?.tenant_id,
        auth.membership?.permissions.slice().sort(),
    ]);
}
