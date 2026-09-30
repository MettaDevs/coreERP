/** Tanggal dan jam, misalnya "28 Sep 2026, 14.05". */
export const DATE_TIME: Intl.DateTimeFormatOptions = {
    dateStyle: 'medium',
    timeStyle: 'short',
};

const WITHOUT_ZONE = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/;

/**
 * Waktu dari server sebagai teks menurut zona waktu pengguna (area 7).
 *
 * Server menyimpan dan mengirim waktu dalam UTC. Waktu tanpa offset, misalnya `2026-09-27 17:30:00`
 * dari query mentah, juga dibaca sebagai UTC; tanpa aturan ini peramban membacanya sebagai jam
 * perangkat. Zona perangkat tidak pernah dipakai: zonanya selalu setelan pengguna dari server.
 *
 * Tanggal tanpa jam (`2026-09-28`) jangan dilewatkan ke sini; tanggal tidak punya zona dan tidak digeser.
 */
export function formatDateTime(
    value: string,
    timeZone: string,
    options: Intl.DateTimeFormatOptions = DATE_TIME,
): string {
    const date = new Date(
        WITHOUT_ZONE.test(value) ? `${value.replace(' ', 'T')}Z` : value,
    );

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('id-ID', { ...options, timeZone }).format(
        date,
    );
}
