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

/**
 * Tanggal dan jam dari waktu ISO 8601 yang membawa zona waktunya, dalam zona waktu peramban: "23 Sep 2026, 15.00".
 *
 * Waktu dari server klien dikirim dalam UTC. Menampilkannya apa adanya membuat operator di Jakarta membaca jam yang
 * tujuh jam lebih awal dari yang dialami klinik.
 */
export function dateTimeText(iso: string | null): string | null {
    if (!iso) {
        return null;
    }

    const time = Date.parse(iso);

    if (Number.isNaN(time)) {
        return null;
    }

    return new Intl.DateTimeFormat('id', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(time);
}

/** Lama waktu dalam dua satuan terbesar: "1 hari 3 jam", "5 jam 12 menit", "40 menit". */
export function durationText(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (days > 0) {
        return hours % 24 > 0
            ? `${days} hari ${hours % 24} jam`
            : `${days} hari`;
    }

    if (hours > 0) {
        return minutes % 60 > 0
            ? `${hours} jam ${minutes % 60} menit`
            : `${hours} jam`;
    }

    return minutes > 0 ? `${minutes} menit` : 'kurang dari semenit';
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
