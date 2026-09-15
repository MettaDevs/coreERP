/**
 * Keadaan lisensi situs sebagaimana dikirim server — lihat `App\Support\License\SiteLicenseState`.
 *
 * Peramban tidak memutuskan apa pun dari nilai ini. Yang mengunci dan menyaring app adalah server;
 * yang di sini hanya bahan kalimat.
 */
export type SiteLicenseStatus =
    'not_required' | 'missing' | 'invalid' | 'valid' | 'expiring' | 'expired';

export type SiteLicense = {
    status: SiteLicenseStatus;
    validUntil: string | null;
    /** Dihitung server pada saat yang sama dengan keadaannya, supaya spanduk dan kunci sepakat. */
    daysLeft: number | null;
    required: boolean;
};

/**
 * `2027-09-14` menjadi `14 September 2027`.
 *
 * Diurai per bagian dan ditulis dalam UTC, bukan `new Date('2027-09-14')` biasa: bentuk itu dibaca
 * sebagai tengah malam UTC, lalu peramban yang zona waktunya di belakang UTC menampilkannya sebagai
 * tanggal sehari sebelumnya — tanggal berakhir yang salah justru pada kalimat yang menyebut tanggal.
 */
export function formatLicenseDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const [year, month, day] = value.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        'id-ID',
        { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' },
    );
}
