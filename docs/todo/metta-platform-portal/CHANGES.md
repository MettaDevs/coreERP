# Mengelola perubahan produk dan rencana

Dokumen ini menetapkan cara tim menyesuaikan kebijakan dan pekerjaan ketika kebutuhan berubah. Semua aturan di bawah adalah target implementasi. Konfigurasi dinamis belum tersedia hanya karena dokumen ini ada.

Koreksi cakupan pada 2 November 2026 menetapkan istilah domain netral untuk pemerintah dan swasta. Model memakai organisasi, fasilitas, service group, catalog authority dan process profile. Contoh pengelola pada evidence tidak menjadi syarat bentuk usaha, nama tabel, atau default wajib semua customer.

Keputusan terbaru memakai nama database `core_control_plane`. Domain Organization Foundation di sana menjadi master organisasi/hierarchy bersama ERP dan Faskes, tanpa pembelian ERP. [Spesifikasi sharing](../control-plane-database/ORGANIZATION-AND-SHARING.md) memisahkan sharing pasien, clinical records, katalog obat, dan sumber stok. Enforcement legacy mengikuti [TODO sharing](SHARING-TODO.md), bukan diasumsikan tersedia dari metadata.

[Profil kelompok layanan](../control-plane-database/SERVICE-GROUP-PROFILES.md) menambahkan service catalog, pricing/availability, dan kelompok unit utama dengan unit pendukung. Default tersebut menggantikan asumsi semua unit selalu mempunyai registry mandiri; tenant/profile lain tetap dapat memilih konfigurasi berbeda. Katalog bersama lintas kelompok tidak berarti pasien, tarif, atau saldo stok semuanya sama.

[Profile custody Apotek/DEPO Klinik](../control-plane-database/SUPPLY-CUSTODY-AND-PAYMENT.md) memisahkan source, owner, lokasi/custodian, unit realisasi, seller, beneficiary dan kasir penerima. Mengubah routing tidak boleh menyamarkan kepemilikan atau atribusi uang. Kategori/perizinan fasilitas adalah batas validasi, bukan aturan universal semua Klinik selalu dilarang membeli/memiliki obat.

## Bedakan konfigurasi dari kemampuan kode

| Bagian     | Boleh dikonfigurasi                                                                            | Tetap memerlukan kode dan review                                                                        |
| ---------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Katalog    | Nama, deskripsi, urutan, profil yang sudah didukung, visibilitas public.                       | Menambahkan engine produk atau capability yang adapter belum mendukung.                                 |
| Trial      | Durasi, kuota, batas kapasitas, undangan, dan enrollment per produk.                           | Aturan kepemilikan, transaksi kuota, dan pemeriksaan akses.                                             |
| Deployment | Release yang telah diverifikasi, seed/profil versi, penempatan yang sudah disetujui.           | Restore, schema compatibility, isolasi jaringan, dan adapter SQL Server.                                |
| Branding   | Nama organisasi/fasilitas dan judul browser. Logo Metta tetap default sesuai keputusan produk. | Sanitasi output dan keamanan upload jika logo customer kelak didukung.                                  |
| Domain     | Base domain yang terdaftar dan slug yang valid.                                                | Verifikasi DNS/TLS, callback allowlist, dan reservasi domain. Tidak menerima target bebas dari browser. |
| Akses      | Pemilihan role yang telah dikenal dan grant anggota.                                           | Menambahkan permission atau memperluas batas profil fasilitas.                                          |

Pakai registry produk bertipe dan tervalidasi. Metadata dapat bertambah tanpa menambah cabang `if product` pada semua layar. Adapter produk tetap dipilih dari daftar kode yang disetujui. Jangan membuat engine yang mengeksekusi PHP, SQL, atau shell dari konfigurasi customer.

Adapter menyatakan capability yang benar-benar didukung, misalnya provision, member provisioning/revoke, suspend, backup, atau upgrade. Portal dan admin membaca capability yang sama. Backend tetap menolak operasi yang tidak didukung walaupun request dikirim langsung. Capability pindah server atau perubahan profil tidak ditampilkan sebelum implementasinya tersedia.

Jangan memberi konsol atau browser akses tulis langsung ke database SSO maupun database fasilitas. Kontrak customer memeriksa membership dan grant; credential mesin internal tidak pernah diberikan ke browser.

Jangan menambah runtime plugin loader atau database kebijakan generik sebelum ada kebutuhan. Mulai dari registry yang ada di `config/managed_products.php`, pisahkan metadata dari credential dan implementasi adapter, lalu pindahkan pengaturan operator ke mekanisme setting pusat yang tervalidasi dan diaudit. Browser hanya menerima field publik.

## Bekukan keputusan untuk setiap operasi

Saat permintaan provisioning diterima, simpan `product`, `profile`, versi policy, release digest, checksum seed/profil, expiry, dan placement yang dipilih server. Nama field di sini adalah rancangan kontrak, bukan klaim schema yang sudah ada.

- Perubahan durasi berlaku untuk permintaan baru. Perpanjangan trial lama adalah operasi eksplisit yang diaudit.
- Perubahan seed tidak menjalankan restore pada database siap. Perubahan data/schema tenant lama memakai migration yang sesuai produk.
- Perubahan profil tidak cukup mengganti nama. Validasi schema, data, hak akses, dan capability sebelum menerapkan rencana perubahan.
- Perubahan hierarchy tidak otomatis mengubah group berbagi data. Merge/split registry pasien atau stock pool yang sudah berisi data memakai preview, migration operation, fencing, dan rekonsiliasi; bukan toggle biasa.
- Retry memakai keputusan semula dan idempotency key yang sama. Keputusan baru harus menjadi operasi baru yang terlihat.
- Token, credential worker, dan key privat tetap di penyimpanan secret operator. Gunakan referensi dan fingerprint dalam record operasi, bukan isi secret.
- Gunakan revision atau fencing token agar hasil worker lama tidak menimpa keputusan suspend atau revoke yang lebih baru.

Simpan perubahan pusat dan catatan pengiriman pekerjaan dalam transaksi yang sama melalui outbox atau jaminan durable setara. Adapter menyimpan perubahan lokal bersama receipt idempotency. Dua database sudah menciptakan batas commit meskipun process masih sama. Antrean setelah commit tanpa catatan durable tidak cukup. Tidak perlu memasang message broker baru untuk seluruh platform sekarang.

## Pertahankan status yang berbeda

| Objek       | Perubahan yang perlu dibedakan                                                     |
| ----------- | ---------------------------------------------------------------------------------- |
| Keanggotaan | Pending invitation, aktif, dicabut.                                                |
| Hak produk  | Trial aktif, trial berakhir, berlangganan jika tersedia, ditangguhkan.             |
| Operasi     | Queued, berjalan, berhasil, gagal, dibatalkan atau superseded bila didukung.       |
| Environment | Disiapkan, siap, gagal, suspend, diarsipkan. Status nyata harus diperiksa adapter. |

Istilah ini target konsep. PF-02 harus memetakan ke enum dan constraint yang sudah ada sebelum memperkenalkan status baru. Jangan mengganti seluruh model lifecycle ERP hanya agar nama status sama.

Desired state suspend harus tetap bertahan setelah restart worker atau rekonsiliasi. Environment siap tidak berarti hak trial masih aktif. Trial berakhir tidak menghapus organisasi, identitas, atau histori.

Pisahkan penghitung kelayakan trial dari kapasitas. Histori klaim tetap diperhitungkan untuk kelayakan meskipun trial selesai atau fasilitas diarsipkan. Reservasi dan resource yang masih berjalan memakai kapasitas operasional; reservasi baru dilepas setelah adapter membuktikan resource terkait sudah dilepas. Database trial yang expired tetapi masih disimpan tetap memakai kapasitas penyimpanan. Jangan memindahkan hitungan seluruh record lama ke scope tenant tanpa mendefinisikan perbedaan ini.

## Siapkan kemungkinan berikut tanpa membangun semuanya sekarang

| Kemungkinan                                         | Aturan desain sekarang                                                          | Pekerjaan saat kebutuhan benar-benar datang                                                                                           |
| --------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Customer membuka beberapa fasilitas.                | Tenant mempunyai banyak fasilitas dan grant per environment.                    | Sesuaikan paket, kapasitas, dan kuota secara eksplisit.                                                                               |
| Satu orang bekerja untuk dua organisasi.            | Identity global dengan membership per tenant.                                   | Tenant switcher dan konteks pilihan yang divalidasi setiap request.                                                                   |
| Owner keluar atau kehilangan akses.                 | Lindungi Owner terakhir dan pisahkan operator dari Owner.                       | Transfer Owner dengan penerimaan dan jalur pemulihan yang diaudit.                                                                    |
| Customer membuat tenant baru untuk mengulang trial. | Kuota tenant disertai batas principal/email terverifikasi dan kapasitas global. | Tambahkan pengendalian abuse berdasarkan bukti; anggota undangan tidak memperoleh trial baru.                                         |
| Customer mengganti nama organisasi.                 | ID tetap; display name bukan identitas atau domain.                             | Perubahan slug/domain menjadi operasi terpisah dengan TLS dan callback diperiksa.                                                     |
| Apotek berubah menjadi Klinik.                      | Profil dan versi tersimpan; akses ditentukan capability.                        | Migration profil dan persetujuan biaya jika kelak relevan. Jangan otomatis membuka semua menu.                                        |
| Trial menjadi berbayar.                             | Subscription/hak produk terpisah dari tenant dan deployment.                    | Pilih promote atau salinan environment dengan keputusan retensi dan provenance data. Jangan otomatis membawa data contoh ke produksi. |
| Kegagalan worker setelah database dibuat.           | Operation ID, resource ownership, idempotency, dan rekonsiliasi.                | Lanjutkan langkah yang belum selesai; jangan restore ulang database yang sudah berisi data.                                           |
| Pengguna dicabut ketika aplikasi sedang terbuka.    | Grant dan local binding per anggota, invalidasi sesi, serta revision akses.     | Terapkan batas waktu pencabutan yang dapat diukur dan penanganan kegagalan adapter.                                                   |
| Backup lama dikembalikan.                           | Otoritas grant berada di platform, bukan snapshot mUser.                        | Rekonsiliasi revoke sebelum lalu lintas customer dibuka.                                                                              |
| Produk ERP ditawarkan ke public.                    | Registry dapat mengenal produk tanpa mengaktifkannya.                           | Review kebijakan ERP, adapter, kapasitas, dan onboarding tersendiri.                                                                  |
| Environment pindah server.                          | Pisahkan ID environment dari placement dan domain.                              | Backup, cutover, fencing, dan rollback diuji sebelum membuka multi-node.                                                              |
| Runtime platform kelak dipisah dari ERP.            | Database terpisah sudah menjadi target CPDB; ownership dan kontrak tetap jelas. | Pindahkan runtime/repository hanya ketika bukti kebutuhan rilis atau operasional tersedia.                                            |
| Produk dihentikan sementara.                        | Enrollment, launch, dan operasi lifecycle mempunyai kontrol berbeda.            | Tutup pendaftaran tanpa mematikan fasilitas lain; revoke produk tidak mematikan akun SSO global.                                      |

## Ubah rencana tanpa kehilangan alasan

1. Cari ID pekerjaan di [TODO](TODO.md). Jangan mengganti ID ketika scope atau owner berubah.
2. Catat kebutuhan dan alasan pada tabel keputusan di bawah. Tandai apakah itu keputusan produk, kontrak, keamanan, atau implementasi.
3. Petakan dampak ke backend bersama, admin, portal, adapter, data lama, dan verifikasi. Perubahan satu sisi belum selesai bila sisi lain membaca bentuk lama.
4. Perbarui dependensi, acceptance case, dan policy version yang terkena. Pertahankan ID pekerjaan lama sebagai `superseded` jika pekerjaan diganti.
5. Jika desain baru belum terbukti, tambahkan spike kecil dengan bukti keluar. Jangan menandai implementasi selesai karena spike atau mock berhasil.
6. Simpan SHA dan bukti pada tugas yang berubah. Keputusan lama tetap tercatat dengan referensi penggantinya.

| ID keputusan | Status                                  | Pilihan saat ini                                                                                                            | Dibuka ulang ketika                                                                                                 |
| ------------ | --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| D01          | Rekomendasi                             | Tenant dan membership milik platform; SSO autentikasi global.                                                               | Ada keputusan membangun layanan direktori identitas tersendiri.                                                     |
| D02          | Rekomendasi                             | Gunakan tenant kanonik, bukan pohon organisasi customer baru.                                                               | Audit menemukan model kanonik tidak bisa dipisahkan dari ERP tanpa perubahan material.                              |
| D03          | Rekomendasi                             | Portal di backend platform yang sama dengan route/session terpisah.                                                         | Rilis atau kebutuhan tim membuktikan frontend terpisah diperlukan.                                                  |
| D04          | Rekomendasi                             | Public hanya Faskes, dengan profil Apotek, Praktik Dokter, dan Klinik.                                                      | Produk berikutnya lolos adapter, policy, dan kapasitas.                                                             |
| D05          | Perlu keputusan produk sebelum aktivasi | Jumlah trial, fasilitas, dan undangan per organisasi; batas anti-abuse per principal.                                       | Paket dan hasil pilot sudah tersedia. Jangan membuka trial tanpa batas sementara menunggu.                          |
| D06          | Perlu keputusan produk sebelum aktivasi | Domain portal, retensi setelah expiry, jalur perpanjangan, dan kebijakan konversi.                                          | UX dan operasi pilot siap ditinjau. Tidak melakukan purge otomatis sebagai default.                                 |
| D07          | Rekomendasi                             | Repo/service belum dipisah. Keputusan ini tidak menunda split database D09.                                                 | Ada bukti kebutuhan rilis, isolasi gangguan, atau kepemilikan tim.                                                  |
| D08          | Rekomendasi                             | Layar peluncur memerlukan readiness nyata, hak aktif, membership, dan grant.                                                | Perubahan contract akses telah disetujui dan diuji pada kedua antarmuka.                                            |
| D09          | Arah disetujui user 2026-11-02          | Database control plane dipisah dari bisnis ERP; bridging ERP-Faskes belum dibangun.                                         | Detail implementasi mengikuti PRD CPDB dan bukti rehearsal; tidak kembali ke shared authority tanpa keputusan baru. |
| D10          | Keputusan desain PRD 2026-11-02         | Organization Foundation pada core_control_plane menjadi authority generik organisasi/hierarchy, tanpa ERP purchase.         | Jika storage Foundation diekstrak kelak, pertahankan kontrak, ID dan satu authority.                                |
| D11          | Target kebutuhan user 2026-11-02        | Sharing pasien, clinical records, katalog, dan stok memakai policy independen; hierarchy tidak memberikan sharing otomatis. | Capability baru diuji sesuai matriks OS dan tidak mengubah akses lewat fallback global.                             |
| D12          | Pilihan nama oleh user 2026-11-02       | Nama database target core_control_plane.                                                                                    | Perubahan nama memerlukan keputusan dan pembaruan konfigurasi/runbook yang eksplisit.                               |
| D13          | Keputusan desain PRD 2026-11-02         | Perubahan sharing berisi data adalah operasi migration; revision transaksi bukan grant baca permanen.                       | Perubahan kontrak memerlukan negative tests, concurrency, provenance, dan rollback sesuai domain.                   |

PRD baru ada pada [pemisahan database](../control-plane-database/PRD.md). Urutan CPDB-01, PF-01, CPDB-02 sampai CPDB-05, lalu PF-02 menggantikan asumsi lama bahwa seluruh unit portal berjalan di database gabungan. Keputusan D07 hanya berlaku untuk ekstraksi repo/service.

Kebijakan product Owner tidak boleh diganti oleh keputusan developer yang hanya ingin melewati gate. Catat proposal konkret dan dampaknya agar keputusan dapat ditinjau tanpa membaca seluruh chat.
