# TODO platform dan portal customer

Ini daftar kerja aktif untuk rencana [platform Metta](index.md), diselaraskan 2 November 2026 dengan [TODO pemisahan database](../control-plane-database/TODO.md). PF-01 telah diimplementasikan dan hasilnya disiapkan untuk review pada [laporan PF-01](PF-01.md). PF-02 dan unit berikutnya belum diimplementasikan. Bukti fixture tidak berarti runtime server sudah berubah.

## Kelola status pekerjaan

Gunakan `ready`, `blocked`, `in_progress`, `in_review`, `verified`, atau `superseded`. Status `verified` memerlukan bukti perilaku pada SHA yang diuji. Kotak dicentang hanya saat tugas verified. Owner pada tabel adalah peran tim yang disarankan; isi nama penanggung jawab ketika pekerjaan diambil.

Untuk setiap ID, catat owner, branch/PR, SHA, bukti, dan penghambat. Jangan menghapus ID yang batal; tandai superseded dan tautkan penggantinya. [PLAN](PLAN.md) menetapkan urutan dan verifikasi. [CHANGES](CHANGES.md) menetapkan cara mengubah scope.

| Unit  | Peran penanggung jawab               | Status    | Bergantung pada     | PR/SHA dan bukti                                                                     |
| ----- | ------------------------------------ | --------- | ------------------- | ------------------------------------------------------------------------------------ |
| PF-01 | Codex, platform backend + security   | in_review | CPDB-01             | [PF-01](PF-01.md), branch `codex/pf-01-tenant-foundation`, backend teruji `a4f331dd` |
| PF-02 | Platform backend + adapter Faskes    | blocked   | PF-01, CPDB-05      | Belum ada                                                                            |
| PF-03 | Portal + admin UI + platform backend | blocked   | PF-02               | Belum ada                                                                            |
| PF-04 | Identity/access + adapter Faskes     | blocked   | PF-02, PF-03        | Belum ada                                                                            |
| PF-05 | Runtime/operasi + platform backend   | blocked   | PF-02, PF-04        | Belum ada                                                                            |
| PF-06 | QA + release owner                   | blocked   | PF-03, PF-04, PF-05 | Belum ada                                                                            |

Peran dapat dikerjakan orang yang sama. Jangan membagi dua penulis schema atau kontrak yang sama tanpa satu owner integrasi. PF-01 menjadi prasyarat bersama untuk split database, bukan tugas duplikat. PF-02 dimulai setelah split lulus CPDB-05; prototipe UI boleh berjalan tanpa membuka enrollment.

## PF-01. Pisahkan tenant dari instalasi ERP

- [x] PF-01a. Tetapkan tenant kanonik sebagai batas organisasi; petakan client komersial, identity, membership, role, fasilitas, dan environment. Bukti berupa kontrak/model yang direview.
- [x] PF-01b. Pisahkan pembuatan tenant dan role Owner dari proses ERP pada `RegisterBusiness`. Bukti berupa tenant tanpa User duplikat, legal entity ERP, environment ERP, atau entitlement ERP otomatis.
- [x] PF-01c. Tentukan principal bersama yang mengikat identity `issuer + subject`. Satukan jalur ProductCustomer dan User melalui pemetaan eksplisit; jangan mencocokkan otomatis berdasarkan email. Bukti berupa kasus akun lama, baru, dan subject berbeda.
- [x] PF-01d. Tetapkan shared action dan boundary operator/customer. Bukti berupa otorisasi server dan audit aktor yang benar; operator tidak menjadi Owner otomatis.

Bukti PF-01 berada pada `evidence/pf-01/verification.json`, `http-acceptance.json` dan `erp-baseline.json`. Seluruh lane HTTP dan SQL lulus pada fixture terisolasi. Principal customer dipetakan saat creation, collision credential tidak auto-link, dan keanggotaan tidak menjadi permission management. Analytics tetap hold. Tidak ada merge atau deploy dari pekerjaan PF-01.

## PF-02. Kaitkan fasilitas dan provisioning ke tenant

- [ ] PF-02a. Tetapkan identitas fasilitas dan kaitan deployment Faskes ke tenant kanonik. Pertahankan resource ID runtime lama dan adapter SQL Server.
- [ ] PF-02b. Buat inventaris dan dry-run migrasi record owner lama, termasuk record buatan operator tanpa owner. Bukti berupa pemetaan, konflik, dan record belum diklaim; tidak ada takeover berdasarkan email.
- [ ] PF-02c. Terapkan migrasi idempotent dan rollback metadata. Bukti berupa hostname, database, isi seed, dan akun dukungan tetap utuh; tidak ada restore bisnis saat pemetaan.
- [ ] PF-02d. Buat registry produk/profil dan policy tervalidasi serta berversi. Bukti berupa admin dan portal membaca katalog sama, produk nonaktif tersembunyi, input produk/profil ilegal ditolak.
- [ ] PF-02e. Simpan snapshot policy, release, checksum, expiry, dan operasi provisioning. Bukti berupa replay atau worker restart tidak membuat database/fasilitas ganda.
- [ ] PF-02f. Terapkan kuota organisasi disertai anti-abuse principal. Pisahkan histori kelayakan trial, reservasi/running capacity, dan penyimpanan database expired. Bukti berupa request konkuren dan tenant baru tidak mengakali trial; capacity tidak dilepas hanya karena status diarsipkan.
- [ ] PF-02g. Ikat fasilitas ke Organization ID Foundation di core_control_plane tanpa membuat master Faskes kedua. Catat binding tenant/org/environment/UnitBisnis atau Section lokal yang tidak ambigu.
- [ ] PF-02h. Rekam baseline sharing yang berlaku, policy revision, capability, dan authority data. Jangan otomatis mengubah data legacy global menjadi isolated atau memperluasnya menjadi shared. Capability lanjut dikerjakan pada SH-01/02/03.

## PF-03. Sediakan dua antarmuka pada model yang sama

- [ ] PF-03a. Buat portal dengan session, route, permission, layout, dan audience sendiri. Bukti berupa customer tidak dapat membuka route operator.
- [ ] PF-03b. Sediakan create/select tenant dan daftar fasilitas yang diizinkan. Tampilkan kepemilikan pending tanpa tombol kirim atau terima undangan sebelum PF-04. Bukti berupa akun tanpa tenant, satu tenant, banyak tenant, dan record pending.
- [ ] PF-03c. Sediakan form Faskes dengan profil dan aturan dari registry. Bukti berupa seed keadaan sekarang, unit bisnis dan judul mengikuti fasilitas, logo Metta, serta `metta` terlindungi.
- [ ] PF-03d. Sesuaikan admin internal untuk memilih tenant yang sama dan mencatat calon Owner pending. Pengiriman dan penerimaan undangan baru tersedia bersama PF-04. Bukti berupa hasil create/list/status konsisten dari kedua sisi.
- [ ] PF-03e. Tampilkan status operasi, error yang bisa ditindaklanjuti, retry yang aman, dan launch hanya setelah siap. Bukti berupa refresh/login ulang melanjutkan operasi sama.
- [ ] PF-03f. Tampilkan pengaturan domain pasien, rekam medis, katalog, dan sumber stok secara terpisah dengan capability gate. UI hierarchy bukan layar sharing semua data. Bukti berupa API menolak kombinasi yang belum ditegakkan adapter.

## PF-04. Undang tim tanpa shared owner

- [ ] PF-04a. Implementasikan kontrak serta UI kirim/terima invitation untuk customer dan calon Owner dari admin. Gunakan token sekali pakai dengan tenant, grant, inviter, recipient, expiry, dan audit. Pisahkan commit membership pusat dari local provisioning memakai durable operation dan receipt. Grant tetap pending bila local provisioning gagal. Bukti berupa wrong identity, expired, replay, dua acceptance konkuren, dan pemulihan kegagalan setelah central commit.
- [ ] PF-04b. Terapkan security role pengelola tenant dan grant environment terpisah. Bukti berupa anggota satu fasilitas tidak dapat masuk fasilitas atau tenant lain.
- [ ] PF-04c. Provision akun `mUser` unik per anggota, petakan role bisnis, dan batasi dengan profil fasilitas. Bukti berupa dua orang muncul sebagai dua pelaku berbeda pada audit transaksi.
- [ ] PF-04d. Terapkan revoke, invalidasi sesi, dan penutupan login lokal yang berasal dari grant. Bukti berupa sesi aktif dan password lama tidak melewati pencabutan.
- [ ] PF-04e. Lindungi Owner terakhir dan pisahkan akun dukungan. Bukti berupa transfer yang diterima penerus, kegagalan transfer tidak mengunci organisasi, dan `metta` tidak diwariskan ke anggota.

## PF-05. Tahan gagal dan perubahan operasional

- [ ] PF-05a. Persist desired state suspend/resume dan fencing operasi. Bukti berupa worker terlambat tidak menghidupkan tenant yang disuspend.
- [ ] PF-05b. Pisahkan expiry hak trial dari pengarsipan tenant. Bukti berupa trial berakhir menutup aplikasi terkait tanpa memutus organisasi lain atau SSO global.
- [ ] PF-05c. Rekonsiliasi resource yatim, lease, dan hasil worker setelah crash. Bukti berupa kegagalan setelah create DB dapat dilanjutkan tanpa restore data siap.
- [ ] PF-05d. Tetapkan backup dan restore yang mempertahankan revoke, secret baru, dan provenance. Bukti berupa latihan restore terisolasi sebelum akses customer dibuka.
- [ ] PF-05e. Tambahkan audit dan pemantauan antrean, failure, kapasitas disk/database, serta readiness. Bukti berupa notifikasi kegagalan nyata yang tidak mencatat token atau data pasien.

## PF-06. Uji dan rilis alur customer

- [ ] PF-06a. Putuskan batas D05 dan D06 pada CHANGES. Catat angka dan alasan sebelum public enrollment diaktifkan.
- [ ] PF-06b. Uji end-to-end customer baru, existing tenant, undangan tim, operator-created tenant, isolasi, dan revoke. Bukti mengikuti matriks PLAN pada SHA final.
- [ ] PF-06c. Uji browser nyata termasuk Origin/Referrer-Policy, cookie cross-site, callback, status URL publik, dan launch per anggota. Bukti berupa screenshot dan hasil negatif akses lintas tenant.
- [ ] PF-06d. Arahkan CTA landing ke portal dan siapkan fallback. CMS landing tetap terpisah dari akun customer.
- [ ] PF-06e. Rilis dalam enrollment tertutup dahulu. Catat versi backend, portal, adapter, schema, dan prosedur rollback tanpa restore database customer.
- [ ] PF-06f. Buka public enrollment hanya setelah gate lulus dan ada instruksi aktivasi. Takedown DivaPraktik tidak dibatalkan oleh rilis atau rekonsiliasi.
- [ ] PF-06g. Jalankan matriks OS untuk mode yang ditawarkan. Pasien A/B terpisah tetapi stok bersama memerlukan SH-01 dan SH-02 serta OS-04 lulus. Mode baseline satu klinik tidak membuktikan seluruh variasi multi-klinik sudah tersedia.
- [ ] PF-06h. Untuk profil kelompok layanan, uji SG-01 sampai SG-14 serta SH-04/05. Bedakan katalog obat/jasa bersama, pasien per kelompok unit utama/pendukung, tarif lokal, dan lokasi stok. Tidak menyimpulkan sinkronisasi penuh dari kemiripan ID sumber.
- [ ] PF-06i. Untuk profile Klinik/Apotek/DEPO bertitipan, uji SH-06 dan CD-01 sampai CD-12. Unit pelayanan, owner stok, custodian, seller, collector dan beneficiary harus terbukti benar pada routing, receipt, laporan dan pembatalan.

Rincian pekerjaan domain dan perubahan policy ada pada [TODO sharing](SHARING-TODO.md). Tidak membuat writer organisasi kedua atau mengulang schema Foundation di repository Faskes.

## Tunda sampai ada kebutuhan

- [ ] LATER-01. Tawarkan trial public ERP setelah kebijakan ERP dan adapter disetujui. Jangan mengaktifkan form ERP lama sebagai jalan pintas.
- [ ] LATER-02. Implementasikan paket berbayar dan konversi setelah aturan data contoh dan retensi diputuskan.
- [ ] LATER-03. Implementasikan pindah server, custom domain, atau perubahan profil berdasarkan operasi berversi dan rollback yang diuji.
- [ ] LATER-04. Pisahkan hanya runtime/repository platform setelah indikator pada index terbukti. Database terpisah sudah menjadi pekerjaan CPDB sekarang, bukan bagian LATER.

## Catat penghambat dan perubahan

| Tanggal    | ID pekerjaan | Owner aktual     | Penghambat atau perubahan                               | Bukti               | Keputusan pengganti             |
| ---------- | ------------ | ---------------- | ------------------------------------------------------- | ------------------- | ------------------------------- |
| 2026-10-30 | PF-01        | Belum ditugaskan | Perencanaan selesai; implementasi belum dimulai.        | Dokumen folder ini. | Belum ada                       |
| 2026-11-02 | PF-01/PF-02  | Belum ditugaskan | Database control plane dipisah sebelum ekspansi portal. | PRD dan TODO CPDB.  | Dependensi CPDB-01 dan CPDB-05. |
