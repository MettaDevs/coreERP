# Rencana platform Metta dan portal customer

Rencana ini menyiapkan tenant organisasi bersama untuk admin internal dan customer.
Urutan perubahan adalah PF-01, PF-02, PF-03, PF-04, PF-05, lalu PF-06.
Urutan lintas program menjadi CPDB-01, PF-01, CPDB-02 sampai CPDB-05, lalu PF-02 dan seterusnya.
Database terpisah adalah prasyarat; ekstraksi service/repository tetap ditunda. Baca [PRD pemisahan](../control-plane-database/PRD.md).
ID PF adalah unit perubahan yang diusulkan, bukan PR GitHub yang sudah dibuat.
Dokumen ini hanya merencanakan. Aplikasi, server, enrollment, dan tenant yang dimatikan tidak diubah.
Baca [model dan batas sistem](index.md), [TODO](TODO.md), dan [aturan perubahan](CHANGES.md).

## How to read this

One box is one unit of work. Every box names the evidence. Check a box only when its evidence exists.
Tim mencatat owner, PR, SHA, penghambat, dan bukti pada TODO.
Eksekusi berikutnya memakai `poteto-mode/playbooks/autopilot-stack.md` setelah ada instruksi implementasi.
Rencana ini tidak memberi izin merge, deploy, membuka enrollment, atau menghapus data.
Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.
Budget kinerja di bawah adalah usulan gate, bukan angka hasil pengukuran.
Kalibrasikan fixture dan budget sebelum implementasi; perubahan budget dicatat pada CHANGES, bukan dilonggarkan setelah tes gagal.
Throughput checkpoint untuk penyusunan ini adalah n/a, pekerjaan dokumentasi tanpa eksekusi fitur.
Rigor untuk implementasi tinggi karena melintasi identity, kepemilikan, dan SQL Server.

Master organisasi/hierarchy dan metadata policy berada pada Organization Foundation di `core_control_plane`, tanpa entitlement ERP. [Spesifikasi sharing](../control-plane-database/ORGANIZATION-AND-SHARING.md) memisahkan hierarchy, patient registry, clinical-record grants, katalog obat, dan sumber stok. [SHARING-TODO](SHARING-TODO.md) mengatur enforcement SH-01/02 serta migration SH-03; tidak dinyatakan selesai oleh split database atau PF-02.

Rilis yang menawarkan pasien A/B terpisah tetapi stok bersama memerlukan SH-01 dan SH-02 terverifikasi sebelum PF-06 untuk mode tersebut. Mode baseline satu klinik dapat diuji tanpa mengklaim capability multi-klinik. UI/API tidak menawarkan kombinasi yang adapter belum tegakkan.

[Profil kelompok layanan](../control-plane-database/SERVICE-GROUP-PROFILES.md) memakai kelompok unit utama/pendukung untuk pasien, serta katalog obat/jasa organisasi pengelola bersama. SG-01 sampai SG-14 dan SH-04/05 menjadi gate mode tersebut. Database per kelompok tetap default legacy-compatible; konsolidasi banyak kelompok tidak dilakukan sebelum enforcement scope teruji.

[Profile titipan DEPO](../control-plane-database/SUPPLY-CUSTODY-AND-PAYMENT.md) memerlukan SH-06 serta CD yang relevan sebelum dipasarkan. Stock owner, custodian, unit realisasi, seller, collector dan beneficiary berbeda. Routing source saja tidak membuktikan ownership maupun settlement lengkap.

## Program checklist

### Arm the program

- [ ] Baca Principles pada skill poteto-mode serta batas data repository. Bukti berupa catatan awal run.
- [ ] Baca baseline source dan `git show origin/main:AGENTS.md` jika main masih trunk. Jika trunk berubah, catat ref yang benar sebelum menjalankan perintah.
- [ ] Konfirmasi D05/D06 dan detail identity mapping sebelum schema dibuat. Bukti berupa keputusan konkret pada CHANGES.
- [ ] Catat skip untuk prototipe UI pada tahap rencana ini. Tidak ada layar atau implementasi yang dibuat; wireflow dan prototype dilakukan pada unit terkait sebelum desain interaksi dikunci.

### Spawn owners

- [ ] Tetapkan satu owner per unit dan satu owner integrasi kontrak. Bukti berupa nama dan branch pada TODO.
- [ ] Pisahkan worktree implementasi dari perubahan sesi lain. Bukti berupa path, HEAD, dan status bersih untuk file yang akan diubah.
- [ ] Pisahkan review schema/platform, UI, dan adapter. Jangan mengubah tabel yang sama dari dua worker tanpa owner integrasi.

### PR mechanics

- [ ] Buka satu PR per unit yang dapat diuji mandiri; pecah lagi jika hasil review tidak dapat dinilai sebagai satu perubahan.
- [ ] Jalankan formatter dan pemeriksaan sesuai source yang berubah. Bukti berupa output pada SHA terakhir.
- [ ] Perbarui kontrak kedua sisi, migration, TODO, dan dokumentasi perilaku dalam perubahan yang sama.
- [ ] Jangan commit file UI sandbox milik sesi lain. Bukti berupa staging allowlist dan diff terakhir.

### Verdict and merge

- [ ] Nilai hasil sebagai VERIFIED, NOT VERIFIED, atau INCONCLUSIVE pada SHA yang tepat. Lint bukan bukti alur hidup.
- [ ] Pastikan seluruh check wajib hijau. Serahkan PR pada operator; merge tidak diotorisasi oleh rencana ini.
- [ ] Catat skip untuk `/loop 1h` karena tidak ada permintaan automation. Tidak ada timer dibuat. Gunakan status message hanya saat ada perubahan nyata, blocker, atau hasil yang perlu diketahui.

### Boot recipe

- [ ] Siapkan environment nonproduksi terisolasi beserta fixture, seed, domain, email uji, dan batas resource yang terdokumentasi.
- [ ] Rekam baseline trunk dan SHA head setiap repository. Jangan memakai database Windows/Bitbucket atau produksi legacy untuk uji.
- [ ] Jalankan browser melalui kontrol UI yang tersedia dan CLI melalui shell terkontrol. Simpan bukti tiap lane di `artifacts/PF-ID/lane-N/`.
- [ ] Jalankan lane independen dalam gelombang sesuai slot agent. Sepuluh skenario tidak mensyaratkan sepuluh agent bersamaan.
- [ ] Jangan membuat ulang tenant DivaPraktik atau mengaktifkan trial yang ditakedown sebagai efek samping fixture.

## Pisahkan identitas tenant dari instalasi ERP (PF-01)

**Depends on.** CPDB-01 pada TODO pemisahan database. PF-01 adalah satu pekerjaan bersama, tidak diduplikasi.

Pelaksanaan PF-01 dan batas fixture tercatat pada [laporan PF-01](PF-01.md). Backend yang diuji adalah `360a8cc2`; bukti live memakai session SSO sintetis yang sudah diverifikasi, HTTP nyata dan SQL committed. Login biasa tanpa User baru diuji terpisah melalui signed-SSO test. Tidak mengklaim login provider eksternal atau UI portal yang belum dibuat.

**Files.**

- [ ] Tinjau dan ubah `apps/core/app/Platform/Tenant/Actions/RegisterBusiness.php` sesuai scope PF-01. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/Tenant/Models/Tenant.php` sesuai scope PF-01. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/Tenant/Models/TenantMembership.php` sesuai scope PF-01. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/Identity/` sesuai scope PF-01. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/tests/Feature/Platform/Tenant/` sesuai scope PF-01. Bukti berupa diff allowlist.
- [ ] Nyatakan file baru dan migration yang diperlukan pada PR sebelum implementasi. Folder yang disebut di atas adalah sumber saat ini, bukan desain file baru yang dipaksakan.

**Build.**

- [x] Kerjakan PF-01a sampai PF-01d pada TODO. Pertahankan security role dan pemetaan issuer + subject. Pisahkan action tenant dari bootstrap ERP; jangan menambahkan sistem membership kedua. Bukti berupa diff, kontrak, dan hasil verifikasi unit ini.

**You see.**

- [x] Tenant dapat dibuat bagi principal yang sudah ada tanpa User duplikat, company ERP, entitlement ERP, atau environment ERP otomatis. Simpan hasil UI atau query read-only yang membuktikannya.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [x] Tambahkan kasus identitas yang sama membuat tenant baru, dua subject dengan email sama, tenant tanpa environment, dan larangan customer menjadi operator. Jalankan suite Tenant, Identity, dan Boundary. Catat perintah tepat dan output pada SHA head.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `inherit-parent` at the PR head. Gunakan model parent dan fixture nonproduksi; ini rencana pengujian, bukan hasil yang sudah dijalankan.

- [x] Lane 1. Regression lane against trunk. Bandingkan pembuatan tenant operator ERP; tenant lama tetap dapat dibuat dan diakses. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 2. Login principal lama, buat tenant organisasi saja; tidak ada User atau environment ERP tambahan. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 3. Login principal baru; satu identity mapping dan satu membership terbentuk. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 4. Kirim dua subject dengan email sama; tidak terjadi penggabungan identity otomatis. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 5. Buat tenant tanpa app_ids; tidak muncul entitlement ERP otomatis. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 6. Coba route operator sebagai customer; akses ditolak di server. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 7. Operator membuat tenant customer; operator tidak mendapatkan role Owner customer. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 8. Dua request create identik; kontrak idempotency mengembalikan hasil yang sama. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 9. Dua principal memilih slug sama; hanya satu reservasi sah, konflik dijelaskan. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [x] Lane 10. Pilih dua tenant dengan identity yang sama; scope membership tidak tertukar. Save `docs/todo/metta-platform-portal/evidence/pf-01/http-acceptance.json`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [x] Metric. Latency p95 create tenant tanpa provisioning dan jumlah principal/membership yang terbentuk.
- [x] Probe. Jalankan sampel trunk dan head secara bergantian pada fixture dan konkurensi yang sama. Catat query, antrean, error, jumlah sampel, dan apakah pekerjaan benar-benar selesai. Mulai 30 operasi untuk latency interaktif; durasi restore diukur terpisah karena mahal.
- [x] Baseline. Ukur trunk dahulu. Jika trunk belum mempunyai alur ini, catat ketiadaannya dan ukur kerja tambahan serta alur akhir secara absolut; jangan mengarang rasio.
- [x] Rule. Usulan p95 1 detik untuk transaksi tenant pada lingkungan uji terkontrol. Tidak ada record ganda pada request konkuren. Catat dan setujui penyesuaian fixture/budget sebelum run; pemeriksaan lint tidak memenuhi gate ini.

**Review gate.** None. PF-01 tidak merancang interaksi baru; audit CLI dan kontrak tetap wajib.

**Merge.**

- [ ] Catat verdict pada SHA final, check CI, review schema/keamanan, dan keputusan operator. Rencana berhenti pada handoff; tidak melakukan merge otomatis.
- [ ] Jika unit belum aman diaktifkan, pertahankan flag nonaktif tanpa membuat jalur fallback yang melewati authorization.

## Kaitkan fasilitas dan adapter Faskes ke tenant kanonik (PF-02)

**Depends on.** PF-01 dan CPDB-05. Integrasi kepemilikan Faskes memakai database pusat yang telah dipisahkan dan diverifikasi.

**Files.**

- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Models/ManagedProductTenant.php` sesuai scope PF-02. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Products/CreateProductTenant.php` sesuai scope PF-02. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Products/ProductTenantProvisioner.php` sesuai scope PF-02. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/config/managed_products.php` sesuai scope PF-02. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/database/migrations/` sesuai scope PF-02. Bukti berupa diff allowlist.
- [ ] Nyatakan file baru dan migration yang diperlukan pada PR sebelum implementasi. Folder yang disebut di atas adalah sumber saat ini, bukan desain file baru yang dipaksakan.

**Build.**

- [ ] Kerjakan PF-02a sampai PF-02h. Definisikan kontrak registry produk dan resource fasilitas/environment, serta migrasi owner lama yang idempotent. Pertahankan adapter SQL Server dan ID deployment. Bukti berupa diff, kontrak, dan hasil verifikasi unit ini.

**You see.**

- [ ] Tenant organisasi yang sama mempunyai fasilitas Faskes, snapshot policy, dan operasi yang dapat dilanjutkan. Data lama terpetakan tanpa restore database. Simpan hasil UI atau query read-only yang membuktikannya.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Tambahkan kasus migrasi ulang, owner ambigu, policy berubah ketika operation berjalan, reservasi kuota konkuren, dan penolakan product/profile yang tidak didukung. Jalankan suite ManagedProductTenant, ProductRegistration, dan Boundary. Catat perintah tepat dan output pada SHA head.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `inherit-parent` at the PR head. Gunakan model parent dan fixture nonproduksi; ini rencana pengujian, bukan hasil yang sudah dijalankan.

- [ ] Lane 1. Regression lane against trunk. Rekam satu instance lama; UUID, database, URL, release, dan isi data tetap setelah pemetaan. Save `artifacts/PF-02/lane-1/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 2. Jalankan dry-run migrasi; daftar mapping dan konflik tersedia tanpa perubahan resource. Save `artifacts/PF-02/lane-2/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 3. Jalankan migrasi dua kali; eksekusi kedua tidak menambah tenant atau membership. Save `artifacts/PF-02/lane-3/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 4. Baca record buatan operator tanpa owner; record tetap pending claim, tidak otomatis dimiliki email yang cocok. Save `artifacts/PF-02/lane-4/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 5. Buat dua fasilitas pada organisasi yang diizinkan policy uji; database dan credential tetap terisolasi. Save `artifacts/PF-02/lane-5/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 6. Ubah policy saat provisioning berjalan; operation lama tetap memakai snapshot semula. Save `artifacts/PF-02/lane-6/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 7. Matikan produk di registry; request langsung yang memalsukan produk tetap ditolak. Save `artifacts/PF-02/lane-7/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 8. Kirim request konkuren melewati kuota; jumlah reservasi tidak melampaui policy. Save `artifacts/PF-02/lane-8/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 9. Crash setelah create database; retry melanjutkan tanpa restore database yang telah dibuat. Save `artifacts/PF-02/lane-9/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 10. Coba memakai environment Faskes sebagai target migration ERP; penolakan adapter terjadi sebelum akses database. Save `artifacts/PF-02/lane-10/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Latency p95 admission provisioning, queue delay, durasi sampai ready, serta jumlah resource per operation.
- [ ] Probe. Jalankan sampel trunk dan head secara bergantian pada fixture dan konkurensi yang sama. Catat query, antrean, error, jumlah sampel, dan apakah pekerjaan benar-benar selesai. Mulai 30 operasi untuk latency interaktif; durasi restore diukur terpisah karena mahal.
- [ ] Baseline. Ukur trunk dahulu. Jika trunk belum mempunyai alur ini, catat ketiadaannya dan ukur kerja tambahan serta alur akhir secara absolut; jangan mengarang rasio.
- [ ] Rule. Usulan admission p95 1 detik dan ready maksimal 180 detik pada fixture seed serta mesin uji yang dicatat. Resource duplikat harus nol. Catat dan setujui penyesuaian fixture/budget sebelum run; pemeriksaan lint tidak memenuhi gate ini.

**Review gate.** None. PF-02 tidak merancang interaksi baru; audit CLI dan kontrak tetap wajib.

**Merge.**

- [ ] Catat verdict pada SHA final, check CI, review schema/keamanan, dan keputusan operator. Rencana berhenti pada handoff; tidak melakukan merge otomatis.
- [ ] Jika unit belum aman diaktifkan, pertahankan flag nonaktif tanpa membuat jalur fallback yang melewati authorization.

## Bangun portal dan selaraskan admin pada action bersama (PF-03)

**Depends on.** PF-02.

**Files.**

- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Products/` sesuai scope PF-03. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/control-plane/app/Customers/` sesuai scope PF-03. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/control-plane/app/Products/` sesuai scope PF-03. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/resources/js/` sesuai scope PF-03. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/routes/` sesuai scope PF-03. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/contracts/internal/` sesuai scope PF-03. Bukti berupa diff allowlist.
- [ ] Nyatakan file baru dan migration yang diperlukan pada PR sebelum implementasi. Folder yang disebut di atas adalah sumber saat ini, bukan desain file baru yang dipaksakan.

**Build.**

- [ ] Kerjakan PF-03a sampai PF-03f. Buat surface customer terpisah dari route operator. Nyatakan folder portal baru saat detail desain disetujui; gunakan SDK UI dan kontrak platform yang sama. Bukti berupa diff, kontrak, dan hasil verifikasi unit ini.
- [ ] Batasi PF-03 pada create/select tenant, fasilitas, serta pembacaan calon Owner pending. UI kirim/terima undangan baru diaktifkan bersama kontrak dan per-member binding pada PF-04. Bukti berupa tidak adanya tombol undangan yang belum dapat diselesaikan.

**You see.**

- [ ] Customer melihat organisasi dan fasilitas yang diizinkan. Admin melihat record yang sama. Refresh dan login ulang melanjutkan operation, bukan membuat instance baru. Simpan hasil UI atau query read-only yang membuktikannya.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Tambahkan kasus context tenant, registry publik, daftar berhalaman, launch sebelum ready, dan parity operator/customer. Jalankan suite portal yang ditambahkan, ProductRegistration, dan suite konsol yang terdampak. Catat perintah tepat dan output pada SHA head.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `inherit-parent` at the PR head. Gunakan model parent dan fixture nonproduksi; ini rencana pengujian, bukan hasil yang sudah dijalankan.

- [ ] Lane 1. Regression lane against trunk. Rekam flow operator membuat Faskes; hasil profil, seed, expiry, dan status tetap setara. Save `artifacts/PF-03/lane-1/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 2. Login customer tanpa tenant; halaman meminta organisasi sebelum fasilitas. Save `artifacts/PF-03/lane-2/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 3. Login customer dengan satu tenant; portal menampilkan fasilitas miliknya. Save `artifacts/PF-03/lane-3/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 4. Login customer dengan dua tenant; pemilih tidak membocorkan fasilitas tenant lain. Save `artifacts/PF-03/lane-4/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 5. Operator membuat fasilitas pada tenant yang dipilih; portal customer membaca record yang sama. Save `artifacts/PF-03/lane-5/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 6. Buat setiap profil Faskes; unit bisnis, judul, logo Metta, dan batas akses mengikuti konfigurasi. Save `artifacts/PF-03/lane-6/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 7. Refresh ketika operation berjalan; operation ID dan jumlah database tetap sama. Save `artifacts/PF-03/lane-7/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 8. Putus sesi lalu login lagi; status terakhir dapat dilanjutkan tanpa mengisi ulang permintaan. Save `artifacts/PF-03/lane-8/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 9. Gagalkan worker; UI memberi pesan dan retry yang tidak menggandakan resource. Save `artifacts/PF-03/lane-9/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 10. Buka facility belum ready atau milik tenant lain melalui URL langsung; server menolak launch. Save `artifacts/PF-03/lane-10/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Latency p95 daftar tenant/fasilitas dan respons status, jumlah request polling per browser, serta waktu sampai status siap terlihat.
- [ ] Probe. Jalankan sampel trunk dan head secara bergantian pada fixture dan konkurensi yang sama. Catat query, antrean, error, jumlah sampel, dan apakah pekerjaan benar-benar selesai. Mulai 30 operasi untuk latency interaktif; durasi restore diukur terpisah karena mahal.
- [ ] Baseline. Ukur trunk dahulu. Jika trunk belum mempunyai alur ini, catat ketiadaannya dan ukur kerja tambahan serta alur akhir secara absolut; jangan mengarang rasio.
- [ ] Rule. Usulan p95 read 750 milidetik pada fixture yang sama; maksimal satu polling aktif per halaman. Jangan polling ketika tab tersembunyi tanpa kebutuhan. Catat dan setujui penyesuaian fixture/budget sebelum run; pemeriksaan lint tidak memenuhi gate ini.

**Review gate.** Operator meninjau perubahan interaksi sebelum merge.

- [ ] Simpan screenshot keadaan normal, gagal, dan akses ditolak untuk operator. Bukti berada pada folder unit.
- [ ] Simpan video 30 sampai 60 detik alur customer/operator yang berubah. Catat SHA pada catatan bukti.
- [ ] Tunjukkan screenshot dan video kepada operator untuk review interaksi. Tidak menganggap hasil compile sebagai persetujuan UX.

**Merge.**

- [ ] Catat verdict pada SHA final, check CI, review schema/keamanan, dan keputusan operator. Rencana berhenti pada handoff; tidak melakukan merge otomatis.
- [ ] Jika unit belum aman diaktifkan, pertahankan flag nonaktif tanpa membuat jalur fallback yang melewati authorization.

## Undang anggota dan hubungkan ke akun Faskes masing-masing (PF-04)

**Depends on.** PF-02 dan PF-03.

**Files.**

- [ ] Tinjau dan ubah `apps/core/app/Platform/Tenant/Actions/RedeemInvitation.php` sesuai scope PF-04. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/Access/` sesuai scope PF-04. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/Identity/` sesuai scope PF-04. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Products/` sesuai scope PF-04. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/tests/Feature/Platform/Identity/` sesuai scope PF-04. Bukti berupa diff allowlist.
- [ ] Nyatakan file baru dan migration yang diperlukan pada PR sebelum implementasi. Folder yang disebut di atas adalah sumber saat ini, bukan desain file baru yang dipaksakan.

**Build.**

- [ ] Kerjakan PF-04a sampai PF-04e dan kontrak member provisioning pada repository TrialFaskes. Terapkan invitation, grant environment, local user binding, dan revoke sebagai satu kemampuan yang lengkap. Bukti berupa diff, kontrak, dan hasil verifikasi unit ini.
- [ ] Gunakan durable operation/receipt hasil CPDB-03. Jika membership pusat committed tetapi akun lokal gagal dibuat, grant tetap pending dan retry melanjutkan operation yang sama. Bukti berupa fault injection pada kedua commit.

**You see.**

- [ ] Dua anggota masuk sebagai dua mUser berbeda. Penerima undangan tidak perlu membuat tenant. Revoke menghentikan sesi dan akses lokal yang diberikan grant. Simpan hasil UI atau query read-only yang membuktikannya.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Tambahkan kasus invite replay, wrong subject, recipient verification, concurrent accept, Owner terakhir, role ceiling, dan revoke terlambat. Jalankan suite Invitation, Access, Identity, serta harness adapter Faskes. Catat perintah tepat dan output pada SHA head.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `inherit-parent` at the PR head. Gunakan model parent dan fixture nonproduksi; ini rencana pengujian, bukan hasil yang sudah dijalankan.

- [ ] Lane 1. Regression lane against trunk. Login Owner dan dukungan metta tetap berfungsi tanpa memberi akun itu kepada invitee. Save `artifacts/PF-04/lane-1/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 2. Undang identity baru; setelah acceptance ada satu membership dan akun lokal sendiri. Save `artifacts/PF-04/lane-2/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 3. Undang identity lama yang mempunyai tenant lain; membership lama tetap utuh. Save `artifacts/PF-04/lane-3/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 4. Terima undangan memakai identity yang salah; tidak ada membership atau local binding baru. Save `artifacts/PF-04/lane-4/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 5. Gunakan undangan expired atau replay; tidak ada perluasan akses. Save `artifacts/PF-04/lane-5/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 6. Dua acceptance konkuren untuk undangan sama; hasil tetap satu membership/binding. Save `artifacts/PF-04/lane-6/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 7. Beri akses hanya satu fasilitas; fasilitas lain menolak launch meskipun tenant sama. Save `artifacts/PF-04/lane-7/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 8. Masuk sebagai dua anggota dengan role berbeda; audit transaksi memuat pelaku berbeda. Save `artifacts/PF-04/lane-8/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 9. Cabut anggota yang sedang login; sesi aktif dan login lokal terkait ditolak dalam batas revoke. Save `artifacts/PF-04/lane-9/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 10. Coba menghapus Owner terakhir dan mengubah akun metta; server menolak, transfer sah mempertahankan pengelola. Save `artifacts/PF-04/lane-10/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Latency p95 acceptance tanpa menunggu email dan waktu dari revoke committed sampai akses lokal benar-benar ditolak.
- [ ] Probe. Jalankan sampel trunk dan head secara bergantian pada fixture dan konkurensi yang sama. Catat query, antrean, error, jumlah sampel, dan apakah pekerjaan benar-benar selesai. Mulai 30 operasi untuk latency interaktif; durasi restore diukur terpisah karena mahal.
- [ ] Baseline. Ukur trunk dahulu. Jika trunk belum mempunyai alur ini, catat ketiadaannya dan ukur kerja tambahan serta alur akhir secara absolut; jangan mengarang rasio.
- [ ] Rule. Usulan acceptance p95 1 detik di platform. Revoke harus menolak launch segera dan sesi aktif maksimal 60 detik; bila adapter gagal, akses diblokir sampai rekonsiliasi aman. Catat dan setujui penyesuaian fixture/budget sebelum run; pemeriksaan lint tidak memenuhi gate ini.

**Review gate.** Operator meninjau perubahan interaksi sebelum merge.

- [ ] Simpan screenshot keadaan normal, gagal, dan akses ditolak untuk operator. Bukti berada pada folder unit.
- [ ] Simpan video 30 sampai 60 detik alur customer/operator yang berubah. Catat SHA pada catatan bukti.
- [ ] Tunjukkan screenshot dan video kepada operator untuk review interaksi. Tidak menganggap hasil compile sebagai persetujuan UX.

**Merge.**

- [ ] Catat verdict pada SHA final, check CI, review schema/keamanan, dan keputusan operator. Rencana berhenti pada handoff; tidak melakukan merge otomatis.
- [ ] Jika unit belum aman diaktifkan, pertahankan flag nonaktif tanpa membuat jalur fallback yang melewati authorization.

## Lengkapi lifecycle dan pemulihan tanpa mengubah data siap (PF-05)

**Depends on.** PF-02 dan PF-04.

**Files.**

- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Products/ProductTenantProvisioner.php` sesuai scope PF-05. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Console/ReconcileProductTenants.php` sesuai scope PF-05. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/Environment/Models/` sesuai scope PF-05. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/app/Platform/ControlPlane/Models/` sesuai scope PF-05. Bukti berupa diff allowlist.
- [ ] Nyatakan file baru dan migration yang diperlukan pada PR sebelum implementasi. Folder yang disebut di atas adalah sumber saat ini, bukan desain file baru yang dipaksakan.

**Build.**

- [ ] Kerjakan PF-05a sampai PF-05e beserta adapter dan runbook TrialFaskes. Pisahkan desired state, status operasi, hak produk, dan keanggotaan; tambahkan observabilitas tanpa secret. Bukti berupa diff, kontrak, dan hasil verifikasi unit ini.

**You see.**

- [ ] Suspend bertahan melewati restart dan hasil worker lama. Expiry tidak menghapus tenant. Restore tidak mengaktifkan kembali anggota yang telah dicabut. Simpan hasil UI atau query read-only yang membuktikannya.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Tambahkan kasus stale lease, fencing revision, expiry, resume, restore/revoke, resource yatim, dan retry setelah timeout. Jalankan lifecycle dan provisioning suites yang terdampak. Catat perintah tepat dan output pada SHA head.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `inherit-parent` at the PR head. Gunakan model parent dan fixture nonproduksi; ini rencana pengujian, bukan hasil yang sudah dijalankan.

- [ ] Lane 1. Regression lane against trunk. Ready instance dengan sentinel data tetap utuh setelah reconcile berulang. Save `artifacts/PF-05/lane-1/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 2. Suspend ketika worker belum selesai; hasil ready lama tidak membuka kembali aplikasi. Save `artifacts/PF-05/lane-2/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 3. Restart worker setelah suspend; desired state tetap berlaku. Save `artifacts/PF-05/lane-3/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 4. Biarkan trial habis; aplikasi terkait ditolak sementara tenant dan membership tetap ada. Save `artifacts/PF-05/lane-4/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 5. Perpanjang trial dengan operasi sah; expiry baru tercatat dan retry tidak menambah masa berlaku lagi. Save `artifacts/PF-05/lane-5/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 6. Simulasikan crash pada setiap batas create DB, akun, container, routing, dan readiness; hasil akhir tidak ganda. Save `artifacts/PF-05/lane-6/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 7. Kembalikan backup lama ke target uji; anggota yang dicabut tetap tidak dapat login. Save `artifacts/PF-05/lane-7/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 8. Penuhi batas disk/capacity fixture; provisioning baru berhenti tanpa mematikan fasilitas siap. Save `artifacts/PF-05/lane-8/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 9. Gagalkan adapter revoke; dashboard menyatakan belum aman dan peluncuran anggota tetap ditolak. Save `artifacts/PF-05/lane-9/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 10. Arsipkan fixture melalui alur recoverable; identitas dan resource lain tetap tersedia tanpa purge otomatis. Save `artifacts/PF-05/lane-10/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Waktu deteksi operasi macet, durasi reconcile, waktu expiry enforcement, dan waktu restore sampai siap.
- [ ] Probe. Jalankan sampel trunk dan head secara bergantian pada fixture dan konkurensi yang sama. Catat query, antrean, error, jumlah sampel, dan apakah pekerjaan benar-benar selesai. Mulai 30 operasi untuk latency interaktif; durasi restore diukur terpisah karena mahal.
- [ ] Baseline. Ukur trunk dahulu. Jika trunk belum mempunyai alur ini, catat ketiadaannya dan ukur kerja tambahan serta alur akhir secara absolut; jangan mengarang rasio.
- [ ] Rule. Usulan deteksi macet maksimal dua interval reconcile ditambah lease yang dicatat. Expiry ditolak pada request berikutnya. Angka restore dicatat sebagai baseline sebelum SLA disepakati. Catat dan setujui penyesuaian fixture/budget sebelum run; pemeriksaan lint tidak memenuhi gate ini.

**Review gate.** None. PF-05 tidak merancang interaksi baru; audit CLI dan kontrak tetap wajib.

**Merge.**

- [ ] Catat verdict pada SHA final, check CI, review schema/keamanan, dan keputusan operator. Rencana berhenti pada handoff; tidak melakukan merge otomatis.
- [ ] Jika unit belum aman diaktifkan, pertahankan flag nonaktif tanpa membuat jalur fallback yang melewati authorization.

## Verifikasi dua sisi dan pindahkan pintu daftar ke portal (PF-06)

**Depends on.** PF-03, PF-04, dan PF-05.

**Files.**

- [ ] Tinjau dan ubah `docs/todo/metta-platform-portal/` sesuai scope PF-06. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/contracts/internal/` sesuai scope PF-06. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/control-plane/resources/js/` sesuai scope PF-06. Bukti berupa diff allowlist.
- [ ] Tinjau dan ubah `apps/core/resources/js/` sesuai scope PF-06. Bukti berupa diff allowlist.
- [ ] Nyatakan file baru dan migration yang diperlukan pada PR sebelum implementasi. Folder yang disebut di atas adalah sumber saat ini, bukan desain file baru yang dipaksakan.

**Build.**

- [ ] Kerjakan PF-06a sampai PF-06i. Perubahan CTA dan bridge landing dibuat di repository landing. Pertahankan enrollment OFF sampai gate dan instruksi aktivasi terpenuhi. Bukti berupa diff, kontrak, dan hasil verifikasi unit ini.

**You see.**

- [ ] Customer dapat daftar, membuat tenant, mengundang anggota, membuat fasilitas, dan masuk dengan akun berbeda. Admin mengelola tenant yang sama. Penutupan trial tidak mematikan landing atau tenant lain. Simpan hasil UI atau query read-only yang membuktikannya.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Jalankan contract coverage, suites backend dan konsol yang berubah, pemeriksaan TypeScript, build frontend, serta tes bridge landing dan harness Faskes. Bekukan SHA setiap repository yang dirilis. Catat perintah tepat dan output pada SHA head.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `inherit-parent` at the PR head. Gunakan model parent dan fixture nonproduksi; ini rencana pengujian, bukan hasil yang sudah dijalankan.

- [ ] Lane 1. Regression lane against trunk. Rekam CMS landing dan tenant ERP lama; rilis portal tidak mengubah login CMS atau route operator. Save `artifacts/PF-06/lane-1/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 2. Customer baru login SSO, buat organisasi dan fasilitas, lalu benar-benar masuk Faskes. Save `artifacts/PF-06/lane-2/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 3. Customer undangan masuk organisasi yang ada tanpa membuat organisasi atau trial tambahan. Save `artifacts/PF-06/lane-3/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 4. Operator membuat tenant sebelum Owner daftar; hanya penerima sah yang dapat claim. Save `artifacts/PF-06/lane-4/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 5. Uji semua profil Faskes dengan seed, branding, unit bisnis, dan metta yang benar. Save `artifacts/PF-06/lane-5/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 6. Uji Origin, Referrer-Policy, cookie cross-site, callback, dan tiket sekali pakai pada browser nyata. Save `artifacts/PF-06/lane-6/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 7. Coba URL dan operation ID tenant lain; respons dan audit tidak membocorkan data. Save `artifacts/PF-06/lane-7/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 8. Ulangi request setelah timeout dan login ulang; tidak ada instance atau masa trial ganda. Save `artifacts/PF-06/lane-8/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 9. Cabut anggota, restore fixture, dan restart worker; akses yang dicabut tetap ditolak. Save `artifacts/PF-06/lane-9/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.
- [ ] Lane 10. Tutup enrollment dan suspend fixture; landing, produk lain, dan tenant lain tetap berjalan. Save `artifacts/PF-06/lane-10/result.png`. Pass when hasil yang disebut terbukti pada UI atau output CLI nyata dan query pendukung sesuai; log negatif disimpan tanpa secret.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Waktu customer dari submit fasilitas sampai siap, latency portal p95, failure rate provisioning, serta latency jalur ERP lama.
- [ ] Probe. Jalankan sampel trunk dan head secara bergantian pada fixture dan konkurensi yang sama. Catat query, antrean, error, jumlah sampel, dan apakah pekerjaan benar-benar selesai. Mulai 30 operasi untuk latency interaktif; durasi restore diukur terpisah karena mahal.
- [ ] Baseline. Ukur trunk dahulu. Jika trunk belum mempunyai alur ini, catat ketiadaannya dan ukur kerja tambahan serta alur akhir secara absolut; jangan mengarang rasio.
- [ ] Rule. Usulan flow submit sampai siap maksimal 180 detik pada seed/mesin uji yang sama, error tak terduga nol pada matriks. Jalur ERP lama tidak boleh regresi p95 lebih dari 20 persen pada probe yang setara. Catat dan setujui penyesuaian fixture/budget sebelum run; pemeriksaan lint tidak memenuhi gate ini.

**Review gate.** Operator meninjau perubahan interaksi sebelum merge.

- [ ] Simpan screenshot keadaan normal, gagal, dan akses ditolak untuk operator. Bukti berada pada folder unit.
- [ ] Simpan video 30 sampai 60 detik alur customer/operator yang berubah. Catat SHA pada catatan bukti.
- [ ] Tunjukkan screenshot dan video kepada operator untuk review interaksi. Tidak menganggap hasil compile sebagai persetujuan UX.

**Merge.**

- [ ] Catat verdict pada SHA final, check CI, review schema/keamanan, dan keputusan operator. Rencana berhenti pada handoff; tidak melakukan merge otomatis.
- [ ] Jika unit belum aman diaktifkan, pertahankan flag nonaktif tanpa membuat jalur fallback yang melewati authorization.

## Close the program

- [ ] Semua tugas wajib verified dengan PR/SHA dan bukti pada TODO. Pekerjaan LATER tidak menjadi syarat public Faskes.
- [ ] Owner produk menyelesaikan D05/D06 dan owner operasi menyelesaikan runbook serta rollback yang diuji.
- [ ] Catat versi backend, portal, landing, adapter, schema, dan policy yang cocok sebelum deploy.
- [ ] Aktifkan enrollment hanya melalui instruksi terpisah setelah gate lulus. Pastikan takedown tenant yang disengaja tetap berlaku.
- [ ] Perbarui dokumentasi kanonik untuk keputusan yang benar-benar diimplementasikan. Daftar usulan tidak menggantikan aturan runtime.

## Appendix A. Prototype evidence

Belum ada prototype UI atau pengukuran kinerja untuk target portal ini.
Baseline berasal dari source audit yang dirangkum pada index, bukan dari demonstrasi fitur baru.
PF-01 memvalidasi pemisahan action melalui fixture identity/tenant.
PF-03 menambahkan prototype interaksi create/join/select tenant sebelum UI final.
PF-04 membuktikan dua user lokal berbeda sebelum membuka invitation kepada customer.
Hasil audit source tidak boleh diberi label sebagai live verification.

## Appendix B. Alternatives rejected

| Pilihan                                                  | Alasan tidak dipilih sekarang                                                         |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Tenant organisasi di SSO dan di Core sekaligus           | Menghasilkan dua otoritas membership dan risiko drift.                                |
| Customer memakai admin console operator                  | Permission dan operasi platform terlalu luas untuk akun customer.                     |
| Buat backend portal independen dahulu                    | Menambah sinkronisasi dan network hop sebelum batas domain diselesaikan.              |
| Semua anggota memakai owner Faskes                       | Audit pelaku, role, dan revoke per orang tidak dapat dipenuhi.                        |
| Memasukkan SQL Server Faskes ke provisioner database ERP | Menyamakan nama environment tidak membuat engine atau migration kompatibel.           |
| Konfigurasi menjalankan skrip arbitrary                  | Produk baru harus memiliki adapter yang direview, bukan akses eksekusi dari metadata. |

## Appendix C. Risks

| Risiko                                                      | Unit dan pemeriksaan                                                                                                     |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Principal ProductCustomer dan User menjadi identity ganda   | PF-01 memutuskan mapping eksplisit dan penanganan konflik sebelum migration.                                             |
| Record buatan operator diklaim oleh orang yang salah        | PF-02 inventaris owner dan undangan kepemilikan; tidak ada autoclaim email.                                              |
| Grant dicabut tetapi sesi/password lokal tetap aktif        | PF-04 memeriksa login langsung dan sesi aktif; PF-05 memeriksa restore.                                                  |
| Hasil worker lama menghidupkan tenant yang disuspend        | PF-05 desired state dan fencing; PF-06 menguji takedown terpisah dari enrollment.                                        |
| Registry mengubah environment berjalan diam-diam            | PF-02 snapshot; CHANGES mengatur update eksplisit.                                                                       |
| Profile upgrade membuka akses atau merusak data             | Ditunda sebagai operasi migration; bukan perubahan metadata saja.                                                        |
| Legacy hardcode belum terjangkau fixture                    | PF-03/PF-06 mencatat endpoint dan workflow belum diuji sebagai gap; tidak mengklaim semua bisnis lolos.                  |
| Tool kontrol UI/CLI yang diwajibkan playbook tidak tersedia | Gunakan CUA dan shell yang tersedia dengan bukti nyata; tulis keterbatasan, jangan mengklaim tool yang tidak dijalankan. |
| Proyeksi kapasitas trial belum diukur                       | PF-02 admission dan PF-05 metrik disk/queue; public cap tidak dibuka tanpa batas.                                        |

## Appendix D. Links and reading list

- [Model dan batas kepemilikan](index.md).
- [TODO tim](TODO.md).
- [Aturan perubahan dan keputusan](CHANGES.md).
- [Batas konsol](../../dev/31-admin-erp-control-plane.md).
- [Identity dan security role](../../dev/09-identity-and-access.md).
- [SSO](../../dev/32-sso.md).
- Baca skill how pada perubahan boundary PF-01/PF-02.
- Baca skill interrogate untuk keputusan identity, migration kepemilikan, dan grant/revoke sebelum shipping.
- Ikuti skill coreerp-konsol, coreerp-architecture, api-design, dan standar module sesuai file yang benar-benar disentuh.
- Audit keputusan penyusunan disimpan lokal pada `.audit/metta-platform-portal/decisions.tsv`. Hasil review rencana tidak menggantikan review implementasi.
