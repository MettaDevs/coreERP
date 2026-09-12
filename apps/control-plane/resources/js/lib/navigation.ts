export type NavItem = { href: string; title: string };

/**
 * Dua butir, urutannya mengikuti alur kerja dan bukan abjad: pelanggan lahir lebih dulu,
 * lingkungannya menyusul. Layar Lingkungan tidak dapat berbuat apa-apa untuk perusahaan yang belum
 * menjadi pelanggan.
 */
export const navigation: NavItem[] = [
    { href: '/pelanggan', title: 'Pelanggan' },
    { href: '/lingkungan', title: 'Lingkungan' },
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
