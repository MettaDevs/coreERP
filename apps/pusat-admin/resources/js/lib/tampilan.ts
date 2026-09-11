/**
 * Kata yang muncul di layar, terpisah dari kata yang tersimpan di database.
 *
 * Nilai di database berbahasa Inggris (`production`, `provisioning`) karena ia bagian dari skema
 * dan constraint PostgreSQL yang menyebutnya. Yang dibaca operator berbahasa Indonesia. Memetakan
 * keduanya di satu tempat lebih murah daripada menerjemahkannya di setiap layar, dan mencegah satu
 * layar memakai kata yang berbeda dari layar sebelahnya.
 */
export const namaJenis: Record<string, string> = {
    production: 'Produksi',
    sandbox: 'Sandbox',
    demo: 'Demo',
};

export const namaStatus: Record<string, string> = {
    provisioning: 'Sedang disiapkan',
    copying: 'Sedang disalin',
    active: 'Aktif',
    maintenance: 'Pemeliharaan',
    degraded: 'Bermasalah',
    suspended: 'Ditangguhkan',
    soft_deleted: 'Dihapus',
};

export const namaOperasi: Record<string, string> = {
    provision: 'Penyiapan',
    copy: 'Penyalinan',
    migrate: 'Migrasi skema',
    disarm: 'Pelucutan',
    suspend: 'Penangguhan',
    resume: 'Pengaktifan kembali',
    soft_delete: 'Penghapusan',
    restore: 'Pemulihan',
    purge: 'Pembersihan',
    expire: 'Kedaluwarsa',
};

export const namaHasil: Record<string, string> = {
    running: 'Berjalan',
    succeeded: 'Berhasil',
    failed: 'Gagal',
};

export function sebut(kamus: Record<string, string>, kunci: string): string {
    return kamus[kunci] ?? kunci;
}
