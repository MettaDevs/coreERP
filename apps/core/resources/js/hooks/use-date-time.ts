import { usePage } from '@inertiajs/react';
import { formatDateTime } from '@/lib/date-time';

/**
 * Pemformat waktu menurut zona waktu pengguna, dihitung server (`clock.timezone`).
 *
 * Layar Core dan module memakai ini untuk setiap waktu dari server, misalnya kapan sebuah ekspor
 * dibuat atau kapan record diubah, supaya pengguna di WITA melihat jam WITA walau perangkatnya
 * disetel zona lain. Waktu kosong menjadi "—". Tanggal tanpa jam tidak lewat sini.
 */
export function useDateTimeFormat(): (
    value: string | null | undefined,
    options?: Intl.DateTimeFormatOptions,
) => string {
    const { clock } = usePage().props;
    const timeZone = clock?.timezone ?? 'UTC';

    return (value, options) =>
        value ? formatDateTime(value, timeZone, options) : '—';
}
