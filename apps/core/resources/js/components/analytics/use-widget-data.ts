import { useEffect, useRef, useState } from 'react';
import type { WidgetFailure } from '@/lib/analytics/api';
import { fetchWidgetData, widgetFailure } from '@/lib/analytics/api';
import type { ResultSet } from '@/lib/analytics/types';

type Loaded = {
    key: string;
    result: ResultSet | null;
    failure: WidgetFailure | null;
};

/**
 * Data satu widget, dimuat saat widget mendekati layar (`docs/todo/analitik/dasbor-dan-visual.md`, bagian
 * *Memuat data widget*): dasbor 24 widget tidak menembakkan 24 query sekaligus, yang di bawah lipatan
 * menunggu digulir. `ref` dipasang pada pembungkus widget.
 *
 * Hasil disimpan bersama kuncinya (widget dan urutan muat ulang), jadi hasil permintaan lama tidak pernah
 * tampil sebagai hasil permintaan sekarang; `previous` adalah hasil terakhir yang berhasil, untuk tetap
 * ditampilkan redup selama Muat ulang berjalan. `reload()` meminta hitungan baru tanpa hasil simpanan.
 *
 * `enabled` palsu untuk widget yang tidak punya data (teks) atau yang definisinya sudah diketahui tidak
 * dapat dihitung; tidak ada permintaan yang dikirim.
 */
export function useWidgetData(widgetId: string, enabled: boolean) {
    const ref = useRef<HTMLDivElement | null>(null);
    const [visible, setVisible] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const [loaded, setLoaded] = useState<Loaded | null>(null);
    const [previous, setPrevious] = useState<ResultSet | null>(null);
    const key = `${widgetId}:${attempt}`;

    useEffect(() => {
        const node = ref.current;

        if (!enabled || node === null) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { rootMargin: '200px' },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, [enabled]);

    useEffect(() => {
        if (!enabled || !visible) {
            return;
        }

        const controller = new AbortController();

        fetchWidgetData(widgetId, controller.signal, attempt > 0)
            .then((result) => {
                setLoaded({ key, result, failure: null });
                setPrevious(result);
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setLoaded({
                        key,
                        result: null,
                        failure: widgetFailure(caught),
                    });
                }
            });

        return () => controller.abort();
    }, [enabled, visible, widgetId, attempt, key]);

    const current = loaded !== null && loaded.key === key ? loaded : null;

    return {
        ref,
        loading: enabled && current === null,
        result: current?.result ?? null,
        failure: current?.failure ?? null,
        previous,
        reload: () => setAttempt((value) => value + 1),
    };
}
