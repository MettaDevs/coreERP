export type NavItem = { href: string; title: string };

/**
 * Urutannya mengikuti alur kerja dan bukan abjad: tenant lahir lebih dulu, lingkungannya menyusul,
 * dan Pembaruan dibaca sesudah keduanya ada. Layar Lingkungan tidak dapat berbuat apa-apa untuk
 * perusahaan yang belum menjadi pelanggan, dan layar Pembaruan tidak punya apa pun untuk
 * dibandingkan sebelum ada lingkungan.
 *
 * Situs paling akhir: ia server milik klien on-prem yang dikelola lewat agen, dan tenant-nya harus
 * sudah ada lebih dulu.
 */
export const navigation: NavItem[] = [
    { href: '/tenant', title: 'Tenant' },
    { href: '/lingkungan', title: 'Lingkungan' },
    { href: '/pembaruan', title: 'Pembaruan' },
    { href: '/situs', title: 'Situs' },
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
