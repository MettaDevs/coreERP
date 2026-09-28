/**
 * Membandingkan dua nomor rilis bertitik sebagai angka: `0.10.0` lebih baru dari `0.9.3`.
 *
 * Untuk nomor tiga angka hasilnya sama dengan `SiteRelease::compare` (`version_compare` PHP). Untuk jumlah
 * angka yang berbeda tidak: di sini `0.2` dan `0.2.0` setara, sedangkan PHP menilai yang kedua lebih baru —
 * alasan nomor rilis selalu tiga angka ada di docs/dev/29. Layar hanya memakainya untuk menandai "ada rilis
 * lebih baru"; keputusan yang mengikat — rilis mana yang boleh dipasang — tetap diambil server.
 */
export function compareReleases(a: string, b: string): number {
    const left = a.split('.').map(Number);
    const right = b.split('.').map(Number);
    const length = Math.max(left.length, right.length);

    for (let i = 0; i < length; i++) {
        const difference = (left[i] ?? 0) - (right[i] ?? 0);

        if (difference !== 0) {
            return difference > 0 ? 1 : -1;
        }
    }

    return 0;
}

/** Rilis yang lebih baru dari yang terpasang, atau null bila tidak ada atau belum terbaca. */
export function newerRelease(
    reported: string | null,
    newest: string | null,
): string | null {
    if (!reported || !newest) {
        return null;
    }

    return compareReleases(newest, reported) > 0 ? newest : null;
}
