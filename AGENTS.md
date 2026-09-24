# CoreERP

- **Belum ada satu pun pelanggan di CoreERP.** Per 13 September 2026, nol pelanggan sungguhan dan nol lingkungan produksi yang dipakai orang luar **di aplikasi ini**. Setiap tenant, lingkungan, dan baris data yang ada hari ini adalah data percobaan. Jangan menulis kalimat, keputusan, atau peringatan yang berasumsi ada pelanggan yang sedang memakai sistem ini, dan jangan menahan perubahan berisiko demi data yang sebenarnya boleh dibuang. Baris ini berlaku sampai pemilik produk menghapusnya sendiri; jangan hapus atas inisiatif sendiri meskipun terlihat sudah tidak berlaku.

  **Yang tidak boleh disimpulkan dari baris di atas: bahwa tidak ada pelanggan sama sekali.** Pelanggan yang sudah berjalan ada di **sistem lama**, tersebar di beberapa server, dan tiap pelanggan memegang salinan source code serta databasenya sendiri-sendiri. Bentuk itulah yang membuat tim kewalahan mengelolanya, dan CoreERP adalah jawabannya. Konsekuensinya untuk penilaian teknis: dukungan banyak server **bukan** spekulasi — ia tujuan pindahan yang sudah pasti datang. Yang belum pasti hanya urutannya, dan sampai ada satu pelanggan yang benar-benar pindah, membangun mekanismenya lebih dulu tetap membangun untuk beban yang belum ada.
- Desain kanonik ada di `docs/dev/README.md` dan di Dynamic 365 https://learn.microsoft.com/en-us/dynamics365/. Buka hanya dokumen yang relevan dengan tugas; dokumen konsep lama bersifat historis.
- Jaga perubahan dan dependency tetap minimal. Jangan membuat abstraksi atau compatibility layer spekulatif kalau ada yang bingung langsung tanyakan saya, stop berfikir sampainkonteks jelas. /
- Untuk perubahan source atau audit keterbacaan, gunakan skill `code-formatting`: pakai formatter yang sudah ada, pisahkan perubahan format dari perubahan perilaku, dan jangan menambah atau mengubah kebijakan formatter tanpa persetujuan eksplisit.
- Teks UI untuk end user—termasuk hint, label, dialog, empty state, error, dan status—wajib memakai bahasa sehari-hari yang menjelaskan tindakan atau dampaknya bagi pengguna. Jangan tampilkan istilah internal seperti entitlement, artifact, deployment/installation registry, `TenantContext`, `tenant_id`, atau istilah arsitektur lain kecuali layar memang ditujukan untuk developer/operator teknis. Bahasa sehari-hari bukan berarti menerjemahkan istilah teknis yang sudah baku: push, pull, scope, token, signing secret, webhook, endpoint, dan GET/POST/PUT ditulis apa adanya di layar, pesan, kontrak, dan docs — bukan "dorong", "tarik", "cakupan", atau "rahasia penanda tangan". Batasnya ada di skill `coreerp-docs`, bagian *Where the loanword boundary sits*.
- Bantuan konteks UI mengikuti [standar bantuan kontekstual pada halaman dan field](docs/dev/02-module-standard.md#bantuan-kontekstual-pada-halaman-dan-field): setiap field boleh diberi detail opsional, tetapi hanya field yang rumit atau tidak langsung jelas yang perlu help text. Hover sekitar satu detik menampilkan bantuan sementara dan bantuan hilang saat cursor berpindah. Klik label/judul field menampilkan bantuan yang tetap terbuka walau cursor meninggalkan field; klik label/judul itu lagi menutupnya. Pola toggle ini juga harus tersedia lewat focus/keyboard. Jangan menambahkan ikon atau deskripsi pada setiap judul page/card, dan jangan mengulang arti field di header. Teks wajib, validasi, dan error tetap terlihat. Aturan ini mengatur isi dan perilaku; implementasi komponen UI dapat berubah berkala.
- Hanya ada satu bentuk penempatan: module di bawah `modules/` di dalam repo ini, berjalan di runtime Core dan memakai database tenant yang sama. Bentuk lama — app di repo `app-erp-*` dengan API, UI, database, dan container sendiri — dibuang pada 10 September 2026 beserta seluruh kode yang melayaninya. Bacalah bagian **Satu bentuk module** di bawah sebelum menilai sebuah pull request melanggar aturan atau tidak.
- Sebelum menyentuh `apps/control-plane` (admin.erp), kontrak API agen, tabel milik sisi pusat, autentikasi operator, atau SSO di kedua aplikasi, buka skill `coreerp-konsol`. Konteksnya ada di [`docs/dev/31-admin-erp-control-plane.md`](docs/dev/31-admin-erp-control-plane.md) dan [`docs/dev/32-sso.md`](docs/dev/32-sso.md). Konsol sengaja tidak punya migration: tabel baru miliknya lahir di Core, beserta model penanda `OwnedByControlPlane`.
- Setiap surface yang dipanggil app lain wajib ada di contract: route `internal/v1`, event yang diterbitkan, dan webhook. Permukaan app-ke-Core ditulis tangan di `apps/core/contracts/internal/` (satu berkas per path dan per komponen, satu akar per pembaca, satu akar per domain untuk integrasi sistem luar) lalu dirakit `python contracts/bundle.py` menjadi `contracts/openapi-internal.yaml` dan `contracts/terbit/`; berkas hasil tidak pernah diedit tangan. Setelah mengubah `routes/api.php` atau kontrak, jalankan `python contracts/bundle.py` lalu `python contracts/check-contract-coverage.py` dari `apps/core` — tidak ada test yang gagal karena contract kurang lengkap, hanya cek ini yang menangkapnya, dan keduanya juga berjalan di CI. Spesifikasi integrasi domain terbit tanpa login di portal `/docs`. Aturan lengkap ada pada **Contract decision gate** di `.agents/skills/coreerp-architecture/SKILL.md`.
- Pilih transport dari maknanya: REST untuk perintah/permintaan data, event untuk fakta yang sudah terjadi. Jangan mengontrakkan panggilan REST sebagai channel AsyncAPI atau sebaliknya.
- Contract lintas app ditulis tangan, bukan hasil generate. Output Scramble (`contracts/openapi.json`) hanya sah untuk endpoint yang consumer-nya cuma UI Control Plane sendiri.
- Event memakai nama `module.aggregate.action.vN` dan envelope penuh sesuai `docs/dev/04-api-and-integration.md`. Versi yang sudah terbit tidak diubah di tempat — tambah/hapus field wajib berarti `vN+1`.
- Contract menggambarkan keadaan sebenarnya. Kalau kode belum memenuhi aturan kanonik, tulis gap-nya di `info.description`; jangan menuliskan bentuk ideal yang belum dikirim kode.
- Mengubah contract event berarti mengubah kedua sisi: repo penerbit dan repo penerima, dalam pekerjaan yang sama.
- **Data acuan baru untuk pelanggan yang sudah ada ditulis sebagai migration, bukan seeder.** `seeded_at` menandai sekali seumur pemasangan — itu memang bentuk yang benar untuk data awal, karena pelanggan yang menonaktifkan module lalu mengaktifkannya lagi tidak boleh mendapat master bawaan dobel. Akibatnya seeder yang ditambahi baris pada versi berikutnya **tidak akan pernah berjalan** untuk tenant yang sudah memasangnya. Seeder module = data awal saat dipasang; migration data = apa pun yang harus sampai ke pelanggan lama. Core sudah menganut pola itu (`seed_country_regions` adalah migration), dan sejak sekarang ia mengikat.
- Jangan samakan katalog, entitlement, dan pemasangan module. Katalog berarti produk dikenal; entitlement berarti tenant berhak memakai; `installed` hanya sah setelah migration module berhasil dan barisnya tercatat di `core_module_installations`. UI berlabel "terpasang" wajib membaca catatan pemasangan itu, tidak boleh menyimpulkannya dari entitlement.
- Data tenant wajib memakai `TenantContext` tepercaya dan `tenant_id`; data operasional memakai `org_unit_id` bila relevan.
- **Nama di dalam kode ditulis bahasa Inggris. Tanpa kecuali.** Kelas, berkas, folder, method, fungsi, properti, variabel, nama route, kunci config, variabel env, signature perintah artisan, komponen React, dan berkas halaman Inertia. `SiapkanLingkungan` menjadi `ProvisionEnvironment`; `$lingkungan` menjadi `$environment`; `core.tenggat_siapkan` menjadi `core.provision_timeout`. Berlaku juga untuk nama method test — ia tetap method.

  **Yang justru tidak ikut, dan ini disengaja:** nama tabel, kolom, index, dan constraint database; teks yang dibaca pengguna di layar; alamat URL yang diketik operator; komentar, docblock, pesan commit, dan seluruh isi `docs/`. Semuanya tetap bahasa Indonesia. Yang dibedakan di sini bahasa **mesin** dan bahasa **manusia**: kode dibaca berdampingan dengan Laravel, PostgreSQL, dan React yang seluruhnya berbahasa Inggris, sehingga `foreach ($berhak as $id)` memaksa pembacanya berpindah bahasa di tengah satu baris. Prosa penjelas tidak punya masalah itu — ia justru lebih tepat ditulis dalam bahasa yang dipakai orang yang membacanya.

  Kalau sebuah istilah tidak punya padanan Inggris yang jelas, pakai istilah Dynamics 365 untuk hal yang sama. Jangan mengarang terjemahan harfiah.
- Tidak ada baris yang dihapus fisik. Menghapus berarti mengisi `deleted_at`, dan di layar tindakan itu bernama Arsipkan. Konsekuensinya wajib diikuti: indeks unik pada kode bisnis harus parsial (`WHERE deleted_at IS NULL`), penyaringan baris terarsip terjadi di lapisan model, dan mencabut modul tidak menyentuh data. Rinciannya pada [penghapusan lunak](docs/dev/02-module-standard.md#penghapusan-lunak).
- Saat menambah app atau fitur yang menyimpan/menampilkan data operasional, wajib membuka dan mengikuti **Data policy decision gate** pada `.agents/skills/coreerp-architecture/SKILL.md`. Deklarasikan policy data beserta kontraknya pada manifest hanya bila resource memang perlu dibatasi organisasi.
- Sebelum membuat app, master, transaksi, workflow, atau integrasi baru, wajib gunakan `.agents/skills/module-discovery/SKILL.md`: cari referensi resmi Dynamics 365, buat proposal keputusan, dan tunggu persetujuan untuk pilihan material. Jika tidak ada padanan Dynamics, nyatakan dengan jelas.
- SaaS dikelola control plane; on-prem perpetual berdiri sendiri, memakai update bertanda tangan, dan tanpa telemetry wajib.
- Pertahankan perubahan user yang tidak terkait. Verifikasi hanya scope yang berubah dengan script Composer/NPM yang tersedia.
- **Suite Core dijalankan paralel, dua tahap, persis seperti CI.** Dari `apps/core`:

  ```bash
  php artisan test --parallel --exclude-group=serial --exclude-group=lambat
  php artisan test --group=serial
  ```

  Atau `composer test:fast`, yang menjalankan keduanya berurutan. Tambahkan `--exclude-group=lambat` hanya untuk putaran cepat saat mengerjakan; sebelum menyatakan sesuatu hijau, jalankan tanpa mengecualikannya, karena itu yang dilakukan CI di luar pull request.

  **Jangan menjalankan `php vendor/phpunit/phpunit/phpunit` polos atas seluruh suite Core.** Ia serial dan memuat grup `serial` bersama sisanya — kombinasi yang tidak pernah dijalankan repo ini maupun CI, dan grup itu bernama `serial` justru karena isinya tidak boleh berjalan berbarengan dengan yang lain. Hasilnya bukan "lebih aman, cuma lambat", melainkan kegagalan yang tidak dapat dibedakan dari cacat kode. Phpunit langsung tetap yang benar untuk **satu berkas, satu folder, atau `--filter`** saat menelusuri kegagalan.

  Dua alasan memilih paralel, dan keduanya berlaku setiap saat. Ia memakai seluruh inti mesin alih-alih satu — suite Core yang serial memakan puluhan menit. Dan ia membuat satu database per proses (`core_erp_test_1`, `_2`, …), sehingga putaran test tidak berebut `core_erp_test` dengan sesi atau worktree lain yang kebetulan berjalan di mesin yang sama.

  **Suite `apps/control-plane` berbeda dan memang serial**: ia tidak memasang ParaTest dan tidak punya grup `serial`, jadi `php artisan test` atau phpunit langsung adalah yang benar di sana. Jangan menyeragamkannya tanpa memasang ParaTest lebih dulu.
- Sebuah modul belum selesai hanya karena test feature lulus. Modul baru wajib melewati load test: 1000+ VU serentak, 100+ tenant, 2+ instance API di belakang load balancer, database asli (bukan SQLite), 90 detik pada beban penuh. Gate kebenaran—0 pelanggaran lintas tenant, 0 nomor ganda, 0 eskalasi hak, 0 error 5xx aplikasi—berlaku di perangkat keras apa pun dan diverifikasi lewat SQL langsung ke database, bukan lewat API yang sedang diuji. Gate latensi diukur pada concurrency yang masih tertahan, bukan pada titik jenuh. Rinciannya ada pada skill `coreerp-architecture`; contoh implementasi ada di `modules/apperp/management-aset/loadtest/`.

Rules:
- Do not ever hardcode a name, like name of a company, name of a person, name  of a BIG MODULE, everything should config on database, ask me if you still didnt clear about this later on the conv

## Satu bentuk module

Sampai 10 September 2026 dua bentuk berdiri bersamaan dan aturannya berbeda. Sejak hari itu hanya ada
satu: module di bawah `modules/`.

| | Module di bawah `modules/` |
| --- | --- |
| Proses | ikut runtime Core |
| Database | database tenant yang sama dengan Core |
| Pemisah tabel | awalan nama tabel, misalnya `aset_` |
| Memanggil Core | panggilan fungsi biasa di dalam proses |
| Contract | wajib hanya untuk permukaan yang dipanggil di luar runtime |
| Token layanan sendiri | tidak ada |

Bentuk lama — app di repo `app-erp-*` dengan container, database, dan token layanan sendiri di
belakang reverse proxy — sudah tidak punya satu pun subjek, dan kodenya dibuang: penempatan app,
pendaftaran rilis penyedia, path konten `/apps-content/...`, halaman tuan rumah beriframe, token
konteks app, dan dua modul Apache yang hanya ada untuk mem-proxy-nya. Tabel `app_placements`,
`app_releases`, dan `app_installations` sengaja ditinggalkan sebagai tabel yatim, karena aturan repo
ini adalah semua penghapusan bersifat lunak.

Yang berlaku pada module, dan tidak boleh dilonggarkan dengan alasan apa pun:

- Sebuah module tidak boleh menyentuh tabel milik module lain. Yang menjaganya test dan analisa
  statis; batas yang dijaga pemeriksaan tetap batas, dan pelanggarnya ditolak.
- Setiap tabel module membawa `tenant_id`, dan setiap query menyaringnya.
- Nama event, envelope, dan aturan versinya tidak berubah.

Bila kelak masih ada repo app lama yang ditarik masuk, repo itu wajib bersih dan seluruh commit-nya
sudah terdorong ke remote lebih dulu. Itu langkah pertama pemindahannya, bukan anggapan: penarikan
yang berjalan di atas repo yang belum terdorong menelan pekerjaan yang belum ada di mana pun.

Aturan module-nya sendiri adalah desain kanonik di
[`docs/dev/02-module-standard.md`](docs/dev/02-module-standard.md).

## UI overlay dropdowns

- Any `Select` or combobox rendered inside an SDK `Sheet`, dialog, popover, or other overlay must receive that overlay content ref through `portalContainer`. Otherwise its menu may visually open beneath the overlay but cannot be selected. Follow the full UI guidance in [`.agents/skills/coreerp-ui/SKILL.md`](.agents/skills/coreerp-ui/SKILL.md).

## UI runtime verification

- Setelah mengubah UI, `npm run build` saja tidak cukup: rebuild dan recreate container runtime melalui `D:\Kerja\erp-dev\start.ps1 -Build`, lalu buka layar yang diubah pada `http://localhost:8000` untuk memastikan artifact baru benar-benar tampil.
- Jangan melaporkan perubahan UI selesai hanya karena type-check atau build lokal lulus. Pastikan container yang aktif dibuat ulang setelah perubahan dan health check stack selesai.

## Runtime database verification

- Perintah `php artisan migrate`, `db:seed`, atau `tinker` dari host dapat memakai database yang berbeda dari UI lokal. Untuk data yang harus terlihat di `http://localhost:8000`, jalankan migrasi/seed melalui container `core-app` dari `D:\Kerja\erp-dev` atau gunakan `D:\Kerja\erp-dev\start.ps1 -Build`.
- Setelah migration atau seed, verifikasi dengan query dari container runtime dan reload layar terkait. Jangan menyatakan data tersedia di UI hanya berdasarkan hasil `php artisan` di host.

## App manifest runtime verification

- Setelah mengubah navigasi atau security di `app.yaml`, rebuild UI saja tidak cukup. Jalankan `app:register-manifest <id module>` melalui container `core-app` di `D:\Kerja\erp-dev`, lalu verifikasi kolom `apps.navigation` pada database runtime dan reload shell Core.
- Saat menghapus duty yang sudah dipakai role, buat migration kecil untuk melepas relasi role-duty terlebih dahulu; baru daftarkan ulang manifest. Ini mencegah menu lama tetap tampil dari katalog Core.
