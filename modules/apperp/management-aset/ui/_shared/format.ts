/**
 * Pemformat kecil untuk layar asuransi, garansi, dan downtime aset.
 *
 * Waktu dari server berzona UTC dan ditampilkan lewat `useDateTimeFormat()` Core. Yang ada di sini
 * hanya yang tidak disediakan Core: angka uang, tanggal tanpa jam, dan isian `datetime-local` menurut
 * zona pengguna.
 */

/** Angka uang tanpa simbol mata uang, misalnya "45.000.000". */
export function money(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: 2,
    }).format(Number(value));
}

/** Tanggal tanpa jam ("2026-09-30") sebagai "30-09-2026". Tanggal tidak punya zona, jadi tidak digeser. */
export function day(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return value.slice(0, 10).split('-').reverse().join('-');
}

/**
 * Waktu UTC dari server sebagai nilai isian `datetime-local` menurut zona pengguna, misalnya
 * "2026-09-30T14:05". Server membaca isian tanpa offset menurut zona yang sama, jadi bolak-baliknya
 * tidak menggeser jam.
 */
export function zonedInput(
    utc: string | null | undefined,
    timeZone: string,
): string {
    if (!utc) {
        return '';
    }

    const date = new Date(
        /[zZ]|[+-]\d{2}:?\d{2}$/.test(utc) ? utc : `${utc.replace(' ', 'T')}Z`,
    );

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
