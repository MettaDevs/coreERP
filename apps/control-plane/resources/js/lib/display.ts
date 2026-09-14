/**
 * Kata yang muncul di layar, terpisah dari kata yang tersimpan di database.
 *
 * Nilai di database berbahasa Inggris (`production`, `provisioning`) karena ia bagian dari skema
 * dan constraint PostgreSQL yang menyebutnya. Yang dibaca operator berbahasa Indonesia. Memetakan
 * keduanya di satu tempat lebih murah daripada menerjemahkannya di setiap layar, dan mencegah satu
 * layar memakai kata yang berbeda dari layar sebelahnya.
 */
export const kindLabels: Record<string, string> = {
    production: 'Produksi',
    sandbox: 'Sandbox',
    demo: 'Demo',
};

export const statusLabels: Record<string, string> = {
    provisioning: 'Sedang disiapkan',
    copying: 'Sedang disalin',
    active: 'Aktif',
    maintenance: 'Pemeliharaan',
    degraded: 'Bermasalah',
    suspended: 'Ditangguhkan',
    soft_deleted: 'Dihapus',
};

export const moduleStatusLabels: Record<string, string> = {
    installed: 'Terpasang',
    disabled: 'Dimatikan',
    uninstalled: 'Dicopot',
};

export const operationLabels: Record<string, string> = {
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

export const resultLabels: Record<string, string> = {
    running: 'Berjalan',
    succeeded: 'Berhasil',
    failed: 'Gagal',
};

/**
 * Keadaan sebuah lingkungan terhadap versi image yang sedang berjalan.
 *
 * `unknown` sengaja tidak diterjemahkan menjadi "Tidak diketahui". Ia mencakup dua keadaan yang
 * keduanya bukan kegagalan dan bukan ketertinggalan — sedang dikerjakan, dan belum pernah punya
 * skema sama sekali — dan "Tidak diketahui" membaca keduanya sebagai kerusakan. "Belum terbaca"
 * menyebut apa adanya: layarnya belum dapat menyimpulkan, bukan sesuatunya rusak.
 */
export const fleetStateLabels: Record<string, string> = {
    current: 'Mutakhir',
    behind: 'Tertinggal',
    failed: 'Bermasalah',
    unknown: 'Belum terbaca',
};

/**
 * Keadaan sebuah situs dilihat dari admin.erp.
 *
 * `stale` berbunyi "Tidak melapor", bukan "Mati". Konsol ini hanya tahu laporannya berhenti datang —
 * sebabnya dapat berupa server yang mati, internet klien yang putus, atau agen yang berhenti — dan
 * kata yang menebak salah satunya membuat operator mencari di tempat yang keliru.
 */
export const siteStateLabels: Record<string, string> = {
    not_enrolled: 'Belum terdaftar',
    enrolled: 'Terdaftar',
    stale: 'Tidak melapor',
    revoked: 'Dicabut',
};

export const connectivityLabels: Record<string, string> = {
    online: 'Online',
    offline: 'Offline',
};

export const siteOperationLabels: Record<string, string> = {
    upgrade: 'Perbarui',
    backup: 'Cadangkan',
    install_license: 'Pasang lisensi',
    rotate_key: 'Ganti kunci situs',
    send_diagnostics: 'Kirim diagnosa',
};

export const siteOperationStatusLabels: Record<string, string> = {
    requested: 'Menunggu diambil agen',
    running: 'Berjalan',
    succeeded: 'Berhasil',
    failed: 'Gagal',
    cancelled: 'Dibatalkan',
    expired: 'Tidak pernah diambil',
};

export function labelFor(labels: Record<string, string>, key: string): string {
    return labels[key] ?? key;
}
