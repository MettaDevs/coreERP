import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { usePage } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';
import { formatLicenseDate } from '@/lib/site-license';
import type { SiteLicense } from '@/lib/site-license';

type SharedProps = {
    siteLicense: SiteLicense | null;
};

/** "hari ini", "besok", atau "dalam N hari" — angka nol dan satu tidak dibaca orang sebagai hitungan. */
function remaining(daysLeft: number | null): string {
    if (daysLeft === null || daysLeft <= 0) {
        return 'hari ini';
    }

    if (daysLeft === 1) {
        return 'besok';
    }

    return `dalam ${daysLeft} hari`;
}

function copyFor(
    license: SiteLicense,
): { title: string; sentence: string } | null {
    const validUntil = formatLicenseDate(license.validUntil);

    /*
     * Dua nada, dan yang memilih adalah `required`, bukan keadaannya.
     *
     * Bila lisensi wajib, keadaan yang buruk berarti pengguna tenant sudah terkunci — satu-satunya
     * yang masih melihat spanduk ini akun penyedia. Menulis "aplikasi tetap dapat dipakai" kepadanya
     * adalah kalimat yang salah tepat pada saat yang paling penting. Bila tidak wajib, kalimat itu
     * justru yang harus ada: orang yang membacanya di tengah pelayanan akan mengira pekerjaannya
     * sebentar lagi terkunci dan berhenti menyimpan.
     */
    switch (license.status) {
        case 'expiring':
            return {
                title: `Lisensi berakhir ${remaining(license.daysLeft)}`,
                sentence: license.required
                    ? `Lisensi aplikasi ini berlaku sampai ${validUntil}. Setelah itu aplikasi tidak dapat dibuka sampai lisensinya diperpanjang. Hubungi penyedia aplikasi sekarang.`
                    : `Lisensi berlaku sampai ${validUntil}. Hubungi penyedia untuk memperpanjang; aplikasi tetap dapat dipakai seperti biasa.`,
            };
        case 'expired':
            return {
                title: 'Masa lisensi sudah berakhir',
                sentence: license.required
                    ? `Masa lisensi berakhir pada ${validUntil}. Pengguna lain tidak dapat membuka aplikasi sampai lisensinya diperpanjang.`
                    : `Masa lisensi berakhir pada ${validUntil}. Aplikasi tetap dapat dipakai; hubungi penyedia untuk memperpanjang.`,
            };
        case 'missing':
            return {
                title: 'Lisensi belum terpasang',
                sentence: license.required
                    ? 'Lisensi tidak ditemukan. Pengguna lain tidak dapat membuka aplikasi sampai lisensinya dipasang.'
                    : 'Lisensi tidak ditemukan. Aplikasi tetap dapat dipakai; hubungi penyedia untuk memasangnya.',
            };
        case 'invalid':
            return {
                title: 'Lisensi tidak dapat diperiksa',
                sentence: license.required
                    ? 'Keaslian lisensi tidak dapat dipastikan. Pengguna lain tidak dapat membuka aplikasi sampai lisensinya diganti.'
                    : 'Keaslian lisensi tidak dapat dipastikan. Aplikasi tetap dapat dipakai; hubungi penyedia untuk menggantinya.',
            };
        default:
            return null;
    }
}

/**
 * Memberi tahu bahwa lisensi pemasangan ini perlu diurus.
 *
 * Spanduk ini tidak menghalangi apa pun; yang mengunci server. Ia tampil selama lisensi segera
 * berakhir — tujuh hari sebelumnya, dan dalam keadaan sehat tidak pernah, karena lisensi diperpanjang
 * otomatis jauh sebelum itu. Begitu ia terlihat, perpanjangannya sudah gagal beberapa kali.
 *
 * Tidak dapat ditutup, sama seperti spanduk lingkungan: yang dapat ditutup ditutup pada hari
 * pertama, lalu tidak terlihat lagi justru ketika tanggalnya lewat.
 */
export default function SiteLicenseBanner() {
    const { siteLicense } = usePage<SharedProps>().props;

    if (!siteLicense) {
        return null;
    }

    const copy = copyFor(siteLicense);

    if (!copy) {
        return null;
    }

    // Merah untuk keadaan yang sudah mengunci pengguna lain, dan untuk lisensi yang sudah habis.
    // Yang segera berakhir tetap netral: ia masih peringatan, bukan kejadian.
    const locksOthers =
        siteLicense.required && siteLicense.status !== 'expiring';

    return (
        <div className="px-4 pt-3">
            <Alert
                role="status"
                variant={
                    locksOthers || siteLicense.status === 'expired'
                        ? 'destructive'
                        : 'default'
                }
            >
                <TriangleAlertIcon />
                <AlertTitle>{copy.title}</AlertTitle>
                <AlertDescription>{copy.sentence}</AlertDescription>
            </Alert>
        </div>
    );
}
