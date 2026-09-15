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

export const siteOperationLabels: Record<string, string> = {
    install: 'Pasang',
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

/**
 * Keadaan pemasangan server klien, dihitung `InstallProgress` di server.
 *
 * Satu daftar untuk panel di halaman lingkungan, daftar lingkungan, dan ringkasan situs — kata yang
 * sama di ketiganya. `stale` di sini "Tertinggal", bukan "Tidak melapor" seperti keadaan situs: yang
 * dibaca dari daftar ini adalah server yang **sudah terpasang** lalu berhenti terlihat.
 */
export const installStateLabels: Record<string, string> = {
    not_prepared: 'Belum disiapkan',
    no_command: 'Perintah pasang belum dibuat',
    awaiting_command: 'Menunggu perintah dijalankan',
    awaiting_release: 'Menunggu rilis',
    connected: 'Server tersambung',
    installing: 'Memasang',
    ready: 'Jalan',
    stale: 'Tertinggal',
    failed: 'Gagal',
    revoked: 'Dicabut',
};

export function labelFor(labels: Record<string, string>, key: string): string {
    return labels[key] ?? key;
}
