# Sisa pemindahan ke satu runtime

Folder rencana kerja `docs/todo/satu-runtime/` dibubarkan setelah aturannya berpindah menjadi desain
kanonik di `docs/dev/`. Halaman ini memuat apa yang **belum selesai** pada saat pembubaran itu, satu
per satu, supaya tidak ada yang hilang bersama foldernya.

Ini bukan salinan seluruh rencana kerja. Yang sudah selesai memang sengaja dibiarkan hilang — ia
sudah ada di kode, di riwayat git, dan di halaman desain kanoniknya. Yang tersisa di sini hanya yang
masih menuntut pekerjaan atau keputusan.

Satu tugas yang belum pernah dibangun sama sekali punya halamannya sendiri:
[bundle dan pemasangan di server pelanggan](../bundle-on-prem/).

## 1. Jalur konten dan proxy — hidup di kode, tanpa subjek

Rencana kerja menyebut tiga berkas yang harus dibuang bersama pembuangan jalur container:

- `apps/control-plane/app/Support/AppContentPath.php`
- `apps/control-plane/app/Console/Commands/RenderAppProxyConfigCommand.php`
- `deploy/apps-content-proxy.md`

Tugas itu tidak pernah punya pemilik: ia ditulis "dikerjakan pada fase 7", dan tidak ada satu pun
tugas fase 7 yang memikulnya.

**Diperiksa pada 10 September 2026, dan ketiganya belum boleh dibuang** — tetapi bukan karena alasan
yang tertulis di rencana.

Alasan yang tertulis di rencana ternyata **salah**: ia menyebut `app-erp-procurement` masih memakai
jalur konten. Repo itu tidak menyebut jalur konten sama sekali, dan sebuah migration sudah
mengeluarkan procurement dari katalog, penempatan, dan entitlement. Stack pengembangan lokal juga
tidak lagi punya layanan untuk menjalankan app berkontainer mana pun. Jadi hari ini **tidak ada satu
pun app yang benar-benar dilayani** jalur itu; perintah proxy-nya berjalan setiap container web naik
dan merender konfigurasi kosong.

Alasan yang sebenarnya bersifat struktural. Jalur konten bukan berkas yang berdiri sendiri — ia satu
mata rantai dari jalur hosting container yang **masih utuh dan sengaja dipertahankan**: pendaftaran
rilis oleh penyedia, penempatan app, penyusunan alamat konten oleh katalog peluncur, halaman shell
yang memuat alamat itu sebagai iframe, dan dua modul Apache yang hanya ada untuk melayaninya.
Membuang tiga berkas itu sendirian menghasilkan shell yang menerbitkan alamat yang tidak ada yang
mem-proxy — jalur rusak, bukan jalur yang dibuang.

Satu berkas yang **tidak** disebut rencana dan termasuk himpunan yang sama: dua modul Apache yang
dinyalakan `Dockerfile` khusus untuk proxy ini.

**Keputusan pemilik produk, 10 September 2026: procurement datang sebagai module.**

Artinya jalur container tidak punya subjek di masa depan, dan ia dibuang **sekaligus**, bukan
sepotong-sepotong. Yang termasuk himpunan itu, sejauh yang sudah ditelusuri:

- ketiga berkas yang disebut rencana, ditambah dua modul Apache pada `Dockerfile`
- halaman shell yang memuat konten app sebagai iframe
- penyusunan alamat konten pada katalog peluncur
- penempatan app dan rilis penyedia beserta endpoint-nya
- token konteks app
- perintah penyiapan runtime app lokal
- test dan halaman dokumen yang menjelaskan semuanya

Ini pekerjaan besar yang berdiri sendiri, dan ia **berdiri sendiri sebagai satu pull request**.
Membuangnya bercampur dengan pekerjaan lain membuat diff-nya tidak bisa ditinjau, dan jalur yang
setengah dibuang lebih berbahaya daripada jalur yang masih utuh.

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

Satu repo sengaja **tidak** disentuh: `app-erp-procurement`. Ia belum dipindah, dan pemindahannya
adalah pekerjaan yang disebut bagian 1 di atas.

## 5. Tiga lubang pemeriksaan yang ditemukan saat menulis desain kanonik

Ketiganya ditemukan dengan memeriksa alur dan setelannya, bukan dengan membaca dokumen, dan tidak
satu pun sudah ditutup.

**ESLint tidak menjangkau `modules/` sama sekali.** Perintah `npm run lint:check` menjalankan
`eslint .` dari `apps/control-plane`, dan konfigurasi flat menolak berkas di luar folder
konfigurasinya. Prettier dan `tsc` sudah mencakup `modules/`; ESLint tidak. Akibat nyatanya: aturan
hook React dan urutan impor **tidak berlaku** pada halaman modul, dan ada puluhan berkas UI modul di
pohon ini yang belum pernah diperiksa keduanya.

**Tidak ada mesin yang menahan `phpstan-baseline.neon` bertambah.** Aturannya jelas — baseline hanya
boleh menyusut — tetapi tidak ada test maupun langkah alur yang membandingkan ukurannya. Pull request
yang menambah baris tetap hijau; yang menegakkannya hanya peninjau.

**Push langsung ke `main` tidak diperiksa apa pun.** Alur linter dan alur test keduanya hanya dipicu
`pull_request`. Ini bertetangga dengan catatan yang sudah tertulis di
[CI/CD](../../dev/22-ci-cd.md): perlindungan cabang tidak tersedia pada paket GitHub yang dipakai,
jadi pemeriksaan apa pun dapat dilewati dengan satu klik gabungkan.

Satu lagi yang sejenis dan tercatat di halaman desainnya sendiri: larangan mengimpor `@/lib/...` dari
folder modul **tidak dijaga alat mana pun**, dan build tetap hijau bila dilanggar.

## 6. Repo penyebaran belum di-commit

`app-erp-deployment` sudah diperbaiki agar memakai satu image edisi — satu berkas compose, satu
database, satu repo sumber — dan dibuktikan jalan. Perubahannya sengaja **dibiarkan belum di-commit**
atas permintaan pemilik repo.

Selama belum di-commit, siapa pun yang memasang on-prem dengan mengikuti repo itu apa adanya masih
akan mencoba menarik image per app yang tidak dibangun lagi.

## 7. Kontrak OpenAPI internal tidak diregenerasi siapa pun

`apps/control-plane/contracts/openapi.json` adalah salinan yang dibuat sekali dan tidak ada langkah
CI yang meregenerasinya maupun memeriksa apakah ia masih cocok dengan rute yang benar-benar ada.
Ia akan basi tanpa ada satu pun pemeriksaan yang gagal.

Ini bertetangga dengan aturan yang sudah tertulis di
[API, event, dan integrasi module](../../dev/04-api-and-integration.md): kontrak yang tidak dijaga
pemeriksa adalah dokumentasi, bukan kontrak. Yang perlu diputuskan bukan cara memperbaikinya,
melainkan apakah berkas itu tetap ada — dan kalau tetap ada, apa yang menjaganya.
