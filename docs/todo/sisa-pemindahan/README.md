# Sisa pemindahan ke satu runtime

Folder rencana kerja `docs/todo/satu-runtime/` dibubarkan setelah aturannya berpindah menjadi desain
kanonik di `docs/dev/`. Halaman ini memuat apa yang **belum selesai** pada saat pembubaran itu, satu
per satu, supaya tidak ada yang hilang bersama foldernya.

Ini bukan salinan seluruh rencana kerja. Yang sudah selesai memang sengaja dibiarkan hilang — ia
sudah ada di kode, di riwayat git, dan di halaman desain kanoniknya. Yang tersisa di sini hanya yang
masih menuntut pekerjaan atau keputusan.

Satu tugas yang belum pernah dibangun sama sekali punya halamannya sendiri:
[bundle dan pemasangan di server pelanggan](../bundle-on-prem/).

## 1. Jalur konten dan proxy — dibuang seluruhnya, selesai

**Selesai pada 10 September 2026.** Pemilik produk memutuskan pada hari yang sama bahwa
`app-erp-procurement` datang sebagai module, bukan sebagai app berkontainer. Dengan itu jalur hosting
container tidak punya subjek di masa depan, dan ia dibuang **sekaligus** dalam satu pull request —
bukan sepotong-sepotong, karena jalur yang setengah dibuang lebih berbahaya daripada jalur yang masih
utuh.

Yang dibuang:

- `apps/control-plane/app/Support/AppContentPath.php` dan `AppContextToken.php`
- `apps/control-plane/app/Console/Commands/RenderAppProxyConfigCommand.php` dan
  `BootstrapLocalAppRuntimeCommand.php`
- `apps/control-plane/app/Jobs/DeployAppPlacement.php`
- `apps/control-plane/app/Support/Reporting/AppReportClient.php` beserta cabang HTTP pada
  `SumberLaporan` — tidak ada lagi app di luar proses yang menyiapkan dataset laporan
- pendaftaran rilis penyedia: `AppReleaseController`, `AppReleaseRequest`, model `AppRelease`,
  rutenya, dan entri OpenAPI-nya
- `apps/control-plane/resources/js/pages/apps/host.tsx` beserta pemeriksa pesan iframe pada
  `resources/js/lib/notifications.ts`
- `deploy/apps-content-proxy.md`
- pemanggilan `app:render-proxy-config` pada `docker/entrypoint.sh`, dan dua modul Apache
  (`proxy`, `proxy_http`) pada `Dockerfile`
- setelan `reporting.app_api_endpoints` dan `reporting.app_timeout`
- test yang menguji jalur itu

Yang **tidak** dibuang, dan alasannya:

- **Tabel `app_placements`, `app_releases`, dan `app_installations`.** Ditinggalkan sebagai tabel
  yatim. Menghapusnya berarti migration yang membuang data di server setiap pelanggan, dan aturan
  repo ini adalah semua penghapusan bersifat lunak. Tidak ada kode yang menulis maupun membacanya
  lagi.
- **Endpoint `/api/internal/v1/` beserta middleware `internal-app`.** Ia bukan bagian dari hosting
  container; ia batas untuk integrasi luar dan addon pihak ketiga, dan
  [API, event, dan integrasi module](../../dev/04-api-and-integration.md) sudah menyatakan ia
  dipertahankan. Yang berubah hanya penentu kesiapannya: dari penempatan container menjadi catatan
  pemasangan module.
- **`tenant_deployments`.** Ia mencatat profile dan placement satu tenant, dan masih dibaca jalur
  urutan nomor. Ia bukan bagian jalur container.

Satu akibat yang ikut diperbaiki karena pembuangan ini memaksanya: `EnsureNumberSequenceDrafts`
membaca kesiapan dari `app_placements`, sehingga **module tidak pernah memperoleh urutan nomor lewat
jalur itu**. Ia sekarang membaca catatan pemasangan module.

## 2. Penugasan role otomatis hilang — diterima, bukan ditunggu

**Keputusan pemilik produk, 10 September 2026: kemampuan ini dilepas.** Ia tidak pernah benar-benar
dipakai di tempat pelanggan, jadi memulihkannya berarti membangun kontrak Core baru untuk sesuatu
yang belum pernah ada penggunanya. Bagian ini tetap ditulis karena kehilangannya nyata dan harus
dapat ditemukan orang berikutnya — bukan karena ia menunggu dikerjakan.

Ketika Human Resources masih app tersendiri, penugasan seorang pekerja ke sebuah posisi juga
memberinya role beserta lingkup unit kerjanya, lewat panggilan HTTP ke Core. Panggilan itu hilang
bersama kerangka app lama, dan **Core belum punya kontrak penggantinya** — tidak satu pun antarmuka
di `App\Support\Modules\Contracts` menyentuh penugasan role.

Mempertahankan klien HTTP-nya bukan pilihan yang lebih aman: setelan alamat Core ikut hilang, jadi
pemanggilan itu sekarang menembak alamat kosong dan **selalu** gagal — setiap penugasan untuk pekerja
berakun akan berakhir 500, bukan tersimpan. Yang dipilih: penugasannya tersimpan, dan rolenya
ditugaskan admin tenant dengan tangan.

Kalau kelak ada pelanggan yang benar-benar membutuhkannya, yang mengembalikannya adalah antarmuka
Core baru yang menerima id keanggotaan, id posisi, id unit kerja, id penugasan, dan status aktif.
Bentuk itu dicatat di sini supaya tidak perlu ditemukan ulang dari awal.

**Dua saringan yang ikut hilang**, dan keduanya tidak dapat dipulihkan dari sisi module:

| Yang hilang | Sebabnya | Akibatnya |
| --- | --- | --- |
| Saringan organisasi aktif | kontrak direktori organisasi tidak memulangkan status organisasi | daftar unit kerja dapat memuat organisasi non-aktif |
| Saringan keanggotaan aktif | kontrak keanggotaan tidak memulangkan status keanggotaan | penautan akun dapat menunjuk keanggotaan yang sudah tidak aktif |

Keduanya menuntut kolom tambahan pada kontrak Core, bukan tambalan di module. Keduanya ikut dilepas
oleh keputusan yang sama, dan ikut kembali bila kemampuannya kelak diminta.

## 3. Dua permukaan sudah terverifikasi di bawah beban — selesai

Skenario **work order** dan **penyusutan** dipindahkan ke bentuk yang berlaku pada 10 September 2026:
tenant disiapkan lewat alur pendaftaran usaha yang sungguhan lalu cookie sesi, bukan berkas tenant
dan bearer token yang sudah tidak ada. Pemberitahuan "gagal keras" di kepala kedua berkas dibuang
setelah keduanya benar-benar berjalan.

Gate kebenaran **lulus**, dan angkanya diambil dari run yang benar-benar selesai: balapan transisi
work order menghasilkan tepat satu pemenang pada seluruh percobaan, begitu pula balapan finalisasi
penyusutan; probe lintas tenant dan probe eskalasi izin ditolak seluruhnya; pemeriksaan SQL milik
module maupun milik Core semuanya nol.

Gate latensi lulus sampai delapan pengguna serentak dan gagal pada dua belas. **Gate-nya tidak
dilonggarkan.** Batas delapan itu sama dengan yang sudah tercatat untuk skenario lain pada mesin ini,
jadi ia batas mesin, bukan batas kedua permukaan ini.

Dua hal dicatat apa adanya karena keduanya membatasi arti hasil di atas:

- **Beberapa oracle tidak dapat dibuat merah** — nomor diterbitkan server, tenant datang dari sesi,
  dan status yang dipulangkan adalah status yang baru saja ditulis, sehingga tidak ada bentuk
  permintaan yang bisa merusaknya. Keempatnya tidak dihitung sebagai pembuktian.
- **Sakelar pembuktian merah pada skenario penyusutan merusak fixture-nya untuk selamanya**, karena
  ia mengirim pembalikan. Ia wajib dijalankan dengan fixture tersendiri, dan itu ditulis di kepala
  berkasnya.

## 4. Pengarsipan repo lama — selesai untuk dua repo yang pindah

`app-erp-hr` dan `app-erp-management-aset` sudah menjadi arsip pada 10 September 2026, masing-masing
dengan berkas pengantar di remote-nya yang menunjuk rumah barunya di dalam repo ini.

Satu hal yang pantas dicatat karena hampir terlewat: `app-erp-management-aset` sempat **diarsipkan
sebelum penunjuknya mendarat**. Repo yang sudah menjadi arsip bersifat hanya-baca, jadi commit
penunjuknya tertahan di mesin lokal tanpa ada yang tahu. Memperbaikinya menuntut repo itu dibuka
kembali, penunjuknya didorong, lalu ditutup lagi. **Urutannya mengikat: penunjuk mendarat dulu, arsip
kemudian.**

`app-erp-template` dan `app-erp-ci-workflows` menyusul pada hari yang sama. Yang pertama lebih dulu
mendapat berkas pengantar ke cetakan module di `modules/_template/`; yang kedua tidak dipanggil satu
repo pun di organisasi — diperiksa dengan pencarian kode, dan satu-satunya penyebutan yang tersisa
ada di dokumen serta satu test di repo ini.

Satu repo sengaja **tidak** disentuh: `app-erp-procurement`. Ia belum dipindah; keputusan pemilik
produk pada 10 September 2026 adalah ia datang sebagai module, dan pemindahannya sendiri belum
dikerjakan. Yang sudah selesai adalah pembuangan jalur container yang disebut bagian 1 di atas.

## 5. Lubang pemeriksaan — dua nyata dan sudah ditutup, satu salah lapor

**ESLint tidak menjangkau `modules/` maupun `packages/`.** Dan bentuknya lebih buruk daripada
"menolak": ia memeriksa **nol** berkas di sana lalu keluar dengan kode 0. ESLint 9 menetapkan base
path dari letak berkas konfigurasi, dan konfigurasinya ada di `apps/control-plane/`. Puluhan berkas
UI module karena itu tidak pernah diperiksa aturan hook React maupun urutan impor sejak module
pertama mendarat, sementara alur CI melaporkan linter hijau di setiap pull request.

Ditutup: konfigurasinya pindah ke akar repo, perintahnya diarahkan ke akar, dan
`scripts/periksa-jangkauan-eslint.mjs` menolak bila ESLint tidak melihat satu pun berkas di kedua
folder itu. Begitu jangkauannya dibuka, 608 temuan mekanis muncul sekaligus.

**Tidak ada mesin yang menahan `phpstan-baseline.neon` bertambah.** Ditutup oleh
`scripts/periksa-baseline-phpstan.mjs`, yang membandingkan **per entri**, bukan totalnya.
Rancangan pertamanya membandingkan total dan terbukti tidak menjaga apa pun: pada cabang yang
membuang tujuh belas bungkaman, menambahkan tiga bungkaman baru masih terbaca "menyusut" dan lolos.

**"Push langsung ke `main` tidak diperiksa" — ini salah lapor, dan pantas dicatat sebagai
kesalahan.** Ketiadaan pemicu `push` bukan kelalaian melainkan keputusan sadar, dan alasannya sudah
tertulis di kepala kedua alur: `pull_request` dijalankan terhadap pohon yang **sudah digabungkan**
ke basisnya, jadi mengulang alur yang sama setelah merge menguji pohon yang praktis identik dengan
yang barusan hijau — dan repo ini privat di paket gratis. Komentar itu bahkan sudah menyebut sendiri
konsekuensi yang dilepasnya.

Pelajarannya: sebuah survei yang membaca setelan tanpa membaca alasan yang tertulis di sebelahnya
akan melaporkan keputusan sebagai kelalaian.

Satu yang sejenis dan masih terbuka: larangan mengimpor `@/lib/...` dari folder module **tidak
dijaga alat mana pun**, dan build tetap hijau bila dilanggar. Ia tercatat di
[UI modul di dalam shell](../../dev/27-ui-modul-dalam-shell.md).

## 6. Aksi massal di layar master tidak pernah dapat dijangkau

Ditemukan saat memperbaiki temuan hook React, di luar lint.

Pada `modules/apperp/management-aset/ui/master/MasterPage.tsx`, daftar baris terpilih **tidak pernah
terisi**: satu-satunya penyetelnya hanya pernah dipanggil dengan daftar kosong, dan tabelnya punya
prop pemilihan yang tidak pernah diberikan.

Akibatnya bilah aksi massal — yang hanya muncul ketika ada baris terpilih — **tidak pernah muncul**,
dan sekitar enam puluh baris di belakangnya, lengkap dengan konfirmasi dan pemrosesan berbarengan,
tidak dapat dijangkau siapa pun. Tidak ada test yang gagal karenanya, karena tidak ada test yang
pernah menekan tombol yang tidak pernah ada.

Dua jalan, dan keduanya keputusan pemilik produk, bukan pekerjaan yang tinggal dijalankan:

- **Sambungkan** — pemilihan baris diaktifkan, dan aksi massal menjadi fitur yang benar-benar ada.
  Itu menambah fitur, bukan memperbaiki cacat.
- **Buang** — enam puluh baris itu dihapus. Itu membuang niat yang mungkin masih dipegang.

Perbaikan lint di berkas itu sudah dikerjakan dengan menurunkan daftar terpilih dari daftar baris,
jadi kodenya sudah benar begitu pemilihan disambungkan.

## 7. Repo penyebaran belum di-commit

`app-erp-deployment` sudah diperbaiki agar memakai satu image edisi — satu berkas compose, satu
database, satu repo sumber — dan dibuktikan jalan. Perubahannya sengaja **dibiarkan belum di-commit**
atas permintaan pemilik repo.

Selama belum di-commit, siapa pun yang memasang on-prem dengan mengikuti repo itu apa adanya masih
akan mencoba menarik image per app yang tidak dibangun lagi.

## 8. Kontrak OpenAPI internal tidak diregenerasi siapa pun

`apps/control-plane/contracts/openapi.json` adalah salinan yang dibuat sekali dan tidak ada langkah
CI yang meregenerasinya maupun memeriksa apakah ia masih cocok dengan rute yang benar-benar ada.
Ia akan basi tanpa ada satu pun pemeriksaan yang gagal.

Ini bertetangga dengan aturan yang sudah tertulis di
[API, event, dan integrasi module](../../dev/04-api-and-integration.md): kontrak yang tidak dijaga
pemeriksa adalah dokumentasi, bukan kontrak. Yang perlu diputuskan bukan cara memperbaikinya,
melainkan apakah berkas itu tetap ada — dan kalau tetap ada, apa yang menjaganya.
