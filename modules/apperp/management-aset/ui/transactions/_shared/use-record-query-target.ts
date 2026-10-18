import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useEffectEvent } from 'react';
import { toast } from 'sonner';

/** Membuka target kartu pada tab baru melalui pembaca record yang sudah berizin. */
export function useRecordQueryTarget({
    scopeKey,
    enabled = true,
    onOpen,
    onError,
    requireDate = false,
}: {
    scopeKey: string;
    enabled?: boolean;
    requireDate?: boolean;
    onOpen: (
        id: string,
        signal: AbortSignal,
        kind: string | null,
        date: string | null,
    ) => Promise<void> | void;
    onError?: (error: unknown) => void;
}) {
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    const id = query.get('record');
    const kind = query.get('record_kind');
    const date = query.get('record_date');
    const open = useEffectEvent(onOpen);
    const fail = useEffectEvent((error: unknown) => {
        if (onError) {
            onError(error);
        } else {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Tautan data tidak valid.',
            );
        }
    });
    useEffect(() => {
        if (!enabled || !id) {
            return;
        }

        if (!/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i.test(id)) {
            fail(new Error('Tautan data tidak valid.'));

            return;
        }

        if (
            requireDate &&
            (!date ||
                !/^\d{4}-\d{2}-\d{2}$/.test(date) ||
                Number(date.slice(0, 4)) === 0 ||
                Number.isNaN(Date.parse(date + 'T00:00:00Z')) ||
                new Date(date + 'T00:00:00Z').toISOString().slice(0, 10) !==
                    date)
        ) {
            fail(new Error('Tautan data tidak valid.'));

            return;
        }

        const controller = new AbortController();
        void (async () => {
            try {
                await open(id, controller.signal, kind, date);
            } catch (error) {
                if (!controller.signal.aborted) {
                    fail(error);
                }
            }
        })();

        return () => controller.abort();
    }, [scopeKey, enabled, id, kind, date, requireDate]);

    return useCallback(() => {
        const next = new URL(url, window.location.origin);

        if (
            !next.searchParams.has('record') &&
            !next.searchParams.has('record_kind') &&
            !next.searchParams.has('record_date')
        ) {
            return;
        }

        next.searchParams.delete('record');
        next.searchParams.delete('record_kind');
        next.searchParams.delete('record_date');
        router.replace({
            url: next.pathname + next.search + next.hash,
            preserveState: true,
            preserveScroll: true,
        });
    }, [url]);
}
