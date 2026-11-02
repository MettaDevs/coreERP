# Menyiapkan cutover dan rollback database

Ini rancangan runbook. Perintah migration/copy baru belum diimplementasikan. Jangan menganggap langkah atau gate yang belum dicentang sebagai pekerjaan yang sudah dijalankan. Pelaksanaan cutover memerlukan instruksi terpisah.

## Selesaikan prasyarat

- [ ] Bekukan source SHA, versi schema, target host/database, image, migration history, dan policy yang diuji. Simpan manifest tanpa secret.
- [ ] Inventaris semua canonical writer, role assignment, framework store, job, scheduler, lease, dan adapter yang bisa menulis metadata.
- [ ] Nyatakan pemetaan setiap tabel, sequence, view, trigger, FK, ciphertext, dan proyeksi. Tidak ada objek belum diklasifikasikan.
- [ ] Target database bernama core_control_plane. Pindahkan identity/hierarchy organisasi generik ke Foundation, pisahkan atribut finansial ERP dan provider Faskes. Audit OrganizationParty/Address Book serta pertahankan ID, hierarchy version, dan binding lokal.
- [ ] Verifikasi control connection, ERP pooled connection, environment-specific connection, dan maintenance connection memakai target yang benar.
- [ ] Uji code path pada dua database dan role berbeda. Tidak menggunakan schema berbeda dalam database sama sebagai bukti split.
- [ ] Uji backup dan restore ke target terisolasi; uji dekripsi dan signing tanpa membuka secret di log.
- [ ] Ukur waktu copy, validasi, restart, smoke, dan rollback sebelum menetapkan jendela maintenance. Catat RPO/RTO yang dapat dipenuhi.

## Rekam manifest perpindahan

| Bagian manifest  | Isi minimum                                                                                                                    |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Identitas run    | Run ID, source SHA/schema version, migration epoch, waktu mulai dan pelaku.                                                    |
| Asal/tujuan      | Database, role, koneksi, dan fingerprint konfigurasi yang aman; tanpa password.                                                |
| Ownership        | Move/retain/projection untuk setiap objek; penulis lama dan penulis baru.                                                      |
| Validasi         | PK set, jumlah baris, content checksum yang terurut/dinormalisasi, sequence/identity berikutnya, constraint, dan orphan check. |
| Identity dan key | Pemetaan ID stabil, ciphertext count, key availability, signing kid, hasil verifikasi dekripsi/signature.                      |
| Operasi          | Queue watermark, receipt terakhir, lease, revision akses, desired state, dan outbox checkpoint.                                |
| Hasil            | Bukti per gate, daftar kegagalan, keputusan lanjut/rollback, dan owner penanganan.                                             |

Checksum row count saja tidak membuktikan isi identik. Checksum harus memperlakukan timestamp, null, binary/ciphertext, dan urutan record secara konsisten. Jangan menuliskan nilai pribadi atau secret pada laporan perbedaan publik.

## Jalankan rehearsal terisolasi

1. Restore backup/fixture ke Linux nonproduksi yang tidak terhubung sebagai writer ke sistem legacy.
2. Jalankan prosedur yang sama dengan cutover yang direncanakan, termasuk freeze, fencing, copy, sequence repair, validasi, dan restart worker.
3. Jalankan kasus tenant/operator/ERP/Faskes pada fixture baru. Jangan menghidupkan DivaPraktik atau mengubah enrollment asli.
4. Hentikan control DB dan business DB secara bergantian. Catat perilaku session, request, queue, timeout, dan revoke terhadap kontrak PRD.
5. Jalankan rollback sebelum target menerima write dan latihan pemulihan setelah target menerima write.
6. Catat semua langkah manual yang masih belum deterministik. Otomatiskan atau beri gate eksplisit sebelum cutover nyata.

## Bekukan penulis sebelum copy akhir

1. Tutup perubahan tenant, environment, membership, invitation, hak produk, dan setting pusat dari semua pintu, termasuk API internal dan operator.
2. Drain request/job yang relevan, pause scheduler/provisioner, dan tunggu atau batalkan lease dengan aturan yang dapat dibuktikan.
3. Fence worker lama melalui epoch/revision. Worker yang selesai terlambat tidak boleh menulis ke asal ataupun tujuan dengan authority lama.
4. Bila writer belum dapat diisolasi secara pasti, gunakan maintenance seluruh runtime CoreERP untuk jangka terbatas. Jangan mengklaim partial maintenance aman dari dugaan.
5. Ambil backup konsisten dan rekam checkpoint final. Pastikan outbox/receipt tidak mempunyai pekerjaan yang statusnya tidak diketahui.

Tabel bisnis ERP yang tetap berada di database asal dapat terus dipakai hanya bila manifest membuktikan tidak ada writer lintas batas yang masih berjalan. Jangan membekukan metadata saja sementara transaksi campuran lama masih aktif.

## Salin dan verifikasi target

1. Siapkan database dan role tujuan melalui migration owner. Pisahkan privilege default dan larangan akses PUBLIC yang relevan.
2. Buat schema pusat yang sudah diklasifikasikan, bukan seluruh schema historis CoreERP.
3. Copy dalam urutan dependency yang dicatat. Pertahankan ID, revision, expiry, ciphertext, receipt, dan tombstone.
4. Buat atau perbarui proyeksi lokal serta FK lokal yang menggantikan FK lintas batas. Tidak membuang constraint tanpa pengganti integritas.
5. Perbaiki sequence/identity dan validasi constraint, PK set, row count, checksum, serta dekripsi/signing.
6. Catat database tujuan belum menerima canonical write aplikasi sampai seluruh gate pre-switch lulus.

Validasi juga policy/group revision, effective date, adapter acknowledgement, snapshot production/sandbox, dan status grant. Cutover database tidak melakukan merge/split pasien atau stock pool. Operasi tersebut mempunyai preview dan migration tersendiri pada spesifikasi sharing.

## Alihkan koneksi dan buktikan hasilnya

1. Alihkan konfigurasi terkontrol pada Core, konsol, worker, scheduler, CLI, dan komponen terkait. Simpan active image digest; perubahan env tidak boleh mengembalikan image lama.
2. Bersihkan config cache yang relevan, lalu restart proses pemegang koneksi. Worker lama tidak boleh tetap hidup dengan credential atau config asal.
3. Verifikasi `current_database()`, server, role, dan privilege aktual dari setiap runtime. Nama env tidak menjadi bukti.
4. Sebelum smoke yang menulis atau menimbulkan side effect, rekam first canonical write/side effect pada tujuan. Smoke create tenant, grant, atau provisioning sudah melewati batas rollback sebelum-write, walaupun memakai fixture. Jalankan smoke tersebut, lalu transaksi ERP, module installation/migration yang sah, login, role, dan laporan audit.
5. Uji endpoint negatif lintas tenant, grant pending, stale worker, dan role database yang salah.
6. Verifikasi enrollment OFF serta tenant sengaja ditakedown tetap tidak aktif. Jangan menyalakan public sebagai bagian smoke.
7. Buka kembali writer hanya setelah semua gate lulus. Catat waktu pembukaan secara terpisah. Batas rollback mengikuti write/side effect pertama, termasuk smoke, bukan waktu writer umum dibuka.

## Bedakan dua jenis rollback

| Kondisi                                                                              | Tindakan yang diizinkan                                                                                                                                                 |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Target belum menerima write baru dan tidak ada side effect eksternal setelah freeze. | Kembalikan routing di bawah maintenance/fencing, restart, verifikasi asal, dan catat target tidak otoritatif.                                                           |
| Target sudah menerima write atau adapter menghasilkan side effect baru.              | Utamakan forward repair. Jangan sekadar mengganti env ke asal.                                                                                                          |
| Reverse migration benar-benar diperlukan setelah write baru.                         | Bekukan semua writer, rekonsiliasi delta tujuan ke asal dengan ID/revision/receipt/key yang sama, validasi ulang, baru pindah authority. Prosedurnya harus telah diuji. |
| ERP bisnis telah berubah sejak backup gabungan dibuat.                               | Jangan restore backup gabungan lama di atas database ERP yang aktif. Pulihkan hanya scope yang tepat dan rekonsiliasi metadata.                                         |

Database asal masih dapat menjadi business database ERP sesudah split. Jadikan hanya salinan **tabel pusat lama** non-writable dan tidak dipakai selama retensi. Jangan membuat seluruh database asal read-only bila masih melayani ERP.

## Pantau setelah perpindahan

- [ ] Metrik salah koneksi, permission denied yang tidak diharapkan, projection lag, outbox backlog, stale leases, dan decrypt/signing failure tidak menunjukkan regresi.
- [ ] ID serta jumlah operasi baru sesuai receipt; tidak ada tenant, grant, database, atau perpanjangan expiry ganda.
- [ ] Role database lama tidak dipakai untuk canonical write. Pembaca tidak fallback ke tabel asal.
- [ ] Restore pusat dan bisnis terpisah dapat dilakukan tanpa menghidupkan kembali akses yang dicabut.
- [ ] Arsipkan hasil run, known gaps, dan owner on-call. Retensi dan pembersihan salinan lama memerlukan instruksi terpisah; tidak purge otomatis dalam program ini.
