import { Button } from '@apperp/ui/button';
import { Head, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { formatLicenseDate } from '@/lib/site-license';
import { logout } from '@/routes';

/**
 * Hanya tiga keadaan yang mengunci, jadi hanya tiga yang pernah dikirim ke halaman ini — lihat
 * `App\Http\Middleware\EnforceSiteLicense`.
 */
type Props = {
    status: 'missing' | 'invalid' | 'expired';
    validUntil: string | null;
};

/**
 * Kalimat pertama halaman, menurut sebabnya.
 *
 * Tanggal hanya disebut bila memang ada. Lisensi yang hilang atau tanda tangannya salah tidak punya
 * tanggal yang dapat dipercaya, dan menyebut "berakhir pada" tanpa tanggal lebih membingungkan
 * daripada tidak menyebutnya.
 */
function reasonFor({ status, validUntil }: Props): string {
    if (status === 'expired' && validUntil) {
        return `Lisensi aplikasi ini sudah berakhir pada ${formatLicenseDate(validUntil)}.`;
    }

    return 'Lisensi aplikasi ini tidak dapat diperiksa.';
}

/**
 * Halaman yang dilihat pengguna ketika lisensi server ini tidak berlaku.
 *
 * Pembacanya petugas di tengah pelayanan, bukan orang teknis. Karena itu yang disebut hanya tiga hal
 * yang dapat ia pakai: apa yang terjadi, bahwa datanya tidak hilang, dan siapa yang dihubungi. Istilah
 * seperti tanda tangan atau berkas lisensi tidak ada gunanya baginya dan sengaja tidak muncul.
 *
 * Tombol keluar ada karena halaman ini dirender di setiap alamat yang ditahan. Tanpa tombol itu, jalan
 * pergi hanya menutup peramban, dan akun penyedia yang datang memperbaiki tidak dapat masuk dari
 * komputer yang sama.
 */
export default function LicenseLocked() {
    const handleLogout = () => {
        router.flushAll();
        router.post(logout().url);
    };

    return (
        <>
            <Head title="Lisensi tidak berlaku" />

            <div className="flex flex-col gap-6 text-center">
                <p className="text-sm text-muted-foreground">
                    Data Anda tetap tersimpan dan tidak ada yang dihapus.
                    Hubungi penyedia aplikasi untuk memperbarui lisensi. Setelah
                    lisensi diperbarui, muat ulang halaman ini.
                </p>

                <Button
                    variant="outline"
                    className="w-full"
                    onClick={handleLogout}
                    data-test="license-locked-logout"
                >
                    <LogOut />
                    Keluar
                </Button>
            </div>
        </>
    );
}

/*
 * Judul dan kalimat pertamanya dibawa tata letak halaman masuk, sama seperti layar lupa kata sandi.
 * Bentuk fungsi, bukan objek, karena kalimatnya bergantung pada sebab yang dikirim server.
 */
LicenseLocked.layout = (props: Props) => ({
    title: 'Lisensi tidak berlaku',
    description: reasonFor(props),
});
