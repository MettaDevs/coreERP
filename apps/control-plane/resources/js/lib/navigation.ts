export type NavItem = { href: string; title: string };

/**
 * Urutannya mengikuti alur kerja dan bukan abjad: tenant lahir lebih dulu, lingkungannya menyusul,
 * dan Pembaruan dibaca sesudah keduanya ada. Layar Lingkungan tidak dapat berbuat apa-apa untuk
 * perusahaan yang belum menjadi pelanggan, dan layar Pembaruan tidak punya apa pun untuk
 * dibandingkan sebelum ada lingkungan.
 *
 * Server klien sesudahnya: VPS milik klien yang dikelola lewat agen, dan tenant beserta lingkungan
 * produksinya harus sudah ada lebih dulu. Alamatnya tetap `/situs` supaya tautan lama sampai; yang berganti
 * hanya kata yang dibaca, karena "Situs" terbaca seperti situs web. Pengaturan paling akhir, karena ia
 * dibuka sesekali — saat menyiapkan konsol, atau saat perintah pasang dan lisensi tidak bekerja — bukan
 * setiap hari.
 */
export const navigation: NavItem[] = [
    { href: '/tenant', title: 'Tenant' },
    { href: '/lingkungan', title: 'Lingkungan' },
    { href: '/pembaruan', title: 'Pembaruan' },
    { href: '/situs', title: 'Server klien' },
    { href: '/pengaturan', title: 'Pengaturan' },
];

/**
 * Rincian sebuah lingkungan beralamat `/lingkungan/{id}`, jadi pencocokannya tidak boleh persis.
 * Ia juga tidak boleh sekadar `startsWith`: alamat seperti `/lingkungan-lama` akan ikut tertangkap,
 * dan butir yang menyala di layar yang salah lebih membingungkan daripada butir yang mati.
 */
export function activeItem(url: string): NavItem | undefined {
    const path = url.split('?')[0];

    return navigation.find(
        (item) => path === item.href || path.startsWith(`${item.href}/`),
    );
}
