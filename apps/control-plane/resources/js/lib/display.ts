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
 * Satu daftar untuk panel di halaman lingkungan, daftar lingkungan, dan daftar server klien — kata yang
 * sama di ketiganya.
 *
 * `stale` berbunyi "Tidak melapor". PRD pemasangan satu perintah (PS-05) menulisnya "Tertinggal", dan kata itu
 * bertahan sampai daftar server klien mulai menandai rilis yang lebih lama dari rilis terbaru: di baris yang
 * sama "Tertinggal" dan "rilis 0.2.0" terbaca sebagai ketinggalan rilis, padahal artinya server yang sudah
 * terpasang berhenti terlihat. "Tidak melapor" menyebut yang benar-benar diketahui konsol, sama dengan
 * `siteStateLabels`.
 */
export const installStateLabels: Record<string, string> = {
    not_prepared: 'Belum disiapkan',
    no_command: 'Perintah pasang belum dibuat',
    awaiting_command: 'Menunggu perintah dijalankan',
    awaiting_release: 'Menunggu rilis',
    connected: 'Server tersambung',
    installing: 'Memasang',
    ready: 'Jalan',
    stale: 'Tidak melapor',
    failed: 'Gagal',
    revoked: 'Dicabut',
};

/**
 * Status posting finance, dengan kata yang sama persis dengan layar Pantau posting di Core. Operator dan admin klinik
 * yang membicarakan satu posting lewat telepon harus menyebutnya dengan kata yang sama.
 */
export const financePostingStatusLabels: Record<string, string> = {
    held: 'Tertahan',
    pending: 'Menunggu ditarik',
    rejected: 'Ditolak',
    manual: 'Manual',
    posted: 'Sudah dibukukan',
};

/**
 * Kesehatan feed posting finance satu server klien, dinilai `FinanceFeedHealth` di server.
 *
 * `unreadable` berbunyi "Tidak terbaca", bukan "Bermasalah": yang diketahui konsol hanya bahwa agen tidak mendapat
 * ringkasan dari Core — feed-nya sendiri bisa saja sehat.
 */
export const financeFeedStateLabels: Record<string, string> = {
    not_reported: 'Belum dilaporkan',
    unreadable: 'Tidak terbaca',
    unused: 'Belum dipakai',
    healthy: 'Sehat',
    attention: 'Perlu perhatian',
};

/** Tempat sebuah lingkungan berjalan — kolom `environments.hosting`. */
export const hostingLabels: Record<string, string> = {
    provider: 'Server kita',
    client_server: 'Server klien',
};

/**
 * Jejak audit server klien dalam kata operator. Kunci aslinya tetap tampil di sampingnya: kunci itu yang
 * dicari di log dan di tabel `operator_audit_events`, dan kata yang dibaca tidak boleh menggantikannya.
 * Kunci yang belum ada di sini tampil apa adanya lewat `labelFor`, bukan disembunyikan.
 */
export const siteAuditLabels: Record<string, string> = {
    'site.created': 'Server klien dicatat',
    'site.settings.updated': 'Setelan diubah',
    'site.install_command.issued': 'Perintah pasang dibuat',
    'site.enrollment_token.issued': 'Token pendaftaran dibuat',
    'site.operation.requested': 'Operasi diminta',
    'site.operation.cancelled': 'Operasi dibatalkan',
    'site.license.issued': 'Lisensi diterbitkan operator',
    'site.license.renewed': 'Lisensi diperpanjang otomatis',
    'site.license.renewal_suspended': 'Perpanjangan lisensi dihentikan',
    'site.license.renewal_resumed': 'Perpanjangan lisensi dilanjutkan',
    'site.registry_robot.issued': 'Kredensial registry diterbitkan',
    'site.registry_robot.deleted': 'Kredensial registry dihapus',
    'site.revoked': 'Server klien dicabut',
};

export function labelFor(labels: Record<string, string>, key: string): string {
    return labels[key] ?? key;
}
