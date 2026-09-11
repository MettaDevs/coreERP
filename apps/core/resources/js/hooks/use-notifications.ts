import { usePage } from '@inertiajs/react';
import { useEffect, useSyncExternalStore } from 'react';

import {
    getNotifications,
    scopeNotifications,
    subscribeNotifications,
} from '@/lib/notifications';
import type { ShellNotification } from '@/lib/notifications';
import type { Auth } from '@/types';

/**
 * Daftar notifikasi user aktif, terikat pada tenant dan user yang sedang masuk.
 * Berganti workspace mengganti daftarnya, bukan menggabungkannya.
 */
export function useNotifications(): ShellNotification[] {
    const { auth } = usePage<{ auth: Auth }>().props;
    const tenantId = auth.membership?.tenant_id ?? 'no-tenant';
    const userId = auth.user?.id ?? 'anon';

    useEffect(() => {
        scopeNotifications(tenantId, userId);
    }, [tenantId, userId]);

    return useSyncExternalStore(
        subscribeNotifications,
        getNotifications,
        () => [],
    );
}
