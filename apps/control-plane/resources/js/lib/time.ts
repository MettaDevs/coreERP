/**
 * "3 menit lalu" dari waktu ISO 8601 yang membawa zona waktunya.
 *
 * Masukannya harus ISO dengan zona waktu (`toIso8601String()` di server), bukan teks `Y-m-d H:i:s`.
 * Teks tanpa zona dibaca peramban sebagai waktu setempat: konsol menyimpan UTC, dan peramban di Jakarta
 * akan menyebut server yang baru melapor "7 jam lalu".
 *
 * `now` dapat disuntikkan supaya satu layar menghitung setiap barisnya dari detik yang sama.
 */
export function relativeTime(
    iso: string | null,
    now: number = Date.now(),
): string | null {
    if (!iso) {
        return null;
    }

    const then = Date.parse(iso);

    if (Number.isNaN(then)) {
        return null;
    }

    const seconds = Math.round((then - now) / 1000);
    const format = new Intl.RelativeTimeFormat('id', { numeric: 'auto' });
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['second', 60],
        ['minute', 60],
        ['hour', 24],
        ['day', 30],
        ['month', 12],
    ];

    let value = seconds;

    for (const [unit, size] of steps) {
        if (Math.abs(value) < size) {
            return format.format(value, unit);
        }

        value = Math.round(value / size);
    }

    return format.format(value, 'year');
}

/** Selisih hari kalender dari hari ini ke tanggal `Y-m-d`; negatif bila sudah lewat. */
export function daysUntil(
    date: string | null,
    now: Date = new Date(),
): number | null {
    if (!date) {
        return null;
    }

    const [year, month, day] = date.split('-').map(Number);

    if (!year || !month || !day) {
        return null;
    }

    const target = Date.UTC(year, month - 1, day);
    const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());

    return Math.round((target - today) / 86_400_000);
}
