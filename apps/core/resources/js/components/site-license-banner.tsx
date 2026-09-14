import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { usePage } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';

type SiteLicenseStatus =
    'not_required' | 'missing' | 'invalid' | 'valid' | 'expiring' | 'expired';

type SharedProps = {
    siteLicense: {
        status: SiteLicenseStatus;
        validUntil: string | null;
    } | null;
};

/**
 * `2027-09-14` menjadi `14 September 2027`.
 *
 * Diurai per bagian dan ditulis dalam UTC, bukan `new Date('2027-09-14')` biasa: bentuk itu dibaca
 * sebagai tengah malam UTC, lalu peramban yang zona waktunya di belakang UTC menampilkannya sebagai
 * tanggal sehari sebelumnya — tanggal berakhir yang salah justru pada kalimat yang menyebut tanggal.
 */
function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const [year, month, day] = value.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        'id-ID',
        { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' },
    );
}

function copyFor(
    status: SiteLicenseStatus,
    validUntil: string,
): { title: string; sentence: string } | null {
    switch (status) {
        case 'expiring':
            return {
                title: 'Masa lisensi segera berakhir',
                sentence: `Lisensi berlaku sampai ${validUntil}. Hubungi penyedia untuk memperpanjang; aplikasi tetap dapat dipakai seperti biasa.`,
            };
        case 'expired':
            return {
                title: 'Masa lisensi sudah berakhir',
                sentence: `Masa lisensi berakhir pada ${validUntil}. Aplikasi tetap dapat dipakai; hubungi penyedia untuk memperpanjang.`,
            };
        case 'missing':
            return {
                title: 'Lisensi belum terpasang',
                sentence:
                    'Lisensi tidak ditemukan di server ini. Aplikasi tetap dapat dipakai; hubungi penyedia untuk memasangnya.',
            };
        case 'invalid':
            return {
                title: 'Lisensi tidak dapat diperiksa',
                sentence:
                    'Lisensi di server ini tidak dapat dipastikan keasliannya. Aplikasi tetap dapat dipakai; hubungi penyedia untuk menggantinya.',
            };
        default:
            return null;
    }
}

/**
 * Memberi tahu bahwa lisensi pemasangan ini perlu diurus — tanpa menghalangi apa pun.
 *
 * Lisensi adalah tanda, bukan kunci. Pelanggan on-prem kita fasilitas kesehatan, jadi yang boleh
 * dilakukan lisensi yang habis hanya satu: memberi tahu. Karena itu setiap kalimat menyebut bahwa
 * aplikasi tetap dapat dipakai — tanpa itu, orang yang membacanya di tengah pelayanan akan mengira
 * pekerjaannya sebentar lagi terkunci dan berhenti menyimpan.
 *
 * Tidak dapat ditutup, sama seperti spanduk lingkungan: yang dapat ditutup ditutup pada hari
 * pertama, lalu tidak terlihat lagi justru ketika tanggalnya lewat.
 */
export default function SiteLicenseBanner() {
    const { siteLicense } = usePage<SharedProps>().props;

    if (!siteLicense) {
        return null;
    }

    const copy = copyFor(
        siteLicense.status,
        formatDate(siteLicense.validUntil),
    );

    if (!copy) {
        return null;
    }

    return (
        <div className="px-4 pt-3">
            <Alert
                role="status"
                variant={
                    siteLicense.status === 'expired' ? 'destructive' : 'default'
                }
            >
                <TriangleAlertIcon />
                <AlertTitle>{copy.title}</AlertTitle>
                <AlertDescription>{copy.sentence}</AlertDescription>
            </Alert>
        </div>
    );
}
