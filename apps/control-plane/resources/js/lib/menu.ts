export type ButirMenu = { alamat: string; judul: string };

/**
 * Dua butir, urutannya mengikuti alur kerja dan bukan abjad: pelanggan lahir lebih dulu,
 * lingkungannya menyusul. Layar Lingkungan tidak dapat berbuat apa-apa untuk perusahaan yang belum
 * menjadi pelanggan.
 */
export const menu: ButirMenu[] = [
    { alamat: '/pelanggan', judul: 'Pelanggan' },
    { alamat: '/lingkungan', judul: 'Lingkungan' },
];

/**
 * Rincian sebuah lingkungan beralamat `/lingkungan/{id}`, jadi pencocokannya tidak boleh persis.
 * Ia juga tidak boleh sekadar `startsWith`: alamat seperti `/lingkungan-lama` akan ikut tertangkap,
 * dan butir yang menyala di layar yang salah lebih membingungkan daripada butir yang mati.
 */
export function butirAktif(url: string): ButirMenu | undefined {
    const jalur = url.split('?')[0];

    return menu.find(
        (butir) =>
            jalur === butir.alamat || jalur.startsWith(`${butir.alamat}/`),
    );
}
